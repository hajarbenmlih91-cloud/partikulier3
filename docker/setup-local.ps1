$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed: $($args[0])" }
}

function New-LocalSecret {
    $bytes = New-Object byte[] 48
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    [Convert]::ToBase64String($bytes)
}

if (-not (Test-Path .env)) { Copy-Item .env.example .env }
$lines = @(Get-Content .env)
$settings = @{}
foreach ($line in $lines) {
    if ($line -match '^([^#=]+)=(.*)$') { $settings[$matches[1]] = $matches[2] }
}
$originalSettings = $settings.Clone()

$legacy = @(docker ps -a --filter 'name=^/partikulier-n8n$' --format '{{.Names}}')
if ($legacy.Count -gt 0) {
    $legacyContainer = (docker inspect partikulier-n8n | ConvertFrom-Json)[0]
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the existing n8n container.' }
    $dataMount = @($legacyContainer.Mounts | Where-Object Destination -eq '/home/node/.n8n')
    if ($dataMount.Count -ne 1 -or $dataMount[0].Type -ne 'volume') {
        throw 'Existing n8n data must use a named volume. Migrate its data manually before setup.'
    }
    if ($settings.PK_N8N_VOLUME -and $settings.PK_N8N_VOLUME -ne $dataMount[0].Name) {
        throw 'PK_N8N_VOLUME differs from the existing n8n data volume. Reconcile the volumes before setup.'
    }
    $settings.PK_N8N_VOLUME = $dataMount[0].Name
}
if ($legacy.Count -gt 0 -and (-not $settings.N8N_ENCRYPTION_KEY -or -not $settings.N8N_EMAIL)) {
    $running = (docker inspect partikulier-n8n | ConvertFrom-Json)[0].State.Running
    if (-not $running) { Invoke-Docker start partikulier-n8n | Out-Null }
    $inspectScript = @'
const fs=require('fs');
const {DatabaseSync}=require('node:sqlite');
const db=new DatabaseSync('/home/node/.n8n/database.sqlite',{readOnly:true});
const owner=db.prepare("SELECT email FROM user WHERE roleSlug='global:owner'").get();
console.log(JSON.stringify({
  key:JSON.parse(fs.readFileSync('/home/node/.n8n/config','utf8')).encryptionKey,
  email:owner?.email
}));
'@
    $existing = $inspectScript | docker exec -i partikulier-n8n node
    if ($LASTEXITCODE -ne 0) { throw 'Cannot read the existing n8n identity and encryption key.' }
    $existing = $existing | ConvertFrom-Json
    if (-not $settings.N8N_ENCRYPTION_KEY) { $settings.N8N_ENCRYPTION_KEY = $existing.key }
    if (-not $settings.N8N_EMAIL) { $settings.N8N_EMAIL = $existing.email }
}
if (-not $settings.N8N_EMAIL) { $settings.N8N_EMAIL = 'admin@example.test' }

$rotations = @{}
foreach ($name in @('PK_DB_PASSWORD', 'PK_DB_ROOT_PASSWORD', 'PK_ADMIN_PASSWORD', 'N8N_PASSWORD')) {
    if (-not $settings[$name] -or $settings[$name] -match '^change-me|^admin$|^password$') {
        $rotations[$name] = New-LocalSecret
        $settings[$name] = $rotations[$name]
        Write-Host "Initialized non-placeholder secret: $name"
    }
}
if (-not $settings.N8N_ENCRYPTION_KEY) { $settings.N8N_ENCRYPTION_KEY = New-LocalSecret }
if (-not $settings.PARTIKULIER_N8N_SECRET) { $settings.PARTIKULIER_N8N_SECRET = New-LocalSecret }
$settings.PARTIKULIER_N8N_WEBHOOK_URL = 'https://n8n-proxy/webhook/partikulier-listing-approved'
if (-not $settings.PK_N8N_VOLUME) { $settings.PK_N8N_VOLUME = 'partikulier_n8n_data' }
$settings.PK_WITH_POLYLANG = '1'
$settings.PK_WITH_AVIF_TOOLS = '1'

$written = New-Object 'System.Collections.Generic.List[string]'
$seen = @{}
foreach ($line in $lines) {
    if ($line -match '^([^#=]+)=') {
        $name = $matches[1]
        $written.Add("$name=$($settings[$name])")
        $seen[$name] = $true
    } else {
        $written.Add($line)
    }
}
foreach ($name in ($settings.Keys | Sort-Object)) {
    if (-not $seen[$name]) { $written.Add("$name=$($settings[$name])") }
}
foreach ($name in $settings.Keys) {
    [Environment]::SetEnvironmentVariable($name, $settings[$name], 'Process')
}
Invoke-Docker volume create $settings.PK_N8N_VOLUME | Out-Null
Invoke-Docker compose config --quiet
Invoke-Docker compose build wordpress

$db = @(docker ps -a --filter 'name=^/partikulier-db-1$' --format '{{.Names}}')
$rotatingDatabase = $rotations.PK_DB_PASSWORD -or $rotations.PK_DB_ROOT_PASSWORD
if ($db.Count -eq 0 -and $rotatingDatabase) {
    $volumes = @(Invoke-Docker volume ls --filter 'name=^partikulier_db_data$' --format '{{.Name}}')
    if ($volumes.Count -gt 0) {
        if (-not $originalSettings.PK_DB_ROOT_PASSWORD -or -not $originalSettings.PK_DB_PASSWORD) {
            throw 'Existing database volume requires its original passwords in .env before rotation.'
        }
        try {
            foreach ($name in @('PK_DB_PASSWORD', 'PK_DB_ROOT_PASSWORD')) {
                [Environment]::SetEnvironmentVariable($name, $originalSettings[$name], 'Process')
            }
            Invoke-Docker compose up -d db | Out-Null
        } finally {
            foreach ($name in @('PK_DB_PASSWORD', 'PK_DB_ROOT_PASSWORD')) {
                [Environment]::SetEnvironmentVariable($name, $settings[$name], 'Process')
            }
        }
        $db = @('partikulier-db-1')
    }
}
if ($db.Count -gt 0 -and $rotatingDatabase) {
    if ($settings.PK_DB_USER -notmatch '^[a-zA-Z0-9_]+$') { throw 'Unsupported local database username.' }
    Invoke-Docker start partikulier-db-1 | Out-Null
    Invoke-Docker exec partikulier-db-1 sh -c 'for i in $(seq 1 60); do MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "SELECT 1" >/dev/null 2>&1 && exit 0; sleep 2; done; echo "Database not ready with its original root password" >&2; exit 1'
    Invoke-Docker compose stop wordpress | Out-Null
    $sql = New-Object 'System.Collections.Generic.List[string]'
    if ($rotations.PK_DB_PASSWORD) {
        $sql.Add("ALTER USER '$($settings.PK_DB_USER)'@'%' IDENTIFIED BY '$($settings.PK_DB_PASSWORD)';")
    }
    if ($rotations.PK_DB_ROOT_PASSWORD) {
        $sql.Add("ALTER USER 'root'@'%' IDENTIFIED BY '$($settings.PK_DB_ROOT_PASSWORD)';")
        $sql.Add("ALTER USER 'root'@'localhost' IDENTIFIED BY '$($settings.PK_DB_ROOT_PASSWORD)';")
    }
    $sql -join "`n" | docker exec -i partikulier-db-1 sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'
    if ($LASTEXITCODE -ne 0) { throw 'Database password rotation failed. Restore/reconcile the private backup before continuing.' }
    Write-Host 'Rotated the existing MySQL accounts without deleting data.'
}
[System.IO.File]::WriteAllLines((Join-Path (Get-Location) '.env'), $written, (New-Object System.Text.UTF8Encoding($false)))
if ($legacy.Count -gt 0) { Invoke-Docker stop partikulier-n8n | Out-Null }

Invoke-Docker compose up -d --wait --wait-timeout 180
Invoke-Docker compose exec -T -u www-data wordpress pk-wp-init
Invoke-Docker compose exec -T -u www-data wordpress wp eval-file /repo/docker/init-local.php
Invoke-Docker compose exec -T -u www-data wordpress wp eval-file /repo/docker/seed-local-example.php
& (Join-Path $PSScriptRoot 'setup-n8n.ps1') -Settings $settings

$health = Invoke-RestMethod 'http://localhost:8099/wp-json/partikulier/v1/health'
if ($health.status -ne 'ok') { throw 'WordPress health check failed.' }
Invoke-Docker compose exec -T wordpress curl --fail --silent --show-error https://n8n-proxy/healthz
Write-Host "`nLocal setup ready: website :8099, n8n :5678, Mailpit :8025. Secrets remain in .env."

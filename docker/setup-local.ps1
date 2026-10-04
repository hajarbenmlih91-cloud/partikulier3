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

$legacy = @(docker ps -a --filter 'name=^/partikulier-n8n$' --format '{{.Names}}')
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
$settings.PK_N8N_VOLUME = 'partikulier_n8n_data'
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

$db = @(docker ps --filter 'name=^/partikulier-db-1$' --format '{{.Names}}')
if ($db.Count -gt 0 -and ($rotations.PK_DB_PASSWORD -or $rotations.PK_DB_ROOT_PASSWORD)) {
    if ($settings.PK_DB_USER -notmatch '^[a-zA-Z0-9_]+$') { throw 'Unsupported local database username.' }
    Invoke-Docker stop partikulier-wordpress-1 | Out-Null
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

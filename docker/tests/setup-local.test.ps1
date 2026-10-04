$ErrorActionPreference = 'Stop'
$source = Get-Content (Join-Path $PSScriptRoot '..\setup-local.ps1') -Raw
$source = $source.Replace('Set-Location (Split-Path $PSScriptRoot -Parent)', '')
$source = $source.Substring(0, $source.IndexOf('Invoke-Docker compose up -d --wait'))
$originalDirectory = Get-Location
$tempDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ("pk-setup-test-" + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempDirectory | Out-Null

function docker {
    $global:LASTEXITCODE = 0
    $command = $args -join ' '
    $script:calls.Add($command)
    if ($args[0] -eq 'ps') {
        if ($command.Contains('partikulier-n8n') -and $script:scenario.Legacy) { 'partikulier-n8n' }
        if ($command.Contains('partikulier-db-1') -and $script:scenario.Database) { 'partikulier-db-1' }
    } elseif ($args[0] -eq 'inspect') {
        @(@{ State = @{ Running = $true }; Mounts = @(@{ Destination = '/home/node/.n8n'; Type = 'volume'; Name = 'legacy-data' }) }) | ConvertTo-Json -Depth 5 -Compress
    } elseif ($command.StartsWith('volume ls') -and $script:scenario.Volume) {
        'partikulier_db_data'
    } elseif ($args[0] -eq 'compose' -and $args[1] -eq 'up') {
        if ($env:PK_DB_ROOT_PASSWORD -ne 'change-me-root' -or $env:PK_DB_PASSWORD -ne 'change-me-db') {
            throw 'Database recreation did not use original credentials.'
        }
    }
}

function Assert-Test($condition, $message) {
    if (-not $condition) { throw $message }
}

try {
    Set-Location $tempDirectory
    foreach ($case in @(
        @{ Name = 'custom volume preserved'; Custom = 'custom-data'; Database = $false; Rotate = $false },
        @{ Name = 'stopped database rotated'; Database = $true; Rotate = $true },
        @{ Name = 'existing volume recreated before rotation'; Database = $false; Volume = $true; Rotate = $true },
        @{ Name = 'legacy volume reused'; Legacy = $true; Rotate = $false },
        @{ Name = 'conflicting legacy volume rejected'; Legacy = $true; Custom = 'other-data'; Rotate = $false; Reject = $true }
    )) {
        $script:scenario = $case
        $script:calls = New-Object 'System.Collections.Generic.List[string]'
        $dbPassword = if ($case.Rotate) { 'change-me-db' } else { 'fixture-db-only' }
        $rootPassword = if ($case.Rotate) { 'change-me-root' } else { 'fixture-root-only' }
        $fixture = @(
            'PK_DB_USER=partikulier', "PK_DB_PASSWORD=$dbPassword", "PK_DB_ROOT_PASSWORD=$rootPassword",
            'PK_ADMIN_PASSWORD=fixture-admin-only', 'N8N_PASSWORD=fixture-n8n-only',
            'N8N_EMAIL=owner@example.test', 'N8N_ENCRYPTION_KEY=fixture-key-only',
            'PARTIKULIER_N8N_SECRET=fixture-hmac-only'
        )
        if ($case.Custom) { $fixture += "PK_N8N_VOLUME=$($case.Custom)" }
        [System.IO.File]::WriteAllLines((Join-Path $tempDirectory '.env'), $fixture)
        $rejected = $false
        try { & ([ScriptBlock]::Create($source)) } catch {
            if (-not $case.Reject -or $_.Exception.Message -notmatch 'differs from') { throw }
            $rejected = $true
        }
        if ($case.Reject) {
            Assert-Test $rejected 'Conflicting legacy mount was not rejected.'
            Assert-Test (-not ($script:calls -contains 'stop partikulier-n8n')) 'Rejected migration stopped the old instance.'
        } else {
            $saved = Get-Content (Join-Path $tempDirectory '.env')
            $expectedVolume = if ($case.Custom) { $case.Custom } elseif ($case.Legacy) { 'legacy-data' } else { 'partikulier_n8n_data' }
            Assert-Test ($saved -contains "PK_N8N_VOLUME=$expectedVolume") 'Existing n8n volume was replaced.'
            if ($case.Rotate) {
                Assert-Test ($script:calls -contains 'start partikulier-db-1') 'Stopped database was not started.'
                Assert-Test ($script:calls -contains 'exec -i partikulier-db-1 sh -c MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot') 'Database accounts were not rotated.'
                Assert-Test (-not ($saved -contains "PK_DB_PASSWORD=$dbPassword")) 'New database password was not saved.'
            }
        }
        Write-Host "PASS: $($case.Name)"
    }
} finally {
    Set-Location $originalDirectory
    Remove-Item -LiteralPath (Join-Path $tempDirectory '.env') -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $tempDirectory
}

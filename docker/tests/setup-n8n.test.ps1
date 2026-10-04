$ErrorActionPreference = 'Stop'
$global:PkN8nTestWorkflows = New-Object 'System.Collections.Generic.List[object]'
$global:PkN8nTestActivations = 0

function Invoke-RestMethod {
    param($Uri, $Method = 'Get', $ContentType, $Body, $SessionVariable, $WebSession, $TimeoutSec)
    if ($TimeoutSec -ne 30) { throw 'n8n API calls must have a bounded timeout.' }
    $path = ([Uri]$Uri).AbsolutePath
    if ($path -eq '/rest/settings') {
        return @{ data = @{ userManagement = @{ showSetupOnFirstLoad = $false } } }
    }
    if ($path -eq '/rest/login') {
        Set-Variable -Name $SessionVariable -Value @{} -Scope 1
        return @{ data = @{} }
    }
    if ($Method -eq 'Get') { return @{ data = @() } }
    if ($path -eq '/rest/credentials') {
        return @{ data = @{ id = 'fixture-credential'; name = ($Body | ConvertFrom-Json).name } }
    }
    if ($path -eq '/rest/workflows') {
        if ($Body.Length -gt 15000) { throw 'Workflow JSON contains inflated file metadata.' }
        $workflow = $Body | ConvertFrom-Json
        $global:PkN8nTestWorkflows.Add($workflow)
        return @{ data = @{ id = "fixture-$($global:PkN8nTestWorkflows.Count)"; versionId = 'fixture-version' } }
    }
    if ($path -match '/activate$') {
        $global:PkN8nTestActivations++
        return @{ data = @{} }
    }
    throw "Unexpected n8n API call: $Method $path"
}

try {
& (Join-Path $PSScriptRoot '..\setup-n8n.ps1') -Settings @{
    N8N_EMAIL = 'fixture@example.test'; N8N_PASSWORD = 'fixture-password-only'
    PARTIKULIER_N8N_SECRET = 'fixture-hmac-only'
} -BaseUrl 'http://127.0.0.1:15678'

if ($global:PkN8nTestWorkflows.Count -ne 2 -or $global:PkN8nTestActivations -ne 2) {
    throw 'Expected exactly the two local workflows, with no Meta publication.'
}
$approval = $global:PkN8nTestWorkflows[0]
$buyer = $global:PkN8nTestWorkflows[1]
if ($approval.nodes[0].parameters.authentication -ne 'none' -or $approval.nodes[0].credentials) {
    throw 'Approval must authenticate the signed raw body, not require a removed secret header.'
}
if ($buyer.nodes[0].parameters.authentication -ne 'headerAuth') {
    throw 'Buyer webhook authentication was weakened.'
}
foreach ($workflow in $global:PkN8nTestWorkflows) {
    $code = @($workflow.nodes | Where-Object type -eq 'n8n-nodes-base.code')[0].parameters.jsCode
    if ($code -isnot [string] -or -not $code.StartsWith("const crypto = require('crypto');")) {
        throw 'Workflow code must be a plain source string, without Get-Content metadata.'
    }
}
Write-Host 'PASS: bounded API calls, compact plain-source workflow JSON, approval HMAC, buyer header auth, no Meta publication'
} finally {
    Remove-Variable PkN8nTestWorkflows, PkN8nTestActivations -Scope Global
}

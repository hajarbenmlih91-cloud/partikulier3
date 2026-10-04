param([Parameter(Mandatory)][hashtable]$Settings)

$ErrorActionPreference = 'Stop'
$base = 'http://localhost:5678'
$public = Invoke-RestMethod "$base/rest/settings"
if ($public.data.userManagement.showSetupOnFirstLoad) {
    $body = @{
        email = $Settings.N8N_EMAIL
        password = $Settings.N8N_PASSWORD
        firstName = 'Local'
        lastName = 'Partikulier'
    } | ConvertTo-Json
    Invoke-RestMethod "$base/rest/owner/setup" -Method Post -ContentType application/json -Body $body | Out-Null
}
$login = @{
    emailOrLdapLoginId = $Settings.N8N_EMAIL
    password = $Settings.N8N_PASSWORD
} | ConvertTo-Json
Invoke-RestMethod "$base/rest/login" -Method Post -ContentType application/json -Body $login -SessionVariable session | Out-Null

function Invoke-N8n($Path, $Method = 'Get', $Body) {
    $args = @{ Uri = "$base/rest/$Path"; Method = $Method; WebSession = $session }
    if ($null -ne $Body) {
        $args.ContentType = 'application/json'
        $args.Body = $Body | ConvertTo-Json -Depth 30 -Compress
    }
    Invoke-RestMethod @args
}

function Set-LocalCredential($Name, $Type, $Data) {
    $existing = @((Invoke-N8n 'credentials').data) | Where-Object name -eq $Name
    if (@($existing).Count -gt 1) { throw "Duplicate local credential: $Name" }
    $body = @{ name = $Name; type = $Type; data = $Data }
    if ($existing) {
        $saved = Invoke-N8n "credentials/$($existing.id)" 'Patch' $body
    } else {
        $saved = Invoke-N8n 'credentials' 'Post' $body
    }
    return @{ id = $saved.data.id; name = $Name }
}

$header = Set-LocalCredential 'Partikulier local webhook secret' 'httpHeaderAuth' @{
    name = 'X-Partikulier-Automation'
    value = $Settings.PARTIKULIER_N8N_SECRET
}
$smtp = Set-LocalCredential 'Partikulier local Mailpit' 'smtp' @{
    host = 'mailpit'; port = 1025; secure = $false; disableStartTls = $true
}

function New-Webhook($Path, $Raw = $false) {
    @{
        id = 'webhook'; name = 'Webhook'; type = 'n8n-nodes-base.webhook'; typeVersion = 2
        position = @(0, 0); webhookId = $Path
        parameters = @{
            httpMethod = 'POST'; path = $Path; authentication = 'headerAuth'
            responseMode = 'responseNode'; options = @{ rawBody = $Raw }
        }
        credentials = @{ httpHeaderAuth = $header }
    }
}

function New-Respond($Expression) {
    @{
        id = 'respond'; name = 'Respond'; type = 'n8n-nodes-base.respondToWebhook'; typeVersion = 1.4
        position = @(750, 0)
        parameters = @{ respondWith = 'json'; responseBody = $Expression; options = @{} }
    }
}

function New-Connection($Target) {
    @{ main = ,@(@{ node = $Target; type = 'main'; index = 0 }) }
}

function Set-LocalWorkflow($Name, $Nodes, $Connections) {
    $existing = @((Invoke-N8n 'workflows').data) | Where-Object name -eq $Name
    if (@($existing).Count -gt 1) { throw "Duplicate local workflow: $Name" }
    $body = @{
        name = $Name; nodes = $Nodes; connections = $Connections
        settings = @{
            executionOrder = 'v1'; saveDataSuccessExecution = 'none'
            saveDataErrorExecution = 'none'; saveManualExecutions = $false
            saveExecutionProgress = $false
        }
    }
    if ($existing) {
        if ($existing.active) { Invoke-N8n "workflows/$($existing.id)/deactivate" 'Post' @{} | Out-Null }
        $saved = Invoke-N8n "workflows/$($existing.id)" 'Patch' $body
    } else {
        $saved = Invoke-N8n 'workflows' 'Post' $body
    }
    $id = $saved.data.id
    Invoke-N8n "workflows/$id/activate" 'Post' @{ versionId = $saved.data.versionId } | Out-Null
    Write-Host "Published: $Name"
}

$approvalCode = Get-Content (Join-Path $PSScriptRoot 'n8n-approval.js') -Raw
$approvalNodes = @(
    (New-Webhook 'partikulier-listing-approved' $true),
    @{
        id = 'approval'; name = 'Validate and prepare mock delivery'
        type = 'n8n-nodes-base.code'; typeVersion = 2; position = @(250, 0)
        parameters = @{ jsCode = $approvalCode }
    },
    @{
        id = 'mail'; name = 'Capture in Mailpit'
        type = 'n8n-nodes-base.emailSend'; typeVersion = 2.1; position = @(500, 0)
        parameters = @{
            operation = 'send'; fromEmail = 'partikulier@example.test'
            toEmail = 'docker-demo-owner@example.test'; subject = '={{ $json.subject }}'
            emailFormat = 'text'; text = '={{ $json.message }}'; options = @{}
        }
        credentials = @{ smtp = $smtp }
    },
    (New-Respond '={{ { ok: true, delivery: "mailpit" } }}')
)
Set-LocalWorkflow 'Partikulier local - approved listing (Mailpit)' $approvalNodes @{
    'Webhook' = (New-Connection 'Validate and prepare mock delivery')
    'Validate and prepare mock delivery' = (New-Connection 'Capture in Mailpit')
    'Capture in Mailpit' = (New-Connection 'Respond')
}

$buyerCode = Get-Content (Join-Path $PSScriptRoot 'n8n-buyer.js') -Raw
$headerParameters = @('X-Partikulier-Automation', 'X-Partikulier-Timestamp', 'X-Partikulier-Key-Id', 'X-Partikulier-Signature') |
    ForEach-Object { @{ name = $_; value = ('={{ $json.headers["' + $_ + '"] }}') } }
$buyerNodes = @(
    (New-Webhook 'partikulier-buyer-demo'),
    @{
        id = 'sign'; name = 'Sign WordPress request'; type = 'n8n-nodes-base.code'
        typeVersion = 2; position = @(250, 0); parameters = @{ jsCode = $buyerCode }
    },
    @{
        id = 'request'; name = 'WordPress automation API'; type = 'n8n-nodes-base.httpRequest'
        typeVersion = 4.2; position = @(500, 0)
        parameters = @{
            method = 'POST'; url = '={{ $json.url }}'; sendHeaders = $true
            headerParameters = @{ parameters = @($headerParameters) }
            sendBody = $true; contentType = 'raw'; rawContentType = 'application/json'
            body = '={{ $json.body }}'; options = @{ timeout = 15000 }
        }
    },
    (New-Respond '={{ $json }}')
)
Set-LocalWorkflow 'Partikulier local - buyer automation (signed)' $buyerNodes @{
    'Webhook' = (New-Connection 'Sign WordPress request')
    'Sign WordPress request' = (New-Connection 'WordPress automation API')
    'WordPress automation API' = (New-Connection 'Respond')
}

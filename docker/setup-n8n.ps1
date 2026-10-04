param(
    [Parameter(Mandatory)][hashtable]$Settings,
    [switch]$WhatsAppOnly
)

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

function Set-LocalWorkflow($Name, $Nodes, $Connections, [bool]$Draft = $false) {
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
        if ($existing.active -and $Draft) {
            throw "Unpublish '$Name' manually before updating its draft."
        }
        if ($existing.active) { Invoke-N8n "workflows/$($existing.id)/deactivate" 'Post' @{} | Out-Null }
        $saved = Invoke-N8n "workflows/$($existing.id)" 'Patch' $body
    } else {
        $saved = Invoke-N8n 'workflows' 'Post' $body
    }
    $id = $saved.data.id
    if ($Draft) {
        Write-Host "Draft prepared: $Name (publish manually to register with Meta)"
    } else {
        Invoke-N8n "workflows/$id/activate" 'Post' @{ versionId = $saved.data.versionId } | Out-Null
        Write-Host "Published: $Name"
    }
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
if (-not $WhatsAppOnly) {
Set-LocalWorkflow 'Partikulier local - approved listing (Mailpit)' $approvalNodes @{
    'Webhook' = (New-Connection 'Validate and prepare mock delivery')
    'Validate and prepare mock delivery' = (New-Connection 'Capture in Mailpit')
    'Capture in Mailpit' = (New-Connection 'Respond')
}
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
if (-not $WhatsAppOnly) {
Set-LocalWorkflow 'Partikulier local - buyer automation (signed)' $buyerNodes @{
    'Webhook' = (New-Connection 'Sign WordPress request')
    'Sign WordPress request' = (New-Connection 'WordPress automation API')
    'WordPress automation API' = (New-Connection 'Respond')
}
}

$metaKeys = @('META_APP_ID', 'META_APP_SECRET', 'WHATSAPP_BUSINESS_ACCOUNT_ID', 'WHATSAPP_PHONE_NUMBER_ID', 'WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_TEST_RECIPIENT')
$provided = @($metaKeys | Where-Object { $Settings[$_] })
if ($provided.Count -eq 0 -and -not $WhatsAppOnly) { return }
foreach ($key in $metaKeys) {
    if (-not $Settings[$key]) { throw "Missing WhatsApp setting: $key" }
}
foreach ($key in @('META_APP_ID', 'WHATSAPP_BUSINESS_ACCOUNT_ID', 'WHATSAPP_PHONE_NUMBER_ID', 'WHATSAPP_TEST_RECIPIENT')) {
    if ($Settings[$key] -notmatch '^\d+$') { throw "Expected digits only: $key" }
}
if (-not $Settings.N8N_PUBLIC_WEBHOOK_URL -or $Settings.N8N_PUBLIC_WEBHOOK_URL -notmatch '^https://') {
    throw 'Set an HTTPS N8N_PUBLIC_WEBHOOK_URL before preparing the WhatsApp workflow.'
}

$incoming = Set-LocalCredential 'Partikulier WhatsApp incoming' 'whatsAppTriggerApi' @{
    clientId = $Settings.META_APP_ID
    clientSecret = $Settings.META_APP_SECRET
}
$outgoing = Set-LocalCredential 'Partikulier WhatsApp outgoing' 'whatsAppApi' @{
    accessToken = $Settings.WHATSAPP_ACCESS_TOKEN
    businessAccountId = $Settings.WHATSAPP_BUSINESS_ACCOUNT_ID
}
$parse = (Get-Content (Join-Path $PSScriptRoot 'n8n-whatsapp-parse.js') -Raw).Replace('__WHATSAPP_TEST_RECIPIENT__', $Settings.WHATSAPP_TEST_RECIPIENT)
$reply = Get-Content (Join-Path $PSScriptRoot 'n8n-whatsapp-reply.js') -Raw
$whatsAppNodes = @(
    @{
        id = '731e041b-bf9a-4f0c-9f73-6d6b9ab7b9a2'; name = 'WhatsApp Trigger'
        webhookId = '195d58e5-12a8-46d6-8da0-ef7f2e0f8852'
        type = 'n8n-nodes-base.whatsAppTrigger'; typeVersion = 1; position = @(0, 0)
        parameters = @{ updates = @('messages'); options = @{} }
        credentials = @{ whatsAppTriggerApi = $incoming }
    },
    @{
        id = 'parse'; name = 'Parse incoming WhatsApp'; type = 'n8n-nodes-base.code'
        typeVersion = 2; position = @(250, 0); parameters = @{ jsCode = $parse }
    },
    @{
        id = 'route'; name = 'Requires WordPress'; type = 'n8n-nodes-base.if'
        typeVersion = 2.2; position = @(500, 0)
        parameters = @{
            conditions = @{
                options = @{ caseSensitive = $true; typeValidation = 'strict'; version = 2 }
                conditions = @(@{
                    id = 'needs-api'; leftValue = '={{ !!$json.operation }}'; rightValue = ''
                    operator = @{ type = 'boolean'; operation = 'true'; singleValue = $true }
                })
                combinator = 'and'
            }
            options = @{}
        }
    },
    @{
        id = 'buyer'; name = 'Call signed buyer workflow'; type = 'n8n-nodes-base.httpRequest'
        typeVersion = 4.2; position = @(750, -100)
        parameters = @{
            method = 'POST'; url = 'http://127.0.0.1:5678/webhook/partikulier-buyer-demo'
            authentication = 'genericCredentialType'; genericAuthType = 'httpHeaderAuth'
            sendBody = $true; specifyBody = 'json'
            jsonBody = '={{ { operation: $json.operation, payload: $json.payload } }}'
            options = @{ timeout = 20000 }
        }
        credentials = @{ httpHeaderAuth = $header }
    },
    @{
        id = 'reply'; name = 'Prepare WhatsApp response'; type = 'n8n-nodes-base.code'
        typeVersion = 2; position = @(1000, -100); parameters = @{ jsCode = $reply }
    },
    @{
        id = 'send'; name = 'Send WhatsApp reply'; type = 'n8n-nodes-base.whatsApp'
        typeVersion = 1.1; position = @(1250, 0)
        parameters = @{
            resource = 'message'; operation = 'send'; messageType = 'text'
            phoneNumberId = $Settings.WHATSAPP_PHONE_NUMBER_ID
            recipientPhoneNumber = '={{ $json.recipient }}'
            textBody = '={{ $json.reply }}'; additionalFields = @{}
        }
        credentials = @{ whatsAppApi = $outgoing }
    },
    @{
        id = 'instructions'; name = 'Test instructions'; type = 'n8n-nodes-base.stickyNote'
        typeVersion = 1; position = @(0, 250)
        parameters = @{
            width = 650; height = 280
            content = "## Real WhatsApp test (draft)`nPublish manually to register the Meta webhook using the public ngrok URL. Only the configured test recipient is accepted.`n`nSend TEST for help, a PK-reference to request an owner contact, or STOP to opt out. Delivery-status events and duplicate business messages do not produce replies.`n`nWordPress calls go through the existing HMAC-signed buyer workflow. No R3 qualification or Sheets export is included. Execution payloads are not saved."
        }
    }
)
Set-LocalWorkflow 'Partikulier WhatsApp - real phone test (WordPress)' $whatsAppNodes @{
    'WhatsApp Trigger' = (New-Connection 'Parse incoming WhatsApp')
    'Parse incoming WhatsApp' = (New-Connection 'Requires WordPress')
    'Requires WordPress' = @{
        main = ,@(@{ node = 'Call signed buyer workflow'; type = 'main'; index = 0 }) + ,@(@{ node = 'Send WhatsApp reply'; type = 'main'; index = 0 })
    }
    'Call signed buyer workflow' = (New-Connection 'Prepare WhatsApp response')
    'Prepare WhatsApp response' = (New-Connection 'Send WhatsApp reply')
} $true

param(
    [string]$EnvFile = '.env',
    [string]$SiteUrl = 'http://localhost:8099',
    [string]$N8nUrl = 'http://localhost:5678',
    [string]$MailpitUrl = 'http://localhost:8025',
    [string]$ComposeProject = 'partikulier'
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)
$EnvFile = (Resolve-Path $EnvFile).Path
$settings = @{}
Get-Content $EnvFile | ForEach-Object {
    if ($_ -match '^([^#=]+)=(.*)$') { $settings[$matches[1]] = $matches[2] }
}

function Assert-Local($Condition, $Message) {
    if (-not $Condition) { throw $Message }
    Write-Host "PASS: $Message"
}

Assert-Local ((Invoke-RestMethod "$SiteUrl/wp-json/partikulier/v1/health").status -eq 'ok') 'WordPress health'
Assert-Local ((Invoke-RestMethod "$N8nUrl/healthz").status -eq 'ok') 'n8n health'
foreach ($language in @('fr', 'en', 'ar')) {
    $page = Invoke-WebRequest "$SiteUrl/$language/" -UseBasicParsing
    Assert-Local ($page.StatusCode -eq 200) "Homepage $language"
    $catalogue = Invoke-WebRequest "$SiteUrl/$language/annonces/" -UseBasicParsing
    Assert-Local ($catalogue.StatusCode -eq 200) "Catalogue $language"
    if ($language -eq 'ar') {
        Assert-Local ($page.Content -match 'dir=["'']rtl["'']') 'Arabic RTL'
    }
}

$initialMessages = (Invoke-RestMethod "$MailpitUrl/api/v1/messages").total
$output = docker compose --env-file $EnvFile -p $ComposeProject exec -T -u www-data wordpress wp eval-file /repo/docker/verify-local-example.php
if ($LASTEXITCODE -ne 0) { throw 'Listing approval failed.' }
$fixture = ($output -join "`n") | ConvertFrom-Json
Assert-Local ($fixture.delivery -eq 'sent') 'WordPress -> HTTPS n8n -> Mailpit approval'
$messages = Invoke-RestMethod "$MailpitUrl/api/v1/messages"
Assert-Local ($messages.total -gt $initialMessages) 'Approval email captured'
$latest = @($messages.messages | Where-Object Subject -eq "Partikulier local approval #$($fixture.listing_id)")[0]
Assert-Local ($null -ne $latest) 'Captured email belongs to the example listing'
$email = Invoke-RestMethod "$MailpitUrl/api/v1/message/$($latest.ID)"
if ($fixture.send_credentials) {
    Assert-Local ($email.Text -match 'Login: docker-demo-owner' -and $email.Text -match 'Password: \S+') 'First approval delivers valid owner credentials'
}
$repeatOutput = docker compose --env-file $EnvFile -p $ComposeProject exec -T -u www-data wordpress wp eval-file /repo/docker/verify-local-example.php
if ($LASTEXITCODE -ne 0) { throw 'Repeat approval failed.' }
$repeat = ($repeatOutput -join "`n") | ConvertFrom-Json
Assert-Local (-not $repeat.send_credentials) 'Repeat approval preserves credentials'

$headers = @{ 'X-Partikulier-Automation' = $settings.PARTIKULIER_N8N_SECRET }
$run = [Guid]::NewGuid().ToString('N')
$phone = '212699' + (Get-Random -Minimum 100000 -Maximum 999999)
function Invoke-Buyer($Operation, $Payload) {
    $body = @{ operation = $Operation; payload = $Payload } | ConvertTo-Json -Depth 8 -Compress
    Invoke-RestMethod "$N8nUrl/webhook/partikulier-buyer-demo" -Method Post -ContentType application/json -Headers $headers -Body $body
}
$contact = @{
    wa_id = $phone; reference = $fixture.reference; provider_message_id = "local-contact-$run"
}
$response = Invoke-Buyer 'contact-authorization' $contact
Assert-Local ($response.allowed -eq $false -and $response.reason -eq 'need_qualification') 'New buyer requires explicit qualification'
$response = Invoke-Buyer 'qualification' @{ wa_id = $phone; is_particulier = $true; provider_message_id = "local-qualification-$run" }
Assert-Local ($response.qualified -eq 'particulier') 'Signed buyer qualification saved'
$response = Invoke-Buyer 'contact-authorization' $contact
Assert-Local ($response.allowed -eq $true) 'n8n -> WordPress HMAC contact authorization'
$response = Invoke-Buyer 'contact-authorization' $contact
Assert-Local ($response.reason -eq 'duplicate_message') 'Duplicate message rejected safely'
$response = Invoke-Buyer 'preferences' @{
    wa_id = $phone; budget_max = 1400000; areas = @('Maarif'); layout = '2 bedrooms + living room'; transaction = 'Achat'
}
Assert-Local ($response.updated -eq $true) 'Buyer preferences saved'
$response = Invoke-Buyer 'consent' @{
    wa_id = $phone; scope = 'similar_listings'; granted = $true; provider_message_id = "local-consent-$run"
}
Assert-Local ($response.consent -eq 'granted') 'Explicit consent saved'
$response = Invoke-Buyer 'opt-out' @{ wa_id = $phone; provider_message_id = "local-stop-$run" }
Assert-Local ($response.processed -and $response.known_lead) 'STOP processed'
$response = Invoke-Buyer 'contact-authorization' @{
    wa_id = $phone; reference = $fixture.reference; provider_message_id = "local-after-stop-$run"
}
Assert-Local ($response.reason -eq 'opted_out') 'STOP blocks later contact'

try {
    Invoke-RestMethod "$SiteUrl/wp-json/partikulier/v1/preferences" -Method Post -ContentType application/json -Headers $headers -Body (@{wa_id=$phone} | ConvertTo-Json) | Out-Null
    throw 'Unsigned WordPress request was unexpectedly accepted.'
} catch {
    if (-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 401) { throw }
    Write-Host 'PASS: WordPress rejects unsigned automation requests'
}
try {
    Invoke-RestMethod "$N8nUrl/webhook/partikulier-buyer-demo" -Method Post -ContentType application/json -Body '{}' | Out-Null
    throw 'Unauthenticated n8n webhook was unexpectedly accepted.'
} catch {
    if (-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 403) { throw }
    Write-Host 'PASS: n8n rejects unauthenticated webhook requests'
}
Write-Host "`nWorking example: $($fixture.url)"
Write-Host 'Owner login: docker-demo-owner. Its first-approval password is in Mailpit, not console output.'

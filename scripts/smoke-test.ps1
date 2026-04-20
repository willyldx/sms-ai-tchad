param(
    [string]$BaseUrl = "http://localhost",
    [string]$WebhookSecret = "change_me",
    [string]$Phone = "+23566000000"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Write-Host "[1/4] Test AI health endpoint..."
$health = Invoke-RestMethod -Method GET -Uri "$BaseUrl/ai/health"
if ($health.status -ne "ok") {
    throw "AI health check failed"
}

Write-Host "[2/4] Test SMS webhook happy path..."
$body = @{ from = $Phone; message = "Donne une capitale africaine" } | ConvertTo-Json
$reply = Invoke-RestMethod -Method POST -Uri "$BaseUrl/api/sms/incoming" -Headers @{
    "Content-Type" = "application/json"
    "X-SMS-Webhook-Secret" = $WebhookSecret
} -Body $body
if (-not $reply.reply) {
    throw "SMS webhook returned no reply"
}

Write-Host "[3/4] Test SMS deduplication..."
$reply2 = Invoke-RestMethod -Method POST -Uri "$BaseUrl/api/sms/incoming" -Headers @{
    "Content-Type" = "application/json"
    "X-SMS-Webhook-Secret" = $WebhookSecret
} -Body $body
if (-not $reply2.reply) {
    throw "Dedup test returned no reply"
}

Write-Host "[4/4] Test unauthorized access..."
$unauthorizedOk = $false
try {
    Invoke-RestMethod -Method POST -Uri "$BaseUrl/api/sms/incoming" -Headers @{
        "Content-Type" = "application/json"
    } -Body $body
} catch {
    if ($_.Exception.Response.StatusCode.Value__ -eq 401) {
        $unauthorizedOk = $true
    }
}

if (-not $unauthorizedOk) {
    throw "Unauthorized test failed: endpoint accepted request without secret"
}

Write-Host "Smoke test OK."

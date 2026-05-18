#!/usr/bin/env sh
set -eu

BASE_URL="${1:-http://localhost}"
SMS_WEBHOOK_SECRET="${2:-}"

if [ -z "$SMS_WEBHOOK_SECRET" ]; then
    echo "Usage: $0 <base-url> <sms-webhook-secret>"
    exit 2
fi

echo "[1/3] Nginx health"
curl -fsS "$BASE_URL/health"

echo "[2/3] AI service health"
curl -fsS "$BASE_URL/ai/health"

echo "[3/3] SMS webhook"
curl -fsS \
    -X POST "$BASE_URL/api/sms/incoming" \
    -H "Content-Type: application/json" \
    -H "X-SMS-Webhook-Secret: $SMS_WEBHOOK_SECRET" \
    -d '{"from":"+23566000000","message":"Bonjour, donne une phrase de test."}'

echo
echo "Smoke test OK"

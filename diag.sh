#!/bin/bash
set -e

echo "=== TEST 1: AI Service depuis le backend container ==="
sudo docker exec sms-ai-tchad-backend-1 php -r "
\$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => 'Content-Type: application/json',
    'content' => '{\"question\":\"dis bonjour\"}',
    'timeout' => 10,
]]);
\$result = @file_get_contents('http://ai-service:8000/ask', false, \$ctx);
echo \$result ? \$result : 'ECHEC: impossible de joindre ai-service';
echo PHP_EOL;
"

echo ""
echo "=== TEST 2: Webhook complet depuis le VPS ==="
sudo docker exec sms-ai-tchad-nginx-1 sh -c "
wget -q -O- \
  --post-data='{\"from\":\"+23480000000\",\"message\":\"dis bonjour\"}' \
  --header='Content-Type: application/json' \
  --header='X-SMS-Webhook-Secret: sms_ai_webhook_secret_secure_2026' \
  http://backend:9000/api/sms/incoming 2>&1 || echo 'wget failed'
" 2>&1 || true

echo ""
echo "=== TEST 3: Logs backend (15 dernieres lignes) ==="
sudo docker compose -f ~/sms-ai-tchad/sms-ai-tchad/docker-compose.yml logs --tail=15 backend

echo ""
echo "=== TEST 4: Logs ai-service (5 dernieres lignes) ==="
sudo docker compose -f ~/sms-ai-tchad/sms-ai-tchad/docker-compose.yml logs --tail=5 ai-service

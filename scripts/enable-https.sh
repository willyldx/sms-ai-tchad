#!/usr/bin/env bash
set -euo pipefail

if [ -z "${1:-}" ] || [ -z "${2:-}" ]; then
    echo "Usage: $0 <domain> <email>"
    echo "Example: $0 sms.example.com admin@example.com"
    exit 1
fi

DOMAIN="$1"
EMAIL="$2"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="$PROJECT_ROOT/docker-compose.yml"
NGINX_CONF="$PROJECT_ROOT/infra/nginx/default.conf"
TLS_TEMPLATE="$PROJECT_ROOT/infra/nginx/default.tls.conf"

if [ ! -f "$TLS_TEMPLATE" ]; then
    echo "Error: TLS template not found: $TLS_TEMPLATE"
    exit 1
fi

echo "[1/6] Ensure stack is running..."
docker compose -f "$COMPOSE_FILE" up -d nginx

echo "[2/6] Request Let's Encrypt certificate for $DOMAIN..."
docker compose -f "$COMPOSE_FILE" run --rm certbot \
    certonly --webroot -w /var/www/certbot \
    -d "$DOMAIN" --email "$EMAIL" --agree-tos --no-eff-email

echo "[3/6] Switch Nginx config to TLS template..."
sed "s/__DOMAIN__/$DOMAIN/g" "$TLS_TEMPLATE" > "$NGINX_CONF"

echo "[4/6] Reload Nginx..."
docker compose -f "$COMPOSE_FILE" restart nginx

echo "[5/6] Verify HTTPS endpoint..."
sleep 3
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://$DOMAIN/health" --max-time 20)
if [ "$HTTP_CODE" != "200" ]; then
    echo "Error: HTTPS verification failed (got HTTP $HTTP_CODE)"
    exit 1
fi

echo "[6/6] HTTPS enabled successfully for $DOMAIN"

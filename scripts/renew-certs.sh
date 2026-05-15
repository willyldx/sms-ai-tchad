#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="$PROJECT_ROOT/docker-compose.yml"

echo "Renewing certificates with certbot..."
docker compose -f "$COMPOSE_FILE" run --rm certbot renew

echo "Reloading Nginx..."
docker compose -f "$COMPOSE_FILE" exec nginx nginx -s reload

echo "Certificate renewal finished."

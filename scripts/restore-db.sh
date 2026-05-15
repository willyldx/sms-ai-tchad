#!/usr/bin/env bash
set -euo pipefail

if [ -z "${1:-}" ]; then
    echo "Usage: $0 <dump-file>"
    echo "Example: $0 ./backups/sms_ai-20260101-120000.sql"
    exit 1
fi

DUMP_FILE="$1"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="$PROJECT_ROOT/docker-compose.yml"

if [ ! -f "$DUMP_FILE" ]; then
    echo "Error: Dump file not found: $DUMP_FILE"
    exit 1
fi

echo "Restoring database from: $DUMP_FILE"
cat "$DUMP_FILE" | docker compose -f "$COMPOSE_FILE" exec -T db sh -lc \
    'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'

echo "Restore done."

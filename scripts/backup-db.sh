#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${1:-./backups}"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_FILE="$PROJECT_ROOT/docker-compose.yml"
OUTPUT_DIR="$PROJECT_ROOT/$BACKUP_DIR"

mkdir -p "$OUTPUT_DIR"

TIMESTAMP=$(date +"%Y%m%d-%H%M%S")
DUMP_FILE="$OUTPUT_DIR/sms_ai-$TIMESTAMP.sql"

echo "Creating MariaDB dump: $DUMP_FILE"
docker compose -f "$COMPOSE_FILE" exec -T db sh -lc \
    'exec mysqldump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > "$DUMP_FILE"

echo "Backup done: $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"

#!/bin/sh
# Ежедневный backup PostgreSQL → /backups (named volume) + опционально rclone в S3-совместимое хранилище.
# Запускается из cron внутри backup-контейнера.

# pipefail ОБЯЗАТЕЛЬНО: без него `pg_dump | gzip > file` маскирует падение
# pg_dump (exit code пайпа = exit code gzip, который пишет валидный пустой
# gzip и возвращает 0). Результат: молча создаётся «бэкап» из 0 байт данных.
# Alpine ash (busybox 1.30+) поддерживает pipefail.
set -eu
set -o pipefail

TIMESTAMP=$(date +%F_%H%M)
BACKUP_DIR="/backups"
FILE="${BACKUP_DIR}/excel_${TIMESTAMP}.sql.gz"
RETENTION_LOCAL_DAYS="${BACKUP_RETENTION_LOCAL_DAYS:-30}"

mkdir -p "$BACKUP_DIR"

echo "[backup $(date -u +%FT%TZ)] starting pg_dump → $FILE"
PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h "${DB_HOST:-postgres}" \
    -p "${DB_PORT:-5432}" \
    -U "${DB_USERNAME:-excel_user}" \
    -d "${DB_DATABASE:-excel_db}" \
    --no-owner --no-privileges \
    | gzip -9 > "$FILE"

SIZE=$(stat -c %s "$FILE" 2>/dev/null || stat -f %z "$FILE")
echo "[backup] dump done: $(($SIZE / 1024)) KB"

# Целостность gzip — гарантия что pg_dump не оборвался посередине
# (truncated gzip имеет валидный header, но `gunzip -t` ловит обрыв
# в конце). Без этой проверки можно годами хранить «бэкап», который
# нельзя восстановить.
if ! gzip -t "$FILE" 2>/dev/null; then
    echo "[backup] FATAL: gzip integrity check failed for $FILE — удаляю битый дамп" >&2
    rm -f "$FILE"
    exit 1
fi

# Off-site через rclone — если настроена. Сегмент BACKUP_RCLONE_REMOTE
# должен иметь вид `s3-remote:bucket/path` (имя remote'а из rclone config).
# Если переменная пустая — пропускаем, держим только локальный бэкап.
if [ -n "${BACKUP_RCLONE_REMOTE:-}" ]; then
    if command -v rclone >/dev/null 2>&1; then
        echo "[backup] uploading to $BACKUP_RCLONE_REMOTE"
        rclone copy "$FILE" "$BACKUP_RCLONE_REMOTE/" --quiet || \
            echo "[backup] WARN rclone upload failed (saved locally)"
    else
        echo "[backup] WARN rclone not installed, BACKUP_RCLONE_REMOTE ignored"
    fi
fi

# Retention: чистим локальные бэкапы старше N дней.
echo "[backup] pruning local files older than ${RETENTION_LOCAL_DAYS} days"
find "$BACKUP_DIR" -name 'excel_*.sql.gz' -type f -mtime "+${RETENTION_LOCAL_DAYS}" -delete

echo "[backup] complete"

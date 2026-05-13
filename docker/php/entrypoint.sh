#!/usr/bin/env bash
# Запускается при старте app-контейнера (см. ENTRYPOINT в Dockerfile).
# Цель: синхронно довести базу/кэш до рабочего состояния, потом передать
# управление основному процессу (php-fpm).

set -euo pipefail

cd /var/www/html

log() { echo "[entrypoint] $*"; }

# 1. Ждём PostgreSQL. compose-сервис называется postgres; депенды-он не
# гарантирует, что postgres УЖЕ принимает коннекты, поэтому ждём явно.
log "Waiting for PostgreSQL at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
for i in $(seq 1 60); do
    if php -r "
        \$dsn = 'pgsql:host=' . (getenv('DB_HOST') ?: 'postgres')
              . ';port=' . (getenv('DB_PORT') ?: '5432')
              . ';dbname=' . (getenv('DB_DATABASE') ?: 'excel_db');
        try {
            new PDO(\$dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'),
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 2]);
            exit(0);
        } catch (Throwable \$e) { exit(1); }
    " 2>/dev/null; then
        log "PostgreSQL reachable."
        break
    fi
    if [ "$i" = "60" ]; then
        log "PostgreSQL did not respond in 60s — giving up."
        exit 1
    fi
    sleep 1
done

# 2. Ждём Redis (быстро, обычно сразу готов).
log "Waiting for Redis at ${REDIS_HOST:-redis}:${REDIS_PORT:-6379}..."
for i in $(seq 1 30); do
    if php -r "
        \$fp = @stream_socket_client('tcp://' . (getenv('REDIS_HOST') ?: 'redis')
                                     . ':' . (getenv('REDIS_PORT') ?: '6379'),
                                     \$en, \$es, 2);
        if (\$fp) { fclose(\$fp); exit(0); } exit(1);
    " 2>/dev/null; then
        log "Redis reachable."
        break
    fi
    sleep 1
done

# 3. APP_KEY: если пустой — сгенерируем. Иначе уважаем то что в .env.
if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY:-}" = "base64:" ]; then
    log "APP_KEY is empty; generating one (will be regenerated on every restart unless persisted in .env)."
    php artisan key:generate --force --show > /tmp/appkey 2>/dev/null || true
    if [ -s /tmp/appkey ]; then
        export APP_KEY="$(cat /tmp/appkey)"
        log "Generated ephemeral APP_KEY. Set APP_KEY in .env to persist sessions across restarts."
    fi
fi

# 4. Pre-migration backup. ЕСЛИ таблицы уже есть (это апдейт, не первый деплой)
# — снимаем pg_dump в /var/www/html/storage/pre-migration-backups до накатывания
# миграций. Если миграция испортит данные / упадёт посредине / понадобится
# rollback — есть откуда восстановить ровно тот state, что был ДО.
# Skip на первом деплое (миграций ещё нет → нечего бэкапить).
BACKUP_DIR="/var/www/html/storage/pre-migration-backups"
mkdir -p "$BACKUP_DIR" 2>/dev/null || true
PENDING_COUNT=$(php artisan migrate:status --pending 2>/dev/null | grep -c "Pending" || echo 0)
TABLES_EXIST=$(php -r "
    \$dsn = 'pgsql:host=' . (getenv('DB_HOST') ?: 'postgres')
          . ';port=' . (getenv('DB_PORT') ?: '5432')
          . ';dbname=' . (getenv('DB_DATABASE') ?: 'excel_db');
    try {
        \$pdo = new PDO(\$dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
        \$n = \$pdo->query(\"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'\")->fetchColumn();
        echo \$n;
    } catch (Throwable \$e) { echo 0; }
" 2>/dev/null)
if [ "${TABLES_EXIST:-0}" -gt 0 ] && [ "${PENDING_COUNT:-0}" -gt 0 ]; then
    TS=$(date +%F_%H%M%S)
    DUMP="${BACKUP_DIR}/pre-migrate_${TS}.sql.gz"
    log "Pending migrations detected → backing up DB to ${DUMP}"
    DUMP_ERR_LOG="${BACKUP_DIR}/pre-migrate_${TS}.err"
    # ВАЖНО: stderr НЕ глушим — пишем в .err файл, чтобы ошибка была видна
    # в логах деплоя. Если pg_dump упал — НЕ запускаем миграцию, иначе
    # катастрофа: миграция испортит данные и восстановить их будет нечем.
    if PGPASSWORD="${DB_PASSWORD}" pg_dump \
            -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" \
            -U "${DB_USERNAME:-excel_user}" -d "${DB_DATABASE:-excel_db}" \
            --no-owner --no-privileges 2>"$DUMP_ERR_LOG" | gzip > "$DUMP"; then
        DUMP_SIZE=$(stat -c %s "$DUMP" 2>/dev/null || stat -f %z "$DUMP" 2>/dev/null || echo 0)
        if [ "${DUMP_SIZE:-0}" -lt 1024 ]; then
            # Дамп подозрительно маленький (меньше 1 КБ) — что-то пошло не так.
            log "FATAL: pg_dump exit code 0, но размер дампа ${DUMP_SIZE} байт. Это меньше КБ — что-то сломалось."
            log "FATAL: stderr pg_dump'а:"
            cat "$DUMP_ERR_LOG" >&2 || true
            log "FATAL: ОТКАЗЫВАЕМСЯ запускать миграцию, чтобы не уничтожить данные."
            log "FATAL: Разберись с pg_dump, удали неполный дамп ${DUMP}, перезапусти контейнер."
            exit 1
        fi
        # Проверка целостности gzip: truncated дамп проходит size-чек, но
        # `gunzip -t` ловит обрыв в конце. Без этой проверки можно хранить
        # «бэкап», который нельзя восстановить (катастрофа при rollback).
        if ! gzip -t "$DUMP" 2>/dev/null; then
            log "FATAL: pre-migration dump gzip-integrity check FAILED — ${DUMP} битый."
            log "FATAL: stderr pg_dump'а:"
            cat "$DUMP_ERR_LOG" >&2 || true
            log "FATAL: ОТКАЗЫВАЕМСЯ запускать миграцию без валидного бэкапа."
            rm -f "$DUMP"
            exit 1
        fi
        log "Pre-migration backup OK (${DUMP_SIZE} bytes, gzip valid)"
        rm -f "$DUMP_ERR_LOG"
        # Чистим pre-migration снимки старше 14 дней.
        find "$BACKUP_DIR" -name 'pre-migrate_*.sql.gz' -mtime +14 -delete 2>/dev/null || true
        find "$BACKUP_DIR" -name 'pre-migrate_*.err'   -mtime +14 -delete 2>/dev/null || true
    else
        log "FATAL: pg_dump УПАЛ. Подробности:"
        cat "$DUMP_ERR_LOG" >&2 || true
        log "FATAL: ОТКАЗЫВАЕМСЯ запускать миграцию без бэкапа."
        log "FATAL: Возможные причины:"
        log "FATAL:   • Postgres недоступен (проверь host/port/credentials)"
        log "FATAL:   • Кончилось место на диске (df -h в backup-data volume)"
        log "FATAL:   • DB_PASSWORD в .env не совпадает с postgres-volume'ом"
        log "FATAL: Реши проблему и перезапусти контейнер."
        exit 1
    fi
else
    log "First deploy or no pending migrations — skipping pre-migration backup."
fi

# 5. Миграции. --force нужно потому что APP_ENV=production.
# migrate идемпотентен: новые миграции накатятся, старые пропустятся.
log "Running migrations..."
php artisan migrate --force --no-interaction

# 5. Кэшируем конфиг/роуты/views. Каждый контейнер делает это для себя.
log "Caching config/routes/views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

log "Boot complete. Starting: $*"
exec "$@"

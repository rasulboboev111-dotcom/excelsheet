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

# 4. Миграции. --force нужно потому что APP_ENV=production.
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

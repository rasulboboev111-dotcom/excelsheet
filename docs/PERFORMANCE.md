# Производительность: серверные настройки

Кодовые оптимизации (lodash, deepClone, индексы, performance-миграция) уже в коде.
Этот файл — серверная часть, которую нельзя автоматически применить из репозитория.
Делается **один раз** при настройке dev-машины или прода.

---

## 1. OPcache (PHP в 3-5 раз быстрее)

### Windows (текущая dev-машина)

В сборке `C:\php82\` сейчас **нет DLL OPcache** — нужно поставить вручную.

1. Скачать `php_opcache.dll` для PHP 8.2 **Thread Safe x64** (под версию PHP):
   https://windows.php.net/downloads/releases/archives/
   Найти архив `php-8.2.X-Win32-vs16-x64.zip`, распаковать только `ext/php_opcache.dll`.

2. Положить файл в `C:\php82\ext\php_opcache.dll`.

3. В `C:\php82\php.ini` раскомментировать и поправить (строки 964, 1784+):

```ini
zend_extension=opcache

opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1     ; на dev — 1 (PHP подхватывает изменения)
opcache.revalidate_freq=2
opcache.save_comments=1
opcache.fast_shutdown=1
```

4. Перезапустить `php artisan serve` (или web-сервер).

5. Проверить:
   ```bash
   php -r "var_dump(extension_loaded('Zend OPcache'));"
   ```
   Должно быть `bool(true)`.

### Linux (продакшен)

Обычно уже стоит. Проверить:
```bash
php -m | grep -i opcache
```

Если нет:
```bash
sudo apt install php8.2-opcache    # Debian/Ubuntu
```

В `/etc/php/8.2/fpm/conf.d/10-opcache.ini`:
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0      ; на проде — 0 (читать из памяти)
opcache.save_comments=1
opcache.fast_shutdown=1
```

После любого деплоя на проде нужен сброс кэша:
```bash
sudo systemctl reload php8.2-fpm
# или, если используется laravel-opcache:
php artisan opcache:clear
```

---

## 2. Redis (кэш + сессии)

### Установка

**Windows (dev):** https://github.com/microsoftarchive/redis/releases — скачать MSI, установить как службу.

**Linux (prod):**
```bash
sudo apt install redis-server
sudo systemctl enable --now redis-server
```

Проверить работу:
```bash
redis-cli ping    # → PONG
```

### Подключение к Laravel

```bash
cd /path/to/project
composer require predis/predis
```

В `.env`:
```env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

Сбросить конфиг и проверить:
```bash
php artisan config:clear
php artisan tinker --execute="cache()->put('ping', 'pong', 60); echo cache()->get('ping');"
# → pong
```

### Если не хочешь Redis на dev

Оставь `CACHE_DRIVER=file`/`SESSION_DRIVER=file` локально, а на проде в `.env`
поставь `redis`. Влияет только на латентность, ни на что больше.

---

## 3. nginx + PHP-FPM (только для прода)

`php artisan serve` — это встроенный dev-сервер, **не для продакшена**. На проде:

```nginx
# /etc/nginx/sites-available/excel
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/excel/public;

    index index.php;
    charset utf-8;

    # Gzip — JS сжимается в 3-4 раза.
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types
        application/javascript
        application/json
        application/xml
        text/css
        text/html
        text/plain
        text/xml;

    # Vite-ассеты — content-hashed, можно кэшировать на год.
    location /build/ {
        add_header Cache-Control "public, max-age=31536000, immutable";
        expires 1y;
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;

        # Защита от больших импортов: должно совпадать с
        # SheetController::MAX_IMPORT_BODY_BYTES (50 МБ).
        client_max_body_size 60M;
    }

    # Запрет к скрытым файлам.
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Активация:
```bash
sudo ln -s /etc/nginx/sites-available/excel /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### PHP-FPM пул (`/etc/php/8.2/fpm/pool.d/www.conf`)

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 15
pm.max_requests = 500

; post_max_size согласован с nginx client_max_body_size:
php_admin_value[post_max_size] = 60M
php_admin_value[upload_max_filesize] = 60M
php_admin_value[memory_limit] = 256M
```

---

## 4. PostgreSQL тюнинг (только для прода с большими объёмами)

В `/etc/postgresql/16/main/postgresql.conf`:
```
shared_buffers = 256MB           ; ~25% RAM сервера
effective_cache_size = 1GB       ; ~75% RAM
work_mem = 16MB
maintenance_work_mem = 128MB
random_page_cost = 1.1           ; SSD
```

Перезапуск:
```bash
sudo systemctl restart postgresql
```

Запустить `VACUUM ANALYZE` после первого крупного импорта:
```bash
psql excel_db -c "VACUUM ANALYZE sheet_data; VACUUM ANALYZE sheet_audit_logs;"
```

---

## 5. Команды деплоя на прод (в нужном порядке)

```bash
cd /var/www/excel
git pull origin main

# Если меняли composer.json:
composer install --optimize-autoloader --no-dev

# Если меняли package.json (билд уже в репо, обычно пропускается):
# npm ci && npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Reload PHP-FPM (важно при OPcache validate_timestamps=0):
sudo systemctl reload php8.2-fpm
```

**Откат** (если что-то сломалось):
```bash
git reset --hard <previous-commit>
php artisan migrate:rollback
php artisan config:clear   # очень важно — иначе закэшированный config из старого тега подцепится
sudo systemctl reload php8.2-fpm
```

---

## 6. Проверка что всё работает

### OPcache работает
```bash
php -r "print_r(opcache_get_status(false)['memory_usage']);"
```

### Redis работает
```bash
php artisan tinker --execute="cache()->put('test', '1', 60); echo cache()->get('test');"
# → 1
```

### Индексы видны планировщику
```sql
EXPLAIN ANALYZE
SELECT * FROM sheet_audit_logs
WHERE sheet_id = 1 ORDER BY created_at DESC LIMIT 50;
-- В плане должно быть Index Scan / Index Only Scan, НЕ Seq Scan.
```

### gzip работает (на проде)
```bash
curl -s -o /dev/null -D - https://your-domain.com/build/assets/Dashboard-XXXXX.js \
  -H "Accept-Encoding: gzip" | grep -i content-encoding
# → content-encoding: gzip
```

### Bundle отдаётся с year-long кэшем
```bash
curl -s -o /dev/null -D - https://your-domain.com/build/assets/Dashboard-XXXXX.js \
  | grep -i cache-control
# → cache-control: public, max-age=31536000, immutable
```

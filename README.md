# Excel Tojiktelecom

Веб-приложение для совместной работы с таблицами в браузере (как Google Sheets, но self-hosted): импорт/экспорт `.xlsx`, формулы, права доступа на каждый лист, журнал изменений, отправка таблиц по email через Gmail.

**Стек:** Laravel 12 + Inertia 2 + Vue 3 + AG Grid + PostgreSQL + Redis + Nginx + PHP-FPM.

---

## 🐳 Деплой через Docker (5 минут, рекомендуется)

Если у тебя есть **любой** сервер с Docker — это **самый простой путь**. Никакой ручной установки PHP/PostgreSQL/Redis/nginx.

### Быстрый старт

```bash
# 1. Поставить Docker, если ещё нет
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER && newgrp docker

# 2. Склонировать репо
git clone https://github.com/ТВОЙ_АККАУНТ/excel.git
cd excel

# 3. Создать .env из шаблона и заполнить ⚠-поля
cp .env.docker.example .env
nano .env
# Минимум что нужно поставить:
#   APP_URL          — твой домен (или http://IP-сервера)
#   DB_PASSWORD      — длинный случайный пароль
#   ADMIN_EMAIL      — почта для входа админа
#   ADMIN_PASSWORD   — длинный случайный пароль

# 4. Сгенерировать APP_KEY (можно прямо в .env через docker)
docker compose run --rm app php artisan key:generate --show
# Скопируй полученное base64:... в .env как APP_KEY=base64:...

# 5. Запустить весь стек (постгрес, редис, php-fpm, nginx, scheduler)
docker compose up -d --build

# 6. Подождать ~30 секунд (миграции накатываются автоматически в entrypoint),
#    проверить что всё стартовало
docker compose ps
docker compose logs app | tail -20
# Должно быть "Boot complete. Starting: php-fpm --nodaemonize"

# Готово. Открой http://server-ip в браузере.
# Войди под ADMIN_EMAIL / ADMIN_PASSWORD из .env.
```

### Что произошло

`docker compose up -d --build` собрал и запустил 5 контейнеров:

| Сервис | Образ | Что делает |
|---|---|---|
| `app` | `excel-app:latest` (наш Dockerfile) | PHP-FPM 8.2 + composer-зависимости + OPcache |
| `nginx` | `excel-nginx:latest` (наш Dockerfile) | nginx 1.27 со статикой из public/ + проксит на app:9000 |
| `postgres` | `postgres:16-alpine` | База данных |
| `redis` | `redis:7-alpine` | Cache + sessions |
| `scheduler` | `excel-app:latest` | Бесконечный цикл `php artisan schedule:run` (для audit-cleanup) |

При первом старте `app`-контейнер автоматически:
1. Подождёт PostgreSQL (до 60 сек).
2. Накатит все миграции (`php artisan migrate --force`) — включая создание админа из `.env`.
3. Закэширует config/routes/views.
4. Запустит php-fpm.

При **повторных** перезапусках (`docker compose restart` или после `docker compose up -d --build`) — то же самое: миграции идемпотентны, новые накатятся, старые пропустятся.

### Управление

```bash
docker compose logs -f app             # хвост логов Laravel
docker compose logs -f nginx           # access/error log nginx
docker compose exec app bash           # shell в контейнере app
docker compose exec app php artisan tinker
docker compose exec postgres psql -U excel_user excel_db
docker compose exec redis redis-cli

docker compose restart app             # рестарт только app (после правки .env)
docker compose down                    # остановить (ДАННЫЕ СОХРАНЯЮТСЯ в volumes)
docker compose down -v                 # ⚠ ОПАСНО: удалить + базу + redis
```

### Обновление (новый код)

```bash
cd /path/to/excel
git pull origin main
docker compose up -d --build           # пересобирает образ + перезапускает
# Миграции и кэши обновятся автоматически при старте app-контейнера.
```

### Бэкапы

```bash
# БД
docker compose exec postgres pg_dump -U excel_user excel_db | gzip > backup_$(date +%F).sql.gz

# Восстановление
gunzip < backup_2026-05-05.sql.gz | docker compose exec -T postgres psql -U excel_user excel_db
```

Положи в cron на хосте:
```cron
0 3 * * * cd /path/to/excel && docker compose exec -T postgres pg_dump -U excel_user excel_db | gzip > /var/backups/excel_$(date +\%F).sql.gz
0 4 * * * find /var/backups -name 'excel_*.sql.gz' -mtime +14 -delete
```

### HTTPS

Самый простой вариант — **Cloudflare**: включи бесплатный proxy для домена, поставь SSL/TLS = Full. Cloudflare сам выдаёт сертификат и шифрует трафик до сервера. Контейнер nginx работает на 80 без изменений.

Если без Cloudflare — поставь **Caddy/Traefik** на хосте перед docker, или используй certbot со standalone-режимом и пропихни сертификат в контейнер через volume. Подробнее — [docs/PERFORMANCE.md](docs/PERFORMANCE.md).

### Когда Docker НЕ подходит

- Хостинг не поддерживает Docker (shared hosting, FastVPS базовый план).
- Нужно тонкое управление PHP/OPcache/PostgreSQL вне дефолтов.
- Ниже 1 ГБ RAM на сервере (5 контейнеров не влезут).

В этих случаях — деплой по-старому, см. ниже **§§ 1-17**.

---

## ⚙ Деплой без Docker (классический способ)

Если по каким-то причинам Docker не подходит — ниже полный пошаговый туториал на чистом Ubuntu/Debian.

### 📚 Содержание

1. [Что нужно на сервере](#1-что-нужно-на-сервере)
2. [Установка системного софта](#2-установка-системного-софта-одной-командой)
3. [PostgreSQL: база и пользователь](#3-postgresql-база-и-пользователь)
4. [Redis: проверить что работает](#4-redis-проверить-что-работает)
5. [Клонирование и зависимости](#5-клонирование-проекта-и-зависимости)
6. [Настройка `.env`](#6-настройка-env)
7. [Миграции и первый админ](#7-миграции-и-первый-админ)
8. [Права на файлы и владелец](#8-права-на-файлы-и-владелец)
9. [Кэши Laravel + cron](#9-кэши-laravel--cron)
10. [Nginx + PHP-FPM](#10-nginx--php-fpm)
11. [HTTPS через Let's Encrypt](#11-https-через-lets-encrypt)
12. [OPcache (ускорение PHP в 3-5 раз)](#12-opcache-ускорение-php-в-3-5-раз)
13. [Google OAuth для Gmail](#13-google-oauth-для-gmail-если-нужна-отправка-писем)
14. [Финальная проверка](#14-финальная-проверка)
15. [Обновление (новый деплой)](#15-обновление-новый-деплой)
16. [Бэкапы](#16-бэкапы-минимум-раз-в-сутки)
17. [Что делать если...](#17-что-делать-если)

---

## 1. Что нужно на сервере

| Компонент | Минимум | Рекомендуется | Зачем |
|---|---|---|---|
| **OS** | Ubuntu 22.04 / Debian 12 | Ubuntu 24.04 LTS | Любой современный Linux подойдёт |
| **RAM** | 2 ГБ | 4+ ГБ | PostgreSQL + PHP-FPM + Redis + nginx |
| **Диск** | 10 ГБ | 50+ ГБ | Зависит от объёма таблиц |
| **PHP** | **8.2** | 8.3+ | Laravel 12 не запустится на меньшем |
| **PostgreSQL** | **15** | 16+ | Миграция использует `NULLS NOT DISTINCT` (PG15+) |
| **Redis** | 6 | 7+ | Cache + sessions |
| **Node.js** | 20 LTS | 22 LTS | Для `npm run build` (если бандл не в репо) |
| **Composer** | 2.6 | 2.7+ | Менеджер пакетов PHP |
| **Nginx** | 1.18 | 1.24+ | Web-сервер |
| **Домен** | — | да | Без домена не получишь HTTPS-сертификат |

**Также обязательно:**
- root- или sudo-доступ.
- Открытые порты 80 и 443 (для HTTP/HTTPS).
- Если стоит файрвол — пропусти 22 (SSH), 80, 443.

---

## 2. Установка системного софта одной командой

⚠ Замени `Ubuntu` ниже если у тебя Debian — команды те же.

```bash
sudo apt update
sudo apt upgrade -y

sudo apt install -y \
  nginx \
  postgresql postgresql-contrib \
  redis-server \
  php8.2-fpm php8.2-cli php8.2-pgsql php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl php8.2-gd \
  php8.2-redis php8.2-opcache php8.2-fileinfo \
  composer git unzip curl

# Node.js 20 LTS — официальная инструкция NodeSource
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Запускаем и включаем автозапуск всех сервисов
sudo systemctl enable --now nginx postgresql redis-server php8.2-fpm
```

**Проверь что всё работает:**
```bash
nginx -v               # должно показать версию
php -v                 # PHP 8.2.x
psql --version         # 15+ или 16+
redis-cli ping         # PONG
node -v                # v20.x
composer --version     # 2.x
```

Если что-то из этого не работает — **остановись здесь и почини**. Дальше идти бессмысленно.

---

## 3. PostgreSQL: база и пользователь

```bash
sudo -u postgres psql
```

В открывшемся `psql=#` интерфейсе:

```sql
-- Создаём пользователя БД (замени пароль на реальный — длинный случайный!)
CREATE USER excel_user WITH PASSWORD 'ОЧЕНЬ_ДЛИННЫЙ_ПАРОЛЬ_СЮДА';

-- Создаём БД
CREATE DATABASE excel_db OWNER excel_user;

-- Расширения
\c excel_db
GRANT ALL PRIVILEGES ON DATABASE excel_db TO excel_user;
GRANT ALL ON SCHEMA public TO excel_user;

-- Выходим
\q
```

**Проверь что коннект работает:**
```bash
psql -h 127.0.0.1 -U excel_user -d excel_db -c "SELECT 1;"
# Введёшь пароль → должно вернуть "?column? = 1"
```

⚠ Сохрани пароль БД — он нужен в `.env` ниже.

---

## 4. Redis: проверить что работает

Уже установился из шага 2 и стартовал автоматически. Просто проверь:

```bash
redis-cli ping
# → PONG
```

Если PONG — пропускай дальше. Если ошибка — `sudo systemctl status redis-server`.

**Опционально** (для security): защитить Redis паролем. Открой `/etc/redis/redis.conf`, найди `# requirepass foobared`, раскомментируй и поставь свой пароль:
```
requirepass ТВОЙ_REDIS_ПАРОЛЬ
```
Перезапусти: `sudo systemctl restart redis-server`. Потом в `.env` укажи `REDIS_PASSWORD=...`.

Для большинства случаев Redis на 127.0.0.1 без пароля — норм, потому что наружу он не доступен.

---

## 5. Клонирование проекта и зависимости

```bash
# Папка для проекта
sudo mkdir -p /var/www/excel
sudo chown $USER:$USER /var/www/excel
cd /var/www/excel

# Клон (замени URL на твой)
git clone https://github.com/ТВОЙ_АККАУНТ/excel.git .

# Зависимости PHP (без dev-пакетов на проде!)
composer install --optimize-autoloader --no-dev --no-interaction

# Если бандл фронта НЕ в репо (public/build пустая) — пересоберём:
# Если в репо есть собранный public/build — этот шаг можно пропустить.
[ ! -f public/build/manifest.json ] && {
    npm ci
    npm run build
}
```

---

## 6. Настройка `.env`

```bash
cp .env.example .env
nano .env   # или vim/любой редактор
```

**Заполни** следующие блоки. Всё остальное — оставь дефолтным.

### Приложение
```env
APP_NAME="Excel Tojiktelecom"
APP_ENV=production
APP_KEY=                                 # сгенерим командой ниже
APP_DEBUG=false                          # ⚠ обязательно false на проде
APP_URL=https://твой-домен.com           # с https если будешь ставить SSL
```

Сгенерируй ключ:
```bash
php artisan key:generate
# впишет APP_KEY=base64:... в .env автоматически
```

### Логирование
```env
LOG_CHANNEL=daily
LOG_DAILY_DAYS=14
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning                        # на проде warning хватает
AUDIT_LOG_RETENTION_DAYS=90              # сколько хранить журнал аудита
```

### База данных (используй пароль из шага 3)
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=excel_db
DB_USERNAME=excel_user
DB_PASSWORD=ТОТ_САМЫЙ_ПАРОЛЬ_ИЗ_ШАГА_3
```

### Redis (cache + sessions)
```env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
QUEUE_CONNECTION=sync                    # пока без очередей
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null                      # или твой пароль если ставил
```

### Дефолтный админ — будет создан миграцией автоматически
```env
ADMIN_EMAIL=admin@твой-домен.com
ADMIN_PASSWORD=КРИПТО-СТОЙКИЙ-ПАРОЛЬ-СЮДА
ADMIN_NAME="Главный админ"
```

⚠ **Сохрани этот пароль в надёжном месте** (1Password / Bitwarden). После первого входа можно (нужно!) сменить через `/profile`.

### Mail (если будет отправка писем; пока заглушка)
```env
MAIL_MAILER=log                          # лог-драйвер: письма уйдут в storage/logs
```

Если планируешь Gmail-OAuth — см. шаг 13.

**Сохрани файл, выйди из редактора.**

---

## 7. Миграции и первый админ

```bash
cd /var/www/excel

# Сбросить любой кэш конфига (на всякий случай)
php artisan config:clear

# Накатить ВСЕ миграции (включая создание админа из .env)
php artisan migrate --force
```

Среди прочего ты увидишь строки:
```
2026_05_05_140000_create_default_admin_user .................. DONE
2026_05_05_150000_add_performance_indexes .................... DONE
```

Первая создаёт админа из `.env`, вторая — добавляет индексы в `sheet_audit_logs` и `sheets`.

**Проверь что админ создался:**
```bash
php artisan tinker --execute="\
\$u = App\Models\User::where('email', env('ADMIN_EMAIL'))->first(); \
echo \$u ? 'OK id=' . \$u->id . ' isAdmin=' . (App\Models\Sheet::userIsAdmin(\$u) ? 'YES' : 'NO') : 'NOT_FOUND';"
# → OK id=1 isAdmin=YES
```

---

## 8. Права на файлы и владелец

Nginx и PHP-FPM работают под пользователем `www-data`. Им нужно писать в `storage/` и `bootstrap/cache/`.

```bash
cd /var/www/excel

# Сделать www-data владельцем сайта (но git-операции остаются твои)
sudo chown -R www-data:www-data storage bootstrap/cache

# Корректные права
sudo find storage -type d -exec chmod 775 {} \;
sudo find storage -type f -exec chmod 664 {} \;
sudo find bootstrap/cache -type d -exec chmod 775 {} \;
sudo find bootstrap/cache -type f -exec chmod 664 {} \;

# Остальные файлы могут оставаться твоими (для git pull / редактирования)
# .env — закрыть, пароли там
sudo chown $USER:www-data .env
sudo chmod 640 .env
```

---

## 9. Кэши Laravel + cron

```bash
cd /var/www/excel

# Кэши конфига/роутов/views — чтобы Laravel не парсил их на каждом запросе
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

⚠ После любых правок в `config/*.php`, `routes/*.php` или `.env` нужно повторить эти команды (или хотя бы `config:clear`).

**Cron для очистки журнала аудита:**
```bash
sudo crontab -e -u www-data
```

Добавь строку (запускает Laravel scheduler раз в минуту, он сам решает когда что чистить):
```cron
* * * * * cd /var/www/excel && php artisan schedule:run >> /dev/null 2>&1
```

---

## 10. Nginx + PHP-FPM

В репозитории уже есть готовый конфиг `nginx/prod.linux.conf`. Подключаем его:

```bash
cd /var/www/excel

# Скопируй конфиг
sudo cp nginx/prod.linux.conf /etc/nginx/sites-available/excel

# Открой и подправь две строки: server_name и (если нужно) root
sudo nano /etc/nginx/sites-available/excel
```

В файле найди и замени:
```nginx
server_name your-domain.com;        # ← поставь свой домен
root /var/www/excel/public;          # ← должно быть так если ставил в /var/www/excel
```

Активируй сайт и убери дефолтный:
```bash
sudo ln -sf /etc/nginx/sites-available/excel /etc/nginx/sites-enabled/excel
sudo rm -f /etc/nginx/sites-enabled/default
```

**PHP-FPM пул** (`/etc/php/8.2/fpm/pool.d/www.conf`) — открой и добавь/поправь под свой сервер:
```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 15
pm.max_requests = 500

php_admin_value[post_max_size]      = 60M
php_admin_value[upload_max_filesize] = 60M
php_admin_value[memory_limit]       = 256M
```

(Для серверов с 1-2 ГБ RAM уменьши `max_children` до 15-20.)

**Проверь конфиг и перезагрузи:**
```bash
sudo nginx -t                          # должно сказать "syntax is ok" + "test is successful"
sudo systemctl reload nginx
sudo systemctl reload php8.2-fpm
```

**Проверь что сайт открывается** (без HTTPS пока):
```bash
curl -I http://твой-домен.com
# → HTTP/1.1 200 OK (или 302 на /login)
```

Если работает — переходи к HTTPS.

---

## 11. HTTPS через Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d твой-домен.com
```

Certbot спросит email (для уведомлений о продлении), согласие с ToS, и автоматически:
- получит сертификат,
- добавит 443-блок в твой nginx-конфиг,
- настроит редирект `http → https`,
- перезагрузит nginx.

**Сертификат продлевается автоматически** через системный таймер `certbot.timer`. Проверь:
```bash
sudo systemctl list-timers | grep certbot
```

После этого открой `https://твой-домен.com` в браузере — должна быть зелёная кнопка замка.

---

## 12. OPcache (ускорение PHP в 3-5 раз)

OPcache **уже установился** в шаге 2 (`php8.2-opcache`). Нужно только настроить под прод-режим.

```bash
sudo nano /etc/php/8.2/fpm/conf.d/10-opcache.ini
```

Замени содержимое на:
```ini
zend_extension=opcache.so

opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0       ; ⚠ на проде 0 — читать только из памяти
opcache.save_comments=1
opcache.fast_shutdown=1
```

⚠ **`validate_timestamps=0`** означает что после `git pull` нужно **обязательно**:
```bash
sudo systemctl reload php8.2-fpm
```
(или сделать `php artisan opcache:clear`, если установлен пакет `appstract/laravel-opcache`).

Перезагрузи PHP-FPM чтобы применить:
```bash
sudo systemctl reload php8.2-fpm
```

---

## 13. Google OAuth для Gmail (если нужна отправка писем)

Без этого работают все функции **кроме** «Отправить таблицу по email». Если сейчас не нужно — пропусти, можно потом.

1. Зайди в https://console.cloud.google.com/apis/credentials
2. Создай OAuth 2.0 Client ID, тип **Web application**.
3. **Authorized redirect URIs** — добавь:
   ```
   https://твой-домен.com/auth/google/callback
   ```
4. Скопируй Client ID и Client Secret в `.env`:
   ```env
   GOOGLE_CLIENT_ID=...apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-...
   GOOGLE_REDIRECT_URI=https://твой-домен.com/auth/google/callback
   ```
5. Сбрось config-кэш:
   ```bash
   php artisan config:clear && php artisan config:cache
   ```
6. Войди в приложение, открой `/profile` → «Подключить Gmail».

---

## 14. Финальная проверка

```bash
# 1. Все сервисы запущены?
sudo systemctl status nginx php8.2-fpm postgresql redis-server | grep "Active:"

# 2. Сайт отвечает?
curl -I https://твой-домен.com
# → HTTP/2 200 (или 302 на /login)

# 3. БД и админ?
cd /var/www/excel
php artisan tinker --execute="echo App\Models\User::count() . ' users; ' . App\Models\Sheet::count() . ' sheets';"

# 4. Redis?
redis-cli ping     # PONG
redis-cli -n 1 DBSIZE  # будет расти когда залогинишься

# 5. OPcache реально работает?
echo "<?php var_dump(opcache_get_status(false)['opcache_enabled']);" | sudo tee /var/www/excel/public/_opcache.php
curl https://твой-домен.com/_opcache.php
# → bool(true)
sudo rm /var/www/excel/public/_opcache.php   # ⚠ обязательно удалить!
```

**Открой `https://твой-домен.com/login` в браузере**, войди под:
```
Email:    из .env (ADMIN_EMAIL)
Password: из .env (ADMIN_PASSWORD)
```

Создай тестовый лист, поправь ячейку. Если всё работает — **поздравляю, ты задеплоил**.

---

## 15. Обновление (новый деплой)

Когда выкатываешь новые изменения из git:

```bash
cd /var/www/excel

# 1. Перевести сайт в режим обслуживания (опционально, но прилично)
php artisan down

# 2. Подтянуть код
git pull origin main

# 3. Установить новые зависимости (если меняли composer.json)
composer install --optimize-autoloader --no-dev --no-interaction

# 4. Если меняли package.json (но обычно бандл уже в репо):
# npm ci && npm run build

# 5. Накатить новые миграции
php artisan migrate --force

# 6. Пересобрать кэши
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 7. Перезагрузить PHP-FPM (важно при OPcache validate_timestamps=0)
sudo systemctl reload php8.2-fpm

# 8. Снять режим обслуживания
php artisan up
```

**Сохрани этот блок как скрипт** `/var/www/excel/deploy.sh` чтобы не вводить руками.

---

## 16. Бэкапы (минимум раз в сутки)

### База данных
```bash
sudo -u postgres pg_dump excel_db | gzip > /var/backups/excel_db_$(date +%F).sql.gz
```

Положи в cron:
```cron
0 3 * * * sudo -u postgres pg_dump excel_db | gzip > /var/backups/excel_db_$(date +\%F).sql.gz
```

И подчищай старше 14 дней:
```cron
0 4 * * * find /var/backups -name 'excel_db_*.sql.gz' -mtime +14 -delete
```

### Восстановление из бэкапа
```bash
gunzip < /var/backups/excel_db_2026-05-05.sql.gz | sudo -u postgres psql excel_db
```

### Off-site
**Серьёзно**: настрой sync на S3 / Backblaze / Yandex Object Storage. Локальный бэкап ≠ бэкап.

---

## 17. Что делать если...

### Сайт показывает 500 / белый экран
```bash
tail -100 /var/www/excel/storage/logs/laravel.log
sudo tail -50 /var/log/nginx/error.log
sudo tail -50 /var/log/php8.2-fpm.log
```
Скорее всего: пермишены на `storage/`, или `.env` повреждён, или новый код требует свежий `php artisan migrate`.

### «These credentials do not match our records» при логине админа
- Перепроверь регистр пароля (Email/`A`dmin12345 ≠ `a`dmin12345).
- `php artisan tinker --execute="\$u = App\Models\User::where('email', env('ADMIN_EMAIL'))->first(); echo Hash::check(env('ADMIN_PASSWORD'), \$u->password) ? 'OK' : 'WRONG';"`
- Если WRONG — сбрось:
  ```bash
  php artisan tinker --execute="\$u = App\Models\User::where('email', env('ADMIN_EMAIL'))->first(); \$u->password = bcrypt(env('ADMIN_PASSWORD')); \$u->email_verified_at = now(); \$u->save(); echo 'reset OK';"
  ```

### «No sheets» / админ видит пустой список
Так и должно быть с нуля. Кликни «Создать лист» в UI.

### Redis отвалился, юзеры разлогиниваются
```bash
sudo systemctl status redis-server
sudo systemctl restart redis-server
```
Проверь `/var/log/redis/redis-server.log`. Скорее всего OOM — увеличь maxmemory или RAM сервера.

### Журнал аудита тормозит
Индексы накатываются миграцией `2026_05_05_150000`. Проверь:
```bash
sudo -u postgres psql excel_db -c "\di idx_audit_logs_*"
```
Должны быть три индекса. Если нет — `php artisan migrate`.

### Импорт большого .xlsx падает с 413
nginx: увеличь `client_max_body_size` в `prod.linux.conf` (сейчас 60M).
PHP-FPM: увеличь `post_max_size` и `upload_max_filesize` в пуле www.conf.
Laravel: `SheetController::MAX_IMPORT_BODY_BYTES` (50 МБ по умолчанию).
Все три должны быть согласованы.

### Что-то ещё
- `docs/PERFORMANCE.md` — глубокий разбор каждого слоя.
- `nginx/README.md` — конфиги и их различия dev/prod.
- `DEPLOY.md` — старый деплой-чеклист (этот README его дополняет).

---

## ⚡ TL;DR — деплой за 15 минут

Если ты тут уже не в первый раз и просто нужен быстрый список команд:

```bash
# 1. Софт
sudo apt install -y nginx postgresql redis-server php8.2-{fpm,cli,pgsql,mbstring,xml,curl,zip,bcmath,intl,gd,redis,opcache,fileinfo} composer git unzip
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
sudo systemctl enable --now nginx postgresql redis-server php8.2-fpm

# 2. БД
sudo -u postgres psql -c "CREATE USER excel_user WITH PASSWORD 'ПАРОЛЬ_СЮДА';"
sudo -u postgres psql -c "CREATE DATABASE excel_db OWNER excel_user;"

# 3. Код
sudo mkdir -p /var/www/excel && sudo chown $USER:$USER /var/www/excel
git clone <REPO_URL> /var/www/excel
cd /var/www/excel
composer install --optimize-autoloader --no-dev
[ ! -f public/build/manifest.json ] && npm ci && npm run build

# 4. .env
cp .env.example .env
nano .env   # заполнить APP_URL, DB_PASSWORD, ADMIN_EMAIL, ADMIN_PASSWORD,
            # CACHE_DRIVER=redis, SESSION_DRIVER=redis, REDIS_CLIENT=predis
php artisan key:generate

# 5. Миграции
php artisan migrate --force

# 6. Права
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# 7. Кэши
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache

# 8. nginx
sudo cp nginx/prod.linux.conf /etc/nginx/sites-available/excel
sudo nano /etc/nginx/sites-available/excel   # подправить server_name
sudo ln -sf /etc/nginx/sites-available/excel /etc/nginx/sites-enabled/excel
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx php8.2-fpm

# 9. HTTPS
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d твой-домен.com

# 10. OPcache prod-режим
sudo sed -i 's/^opcache.validate_timestamps=.*/opcache.validate_timestamps=0/' /etc/php/8.2/fpm/conf.d/10-opcache.ini
sudo systemctl reload php8.2-fpm

# 11. Cron
echo '* * * * * cd /var/www/excel && php artisan schedule:run >> /dev/null 2>&1' | sudo crontab -u www-data -

# Готово. Открой https://твой-домен.com/login
```

---

## Авторы

Проект Tojiktelecom. Лицензия MIT.

При деплое возникли вопросы которых нет в этом README — пиши, дополним.

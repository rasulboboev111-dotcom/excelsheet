# Деплой Excel Tojiktelecom на сервер

Пошаговое руководство по выкатке приложения на боевой сервер. Способ один — **Docker Compose**.

> **Кому это руководство?** Тебе, если ты впервые поднимаешь этот проект на новом сервере, или возвращаешься через полгода и забыл как.
>
> **Сколько времени займёт?** На свежем VPS — **10-15 минут** до первого логина админа (без HTTPS). С HTTPS через Cloudflare — ещё 5 минут.

---

## Содержание

1. [Что нужно ДО начала](#что-нужно-до-начала)
2. [Шаг 1: установить Docker](#шаг-1-установить-docker)
3. [Шаг 2: склонировать репозиторий](#шаг-2-склонировать-репозиторий)
4. [Шаг 3: настроить .env](#шаг-3-настроить-env)
5. [Шаг 4: запустить стек](#шаг-4-запустить-стек)
6. [Шаг 5: проверить что всё работает](#шаг-5-проверить-что-всё-работает)
7. [Шаг 6: войти под админом](#шаг-6-войти-под-админом)
8. [HTTPS (обязательно для прода)](#https-обязательно-для-прода)
9. [Обновление кода](#обновление-кода)
10. [Бэкапы](#бэкапы)
11. [Откат на предыдущую версию](#откат-на-предыдущую-версию)
12. [Troubleshooting](#troubleshooting)
13. [Чек-лист перед публичным релизом](#чек-лист-перед-публичным-релизом)
14. [Мониторинг](#мониторинг)
15. [TL;DR — всё одной портянкой](#tldr--всё-одной-портянкой)

---

## Что нужно ДО начала

| Что | Минимум | Где взять |
|---|---|---|
| **VPS / выделенный сервер** | 2 ГБ RAM, 20 ГБ диск, Ubuntu 22.04+ / Debian 12+ | DigitalOcean, Hetzner, Selectel, Reg.ru — любой |
| **SSH-доступ** | пароль или ключ root/sudo юзера | от провайдера VPS |
| **Домен** (опционально, но желательно) | свой домен | namecheap.com, reg.ru, любой регистратор |
| **DNS A-запись** | `excel.твой-домен.com` → IP сервера | в панели регистратора домена |
| **Открытые порты** | 22 (SSH), 80, 443 | у большинства VPS открыты по умолчанию |
| **Cloudflare-аккаунт** (для бесплатного HTTPS) | free tier | cloudflare.com |

### Подключение к серверу

```bash
ssh root@IP-СЕРВЕРА
# или
ssh твой-юзер@IP-СЕРВЕРА
```

---

## Шаг 1: установить Docker

На свежем сервере Ubuntu 22.04+ / Debian 12+:

```bash
# Установка Docker одной командой
curl -fsSL https://get.docker.com | sudo sh

# Добавить себя в группу docker — чтобы не писать sudo каждый раз
sudo usermod -aG docker $USER

# Перезайти в группу прямо сейчас (без выхода-входа)
newgrp docker

# Проверить
docker --version            # Docker version 27+ ожидаем
docker compose version      # Docker Compose v2.30+ ожидаем
```

Если видишь `Docker version 27+` и `Docker Compose v2+` — порядок.

> **Зачем `newgrp docker`?** Без него `docker` команды требуют `sudo` до следующего входа в SSH-сессию.

---

## Шаг 2: склонировать репозиторий

```bash
# Папка для проекта на сервере (можно любая, /opt/excel — традиция)
sudo mkdir -p /opt/excel
sudo chown $USER:$USER /opt/excel
cd /opt/excel

# Клонируем
git clone https://github.com/rasulboboev111-dotcom/excelsheet.git .
#                                            ^ замени на свой fork если форкнул
```

> **Точка в конце** `git clone ... .` важна — клонируем в текущую папку, не создаём подпапку `excelsheet/`.

После клонирования в `/opt/excel/` лежат: `app/`, `bootstrap/`, `config/`, `database/`, `docker/`, `docker-compose.yml`, `Dockerfile`, `.env.docker.example` и т.д.

---

## Шаг 3: настроить .env

```bash
cd /opt/excel
cp .env.docker.example .env
nano .env
```

### Обязательные поля (помечены `⚠`)

| Поле | Что писать | Пример |
|------|-----------|--------|
| `APP_URL` | URL твоего сайта | `https://excel.tojiktelecom.tj` или `http://1.2.3.4` если без домена |
| `APP_KEY` | Сгенерим командой ниже | `base64:dKQRkp5tW4GcJbYjm...` |
| `DB_PASSWORD` | Длинный случайный | 30+ символов: цифры, буквы, спецсимволы |
| `ADMIN_EMAIL` | Твой email админа | `admin@tojiktelecom.tj` |
| `ADMIN_PASSWORD` | Длинный случайный | 16+ символов |
| `ADMIN_NAME` | Имя админа на UI | `Главный админ` |

### Генерация надёжных паролей

```bash
# DB_PASSWORD — 32 случайных символа
openssl rand -base64 32

# ADMIN_PASSWORD — 20 случайных символов
openssl rand -base64 20
```

Скопируй вывод в `.env` (без переносов строк!).

### Генерация APP_KEY

`APP_KEY` шифрует сессии, токены, sensitive данные. Должен быть **сгенерирован один раз** и зафиксирован — иначе при перезапуске все сессии слетят, и `password_resets` сломаются.

```bash
docker compose run --rm app php artisan key:generate --show
```

Команда выведет строку типа:
```
base64:dKQRkp5tW4GcJbYjmUaFYoI+6sPH0GaeIolzHtjNxFc=
```

Скопируй её **целиком** (вместе с `base64:`) и впиши в `.env`:
```env
APP_KEY=base64:dKQRkp5tW4GcJbYjmUaFYoI+6sPH0GaeIolzHtjNxFc=
```

### Опциональные настройки

**Если перед сайтом будет Cloudflare/Caddy/Traefik с HTTPS** — оставь `SESSION_SECURE_COOKIE=true` (так и в шаблоне). Иначе **обязательно** поменяй на `false`, иначе **логин не будет работать по HTTP** (браузер не отправит Secure-куку без TLS).

```env
# Только HTTP, без HTTPS
SESSION_SECURE_COOKIE=false
```

**Если хочешь Gmail-отправку писем** — настрой OAuth (см. секцию [Gmail OAuth](#настройка-google-oauth)).

**Если порт 80 на сервере уже занят** (например, у тебя крутится старый nginx или Apache):
```env
NGINX_PORT=8080
```

**Off-site бэкапы в S3** — раскомментируй и заполни `BACKUP_RCLONE_REMOTE`. Подробнее в [секции бэкапов](#бэкапы).

### Сохранить .env

В `nano`: `Ctrl+O`, `Enter`, `Ctrl+X`.

---

## Шаг 4: запустить стек

```bash
docker compose up -d --build
```

**Что произойдёт:**
1. Соберутся образы (3 шт.) — `excel-app`, `excel-nginx`, `excel-backup`
2. Подтянутся официальные образы — `postgres:16-alpine`, `redis:7-alpine`
3. Запустятся 7 контейнеров (см. таблицу ниже)
4. `entrypoint.sh` в `app`-контейнере подождёт БД, накатит миграции, закэширует конфиг, создаст дефолтного админа, стартанёт PHP-FPM

**Время сборки:**
- Первый раз — **3-7 минут** (зависит от скорости сети и CPU)
- Последующие — **30 секунд** (cache попадает)

### 7 контейнеров приложения

| Контейнер | Зачем нужен |
|-----------|-------------|
| `excel-app-1` | PHP-FPM 8.2, обрабатывает HTTP-запросы |
| `excel-nginx-1` | Принимает входящие 80, отдаёт статику + проксит PHP на `app:9000` |
| `excel-postgres-1` | PostgreSQL 16, хранит все данные |
| `excel-redis-1` | Redis 7, cache + sessions + очередь |
| `excel-queue-1` | Воркер очереди, отправляет email через Gmail API в фоне |
| `excel-scheduler-1` | Запускает cron-задачи Laravel (например, ежедневный `audit-log:cleanup`) |
| `excel-backup-1` | Ежедневный `pg_dump` в `./backups/` + опциональный upload в S3 |

---

## Шаг 5: проверить что всё работает

```bash
docker compose ps
```

Должно быть **7 строк** со статусом `Up` или `Up (healthy)`:

```
NAME                 STATUS                   PORTS
excel-app-1          Up 30s (healthy)         9000/tcp
excel-backup-1       Up 30s (healthy)
excel-nginx-1        Up 25s (healthy)         0.0.0.0:80->80/tcp
excel-postgres-1     Up 35s (healthy)         5432/tcp
excel-queue-1        Up 25s (healthy)         9000/tcp
excel-redis-1        Up 35s (healthy)         6379/tcp
excel-scheduler-1    Up 25s (healthy)         9000/tcp
```

### Проверить логи приложения

```bash
docker compose logs app | tail -20
```

В конце должно быть:
```
[entrypoint] Pre-migration backup OK (X bytes, gzip valid)
[entrypoint] Running migrations...
[entrypoint] Caching config/routes/views...
[entrypoint] Boot complete. Starting: php-fpm --nodaemonize
[XX-XXX-2026 XX:XX:XX] NOTICE: ready to handle connections
```

### Проверить что nginx отдаёт страницу

```bash
curl -sI http://localhost:80/login
```

Должно вернуть `HTTP/1.1 200 OK` и security-заголовки:
```
HTTP/1.1 200 OK
Server: nginx
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
```

---

## Шаг 6: войти под админом

Открой в браузере:
```
http://IP-СЕРВЕРА
```
или (если DNS уже резолвится):
```
http://excel.твой-домен.com
```

Войди под `ADMIN_EMAIL` / `ADMIN_PASSWORD` из `.env`.

🎉 **Готово.** Приложение работает.

> **Важно:** **сразу** зайди в `/profile` и **смени пароль** на новый. Дефолтный пароль из `.env` известен всем, у кого есть доступ к серверу.

---

## HTTPS (обязательно для прода)

Без HTTPS все пароли и токены летят открытым текстом. **Не выкатывай в публичный доступ без TLS.**

### Вариант A: Cloudflare (самый простой, бесплатный)

1. Зарегистрируй домен на cloudflare.com (или перенаправь NS-серверы с текущего регистратора).
2. **Add Site** → введи домен → выбери Free план.
3. Cloudflare даст 2 nameserver'а — пропиши их у регистратора домена (где купил).
4. Ждёшь ~5 минут пока DNS обновится.
5. В Cloudflare → **DNS** → **Add record**:
   - Type: `A`
   - Name: `excel` (или просто `@` для корня домена)
   - IPv4: IP твоего сервера
   - **Proxy status: Proxied** (оранжевая облачка)
6. В Cloudflare → **SSL/TLS** → **Overview** → выбери **Full** (или **Full (strict)** если хочешь чтобы Cloudflare проверял твой сертификат — но у нас его нет, поэтому **Full**).
7. В `.env` на сервере оставь `SESSION_SECURE_COOKIE=true` и перезапусти:
   ```bash
   docker compose restart app
   ```

**Что получишь:**
- ✅ HTTPS работает (`https://excel.твой-домен.com`)
- ✅ DDoS-защита бесплатно
- ✅ Auto-renewal сертификата
- ✅ Бесплатный CDN для статики
- ✅ Server-side ничего не делаешь

> Контейнер nginx по-прежнему слушает 80, Cloudflare сам шифрует трафик клиент↔Cloudflare. Между Cloudflare и сервером — HTTP, но это внутри инфраструктуры Cloudflare (не критично, если хочешь — настрой `Full (strict)` с self-signed cert).

### Вариант B: Caddy на хосте (без Cloudflare)

Caddy сам получит сертификат от Let's Encrypt при первом обращении.

```bash
# Установка Caddy
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update
sudo apt install -y caddy

# Конфиг Caddy
sudo tee /etc/caddy/Caddyfile > /dev/null << 'EOF'
excel.твой-домен.com {
    reverse_proxy 127.0.0.1:8080
}
EOF

sudo systemctl reload caddy
```

В `.env` укажи **другой порт для nginx-контейнера**, чтобы он не конфликтовал с Caddy:
```env
NGINX_PORT=8080
```

Перезапусти:
```bash
docker compose down
docker compose up -d
```

Caddy сам выдаст сертификат при первом запросе. Готово.

### Вариант C: certbot + Let's Encrypt напрямую в nginx-контейнер

Более ручной способ, но без зависимости от Caddy.

```bash
# Останавливаем nginx-контейнер на минуту
docker compose stop nginx

# Получаем сертификат (certbot временно поднимет HTTP-сервер на 80)
sudo apt install -y certbot
sudo certbot certonly --standalone -d excel.твой-домен.com

# Сертификат лёг в /etc/letsencrypt/live/excel.твой-домен.com/
```

Монтируем сертификаты в nginx-контейнер. В `docker-compose.yml` секция `nginx`:
```yaml
nginx:
  volumes:
    - /etc/letsencrypt:/etc/letsencrypt:ro
  ports:
    - "80:80"
    - "443:443"
```

Добавить SSL-блок в `docker/nginx/default.conf` (отдельный `server` блок на порту 443 с `ssl_certificate` директивами).

```bash
docker compose up -d
```

**Auto-renewal через cron:**
```bash
sudo crontab -e
```
Добавь:
```cron
0 3 * * * certbot renew --quiet --post-hook "cd /opt/excel && docker compose restart nginx"
```

---

## Обновление кода

Когда выкатываешь новые правки из git:

```bash
cd /opt/excel

# 1. Подтянуть код
git pull origin main

# 2. Пересобрать и перезапустить
docker compose up -d --build

# 3. Проверить логи
docker compose logs app | tail -20
```

**Что произойдёт автоматически в `entrypoint.sh`:**
1. ✅ Дождётся PostgreSQL и Redis
2. ✅ Сделает **pre-migration backup** в `./backups/pre-migrate_*.sql.gz`
3. ✅ Проверит целостность gzip — если битый, **миграция не запустится** (защита от потери данных)
4. ✅ Накатит миграции (`php artisan migrate --force`)
5. ✅ Закэширует config / routes / views
6. ✅ Стартанёт PHP-FPM

**Время даунтайма:** 5-15 секунд для контейнеров `app` и `nginx`. БД и Redis не перезапускаются.

### Скрипт `deploy.sh` для автоматизации

```bash
cat > /opt/excel/deploy.sh << 'EOF'
#!/usr/bin/env bash
set -euo pipefail
cd /opt/excel

echo "[deploy] git pull..."
git pull origin main

echo "[deploy] rebuild..."
docker compose up -d --build

echo "[deploy] logs..."
docker compose logs app | tail -20

echo "[deploy] status..."
docker compose ps

echo "✅ Deploy complete: $(git rev-parse --short HEAD)"
EOF

chmod +x /opt/excel/deploy.sh
```

Деплоить теперь: `./deploy.sh`

---

## Бэкапы

База данных — **единственное** что нельзя восстановить из git. Бэкап обязателен.

### Встроенные бэкапы (уже работают)

В стеке есть отдельный **`backup` контейнер** (см. `docker-compose.yml`). Он:
- Запускает `pg_dump` ежедневно (по умолчанию **03:00 UTC**, см. `BACKUP_CRON` в `.env`)
- Жмёт в `.sql.gz` и кладёт в **`./backups/` на хосте** (видна в `/opt/excel/backups/`)
- Хранит последние **30 дней** (см. `BACKUP_RETENTION_LOCAL_DAYS`)
- Опционально аплоадит в S3/Yandex/Backblaze через `rclone`

### Проверить что бэкапы работают

```bash
ls -la /opt/excel/backups/
```

Через день после деплоя там должны быть файлы `excel_YYYY-MM-DD_HHMM.sql.gz`. Если их нет:
```bash
docker compose logs backup | tail -20
```

### Запустить бэкап вручную (для теста)

```bash
docker compose exec backup /usr/local/bin/backup.sh
ls -la /opt/excel/backups/   # должен появиться свежий файл
```

### Off-site бэкап в S3 / Backblaze / Yandex Object Storage

Локальные бэкапы не спасут если сервер сгорит. Настрой удалённое хранилище.

**Один раз — настройка rclone:**
```bash
docker compose run --rm backup rclone config
```
В интерактивном меню:
- `n` (new remote)
- name: `s3` (или любое имя)
- type: `s3` (или другой провайдер)
- ... следуй подсказкам ...

**Сохрани имя remote'а** (например `s3`) — оно теперь в `backup-rclone-config` volume.

В `.env` пропиши:
```env
BACKUP_RCLONE_REMOTE=s3:my-bucket/excel-backups
```

Перезапусти `backup` контейнер:
```bash
docker compose up -d backup
```

Теперь каждый ежедневный дамп будет автоматически уезжать в S3.

### Восстановление из бэкапа

⚠ Эта операция **перезапишет** текущую БД. Сначала переведи в режим обслуживания:

```bash
docker compose exec app php artisan down

# Распаковать и восстановить
gunzip -c /opt/excel/backups/excel_2026-05-13_0300.sql.gz | \
  docker compose exec -T postgres psql -U excel_user -d excel_db

docker compose exec app php artisan up
```

### Восстановление в "одноразовый" контейнер (для проверки целостности бэкапа)

Запускай раз в месяц, чтобы убедиться что бэкапы реально восстанавливаются:

```bash
# Поднять одноразовый postgres с теми же creds, что в проде (чтобы юзер
# excel_user, под которым сделан pg_dump, мог восстановиться без правок).
# Имя БД и логин/пароль — тестовые, никак не пересекаются с проdом.
docker run -d --name backup-test \
  -e POSTGRES_USER=excel_user \
  -e POSTGRES_PASSWORD=test123 \
  -e POSTGRES_DB=excel_db \
  postgres:16-alpine

# Ждём, пока postgres реально начнёт принимать коннекты (init нового
# tablespace на медленном диске может занять 15+ сек, поэтому sleep 10
# ненадёжен).
until docker exec backup-test pg_isready -U excel_user -d excel_db >/dev/null 2>&1; do
  sleep 1
done

# Восстановить
gunzip -c /opt/excel/backups/excel_2026-05-13_0300.sql.gz | \
  docker exec -i backup-test psql -U excel_user -d excel_db

# Проверить
docker exec backup-test psql -U excel_user -d excel_db -c \
  "SELECT COUNT(*) FROM sheets; SELECT COUNT(*) FROM sheet_data;"

# Снести
docker rm -f backup-test
```

---

## Откат на предыдущую версию

Если новый код всё сломал:

```bash
cd /opt/excel

# Посмотреть историю
git log --oneline -10

# Вернуться на конкретный коммит
git checkout <коммит-хеш>

# Пересобрать
docker compose up -d --build
```

⚠ **Внимание про миграции:** если новый код добавлял миграции, они **не откатываются автоматически**. Перед `git checkout`:

```bash
docker compose exec app php artisan migrate:rollback --force
```

Или восстанови БД из **pre-migration backup** (он лежит в `./backups/pre-migrate_*.sql.gz` — создаётся `entrypoint.sh` при каждом деплое с миграциями).

---

## Troubleshooting

### `docker compose up -d` падает с ошибкой

```bash
docker compose logs --tail 50           # все логи
docker compose logs app --tail 50       # только app
docker compose logs nginx --tail 50     # только nginx
docker compose logs postgres --tail 20  # БД
```

### Сайт не открывается / `ERR_CONNECTION_REFUSED`

```bash
# 1. Проверить что nginx-контейнер запущен
docker compose ps nginx

# 2. Проверить что 80 порт слушается на хосте
sudo ss -tlnp | grep ':80'

# 3. Проверить firewall (на Ubuntu)
sudo ufw status
```

Если firewall блочит 80:
```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
```

Если порт 80 занят чем-то другим (Apache, старый nginx):
```bash
sudo lsof -i :80
sudo systemctl stop apache2     # если apache
# или поменяй NGINX_PORT в .env на 8080 и перезапусти стек
```

### 502 Bad Gateway / 504 Gateway Timeout

`nginx` не достучался до `app:9000`. Скорее всего PHP-FPM упал.
```bash
docker compose logs app --tail 50
docker compose restart app
```

Если повторяется — проверь:
- хватает ли памяти (`free -m`)
- работает ли postgres (`docker compose ps postgres`)
- есть ли в логах `app` ошибки соединения с БД

### Логин не работает / "These credentials do not match our records"

**Возможная причина 1:** Админ не создался.

```bash
docker compose exec app php artisan tinker --execute="
\$u = App\Models\User::where('email', env('ADMIN_EMAIL'))->first();
echo \$u ? 'EXISTS id=' . \$u->id . ' verified=' . (\$u->email_verified_at ? 'YES' : 'NO') : 'NOT_FOUND';
"
```

Если `NOT_FOUND` — пустой `ADMIN_EMAIL`/`ADMIN_PASSWORD` в `.env`. Заполни и:
```bash
docker compose restart app
```

**Возможная причина 2:** `SESSION_SECURE_COOKIE=true` на HTTP-сайте.

Браузер не отправляет Secure-куку по HTTP → логин кажется неудачным. Проверь:
```bash
grep SESSION_SECURE_COOKIE /opt/excel/.env
```

Если `true` и сайт на HTTP — поменяй на `false` и:
```bash
docker compose restart app
```

**Возможная причина 3:** Пароль был сменён через миграцию, но `.env` не обновлён.

Сброс пароля и форсированная верификация:
```bash
docker compose exec app php artisan tinker --execute="
\$u = App\Models\User::where('email', env('ADMIN_EMAIL'))->first();
\$u->password = bcrypt(env('ADMIN_PASSWORD'));
\$u->email_verified_at = now();
\$u->save();
echo 'OK';
"
```

### Импорт `.xlsx` падает с 413 (Request Entity Too Large)

Лимит размера тела запроса согласован в **трёх** местах. Все три должны совпадать.

| Где | Параметр | Дефолт |
|-----|----------|--------|
| `docker/nginx/default.conf` | `client_max_body_size` | `60M` |
| `docker/php/php.ini` | `post_max_size` / `upload_max_filesize` | `60M` |
| `app/Http/Controllers/SheetController.php` | `MAX_IMPORT_BODY_BYTES` | `50M` |

Если хочешь больше — увеличь все три и пересобирай:
```bash
docker compose up -d --build
```

### Контейнер постоянно перезапускается (status `Restarting`)

```bash
docker compose ps
# в STATUS будет "Restarting (X)"
docker compose logs <имя-сервиса> --tail 100
```

Частые причины:
- `app` — пароль БД в `.env` не совпадает с тем, что у postgres-volume (после ручной правки). Решение:
  ```bash
  docker compose down -v   # ⚠ УДАЛИТ БД!
  docker compose up -d
  ```
- `postgres` — недостаточно места на диске. Проверь:
  ```bash
  df -h /var/lib/docker
  ```
- `redis` — память закончилась. Уменьши `--maxmemory` в `docker-compose.yml` (сейчас 256mb).

### Pre-migration backup упал → миграции не накатились

В логах `app`:
```
FATAL: pg_dump УПАЛ.
FATAL: ОТКАЗЫВАЕМСЯ запускать миграцию без бэкапа.
```

Это защита от потери данных. Причины:
- Кончилось место на диске (`df -h`)
- Postgres недоступен (`docker compose logs postgres`)
- DB_PASSWORD в .env не совпадает с тем, что в postgres-volume

После решения:
```bash
docker compose restart app
```

### Кончилось место на диске

```bash
# Что занимает
sudo du -sh /var/lib/docker /opt/excel /opt/excel/backups

# Удалить старые Docker-образы (НЕ затронет данные)
docker system prune -a    # ⚠ удалит все неиспользуемые образы

# Удалить старые бэкапы вручную (cron в backup-контейнере и так чистит >30 дней,
# но если хочешь агрессивнее)
find /opt/excel/backups -name 'excel_*.sql.gz' -mtime +60 -delete
```

### Полный сброс (атомная кнопка)

⚠ **УДАЛИТ ВСЁ** — базу, redis, файлы. Только если ничего не жалко:

```bash
cd /opt/excel
docker compose down -v
docker compose up -d --build
```

---

## Чек-лист перед публичным релизом

Прежде чем сказать «готово, можно показывать клиентам»:

### Безопасность
- [ ] `APP_DEBUG=false` в `.env`
- [ ] `APP_KEY` заполнен (`base64:...`, **не пустой**)
- [ ] `DB_PASSWORD` длинный случайный (30+ символов)
- [ ] `ADMIN_PASSWORD` не дефолтный, записан в надёжный password-менеджер
- [ ] **После первого логина** — пароль админа сменён через `/profile`
- [ ] **HTTPS работает** (Cloudflare / Caddy / certbot) — `curl -I https://домен.com/login` возвращает 200
- [ ] `SESSION_SECURE_COOKIE=true` после включения HTTPS
- [ ] Firewall настроен: открыты только 22, 80, 443
  ```bash
  sudo ufw allow 22/tcp
  sudo ufw allow 80/tcp
  sudo ufw allow 443/tcp
  sudo ufw enable
  ```

### Данные
- [ ] Ежедневный бэкап работает (`ls /opt/excel/backups/` через день — есть файлы)
- [ ] Off-site бэкап настроен (`BACKUP_RCLONE_REMOTE` указан и rclone настроен)
- [ ] **Тест восстановления бэкапа прошёл** — на отдельном postgres контейнере, MD5 ключевых таблиц совпали

### Функциональность
- [ ] `docker compose ps` — все 7 контейнеров `healthy`
- [ ] Логин под админом работает
- [ ] Создание листа, редактирование ячейки, импорт `.xlsx`
- [ ] Журнал аудита открывается (`/audit-log`) и показывает события
- [ ] Если используется регистрация — она работает (или закрыта если не нужна — закомментируй роут в `routes/web.php`)
- [ ] Если используется Gmail-отправка — `/profile` показывает кнопку «Подключить Gmail» и она работает

### Мониторинг
- [ ] UptimeRobot настроен на `/login`
- [ ] Email-уведомления о падении приходят на почту админа

---

## Мониторинг

### UptimeRobot (простой uptime-чек)

1. Регистрируйся на uptimerobot.com (free tier — 50 мониторов раз в 5 минут).
2. **Add New Monitor** → Type: **HTTPS** → URL: `https://excel.твой-домен.com/login`.
3. **Alert Contacts** — добавь свой email.
4. Если сайт упадёт — придёт письмо за 5-10 минут.

### Логи приложения

Лог `laravel.log` живёт в `app-storage` volume:
```bash
docker compose exec app tail -f storage/logs/laravel.log
```

Лог nginx:
```bash
docker compose logs -f nginx
```

Лог бэкапа:
```bash
docker compose logs -f backup
# или внутри контейнера:
docker compose exec backup tail -f /var/log/backup.log
```

### Серьёзный мониторинг (опционально)

- **Sentry** — для перехвата исключений приложения. Нужен `sentry/sentry-laravel` пакет и `SENTRY_LARAVEL_DSN` в `.env`.
- **Grafana + Loki** — централизация логов всех контейнеров.
- **Prometheus + node_exporter** — метрики сервера (CPU, RAM, диск).

Это уже не для «деплой за 15 минут». Отдельная тема.

---

## Настройка Google OAuth (для отправки писем из приложения)

Опционально. Нужно если хочешь, чтобы пользователи могли слать таблицы по email из своих Gmail-аккаунтов.

1. **Google Cloud Console** → https://console.cloud.google.com/
2. **APIs & Services** → **Credentials** → **Create Credentials** → **OAuth client ID**
3. **Application type:** Web application
4. **Authorized redirect URIs:** `https://excel.твой-домен.com/auth/google/callback`
5. Сохрани `Client ID` и `Client secret`.
6. В Google Cloud Console → **APIs & Services** → **Library** → найди **Gmail API** → **Enable**.
7. Пропиши в `.env` на сервере:
   ```env
   GOOGLE_CLIENT_ID=твой-client-id.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-твой-secret
   GOOGLE_REDIRECT_URI=https://excel.твой-домен.com/auth/google/callback
   ```
8. Перезапусти:
   ```bash
   docker compose restart app queue
   ```

Юзеры теперь могут зайти в `/profile`, нажать «Подключить Gmail», и слать таблицы по почте с собственного адреса.

---

## TL;DR — всё одной портянкой

```bash
# 1. Свежий VPS Ubuntu/Debian — ставим Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER && newgrp docker

# 2. Код
sudo mkdir -p /opt/excel && sudo chown $USER:$USER /opt/excel
cd /opt/excel
git clone https://github.com/rasulboboev111-dotcom/excelsheet.git .

# 3. .env
cp .env.docker.example .env
nano .env   # заполнить APP_URL, DB_PASSWORD, ADMIN_EMAIL, ADMIN_PASSWORD, ADMIN_NAME
            # На HTTP-сайте: SESSION_SECURE_COOKIE=false
            # Сгенерировать пароли:
            #   openssl rand -base64 32

# 4. APP_KEY
docker compose run --rm app php artisan key:generate --show
nano .env   # APP_KEY=base64:...

# 5. Запуск
docker compose up -d --build

# 6. Проверка
docker compose ps
docker compose logs app | tail -20

# 7. Логин
# Открыть http://IP-сервера → войти ADMIN_EMAIL/ADMIN_PASSWORD
# Сразу сменить пароль через /profile

# 8. HTTPS — добавить домен в Cloudflare (Proxy ON, SSL=Full)
# Поменять SESSION_SECURE_COOKIE=true в .env → docker compose restart app

# 9. Обновления (когда выйдут новые коммиты на github)
cd /opt/excel
git pull origin main
docker compose up -d --build

# 10. Бэкапы работают автоматически (см. ./backups/)
# Off-site:
docker compose run --rm backup rclone config
# в .env: BACKUP_RCLONE_REMOTE=s3:my-bucket/excel-backups
docker compose up -d backup
```

---

## Полезные ссылки

- [README.md](README.md) — описание проекта и быстрый старт для разработчиков
- [docs/PERFORMANCE.md](docs/PERFORMANCE.md) — глубокая оптимизация (OPcache, индексы, nginx-тюнинг)
- [Cloudflare Docs: SSL/TLS](https://developers.cloudflare.com/ssl/) — настройка HTTPS
- [Laravel 12 Docs: Deployment](https://laravel.com/docs/12.x/deployment) — общие принципы деплоя Laravel

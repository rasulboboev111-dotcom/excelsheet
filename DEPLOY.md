# Деплой на сервер

Полное руководство по выкатке Excel Tojiktelecom на боевой сервер.
Два способа: **Docker** (рекомендую) и **без Docker** (если Docker недоступен).

---

## 📋 Что нужно ещё ДО начала

| Что | Минимум | Где взять |
|---|---|---|
| **VPS / выделенный сервер** | 2 ГБ RAM, 20 ГБ диск, Ubuntu 22.04+ или Debian 12+ | DigitalOcean / Hetzner / Selectel / любой |
| **Домен** | свой домен (опционально, можно работать по IP) | namecheap / reg.ru / любой |
| **SSH-доступ к серверу** | root или sudo-юзер | от провайдера VPS |
| **DNS A-запись** | `excel.твой-домен.com` → IP сервера | через панель регистратора |
| **Открытые порты** | 22 (SSH), 80, 443 | по умолчанию открыты у большинства VPS |

Подключись к серверу:
```bash
ssh root@IP-сервера
# или
ssh твой-юзер@IP-сервера
```

---

## 🐳 Способ 1: Docker (рекомендую)

**Сложность:** 🟢 простой
**Время:** 5-10 минут на свежем сервере
**RAM:** ~1.2 ГБ (5 контейнеров)

### 1.1 Установить Docker

На свежем сервере (Ubuntu 22.04+ / Debian 12+):

```bash
# Установка Docker одной командой (официальный installer)
curl -fsSL https://get.docker.com | sudo sh

# Добавить себя в группу docker (чтобы не писать sudo каждый раз)
sudo usermod -aG docker $USER
newgrp docker

# Проверить
docker --version
docker compose version
```

Должны увидеть `Docker version 24+` и `Docker Compose version v2+`.

### 1.2 Склонировать репозиторий

```bash
sudo mkdir -p /opt/excel
sudo chown $USER:$USER /opt/excel
cd /opt/excel

git clone https://github.com/ТВОЙ_АККАУНТ/excel.git .
```

### 1.3 Создать .env

```bash
cp .env.docker.example .env
nano .env
```

**Обязательно** заполни эти строки (помечены `⚠` в шаблоне):

```env
APP_URL=https://excel.твой-домен.com           # или http://IP-сервера если без домена
APP_KEY=                                       # сгенерим в следующем шаге

DB_PASSWORD=ОЧЕНЬ_ДЛИННЫЙ_СЛУЧАЙНЫЙ_ПАРОЛЬ    # 30+ символов

ADMIN_EMAIL=admin@твой-домен.com
ADMIN_PASSWORD=ДЛИННЫЙ_СЛУЧАЙНЫЙ_ПАРОЛЬ        # 16+ символов
ADMIN_NAME="Главный админ"

GOOGLE_REDIRECT_URI=https://excel.твой-домен.com/auth/google/callback
```

Сохрани (Ctrl+O, Enter, Ctrl+X).

**Сгенерируй APP_KEY**:
```bash
docker compose run --rm app php artisan key:generate --show
```

Скопируй вывод (начинается с `base64:...`) и впиши в `.env`:
```env
APP_KEY=base64:dKQRkp5tW4GcJbYjmUaFYoI+6sPH0GaeIolzHtjNxFc=
```

### 1.4 Запустить стек

```bash
docker compose up -d --build
```

Первая сборка занимает **2-5 минут** (на Linux Docker работает в разы быстрее, чем на Windows). При следующих запусках — **30 секунд** (всё закэшировано).

### 1.5 Проверить что всё поднялось

```bash
docker compose ps
```

Должно быть 5 контейнеров со статусом `Up` или `Up (healthy)`:
```
NAME                  STATUS                   PORTS
excel-app-1           Up                       9000/tcp
excel-nginx-1         Up                       0.0.0.0:80->80/tcp
excel-postgres-1      Up (healthy)             5432/tcp
excel-redis-1         Up (healthy)             6379/tcp
excel-scheduler-1     Up                       9000/tcp
```

```bash
docker compose logs app | tail -10
```

В конце должно быть:
```
[entrypoint] Boot complete. Starting: php-fpm --nodaemonize
[XX-XXX-2026 XX:XX:XX] NOTICE: ready to handle connections
```

### 1.6 Открыть в браузере

```
http://IP-сервера
```

или (если домен уже резолвится):
```
http://excel.твой-домен.com
```

Войди под `ADMIN_EMAIL` / `ADMIN_PASSWORD` из `.env`.

🎉 **Готово.** Всё работает.

### 1.7 Включить HTTPS — см. раздел [«🔒 HTTPS»](#-https-важно-для-прода) ниже.

---

## 🛠 Способ 2: Без Docker

Когда Docker недоступен (shared hosting, корпоративные ограничения и т.п.). **Сложнее, но без overhead контейнеров**.

Полный гайд — в [README.md](README.md), раздел **«⚙ Деплой без Docker»** (17 шагов).

Кратко: установить PHP 8.2 + PostgreSQL 16 + Redis + nginx + composer + node, склонировать репо, заполнить `.env`, запустить миграции, настроить nginx, certbot.

---

## 🔒 HTTPS (важно для прода)

Без HTTPS пароли передаются открытым текстом. **Не выкатывай на прод без SSL**.

### Самый простой способ: Cloudflare

1. Зарегистрируй домен на Cloudflare (или перенаправь NS-серверы туда у текущего регистратора).
2. Добавь A-запись: `excel.твой-домен.com → IP-сервера`, **с включённой оранжевой облачкой** (proxy через Cloudflare).
3. В Cloudflare → SSL/TLS → Overview → выбери **Full** или **Full (strict)**.
4. Готово. Cloudflare сам выдаст SSL и шифрует трафик до сервера. Контейнер nginx работает на 80, никаких сертификатов на твоём сервере не нужно.

✅ Бесплатно
✅ Auto-renewal
✅ DDoS-защита в подарок
✅ Не нужно перезапускать стек

### Без Cloudflare: Caddy на хосте

Если хочешь автоматический HTTPS без Cloudflare и без возни — поставь **Caddy** на хост и проксируй на Docker:

```bash
sudo apt install -y caddy

sudo tee /etc/caddy/Caddyfile << 'EOF'
excel.твой-домен.com {
    reverse_proxy 127.0.0.1:8080
}
EOF

sudo systemctl reload caddy
```

В `.env`:
```
NGINX_PORT=8080   # чтобы nginx-контейнер не конфликтовал с Caddy на 80/443
```

```bash
docker compose down && docker compose up -d
```

Caddy сам получит SSL от Let's Encrypt при первом обращении к домену. ✅ Работает из коробки, без правки docker-compose.

### Без Cloudflare: Let's Encrypt + certbot напрямую

⚠ Этот вариант сложнее с Docker (нужен общий volume для сертификатов). Если Cloudflare/Caddy не вариант — рассматривай деплой без Docker (см. [README.md](README.md) §11).

Если очень нужно с Docker:

```bash
sudo apt install -y certbot

# Останавливаем nginx-контейнер на минуту
docker compose stop nginx

# Получаем сертификат (certbot временно поднимет свой сервер на 80)
sudo certbot certonly --standalone -d excel.твой-домен.com
```

Сертификаты лягут в `/etc/letsencrypt/live/excel.твой-домен.com/`. Их подсунуть в nginx-контейнер через volume в `docker-compose.yml`:

```yaml
nginx:
  volumes:
    - /etc/letsencrypt:/etc/letsencrypt:ro
  ports:
    - "80:80"
    - "443:443"
```

Добавить SSL-блок в `docker/nginx/default.conf` (см. `nginx/prod.linux.conf` как шаблон, секция HTTPS-вариант).

```bash
docker compose up -d
```

Cron на хост для auto-renewal:
```cron
0 3 * * * certbot renew --quiet --post-hook "cd /opt/excel && docker compose restart nginx"
```

---

## 🔄 Обновление кода (новый деплой)

Стандартный цикл когда выкатываешь новые правки из git:

```bash
cd /opt/excel

# 1. Подтянуть код
git pull origin main

# 2. Пересобрать и перезапустить (миграции и кэши накатятся автоматически в entrypoint)
docker compose up -d --build

# 3. Проверить
docker compose ps
docker compose logs app | tail -10
```

Время: ~30-60 секунд. Без даунтайма для отдельных контейнеров (postgres, redis не перезапускаются).

### Скрипт `deploy.sh`

Положи в `/opt/excel/deploy.sh`:
```bash
#!/usr/bin/env bash
set -euo pipefail
cd /opt/excel
git pull origin main
docker compose up -d --build
docker compose logs app | tail -10
echo "✅ Deploy complete"
```

```bash
chmod +x /opt/excel/deploy.sh
```

Деплой: `./deploy.sh`.

---

## 🔙 Откат на предыдущую версию

Если новый код всё сломал:

```bash
cd /opt/excel

# Узнать какие коммиты были
git log --oneline -10

# Откатиться на конкретный
git checkout <коммит-хеш>

# Пересобрать
docker compose up -d --build
```

⚠ **Внимание про миграции**: если новый код добавлял миграции, они не откатываются автоматически. Перед `git checkout`:
```bash
docker compose exec app php artisan migrate:rollback --force
```

---

## 💾 Бэкапы (минимум раз в сутки)

База данных — **единственное** что нельзя восстановить из git. Бэкап ежедневно.

### Cron на хосте

```bash
sudo nano /etc/cron.d/excel-backup
```

Содержимое:
```cron
# Бэкап БД каждый день в 3:00 (UTC)
0 3 * * * root cd /opt/excel && docker compose exec -T postgres pg_dump -U excel_user excel_db | gzip > /var/backups/excel_$(date +\%F).sql.gz

# Подчищаем старше 30 дней
0 4 * * * root find /var/backups -name 'excel_*.sql.gz' -mtime +30 -delete
```

Создаём папку:
```bash
sudo mkdir -p /var/backups
```

Проверь что cron работает:
```bash
sudo systemctl status cron
ls -la /var/backups   # на следующее утро тут появится excel_2026-XX-XX.sql.gz
```

### Off-site бэкап (важно!)

Локальный бэкап не спасёт если сервер сгорит / провайдер пропадёт.

**Вариант A: rsync на другой VPS**
```bash
rsync -avz /var/backups/ user@backup-server:/backups/excel/
```

**Вариант B: rclone в S3 / Yandex Object Storage / Backblaze**
```bash
# Установка
curl https://rclone.org/install.sh | sudo bash
rclone config   # настроить remote (s3 / yandex / b2)

# Cron-задача
0 5 * * * rclone sync /var/backups/ remote:excel-backups/
```

### Восстановление из бэкапа

```bash
gunzip < /var/backups/excel_2026-05-05.sql.gz | docker compose exec -T postgres psql -U excel_user excel_db
```

⚠ Эта команда **перезапишет** текущую базу. Сначала переведи приложение в режим обслуживания:
```bash
docker compose exec app php artisan down
# ... восстановить ...
docker compose exec app php artisan up
```

---

## 🚨 Что-то не работает

### `docker compose up -d` падает с ошибкой

```bash
docker compose logs --tail 50           # все логи
docker compose logs app --tail 50       # только app
docker compose logs nginx --tail 50     # только nginx
docker compose logs postgres --tail 20  # БД
```

### Сайт не открывается / `ERR_CONNECTION_REFUSED`

```bash
# Проверить что контейнер nginx запущен и слушает 80
docker compose ps
sudo netstat -tlnp | grep ':80'

# Что-то ещё занимает 80?
sudo lsof -i :80
```

Если 80 занят чем-то другим (Apache, старый nginx) — останови их (`sudo systemctl stop apache2`) или поменяй `NGINX_PORT=8080` в `.env` и перезапусти.

### 502 Bad Gateway / 504 Gateway Timeout

`nginx` не достучался до `app:9000`. Скорее всего PHP-FPM упал.
```bash
docker compose logs app --tail 50
docker compose restart app
```

### «These credentials do not match our records»

```bash
# Проверить что админ создался
docker compose exec app php artisan tinker --execute="\$u = App\Models\User::where('email', env('ADMIN_EMAIL'))->first(); echo \$u ? 'OK id=' . \$u->id . ' verified=' . (\$u->email_verified_at ? 'YES' : 'NO') : 'NOT_FOUND';"

# Если NOT_FOUND — пустые ADMIN_EMAIL/PASSWORD в .env. Заполни и:
docker compose restart app

# Если OK но не пускает — сбросить пароль и форсировать verify:
docker compose exec app php artisan tinker --execute="\$u = App\Models\User::where('email', env('ADMIN_EMAIL'))->first(); \$u->password = bcrypt(env('ADMIN_PASSWORD')); \$u->email_verified_at = now(); \$u->save();"
```

### Импорт `.xlsx` падает с 413 (Request Entity Too Large)

Размеры согласованы в трёх местах:
- nginx: `client_max_body_size` в `docker/nginx/default.conf` (60M)
- PHP: `post_max_size` / `upload_max_filesize` в `docker/php/php.ini` (60M)
- Laravel: `MAX_IMPORT_BODY_BYTES` в `app/Http/Controllers/SheetController.php` (50M)

Если хочешь больше — увеличь все три и пересобирай образ (`docker compose up -d --build`).

### Контейнер постоянно перезапускается (status `Restarting`)

```bash
docker compose ps
# в STATUS будет "Restarting (X)"
docker compose logs <имя-сервиса> --tail 100
```

Самые частые причины:
- `app` — пароль БД не совпадает между `.env` и postgres-volume (если меняли). Решение: `docker compose down -v` (⚠ удалит БД!) и заново.
- `postgres` — недостаточно места на диске. Проверь: `df -h`.
- `redis` — память кончилась. Уменьши `--maxmemory` в compose.

### Закончилось место на диске

```bash
# Что занимает
sudo du -sh /var/lib/docker /var/backups /opt/excel

# Удалить старые образы Docker
docker system prune -a   # ⚠ удалит все неиспользуемые образы

# Удалить старые бэкапы
sudo find /var/backups -name 'excel_*.sql.gz' -mtime +60 -delete
```

### Полный сброс (чистый старт)

⚠ **УДАЛИТ ВСЁ** — базу, redis, файлы. Только если ничего не жалко:

```bash
docker compose down -v
docker compose up -d --build
```

---

## ✅ Чек-лист перед публичным релизом

- [ ] `APP_DEBUG=false` в `.env`
- [ ] `APP_KEY` заполнен (не пустой)
- [ ] `DB_PASSWORD` длинный случайный (30+ символов)
- [ ] `ADMIN_PASSWORD` сменён с дефолтного и записан в надёжное место
- [ ] HTTPS работает (Cloudflare / Caddy / certbot)
- [ ] Cron-бэкап настроен и проверен (`ls -la /var/backups`)
- [ ] Off-site бэкап настроен
- [ ] `docker compose ps` — все 5 контейнеров healthy
- [ ] Логин под админом работает
- [ ] Создание листа, заполнение ячейки, импорт `.xlsx`
- [ ] Журнал аудита открывается (`/audit-log`)
- [ ] Регистрация работает (или закрыта если не нужна)
- [ ] Открыты только нужные порты:
  ```bash
  sudo ufw allow 22/tcp
  sudo ufw allow 80/tcp
  sudo ufw allow 443/tcp
  sudo ufw enable
  ```

---

## 📊 Мониторинг (опционально)

Простой uptime-чек — **UptimeRobot** (https://uptimerobot.com):
1. Регистрируйся (free tier — 50 мониторов).
2. Add monitor → HTTPS — `https://excel.твой-домен.com/login` — раз в 5 минут.
3. Email-нотификации если упадёт.

Логи приложения — пишутся в `storage/logs/laravel.log` внутри `app-storage` volume:
```bash
docker compose exec app tail -f storage/logs/laravel.log
```

Для серьёзного мониторинга — Grafana + Loki / Sentry, но это уже не для «деплой за 5 минут».

---

## 🔗 Полезные ссылки

- [README.md](README.md) — описание проекта и быстрый старт
- [docs/PERFORMANCE.md](docs/PERFORMANCE.md) — глубокая оптимизация (OPcache, индексы, nginx-тюнинг)
- [nginx/README.md](nginx/README.md) — детали nginx-конфигов dev/prod

---

## 📝 TL;DR на одну страницу

```bash
# 1. Свежий VPS Ubuntu/Debian
curl -fsSL https://get.docker.com | sudo sh && sudo usermod -aG docker $USER && newgrp docker

# 2. Код
sudo mkdir -p /opt/excel && sudo chown $USER:$USER /opt/excel
git clone https://github.com/ТВОЙ_АККАУНТ/excel.git /opt/excel
cd /opt/excel
cp .env.docker.example .env
nano .env   # заполнить APP_URL, DB_PASSWORD, ADMIN_EMAIL, ADMIN_PASSWORD
docker compose run --rm app php artisan key:generate --show
nano .env   # APP_KEY=base64:...

# 3. Запуск
docker compose up -d --build

# 4. HTTPS — добавить домен в Cloudflare с включённым proxy и SSL=Full

# 5. Открыть https://домен.com → войти ADMIN_EMAIL/ADMIN_PASSWORD

# Обновления:
git pull && docker compose up -d --build

# Бэкапы:
docker compose exec -T postgres pg_dump -U excel_user excel_db | gzip > backup_$(date +%F).sql.gz
```

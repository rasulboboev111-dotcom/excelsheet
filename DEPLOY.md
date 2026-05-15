# Деплой Excel Tojiktelecom

Поднять приложение с нуля на сервере — **~15 минут**.

Способ один: **Docker Compose**.

---

## Быстрый запуск (все команды одной портянкой)

Для тех, кто уже умеет — копипаст без комментариев. Подробные шаги ниже.

```bash
# 1. Подключиться к серверу
ssh root@IP-СЕРВЕРА

# 2. Поставить Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
newgrp docker

# 3. Скачать код
sudo mkdir -p /opt/excel && sudo chown $USER:$USER /opt/excel
cd /opt/excel
git clone https://github.com/rasulboboev111-dotcom/excelsheet.git .

# 4. Скопировать .env
cp .env.docker.example .env

# 5. Сгенерить пароли (скопируй вывод в .env)
openssl rand -base64 32   # → DB_PASSWORD
openssl rand -base64 20   # → ADMIN_PASSWORD

# 6. Открыть и заполнить .env (APP_URL, DB_PASSWORD, ADMIN_EMAIL, ADMIN_PASSWORD, ADMIN_NAME)
nano .env

# 7. Сгенерить APP_KEY и вписать в .env (тоже через nano)
docker compose run --rm app php artisan key:generate --show

# 8. Запустить
docker compose up -d --build

# 9. Проверить
docker compose ps
docker compose logs app | tail -20

# === Открыть в браузере http://IP-СЕРВЕРА и залогиниться ===
# === Email/пароль — те, что вписал в ADMIN_EMAIL/ADMIN_PASSWORD в .env ===

# --- Дальше для обслуживания ---

# Обновить код
git pull && docker compose up -d --build

# Бэкап вручную
docker compose exec backup /usr/local/bin/backup.sh

# Восстановить из бэкапа
gunzip -c /opt/excel/backups/excel_2026-XX-XX_HHMM.sql.gz | \
  docker compose exec -T postgres psql -U excel_user -d excel_db

# Полный сброс (⚠ удалит БД И БЭКАПЫ — сначала скопируй бэкапы наружу!)
cp -r /opt/excel/backups ~/backups-safety-copy-$(date +%Y%m%d)
docker compose down -v && docker compose up -d --build
```

---

## Данные для входа

После запуска `docker compose up -d --build` войти можно под:

- **Email:** значение `ADMIN_EMAIL` из твоего `.env`
- **Пароль:** значение `ADMIN_PASSWORD` из твоего `.env`

Админа создаёт миграция `2026_05_05_140000_create_default_admin_user.php` — она читает три переменные из `.env` (`ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME`) и создаёт пользователя с глобальной admin-ролью и сразу верифицированной почтой. Миграция накатывается **автоматически** при старте `app`-контейнера (через `entrypoint.sh`).

> Если `ADMIN_EMAIL` или `ADMIN_PASSWORD` пустые — миграция тихо пропускается и юзера не будет (защита от учётки с известным паролем). Заполни и сделай `docker compose restart app`.

После первого входа **сразу** смени пароль через `/profile` — дефолт из `.env` известен всем, у кого доступ к серверу.

---

## Что нужно

- **VPS** (Ubuntu 22.04+ / Debian 12+, 2 ГБ RAM, 20 ГБ диск) — Hetzner, DigitalOcean, Selectel, любой.
- **Доступ по SSH** (IP сервера + пароль root — пришлёт провайдер на email).
- **Домен** (опционально, но желательно для HTTPS) — namecheap.com, reg.ru.

---

## Шаг 1: подключиться к серверу

Открой терминал на своём ноуте (на Windows — PowerShell или Windows Terminal):

```bash
ssh root@IP-СЕРВЕРА
```

- На вопрос `Are you sure...` → напечатай `yes` и Enter.
- Пароль вводится **вслепую** (звёздочки не отображаются — это нормально). Введи и Enter.

Если видишь `root@server:~#` — ты внутри. ✅

---

## Шаг 2: установить Docker

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
newgrp docker

# Проверка
docker --version
docker compose version
```

Видишь две версии — Docker готов.

---

## Шаг 3: скачать код

```bash
sudo mkdir -p /opt/excel && sudo chown $USER:$USER /opt/excel
cd /opt/excel
git clone https://github.com/rasulboboev111-dotcom/excelsheet.git .
```

> Точка в `git clone ... .` важна — клонируем в текущую папку.

---

## Шаг 4: настроить `.env`

`.env` — файл с паролями и настройками. Скопируй шаблон и заполни:

```bash
cp .env.docker.example .env
nano .env
```

**Управление `nano`:** стрелки — перемещение, обычный ввод — печать, `Ctrl+O` `Enter` — сохранить, `Ctrl+X` — выйти.

### Заполнить обязательно

| Поле | Что писать |
|---|---|
| `APP_URL` | URL твоего сайта **с `http://` или `https://`**. Пример: `https://excel.tojiktelecom.tj` или `http://1.2.3.4` |
| `DB_PASSWORD` | Сгенерируй: `openssl rand -base64 32` |
| `ADMIN_EMAIL` | Твой email админа |
| `ADMIN_PASSWORD` | Сгенерируй: `openssl rand -base64 20` |
| `ADMIN_NAME` | Имя админа в UI |
| `APP_KEY` | См. ниже |

> ⚠ `APP_URL` важен — на него строятся ссылки регистрации. Если впишешь `localhost` — коллеги не смогут открыть ссылку со своих компов.

### Сгенерировать `APP_KEY`

```bash
docker compose run --rm app php artisan key:generate --show
```

Выведет строку `base64:dKQRkp5tW4GcJbYjm...` — скопируй её целиком в `.env`:

```env
APP_KEY=base64:dKQRkp5tW4GcJbYjm...
```

### Если деплоишь по HTTP (без HTTPS)

Поменяй в `.env`:
```env
SESSION_SECURE_COOKIE=false
```
Иначе логин не будет работать — браузер не отправит куку без TLS.

---

## Шаг 5: запустить

```bash
docker compose up -d --build
```

Первый запуск — **3-7 минут** (сборка образов). Дальше — 30 секунд.

Проверить что всё поднялось:

```bash
docker compose ps
```

Должно быть **7 контейнеров** в статусе `Up (healthy)`:
- `excel-app-1` — PHP-приложение
- `excel-nginx-1` — веб-сервер (порт 80)
- `excel-postgres-1` — БД
- `excel-redis-1` — кэш и сессии
- `excel-queue-1` — фоновые задачи
- `excel-scheduler-1` — cron Laravel
- `excel-backup-1` — ежедневные бэкапы

Если какой-то не `healthy` → раздел [Если что-то не работает](#если-что-то-не-работает).

---

## Шаг 6: войти

Открой в браузере на своём ноуте: `http://IP-СЕРВЕРА` (или твой домен).

Войди под `ADMIN_EMAIL` / `ADMIN_PASSWORD` из `.env`.

🎉 Готово.

> **Сразу** зайди в `/profile` и смени пароль — дефолтный известен всем, у кого доступ к серверу.

---

## Шаг 7: дать доступ коллегам

Публичной регистрации нет. Доступ выдаётся через **ссылку-приглашение**.

1. Зайди в `/users` → **«+ Создать ссылку»**.
2. Откроется модалка со ссылкой `https://твой-домен/invite/XXXX...` → нажми **«Скопировать»**.
3. Отправь ссылку человеку в личку (Telegram, WhatsApp, email).
4. По ссылке он вводит имя, свою почту, пароль → попадает в систему.

**Особенности:**
- Ссылка многоразовая — можно дать одну всему отделу.
- Срока нет — работает пока админ не нажмёт **«Отозвать»**.
- Все, кто пришёл по ссылке, получают **базовую роль**. Админ-права и право слать почту выдаются вручную через `/users` → «Изменить».

> ⚠ Не публикуй ссылку в открытом доступе — любой, у кого она есть, сможет зарегистрироваться. Передавай только в личных сообщениях.

---

## HTTPS через Cloudflare

Без HTTPS пароли летят открытым текстом — **не пускай в публичный доступ без TLS**. Cloudflare даёт бесплатный HTTPS за 5 минут.

1. Регистрируйся на **cloudflare.com**.
2. **Add Site** → введи свой домен → бесплатный тариф.
3. Cloudflare даст 2 nameserver'а (типа `mark.ns.cloudflare.com`) — пропиши их у регистратора домена.
4. Жди 5-30 минут пока DNS обновится.
5. Cloudflare → **DNS** → **Add record**:
   - Type: `A`
   - Name: `excel` (или `@` для корня)
   - IPv4: IP твоего сервера
   - **Proxy status: Proxied** (оранжевая облачка — НЕ серая)
6. Cloudflare → **SSL/TLS** → **Overview** → выбери **Full**.
7. На сервере убедись что в `.env` стоит `SESSION_SECURE_COOKIE=true`, и перезапусти:
   ```bash
   docker compose restart app
   ```

Открой `https://excel.твой-домен.com` — работает по HTTPS. ✅

---

## Обновление кода

Когда вышли новые коммиты на GitHub:

```bash
cd /opt/excel
git pull origin main
docker compose up -d --build
```

Миграции базы накатятся **автоматически** при старте контейнера. Перед миграцией делается бэкап в `./backups/pre-migrate_*.sql.gz` — если что-то пойдёт не так, есть откат.

Время даунтайма: ~10 секунд.

---

## Бэкапы

Бэкапы **уже работают** — отдельный `backup` контейнер делает `pg_dump` каждый день в 03:00 UTC и кладёт в `./backups/`.

**Проверить:**
```bash
ls -la /opt/excel/backups/
```

Через день после деплоя там должны быть файлы `excel_YYYY-MM-DD_HHMM.sql.gz`.

**Запустить вручную:**
```bash
docker compose exec backup /usr/local/bin/backup.sh
```

**Восстановить из бэкапа** (⚠ перезапишет текущую БД):
```bash
docker compose exec app php artisan down

gunzip -c /opt/excel/backups/excel_2026-05-13_0300.sql.gz | \
  docker compose exec -T postgres psql -U excel_user -d excel_db

docker compose exec app php artisan up
```

**Off-site бэкапы в S3 / Backblaze / Yandex** (рекомендуется — на случай если сервер сгорит):
```bash
docker compose run --rm backup rclone config
```
Настрой remote, потом в `.env` пропиши `BACKUP_RCLONE_REMOTE=s3:my-bucket/excel` и `docker compose up -d backup`.

---

## Если что-то не работает

### Контейнер `app` не `healthy`

```bash
docker compose logs app --tail 50
```

| Что в логах | Что делать |
|---|---|
| `pg_dump: server version mismatch` | Версия `pg_dump` не совпадает с postgres. Проверь, что в `Dockerfile` ставится `postgresql-client-16`. Пересобери: `docker compose up -d --build` |
| `connection refused` / `password authentication failed` | `DB_PASSWORD` в `.env` не совпадает с тем, что в БД. Если БД ещё пустая: `docker compose down -v && docker compose up -d` (⚠ удалит БД) |
| `APP_KEY` пустой | Не сгенерировал ключ. Вернись к Шагу 4 |

### Сайт не открывается

```bash
# Порт 80 слушается?
sudo ss -tlnp | grep :80

# Firewall блочит?
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
```

Если порт занят другим (Apache, старый nginx) — останови их: `sudo systemctl stop apache2`. Или поменяй `NGINX_PORT=8080` в `.env`.

### Логин не работает

**Самая частая причина:** `SESSION_SECURE_COOKIE=true` при работе по HTTP. Браузер не отправляет Secure-куку без HTTPS.

```bash
grep SESSION_SECURE_COOKIE /opt/excel/.env
```

Если стоит `true`, а сайт на HTTP — поменяй на `false` и `docker compose restart app`.

**Если админ не создаётся:**
```bash
docker compose exec app php artisan tinker --execute="
\$u = App\Models\User::where('email', env('ADMIN_EMAIL'))->first();
\$u->password = bcrypt(env('ADMIN_PASSWORD'));
\$u->email_verified_at = now();
\$u->save();
echo 'OK';
"
```

### Ссылка регистрации `/invite/...` → 404

- Ссылка отозвана → создай новую.
- `APP_URL` в `.env` неправильный (ссылки строятся не на тот домен) → поправь, `docker compose restart app`, создай ссылку заново.
- Пользователь уже залогинен → пусть откроет в инкогнито.

### Импорт `.xlsx` падает с 413

Размер файла больше лимита. Увеличь в трёх местах:
- `docker/nginx/default.conf` → `client_max_body_size`
- `docker/php/php.ini` → `post_max_size` и `upload_max_filesize`
- `app/Http/Controllers/SheetController.php` → `MAX_IMPORT_BODY_BYTES`

Пересобери: `docker compose up -d --build`.

### Полный сброс (если совсем сломано)

⚠ **Удалит ВСЁ** — БД, файлы, сессии, **И БЭКАПЫ**.

Бэкапы (`./backups/pre-migrate_*.sql.gz` и ежедневные) лежат в том же volume, что приложение → `down -v` уничтожит и их тоже. Восстановить будет неоткуда.

**Сначала скопируй бэкапы наружу:**

```bash
# Скопировать всю папку с бэкапами в домашнюю директорию
cp -r /opt/excel/backups ~/backups-safety-copy-$(date +%Y%m%d)

# Проверить что скопировалось
ls -la ~/backups-safety-copy-*/
```

**Только после этого** делай сброс:

```bash
cd /opt/excel
docker compose down -v
docker compose up -d --build
```

После запуска, если нужно — восстанови БД из сохранённой копии:

```bash
gunzip -c ~/backups-safety-copy-YYYYMMDD/excel_LATEST.sql.gz | \
  docker compose exec -T postgres psql -U excel_user -d excel_db
```

---

## Чек-лист перед релизом

- [ ] `APP_DEBUG=false` в `.env`
- [ ] `APP_KEY` заполнен
- [ ] `APP_URL` — продовый домен с `https://`
- [ ] Пароль админа сменён через `/profile`
- [ ] HTTPS работает
- [ ] `SESSION_SECURE_COOKIE=true` (при HTTPS)
- [ ] Firewall: открыты только 22, 80, 443 (`sudo ufw enable`)
- [ ] Через день — в `/opt/excel/backups/` появились автоматические бэкапы
- [ ] Off-site бэкап настроен (S3/Backblaze)
- [ ] Создание ссылки регистрации работает
- [ ] Регистрация по ссылке создаёт пользователя

---

## Полезные ссылки

- [README.md](README.md) — описание проекта
- [Cloudflare SSL/TLS](https://developers.cloudflare.com/ssl/)
- [Laravel Deployment](https://laravel.com/docs/12.x/deployment)

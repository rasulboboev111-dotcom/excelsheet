# Excel Tojiktelecom

Веб-приложение для совместной работы с таблицами в браузере (как Google Sheets, но self-hosted): импорт/экспорт `.xlsx`, формулы, права доступа на каждый лист, журнал изменений, отправка таблиц по email через Gmail.

**Стек:** Laravel 12 + Inertia 2 + Vue 3 + AG Grid + PostgreSQL + Redis + Nginx + PHP-FPM.

Деплой — **только через Docker**. Один способ, один пайплайн, никаких ручных установок PHP/PostgreSQL/Redis/nginx на хост.

---

## 🐳 Деплой через Docker

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

Альтернативы (Caddy на хосте, certbot напрямую) — см. `DEPLOY.md`.

---

## Авторы

Проект Tojiktelecom. Лицензия MIT.

При деплое возникли вопросы которых нет в этом README — пиши, дополним.

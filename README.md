# Excel Tojiktelecom

Веб-приложение для совместной работы с таблицами в браузере (как Google Sheets, но self-hosted): импорт/экспорт `.xlsx`, формулы, права доступа на каждый лист, журнал изменений, отправка таблиц по email через Gmail.

**Стек:** Laravel 12 + Inertia 2 + Vue 3 + AG Grid + PostgreSQL.

---

## Содержание

1. [Требования к серверу](#1-требования-к-серверу)
2. [Установка проекта](#2-установка-проекта)
3. [Настройка `.env`](#3-настройка-env)
4. [База данных и миграции](#4-база-данных-и-миграции)
5. [Создание первого админа](#5-создание-первого-админа)
6. [Права доступа на файлы](#6-права-доступа-на-файлы)
7. [Кэширование Laravel и cron](#7-кэширование-laravel-и-cron)
8. [Nginx + HTTPS](#8-nginx--https)
9. [Google OAuth — отправка писем через Gmail](#9-google-oauth--отправка-писем-через-gmail)
10. [Проверка после деплоя](#10-проверка-после-деплоя)
11. [Обновление проекта](#11-обновление-проекта)
12. [Восстановление доступа](#12-восстановление-доступа-к-аккаунту-админа)
13. [Чек-лист безопасности](#13-чек-лист-безопасности-перед-публикацией)

---

## 1. Требования к серверу

| Компонент | Минимум | Рекомендуется | Зачем |
|---|---|---|---|
| **OS** | Ubuntu 22.04 / Debian 12 | Ubuntu 24.04 LTS | Любой современный Linux подойдёт |
| **PHP** | **8.2** | 8.3+ | Laravel 12 не запустится на меньшем |
| **PostgreSQL** | **15** | 16+ | Миграция использует `NULLS NOT DISTINCT` (PG15+) |
| **Node.js** | 18 | 20 LTS | Для `npm run build` |
| **Composer** | 2.6 | 2.7+ | Менеджер пакетов PHP |
| **Web-сервер** | Nginx | Nginx | Apache тоже работает, но конфиги ниже только для Nginx |

**Расширения PHP** *(должны быть установлены)*:
`pdo_pgsql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `tokenizer`, `openssl`, `intl`, `fileinfo`, `gd`.

Установка одной командой на Ubuntu:
```bash
sudo apt update
sudo apt install -y nginx postgresql postgresql-contrib \
  php8.2-fpm php8.2-cli php8.2-pgsql php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl php8.2-gd \
  composer nodejs npm git unzip
```

---

## 2. Установка проекта

```bash
# Создать папку, склонировать репо
sudo mkdir -p /var/www/excel
sudo chown $USER:$USER /var/www/excel
cd /var/www/excel
git clone <ВАШ_REPO_URL> .

# Установить PHP-зависимости (БЕЗ dev-пакетов на проде!)
composer install --no-dev --optimize-autoloader

# Установить и собрать фронтенд
npm ci
npm run build
```

После этого в `public/build/` появятся скомпилированные ассеты.

---

## 3. Настройка `.env`

```bash
cp .env.example .env
nano .env
```

Заполните **минимум следующие переменные** для продакшена:

```env
APP_NAME="Excel Tojiktelecom"
APP_ENV=production              # ← ВАЖНО: production, не local
APP_KEY=                         # ← оставьте пустым, заполнится дальше
APP_DEBUG=false                 # ← ВАЖНО: false на проде
APP_URL=https://ваш-домен.tj    # ← реальный URL вашего сайта

# Логи: daily-driver чтобы файл не разрастался без границ
LOG_CHANNEL=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=info

# Сколько хранить аудит-журнал (в днях)
AUDIT_LOG_RETENTION_DAYS=90

# База данных
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1               # или адрес отдельного DB-сервера
DB_PORT=5432
DB_DATABASE=excel_db
DB_USERNAME=excel_user
DB_PASSWORD=<длинный-случайный-пароль>

# Сессии и кэш в БД (надёжнее чем file для возможного multi-server)
SESSION_DRIVER=database
CACHE_DRIVER=database
QUEUE_CONNECTION=sync
SESSION_LIFETIME=120

# Google OAuth (для отправки писем через Gmail). См. раздел 9.
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://ваш-домен.tj/auth/google/callback
```

### Сгенерировать `APP_KEY`

```bash
php artisan key:generate
```

Эта команда **сама** запишет новый уникальный ключ в `.env`. **Не копируйте `APP_KEY` с другого окружения** — каждое окружение должно иметь свой ключ. *(Иначе сессии и зашифрованные данные с одного сервера откроются на другом.)*

---

## 4. База данных и миграции

```bash
# Создать БД и пользователя в PostgreSQL
sudo -u postgres psql <<EOF
CREATE DATABASE excel_db;
CREATE USER excel_user WITH ENCRYPTED PASSWORD '<тот-же-пароль-что-в-env>';
GRANT ALL PRIVILEGES ON DATABASE excel_db TO excel_user;
\c excel_db
GRANT ALL ON SCHEMA public TO excel_user;
EOF

# Применить миграции (создаст все таблицы и роли)
php artisan migrate --force
```

Флаг `--force` нужен для production-окружения, иначе artisan переспросит подтверждение.

После миграции в БД будут таблицы: `users`, `sheets`, `sheet_data`, `sheet_audit_logs`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `sessions`, `cache`.

---

## 5. Создание первого админа

```bash
php artisan tinker
```

Внутри tinker выполните:

```php
$u = App\Models\User::create([
    'name'              => 'Админ',
    'email'             => 'admin@вашсайт.tj',
    'password'          => Hash::make('Длинный-Безопасный-Пароль-1234'),
    'email_verified_at' => now(),
]);
App\Models\Sheet::makeUserAdmin($u);
echo "Создан admin id=$u->id\n";
exit
```

После этого зайдите на `https://ваш-домен.tj/login` → email + пароль → попадёте в Dashboard как админ.

> ⚠️ **Сразу смените пароль** через интерфейс после первого входа: `/profile` → секция «Update Password».
>
> ⚠️ **Создайте второго админа** через `/users` или повторив команду выше — на случай если первый аккаунт будет заблокирован/потерян.

---

## 6. Права доступа на файлы

Web-сервер *(`www-data` для Nginx на Ubuntu)* должен иметь возможность писать в:
- `storage/` — сюда пишутся логи, кэш-файлы, сессии (если в файлах), временные xlsx
- `bootstrap/cache/` — сюда Laravel кэширует конфиг, роуты, view'хи

```bash
sudo chown -R www-data:www-data /var/www/excel/storage /var/www/excel/bootstrap/cache
sudo chmod -R 775 /var/www/excel/storage /var/www/excel/bootstrap/cache
```

`.env` должен быть **не доступен на чтение никому кроме самого приложения**:

```bash
sudo chmod 600 /var/www/excel/.env
sudo chown www-data:www-data /var/www/excel/.env
```

---

## 7. Кэширование Laravel и cron

### Закэшировать конфиг и роуты *(ускоряет сайт в 2-3 раза)*

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Прописать cron для запланированных задач

Сайт использует `php artisan schedule:run` для:
- Чистки старых записей audit-журнала *(`audit-log:cleanup`, ежедневно в 03:15, удаляет старше `AUDIT_LOG_RETENTION_DAYS` дней)*

Добавьте **одну** строку в crontab:

```bash
crontab -e
```

И вставьте:

```cron
* * * * * cd /var/www/excel && php artisan schedule:run >> /dev/null 2>&1
```

Эта строка запускает планировщик **каждую минуту**, а Laravel сам решает что именно сейчас выполнять.

Проверить что задачи зарегистрированы:
```bash
php artisan schedule:list
```

---

## 8. Nginx + HTTPS

### Конфиг Nginx

`/etc/nginx/sites-available/excel`:

```nginx
server {
    listen 80;
    server_name ваш-домен.tj www.ваш-домен.tj;
    return 301 https://ваш-домен.tj$request_uri;
}

server {
    listen 443 ssl http2;
    server_name ваш-домен.tj;
    root /var/www/excel/public;

    # SSL — заполнится Certbot'ом ниже
    ssl_certificate     /etc/letsencrypt/live/ваш-домен.tj/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ваш-домен.tj/privkey.pem;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";

    index index.php;
    charset utf-8;

    # Большие XLSX-файлы при импорте — 50 МБ
    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 120;
    }

    # Запретить доступ к скрытым/служебным файлам
    location ~ /\.(?!well-known).*       { deny all; }
    location ~ ^/(\.env|\.git|composer\.|package(-lock)?\.json|vendor|storage|tests) { deny all; }
}
```

Активировать конфиг:
```bash
sudo ln -s /etc/nginx/sites-available/excel /etc/nginx/sites-enabled/
sudo nginx -t   # проверить синтаксис
sudo systemctl reload nginx
```

### HTTPS через Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d ваш-домен.tj -d www.ваш-домен.tj
```

Certbot **сам обновит** Nginx-конфиг и пропишет SSL-сертификаты. Сертификат будет автообновляться.

---

## 9. Google OAuth — отправка писем через Gmail

Сайт умеет отправлять таблицы по email **от имени юзера**: каждый юзер однократно подключает свой Gmail, после чего его письма уходят с его собственного gmail-адреса (получатель видит реального отправителя, копия попадает в Sent у юзера).

### Шаг 1 — Создать Google Cloud проект

1. Откройте [Google Cloud Console](https://console.cloud.google.com/)
2. Создайте новый проект *(или используйте существующий)*
3. Запомните **Project ID**

### Шаг 2 — Включить Gmail API

1. [APIs & Services → Library](https://console.cloud.google.com/apis/library)
2. Найдите **Gmail API** → нажмите **Enable**

### Шаг 3 — OAuth consent screen

1. [APIs & Services → OAuth consent screen](https://console.cloud.google.com/apis/credentials/consent)
2. **User type**: External *(если у вас не Workspace-domain)*
3. Заполните:
   - **App name**: «Excel Tojiktelecom»
   - **User support email**: ваш email
   - **App logo**: загрузите логотип *(опционально)*
   - **Application home page**: `https://ваш-домен.tj`
   - **Privacy policy URL**: ссылка на политику конфиденциальности *(нужно создать на сайте)*
   - **Terms of service URL**: ссылка на условия пользования
   - **Authorized domains**: `ваш-домен.tj`
   - **Developer contact**: ваш email
4. **Scopes** → нажмите **Add or remove scopes** → отметьте:
   - `openid`
   - `https://www.googleapis.com/auth/userinfo.email`
   - `https://www.googleapis.com/auth/userinfo.profile`
   - `https://www.googleapis.com/auth/gmail.send` ← **обязательно!**
5. **Save and continue**

### Шаг 4 — OAuth Client ID

1. [Credentials → + Create Credentials → OAuth client ID](https://console.cloud.google.com/apis/credentials)
2. **Application type**: Web application
3. **Authorized redirect URIs** *(добавьте все которые используете)*:
   - `https://ваш-домен.tj/auth/google/callback` — production
   - `http://127.0.0.1:8000/auth/google/callback` — для локального dev
4. Жмите **Create** → получите:
   - **Client ID**
   - **Client Secret**
5. Скопируйте оба в `.env`:
   ```env
   GOOGLE_CLIENT_ID=<вставить-сюда>.apps.googleusercontent.com
   GOOGLE_CLIENT_SECRET=GOCSPX-<вставить-сюда>
   GOOGLE_REDIRECT_URI=https://ваш-домен.tj/auth/google/callback
   ```
6. Перезагрузить конфиг:
   ```bash
   php artisan config:cache
   sudo systemctl reload php8.2-fpm
   ```

### Шаг 5 — Test users *(пока приложение в Testing-режиме)*

Пока ваш OAuth consent screen в **Testing**, использовать его могут только:
- Владелец проекта
- Юзеры, добавленные в **Test Users** *(до 100 шт.)*

Чтобы конкретный юзер сайта мог подключить Gmail:
1. [OAuth consent screen → Test users](https://console.cloud.google.com/apis/credentials/consent)
2. **+ Add users** → введите его Gmail-адрес → Save

### Шаг 6 — Production: Google Verification

Когда вы готовы открыть отправку для **всех** юзеров (без списка Test Users):

1. [OAuth consent screen → Publishing status](https://console.cloud.google.com/apis/credentials/consent) → **Publish App**
2. Поскольку `gmail.send` — это **sensitive scope**, Google потребует **verification**:
   - Заполните форму
   - Подтвердите владение доменом *(через Google Search Console)*
   - Возможно потребуется видео-демонстрация что ваше приложение делает с Gmail
3. Ожидание: от 2 дней до 2 недель.

**Совет:** до verification можно работать в Testing с реальными юзерами через список Test Users (до 100). Этого хватает для большинства бизнес-сценариев.

### Шаг 7 — Выдать юзеру право на отправку

После настройки OAuth админу нужно **дать разрешение** конкретным юзерам:

1. Залогиньтесь как админ → `/users`
2. У нужного юзера нажмите **Изменить**
3. Поставьте галочку **«Может отправлять почту»**
4. Сохранить

После этого юзер увидит секцию «Отправка через Gmail» в `/profile` и кнопку «📧 Отправить» в Dashboard.

### Шаг 8 — Юзер подключает Gmail

1. Юзер заходит на `/profile`
2. В секции «Отправка писем через Gmail» нажимает **«Подключить Gmail»**
3. Открывается страница Google → выбирает аккаунт → **разрешает** (см. что именно — должно быть *«Send email on your behalf»*)
4. Возвращается на сайт залогиненный, видит **«✓ Подключено: <email>»**
5. Теперь в Dashboard у листа кнопка **«📧 Отправить»** → модалка → отправка прямо со своего Gmail

---

## 10. Проверка после деплоя

| Проверка | Команда / URL | Что должно быть |
|---|---|---|
| Сайт открывается по HTTPS | `https://ваш-домен.tj` | 200 OK, нет mixed content warnings |
| HTTPS принудительный | `http://ваш-домен.tj` | 301 → https |
| Логин админа | `/login` | После ввода — попадаете в Dashboard |
| Создание листа | Dashboard → «+» | Лист создаётся |
| Импорт `.xlsx` | Импорт через шапку | Лист появляется в табах |
| Список юзеров | `/users` | Видна таблица, можно создать юзера |
| Журнал | `/audit-log` | Записи появляются после действий |
| Schedule | `php artisan schedule:list` | Видна `audit-log:cleanup` |
| Cron | `crontab -l` | Есть строка с `schedule:run` |
| Логи пишутся | `tail -f storage/logs/laravel-$(date +%Y-%m-%d).log` | Видны access-записи |
| Gmail OAuth *(если настроен)* | `/profile` → «Подключить Gmail» | Редирект на Google проходит, после Allow — «✓ Подключено» |
| Отправка письма | Dashboard → «📧 Отправить» | Письмо приходит получателю |

---

## 11. Обновление проекта

Создайте файл `deploy.sh` в корне проекта:

```bash
#!/bin/bash
set -e

cd /var/www/excel

# 1. Получить новый код
git pull origin main

# 2. Зависимости
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# 3. Миграции
php artisan migrate --force

# 4. Кэшировать заново
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. Перезагрузить PHP-FPM (чтобы opcache подхватил новый код)
sudo systemctl reload php8.2-fpm

echo "Deploy complete"
```

Сделать исполняемым:
```bash
chmod +x deploy.sh
```

Запускать при каждом обновлении:
```bash
./deploy.sh
```

---

## 12. Восстановление доступа к аккаунту админа

Если **забыли пароль** и нет другого админа:

```bash
ssh user@ваш-сервер
cd /var/www/excel
php artisan tinker
```

```php
$u = App\Models\User::where('email', 'admin@вашсайт.tj')->first();
$u->password = Hash::make('новый-пароль-12345');
$u->save();
exit
```

После этого войдите с новым паролем и поменяйте через UI на нормальный.

> ⚠️ Это **единственный** способ восстановления (мы намеренно отключили «забыл пароль» через email чтобы не зависеть от SMTP). Берегите SSH-ключи к серверу как ключ от сейфа.

---

## 13. Чек-лист безопасности перед публикацией

- [ ] `APP_ENV=production` и `APP_DEBUG=false` в `.env`
- [ ] `APP_KEY` уникальный *(сгенерирован на сервере, не скопирован с dev)*
- [ ] HTTPS принудительный (HTTP редиректит на HTTPS)
- [ ] Минимум **2 админа** в системе *(на случай блокировки одного)*
- [ ] Длинные случайные пароли у админов *(>12 символов)*
- [ ] Длинный случайный пароль БД, не используется в других местах
- [ ] `.env` имеет права `chmod 600`, владелец — `www-data`
- [ ] `storage/` и `bootstrap/cache/` writable только `www-data`
- [ ] **Ежедневный бэкап БД** настроен *(см. ниже)*
- [ ] Cron `schedule:run` добавлен и проверен через `php artisan schedule:list`
- [ ] SSH-доступ только по ключам, root-логин запрещён
- [ ] Файрвол открывает только 22, 80, 443
- [ ] Google OAuth verification **пройдена** *(если планируется >100 юзеров)*

### Бэкапы БД

Минимум:

```bash
# /etc/cron.daily/excel-db-backup
#!/bin/bash
DATE=$(date +%Y-%m-%d_%H%M)
sudo -u postgres pg_dump excel_db | gzip > /var/backups/excel_db_$DATE.sql.gz
find /var/backups -name "excel_db_*.sql.gz" -mtime +30 -delete
```

```bash
sudo chmod +x /etc/cron.daily/excel-db-backup
```

Это создаёт ежедневный бэкап в `/var/backups/`, хранит 30 дней.

**Лучше дополнительно копировать на S3 / Backblaze** *(чтобы при сбое сервера бэкапы не пропали вместе с ним)*.

---

## Стек версий

- **PHP** 8.2+
- **Laravel** 12.58
- **Inertia.js** (PHP) 2.0
- **Vue** 3.5
- **AG Grid Community** 35
- **HyperFormula** 3 *(формулы)*
- **PhpSpreadsheet** 4 *(импорт/экспорт xlsx)*
- **Spatie Laravel Permission** 6 *(роли admin + per-sheet права через teams)*
- **Google API Client** 2 *(Gmail OAuth)*
- **Tailwind CSS** 3
- **PostgreSQL** 15+

---

## Частые проблемы

### `403 Admin only` после нажатия любой кнопки
В сессии есть остатки от логина под не-админом. Выйдите и войдите заново под админ-аккаунтом.

### `cURL error 60: SSL certificate problem` при OAuth
PHP не находит CA-bundle. На Linux обычно автоматически работает; если нет — установите:
```bash
sudo apt install -y ca-certificates
sudo update-ca-certificates
```

### `Could not find driver` при `php artisan migrate`
Не установлено `php8.2-pgsql`:
```bash
sudo apt install -y php8.2-pgsql
sudo systemctl reload php8.2-fpm
```

### `Class "Google\Client" not found` после деплоя
Нужно перегенерировать autoload:
```bash
composer dump-autoload --optimize
```

### Письма не уходят, в логах `insufficient_scopes`
Юзер не в Test Users *(или приложение не в production verification)*. См. раздел 9, шаги 5-6.

### Cron `schedule:run` не запускается
Проверьте что crontab прописан под пользователем который имеет доступ к проекту *(не root)*:
```bash
crontab -l
```

---

## Контакты

Вопросы по деплою → ваш админ.
Issues / баги → внутренний tracker.

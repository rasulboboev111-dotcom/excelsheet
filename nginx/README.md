# nginx — конфиги для dev и prod

Зеркальная пара конфигов: на dev (Windows) и на prod (Linux + PHP-FPM)
работают по одной и той же схеме. Меняется только подложка (php-cgi на
Windows вместо PHP-FPM, абсолютные vs относительные пути для `mime.types`
и `fastcgi_params`).

## Локально (Windows)

`dev.windows.conf` — слушает `127.0.0.1:8000`, перенаправляет PHP на
`php-cgi.exe -b 127.0.0.1:9000`.

### Запуск

1. Останови `php artisan serve` если работает (он тоже слушает 8000).
2. Запусти Redis: двойной клик на `start-redis.bat` (если ещё не запущен).
3. Запусти nginx + php-cgi: двойной клик на `start-nginx.bat`.
4. Открой `http://127.0.0.1:8000` — раздаётся через nginx.

### Остановка

Закрыть окно `start-nginx.bat` или запустить `stop-nginx.bat`.

### Логи

`.nginx/logs/access.log` и `.nginx/logs/error.log` — оба в `.gitignore`.

### Что отличается от прода

| | dev (Windows) | prod (Linux) |
|---|---|---|
| PHP worker | `php-cgi.exe` (одно соединение TCP) | `php8.2-fpm` (Unix socket, пул) |
| Пути в `include` | абсолютные `D:/.../mime.types` | относительные `mime.types` |
| TLS | нет | Let's Encrypt + redirect 80→443 |
| Логи | `.nginx/logs/` | `/var/log/nginx/` |
| Юзер процесса | твой | `www-data` |

## На проде (Linux)

`prod.linux.conf` — копируется в `/etc/nginx/sites-available/excel`.

### Установка

```bash
# 1. Установить nginx + PHP-FPM (Debian/Ubuntu)
sudo apt update
sudo apt install -y nginx php8.2-fpm

# 2. Скопировать конфиг
sudo cp nginx/prod.linux.conf /etc/nginx/sites-available/excel

# 3. ОБЯЗАТЕЛЬНО подправить server_name и root в этом файле перед активацией
sudo nano /etc/nginx/sites-available/excel

# 4. Активировать
sudo ln -s /etc/nginx/sites-available/excel /etc/nginx/sites-enabled/excel
sudo rm -f /etc/nginx/sites-enabled/default     # убрать дефолтный сайт

# 5. Применить пул PHP-FPM (см. секцию в конце prod.linux.conf)
sudo nano /etc/php/8.2/fpm/pool.d/www.conf

# 6. Проверка + перезагрузка
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl reload php8.2-fpm
```

### TLS (HTTPS)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
# certbot автоматически добавит 443-блок и редирект 80→443
sudo systemctl reload nginx
```

После этого можно раскомментировать `return 301 https://...;` в http-блоке
`prod.linux.conf` (если certbot ещё не сделал этого сам).

### Деплой

Стандартный цикл — см. `docs/PERFORMANCE.md` секция «Команды деплоя».

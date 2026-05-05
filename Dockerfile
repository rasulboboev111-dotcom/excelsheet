# syntax=docker/dockerfile:1.7
#
# Multi-stage Dockerfile для Excel Tojiktelecom.
# Цели (targets):
#   - app:   PHP-FPM 8.2 + все экстеншены + готовый Laravel.
#   - nginx: nginx:alpine + статические файлы из /public/ + конфиг.
# В docker-compose.yml оба образа собираются из этого файла.

# ============================================================
# Stage 1: Composer-зависимости
# ============================================================
FROM composer:2.7 AS composer-deps
WORKDIR /app
COPY composer.json composer.lock ./
# Минимально нужные файлы для composer install (он может вызвать artisan).
COPY artisan ./
COPY app/ ./app/
COPY bootstrap/ ./bootstrap/
COPY config/ ./config/
COPY database/ ./database/
COPY public/index.php ./public/
COPY routes/ ./routes/
COPY storage/ ./storage/
# Игнорируем platform-requirements: composer:2.7 image не содержит часть
# нужных расширений (ext-gd, ext-intl, ext-pdo_pgsql и др.), но в финальном
# app-образе (php:8.2-fpm-alpine с docker-php-ext-install) они все есть.
# Здесь composer только разрешает зависимости и скачивает пакеты — для этого
# реальные расширения не нужны.
RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --ignore-platform-reqs

# ============================================================
# Stage 2: Сборка фронта (Vite)
# ============================================================
# resources/js/app.js импортит ZiggyVue из ../../vendor/tightenco/ziggy —
# это PHP-пакет от Tighten, у которого внутри лежит JS-исходник для Vite.
# Поэтому в node-стадию нужно занести vendor/ ИЗ composer-стадии,
# иначе RollupError: Could not resolve "../../vendor/tightenco/ziggy".
FROM node:20-alpine AS node-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY postcss.config.js tailwind.config.js vite.config.js jsconfig.json ./
COPY resources/ ./resources/
COPY --from=composer-deps /app/vendor ./vendor
RUN npm run build

# ============================================================
# Stage 3: Финальный app-образ (PHP-FPM)
# ============================================================
# Debian-вариант (bookworm), а не alpine — на нём расширения собираются
# из готовых apt-пакетов с dev-headers, без компиляции из исходников.
# Используем install-php-extensions (https://github.com/mlocati/docker-php-extension-installer)
# — это de-facto стандарт для официального PHP-образа: один шаг, всё что
# нужно подтянет автоматически. В Alpine компиляция занимала 15-30 минут,
# тут — 1-2 минуты.
FROM php:8.2-fpm-bookworm AS app

# Системные утилиты + install-php-extensions.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        zip \
        tini \
    && curl -sSLo /usr/local/bin/install-php-extensions \
        https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions \
        pdo_pgsql \
        bcmath \
        zip \
        intl \
        gd \
        opcache \
        mbstring \
        exif \
        pcntl \
        redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP конфиги — наш php.ini + agressive OPcache.
COPY docker/php/php.ini      /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini  /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/www.conf     /usr/local/etc/php-fpm.d/zz-www.conf

# Код приложения.
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=composer-deps --chown=www-data:www-data /app/vendor ./vendor
COPY --from=node-build --chown=www-data:www-data /app/public/build ./public/build

# Права на storage и bootstrap/cache (php-fpm должен туда писать).
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Entrypoint: ждёт PostgreSQL → migrate → config:cache → php-fpm.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
# tini в Debian apt-пакете лежит в /usr/bin/tini (на Alpine было /sbin/tini).
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]

# ============================================================
# Stage 4: nginx-образ со статикой проекта
# ============================================================
FROM nginx:1.27-alpine AS nginx

COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Каталоги под логи (nginx:alpine их и так создаёт, но на всякий).
RUN mkdir -p /var/log/nginx && chown -R nginx:nginx /var/log/nginx

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]

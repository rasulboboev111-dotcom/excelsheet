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
RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts

# ============================================================
# Stage 2: Сборка фронта (Vite)
# ============================================================
FROM node:20-alpine AS node-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY postcss.config.js tailwind.config.js vite.config.js jsconfig.json ./
COPY resources/ ./resources/
RUN npm run build

# ============================================================
# Stage 3: Финальный app-образ (PHP-FPM)
# ============================================================
FROM php:8.2-fpm-alpine AS app

# Системные зависимости + PHP-экстеншены.
RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        libpng-dev \
        oniguruma-dev \
        libxml2-dev \
        zip \
        unzip \
        git \
        bash \
        tini \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        bcmath \
        zip \
        intl \
        gd \
        opcache \
        mbstring \
        exif \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/cache/apk/*

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
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/entrypoint.sh"]
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

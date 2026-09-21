# syntax=docker/dockerfile:1.7
# MediFind Phase 1A local-development PHP-FPM image. Not production-ready.

FROM composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS composer_bin

FROM node:24.21.0-bookworm@sha256:64af3819f9275802414d7cdc38c27e9d82bd564dec4d4da87d008255d36c63b4 AS assets
WORKDIR /src
COPY apps/api/package.json apps/api/package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund \
    && npm cache clean --force
COPY apps/api/resources ./resources
COPY apps/api/public ./public
COPY apps/api/vite.config.js ./
RUN npm run build \
    && rm -rf /root/.npm /src/node_modules

FROM php:8.4.25-fpm-bookworm@sha256:0a5638c2c48eb04987107ae448a398dd18345bcf659e15fd99a422617e754264 AS php_builder
WORKDIR /var/www/html

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        $PHPIZE_DEPS \
        libpq-dev \
        libicu-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip pcntl opcache \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear /var/lib/apt/lists/* /tmp/*

COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader \
    && rm -rf /root/.composer /tmp/*

COPY apps/api/ ./
COPY --from=assets /src/public/build ./public/build

RUN cp .env.example .env \
    && APP_KEY="$(php -r 'echo "base64:" . base64_encode(random_bytes(32));')" \
        php artisan package:discover --ansi \
    && composer dump-autoload --optimize \
    && rm -f .env \
    && rm -f /usr/bin/composer \
    && rm -rf /root/.composer /tmp/*

FROM php:8.4.25-fpm-bookworm@sha256:0a5638c2c48eb04987107ae448a398dd18345bcf659e15fd99a422617e754264 AS runtime
LABEL org.opencontainers.image.title="medifind-app"
LABEL org.opencontainers.image.description="MediFind Phase 1A local-development PHP-FPM image. Not production-ready."
LABEL medifind.image.role="local-development"

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq5 \
        libicu72 \
        libzip4 \
        libfcgi-bin \
    && rm -rf /var/lib/apt/lists/* /tmp/*

COPY --from=php_builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php_builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/php/php.ini /usr/local/etc/php/conf.d/medifind.ini
COPY infra/php/www-ping.conf /usr/local/etc/php-fpm.d/zz-ping.conf
COPY infra/php/zz-nonroot.conf /usr/local/etc/php-fpm.d/zz-nonroot.conf
COPY --from=php_builder --chown=www-data:www-data /var/www/html /var/www/html

# Official php-fpm-bookworm ships PHPIZE_DEPS (gcc/make/linux-libc-dev). Remove them
# from the runtime stage; they are not required to run FPM.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache \
    && apt-get purge -y --auto-remove \
        autoconf \
        dpkg-dev \
        file \
        g++ \
        gcc \
        libc-dev \
        linux-libc-dev \
        make \
        pkg-config \
        re2c \
    && rm -rf /var/lib/apt/lists/* /tmp/* \
    && test ! -e /var/www/html/.env \
    && test ! -e /usr/bin/composer \
    && test ! -e /usr/bin/git \
    && test ! -e /usr/bin/gcc

USER www-data

FROM nginx:1.28.0-bookworm@sha256:552e7481ca93ffccd046aa658dbbed22caefbc09c66fa7cd247cbb90b8a5c609 AS nginx
LABEL org.opencontainers.image.title="medifind-nginx"
LABEL org.opencontainers.image.description="MediFind Phase 1A local-development Nginx image with baked public assets. Not production-ready."
LABEL medifind.image.role="local-development"

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libgnutls30=3.7.9-2+deb12u7 \
        libssl3=3.0.20-1~deb12u2 \
        openssl=3.0.20-1~deb12u2 \
    && rm -rf /var/lib/apt/lists/* /tmp/* \
    && test "$(dpkg-query -W -f='${Version}' libgnutls30)" = "3.7.9-2+deb12u7" \
    && test "$(dpkg-query -W -f='${Version}' libssl3)" = "3.0.20-1~deb12u2" \
    && test "$(dpkg-query -W -f='${Version}' openssl)" = "3.0.20-1~deb12u2" \
    && printf 'libgnutls30=%s\nlibssl3=%s\nopenssl=%s\n' \
        "$(dpkg-query -W -f='${Version}' libgnutls30)" \
        "$(dpkg-query -W -f='${Version}' libssl3)" \
        "$(dpkg-query -W -f='${Version}' openssl)" \
        > /etc/medifind-localdev-packages.txt

COPY --from=php_builder /var/www/html/public /var/www/html/public
COPY infra/nginx/default.conf /etc/nginx/conf.d/default.conf

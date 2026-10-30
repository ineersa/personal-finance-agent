# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1.13.2-php8.5-bookworm@sha256:308b7dbf7e263a7200f17c1957c0ed1dfdcd01fd914e1b7e99eef7f92b06d4ae AS frankenphp_base

WORKDIR /app

RUN install-php-extensions @composer bcmath intl mbstring pdo_sqlite sqlite3 pcntl posix zip opcache

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev APP_DEBUG=1
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod APP_DEBUG=0
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --prefer-dist --no-autoloader --no-scripts --no-interaction --no-progress

COPY . ./
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && php bin/console asset-map:compile --env=prod --no-debug \
    && mkdir -p var/data \
    && chown -R 1000:1000 var

USER 1000:1000

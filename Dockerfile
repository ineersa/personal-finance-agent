# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1.13.2-php8.5-bookworm@sha256:308b7dbf7e263a7200f17c1957c0ed1dfdcd01fd914e1b7e99eef7f92b06d4ae AS frankenphp_base

WORKDIR /app

RUN install-php-extensions @composer bcmath intl mbstring pdo_sqlite sqlite3 pcntl posix zip opcache

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev APP_DEBUG=1
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

FROM node:22-bookworm-slim@sha256:4b150b48b4116e2a22f2c39cc27025fbf4f153e2078fd3b14d159fdb5c7279da AS tooling_node

FROM frankenphp_dev AS frankenphp_tooling
COPY --from=tooling_node /usr/local/bin/node /usr/local/bin/node
COPY --from=tooling_node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && apt-get update && apt-get install -y --no-install-recommends curl xz-utils libnss3 libatk1.0-0 libatk-bridge2.0-0 libx11-xcb1 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 libasound2 libpango-1.0-0 libcairo2 libcups2 libxkbcommon0 \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL https://github.com/symfony-cli/symfony-cli/releases/download/v5.20.0/symfony-cli_linux_amd64.tar.gz | tar -xz -C /usr/local/bin symfony
ENV HOME=/app/var/tooling/home PLAYWRIGHT_BROWSERS_PATH=/app/var/tooling/browsers
WORKDIR /app
ENTRYPOINT []
CMD ["php", "--version"]

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

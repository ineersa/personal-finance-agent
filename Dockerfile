# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1.13.2-php8.5-bookworm@sha256:308b7dbf7e263a7200f17c1957c0ed1dfdcd01fd914e1b7e99eef7f92b06d4ae

WORKDIR /app

RUN install-php-extensions @composer bcmath intl mbstring pdo_sqlite sqlite3 pcntl posix zip opcache

COPY docker/frankenphp/Caddyfile /etc/caddy/Caddyfile

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

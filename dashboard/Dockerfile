# syntax=docker/dockerfile:1

FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM moby/buildkit:v0.25.1 AS buildkit

FROM gcr.io/go-containerregistry/crane:v0.22.1 AS crane

FROM dunglas/frankenphp:1-php8.4

ARG TARGETARCH
ARG KUBECTL_VERSION=v1.36.5

RUN install-php-extensions pdo_sqlite intl zip bcmath pcntl opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && curl -fsSL -o /usr/local/bin/kubectl "https://dl.k8s.io/release/${KUBECTL_VERSION}/bin/linux/${TARGETARCH}/kubectl" \
    && chmod +x /usr/local/bin/kubectl

COPY --from=buildkit /usr/bin/buildctl /usr/local/bin/buildctl
COPY --from=crane /ko-app/crane /usr/local/bin/crane
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build public/build
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction

COPY docker/entrypoint.sh /usr/local/bin/opencloud-entrypoint

ENV SERVER_NAME=:8080 \
    SERVER_ROOT=/app/public \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/lib/opencloud/data/database.sqlite

EXPOSE 8080

ENTRYPOINT ["opencloud-entrypoint"]
CMD ["web"]

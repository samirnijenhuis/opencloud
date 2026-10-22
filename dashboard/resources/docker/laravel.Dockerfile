FROM {{ base_image }} AS base
RUN install-php-extensions {{ base_extensions }}
{{ extra_extensions }}
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
WORKDIR /app

FROM base AS vendor
COPY . .
RUN --mount=type=cache,target=/tmp/composer-cache {{ secret_mounts }}composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
{{#assets}}

FROM node:22-alpine AS assets
WORKDIR /app
# A stalled download fails after 60 seconds and npm tries again, instead of waiting 5 minutes.
ENV NPM_FLAGS="--no-audit --no-fund --fetch-timeout=60000 --fetch-retries=5 --fetch-retry-mintimeout=2000"
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN --mount=type=cache,target=/root/.npm {{ secret_mounts }}if [ -f package-lock.json ]; then npm ci $NPM_FLAGS; else npm install $NPM_FLAGS; fi && npm run build
{{/assets}}

FROM base
COPY . .
COPY --from=vendor /app/vendor ./vendor
{{#assets}}
COPY --from=assets /app/public/build ./public/build
{{/assets}}
RUN composer dump-autoload --optimize --no-dev && php artisan package:discover --ansi
{{ build_command }}
ENV SERVER_NAME=:8080
EXPOSE 8080
CMD ["sh", "-c", "php artisan optimize || echo 'opencloud: php artisan optimize failed, so the app starts without caches'; exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile"]

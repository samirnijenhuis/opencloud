FROM {{ base_image }} AS base
RUN install-php-extensions {{ base_extensions }}
{{ extra_extensions }}
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

FROM base AS vendor
COPY . .
RUN {{ composer_secrets }}composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
{{#assets}}

FROM node:22-alpine AS assets
WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi && npm run build
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

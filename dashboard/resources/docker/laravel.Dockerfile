FROM {{ base_image }} AS base
RUN install-php-extensions {{ base_extensions }}
{{ extra_extensions }}
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
WORKDIR /app

FROM base AS vendor
# Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
ARG OPENCLOUD_BUILD_ENV
# Opencloud sets this to a key of the application, so the cache mounts of one application never reach another.
ARG OPENCLOUD_CACHE_ID
COPY . .
RUN --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/tmp/composer-cache,target=/tmp/composer-cache {{ secret_mounts }}composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
{{#assets}}

# Vite plugins such as Wayfinder run artisan during the asset build, so this stage has PHP and Node.
FROM base AS assets
ARG OPENCLOUD_BUILD_ENV
ARG OPENCLOUD_CACHE_ID
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx && ln -s ../lib/node_modules/corepack/dist/corepack.js /usr/local/bin/corepack \
    && echo 'memory_limit=-1' > "$PHP_INI_DIR/conf.d/zz-opencloud-build.ini"
# A stalled download fails after 60 seconds and npm tries again, instead of waiting 5 minutes.
# IPv6 goes first, because on some networks IPv4 downloads stall while IPv6 does not. Without IPv6, Node falls back to IPv4 at once.
ENV NPM_FLAGS="--no-audit --no-fund --fetch-timeout=60000 --fetch-retries=5 --fetch-retry-mintimeout=2000" COREPACK_ENABLE_DOWNLOAD_PROMPT=0 NODE_OPTIONS=--dns-result-order=ipv6first
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN composer dump-autoload --no-dev --no-scripts && php artisan package:discover --ansi && if [ -d vendor/laravel/wayfinder ]; then php artisan wayfinder:generate; fi
# The lock file picks the package manager.
RUN --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/root/.npm,target=/root/.npm --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/root/.local/share/pnpm/store,target=/root/.local/share/pnpm/store --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/usr/local/share/.cache/yarn,target=/usr/local/share/.cache/yarn {{ secret_mounts }}if [ -f pnpm-lock.yaml ]; then npm install -g $NPM_FLAGS "pnpm@{{ pnpm_version }}" && pnpm install --frozen-lockfile && pnpm run build; elif [ -f yarn.lock ]; then corepack enable && yarn install && yarn run build; elif [ -f package-lock.json ]; then npm ci $NPM_FLAGS && npm run build; else npm install $NPM_FLAGS && npm run build; fi
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

# syntax=docker/dockerfile:1

FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM moby/buildkit:v0.25.1 AS buildkit

FROM gcr.io/go-containerregistry/crane:v0.22.1 AS crane

FROM dunglas/frankenphp:1.13.2-php8.5.12

ARG TARGETARCH
ARG KUBECTL_VERSION=v1.36.5

RUN install-php-extensions pdo_sqlite intl zip bcmath pcntl opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends git openssh-client \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && curl -fsSL -o /usr/local/bin/kubectl "https://dl.k8s.io/release/${KUBECTL_VERSION}/bin/linux/${TARGETARCH}/kubectl" \
    && chmod +x /usr/local/bin/kubectl

# The sums come from tofu_${TOFU_VERSION}_SHA256SUMS of the OpenTofu release.
ARG TOFU_VERSION=1.13.2
ARG TOFU_SHA256_AMD64=ac05d9952662c7db28dd57e93f0a68018837bfdf9342384ec0987977c16a9645
ARG TOFU_SHA256_ARM64=88e2d9e3f94f97b76ab2faf55ade87578f56aed19c5e45fa139d70e994366d1f

RUN case "$TARGETARCH" in \
        amd64) TOFU_SHA256="$TOFU_SHA256_AMD64" ;; \
        arm64) TOFU_SHA256="$TOFU_SHA256_ARM64" ;; \
        *) echo "No OpenTofu checksum for $TARGETARCH" >&2; exit 1 ;; \
    esac \
    && curl -fsSL -o /tmp/tofu.tar.gz "https://github.com/opentofu/opentofu/releases/download/v${TOFU_VERSION}/tofu_${TOFU_VERSION}_linux_${TARGETARCH}.tar.gz" \
    && echo "${TOFU_SHA256}  /tmp/tofu.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/tofu.tar.gz -C /usr/local/bin tofu \
    && rm /tmp/tofu.tar.gz

# The modules live in the tofu/ folder at the root of the repository. Build with --build-context tofu=tofu.
COPY --from=tofu . /opt/opencloud/tofu

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
    DB_DATABASE=/var/lib/opencloud/data/database.sqlite \
    OPENCLOUD_TOFU_MODULES=/opt/opencloud/tofu

EXPOSE 8080

ENTRYPOINT ["opencloud-entrypoint"]
CMD ["web"]

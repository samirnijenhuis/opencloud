<?php

namespace Tests\Domains\Builds;

use App\Domains\Builds\LaravelDockerfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LaravelDockerfileTest extends TestCase
{
    public function test_the_stock_laravel_app_gets_php_8_4_and_the_asset_build(): void
    {
        $composer = ['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0', 'laravel/tinker' => '^2.10.1']];
        $package = ['private' => true, 'type' => 'module', 'scripts' => ['build' => 'vite build', 'dev' => 'vite']];

        $this->assertSame(<<<'DOCKERFILE'
            FROM dunglas/frankenphp:1-php8.4 AS base
            RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_mysql pdo_pgsql redis zip
            COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
            ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
            WORKDIR /app

            FROM base AS vendor
            # Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
            ARG OPENCLOUD_BUILD_ENV
            COPY . .
            RUN --mount=type=cache,target=/tmp/composer-cache composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

            # Vite plugins such as Wayfinder run artisan during the asset build, so this stage has PHP and Node.
            FROM base AS assets
            ARG OPENCLOUD_BUILD_ENV
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
            RUN --mount=type=cache,target=/root/.npm --mount=type=cache,target=/root/.local/share/pnpm/store --mount=type=cache,target=/usr/local/share/.cache/yarn if [ -f pnpm-lock.yaml ]; then npm install -g $NPM_FLAGS "pnpm@latest" && pnpm install --frozen-lockfile && pnpm run build; elif [ -f yarn.lock ]; then corepack enable && yarn install && yarn run build; elif [ -f package-lock.json ]; then npm ci $NPM_FLAGS && npm run build; else npm install $NPM_FLAGS && npm run build; fi

            FROM base
            COPY . .
            COPY --from=vendor /app/vendor ./vendor
            COPY --from=assets /app/public/build ./public/build
            RUN composer dump-autoload --optimize --no-dev && php artisan package:discover --ansi
            ENV SERVER_NAME=:8080
            EXPOSE 8080
            CMD ["sh", "-c", "php artisan optimize || echo 'opencloud: php artisan optimize failed, so the app starts without caches'; exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile"]

            DOCKERFILE, $this->template()->render($composer, $package));
    }

    public function test_extensions_build_secrets_and_the_build_command_without_package_json(): void
    {
        $composer = ['require' => [
            'php' => '~8.2.0',
            'ext-gd' => '*',
            'ext-Imagick' => '*',
            'ext-mbstring' => '*',
            'ext-zend-opcache' => '*',
            'ext-swoole' => '*',
            'ext-imagick' => '*',
            'laravel/framework' => '^11.0',
        ]];

        $this->assertSame(<<<'DOCKERFILE'
            FROM ghcr.io/acme/php:8.2-frankenphp AS base
            RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_mysql pdo_pgsql redis zip
            RUN install-php-extensions imagick swoole
            COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
            ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
            WORKDIR /app

            FROM base AS vendor
            # Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
            ARG OPENCLOUD_BUILD_ENV
            COPY . .
            RUN --mount=type=cache,target=/tmp/composer-cache --mount=type=secret,id=COMPOSER_AUTH,env=COMPOSER_AUTH --mount=type=secret,id=NOVA_LICENSE,env=NOVA_LICENSE composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

            FROM base
            COPY . .
            COPY --from=vendor /app/vendor ./vendor
            RUN composer dump-autoload --optimize --no-dev && php artisan package:discover --ansi
            RUN php artisan icons:cache
            ENV SERVER_NAME=:8080
            EXPOSE 8080
            CMD ["sh", "-c", "php artisan optimize || echo 'opencloud: php artisan optimize failed, so the app starts without caches'; exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile"]

            DOCKERFILE, (new LaravelDockerfile(['8.2', '8.3', '8.4'], 'ghcr.io/acme/php:{php}-frankenphp'))->render(
            $composer,
            null,
            ['COMPOSER_AUTH', 'NOVA_LICENSE'],
            ' php artisan icons:cache ',
        ));
    }

    public function test_a_package_json_without_a_build_script_gets_no_asset_stage(): void
    {
        $dockerfile = $this->template()->render(['require' => ['php' => '^8.3']], ['devDependencies' => ['prettier' => '^3']]);

        $this->assertStringNotContainsString('node:22-alpine', $dockerfile);
        $this->assertStringNotContainsString('public/build', $dockerfile);
        $this->assertStringStartsWith("FROM dunglas/frankenphp:1-php8.4 AS base\n", $dockerfile);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: list<string>, 2: string}> */
    public static function phpChoices(): array
    {
        return [
            'no php requirement' => [['require' => []], ['8.2', '8.3', '8.4'], '8.4'],
            'no require block' => [[], ['8.2', '8.3'], '8.4'],
            'caret takes the highest' => [['require' => ['php' => '^8.2']], ['8.2', '8.3', '8.4'], '8.4'],
            'tilde pins the minor' => [['require' => ['php' => '~8.3.0']], ['8.2', '8.3', '8.4'], '8.3'],
            'a patch floor still allows that minor' => [['require' => ['php' => '>=8.2.10 <8.3']], ['8.2', '8.3', '8.4'], '8.2'],
            'an upper bound' => [['require' => ['php' => '>=8.1 <8.4']], ['8.2', '8.3', '8.4'], '8.3'],
            'unsorted list' => [['require' => ['php' => '>=8.1']], ['8.4', '8.2', '8.3'], '8.4'],
            'or constraint' => [['require' => ['php' => '^7.4 || ^8.2']], ['8.2', '8.3'], '8.3'],
        ];
    }

    /**
     * @param  array<string, mixed>  $composer
     * @param  list<string>  $versions
     */
    #[DataProvider('phpChoices')]
    public function test_it_picks_the_highest_listed_php_minor_that_composer_allows(array $composer, array $versions, string $expected): void
    {
        $this->assertSame($expected, (new LaravelDockerfile($versions, 'php:{php}'))->phpVersion($composer));
    }

    public function test_it_refuses_a_php_requirement_without_a_base_image(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.json requires PHP ^7.4. Opencloud has base images for PHP 8.2, 8.3, 8.4.');

        $this->template()->phpVersion(['require' => ['php' => '^7.4']]);
    }

    private function template(): LaravelDockerfile
    {
        return new LaravelDockerfile(['8.2', '8.3', '8.4'], 'dunglas/frankenphp:1-php{php}');
    }
}

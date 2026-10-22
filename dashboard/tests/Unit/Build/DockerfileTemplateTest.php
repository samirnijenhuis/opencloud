<?php

namespace Tests\Unit\Build;

use App\Build\DockerfileTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DockerfileTemplateTest extends TestCase
{
    public function test_the_stock_laravel_app_gets_php_8_4_and_the_asset_build(): void
    {
        $composer = ['require' => ['php' => '^8.2', 'laravel/framework' => '^12.0', 'laravel/tinker' => '^2.10.1']];
        $package = ['private' => true, 'type' => 'module', 'scripts' => ['build' => 'vite build', 'dev' => 'vite']];

        $this->assertSame(<<<'DOCKERFILE'
            FROM dunglas/frankenphp:1-php8.4 AS base
            RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_mysql pdo_pgsql redis zip
            COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
            WORKDIR /app

            FROM base AS vendor
            COPY . .
            RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

            FROM node:22-alpine AS assets
            WORKDIR /app
            COPY . .
            COPY --from=vendor /app/vendor ./vendor
            RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi && npm run build

            FROM base
            COPY . .
            COPY --from=vendor /app/vendor ./vendor
            COPY --from=assets /app/public/build ./public/build
            RUN composer dump-autoload --optimize --no-dev && php artisan package:discover --ansi
            ENV SERVER_NAME=:8080
            EXPOSE 8080

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
            WORKDIR /app

            FROM base AS vendor
            COPY . .
            RUN --mount=type=secret,id=COMPOSER_AUTH,env=COMPOSER_AUTH --mount=type=secret,id=NOVA_LICENSE,env=NOVA_LICENSE composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

            FROM base
            COPY . .
            COPY --from=vendor /app/vendor ./vendor
            RUN composer dump-autoload --optimize --no-dev && php artisan package:discover --ansi
            RUN php artisan icons:cache
            ENV SERVER_NAME=:8080
            EXPOSE 8080

            DOCKERFILE, (new DockerfileTemplate(['8.2', '8.3', '8.4'], 'ghcr.io/acme/php:{php}-frankenphp'))->render(
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
        $this->assertSame($expected, (new DockerfileTemplate($versions, 'php:{php}'))->phpVersion($composer));
    }

    public function test_it_refuses_a_php_requirement_without_a_base_image(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composer.json requires PHP ^7.4. Opencloud has base images for PHP 8.2, 8.3, 8.4.');

        $this->template()->phpVersion(['require' => ['php' => '^7.4']]);
    }

    private function template(): DockerfileTemplate
    {
        return new DockerfileTemplate(['8.2', '8.3', '8.4'], 'dunglas/frankenphp:1-php{php}');
    }
}

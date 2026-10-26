<?php

namespace Tests\Unit\Build;

use App\Build\NodeDockerfile;
use App\Build\NodeProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NodeDockerfileTest extends TestCase
{
    public function test_an_npm_app_gets_the_node_of_its_engines_the_build_script_the_build_command_and_the_start_script(): void
    {
        $package = ['engines' => ['node' => '^20.11'], 'scripts' => ['build' => 'tsc', 'start' => 'node dist/server.js']];

        $this->assertSame(<<<'DOCKERFILE'
            FROM node:20-slim
            # Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
            ARG OPENCLOUD_BUILD_ENV
            # A stalled download fails after 60 seconds and npm tries again, instead of waiting 5 minutes.
            # IPv6 goes first, because on some networks IPv4 downloads stall while IPv6 does not. Without IPv6, Node falls back to IPv4 at once.
            # These are build args, so the running app does not get them.
            ARG NPM_FLAGS="--no-audit --no-fund --fetch-timeout=60000 --fetch-retries=5 --fetch-retry-mintimeout=2000"
            ARG NODE_OPTIONS=--dns-result-order=ipv6first
            ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
            WORKDIR /app
            COPY . .
            RUN --mount=type=cache,target=/root/.npm --mount=type=cache,target=/root/.local/share/pnpm/store --mount=type=cache,target=/usr/local/share/.cache/yarn --mount=type=secret,id=NPM_TOKEN,env=NPM_TOKEN npm ci $NPM_FLAGS
            RUN --mount=type=secret,id=NPM_TOKEN,env=NPM_TOKEN npm run build
            RUN --mount=type=secret,id=NPM_TOKEN,env=NPM_TOKEN npx prisma generate
            # Some frameworks, for example Astro and Nuxt, listen on HOST. A probe cannot reach an app that listens on localhost.
            ENV HOST=0.0.0.0
            CMD ["npm", "run", "start"]

            DOCKERFILE, NodeDockerfile::render(new NodeProject($package, 'package-lock.json'), ['NPM_TOKEN'], ' npx prisma generate '));
    }

    /** @return array<string, array{array<string, mixed>, string|null, list<string>}> */
    public static function projects(): array
    {
        $install = 'RUN --mount=type=cache,target=/root/.npm --mount=type=cache,target=/root/.local/share/pnpm/store --mount=type=cache,target=/usr/local/share/.cache/yarn ';

        return [
            'pnpm with packageManager and only a main file' => [
                ['packageManager' => 'pnpm@9.15.4+sha512.abc', 'main' => 'src/index.js'],
                'pnpm-lock.yaml',
                ['FROM node:24-slim', $install.'npm install -g $NPM_FLAGS "pnpm@9.15.4" && pnpm install --frozen-lockfile', 'CMD ["node", "src/index.js"]'],
            ],
            'yarn on the newest node' => [
                ['engines' => ['node' => '>=25'], 'scripts' => ['build' => 'next build', 'start' => 'next start']],
                'yarn.lock',
                ['FROM node:26-slim', $install.'npm install -g $NPM_FLAGS corepack && corepack enable && yarn install', 'RUN yarn run build', 'CMD ["yarn", "run", "start"]'],
            ],
            'no lock file and an engines range that allows the default' => [
                ['engines' => ['node' => '>=18'], 'scripts' => ['start' => 'node server.js']],
                null,
                ['FROM node:24-slim', $install.'npm install $NPM_FLAGS', 'CMD ["npm", "run", "start"]'],
            ],
            'an engines range without the default gets the highest version that it allows' => [
                ['engines' => ['node' => '18 || 20'], 'scripts' => ['start' => 'node server.js']],
                'package-lock.json',
                ['FROM node:20-slim', $install.'npm ci $NPM_FLAGS', 'CMD ["npm", "run", "start"]'],
            ],
            'an engines value that is not semver gets the default' => [
                ['engines' => ['node' => 'lts/*'], 'main' => 'index.js'],
                'package-lock.json',
                ['FROM node:24-slim', $install.'npm ci $NPM_FLAGS', 'CMD ["node", "index.js"]'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  list<string>  $expected  the FROM, RUN, and CMD lines
     */
    #[DataProvider('projects')]
    public function test_the_lock_file_picks_the_package_manager_and_engines_picks_node(array $package, ?string $lockFile, array $expected): void
    {
        $dockerfile = NodeDockerfile::render(new NodeProject($package, $lockFile));

        $this->assertSame($expected, array_values(preg_grep('/^(FROM|RUN|CMD) /', explode("\n", $dockerfile))));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function projectsThatCannotBuild(): array
    {
        return [
            'no start script and no main' => [
                ['scripts' => ['build' => 'tsc']],
                'package.json has no start script and no main, so opencloud does not know how to start the app. Add a start script.',
            ],
            'a node version without an image' => [
                ['engines' => ['node' => '14.x'], 'scripts' => ['start' => 'node server.js']],
                'package.json requires Node 14.x. Opencloud builds with Node 18, 20, 22, 24, 26.',
            ],
        ];
    }

    /** @param  array<string, mixed>  $package */
    #[DataProvider('projectsThatCannotBuild')]
    public function test_a_project_that_opencloud_cannot_build_fails_with_a_plain_message(array $package, string $message): void
    {
        $this->expectExceptionObject(new RuntimeException($message));

        NodeDockerfile::render(new NodeProject($package, 'package-lock.json'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function pnpmProjects(): array
    {
        return [
            'packageManager wins' => [['packageManager' => 'pnpm@9.15.4+sha512.abc', 'engines' => ['pnpm' => '^11.0.0']], '9.15.4'],
            'engines range' => [['engines' => ['pnpm' => '^11.0.0']], '^11.0.0'],
            'nothing set' => [['private' => true], 'latest'],
            'yarn as package manager' => [['packageManager' => 'yarn@4.1.0'], 'latest'],
            'unsafe engines value' => [['engines' => ['pnpm' => '11; rm -rf /']], 'latest'],
        ];
    }

    /** @param  array<string, mixed>  $package */
    #[DataProvider('pnpmProjects')]
    public function test_the_pnpm_version_follows_the_project(array $package, string $expected): void
    {
        $this->assertSame($expected, NodeProject::pnpmVersion($package));
    }
}

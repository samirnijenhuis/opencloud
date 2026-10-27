<?php

namespace Tests\Domains\Builds;

use App\Domains\Builds\NodeProject;
use App\Domains\Builds\StaticDockerfile;
use PHPUnit\Framework\TestCase;

class StaticDockerfileTest extends TestCase
{
    private const NGINX = <<<'DOCKERFILE'
        FROM nginx:1.30-alpine
        COPY <<'NGINX' /etc/nginx/conf.d/default.conf
        server {
            listen 8080;
            root /site;
            # A single-page app routes in the browser, so a path without a file gets index.html.
            location / {
                try_files $uri $uri/ $uri.html /index.html =404;
            }
            # The repository root can hold .git, .env, and other private files.
            location ~ /\.(?!well-known/) {
                return 404;
            }
        }
        NGINX

        DOCKERFILE;

    public function test_a_repository_without_a_build_script_serves_its_root(): void
    {
        $expected = self::NGINX."COPY . /site\nEXPOSE 8080\n";

        $this->assertSame($expected, StaticDockerfile::render(null, ['VITE_API']));
        $this->assertSame($expected, StaticDockerfile::render(new NodeProject(['devDependencies' => ['prettier' => '^3']], 'package-lock.json')));
    }

    public function test_a_build_script_runs_in_its_own_stage_and_nginx_serves_the_output(): void
    {
        $project = new NodeProject(['engines' => ['node' => '22.x'], 'scripts' => ['build' => 'vite build']], 'pnpm-lock.yaml');

        $this->assertSame(<<<'DOCKERFILE'
            FROM node:22-slim AS build
            # Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
            ARG OPENCLOUD_BUILD_ENV
            # A stalled download fails after 60 seconds and npm tries again, instead of waiting 5 minutes.
            # IPv6 goes first, because on some networks IPv4 downloads stall while IPv6 does not. Without IPv6, Node falls back to IPv4 at once.
            ARG NPM_FLAGS="--no-audit --no-fund --fetch-timeout=60000 --fetch-retries=5 --fetch-retry-mintimeout=2000"
            ARG NODE_OPTIONS=--dns-result-order=ipv6first
            ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
            WORKDIR /app
            COPY . .
            RUN --mount=type=cache,target=/root/.npm --mount=type=cache,target=/root/.local/share/pnpm/store --mount=type=cache,target=/usr/local/share/.cache/yarn --mount=type=secret,id=VITE_API,env=VITE_API npm install -g $NPM_FLAGS "pnpm@latest" && pnpm install --frozen-lockfile && pnpm run build
            # The site is the first of these folders that the build makes. A trailing /. follows a symlink, for example the dist of Nuxt.
            RUN for dir in dist build out _site public; do if [ -d "$dir" ]; then mkdir /site && cp -R "$dir/." /site && exit 0; fi; done; echo 'opencloud: the build made no dist, build, out, _site, or public folder. To use another preset, create a new application with the same repository.' >&2; exit 1


            DOCKERFILE.self::NGINX."COPY --from=build /site /site\nEXPOSE 8080\n", StaticDockerfile::render($project, ['VITE_API']));
    }
}

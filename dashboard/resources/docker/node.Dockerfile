FROM node:{{ node }}-slim
# tini runs as PID 1 and passes SIGTERM to the app. As PID 1, Node ignores SIGTERM when the app has no handler for it.
RUN apt-get update && apt-get install -y --no-install-recommends tini && rm -rf /var/lib/apt/lists/*
# Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
ARG OPENCLOUD_BUILD_ENV
# Opencloud sets this to a key of the application, so the cache mounts of one application never reach another.
ARG OPENCLOUD_CACHE_ID
# A stalled download fails after 60 seconds and npm tries again, instead of waiting 5 minutes.
# IPv6 goes first, because on some networks IPv4 downloads stall while IPv6 does not. Without IPv6, Node falls back to IPv4 at once.
# These are build args, so the running app does not get them.
ARG NPM_FLAGS="--no-audit --no-fund --fetch-timeout=60000 --fetch-retries=5 --fetch-retry-mintimeout=2000"
ARG NODE_OPTIONS=--dns-result-order=ipv6first
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
WORKDIR /app
COPY . .
RUN --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/root/.npm,target=/root/.npm --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/root/.local/share/pnpm/store,target=/root/.local/share/pnpm/store --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/usr/local/share/.cache/yarn,target=/usr/local/share/.cache/yarn {{ secret_mounts }}{{ install }}
{{ build_script }}
{{ build_command }}
# Some frameworks, for example Astro and Nuxt, listen on HOST. A probe cannot reach an app that listens on localhost.
ENV HOST=0.0.0.0
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD {{ start }}

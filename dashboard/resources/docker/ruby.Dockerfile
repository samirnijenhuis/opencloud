FROM ruby:{{ ruby }}
# tini runs as PID 1 and passes SIGTERM to the app.
RUN apt-get update && apt-get install -y --no-install-recommends tini && rm -rf /var/lib/apt/lists/*
# Bundler.setup also skips these groups when the app starts, so it does not look for gems that the build left out.
ENV BUNDLE_WITHOUT=development:test RACK_ENV=production RAILS_ENV=production
# Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
ARG OPENCLOUD_BUILD_ENV
# Opencloud sets this to a key of the application, so the cache mounts of one application never reach another.
ARG OPENCLOUD_CACHE_ID
WORKDIR /app
COPY . .
RUN --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/usr/local/bundle/cache,target=/usr/local/bundle/cache {{ secret_mounts }}bundle install
{{ assets }}
{{ build_command }}
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD {{ start }}

FROM python:{{ python }}
# tini runs as PID 1 and passes SIGTERM to the app.
RUN apt-get update && apt-get install -y --no-install-recommends tini && rm -rf /var/lib/apt/lists/*
ENV PIP_DISABLE_PIP_VERSION_CHECK=1 PIP_ROOT_USER_ACTION=ignore
{{ tool }}
# The app gets its own virtual env, so the dependencies of the tool above never mix with those of the app.
RUN python -m venv /opt/venv
ENV VIRTUAL_ENV=/opt/venv PATH=/opt/venv/bin:$PATH UV_PROJECT_ENVIRONMENT=/opt/venv UV_PYTHON_DOWNLOADS=never UV_LINK_MODE=copy
# Opencloud sets this to a key of the build values, so a build with other values never reuses a layer that read a secret.
ARG OPENCLOUD_BUILD_ENV
# Opencloud sets this to a key of the application, so the cache mounts of one application never reach another.
ARG OPENCLOUD_CACHE_ID
WORKDIR /app
COPY . .
RUN --mount=type=cache,id=${OPENCLOUD_CACHE_ID}/root/.cache,target=/root/.cache {{ secret_mounts }}{{ install }}
{{ build_command }}
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD {{ start }}

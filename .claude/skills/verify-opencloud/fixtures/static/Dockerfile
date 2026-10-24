# syntax=docker/dockerfile:1
FROM nginx:1.27-alpine
COPY index.html /usr/share/nginx/html/index.html
# The nginx image fills in ${VAR} from the container env when it starts, so /verify shows the runtime env.
COPY default.conf.template /etc/nginx/templates/default.conf.template
# Opencloud passes a build env value as a BuildKit secret. Writing it into the files proves that the build saw it.
RUN --mount=type=secret,id=VERIFY_BUILD \
    build=$(cat /run/secrets/VERIFY_BUILD 2>/dev/null || echo none) \
    && sed -i "s/__BUILD__/${build}/g" /usr/share/nginx/html/index.html /etc/nginx/templates/default.conf.template
EXPOSE 8080

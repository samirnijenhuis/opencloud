# syntax=docker/dockerfile:1
FROM php:8.4-apache
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql pdo_pgsql redis
RUN sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf
COPY public/ /var/www/html/
COPY lib.php deploy.php /var/www/
# A SQLite database lives in this folder. Apache runs PHP as www-data, so www-data must write to it.
RUN mkdir -p /var/www/data && chown www-data:www-data /var/www/data
# Opencloud passes a build env value as a BuildKit secret. The file proves that the build saw it.
RUN --mount=type=secret,id=VERIFY_BUILD sh -c 'cat /run/secrets/VERIFY_BUILD > /var/www/build-marker 2>/dev/null || echo none > /var/www/build-marker'
EXPOSE 8080

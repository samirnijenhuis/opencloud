#!/bin/sh
set -eu

wait_for_migrations() {
    # A bare --pending exits 0 even when migrations are pending; --pending=1 makes it exit 1.
    until [ -f "$DB_DATABASE" ] && php artisan migrate:status --pending=1 >/dev/null 2>&1; do
        echo "Waiting for the web container to migrate $DB_DATABASE"
        sleep 2
    done
}

has_command() {
    php artisan list --raw | awk -v name="$1" '$1 == name { found = 1 } END { exit !found }'
}

case "${1:-web}" in
web)
    mkdir -p "$(dirname "$DB_DATABASE")"
    [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
    php artisan migrate --force
    if has_command opencloud:bootstrap; then
        php artisan opencloud:bootstrap
    fi
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
    ;;
worker)
    wait_for_migrations
    exec php artisan queue:work --queue=default --sleep=1 --tries=1 --timeout=600
    ;;
builder)
    wait_for_migrations
    exec php artisan queue:work --queue=builds --sleep=1 --tries=1 --timeout=3600
    ;;
scheduler)
    wait_for_migrations
    exec php artisan schedule:work
    ;;
*)
    exec "$@"
    ;;
esac

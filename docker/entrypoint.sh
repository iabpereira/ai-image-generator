#!/bin/sh
set -e

# Only the web server container prepares the app; one-off commands
# (e.g. `docker compose run --rm web php artisan ...`) skip this.
if [ "$1" = "frankenphp" ]; then
    mkdir -p storage/app/public

    if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
        DB_FILE="${DB_DATABASE:-/app/database/data/database.sqlite}"
        mkdir -p "$(dirname "$DB_FILE")"
        [ -f "$DB_FILE" ] || touch "$DB_FILE"
    fi

    echo "Running migrations..."
    php artisan migrate --force

    echo "Caching config, routes, views and events..."
    php artisan optimize

    php artisan storage:link --force >/dev/null 2>&1 || true
fi

exec "$@"

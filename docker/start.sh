#!/bin/sh
# Runs the queue worker and the scheduler next to the web server, in one
# container (the host is too small for one container per process). Each
# background process restarts if it exits; if the web server exits, the
# container stops and Docker's restart policy brings everything back.
set -e

keep_running() {
    while true; do
        "$@" || true
        echo "'$*' exited; restarting in 1s" >&2
        sleep 1
    done
}

keep_running php artisan queue:work --sleep=1 --max-time=3600 --memory=96 &
keep_running php artisan schedule:work &

exec frankenphp php-server --root /app/public --listen :80

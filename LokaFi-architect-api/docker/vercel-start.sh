#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required."
    exit 1
fi

export APP_CONFIG_CACHE="${APP_CONFIG_CACHE:-/tmp/laravel/cache/config.php}"
export APP_EVENTS_CACHE="${APP_EVENTS_CACHE:-/tmp/laravel/cache/events.php}"
export APP_PACKAGES_CACHE="${APP_PACKAGES_CACHE:-/tmp/laravel/cache/packages.php}"
export APP_ROUTES_CACHE="${APP_ROUTES_CACHE:-/tmp/laravel/cache/routes.php}"
export APP_SERVICES_CACHE="${APP_SERVICES_CACHE:-/tmp/laravel/cache/services.php}"
export VIEW_COMPILED_PATH="${VIEW_COMPILED_PATH:-/tmp/laravel/views}"

mkdir -p /tmp/laravel/cache /tmp/laravel/views

php artisan config:clear
php artisan config:cache

php artisan serve --host=0.0.0.0 --port="${PORT:-80}" &
server_pid=$!

cleanup() {
    kill "$server_pid" 2>/dev/null || true
}

trap cleanup INT TERM EXIT

wait "$server_pid"

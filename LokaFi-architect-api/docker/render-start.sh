#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required."
    exit 1
fi

case "$APP_KEY" in
    base64:*) ;;
    *)
        APP_KEY="base64:$(php -r 'echo base64_encode(hash("sha256", getenv("APP_KEY"), true));')"
        export APP_KEY
        ;;
esac

mkdir -p \
    bootstrap/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

php artisan config:clear

attempt=1
until php artisan migrate --force; do
    if [ "$attempt" -ge 10 ]; then
        echo "Database migration failed after $attempt attempts."
        exit 1
    fi

    echo "Database is not ready; retrying migration ($attempt/10)."
    attempt=$((attempt + 1))
    sleep 3
done

if [ "${LOKAFI_SEED_DEMO:-false}" = "true" ]; then
    php artisan db:seed --class=LokaFiDemoSeeder --force
fi

php artisan config:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"

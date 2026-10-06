#!/bin/bash
set -eu

cd /var/www/html

role="${1:-web}"

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is empty. Set it in .env before starting Docker." >&2
    exit 1
fi

require_production_database() {
    if [ "${VERCEL_ENV:-}" != "production" ]; then
        return 0
    fi

    if [ "${DB_CONNECTION:-}" != "pgsql" ]; then
        echo "Production requires DB_CONNECTION=pgsql; refusing to start with '${DB_CONNECTION:-unset}'." >&2
        exit 1
    fi

    if [ -z "${DB_URL:-}" ] && [ -z "${DATABASE_URL:-}" ]; then
        echo "Production requires DB_URL or DATABASE_URL for PostgreSQL; neither is set." >&2
        exit 1
    fi
}

wait_for_database() {
    if [ "${DB_CONNECTION:-sqlite}" = "pgsql" ]; then
        echo "Waiting for PostgreSQL..."
        php artisan adhkar:db-status --wait=60 --no-interaction
        return
    fi

    if [ "${DB_CONNECTION:-sqlite}" != "mysql" ]; then
        return 0
    fi

    echo "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."

    i=0
    while [ "$i" -lt 30 ]; do
        if php -r '
            $host = getenv("DB_HOST") ?: "mysql";
            $port = getenv("DB_PORT") ?: "3306";
            $user = getenv("DB_USERNAME") ?: "azkar";
            $pass = getenv("DB_PASSWORD") ?: "";
            $db = getenv("DB_DATABASE") ?: "azkar";
            try {
                new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass);
                exit(0);
            } catch (Throwable $e) {
                exit(1);
            }
        '; then
            return 0
        fi

        i=$((i + 1))
        sleep 2
    done

    echo "MySQL did not become ready." >&2
    exit 1
}

if [ -n "${VERCEL:-}" ]; then
    # One container serves webhooks and has no always-on worker.
    # cron-job.org calls /internal/cron every minute, which runs the reminder tick in-process.
    export QUEUE_CONNECTION=sync
    export LOG_CHANNEL=stderr
fi

prepare_app() {
    require_production_database

    if [ "${DB_CONNECTION:-sqlite}" = "mysql" ]; then
        wait_for_database
    fi

    # Database wait, migrations, content sync (only when changed) and the config cache, in one PHP process.
    if [ "${APP_ENV:-local}" = "production" ]; then
        php artisan adhkar:prepare --cache-config --no-interaction
    else
        php artisan adhkar:prepare --no-interaction
    fi

    # migrate creates the sqlite file as root; php-fpm runs as www-data.
    chown -R www-data:www-data storage bootstrap/cache database
    chmod -R ug+rwx storage bootstrap/cache database
}

case "$role" in
    web)
        prepare_app
        php-fpm --nodaemonize &
        fpm_pid=$!

        # nginx answers 502 until php-fpm listens, and a cold start's first request arrives as soon as port 80 opens.
        i=0
        until php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'; do
            if ! kill -0 "$fpm_pid" 2>/dev/null || [ "$i" -ge 100 ]; then
                echo "php-fpm did not start listening." >&2
                exit 1
            fi
            i=$((i + 1))
            sleep 0.1
        done

        nginx -g "daemon off;" &
        nginx_pid=$!

        stop_children() {
            kill -"$1" "$nginx_pid" "$fpm_pid" 2>/dev/null || true
            wait "$nginx_pid" "$fpm_pid" 2>/dev/null || true
        }

        # The php-fpm base image sets STOPSIGNAL SIGQUIT: both servers treat QUIT as a graceful stop.
        trap 'stop_children QUIT; exit 0' QUIT
        trap 'stop_children TERM; exit 0' TERM INT

        # Blocks without polling until either server exits; a trapped signal interrupts it.
        wait -n "$fpm_pid" "$nginx_pid" || true

        if kill -0 "$fpm_pid" 2>/dev/null; then
            echo "nginx exited." >&2
        else
            echo "php-fpm exited." >&2
        fi
        stop_children TERM
        exit 1
        ;;
    queue)
        wait_for_database
        exec php artisan queue:work --sleep=1 --tries=3 --backoff=5,30,120 --max-time=3600 --no-interaction
        ;;
    scheduler)
        wait_for_database
        while true; do
            php artisan schedule:run --no-interaction
            sleep $((60 - $(date +%S)))
        done
        ;;
    *)
        exec "$@"
        ;;
esac

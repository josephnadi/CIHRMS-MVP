#!/bin/sh
set -e

ROLE="${CONTAINER_ROLE:-web}"
echo "[entrypoint] starting role=${ROLE}"

# Web is the single migration owner. Wait for Postgres, then migrate.
if [ "$ROLE" = "web" ]; then
    echo "[entrypoint] waiting for database..."
    tries=0
    until php artisan migrate:status >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "[entrypoint] database not reachable after 30 tries; continuing anyway"
            break
        fi
        sleep 2
    done
    echo "[entrypoint] running migrations..."
    php artisan migrate --force
fi

# All roles: (re)build framework caches against the injected env. Idempotent.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link 2>/dev/null || true

echo "[entrypoint] exec: $*"
exec "$@"

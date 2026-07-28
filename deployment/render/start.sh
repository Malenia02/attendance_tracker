#!/usr/bin/env sh
set -eu
umask 027

APP_PORT="${PORT:-10000}"

missing_variables=""

for variable in \
    APP_KEY \
    APP_URL \
    FRONTEND_URL \
    DB_CONNECTION \
    DB_HOST \
    DB_DATABASE \
    DB_USERNAME \
    DB_PASSWORD \
    DTR_SIGNING_KEY \
    QR_SIGNING_KEY
do
    if [ -z "$(printenv "${variable}" 2>/dev/null || true)" ]; then
        missing_variables="${missing_variables} ${variable}"
    fi
done

if [ -n "${missing_variables}" ]; then
    echo "ERROR: Required production environment variables are missing:${missing_variables}" >&2
    echo "Configure them in the hosting provider before starting this image. Values are intentionally not printed." >&2
    exit 1
fi

if [ -n "${MYSQL_ATTR_SSL_CA:-}" ]; then
    if [ -z "${MYSQL_SSL_CA_BASE64:-}" ]; then
        echo "ERROR: MYSQL_SSL_CA_BASE64 is required when MYSQL_ATTR_SSL_CA is configured." >&2
        exit 1
    fi

    if ! printf '%s' "${MYSQL_SSL_CA_BASE64}" | base64 --decode > "${MYSQL_ATTR_SSL_CA}"; then
        echo "ERROR: MYSQL_SSL_CA_BASE64 is not a valid base64-encoded certificate." >&2
        exit 1
    fi

    chown root:www-data "${MYSQL_ATTR_SSL_CA}"
    chmod 0640 "${MYSQL_ATTR_SSL_CA}"
fi

case "${DB_CONNECTION}" in
    mysql|mariadb)
        ;;
    *)
        echo "ERROR: DB_CONNECTION must be mysql or mariadb for this production image; received '${DB_CONNECTION}'." >&2
        exit 1
        ;;
esac

sed -ri "s/^Listen [0-9]+$/Listen ${APP_PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${APP_PORT}>/" /etc/apache2/sites-available/000-default.conf

mkdir -p \
    storage/app/private/personnel-photos \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown www-data:www-data \
    storage \
    storage/app \
    storage/app/private \
    storage/framework \
    storage/framework/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

php artisan config:clear

if [ "${RUN_MIGRATIONS_ON_START:-false}" = "true" ]; then
    echo "Running database migrations during startup (test/free deployment mode)."
    php artisan migrate --force
fi

if [ "${EPHEMERAL_UPLOADS:-false}" = "true" ]; then
    echo "WARNING: Personnel photos use ephemeral storage and can be lost whenever the service restarts or redeploys." >&2
fi

php artisan production:check
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan production:check

# Artisan runs as root during container startup. With the restrictive umask
# above, newly generated cache files would otherwise be unreadable by Apache's
# www-data worker and Laravel would fail before its configuration is loaded.
chown -R www-data:www-data \
    bootstrap/cache \
    storage/framework/cache \
    storage/framework/views

exec apache2-foreground

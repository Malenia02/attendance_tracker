# Production deployment

This application deploys as one same-origin Laravel application. React is
compiled into `backend/public/app`; production does not run Vite or Node.js.

A split Render/Vercel deployment is also supported. It uses an external React
frontend with sibling-subdomain Sanctum authentication. See
[RENDER-VERCEL.md](RENDER-VERCEL.md).

## Supported hosting

- Apache with PHP-FPM or mod_php
- Nginx with PHP-FPM
- Managed Laravel hosting
- A VPS or container platform
- Shared PHP hosting that supports PHP 8.2+, MySQL/MariaDB, URL rewriting, and
  the required PHP extensions

For real personnel data, use a host with HTTPS, backups, reliable API requests,
server logs, and a documented incident-recovery process. Free shared hosting is
appropriate only for demonstrations with non-sensitive data.

## Server requirements

- PHP 8.2 or newer
- MySQL 8 or MariaDB 10.4+
- PHP extensions: `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`,
  `openssl`, `pdo_mysql`, `session`, `tokenizer`, `xml`, and `zip`
- Composer 2
- Node.js 20.19+ or 22.12+ on the build machine
- HTTPS for secure session cookies, phone camera access, and browser GPS

The web document root must be `backend/public`. Example Apache and Nginx
configurations are in `deployment/`.

## Build the release

Run these commands from a trusted build machine:

```bash
cd backend
composer install
php artisan test

cd ../frontend
npm ci
npm run lint
npm run build:laravel

cd ../backend
composer install --no-dev --prefer-dist --optimize-autoloader
```

Do not upload `frontend/node_modules`, the development Vite server, test
databases, `.env`, logs, or local database backups.

## Configure the environment

Copy `backend/.env.production.example` to `backend/.env` on the server and
replace every example domain, database credential, and blank secret.

Generate independent secrets:

```bash
php artisan key:generate
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Use the two random hexadecimal values for `DTR_SIGNING_KEY` and
`QR_SIGNING_KEY`. They must be different from each other and from `APP_KEY`.
Back them up securely. Losing a signing key prevents verification of existing
signed QR/DTR records. Never commit `.env`.

For direct hosting, retain:

```dotenv
TRUSTED_PROXIES=127.0.0.1,::1
```

Only list real reverse-proxy addresses. Use `TRUSTED_PROXIES=*` solely when a
trusted edge proxy always removes and replaces forwarding headers.

## Database and first administrator

Back up the database before every deployment. Then run:

```bash
cd backend
php artisan migrate --force
php artisan system:create-admin
```

The administrator command refuses to run after an active administrator exists
and reads the password interactively so it is not stored in shell history.
Never run `migrate:fresh` against production.

For a host without SSH or Artisan, import
`backend/database/schema/mysql-schema.sql` through phpMyAdmin for a new
database. Existing databases must receive every later migration through a
controlled SQL change; do not re-import the baseline over live data.

## Permissions

The web-server account needs write access only to:

```text
backend/storage/
backend/bootstrap/cache/
```

Application code, `.env`, the DTR template, and compiled React assets should
not be writable by the web-server account after deployment. Personnel photos
are private under `storage/app/private` and must not be exposed as a public
directory.

## Validate and optimize

After installing the final `.env`, build, and database:

```bash
php artisan optimize:clear
php artisan production:check
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan production:check
```

`production:check` validates HTTPS, debug mode, signing keys, secure cookies,
PHP extensions, writable directories, the React build, the DTR template, the
database connection, and critical tables.

Use these probes with your monitoring service:

- `/up` — process liveness
- `/health/ready` — database, storage, DTR template, and React readiness

Run dependency audits for every release:

```bash
cd backend
composer audit --locked --no-dev

cd ../frontend
npm audit --omit=dev
```

As of 2026-07-27, the application uses `react-router-dom` 7.18.1. The npm
advisory database reports GHSA-qwww-vcr4-c8h2 for React Router's optional
React Server Components mode. This application is a client-only Vite SPA and
does not enable that mode. No fixed release is currently published. Keep the
dependency current and replace this note once npm publishes a patched version.

## Deployment sequence

```bash
php artisan down --retry=30
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

If queues are introduced later, configure Supervisor/systemd and restart the
workers with `php artisan queue:restart`. The default portable production
configuration uses `QUEUE_CONNECTION=sync`.

## Shared hosting

Prefer a control panel that lets the domain document root point to
`backend/public`. If it cannot, use
`deployment/shared-hosting-root.htaccess.example` only after confirming that
private Laravel directories and `.env` return HTTP 403 from the public
internet. This workaround is less safe than a proper document root.

Build Composer dependencies and React locally, upload the complete backend
including `vendor` and `public/app`, and import the schema through phpMyAdmin
when shell access is unavailable. Use `SESSION_DRIVER=file` and
`CACHE_STORE=file` only if the host does not support the corresponding database
tables and `storage/framework` is private and writable.

InfinityFree also imposes API, command-line, cron, symlink, file-size, and
resource limitations. Use it only for a demonstration with fake records.

## Backups and recovery

Back up and encrypt:

- The MySQL/MariaDB database
- `backend/storage/app/private/personnel-photos`
- The production `.env` secrets in a separate secret manager
- The exact deployed release identifier

Test restoration regularly. Keep audit logs according to the applicable DILG
retention policy. A database-only backup is incomplete because it does not
contain personnel photos or application signing secrets.

## Rollback

Keep the previous code release and a pre-deployment database backup. Roll back
application code first when a migration is backward-compatible. Do not run
`migrate:rollback` automatically after a failed deployment; a down migration
may destroy production data. Restore the verified backup when a schema rollback
is required.

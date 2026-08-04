# Render backend and Vercel frontend

This deployment keeps the Laravel API on Render and publishes the Vite React
application on Vercel.

## Free test deployment

For evaluation, use the default free-test Blueprint:

```text
Blueprint Name: dilg-attendance-free-test
Branch:         main
Blueprint Path: render.yaml
```

The root `render.yaml` and the explicit `render.free.yaml` use the same free
test configuration. The paid, always-on configuration is retained in
`render.production.yaml` for the later production upgrade. The free service:

- uses Render's Free web-service plan;
- runs migrations during startup because pre-deploy commands are paid-only;
- has no persistent disk, so uploaded personnel photos can disappear after
  any restart, idle spin-down, or redeploy;
- is for test data only and must never contain real personnel records.

The application still requires MySQL. Render's managed database is PostgreSQL,
so create a free Aiven for MySQL service and enter its connection values in
the Blueprint form:

```dotenv
DB_HOST=the Aiven host
DB_PORT=the Aiven port
DB_DATABASE=the Aiven database
DB_USERNAME=the Aiven user
DB_PASSWORD=the Aiven password
```

Download Aiven's CA certificate and convert it to base64 in PowerShell:

```powershell
[Convert]::ToBase64String(
    [IO.File]::ReadAllBytes("C:\path\to\ca.pem")
)
```

Paste the result into `MYSQL_SSL_CA_BASE64`. The startup script reconstructs
the certificate at `/tmp/dilg-mysql-ca.pem` with restricted permissions.
Never commit the certificate value or database password.

Enter the exact Vercel deployment URL in Render:

```dotenv
FRONTEND_URL=https://your-project.vercel.app
SANCTUM_STATEFUL_DOMAINS=your-project.vercel.app
CORS_ALLOWED_ORIGINS=https://your-project.vercel.app
TRUSTED_HOSTS=^(your-render-service\.onrender\.com|your-project\.vercel\.app)$
```

Do not enter a value for `SESSION_DOMAIN` in proxy mode. In Vercel, set:

```dotenv
VITE_API_URL=/api
```

The included Vercel rewrites proxy `/api/*` and `/sanctum/*` to the free
Render test service. This keeps encrypted session cookies on the Vercel
hostname and avoids unreliable third-party cookies between `vercel.app` and
`onrender.com`. Both hostnames must be included in `TRUSTED_HOSTS` because
Laravel validates the original Vercel hostname forwarded by the proxy.

## Required domain layout

Use two subdomains of one domain:

```text
attendance.example.gov.ph      Vercel frontend
api.attendance.example.gov.ph  Render backend
```

Replace `example.gov.ph` everywhere in this guide with the domain you control.
Do not use `project.vercel.app` with `service.onrender.com` for production
login. Those hosts do not share a parent domain, so the current Sanctum
session-cookie authentication cannot work correctly between them.

Vercel preview URLs also do not share the production parent domain. Do not
connect previews to the production database. Use a dedicated preview
subdomain and isolated database if authenticated preview deployments are
needed.

## Architecture

```text
Browser
  -> https://attendance.example.gov.ph       React assets from Vercel
  -> https://api.attendance.example.gov.ph   Laravel API on Render
       -> managed MySQL/MariaDB
       -> Render persistent disk for private personnel photos
```

The API continues to use encrypted, HttpOnly Laravel session cookies. The
readable XSRF cookie is shared only with the sibling frontend subdomain.
Frontend JavaScript never stores a bearer token.

## 1. Database

Create a production MySQL 8 or MariaDB database with:

- TLS connections
- automated backups
- a region close to Render Singapore
- an application user restricted to this database
- sufficient connection and storage limits

Render's managed relational database is PostgreSQL. This project currently
uses a MySQL schema baseline and MySQL-specific activity-log queries, so do not
set `DB_CONNECTION=pgsql`.

For a new empty database, the Render pre-deploy command runs
`php artisan migrate --force`. The container includes the MySQL client needed
for Laravel to load `backend/database/schema/mysql-schema.sql`.

To move existing local records, export the local database and import it into
the production database before the first Render deployment. Never run
`migrate:fresh` on production.

## 2. Choose the final domains

Add the frontend domain to the Vercel project and the API domain to the Render
service. Follow the DNS values shown by each provider; do not guess the CNAME
or A records.

Before deploying, replace both occurrences of
`https://api.attendance.example.gov.ph` in `frontend/vercel.json`. They are in
the frontend Content Security Policy.

## 3. Deploy Laravel to Render

Push this repository to a private Git provider. In Render, create a Blueprint
from `render.production.yaml`.

The Blueprint creates:

- a paid Starter Docker web service in Singapore
- a health check at `/health/ready`
- a 1 GB persistent disk mounted at
  `/var/www/html/storage/app/private`
- a pre-deploy database migration
- automatic deployment only after repository CI checks pass

During Blueprint creation, enter these secret or deployment-specific values:

```dotenv
APP_KEY=base64:GENERATE_A_REAL_LARAVEL_KEY
APP_URL=https://api.attendance.example.gov.ph
FRONTEND_URL=https://attendance.example.gov.ph

DB_HOST=your-production-mysql-host
DB_DATABASE=dilg_attendance
DB_USERNAME=dilg_app
DB_PASSWORD=your-random-database-password

SESSION_DOMAIN=.attendance.example.gov.ph
SANCTUM_STATEFUL_DOMAINS=attendance.example.gov.ph
CORS_ALLOWED_ORIGINS=https://attendance.example.gov.ph
TRUSTED_HOSTS=^(dilg-attendance-api\.onrender\.com|api\.attendance\.example\.gov\.ph|attendance\.example\.gov\.ph)$
```

Generate `APP_KEY` locally without changing the local `.env`:

```bash
cd backend
php artisan key:generate --show
```

Values must not have a trailing slash. `CORS_ALLOWED_ORIGINS` includes the
scheme, while `SANCTUM_STATEFUL_DOMAINS` contains only the hostname.

The Blueprint generates independent DTR and QR signing keys. Preserve all
three signing/encryption keys in a password manager. Changing them later
invalidates sessions and can prevent verification of previously signed data.

`TRUSTED_HOSTS` must allow only the exact Render API hostname, production API
domain, and frontend proxy hostname. If the service or domains change, update
the anchored regular expression and redeploy.

After the first successful deployment, open the paid service shell and run:

```bash
php artisan system:create-admin
php artisan production:check
```

## 4. Deploy React to Vercel

Import the same repository into Vercel and use:

```text
Root Directory:    frontend
Framework Preset:  Vite
Build Command:     npm run build
Output Directory:  dist
```

Add this Production environment variable:

```dotenv
VITE_API_URL=https://api.attendance.example.gov.ph/api
```

`VITE_API_URL` is public build configuration, not a secret. Redeploy after
changing it. The included `frontend/vercel.json` handles React Router deep
links, immutable asset caching, and browser security headers.

## 5. Verify the deployment

Check the backend first:

```text
https://api.attendance.example.gov.ph/up
https://api.attendance.example.gov.ph/health/ready
```

Then use a private browser window and verify:

1. Login sets `XSRF-TOKEN` and `dilg_attendance_session` for the shared parent
   domain.
2. Refreshing `/dashboard` stays logged in.
3. Personnel photos load from the API domain.
4. Photo upload survives a Render redeploy.
5. QR scanning requests camera access and records a scan.
6. GPS validation uses the expected office location and radius.
7. DTR generation downloads successfully.
8. A wrong Origin does not receive an
   `Access-Control-Allow-Origin` response header.

## Operations and limitations

- Do not use a free Render web service for live attendance. Cold starts can
  exceed the frontend session-check timeout, and free services do not support
  persistent disks.
- A Render persistent disk limits this service to one instance and introduces
  brief deployment downtime. Move personnel photos to private object storage
  before scaling horizontally.
- The database and personnel-photo disk both need tested backups. A database
  backup alone is incomplete.
- Render application logs go to stderr and are visible in the Render dashboard.
- Keep Vercel and Render production access limited to authorized maintainers.
- Never add `.env`, database exports, signing keys, or personnel photos to Git.
- Configure and test the encrypted backup workflow in
  [BACKUP-RECOVERY.md](BACKUP-RECOVERY.md) before live attendance begins.

# DILG GIP Attendance Tracker

A Laravel and React attendance monitoring system for DILG Government
Internship Program personnel. It manages personnel records, office-based
attendance, QR scanning, holidays and optional working days, DTR review, and
auditable certification.

The application uses the `Asia/Manila` timezone. Its normal schedule is Monday
through Thursday at ten hours per day. Friday, Saturday, and Sunday are rest
days unless an authorized office-specific working day is created.

## Main features

- Modern role-aware dashboard
- Secured, role-aware Notification Center with unread tracking, workflow links,
  pagination, and automatic retention
- Personnel directory with private photo/signature uploads and optional automatic employee numbers
- Departments and offices with GPS coordinates and allowed radius
- Morning and afternoon time in/time out
- Automatic late, undertime, half-day, absence, holiday, and rest-day handling
- Office-specific optional working days
- Signed personnel QR cards with printable premium layouts
- GPS accuracy, location freshness, and office-radius verification
- Holiday and working-day calendar
- Leave and official-business requests with private attachments, scoped review,
  immutable status history, and automatic attendance/DTR integration
- DTR preparation, submission, return, review, certification, and generation
- Three populated DTR copies per personnel record
- Attendance corrections and verification from the DTR workflow
- System user and role management
- Attendance, authentication, QR, DTR, and administrative activity logs

## Technology

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.2+, Laravel 12, Laravel Sanctum |
| Frontend | React 19, Vite 8, React Router |
| Database | MySQL or MariaDB |
| QR | `qrcode` and `html5-qrcode` |
| Documents | Word `.docx` DTR template |
| Styling | Feature-based CSS under `frontend/src/styles` |

## User roles

| Role | Typical access |
| --- | --- |
| Administrator | Full system administration, QR kiosk operation, logs, user management, and DTR generation |
| HR | QR kiosk operation, personnel, departments, attendance correction, DTR review, and certification |
| Supervisor | Own attendance/card plus department-scoped verification, leave/official-business review, and DTR review |
| Encoder | Own attendance/card plus department-scoped attendance and DTR processing |
| Personnel | Own attendance, QR card, leave/official-business requests, and DTR information |

Backend authorization is the security boundary. Hiding a menu item in React
does not grant or remove API access.

## Project structure

```text
dilg-attendance-system/
├── backend/                    Laravel API and document generation
│   ├── app/
│   ├── database/
│   │   ├── migrations/
│   │   └── schema/mysql-schema.sql
│   ├── resources/templates/DTR-format-1.docx
│   └── tests/
├── frontend/                   React and Vite interface
│   └── src/
│       ├── components/
│       ├── pages/
│       └── styles/
└── README.md
```

See [frontend/src/styles/README.md](frontend/src/styles/README.md) for the CSS
file map.

See [docs/API-ROUTES.md](docs/API-ROUTES.md) for the API route map, route
purpose, role access, and common debugging status codes.

## Requirements

- XAMPP or an equivalent PHP/MySQL environment
- PHP 8.2 or newer with the extensions required by Laravel
- Composer 2
- Node.js 20.19+ or 22.12+ and npm
- MySQL or MariaDB
- A modern Chromium-based browser is recommended for QR scanning and printing

## Local installation

### 1. Prepare the database

Start Apache and MySQL from XAMPP. Create an empty MySQL database, for example:

```text
dilg_attendance_tracker
```

The repository includes a schema-only MySQL baseline. It contains table
definitions and migration history, but no personnel, attendance, or password
data.

### 2. Configure the backend

From PowerShell:

```powershell
cd C:\xampp\htdocs\dilg-attendance-system\backend
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Update `backend/.env`:

```dotenv
APP_NAME="DILG GIP Attendance Tracker"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Manila

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dilg_attendance_tracker
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
DTR_SIGNING_KEY=replace_with_a_random_key
QR_SIGNING_KEY=replace_with_a_different_random_key
MAXIMUM_LOCATION_ACCURACY_METERS=100
```

Generate two different signing keys:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Apply the schema and migrations:

```powershell
php artisan migrate
php artisan optimize:clear
```

Do not run `migrate:fresh` against a database containing real attendance data.

### 3. Create the first administrator

No production password is hard-coded in this repository. For a new empty
database, create the first administrator through Tinker:

```powershell
php artisan tinker
```

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

User::updateOrCreate(
    ['username' => 'admin'],
    [
        'personnel_id' => null,
        'password_hash' => Hash::make('Replace-With-A-Strong-Password!2026'),
        'user_role' => 'Administrator',
        'status' => 'Active',
    ]
);
```

Exit Tinker, sign in, and replace the example password immediately. Create the
remaining accounts from **System Users**.

### 4. Install and run the frontend

Open a second PowerShell window:

```powershell
cd C:\xampp\htdocs\dilg-attendance-system\frontend
npm install
npm run dev
```

Run the backend in the first window:

```powershell
cd C:\xampp\htdocs\dilg-attendance-system\backend
php artisan serve --host=127.0.0.1 --port=8000
```

Open [http://localhost:5173](http://localhost:5173).

## Opening the system on another device

The computer and phone must be connected to the same Wi-Fi network or to the
computer's mobile hotspot.

1. Run `ipconfig` and find the computer's IPv4 address.
2. Add the frontend address to `SANCTUM_STATEFUL_DOMAINS`, for example:

   ```dotenv
   APP_URL=http://192.168.1.25:8000
   SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173,192.168.1.25:5173
   ```

3. Clear cached configuration:

   ```powershell
   cd backend
   php artisan optimize:clear
   ```

4. Start both servers on the network:

   ```powershell
   cd backend
   php artisan serve --host=0.0.0.0 --port=8000
   ```

   ```powershell
   cd frontend
   npm run dev -- --host 0.0.0.0
   ```

5. Open `http://YOUR_COMPUTER_IP:5173` on the phone.

Windows Firewall rules must be created from PowerShell running as
Administrator. Camera scanning and precise browser geolocation on a phone
normally require HTTPS because a LAN IP over plain HTTP is not a secure browser
context. Manual scanning remains available during local development.

## Attendance and DTR rules

- Monday–Thursday are normal ten-hour working days.
- Friday–Sunday are rest days by default.
- An Administrator or HR user can add an office-specific working day when a
  director requires duty on a rest day.
- Holidays can apply globally or to a specific office.
- The migration baseline includes the official nationwide 2026 calendar from
  Proclamation No. 1006, s. 2025, plus Proclamation Nos. 1189 and 1264 for
  Eid'l Fitr and Eid'l Adha. Newly proclaimed local holidays and future annual
  calendars must still be reviewed and entered by an Administrator or HR user.
- Attendance after the configured morning threshold is recorded and flagged as
  late instead of being blocked.
- Missing morning or afternoon sessions can produce a half-day result.
- Attendance corrections are logged with the old and new values, reason,
  operator, IP address, and timestamp.
- Personnel without an assigned department are excluded from DTR monitoring
  and cannot start, certify, generate, or reopen a DTR workflow.
- Administrator is the full-access override role and may verify their own
  attendance, certify their own DTR, and review their own DTR reopening request.
  The action is still validated and written to the audit trail.
- Administrator and HR accounts may browse and regenerate personnel QR cards.
  Supervisor, Encoder, and Personnel accounts can view and print only the card
  linked to their own system account.
- Personnel cards have independent valid-from and valid-until dates, defaulting
  to one year for new credentials. Card validity must remain inside the
  employment period, and QR attendance rejects scans outside either period.
- DTR records move through Draft, Submitted, Returned, and Certified states.
- GIP personnel use semi-monthly DTR periods (1stâ€“15th and 16thâ€“month end).
  Other personnel types use a full-month period. A full-month GIP DTR is an
  exception that requires Administrator or HR authorization, a written reason,
  and an immutable activity-log entry.
- A DTR cannot be submitted before its cutoff. It becomes overdue after the
  configured grace period. Due and overdue records appear in the Action Center,
  while personnel receive cutoff reminders through the notification panel.
- Returned records must be corrected and submitted again before certification.
- Certification creates a signed snapshot. Generated certified DTRs are checked
  against that snapshot to detect later changes.

## QR attendance

- QR payloads are signed by the backend.
- Scan requests are rate-limited and tied to the authenticated kiosk operator.
- Administrator and HR accounts can operate a multi-person kiosk. Supervisor,
  Encoder, and Personnel accounts can scan only the card linked to their own
  account and can view only their own card.
- Every scan now requires a 90-second, one-time server challenge bound to the
  current kiosk account and device identifier. Replaying the same API request is
  rejected with `409 Conflict`.
- Phones use server-checked GPS coordinates, accuracy, freshness, and office
  distance. Laptops may use an Administrator-registered office public IP when
  browser GPS is missing or inaccurate. A reliable GPS reading that conflicts
  with the office network is rejected instead of silently accepted.
- Trusted office networks are exact-IP and department scoped, expire after 30
  days by default, and are recorded on every accepted QR audit log. The client
  cannot submit or choose the IP address.
- Personnel photos are stored privately and served only through an authenticated
  API route.
- Optional personnel signatures are stored privately and printed on the back of
  the ID as the cardholder signature.
- A photographed printed card can still be presented at a real authorized
  kiosk. Use a supervised fixed kiosk and compare the displayed personnel photo
  when stronger physical identity assurance is required.

The July 29, 2026 security migration hashes existing per-person QR credentials,
so cards printed before that migration must be printed again after deployment.

When printing QR cards, use 100% scale and enable **Background graphics** for
the premium navy and teal card design.

## Security configuration

Before production deployment:

- Copy values from `backend/.env.production.example`.
- Set `APP_ENV=production` and `APP_DEBUG=false`.
- Use HTTPS and secure session cookies.
- Use a least-privilege database account.
- Set separate random values for `APP_KEY`, `DTR_SIGNING_KEY`, and
  `QR_SIGNING_KEY`.
- Never commit `.env`, database backups, generated DTRs, photos, or signatures.
- Keep `frontend/node_modules`, `frontend/dist`, and `backend/vendor` out of Git.
- Back up the database, `backend/storage/app/private/personnel-photos`,
  `backend/storage/app/private/personnel-signatures`, and
  `backend/storage/app/private/leave-documents`.

Authentication uses Sanctum session cookies with CSRF protection. Login,
general API, and QR scan routes have separate rate limits. Repeated login
failures trigger a temporary account lock. Passwords are hashed and validated
using Laravel's password rules.

The normal login session expires after `SESSION_LIFETIME` minutes of inactivity
(120 minutes by default). Users may explicitly select **Keep me signed in** on
a private device; Laravel then issues an encrypted, `HttpOnly` remembered-login
cookie for `AUTH_REMEMBER_DURATION` minutes (21,600 minutes, or 15 days, by
default). Passwords and bearer tokens are never stored in browser storage.
Signing out or changing an account's password, role, or status revokes its
remembered-login token as well as its active sessions.

API JSON responses retain the existing page-specific fields and also include:

```json
{
  "success": true,
  "request_id": "0190c8c2-...",
  "data": {}
}
```

Errors include a stable `error.code`, a safe message, optional validation
details, and the same request ID used by server logs.

## Production deployment

Production runs as one same-origin application. Build React into Laravel before
uploading:

```powershell
cd frontend
npm ci
npm run build:laravel

cd ..\backend
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan production:check
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Point the domain document root to `backend/public`; do not expose the repository
root or the Laravel `.env` file. HTTPS is required for secure cookies, phone
camera scanning, and GPS. Complete Apache, Nginx, shared-hosting, backup,
rollback, and validation instructions are in
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

For a split deployment with Laravel on Render and React on Vercel, use sibling
subdomains under one parent domain and follow
[docs/RENDER-VERCEL.md](docs/RENDER-VERCEL.md). The repository includes
the free-test `render.yaml`, the paid `render.production.yaml`, a production
PHP Docker image, and `frontend/vercel.json`.

The Vercel API function cryptographically signs the original client IP before
forwarding it to Render. Generate one random secret and save the exact same
value as `FRONTEND_PROXY_SIGNING_SECRET` in both the Render service and Vercel
project. In Vercel also set `BACKEND_API_URL` to the Render origin. Never prefix
the proxy secret with `VITE_`, because Vite variables are public browser data.

```powershell
$proxySecretBytes = New-Object byte[] 48
[Security.Cryptography.RandomNumberGenerator]::Fill($proxySecretBytes)
[Convert]::ToBase64String($proxySecretBytes)
```

After both deployments contain the shared secret, edit a department as an
Administrator while connected to the office internet and select **Register
current network**. Registration is server-derived and does not accept a pasted
IP address. Re-register after the ISP changes the office public IP or when the
30-day verification expires.

The current free Render service is for testing only. `render.free.yaml` retains
the Aiven CA certificate configuration and runs migrations during startup, but
uploaded personnel photos, signatures, and leave supporting documents are
ephemeral and may disappear after a restart or redeploy. Free instances can
also take close to a minute to wake after being idle, so frontend session
verification allows a 60-second cold start. Do not use the free instance as the
final records system.

### Free-tier performance safeguards

High-volume Personnel, System Users, Attendance, DTR, QR card, schedule
assignment, and Leave/Official Business lists use database pagination and
bounded lookups. Leave review synchronization is also limited to the requested
date range and uses indexed attendance upserts rather than loading unrelated
records. The dashboard is cached briefly, and DTR document generation is
limited to 20 personnel per synchronous batch by default. These limits are
suitable for testing on one free Render web service and an Aiven free MySQL
node:

```env
DASHBOARD_CACHE_SECONDS=30
DTR_SYNC_BATCH_LIMIT=20
DTR_SUBMISSION_GRACE_DAYS=2
DTR_REMINDER_DAYS_BEFORE=3
```

Run the new scalability migration after deployment. To make a measured capacity
check, follow [load-tests/README.md](load-tests/README.md). Do not run a load
test against real personnel data or during office attendance hours.

For production, use persistent object storage for photos, signatures, and leave
documents, a paid always-on web instance, a dedicated queue worker,
Redis-backed cache/queues, managed database backups, and monitoring. The free
services remain appropriate for functional testing, not a live government
records workload.

The root `render.yaml` remains the free test configuration while the system is
being evaluated; `render.free.yaml` is an explicit copy of that test setup.
The paid, always-on configuration is preserved in `render.production.yaml` for
the later production upgrade. Daily encrypted MySQL backups, retention, and
disposable restore verification are provided in
[.github/workflows/database-backup.yml](.github/workflows/database-backup.yml),
but scheduled runs stay disabled until `ENABLE_SCHEDULED_BACKUPS=true` is set
as a GitHub Actions repository variable. Complete the one-time setup in
[docs/BACKUP-RECOVERY.md](docs/BACKUP-RECOVERY.md) before enabling it.

## Testing and quality checks

Backend:

```powershell
cd backend
php artisan test
php vendor\bin\pint --test app bootstrap config database\migrations routes tests
composer audit
```

Frontend:

```powershell
cd frontend
npm run lint
npm run build
npm audit
```

## Common troubleshooting

### Render cannot resolve the Aiven database host

An error containing `php_network_getaddresses`, `getaddrinfo`, or `Name or
service not known` means DNS resolution failed before Laravel could authenticate
or run a query. In Aiven, confirm that the MySQL service is **Running**, then
copy the current **Host** from its connection details into Render's `DB_HOST`.
Enter only the hostname—do not include `mysql://`, `https://`, the port, quotes,
or spaces. Keep the Aiven port in `DB_PORT`.

The Render startup script retries database migrations and readiness checks for
short DNS or managed-database interruptions. If all retries fail, correct the
environment value and choose **Manual Deploy → Deploy latest commit**.

### White screen

Check the Vite terminal and browser console, then run:

```powershell
cd frontend
npm install
npm run lint
npm run dev
```

### Login returns 419

Confirm the current frontend host and port are in
`SANCTUM_STATEFUL_DOMAINS`, then run `php artisan optimize:clear`. Access the
frontend and backend using consistent hostnames; avoid mixing `localhost` and
`127.0.0.1` during the same session.

### Phone says webpage unavailable

Confirm both servers use `--host=0.0.0.0`, verify the IPv4 address, keep both
devices on the same network, and allow ports 5173 and 8000 through Windows
Firewall.

### QR photo or personnel photo is missing

Edit the personnel record and upload a JPEG, PNG, or WebP image of at least
128×128 pixels and no larger than 3 MB. Refresh the QR page with `Ctrl + F5`
after replacing a photo.

### QR camera does not open on a phone

Use HTTPS. Browser camera APIs are generally blocked on non-secure LAN HTTP
addresses.

### DTR generation fails

Confirm that this file exists:

```text
backend/resources/templates/DTR-format-1.docx
```

Only records allowed by the current DTR workflow can be generated.

## Data protection

This system stores government personnel and attendance information. Restrict
server access, collect only necessary information, keep audit records, use
encrypted backups, and follow the applicable DILG privacy and records-retention
policies.

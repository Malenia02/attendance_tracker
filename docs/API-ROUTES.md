# API Route Reference

This file explains what each backend route is used for. The frontend normally
calls these through Vercel using `/api/...`, then Vercel forwards the request to
the Laravel API.

Backend authorization is still enforced by Laravel. Do not rely on hidden React
buttons or sidebar items for security.

## Route protection summary

Most API routes use:

- `web` for Laravel session cookies and CSRF protection
- `auth:sanctum` for authenticated sessions
- `session.active` to reject inactive users/sessions
- `throttle:api` for general rate limiting
- `api.audit` for activity logging

Some sensitive routes add role middleware and stricter throttles.

## Public and deployment routes

These routes are declared in `backend/routes/web.php`.

| Method | Route | Purpose | Protection |
| --- | --- | --- | --- |
| `POST` | `/api/auth/login` | Signs in a system user and creates the Laravel session cookie. | `throttle:login` |
| `GET` | `/health/ready` | Health check used by Render and manual deployment checks. | `throttle:30,1` |
| `GET` | `/{path?}` | React SPA fallback for browser refreshes. Excludes API/health/framework routes. | Public frontend route |

## Protected authenticated API routes

These routes are declared in `backend/routes/api.php`.

### Auth and session

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/auth/me` | Returns the currently authenticated system user, role, and linked personnel data. | Any authenticated user |
| `POST` | `/api/auth/logout` | Logs out and destroys the current session. | Any authenticated user |

### Dashboard, action center, and notifications

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/dashboard` | Returns role-specific dashboard metrics and queues. | Any authenticated user |
| `GET` | `/api/action-center` | Returns unified action queues such as correction requests, DTR cutoffs, missing time-outs, and setup gaps. | Administrator, HR, Supervisor |
| `GET` | `/api/notifications` | Returns paginated notifications for the current user. | Any authenticated user |
| `GET` | `/api/notifications/summary` | Returns unread notification counts and summary data. | Any authenticated user |
| `PATCH` | `/api/notifications/read-all` | Marks all notifications as read for the current user. | Any authenticated user |
| `PATCH` | `/api/notifications/{notificationId}/read` | Marks one notification as read. | Any authenticated user |

### Daily attendance

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/attendance` | Lists attendance records with role-scoped access and filters. | Any authenticated user |
| `GET` | `/api/attendance/options` | Provides personnel/status/date options for attendance forms. | Any authenticated user |
| `POST` | `/api/attendance/time-log` | Records time-in/time-out through the system button. | Any authenticated user |
| `GET` | `/api/attendance/correction-requests` | Lists attendance correction requests with role-scoped visibility. | Any authenticated user |
| `GET` | `/api/attendance/correction-requests/{correctionRequest}` | Opens one correction request for review. | Administrator, HR |
| `POST` | `/api/attendance/correction-requests` | Lets a personnel-linked account explain and request correction for its own attendance. | Any authenticated user |
| `PATCH` | `/api/attendance/correction-requests/{correctionRequest}/review` | Approves or rejects an attendance correction request. | Administrator, HR |
| `PATCH` | `/api/attendance/{attendance}/verify` | Verifies one attendance record. | Administrator, HR, Supervisor |
| `POST` | `/api/attendance/verify-bulk` | Verifies multiple attendance records in one controlled request. | Administrator, HR, Supervisor |
| `POST` | `/api/attendance/correction` | Manually corrects an attendance record. | Administrator, HR |

### QR attendance and personnel cards

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/qr-attendance` | Returns QR kiosk state and the current user card visibility. | Administrator, HR, Supervisor, Encoder, Personnel |
| `GET` | `/api/qr-attendance/cards` | Lists personnel cards for kiosk/card management. | Administrator, HR |
| `POST` | `/api/qr-attendance/challenge` | Creates a short-lived QR scan challenge bound to the kiosk/device. | Administrator, HR, Supervisor, Encoder, Personnel |
| `POST` | `/api/qr-attendance/scan` | Validates a scanned QR card, location/IP rules, and records attendance. | Administrator, HR, Supervisor, Encoder, Personnel |
| `POST` | `/api/qr-attendance/personnel/{personnel}/regenerate` | Regenerates a personnel QR credential/card token. | Administrator, HR |

### DTR workflow

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/dtr` | Lists DTR rows for a month/period, including schedule coverage and certification state. | Any authenticated user |
| `POST` | `/api/dtr/generate` | Generates a DTR document from verified attendance and certification data. | Any authenticated user, role-scoped |
| `PATCH` | `/api/dtr/{personnel}/status` | Submits, certifies, returns, or updates a DTR workflow status. Late cutoff submissions become `Submitted Late`. | Role-scoped backend checks |
| `POST` | `/api/dtr/{personnel}/reopen-requests` | Requests reopening of a certified DTR. | Administrator, HR |
| `PATCH` | `/api/dtr/reopen-requests/{reopenRequest}/review` | Reviews a DTR reopening request. | Administrator |

### Leave and official business

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/leave-requests` | Lists leave/official-business requests with role-scoped filters. | Any authenticated user |
| `POST` | `/api/leave-requests` | Creates a leave or official-business request with optional private attachment. | Any authenticated user |
| `GET` | `/api/leave-requests/{leaveRecord}/document` | Downloads/views the private supporting document for an authorized request. | Role-scoped backend checks |
| `PATCH` | `/api/leave-requests/{leaveRecord}/review` | Approves or rejects a leave/official-business request. | Administrator, HR, Supervisor |
| `PATCH` | `/api/leave-requests/{leaveRecord}/cancel` | Cancels an eligible request. | Role-scoped backend checks |

### Holidays, duty days, and private personnel media

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/holidays` | Lists holidays, rest days, and office-specific duty days for calendar views. | Any authenticated user |
| `GET` | `/api/holidays/options` | Provides calendar form options. | Any authenticated user |
| `GET` | `/api/personnel/{personnel}/photo` | Serves an authorized personnel photo from private storage. | Role-scoped backend checks |
| `GET` | `/api/personnel/{personnel}/signature` | Serves an authorized personnel signature image from private storage. | Role-scoped backend checks |

### HR setup and workforce rules

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/personnel-onboarding` | Lists personnel that need activation/setup. | Administrator, HR |
| `PATCH` | `/api/personnel-onboarding/bulk` | Activates or updates multiple personnel onboarding records atomically. | Administrator, HR |
| `PATCH` | `/api/personnel-onboarding/{personnel}` | Updates one onboarding/activation record. | Administrator, HR |
| `GET` | `/api/departments` | Lists departments/offices and GPS rules. | Administrator, HR |
| `POST` | `/api/departments` | Creates a department/office with location/radius settings. | Administrator, HR |
| `PUT/PATCH` | `/api/departments/{department}` | Updates department/office details and GPS settings. | Administrator, HR |
| `DELETE` | `/api/departments/{department}` | Deletes an eligible department/office. | Administrator, HR |
| `GET` | `/api/schedules` | Lists work schedules. | Administrator, HR |
| `POST` | `/api/schedules` | Creates a work schedule template. | Administrator, HR |
| `PUT/PATCH` | `/api/schedules/{schedule}` | Updates a work schedule template. | Administrator, HR |
| `DELETE` | `/api/schedules/{schedule}` | Deletes an eligible work schedule template. | Administrator, HR |
| `POST` | `/api/schedules/assignments` | Assigns a work schedule to personnel and closes overlapping assignments. | Administrator, HR |
| `DELETE` | `/api/schedules/assignments/{personnelSchedule}` | Removes a schedule assignment. | Administrator, HR |
| `POST` | `/api/holidays` | Creates a holiday or office-specific duty day. | Administrator, HR |
| `PUT/PATCH` | `/api/holidays/{holiday}` | Updates a holiday or duty day. | Administrator, HR |
| `DELETE` | `/api/holidays/{holiday}` | Deletes a holiday or duty day. | Administrator, HR |
| `GET` | `/api/personnel/options` | Provides personnel form options. | Administrator, HR |
| `GET` | `/api/personnel` | Lists personnel records with filters and pagination. | Administrator, HR |
| `POST` | `/api/personnel` | Creates a personnel record. | Administrator, HR |
| `PUT/PATCH` | `/api/personnel/{personnel}` | Updates a personnel record. | Administrator, HR |
| `DELETE` | `/api/personnel/{personnel}` | Deletes an eligible personnel record. | Administrator, HR |

### Administrator security and system settings

| Method | Route | Purpose | Roles |
| --- | --- | --- | --- |
| `GET` | `/api/activity-logs` | Lists immutable system activity logs. | Administrator |
| `GET` | `/api/system-users/options` | Provides system-user form options and linkable personnel. | Administrator |
| `GET` | `/api/system-users` | Lists system user accounts. | Administrator |
| `POST` | `/api/system-users` | Creates a system user account. | Administrator |
| `PUT/PATCH` | `/api/system-users/{systemUser}` | Updates a system user account, role, status, or password. | Administrator |
| `DELETE` | `/api/system-users/{systemUser}` | Deletes an eligible system user account. | Administrator |
| `POST` | `/api/departments/{department}/office-networks` | Registers a trusted office public IP/network for attendance location fallback. | Administrator |
| `DELETE` | `/api/departments/{department}/office-networks/{officeNetwork}` | Removes a trusted office network entry. | Administrator |

## Notes for debugging

- A `401` response means the session is missing, expired, inactive, or rejected.
- A `403` response means the user is authenticated but the backend role/scope check refused access.
- A `422` response means validation failed or the requested workflow action is not allowed.
- A `500` response should be checked in Render logs using the returned `request_id`.
- Mutating requests must pass CSRF/session checks and should be called through the frontend proxy in production.

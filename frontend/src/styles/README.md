# Stylesheet map

`src/index.css` is the stylesheet manifest. Keep its imports in the current
order because later feature files intentionally override shared foundations.

## Core

- `core/theme.css` — light and dark theme tokens
- `core/foundation.css` — global box sizing, application variables, body, form,
  and root defaults
- `core/responsive.css` — final cross-page phone safeguards, safe-area spacing,
  touch controls, compact modals, and small-screen overflow handling

## Layout

- `layout/header.css` — top navigation and profile controls
- `layout/sidebar.css` — sidebar navigation and signed-in user block
- `layout/app-layout.css` — main content shell, panels, breadcrumbs, common
  responsive layout, and collapsed/mobile shell behavior

## Shared components

- `components/admin-records.css` — reusable summary cards, record tables,
  filters, notices, actions, forms, validation, and modals used by System Users
  and Personnel

## Feature pages

- `pages/dashboard.css`
- `pages/login.css`
- `pages/personnel.css`
- `pages/calendar-holidays.css`
- `pages/attendance.css`
- `pages/dtr-monitoring.css`
- `pages/qr-attendance.css`
- `pages/departments.css`
- `pages/activity-logs.css`
- `pages/error-page.css`

When adding a style, prefer the narrowest applicable page file. Put it in a
layout or shared component file only when the selector is intentionally reused.

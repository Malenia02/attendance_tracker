# Encrypted database backup and recovery

The repository includes a production backup workflow at
`.github/workflows/database-backup.yml`. It can be started manually at any
time. Its 02:30 Asia/Manila daily schedule remains disabled until the GitHub
Actions repository variable `ENABLE_SCHEDULED_BACKUPS` is set to `true`.

The job does not store a plaintext database export in GitHub artifacts. It:

1. creates a transaction-consistent MySQL dump over verified TLS;
2. compresses the dump;
3. uploads it into an encrypted Restic repository in private S3-compatible
   object storage;
4. downloads the newest encrypted snapshot;
5. verifies its checksum and gzip integrity;
6. imports it into a disposable MySQL 8 container;
7. checks the critical attendance tables;
8. checks a subset of the encrypted repository data; and
9. applies retention only after restoration succeeds.

## Storage requirements

Create a private S3-compatible bucket dedicated to backups. Enable bucket
versioning and object-lock/immutability when the provider supports them. Use a
separate service account limited to that bucket and path. It must not have
access to unrelated application storage.

The Restic password encrypts every snapshot before it reaches the bucket. Save
that password in an offline password manager as well as GitHub. Losing it makes
every backup unrecoverable. Do not reuse `APP_KEY`, the database password, or
the DTR/QR signing keys.

Example repository value:

```text
s3:https://s3.example-provider.com/dilg-private-backups/mysql
```

Use the exact endpoint format supplied by the storage provider.

## Configure GitHub

In the GitHub repository, create an Environment named
`production-backups`. Limit who can edit or run workflows in this environment.
Add these Environment secrets:

| Secret | Value |
| --- | --- |
| `BACKUP_DB_HOST` | Production MySQL hostname only |
| `BACKUP_DB_PORT` | Production MySQL port |
| `BACKUP_DB_DATABASE` | Production database name |
| `BACKUP_DB_USERNAME` | Read/backup database account |
| `BACKUP_DB_PASSWORD` | Password for that account |
| `BACKUP_MYSQL_SSL_CA_BASE64` | Base64-encoded database CA certificate |
| `RESTIC_REPOSITORY` | Private S3 Restic repository URL |
| `RESTIC_PASSWORD` | Independent high-entropy encryption password |
| `BACKUP_S3_ACCESS_KEY_ID` | Bucket-scoped access key |
| `BACKUP_S3_SECRET_ACCESS_KEY` | Bucket-scoped secret key |

Add `BACKUP_S3_REGION` as an Environment variable. Use `auto` only when the
provider explicitly supports it.

GitHub-hosted runners use changing outbound addresses. If the production
database has an IP allowlist, run this workflow on a hardened self-hosted
runner with a fixed outbound IP or use a provider-native backup job with fixed
egress. Do not permanently open a production database to the whole internet
just to run backups.

## First run

The encrypted Restic repository must be initialized once:

1. Open **GitHub → Actions → Encrypted production database backup**.
2. Select **Run workflow**.
3. Enable **Initialize a new empty Restic repository**.
4. Run the workflow.
5. Confirm all restore-verification steps pass.
6. When the backup destination is ready for continuous use, add the repository
   variable `ENABLE_SCHEDULED_BACKUPS=true` to enable daily runs.

The scheduled workflow does not auto-initialize a missing repository. This
prevents a wrong bucket path or wrong password from silently creating a second
backup repository.

## Retention

The default verified-backup policy keeps:

- the latest 3 snapshots;
- 14 daily snapshots;
- 8 weekly snapshots; and
- 12 monthly snapshots.

Retention pruning runs only after the newest snapshot has been restored into
disposable MySQL and verified. Review this policy against the official DILG
records-retention requirement before live use.

## Monitoring

GitHub marks the workflow failed if dumping, encryption, upload, download,
checksum validation, import, table validation, repository checking, or pruning
fails. Enable GitHub Actions failure notifications for the responsible system
administrator. A failed job must be investigated the same day.

At least monthly, manually run the workflow with `100%` as the integrity
subset. This reads and verifies the complete encrypted repository and may
incur storage bandwidth charges.

## Recovery procedure

Do not overwrite production immediately after an incident.

1. Put the attendance application into maintenance mode or otherwise stop
   writes.
2. Preserve an emergency dump of the current database, even if it appears
   damaged.
3. Restore the selected snapshot into a separate recovery database.
4. Run `php artisan production:check` against that recovery database.
5. Compare personnel, attendance, DTR, activity-log, and migration counts.
6. Obtain approval from the responsible administrator.
7. Switch the application to the verified recovery database.

The daily workflow proves that the newest dump can be imported, but it never
modifies the production database.

## Backup scope

This workflow protects the MySQL database only. A complete recovery plan must
also protect:

- private personnel photos and signatures;
- leave supporting documents;
- `APP_KEY`, `DTR_SIGNING_KEY`, and `QR_SIGNING_KEY`;
- the exact deployed Git commit; and
- DNS and hosting configuration.

Keep signing and encryption secrets in a separate secret manager, not inside
the same backup bucket.

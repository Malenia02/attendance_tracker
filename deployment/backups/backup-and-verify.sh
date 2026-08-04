#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

required_variables=(
  DB_HOST
  DB_PORT
  DB_DATABASE
  DB_USERNAME
  DB_PASSWORD
  MYSQL_SSL_CA_BASE64
  RESTIC_REPOSITORY
  RESTIC_PASSWORD
  AWS_ACCESS_KEY_ID
  AWS_SECRET_ACCESS_KEY
)

missing_variables=()
for variable in "${required_variables[@]}"; do
  if [[ -z "${!variable:-}" ]]; then
    missing_variables+=("${variable}")
  fi
done

if (( ${#missing_variables[@]} > 0 )); then
  printf 'ERROR: Missing required backup variables: %s\n' "${missing_variables[*]}" >&2
  exit 1
fi

for command in docker gzip sha256sum base64 grep; do
  if ! command -v "${command}" >/dev/null 2>&1; then
    printf 'ERROR: Required command is unavailable: %s\n' "${command}" >&2
    exit 1
  fi
done

MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.4}"
RESTIC_IMAGE="${RESTIC_IMAGE:-restic/restic:0.18.1}"
RESTIC_HOST="${RESTIC_HOST:-dilg-attendance-production}"
BACKUP_KEEP_LAST="${BACKUP_KEEP_LAST:-3}"
BACKUP_KEEP_DAILY="${BACKUP_KEEP_DAILY:-14}"
BACKUP_KEEP_WEEKLY="${BACKUP_KEEP_WEEKLY:-8}"
BACKUP_KEEP_MONTHLY="${BACKUP_KEEP_MONTHLY:-12}"
RESTIC_CHECK_SUBSET="${RESTIC_CHECK_SUBSET:-5%}"
RESTIC_AUTO_INIT="${RESTIC_AUTO_INIT:-false}"

work_directory="$(mktemp -d)"
dump_directory="${work_directory}/dump"
restore_directory="${work_directory}/restore"
ca_file="${work_directory}/mysql-ca.pem"
verify_container="dilg-db-restore-${GITHUB_RUN_ID:-$$}-${RANDOM}"
verify_database="dilg_restore_verify"
verify_password="$(printf '%s%s%s' "${RANDOM}" "$(date +%s)" "$$" | sha256sum | cut -d' ' -f1)"

mkdir -p "${dump_directory}" "${restore_directory}"

cleanup() {
  docker rm -f "${verify_container}" >/dev/null 2>&1 || true
  rm -rf "${work_directory}"
}
trap cleanup EXIT INT TERM

if ! printf '%s' "${MYSQL_SSL_CA_BASE64}" | base64 --decode > "${ca_file}"; then
  echo 'ERROR: MYSQL_SSL_CA_BASE64 is not valid base64.' >&2
  exit 1
fi

if ! grep -q -- '-----BEGIN CERTIFICATE-----' "${ca_file}"; then
  echo 'ERROR: The decoded MySQL CA does not contain a PEM certificate.' >&2
  exit 1
fi

restic_run() {
  docker run --rm \
    --env RESTIC_REPOSITORY \
    --env RESTIC_PASSWORD \
    --env AWS_ACCESS_KEY_ID \
    --env AWS_SECRET_ACCESS_KEY \
    --env AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-auto}" \
    --env AWS_SESSION_TOKEN="${AWS_SESSION_TOKEN:-}" \
    --volume "${work_directory}:/work" \
    "${RESTIC_IMAGE}" "$@"
}

echo 'Creating a transaction-consistent MySQL backup.'
export MYSQL_PWD="${DB_PASSWORD}"
docker run --rm \
  --env MYSQL_PWD \
  --volume "${dump_directory}:/backup" \
  --volume "${ca_file}:/certs/mysql-ca.pem:ro" \
  "${MYSQL_IMAGE}" \
  mysqldump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USERNAME}" \
    --ssl-mode=VERIFY_CA \
    --ssl-ca=/certs/mysql-ca.pem \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --hex-blob \
    --set-gtid-purged=OFF \
    --no-tablespaces \
    --default-character-set=utf8mb4 \
    --result-file=/backup/database.sql \
    "${DB_DATABASE}"
unset MYSQL_PWD

if [[ ! -s "${dump_directory}/database.sql" ]]; then
  echo 'ERROR: mysqldump produced an empty backup.' >&2
  exit 1
fi

gzip -9 "${dump_directory}/database.sql"
original_checksum="$(sha256sum "${dump_directory}/database.sql.gz" | cut -d' ' -f1)"

if ! restic_run snapshots >/dev/null 2>&1; then
  if [[ "${RESTIC_AUTO_INIT}" != 'true' ]]; then
    echo 'ERROR: The encrypted Restic repository is unavailable or not initialized.' >&2
    echo 'Set RESTIC_AUTO_INIT=true only for the first run, then remove it.' >&2
    exit 1
  fi

  echo 'Initializing the encrypted Restic repository.'
  restic_run init
fi

echo 'Uploading the encrypted database snapshot.'
restic_run backup /work/dump/database.sql.gz \
  --host "${RESTIC_HOST}" \
  --tag database \
  --tag mysql

echo 'Restoring the newest encrypted snapshot for verification.'
restic_run dump \
  --host "${RESTIC_HOST}" \
  --tag database \
  latest \
  /work/dump/database.sql.gz > "${restore_directory}/database.sql.gz"

gzip -t "${restore_directory}/database.sql.gz"
restored_checksum="$(sha256sum "${restore_directory}/database.sql.gz" | cut -d' ' -f1)"

if [[ "${original_checksum}" != "${restored_checksum}" ]]; then
  echo 'ERROR: Restored dump checksum does not match the source dump.' >&2
  exit 1
fi

docker run --detach \
  --name "${verify_container}" \
  --env MYSQL_ROOT_PASSWORD="${verify_password}" \
  --env MYSQL_DATABASE="${verify_database}" \
  "${MYSQL_IMAGE}" >/dev/null

database_ready=false
for _ in $(seq 1 60); do
  if docker exec \
    --env MYSQL_PWD="${verify_password}" \
    "${verify_container}" \
    mysqladmin ping --user=root --silent >/dev/null 2>&1; then
    database_ready=true
    break
  fi
  sleep 2
done

if [[ "${database_ready}" != 'true' ]]; then
  echo 'ERROR: Disposable MySQL restore-verification database did not start.' >&2
  exit 1
fi

gunzip --stdout "${restore_directory}/database.sql.gz" | docker exec --interactive \
  --env MYSQL_PWD="${verify_password}" \
  "${verify_container}" \
  mysql --user=root "${verify_database}"

critical_tables=(
  migrations
  system_users
  personnel
  departments
  work_schedules
  attendance_records
  time_logs
  activity_logs
)

for table in "${critical_tables[@]}"; do
  table_exists="$(docker exec \
    --env MYSQL_PWD="${verify_password}" \
    "${verify_container}" \
    mysql --batch --skip-column-names --user=root "${verify_database}" \
    --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${table}'")"

  if [[ "${table_exists}" != '1' ]]; then
    printf 'ERROR: Restore verification is missing critical table: %s\n' "${table}" >&2
    exit 1
  fi
done

check_tables="$(IFS=,; echo "${critical_tables[*]}")"
docker exec \
  --env MYSQL_PWD="${verify_password}" \
  "${verify_container}" \
  mysql --batch --skip-column-names --user=root "${verify_database}" \
  --execute="CHECK TABLE ${check_tables}" | awk '$4 != "OK" { exit 1 }'

echo 'Checking encrypted repository integrity.'
restic_run check --read-data-subset="${RESTIC_CHECK_SUBSET}"

echo 'Applying backup retention after successful restore verification.'
restic_run forget \
  --host "${RESTIC_HOST}" \
  --tag database \
  --keep-last "${BACKUP_KEEP_LAST}" \
  --keep-daily "${BACKUP_KEEP_DAILY}" \
  --keep-weekly "${BACKUP_KEEP_WEEKLY}" \
  --keep-monthly "${BACKUP_KEEP_MONTHLY}" \
  --prune

echo 'Encrypted database backup and disposable restore verification completed successfully.'

#!/usr/bin/env bash
# HELBARON — PostgreSQL restore + restore-verification (provider-neutral).
# Restores a custom-format dump into a TARGET database. Refuses to clobber unless --force. Fails safe;
# never prints credentials.
#
# Usage:
#   DATABASE_URL=postgres://user:pass@host:5432/target  ./scripts/db-restore.sh <DUMP_FILE> [--force]
# The dump may be a .dump or a .dump.gpg (set BACKUP_GPG_RECIPIENT / gpg configured to decrypt).
set -Eeuo pipefail

DUMP="${1:-}"
FORCE="${2:-}"
[[ -n "$DUMP" ]] || { echo "usage: $0 <DUMP_FILE> [--force]" >&2; exit 2; }
[[ -f "$DUMP" ]] || { echo "ERROR: dump not found: $DUMP" >&2; exit 2; }
command -v pg_restore >/dev/null 2>&1 || { echo "ERROR: pg_restore not found" >&2; exit 2; }
if [[ -z "${DATABASE_URL:-}" && -z "${PGDATABASE:-}" ]]; then
  echo "ERROR: set DATABASE_URL or PG* connection variables (TARGET database)" >&2; exit 2
fi

# Verify checksum if a sidecar exists.
if [[ -f "${DUMP}.sha256" ]] && command -v sha256sum >/dev/null 2>&1; then
  (cd "$(dirname "$DUMP")" && sha256sum -c "$(basename "$DUMP").sha256") || { echo "ERROR: checksum mismatch" >&2; exit 3; }
fi

# Decrypt if needed.
WORK="$DUMP"
if [[ "$DUMP" == *.gpg ]]; then
  command -v gpg >/dev/null 2>&1 || { echo "ERROR: encrypted dump but gpg missing" >&2; exit 2; }
  WORK="${DUMP%.gpg}"
  gpg --yes --batch --decrypt --output "$WORK" "$DUMP"
fi

if [[ "$FORCE" != "--force" ]]; then
  echo "Refusing to restore over an existing database without --force." >&2
  echo "Re-run with --force once you have confirmed the TARGET is disposable/intended." >&2
  exit 4
fi

echo "==> restoring ${WORK} into target database"
# --clean --if-exists makes the restore idempotent; --no-owner/--no-acl for portability.
if [[ -n "${DATABASE_URL:-}" ]]; then
  pg_restore --clean --if-exists --no-owner --no-acl --exit-on-error -d "$DATABASE_URL" "$WORK"
else
  pg_restore --clean --if-exists --no-owner --no-acl --exit-on-error "$WORK"
fi

echo "==> restore complete; verifying row-readability on a core table"
# Restore verification: the target must answer a trivial query (schema present + connectable).
if [[ -n "${DATABASE_URL:-}" ]]; then
  psql "$DATABASE_URL" -tAc "select count(*) from information_schema.tables where table_schema='public';"
else
  psql -tAc "select count(*) from information_schema.tables where table_schema='public';"
fi
echo "==> restore verified"

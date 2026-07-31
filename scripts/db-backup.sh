#!/usr/bin/env bash
# HELBARON — PostgreSQL logical backup (provider-neutral).
# Produces a timestamped, integrity-checked custom-format dump. Fails safe; never prints credentials.
#
# Usage:
#   DATABASE_URL=postgres://user:pass@host:5432/db  ./scripts/db-backup.sh [OUT_DIR]
#   (or set PGHOST/PGPORT/PGUSER/PGPASSWORD/PGDATABASE individually)
# Optional:
#   BACKUP_GPG_RECIPIENT=<key-id>   # encrypt the dump at rest (off-site safe)
#   BACKUP_RETENTION_DAYS=14        # prune older local dumps (default 14)
set -Eeuo pipefail

OUT_DIR="${1:-${BACKUP_DIR:-./storage/backups}}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

command -v pg_dump >/dev/null 2>&1 || { echo "ERROR: pg_dump not found on PATH" >&2; exit 2; }

if [[ -z "${DATABASE_URL:-}" && -z "${PGDATABASE:-}" ]]; then
  echo "ERROR: set DATABASE_URL or PG* connection variables" >&2
  exit 2
fi

mkdir -p "$OUT_DIR"
BASE="$OUT_DIR/helbaron-${STAMP}.dump"
CONN=("${DATABASE_URL:-}")

echo "==> backing up database to ${BASE} (custom format, compressed)"
# -Fc = custom (restorable with pg_restore), -Z6 = compression, --no-owner for portable restore.
if [[ -n "${DATABASE_URL:-}" ]]; then
  pg_dump -Fc -Z6 --no-owner --no-acl -f "$BASE" "$DATABASE_URL"
else
  pg_dump -Fc -Z6 --no-owner --no-acl -f "$BASE"
fi

# Integrity: pg_restore --list must parse the archive TOC.
pg_restore --list "$BASE" >/dev/null
echo "==> integrity check OK ($(du -h "$BASE" | cut -f1))"

# SHA-256 sidecar for tamper/transfer verification.
if command -v sha256sum >/dev/null 2>&1; then sha256sum "$BASE" > "${BASE}.sha256"; fi

# Optional encryption at rest (off-site).
if [[ -n "${BACKUP_GPG_RECIPIENT:-}" ]]; then
  command -v gpg >/dev/null 2>&1 || { echo "ERROR: BACKUP_GPG_RECIPIENT set but gpg missing" >&2; exit 2; }
  gpg --yes --batch --encrypt --recipient "$BACKUP_GPG_RECIPIENT" "$BASE"
  rm -f "$BASE"
  echo "==> encrypted to ${BASE}.gpg"
fi

# Retention: prune old local dumps (off-site copies are managed by the storage lifecycle policy).
find "$OUT_DIR" -maxdepth 1 -name 'helbaron-*.dump*' -type f -mtime +"$RETENTION_DAYS" -print -delete || true

echo "==> backup complete"

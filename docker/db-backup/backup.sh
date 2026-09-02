#!/bin/bash
# A3.6: periodic pg_dump of the application database, uploaded to a
# dedicated MinIO bucket, with automatic expiry via MinIO's own ILM
# (Information Lifecycle Management) rather than a hand-rolled cleanup
# loop - one `mc ilm add` call, MinIO enforces it server-side from then on.
#
# Deliberately a plain sleep loop (same pattern as backend/src/Command/
# PublishKpiSnapshotCommand.php for A3.1) rather than cron: this project
# has no cron infrastructure anywhere, and a tight loop in its own
# restart:unless-stopped container is simpler and just as reliable at
# this scale. One failed backup attempt (pg_dump error, MinIO
# unreachable) is logged and retried on the next tick - never crashes the
# container into a restart loop.
#
# "nev-climate-data-backups" is a bucket of its own, separate from
# "nev-climate-data" (the Volet B Bronze/Silver/Gold data lake bucket,
# see pipeline/common/minio_staging.py) - operational backups are a
# different concern from pipeline data, and keeping them in separate
# buckets means a backup-retention policy (or a bug in this script) can
# never touch real pipeline data, and vice versa.
set -uo pipefail

BACKUP_BUCKET="${BACKUP_BUCKET:-nev-climate-data-backups}"
BACKUP_INTERVAL_SECONDS="${BACKUP_INTERVAL_SECONDS:-86400}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

export PGHOST="${PGHOST:-database}"
export PGUSER="${POSTGRES_USER:?POSTGRES_USER is required}"
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is required}"
export PGDATABASE="${POSTGRES_DB:?POSTGRES_DB is required}"

log() {
  echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"
}

mc alias set backupminio "http://${MINIO_ENDPOINT:-minio:9000}" "${MINIO_ROOT_USER:-minioadmin}" "${MINIO_ROOT_PASSWORD:?MINIO_ROOT_PASSWORD is required}" >/dev/null
mc mb --ignore-existing "backupminio/${BACKUP_BUCKET}" >/dev/null
# --ignore-existing above only silences "bucket already exists" style
# errors on mb, not on ilm; ilm add is idempotent on its own (re-adding
# the same rule just replaces it), so no separate guard is needed here.
mc ilm add --expiry-days "${BACKUP_RETENTION_DAYS}" "backupminio/${BACKUP_BUCKET}" >/dev/null 2>&1 || true

log "Sauvegardes automatisées démarrées (bucket=${BACKUP_BUCKET}, intervalle=${BACKUP_INTERVAL_SECONDS}s, rétention=${BACKUP_RETENTION_DAYS}j)."

while true; do
  timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
  filename="nev_climate_data_${timestamp}.sql.gz"
  tmpfile="/tmp/${filename}"

  log "Sauvegarde en cours -> ${filename}"

  # --no-owner --no-privileges: the dump must restore cleanly into any
  # target role name (a throwaway restore-verification database, a
  # different environment's user) without failing on ALTER OWNER/GRANT
  # statements referencing a role that may not exist there.
  if pg_dump --no-owner --no-privileges | gzip > "${tmpfile}"; then
    if mc cp --quiet "${tmpfile}" "backupminio/${BACKUP_BUCKET}/${filename}" >/dev/null; then
      log "Sauvegarde envoyée avec succès : ${filename} ($(du -h "${tmpfile}" | cut -f1))"
    else
      log "ERREUR : échec de l'envoi vers MinIO pour ${filename}"
    fi
  else
    log "ERREUR : pg_dump a échoué"
  fi
  rm -f "${tmpfile}"

  sleep "${BACKUP_INTERVAL_SECONDS}"
done

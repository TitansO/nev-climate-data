#!/bin/bash
# A3.6: periodic pg_dump of the application database, uploaded to a
# dedicated MinIO bucket, with expiry enforced client-side on every tick
# via `rclone delete --min-age` (see below). Uses rclone rather than
# MinIO's own `mc` client: mc is no longer installable (audit
# pre-production 2026-09-23, P2 - see docker/db-backup/Dockerfile) since
# MinIO archived that project; rclone speaks the same S3-compatible
# protocol and is a plain Alpine package with no external download URL
# to go stale. rclone has no direct equivalent of `mc ilm add` (server-
# side lifecycle), hence the client-side sweep instead of a one-time
# policy call.
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

# rclone remote "backupminio" configured entirely via env vars (no
# ~/.config/rclone/rclone.conf) - keeps credentials out of any file on
# disk, consistent with how PG* above are handled.
export RCLONE_CONFIG=/dev/null
export RCLONE_CONFIG_BACKUPMINIO_TYPE=s3
export RCLONE_CONFIG_BACKUPMINIO_PROVIDER=Minio
export RCLONE_CONFIG_BACKUPMINIO_ENV_AUTH=false
export RCLONE_CONFIG_BACKUPMINIO_ENDPOINT="http://${MINIO_ENDPOINT:-minio:9000}"
export RCLONE_CONFIG_BACKUPMINIO_ACCESS_KEY_ID="${MINIO_ROOT_USER:-minioadmin}"
export RCLONE_CONFIG_BACKUPMINIO_SECRET_ACCESS_KEY="${MINIO_ROOT_PASSWORD:?MINIO_ROOT_PASSWORD is required}"

log() {
  echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"
}

rclone mkdir "backupminio:${BACKUP_BUCKET}" >/dev/null 2>&1

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
    if rclone copyto "${tmpfile}" "backupminio:${BACKUP_BUCKET}/${filename}" >/dev/null 2>&1; then
      log "Sauvegarde envoyée avec succès : ${filename} ($(du -h "${tmpfile}" | cut -f1))"
      # sweep, not a one-time policy (rclone has no `mc ilm` equivalent) -
      # run on every successful upload so retention stays enforced even if
      # this container restarts and misses a tick.
      rclone delete --min-age "${BACKUP_RETENTION_DAYS}d" "backupminio:${BACKUP_BUCKET}" >/dev/null 2>&1 || true
    else
      log "ERREUR : échec de l'envoi vers MinIO pour ${filename}"
    fi
  else
    log "ERREUR : pg_dump a échoué"
  fi
  rm -f "${tmpfile}"

  sleep "${BACKUP_INTERVAL_SECONDS}"
done

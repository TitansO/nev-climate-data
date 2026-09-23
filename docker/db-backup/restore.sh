#!/bin/bash
# A3.6: restores one backup produced by backup.sh into a target database.
# Manual/on-demand only - never runs in a loop, never runs automatically.
#
# Usage (from the host):
#   docker compose run --rm db-backup /usr/local/bin/restore.sh <filename> [target-database]
#
# <filename>        object name in the backups bucket, e.g.
#                    nev_climate_data_20260902T120000Z.sql.gz
#                    ("rclone lsf backupminio:nev-climate-data-backups" lists them)
# [target-database] defaults to POSTGRES_DB (the real database!) - pass an
#                    explicit throwaway name to verify a restore without
#                    touching real data, e.g.:
#                    docker compose exec database createdb -U "$POSTGRES_USER" restore_check
#                    docker compose run --rm db-backup /usr/local/bin/restore.sh <filename> restore_check
set -uo pipefail

FILENAME="${1:?Usage: restore.sh <backup-filename> [target-database]}"
BACKUP_BUCKET="${BACKUP_BUCKET:-nev-climate-data-backups}"

export PGHOST="${PGHOST:-database}"
export PGUSER="${POSTGRES_USER:?POSTGRES_USER is required}"
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD is required}"
TARGET_DB="${2:-${POSTGRES_DB:?POSTGRES_DB is required}}"

# See backup.sh for why rclone (not `mc`, no longer installable).
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

tmpfile="/tmp/${FILENAME}"
log "Téléchargement de ${FILENAME} depuis backupminio:${BACKUP_BUCKET}..."
if ! rclone copyto "backupminio:${BACKUP_BUCKET}/${FILENAME}" "${tmpfile}" >/dev/null 2>&1; then
  log "ERREUR : impossible de télécharger ${FILENAME} (nom incorrect ? \"rclone lsf backupminio:${BACKUP_BUCKET}\" pour lister les sauvegardes disponibles)"
  exit 1
fi

log "Restauration dans la base '${TARGET_DB}'..."
if gunzip -c "${tmpfile}" | psql -v ON_ERROR_STOP=1 -d "${TARGET_DB}" >/tmp/restore_output.log 2>&1; then
  log "Restauration terminée avec succès dans '${TARGET_DB}'."
  rm -f "${tmpfile}"
  exit 0
else
  log "ERREUR : la restauration a échoué - voir /tmp/restore_output.log dans ce conteneur."
  cat /tmp/restore_output.log
  rm -f "${tmpfile}"
  exit 1
fi

#!/bin/sh
# Audit post-C3 2026-10-02/05 (P-10) : bootstrap idempotent de Garage
# (remplacant de MinIO). Verifie via l'API admin HTTP (seul moyen fiable -
# "garage node id" exige un fichier de config local absent de ce conteneur,
# puisque l'identite du noeud est derivee de sa propre cle, pas accessible
# a distance) si le layout/la cle/le bucket existent deja avant de les
# (re)creer, pour supporter un redemarrage du service sans erreur ni
# duplication.
set -eu

GARAGE_HOST="${GARAGE_HOST:-garage}"
GARAGE_ADMIN_PORT="${GARAGE_ADMIN_PORT:-3903}"
GARAGE_RPC_PORT="${GARAGE_RPC_PORT:-3901}"
GARAGE_ZONE="${GARAGE_ZONE:-dc1}"
GARAGE_CAPACITY="${GARAGE_CAPACITY:-100GB}"
GARAGE_BUCKET_NAME="${GARAGE_BUCKET_NAME:-nev-climate-data}"
GARAGE_KEY_NAME="${GARAGE_KEY_NAME:-nev-climate-data}"
CREDENTIALS_FILE="${CREDENTIALS_FILE:-/credentials/garage.env}"

admin_url="http://${GARAGE_HOST}:${GARAGE_ADMIN_PORT}/v1/status"

echo "Attente de la disponibilite du serveur Garage..."
attempt=0
until wget -q -O /tmp/status.json --header="Authorization: Bearer ${GARAGE_ADMIN_TOKEN}" "$admin_url"; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Garage n'a pas demarre a temps (30 tentatives)." >&2
        exit 1
    fi
    sleep 2
done

NODE_ID=$(jq -r '.node' /tmp/status.json)
echo "Noeud Garage pret : ${NODE_ID}"

garage() {
    /usr/local/bin/garage --rpc-host "${NODE_ID}@${GARAGE_HOST}:${GARAGE_RPC_PORT}" "$@"
}

HAS_ROLE=$(jq -r --arg id "$NODE_ID" '.nodes[] | select(.id == $id) | .role // empty' /tmp/status.json)
if [ -z "$HAS_ROLE" ]; then
    echo "Attribution du layout (zone=${GARAGE_ZONE}, capacite=${GARAGE_CAPACITY})..."
    garage layout assign -z "$GARAGE_ZONE" -c "$GARAGE_CAPACITY" "$NODE_ID"
    CURRENT_VERSION=$(jq -r '.layoutVersion' /tmp/status.json)
    NEW_VERSION=$((CURRENT_VERSION + 1))
    garage layout apply --version "$NEW_VERSION"
else
    echo "Layout deja attribue, aucune action necessaire."
fi

if garage key list | grep -q "  ${GARAGE_KEY_NAME}\$"; then
    echo "Cle '${GARAGE_KEY_NAME}' deja existante, recuperation des identifiants."
else
    echo "Creation de la cle '${GARAGE_KEY_NAME}'..."
    garage key create "$GARAGE_KEY_NAME"
fi
KEY_INFO=$(garage key info "$GARAGE_KEY_NAME" --show-secret)
ACCESS_KEY=$(echo "$KEY_INFO" | grep '^Key ID:' | sed 's/^Key ID:[[:space:]]*//')
SECRET_KEY=$(echo "$KEY_INFO" | grep '^Secret key:' | sed 's/^Secret key:[[:space:]]*//')

# db-backup (docker/db-backup/backup.sh) cree son propre bucket de
# sauvegardes ("nev-climate-data-backups", distinct du bucket data lake
# cree plus bas) via "rclone mkdir" au premier lancement, exactement comme
# MINIO_ROOT_USER le pouvait avec MinIO - confirme en direct (403
# AccessDenied sans ce flag, un bucket cree via S3 devient automatiquement
# RW pour la cle qui l'a cree, pas besoin d'un "bucket allow" en plus).
garage key allow --create-bucket "$GARAGE_KEY_NAME"

if garage bucket list | grep -q "  ${GARAGE_BUCKET_NAME}[[:space:]]"; then
    echo "Bucket '${GARAGE_BUCKET_NAME}' deja existant."
else
    echo "Creation du bucket '${GARAGE_BUCKET_NAME}'..."
    garage bucket create "$GARAGE_BUCKET_NAME"
fi

echo "Attribution des permissions lecture/ecriture sur la cle..."
garage bucket allow --read --write "$GARAGE_BUCKET_NAME" --key "$GARAGE_KEY_NAME"

mkdir -p "$(dirname "$CREDENTIALS_FILE")"
cat > "$CREDENTIALS_FILE" <<EOF
GARAGE_ACCESS_KEY=${ACCESS_KEY}
GARAGE_SECRET_KEY=${SECRET_KEY}
EOF

echo "Bootstrap Garage termine. Identifiants ecrits dans ${CREDENTIALS_FILE}."

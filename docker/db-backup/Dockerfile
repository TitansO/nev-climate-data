# A3.6: dedicated, minimal image for automated database backups - not the
# main "backend" image (postgres:16-alpine already ships pg_dump/psql,
# nothing PHP-related belongs here; keeps the backend image lean and
# never bundles backup tooling into a container that also serves live
# traffic).
FROM postgres:16-alpine

# Audit pre-production 2026-09-23 (P2, decouvert en verifiant le fix
# ci-dessous) : le client "mc" officiel de MinIO n'est plus installable -
# https://dl.min.io/client/mc/release/linux-amd64/mc renvoie HTTP 410 Gone
# (le projet mc a ete archive par MinIO). L'ancien "curl -sSL -o" sans
# "-f" ne detectait pas l'echec : il ecrivait silencieusement la page
# d'erreur HTML a la place du binaire, "chmod +x" reussissait dessus sans
# probleme, et l'image se construisait "avec succes" - l'echec ne se
# manifestait qu'au runtime, au premier envoi vers MinIO. rclone (paquet
# Alpine officiel, aucune dependance a une URL externe qui peut casser
# silencieusement) le remplace : meme protocole S3 compatible MinIO, cf.
# backup.sh/restore.sh pour la config via variables RCLONE_CONFIG_*.
RUN apk add --no-cache bash rclone

COPY docker/db-backup/backup.sh docker/db-backup/restore.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/restore.sh

# Audit pre-production 2026-09-23 (P2) : tournait root faute d'un USER
# explicite, avec un acces reel aux identifiants MinIO en environnement.
# Reutilise le compte "postgres" deja cree par l'image de base (HOME=
# /var/lib/postgresql, inscriptible) plutot que d'en creer un nouveau -
# rclone n'ecrit d'ailleurs aucun fichier de config (RCLONE_CONFIG=
# /dev/null dans backup.sh/restore.sh, tout passe par des variables
# d'environnement), donc ce HOME inscriptible n'est meme plus requis par
# l'outil de sauvegarde lui-meme, seulement par convention.
USER postgres

# CMD (pas ENTRYPOINT) : audit pre-production 2026-09-23 (P2, decouvert en
# testant reellement le scenario de restauration, jamais exerce
# auparavant) - avec ENTRYPOINT, "docker compose run --rm db-backup
# /usr/local/bin/restore.sh <fichier>" (l'usage documente en tete de
# restore.sh) ajoutait silencieusement ces arguments a backup.sh au lieu
# de lancer restore.sh, qui ne s'executait donc jamais. CMD permet a
# "docker compose up -d" de lancer la boucle de sauvegarde par defaut
# tout en laissant un "docker compose run" avec une commande explicite la
# remplacer entierement, comme le veut l'usage documente.
CMD ["/usr/local/bin/backup.sh"]

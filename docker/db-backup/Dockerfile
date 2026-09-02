# A3.6: dedicated, minimal image for automated database backups - not the
# main "backend" image (postgres:16-alpine already ships pg_dump/psql,
# nothing PHP-related belongs here; keeps the backend image lean and
# never bundles backup tooling into a container that also serves live
# traffic).
FROM postgres:16-alpine

RUN apk add --no-cache bash curl \
    && curl -sSL https://dl.min.io/client/mc/release/linux-amd64/mc -o /usr/local/bin/mc \
    && chmod +x /usr/local/bin/mc

COPY docker/db-backup/backup.sh docker/db-backup/restore.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/restore.sh

ENTRYPOINT ["/usr/local/bin/backup.sh"]

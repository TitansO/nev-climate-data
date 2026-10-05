# Audit post-C3 2026-10-02/05 (P-10) : l'image dxflrs/garage:v1.0.1 est
# distroless (aucun shell, confirme en direct - "exec: sleep: not found")
# - inutilisable pour un script de bootstrap. Ce Dockerfile recupere
# uniquement le binaire /garage de cette image dans une base Alpine (qui a
# un shell), pour pouvoir executer docker/garage/init.sh.
FROM alpine:3.20
RUN apk add --no-cache jq
COPY --from=dxflrs/garage:v1.0.1 /garage /usr/local/bin/garage
COPY docker/garage/init.sh /init.sh
RUN chmod +x /init.sh
ENTRYPOINT ["/init.sh"]

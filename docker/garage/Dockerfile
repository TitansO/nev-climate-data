# Audit post-C3 2026-10-02/05 (P-10) : remplace MinIO (image Docker plus
# telechargeable anonymement). Dockerfile minimal, meme motif deja etabli
# dans ce projet pour un fichier de config unique (voir docker/backend/
# Dockerfile::COPY vhost.conf/security.conf) - jamais de bind-mount runtime
# pour un seul fichier.
FROM dxflrs/garage:v1.0.1
COPY docker/garage/garage.toml /etc/garage.toml

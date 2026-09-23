FROM python:3.12-slim

WORKDIR /app

COPY ai/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# HF_HOME fixe (sous /app, deja WORKDIR) plutot que le defaut ~/.cache/
# huggingface : le defaut resout au HOME de l'utilisateur actif, qui
# change entre le build (root) et le runtime (appuser, plus bas) - sans
# ca, le modele est mis en cache sous /root/.cache et appuser ne peut
# plus y acceder du tout au runtime (voir le commentaire HF_HUB_OFFLINE
# ci-dessous pour l'incident reel que ca a cause).
ENV HF_HOME=/app/.cache/huggingface

# Bakes the local embedding model's weights into the image at build time
# (~80MB) - Gemini's free-tier embedding quota is exhausted quickly under
# real RAG traffic (one call per chunk at index time, one per question at
# query time), so embeddings run locally instead (ai/local_embeddings.py),
# with no external quota at all. Downloading at build time instead of on
# first request avoids a slow/network-dependent cold start.
RUN python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('all-MiniLM-L6-v2')"

# HF_HUB_OFFLINE=1 : le modele est deja bakes ci-dessus, le service n'a
# donc plus jamais besoin du reseau pour le charger - sans ce flag,
# sentence-transformers/transformers tentent quand meme une verification
# aupres de Hugging Face Hub (find_adapter_config_file) a chaque chargement,
# qui essaie d'ecrire dans le cache si le resultat n'y est pas deja exact.
# Regression reelle constatee en verifiant ce Dockerfile (audit
# pre-production 2026-09-23, P2) : cette verification echouait avec
# "PermissionError at /home/appuser" (appuser n'a pas de HOME - voir
# --no-create-home plus bas) et faisait boucler le conteneur en
# redemarrage permanent (75 redemarrages observes) - le service ai etait
# indisponible depuis le commit precedent, la verification faite a
# l'epoque (whoami) n'avait teste que l'identite non-root, jamais que le
# service demarrait reellement.
ENV HF_HUB_OFFLINE=1

COPY ai/ ./ai/

ENV PYTHONPATH=/app

# Audit pre-production 2026-09-23 (P2) : tournait root faute d'un USER
# explicite (comme les 3 autres Dockerfiles du projet). Port 8000 (non
# privilegie, contrairement au 80 d'Apache dans docker/backend/Dockerfile)
# rend ce cas simple : aucun binding privilegie a preserver. chown avant
# USER : appuser doit pouvoir lire /app/.cache/huggingface (cree par root
# ci-dessus) en plus du code applicatif.
RUN useradd --system --no-create-home --shell /usr/sbin/nologin appuser \
    && chown -R appuser:appuser /app
USER appuser

CMD ["uvicorn", "ai.main:app", "--host", "0.0.0.0", "--port", "8000"]

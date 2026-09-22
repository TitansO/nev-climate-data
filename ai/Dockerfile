FROM python:3.12-slim

WORKDIR /app

COPY ai/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Bakes the local embedding model's weights into the image at build time
# (~80MB) - Gemini's free-tier embedding quota is exhausted quickly under
# real RAG traffic (one call per chunk at index time, one per question at
# query time), so embeddings run locally instead (ai/local_embeddings.py),
# with no external quota at all. Downloading at build time instead of on
# first request avoids a slow/network-dependent cold start.
RUN python -c "from sentence_transformers import SentenceTransformer; SentenceTransformer('all-MiniLM-L6-v2')"

COPY ai/ ./ai/

ENV PYTHONPATH=/app

CMD ["uvicorn", "ai.main:app", "--host", "0.0.0.0", "--port", "8000"]

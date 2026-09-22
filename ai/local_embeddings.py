"""Local, zero-quota embedding backend - replaces Gemini's embed_content()
as the RAG corpus's embedding source. Gemini's free tier hit its rate limit
mid-reindex (see gemini_client.py's RATE_LIMIT_RETRY_DELAY_SECONDS comment):
embeddings are called far more often than chat generation (once per corpus
chunk at index time, once per question at query time), so this is the piece
that actually needs to run with no external quota. Chat generation
(gemini_client.ask()/classify()) stays on Gemini - one call per real
question/classification, much lighter traffic that the free tier handles
fine.

"all-MiniLM-L6-v2": a well-known, CPU-fast, 384-dimension sentence
embedding model, small enough to run inside this container with no GPU -
its weights are baked into the Docker image at build time (see
ai/Dockerfile) rather than downloaded on first request.
"""
from __future__ import annotations

from sentence_transformers import SentenceTransformer

MODEL_NAME = "all-MiniLM-L6-v2"
EMBEDDING_DIMENSIONS = 384

# Loaded once at import time - a fresh SentenceTransformer() per call would
# reload the model's weights from disk on every single embed() call, the
# real cost this module exists to avoid.
_model = SentenceTransformer(MODEL_NAME)


def embed(text: str) -> list[float]:
    return _model.encode(text, normalize_embeddings=True).tolist()

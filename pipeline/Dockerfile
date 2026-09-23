FROM python:3.12-slim

WORKDIR /app

COPY pipeline/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY pipeline/ ./pipeline/

ENV PYTHONPATH=/app

# Audit pre-production 2026-09-23 (P2) : tournait root faute d'un USER
# explicite. Image partagee par funding-validator/emission-validator
# (consommateurs Kafka long-running, aucune ecriture locale hors /tmp).
RUN useradd --system --no-create-home --shell /usr/sbin/nologin appuser
USER appuser

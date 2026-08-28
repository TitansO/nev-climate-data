FROM python:3.12-slim

WORKDIR /app

COPY pipeline/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY pipeline/ ./pipeline/

ENV PYTHONPATH=/app

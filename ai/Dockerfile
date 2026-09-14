FROM python:3.12-slim

WORKDIR /app

COPY ai/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY ai/ ./ai/

ENV PYTHONPATH=/app

CMD ["uvicorn", "ai.main:app", "--host", "0.0.0.0", "--port", "8000"]

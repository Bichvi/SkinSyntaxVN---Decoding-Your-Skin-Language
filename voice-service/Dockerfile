FROM python:3.11-slim

WORKDIR /app

# Install system audio & C build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    libsndfile1 \
    ffmpeg \
    curl \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .

RUN pip install --no-cache-dir -r requirements.txt

COPY . .

ENV TTS_PORT=5002
ENV PRECISION=int8
ENV NUM_THREADS=4

# Cache model trong volume (model_loader.py chỉ dùng D:\hf_cache khi chưa có HF_HOME).
# Tắt symlink: onnxruntime >= 1.30 từ chối file .data là symlink trỏ ra thư mục blobs/.
ENV HF_HOME=/root/.cache/huggingface
ENV HF_HUB_DISABLE_SYMLINKS=1

EXPOSE 5002

HEALTHCHECK --interval=30s --timeout=10s --retries=3 \
  CMD curl -f http://localhost:5002/api/health || exit 1

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "5002"]

import os
from urllib.parse import urlsplit, urlunsplit
from dotenv import load_dotenv

load_dotenv()

def _health_url(service_url: str) -> str:
    """Suy ra URL /api/health cung host:port từ URL endpoint của một service."""
    parts = urlsplit(service_url)
    return urlunsplit((parts.scheme, parts.netloc, "/api/health", "", ""))


class Config:
    LIVEKIT_URL = os.getenv("LIVEKIT_URL", "ws://localhost:7880")
    LIVEKIT_API_KEY = os.getenv("LIVEKIT_API_KEY", "devkey")
    LIVEKIT_API_SECRET = os.getenv("LIVEKIT_API_SECRET", "secret")
    
    AI_SERVICE_URL = os.getenv("AI_SERVICE_URL", "http://localhost:5001/api/chat/auto")
    TTS_SERVICE_URL = os.getenv("TTS_SERVICE_URL", "http://localhost:5002/api/tts/stream")
    REDIS_URL = os.getenv("REDIS_URL", "redis://localhost:6379/0")
    AI_HEALTH_URL = os.getenv("AI_HEALTH_URL") or _health_url(AI_SERVICE_URL)
    TTS_HEALTH_URL = os.getenv("TTS_HEALTH_URL") or _health_url(TTS_SERVICE_URL)

    # Chờ phụ thuộc (ai-service, voice-service) sẵn sàng trước khi gửi bất kỳ chat nào.
    STARTUP_WAIT_SECONDS = float(os.getenv("STARTUP_WAIT_SECONDS", "120"))
    STARTUP_BACKOFF_INITIAL = float(os.getenv("STARTUP_BACKOFF_INITIAL", "2"))
    STARTUP_BACKOFF_MAX = float(os.getenv("STARTUP_BACKOFF_MAX", "30"))
    
    COOLDOWN_SECONDS = float(os.getenv("COOLDOWN_SECONDS", "3.0"))
    MAX_QUEUE_SIZE = int(os.getenv("MAX_QUEUE_SIZE", "5"))
    TTL_SECONDS = int(os.getenv("TTL_SECONDS", "15"))

config = Config()

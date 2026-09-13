import os
from dotenv import load_dotenv

load_dotenv()

class Config:
    LIVEKIT_URL = os.getenv("LIVEKIT_URL", "ws://localhost:7880")
    LIVEKIT_API_KEY = os.getenv("LIVEKIT_API_KEY", "devkey")
    LIVEKIT_API_SECRET = os.getenv("LIVEKIT_API_SECRET", "secret")
    
    AI_SERVICE_URL = os.getenv("AI_SERVICE_URL", "http://localhost:5001/api/chat/auto")
    TTS_SERVICE_URL = os.getenv("TTS_SERVICE_URL", "http://localhost:5002/api/tts/stream")
    REDIS_URL = os.getenv("REDIS_URL", "redis://localhost:6379/0")
    
    COOLDOWN_SECONDS = float(os.getenv("COOLDOWN_SECONDS", "3.0"))
    MAX_QUEUE_SIZE = int(os.getenv("MAX_QUEUE_SIZE", "5"))
    TTL_SECONDS = int(os.getenv("TTL_SECONDS", "15"))

config = Config()

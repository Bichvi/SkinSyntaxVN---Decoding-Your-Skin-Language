import json
import logging
import os
from pathlib import Path

from dotenv import load_dotenv


ROOT_DIR = Path(__file__).resolve().parent.parent
load_dotenv(ROOT_DIR / ".env")

PORT = int(os.getenv("AI_IDOL_PORT", "5003"))
MONGO_URI = os.getenv("MONGO_URI", "mongodb://localhost:27017")
MONGO_DB = os.getenv("MONGO_DB", "skinsyntax")
REDIS_URL = os.getenv("AI_IDOL_REDIS_URL", os.getenv("REDIS_URL", "redis://localhost:6379/2"))
CHROMA_COLLECTION = os.getenv("AI_IDOL_CHROMA_COLLECTION", "products")
CHROMA_HOST = os.getenv("AI_IDOL_CHROMA_HOST", "localhost")
CHROMA_PORT = int(os.getenv("AI_IDOL_CHROMA_PORT", "8000"))
TIMEZONE = os.getenv("AI_IDOL_TIMEZONE", "Asia/Ho_Chi_Minh")

GROQ_API_KEY = os.getenv("GROQ_API_KEY", "")
GROQ_MODEL = os.getenv("AI_IDOL_GROQ_MODEL", "openai/gpt-oss-20b")
GROQ_FALLBACK_MODELS = [
    model.strip()
    for model in os.getenv("AI_IDOL_GROQ_FALLBACK_MODELS", "qwen/qwen3.8-27b").split(",")
    if model.strip() and model.strip() != GROQ_MODEL
]
GROQ_MAX_PROMPT_CHARS = int(os.getenv("AI_IDOL_GROQ_MAX_PROMPT_CHARS", "8000"))
GROQ_RETRY_PROMPT_CHARS = int(os.getenv("AI_IDOL_GROQ_RETRY_PROMPT_CHARS", "5000"))
GROQ_MAX_COMPLETION_TOKENS = int(os.getenv("AI_IDOL_GROQ_MAX_COMPLETION_TOKENS", "700"))
JINA_API_KEY = os.getenv("JINA_API_KEY", "")  # Optional only.

AGENT_ENABLED = os.getenv("AI_IDOL_AGENT_ENABLED", "true").strip().lower() in {"1", "true", "yes", "on"}
AGENT_ALLOW_AUTO_PUBLISH = os.getenv("AI_IDOL_AGENT_ALLOW_AUTO_PUBLISH", "false").strip().lower() in {
    "1", "true", "yes", "on"
}
SCRIPT_COUNCIL_ENABLED = os.getenv("AI_IDOL_SCRIPT_COUNCIL_ENABLED", "true").strip().lower() in {
    "1", "true", "yes", "on"
}
SCRIPT_COUNCIL_URL = os.getenv(
    "AI_IDOL_SCRIPT_COUNCIL_URL", "http://ai-idol-agent-service:5013"
).rstrip("/")
SCRIPT_COUNCIL_TIMEOUT = int(os.getenv("AI_IDOL_SCRIPT_COUNCIL_TIMEOUT", "240"))
INTERNAL_TOKEN = os.getenv("AI_IDOL_INTERNAL_TOKEN", "").strip()

AVATAR_PROVIDER = os.getenv("AVATAR_PROVIDER", "mock").lower()
AVATAR_SERVICE_URL = os.getenv("AI_IDOL_AVATAR_SERVICE_URL", "http://ai-idol-avatar:7861").rstrip("/")
AVATAR_SERVICE_TIMEOUT = int(os.getenv("AI_IDOL_AVATAR_SERVICE_TIMEOUT", "14400"))
TTS_PROVIDER = os.getenv("AI_IDOL_TTS_PROVIDER", "piper").lower()
AUDIO_PIPELINE_VERSION = "2"
PIPER_MODEL_PATH = os.getenv("AI_IDOL_PIPER_MODEL_PATH", "/opt/piper/vi_VN-vais1000-medium.onnx")
PIPER_CONFIG_PATH = os.getenv("AI_IDOL_PIPER_CONFIG_PATH", f"{PIPER_MODEL_PATH}.json")

BASE_DIR = Path(__file__).resolve().parent
STORAGE_DIR = Path(os.getenv("AI_IDOL_STORAGE_DIR", str(BASE_DIR / "storage")))
AUDIO_DIR = STORAGE_DIR / "audio"
AVATARS_DIR = STORAGE_DIR / "avatars"
BACKGROUNDS_DIR = STORAGE_DIR / "backgrounds"
OUTPUT_DIR = STORAGE_DIR / "output"
PROGRAM_DIR = STORAGE_DIR / "programs"
TEMP_DIR = STORAGE_DIR / "tmp"
for folder in (STORAGE_DIR, AUDIO_DIR, AVATARS_DIR, BACKGROUNDS_DIR, OUTPUT_DIR, PROGRAM_DIR, TEMP_DIR):
    folder.mkdir(parents=True, exist_ok=True)

CAMPAIGN_MAX_PRODUCTS = int(os.getenv("AI_IDOL_MAX_PRODUCTS", "100"))
MIN_LEAD_MINUTES = int(os.getenv("AI_IDOL_MIN_LEAD_MINUTES", "5"))
ESTIMATED_MINUTES_PER_PRODUCT = float(os.getenv("AI_IDOL_ESTIMATED_MINUTES_PER_PRODUCT", "8"))
DISPATCH_INTERVAL_SECONDS = float(os.getenv("AI_IDOL_DISPATCH_INTERVAL_SECONDS", "5"))
BROADCAST_MAX_RETRIES = int(os.getenv("AI_IDOL_BROADCAST_MAX_RETRIES", "3"))
BROADCAST_RETRY_SECONDS = int(os.getenv("AI_IDOL_BROADCAST_RETRY_SECONDS", "5"))


def _load_rtmp_targets() -> dict[str, str]:
    try:
        targets = json.loads(os.getenv("AI_IDOL_RTMP_TARGETS_JSON", "{}") or "{}")
    except json.JSONDecodeError:
        targets = {}
    if not isinstance(targets, dict):
        targets = {}
    for platform, url_name, key_name in (
        ("youtube", "YOUTUBE_RTMP_URL", "YOUTUBE_STREAM_KEY"),
        ("facebook", "FACEBOOK_RTMP_URL", "FACEBOOK_STREAM_KEY"),
    ):
        url, key = os.getenv(url_name, "").strip(), os.getenv(key_name, "").strip()
        if url and key:
            targets.setdefault(platform, f"{url.rstrip('/')}/{key}")
    return {str(key).lower(): str(value) for key, value in targets.items() if value}


RTMP_TARGETS = _load_rtmp_targets()

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
logger = logging.getLogger("AI-IDOL-SERVICE")

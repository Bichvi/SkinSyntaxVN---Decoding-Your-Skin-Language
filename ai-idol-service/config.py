import json
import logging
import os
import re
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
AUDIO_PIPELINE_VERSION = "3"
PIPER_MODEL_PATH = os.getenv("AI_IDOL_PIPER_MODEL_PATH", "/opt/piper/vi_VN-vais1000-medium.onnx")
PIPER_CONFIG_PATH = os.getenv("AI_IDOL_PIPER_CONFIG_PATH", f"{PIPER_MODEL_PATH}.json")
# Breathing room: Piper's silence after each sentence, plus a varied pause between script sections.
PIPER_SENTENCE_SILENCE = float(os.getenv("AI_IDOL_PIPER_SENTENCE_SILENCE", "0.2"))
SECTION_PAUSE_SECONDS = (
    float(os.getenv("AI_IDOL_SECTION_PAUSE_MIN", "0.35")),
    float(os.getenv("AI_IDOL_SECTION_PAUSE_MAX", "0.8")),
)

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

# Final audio mix: voice loudness normalization plus an optional ducked music bed.
# Music lives outside STORAGE_DIR because storage is a runtime Docker volume.
AUDIO_MIX_ENABLED = os.getenv("AI_IDOL_AUDIO_MIX_ENABLED", "true").strip().lower() in {"1", "true", "yes", "on"}
MUSIC_DIR = Path(os.getenv("AI_IDOL_MUSIC_DIR", str(BASE_DIR / "assets" / "music")))
# Bed loudness in music-only gaps. The voice-keyed compressor dips it under speech;
# a lower ratio keeps the music audible while Syna talks.
MUSIC_BED_LUFS = float(os.getenv("AI_IDOL_MUSIC_BED_LUFS", "-19"))
MUSIC_DUCK_RATIO = float(os.getenv("AI_IDOL_MUSIC_DUCK_RATIO", "3"))


def _seconds_range(name: str, default: str) -> tuple[float, float]:
    """Parse "2-3" (random per job) or "2.5" (fixed) into a non-negative (low, high)."""
    raw = os.getenv(name, default).replace(" ", "")
    low, _, high = raw.partition("-")
    low_value = max(0.0, float(low))
    high_value = max(low_value, float(high or low))
    return low_value, high_value


# Music-only lead-in before the first word and tail after the last one.
MUSIC_INTRO_SECONDS = _seconds_range("AI_IDOL_MUSIC_INTRO_SECONDS", "2-3")
MUSIC_OUTRO_SECONDS = _seconds_range("AI_IDOL_MUSIC_OUTRO_SECONDS", "2-3")
AUDIO_TARGET_LUFS = float(os.getenv("AI_IDOL_AUDIO_TARGET_LUFS", "-14"))
# Intro/outro cards (product photo, name, price, call to action) during the music lead-in/tail.
TITLE_CARDS_ENABLED = os.getenv("AI_IDOL_TITLE_CARDS_ENABLED", "true").strip().lower() in {"1", "true", "yes", "on"}
CARD_FONT_BOLD = os.getenv("AI_IDOL_CARD_FONT_BOLD", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf")
CARD_FONT_REGULAR = os.getenv("AI_IDOL_CARD_FONT_REGULAR", "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")

CAMPAIGN_MAX_PRODUCTS = int(os.getenv("AI_IDOL_MAX_PRODUCTS", "100"))
MIN_LEAD_MINUTES = int(os.getenv("AI_IDOL_MIN_LEAD_MINUTES", "5"))
ESTIMATED_MINUTES_PER_PRODUCT = float(os.getenv("AI_IDOL_ESTIMATED_MINUTES_PER_PRODUCT", "8"))
DISPATCH_INTERVAL_SECONDS = float(os.getenv("AI_IDOL_DISPATCH_INTERVAL_SECONDS", "5"))
BROADCAST_MAX_RETRIES = int(os.getenv("AI_IDOL_BROADCAST_MAX_RETRIES", "3"))
BROADCAST_RETRY_SECONDS = int(os.getenv("AI_IDOL_BROADCAST_RETRY_SECONDS", "5"))
BROADCAST_LATE_GRACE_MINUTES = int(os.getenv("AI_IDOL_BROADCAST_LATE_GRACE_MINUTES", "30"))
RENDER_WAIT_CUTOFF_MINUTES = int(os.getenv("AI_IDOL_RENDER_WAIT_CUTOFF_MINUTES", "10"))
STALE_JOB_MINUTES = int(os.getenv("AI_IDOL_STALE_JOB_MINUTES", "45"))
STALE_STARTING_MINUTES = int(os.getenv("AI_IDOL_STALE_STARTING_MINUTES", "3"))
STALE_ASSEMBLING_MINUTES = int(os.getenv("AI_IDOL_STALE_ASSEMBLING_MINUTES", "60"))
TRACKING_STARTED_ON = os.getenv("AI_IDOL_TRACKING_STARTED_ON", "").strip() or None  # YYYY-MM-DD local, ngày đầu đủ tracking
PRODUCT_EVENTS_RETENTION_DAYS = int(os.getenv("AI_IDOL_PRODUCT_EVENTS_RETENTION_DAYS", "120"))


def _positive_int_env(name: str, default: int) -> int:
    """Integer >= 1 from the environment; a malformed or too small value falls back to the default."""
    raw = os.getenv(name, "").strip()
    if not raw:
        return default
    try:
        value = int(raw)
    except ValueError:
        value = 0
    if value < 1:
        logging.getLogger("AI-IDOL-SERVICE").warning("%s không hợp lệ (cần số nguyên >= 1); dùng mặc định %s", name, default)
        return default
    return value


_DEFAULT_AUTOPILOT_SLOTS = "12:00,20:00"


def _autopilot_slots_env() -> str:
    """Comma separated HH:MM prime-time slots; a malformed list falls back to the default."""
    raw = os.getenv("AI_IDOL_AUTOPILOT_SLOTS", "").strip()
    if not raw:
        return _DEFAULT_AUTOPILOT_SLOTS
    parts = [part.strip() for part in raw.split(",")]
    if all(re.fullmatch(r"(?:[01]?[0-9]|2[0-3]):[0-5][0-9]", part) for part in parts):
        return ",".join(parts)
    logging.getLogger("AI-IDOL-SERVICE").warning("AI_IDOL_AUTOPILOT_SLOTS không hợp lệ (cần HH:MM,HH:MM); dùng mặc định %s", _DEFAULT_AUTOPILOT_SLOTS)
    return _DEFAULT_AUTOPILOT_SLOTS


# Autopilot (Studio "Tự động"): product count when the prompt gives none, its cap, and prime-time start slots.
AUTOPILOT_DEFAULT_PRODUCTS = _positive_int_env("AI_IDOL_AUTOPILOT_DEFAULT_PRODUCTS", 5)
AUTOPILOT_MAX_PRODUCTS = _positive_int_env("AI_IDOL_AUTOPILOT_MAX_PRODUCTS", 10)
AUTOPILOT_SLOTS = _autopilot_slots_env()


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

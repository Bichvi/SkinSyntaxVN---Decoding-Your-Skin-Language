import os

TOKEN_SECRET = os.getenv("CHAT_AGENT_TOKEN_SECRET", "").strip()
INTERNAL_SECRET = os.getenv("CHAT_AGENT_INTERNAL_SECRET", "").strip()
PHP_INTERNAL_URL = os.getenv("CHAT_AGENT_PHP_URL", "http://127.0.0.1/index.php").strip()
AGENT_V2 = os.getenv("SYNA_AGENT_V2", "") == "1"
MAX_STEPS = 8
TURN_TIMEOUT_S = 45
REDIS_URL = os.getenv("REDIS_URL", "").strip()
AGENT_WARMUP = os.getenv("SYNA_AGENT_WARMUP", "1") != "0"

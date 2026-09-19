"""Runtime configuration for the recommendation service."""

from __future__ import annotations

import os
from pathlib import Path

SERVICE_DIR = Path(__file__).resolve().parent
ROOT_DIR = SERVICE_DIR.parent

HOST = os.getenv("RECOMMENDATION_HOST", "0.0.0.0")
PORT = int(os.getenv("RECOMMENDATION_PORT", "5002"))
REQUEST_TIMEOUT_SECONDS = float(os.getenv("RECOMMENDATION_REQUEST_TIMEOUT", "1.35"))
TOP_K = max(5, int(os.getenv("RECOMMENDATION_TOP_K", "24")))
MAX_ROUTINE_STEPS = max(1, int(os.getenv("RECOMMENDATION_MAX_STEPS", "4")))

MONGO_URI = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
MONGO_DB_NAME = os.getenv("MONGO_DB_NAME", os.getenv("MONGO_DB", "skinsyntax"))
MONGO_COLLECTION = os.getenv("RECOMMENDATION_MONGO_COLLECTION", "san_pham")

MYSQL_HOST = os.getenv("MYSQL_HOST", os.getenv("DB_HOST", "127.0.0.1"))
MYSQL_PORT = int(os.getenv("MYSQL_PORT", os.getenv("DB_PORT", "3306")))
MYSQL_DATABASE = os.getenv("MYSQL_DATABASE", os.getenv("DB_NAME", "skinsyntax"))
MYSQL_USER = os.getenv("MYSQL_USER", os.getenv("DB_USER", "root"))
MYSQL_PASSWORD = os.getenv("MYSQL_PASSWORD", os.getenv("DB_PASSWORD", ""))
MYSQL_CONNECT_TIMEOUT = float(os.getenv("MYSQL_CONNECT_TIMEOUT", "0.35"))
MYSQL_READ_TIMEOUT = float(os.getenv("MYSQL_READ_TIMEOUT", "0.8"))
MYSQL_PRODUCTS_TABLE = os.getenv("MYSQL_PRODUCTS_TABLE", "san_pham")

CHROMA_DIR = Path(
    os.getenv("RECOMMENDATION_CHROMA_DIR", str(ROOT_DIR / "database" / "chroma_db"))
).resolve()
CHROMA_COLLECTION = os.getenv("RECOMMENDATION_CHROMA_COLLECTION", "products")
INDEX_DIR = Path(
    os.getenv("RECOMMENDATION_INDEX_DIR", str(ROOT_DIR / "database" / "recommendation_index"))
).resolve()

RESPONSE_CACHE_TTL = max(0, int(os.getenv("RECOMMENDATION_CACHE_TTL", "120")))
PRODUCT_REFRESH_TTL = max(1, int(os.getenv("RECOMMENDATION_PRODUCT_TTL", "300")))
RRF_K = int(os.getenv("RECOMMENDATION_RRF_K", "60"))
RRF_ALPHA = min(1.0, max(0.0, float(os.getenv("RECOMMENDATION_RRF_ALPHA", "0.5"))))
RERANKER_MODEL = "cross-encoder/mmarco-mMiniLMv2-L12-H384-v1"

UNAVAILABLE_MESSAGE = "Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau."

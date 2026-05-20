"""
LlamaIndex Setup for SkinSyntax (Google Gemini)
File: ai-service-flask/config.py
"""
import os
from typing import Optional

from dotenv import load_dotenv

# Load environment variables
load_dotenv('.env')

class LlamaIndexConfig:
    """LlamaIndex Configuration"""

    @staticmethod
    def _parse_google_api_keys() -> list[str]:
        raw_values = []

        multi_value = os.getenv('GOOGLE_API_KEYS', '')
        if multi_value:
            raw_values.extend(multi_value.split(','))

        single_value = os.getenv('GOOGLE_API_KEY', '')
        if single_value:
            raw_values.append(single_value)

        normalized = []
        seen = set()
        for item in raw_values:
            key = item.strip()
            if not key or key in seen:
                continue
            seen.add(key)
            normalized.append(key)

        return normalized
    
    # Google Gemini API Key
    GOOGLE_API_KEYS = _parse_google_api_keys.__func__()
    GOOGLE_API_KEY = GOOGLE_API_KEYS[0] if GOOGLE_API_KEYS else ''
    
    # Model Configuration
    LLAMA_MODEL = os.getenv('LLAMA_INDEX_MODEL', 'models/gemini-2.5-flash')
    EMBEDDING_MODEL = os.getenv('EMBEDDING_MODEL', os.getenv('GEMINI_EMBEDDING_MODEL', 'models/gemini-embedding-001'))
    if EMBEDDING_MODEL in {'text-embedding-001', 'models/text-embedding-001', 'embedding-001', 'models/embedding-001'}:
        EMBEDDING_MODEL = 'models/gemini-embedding-001'
    LLAMA_MODE = os.getenv('LLAMA_INDEX_MODE', 'local')
    
    # Vector Store Configuration
    VECTOR_DB_TYPE = os.getenv('VECTOR_DB_TYPE', 'chroma')
    VECTOR_DB_PATH = os.getenv('VECTOR_DB_PATH', './chroma_db')
    CHROMA_COLLECTION = os.getenv('CHROMA_COLLECTION_NAME', 'skinsyntax_products')

    # MongoDB / Hybrid Search Configuration
    # Mặc định cùng DB với website (skinsyntax); collections RAG: products_rag, user_profiles, order_history, query_cache
    # Nếu bạn tách DB AI riêng, đặt MONGODB_DB_NAME=skinsyntax_ai trong .env
    MONGODB_URI = os.getenv('MONGODB_URI', 'mongodb://localhost:27017/')
    MONGODB_DB_NAME = os.getenv('MONGODB_DB_NAME', 'skinsyntax')
    MONGODB_PRODUCTS_COLLECTION = os.getenv('MONGODB_PRODUCTS_COLLECTION', 'products_rag')
    MONGODB_USER_PROFILES_COLLECTION = os.getenv('MONGODB_USER_PROFILES_COLLECTION', 'user_profiles')
    MONGODB_ORDER_HISTORY_COLLECTION = os.getenv('MONGODB_ORDER_HISTORY_COLLECTION', 'order_history')
    MONGODB_QUERY_CACHE_COLLECTION = os.getenv('MONGODB_QUERY_CACHE_COLLECTION', 'query_cache')
    ENABLE_MONGODB_RAG = os.getenv('ENABLE_MONGODB_RAG', '1').strip().lower() in {'1', 'true', 'yes', 'on'}
    HYBRID_CANDIDATE_LIMIT = int(os.getenv('HYBRID_CANDIDATE_LIMIT', 24))
    QUERY_CACHE_SIMILARITY_THRESHOLD = float(os.getenv('QUERY_CACHE_SIMILARITY_THRESHOLD', 0.93))
    QUERY_CACHE_TTL_SECONDS = int(os.getenv('QUERY_CACHE_TTL_SECONDS', 604800))

    # Redis L1 cache (optional) — exact key; MongoDB query_cache vẫn dùng embedding tương đồng
    REDIS_URL = os.getenv('REDIS_URL', '').strip()
    REDIS_RECOMMENDATION_TTL_SECONDS = int(os.getenv('REDIS_RECOMMENDATION_TTL_SECONDS', 604800))

    # PostgreSQL source used for sync to MongoDB
    POSTGRES_HOST = os.getenv('POSTGRES_HOST', 'localhost')
    POSTGRES_DB = os.getenv('POSTGRES_DB', 'skinsyntax')
    POSTGRES_USER = os.getenv('POSTGRES_USER', 'root')
    POSTGRES_PASSWORD = os.getenv('POSTGRES_PASSWORD', '')
    
    # RAG Settings
    CHUNK_SIZE = int(os.getenv('CHUNK_SIZE', 1024))
    CHUNK_OVERLAP = int(os.getenv('CHUNK_OVERLAP', 20))
    TOP_K = int(os.getenv('TOP_K', 3))
    
    # Recommendation Model
    RECOMMENDATION_MODEL = os.getenv('RECOMMENDATION_MODEL', 'gemini-2.0-flash')
    TEMPERATURE = float(os.getenv('TEMPERATURE', 0.7))
    RECOMMENDATION_MAX_PRODUCTS = int(os.getenv('RECOMMENDATION_MAX_PRODUCTS', 5))

    @classmethod
    def gemini_model_resource(cls, model_name: Optional[str] = None) -> str:
        """API Gemini cần dạng models/... cho GenerativeModel."""
        name = (model_name or cls.RECOMMENDATION_MODEL or 'gemini-2.0-flash').strip()
        if name.startswith('models/'):
            return name
        return f'models/{name}'
    
    @classmethod
    def validate(cls):
        """Kiểm tra API key"""
        if not cls.GOOGLE_API_KEYS:
            raise ValueError("❌ GOOGLE_API_KEY hoặc GOOGLE_API_KEYS không được config! Vui lòng cập nhật .env file")
        return True

    @classmethod
    def get_google_api_keys(cls) -> list[str]:
        return list(cls.GOOGLE_API_KEYS)

if __name__ == '__main__':
    try:
        LlamaIndexConfig.validate()
        print("✅ LlamaIndex Config OK!")
        print(f"   Model: {LlamaIndexConfig.LLAMA_MODEL}")
        print(f"   Vector DB: {LlamaIndexConfig.VECTOR_DB_TYPE}")
        print(f"   Vector DB Path: {LlamaIndexConfig.VECTOR_DB_PATH}")
        print(f"   MongoDB Enabled: {LlamaIndexConfig.ENABLE_MONGODB_RAG}")
    except ValueError as e:
        print(str(e))

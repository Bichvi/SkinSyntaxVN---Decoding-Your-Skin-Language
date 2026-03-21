"""
LlamaIndex Setup for SkinSyntax (Google Gemini)
File: ai-service-flask/config.py
"""
import os
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
    LLAMA_MODE = os.getenv('LLAMA_INDEX_MODE', 'local')
    
    # Vector Store Configuration
    VECTOR_DB_TYPE = os.getenv('VECTOR_DB_TYPE', 'chroma')
    VECTOR_DB_PATH = os.getenv('VECTOR_DB_PATH', './chroma_db')
    CHROMA_COLLECTION = os.getenv('CHROMA_COLLECTION_NAME', 'skinsyntax_products')
    
    # RAG Settings
    CHUNK_SIZE = int(os.getenv('CHUNK_SIZE', 1024))
    CHUNK_OVERLAP = int(os.getenv('CHUNK_OVERLAP', 20))
    TOP_K = int(os.getenv('TOP_K', 3))
    
    # Recommendation Model
    RECOMMENDATION_MODEL = os.getenv('RECOMMENDATION_MODEL', 'gemini-2.5-flash')
    TEMPERATURE = float(os.getenv('TEMPERATURE', 0.7))
    RECOMMENDATION_MAX_PRODUCTS = int(os.getenv('RECOMMENDATION_MAX_PRODUCTS', 5))
    
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
    except ValueError as e:
        print(str(e))

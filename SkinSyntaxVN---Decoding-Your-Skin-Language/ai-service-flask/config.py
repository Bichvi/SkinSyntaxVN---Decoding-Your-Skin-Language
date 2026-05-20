"""
LlamaIndex Setup for SkinSyntax
File: ai-service-flask/config.py
"""
import os
from dotenv import load_dotenv

# Load environment variables
load_dotenv('.env')

class LlamaIndexConfig:
    """LlamaIndex Configuration"""
    
    # OpenAI API Key
    OPENAI_API_KEY = os.getenv('OPENAI_API_KEY')
    
    # Model Configuration
    LLAMA_MODEL = os.getenv('LLAMA_INDEX_MODEL', 'gpt-3.5-turbo')
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
    RECOMMENDATION_MODEL = os.getenv('RECOMMENDATION_MODEL', 'gpt-3.5-turbo')
    TEMPERATURE = float(os.getenv('TEMPERATURE', 0.7))
    
    @classmethod
    def validate(cls):
        """Kiểm tra API key"""
        if not cls.OPENAI_API_KEY or cls.OPENAI_API_KEY == 'sk-your-api-key-here':
            raise ValueError("❌ OPENAI_API_KEY không được config! Vui lòng cập nhật .env file")
        return True

if __name__ == '__main__':
    try:
        LlamaIndexConfig.validate()
        print("✅ LlamaIndex Config OK!")
        print(f"   Model: {LlamaIndexConfig.LLAMA_MODEL}")
        print(f"   Vector DB: {LlamaIndexConfig.VECTOR_DB_TYPE}")
        print(f"   Vector DB Path: {LlamaIndexConfig.VECTOR_DB_PATH}")
    except ValueError as e:
        print(str(e))

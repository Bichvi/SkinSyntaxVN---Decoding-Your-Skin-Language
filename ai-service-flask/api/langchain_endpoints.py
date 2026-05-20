"""
LangChain-based Recommendation API Endpoints
Add these routes to app.py for RAG-powered recommendations
"""
import logging
from flask import Flask, request, jsonify
from typing import Optional, Any

from rag.langchain_setup import RecommendationChainBuilder, ChatbotChainBuilder
from rag.hybrid_retriever import SkincareHybridRetriever
from rag.redis_cache import get_cache, RecommendationCache
from rag.prompt_templates import CacheKeyBuilder

logger = logging.getLogger(__name__)

# Global instances (initialize in main app.py)
_recommendation_builder: Optional[RecommendationChainBuilder] = None
_chatbot_builder: Optional[ChatbotChainBuilder] = None
_hybrid_retriever: Optional[SkincareHybridRetriever] = None
_cache: Optional[RecommendationCache] = None


def init_langchain_components(app: Flask, llm: Any, mongo_uri: str, db_name: str) -> None:
    """
    Initialize LangChain components
    
    Call this in your Flask app initialization after setting up LLM
    
    Example:
        from langchain_google_genai import ChatGoogleGenerativeAI
        llm = ChatGoogleGenerativeAI(model="gemini-2.5-flash")
        init_langchain_components(app, llm, MONGODB_URI, MONGODB_DB_NAME)
    """
    global _recommendation_builder, _chatbot_builder, _hybrid_retriever, _cache

    try:
        # Initialize cache
        redis_url = app.config.get('REDIS_URL', 'redis://localhost:6379/0')
        _cache = get_cache(redis_url)

        # Initialize hybrid retriever
        _hybrid_retriever = SkincareHybridRetriever(
            mongo_uri=mongo_uri,
            db_name=db_name,
            embedding_model=llm,  # Use LLM for embeddings
        )

        # Initialize recommendation builder
        _recommendation_builder = RecommendationChainBuilder(
            llm=llm,
            retriever=_hybrid_retriever,
            cache=_cache,
        )

        # Initialize chatbot builder
        _chatbot_builder = ChatbotChainBuilder(llm=llm)

        logger.info("[Init] LangChain components initialized successfully")
    except Exception as e:
        logger.error(f"[Init] Failed to initialize LangChain components: {e}")
        raise


# ============================================
# NEW API ENDPOINTS FOR LANGCHAIN RAG
# ============================================

def register_langchain_routes(app: Flask) -> None:
    """Register all LangChain-based recommendation endpoints"""

    @app.post('/api/recommend/langchain-rag')
    def recommend_langchain_rag():
        """
        Main RAG-based recommendation endpoint using LangChain
        
        Expected POST body:
        {
            "user_profile": {
                "gioi_tinh": "Nam",
                "nam_sinh": 1995,
                "skin_type": "Da dầu",
                "concerns": ["mụn", "nhờn"],
                "avoid_ingredients": [],
                "budget": 500000,
                "sensitivity": "Bình thường"
            },
            "query_text": "Tôi muốn một serum dưới 500k",
            "top_k": 5,
            "use_cache": true
        }
        """
        if not _recommendation_builder:
            return jsonify({
                'ok': False,
                'message': 'Recommendation service not initialized'
            }), 503

        try:
            data = request.get_json(force=True) or {}
            user_profile = data.get('user_profile') or {}
            query_text = str(data.get('query_text', '')).strip()
            top_k = min(int(data.get('top_k', 5)), 10)
            use_cache = data.get('use_cache', True)

            # Validate profile
            if not isinstance(user_profile, dict):
                return jsonify({
                    'ok': False,
                    'message': 'user_profile must be an object'
                }), 400

            if not user_profile.get('skin_type'):
                return jsonify({
                    'ok': False,
                    'message': 'skin_type is required'
                }), 400

            # Generate recommendations
            logger.info(f"[API] Generating RAG recommendations for skin_type={user_profile.get('skin_type')}")
            
            result = _recommendation_builder.generate_recommendations(
                profile=user_profile,
                query_text=query_text,
                top_k=top_k,
                use_cache=use_cache,
            )

            return jsonify(result), (200 if result.get('ok') else 400)

        except Exception as e:
            logger.error(f"[API] Error in recommend_langchain_rag: {e}", exc_info=True)
            return jsonify({
                'ok': False,
                'message': f'Error: {str(e)}'
            }), 500

    @app.post('/api/recommend/hybrid-search')
    def recommend_hybrid_search():
        """
        Direct hybrid search endpoint (retrieval only, no LLM explanation)
        Returns raw products with scores
        
        Expected POST body:
        {
            "query": "serum cho da dầu",
            "user_profile": {...},
            "top_k": 10
        }
        """
        if not _hybrid_retriever:
            return jsonify({
                'ok': False,
                'message': 'Hybrid retriever not initialized'
            }), 503

        try:
            data = request.get_json(force=True) or {}
            query = str(data.get('query', '')).strip()
            user_profile = data.get('user_profile') or {}
            top_k = min(int(data.get('top_k', 10)), 20)

            if not query:
                return jsonify({
                    'ok': False,
                    'message': 'query is required'
                }), 400

            # Perform hybrid search
            logger.info(f"[API] Hybrid search: query={query}")
            
            retrieved = _hybrid_retriever.retrieve(
                query=query,
                profile=user_profile,
                top_k=top_k,
            )

            products = [p.to_dict() for p in retrieved]

            return jsonify({
                'ok': True,
                'count': len(products),
                'data': products,
                'query': query,
            }), 200

        except Exception as e:
            logger.error(f"[API] Error in recommend_hybrid_search: {e}", exc_info=True)
            return jsonify({
                'ok': False,
                'message': f'Error: {str(e)}'
            }), 500

    @app.post('/api/chat/ingredient-analysis')
    def chat_ingredient_analysis():
        """
        Analyze skincare ingredient
        
        Expected POST body:
        {
            "ingredient": "Vitamin C",
            "skin_type": "Da dầu"
        }
        """
        if not _chatbot_builder:
            return jsonify({
                'ok': False,
                'message': 'Chatbot service not initialized'
            }), 503

        try:
            data = request.get_json(force=True) or {}
            ingredient = str(data.get('ingredient', '')).strip()
            skin_type = str(data.get('skin_type', '')).strip()

            if not ingredient:
                return jsonify({
                    'ok': False,
                    'message': 'ingredient is required'
                }), 400

            # Analyze ingredient
            logger.info(f"[API] Ingredient analysis: {ingredient}")
            
            analysis = _chatbot_builder.analyze_ingredient(
                ingredient=ingredient,
                products=[],  # Could retrieve products containing this ingredient
                skin_type=skin_type,
            )

            return jsonify({
                'ok': True,
                'ingredient': ingredient,
                'analysis': analysis,
            }), 200

        except Exception as e:
            logger.error(f"[API] Error in chat_ingredient_analysis: {e}", exc_info=True)
            return jsonify({
                'ok': False,
                'message': f'Error: {str(e)}'
            }), 500

    @app.get('/api/cache/stats')
    def get_cache_stats():
        """Get cache statistics"""
        if not _cache:
            return jsonify({
                'available': False,
                'message': 'Cache not initialized'
            }), 503

        stats = _cache.get_cache_stats()
        return jsonify(stats), 200

    @app.post('/api/cache/clear')
    def clear_cache():
        """Clear all cache (admin only)"""
        if not _cache:
            return jsonify({
                'ok': False,
                'message': 'Cache not initialized'
            }), 503

        try:
            success = _cache.clear_all()
            return jsonify({
                'ok': success,
                'message': 'Cache cleared' if success else 'Failed to clear cache'
            }), 200 if success else 500
        except Exception as e:
            logger.error(f"[API] Error clearing cache: {e}")
            return jsonify({
                'ok': False,
                'message': f'Error: {str(e)}'
            }), 500

    @app.get('/api/health/langchain')
    def health_langchain():
        """Health check for LangChain components"""
        status = {
            'ok': True,
            'components': {
                'recommendation_builder': _recommendation_builder is not None,
                'chatbot_builder': _chatbot_builder is not None,
                'hybrid_retriever': _hybrid_retriever is not None,
                'cache': _cache is not None and _cache.is_available(),
            }
        }

        all_ok = all(status['components'].values())
        return jsonify(status), (200 if all_ok else 503)


# Example usage in main app.py:
"""
from flask import Flask
from langchain_google_genai import ChatGoogleGenerativeAI
from config import LlamaIndexConfig
from rag.api.langchain_endpoints import init_langchain_components, register_langchain_routes

app = Flask(__name__)

# After other setup...
try:
    llm = ChatGoogleGenerativeAI(
        model="gemini-2.5-flash",
        google_api_key=LlamaIndexConfig.GOOGLE_API_KEY,
        temperature=0.7,
    )
    
    init_langchain_components(
        app,
        llm,
        LlamaIndexConfig.MONGODB_URI,
        LlamaIndexConfig.MONGODB_DB_NAME
    )
    
    register_langchain_routes(app)
    print("[OK] LangChain endpoints registered")
except Exception as e:
    print(f"[ERROR] Failed to initialize LangChain: {e}")
"""

"""
Simple Flask routes for recommendation API
Uses HybridRetriever + LLM directly without LangChain chains
"""
import logging
import json
from flask import request, jsonify
from typing import Optional, Any

logger = logging.getLogger(__name__)

# Global instances
_recommender: Optional[Any] = None
_hybrid_retriever: Optional[Any] = None
_llm: Optional[Any] = None


def init_simple_routes(app, hybrid_retriever, llm):
    """Initialize routes with retriever and LLM"""
    global _recommender, _hybrid_retriever, _llm
    
    from rag.simple_recommender import SimpleRecommender
    
    _hybrid_retriever = hybrid_retriever
    _llm = llm
    _recommender = SimpleRecommender(hybrid_retriever, llm)
    
    # Register routes
    @app.post('/api/recommend/hybrid')
    def recommend_hybrid():
        """Hybrid search + LLM explanation endpoint"""
        try:
            data = request.get_json() or {}
            
            # Extract parameters
            user_profile = data.get('user_profile', {})
            query_text = data.get('query_text', '')
            top_k = min(int(data.get('top_k', 5)), 10)  # Max 10
            
            # Validate profile
            if not user_profile:
                return jsonify({'error': 'user_profile required'}), 400
            
            logger.info(f"[API] recommend_hybrid: query={query_text}, profile={user_profile}")
            
            # Generate recommendations
            result = _recommender.generate_recommendations(
                profile=user_profile,
                query_text=query_text,
                top_k=top_k
            )
            
            return jsonify(result), 200 if result.get('ok') else 400
            
        except Exception as e:
            logger.error(f"[API] recommend_hybrid error: {e}", exc_info=True)
            return jsonify({'error': str(e)}), 500
    
    @app.post('/api/recommend/explain')
    def recommend_explain():
        """Alias for hybrid endpoint"""
        return recommend_hybrid()
    
    @app.post('/api/recommend/langchain-rag')
    def recommend_langchain_rag():
        """Alias for hybrid endpoint"""
        return recommend_hybrid()
    
    @app.get('/api/health/hybrid')
    def health_hybrid():
        """Health check"""
        return jsonify({
            'ok': True,
            'message': 'Hybrid recommender ready',
            'retriever': 'SkincareHybridRetriever',
            'llm': 'ChatGoogleGenerativeAI'
        }), 200
    
    logger.info("[Routes] Simple recommendation routes registered")

"""
Simple Recommendation Engine (without LangChain chains to avoid hanging)
Uses HybridRetriever + direct LLM calls
"""
import logging
from typing import Any, Optional, Dict, List
from datetime import datetime

logger = logging.getLogger(__name__)


class SimpleRecommender:
    """Simple recommender using HybridRetriever + direct LLM calls"""
    
    def __init__(self, hybrid_retriever: Any, llm: Any):
        """
        Initialize
        Args:
            hybrid_retriever: SkincareHybridRetriever instance
            llm: ChatGoogleGenerativeAI or similar LLM instance
        """
        self.retriever = hybrid_retriever
        self.llm = llm
    
    def generate_recommendations(
        self,
        profile: dict,
        query_text: str = "",
        top_k: int = 5
    ) -> Dict[str, Any]:
        """
        Generate recommendations with natural explanations
        
        Args:
            profile: Customer profile
            query_text: Query text (e.g., "kem dưỡng")
            top_k: Number of products to return
            
        Returns:
            Response dict with recommendations
        """
        try:
            # 1. Retrieve products using hybrid search
            logger.info(f"[Recommender] Retrieving products for query: {query_text}")
            retrieved = self.retriever.retrieve(
                query=query_text or self._build_default_query(profile),
                profile=profile,
                top_k=top_k * 2  # Get more for LLM to filter
            )
            
            if not retrieved:
                logger.warning("[Recommender] No products found")
                return {
                    'ok': False,
                    'message': 'Không tìm thấy sản phẩm phù hợp',
                    'items': []
                }
            
            # 2. Generate explanations for top products
            logger.info(f"[Recommender] Generating explanations for {len(retrieved)} products")
            explained_products = []
            for product in retrieved[:top_k]:
                explanation = self._generate_explanation(product, profile, query_text)
                product_dict = product.to_dict() if hasattr(product, 'to_dict') else product
                product_dict['llm_explanation'] = explanation
                explained_products.append(product_dict)
            
            # 3. Generate summary
            summary = self._generate_summary(explained_products[:3], profile)
            
            return {
                'ok': True,
                'message': 'Gợi ý thành công',
                'items': explained_products,
                'summary': summary,
                'search_mode': 'hybrid',
                'query': query_text or self._build_default_query(profile),
            }
            
        except Exception as e:
            logger.error(f"[Recommender] Error: {e}", exc_info=True)
            return {
                'ok': False,
                'message': f'Lỗi: {str(e)}',
                'items': []
            }
    
    def _generate_explanation(
        self,
        product: Any,
        profile: dict,
        query_text: str = ""
    ) -> str:
        """Generate natural explanation for a product"""
        try:
            # Get product info
            product_dict = product.to_dict() if hasattr(product, 'to_dict') else product
            name = product_dict.get('ten_san_pham', 'Sản phẩm')
            benefits = product_dict.get('tac_dung', '')
            ingredients = product_dict.get('thanh_phan', '')
            price = product_dict.get('gia_ban', 0)
            brand = product_dict.get('ten_thuong_hieu', '')
            
            # Build natural prompt
            skin_type = profile.get('skin_type', 'da của bạn')
            concerns = ", ".join(profile.get('concerns', []))
            budget = profile.get('budget', 'không giới hạn')
            
            prompt = f"""Viết giải thích tự nhiên (kiểu bạn thân) tại sao sản phẩm này phù hợp:

Sản phẩm: {name}
Thương hiệu: {brand}
Giá: {price:,} VND
Ngân sách khách: {budget}k
Loại da: {skin_type}
Vấn đề da: {concerns}
Thành phần: {ingredients}
Lợi ích: {benefits}

Viết 2-3 câu, bắt đầu kiểu "Theo tôi, với da {skin_type} và vấn đề {concerns} của bạn..." 
Nhấn mạnh thành phần cụ thể, so sánh giá, và lợi ích."""

            # Call LLM directly
            response = self.llm.invoke(prompt)
            explanation = response.content if hasattr(response, 'content') else str(response)
            return explanation.strip()
            
        except Exception as e:
            logger.warning(f"[Recommender] Error generating explanation: {e}")
            # Fallback
            return f"Sản phẩm này rất phù hợp với nhu cầu của bạn."
    
    def _generate_summary(self, products: List[Dict], profile: dict) -> str:
        """Generate recommendation summary"""
        try:
            if not products:
                return "Không tìm thấy sản phẩm phù hợp."
            
            product_names = ", ".join([p.get('ten_san_pham', '') for p in products[:3]])
            skin_type = profile.get('skin_type', 'da của bạn')
            concerns = ", ".join(profile.get('concerns', []))
            
            prompt = f"""Viết tóm tắt lời khuyên tự nhiên (1-2 câu):
Sản phẩm đề xuất: {product_names}
Loại da: {skin_type}
Vấn đề: {concerns}

Bắt đầu: "Dựa trên tình trạng da của bạn,..." Tự nhiên, thân thiện."""
            
            response = self.llm.invoke(prompt)
            summary = response.content if hasattr(response, 'content') else str(response)
            return summary.strip()
            
        except Exception as e:
            logger.warning(f"[Recommender] Error generating summary: {e}")
            return f"Các sản phẩm này sẽ giúp bạn cải thiện tình trạng da."
    
    @staticmethod
    def _build_default_query(profile: dict) -> str:
        """Build default query from profile"""
        parts = []
        if profile.get('skin_type'):
            parts.append(f"da {profile['skin_type']}")
        if profile.get('concerns'):
            concerns_str = " và ".join(profile['concerns'])
            parts.append(f"giải quyết {concerns_str}")
        return "sản phẩm skincare cho " + ", ".join(parts) if parts else "skincare"

"""
LangChain-based Recommendation Chain Builder
Orchestrates retrieval, LLM processing, and response generation
Reference: https://docs.langchain.com/oss/python/integrations/vectorstores/mongodb_atlas

NOTE: This module is intentionally minimalist to avoid hanging on imports.
Full implementation moved to async initialization in app.py
"""
import logging
from typing import Any, Optional

logger = logging.getLogger(__name__)


class LLMChain:
    """Simple LLM Chain wrapper for compatibility"""
    def __init__(self, llm, prompt):
        self.llm = llm
        self.prompt = prompt
    
    def run(self, **kwargs):
        """Simple run implementation"""
        try:
            formatted = self.prompt.format(**kwargs)
            return self.llm.invoke(formatted)
        except Exception as e:
            logger.error(f"LLMChain.run error: {e}")
            return ""


class PromptTemplate:
    """Simple PromptTemplate wrapper"""
    def __init__(self, input_variables=None, template=""):
        self.input_variables = input_variables or []
        self.template = template
    
    def format(self, **kwargs):
        result = self.template
        for var in self.input_variables:
            result = result.replace(f"{{{var}}}", str(kwargs.get(var, '')))
        return result


class RecommendationChainBuilder:
    """Minimal implementation - full version requires careful import handling"""
    
    def __init__(self, llm=None, retriever=None, cache=None):
        self.llm = llm
        self.retriever = retriever
        self.cache = cache
        logger.warning("[RecommendationChainBuilder] Using minimal implementation")
    
    def generate_recommendations(self, profile: dict, query_text: str = "", top_k: int = 5, use_cache: bool = True) -> dict:
        """Placeholder - actual implementation in api/langchain_endpoints.py"""
        return {"ok": False, "message": "LangChain chain builder not fully initialized"}


class ChatbotChainBuilder:
    """Minimal implementation"""
    
    def __init__(self, llm=None):
        self.llm = llm
        logger.warning("[ChatbotChainBuilder] Using minimal implementation")
    
    def analyze_ingredient(self, ingredient: str, products: list = None, skin_type: str = "") -> str:
        """Placeholder implementation"""
        return f"Không thể phân tích thành phần '{ingredient}' lúc này."


class RecommendationChainBuilder:
    """
    Builds LangChain chains for skincare recommendations
    Combines retrieval, caching, and LLM reasoning
    """

    def __init__(
        self,
        llm: BaseLanguageModel,
        retriever: SkincareHybridRetriever,
        cache: Optional[RecommendationCache] = None,
    ):
        """
        Initialize recommendation chain builder
        
        Args:
            llm: LangChain LLM instance (GPT, Gemini, etc)
            retriever: Hybrid retriever for product search
            cache: Optional Redis cache instance
        """
        self.llm = llm
        self.retriever = retriever
        self.cache = cache or RecommendationCache()
        self.chains = {}

    def _build_recommendation_chain(self) -> LLMChain:
        """Build the main recommendation generation chain"""
        if 'recommendation' in self.chains:
            return self.chains['recommendation']

        # Create prompt template
        prompt = PromptTemplate(
            input_variables=['profile', 'products', 'query', 'system_prompt'],
            template="{system_prompt}\n\n{profile}\n\n{products}\n\n{query}"
        )

        chain = LLMChain(llm=self.llm, prompt=prompt)
        self.chains['recommendation'] = chain
        
        logger.debug("[Chain] Recommendation chain built")
        return chain

    def _build_explanation_chain(self) -> LLMChain:
        """Build chain for explaining individual products"""
        if 'explanation' in self.chains:
            return self.chains['explanation']

        prompt = PromptTemplate(
            input_variables=['product_name', 'benefits', 'concerns', 'price', 'budget'],
            template="""Giải thích tại sao '{product_name}' phù hợp:
- Lợi ích: {benefits}
- Vấn đề da: {concerns}
- Giá: {price} VND
- Ngân sách: {budget}

Viết 1-2 câu giải thích tự nhiên."""
        )

        chain = LLMChain(llm=self.llm, prompt=prompt)
        self.chains['explanation'] = chain
        
        logger.debug("[Chain] Explanation chain built")
        return chain

    def _build_summary_chain(self) -> LLMChain:
        """Build chain for generating recommendation summary"""
        if 'summary' in self.chains:
            return self.chains['summary']

        prompt = PromptTemplate(
            input_variables=['top_products', 'concerns', 'skin_type'],
            template="""Viết một tóm tắt lời khuyên tự nhiên cho khách hàng.

Sản phẩm đề xuất: {top_products}
Vấn đề da: {concerns}
Loại da: {skin_type}

Viết 1-2 câu, bắt đầu bằng: "Theo tôi, với da {skin_type} và vấn đề {concerns}..." """
        )

        chain = LLMChain(llm=self.llm, prompt=prompt)
        self.chains['summary'] = chain
        
        logger.debug("[Chain] Summary chain built")
        return chain

    # ========== PUBLIC METHODS ==========

    def generate_recommendations(
        self,
        profile: dict,
        query_text: str = "",
        top_k: int = 5,
        use_cache: bool = True,
    ) -> dict[str, Any]:
        """
        Generate full recommendation response using RAG + LLM
        
        Args:
            profile: Customer profile (skin_type, concerns, budget, etc)
            query_text: Optional customer query text
            top_k: Number of products to recommend
            use_cache: Whether to check/use cache
            
        Returns:
            Dict with recommendations and explanations
        """
        # Check cache first
        cache_key = CacheKeyBuilder.build_key(profile, query_text)
        
        if use_cache and self.cache.is_available():
            cached = self.cache.get_recommendation(cache_key)
            if cached:
                logger.info(f"[Recommender] Cache hit for key {cache_key[:16]}")
                return cached

        try:
            # 1. Retrieve relevant products using hybrid search
            logger.info("[Recommender] Starting hybrid retrieval...")
            retrieved = self.retriever.retrieve(
                query=query_text or self._build_default_query(profile),
                profile=profile,
                top_k=top_k * 2  # Get more than needed for LLM to select
            )

            if not retrieved:
                logger.warning("[Recommender] No products retrieved")
                return {
                    'ok': False,
                    'message': 'Không tìm thấy sản phẩm phù hợp',
                    'items': []
                }

            # 2. Generate LLM explanations
            logger.info(f"[Recommender] Generating LLM explanations for {len(retrieved)} products...")
            explained_products = []
            for product in retrieved[:top_k]:
                explanation = self._generate_product_explanation(
                    product=product,
                    profile=profile
                )
                
                product_dict = product.to_dict()
                product_dict['llm_explanation'] = explanation
                explained_products.append(product_dict)

            # 3. Generate summary advice
            summary = self._generate_recommendation_summary(
                products=explained_products,
                profile=profile
            )

            # 4. Build response
            response = {
                'ok': True,
                'message': 'Gợi ý thành công',
                'items': explained_products,
                'summary': summary,
                'search_mode': 'hybrid',
                'cache_hit': False,
                'query': query_text or self._build_default_query(profile),
            }

            # 5. Cache the response
            if use_cache and self.cache.is_available():
                self.cache.set_recommendation(cache_key, response)
                logger.info(f"[Recommender] Response cached with key {cache_key[:16]}")

            return response

        except Exception as e:
            logger.error(f"[Recommender] Error in generate_recommendations: {e}", exc_info=True)
            return {
                'ok': False,
                'message': f'Lỗi xử lý: {str(e)}',
                'items': []
            }

    def _generate_product_explanation(
        self,
        product: RetrievedProduct,
        profile: dict
    ) -> str:
        """
        Generate natural language explanation for why a product is recommended
        
        Args:
            product: Retrieved product
            profile: Customer profile
            
        Returns:
            Natural language explanation
        """
        try:
            # Use built-in reasons first
            if product.reasons:
                reason_text = " và ".join(product.reasons)
                return f"Sản phẩm này lý tưởng bởi vì {reason_text}."

            # Fall back to LLM explanation
            explanation_chain = self._build_explanation_chain()
            
            result = explanation_chain.run(
                product_name=product.name,
                benefits=product.benefits,
                concerns=", ".join(profile.get('concerns', [])),
                price=int(product.price),
                budget=profile.get('budget', 'không giới hạn')
            )

            return result.strip()
        except Exception as e:
            logger.warning(f"[Chain] Error generating explanation: {e}")
            return f"Sản phẩm {product.name} phù hợp với hồ sơ da của bạn."

    def _generate_recommendation_summary(
        self,
        products: list[dict],
        profile: dict
    ) -> str:
        """
        Generate overall recommendation summary
        
        Args:
            products: List of recommended products
            profile: Customer profile
            
        Returns:
            Summary text
        """
        try:
            if not products:
                return "Không tìm thấy sản phẩm phù hợp."

            top_3 = products[:3]
            product_names = ", ".join([p.get('ten_san_pham', '') for p in top_3])
            
            summary_chain = self._build_summary_chain()
            
            result = summary_chain.run(
                top_products=product_names,
                concerns=", ".join(profile.get('concerns', ['không xác định'])),
                skin_type=profile.get('skin_type', 'không xác định')
            )

            return result.strip()
        except Exception as e:
            logger.warning(f"[Chain] Error generating summary: {e}")
            skin_type = profile.get('skin_type', 'da của bạn')
            return f"Dựa trên hồ sơ da {skin_type} của bạn, các sản phẩm này sẽ phù hợp nhất."

    @staticmethod
    def _build_default_query(profile: dict) -> str:
        """
        Build default search query from customer profile
        Used when customer doesn't provide specific query
        
        Args:
            profile: Customer profile
            
        Returns:
            Constructed query string
        """
        parts = []

        # Add skin type
        if profile.get('skin_type'):
            parts.append(f"da {profile['skin_type']}")

        # Add concerns
        if profile.get('concerns'):
            concerns_str = " và ".join(profile['concerns'])
            parts.append(f"giải quyết {concerns_str}")

        # Add product type hint if query has it
        query = profile.get('query_text', '')
        if 'serum' in query.lower():
            parts.append("serum")
        elif 'kem' in query.lower() or 'cream' in query.lower():
            parts.append("kem dưỡng")
        elif 'mặt nạ' in query.lower() or 'mask' in query.lower():
            parts.append("mặt nạ")

        return "sản phẩm skincare cho " + ", ".join(parts) if parts else "skincare"


class ChatbotChainBuilder:
    """
    Builds LangChain chains for general beauty chatbot
    For answering ingredient questions, product info, etc
    """

    def __init__(self, llm: BaseLanguageModel):
        """Initialize chatbot chain builder"""
        self.llm = llm
        self.chains = {}

    def _build_ingredient_analysis_chain(self) -> LLMChain:
        """Build chain for analyzing skincare ingredients"""
        prompt = PromptTemplate(
            input_variables=['ingredient', 'products', 'skin_type'],
            template="""Phân tích về thành phần '{ingredient}' trong skincare.

Sản phẩm chứa thành phần này: {products}
Loại da của khách hàng: {skin_type}

Giải thích:
1. Thành phần này là gì
2. Tác dụng của nó
3. Phù hợp với loại da nào
4. Lưu ý khi sử dụng"""
        )

        chain = LLMChain(llm=self.llm, prompt=prompt)
        return chain

    def analyze_ingredient(
        self,
        ingredient: str,
        products: list[str],
        skin_type: str = ""
    ) -> str:
        """
        Analyze a skincare ingredient
        
        Args:
            ingredient: Ingredient name
            products: List of products containing this ingredient
            skin_type: Optional customer skin type
            
        Returns:
            Analysis text
        """
        try:
            chain = self._build_ingredient_analysis_chain()
            result = chain.run(
                ingredient=ingredient,
                products=", ".join(products),
                skin_type=skin_type or "không xác định"
            )
            return result.strip()
        except Exception as e:
            logger.error(f"[Chatbot] Error analyzing ingredient: {e}")
            return f"Xin lỗi, tôi không thể phân tích '{ingredient}' lúc này."

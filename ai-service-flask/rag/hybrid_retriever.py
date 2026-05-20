"""
Hybrid Retriever for Skincare Recommendations
Combines keyword search (BM25) with semantic search (embeddings)
Reference: https://docs.langchain.com/oss/python/integrations/retrievers/pinecone_hybrid_search
"""
import logging
import math
import re
from dataclasses import dataclass
from typing import Any, Optional

try:
    from langchain_mongodb.retrievers import MongoDBAtlasHybridSearchRetriever
    LANGCHAIN_AVAILABLE = True
except ImportError:
    LANGCHAIN_AVAILABLE = False
    MongoDBAtlasHybridSearchRetriever = None

from pymongo import MongoClient
from pymongo.collection import Collection


logger = logging.getLogger(__name__)


@dataclass
class RetrievedProduct:
    """Represents a retrieved product from hybrid search"""
    product_id: str
    name: str
    brand: str
    price: float
    description: str
    ingredients: str
    benefits: str
    image_url: str
    keyword_score: float
    semantic_score: float
    final_score: float
    reasons: list[str]

    def to_dict(self) -> dict:
        """Convert to dictionary for JSON serialization"""
        return {
            'id': self.product_id,
            'ten_san_pham': self.name,
            'thuong_hieu': self.brand,
            'gia_ban': self.price,
            'mo_ta': self.description,
            'thanh_phan': self.ingredients,
            'tac_dung': self.benefits,
            'link_hinh_anh': self.image_url,
            'keyword_score': round(self.keyword_score, 3),
            'semantic_score': round(self.semantic_score, 3),
            'score': round(self.final_score, 2),
            'reasons': self.reasons,
        }


class SkincareHybridRetriever:
    """
    Hybrid search retriever for skincare products
    Combines:
    1. Keyword search (BM25) on product names, descriptions, ingredients
    2. Semantic search (vector embeddings) for understanding intent
    """

    # Weighting for hybrid search (can be tuned)
    DEFAULT_KEYWORD_WEIGHT = 0.4  # 40% keyword relevance
    DEFAULT_SEMANTIC_WEIGHT = 0.6  # 60% semantic relevance

    def __init__(
        self,
        mongo_uri: str,
        db_name: str,
        collection_name: str = "products",
        embedding_model: Optional[Any] = None,
        keyword_weight: float = 0.4,
        semantic_weight: float = 0.6,
    ):
        """
        Initialize hybrid retriever
        
        Args:
            mongo_uri: MongoDB connection URI
            db_name: Database name
            collection_name: Products collection name
            embedding_model: LLM/embedding model for semantic search
            keyword_weight: Weight for keyword search (0-1)
            semantic_weight: Weight for semantic search (0-1)
        """
        self.mongo_uri = mongo_uri
        self.db_name = db_name
        self.collection_name = collection_name
        self.embedding_model = embedding_model
        
        # Normalize weights
        total = keyword_weight + semantic_weight
        self.keyword_weight = keyword_weight / total if total > 0 else 0.5
        self.semantic_weight = semantic_weight / total if total > 0 else 0.5

        # Initialize MongoDB connection
        try:
            self.client = MongoClient(mongo_uri, serverSelectionTimeoutMS=5000)
            self.db = self.client[db_name]
            self.collection: Collection = self.db[collection_name]
            self._ensure_indexes()
            logger.info("[Retriever] MongoDB connected successfully")
        except Exception as e:
            logger.error(f"[Retriever] Failed to connect MongoDB: {e}")
            raise

    def _ensure_indexes(self) -> None:
        """Ensure necessary indexes exist"""
        try:
            # Text index for keyword search
            self.collection.create_index([
                ("ten_san_pham", "text"),
                ("mo_ta", "text"),
                ("thanh_phan", "text"),
                ("thuong_hieu", "text"),
            ])
            
            # Regular indexes for filtering
            self.collection.create_index("gia_ban")
            self.collection.create_index("loai_da")
            self.collection.create_index("tac_dung")
            
            logger.debug("[Retriever] Indexes created/verified")
        except Exception as e:
            logger.warning(f"[Retriever] Index creation warning: {e}")

    # ========== KEYWORD SEARCH ==========

    def _keyword_search(
        self,
        query: str,
        limit: int = 20,
        **filters
    ) -> list[tuple[dict, float]]:
        """
        Perform keyword (full-text) search on products
        
        Args:
            query: Search query text
            limit: Max results to return
            **filters: Optional MongoDB filters (e.g., gia_ban={'$lt': 500000})
            
        Returns:
            List of (product_doc, relevance_score) tuples
        """
        try:
            # Build search query
            search_filter = {
                "$text": {"$search": query}
            }
            search_filter.update(filters)
            
            # Search with text score
            results = list(self.collection.find(
                search_filter,
                {"score": {"$meta": "textScore"}}
            ).sort([("score", {"$meta": "textScore"})]).limit(limit))
            
            # Normalize scores to 0-1 range
            if results:
                scores = [r.get('score', 0) for r in results]
                max_score = max(scores) if scores else 1
                normalized = [
                    (r, r.get('score', 0) / max_score if max_score > 0 else 0)
                    for r in results
                ]
            else:
                normalized = []
            
            logger.debug(f"[Retriever] Keyword search returned {len(normalized)} results")
            return normalized
        except Exception as e:
            logger.error(f"[Retriever] Keyword search error: {e}")
            return []

    # ========== SEMANTIC SEARCH ==========

    def _semantic_search(
        self,
        query: str,
        limit: int = 20,
        **filters
    ) -> list[tuple[dict, float]]:
        """
        Perform semantic (vector similarity) search
        
        Args:
            query: Natural language query
            limit: Max results
            **filters: Optional MongoDB filters
            
        Returns:
            List of (product_doc, similarity_score) tuples
        """
        if not self.embedding_model:
            logger.warning("[Retriever] Embedding model not available for semantic search")
            return []

        try:
            # Generate embedding for query
            query_embedding = self._get_embedding(query)
            if not query_embedding:
                return []
            
            # Get all products (in production, use vector search)
            all_products = list(self.collection.find(filters).limit(limit * 2))
            
            # Calculate similarities
            results = []
            for product in all_products:
                # Construct semantic representation of product
                product_text = self._product_to_semantic_text(product)
                product_embedding = self._get_embedding(product_text)
                
                if product_embedding:
                    similarity = self._cosine_similarity(query_embedding, product_embedding)
                    results.append((product, similarity))
            
            # Sort by similarity
            results.sort(key=lambda x: x[1], reverse=True)
            
            logger.debug(f"[Retriever] Semantic search returned {len(results[:limit])} results")
            return results[:limit]
        except Exception as e:
            logger.error(f"[Retriever] Semantic search error: {e}")
            return []

    def _get_embedding(self, text: str) -> Optional[list[float]]:
        """Get embedding for text"""
        if not self.embedding_model:
            return None
        
        try:
            # For Gemini embeddings
            if hasattr(self.embedding_model, 'embed_query'):
                return self.embedding_model.embed_query(text)
            # For other embedding models
            elif callable(self.embedding_model):
                return self.embedding_model(text)
            else:
                return None
        except Exception as e:
            logger.error(f"[Retriever] Embedding error: {e}")
            return None

    @staticmethod
    def _product_to_semantic_text(product: dict) -> str:
        """Convert product to semantic representation for embedding"""
        parts = [
            product.get('ten_san_pham', ''),
            product.get('thuong_hieu', ''),
            product.get('mo_ta', ''),
            product.get('thanh_phan', ''),
            product.get('tac_dung', ''),
        ]
        return ' '.join(filter(None, parts))

    @staticmethod
    def _cosine_similarity(vec1: list[float], vec2: list[float]) -> float:
        """Calculate cosine similarity between two vectors"""
        if not vec1 or not vec2 or len(vec1) != len(vec2):
            return 0.0
        
        dot_product = sum(a * b for a, b in zip(vec1, vec2))
        norm1 = math.sqrt(sum(a ** 2 for a in vec1))
        norm2 = math.sqrt(sum(b ** 2 for b in vec2))
        
        if norm1 == 0 or norm2 == 0:
            return 0.0
        
        return dot_product / (norm1 * norm2)

    # ========== HYBRID SEARCH ==========

    def retrieve(
        self,
        query: str,
        profile: dict,
        top_k: int = 10,
        keyword_weight: Optional[float] = None,
        semantic_weight: Optional[float] = None,
    ) -> list[RetrievedProduct]:
        """
        Perform hybrid search combining keyword + semantic search
        
        Args:
            query: User query/natural language request
            profile: Customer profile with filters (budget, concerns, skin_type, etc)
            top_k: Number of top results to return
            keyword_weight: Override default keyword weight
            semantic_weight: Override default semantic weight
            
        Returns:
            List of RetrievedProduct sorted by combined score
        """
        keyword_weight = keyword_weight or self.keyword_weight
        semantic_weight = semantic_weight or self.semantic_weight

        # Build MongoDB filters from profile and query
        filters = self._build_filters(profile, query)

        logger.info(f"[Retriever] Starting hybrid search: query='{query}', filters={filters}")

        # Perform both searches
        keyword_results = self._keyword_search(query, limit=top_k * 3, **filters)
        semantic_results = self._semantic_search(query, limit=top_k * 3, **filters)

        # Combine results
        combined = {}  # product_id -> RetrievedProduct
        
        # Add keyword results
        for product, keyword_score in keyword_results:
            product_id = str(product.get('_id', product.get('id', '')))
            reasons = self._extract_reasons(product, query, profile)
            
            combined[product_id] = RetrievedProduct(
                product_id=product_id,
                name=product.get('ten_san_pham', ''),
                brand=product.get('thuong_hieu', ''),
                price=float(product.get('gia_ban', 0)),
                description=product.get('mo_ta', ''),
                ingredients=product.get('thanh_phan', ''),
                benefits=product.get('tac_dung', ''),
                image_url=product.get('link_hinh_anh', ''),
                keyword_score=keyword_score,
                semantic_score=0.0,
                final_score=keyword_score * keyword_weight,
                reasons=reasons,
            )

        # Merge semantic results
        for product, semantic_score in semantic_results:
            product_id = str(product.get('_id', product.get('id', '')))
            
            if product_id in combined:
                # Update with semantic score
                combined[product_id].semantic_score = semantic_score
                combined[product_id].final_score = (
                    combined[product_id].keyword_score * keyword_weight +
                    semantic_score * semantic_weight
                )
            else:
                # New product from semantic search
                reasons = self._extract_reasons(product, query, profile)
                combined[product_id] = RetrievedProduct(
                    product_id=product_id,
                    name=product.get('ten_san_pham', ''),
                    brand=product.get('thuong_hieu', ''),
                    price=float(product.get('gia_ban', 0)),
                    description=product.get('mo_ta', ''),
                    ingredients=product.get('thanh_phan', ''),
                    benefits=product.get('tac_dung', ''),
                    image_url=product.get('link_hinh_anh', ''),
                    keyword_score=0.0,
                    semantic_score=semantic_score,
                    final_score=semantic_score * semantic_weight,
                    reasons=reasons,
                )

        # Sort by final score and return top_k
        results = sorted(combined.values(), key=lambda x: x.final_score, reverse=True)
        logger.info(f"[Retriever] Hybrid search completed: {len(results)} results, top score: {results[0].final_score if results else 0:.3f}")
        
        return results[:top_k]

    @staticmethod
    def _build_filters(profile: dict, query: str = '') -> dict:
        """
        Build MongoDB filters from customer profile and query
        
        Args:
            profile: Customer profile dict
            query: User query text for extracting product type
            
        Returns:
            MongoDB filter dict
        """
        filters = {}

        # Budget filter
        if profile.get('budget') and profile['budget'] > 0:
            filters['gia_ban'] = {'$lte': profile['budget']}

        # Skin type filter
        if profile.get('skin_type'):
            skin_types = [profile['skin_type']]
            if 'loai_da' in profile:
                filters['loai_da'] = {'$in': skin_types}

        # Extract product type from query (kem dưỡng, serum, essence, v.v.)
        product_type = SkincareHybridRetriever._extract_product_type(query)
        if product_type:
            filters['$or'] = [
                {'loai_san_pham': {'$regex': product_type, '$options': 'i'}},
                {'ten_danh_muc': {'$regex': product_type, '$options': 'i'}},
                {'ten_san_pham': {'$regex': product_type, '$options': 'i'}}
            ]
            logger.debug(f"[Retriever] Filtering by product type: {product_type}")

        # Exclude products with avoided ingredients
        if profile.get('avoid_ingredients'):
            avoid = profile['avoid_ingredients']
            avoid_regex = '|'.join(re.escape(i) for i in avoid)
            filters['thanh_phan'] = {
                '$not': {'$regex': avoid_regex, '$options': 'i'}
            }

        return filters

    @staticmethod
    def _extract_product_type(query: str) -> str:
        """
        Extract product type from user query
        Examples: 'kem dưỡng' -> 'kem', 'serum toner' -> 'serum', etc.
        
        Args:
            query: User query text
            
        Returns:
            Product type string or empty string
        """
        if not query:
            return ''
        
        query_lower = query.lower()
        
        # Common Vietnamese skincare product types
        product_keywords = {
            'kem dưỡng': 'kem',
            'kem chống nắng': 'kem',
            'kem mặt': 'kem',
            'kem dạng gel': 'kem',
            'kem dạng tube': 'kem',
            'serum': 'serum',
            'essence': 'essence',
            'toner': 'toner',
            'nước hoa hồng': 'toner',
            'mặt nạ': 'mask',
            'mask': 'mask',
            'sữa rửa mặt': 'cleanser',
            'sữa tẩy trang': 'cleanser',
            'nước tẩy trang': 'toner',
            'sữa dưỡng': 'milk',
            'lotion': 'lotion',
            'xịt khoáng': 'spray',
            'dầu': 'oil',
            'scrub': 'scrub',
            'kem trị': 'treatment',
            'kem chuyên biệt': 'treatment',
            'bb cream': 'bb',
            'cc cream': 'cc',
            'cushion': 'cushion',
            'phấn': 'powder',
            'kem lót': 'primer',
        }
        
        # Check for exact matches first (longer phrases)
        for keyword, product_type in sorted(product_keywords.items(), key=lambda x: len(x[0]), reverse=True):
            if keyword in query_lower:
                return product_type
        
        return ''

    @staticmethod
    def _extract_reasons(
        product: dict,
        query: str,
        profile: dict
    ) -> list[str]:
        """
        Extract reasons why this product matches the query
        
        Args:
            product: Product document
            query: User query
            profile: User profile
            
        Returns:
            List of matching reasons
        """
        reasons = []

        # Check benefits against concerns
        benefits = (product.get('tac_dung') or '').lower()
        concerns = profile.get('concerns', [])
        for concern in concerns:
            if concern.lower() in benefits:
                reasons.append(f"Giúp giải quyết vấn đề {concern}")

        # Price match
        price = float(product.get('gia_ban', 0))
        budget = profile.get('budget', 0)
        if budget > 0 and price <= budget:
            reasons.append(f"Phù hợp ngân sách (chỉ {price:,.0f} VND)")

        # Ingredient match
        ingredients = (product.get('thanh_phan') or '').lower()
        if 'vitamin c' in ingredients:
            reasons.append("Chứa Vitamin C làm sáng da")
        if 'hyaluronic' in ingredients:
            reasons.append("Giàu Hyaluronic Acid cấp ẩm")
        if 'niacinamide' in ingredients:
            reasons.append("Có Niacinamide kiểm soát dầu")

        # Query relevance
        if any(word in (query or '').lower() for word in ['serum', 'essence']):
            product_type = product.get('ten_san_pham', '').lower()
            if 'serum' in product_type or 'essence' in product_type:
                reasons.append("Đúng loại sản phẩm bạn tìm")

        return reasons[:3]  # Return top 3 reasons

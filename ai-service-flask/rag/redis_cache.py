"""
Redis Caching Layer for Recommendation System
Implements caching for similar queries and collaborative filtering
Reference: https://docs.langchain.com/oss/python/integrations/retrievers/pinecone_hybrid_search
"""
import hashlib
import json
import logging
from datetime import datetime, timedelta, timezone
from typing import Any, Optional

try:
    import redis
    from redis import Redis
    REDIS_AVAILABLE = True
except ImportError:
    REDIS_AVAILABLE = False
    redis = None
    Redis = None


logger = logging.getLogger(__name__)


class RecommendationCache:
    """Cache system for recommendation queries and responses"""

    # Cache TTL in seconds
    QUERY_CACHE_TTL = 7 * 24 * 3600  # 7 days for same profile
    PRODUCT_CACHE_TTL = 30 * 24 * 3600  # 30 days for product data
    USER_HISTORY_TTL = 90 * 24 * 3600  # 90 days for user history

    def __init__(self, redis_url: str = "redis://localhost:6379/0"):
        """
        Initialize Redis cache client
        
        Args:
            redis_url: Redis connection URL
        """
        if not REDIS_AVAILABLE:
            logger.warning("Redis not installed. Caching disabled.")
            self.client = None
            return

        try:
            self.client = redis.from_url(redis_url, decode_responses=True)
            self.client.ping()
            logger.info("[Cache] Redis connected successfully")
        except Exception as e:
            logger.error(f"[Cache] Failed to connect to Redis: {e}")
            self.client = None

    def is_available(self) -> bool:
        """Check if cache is available"""
        return self.client is not None

    @staticmethod
    def _normalize_key(value: str) -> str:
        """Normalize string for use as cache key"""
        return hashlib.sha256(value.encode()).hexdigest()

    # ========== RECOMMENDATION CACHING ==========

    def get_recommendation(self, profile_key: str) -> Optional[dict]:
        """
        Retrieve cached recommendation for similar profile
        
        Args:
            profile_key: Hash of user profile + query
            
        Returns:
            Cached recommendation response or None
        """
        if not self.is_available():
            return None

        try:
            cached = self.client.get(f"rec:{profile_key}")
            if cached:
                data = json.loads(cached)
                logger.debug(f"[Cache HIT] Recommendation for {profile_key[:8]}")
                return data
            return None
        except Exception as e:
            logger.error(f"[Cache] Error retrieving recommendation: {e}")
            return None

    def set_recommendation(
        self,
        profile_key: str,
        recommendation_data: dict,
        ttl: Optional[int] = None
    ) -> bool:
        """
        Cache a recommendation response
        
        Args:
            profile_key: Hash of user profile + query
            recommendation_data: The recommendation to cache
            ttl: Time-to-live in seconds (default: QUERY_CACHE_TTL)
            
        Returns:
            Success status
        """
        if not self.is_available():
            return False

        try:
            ttl = ttl or self.QUERY_CACHE_TTL
            cache_value = json.dumps(recommendation_data, ensure_ascii=False)
            self.client.setex(f"rec:{profile_key}", ttl, cache_value)
            logger.debug(f"[Cache SET] Recommendation for {profile_key[:8]}")
            return True
        except Exception as e:
            logger.error(f"[Cache] Error caching recommendation: {e}")
            return False

    def find_similar_cached_profiles(self, profile_key: str, limit: int = 5) -> list[dict]:
        """
        Find similar cached recommendation profiles for collaborative filtering
        
        Args:
            profile_key: Current profile hash
            limit: Max profiles to return
            
        Returns:
            List of similar profile recommendations
        """
        if not self.is_available():
            return []

        try:
            # Get all recommendation cache keys
            pattern = "rec:*"
            keys = self.client.keys(pattern)[:100]  # Limit to prevent memory spike
            
            similar = []
            for key in keys:
                if key == f"rec:{profile_key}":
                    continue
                    
                try:
                    data = json.loads(self.client.get(key) or "{}")
                    # Could implement similarity scoring here
                    similar.append(data)
                except:
                    pass
            
            return similar[:limit]
        except Exception as e:
            logger.error(f"[Cache] Error finding similar profiles: {e}")
            return []

    # ========== PRODUCT CACHING ==========

    def get_product(self, product_id: str) -> Optional[dict]:
        """
        Retrieve cached product data
        
        Args:
            product_id: Product ID/SKU
            
        Returns:
            Product data or None
        """
        if not self.is_available():
            return None

        try:
            cached = self.client.get(f"product:{product_id}")
            if cached:
                return json.loads(cached)
            return None
        except Exception as e:
            logger.error(f"[Cache] Error retrieving product: {e}")
            return None

    def set_product(
        self,
        product_id: str,
        product_data: dict,
        ttl: Optional[int] = None
    ) -> bool:
        """
        Cache product data
        
        Args:
            product_id: Product ID/SKU
            product_data: Product information
            ttl: Time-to-live in seconds
            
        Returns:
            Success status
        """
        if not self.is_available():
            return False

        try:
            ttl = ttl or self.PRODUCT_CACHE_TTL
            cache_value = json.dumps(product_data, ensure_ascii=False)
            self.client.setex(f"product:{product_id}", ttl, cache_value)
            return True
        except Exception as e:
            logger.error(f"[Cache] Error caching product: {e}")
            return False

    def invalidate_products(self, product_ids: list[str]) -> int:
        """
        Invalidate product cache entries (call when products updated)
        
        Args:
            product_ids: List of product IDs to invalidate
            
        Returns:
            Count of invalidated entries
        """
        if not self.is_available():
            return 0

        try:
            count = 0
            for product_id in product_ids:
                if self.client.delete(f"product:{product_id}"):
                    count += 1
            logger.info(f"[Cache] Invalidated {count} product cache entries")
            return count
        except Exception as e:
            logger.error(f"[Cache] Error invalidating products: {e}")
            return 0

    # ========== USER HISTORY CACHING ==========

    def get_user_history(self, customer_id: str) -> Optional[list]:
        """
        Retrieve cached user purchase history
        
        Args:
            customer_id: Customer ID
            
        Returns:
            Cached history or None
        """
        if not self.is_available():
            return None

        try:
            cached = self.client.get(f"user_hist:{customer_id}")
            if cached:
                return json.loads(cached)
            return None
        except Exception as e:
            logger.error(f"[Cache] Error retrieving user history: {e}")
            return None

    def set_user_history(
        self,
        customer_id: str,
        history_data: list,
        ttl: Optional[int] = None
    ) -> bool:
        """
        Cache user purchase history
        
        Args:
            customer_id: Customer ID
            history_data: List of purchase history items
            ttl: Time-to-live in seconds
            
        Returns:
            Success status
        """
        if not self.is_available():
            return False

        try:
            ttl = ttl or self.USER_HISTORY_TTL
            cache_value = json.dumps(history_data, ensure_ascii=False)
            self.client.setex(f"user_hist:{customer_id}", ttl, cache_value)
            return True
        except Exception as e:
            logger.error(f"[Cache] Error caching user history: {e}")
            return False

    # ========== QUERY EMBEDDING CACHE ==========

    def get_query_embedding(self, query_text: str) -> Optional[list[float]]:
        """
        Retrieve cached query embedding to avoid re-embedding
        
        Args:
            query_text: Query text
            
        Returns:
            Embedding vector or None
        """
        if not self.is_available():
            return None

        try:
            key = f"embed:{self._normalize_key(query_text)}"
            cached = self.client.get(key)
            if cached:
                return json.loads(cached)
            return None
        except Exception as e:
            logger.error(f"[Cache] Error retrieving embedding: {e}")
            return None

    def set_query_embedding(
        self,
        query_text: str,
        embedding: list[float],
        ttl: Optional[int] = None
    ) -> bool:
        """
        Cache query embedding
        
        Args:
            query_text: Query text
            embedding: Embedding vector
            ttl: Time-to-live in seconds
            
        Returns:
            Success status
        """
        if not self.is_available():
            return False

        try:
            key = f"embed:{self._normalize_key(query_text)}"
            ttl = ttl or self.QUERY_CACHE_TTL
            cache_value = json.dumps(embedding)
            self.client.setex(key, ttl, cache_value)
            return True
        except Exception as e:
            logger.error(f"[Cache] Error caching embedding: {e}")
            return False

    # ========== CACHE STATISTICS ==========

    def get_cache_stats(self) -> dict[str, Any]:
        """Get cache statistics"""
        if not self.is_available():
            return {"available": False}

        try:
            info = self.client.info()
            return {
                "available": True,
                "used_memory": info.get("used_memory_human", "N/A"),
                "connected_clients": info.get("connected_clients", 0),
                "hits": info.get("keyspace_hits", 0),
                "misses": info.get("keyspace_misses", 0),
                "expired_keys": info.get("expired_keys", 0),
            }
        except Exception as e:
            logger.error(f"[Cache] Error getting stats: {e}")
            return {"available": False, "error": str(e)}

    def clear_all(self) -> bool:
        """Clear all cache (use with caution)"""
        if not self.is_available():
            return False

        try:
            self.client.flushdb()
            logger.warning("[Cache] All cache cleared")
            return True
        except Exception as e:
            logger.error(f"[Cache] Error clearing cache: {e}")
            return False


# Singleton cache instance
_cache_instance: Optional[RecommendationCache] = None


def get_cache(redis_url: str = "redis://localhost:6379/0") -> RecommendationCache:
    """Get or create cache instance"""
    global _cache_instance
    if _cache_instance is None:
        _cache_instance = RecommendationCache(redis_url)
    return _cache_instance

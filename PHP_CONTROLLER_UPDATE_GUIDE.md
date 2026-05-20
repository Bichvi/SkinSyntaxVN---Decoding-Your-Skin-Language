"""
PHP Controller Update Guide for RAG-Based Recommendation System

This file provides code snippets and guidance for updating the HomeController
to work with the new LangChain-based recommendation endpoints.

The key changes:
1. Update fetchAiHybridRecommendations() to call /api/recommend/langchain-rag
2. Add error handling for the new API
3. Cache results in Redis (optional but recommended)
4. Add logging for debugging
"""

# ============================================
# PHP CODE SNIPPETS FOR HOMECONTROLLER
# ============================================

# In backend/app/controllers/HomeController.php, find the fetchAiHybridRecommendations() method
# and replace it with this new version:

"""
// NEW VERSION - LangChain RAG-Based
private function fetchAiHybridRecommendations(array $profile, string $query_text): array {
    $endpoint = defined('AI_LANGCHAIN_RECOMMENDATION_ENDPOINT')
        ? AI_LANGCHAIN_RECOMMENDATION_ENDPOINT
        : 'http://127.0.0.1:5000/api/recommend/langchain-rag';

    $timeout = defined('AI_LANGCHAIN_RECOMMENDATION_TIMEOUT')
        ? AI_LANGCHAIN_RECOMMENDATION_TIMEOUT
        : 30;

    try {
        // Prepare payload for LangChain RAG endpoint
        $payload = [
            'user_profile' => [
                'gioi_tinh' => (string)($profile['gioi_tinh'] ?? ''),
                'nam_sinh' => (int)($profile['nam_sinh'] ?? 0),
                'skin_type' => (string)($profile['skin_type'] ?? ''),
                'concerns' => array_values($profile['concerns'] ?? []),
                'avoid_ingredients' => array_values($profile['avoid_ingredients'] ?? []),
                'budget' => (int)($profile['budget'] ?? 0),
                'sensitivity' => (string)($profile['sensitivity'] ?? ''),
            ],
            'query_text' => $query_text,
            'top_k' => 5,
            'use_cache' => true,
        ];

        // Call LangChain RAG endpoint
        $response = $this->callHttpEndpoint($endpoint, $payload, $timeout);

        if (!is_array($response)) {
            error_log('Invalid response from LangChain RAG: ' . gettype($response));
            return [];
        }

        // Check if request was successful
        if (empty($response['ok'])) {
            error_log('LangChain RAG error: ' . ($response['message'] ?? 'Unknown error'));
            return [];
        }

        // Parse response
        return [
            'ok' => true,
            'message' => $response['message'] ?? 'Recommendation successful',
            'items' => $this->formatRecommendationItems($response['data'] ?? []),
            'summary' => $response['summary'] ?? '',
            'search_mode' => 'hybrid',
            'cache_hit' => $response['cache_hit'] ?? false,
            'query' => $response['query'] ?? $query_text,
        ];

    } catch (Throwable $e) {
        error_log('fetchAiHybridRecommendations error: ' . $e->getMessage());
        return [];
    }
}

// Helper method to format recommendation items for frontend
private function formatRecommendationItems(array $items): array {
    $formatted = [];
    foreach ($items as $item) {
        $formatted[] = [
            'id' => $item['id'] ?? '',
            'ten_san_pham' => $item['ten_san_pham'] ?? '',
            'thuong_hieu' => $item['thuong_hieu'] ?? '',
            'gia_ban' => (float)($item['gia_ban'] ?? 0),
            'mo_ta' => $item['mo_ta'] ?? '',
            'thanh_phan' => $item['thanh_phan'] ?? '',
            'tac_dung' => $item['tac_dung'] ?? '',
            'link_hinh_anh' => $item['image_url'] ?? $item['link_hinh_anh'] ?? '',
            'llm_explanation' => $item['llm_explanation'] ?? '',
            'score' => $item['score'] ?? 0,
            'reasons' => $item['reasons'] ?? [],
        ];
    }
    return $formatted;
}

// Helper method to make HTTP calls
private function callHttpEndpoint(string $url, array $payload, int $timeout = 30): mixed {
    $ch = curl_init($url);
    
    if (!$ch) {
        throw new RuntimeException('Failed to initialize curl');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SkinSyntaxVN/1.0',
        ],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP request failed: {$error}");
    }
    
    curl_close($ch);

    if ($http_code >= 400) {
        error_log("HTTP {$http_code}: {$response}");
        return null;
    }

    return json_decode($response, true);
}
"""

# ============================================
# CONFIG UPDATES
# ============================================

# Add these constants to backend/app/config/config.php:

"""
// LangChain RAG Recommendation Endpoint
defined('AI_LANGCHAIN_RECOMMENDATION_ENDPOINT') || define('AI_LANGCHAIN_RECOMMENDATION_ENDPOINT', 
    ss_env('AI_LANGCHAIN_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5000/api/recommend/langchain-rag'));

defined('AI_LANGCHAIN_RECOMMENDATION_TIMEOUT') || define('AI_LANGCHAIN_RECOMMENDATION_TIMEOUT', 
    ss_env('AI_LANGCHAIN_RECOMMENDATION_TIMEOUT', 30));

// Hybrid Search Endpoint (for direct retrieval without LLM)
defined('AI_HYBRID_SEARCH_ENDPOINT') || define('AI_HYBRID_SEARCH_ENDPOINT', 
    ss_env('AI_HYBRID_SEARCH_ENDPOINT', 'http://127.0.0.1:5000/api/recommend/hybrid-search'));

// Chat Endpoints
defined('AI_INGREDIENT_ANALYSIS_ENDPOINT') || define('AI_INGREDIENT_ANALYSIS_ENDPOINT', 
    ss_env('AI_INGREDIENT_ANALYSIS_ENDPOINT', 'http://127.0.0.1:5000/api/chat/ingredient-analysis'));

defined('AI_CACHE_STATS_ENDPOINT') || define('AI_CACHE_STATS_ENDPOINT', 
    ss_env('AI_CACHE_STATS_ENDPOINT', 'http://127.0.0.1:5000/api/cache/stats'));
"""

# ============================================
# ENVIRONMENT VARIABLES (.env)
# ============================================

"""
# LangChain Configuration
LANGCHAIN_ENABLED=true
AI_LANGCHAIN_RECOMMENDATION_ENDPOINT=http://127.0.0.1:5000/api/recommend/langchain-rag
AI_LANGCHAIN_RECOMMENDATION_TIMEOUT=30

# Redis Cache Configuration
REDIS_ENABLED=true
REDIS_URL=redis://localhost:6379/0
REDIS_KEY_PREFIX=skinsyntax_

# MongoDB Configuration (for hybrid search)
MONGODB_URI=mongodb://localhost:27017
MONGODB_DB_NAME=skinsyntax_rag

# LLM Configuration
GOOGLE_API_KEY=your-gemini-api-key
LLM_MODEL=gemini-2.5-flash
LLM_TEMPERATURE=0.7
"""

# ============================================
# FLOW DIAGRAM - NEW RAG-BASED RECOMMENDATION
# ============================================

"""
USER SUBMITS RECOMMENDATION REQUEST
    ↓
PHP Controller xulygoiy()
    ↓
fetchAiHybridRecommendations()
    ↓
POST to /api/recommend/langchain-rag
    ↓ (Flask App)
RecommendationChainBuilder.generate_recommendations()
    ├─ Check Cache (Redis)
    │   └─ If HIT: Return cached response
    │
    ├─ Hybrid Retrieval
    │   ├─ Keyword Search (MongoDB full-text)
    │   │   └─ BM25 scoring on product names, descriptions, ingredients
    │   │
    │   ├─ Semantic Search (Vector embeddings)
    │   │   └─ Cosine similarity between query embedding and product embeddings
    │   │
    │   └─ Combine Scores
    │       └─ final_score = 0.4 * keyword_score + 0.6 * semantic_score
    │
    ├─ LLM Processing
    │   ├─ Generate Explanations (per product)
    │   │   └─ Why this product matches the user profile
    │   │
    │   ├─ Generate Summary (overall recommendation)
    │   │   └─ Natural language summary of top recommendations
    │   │
    │   └─ Format Response
    │       └─ Return ranked products with LLM explanations
    │
    ├─ Cache Response (Redis)
    │   └─ TTL: 7 days for same profile/query combination
    │
    └─ Return JSON Response
        ↓
PHP Controller receives response
    ├─ Validate response
    ├─ Format for frontend
    └─ Return JSON to frontend
        ↓
Frontend displays results
    ├─ Product cards with:
    │   ├─ Product name & brand
    │   ├─ Price
    │   ├─ LLM explanation
    │   ├─ Match score
    │   └─ Reasons for recommendation
    │
    └─ Advice panel
        └─ Natural language summary from AI


KEY IMPROVEMENTS OVER OLD SYSTEM:
1. TRUE RAG: Retrieves products → Sends to LLM for reasoning
2. HYBRID SEARCH: Combines keyword + semantic search for better relevance
3. CACHING: Redis caching prevents re-processing similar queries
4. NATURAL LANGUAGE: LLM generates human-like explanations
5. COLLABORATIVE FILTERING: Can learn from similar user profiles
6. PROMPT ENGINEERING: Consistent, high-quality recommendations via system prompts
7. TRANSPARENCY: Returns reasons for each recommendation
8. FLEXIBILITY: Easy to update prompts without changing code
"""

# ============================================
# TESTING THE NEW ENDPOINT
# ============================================

# Test with curl:
"""
curl -X POST http://localhost:5000/api/recommend/langchain-rag \
  -H "Content-Type: application/json" \
  -d '{
    "user_profile": {
      "gioi_tinh": "Nữ",
      "nam_sinh": 1995,
      "skin_type": "Da dầu",
      "concerns": ["mụn", "bóng nhờn"],
      "avoid_ingredients": ["alcohol"],
      "budget": 500000,
      "sensitivity": "Bình thường"
    },
    "query_text": "Tôi muốn một serum dưới 500k",
    "top_k": 5,
    "use_cache": true
  }'
"""

# Test hybrid search only (without LLM):
"""
curl -X POST http://localhost:5000/api/recommend/hybrid-search \
  -H "Content-Type: application/json" \
  -d '{
    "query": "serum vitamin c cho da dầu",
    "user_profile": {
      "skin_type": "Da dầu",
      "budget": 500000
    },
    "top_k": 10
  }'
"""

# Test ingredient analysis:
"""
curl -X POST http://localhost:5000/api/chat/ingredient-analysis \
  -H "Content-Type: application/json" \
  -d '{
    "ingredient": "Niacinamide",
    "skin_type": "Da dầu"
  }'
"""

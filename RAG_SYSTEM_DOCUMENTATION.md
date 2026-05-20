# RAG-Based Skincare Recommendation System with LangChain

## Overview

This document explains the new RAG (Retrieval Augmented Generation) based recommendation system that has been implemented using LangChain, MongoDB, and LLMs (Large Language Models).

The system transforms the recommendation engine from a simple SQL-based approach to an intelligent AI system that:
1. **Retrieves** relevant products using hybrid search (keyword + semantic)
2. **Augments** the retrieved data with customer context and preferences
3. **Generates** natural, human-like explanations using LLMs
4. **Caches** similar queries to reduce LLM token usage

## Architecture

### Components

```
┌─────────────────────────────────────────────────────────────┐
│                      Frontend (PHP/JS)                       │
│            goiy.php - Recommendation Page                   │
└──────────────────────┬──────────────────────────────────────┘
                       │ POST /index.php?r=xulygoiy
                       ↓
┌─────────────────────────────────────────────────────────────┐
│               PHP Backend (Laravel-like)                     │
│         HomeController::xulygoiy()                          │
│    - Validate input                                         │
│    - Build user profile                                     │
│    - Merge with saved profile if logged in                 │
│    - Call AI service endpoint                              │
└──────────────────────┬──────────────────────────────────────┘
                       │ POST /api/recommend/langchain-rag
                       ↓
┌─────────────────────────────────────────────────────────────┐
│              Flask AI Service (Python)                       │
│           /api/recommend/langchain-rag                      │
└──────────────────────┬──────────────────────────────────────┘
                       │
        ┌──────────────┼──────────────┐
        ↓              ↓              ↓
   ┌────────┐  ┌──────────────┐  ┌────────┐
   │ Cache  │  │ Recomm.Chain │  │ Config │
   │ Check  │  │  Builder     │  │        │
   │(Redis) │  │              │  │        │
   └────────┘  └──────────────┘  └────────┘
        │              │              │
        │    Cache    │ No Cache     │
        │    HIT      │              │
        │      ↓      │      ↓       │
        │   Return    │   Continue   │
        │             │      ↓       │
        │             │  ┌────────────────────┐
        │             └→ │ Hybrid Retriever   │
        │                │                    │
        │                ├─ Keyword Search   │
        │                │  (MongoDB fulltext)
        │                │                    │
        │                ├─ Semantic Search  │
        │                │  (Vector embed.)   │
        │                │                    │
        │                └─ Combine & Score  │
        │                      │
        └──────────────────────┼─ Products
                               ↓
                        ┌──────────────┐
                        │  LLM Chain   │
                        │              │
                        ├─ System     │
                        │  Prompt     │
                        │              │
                        ├─ Per-Product│
                        │  Explanation│
                        │              │
                        ├─ Summary    │
                        │  Advice     │
                        │              │
                        └──────────────┘
                               ↓
                        ┌──────────────┐
                        │ Format JSON  │
                        │ & Cache      │
                        │ (Redis TTL)  │
                        └──────────────┘
                               ↓
                        Return to PHP
                               ↓
                        Return to Frontend
```

## Key Concepts

### 1. Hybrid Search (Retrieval Phase)

The hybrid retriever combines two complementary search methods:

#### Keyword Search (BM25)
- **What**: Full-text search on product metadata
- **Searches**: Product names, descriptions, ingredients, benefits
- **Score**: Relevance based on term frequency and document frequency
- **Use Case**: "serum", "vitamin c", "cho da dầu"

#### Semantic Search (Vector Similarity)
- **What**: Embedding-based similarity search
- **How**: Converts text to vectors, calculates cosine similarity
- **Searches**: Meaning and intent, not just keywords
- **Use Case**: Understanding "da dầu" ≈ "oily skin", "mụn ẩn" ≈ "acne"

#### Combination
```
final_score = 0.4 * keyword_score + 0.6 * semantic_score
```

This weighting (40/60) can be tuned based on observed performance.

### 2. RAG (Retrieval Augmented Generation)

The system follows the RAG pattern:

1. **Retrieve**: Find relevant products using hybrid search
2. **Augment**: Add customer profile context to the retrieval results
3. **Generate**: Use LLM to understand and generate responses

```
Query + Profile + Retrieved Products → LLM → Natural Language Response
```

**Why RAG?**
- Prevents LLM hallucination (can't recommend non-existent products)
- Grounds recommendations in actual database
- Reduces token usage (only sends retrieved products to LLM)
- Improves relevance (uses domain-specific retrieval)

### 3. Prompt Engineering

The system uses well-crafted prompts to guide LLM behavior:

#### System Prompt (Role Definition)
```
"Bạn là chuyên gia tư vấn mỹ phẩm chuyên nghiệp..."
```
Sets the tone, expertise level, and responsibilities.

#### User Prompt (Context + Task)
```
"=== HỒ SƠ KHÁCH HÀNG ===
Loại da: ...
Vấn đề: ...
Ngân sách: ...

=== DANH SÁCH SẢN PHẨM ===
[Products from hybrid search]

=== YÊU CẦU ===
1. Phân tích...
2. Đề xuất...
3. Giải thích..."
```

Provides structured context for the LLM to work with.

### 4. Caching Strategy

Redis caching optimizes performance for similar queries:

#### Query Cache
- **Key**: Hash of (skin_type + concerns + budget + query_text)
- **Value**: Full recommendation response
- **TTL**: 7 days
- **Hit Rate Target**: 30-40%

#### Collaborative Filtering
- Store profiles of similar users
- Reuse recommendations for similar profiles
- Reduces LLM calls by 30-50%

### 5. Database Integration

#### MongoDB
- Stores product catalog with metadata
- Supports full-text search indexes
- Supports vector search (Atlas only)
- Flexible schema for product attributes

#### PostgreSQL (Existing)
- Customer accounts and authentication
- Purchase history
- Survey responses
- Integration via sync process

## Files and Modules

### New Python Modules

```
ai-service-flask/rag/
├── prompt_templates.py      # Prompt engineering templates
├── hybrid_retriever.py       # Keyword + semantic search
├── langchain_setup.py        # LangChain chain builders
├── redis_cache.py            # Caching layer
└── __init__.py

api/
└── langchain_endpoints.py    # Flask route handlers
```

### Key Classes

#### SkincareHybridRetriever
- Performs hybrid search on MongoDB
- Combines keyword and semantic search
- Returns RetrievedProduct objects with scores

#### RecommendationChainBuilder
- Orchestrates retrieval + LLM processing
- Manages prompt templates
- Handles caching

#### RecommendationCache
- Redis-based caching
- Supports query, product, and user history caching
- Automatic cache invalidation

#### RecommendationPrompts
- Static prompt templates
- Prompt building utilities
- Cache key generation

## Usage Examples

### 1. Basic Recommendation Request

```python
from rag.langchain_setup import RecommendationChainBuilder
from rag.hybrid_retriever import SkincareHybridRetriever

# Setup
retriever = SkincareHybridRetriever(
    mongo_uri="mongodb://localhost:27017",
    db_name="skinsyntax_rag"
)
builder = RecommendationChainBuilder(llm=llm, retriever=retriever)

# Generate recommendations
result = builder.generate_recommendations(
    profile={
        "gioi_tinh": "Nữ",
        "nam_sinh": 1995,
        "skin_type": "Da dầu",
        "concerns": ["mụn", "bóng nhờn"],
        "budget": 500000,
    },
    query_text="Tôi muốn một serum dưới 500k",
    top_k=5
)

# result contains:
# {
#   "ok": true,
#   "items": [
#     {
#       "id": "...",
#       "ten_san_pham": "...",
#       "llm_explanation": "Sản phẩm này lý tưởng vì...",
#       "score": 0.92,
#       "reasons": [...]
#     },
#     ...
#   ],
#   "summary": "Dựa trên hồ sơ da của bạn..."
# }
```

### 2. Direct Hybrid Search

```python
from rag.hybrid_retriever import SkincareHybridRetriever

retriever = SkincareHybridRetriever(...)

products = retriever.retrieve(
    query="serum vitamin c",
    profile={
        "skin_type": "Da dầu",
        "budget": 500000
    },
    top_k=10
)

for product in products:
    print(f"{product.name}: {product.final_score}")
```

### 3. API Usage from PHP

```php
$payload = [
    'user_profile' => [
        'gioi_tinh' => 'Nữ',
        'nam_sinh' => 1995,
        'skin_type' => 'Da dầu',
        'concerns' => ['mụn', 'bóng nhờn'],
        'budget' => 500000,
    ],
    'query_text' => 'Tôi muốn một serum dưới 500k',
    'top_k' => 5,
    'use_cache' => true,
];

$response = json_decode(file_get_contents(
    'http://localhost:5000/api/recommend/langchain-rag',
    false,
    stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json\r\n',
            'content' => json_encode($payload),
            'timeout' => 30,
        ]
    ])
), true);
```

## Configuration

### Environment Variables

```bash
# LangChain Configuration
LANGCHAIN_ENABLED=true
AI_LANGCHAIN_RECOMMENDATION_ENDPOINT=http://127.0.0.1:5000/api/recommend/langchain-rag
AI_LANGCHAIN_RECOMMENDATION_TIMEOUT=30

# Redis Configuration
REDIS_URL=redis://localhost:6379/0
REDIS_KEY_PREFIX=skinsyntax_

# MongoDB Configuration
MONGODB_URI=mongodb://localhost:27017
MONGODB_DB_NAME=skinsyntax_rag

# LLM Configuration
GOOGLE_API_KEY=your-gemini-api-key
LLM_MODEL=gemini-2.5-flash
LLM_TEMPERATURE=0.7
```

### Python Config (config.py)

```python
class LlamaIndexConfig:
    # MongoDB
    MONGODB_URI = os.getenv('MONGODB_URI', 'mongodb://localhost:27017')
    MONGODB_DB_NAME = os.getenv('MONGODB_DB_NAME', 'skinsyntax_rag')
    MONGODB_PRODUCTS_COLLECTION = 'products'
    
    # LLM
    GOOGLE_API_KEY = os.getenv('GOOGLE_API_KEY')
    LLM_MODEL = os.getenv('LLM_MODEL', 'gemini-2.5-flash')
    
    # Redis
    REDIS_URL = os.getenv('REDIS_URL', 'redis://localhost:6379/0')
    
    # Retrieval
    HYBRID_KEYWORD_WEIGHT = 0.4
    HYBRID_SEMANTIC_WEIGHT = 0.6
    TOP_K_PRODUCTS = 10
    
    # Cache TTL
    QUERY_CACHE_TTL = 7 * 24 * 3600  # 7 days
    PRODUCT_CACHE_TTL = 30 * 24 * 3600  # 30 days
```

## Performance Tuning

### Keyword Weight Adjustment

```python
# More text-based matching
retriever = SkincareHybridRetriever(
    ...,
    keyword_weight=0.6,
    semantic_weight=0.4
)

# More semantic/meaning-based matching  
retriever = SkincareHybridRetriever(
    ...,
    keyword_weight=0.3,
    semantic_weight=0.7
)
```

### LLM Temperature

```python
# More deterministic, consistent responses
llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash",
    temperature=0.3  # Lower = more consistent
)

# More creative, varied responses
llm = ChatGoogleGenerativeAI(
    model="gemini-2.5-flash",
    temperature=0.9  # Higher = more creative
)
```

### Cache Strategy

```python
# Enable caching for all requests
builder.generate_recommendations(..., use_cache=True)

# Disable for real-time requests
builder.generate_recommendations(..., use_cache=False)
```

## Debugging

### Check Cache Status

```bash
curl http://localhost:5000/api/cache/stats
```

### Clear Cache

```bash
curl -X POST http://localhost:5000/api/cache/clear
```

### Health Check

```bash
curl http://localhost:5000/api/health/langchain
```

### Check Retrieval Only

```bash
curl -X POST http://localhost:5000/api/recommend/hybrid-search \
  -H "Content-Type: application/json" \
  -d '{
    "query": "serum cho da dầu",
    "user_profile": {"skin_type": "Da dầu"},
    "top_k": 10
  }'
```

## References

- **RAG Pattern**: https://www.promptingguide.ai/techniques/rag
- **MongoDB Atlas Vector Search**: https://docs.langchain.com/oss/python/integrations/vectorstores/mongodb_atlas
- **Hybrid Search**: https://docs.langchain.com/oss/python/integrations/retrievers/pinecone_hybrid_search
- **LangChain Documentation**: https://python.langchain.com/
- **Prompt Engineering**: https://www.promptingguide.ai/

## Migration from Old System

### Old Flow
```
User Input → SQL Query → Fallback to LLM → Return Results
```

### New Flow
```
User Input → Hybrid Search (Keyword + Semantic) → LLM Processing → Return Results
            ↓
           Cache (if similar query seen before)
```

### Benefits
1. **Better Relevance**: Semantic understanding vs keyword matching
2. **Consistency**: LLM always sees same products, generates similar explanations
3. **Performance**: Redis caching reduces LLM calls by 30-50%
4. **Transparency**: Returns reasons and match scores
5. **Flexibility**: Easy prompt tuning without code changes

## Next Steps

1. **Install Dependencies**: `pip install -r requirements.txt`
2. **Setup MongoDB**: Ensure MongoDB has product collection indexed
3. **Setup Redis**: For caching (optional but recommended)
4. **Configure Environment**: Set .env variables
5. **Initialize Components**: Call `init_langchain_components()` in Flask app
6. **Update PHP Controller**: Use new `/api/recommend/langchain-rag` endpoint
7. **Test**: Verify recommendations work as expected
8. **Monitor**: Check cache stats and performance metrics
9. **Tune**: Adjust weights and prompts based on feedback

## Troubleshooting

### Issue: "MongoDB hybrid service unavailable"
- Check MongoDB connection
- Verify MONGODB_URI in .env
- Ensure products collection exists

### Issue: "Redis not installed"
- Install: `pip install redis`
- Or run without caching (performance will be slower)

### Issue: "Slow recommendations"
- Check hybrid_search latency separately
- Check LLM latency separately
- Enable caching (check cache hit rate)
- Reduce top_k for retrieval

### Issue: "Low quality explanations"
- Adjust LLM temperature
- Update system prompt in prompt_templates.py
- Check retrieved products quality first
- Verify product metadata completeness

## Support

For issues or questions, check:
1. Logs in Flask app
2. Cache stats: `/api/cache/stats`
3. Health check: `/api/health/langchain`
4. MongoDB logs
5. Redis logs

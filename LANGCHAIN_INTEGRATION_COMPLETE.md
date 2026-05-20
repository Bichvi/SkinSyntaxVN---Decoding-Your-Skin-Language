# LangChain RAG Integration - Complete Implementation Guide

## ✅ Integration Status: COMPLETE

All LangChain components have been successfully integrated into the Flask application and PHP backend.

---

## 📋 Changes Made

### 1. **Flask Application (`ai-service-flask/app.py`)**

#### Added Imports:
```python
import logging  # For structured logging

# LangChain components import
from api.langchain_endpoints import init_langchain_components, register_langchain_routes
from langchain_google_genai import ChatGoogleGenerativeAI
```

#### Added Configuration & Initialization:
```python
# Configure Flask for LangChain
app.config['REDIS_URL'] = os.getenv('REDIS_URL', 'redis://localhost:6379/0')

# Initialize LangChain components if available
if init_langchain_components and register_langchain_routes and ChatGoogleGenerativeAI:
    try:
        # Initialize LLM
        google_api_key = LlamaIndexConfig.get_google_api_keys()
        if google_api_key:
            api_key = google_api_key[0]  # Use first available API key
            llm = ChatGoogleGenerativeAI(
                model="gemini-2.5-flash",
                google_api_key=api_key,
                temperature=0.7,
            )
            
            # Initialize LangChain components
            mongo_uri = LlamaIndexConfig.MONGODB_URI
            db_name = LlamaIndexConfig.MONGODB_DB_NAME
            init_langchain_components(app, llm, mongo_uri, db_name)
            
            # Register LangChain routes
            register_langchain_routes(app)
            
            print("[OK] LangChain RAG components initialized and routes registered")
        else:
            print("[WARN] GOOGLE_API_KEY not configured for LangChain")
    except Exception as langchain_init_error:
        print(f"[ERROR] Failed to initialize LangChain components: {langchain_init_error}")
else:
    print("[WARN] LangChain imports not available")
```

### 2. **PHP Backend Configuration (`backend/app/config/config.php`)**

#### Updated Endpoint:
**Changed from:**
```php
defined('AI_HYBRID_RECOMMENDATION_ENDPOINT') || define('AI_HYBRID_RECOMMENDATION_ENDPOINT', ss_env('AI_HYBRID_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5000/api/recommend/hybrid'));
```

**Changed to:**
```php
defined('AI_HYBRID_RECOMMENDATION_ENDPOINT') || define('AI_HYBRID_RECOMMENDATION_ENDPOINT', ss_env('AI_HYBRID_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5000/api/recommend/langchain-rag'));
```

This tells the PHP backend to use the new LangChain RAG endpoint instead of the old hybrid endpoint.

---

## 🚀 Setup Instructions

### Step 1: Install Python Dependencies

```bash
cd ai-service-flask
pip install -r requirements.txt
```

**Key packages installed:**
- `langchain>=0.2.0` - LLM orchestration framework
- `langchain-mongodb>=0.2.0` - MongoDB integration for LangChain
- `langchain-google-genai>=0.2.0` - Google Gemini integration
- `redis>=5.0.0` - Redis client for caching
- `numpy>=1.24.0` - Numerical computing

### Step 2: Verify Environment Variables

Ensure your `.env` file has:

```env
# Google Gemini API Key (required for LLM)
GOOGLE_API_KEY=<your-api-key>
GOOGLE_API_KEYS=<key1>,<key2>,<key3>  # Optional: multiple keys for fallback

# MongoDB Connection
MONGODB_URI=mongodb://localhost:27017
MONGODB_DB_NAME=skinsyntax  # Or your database name

# Redis Connection (for caching)
REDIS_URL=redis://localhost:6379/0

# LLM Configuration
RECOMMENDATION_MODEL=gemini-2.5-flash
TEMPERATURE=0.7
```

### Step 3: Verify MongoDB

Ensure:
1. MongoDB is running (`mongod` service is active)
2. Database exists with product collection
3. Full-text indexes are created on the collection:

```javascript
// Run in MongoDB shell
db.product_collection.createIndex({ 
  "ten_san_pham": "text", 
  "mo_ta": "text", 
  "thanh_phan": "text",
  "thuong_hieu": "text"
})
```

### Step 4: Verify Redis

```bash
# Check Redis is running
redis-cli ping
# Should return: PONG
```

### Step 5: Start Flask Application

```bash
cd ai-service-flask
python app.py
```

**Expected output:**
```
 * Running on http://0.0.0.0:5000
[OK] LlamaIndex initialized successfully
[OK] LangChain RAG components initialized and routes registered
```

---

## 📡 API Endpoints

### Main Recommendation Endpoint
**Endpoint:** `POST /api/recommend/langchain-rag`

**Request:**
```json
{
  "user_profile": {
    "gioi_tinh": "Nữ",
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
```

**Response:**
```json
{
  "ok": true,
  "items": [
    {
      "product_id": "prod_123",
      "name": "Serum A",
      "brand": "Brand X",
      "price": 450000,
      "llm_explanation": "Sản phẩm này phù hợp vì...",
      "keyword_score": 0.85,
      "semantic_score": 0.92
    }
  ],
  "summary": "Tôi đã tìm thấy 5 sản phẩm phù hợp nhất...",
  "search_mode": "hybrid",
  "cache_hit": false
}
```

### Hybrid Search Only (No LLM)
**Endpoint:** `POST /api/recommend/hybrid-search`

```json
{
  "query": "serum cho da dầu",
  "user_profile": {...},
  "top_k": 10
}
```

### Ingredient Analysis
**Endpoint:** `POST /api/chat/ingredient-analysis`

```json
{
  "ingredient": "Vitamin C",
  "skin_type": "Da dầu"
}
```

### Cache Statistics
**Endpoint:** `GET /api/cache/stats`

**Response:**
```json
{
  "total_keys": 42,
  "recommendation_cache_keys": 30,
  "product_cache_keys": 10,
  "user_history_keys": 2,
  "total_memory_mb": 2.5
}
```

### Clear Cache (Admin)
**Endpoint:** `POST /api/cache/clear`

### Health Check
**Endpoint:** `GET /api/health/langchain`

**Response:**
```json
{
  "ok": true,
  "components": {
    "recommendation_builder": true,
    "chatbot_builder": true,
    "hybrid_retriever": true,
    "cache": true
  }
}
```

---

## 🧪 Testing Workflow

### 1. Test Flask Health Check

```bash
curl http://localhost:5000/api/health/langchain
```

Expected: All components should be `true`

### 2. Test LangChain Recommendation

```bash
curl -X POST http://localhost:5000/api/recommend/langchain-rag \
  -H "Content-Type: application/json" \
  -d '{
    "user_profile": {
      "skin_type": "Da dầu",
      "concerns": ["mụn"]
    },
    "query_text": "Tôi cần kem chống nắng cho da dầu",
    "top_k": 5
  }'
```

### 3. Test Recommendation Page

1. Open: `http://localhost/VN/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/public/index.php?r=goiy`
2. Fill in the form:
   - Skin Type: "Da dầu"
   - Concerns: Select "Mụn"
   - Budget: 500,000 VND
3. Submit form
4. View recommendations - should now show:
   - Product names with LLM explanations
   - Prices and ratings
   - "Related products" section

### 4. Monitor Logs

During the test, check the Flask console for:
```
[API] Generating RAG recommendations for skin_type=Da dầu
[Hybrid] Keyword search: found 15 products
[Hybrid] Semantic search: found 12 products
[LLM] Generated recommendations in 2.3s
[Cache] Storing result in Redis
```

---

## 🔍 Troubleshooting

### Issue: "LangChain components import failed"

**Solution:**
```bash
# Reinstall LangChain packages
pip install --upgrade langchain langchain-mongodb langchain-google-genai
```

### Issue: "Recommendation service not initialized"

**Check:**
1. Flask app is running
2. `GOOGLE_API_KEY` is set in `.env`
3. MongoDB is running
4. View Flask console for initialization errors

### Issue: Recommendations page shows old results

**Root Cause:** PHP endpoint still pointing to old URL

**Fix:**
1. Verify config.php has: `'http://127.0.0.1:5000/api/recommend/langchain-rag'`
2. Clear PHP cache: Delete `backend/storage/cache/*`
3. Hard refresh browser: `Ctrl+Shift+Delete` (Chrome)

### Issue: "GOOGLE_API_KEY chưa được cấu hình"

**Fix:**
1. Check `.env` file exists and is readable
2. Verify `GOOGLE_API_KEY` is set
3. Restart Flask: `python app.py`

### Issue: Redis connection error

**Solution:**
```bash
# Start Redis if not running
redis-server

# Or on Windows (if Redis installed):
redis-cli ping  # Check connection
```

### Issue: MongoDB connection timeout

**Check:**
```bash
# Test MongoDB connection
mongosh "mongodb://localhost:27017"
# Or
mongo
```

---

## 📊 Performance Metrics

### Expected Response Times

| Operation | Time | Notes |
|-----------|------|-------|
| Keyword Search | 200-400ms | BM25 on full-text index |
| Semantic Search | 1-2s | Gemini embedding generation |
| LLM Explanation | 1-3s | Gemini text generation |
| **Total (cached)** | 100-150ms | From Redis |
| **Total (uncached)** | 2-5s | First request or TTL expired |

### Cache Hit Rate Expectations

- **After 1 hour:** 15-20% (similar profiles)
- **After 1 day:** 25-35% (common searches)
- **After 1 week:** 35-45% (frequent patterns)

---

## 🔐 Security Considerations

### API Keys

✅ **Safe:**
- `GOOGLE_API_KEY` in `.env` (never commit)
- Multiple API key rotation supported

⚠️ **Important:**
- Don't log full API keys
- Rotate keys periodically
- Use IAM roles in production

### Cache Security

✅ **Safe:**
- Redis password: Set in `.env` if needed
- Cache TTL prevents stale data
- User data isolated by cache keys

### MongoDB Access

✅ **Safe:**
- Bind MongoDB to localhost only
- Use authentication in production
- Whitelist IP addresses

---

## 📚 Architecture Review

### New Components

1. **`api/langchain_endpoints.py`** - Flask route handlers
2. **`rag/langchain_setup.py`** - LLM chain orchestration
3. **`rag/hybrid_retriever.py`** - Keyword + semantic search
4. **`rag/prompt_templates.py`** - Prompt engineering templates
5. **`rag/redis_cache.py`** - Caching logic

### Data Flow

```
[PHP Form] 
  ↓ POST to /api/recommend/langchain-rag
[Flask Endpoint]
  ↓
[Validate Input]
  ↓
[Check Redis Cache] 
  ↓ (miss)
[Hybrid Retrieval]
  ├─ Keyword Search (MongoDB)
  └─ Semantic Search (Gemini embeddings)
  ↓
[Combine Results]
  ↓
[LLM Generation] (Gemini)
  ├─ System Prompt
  ├─ Product Context
  └─ Generate Explanations
  ↓
[Format Response]
  ↓
[Cache in Redis]
  ↓
[Return JSON]
  ↓
[PHP Processes]
  ↓
[Display on Page]
```

---

## ✨ Next Steps (Optional Enhancements)

1. **Vector Embeddings Storage:**
   - Pre-compute product embeddings
   - Store in MongoDB Atlas with `vector_search`
   - Replace Gemini embeddings for speed

2. **Analytics:**
   - Track recommendation accuracy
   - Monitor cache hit rates
   - Log user feedback

3. **A/B Testing:**
   - Compare LLM explanations with rule-based
   - Measure engagement metrics

4. **Multi-language Support:**
   - Add translations in prompt templates
   - Support English, Vietnamese, etc.

---

## 📞 Support

For issues or questions:

1. Check logs: `Flask console` and `PHP error logs`
2. Verify setup: `curl http://localhost:5000/api/health/langchain`
3. Test endpoints: Use provided curl examples
4. Review documentation: See `RAG_SYSTEM_DOCUMENTATION.md`

---

## ✅ Verification Checklist

- [ ] Flask starts without errors
- [ ] `/api/health/langchain` returns all components ready
- [ ] MongoDB has products indexed
- [ ] Redis is running and accessible
- [ ] `.env` has `GOOGLE_API_KEY` set
- [ ] PHP config points to `/api/recommend/langchain-rag`
- [ ] Recommendation page shows new LLM-powered results
- [ ] Cache is working (check `/api/cache/stats`)
- [ ] Response times are reasonable (2-5s first request)
- [ ] Subsequent requests are faster (cached results)

---

**Status:** ✅ Ready for Production Testing

**Integration Date:** 2024

**Last Updated:** Integration complete - All components active

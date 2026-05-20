# Quick Start Guide - RAG Recommendation System Implementation

## Step 1: Install Dependencies

```bash
cd ai-service-flask

# Update requirements.txt with new packages
pip install langchain>=0.2.0
pip install langchain-mongodb>=0.2.0
pip install langchain-google-genai>=0.2.0
pip install langchain-openai>=0.2.0
pip install redis>=5.0.0
pip install numpy>=1.24.0

# Or install everything at once
pip install -r requirements.txt
```

## Step 2: Setup Environment Variables

Create or update `.env` file in project root:

```bash
# Flask
FLASK_ENV=development
FLASK_PORT=5000

# Google Gemini API
GOOGLE_API_KEY=your-gemini-api-key-here

# MongoDB
MONGODB_URI=mongodb://localhost:27017
MONGODB_DB_NAME=skinsyntax_rag
MONGODB_PRODUCTS_COLLECTION=products

# Redis
REDIS_URL=redis://localhost:6379/0
REDIS_ENABLED=true

# LLM Configuration
LLM_MODEL=gemini-2.5-flash
LLM_TEMPERATURE=0.7

# LangChain
LANGCHAIN_ENABLED=true
AI_LANGCHAIN_RECOMMENDATION_ENDPOINT=http://127.0.0.1:5000/api/recommend/langchain-rag
AI_LANGCHAIN_RECOMMENDATION_TIMEOUT=30
```

## Step 3: Initialize MongoDB

Ensure your products collection has the required fields:

```javascript
// MongoDB query to check collection
db.products.findOne()

// Should have fields like:
{
  _id: ObjectId(...),
  id: "product_id",
  ten_san_pham: "Product Name",
  thuong_hieu: "Brand",
  gia_ban: 500000,
  mo_ta: "Description",
  thanh_phan: "Ingredients list",
  tac_dung: "Benefits",
  link_hinh_anh: "image_url",
  loai_da: ["Da dầu", "Da khô"]  // Optional
}
```

Create indexes:

```javascript
// Text index for keyword search
db.products.createIndex({
  "ten_san_pham": "text",
  "mo_ta": "text",
  "thanh_phan": "text",
  "thuong_hieu": "text"
})

// Regular indexes for filtering
db.products.createIndex({"gia_ban": 1})
db.products.createIndex({"loai_da": 1})
db.products.createIndex({"tac_dung": 1})
```

## Step 4: Setup Redis (Optional but Recommended)

```bash
# Start Redis (or use Docker)
docker run -d -p 6379:6379 redis:latest

# Test connection
redis-cli ping  # Should return PONG
```

## Step 5: Update Flask App

In `ai-service-flask/app.py`, add LangChain initialization:

```python
# At the top with other imports
from api.langchain_endpoints import init_langchain_components, register_langchain_routes
from langchain_google_genai import ChatGoogleGenerativeAI
from config import LlamaIndexConfig

# After app creation (after app = Flask(__name__))
try:
    # Initialize LLM
    llm = ChatGoogleGenerativeAI(
        model="gemini-2.5-flash",
        google_api_key=LlamaIndexConfig.GOOGLE_API_KEY,
        temperature=0.7,
    )
    
    # Initialize LangChain components
    init_langchain_components(
        app,
        llm,
        LlamaIndexConfig.MONGODB_URI,
        LlamaIndexConfig.MONGODB_DB_NAME
    )
    
    # Register API routes
    register_langchain_routes(app)
    
    logger.info("[OK] LangChain RAG components initialized")
except Exception as e:
    logger.error(f"[ERROR] Failed to initialize LangChain: {e}")
    # System can still work with fallback recommendation method
```

## Step 6: Update PHP Controller

In `backend/app/controllers/HomeController.php`, update the `fetchAiHybridRecommendations()` method:

```php
private function fetchAiHybridRecommendations(array $profile, string $query_text): array {
    $endpoint = 'http://127.0.0.1:5000/api/recommend/langchain-rag';
    $timeout = 30;

    try {
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

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 400) {
            error_log("LangChain API error ($http_code): $response");
            return [];
        }

        $data = json_decode($response, true);
        
        return [
            'ok' => !empty($data['ok']),
            'message' => $data['message'] ?? '',
            'items' => $data['items'] ?? [],
            'summary' => $data['summary'] ?? '',
            'search_mode' => 'hybrid',
        ];

    } catch (Throwable $e) {
        error_log('fetchAiHybridRecommendations error: ' . $e->getMessage());
        return [];
    }
}
```

## Step 7: Test the System

### Test 1: Check Flask App is Running

```bash
curl http://localhost:5000/api/health/langchain
```

Expected response:
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

### Test 2: Test Hybrid Search Endpoint

```bash
curl -X POST http://localhost:5000/api/recommend/hybrid-search \
  -H "Content-Type: application/json" \
  -d '{
    "query": "serum vitamin c cho da dầu",
    "user_profile": {
      "skin_type": "Da dầu",
      "budget": 500000
    },
    "top_k": 5
  }'
```

### Test 3: Test Full RAG Endpoint

```bash
curl -X POST http://localhost:5000/api/recommend/langchain-rag \
  -H "Content-Type: application/json" \
  -d '{
    "user_profile": {
      "gioi_tinh": "Nữ",
      "nam_sinh": 1995,
      "skin_type": "Da dầu",
      "concerns": ["mụn", "bóng nhờn"],
      "avoid_ingredients": [],
      "budget": 500000,
      "sensitivity": "Bình thường"
    },
    "query_text": "Tôi muốn một serum dưới 500k",
    "top_k": 5,
    "use_cache": true
  }'
```

### Test 4: Test Cache Status

```bash
curl http://localhost:5000/api/cache/stats
```

### Test 5: Visit the Recommendation Page

Open browser and navigate to:
```
http://localhost/VN/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/public/index.php?r=goiy
```

Fill out the form and click "Nhận gợi ý"

## Step 8: Verify Results

When you click "Nhận gợi ý", you should see:

1. ✅ Products listed with match scores
2. ✅ Natural language explanations from LLM
3. ✅ Reasons for each recommendation
4. ✅ Product images, prices, and details
5. ✅ Summary advice at the top

## Troubleshooting

### Problem: "MongoDB hybrid recommendation service is not available"

**Solution:**
1. Check MongoDB is running: `mongosh` or `mongo`
2. Check MONGODB_URI in .env
3. Verify products collection exists: `db.products.count()`
4. Check if collection has data

### Problem: "LLM not initialized"

**Solution:**
1. Verify GOOGLE_API_KEY is set correctly
2. Check LLM model name is correct (gemini-2.5-flash)
3. Check internet connection (API calls to Google)
4. Check logs: `tail -f flask_app.log`

### Problem: Slow responses (>10 seconds)

**Solution:**
1. Check hybrid search latency separately: `/api/recommend/hybrid-search`
2. Check MongoDB query performance
3. Check if Redis is properly configured and cached
4. Try reducing top_k (fewer products to process)

### Problem: Poor quality recommendations

**Solution:**
1. Check retrieved products are correct: `/api/recommend/hybrid-search`
2. If retrieval is good, adjust LLM temperature or prompt
3. Verify product data quality (description, ingredients, benefits)
4. Check user profile is being passed correctly

## Verification Checklist

- [ ] Requirements installed: `pip list | grep langchain`
- [ ] .env file configured with API keys
- [ ] MongoDB running and accessible
- [ ] Redis running (optional)
- [ ] Flask app starts without errors: `python app.py`
- [ ] Health check passes: `curl http://localhost:5000/api/health/langchain`
- [ ] Hybrid search returns products
- [ ] LangChain RAG endpoint returns recommendations with explanations
- [ ] PHP controller successfully calls the new endpoint
- [ ] Recommendation page displays results

## Performance Baseline

Expected response times (typical):

| Component | Time | Notes |
|-----------|------|-------|
| Keyword search | 100-300ms | MongoDB full-text search |
| Semantic search | 200-500ms | Vector embedding + similarity |
| LLM processing | 2-5s | Per-product explanation generation |
| **Total (no cache)** | **3-6s** | First-time request |
| **Total (cache hit)** | **50ms** | Cached response |

## Next Steps After Verification

1. **Monitor Performance**: Check response times in production
2. **Tune Weights**: Adjust keyword/semantic weight ratio
3. **Collect Feedback**: Track user satisfaction scores
4. **Optimize Prompts**: Refine based on sample outputs
5. **Scale**: Consider multi-GPU setup if needed
6. **Update Documentation**: Document any custom changes

## Rollback Plan

If you need to revert to the old system:

1. In PHP controller, revert `fetchAiHybridRecommendations()` method
2. Don't initialize LangChain components in Flask app
3. The old fallback system will still work

## Getting Help

If something doesn't work:

1. **Check logs**:
   - Flask: `tail -f flask_app.log`
   - PHP: `tail -f backend_errors.log`
   - MongoDB: `mongosh` connection test

2. **Test components independently**:
   - Test hybrid search alone
   - Test LLM generation with mock products
   - Test cache separately

3. **Enable debug mode**:
   ```python
   # In app.py
   app.run(debug=True)
   ```

4. **Check API responses**:
   - Use curl or Postman
   - Look at HTTP status codes and error messages
   - Check response body for detailed errors

5. **Monitor cache**:
   - `redis-cli`
   - `keys *` to see all cache entries
   - `get rec:*` to see specific caches

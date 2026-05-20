# ✅ Integration Verification Checklist

Use this checklist to verify the LangChain integration is complete and working.

---

## 📋 File Changes Verification

### Flask App (`ai-service-flask/app.py`)

- [ ] Line 33: Has import `from api.langchain_endpoints import init_langchain_components, register_langchain_routes`
- [ ] Line 34: Has import `from langchain_google_genai import ChatGoogleGenerativeAI`
- [ ] Line 14: Has import `import logging`
- [ ] Lines 47-74: Has LangChain initialization block with try/except
- [ ] Line 48: Has condition `if init_langchain_components and register_langchain_routes and ChatGoogleGenerativeAI:`
- [ ] Line 54: Has `llm = ChatGoogleGenerativeAI(model="gemini-2.5-flash", ...)`
- [ ] Line 63: Has `init_langchain_components(app, llm, mongo_uri, db_name)`
- [ ] Line 66: Has `register_langchain_routes(app)`
- [ ] Line 69: Has success message `[OK] LangChain RAG components initialized and routes registered`

**Verification Command:**
```bash
grep -n "init_langchain_components\|register_langchain_routes\|ChatGoogleGenerativeAI" ai-service-flask/app.py | head -20
```

### PHP Config (`backend/app/config/config.php`)

- [ ] Line 114: Contains `/api/recommend/langchain-rag` (NOT `/api/recommend/hybrid`)
- [ ] Line 114: Contains `AI_HYBRID_RECOMMENDATION_ENDPOINT`
- [ ] Line 115: Timeout is still `30` seconds

**Verification Command:**
```bash
grep -n "AI_HYBRID_RECOMMENDATION_ENDPOINT" backend/app/config/config.php
```

**Expected Output:**
```
114: defined('AI_HYBRID_RECOMMENDATION_ENDPOINT') || define('AI_HYBRID_RECOMMENDATION_ENDPOINT', ss_env('AI_HYBRID_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5000/api/recommend/langchain-rag'));
```

---

## 🔧 Environment Setup Verification

### Python Dependencies

- [ ] Installed langchain: `pip list | grep langchain`
- [ ] Installed langchain-mongodb: `pip list | grep langchain`
- [ ] Installed langchain-google-genai: `pip list | grep langchain`
- [ ] Installed redis: `pip list | grep redis`
- [ ] Installed numpy: `pip list | grep numpy`

**Installation Command:**
```bash
cd ai-service-flask
pip install -r requirements.txt
```

### Environment Variables (`.env`)

- [ ] `GOOGLE_API_KEY` is set and not empty
- [ ] `GOOGLE_API_KEYS` (optional) has multiple keys for fallback
- [ ] `MONGODB_URI` points to your MongoDB instance
- [ ] `MONGODB_DB_NAME` is set (default: 'skinsyntax' or your db name)
- [ ] `REDIS_URL` points to your Redis instance

**Check .env:**
```bash
cat .env | grep -E "GOOGLE_API_KEY|MONGODB_URI|REDIS_URL"
```

---

## 🚀 Service Verification

### MongoDB

- [ ] MongoDB service is running
- [ ] Can connect: `mongosh mongodb://localhost:27017`
- [ ] Database exists with product collection
- [ ] Full-text index created on products collection

**Verification:**
```bash
# From MongoDB shell:
db.product_collection.getIndexes()  # Should show text index
```

### Redis

- [ ] Redis service is running
- [ ] Can ping: `redis-cli ping` (should return `PONG`)

**Verification:**
```bash
redis-cli ping
```

### Flask Application

- [ ] Flask starts without errors:
  ```bash
  cd ai-service-flask
  python app.py
  ```
- [ ] Console shows: `[OK] LangChain RAG components initialized and routes registered`
- [ ] Flask server listens on `http://0.0.0.0:5000`

**Expected Console Output:**
```
 * Running on http://0.0.0.0:5000
[OK] LlamaIndex initialized successfully
[OK] LangChain RAG components initialized and routes registered
 * WARNING: This is a development server. Do not use it in production.
```

---

## 🔍 Endpoint Verification

All endpoints should be accessible. Test with curl:

### Health Check Endpoint
```bash
curl http://localhost:5000/api/health/langchain
```

**Expected Response (All True):**
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

- [ ] Response status is 200
- [ ] All components are `true`

### Recommendation Endpoint
```bash
curl -X POST http://localhost:5000/api/recommend/langchain-rag \
  -H "Content-Type: application/json" \
  -d '{
    "user_profile": {
      "skin_type": "Da dầu",
      "concerns": ["mụn"]
    },
    "query_text": "Tôi cần sản phẩm chống nắng"
  }'
```

**Expected Response:**
```json
{
  "ok": true,
  "items": [...],
  "summary": "...",
  "search_mode": "hybrid",
  "cache_hit": false
}
```

- [ ] Response status is 200
- [ ] `"ok": true`
- [ ] `"items"` array has products
- [ ] Each item has `llm_explanation` field
- [ ] `"search_mode"` is "hybrid"

### Cache Stats Endpoint
```bash
curl http://localhost:5000/api/cache/stats
```

**Expected Response:**
```json
{
  "total_keys": 1,
  "recommendation_cache_keys": 1,
  ...
}
```

- [ ] Response status is 200
- [ ] Shows cache statistics

---

## 🌐 Web Application Verification

### PHP Backend

- [ ] PHP server is running on `http://localhost/VN/...`
- [ ] No PHP errors in logs
- [ ] Can access home page

### Recommendation Page

1. **Open Page:**
   ```
   http://localhost/VN/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/public/index.php?r=goiy
   ```

2. **Fill Form:**
   - [ ] Skin Type dropdown has options
   - [ ] Concerns checkboxes available
   - [ ] Budget field present
   - [ ] Form submits successfully

3. **Check Results:**
   - [ ] Page shows recommendations (not empty)
   - [ ] Each product has:
     - [ ] Product name
     - [ ] Brand
     - [ ] Price
     - [ ] "Sản phẩm này phù hợp vì..." explanation (LLM generated)
     - [ ] Rating/Star display
   - [ ] No error messages shown
   - [ ] Results load within 5 seconds (first time)

4. **Test Caching:**
   - [ ] Submit same form again
   - [ ] Results load instantly (<200ms)
   - [ ] Same products appear in same order

---

## 📊 Performance Verification

### Response Times

**First Request (Uncached):**
- [ ] Response time: 2-5 seconds (normal range)
- [ ] Flask logs show: `[Hybrid]`, `[LLM]`, `[Cache]` operations

**Subsequent Requests (Cached):**
- [ ] Response time: <200ms (fast)
- [ ] Flask logs show: `[Cache] Hit` message

### Log Output During Request

Expected console log during first request:
```
[API] Generating RAG recommendations for skin_type=Da dầu
[Hybrid] Keyword search: found 15 products
[Hybrid] Semantic search: found 12 products  
[LLM] Generated explanations in 1.5s
[Cache] Storing result in Redis
```

- [ ] All these log lines appear
- [ ] No error messages mixed in

---

## 🔐 Security Verification

- [ ] API keys are in `.env` (not hardcoded in source)
- [ ] `.env` is in `.gitignore` (not committed to repo)
- [ ] No API keys printed to console
- [ ] MongoDB bound to localhost only
- [ ] Redis password configured (if exposed to network)

---

## 🆘 Troubleshooting Checklist

If something isn't working, verify:

### If Flask doesn't start:
- [ ] Python 3.8+ installed: `python --version`
- [ ] Virtual environment activated
- [ ] Requirements installed: `pip install -r requirements.txt`
- [ ] Port 5000 not in use: `netstat -an | grep 5000`

### If endpoints return errors:
- [ ] MongoDB is running and accessible
- [ ] Redis is running and accessible  
- [ ] `.env` file has valid API key
- [ ] Product collection exists in MongoDB
- [ ] Full-text index created on collection

### If recommendations page shows nothing:
- [ ] PHP config changed to use `/api/recommend/langchain-rag`
- [ ] PHP cache cleared: `rm -rf backend/storage/cache/*`
- [ ] Browser cache cleared: `Ctrl+Shift+Delete`
- [ ] Hard refresh page: `Ctrl+F5`
- [ ] Flask health check returns all true

### If responses are slow:
- [ ] First request is slow (2-5s) - normal
- [ ] Subsequent requests fast (<200ms) - cache hit
- [ ] Check cache: `curl http://localhost:5000/api/cache/stats`
- [ ] Monitor: `redis-cli KEYS "*rec:*" | wc -l` (should grow)

---

## ✅ Final Sign-Off

When all items are checked:

1. **Development Environment Ready** ✅
2. **Services Running** ✅
3. **Code Changes Applied** ✅
4. **Endpoints Functional** ✅
5. **Web Page Displaying Results** ✅
6. **Performance Acceptable** ✅

---

## 📝 Summary

| Component | Status | Verified |
|-----------|--------|----------|
| Flask app.py | Modified | [ ] |
| PHP config.php | Modified | [ ] |
| Python dependencies | Installed | [ ] |
| MongoDB | Running | [ ] |
| Redis | Running | [ ] |
| LangChain endpoints | Registered | [ ] |
| Health check | Working | [ ] |
| Recommendation page | Displaying results | [ ] |
| Performance | Acceptable | [ ] |

---

**When all items are checked, the integration is complete and working!** 🎉

If you get stuck on any item, refer back to:
- `LANGCHAIN_INTEGRATION_COMPLETE.md` - Detailed setup guide
- `LANGCHAIN_INTEGRATION_QUICKSTART.md` - Quick reference
- `PROBLEM_SOLVED_DOCUMENTATION.md` - How the fix works

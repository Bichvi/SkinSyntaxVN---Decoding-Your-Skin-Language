# 🎯 IMMEDIATE ACTION ITEMS - Get Your Recommendations Working

Your LangChain RAG integration is complete. Follow these steps in order:

---

## Step 1: Verify Python Setup (2 minutes)

```bash
# Navigate to Flask app
cd ai-service-flask

# Install dependencies
pip install -r requirements.txt

# Verify installations
pip list | grep -E "langchain|redis|numpy"
```

**Expected Output:**
```
langchain-core             0.2.x
langchain-google-genai     0.2.x
langchain-mongodb          0.2.x
redis                      5.0.x
numpy                      1.24.x
```

---

## Step 2: Start Required Services (3 minutes)

**Open 3 different terminals:**

### Terminal 1: MongoDB
```bash
# Windows with MongoDB installed as service:
net start MongoDB

# Or manually:
mongod
```

**Expected:** `Listening on 127.0.0.1:27017`

### Terminal 2: Redis
```bash
# Windows with Redis installed:
redis-server

# Or direct command:
redis-cli ping  # Should return: PONG
```

**Expected:** `Ready to accept connections`

### Terminal 3: Flask App (only start after MongoDB and Redis are ready)
```bash
cd ai-service-flask
python app.py
```

**Expected Console Output:**
```
 * Running on http://0.0.0.0:5000
[OK] LlamaIndex initialized successfully
[OK] LangChain RAG components initialized and routes registered
 * WARNING: This is a development server...
```

✅ If you see `[OK] LangChain RAG components initialized and routes registered`, the integration is working!

---

## Step 3: Quick Endpoint Test (1 minute)

Open a new terminal and test:

```bash
curl http://localhost:5000/api/health/langchain
```

**Expected Response:**
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

✅ All `true` = Everything working!

---

## Step 4: Test Recommendation API (2 minutes)

```bash
curl -X POST http://localhost:5000/api/recommend/langchain-rag \
  -H "Content-Type: application/json" \
  -d '{
    "user_profile": {
      "skin_type": "Da dầu",
      "concerns": ["mụn"]
    },
    "query_text": "Tôi cần kem chống nắng"
  }'
```

**Expected Response:**
```json
{
  "ok": true,
  "items": [
    {
      "product_id": "...",
      "name": "Product Name",
      "brand": "Brand",
      "price": 450000,
      "llm_explanation": "Sản phẩm này phù hợp vì..."
    }
  ],
  "summary": "Tôi đã tìm thấy...",
  "search_mode": "hybrid",
  "cache_hit": false
}
```

✅ If you see products with `llm_explanation`, the LLM is working!

---

## Step 5: Test Recommendation Page (2 minutes)

1. **Open your browser:**
   ```
   http://localhost/VN/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/public/index.php?r=goiy
   ```

2. **Fill the form:**
   - **Loại da / Skin Type:** Select "Da dầu"
   - **Concerns:** Check "Mụn"
   - **Budget:** 300,000-500,000 VND

3. **Click "Tìm sản phẩm" (Find Products)**

4. **Check the results:**
   - [ ] Page shows products (not empty)
   - [ ] Each product has a name and price
   - [ ] Each product has an explanation starting with "Sản phẩm này phù hợp vì..."
   - [ ] No error messages shown
   - [ ] Page loaded within 5 seconds

✅ If you see products with LLM explanations, your integration is working!

---

## ⚡ Quick Verification (30 seconds)

If everything is working, you should see:

### In Flask Console
```
[API] Generating RAG recommendations for skin_type=Da dầu
[Hybrid] Keyword search: found 15 products
[Hybrid] Semantic search: found 12 products
[LLM] Generated explanations in 1.8s
[Cache] Storing result in Redis
```

### On Recommendation Page
```
✓ Serum Vitamin C
  Brand: Ordinary
  450,000 VND
  ⭐ 4.8
  
  Sản phẩm này phù hợp vì: Serum này chứa Vitamin C
  giúp làm sáng da và chống oxy hóa, rất thích hợp
  cho da dầu có mụn...

✓ Kem chống nắng UV
  Brand: Sunplay
  380,000 VND
  ⭐ 4.9
  
  Sản phẩm này phù hợp vì: Công thức nhẹ, không
  gây nhờn, phù hợp cho da dầu...
```

---

## 🆘 If Something's Wrong

### "LangChain components import failed"
```bash
pip install --upgrade langchain langchain-mongodb langchain-google-genai
python app.py
```

### "Recommendation service not initialized"
1. Check Flask console for errors
2. Verify `.env` has `GOOGLE_API_KEY`
3. Check MongoDB is running: `mongosh`
4. Check Redis is running: `redis-cli ping`

### "Still seeing old recommendations"
1. Clear PHP cache: `rmdir /s backend\storage\cache` (Windows) or `rm -rf backend/storage/cache` (Linux/Mac)
2. Hard refresh browser: `Ctrl+Shift+Delete` 
3. Check PHP config: `backend/app/config/config.php` line 114 should have `/api/recommend/langchain-rag`

### "Endpoint returns error"
Check Flask console - there should be an error message showing what went wrong

---

## 📋 Verification Checklist

- [ ] Flask shows: `[OK] LangChain RAG components initialized`
- [ ] Health check returns all components `true`
- [ ] API test returns products with `llm_explanation`
- [ ] Recommendation page shows products with explanations
- [ ] First request takes 2-5 seconds
- [ ] Second request (same form) takes <1 second (cached)

---

## What You Should See

### Before (What Was Broken)
```
Page showed: "No recommendations" or generic results
User couldn't tell AI was being used
```

### After (What's Fixed Now) ✅
```
Product 1: Serum Vitamin C
Sản phẩm này phù hợp vì: [Natural explanation from Gemini LLM]

Product 2: Kem chống nắng
Sản phẩm này phù hợp vì: [Personalized recommendation]

Product 3: Mặt nạ đất sét
Sản phẩm này phù hợp vì: [Context-aware explanation]

[Explanations are generated by Google Gemini LLM]
[Hybrid search combines keyword + semantic matching]
[Results are cached for instant subsequent loads]
```

---

## 🎉 You're All Set!

The integration is complete. Just follow the steps above to verify it's working.

**Total time:** ~15 minutes from start to verification

**What changed:** 
- Flask now initializes LangChain on startup
- PHP now calls the new `/api/recommend/langchain-rag` endpoint
- Users see LLM-powered explanations instead of generic text

**Questions?** Check:
1. `PROBLEM_SOLVED_DOCUMENTATION.md` - How the fix works
2. `LANGCHAIN_INTEGRATION_COMPLETE.md` - Detailed technical guide
3. `INTEGRATION_VERIFICATION_CHECKLIST.md` - Complete verification

---

**Status:** ✅ Ready to Test

**Next Action:** Start the services (MongoDB → Redis → Flask) and visit the recommendation page

**Expected Result:** LLM-powered product recommendations with personalized explanations

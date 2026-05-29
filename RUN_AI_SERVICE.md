# Run AI Services

Project có 2 Flask service riêng:

- Chatbot service: `chatbot_flask.py`, LangChain + ChromaDB, port `5001`.
- Recommendation service: `rcm_flask.py`, LlamaIndex + MongoDB/product index, port `5002`.

Không chạy `python app.py` vì project không có `app.py`.

## 1. Tạo và activate venv

```powershell
cd D:\xampp\htdocs\SkinSyntaxVN---Decoding-Your-Skin-Language-1\ai-service-flask
python -m venv .venv
.\.venv\Scripts\activate
```

## 2. Cài thư viện

Recommendation cần LlamaIndex, BM25 retriever, Gemini và MongoDB client:

```powershell
pip install -r requirements_recommendation.txt
```

Chatbot vẫn dùng bộ thư viện hybrid/LangChain hiện có:

```powershell
pip install -r requirements_hybrid.txt
```

## 3. Build recommendation index

Chỉ build khi cần tạo/cập nhật index sản phẩm. API recommendation không build lại index mỗi request.

```powershell
python -m recommendation.indexer
```

Kết quả mong đợi:

```json
{
  "ok": true,
  "count": 6377,
  "index_dir": "D:\\xampp\\htdocs\\SkinSyntaxVN---Decoding-Your-Skin-Language-1\\database\\recommendation_index"
}
```

## 4. Chạy Chatbot Service

Service này chỉ phục vụ chatbot LangChain + ChromaDB.

```powershell
python chatbot_flask.py
```

Health:

```powershell
curl http://127.0.0.1:5001/health
```

## 5. Chạy Recommendation Service

Service này phục vụ `/goiy` cho user đã đăng nhập, dùng LlamaIndex và MongoDB.

```powershell
python rcm_flask.py
```

Health:

```powershell
curl http://127.0.0.1:5002/health
```

Kết quả:

```json
{
  "ok": true,
  "service": "recommendation-flask",
  "framework": "LlamaIndex"
}
```

## 6. Test Recommendation API

```powershell
curl -X POST http://127.0.0.1:5002/api/recommend/llamaindex -H "Content-Type: application/json" -d "{\"user_id\":\"test\"}"
```

Với user thật có trong MongoDB, API trả:

```json
{
  "ok": true,
  "source": "llamaindex",
  "answer_text": "...",
  "products": []
}
```

Nếu MongoDB, Gemini key, dependency LlamaIndex hoặc index lỗi, API trả:

```json
{
  "ok": false,
  "message": "Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau."
}
```

## 7. PHP /goiy

- Guest: không gọi Flask, chỉ public discovery từ MongoDB.
- Logged-in: PHP gọi `http://127.0.0.1:5002/api/recommend/llamaindex`.

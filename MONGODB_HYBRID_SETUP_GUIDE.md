# MongoDB Hybrid Recommendation Setup

## 1. Muc tieu sau khi nang cap

He thong se duoc chia thanh 2 tang du lieu ro rang:

- PostgreSQL tiep tuc giu vai tro CSDL giao dich cho website: tai khoan, hoa don, san pham, lich su tim kiem.
- MongoDB dong vai tro tang AI/search: luu `products_rag`, `user_profiles`, `order_history`, `query_cache` de phuc vu hybrid search va RAG.

Luong goi y moi tren website se hoat dong theo thu tu:

1. Nguoi dung bam "Nhan goi y" va co the nhap them nhu cau bang ngon ngu tu nhien.
2. PHP goi Flask endpoint `/api/recommend/hybrid`.
3. Flask lay ung vien tu MongoDB bang keyword search, semantic rerank, lich su mua hang va query cache.
4. Neu hybrid service chua san sang, website tu dong fallback ve luong goi y SQL cu de khong bi vo trang.

## 2. Cac file da duoc them hoac sua

- `ai-service-flask/rag/mongo_hybrid_service.py`: dich vu hybrid search tren MongoDB.
- `ai-service-flask/sync_postgres_to_mongodb.py`: dong bo san pham, profile nguoi dung va lich su don hang tu PostgreSQL sang MongoDB.
- `ai-service-flask/app.py`: them endpoint `/api/recommend/hybrid`.
- `backend/app/controllers/HomeController.php`: uu tien hybrid endpoint, fallback ve content-based SQL.
- `backend/app/views/goiy.php`: them o nhap nhu cau tu nhien, panel tom tat AI, badge hybrid/cache.
- `backend/public/assets/css/style.css`: style cho giao dien goi y moi.

## 3. Cai dat MongoDB

### Cach 1: MongoDB Community Server tren Windows

1. Cai MongoDB Community Server.
2. Dam bao service MongoDB dang chay o cong `27017`.
3. Kiem tra bang MongoDB Compass hoac shell:

```powershell
mongosh "mongodb://127.0.0.1:27017"
```

### Cach 2: Docker

```powershell
docker run -d --name skinsyntax-mongodb -p 27017:27017 mongo:7
```

## 4. Cau hinh bien moi truong cho Flask AI service

Tao file `ai-service-flask/.env` dua tren `ai-service-flask/.env.example` va dien cac bien sau:

```env
GOOGLE_API_KEYS=your_gemini_key_1,your_gemini_key_2
LLAMA_INDEX_MODEL=models/gemini-2.5-flash
RECOMMENDATION_MODEL=gemini-2.5-flash

ENABLE_MONGODB_RAG=true
MONGODB_URI=mongodb://127.0.0.1:27017
MONGODB_DB_NAME=skinsyntax_ai
MONGODB_PRODUCTS_COLLECTION=products_rag
MONGODB_USER_PROFILES_COLLECTION=user_profiles
MONGODB_ORDER_HISTORY_COLLECTION=order_history
MONGODB_QUERY_CACHE_COLLECTION=query_cache

POSTGRES_HOST=127.0.0.1
POSTGRES_DB=skinsyntax
POSTGRES_USER=postgres
POSTGRES_PASSWORD=123456
```

Neu website PHP dung file `.env` o root cho AI endpoint, can bo sung them:

```env
AI_HYBRID_RECOMMENDATION_ENDPOINT=http://127.0.0.1:5000/api/recommend/hybrid
AI_HYBRID_RECOMMENDATION_TIMEOUT=25
```

## 5. Cai thu vien Python

Trong thu muc `ai-service-flask`:

```powershell
python -m venv .venv
.\.venv\Scripts\activate
pip install -r requirements.txt
```

Thu vien moi quan trong:

- `pymongo`
- `psycopg2-binary`
- `google-generativeai`
- `llama-index-embeddings-gemini`
- `llama-index-llms-gemini`

## 6. Dong bo du lieu tu PostgreSQL sang MongoDB

Sau khi PostgreSQL va MongoDB deu dang chay, thuc hien:

```powershell
cd ai-service-flask
.\.venv\Scripts\activate
python sync_postgres_to_mongodb.py
```

Script nay se dong bo 3 nhom du lieu:

- San pham vao `products_rag`
- Ho so nguoi dung vao `user_profiles`
- Lich su mua hang vao `order_history`

Neu du lieu san pham hoac don hang thay doi, hay chay lai script de cap nhat MongoDB.

## 7. Chay Flask AI service

```powershell
cd ai-service-flask
.\.venv\Scripts\activate
python app.py
```

Kiem tra health:

```powershell
Invoke-WebRequest -UseBasicParsing http://127.0.0.1:5000/api/health | Select-Object -ExpandProperty Content
```

Ban can thay trang thai co thong tin `mongodb_rag` san sang.

## 8. Kiem tra website sau khi sua

1. Mo trang goi y cua website.
2. Dang nhap bang tai khoan da co profile de he thong nap duoc `recent_keywords` va `recent_orders`.
3. Nhap them mot cau hoi tu nhien, vi du:

```text
Da dau nhay cam, co mun an va tham sau mun, muon routine toi duoi 500k.
```

4. Bam `Nhan goi y`.
5. Xac nhan giao dien hien:
   - badge `Hybrid Search` neu Flask + MongoDB dang chay
   - badge `Cache tuong tu` neu cau hoi trung gan voi truy van cu
   - panel `Tom tat tu AI`
   - danh sach san pham co `llm_explanation`

Neu Flask chua chay hoac MongoDB loi, web van se tra ve danh sach goi y theo SQL va hien `Fallback SQL`.

## 9. Nhung viec nen lam tiep de dat muc do do an tot hon

1. Tao cron/job de tu dong chay `sync_postgres_to_mongodb.py` theo lich.
2. Them Atlas Search hoac text index de cai thien keyword candidate retrieval.
3. Luu feedback nguoi dung khi bam san pham de phuc vu ranking ve sau.
4. Them dashboard quan tri hien trang thai sync, so luong document MongoDB va ti le cache hit.
5. Neu can demo thuyet phuc hon, hien them giai thich vi sao san pham khop voi `recent_keywords` va `order_history`.
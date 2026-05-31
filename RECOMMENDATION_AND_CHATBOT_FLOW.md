# SkinSyntaxVN - Recommendation and Chatbot Flow

Ngay lap: 2026-05-30  
Pham vi: phan tich luong AI chatbot va recommendation theo source code hien tai, khong sua code.

## 1. Ket luan tach module

| Module | Entry point | Port mac dinh | Framework | Muc dich |
| --- | --- | --- | --- | --- |
| Chatbot | `ai-service-flask/chatbot_flask.py` | `5001` (`CHATBOT_PORT`) | LangChain + ChromaDB | Tra loi chat/routine AI, RAG san pham/kien thuc. |
| Recommendation | `ai-service-flask/rcm_flask.py` | `5002` (`RECOMMENDATION_PORT`) | LlamaIndex | Goi y san pham ca nhan hoa cho route `/goiy`. |

Hai module nay dang duoc tach thanh service Flask rieng. `chatbot_flask.py` khong nen dung cho recommendation LlamaIndex chinh cua `/goiy`; `/goiy` goi `rcm_flask.py`.

## 2. Chatbot Flask

File chinh: `ai-service-flask/chatbot_flask.py`

### 2.1 Endpoint

| Endpoint | Method | Mo ta |
| --- | --- | --- |
| `/health` | GET | Tra JSON `{ok: true, service: chatbot-flask, framework: LangChain + ChromaDB}`. |
| `/api/health` | GET | Health chi tiet: port, model, so Gemini keys, trang thai ChromaDB, so document. |
| `/api/chat` | POST | Nhan message tu PHP/widget, goi pipeline chatbot va tra answer/products. |

PHP endpoint lien quan:

| PHP route | Controller/action | Mo ta |
| --- | --- | --- |
| `index.php?r=ai_chat_assistant` | `HomeController::aiChatAssistant()` | Nhan AJAX tu UI widget, build context profile/cart/product, goi `AI_CHAT_ENDPOINT` mac dinh `http://127.0.0.1:5001/api/chat`. |

### 2.2 Co dung LangChain khong?

Co. Source import va dung cac package LangChain:

- `langchain_chroma.Chroma`
- `langchain_huggingface.HuggingFaceEmbeddings`
- `langchain_google_genai.ChatGoogleGenerativeAI`
- `langchain_openai.ChatOpenAI`
- LangChain schema/output parser/prompts.

### 2.3 Co RAG khong?

Co. Chatbot load vector store ChromaDB qua `get_vectorstore()`, retrieve document lien quan, sau do dua context vao LLM. Endpoint `/api/health` kiem tra ChromaDB va so document trong collection.

ChromaDB path duoc cau hinh bang `CHROMA_DB_PATH`, mac dinh lay trong source `chatbot_flask.py`.

### 2.4 Co hybrid search + reranking khong?

Co. `chatbot_flask.py` import `HybridSearchPipeline` va `BM25Search` tu `ai-service-flask/hybrid_search.py`.

`hybrid_search.py` co cac thanh phan:

- BM25 keyword search.
- Vector/semantic search tu ChromaDB/LangChain.
- Hop nhat ket qua bang RRF/score combination.
- Vietnamese reranker bang CrossEncoder neu kha dung.

### 2.5 Co router intent khong?

Co. Trong `chatbot_flask.py` co `classify_intent(query, llms)` voi cac intent nhu:

- `PRODUCT_INQUIRY`
- `COSMETIC_KNOWLEDGE_OUT_OF_DB`
- `GENERAL_CONVERSATION`
- Cac nhu cau thanh phan/ingredient lien quan.

Intent router duoc dung de dieu huong cach tra loi va cach lay context.

### 2.6 Du lieu chatbot lay tu dau?

Nguon doc tu source:

- ChromaDB da build cho chatbot.
- Document/san pham trong vector store.
- Context san pham/gio hang/profile do PHP `HomeController::aiChatAssistant()` dinh kem.
- MongoDB `san_pham` thong qua PHP khi can gan san pham lien quan vao widget.
- Lich su conversation do frontend gui len trong payload.

## 3. Recommendation Flask/LlamaIndex

File entry point: `ai-service-flask/rcm_flask.py`

### 3.1 Endpoint

| Endpoint | Method | Mo ta |
| --- | --- | --- |
| `/health` | GET | Tra JSON `{ok: true, service: recommendation-flask, framework: LlamaIndex}`. |
| `/api/recommend/llamaindex` | POST | Nhan `user_id`, `email`, goi `LlamaIndexRecommendService::recommend()`, tra `answer_text` va `products`. |

PHP config:

- `AI_LLAMA_INDEX_RECOMMENDATION_ENDPOINT = http://127.0.0.1:5002/api/recommend/llamaindex`
- `AI_LLAMA_INDEX_RECOMMENDATION_TIMEOUT = 35`

### 3.2 Co dung LlamaIndex khong?

Co. File `ai-service-flask/services/llamaindex_recommend_service.py` import that tu LlamaIndex:

- `from llama_index.core import Settings, StorageContext, load_index_from_storage`
- `from llama_index.embeddings.huggingface import HuggingFaceEmbedding`
- `from llama_index.core.retrievers import VectorIndexRetriever`
- `from llama_index.retrievers.bm25 import BM25Retriever`
- `from llama_index.llms.gemini import Gemini`

Khong thay class gia ten LlamaIndex trong service nay.

### 3.3 Index luu o dau?

Config: `ai-service-flask/recommendation/config.py`  
Service dung `RECOMMENDATION_INDEX_DIR`.

Theo source va yeu cau hien tai, index duoc persist tai:

```text
database/recommendation_index
```

File service kiem tra `docstore.json`; neu thieu thi bao loi:

```text
Recommendation index chưa được build. Hãy chạy python -m recommendation.indexer
```

Indexer: `ai-service-flask/recommendation/indexer.py`

Luong index:

1. Doc products tu MongoDB.
2. Chuan hoa product thanh document text/metadata.
3. Tao `VectorStoreIndex`.
4. Persist index vao `database/recommendation_index`.
5. Luu metadata product vao `products_meta.json`.

Lenh build index:

```powershell
cd ai-service-flask
python -m recommendation.indexer
```

### 3.4 Pipeline `/api/recommend/llamaindex`

File service: `ai-service-flask/services/llamaindex_recommend_service.py`

Luong doc duoc trong code:

1. `_resolve_customer(user_id, email)` tim khach hang trong `khach_hang`, map voi `nguoidung` neu can.
2. `_history(customer_id)` lay:
   - `hoa_don`
   - `chi_tiet_hoa_don`
   - `gio_hang` neu co collection
   - `lich_su_chat`
   - product tu `san_pham`
3. `_skin_profile_doc(customer)` doc `ho_so_da` theo `ma_kh`, `customer_id`, `user_id`, `email`.
4. `_implicit_query(customer, history)` tao query ngam tu:
   - skin type
   - concerns
   - budget
   - sensitivity
   - avoid ingredients
   - previous purchases
   - cart
   - recent chat needs
5. `_load_index()` load LlamaIndex da persist, khong build lai moi request.
6. `_vector_scores()` dung `VectorIndexRetriever`.
7. `_bm25_scores()` dung `BM25Retriever` tren nodes da persist.
8. `_rerank()` hop nhat candidate ids tu vector + BM25, hydrate product tu MongoDB, filter metadata budget/skin/stock, tinh score ket hop:
   - 58% semantic
   - 32% lexical/BM25
   - diem rating/popularity bo sung
9. `_gemini_answer()` dung `llama_index.llms.gemini.Gemini` de tao `answer_text`, voi prompt yeu cau chi dung san pham trong database.
10. `recommend()` tra JSON:

```json
{
  "ok": true,
  "source": "llamaindex",
  "answer_text": "...",
  "products": [
    {
      "id": "...",
      "ten_san_pham": "...",
      "gia_ban": 0,
      "gia_thi_truong": 0,
      "phan_tram_giam": 0,
      "thuong_hieu": "...",
      "link_hinh_anh": "...",
      "diem_danh_gia": 0,
      "reason": "..."
    }
  ]
}
```

Neu loi, `rcm_flask.py` log exception va tra:

```json
{
  "ok": false,
  "message": "Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau."
}
```

## 4. Route `/goiy` trong PHP

Route: `index.php?r=goiy`  
Controller: `HomeController::goiy()`  
View: `backend/app/views/goiy.php`

### 4.1 Chua dang nhap

Dieu kien:

- `current_user()` khong co email.

Luong:

1. Doc filter tu query:
   - `keyword`
   - `danh_muc`/`category`
   - `thuong_hieu`/`brand`
   - `gia_tu`/`price_min`
   - `gia_den`/`price_max`
   - `sort`
2. Goi `loadPublicRecommendationData()`.
3. Model `SanPham::publicRecommendationSections(6, filters, sort)`.
4. Render `goiy.php` voi `showPublicDiscovery = true`.

Khong goi Flask, khong goi LlamaIndex.

UI hien 5 khoi:

- San pham ban chay nhat.
- San pham duoc danh gia cao.
- San pham dang giam gia.
- San pham duoc nhieu nguoi quan tam/tim kiem.
- San pham moi.

### 4.2 Da dang nhap nhung chua co ho so da hop le

Dieu kien:

- Co email session.
- `buildRecommendationProfile($email)` co the tra profile.
- `hasValidSkinProfile($profile)` tra false.

Luong:

1. Khong goi LlamaIndex.
2. Goi public discovery nhu guest.
3. Render banner moi khao sat, link `index.php?r=khaosat`.
4. Van hien cac khoi san pham public.

Ham `hasValidSkinProfile()` xem hop le neu co it nhat mot trong:

- `skin_type`/`loai_da`
- `concerns`/`van_de_da`/`skin_issues`
- `budget`/`ngan_sach`
- `muc_tieu_cham_soc_da`/`goal`

### 4.3 Da dang nhap va co ho so da hop le

Dieu kien:

- Co email session.
- Profile hop le.

Luong:

1. `buildRecommendationProfile($email)` lay:
   - `khach_hang`
   - `ho_so_da` qua `TaiKhoan::getSkinProfileByEmail`
   - van de da, thanh phan tranh, ngan sach
   - tu khoa gan day
   - lich su don hang
2. `fetchLlamaIndexRecommendations($profile, $sessionUser)` POST den `AI_LLAMA_INDEX_RECOMMENDATION_ENDPOINT`.
3. Neu Flask tra `ok=true`, `source=llamaindex`, co products: render `answer_text` va product cards.
4. Neu loi/timeout/khong co products: render thong bao than thien, khong hien source ky thuat.

## 5. Public discovery khong AI

Model: `SanPham.php`

Ham lien quan:

| Ham | Vai tro |
| --- | --- |
| `buildProductFilters($request)` | Tao MongoDB filter theo keyword, danh muc, thuong hieu, gia. |
| `buildProductSort($sort, $defaultSort)` | Map sort UI sang MongoDB sort. |
| `getBestSellerProducts()` | Lay ban chay, default `so_luong_da_ban DESC`. |
| `getTopRatedProducts()` | Lay danh gia cao, default rating/review count. |
| `getDiscountProducts()` | Lay san pham giam gia. |
| `getMostViewedProducts()` | Lay san pham nhieu luot xem. |
| `getNewProducts()` | Lay san pham moi. |
| `getCollectionProducts()` | Dung cho route `product_collection`. |
| `publicRecommendationSections()` | Gom 5 khoi cho `/goiy`. |

Day la MongoDB product query, khong phai fallback AI.

## 6. Legacy/bo sung recommendation endpoints

Trong `ai-service-flask/recommendation/routes.py` co blueprint route:

- `GET /api/recommend/guest`
- `GET /api/recommend/profile/<user_id>`
- `POST /api/recommend/langchain-rag`
- `POST /api/recommend/llamaindex`
- `POST /api/recommend/index`

Tuy nhien service recommendation chinh theo kien truc hien tai la `rcm_flask.py` port 5002. Neu blueprint nay khong duoc register trong service dang chay thi cac endpoint trong file chi la module bo sung/legacy.

Trong PHP con co:

- `index.php?r=xulygoiy`: luong goi y cu.
- `index.php?r=api_profile_recommendations`: API profile recommendation trong `TaiKhoanController`, co consent va endpoint profile cu port 5001.

Hai route nay khong phai luong LlamaIndex chinh cua trang `/goiy`.

## 7. Cach chay theo source hien tai

Chatbot:

```powershell
cd ai-service-flask
python chatbot_flask.py
```

Health:

```text
http://127.0.0.1:5001/health
```

Recommendation:

```powershell
cd ai-service-flask
python -m recommendation.indexer
python rcm_flask.py
```

Health:

```text
http://127.0.0.1:5002/health
```

Test recommendation:

```powershell
curl -X POST http://127.0.0.1:5002/api/recommend/llamaindex -H "Content-Type: application/json" -d "{\"user_id\":\"1\"}"
```

## 8. Diem can xac nhan

- Chatbot ChromaDB path va collection cu the nen kiem tra runtime `.env`/bien moi truong vi source cho phep override bang `CHROMA_DB_PATH`.
- Recommendation can `GOOGLE_API_KEY` hoac `GOOGLE_API_KEYS`; neu thieu key, retrieval co the thanh cong nhung `answer_text` Gemini se loi.
- `recommendation/routes.py` co blueprint nhieu endpoint, nhung neu chay `rcm_flask.py` thi endpoint chinh la endpoint khai bao truc tiep trong `rcm_flask.py`.
- Chatbot va recommendation deu co comment/chuoi mojibake o mot so doan source; tai lieu nay chi ghi nhan, khong sua code.

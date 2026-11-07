# Bước 00 — Khảo sát hiện trạng tìm kiếm & khả thi Neo4j (2026-10-21)

> Chỉ đọc. Không sửa code, không ghi DB. Truy vấn Mongo Atlas chỉ dùng `find`/`count_documents`/`aggregate` (không `$out`/`$merge`) qua `.venv` gốc (Python 3.12.5, pymongo). Script khảo sát nằm ngoài repo (scratchpad).
> Không gọi thử `index.php?r=tatca&q=...` trên web vì request đó **ghi** `tuong_tac_nguoi_dung` (`InteractionLogger::logSearch`) — đã tái hiện lỗi ở mức truy vấn Mongo thay thế (mục 2).

**GitNexus:** `node .gitnexus/run.cjs query "..." --repo .` lỗi trên Windows (`too many arguments for 'query'` — cụm có dấu cách bị tách); `context paginateWithProjection` → `Symbol not found`; `status` treo > 120s. Index coi như chưa sẵn sàng ("index pending" trong AGENTS.md) → dùng `grep`/đọc file.

---

## 1. Luồng tìm kiếm PHP

| Mắt xích | Vị trí |
|---|---|
| Route `r=tatca` | `index.php:105-106` → `(new SanPhamController($pdo))->tatca()`; route `r=timkiem` cũng gọi `tatca()` (`index.php:205-206`) |
| Controller | `backend/app/controllers/SanPhamController.php:334` `tatca()`; `q` cắt bởi `normalizeKeyword` (`:255`, max `SEARCH_KEYWORD_MAX_LEN`); ghi lịch sử `:351-357`; cache Valkey key `catalog:tatca:<md5>` TTL 300s `:360-381`; gọi `paginate(... , true, '', $sort)` `:377`; render `tatca` `:383-392` |
| Model | `backend/app/models/SanPham.php:834` `paginate()` → `:839` `paginateWithProjection()` |
| Regex | `SanPham.php:335-338` `buildSearchRegex()` |

**`buildSearchRegex`** (`SanPham.php:335-338`): `preg_quote(trim($q))` → `new Regex($q, 'i')`. **Không** bỏ dấu, **không** tách từ, chỉ không phân biệt hoa thường. Cả cụm thành 1 regex.

**`paginateWithProjection`** (`:846-858`): `$or` trên `ten_san_pham, ma_san_pham, thuong_hieu, danh_muc_day_du, loai_san_pham, barcode` (+ `ma_san_pham` số nếu `q` là số).
⚠️ **Phát hiện mới:** trong Mongo **không tồn tại** trường `thuong_hieu`, `loai_san_pham`, `barcode` (count `$exists` = 0 cho cả 3). Tên hãng chỉ có ở `ma_thuong_hieu` (int) và được map sang tên ở PHP sau khi đọc (`SanPham.php:255` `getBrandName`). ⇒ Hiện tìm "torriden" chỉ ra kết quả nhờ tên SP có chữ Torriden, không nhờ trường hãng.

**Nơi gọi `SanPham::paginate`** (`grep -rn -- "->paginate(" backend frontend`):

| Nơi gọi | Loại | `onlyVisibleOnWebsite` |
|---|---|---|
| `SanPhamController.php:377` (`tatca`) | Public | true |
| `SanPhamController.php:414` (`danhsach`, fallback) | Public | true |
| `SanPham.php:1562` (`getProductsByType` default) | Public | true |
| `LiveController.php:256` (`apiSearchCatalogProducts`, route `api_search_catalog_products` `index.php:416`) | Live/admin tool (cần xác nhận quyền) | **false** → trả cả SP ẩn |
| `QuanTriController.php:275`, `:1009` | Admin | false |
| `backend/tests/verify_category_migration_complete.php:163` | Test (không chạy) | true |

Ngoài ra `searchSuggestions()` (`SanPham.php:~906`) và các khối `:1331`, `:1361`, `:1662` cũng dùng `buildSearchRegex` + `thuong_hieu` → cùng lỗi cụm.

**SP "đang bán":** `availableProductFilter()` (`SanPham.php:82-85`) = `visibleProductFilter()` (`:78-80`) = `trang_thai ∉ ['inactive','hidden','tam_an','taman','disabled','off','0']`. Hết hàng vẫn hiện (chỉ chặn mua). `LISTING_PROJECTION` (`:7`) = loại `mo_ta`, `thanh_phan_full`, `hdsd`.

**View `frontend/views/tatca.php`:** nhận `$items, $total, $page, $perPage, $q, $cap1, $cap2, $sort` (`:3-10`), thêm `$pageTitle`, `$dbUnavailableMessage` (từ `danhsach`). Khối rỗng: `:90-96` (`<h4>Không tìm thấy sản phẩm phù hợp</h4>` ở `:93`). **Không có partial card** — card render inline trong `foreach` `:99-~170`. Container kết quả `#catalogResults` (`:87`) được live-filter thay nội dung (header `X-Requested-With: live-filter`).

## 2. Schema & chất lượng dữ liệu `san_pham`

Collection thật: `san_pham` (`SanPham.php:874` `$this->db->san_pham`). Tổng 2853 doc, **cả 2853 đều "đang bán"** theo filter trên. Có thêm `san_pham_backup_6377` (6377 doc — cũ).

Mẫu (doc `ma_san_pham` 925/928/929, rút gọn): `ma_san_pham: Int64=925`, `ten_san_pham: str='Gel Dưỡng Ẩm Naturie…'`, `gia_ban: int`, `gia_thi_truong: int`, `dung_tich: str`, `loai_da: str='Da thường/Mọi loại da'`, `danh_muc_day_du: str='Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Ẩm -> Kem / Gel / Dầu Dưỡng'`, `ma_thuong_hieu: int=191`, `ma_danh_muc: int=7`, `ma_xuat_xu`, `ma_noi_san_xuat`, `ma_loai_da: int`, `diem_danh_gia: float`, `so_luong_danh_gia: int`, `thanh_phan: str='• Chiết xuất…'` (mô tả hoạt chất), `thanh_phan_full: str='Water, Glycerin, …'` (INCI), `thanh_phan_sach: str='carnitine, salicylic acid'` (danh sách hoạt chất sạch), `so_luong_ton_kho`, `trang_thai_kho`, `so_luong_da_ban`, `luot_xem`, `ngay_tao: datetime`, `updated_at: datetime`.

**Thương hiệu:** collection `thuong_hieu` (432 hãng, `ma_thuong_hieu`, `ten_thuong_hieu`). Không có tên trùng khi chuẩn hóa hoa/thường/khoảng trắng; 0 tên có khoảng trắng thừa; 0 SP trỏ hãng không tồn tại → **không có vấn đề viết không thống nhất** (vì SP lưu id, không lưu chuỗi).
Top 20 (tên, id, số SP): Cocoon(2) 134 · L'Oreal(6) 82 · B.O.M(163) 62 · La Roche-Posay(7) 60 · Naris Cosmetics(59) 59 · Garnier(14) 58 · LipIce(226) 57 · Hada Labo(49) 56 · Sunplay(33) 50 · Foodaholic(206) 49 · Eucerin(38) 48 · Emmié by Happy Skin(77) 48 · Vichy(17) 46 · Colorkey(60) 46 · Bioderma(4) 43 · Bio-essence(81) 43 · Banobagi(156) 43 · Bioré(101) 42 · Maybelline(9) 42 · Cetaphil(5) 38.

**Ca kiểm thử chuẩn Torriden:** hãng `Torriden` id **24**, **30 SP**. Theo danh mục: 11 (Mặt nạ giấy) 9 · 9 (Serum) 6 · 3 (Bộ chăm sóc) 4 · 7 3 · 4 (Toner) 3 · 18 2 · 6 1 · 38 1 · **1 (Sữa rửa mặt) 1**. Torriden + tên chứa "rửa mặt": **1 SP** — `1496 "Sữa Rửa Mặt Torriden Tạo Bọt Dưỡng Ẩm Da 150ml"` (`ma_danh_muc=1`). Truy vấn cũ (regex cả cụm "torriden sữa rửa mặt" trên 6 trường) → **0** (đã tái hiện bằng `count_documents`).
Gợi ý kỳ vọng sau sửa: top-1 = 1496; nhóm "cùng hãng" = 29 SP Torriden còn lại; nhóm "thay thế cùng công dụng" lấy từ danh mục 1 (285 SP; 342 SP có "rửa mặt" trong tên).

**Cây danh mục** (`danh_muc`, 28 doc, có `level`, `parent_id`, `is_leaf`, `slug`, `updated_at`): cấp1 `1001 Chăm Sóc Da Mặt` →
- `3 Bộ Chăm Sóc Da Mặt` (lá), `6 Chống Nắng Da Mặt` (lá)
- `2001 Mặt Nạ` → 11 Giấy, 19 Rửa, 53 Ngủ, 83 Lột
- `2002 Làm Sạch Da` → 1 Sữa Rửa Mặt, 2 Tẩy Trang, 4 Toner, 17 Tẩy TBC
- `2003 Dưỡng Ẩm` → 7 Kem/Gel/Dầu, 30 Xịt Khoáng, 37 Lotion
- `2004 Đặc Trị` → 9 Serum, 25 Trị Mụn, 105 Đặc trị khác
- `2005 Dưỡng Mắt` → 38, 60 · `2006 Dưỡng Môi` → 18, 29, 73

⚠️ **20 `ma_danh_muc` mồ côi** (không có trong `danh_muc`), chiếm **380 SP (13,3%)** — toàn bộ là dữ liệu fixture `test_dataset='pb-fixture-v1'` (Trang Điểm 169, Tóc 139, Cơ Thể 72).

Độ phủ chi tiết: xem bảng cuối file.

## 3. Dữ liệu phụ trợ

- **Hồ sơ da:** `khach_hang` (182 doc) trường `van_de_da` (chuỗi CSV, vd `'Thâm mụn, sạm nám, tàn nhang, Mụn viêm, sưng đỏ'`), `muc_do_nhay_cam`, `muc_do_mun`, `muc_tieu_cham_soc` (vd `'Sạch mụn, giảm viêm'`), `thanh_phan_tranh` (vd `'Fragrance/Parfum, Alcohol'`), `ngan_sach`, `tinh_trang_dac_biet`. **Không có trường `loai_da`**: loại da được mã hóa trong `tinh_trang_dac_biet` dạng `"loaida:<id> | <text>"` (`TaiKhoan.php:285-287`, đọc ở `getSkinProfileByEmail` `:290-307`). Danh mục loại da `loai_da` 10 doc (1 Da dầu/Hỗn hợp dầu, 2 Da thường/Mọi loại da, 3 Nhạy cảm, 4 Unknown, 5 Khô/Hỗn hợp khô, 6 Mụn, 7–10 biến thể trùng nghĩa không SP nào dùng). 160/182 KH là fixture.
- **Lịch sử tìm kiếm:** `saveSearchHistory` (`SanPhamController.php:259`) → session `search_history` + `TaiKhoan::luuLichSuTimKiem` (`TaiKhoan.php:527-547`) → `lich_su_tim_kiem {id, ma_kh, tu_khoa, ngay_tim}` (30 doc, chủ yếu "serum"). `InteractionLogger::logSearch` (`services/InteractionLogger.php:52`) → `TuongTac` → `tuong_tac_nguoi_dung {ma_tuong_tac, ma_kh, session_id, ma_san_pham, loai_tuong_tac, trong_so, metadata, created_at}` (257 doc: view 156, search 56, search_click 21, add_to_cart 15, purchase 8).
- **Đơn hàng:** `hoa_don` (2336; `ma_hoa_don, ma_kh, ngay_dat, trang_thai, …`) + `chi_tiet_hoa_don` (3140; `ma_hoa_don, ma_san_pham, so_luong, don_gia`). `ngay_dat` 2026-03-18 → 2026-10-16. 342 đơn từ 2026-10-07 (14 ngày). 560 đơn ≥ 2 item (đủ cho "thường mua cùng"). Trạng thái không chuẩn hóa (`'Hoàn thành'` 2100 vs `'Hoan thanh'` 9, `'Đã hủy'`/`'Da huy'`…; có `trang_thai_normalized` ở 2319 doc). ⚠️ **2305/2336 đơn là fixture** (`test_dataset`). Ngoài ra `product_events` (68 777, fixture) và `product_metrics_snapshots` (216 068).
- **Xung đột hoạt chất:** `recommendation_service/conflict_checker.py:9` import `find_conflicts` từ `recommendation_service/knowledge/ingredient_rules.py` (Python cứng, `_ALIASES` `:16`, `CONFLICT_RULES` `:37`) — **3 cặp** (`len(CONFLICT_RULES)=3`). Không lấy từ Mongo. `product_skin_compatibility` tồn tại nhưng rỗng (0 doc).

## 4. ai-service-flask

**App đang chạy thật** (bằng chứng):
- `Dockerfile.ai:45` `CMD gunicorn --config gunicorn_config.py chatbot_service.chatbot_flask:app`; `docker-compose.yml:94-137` service `ai-service` port 5001; `docker ps` → `skinsyntax-ai 0.0.0.0:5001 (healthy)`; `GET :5001/api/health` → `{"service":"SkinSyntaxVN Chatbot","chromadb":true,"documents":6377,…}`.
- `ai-service-flask/chatbot_flask.py` chỉ là shim import `chatbot_service.chatbot_flask`.
- `recommendation_service/rcm_flask.py` (`create_app`, `:35`) **không** nằm trong compose/Dockerfile; chỉ chạy tay (`config.PORT` mặc định **5002**, trùng port `voice-service` 5002). `ai-service-flask/rcm_flask.py` là shim của nó.
- `recommendation/routes.py:10` `recommendation_bp` **không được `register_blueprint` ở đâu** (grep toàn `ai-service-flask`) → route chết. Tuy nhiên `recommendation.mongo_source` vẫn được dùng bởi `structured_recommendation.py:19` (endpoint `/api/recommend` của app đang chạy) và `services/llamaindex_recommend_service.py:11`.
⇒ **Kết luận:** runtime = `chatbot_service.chatbot_flask`. `recommendation_service` là thư viện + app riêng chưa deploy; `recommendation/` chỉ dùng như thư viện đọc Mongo.

**Endpoint app đang chạy** (`chatbot_service/chatbot_flask.py`): GET `/health` `:3125`, GET `/api/health` `:3130`, POST `/api/chat` + `/api/chat/auto` `:3164-3165`, POST `/api/recommend` `:3317` (gọi `structured_recommendation.generate_structured_recommendation`), POST `/api/recommend/profile|llamaindex|langchain-rag` `:3346-3348`, GET `/api/chat/conversations` `:3430`, GET `/api/chat/conversations/<conv_id>` `:3459`, POST `/api/chat/conversations` `:3499`, DELETE `/api/chat/conversations/<conv_id>` `:3523`.
**Endpoint `recommendation_service/rcm_flask.py`** (không chạy): GET `/health`,`/api/health`,`/ready`,`/`; POST `/api/recommend`, `/api/recommend/routine|profile|llamaindex|langchain-rag|explain` (`:76-82`); GET `/api/recommend/profile/<user_id>` (`:93`); POST `/api/recommend/index` (`:103`).
**Endpoint `recommendation/routes.py`** (chết): GET `/api/recommend/guest` `:30`, GET `/api/recommend/profile/<user_id>` `:37`, POST `langchain-rag` `:52`, `llamaindex` `:78`, `index` `:97`.

**Auth nội bộ:** port 5001 **không có** token/header auth; chỉ đọc header `X-User-Email` cho conversations (`:3432,3461,3501,3525`) — tin tưởng mạng nội bộ. Có biến `AI_IDOL_INTERNAL_TOKEN` trong `.env.example` nhưng dành cho ai-idol, không cho 5001. CORS mở (`rcm_flask.py:40`).

**recommendation_service — API chính:**
- `hybrid_search.py`: `BM25Index.ensure/search(query, limit)->[(id,score)]` `:41-129`; `HybridSearch.search(query, limit, user_id)->list[HybridHit]` `:153` (BM25 + Chroma, RRF `RRF_K=60`, `alpha=0.5`); `get_hybrid_search()` `:209`. Tokenizer `_norm/_tokens` `:22-28` đã **bỏ dấu (NFKD)** + tách từ `\w+`, bỏ token 1 ký tự → **dùng lại được cho tìm từ khóa tự do**.
- `retrieval.py`: `ChromaRetrieval.search(query, limit)->list[VectorHit]` `:53`, `build_or_refresh(products)->int` `:85` (id `product_{p['id']}` `:108`); Chroma **persist_directory** cục bộ (`config.CHROMA_DIR = database/chroma_db`), không dùng HttpClient.
- `reranker.py`: `Reranker.rerank(query, candidates, limit=8)` `:41` (cross-encoder mmarco, tốn RAM/CPU).
- `response_cache.py`: `ResponseCache.key/get/set` `:28-53` (TTL `RECOMMENDATION_CACHE_TTL`=120).
- `pipeline.py`: `RecommendationPipeline.default()` `:45`, `recommend(payload)->RecommendResponse` `:101`, `search_text(product)` `:95`.
- `product_repository.py`: `list_visible(limit=2000)` `:227` ⚠️ giới hạn 2000 < 2853 SP, sort `luot_xem` → BM25 bỏ sót ~850 SP; ưu tiên MySQL rồi mới Mongo (`:229-230`) — MySQL không còn dùng; filter visible thiếu `tam_an`,`taman`,`off` so với PHP (`:218`).

**ChromaDB (server `chromadb/chroma:0.5.20`, :8000):** collection `products` = **6377** doc (dim 1024), `langchain` = 0. Document text: `"Tên sản phẩm / Loại sản phẩm / Thương hiệu / Xuất xứ / Nơi sản xuất / Dung tích / Loại da phù hợp / Mô tả …"`. Metadata: `dung_tich, gia_ban, link_hinh_anh, loai_da, loai_san_pham, mo_ta, noi_san_xuat, ten_san_pham, thanh_phan_chinh, thuong_hieu, xuat_xu_thuong_hieu` — **không có `ma_san_pham`**, id `product_1..`. Script index: `database/import_chromadb.py` (đọc CSV `database/data_clean_final.csv`), embed `sentence-transformers/static-similarity-mrl-multilingual-v1` (`chatbot_service/model_config.py:4`). Lần cuối: `database/chroma_db/chroma.sqlite3` sửa đổi 2026-08-20 (bản local); server Chroma không có metadata ngày. ⚠️ 6377 = số doc `san_pham_backup_6377` ⇒ index **lệch** catalog hiện tại 2853 SP.

**Python & thư viện:** local `.venv` (gốc repo) Python 3.12.5; container `skinsyntax-ai` Python 3.11.16. Requirements thật của container: `ai-service-flask/requirements.txt` (`Dockerfile.ai:17-20`).

| Gói | `.venv` local | container | requirements.txt |
|---|---|---|---|
| neo4j | thiếu | thiếu | không |
| rapidfuzz | thiếu | thiếu | không |
| unidecode | thiếu | thiếu | không |
| pyyaml | 6.0.3 | có | (gián tiếp) |
| pydantic | 2.13.4 | có | `>=2.0,<3` |
| pytest | 9.1.1 | **thiếu** | không |
| chromadb | 1.5.9 ⚠️ (server 0.5.20) | — | `>=1.0.0` |
| rank_bm25 | thiếu (BM25 tự viết) | — | — |

**Test Python:** `ai-service-flask/tests/` (test_recommendation_service, test_chat_regressions, test_consultation_100, test_acceptance_fixes) + `recommendation_service/tests/test_safety_filter.py`. Không có `pytest.ini/pyproject`. Đã chạy: `.venv/Scripts/python.exe -m pytest -q ai-service-flask/tests/test_recommendation_service.py ai-service-flask/recommendation_service/tests` → **6 passed in 0.12s**.

## 5. Cách PHP gọi Flask

- Cấu hình: `backend/app/config/config.php:122-131` (`AI_RECOMMENDATION_ENDPOINT`, `AI_HYBRID_RECOMMENDATION_ENDPOINT`, `AI_LLAMA_INDEX_…`, `AI_PROFILE_…`, `AI_CHAT_ENDPOINT`, timeout 20/30/35/20/60s; mặc định port 5002). `.env` ghi đè sang `127.0.0.1:5001`; `docker-compose.yml` (php-backend env) ghi đè sang `http://ai-service:5001/...`.
- ⚠️ `AI_RECOMMENDATION_ENDPOINT=/api/recommend/explain` và `AI_CHAT_STREAM_ENDPOINT=/api/chat/stream` → **404** trên app đang chạy (đã `curl -X POST` thử: 404/404). Hai route này chỉ có ở `recommendation_service` (explain) hoặc không tồn tại (stream).
- Gọi HTTP: `HomeController::postJsonRequest(url, payload, timeout=20)` (`HomeController.php:3503`, curl, `CURLOPT_TIMEOUT max(3,$timeout)` `:3525`, lỗi → `error_log("[AI_CHAT] …")` và trả mảng `ok=false`), một hàm curl khác timeout 15s (`:3408`), getter endpoint/timeout `:1687-1740` (fallback cứng `127.0.0.1:5000`). `SanPhamController.php:769-787` gọi ai-idol (timeout 0 = stream).
- Lớp dùng chung: `backend/app/services/RecommendationClient.php:134` (DTO, curl/stream, connect timeout ≤5s, retry `isRetryable` `:228`) — **không được khởi tạo ở đâu** (grep `RecommendationClient` ngoài file = 0). ⇒ Chưa có client chung đang dùng; ứng viên tốt cho Bước 06.
- Không có header auth nào PHP→Flask 5001.

## 6. Hạ tầng (`docker-compose.yml`)

| Service | Image/Build | Port host | Healthcheck |
|---|---|---|---|
| mongodb | mongo:7 | 27018 | mongosh ping (local, **không phải** nguồn dữ liệu — app dùng Atlas qua `MONGO_URI`) |
| chromadb | chromadb/chroma:0.5.20 | 8000 | `/api/v1/heartbeat` |
| redis | redis:7-alpine | 6380 | `redis-cli ping` |
| ai-service | Dockerfile.ai | 5001 | `/api/health` |
| ai-idol-agent-service | Dockerfile.ai-idol-agent | (5013 nội bộ) | python urllib |
| ai-idol-service | Dockerfile.ai-idol | 127.0.0.1:5003 | `/api/health` |
| ai-idol-worker / control-worker / broadcast-worker / beat | (anchor) | — | — |
| php-backend | Dockerfile.php | (9000 nội bộ) | — |
| nginx | nginx:1.27-alpine | 8080 | — |
| voice-service | ./voice-service | 5002 | — |
| livekit-voice-agent | ./livekit-voice-agent | — | — |

Network `skinsyntax-net`; volumes `mongo_data, chroma_data, uploads_data, redis_data, vieneu_model_cache, ai_idol_storage`. ai-service mount `./ai-service-flask:/app` và `./database:/app/database:ro`.
Biến môi trường ai-service (tên): `CHATBOT_PORT, GUNICORN_WORKERS, GUNICORN_WORKER_CLASS, CHROMA_HOST, CHROMA_PORT, CHROMA_DB_PATH, CSV_FILE_PATH, MONGO_URI, MONGO_DB, MONGO_DB_NAME, REDIS_URL, REDIS_CACHE_TTL, SESSION_CACHE_TTL, PYTHONPATH` + `env_file: .env`.
`.env.example` key chính: `APP_URL, MONGO_URI, MONGO_DB, MONGO_DB_NAME, REDIS_URL, OPENAI_*, TAVILY_API_KEY, CHATBOT_PORT, GUNICORN_*, AI_CHAT_ENDPOINT, AI_CHAT_STREAM_ENDPOINT, AI_RECOMMENDATION_ENDPOINT, AI_HYBRID_RECOMMENDATION_ENDPOINT, AI_LLAMA_INDEX_RECOMMENDATION_ENDPOINT, AI_*_TIMEOUT, CHROMA_DB_PATH, CSV_FILE_PATH, SMTP_*, GOOGLE_*, FACEBOOK_*, BANK_TRANSFER_*, SEPAY_*, VNPAY_*, LIVEKIT_*, AI_IDOL_*, GROQ_API_KEY, CACHE_DRIVER, VALKEY_URL, CACHE_*` — chưa có `NEO4J_*`.
`.gitignore`: đã chặn `.env*` (trừ `.env.example`), `chroma_db/`, `ai-service-flask/chroma_db/`, `ai-service-flask/chroma_data/`, `database/recommendation_index/` (`:5-47`). **Chưa** chặn thư mục dữ liệu Neo4j (vd `neo4j_data/`, `docker/neo4j/`).
RAM Docker: tổng 11,57 GiB / 12 CPU; đang dùng ≈ 3,5 GiB (ai-service 1,7 GiB, chromadb 356 MiB, mongo 328 MiB).

## 7. Hạ tầng kiểm thử

- Playwright: **không có** `package.json`, `playwright.config.*`, `node_modules/playwright` (`npx --no-install playwright` → thiếu gói). E2E hiện chạy qua **Playwright MCP** (`browser_run_code_unsafe` với file `e2e/syna-self-healing.e2e.js`, ghi chú ở dòng 2 file đó). `.claude/skills/e2e/SKILL.md` chỉ quy định quy trình chung (không có lệnh cố định).
- Composer: `composer.json` chỉ `mongodb/mongodb 1.19.1`; **không có phpunit**, không có `scripts`. Kiểm PHP = `php -l`.
- `backend/tests/*.php` ghi DB thật → **không chạy**.

---

## Bảng độ phủ dữ liệu (2853 SP đang bán)

| Trường | Tên thật | % có | Kiểu | Ghi chú |
|---|---|---|---|---|
| Thương hiệu | `ma_thuong_hieu` → `thuong_hieu.ten_thuong_hieu` | 100% | int | Không có chuỗi `thuong_hieu` trong SP; 0 id mồ côi |
| Mã danh mục | `ma_danh_muc` | 100% | int | 380 SP (13,3%) trỏ id không có trong `danh_muc` (fixture) |
| Danh mục đầy đủ | `danh_muc_day_du` | 100% | str | "A -> B -> C -> D" |
| Loại SP | — (suy ra từ lá `ma_danh_muc`) | 0% trường riêng | — | `loai_san_pham` không tồn tại |
| Thành phần INCI | `thanh_phan_full` | 94,5% | str (dài TB ~1516 ký tự) | Chuỗi, phân tách dấu phẩy, có xuống dòng lỗi |
| Hoạt chất | `thanh_phan_sach` | 77,1% | str (TB ~154) | Danh sách hoạt chất, dùng tốt cho Neo4j |
| Mô tả hoạt chất | `thanh_phan` | 77,1% | str (TB ~382) | Gạch đầu dòng tiếng Việt |
| Loại da | `loai_da` / `ma_loai_da` | 100% có giá trị, **81,9% có nghĩa** | str / int | 517 SP (18,1%) = "Unknown"; 1343 (47%) = "Da thường/Mọi loại da" |
| Công dụng / vấn đề da | — | **0%** | — | Không có trường; phải suy từ danh mục + `mo_ta` + `thanh_phan` |
| Lượt bán | `so_luong_da_ban` | 100% có, 73,9% > 0 | int | Phần lớn từ fixture |
| Đánh giá | `diem_danh_gia`, `so_luong_danh_gia` | 100% có, 73,9% > 0 | float, int | |
| Tồn kho | `so_luong_ton_kho`, `trang_thai_kho` | 100% (>0: 100%) | int, str | |
| Giá | `gia_ban` | 100% | int (1 float) | |
| Giá gốc | `gia_thi_truong` | 100% có, 95,7% > 0 | int | |
| Ngày cập nhật | `updated_at` | 100% | datetime | Max 2026-10-16 → dùng được cho sync tăng dần |

## Kết luận khả thi Neo4j

- Thành phần: `thanh_phan_full` 94,5%, `thanh_phan_sach` 77,1% → **≥ 60% ✔**.
- Loại da: 81,9% có nghĩa → **≥ 40% ✔**.
- ⇒ **Khả thi, không bắt buộc bổ sung dữ liệu trước Bước 03.** Nhưng cạnh `TREATS (vấn đề da)` phải **suy luận** (không có trường công dụng) — dùng `recommendation_service/knowledge/concern_mapping.py` + lá danh mục; nên đánh dấu nguồn cạnh là `derived`.
- Dữ liệu "bán chạy 14 ngày"/"thường mua cùng" có đủ số lượng (342 đơn/14 ngày, 560 đơn ≥2 item) nhưng ~99% là fixture → kết quả mang tính demo.

## RỦI RO

| Rủi ro | Mức | Đề xuất |
|---|---|---|
| Truy vấn PHP `$or` trên trường không tồn tại (`thuong_hieu`, `loai_san_pham`, `barcode`) | Cao | Bước 01: tìm hãng qua `ma_thuong_hieu ∈ ids khớp tên` hoặc trường `search_text` có tên hãng |
| `buildSearchRegex` dùng chung cho `searchSuggestions`, admin, live, lọc danh mục | Cao | Không sửa hàm cũ; thêm hàm/tham số mới chỉ cho public; impact analysis trước khi sửa |
| Chroma `products` 6377 doc lệch catalog 2853, id không phải `ma_san_pham` | Cao | Re-index từ Mongo với id = `ma_san_pham` trước khi dùng cho gợi ý |
| `ProductRepository.list_visible(limit=2000)` < 2853; filter visible khác PHP | Trung bình | Bỏ/nâng giới hạn, đồng bộ filter với `visibleProductFilter` |
| Endpoint PHP cấu hình (`/api/recommend/explain`, `/api/chat/stream`) trả 404 | Trung bình | Quyết định module; thêm route mới có contract test |
| Port 5002 trùng giữa `recommendation_service` và `voice-service` | Trung bình | Không chạy rcm_flask riêng; gắn route vào app 5001 hoặc đổi port |
| Không có auth nội bộ PHP→Flask 5001 (port public 0.0.0.0) | Trung bình | Thêm header token nội bộ (tên biến mới, vd `AI_INTERNAL_TOKEN`) hoặc bind 127.0.0.1 |
| Neo4j thêm RAM (đề xuất heap 512M + pagecache 256M ≈ 1 GiB) | Thấp | Còn ~8 GiB trống; đặt `mem_limit` |
| Dữ liệu fixture (13% SP, 99% đơn) làm lệch "bán chạy" | Trung bình | Cho phép lọc `test_dataset` qua cấu hình |
| Cache Valkey `catalog:tatca:*` TTL 300s giữ kết quả 0 cũ | Thấp | Đổi `v` trong key (`'v' => 1` → 2) khi đổi thuật toán |
| chromadb client 1.5.9 (local) vs server 0.5.20 | Thấp | Test trong container, ghim phiên bản |
| Container thiếu `pytest`, `neo4j`, `rapidfuzz`, `unidecode` | Thấp | Thêm vào requirements ở bước tương ứng |
| GitNexus CLI không dùng được (query lỗi tham số, index pending) | Thấp | Chạy `analyze --index-only` trước Bước 01 |

## CÂU HỎI CẦN NGƯỜI DÙNG QUYẾT ĐỊNH

1. Có được **thêm trường `search_text`** (tên + hãng + danh mục, bỏ dấu, lowercase) vào `san_pham` trên Atlas (kèm index) không? Hay chỉ tính lúc truy vấn?
2. **Module gợi ý**: gắn API mới vào app đang chạy `chatbot_service` (port 5001) dùng thư viện `recommendation_service` (đề xuất), hay deploy `recommendation_service/rcm_flask.py` thành service riêng (cần port khác 5002)?
3. **Ngân sách RAM cho Neo4j** (đề xuất ≤ 1 GiB, Neo4j Community 5.x trong compose)?
4. Có **re-index Chroma `products` từ Mongo** (id = `ma_san_pham`, thay 6377 doc cũ) không?
5. Có loại **dữ liệu fixture** (`test_dataset`) khỏi "bán chạy"/"mua cùng" không?
6. Có thêm **token auth nội bộ** PHP→Flask cho endpoint gợi ý mới không?
7. Route `api_search_catalog_products` (Live) có nên đổi sang tìm theo từ luôn không (đang trả cả SP ẩn)?

## Quy tắc trích xuất thuộc tính (đã chốt 2026-10-21)

SP lưu **mã**, tên lấy bằng tra bảng (lookup). Mọi bước sau (search_text, Neo4j, Chroma) dùng đúng bảng này:

| Thuộc tính | Lấy từ `san_pham` | Tra bảng | Kết quả |
|---|---|---|---|
| Thương hiệu | `ma_thuong_hieu` | `thuong_hieu.ma_thuong_hieu` → `ten_thuong_hieu` | vd 163 → "B.O.M", 24 → "Torriden" (432 hãng, 0 mã mồ côi) |
| Loại SP | `ma_danh_muc` | `danh_muc.ma_danh_muc` → `ten_danh_muc` (lá, level 3) + cha qua `parent_id` | vd 7 → "Kem / Gel / Dầu Dưỡng" ⊂ "Dưỡng Ẩm" ⊂ "Chăm Sóc Da Mặt". 380 SP fixture mã mồ côi → fallback tách `danh_muc_day_du` theo " -> " (phần cuối) |
| Loại da | `ma_loai_da` | `loai_da.ma_loai_da` → `ten_loai_da` | id 4 "Unknown" = không có loại da |
| Xuất xứ hãng | `ma_xuat_xu` | `xuat_xu.ten_xuat_xu` | |
| Nơi sản xuất | `ma_noi_san_xuat` | `noi_san_xuat` | |
| Hoạt chất | `thanh_phan_sach` (chuỗi, tách dấu phẩy) | — | fallback `thanh_phan_full` (INCI) |
| Barcode | — | — | Không có trong dữ liệu → bỏ khỏi truy vấn |

PHP đã có sẵn hàm tra: `SanPham::getBrandName` (`SanPham.php:255`), `getOriginName` (`:266`), lookup map `:109-125`. Tìm theo hãng = tìm tên trong `thuong_hieu` → lấy danh sách `ma_thuong_hieu` → lọc `ma_thuong_hieu ∈ [...]`.

## Quyết định đã chốt
- Neo4j: heap 512 MiB + pagecache 128 MiB, `mem_limit: 768m`.
- Chroma: index lại collection `products` từ Mongo `san_pham` (2853 SP, id = `ma_san_pham`, văn bản dùng tên đã tra ở bảng trên); sao lưu thư mục/volume Chroma cũ trước khi nạp. Mongo không bị sửa.

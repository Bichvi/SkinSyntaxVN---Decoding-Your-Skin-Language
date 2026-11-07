# Smart Search + Recommendation (MongoDB · ChromaDB · Neo4j)

> Tài liệu kế hoạch tổng hợp. Ngày lập: 2026-10-21. Trạng thái (cập nhật 2026-10-21, Bước 09): **ĐÃ TRIỂN KHAI, CHƯA COMMIT HẾT**. Kiến trúc thực tế, chỗ khác kế hoạch, lệnh vận hành và chỉ số: [`update_neo4j_search_recommendation.md`](update_neo4j_search_recommendation.md). Nhật ký: [`docs/plans/smart-search-handoff.md`](docs/plans/smart-search-handoff.md).
>
> | Bước (kế hoạch) | Bước (sổ bàn giao) | Trạng thái |
> |---|---|---|
> | 0 Khảo sát | 00 | DONE |
> | 0.5 Tìm theo từng từ | 01 | DONE (đã commit `e42f4ac`) |
> | 1 Neo4j & schema | 02 | DONE (`mem_limit` 1g thay vì 768m) |
> | 2 ETL Mongo → Neo4j | 03 | DONE |
> | 3 Intent parser | 04 | DONE |
> | 4 API `/api/search/recommend` | 05 (chế độ A) + 05B (chế độ B) | DONE (cross-encoder tắt) |
> | 5 Tích hợp PHP | 06 | DONE |
> | 6 Giao diện Smart Fallback | 07 | DONE |
> | 7 Cá nhân hóa & đo lường | 08 | DONE (CTR boost chưa làm) |
> | 8 Kiểm thử tổng & tài liệu | 09 | DONE (E2E #5 và #9 qua trình duyệt NOT RUN vì không có tài khoản test, đã kiểm tương đương bằng CLI/API) |
> | (ngoài kế hoạch) Sửa timeout Flask: gunicorn gthread + warm-up | "Sửa timeout Flask" | DONE |
> Mục tiêu: trang `index.php?r=tatca&q=...` không bao giờ trả về trang trắng; luôn có kết quả đúng
> hoặc gợi ý có lý do rõ ràng, kết hợp tìm kiếm từ khóa, vector ngữ nghĩa và đồ thị tri thức da liễu.

---

## 1. Vấn đề hiện tại

Tìm `torriden sữa rửa mặt` → **0 kết quả**, dù shop có nhiều sản phẩm Torriden và có cả sữa rửa mặt Torriden.

**Nguyên nhân gốc** — `backend/app/models/SanPham.php:846-855` (`paginateWithProjection`):

```php
$regex = $this->buildSearchRegex($q);          // cả cụm "torriden sữa rửa mặt" thành 1 regex
$filter['$or'] = [
    ['ten_san_pham' => $regex],                 // tên: "Sữa Rửa Mặt Torriden ..." → sai thứ tự → không khớp
    ['thuong_hieu'  => $regex],                 // hãng: "Torriden" → không chứa "sữa rửa mặt" → không khớp
    ['danh_muc_day_du' => $regex], ...          // không trường nào chứa NGUYÊN cụm → 0 kết quả
];
```

Tức là: **cả cụm phải nằm trọn trong MỘT trường**. Mọi truy vấn có ≥ 2 ý (hãng + loại, loại + loại da…) đều dễ rỗng.

## 2. Hiện trạng hạ tầng (đã kiểm tra trong repo)

| Thành phần | Có sẵn? | Vị trí / chi tiết |
|---|---|---|
| MongoDB Atlas (nguồn dữ liệu gốc) | Có | collection `san_pham`; trường `ten_san_pham`, `thuong_hieu`, `danh_muc_day_du`, `loai_san_pham`, `ma_danh_muc`, `trang_thai`… |
| Tìm kiếm PHP | Có (lỗi nêu trên) | `SanPhamController` (route `tatca`, dòng ~337-388) → `SanPham::paginate()` |
| Gợi ý content-based PHP | Có | `backend/app/models/GoiYContentBased.php` — intent cứng (vd `sua_rua_mat`), `normalizeText()` bỏ dấu |
| Lịch sử tìm kiếm | Có | `SanPhamController::saveSearchHistory`, `InteractionLogger::logSearch` |
| ChromaDB | Có | `docker-compose.yml:59` image `chromadb/chroma:0.5.20`; collection `products` (chatbot) và `recommendation_products` |
| Embedding model | Có | `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` (`recommendation/config.py:24`) — đa ngôn ngữ, hiểu tiếng Việt |
| Hybrid search | Có | `recommendation_service/hybrid_search.py` — BM25 trong RAM + Chroma, trộn bằng **RRF** |
| Reranker | Có | `cross-encoder/mmarco-mMiniLMv2-L12-H384-v1` (`recommendation_service/config.py:42`) |
| Kiểm tra xung đột hoạt chất | Có | `recommendation_service/conflict_checker.py`, `safety_filter.py` |
| Redis cache | Có | `redis:7-alpine`, `recommendation_service/response_cache.py` |
| Flask blueprint gợi ý | Có | `recommendation/routes.py` (`recommendation_bp`) |
| **Neo4j** | **Chưa có** | Cần dựng mới |
| Trang tìm kiếm dùng AI | **Chưa** | Trang `tatca` chưa gọi bất kỳ service Python nào |

> ⚠ Có **hai** thư mục song song: `recommendation/` và `recommendation_service/`. Bước 0 phải xác định cái nào đang chạy thật trước khi mở rộng.

---

## 3. Kiến trúc mục tiêu

### 3.1 Nguyên tắc bất biến

1. **MongoDB là nguồn sự thật duy nhất.** ChromaDB và Neo4j chỉ là *bản sao dẫn xuất* (derived index), có thể xóa và dựng lại bất kỳ lúc nào. Không bao giờ ghi ngược về Mongo.
2. **PHP không gọi trực tiếp Neo4j/Chroma.** PHP → Flask (HTTP nội bộ) → Neo4j/Chroma/Mongo. Đúng ranh giới service trong `AGENTS.md`.
3. **Graceful degradation (xuống cấp mềm).** Neo4j chết → bỏ nhánh graph. Chroma chết → bỏ nhánh semantic. Flask chết → PHP tự lấy SP bán chạy. **Không bao giờ trang trắng.**
4. **Flask chỉ trả `product_id` + mã lý do.** Giá, ảnh, tồn kho PHP đọc lại từ Mongo → luôn đúng giá thật.
5. **Không hardcode tên hãng ra UI.** Copy hiển thị theo công dụng.

### 3.2 Sơ đồ thành phần

```
┌──────────────── Trình duyệt ────────────────┐
│ tatca.php: kết quả / Smart Fallback UI       │
└───────────────┬─────────────────────────────┘
                │ GET index.php?r=tatca&q=...
┌───────────────▼─────────────────────────────┐
│ PHP (backend MVC)                            │
│  SanPhamController::tatca                    │
│   ├─ (A) SanPham::paginate  → MongoDB        │  tìm theo từng từ (Bước 0.5)
│   └─ nếu < 4 kết quả:                        │
│      SearchRecommendClient (curl, 1.2s)      │──┐
│      ├─ ok   → SanPham::findByIds($in)        │  │
│      └─ lỗi  → SanPham::bestSellers()        │  │
└──────────────────────────────────────────────┘  │ POST /api/search/recommend
┌─────────────────────────────────────────────────▼──────────────┐
│ Flask ai-service-flask  (blueprint search_bp)                   │
│  1. IntentParser  ─ từ điển entity (Neo4j/Mongo) + rapidfuzz    │
│  2. Redis cache lookup                                           │
│  3. ThreadPoolExecutor chạy song song (timeout 800ms):           │
│     ├─ same_brand        → Neo4j (Cypher)                        │
│     ├─ category_alt      → Neo4j (ingredient-profile similarity) │
│     ├─ semantic          → hybrid_search (BM25 + Chroma, RRF)    │
│     ├─ personalized      → Neo4j SUITABLE_FOR + conflict_checker │
│     └─ trending          → Mongo aggregate (luôn có)             │
│  4. Reranker: weighted score + cross-encoder (top-N) + dedupe    │
│  5. Trả {mode, sections[{key, items[{product_id, reason_code}]}]}│
└───────┬───────────────┬───────────────┬─────────────────────────┘
        │               │               │
   ┌────▼───┐     ┌─────▼────┐    ┌─────▼────┐
   │ Neo4j  │     │ ChromaDB │    │  Redis   │
   └────▲───┘     └─────▲────┘    └──────────┘
        │ ETL (sync)    │ indexer (đã có)
        └──────┬────────┘
          ┌────▼─────┐
          │ MongoDB  │  ← nguồn sự thật
          └──────────┘
```

### 3.3 Luồng xử lý một truy vấn (end-to-end)

```
User gõ "torriden sữa rửa mặt"
 │
 ├─[PHP] normalizeKeyword → saveSearchHistory → InteractionLogger::logSearch
 ├─[PHP] paginate(q) theo TỪNG TỪ  (AND giữa từ, OR giữa trường, bỏ dấu)
 │     ├─ ≥ 4 SP  → render kết quả bình thường (mode=exact). KẾT THÚC.
 │     └─ < 4 SP  → tiếp tục
 ├─[PHP] POST Flask /api/search/recommend {q, user_hash, skin_profile, cart_ids, exclude_ids}
 │
 ├─[Flask] IntentParser:
 │     normalized = "torriden sua rua mat"
 │     brands=["torriden"], categories=["sua_rua_mat"], confidence=0.95
 ├─[Flask] Redis GET search:rec:{sha1(normalized+skin_hash)} → hit thì trả luôn
 ├─[Flask] chạy song song 5 nhánh, mỗi nhánh có try/except + timeout riêng
 ├─[Flask] gộp → chấm điểm → cross-encoder rerank top 40 → dedupe → cắt 8/section
 ├─[Flask] Redis SETEX 300s → trả JSON
 │
 ├─[PHP] gom product_id → Mongo $in (1 query) → giữ thứ tự → lọc ẩn/hết hàng
 ├─[PHP] map reason_code → câu tiếng Việt (config/search_reasons.php)
 └─[View] banner trung tính + chips + các section + badge lý do + CTA "Hỏi Syna"
```

---

## 4. Công nghệ & thư viện — dùng để làm gì

### 4.1 Python (ai-service-flask)

| Thư viện | Trạng thái | Tác dụng trong dự án |
|---|---|---|
| `flask` 3.0.3 | có | Web framework; mỗi tính năng là một **Blueprint** (`search_bp`) |
| `gunicorn` 23 | có | WSGI server chạy production (`gunicorn_config.py`) |
| `pymongo` ≥ 4.6 | có | Đọc Mongo (ETL, trending). **Chỉ đọc** |
| `chromadb` + `langchain-chroma` | có | Vector store; tìm sản phẩm gần nghĩa |
| `sentence-transformers` | có | Sinh embedding (`paraphrase-multilingual-MiniLM-L12-v2`, 384 chiều) và chạy **CrossEncoder** rerank |
| `langchain-huggingface` | có | Bọc model HF thành `Embeddings` cho LangChain |
| BM25 nội bộ (`hybrid_search.BM25Index`) | có | Tìm từ khóa chính xác (tên, mã SP) — bù cho điểm yếu của vector |
| `redis` ≥ 5 | có | Cache response 5 phút; khóa phân tán khi sync |
| **`neo4j`** (driver chính thức, 5.x) | **thêm mới** | Kết nối Bolt tới Neo4j; dùng `GraphDatabase.driver`, session `execute_read` với **tham số** (chống Cypher injection) |
| **`rapidfuzz`** | **thêm mới** | So khớp mờ tên hãng/danh mục (`toriden` → `torriden`), nhanh hơn `fuzzywuzzy` ~10 lần |
| **`unidecode`** (hoặc tự viết bảng bỏ dấu) | thêm mới | Bỏ dấu tiếng Việt khi chuẩn hóa key |
| **`pyyaml`** | thêm mới (nếu chưa có) | Đọc `synonyms.yaml`, `ingredient_rules.yaml` |
| **`pydantic`** | kiểm tra | Validate request/response schema (đã có `schemas.py`, xem đang dùng gì) |
| `concurrent.futures` (stdlib) | có | `ThreadPoolExecutor` chạy 5 nhánh song song với timeout |
| `pytest`, `pytest-mock` | có/kiểm tra | Unit + integration test |
| **`testcontainers[neo4j]`** | tùy chọn | Bật Neo4j thật trong test tích hợp |

### 4.2 PHP

| Thành phần | Tác dụng |
|---|---|
| `mongodb/mongodb` (Composer, đã có) | Query sản phẩm, `$in` theo id |
| `curl` (ext-curl) | Gọi Flask; set `CURLOPT_TIMEOUT_MS=1200`, `CURLOPT_CONNECTTIMEOUT_MS=300` |
| Lớp mới `SearchRecommendClient` | Đóng gói gọi Flask + circuit breaker đơn giản (lỗi 3 lần liên tiếp → bỏ qua 60s, lưu APCu/Redis) |
| `config/search_reasons.php` | Map `reason_code` → câu tiếng Việt |

### 4.3 Hạ tầng

| Service | Image | Ghi chú |
|---|---|---|
| Neo4j | `neo4j:5-community` | Bolt 7687 (nội bộ network), Browser 7474 bind `127.0.0.1`; plugin **APOC** (tiện ích batch) |
| ChromaDB | `chromadb/chroma:0.5.20` | Đã có |
| Redis | `redis:7-alpine` | Đã có |

### 4.4 Vì sao cần cả 3 kỹ thuật?

| Kỹ thuật | Giỏi | Kém | Vai trò |
|---|---|---|---|
| Từ khóa (Mongo regex / BM25) | Tên riêng, mã SP, hãng chính xác | Không hiểu đồng nghĩa, câu tự nhiên | Lớp 1 – kết quả chính xác |
| Vector (Chroma + embedding) | Hiểu ý "srm cho da khô bong tróc" | Hay nhầm hãng/loại, không giải thích được | Lớp 2 – gần nghĩa |
| Graph (Neo4j) | Quan hệ nhiều bước: hoạt chất ↔ vấn đề da ↔ loại da; xung đột; **lý do** | Cần dữ liệu thành phần sạch | Lớp 3 – thay thế thông minh, cá nhân hóa, giải thích |

---

## 5. Mô hình dữ liệu Neo4j

```
(:Brand {key,name})◄─[:OF_BRAND]─(:Product {id,name,price,sold,rating,in_stock,active})
                                     │  │  │  │  │
             [:IN_CATEGORY]──────────┘  │  │  │  └──[:SIMILAR_TO {score,reason}]──►(:Product)
                   ▼                    │  │  └─────[:CO_PURCHASED {count}]──────►(:Product)
       (:Category {key,name})           │  └──[:SUITABLE_FOR]──►(:SkinType {key})
             │[:CHILD_OF]               │     [:TARGETS]──────►(:Concern {key})
             ▼                          │     [:HAS_BENEFIT]──►(:Benefit {key})
       (:Category)               [:CONTAINS {rank}]
                                        ▼
                         (:Ingredient {key,inci,name_vi,role})
                            │[:HELPS]──►(:Concern)
                            └─[:CONFLICTS_WITH {severity,reason}]─(:Ingredient)
```

- `Product.id` = `ma_san_pham` của Mongo (dạng string) để PHP map ngược.
- Mọi `key` = chữ thường, bỏ dấu, thay khoảng trắng bằng `_`, đã gộp đồng nghĩa (`srm`, `cleanser`, `gel rửa mặt` → `sua_rua_mat`).
- `CONTAINS.rank` = vị trí trong bảng thành phần (càng nhỏ càng nhiều nồng độ) → dùng làm trọng số.

---

## 6. Lối code (conventions)

**Python**
- Cấu trúc package mới: `ai-service-flask/search_service/` gồm `config.py`, `intent_parser.py`, `graph_repository.py`, `branches.py`, `ranker.py`, `routes.py`, `schemas.py`, `tests/`. ETL đặt ở `ai-service-flask/graph_sync/`.
- **Repository pattern**: chỉ `graph_repository.py` được viết Cypher; logic khác gọi hàm Python thuần → dễ mock khi test.
- **Dependency injection**: hàm nhận `driver`, `cache`, `clock` qua tham số/constructor, không import global → test không cần DB thật.
- Driver Neo4j tạo **một lần** khi app khởi động (singleton, connection pool), đóng khi shutdown.
- **Mọi Cypher dùng tham số** `$q`, `$brand` — cấm f-string nối chuỗi.
- Type hints đầy đủ, `logging` với `extra={"correlation_id": ...}`; không log email/SĐT/hồ sơ da thô.
- Cấu hình qua biến môi trường, có giá trị mặc định an toàn; feature flag `SEARCH_GRAPH_ENABLED`, `SEARCH_SEMANTIC_ENABLED`, `SEARCH_CO_PURCHASE_ENABLED`.

**PHP**
- Không đổi chữ ký `SanPham::paginate()` (dùng chung admin). Thêm hàm mới hoặc tham số tùy chọn có mặc định giữ hành vi cũ.
- Client gọi Flask là **một lớp riêng** trong `backend/app/services/`, controller chỉ gọi lớp này.
- Escape mọi output bằng `htmlspecialchars`; view không chứa logic truy vấn.

**Hợp đồng API (contract)** — `POST /api/search/recommend`

```json
// Request
{ "q": "torriden sữa rửa mặt", "user_hash": "sha256...|null",
  "skin_profile": {"skin_type": "kho", "concerns": ["mun"]} | null,
  "cart_ids": ["123"], "exclude_ids": ["45"], "limit_per_section": 8 }

// Response 200
{ "mode": "fallback", "degraded": false, "took_ms": 214,
  "intent": {"brands": ["torriden"], "categories": ["sua_rua_mat"], "confidence": 0.95},
  "sections": [
    {"key": "category_alt", "items": [
      {"product_id": "102", "score": 0.83,
       "reason_code": "shared_ingredients", "reason_params": ["Hyaluronic Acid", "Panthenol"]}]},
    {"key": "same_brand", "items": [...]},
    {"key": "trending", "items": [...]}
  ] }
```

`reason_code` cho phép: `same_brand`, `same_category`, `shared_ingredients`, `fits_skin_type`, `targets_concern`, `semantic_match`, `trending`, `bought_together`.

---

## 7. Pipeline chi tiết từng bước

Mỗi bước gồm: **Hiện trạng → Yêu cầu → Kỹ thuật → Kết quả cần có → Prompt giao việc**. Làm đúng thứ tự; bước sau dựa trên output bước trước.

---

### Bước 0 — Khảo sát (chỉ đọc)

**Hiện trạng:** đã biết nguyên nhân 0 kết quả và hạ tầng ở mục 2. Chưa biết: % sản phẩm có thành phần/loại da/lượt bán; `recommendation/` hay `recommendation_service/` đang chạy thật; PHP đang gọi Flask thế nào (URL, timeout, auth nội bộ); bảng xung đột hoạt chất lưu ở đâu.

**Yêu cầu:** không sửa file nào. Đếm độ đầy đủ dữ liệu bằng aggregate chỉ đọc.

**Kỹ thuật:** GitNexus `query`/`context` cho `paginate`, `GoiYContentBased`, `hybrid_search`; Mongo aggregate `$group` đếm field tồn tại (`$ifNull`, `$type`).

**Kết quả cần có:** `docs/plans/2026-10-21-neo4j-search-audit.md` gồm luồng hiện tại (file:dòng), schema thật + % đầy đủ từng trường, module Python đang dùng, cách PHP gọi Flask, danh sách rủi ro, câu hỏi cần quyết định.

```
PROMPT BƯỚC 0
Đọc AGENTS.md, .agents/, update.md, update_smart_search_neo4j.md. CHỈ KHẢO SÁT, KHÔNG SỬA FILE.
1. Xác nhận luồng r=tatca → SanPhamController → SanPham::paginate → buildSearchRegex; ghi file:dòng.
2. Lấy 3 document san_pham mẫu (che thông tin nhạy cảm). Bằng aggregate CHỈ ĐỌC, thống kê % SP active có:
   thuong_hieu, ma_danh_muc, thanh_phan (dạng chuỗi hay mảng?), loai_da/skin_type, cong_dung/concern,
   da_ban/sold, danh_gia/rating, ton_kho. Liệt kê giá trị thuong_hieu viết không thống nhất.
3. ai-service-flask: recommendation/ và recommendation_service/ — cái nào được đăng ký vào app đang chạy
   (xem chatbot_flask.py, rcm_flask.py, gunicorn_config.py, docker-compose)? Liệt kê endpoint, collection
   Chroma đang index trường nào, model embedding/reranker, cách cache.
4. PHP gọi Flask ở đâu (grep curl/AI_SERVICE_URL): URL, timeout, header auth nội bộ.
5. Bảng/collection xung đột hoạt chất của giỏ hàng; hồ sơ khảo sát da lưu ở đâu, trường nào.
6. docker-compose: service, network, volume, healthcheck.
Chạy GitNexus query/context cho paginate, GoiYContentBased, hybrid_search.
Ghi docs/plans/2026-10-21-neo4j-search-audit.md; kết thúc bằng RỦI RO và CÂU HỎI CẦN QUYẾT ĐỊNH.
```

---

### Bước 0.5 — Sửa tìm kiếm theo từng từ (làm sớm, lợi ích lớn nhất)

**Hiện trạng:** regex nguyên cụm, so từng trường riêng (mục 1).

**Yêu cầu:** tách từ khóa thành token; mỗi token khớp **ít nhất một trường** (OR), mọi token phải khớp (AND). Hỗ trợ không dấu. Xếp SP khớp tên lên đầu. Không đổi hành vi trang admin.

**Kỹ thuật:**
- Bỏ token rác/stopword ngắn (`cho`, `và`, `của`), giới hạn 6 token để tránh query nặng.
- Mỗi token: `preg_quote` → regex không phân biệt hoa thường. Để khớp không dấu, cách bền vững nhất là thêm trường `search_text` (tên + hãng + danh mục + loại, đã bỏ dấu, chữ thường) — **cần hỏi người dùng** trước khi thêm trường vào Mongo; nếu chưa được phép, dùng regex lớp ký tự (`[aàáạảãâầấậẩẫăằắặẳẵ]`).
- Filter mẫu:
  ```php
  $filter['$and'] = array_map(fn($tok) => ['$or' => [
      ['ten_san_pham' => $rx($tok)], ['thuong_hieu' => $rx($tok)],
      ['danh_muc_day_du' => $rx($tok)], ['loai_san_pham' => $rx($tok)],
  ]], $tokens);
  ```
- Nên tạo index Atlas phù hợp (hoặc Atlas Search sau này) — ghi vào rủi ro, không tự tạo.

**Kết quả cần có:** `torriden sữa rửa mặt`, `sua rua mat torriden`, `torriden srm`* đều ra sữa rửa mặt Torriden (*cần synonym ở Bước 3). Admin tìm theo mã SP/barcode vẫn chạy. Có test hồi quy; `php -l` sạch; E2E chụp ảnh trước/sau.

```
PROMPT BƯỚC 0.5
Đọc update_smart_search_neo4j.md mục 1, 6, Bước 0.5 và file audit Bước 0.
Chạy GitNexus impact upstream cho SanPham::paginate, paginateWithProjection, buildSearchRegex; báo callers & risk.
Nếu HIGH/CRITICAL: dừng và báo tôi.
Sửa tìm kiếm thành AND-giữa-token / OR-giữa-trường, hỗ trợ không dấu, tối đa 6 token, bỏ stopword.
Giữ nguyên nhánh is_numeric (mã SP) và hành vi admin. Không đổi chữ ký public.
Sắp xếp: SP có tên khớp tất cả token lên trước (nếu sort=default).
Test: viết test hồi quy (KHÔNG chạy hàng loạt backend/tests vì ghi DB thật — chỉ chạy test mới, chỉ đọc).
php -l; E2E Playwright với "torriden sữa rửa mặt", "sua rua mat", "xyzabc" → ảnh vào report/search-token-e2e/.
```

---

### Bước 1 — Dựng Neo4j & schema

**Hiện trạng:** chưa có Neo4j.

**Yêu cầu:** thêm service vào `docker-compose.yml`; biến môi trường; migration constraint/index idempotent; tài liệu schema.

**Kỹ thuật:**
```yaml
neo4j:
  image: neo4j:5-community
  container_name: skinsyntax-neo4j
  environment:
    NEO4J_AUTH: ${NEO4J_USER}/${NEO4J_PASSWORD}
    NEO4J_PLUGINS: '["apoc"]'
    NEO4J_server_memory_heap_max__size: 1G
    NEO4J_server_memory_pagecache_size: 512M
  ports: ["127.0.0.1:7474:7474", "127.0.0.1:7687:7687"]
  volumes: [neo4j_data:/data]
  healthcheck:
    test: ["CMD-SHELL", "cypher-shell -u $${NEO4J_USER} -p $${NEO4J_PASSWORD} 'RETURN 1' || exit 1"]
```
Migration `migrations/neo4j/001_constraints.cypher`:
```cypher
CREATE CONSTRAINT product_id IF NOT EXISTS FOR (p:Product) REQUIRE p.id IS UNIQUE;
CREATE CONSTRAINT brand_key IF NOT EXISTS FOR (b:Brand) REQUIRE b.key IS UNIQUE;
-- tương tự Category, Ingredient, SkinType, Concern, Benefit
CREATE INDEX product_active IF NOT EXISTS FOR (p:Product) ON (p.active);
CREATE FULLTEXT INDEX product_search IF NOT EXISTS FOR (n:Product|Brand|Category) ON EACH [n.name];
```

**Kết quả cần có:** container healthy; chạy migration 2 lần không lỗi; `SHOW CONSTRAINTS` đủ 7; `.env.example` có `NEO4J_URI`, `NEO4J_USER`, `NEO4J_PASSWORD`; `docs/neo4j/schema.md`; `.gitignore` chặn dữ liệu Neo4j.

```
PROMPT BƯỚC 1
Đọc update_smart_search_neo4j.md mục 3, 5, Bước 1 và file audit. Dùng brainstorming + writing-plans trước khi code.
Thêm service neo4j:5-community (APOC) vào docker-compose như mẫu; cổng chỉ bind 127.0.0.1; volume neo4j_data;
healthcheck cypher-shell. Mật khẩu chỉ từ .env, thêm key rỗng vào .env.example. Không commit .env/dữ liệu.
Viết migrations/neo4j/001_constraints.cypher (idempotent) + script chạy migration (Python dùng driver neo4j).
Viết docs/neo4j/schema.md (node, quan hệ, quy tắc key, ví dụ).
Kiểm chứng: docker compose up -d neo4j → healthy; chạy migration 2 lần; SHOW CONSTRAINTS; dán output.
Chưa nạp dữ liệu.
```

---

### Bước 2 — ETL đồng bộ Mongo → Neo4j

**Hiện trạng:** dữ liệu chỉ ở Mongo; xung đột hoạt chất nằm ở `conflict_checker.py`/DB riêng.

**Yêu cầu:** script chỉ đọc Mongo, ghi Neo4j theo lô, idempotent, có `--full`, `--since`, `--dry-run`.

**Kỹ thuật — pipeline ETL:**
```
Extract   pymongo find(projection tối thiểu) theo batch 500, --since dùng updated_at
   ↓
Transform normalize_key()  → bỏ dấu (unidecode), lower, synonyms.yaml
          parse_ingredients() → tách chuỗi theo , ; / → map INCI qua ingredient_aliases.yaml
          infer_tags()      → ingredient_rules.yaml: HA/Panthenol→cap_am, Centella→lam_diu,
                              Salicylic→tri_mun … (KHÔNG dùng LLM)
   ↓
Load      UNWIND $rows AS r MERGE (p:Product {id:r.id}) SET p += r.props
          MERGE (b:Brand {key:r.brand_key}) MERGE (p)-[:OF_BRAND]->(b) ...
          SP không còn trong Mongo/đã ẩn → SET p.active=false (không DELETE)
   ↓
Derive    SIMILAR_TO top-10/SP:
          score = 0.40·Jaccard(ingredients) + 0.25·cùng category + 0.15·Jaccard(benefit)
                + 0.10·Jaccard(skin_type) + 0.10·cùng brand
          reason = hoạt chất chung nổi bật nhất
   ↓
Report    số node/quan hệ, % SP có ingredient/skin_type, top 50 thành phần không map được (chỉ đếm)
```
Dùng Redis lock `graph_sync:lock` để không chạy 2 job cùng lúc.

**Kết quả cần có:** số `Product{active:true}` = số SP active ở Mongo; `MATCH (:Brand{key:'torriden'})<-[:OF_BRAND]-(p) RETURN count(p)` khớp Mongo; báo cáo độ phủ; pytest với fixture giả.

```
PROMPT BƯỚC 2
Đọc update_smart_search_neo4j.md mục 5, 6, Bước 2 và file audit (tên trường thật).
Tạo ai-service-flask/graph_sync/ (normalize.py, ingredients.py, rules.py, loader.py, similarity.py, __main__.py)
và data/graph/{synonyms,ingredient_aliases,ingredient_rules}.yaml.
Mongo CHỈ ĐỌC. Neo4j MERGE theo lô 500, tham số hóa. Nạp CONFLICTS_WITH từ nguồn xung đột đã có (không tự chế).
CLI: python -m graph_sync --full | --since ISO | --dry-run. Redis lock chống chạy song song.
pytest: normalize_key, synonym, parse_ingredients, infer_tags, công thức similarity — fixture giả, không DB thật.
Chạy --dry-run rồi --full; báo cáo số lượng, % độ phủ, so khớp count Torriden với Mongo.
Nếu % ingredient < 60%: DỪNG, báo tôi trước khi sang Bước 3.
```

---

### Bước 3 — Intent Parser

**Hiện trạng:** intent cứng trong PHP (`GoiYContentBased::strictIntentTokens`).

**Yêu cầu:** parser Python tất định, chịu gõ sai nhẹ, không dấu, thứ tự bất kỳ.

**Kỹ thuật:**
1. Từ điển entity: load từ Neo4j (`MATCH (b:Brand) RETURN b.key, b.name`…) + `synonyms.yaml`; cache trong RAM, làm mới mỗi 10 phút (TTL). Neo4j chết → fallback đọc Mongo `distinct`.
2. Chuẩn hóa → sinh n-gram 1..4 → **longest-match-first** (khớp "sữa rửa mặt" trước "mặt").
3. Token chưa khớp → `rapidfuzz.process.extractOne(tok, brand_names, scorer=fuzz.WRatio, score_cutoff=88)`.
4. Giá: regex `(dưới|<)\s*(\d+)\s*(k|nghìn|ngàn|tr|triệu)?`, `(\d+)\s*-\s*(\d+)`.
5. `confidence` = tỷ lệ ký tự đã gán vào entity. < 0.5 → để nhánh semantic gánh (không gọi LLM ở MVP).

**Kết quả cần có:** `intent_parser.parse(q) -> Intent` (pydantic/dataclass); ≥ 25 test pass.

```
PROMPT BƯỚC 3
Đọc update_smart_search_neo4j.md Bước 3. TDD: viết test trước.
Tạo ai-service-flask/search_service/intent_parser.py + tests/test_intent_parser.py.
Từ điển: Neo4j (qua graph_repository) + synonyms.yaml, TTL 10 phút, fallback Mongo distinct.
Longest-match n-gram, rapidfuzz WRatio ≥ 88 cho brand, parse giá VN (k, nghìn, tr, triệu, khoảng a-b).
≥ 25 case: "torriden sữa rửa mặt", "sua rua mat torriden", "toriden srm", "srm cho da dầu mụn",
"toner không cồn dưới 300k", "serum vitamin c", "kem chống nắng da nhạy cảm", "", "   ", "@@##",
"'; MATCH (n) DETACH DELETE n", chuỗi 500 ký tự…
Không gọi LLM. Chạy pytest, dán kết quả.
```

---

### Bước 4 — API gợi ý lai `/api/search/recommend`

**Hiện trạng:** đã có `hybrid_search` (BM25 + Chroma + RRF), reranker cross-encoder, `conflict_checker`, `response_cache`. Chưa có API cho trang tìm kiếm.

**Yêu cầu:** blueprint mới **tái sử dụng** các module trên, không viết lại.

**Kỹ thuật — 5 nhánh song song** (`ThreadPoolExecutor(max_workers=5)`, `wait(timeout=0.8)`; nhánh quá hạn/lỗi bị bỏ, đặt `degraded=true`):

| Nhánh | Nguồn | Cypher / cách làm |
|---|---|---|
| `same_brand` | Neo4j | `MATCH (b:Brand{key:$brand})<-[:OF_BRAND]-(p:Product{active:true}) OPTIONAL MATCH (p)-[:IN_CATEGORY]->(c)-[:CHILD_OF*0..1]->(:Category{key:$cat_parent}) RETURN p.id, (c IS NOT NULL) AS near ORDER BY near DESC, p.sold DESC LIMIT 20` |
| `category_alt` | Neo4j | Lấy "hồ sơ hoạt chất" của hãng: top-8 ingredient phổ biến trong SP của hãng → tìm SP thuộc `$cat`, khác hãng, đếm hoạt chất chung (trọng số theo `CONTAINS.rank`) |
| `semantic` | `hybrid_search` có sẵn | BM25 + Chroma, RRF k=60, top 30 |
| `personalized` | Neo4j + `conflict_checker` | SP `SUITABLE_FOR` loại da / `TARGETS` vấn đề da; loại SP có `CONFLICTS_WITH` với `cart_ids` |
| `trending` | Mongo | aggregate đơn hàng 14 ngày (hoặc `da_ban` nếu chưa có), luôn chạy |

**Chấm điểm & xếp hạng:**
```
score = 0.35·graph + 0.30·semantic + 0.20·popularity + 0.15·personal − 0.5·(hết hàng)
        (mỗi thành phần chuẩn hóa min-max về [0,1]; trọng số trong config)
→ CrossEncoder (mmarco-mMiniLMv2) chấm lại top 40 theo cặp (q, tên+danh mục)
→ final = 0.7·score + 0.3·ce_score
→ dedupe: mỗi product_id chỉ giữ ở section có điểm cao nhất
→ bỏ exclude_ids, cắt limit_per_section, bỏ section rỗng
```
Cache: Redis `search:rec:v1:{sha1(normalized_q|skin_hash|cart_hash)}` TTL 300s. Bỏ qua cache nếu `degraded`.

**Kết quả cần có:** endpoint trả đúng contract (mục 6); p95 < 800ms khi cache ấm; Neo4j tắt → 200 + `degraded:true` + vẫn có `semantic`/`trending`; test unit (ranker, dedupe), test tích hợp (mock driver hoặc testcontainers), contract test.

```
PROMPT BƯỚC 4
Đọc update_smart_search_neo4j.md mục 3, 4, 6, Bước 4 + audit (module nào đang chạy thật).
GitNexus impact cho hybrid_search, reranker, conflict_checker, response_cache trước khi đụng tới.
Tạo search_service/{graph_repository,branches,ranker,routes,schemas}.py; đăng ký search_bp vào app đang chạy,
dùng cùng cơ chế auth nội bộ như các endpoint PHP đang gọi. TÁI SỬ DỤNG hybrid_search/reranker/conflict_checker.
5 nhánh song song timeout 800ms; Cypher tham số hóa; công thức điểm và trọng số trong config + feature flag.
Chỉ trả product_id + reason_code/params. Log correlation_id, không log PII. Cache Redis 300s.
Test: unit ranker/dedupe; integration với mock driver; case Neo4j down → 200 + degraded; contract JSON schema.
Đo p95 bằng script 100 request; dán kết quả pytest và latency.
```

---

### Bước 5 — Tích hợp PHP

**Hiện trạng:** controller gọi `paginate` rồi render; rỗng → hộp trống.

**Yêu cầu:** khi < 4 kết quả mới gọi Flask; không bao giờ trắng trang.

**Kỹ thuật:**
- `backend/app/services/SearchRecommendClient.php`: curl POST JSON, timeout 1.2s/connect 0.3s, circuit breaker (3 lỗi → nghỉ 60s), trả `null` khi lỗi.
- `SanPham::findVisibleByIds(array $ids)`: một query `$in` + `availableProductFilter()`, giữ thứ tự theo `$ids` bằng map PHP.
- Fallback cuối: `SanPham::bestSellers(8)`.
- `config/search_reasons.php`:
  ```php
  return [
    'shared_ingredients' => 'Chung hoạt chất %s',
    'fits_skin_type'     => 'Hợp %s',
    'same_category'      => 'Cùng công dụng',
    'trending'           => 'Bán chạy tuần này',
    // ...
  ];
  ```
- `user_hash` = sha256(email + salt), không gửi email thô.

**Kết quả cần có:** mọi từ khóa đều có nội dung; contract test PHP ↔ Flask; `php -l` sạch.

```
PROMPT BƯỚC 5
Đọc update_smart_search_neo4j.md mục 3.3, 4.2, 6, Bước 5. GitNexus impact cho SanPhamController (route tatca)
và SanPham; báo risk. Tạo SearchRecommendClient (timeout 1.2s, circuit breaker), SanPham::findVisibleByIds,
SanPham::bestSellers (nếu chưa có), config/search_reasons.php. Controller: < 4 kết quả → gọi client → map
id → card; lỗi → bestSellers. Giữ nguyên dữ liệu truyền view khi có kết quả. Escape output.
Không gửi PII sang Flask (chỉ user_hash). php -l; contract test với response mẫu.
```

---

### Bước 6 — Giao diện Smart Fallback

**Hiện trạng:** hộp "Không tìm thấy sản phẩm phù hợp" + 1 nút (ảnh người dùng gửi).

**Yêu cầu:** UI không nêu tên hãng, nhóm theo công dụng, có lý do.

**Bố cục:**
```
┌ Banner: Chưa có sản phẩm khớp chính xác với «q». Đây là những lựa chọn gần nhất cho bạn.
├ Chips: [Cùng thương hiệu] [Làm sạch dịu nhẹ] [Hợp da của bạn] [Bán chạy]   (cuộn ngang trên mobile)
├ Section "Làm sạch dịu nhẹ, cân bằng ẩm"      ← category_alt   (badge: Chung hoạt chất HA, B5)
├ Section "Cùng dòng sản phẩm bạn quan tâm"    ← same_brand     (KHÔNG ghi tên hãng)
├ Section "Hợp với làn da của bạn"             ← personalized   (chỉ hiện khi có hồ sơ da)
├ Section "Được mua nhiều tuần này"            ← trending
└ CTA: [Hỏi Syna để tìm sản phẩm thay thế] → mở chatbot, điền sẵn q
```
Tái sử dụng partial card sản phẩm sẵn có; grid 4 cột desktop / 2 cột mobile; section rỗng ẩn; `data-section`/`data-reason` cho tracking.

**Kết quả cần có:** E2E Playwright thật với `torriden sữa rửa mặt`, `xyzabc`, `srm da dầu`; desktop + 375px không cuộn ngang; ảnh trong `report/search-fallback-e2e/`.

```
PROMPT BƯỚC 6
Đọc update_smart_search_neo4j.md Bước 6 và .claude/skills/e2e/SKILL.md. Sửa frontend/views/tatca.php phần rỗng,
dùng lại card partial + design token hiện có. Banner trung tính, chips, sections theo thứ tự API, badge lý do,
CTA Syna (q điền sẵn, escape). Responsive 375px. Thêm data-attribute để tracking.
Chạy Playwright thật 3 từ khóa, desktop + mobile, ảnh vào report/search-fallback-e2e/. Không claim PASS nếu chưa chạy.
```

---

### Bước 7 — Cá nhân hóa & học từ hành vi

**Hiện trạng:** có hồ sơ khảo sát da, lịch sử tìm kiếm, `InteractionLogger`; chưa vào graph.

**Yêu cầu & kỹ thuật:**
- Ghi `search_events` (Mongo): `{ts, q_norm, section, product_id, action: view|click|add_to_cart, user_hash}`.
- Job `python -m graph_sync --derive co_purchase`: từ đơn hàng 90 ngày tạo `(:Product)-[:CO_PURCHASED{count}]->(:Product)` (chỉ cặp count ≥ 2).
- Thêm nhánh/thành phần điểm `bought_together` với trọng số nhỏ (0.1), flag `SEARCH_CO_PURCHASE_ENABLED`.
- Script `scripts/search_metrics.py`: zero-result rate, CTR theo section, add-to-cart sau fallback.

**Kết quả cần có:** báo cáo chỉ số trước/sau; flag bật/tắt hoạt động.

```
PROMPT BƯỚC 7
Đọc update_smart_search_neo4j.md Bước 7. Ghi search_events từ view (fetch beacon, không chặn UI),
job CO_PURCHASED (Mongo chỉ đọc), thêm vào ranker sau flag. Script báo cáo 3 chỉ số. Test unit cho job và ranker.
```

---

### Bước 8 — Kiểm thử tổng & tài liệu

**Yêu cầu:** pytest `ai-service-flask`; `php -l` các file đổi; skill lint; E2E toàn luồng **và** khi `docker stop skinsyntax-neo4j` (trang vẫn có gợi ý); đo p95; `git diff --check`; GitNexus `detect_changes --scope all`.

**Kết quả cần có:** cập nhật file này (trạng thái từng bước), `update.md` tổng; báo cáo lệnh đã chạy, file đã đổi, phần bỏ qua, rủi ro. Chưa commit khi chưa được đồng ý.

```
PROMPT BƯỚC 8
Chạy toàn bộ kiểm thử theo update_smart_search_neo4j.md Bước 8, gồm kịch bản tắt Neo4j và tắt Flask.
Cập nhật trạng thái trong file này và update.md. git diff --check; GitNexus detect_changes --scope all.
Báo cáo đầy đủ; KHÔNG commit nếu tôi chưa đồng ý.
```

---

## 8. Rủi ro chính

| Rủi ro | Ảnh hưởng | Giảm thiểu |
|---|---|---|
| Dữ liệu thành phần thiếu/bẩn | Neo4j gần như vô dụng | Đo ở Bước 0/2; dừng nếu < 60%; bổ sung dữ liệu trước |
| Regex nhiều token trên Atlas chậm | Trang tìm kiếm chậm | Giới hạn 6 token; cân nhắc trường `search_text` + index hoặc Atlas Search |
| `paginate` dùng chung admin | Hỏng tìm kiếm admin | GitNexus impact; không đổi chữ ký; test hồi quy |
| Hai module recommendation song song | Sửa nhầm chỗ | Bước 0 xác định module đang chạy |
| Neo4j tốn RAM trên máy dev | Docker chậm | Giới hạn heap 1G, pagecache 512M |
| Đồng bộ trễ (giá/tồn kho) | Gợi ý SP hết hàng | PHP luôn đọc lại Mongo và lọc `availableProductFilter` |
| Lộ PII sang AI service | Vi phạm bảo mật | Chỉ gửi `user_hash` và hồ sơ da đã rút gọn |

## 9. Thứ tự ưu tiên

1. **Bước 0 → 0.5**: sửa ngay lỗi 0 kết quả (giá trị lớn nhất, rủi ro thấp).
2. **Bước 4 (chỉ nhánh semantic + trending) → 5 → 6**: có Smart Fallback dùng ChromaDB sẵn có, chưa cần Neo4j.
3. **Bước 1 → 2 → 3 → bật nhánh graph ở Bước 4**: thêm Neo4j cho gợi ý thay thế có lý do.
4. **Bước 7 → 8**: cá nhân hóa, đo lường, chốt.

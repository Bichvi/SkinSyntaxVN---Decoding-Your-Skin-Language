# Smart Search + gợi ý (MongoDB · ChromaDB · Neo4j): tài liệu bàn giao

Nhánh `syna`, ngày 2026-10-21. **Phần lớn chưa commit.** Riêng commit `e42f4ac` đã chứa phần tìm kiếm theo từng từ, kèm theo `SanPhamController.php` của Bước 06/08 (xem §9).
Kế hoạch gốc: [`update_smart_search_neo4j.md`](update_smart_search_neo4j.md). Nhật ký từng bước: [`docs/plans/smart-search-handoff.md`](docs/plans/smart-search-handoff.md). Khảo sát: [`docs/plans/2026-10-21-neo4j-search-audit.md`](docs/plans/2026-10-21-neo4j-search-audit.md). Schema đồ thị: [`docs/neo4j/schema.md`](docs/neo4j/schema.md).

Tài liệu này không chứa bí mật. Biến môi trường chỉ ghi tên và giá trị mặc định.

---

## 1. Đã làm được gì

| Phần | Trạng thái |
|---|---|
| Tìm theo từng từ, không phân biệt dấu (`SanPham::paginate`) | Xong, đã commit trong `e42f4ac` |
| Neo4j (`skinsyntax-neo4j`) + schema + migrate | Xong |
| ETL Mongo → Neo4j (`graph_sync`) + CO_PURCHASED | Xong |
| Bộ phân tích ý định tất định (intent parser) | Xong |
| API `POST /api/search/recommend`, chế độ B (đồ thị + ngữ nghĩa + đang bán chạy) | Xong |
| PHP gọi API, có circuit breaker và dự phòng best seller | Xong |
| Giao diện Smart Fallback trên `r=tatca` | Xong |
| Cá nhân hóa theo `skin_profile`, beacon `search_events`, script đo chỉ số | Xong |
| Cộng điểm theo CTR (`SEARCH_CTR_BOOST_ENABLED`) | **Chưa làm** (tùy chọn) |
| Cross-encoder rerank | Có code, **tắt** (CPU vượt ngân sách 250ms) |

## 2. Khác kế hoạch ở đâu

- **Không có trường `thuong_hieu`, `loai_san_pham` hay `barcode` trong `san_pham`.** Tên hãng, danh mục, loại da và xuất xứ đều phải tra qua mã: `ma_thuong_hieu`, `ma_danh_muc`, `ma_loai_da`, `ma_xuat_xu`. Tìm hãng thì tra `thuong_hieu.ten_thuong_hieu` rồi lọc `ma_thuong_hieu $in`.
- Khớp không dấu dùng **regex lớp ký tự** (cách A), không thêm trường `search_text` (cách B chưa làm).
- Module Flask đang chạy là `chatbot_service.chatbot_flask:app`. API mới nằm ở **blueprint mới `search_service`**, không dùng `recommendation/` hay `rcm_flask`. Thư viện `recommendation_service.HybridSearch` (BM25 + Chroma RRF) được dùng lại.
- Chroma có **collection mới `catalog_products_v1`**, id = `ma_san_pham`, 2853 tài liệu, index lại từ Mongo. Collection cũ `products` của chatbot vẫn giữ nguyên.
- Neo4j chạy với `mem_limit 1g` (kế hoạch ghi 768m; khi nạp dữ liệu đã chạm trần 768m), heap 512m, pagecache 128m.
- Quan hệ `IN_CATEGORY` chỉ trỏ tới danh mục lá. Khi lọc theo danh mục cha phải đi qua `CHILD_OF*0..2`.
- Gunicorn của `skinsyntax-ai` chuyển từ 1 worker **sync** sang **gthread**, 8 luồng. Lý do: một request chat dài làm search xếp hàng 5-20s. Warm-up search chạy trong `post_fork`.
- Hợp đồng PHP ↔ Flask có thêm `intent.brand_names` và `intent.category_names`, cả hai tùy chọn.

## 3. Luồng xử lý

```
Trình duyệt ── GET index.php?r=tatca&q=... ──► PHP SanPhamController::tatca  (backend/public/index.php trên :8080)
   │                                              │ 1. SanPham::paginate (Mongo Atlas, theo token, không dấu)
   │                                              │ 2. Nếu q≠'' && page=1 && total<4 && SEARCH_FALLBACK_ENABLED:
   │                                              │      SearchRecommendClient ── POST /api/search/recommend ──► Flask skinsyntax-ai:5001
   │                                              │        (timeout 1.2s, X-Internal-Token, breaker Redis 3 lỗi→mở 60s)
   │                                              │                                   ├ intent_parser (từ điển Neo4j/Mongo + YAML)
   │                                              │                                   ├ semantic: BM25 + Chroma catalog_products_v1
   │                                              │                                   ├ graph (Neo4j): category_alt, same_brand, personalized
   │                                              │                                   ├ trending: hoa_don 14 ngày (Redis 10')
   │                                              │                                   └ ranker → sections + reason_code (cache Redis 300s)
   │                                              │ 3. SearchFallbackBuilder: đọc lại giá/ảnh/tồn kho từ Mongo theo id,
   │                                              │    bỏ SP ẩn/trùng; lỗi/timeout → section "Sản phẩm bán chạy" (degraded)
   │                                              ▼
   │◄──── HTML: lưới kết quả chính xác + partial search_fallback.php (banner, chips, section, CTA Syna)
   └── search-fallback.js ── sendBeacon POST r=search_event ──► SearchEventTracker → Mongo search_events (TTL 180 ngày)

Đồng bộ (thủ công/cron): Mongo (chỉ đọc) ── graph_sync ──► Neo4j (Product/Brand/Category/Ingredient/SkinType/Concern/Benefit/Origin,
                                                          SIMILAR_TO, CONFLICTS_WITH, CO_PURCHASED)
```

Khi một thành phần hỏng:
- Neo4j tắt: các nhánh đồ thị nằm trong `degraded_branches`, trang vẫn có section semantic và trending.
- Flask tắt: PHP hiện "Sản phẩm bán chạy". Sau 3 lỗi, breaker mở và PHP không gọi Flask trong 60s.
- Chroma lỗi: nhánh semantic bị đánh dấu degraded.

Trang không bao giờ trả 5xx vì gợi ý hỏng.

## 4. File chính

- PHP: `backend/app/models/SanPham.php` (tokenize, `findVisibleByIds`, `orderByIds`, `bestSellers`), `backend/app/controllers/SanPhamController.php` (`tatca`, `buildSearchFallback`, `searchSkinProfile`), `backend/app/services/{SearchRecommendClient,SearchFallbackBuilder,SearchReasonFormatter,SearchEventTracker,SearchSkinProfile}.php`, `backend/app/config/search_reasons.php`. Route `search_event` có trong cả `backend/public/index.php` (entry thật của :8080) và `frontend/public/index.php`.
- View: `frontend/views/tatca.php`, `frontend/views/partials/search_fallback.php`, `frontend/public/assets/{css/search-fallback.css,js/search-fallback.js}`.
- Flask: `ai-service-flask/search_service/*` (routes, service, branches, ranker, graph_repository, neo4j_client, catalog, dictionary, intent_parser, metrics, schemas, `contract/recommend_response.schema.json`, `scripts/reindex_chroma.py`), `chatbot_service/chatbot_flask.py` (đăng ký blueprint), `gunicorn_config.py`.
- Đồ thị: `ai-service-flask/graph_sync/*`, `ai-service-flask/data/graph/*.yaml`, `migrations/neo4j/001_constraints.cypher`.
- Script: `ai-service-flask/scripts/{search_metrics,setup_search_events}.py`.
- Hạ tầng: `docker-compose.yml` (service `neo4j`, biến môi trường cho ai-service và php-backend), `.env.example`, `.gitignore`.

## 5. Biến môi trường và cờ bật/tắt

| Biến | Nơi đọc | Mặc định | Ý nghĩa |
|---|---|---|---|
| `SEARCH_INTERNAL_TOKEN` | PHP + Flask | rỗng | Token dùng chung cho header `X-Internal-Token`. Nếu rỗng, Flask chỉ nhận request từ IP loopback/private. **Cần đặt** (xem §8) |
| `SEARCH_FALLBACK_ENABLED` | PHP | 1 | Đặt 0 để tắt hẳn gợi ý (trang trở về hộp rỗng cũ) |
| `SEARCH_RECOMMEND_ENDPOINT` | PHP | compose: `http://ai-service:5001/api/search/recommend` | Không đặt trong `.env`, nếu không `.env` (127.0.0.1) sẽ ghi đè |
| `SEARCH_RECOMMEND_TIMEOUT_MS` / `_CONNECT_TIMEOUT_MS` | PHP | 1200 / 300 | |
| `SEARCH_BREAKER_REDIS_URL` | PHP | compose: `redis://redis:6379/0` | Key `search:recommend:breaker:v1` |
| `SEARCH_USER_HASH_SALT` | PHP | rỗng | Nếu rỗng, `user_hash` = null trong request và `search_events` |
| `SEARCH_EVENTS_ENABLED` | PHP | 1 | Đặt 0 để tắt beacon (route trả 204, không ghi) |
| `SEARCH_GRAPH_ENABLED` | Flask | code: false, compose: **true** | false = chế độ A (chỉ semantic + trending) |
| `SEARCH_SEMANTIC_ENABLED` | Flask | true | |
| `SEARCH_RERANK_ENABLED` | Flask | false | Cross-encoder (tốn CPU) |
| `SEARCH_CO_PURCHASE_ENABLED` | Flask | false | Cộng điểm "thường mua cùng" theo `cart_ids` |
| `SEARCH_TIMEOUT_MS` | Flask | 800 | Ngân sách cho mỗi nhánh |
| `SEARCH_CACHE_TTL`, `SEARCH_TRENDING_CACHE_TTL`, `SEARCH_CATALOG_TTL`, `SEARCH_DICT_TTL` | Flask | 300, 600, 300, 600 | |
| `SEARCH_TRENDING_DAYS`, `SEARCH_LIMIT_PER_SECTION`, `SEARCH_FUZZY_CUTOFF`, `SEARCH_CO_PURCHASE_TIMEOUT_MS` | Flask | 14, 8, 88, 250 | |
| `SEARCH_CHROMA_COLLECTION` | Flask | `catalog_products_v1` | |
| `NEO4J_URI`, `NEO4J_USER`, `NEO4J_PASSWORD` | Flask, graph_sync, compose | `bolt://neo4j:7687`, `neo4j`, (bắt buộc trong `.env`) | |
| `GUNICORN_WORKER_CLASS`, `GUNICORN_THREADS`, `GUNICORN_WORKERS` | gunicorn | gthread, 8, `.env` hiện là 1 | |

## 6. Lệnh vận hành

Trên Git Bash, đặt `MSYS_NO_PATHCONV=1` trước `docker exec -w /app`.

```bash
docker compose up -d neo4j                               # khởi động Neo4j (healthy sau ~1 phút)
docker compose build ai-service && docker compose up -d --no-deps ai-service   # khi đổi requirements
# Schema (từ host, cần NEO4J_PASSWORD trong môi trường; idempotent)
cd ai-service-flask && NEO4J_URI=bolt://localhost:7687 ../.venv/Scripts/python.exe -m graph_sync.migrate
# Đồng bộ (trong container; exit 0 ok, 1 lỗi, 2 sai tham số, 3 đang có lock Redis graph_sync:lock)
docker exec -w /app skinsyntax-ai python -m graph_sync --full            # ~36s, đặt active=false cho SP đã mất
docker exec -w /app skinsyntax-ai python -m graph_sync --since 2026-10-20T00:00:00Z
docker exec -w /app skinsyntax-ai python -m graph_sync --full --dry-run  # ~11-13s, không ghi
docker exec -w /app skinsyntax-ai python -m graph_sync --similarity-only
docker exec -w /app skinsyntax-ai python -m graph_sync --derive co_purchase --days 90 [--dry-run]
# Chroma: index lại catalog khi sản phẩm đổi nhiều
docker exec skinsyntax-ai python -m search_service.scripts.reindex_chroma
# Mongo search_events (chỉ chạy 1 lần, idempotent)
docker exec -w /app skinsyntax-ai python scripts/setup_search_events.py
# Chỉ số (host .venv, đọc MONGO_URI từ env/.env, không in ra)
.venv/Scripts/python.exe ai-service-flask/scripts/search_metrics.py --days 7 [--out report/search-metrics]
# Sức khỏe
curl -s localhost:5001/api/search/health      # {"chroma":"ok","neo4j":"ok","redis":"ok"}
```

**Lịch đồng bộ khuyến nghị:**
- `--since <lần chạy trước>` mỗi giờ.
- `--full` mỗi đêm, vì `--since` không phát hiện sản phẩm bị xóa cứng.
- `--derive co_purchase --days 90` mỗi tuần.
- `reindex_chroma` sau khi nhập hàng loạt sản phẩm.
- Lock Redis đã chặn hai lần chạy chồng nhau.

## 7. Tắt từng tính năng

| Muốn tắt | Cách |
|---|---|
| Toàn bộ gợi ý trên trang tìm kiếm | `SEARCH_FALLBACK_ENABLED=0` rồi recreate `php-backend` |
| Chỉ nhánh đồ thị (về chế độ A) | `SEARCH_GRAPH_ENABLED=false` rồi recreate `ai-service`. Cũng có thể `docker stop skinsyntax-neo4j`: hệ thống tự hạ cấp, breaker probe 10s |
| Beacon đo lường | `SEARCH_EVENTS_ENABLED=0` |
| Thường mua cùng | `SEARCH_CO_PURCHASE_ENABLED=false` (đang là mặc định) |
| Gunicorn về cách cũ | `GUNICORN_WORKER_CLASS=sync` (không khuyến nghị, chat sẽ chặn search) |
| Hoàn tác tìm kiếm theo từng từ | revert phần `SanPham.php` trong `e42f4ac` |

## 8. Việc người dùng cần làm

1. **Đặt `SEARCH_INTERNAL_TOKEN`** trong `.env`, một chuỗi ngẫu nhiên dài (ví dụ `openssl rand -hex 32`). Sau đó chạy `docker compose up -d --no-deps ai-service php-backend`. Cả hai service đều đọc `.env` qua `env_file`. Hiện token rỗng ở cả hai container, nên Flask đang dựa vào việc chỉ cho phép IP private.
2. **Đặt `SEARCH_USER_HASH_SALT`** nếu muốn đo theo người dùng (hiện `user_hash` = null). Không có giá trị mặc định. Không đổi salt sau khi đã dùng, vì hash cũ sẽ không còn khớp.
3. `NEO4J_PASSWORD` đã có trong `.env` (32 hex). Không commit `.env`.

## 9. Chỉ số đo được (2026-10-21, Bước 09)

- API, 100 request (50 truy vấn × 2, tuần tự, đã xóa cache kết quả trước):
  - Lượt 1 (miss): p50 32ms, p95 102ms.
  - Lượt 2 (cache ấm): p50 4,6ms, p95 19ms.
  - Tổng: p95 61ms, max 123ms, 0 lượt degraded.
  - Lần đo ngay sau khi restart `skinsyntax-ai`: miss p95 327ms, max 728ms.
  - Mục tiêu p95 < 800ms: **đạt**.
- Trang `r=tatca` (Playwright, median của 5 lần, TTFB/load):
  - "torriden sữa rửa mặt" (có gọi Flask): 540/1133ms.
  - "xyzabc": 435/1103ms.
  - "Torriden" (30 kết quả, không gọi Flask): 429/569ms.
  - Flask tắt: lần 1 TTFB 1,3s, có thêm ~0,3s chờ DNS. Từ lần 4 trở đi breaker đã mở: 0,28-0,56s.
- `graph_sync --full --dry-run`: 11,0s (extract 2,9, transform 0,8, similarity 7,3), wall 13s.
- RAM Neo4j: 843MiB/1GiB (82%), `oom_kill 0`.
- Test: pytest 207 passed + 8 integration passed. PHP: `SanPhamSearchTokenTest` 13, `SearchFallbackTest` 47, `SearchEventTest` 52.

## 10. Rủi ro còn lại

- **Commit `e42f4ac` không tự đứng được.** `SanPhamController.php` trong đó `require_once` các service Search* chưa được track. Checkout riêng commit này sẽ lỗi fatal khi `total<4`. Cần commit tiếp các file service (xem đề xuất commit trong báo cáo Bước 09).
- **Thread-safety của chatbot dưới gthread.** `chatbot_flask.py` có bản riêng của `get_llms`, `get_vectorstore` và `get_hybrid_pipeline`. Đây là các biến global khởi tạo lười, không có lock, và **không** được `post_fork` làm ấm (post_fork chỉ làm ấm các bản trong `llm_pool` và `retrieval`). Khi các request chat đầu tiên chạy song song:
  - `get_llms` gán `_llms = []` trước rồi mới append, nên luồng thứ hai có thể nhận danh sách rỗng trong 300s và trả câu trả lời dự phòng.
  - Chroma và pipeline có thể bị khởi tạo hai lần (tốn tài nguyên, nhưng vô hại).
  - Sửa đề xuất (chưa làm): thêm lock và gán `_llms` một lần sau khi build xong, hoặc làm ấm cả các hàm này trong `post_fork`.
  - `LLM_COOLDOWNS` và `_WEB_CACHE` chỉ thao tác dict đơn lẻ, an toàn nhờ GIL.
- **`skinsyntax-livekit-agent` restart liên tục** vì không resolve được DNS `voice-service:5002`, và gọi chat RAG vào ai-service (timeout 10s). Lỗi này ngoài phạm vi. gthread giúp search không bị chặn, nhưng agent vẫn tốn tài nguyên.
- Neo4j ở mức 82% trần 1g. Khi dữ liệu hoặc truy vấn tăng thì phải nâng `mem_limit`.
- Dữ liệu đơn hàng rất thưa, chỉ 2 cặp CO_PURCHASED. Benefit/concern 100% là suy luận. 52 sản phẩm không có thành phần.
- Log Flask `search_recommend` ghi `q` khi độ dài ≤ 50 ký tự. Đây là truy vấn người dùng, không phải PII định danh, nhưng cần lưu ý nếu đẩy log ra ngoài.
- Lúc Neo4j vừa khởi động, 1-2 request đầu có thể timeout nhánh đồ thị.
- `docker logs skinsyntax-ai` có ký tự `\x00`. Cần đọc qua `tr -d '\000'`.
- Chạy song song c=4: server mất 300-500ms/req (tranh CPU khi embed). Nếu cần: cache embedding truy vấn hoặc `GUNICORN_WORKERS=2`.

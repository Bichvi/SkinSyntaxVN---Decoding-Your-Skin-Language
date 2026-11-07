# BƯỚC 05 — API GỢI Ý LAI `POST /api/search/recommend` (Flask)

## Vai trò
Kỹ sư backend Python/AI. Xây endpoint nhận truy vấn tìm kiếm, trả về các nhóm sản phẩm gợi ý (chỉ `product_id` + mã lý do), kết hợp Neo4j + ChromaDB/BM25 + độ phổ biến + cá nhân hóa, **không bao giờ fail vì một nguồn dữ liệu chết**.

## Chế độ thực hiện (đọc handoff để biết đang ở chế độ nào)
- **Chế độ A — chưa có Neo4j** (Bước 02-04 chưa xong): chỉ làm nhánh `semantic` + `trending`; nhánh graph để sẵn interface, trả rỗng khi `SEARCH_GRAPH_ENABLED=false`. `intent` trả `null`.
- **Chế độ B — đã có Neo4j + parser**: bật đủ 5 nhánh, dùng `search_service.intent_parser`.
Ghi rõ chế độ đã làm vào handoff.

## Đọc trước
1. `AGENTS.md`
2. `docs/plans/smart-search-handoff.md` (module Flask đang chạy thật, endpoint, header auth nội bộ, lệnh pytest, kết quả các bước trước)
3. `ai-service-flask/recommendation_service/`: `hybrid_search.py`, `retrieval.py`, `reranker.py`, `conflict_checker.py`, `safety_filter.py`, `response_cache.py`, `config.py`, `product_repository.py` — **tái sử dụng, không viết lại**. Đọc kỹ chữ ký hàm public.
4. File Flask app đang chạy thật (theo handoff) — nơi đăng ký blueprint.
5. Chế độ B: `docs/neo4j/schema.md`, `search_service/intent_parser.py`, `search_service/dictionary.py`.

## Quy trình bắt buộc
- `git status`.
- GitNexus impact upstream cho mọi hàm có sẵn mà bạn **sửa** (không cần cho hàm chỉ gọi): `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`. HIGH/CRITICAL → dừng, báo người dùng.
- TDD cho ranker/dedupe/schema.

## Cấu trúc code
```
ai-service-flask/search_service/
  config.py            # thêm: SEARCH_TIMEOUT_MS=800, LIMIT_PER_SECTION=8, CACHE_TTL=300,
                       #       SEARCH_GRAPH_ENABLED, SEARCH_SEMANTIC_ENABLED, WEIGHTS={graph:.35, semantic:.30, popularity:.20, personal:.15}
  schemas.py           # RecommendRequest, RecommendItem, Section, RecommendResponse (+ validate)
  graph_repository.py  # MỌI Cypher nằm ở đây; nhận driver qua constructor
  branches.py          # same_brand(), category_alt(), semantic(), personalized(), trending()  → list[Candidate]
  ranker.py            # normalize, combine, cross_encoder_rerank, dedupe, cut  (thuần)
  service.py           # SearchRecommendService.recommend(req) → RecommendResponse (điều phối, cache, timeout)
  routes.py            # search_bp: POST /api/search/recommend, GET /api/search/health
  neo4j_client.py      # get_driver(): singleton, lazy, None nếu thiếu env hoặc lỗi kết nối
ai-service-flask/tests/search_service/
  test_ranker.py  test_schemas.py  test_service.py  test_routes.py  test_contract.py
```

## Hợp đồng API (đây là contract — PHP Bước 06 dựa vào, không đổi tùy tiện)
Request:
```json
{ "q": "torriden sữa rửa mặt",
  "user_hash": "sha256-hex | null",
  "skin_profile": {"skin_type": "da_kho", "concerns": ["mun"]} ,
  "cart_ids": ["123"], "exclude_ids": ["45"],
  "limit_per_section": 8 }
```
Validate: `q` 1..200 ký tự (sau trim); mảng id ≤ 50 phần tử, mỗi id ≤ 32 ký tự `[A-Za-z0-9_-]`; `limit_per_section` 1..20; sai → 400 `{error:"invalid_request", field}`.

Response 200:
```json
{ "mode": "fallback",
  "degraded": false,
  "degraded_branches": [],
  "took_ms": 214,
  "intent": {"brands":["torriden"],"categories":["sua_rua_mat"],"skin_types":[],"concerns":[],"confidence":0.95} ,
  "sections": [
    {"key":"category_alt","items":[{"product_id":"102","score":0.83,"reason_code":"shared_ingredients","reason_params":["Hyaluronic Acid","Panthenol"]}]},
    {"key":"same_brand","items":[...]},
    {"key":"personalized","items":[...]},
    {"key":"semantic","items":[...]},
    {"key":"trending","items":[...]}
  ] }
```
- `section.key` ∈ `same_brand | category_alt | personalized | semantic | trending`. Section rỗng **không** trả về.
- `reason_code` ∈ `same_brand | same_category | shared_ingredients | fits_skin_type | targets_concern | semantic_match | trending | bought_together`. `reason_params` là list chuỗi hiển thị (≤ 3), không chứa câu tiếng Việt hoàn chỉnh.
- Chỉ trả `product_id` — PHP tự lấy giá/ảnh/tồn kho từ Mongo.
- Auth: dùng **đúng cơ chế auth nội bộ** các endpoint PHP đang gọi (theo handoff); thiếu/sai → 401.

## Logic các nhánh
Chạy song song: `ThreadPoolExecutor(max_workers=5)`; `concurrent.futures.wait(futures, timeout=SEARCH_TIMEOUT_MS/1000)`; nhánh chưa xong/exception → bỏ, thêm vào `degraded_branches`, `degraded=true`. Mỗi nhánh trả tối đa 30 `Candidate(product_id, raw_score, reason_code, reason_params, branch)`.

| Nhánh | Điều kiện chạy | Cách làm |
|---|---|---|
| `same_brand` | B, có brand | Cypher dưới (1) |
| `category_alt` | B, có category | Cypher dưới (2): SP cùng category, **khác** brand trong intent, xếp theo số hoạt chất chung với "hồ sơ hoạt chất" của brand đó (nếu có brand) hoặc theo `sold` |
| `personalized` | B, có `skin_profile` | Cypher (3) + loại SP xung đột với `cart_ids` qua `CONFLICTS_WITH` (hoặc `conflict_checker` có sẵn) |
| `semantic` | luôn (nếu bật) | Gọi `hybrid_search` có sẵn (BM25 + Chroma, RRF) với `q`, top 30; nếu có category trong intent, lọc/ưu tiên theo category |
| `trending` | luôn | Mongo (chỉ đọc): tổng số lượng bán 14 ngày từ đơn hàng; nếu không có dữ liệu đơn → sort theo trường lượt bán; cache Redis 10 phút riêng |

Cypher (tham số hóa — **cấm** f-string/nối chuỗi):
```cypher
// (1) same_brand — ưu tiên SP cùng danh mục cha với category tìm
MATCH (b:Brand {key:$brand})<-[:OF_BRAND]-(p:Product {active:true})
OPTIONAL MATCH (p)-[:IN_CATEGORY]->(c:Category)
WITH p, CASE WHEN c.key IN $categories THEN 2
             WHEN exists { (c)-[:CHILD_OF]->(:Category)<-[:CHILD_OF]-(:Category {key:$category}) } THEN 1
             ELSE 0 END AS near
RETURN p.id AS id, near, p.sold AS sold, p.in_stock AS in_stock
ORDER BY near DESC, sold DESC LIMIT 30

// (2) category_alt — hồ sơ hoạt chất của brand
MATCH (:Brand {key:$brand})<-[:OF_BRAND]-(:Product {active:true})-[r:CONTAINS]->(i:Ingredient)
WHERE r.rank <= 10
WITH i, count(*) AS freq ORDER BY freq DESC LIMIT 8
WITH collect(i) AS profile
MATCH (p:Product {active:true})-[:IN_CATEGORY]->(:Category {key:$category})
WHERE NOT (p)-[:OF_BRAND]->(:Brand {key:$brand})
OPTIONAL MATCH (p)-[:CONTAINS]->(shared:Ingredient) WHERE shared IN profile
RETURN p.id AS id, count(shared) AS shared_count, collect(shared.name)[..3] AS shared_names, p.sold AS sold
ORDER BY shared_count DESC, sold DESC LIMIT 30

// (3) personalized
MATCH (p:Product {active:true})
WHERE ($skin_type IS NULL OR (p)-[:SUITABLE_FOR]->(:SkinType {key:$skin_type}))
  AND (size($categories)=0 OR (p)-[:IN_CATEGORY]->(:Category) WHERE ... )   // viết lại hợp lệ
OPTIONAL MATCH (p)-[:TARGETS]->(cn:Concern) WHERE cn.key IN $concerns
WITH p, count(cn) AS concern_hits
WHERE NOT exists {
  MATCH (p)-[:CONTAINS]->(:Ingredient)-[:CONFLICTS_WITH]-(:Ingredient)<-[:CONTAINS]-(c:Product) WHERE c.id IN $cart_ids }
RETURN p.id AS id, concern_hits, p.sold AS sold ORDER BY concern_hits DESC, sold DESC LIMIT 30
```
(Câu (3) là phác thảo — viết lại cho cú pháp Neo4j 5 hợp lệ và test bằng `EXPLAIN`.)

Khi không brand: bỏ (1); (2) dùng category + sort theo `sold`. Khi không category: (2) dùng `SIMILAR_TO` của top-3 kết quả semantic.

## Xếp hạng (ranker.py — hàm thuần)
1. Chuẩn hóa `raw_score` min-max **trong từng nhánh** về [0,1].
2. Gộp theo `product_id`: `graph` = max điểm từ (1)(2), `semantic`, `personal`, `popularity` = log1p(sold) chuẩn hóa.
3. `score = Σ w·component − 0.5·(in_stock == false)`. Trọng số từ config.
4. Cross-encoder có sẵn (`reranker.py`, model `cross-encoder/mmarco-mMiniLMv2-L12-H384-v1`) chấm lại **top 40** theo cặp (q, tên + danh mục); `final = 0.7·score + 0.3·ce`. Nếu reranker chưa nạp hoặc > 250ms → bỏ qua, không lỗi.
5. **Dedupe**: mỗi `product_id` chỉ giữ ở section mà nó có điểm cao nhất; loại `exclude_ids`.
6. Cắt `limit_per_section`; thứ tự section: `category_alt, same_brand, personalized, semantic, trending` (bỏ section rỗng).
7. `mode`: `fallback` khi gọi từ PHP với kết quả ít (luôn `fallback` ở MVP).

## Cache & quan sát
- Redis key `search:rec:v1:{sha1(normalized_q|skin_hash|sorted(cart_ids)|limit)}` TTL 300s; **không cache khi `degraded`**. Redis lỗi → bỏ qua cache.
- Mỗi request có `correlation_id` (lấy header `X-Request-Id` nếu có, không thì sinh uuid4), log JSON: `correlation_id, took_ms, degraded_branches, counts per section, cache_hit`. **Không log** `user_hash`, `skin_profile`, `q` dài hơn 50 ký tự.
- `GET /api/search/health` → `{neo4j: ok|down|disabled, chroma: ok|down, redis: ok|down}`.

## Kiểm thử
- `test_ranker.py`: chuẩn hóa, gộp, phạt hết hàng, dedupe, cắt, thứ tự section.
- `test_schemas.py`: validate input sai (q rỗng, q 201 ký tự, id có ký tự lạ, limit 0/21).
- `test_service.py` (mock nhánh): một nhánh raise → `degraded=true`, các nhánh khác vẫn trả; nhánh ngủ quá timeout → bị bỏ; cache hit; Redis lỗi.
- `test_routes.py`: 401 khi thiếu auth, 400 khi sai input, 200 đúng shape.
- `test_contract.py`: JSON schema của response (lưu tại `ai-service-flask/search_service/contract/recommend_response.schema.json`) — Bước 06 dùng lại file này.
- Chế độ B: integration với Neo4j thật (local docker) đánh dấu `@pytest.mark.integration`, bỏ qua nếu không có env.

## Kiểm chứng (chạy thật, dán output)
1. pytest toàn bộ `tests/search_service`.
2. Gọi thật bằng `curl` tới Flask local với 3 truy vấn: `torriden sữa rửa mặt`, `xyzabc`, `srm cho da dầu` → dán JSON rút gọn.
3. Chế độ B: `docker stop skinsyntax-neo4j` → gọi lại → 200, `degraded=true`, vẫn có `semantic`/`trending`; `docker start skinsyntax-neo4j`.
4. Đo latency: script gửi 100 request (50 truy vấn khác nhau × 2) → in p50/p95; mục tiêu p95 < 800ms khi cache ấm.

## Đầu ra
Code, test, file JSON schema contract. Mục "Bước 05" trong handoff: chế độ A/B, URL + header auth, ví dụ request/response, đường dẫn file contract, p95 đo được, env/flag mới. Không commit nếu chưa được yêu cầu.

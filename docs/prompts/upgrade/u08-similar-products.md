# U08 — KHỐI "SẢN PHẨM TƯƠNG TỰ" Ở TRANG CHI TIẾT SẢN PHẨM

## Vai trò
Kỹ sư fullstack. Tận dụng quan hệ `SIMILAR_TO` (28.525 cạnh, có `reason`, `shared`) để hiện gợi ý ở trang chi tiết.

## Đọc trước
`u00-common-rules.md` → handoff → `docs/neo4j/schema.md` → controller/view trang chi tiết sản phẩm (rg route chi tiết, vd `r=chitiet`) — xem đã có khối gợi ý nào (Simple/Hybrid recommender, "sản phẩm liên quan") → **không xóa khối cũ**; quyết định đặt khối mới cạnh hoặc thay vị trí hiển thị bằng flag. `search_service/{graph_repository,routes,service}.py`, `SearchRecommendClient.php`, `search_fallback.php` (tái dùng card + badge).

## API Flask mới
`GET /api/products/<id>/similar?limit=8&exclude=<csv>&skin_type=<key>` (auth nội bộ + rate limit U03):
```
Response: {product_id, items:[{product_id, score, reason_code, reason_params}], degraded, took_ms}
```
- Nguồn chính Cypher (tham số hóa):
  ```cypher
  MATCH (p:Product {id:$id})-[s:SIMILAR_TO]->(q:Product {active:true})
  OPTIONAL MATCH (p)-[:IN_CATEGORY]->(c)<-[:IN_CATEGORY]-(q)
  RETURN q.id AS id, s.score AS score, s.reason AS reason, s.shared AS shared,
         c IS NOT NULL AS same_leaf, q.in_stock AS in_stock, q.sold AS sold
  ORDER BY same_leaf DESC, s.score DESC LIMIT 40
  ```
- Xếp lại: `final = score + 0.15*same_leaf + 0.05*log1p(sold)_norm − 0.5*(!in_stock)`; đa dạng hóa: tối đa 3 SP cùng hãng; nếu có `skin_type` cộng 0.1 cho SP `SUITABLE_FOR` loại da đó.
- `reason_code`: `shared_ingredients` (params = `shared` tối đa 3), `same_category`, `same_brand`.
- Neo4j lỗi/chưa có cạnh → fallback semantic: dùng embedding của SP (Chroma `catalog_products_v1` get theo id → query láng giềng, lọc cùng danh mục lá), `degraded:true`.
- Cache Redis `product:similar:v1:<id>:<skin>` 1 giờ + generation invalidate (U03). Cập nhật contract JSON schema mới `contract/similar_response.schema.json`.

## PHP + View
- `SearchRecommendClient` thêm `similar(int $id, ?string $skinType, array $exclude)` (timeout 800ms, chung breaker).
- Controller chi tiết: gọi sau khi đã có dữ liệu SP chính, `exclude` = SP hiện tại + SP trong giỏ (tùy chọn); lấy card qua `findVisibleByIds`. Lỗi → không hiện khối (hoặc dùng khối cũ).
- Lazy: render khối bằng **fetch phía client** sau khi trang tải (endpoint PHP `r=similar_products&id=` trả HTML partial) để không làm chậm TTFB trang chi tiết; có skeleton; SEO không cần khối này. Route thêm vào cả 2 `index.php`.
- Partial `frontend/views/partials/similar_products.php`: tiêu đề "Sản phẩm tương tự", carousel ngang (scroll-snap) 4 card desktop / 2 mobile, badge lý do qua `SearchReasonFormatter`, data-attribute tracking (`data-ssf-section="similar"`) → beacon U03.
- Khách đăng nhập có hồ sơ da (U08 dùng `SearchSkinProfile`) → truyền `skin_type`.

## Kiểm thử
- Python unit: rerank/đa dạng hóa, fallback khi Neo4j lỗi, validate id.
- Integration Neo4j: SP 1496 → có sữa rửa mặt khác hãng trong top 8 (nhờ `same_leaf`).
- PHP unit: client parse, partial escape.
- E2E Playwright MCP: trang chi tiết 1496, 1 serum, 1 kem chống nắng → khối hiện, badge đúng, click card → sang SP đó, thêm giỏ từ card; tắt Neo4j → khối vẫn hiện (semantic) hoặc ẩn gọn; 375px không tràn; TTFB trang chi tiết không tăng (đo trước/sau). Ảnh `report/upgrade-e2e/u08-*.png`.

## Đầu ra
Mục "U08" trong handoff: endpoint, contract, partial, số liệu TTFB/latency, quyết định với khối gợi ý cũ. Không commit.

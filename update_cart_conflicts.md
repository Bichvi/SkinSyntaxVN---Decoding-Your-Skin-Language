# Giỏ hàng: cảnh báo xung đột hoạt chất + gợi ý hoàn thiện routine (U09)

## Làm gì
- Giỏ hàng (`?r=giohang`) có thêm khối tải bằng fetch (lỗi hoặc rỗng thì ẩn, không chặn thanh toán):
  - **Lưu ý khi dùng chung hoạt chất**: cặp sản phẩm trong giỏ có hoạt chất kỵ nhau, mức `high` (màu cảnh báo, biểu tượng), `medium`/`low` (thông tin), câu khuyên tiếng Việt từ file luật.
  - **Hoàn thiện routine của bạn**: chip các bước (sữa rửa mặt, toner, serum, kem dưỡng, kem chống nắng) đã có/còn thiếu và tối đa 3 bước thiếu x 4 sản phẩm không xung đột với giỏ; nút "Thêm vào giỏ" bằng AJAX, sau đó bảng giỏ và khối tự cập nhật.
- Khối cũ trong chatbot (`HomeController::detectCartIngredientConflicts`) giữ nguyên; trang giỏ trước đây chưa có cảnh báo nào nên không trùng UI.

## Nguồn tri thức (có review, có nguồn)
- `ai-service-flask/data/graph/conflict_rules.yaml`: 11 luật -> 55 cặp INCI (trước đó 12), có `severity`, `timing`, `evidence`, `advice_vi`, `source`. Gồm toàn bộ luật cũ trong `recommendation_service/knowledge/ingredient_rules.py` (không sửa/xóa). Có `rank_limits` (hoạt chất chỉ tính khi nằm trong N thành phần đầu), `ignore_categories` (tóc, trang điểm, son dưỡng) và `rinse_off_categories` (sản phẩm rửa trôi: hạ một mức, mức thấp thì bỏ). Các quy tắc heuristic này là phán đoán, cần chuyên gia duyệt.
- `ai-service-flask/data/graph/routine_steps.yaml`: thứ tự bước sáng/tối, bước cốt lõi, danh mục tương ứng.
- Nạp Neo4j: `docker exec -w /app skinsyntax-ai python -m graph_sync --conflicts-only [--dry-run]` (không đọc Mongo, ghi ~55 cạnh, idempotent).

## API
`POST /api/cart/analyze` (Flask, token nội bộ, rate limit `SEARCH_RATE_LIMIT_RPS`): xem `search_service/contract/cart_analyze_response.schema.json`. Neo4j lỗi -> dùng luật Python (cùng YAML, đọc thành phần từ Mongo), `degraded:true`, không có gợi ý. Cache Redis `cart:analyze:v1:g<gen>:<sha1>` 10 phút.
PHP: route `cart_analyze` (cả hai `index.php`), `SearchRecommendClient::cartAnalyze`, `CartInsights`, `partials/cart_insights.php`, `cart-insights.js/css`. Công tắc: `CART_INSIGHTS_ENABLED`, `SEARCH_CART_ENABLED`.

## Breaker
Mỗi endpoint Flask một breaker PHP riêng (`search:recommend|similar|intent|cart:breaker:v1`). Flask: `neo4j_client.run_with_deadline` (mặc định 0.6s, `NEO4J_CALL_DEADLINE_S`) mở breaker Neo4j ngay khi một lệnh gọi treo quá hạn.

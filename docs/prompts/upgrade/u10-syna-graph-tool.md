# U10 — SYNA TRUY VẤN NEO4J (GRAPH TOOL CHO CHATBOT)

## Vai trò
Kỹ sư AI/LLM. Cho trợ lý Syna trả lời câu hỏi có điều kiện quan hệ (loại da, hoạt chất, xung đột, giá, hãng) bằng dữ liệu đồ thị **đúng và kiểm chứng được**, thay vì chỉ dựa vào RAG văn bản.

## Đọc trước
`u00-common-rules.md` → handoff → `ai-service-flask/chatbot_service/` (luồng xử lý câu hỏi: `chatbot_flask.py`, `retrieval.py`, phần "catalog attribute constraints" commit 5994967 — Syna đã có lọc theo loại da/hãng/xuất xứ/thành phần/giá: **tìm và tái dùng**), `search_service/{intent_parser,graph_repository}.py`, `docs/neo4j/schema.md`.

## Nguyên tắc an toàn
- **LLM KHÔNG viết Cypher tự do.** Chỉ dùng **tool có tham số** với Cypher cố định, tham số được validate (key thuộc từ điển, số trong khoảng). Không bao giờ nối chuỗi người dùng vào Cypher.
- Trả lời về xung đột/da liễu phải kèm khuyến nghị tham khảo chuyên gia khi severity high; không chẩn đoán bệnh.
- Sản phẩm trả lời phải lấy từ kết quả tool (id thật), giá/tồn kho lấy lại từ Mongo/catalog snapshot.

## Thiết kế
1. `ai-service-flask/chatbot_service/graph_tools.py` — các hàm tool (thuần, nhận driver qua DI):
   - `find_products(category?, skin_type?, concerns[], benefits[], include_ingredients[], exclude_ingredients[], avoid_conflict_with[], brand?, price_max?, limit=6)`
   - `check_conflicts(product_ids[] | ingredient_keys[])`
   - `explain_product(product_id)` → hoạt chất chính (role active), benefit/concern (kèm `source`), loại da phù hợp.
   - `similar_products(product_id, limit=5)` (dùng lại logic U08).
   Mỗi tool trả JSON gọn (≤ 2KB), có `evidence` (hoạt chất chung, quan hệ dùng).
2. Định tuyến (router): dùng `intent_parser` + từ khóa nhận diện câu hỏi điều kiện ("hợp da…", "không kỵ…", "không chứa…", "dưới …k", "có … không", "dùng chung … được không"):
   - Có đủ entity → gọi tool trực tiếp (tất định), đưa kết quả vào context LLM để diễn đạt.
   - Câu tự do phức tạp → cho LLM chọn tool qua function-calling của provider đang dùng (nếu pool LLM hỗ trợ tool calling; nếu không, dùng router tất định + prompt template). Ghi rõ cách đã chọn.
   - Không khớp → luồng RAG cũ (không đổi hành vi).
3. Phủ định: "không kỵ retinol" → `avoid_conflict_with=[retinol]`; "không chứa cồn" → `exclude_ingredients=[alcohol_denat, …]` (mở rộng `Intent` với `exclude_ingredients`, giữ tương thích).
4. Feature flag `SYNA_GRAPH_TOOLS_ENABLED` (mặc định true trong compose, false trong code). Timeout tool 600ms; Neo4j lỗi → rơi về RAG cũ, không lỗi chat.
5. Log: tên tool, tham số đã chuẩn hóa (không log câu hỏi đầy đủ), số kết quả, thời gian.

## Bộ đánh giá (bắt buộc)
- `ai-service-flask/tests/chatbot_graph/eval_cases.yaml` ≥ 25 câu hỏi tiếng Việt có kỳ vọng kiểm được bằng máy, ví dụ:
  - "serum nào hợp da dầu, không kỵ retinol" → mọi SP trả về là serum, `SUITABLE_FOR da_dau`, không có `CONFLICTS_WITH` với retinol.
  - "sữa rửa mặt không chứa cồn dưới 300k" → đúng danh mục, không chứa alcohol_denat, giá ≤ 300000.
  - "retinol dùng chung với BHA được không" → check_conflicts có kết quả, câu trả lời chứa khuyên dùng xen kẽ.
  - "Torriden có kem chống nắng không" → brand torriden ∧ kem_chong_nang (có/không đúng thực tế).
- Script `eval_graph_tools.py`: chạy tầng tool (không cần LLM) → % đạt ràng buộc; tùy chọn `--with-llm` chạy 5 câu end-to-end (ghi chi phí).
- Mục tiêu: tầng tool ≥ 95% đạt ràng buộc.

## Kiểm thử
- Unit: validate tham số, phủ định, router chọn tool, fallback RAG khi lỗi.
- Integration Neo4j: chạy eval tầng tool.
- Chạy thật 5 câu qua `/api/chat` (hoặc endpoint chat đang dùng) → dán câu trả lời rút gọn; câu hỏi thường (không điều kiện) vẫn trả lời như cũ.
- E2E (nếu trình duyệt khả dụng): mở Syna trên :8080, hỏi 2 câu, chụp ảnh `report/upgrade-e2e/u10-*.png`.

## Đầu ra
Mục "U10" trong handoff: danh sách tool + tham số, cách định tuyến, kết quả eval. Không commit.

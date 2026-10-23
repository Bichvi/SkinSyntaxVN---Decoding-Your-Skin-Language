# Syna truy vấn Neo4j bằng tool có tham số (U10)

Trợ lý Syna (chatbot Flask `chatbot_service.chatbot_flask:app`) trả lời câu hỏi có điều kiện quan hệ (loại da, hoạt chất, xung đột, giá, hãng) bằng dữ liệu đồ thị Neo4j đã kiểm chứng. Câu hỏi thường vẫn đi luồng RAG cũ.

## Nguyên tắc an toàn
- LLM **không viết Cypher**. Mỗi tool có Cypher cố định, tham số hóa (`graph_repository.FIND_PRODUCTS`, `EXPLAIN_PRODUCT`, `SIMILAR_PRODUCTS`, `CART_CONFLICTS`); mọi tham số được kiểm tra theo từ điển (key danh mục/loại da/hoạt chất/hãng, khoảng giá, số lượng) trước khi chạy.
- Giá, tồn kho, ẩn/hiện, ảnh lấy lại từ Mongo (`san_pham`, `maxTimeMS` 400ms; fallback snapshot catalog). Sản phẩm không có trong kết quả tool không được nhắc: câu trả lời của LLM bị loại nếu có link id lạ (dùng câu mẫu tất định thay thế).
- Xung đột mức cao luôn kèm khuyến nghị hỏi bác sĩ da liễu; không chẩn đoán. Điều kiện phủ định mà đồ thị không kiểm được ("không gây bí da") được nói rõ là chưa kiểm chứng.
- Thành phần "không chứa X" chỉ xét sản phẩm đọc được >= 3 thành phần INCI (danh sách thành phần thiếu không bao giờ bị coi là "không chứa").

## Tool (`chatbot_service/graph_tools.py`)
| Tool | Tham số | Ghi chú |
|---|---|---|
| `find_products` | category, skin_type, concerns[], benefits[], include_ingredients[], exclude_ingredients[], avoid_conflict_with[], brand, price_max, price_min, limit (<=10, mặc định 6) | Loại da khớp đúng (`SUITABLE_FOR`) đi trước; thiếu (<3) thì thêm sản phẩm ghi "mọi loại da" và nói rõ. `avoid_conflict_with` loại sản phẩm có hoạt chất nối `CONFLICTS_WITH` mức trung bình/cao tới hoạt chất đó (áp `rank_limits` của luật U09). |
| `check_conflicts` | product_ids[2..6] hoặc ingredient_keys[2..5] | Theo `conflict_rules.yaml` (có nguồn, mức, thời điểm, lời khuyên). Sản phẩm: dùng `CartAnalyzeService.conflicts_for` (Neo4j, rơi về luật Python). |
| `explain_product` | product_id, probe_ingredients[] | Hoạt chất chính, công dụng/vấn đề (kèm `source`), loại da, kiểm tra có/không một hoạt chất hoặc nhóm. |
| `similar_products` | product_id, limit (<=8) | Dùng lại `SimilarProductsService` (U08). |

Kết quả JSON <= 2048 byte (`fit_result` rút gọn tên, bỏ `why`, bớt sản phẩm và đặt `truncated`). Thẻ sản phẩm cho UI nằm ở khóa riêng `_cards` (không đưa cho LLM).

Nhóm thành phần: `data/graph/ingredient_groups.yaml` (`exclusion_groups`: cồn khô, hương liệu, paraben, sulfate, silicone, dầu khoáng; `include_families`: vitamin C, hyaluronic, ceramide). Có test chống lệch với `ingredient_aliases.yaml`.

## Định tuyến (`chatbot_service/graph_router.py`)
Router **tất định** (không function-calling): pool LLM trộn OpenAI/Gemini/Groq/Zhipu/OpenRouter, hỗ trợ tool-calling không đồng đều và tốn thêm một lượt gọi. `search_service.intent_parser` rút thực thể và phạm vi phủ định; từ khóa chọn tool; LLM chỉ diễn đạt kết quả.
- `check_conflicts`: >=2 hoạt chất + "dùng chung/kết hợp/xung đột...".
- `similar_products` / `explain_product`: sản phẩm đang xem ("sản phẩm này") hoặc sản phẩm gọi đúng tên (hãng + mọi từ còn lại nằm trong đúng 1 tên).
- `find_products`: hỏi sản phẩm + có điều kiện mạnh (loại trừ/không kỵ, giá, hoạt chất, hãng+danh mục) hoặc >=3 điều kiện nhẹ (loại da/vấn đề/công dụng).
- Không định tuyến (RAG như cũ): routine, kiến thức ("là gì", "cách dùng"), chính sách cửa hàng, so sánh, khảo sát, câu thường ("serum cho da dầu"), tên sản phẩm không tra được, nhiều danh mục, hồ sơ da nhạy cảm không ổn định hỏi retinol/vitamin C (cổng an toàn cũ quyết định).
- Vị trí trong `xu_ly_cau_hoi`: sau nhánh STORE_SUPPORT, trước `contextualize_query`/`classify_intent` (tiết kiệm 2 lượt LLM). Neo4j lỗi/quá 600ms (`NEO4J_CALL_DEADLINE_S`), lỗi tool, không khớp -> trả `None` và chạy RAG.
- Cờ `SYNA_GRAPH_TOOLS_ENABLED`: mặc định `false` trong code, `true` trong `docker-compose.yml`.
- PHP (`HomeController::aiChatAssistant`): khi Flask trả khóa `graph_tool`, giữ nguyên danh sách sản phẩm và câu trả lời (bỏ các bộ lọc regex và câu chung "Mình đã tìm thấy các sản phẩm…"), vẫn áp lọc `avoid_ingredients` của hồ sơ.

## Phủ định (`Intent` mở rộng)
`Intent.exclude_ingredients` ("không chứa cồn") và `Intent.avoid_conflict_with` ("không kỵ retinol", "không xung đột với BHA"); phạm vi phủ định mở rộng tới 3 từ đệm và danh sách ("không chứa cồn và paraben"). Token phủ định vẫn nằm trong `residual_terms` (tương thích tìm kiếm).

## Log
`syna_graph`: `{"event":"syna_graph_tool","tool","params"(đã chuẩn hóa),"count","took_ms","degraded"}`. Không log câu hỏi của khách.

## Kiểm thử và đánh giá
- Unit: `ai-service-flask/tests/chatbot_graph/` (tool, router, tích hợp `xu_ly_cau_hoi`), `tests/search_service/test_intent_parser.py` (phủ định).
- Neo4j thật: `test_chat_tools_neo4j.py` (`-m integration`, `NEO4J_TEST_URI`).
- Bộ đánh giá: `tests/chatbot_graph/eval_cases.yaml` (32 câu, 5 câu không được định tuyến) + `scripts/eval_graph_tools.py`, chạy trong container: `MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python scripts/eval_graph_tools.py [--with-llm]`. Xác minh bằng truy vấn Neo4j/Mongo viết riêng (không dùng lại Cypher của tool).
- Khởi động: `_warm_chat_tools` biên dịch trước các tổ hợp điều kiện (Neo4j biên dịch ~0,4s mỗi tổ hợp cờ).

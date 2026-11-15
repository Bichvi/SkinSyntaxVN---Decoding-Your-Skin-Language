# Đánh giá công cụ đồ thị của Syna (U10), chạy ngày 2026-11-12

Bộ 32 câu: `ai-service-flask/tests/chatbot_graph/eval_cases.yaml` (27 câu phải định tuyến sang tool, 5 câu không được định tuyến). Chỉ đọc Neo4j và Mongo, không gọi LLM, không ghi dữ liệu. Chạy trong container `skinsyntax-ai` trên Docker local, Neo4j 5.26 community, Mongo Atlas.

## Kết quả chính (`eval-2026-11-12-isolated.json`, script `run_isolated.py`)

Cách chạy: gọi `search_service.service.warm_up()` giống gunicorn post_fork, sau đó chạy từng câu riêng. Lần thử 1 dùng đúng cấu hình server (hạn 600 ms cho mỗi lệnh Neo4j). Nếu tool lỗi, chờ 11 s cho cầu dao (mở 10 s) đóng lại rồi thử lần 2.

| Chỉ số | Lần thử 1 (cấu hình server) | Sau lần thử 2 |
|---|---|---|
| Định tuyến đúng (tool + tham số, hoặc không định tuyến) | 32/32 | 32/32 |
| Câu đạt toàn bộ ràng buộc | 28/32 | 31/32 |
| Ràng buộc đạt | 352/356 | 427/428 |
| Câu tool quá hạn (`neo4j_timeout`, hạ cấp sang RAG) | 4 | 1 |
| Ràng buộc bị vi phạm trên sản phẩm được trả về | **0** | **0** |

- Mọi ràng buộc không đạt đều do tool quá hạn, không có trường hợp nào trả về sản phẩm sai điều kiện (sai danh mục, sai loại da, chứa thành phần bị loại trừ, kỵ hoạt chất, sai hãng, giá ngoài khoảng, hay giá hiển thị khác giá trong Mongo).
- 4 câu quá hạn ở lần thử 1: `cerave_cleanser_no_alcohol`, `moisturizer_silicone_free_dry`, `toner_dry_no_alcohol`, `moisturizer_sensitive_no_alcohol_no_fragrance_400k`. Cả 4 đều là tổ hợp điều kiện **không có** trong danh sách làm ấm `_warm_chat_tools`. Ba câu đạt hết ở lần 2. Câu `moisturizer_silicone_free_dry` quá hạn cả 2 lần trong lượt này, nhưng đạt 21/21 ở 6 lần chạy riêng sau đó (3 lần với hạn 600 ms, 3 lần với hạn 5 s).
- Thời gian tool + đọc giá/tồn kho từ Mongo ở lần thử 1, chỉ tính các câu gọi Neo4j và có kết quả (n = 18): 49, 59, 81, 92, 112, 114, 142, 151, 190, 195, 210, 215, 239, 262, 293, 316, 761, 828 ms → p50 ≈ 171 ms, p95 (nearest-rank) = 828 ms. `check_conflicts` dùng luật YAML nên chỉ mất 0–1 ms.

## Hai lượt chạy script nguyên bản (để giải thích phương pháp)

| File | Điều kiện | Câu đạt | Ràng buộc |
|---|---|---|---|
| `eval-2026-11-12-fullrun-cold.json` | `scripts/eval_graph_tools.py` chạy thẳng, không làm ấm | 11/32 | 81/102 |
| `eval-2026-11-12-fullrun-warm.json` | Như trên, có `warm_up()` trước | 12/32 | 100/120 |

Nguyên nhân: script chạy liên tục các câu trong vài giây. Lệnh Neo4j đầu tiên vượt hạn 600 ms làm cầu dao mở 10 s, nên mọi câu gọi đồ thị sau đó bị hạ cấp ngay (thời gian 1 ms). Đây không phải lỗi kết quả. Tuy vậy, nó cho thấy một hành vi thật khi vận hành: một lần quá hạn khiến mọi câu hỏi có điều kiện trong 10 giây tiếp theo rơi về luồng RAG.

Lưu ý: lần chạy đầu tiên (cold) còn gặp lỗi kết nối Mongo `ConfigurationError` ở 2 lần thử đầu (tra DNS bản ghi SRV của Atlas). Lần sau ping ổn định.

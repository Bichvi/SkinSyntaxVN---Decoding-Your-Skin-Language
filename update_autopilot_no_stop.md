# update_autopilot_no_stop — Studio "Tự động" không dừng vì mơ hồ có thể sửa

Chỉ đổi trong `ai-idol-service/`. Chế độ thủ công (không phải autopilot) giữ nguyên hành vi: `build_query_plan` mặc định `autopilot=False`, các khóa của plan không đổi.

## File đổi
- `agents/command_query.py`: `build_query_plan(..., autopilot=True)` biến mọi câu hỏi thành mặc định; plan có thêm `autopilot_notes`, `categories`, `name_terms`, `prefer_pure`, `autopilot` (chỉ ở autopilot). `execute_query`: nhóm danh mục/tên (OR + chia đều số lượng), rank `units_asc`, `discount_slow` theo doanh số toàn kỳ, đẩy combo xuống cuối.
- `agents/catalog_constraints.py`: `extract_constraints(..., bare_country=True)` nhận "Hàn Quốc", "từ Pháp hoặc Đức" không cần "xuất xứ".
- `agents/autopilot_query.py` (mới): nhận diện brief là TẬP sản phẩm, và thang nới điều kiện khi 0 kết quả.
- `agents/service.py`: định tuyến brief tập sản phẩm vào query path, ghi `autopilot_notes` thành decision `adjusted`, nới điều kiện khi rỗng, purpose xác định bằng luật.
- Test: `tests/test_autopilot_no_stop.py` (mới); sửa 3 test cũ trong `tests/test_autopilot_service.py` cho hành vi mới.

## Quy tắc mặc định (mỗi quy tắc ghi decision `adjusted`)
| Tình huống | Mặc định |
|---|---|
| "tuần này" | 7 ngày hoàn tất gần nhất |
| "khoảng N tháng" | hạn còn lại N-1..N+1 tháng |
| "còn hạn từ A đến B tháng" | coi như hạn dùng A..B tháng (đi theo sổ lô) |
| "tồn kho thấp" / "sắp hết hàng" | tồn 1..10, xếp tồn ít nhất trước |
| "tồn kho nhiều nhưng bán chậm" | rank mới `units_asc` (doanh số toàn kỳ tăng dần), tồn từ 1, ưu tiên tồn/bán cao |
| "bán chậm" đứng riêng | `units_asc` |
| "xem nhiều" (không nói chưa mua) | `views_no_orders` |
| "trừ/không lấy sản phẩm hết hàng" | `stock_min = 1` |
| phủ định khác không hiểu | bỏ phần đó, ghi chú; phủ định hiểu được (thương hiệu, thành phần...) dùng như cũ |
| nhiều danh mục | lấy tất cả (OR), chia đều số lượng (danh mục đứng trước nhận phần dư) |
| danh từ chưa có danh mục (toner, tẩy trang, son môi...) | lọc theo tên sản phẩm chứa từ đó |
| nhiều tiêu chí / nhiều kỳ / mâu thuẫn | lấy tiêu chí đầu / kỳ ngắn nhất / giữ phần loại trừ |
| "N tuần/ngày qua" khác 7/30 | gần nhất trong 7 hoặc 30 (14 ngày → 7) |
| không nêu xếp hạng, "hot", "hot hit" | bán chạy nhất (doanh số toàn kỳ, sản phẩm chưa bán vẫn được xếp cuối) |
| "bỏ giỏ hàng" | `cart` |
| 0 kết quả | nới dần (`QUERY_RELAXED`): kỳ → toàn kỳ, bỏ giá, thành phần, loại da, xuất xứ, thương hiệu, lọc tên, tồn/hạn, rank → bán chạy, bỏ danh mục, cuối cùng theo mã. Không bao giờ bỏ điều kiện loại trừ. Chỉ dừng khi catalog rỗng |

`query_questions_decision` còn nguyên nhưng với autopilot không còn câu hỏi nào ngoài câu "Số lượng 1–50" (đã được COUNT_CAP trả lời).

## Định tuyến brief tập sản phẩm (nhóm B)
Brief không có id (`mã 995`) và không dính `business_query_requested` được đưa vào query path nếu có: số lượng ≥ 2 ("5 ...", "top 10"), "các/mấy/những", "hot/hot hit", "bỏ giỏ", "còn hạn", nhiều danh mục, hoặc ràng buộc xuất xứ/giá/loại da/thành phần/loại trừ/ghi chú nội dung. Sau đó bộ resolver tên chạy thử: nếu mọi sản phẩm được chọn là do TÊN XUẤT HIỆN NGUYÊN VĂN trong brief thì vẫn đi đường tên (nhiều sản phẩm gọi tên vẫn giữ). Danh mục hoặc thương hiệu đơn lẻ không kèm tín hiệu trên vẫn đi đường tên như cũ (test `test_ambiguous_name_picks_the_first_candidate`).

## Khác
- Đếm số lượng: autopilot nhận "<N> <danh từ bất kỳ>" (không nhận khoảng thời gian, giá, ngưỡng "từ/trên/dưới/khoảng N").
- "không lấy thương hiệu X": không còn bị BRAND_PATTERN hiểu thành "chỉ lấy X".
- `_extract_explicit_ids` đọc "mà bán" thành mã "ban"; ở autopilot chỉ nhận id có chữ số (execute_query và định tuyến).
- Purpose ở autopilot: chỉ `override purpose` hoặc `infer_purpose` (từ "livestream/live/youtube/facebook/tiktok" hoặc giờ gõ tay); purpose do LLM và giờ LLM tự bịa bị bỏ.
- Fallback toàn kỳ giờ truyền `vocab` (trước đó bị mất khi chạy lại) và `discount_slow` dùng xếp hạng bán chậm trong số sản phẩm đang giảm giá thay vì "bán chạy".
- Nhóm E: combo (tên có `+`, combo, set, bộ...) bị đẩy xuống cuối, chỉ lấp phần thiếu.

## Test
`tests/test_autopilot_no_stop.py` 26 test (A1–A8, định tuyến B, đếm C, purpose D, combo E, nới điều kiện, catalog rỗng, hồi quy thủ công). Ba test cũ đổi kỳ vọng vì trái spec mới (dừng -> mặc định/nới): `test_unclear_query_is_defaulted_not_stopped`, `test_nothing_to_rank_on_is_relaxed_to_catalog_order`, và `test_no_products_from_a_query_carries_the_query_warnings` (giờ dùng catalog rỗng). Toàn bộ suite ai-idol-service xanh.

## Còn thiếu / lưu ý
- `plan["summary"]` của query_result không cập nhật sau khi nới (decision `QUERY_RELAXED` mới là nguồn sự thật).
- "dưỡng trắng", "lão hóa", "dịu nhẹ" chưa có bộ lọc dữ liệu: rơi về bán chạy nhất trong nhóm.
- `units_asc` chỉ dùng doanh số toàn kỳ của catalog (chưa có snapshot theo kỳ).
- Chưa chạy dry-run trên catalog Atlas thật.

## Vòng 2 (kết quả dry-run Atlas thật)
1. **Kỳ thiếu số liệu:** nếu xếp hạng theo kỳ (7/30 ngày) trả ít hơn số cần (nhưng > 0), bổ sung phần còn thiếu từ xếp hạng 30 ngày (khi kỳ gốc là 7), rồi doanh số toàn kỳ, cùng bộ lọc; ghi `METRICS_FALLBACK` (adjusted). Kỳ rỗng hoàn toàn vẫn thay bằng toàn kỳ như cũ. Test `test_partial_snapshots_that_leave_the_list_short_fall_back` đổi kỳ vọng: 3 sản phẩm của kỳ đứng đầu, rồi toàn kỳ lấp tiếp.
2. **Nhu cầu mô tả** (bảng `NEEDS` trong `command_query.py`): trị mụn, dưỡng trắng/sáng da, vitamin C, da nhạy cảm, chống lão hóa, không hương liệu. Sản phẩm "khớp" nếu tên/thành phần có từ khóa, hoặc loại da đúng, hoặc (không hương liệu) danh sách INCI không có fragrance. Khớp đủ nhu cầu xếp trước; chỉ lọc cứng khi số khớp >= số cần; thiếu thì lấp bằng xếp hạng chung và ghi `NEED_RELAXED`. Điều kiện loại da trùng nhu cầu (mụn, nhạy cảm) hạ xuống SOFT để hợp với "OR theo tên".
   - Lỗi gốc của "cho da nhạy cảm": "không chứa hương liệu cho da nhạy cảm" bị hiểu là LOẠI TRỪ da nhạy cảm. Sửa trong `extract_constraints` (chỉ khi `bare_country=True`, tức autopilot): chữ "không" thuộc về thành phần đứng ngay sau nó.
   - Danh mục serum không còn nhận "Tinh Chất Chống Nắng" (autopilot, `_category_matches(strict=True)`).
   - Thang nới điều kiện giờ HẠ điều kiện cứng (giá, thành phần kể cả loại trừ do thiếu INCI, loại da, xuất xứ) xuống ưu tiên thay vì bỏ hẳn. Loại trừ thương hiệu không bao giờ bị nới.
3. **Hạn dùng:** "còn hạn / hạn dùng / hạn còn / hết hạn" luôn vào query path, bỏ qua bước thử resolver tên.
4. **Biến thể:** autopilot chỉ lấy 1 sản phẩm mỗi dòng sản phẩm (tên bỏ dung tích, màu/tone, "Combo N", [Mini], số); biến thể còn lại và combo chỉ lấp phần thiếu.

Test mới: lớp `NoStopRoundTwoTests` (9 test) trong `tests/test_autopilot_no_stop.py`.
Lưu ý: "dưỡng trắng", "lão hóa" vẫn dựa vào từ khóa trong tên/thành phần, không có cột catalog riêng.

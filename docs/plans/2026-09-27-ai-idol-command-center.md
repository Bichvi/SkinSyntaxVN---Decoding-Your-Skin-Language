# AI Idol: Command Center — kế hoạch và tiêu chí nghiệm thu

Ngày: 27/09/2026. Phạm vi: Agent trong AI Idol Studio, không đổi chatbot/recommend.

## Hiện trạng đã đọc từ code

- PHP admin chuyển lệnh qua Flask rồi Celery `process_agent_run_task`.
- `classify_intent` chỉ cho CREATE_CAMPAIGN đi tiếp; PRODUCT_SEARCH dừng ở NEEDS_INPUT.
- Catalog bị chuẩn hóa hai lần và mất sales_count trong lần thứ hai.
- Resolver chưa có điều kiện tồn kho, ngày hết hạn, danh mục chống nắng hay thời gian thống kê.
- Model PHP có các alias stock; không đồng nghĩa mọi bản ghi `san_pham` đã có stock. Chưa thấy hợp đồng quản lý lô/hạn dùng trong model PHP.
- Có đơn hàng và chi tiết đơn hàng, nhưng số lượt xem/thêm giỏ hiện chưa có hợp đồng attribution và coverage phục vụ chuyển đổi.

## Quyết định thiết kế trước triển khai

Dùng planner xác định cho các bộ lọc nghiệp vụ có cấu trúc; LLM hiện có tiếp tục xử lý brief sáng tạo. Không thêm agent/DB/dependency cho truy vấn số liệu. CrewAI giữ vai trò viết và phản biện kịch bản. MongoDB là nguồn sự thật; vector search không quyết định thứ hạng bán hàng.

Luồng: prompt mẫu hoặc lệnh → classifier → QueryPlan → kiểm tra thiếu/mơ hồ → truy vấn → kết quả + bằng chứng → chuyển danh sách sang Wizard hoặc campaign. Lệnh chỉ lọc kết thúc ở RESULTS_READY, không render. Lệnh kép tạo live chỉ đi tiếp khi điều kiện dữ liệu và lịch hợp lệ.

## Các mốc triển khai trong thay đổi này

1. Prompt mẫu theo nhóm Kho & hạn dùng / Bán hàng / Livestream; click điền ô nhập, không gửi ngay; cho chỉnh trước khi chạy.
2. QueryPlan: danh mục, số lượng, tồn kho, khoảng hạn còn lại theo tháng lịch, metric, khoảng thời gian, cảnh báo và câu hỏi làm rõ. Điều kiện hạn dùng áp dụng trên lô còn hàng, loại lô hết hạn/cách ly. Không coi "hạn dùng 36 tháng kể từ NSX" là ngày hết hạn.
3. Executor read-only: áp bộ lọc cứng trước, xếp hạng sau, giới hạn cuối. Không bù sản phẩm ngoài điều kiện. Trả số lượng yêu cầu/thực tìm được, nguồn dữ liệu, thời điểm kiểm tra, lý do loại do thiếu dữ liệu. Không xếp hạng trên catalog bị cắt ngầm.
4. Có sẵn strategy bán chạy, doanh thu cao/chuyển đổi thấp, xem nhiều chưa mua, thêm giỏ nhiều, giảm giá bán chậm, tăng trưởng. Nếu chưa có snapshot đủ coverage: trả DATA_UNAVAILABLE với hướng khắc phục, không dùng review_count làm sales.
5. Tích hợp vào agent service, render bảng kết quả và cho chuyển sản phẩm sang Wizard. Lịch ngày rõ ràng không tự dời sang ngày mai; không đồng nhất giờ phát với thời lượng.
6. Kiểm thử parser, điều kiện ngày/tháng, nhiều lô, missing-vs-zero, phân loại, thứ hạng, tách read-only/campaign; PHP/JS syntax; kiểm tra UI với harness có sẵn.

## Hợp đồng dữ liệu

- Catalog: mã/tên, danh mục, trạng thái, stock (alias của model PHP), giá, sales_count toàn kỳ (chỉ dùng khi yêu cầu toàn kỳ).
- `inventory_batches`: product_id (chuỗi), batch_id, expires_on (YYYY-MM-DD hoặc BSON date), available_quantity (sau trừ giữ chỗ), status=available. Một sản phẩm có nhiều lô; kết quả nêu lô phù hợp và số lượng riêng, không suy thành toàn bộ tồn có cùng hạn.
- `product_metrics_snapshots`: product_id, window_days (7/30), as_of (YYYY-MM-DD, ngày chốt loại trừ), coverage_complete=true, units_sold, net_revenue, viewed_sessions, converted_sessions, cart_sessions, previous_units_sold. Đếm sessions chuyển đổi phải là tập con của sessions đã xem cùng cohort và cùng cửa sổ attribution. Không lấy orders/views ở hai tập không liên quan.
- Snapshot chốt ngày hiện tại ở Asia/Ho_Chi_Minh; cửa sổ là 7/30 ngày hoàn tất trước hôm nay. Tuần này so sánh cùng số ngày hoàn tất của tuần trước, không so nửa tuần với cả tuần. Nếu chưa có thì yêu cầu rõ 7 ngày thay vì giả định.
- Không có snapshot = thiếu dữ liệu, không phải 0. Hoàn tiền/hoàn hàng phải phân bổ theo từng dòng; không dùng tổng tiền hóa đơn (gồm phí giao hàng) làm doanh thu sản phẩm.

## Định nghĩa nghiệp vụ, cần hiển thị cho admin

- Còn tối đa 18 tháng: ngày hết hạn > hôm nay và <= hôm nay + 18 tháng lịch.
- Từ 17 đến 19 tháng: biên bao gồm; riêng lô hết hạn hôm nay luôn bị loại.
- Khoảng 18 tháng: hỏi admin chọn phạm vi; không tự hiểu thành cận hạn.
- Bán chạy: số lượng bán hợp lệ giảm dần; nếu không nói kỳ dùng 30 ngày, hiển thị rõ kỳ.
- Cao/thấp: percentile trong cùng tập danh mục và cửa sổ; cần tối thiểu 5 sản phẩm đủ dữ liệu.
- Chuyển đổi thấp: converted_sessions/viewed_sessions, cần >=100 viewed_sessions (ngưỡng cấu hình trong code v1).
- Xem nhiều chưa chốt: converted_sessions=0, phân biệt rõ với chuyển đổi thấp.
- Bán chậm: units/day trong 30 ngày; dữ liệu bán ngày còn hàng sẽ cải thiện metric giai đoạn sau.
- Tăng trưởng: (hiện tại-trước)/trước, previous=0 không chia bằng max(previous,1); báo thiếu cơ sở so sánh. Kèm chênh lệch tuyệt đối.

## Kế hoạch tiếp theo để hoàn thiện nguồn dữ liệu

1. Quản lý lô nhập: bổ sung màn nhập batch_id/NSX/HSD/số lượng, giữ chỗ/hoàn kho theo lô, FEFO. Import có preview và xác nhận; không tạo HSD giả cho catalog cũ.
2. Pipeline snapshot: job Celery theo ngày, unique(product_id,window_days,as_of), watermark, backfill có giới hạn, reconcile với đơn đã thanh toán/trạng thái hủy/hoàn. Snapshot được publish coverage_complete chỉ sau khi toàn bộ nguồn reconcile thành công.
3. Tracking: event_id duy nhất, product_view/add_to_cart/purchase, session ẩn danh, event_time, loại bot/admin/test, idempotency; phiên mua phải nối về phiên xem theo attribution thống nhất. Thu thập tối thiểu và có thời hạn lưu.
4. Index gợi ý: inventory_batches(product_id,status,expires_on); product_metrics_snapshots(window_days,as_of,product_id) unique; catalog(category_id,status). Đo explain và latency trước tạo index production.
5. Nâng parser: schema JSON có version, entity resolver taxonomy, field whitelist; LLM đề xuất slot nhưng backend xác minh. Bộ eval >=100 câu tiếng Việt có dấu/không dấu, negation, đa intent, sửa lệnh, lịch.
6. Vận hành: trace_id/run_id, latency từng stage, data coverage, clarification rate, false-match rate, top-k relevance, empty-result rate. Không log credentials hoặc chi tiết khách hàng.

## Nghiệm thu

- Prompt kho và HSD xuất hiện dưới textarea, dùng được bằng bàn phím.
- "hạn sử dụng" không bị hiểu nhầm thành "dừng" campaign.
- Không chọn kem nền SPF thay cho kem chống nắng; không chọn cleanser nhờ từ khóa trong mô tả.
- Lọc 10 chỉ trả tối đa 10, nêu rõ nếu thiếu; không dùng candidate ngoài điều kiện để xác nhận bypass.
- Query đọc không gọi LLM/TTS/render/create_campaign.
- Hai lô khác HSD được đánh giá riêng, stock unknown không bằng stock 0.
- Có kế hoạch/tiêu chí/lý do hiển thị; kết quả chỉ là dữ liệu nội bộ, không tự chuyển số liệu thành claim quảng cáo.
- Ghi kết quả test thật và giới hạn môi trường ở cuối phiên triển khai; không push nếu chưa có yêu cầu mới.

## Cấu trúc code đã triển khai

| Tệp | Trách nhiệm |
| --- | --- |
| `ai-idol-service/agents/intent_classifier.py` | Nhận diện yêu cầu lọc; không nhầm “sử dụng” thành “dừng”. |
| `ai-idol-service/agents/command_query.py` | Biên dịch các mẫu được hỗ trợ thành QueryPlan, lọc cứng, xếp hạng, ghi bằng chứng và phần thiếu dữ liệu. |
| `ai-idol-service/agents/query_sources.py` | Adapter đọc MongoDB; không ghi/migrate; giới hạn an toàn trả lỗi khi vượt thay vì cắt catalog ngầm. |
| `ai-idol-service/agents/service.py` | Điều phối query-only hoặc query + campaign; chặn thiếu dữ liệu, thiếu số lượng, lịch không hợp lệ. |
| `ai-idol-service/agents/intent_parser.py` | Đọc ngày dd/mm/yyyy, phân biệt giờ phát và thời lượng, giữ lịch quá khứ để yêu cầu sửa. |
| `ai-idol-service/agents/product_resolver.py` | Giữ nguyên số bán khi chuẩn hóa lại dữ liệu. |
| `frontend/views/admin/ai_idol/index.php` | 15 prompt mẫu theo 3 nhóm; không tự gửi khi nhấn mẫu. |
| `frontend/public/assets/js/ai-idol-studio.js` | Điền/khôi phục lệnh, giải thích kết quả, thêm sản phẩm đã xác minh vào Wizard kể cả ngoài 100 sản phẩm tải ban đầu. |
| `frontend/public/assets/css/ai-idol-studio.css` | Style giới hạn trong Studio, hỗ trợ bàn phím và bố cục co giãn. |
| `ai-idol-service/tests/test_command_query*.py` | Kiểm thử planner/executor và hợp đồng orchestration, không dùng LLM thật. |

Không thêm database, dependency hoặc dịch vụ AI mới. API PHP → Flask hiện có giữ nguyên; kết quả mở rộng nằm trong `run.plan.query_result`.

### Hợp đồng kết quả và xử lý từng trường hợp

`query_result` gồm `query`, `selected`, `warnings`, `missing_data`, `complete`, `matched_count`, `requested_count`, `checked_at`, `status`. Mỗi phần tử `selected` có `product_id`, `name`, `reason`, `evidence`. Đây là bằng chứng truy vấn, không phải “độ tin cậy 100%”.

| Trường hợp | Kết quả | Tác vụ tốn tài nguyên |
| --- | --- | --- |
| Chỉ lọc, đủ dữ liệu | RESULTS_READY + danh sách/bằng chứng | Không LLM/TTS/render/campaign |
| Chỉ lọc, không sản phẩm phù hợp | EMPTY_RESULTS, không bù sản phẩm ngoài tiêu chí | Không |
| Không có nguồn số liệu cần thiết | DATA_UNAVAILABLE + nhóm dữ liệu còn thiếu | Không |
| Có kết quả nhưng một phần catalog thiếu số liệu | RESULTS_READY, complete=false, nêu rõ phạm vi xếp hạng | Không tự chuyển campaign |
| “Khoảng 18 tháng”, nhiều tiêu chí mơ hồ, ngoài khả năng v1 | NEEDS_INPUT + câu hỏi cụ thể | Không |
| Lọc + tạo live, ít kết quả hơn yêu cầu | NEEDS_INPUT; admin sửa số lượng/điều kiện | Không tạo campaign |
| Lọc + tạo live, đủ số lượng và dữ liệu | Tiếp tục pipeline campaign cũ, giữ policy đã chọn | Chỉ enqueue sau validation |
| Giờ rõ ràng đã qua/không đủ chuẩn bị | NEEDS_INPUT / SCHEDULE_REVIEW; gợi ý lịch nhưng chưa đặt | Không tự dời lịch |
| Nguồn dữ liệu vượt giới hạn hoặc lỗi kết nối | NEEDS_ATTENTION theo cơ chế lỗi hiện có | Không xếp hạng tập bị cắt |

### Giới hạn v1 cần hiểu đúng

- Bộ phân tích xác định, chưa phải NLU hiểu mọi câu tiếng Việt. Taxonomy đang ánh xạ kem chống nắng, sữa rửa mặt, serum; các ví dụ trên UI là phạm vi được hỗ trợ. Tìm sản phẩm tự do không có từ khóa nghiệp vụ vẫn theo cơ chế xác nhận cũ.
- Kiểm tra phủ định/điều kiện không hỗ trợ là guard có giới hạn, không phải chứng minh hiểu mọi ràng buộc trong ngôn ngữ tự nhiên. Mở rộng phải thêm slot/schema và eval tương ứng, không chỉ thêm prompt cho LLM.
- Snapshot adapter dùng `as_of` chuỗi ISO ngày (không BSON datetime); `expires_on` hỗ trợ ISO ngày hoặc BSON datetime. Unique key snapshot và validation nằm trong kế hoạch job ETL, chưa migrate DB.
- Đọc catalog v1 có trần 20.000 bản ghi; lô hàng 50.000. Khi vượt phải chuyển bộ lọc/xếp hạng sang Mongo aggregation/index có kiểm tra coverage.
- QueryResult là snapshot khi chạy. Chọn vào Wizard không giữ chỗ lô và chưa đảm bảo FEFO khi xuất kho. Không tự đưa HSD hoặc số lượng tồn vào lời quảng cáo.

## Bàn giao kiểm chứng — 28/09/2026

- PASS: `python -m unittest discover -s tests -v` trong image `skinsyntax-ai-idol:local`, mount source chỉ đọc, `--network none`: **82 tests**. Trong đó 34 test mới cho query và orchestration.
- PASS: `php -l frontend/views/admin/ai_idol/index.php`; PHP render view với catalog rỗng xác nhận 15 nút prompt và nút khôi phục.
- PASS: `node --check frontend/public/assets/js/ai-idol-studio.js`; `git diff --check` tại thời điểm kiểm tra.
- Đọc MongoDB local bằng container kiểm chứng riêng: 6.271 sản phẩm, 258 bản ghi khớp chống nắng; truy vấn hạn 17–19 tháng thiếu ngày hết hạn/số lượng lô; truy vấn 7 ngày thiếu snapshot ở 258 bản ghi. Bán chạy toàn kỳ không có bản ghi hợp lệ có số bán >0. Không sử dụng review_count để bù số liệu.
- E2E admin NOT RUN thành công: browser được điều khiển vẫn chuyển về trang khách; không tạo session giả/bỏ qua kiểm tra quyền. Repo chưa có harness Playwright cho Studio. PHP render/syntax không thay thế E2E.
- Linter Python/CSS và frontend unit runner riêng cho Studio chưa được cấu hình; không coi syntax check là lint đầy đủ.
- GitNexus index cũ không tìm thấy các symbol mới (UNKNOWN). Đã đối chiếu callers bằng tìm kiếm/đọc luồng PHP → Flask → Celery. Reindex CLI bị EACCES khi truy cập npm; chưa có graph validation sạch.
- Chưa commit/push, migrate, rebuild hoặc restart dịch vụ đang chạy. Docker API/worker đang dùng source trong image cũ; code backend mới đã kiểm thử bằng mount chỉ đọc trong container riêng, chưa kích hoạt trên phiên đang chạy.

### Trình tự đưa vào dùng sau khi duyệt local rebuild

1. Kiểm tra không có tác vụ render/phát cần giữ nguyên; giữ nguyên volume dữ liệu.
2. Build image AI Idol từ source mới bằng Compose; không cài lại mô hình/PyTorch nếu cache vẫn hợp lệ.
3. Cập nhật các process dùng chung image (API, worker, beat, broadcast worker) trong thời điểm an toàn; không restart MongoDB/Redis ngoài phạm vi.
4. Đăng nhập admin, thử prompt kho, “khoảng 18 tháng”, bán chạy 7 ngày; xác nhận không tạo campaign và thể hiện thiếu dữ liệu đúng.
5. Trên fixture đủ dữ liệu: kiểm tra thứ hạng, thiếu số lượng, chuyển Wizard, lệnh kép với assisted policy; sau đó mới thử một campaign nội bộ. Chưa bật auto-publish.
6. Hoàn thiện quản lý lô và ETL snapshot theo các mốc ở trên trước khi tuyên bố bộ lọc hạn dùng/bán hàng sẵn sàng dùng thực tế.

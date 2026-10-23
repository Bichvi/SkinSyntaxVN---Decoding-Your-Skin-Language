# Trung tâm hỗ trợ hợp nhất + Chatbot trả lời chính sách & HDSD

Trạng thái: **Đã triển khai** (2026-10-23; đã commit toàn bộ Bước 00–07b) · Nhánh `syna`

| Tài liệu | Dùng để |
|---|---|
| `docs/superpowers/specs/2026-10-21-store-support-chatbot-design.md` | Thiết kế chi tiết (nguồn quyết định) |
| `docs/prompts/store-support/README.md` | Bộ prompt thực thi 8 bước + luật chung |
| `docs/plans/store-support-handoff.md` | Sổ bàn giao giữa các bước |

## Vấn đề

1. Chatbot Syna chưa dùng kho hỗ trợ `support.json` → câu hỏi chính sách bị AI trả lời chung chung hoặc bịa.
2. Web có hai trung tâm hỗ trợ mâu thuẫn: trang `ho_tro_khach_hang`/`bao_hanh` + header/footer ghi `1900 0000`, giờ `08:00–22:00`; `support.json` ghi `0398606281`, giờ chưa công bố. Trang `tro_giup` không có link trên menu.
3. Chưa có hướng dẫn sử dụng cho tính năng riêng (khảo sát da, Syna, voucher từ video, live, flash sale…).

## Giải pháp (tóm tắt)

- **Một nguồn sự thật**: `support.json` (đúng: `0398606281`, `cskh@skinsyntax.com`, giờ chưa công bố). Header/footer/trang hỗ trợ/chatbot cùng đọc.
- **Một trung tâm hỗ trợ** tại `?r=ho_tro_khach_hang` (các URL cũ giữ nguyên), giao diện **accordion 4 cấp**: Nhóm → Bài → Mục → Chi tiết; deep-link, lọc tức thì không dấu, "Hỏi Syna" ở từng mục; chạy được khi tắt JS.
- **Nhóm mới "Hướng dẫn sử dụng"**: 9 bài (5/14 bài dự kiến bị loại vì route/luồng hỏng, xem handoff Bước 02), mỗi bước truy vết được về route/controller/view thật.
- **Chatbot**: PHP xếp hạng bài (`SupportKnowledge::rank`) → gửi ≤3 bài qua khóa `store_support_articles` → Flask nhánh `STORE_SUPPORT` (sau chitchat, trước off-topic): rerank đoạn bằng cross-encoder sẵn có → LLM viết câu trả lời có trích dẫn theo prompt cố định → **hậu kiểm số liệu** (ngày, phí, %, giờ, SĐT, email, URL, lời hứa) → sai thì trả bản soạn sẵn. Flask lỗi → PHP trả bản soạn sẵn nếu khớp chắc.
- **Widget**: nhãn "Thông tin chính thức", thẻ nguồn, chip hỏi tiếp, menu "Trợ giúp" dropdown 2 cấp.

## Thứ tự thực thi

00 Khảo sát → 01 Nguồn sự thật + gộp route → 04 Xếp hạng PHP → 05 Flask STORE_SUPPORT → 02 Bài HDSD → 03 Giao diện dropdown → 06 Widget → 07 Kiểm thử tổng.

## Quy tắc khi thêm/sửa bài trong `support.json`

- Chỉ viết điều có trong code/giao diện thật hoặc chính sách đã được duyệt; không thêm số liệu chưa duyệt.
- `triggers`: cụm không dấu, chữ thường; `questions`: câu tự nhiên có dấu (dùng cho FAQ, chip, test).
- `answer` phải tự đứng được (chatbot dùng khi LLM lỗi).
- Bài HDSD phải có `route_sources`; đổi giao diện tính năng → rà lại bài tương ứng.
- Tăng `version` mỗi lần sửa nội dung chính sách.
- Chạy `backend/tests/unit/SupportContentTest.php` và `SupportRankTest.php` sau khi sửa.

## Kiến trúc (thực tế sau triển khai)

- Nguồn: `backend/app/content/support.json` (6 nhóm, 29 bài). Đọc qua `SupportKnowledge` (`rank`, `toChatContext`, `fallbackResponse`, `shouldBypassChatCache`).
- Chat: widget JSON -> `HomeController::aiChatAssistant()` -> `rank()` ; có bài >= `LOW_THRESHOLD` thì bỏ qua cache PHP và thêm khóa `store_support_articles` vào JSON prompt -> Flask `chatbot_flask.py::xu_ly_cau_hoi` khối "1b" (sau kiểm khảo sát, trước `contextualize_query`) -> `store_support.py` (chọn đoạn + rerank, prompt, hậu kiểm, `canonicalize_links`).
- Ngưỡng: `LOW_THRESHOLD=3.0`, `HIGH_THRESHOLD=8.0` (fallback PHP chỉ khi Flask lỗi và bài đầu >= HIGH), `EXACT_SCORE=1000`, `CONTEXT_CHAR_LIMIT=2500` ký tự/bài, tối đa 3 bài.
- Khóa payload: `store_support_articles[{slug,title,summary,answer,sections,url,version,score,questions}]`. Khóa phản hồi: `intent_mode=STORE_SUPPORT`, `support_topic` (slug), `sources[{title,url,version}]`, `suggested_questions`, `pipeline_mode` = `store_support_rag` | `verified_store_content` (bản soạn sẵn, `fallback=true`).
- Hậu kiểm: số liệu/SĐT/email/URL không có trong nguồn, nhắc lại số sai của khách, "chưa có thông tin" kèm >= 2 bước đánh số -> bản soạn sẵn.
- Giao diện: `info/support.php` accordion 4 cấp (`support-center.js/css`); widget (`ai_chat_widget.php`) hiện nhãn "Thông tin chính thức", thẻ nguồn, chip gợi ý, menu "Trợ giúp".

## Quy tắc viết bài HDSD

- Mỗi bước phải chép từ code/giao diện thật (nhãn nút đúng chữ), có `route_sources`, `updated`.
- Không đưa số liệu cấu hình theo chiến dịch (số lần nhận voucher, chu kỳ luân phiên...). Số đưa vào phải có trong code (vd 95% thời lượng video).
- Để câu hỏi sản phẩm không bị hút: title/summary/questions không chứa từ chung (da, routine, sản phẩm, sale...); để từ đó trong `triggers`.
- Không viết bài cho tính năng đang hỏng (route 404/Fatal).

## Chạy test và eval

- PHP: `php backend/tests/unit/SupportContentTest.php` (38), `SupportRankTest.php` (50), `php backend/tests/store_assistance_test.php` (156), `php backend/tests/check_store_cases.php` (`failed: []`).
- Python (trong `ai-service-flask/`): `pytest tests/test_store_support.py tests/test_chat_regressions.py tests/test_acceptance_fixes.py tests/test_chatbot_init_locks.py` (119 passed).
- Eval chất lượng (cần Docker :8080 + AI :5001, gọi LLM thật): `python tests/run_store_support_eval.py` -> `report/store-support-e2e/eval-<ngày>.json`. Bộ ca: `tests/store_support_eval_cases.json` (45 ca: 25 chính sách, 5 khẳng định quá mức, 10 sản phẩm, 5 tấn công). Ca overclaim chấm tay (script chỉ gắn cờ bằng cụm từ, có dương tính giả khi câu phủ định).
- Kết quả 2026-10-23: đúng slug 25/25 chính sách (30/30 kể cả overclaim), chứa giá trị bắt buộc 16/16, số liệu lạ 0, câu sản phẩm không bị hút 10/10, tấn công 5/5, fallback 5/35 (đều ở câu đối kháng/không có đáp án trực tiếp), p50 2,98 s, p95 6,03 s, khẳng định quá mức chấm tay 1/5 (VAT).
- Kết quả sau Bước 07b (`eval-2026-10-23-r2.json`): chứa giá trị bắt buộc 16/16, số liệu lạ 0, sản phẩm 10/10, tấn công 5/5, **khẳng định quá mức chấm tay 0/5** (VAT nay mở đầu "Bạn cần liên hệ ... để xác nhận"; script gắn cờ 0/5 sau khi bỏ dương tính giả câu phủ định), fallback 3/34, p50 2,53 s, p95 5,71 s. Đúng slug 24/25 chỉ vì 1 ca bị 502 (nhà cung cấp LLM `APIStatusError`, không phải lỗi logic; chạy lại tay "tôi muốn khiếu nại đơn hàng" -> `khieu-nai` đúng). Hậu kiểm mới kích hoạt 0 lần trên eval thật (prompt đã đủ); được kiểm bằng unit test.
- E2E Playwright: ảnh trong `report/store-support-e2e/final/`.

## Giới hạn đã biết (Bước 07)

- **Khẳng định quá mức (đã xử lý ở Bước 07b)**: prompt thêm quy tắc 12-13 (chỉ nói có/không khi nguồn nói rõ; nguồn chỉ bảo liên hệ thì mở đầu "Bạn cần liên hệ ... để xác nhận") + hậu kiểm `unsupported_claim` trong `validate_answer(answer, passages, question)`: chỉ chạy với câu hỏi có/không, câu đầu bắt đầu "Shop/SkinSyntax có", "Có", "Được", "Bạn có thể"; cụm hành động (bỏ dấu, bỏ stopword) phải phủ >= 60% ở một câu nguồn không mang giọng "để xác nhận/để biết/chưa có/đang cập nhật", nếu không -> bản soạn sẵn. Bảo thủ có chủ ý: cụm < 2 từ khóa thì dùng cụm của câu hỏi, vẫn < 2 thì bỏ qua; câu phủ định ("Không...", "Mình chưa có thông tin...") không bao giờ bị kiểm. Giới hạn: đồng nghĩa lạ có thể bị báo nhầm (rơi về bản soạn sẵn, an toàn); chỉ kiểm câu mở đầu, không kiểm các câu sau.
- Câu hỏi diễn đạt quá xa mọi trigger/câu mẫu có thể không lọt ngưỡng -> đi luồng AI cũ.
- `toChatContext` cắt phần cuối bài dài (> 2.500 ký tự); câu tấn công/đối kháng thường rơi về bản soạn sẵn (an toàn nhưng cứng).
- Câu hỏi nhiều ý chạm hai bài: PHP rank tối đa 3 bài, chất lượng phụ thuộc LLM.
- 5 bài HDSD chưa viết vì tính năng hỏng (mua trong chat, dùng voucher ở thanh toán, xem live, đánh giá, hỏi đáp sản phẩm); `lichsuchat` vẫn 404.
- Bộ eval gọi LLM thật nên kết quả dao động nhẹ giữa các lần chạy.
- Thông tin pháp lý (tên doanh nghiệp, MST, địa chỉ) vẫn chờ đơn vị vận hành bổ sung.
- Việc nên làm tiếp: trang admin soạn bài `support.json`, chọn section theo câu hỏi thay vì cắt cuối bài.

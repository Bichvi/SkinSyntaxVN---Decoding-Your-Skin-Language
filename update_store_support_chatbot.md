# Trung tâm hỗ trợ hợp nhất + Chatbot trả lời chính sách & HDSD

Trạng thái: **Đã thiết kế, chưa triển khai** (2026-10-21) · Nhánh `syna`

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
- **Nhóm mới "Hướng dẫn sử dụng"**: 14 bài, mỗi bước truy vết được về route/controller/view thật.
- **Chatbot**: PHP xếp hạng bài (`SupportKnowledge::rank`) → gửi ≤3 bài qua khóa `store_support_articles` → Flask nhánh `STORE_SUPPORT` (sau chitchat, trước off-topic): rerank đoạn bằng cross-encoder sẵn có → LLM viết câu trả lời có trích dẫn theo prompt cố định → **hậu kiểm số liệu** (ngày, phí, %, giờ, SĐT, email, URL, lời hứa) → sai thì trả bản soạn sẵn. Flask lỗi → PHP trả bản soạn sẵn nếu khớp chắc.
- **Widget**: nhãn "Thông tin chính thức", thẻ nguồn, chip hỏi tiếp, menu "Trợ giúp" dropdown 2 cấp.

## Thứ tự thực thi

00 Khảo sát → 01 Nguồn sự thật + gộp route → 04 Xếp hạng PHP → 05 Flask STORE_SUPPORT → 02 Bài HDSD → 03 Giao diện dropdown → 06 Widget → 07 Kiểm thử tổng.

## Quy tắc khi thêm/sửa bài trong `support.json` (áp dụng sau khi triển khai)

- Chỉ viết điều có trong code/giao diện thật hoặc chính sách đã được duyệt; không thêm số liệu chưa duyệt.
- `triggers`: cụm không dấu, chữ thường; `questions`: câu tự nhiên có dấu (dùng cho FAQ, chip, test).
- `answer` phải tự đứng được (chatbot dùng khi LLM lỗi).
- Bài HDSD phải có `route_sources`; đổi giao diện tính năng → rà lại bài tương ứng.
- Tăng `version` mỗi lần sửa nội dung chính sách.
- Chạy `backend/tests/unit/SupportContentTest.php` và `SupportRankTest.php` sau khi sửa.

## Giới hạn đã biết (cập nhật ở Bước 07)

- Câu hỏi diễn đạt quá xa mọi trigger/câu mẫu có thể không lọt ngưỡng → đi luồng AI cũ.
- Thông tin pháp lý (tên doanh nghiệp, MST, địa chỉ) vẫn đang chờ đơn vị vận hành bổ sung.

# Kiểm tra lỗi cuộn và retinol / toner

## Phạm vi và kết quả

- 6/6 ca gọi API thật đạt các điều kiện trong `run_checks.py`; xem đầy đủ câu hỏi, câu trả lời và sản phẩm trong `live-results.json`.
- Ba ca đi qua PHP (`ai_chat_api`); ba ca gọi Flask với ngữ cảnh hồ sơ/lịch sử giả lập. Các ca giả lập không đại diện cho kiểm thử đăng nhập thực tế.
- Giá, ID và nhóm sản phẩm trả về được đối chiếu MongoDB đang chạy. Ca retinol kiểm tra có retinol trong dữ liệu thành phần và trả đúng một sản phẩm.
- Kiểm tra quyết định retinol ban đầu báo lỗi vì bộ test chỉ chấp nhận cụm “chưa nên”. Đọc câu trả lời thực tế thấy “chưa khuyên bắt đầu retinol ngay”; đã sửa bộ test chấp nhận ngữ nghĩa này và chạy lại cả sáu ca.
- Bộ unit test hiện có: 36 test đạt trước thay đổi kiểm tra khẳng định an toàn; sau thay đổi, chạy lại 31 test trong `test_chat_regressions.py`, gồm một regression mới, đều đạt.
- PHP lint và kiểm tra whitespace diff đạt.

## Các ca API

| Ca | Kết quả cần kiểm tra | Kết quả |
|---|---|---|
| Khách hỏi “kiếm 1 chai retinol” | Có đúng một sản phẩm thật, giá đúng, có retinol | Đạt |
| Chuyển từ retinol sang toner qua PHP | Chỉ trả nhóm toner | Đạt |
| Hồ sơ khô căng/bong tróc, yêu cầu toner | Chỉ trả nhóm toner; giá khớp DB | Đạt |
| Hồ sơ trên, hỏi có nên mua retinol hiện tại | Trả lời cân nhắc bắt đầu sử dụng; không gắn thẻ bán hàng | Đạt |
| Tìm retinol với ngân sách 5.000đ | Danh sách rỗng; PHP không gắn lại các ứng viên ban đầu | Đạt |
| Chuyển từ toner sang hỗ trợ trị mụn | Chỉ trả đúng nhóm mới | Đạt |

## Kiểm tra giao diện trực tiếp

- Kéo thanh cuộn chat từ đầu đến cuối: chat đi từ 0 tới khoảng 106px trong lượt đo đầu; vị trí trang nền giữ nguyên khoảng 170px.
- Cuộn lên đọc tin cũ khi đang chờ phản hồi retinol: sau khi phản hồi tới, chat vẫn ở đầu thay vì nhảy tới cuối.
- Ô nhập 20 dòng nháp: ô nhập cuộn từ 0 tới khoảng 359px, chat giữ ở 0 và trang giữ ở 604px. Nháp đã được xóa, không gửi.
- Kiểm tra ở 390×844 và kích thước trình duyệt thông thường. Thanh lịch sử trên điện thoại mở riêng, có nút đóng, không thu hẹp cột chat.
- Đã gửi hai câu hỏi sản phẩm qua giao diện đang đăng nhập: toner và retinol. Tạo một cuộc hội thoại kiểm tra mới, không xóa lịch sử có sẵn.
- Tài khoản kiểm tra không có lịch sử dài lúc bắt đầu; chưa xác nhận bằng thao tác kéo một danh sách hàng trăm cuộc hội thoại. Danh sách được đặt vùng cuộn riêng với `overscroll-behavior`.

## Vì sao trước đó nói không có sản phẩm?

Lượt tái hiện đầu tiên của câu “kiếm 1 chai retinol” bị phân loại `general`, nên không chạy tìm kiếm. Mặt khác, PHP trước đây giữ ứng viên tìm kiếm khi Flask trả danh sách rỗng, dẫn đến lời tư vấn và thẻ sản phẩm khác nhau. Phiên bản hiện tại đã có quy tắc nhận dạng yêu cầu, đọc lại thông tin MongoDB và chọn sản phẩm theo điều kiện; bộ kiểm tra trên xác nhận các đường đi liên quan.

Có sản phẩm trong kho không tự chứng minh phù hợp với một người cụ thể. Trong thử nghiệm giao diện đã phát hiện câu khẳng định “an toàn cho cả làn da nhạy cảm”; bản vá bổ sung kiểm tra khẳng định này và dùng câu trả lời từ dữ liệu sản phẩm khi không đạt. Đây là regression check có giới hạn, không phải chứng nhận toàn bộ lời tư vấn luôn chính xác.

Chạy lại: `python -X utf8 report/chat-scroll-retrieval/run_checks.py` (cần các dịch vụ local, MongoDB và cấu hình LLM của dự án đang hoạt động).

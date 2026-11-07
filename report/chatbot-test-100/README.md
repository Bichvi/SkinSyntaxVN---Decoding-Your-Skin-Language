# Bộ 100 tình huống nghiệm thu chatbot

- [CASES.md](CASES.md): đọc, copy câu hỏi và chấm thủ công từng case.
- [cases.json](cases.json): payload, điều kiện, tiêu chí máy kiểm được và tiêu chí cần đọc.
- [run_cases.py](run_cases.py): kiểm tra đặc tả hoặc gọi API khi có `--execute`.
- [build_suite.py](build_suite.py): nguồn biên soạn, tái tạo hai file đặc tả.

**Đã chạy và có báo cáo:** [lượt phát hiện 15 lỗi ngày 09/09](run-2026-09-09/RESULTS.md), [kết quả sửa và test lại ngày 10/09](run-2026-09-10/RESULTS.md). File cases.json vẫn là đặc tả, kết quả thực thi được lưu riêng. Không sửa tiêu chí thành dễ hơn chỉ để được kết quả xanh.

## Phân bổ

| Nội dung | Số case |
|---|---:|
| Hiểu ý định, cách trả lời | 10 |
| Nhóm sản phẩm | 12 |
| Thành phần, phủ định | 14 |
| Giá, ngân sách, số lượng | 14 |
| Lịch sử, chuyển chủ đề | 15 |
| Hồ sơ, người được tư vấn | 10 |
| Routine, giỏ hàng | 8 |
| Dữ kiện sản phẩm | 7 |
| Dữ liệu lỗi, phụ thuộc lỗi | 5 |
| Chống bịa, cách ly phiên | 5 |
| **Tổng** | **100** |

83 case có thể gửi đến API dưới dạng guest. 15 case cần fixture và 2 case cần thao tác bằng tài khoản test riêng. Runner không tự thay database, ngắt mạng hay dùng tài khoản thật để chạy những case này.

## Cách chạy

Từ thư mục gốc repository, kiểm tra đủ 100 ID và cấu trúc trước; lệnh này không gọi model:

```powershell
python -X utf8 report/chatbot-test-100/run_cases.py
```

Chạy thử ba case API, dùng model được cấu hình trong dịch vụ đang chạy:

```powershell
python -X utf8 report/chatbot-test-100/run_cases.py --execute --ids CHAT-007,CHAT-008,CHAT-022
```

Gửi toàn bộ 83 case API, ghi 17 case còn lại là cần chuẩn bị:

```powershell
python -X utf8 report/chatbot-test-100/run_cases.py --execute
```

Runner mặc định gọi `http://localhost:5001/api/chat/auto`. **Payload đặc tả là hợp đồng Flask**, không đưa nguyên bộ qua endpoint PHP vì tên trường hồ sơ/history và session khác nhau. Hai case cách ly tài khoản có hướng dẫn thủ công riêng qua PHP.

Nếu có snapshot catalog JSON cùng thời điểm kiểm thử, thêm `--catalog <đường-dẫn-json>`. Chấp nhận một mảng sản phẩm hoặc object `{"products": [...]}`. Mỗi sản phẩm cần `ma_san_pham`/`id`, `ten_san_pham`/`name`, `gia_ban`/`price`, `loai_san_pham`/`category`; kiểm tra thành phần cần `thanh_phan_day_du` hoặc `inci`. Có thể bổ sung `trang_thai`, `stock_status`, `so_luong_ton`. Dùng snapshot chỉ chứa dữ liệu sản phẩm, không xuất người dùng/đơn hàng/khóa truy cập.

Không có snapshot thì đối chiếu ID, tên, giá, nhóm, stock và INCI được đánh dấu cần kiểm tra thủ công. Không xem điều kiện này là tự động đạt. Chỉ kiểm giá trên thẻ chưa đủ chứng minh giá lấy đúng database.

## Chấm kết quả

- `AUTO_FAIL`: một hoặc nhiều điều kiện tự động không đạt.
- `REVIEW_REQUIRED`: phần tự động đã kiểm không phát hiện lỗi; cần đọc câu trả lời và hoàn tất các kiểm tra còn thiếu. Đây **không phải PASS**.
- `SETUP_REQUIRED`: cần fixture/tài khoản test; chưa chạy.
- `EXECUTION_ERROR`: lỗi gọi API hoặc đọc response; không tính là kiểm thử đạt.

Chỉ người kiểm thử kết luận ĐẠT sau khi tất cả tiêu chí của case đạt. Có thể ghi kết luận vào bản sao tài liệu hoặc biên bản riêng; giữ nguyên response JSON để đối chiếu.

Các case có câu trả lời rỗng sản phẩm phải được đọc để phân biệt: không tìm được ứng viên, bị điều kiện loại hết, thiếu INCI, cần hỏi thêm hoặc lỗi dịch vụ. Tên nhóm đúng và HTTP 200 không đủ chứng minh câu trả lời hữu ích.

Không yêu cầu khớp nguyên văn một mẫu trả lời; không đóng băng giá, ID hoặc cam kết luôn tìm được đủ sản phẩm trên catalog thay đổi. Với dữ kiện cần kết quả chính xác (giá bằng ngưỡng, mất INCI, mất kết nối), dùng fixture nêu trong case.

## Kiểm tra bộ công cụ

Đã kiểm tra 100 ID liên tiếp, không trùng tiêu đề, khớp giữa JSON và Markdown.
10 test của runner đã qua bằng dữ liệu giả lập, không gọi chatbot/model thật:

```powershell
python -X utf8 -m unittest discover -s report/chatbot-test-100 -p test_runner.py -v
```

Đây là kiểm thử công cụ chấm, không phải kết quả thực thi 100 tình huống chatbot.

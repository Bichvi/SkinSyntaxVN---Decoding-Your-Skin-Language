# Việc cần bổ sung trước khi đưa tư liệu vào PaperOrchestra

File này KHÔNG nằm trong `raw_materials/` vì là ghi chú cho nhóm, không phải nội dung bài.

## A. Thông tin chỉ nhóm biết (điền tay, không cần chạy gì)

| # | Cần điền | Điền vào |
|---|---|---|
| A1 | Tên tác giả, MSSV, email, giảng viên hướng dẫn, tác giả liên hệ | phần đầu bài (template) |
| A2 | Cấu hình máy đo: CPU, RAM, hệ điều hành, phiên bản Docker, Python, PHP | `experiment_log.md` mục 0 |
| A3 | Nguồn dữ liệu sản phẩm: website nào, thời điểm thu thập, phạm vi sử dụng (repo có file `data_hasaki_v9_complete.csv`, cần xác nhận) | `idea.md` mục 12 |
| A4 | Giữa hai lần đánh giá router (13:57 và 14:07 ngày 2026-11-03), nhóm đã sửa gì để độ chính xác tăng từ 52,5% lên 92,5%? Model nào được dùng lúc đó? | `experiment_log.md` mục 4.1 |
| A5 | Ảnh dùng đánh giá đọc nhãn do ai chụp, có chứa thông tin cá nhân không | `experiment_log.md` mục 2.1 |

## B. Phép đo nên chạy thêm (xếp theo mức ưu tiên)

| # | Phép đo | Vì sao cần | Cách làm |
|---|---|---|---|
| B1 | ~~Bộ 32 câu hỏi có điều kiện trên đồ thị~~ **ĐÃ CHẠY 2026-11-12** | Kết quả ở `experiment_log.md` mục 5 và `report/graph-tools-eval/` | Còn tùy chọn: `--with-llm` để đo 5 câu trả lời thật (tốn quota LLM) |
| B2 | ~~Đối chiếu tay hoạt chất cho model qwen~~ **ĐÃ XONG 2026-11-12**: 42/50 (84%) | Xem `experiment_log.md` mục 2.3b và `report/label-scan-e2e/ac2-qwen-2026-11-12.md` | – |
| B3 | **Tăng số ảnh nhãn** (ví dụ 20–30 ảnh) | 8 ảnh quá ít để kết luận; hội đồng sẽ hỏi | Chụp thêm, chạy lại `report/label-scan-e2e/smoke.py`, mỗi ảnh cách nhau ≥ 65 s vì hạn mức Groq |
| B4 | **Thử xác nhận đơn đồng thời nhiều lần** (ví dụ 100 ca, như Bảng XI của bài mẫu) | Hiện chỉ có 1 lượt E2E bấm đúp | Viết test gọi song song route `chat_order_confirm` với cùng đơn nháp, đếm số đơn tạo ra. **Chạy trên DB test, không chạy trên Atlas thật** (`backend/tests` đang ghi vào DB thật) |
| B5 | Chấm tay khẳng định quá mức cho lần chạy chính sách 2026-10-25 | Bảng 3.2 còn một ô trống | Đọc 5 câu overclaim trong `eval-2026-10-25-r3.json` |
| B6 | Baseline "không hậu kiểm" cho bộ chính sách | Hiện không thể nói hậu kiểm giúp được bao nhiêu so với không có | Tắt `validate_answer`, chạy lại 45 câu, so sánh số liệu lạ và khẳng định quá mức |

Tối thiểu nên làm A1–A5, B1 và B2. B3–B6 giúp bài thuyết phục hơn.

## C. Lưu ý khi chạy PaperOrchestra
- Viết trong prompt hoặc ghi chú rằng bài phải viết **tiếng Việt** và **chỉ dùng số trong `experiment_log.md`**.
- Kiểm tra từng tài liệu tham khảo công cụ tự tìm. Gợi ý trích dẫn trong `idea.md` mục 8 cũng cần mở ra kiểm lại.
- Sau khi có bản `.tex`, so sánh từng con số trong bài với `experiment_log.md` trước khi gửi cô.

# BƯỚC 02 — VIẾT BÀI HƯỚNG DẪN SỬ DỤNG THEO CODE THẬT

## Vai trò
Người viết tài liệu kỹ thuật kiêm kiểm chứng viên. Viết các bài nhóm `guide` trong `support.json`. **Mỗi câu phải truy được về code hoặc giao diện thật.** Đây là bước dễ "bịa" nhất — kỷ luật quan trọng hơn văn hay.

## Luật bất di bất dịch
- **Không viết bước nào không thấy trong code/view hoặc trên trang đang chạy.** Tên nút, tên mục, thứ tự bước phải đúng chữ trên giao diện (chép nguyên văn nhãn nút).
- Không thêm số liệu (ngày, %, phí, giới hạn, số voucher) trừ khi đọc thấy hằng số/điều kiện trong code — khi đó ghi đúng giá trị và nơi lấy (trong handoff, không trong bài).
- Tính năng chỉ cho nhân viên/admin, đang thử nghiệm, hoặc tắt theo cờ → **không viết**, ghi lý do vào handoff.
- Liên hệ chỉ lấy từ `support.json.contact`. Không nhắc giờ hỗ trợ (chưa công bố).
- Không sửa code ứng dụng ở bước này (chỉ `support.json` + test schema). Không chạy script ghi DB. Trả lời tiếng Việt.

## Đọc trước
1. `AGENTS.md`
2. `docs/plans/store-support-handoff.md` (Bước 00 mục 9: route nào hợp lệ; Bước 01: mô hình 4 cấp)
3. Spec mục 4, 4.1
4. `backend/app/content/support.json` — học giọng văn, độ dài, cách viết `summary`/`answer`/`triggers`/`questions` của các bài hiện có.

## Danh sách bài (chỉ viết những bài có route hợp lệ)
| slug | Nội dung | Route nguồn |
|---|---|---|
| `khao-sat-da` | Làm khảo sát da, cập nhật loại da | `khaosat`, `khaosatnhanh`, `luukhaosat`, `capnhat_loaida` |
| `tro-ly-ai` | Dùng Syna, xem/xóa lịch sử chat | `ai_chat_api`, `lichsuchat`, `ai_clear_history` |
| `xung-dot-gio-hang` | Cảnh báo xung đột thành phần trong giỏ | `giohang` |
| `mua-trong-chat` | Mua hàng ngay trong khung chat | `chat_purchase_action` |
| `goi-y-ca-nhan` | Xem gợi ý routine cá nhân | `goiy` |
| `tim-kiem` | Tìm kiếm, gợi ý khi không có kết quả | `timkiem`, `api_smart_search` |
| `flash-sale` | Săn flash sale | `flash_sale` |
| `video-nhan-thuong` | Xem video nhận voucher | `video_watch_*`, `customer_vouchers` |
| `dung-voucher` | Dùng voucher khi thanh toán | `customer_vouchers`, `thanhtoan` |
| `xem-live` | Xem live, thêm vào giỏ từ live | `live`, `api_live_add_to_cart` |
| `danh-gia` | Đánh giá sản phẩm đã mua | `danhgia_sanpham` |
| `hoi-dap-san-pham` | Hỏi đáp trên trang sản phẩm | `hoidap_sanpham` |
| `ho-so` | Hồ sơ, đổi mật khẩu, địa chỉ mặc định | `hoso`, `capnhathoso`, `doimatkhau`, `diachi_macdinh` |
| `he-thong-cua-hang` | Hệ thống cửa hàng | `he_thong_cua_hang` |

## Quy trình cho TỪNG bài
1. Đọc controller của route (điều kiện đăng nhập, kiểm tra quyền, giới hạn, thông báo lỗi) và view (nhãn nút, trường form, thứ tự hiển thị).
2. Nếu dịch vụ chạy được: mở trang thật ở `http://localhost:8080` (tài khoản khách thử nghiệm có trong seed/fixture của dự án — không dùng tài khoản thật, không đặt đơn thật, không nhận voucher thật), chụp ảnh vào `report/store-support-e2e/step-02/<slug>/` để đối chiếu nhãn. Không chạy được → ghi trong handoff, chỉ viết theo code.
3. Lập **bảng truy vết** (để trong handoff, không trong bài): mỗi bước ↔ `file:dòng`.
4. Viết bài:
   - `group: "guide"`, `route_sources`, `updated: "2026-10-21"`.
   - `title` ≤ 60 ký tự; `summary` 1 câu ≤ 140 ký tự.
   - `answer` 2–4 câu: trả lời thẳng, nêu điều kiện quan trọng (ví dụ cần đăng nhập), nhắc link nếu cần — đây là bản soạn sẵn chatbot dùng khi LLM lỗi, phải tự đứng được.
   - `sections`: 2–4 mục, ví dụ "Trước khi bắt đầu", "Các bước", "Nếu gặp lỗi". Bước có chi tiết phụ → item dạng `{"text","details"}` (cấp 4). Mỗi item một hành động.
   - `triggers`: 5–12 cụm **không dấu, chữ thường** khách hay gõ (đồng nghĩa, viết tắt: "voucher", "ma giam gia", "phieu giam gia"…). Không trùng trigger của bài khác trừ khi cố ý; nếu trùng, ghi vào handoff.
   - `questions`: 2–4 câu hỏi tự nhiên có dấu (dùng cho FAQ và chip gợi ý).
   - `action` (tùy chọn) `{label, url}` chỉ khi có route khách mở trực tiếp được.
5. Tự kiểm: đọc lại bài, gạch mọi câu không có dòng truy vết → xóa câu đó.

## Kiểm thử
- Mở rộng `backend/tests/unit/SupportContentTest.php`: mọi bài `guide` có `route_sources` và mỗi route tồn tại trong `frontend/public/index.php` (`case '<route>'`) hoặc `store_assistance.php`; `triggers` đều đã `fold` (bằng chính nó sau `SupportKnowledge::fold`); không chứa số điện thoại/email khác `contact`.
- `php` chạy test → pass. `python -c "import json;json.load(open('backend/app/content/support.json',encoding='utf-8'))"` → hợp lệ.
- Trang `?r=tro_giup&nhom=guide` và từng `?r=tro_giup&bai=<slug>` hiển thị không lỗi.

## Đầu ra
`support.json` cập nhật; mục "Bước 02" trong handoff: bài đã viết, bài loại + lý do, bảng truy vết rút gọn (slug → file chính), trigger trùng, số liệu lấy từ code (nếu có).

## Tiêu chí hoàn thành
Mọi bước trong mọi bài có truy vết; test schema pass; không có số liệu/nhãn bịa.

# Kiểm tra responsive web SkinSyntaxVN

Phạm vi là website PHP trên nhánh `fix/chatbot-rag-production`, từ commit `39a548a`, với các thay đổi cục bộ có sẵn được giữ lại. Bản kiểm tra chạy trên Chrome/Chromium với PHP local, MongoDB riêng cho audit, 14 sản phẩm mẫu và hai tài khoản thử nghiệm. Kích thước trong báo cáo là CSS pixel.

## Lỗi và thay đổi

| Mức | Hiện tượng và nguyên nhân | Thay đổi |
| --- | --- | --- |
| P1 | Launcher Syna ở bên trái. CSS mobile giữ cả `left` và `right` trên widget fixed, trong khi launcher bị ép chiều rộng và nằm ở đầu widget. Một nhánh khác còn ẩn launcher khi hero xuất hiện. | Hợp nhất bố cục mobile của hai widget, neo theo mép phải và safe area, bỏ observer cũ dùng để ẩn launcher. Tại 390 × 844, tọa độ trái của launcher đổi từ khoảng 19 px sang 328 px. |
| P1 | Chat mở rộng sao chép các ràng buộc desktop. Panel, lịch sử và composer tranh chỗ khi chiều cao giảm. | Dùng chiều cao vùng nhìn thấy, giữ composer ngoài vùng cuộn hội thoại, đặt lịch sử vào vùng cuộn riêng trên mobile. Bàn phím chỉ mở khi người dùng chạm ô nhập trên điện thoại. |
| P1 | Banner hẹn giờ xuất hiện trên modal đăng nhập và chặn click. | Bỏ qua lượt hiển thị banner khi modal, drawer hoặc chat đang mở. Giới hạn chiều cao modal xác thực theo viewport động. |
| P1 | Nút lọc nổi đè launcher hoặc CTA danh mục. | Đưa nút lọc vào luồng trang trên mobile/tablet. Giữ drawer và bộ lọc hiện có, tăng vùng chạm nút đóng và dùng chiều cao động. |
| P2 | Ô tìm kiếm dùng hướng flex cột ở viewport nhỏ, nút gửi bị ẩn, nhóm tài khoản đứng trước logo và hai lối tắt có bề rộng khác nhau. | Xếp logo và nhóm thao tác đúng hàng, đưa input và nút tìm vào một hàng, chia đều hai nút LIVE và routine, tăng vùng chạm chính lên 44 px. |
| P2 | Hero mobile bị quy tắc padding desktop có độ ưu tiên cao ghi đè. | Dùng giá trị padding mobile trong khai báo hero chính. Giữ nội dung, ảnh và nhận diện Syna. |
| P2 | Tên dài bị cắt sau hai dòng, giá có thể chen cùng một hàng và nút mua khó bấm ở thẻ hẹp. Một số tab chi tiết nằm ngoài vùng nhìn thấy lúc đầu. | Cho tên và giá xuống dòng, tăng vùng chạm nút thẻ, cho các tab chi tiết tự xuống hàng trên điện thoại. |

## Phạm vi đã rà soát

- **Bố cục dùng chung**: header, thương hiệu, hamburger, tìm kiếm và autocomplete, menu tài khoản, footer, nút về đầu trang, banner chào mừng, modal xác thực, widget Syna, widget hỗ trợ và drawer bộ lọc.
- **Trang công khai đã mở trong Chromium**: trang chủ và các rail sản phẩm, tất cả sản phẩm, danh sách, danh mục, bộ sưu tập lọc theo thương hiệu, tìm kiếm, chi tiết sản phẩm, giỏ hàng, trang cảm ơn, routine, Beauty Swipe, Flash Sale chưa có chiến dịch, phòng LIVE chưa có phiên, mạng lưới cửa hàng và hỗ trợ.
- **Khách hàng đã mở trong Chromium**: đăng nhập, đăng ký, quên mật khẩu, hồ sơ tài khoản, yêu thích, lịch sử chat hỗ trợ, khảo sát da, cập nhật da nhanh, routine theo hồ sơ và checkout với sản phẩm đã chọn từ giỏ. Ở 360 px, hai panel đăng ký và quên mật khẩu đều giữ chiều rộng viewport và báo email sai định dạng. Panel đăng ký còn 76 px nội dung cuộn được.
- **Quản trị đã mở ở 360 px**: dashboard, danh sách/thêm/sửa sản phẩm, kho lô, nhãn AI, vận hành tìm kiếm, đơn hàng, Flash Sale và form tạo, voucher, Live Commerce, danh mục, người dùng, đánh giá, hỏi đáp, phản hồi và AI Idol. Các màn nhân viên đã mở gồm dashboard, sản phẩm, đánh giá, chat hỗ trợ. Kiểm tra đã xét vùng cuộn cục bộ của bảng. Live Studio chuyển về quản lý live khi thiếu phiên.
- **Rà đường dẫn và template**: các trang pháp lý, trang lỗi, phần thưởng, in đơn, video sản phẩm và các trạng thái phụ thuộc vai trò hoặc dữ liệu. Route không hợp lệ trả 404. View phần thưởng chưa có bằng chứng được phục vụ qua route `phan_thuong` trên bản local này.

## Kiểm chứng responsive

`node scripts/e2e/responsive-storefront.e2e.cjs` dùng Playwright và Chrome cài sẵn. Kịch bản đo tràn ngang trang danh mục, vị trí launcher, khung chat và composer, chạy phóng to/thu nhỏ/đóng, rồi mở và đóng bộ lọc cùng hamburger ở các kích thước có các điều khiển này. Các viewport điện thoại có mô phỏng giảm `VisualViewport` khi nhập. Sản phẩm mẫu được dùng để kiểm tra tab hỏi đáp ở 360 px.

| Viewport | Kết quả |
| --- | --- |
| 375 × 667 | PASS |
| 390 × 844 | PASS |
| 393 × 852 | PASS |
| 440 × 956 | PASS |
| 360 × 800 | PASS |
| 393 × 873 | PASS |
| 768 × 1024 | PASS |
| 1366 × 768 | PASS |
| 1920 × 1080 | PASS |
| 667 × 375, ngang | PASS |

Các lượt kiểm tra riêng qua giao diện đã đi qua đăng nhập, mở/phóng to/đóng chat hỗ trợ, autocomplete và gửi tìm kiếm, tên dài trên thẻ, thêm giỏ và vào checkout, nút cuộn carousel và vuốt carousel bằng touch mô phỏng. Chạy `php -l` trên các PHP thay đổi, `node --check` trên các JS thay đổi, `node backend/tests/chat_purchase_client_test.cjs` (6 kiểm tra) và `git diff --check` đều thoát mã 0. Frontend này được PHP render trực tiếp.

Ảnh chụp nằm trong `D:/xampp/htdocs/fe/output/playwright/`. Các ảnh `responsive-after-360.png`, `responsive-after-390.png`, `responsive-after-393.png`, `responsive-after-1366.png` là trang chủ cùng dữ liệu local. `responsive-chat-expanded-390.png` là panel đang mở rộng. Ảnh `responsive-before-390.png` có cảnh báo session PHP của môi trường lúc tái hiện, vì vậy chỉ dùng làm bằng chứng cho vị trí launcher ban đầu.

## File thay đổi

- `frontend/public/assets/css/style.css`, `frontend/public/assets/css/catalog-filter.css`
- `frontend/public/assets/js/chat-viewport.js`, `frontend/public/assets/js/welcome-popup.js`, `frontend/public/assets/js/syna-mascot.js`
- `frontend/views/components/ai_chat_widget.php`, `frontend/views/components/support_chat_widget.php`
- `frontend/views/layouts/header.php`, `frontend/views/layouts/footer.php`, `frontend/views/chitiet.php`
- `scripts/e2e/responsive-storefront.e2e.cjs`, báo cáo này

## Giới hạn và kiểm tra trên bản deploy

Chromium mô phỏng hình học bàn phím và touch. Safari iOS, safe area trên phần cứng thật, LiveKit đang phát, gửi tin tới dịch vụ AI, email/OTP, trả tiền, đơn hàng đã có lịch sử, chiến dịch có dữ liệu, video có thưởng, quyền nhân viên riêng và các thao tác ghi của quản trị vẫn cần kiểm chứng trong môi trường phù hợp. GitNexus CLI bị chặn khi tải package (EACCES). Phạm vi ảnh hưởng đã được dò qua các layout include, selector và script gọi trực tiếp. Impeccable báo các cảnh báo từ typography, đường viền và hiệu ứng hiện hữu, được giữ theo yêu cầu nhận diện.

Sau khi phát hành các file giao diện, mở website trong Chrome DevTools, bật Device Toolbar rồi tải lại với cache tắt. Kiểm tra Syna trên trang chủ và danh mục ở 360, 390 và 393 px. Thử menu, tìm và lọc sản phẩm, thêm giỏ và đến bước checkout bằng tài khoản thử. Kiểm tra lại 768 px và hai kích thước desktop trong bảng. Trên iPhone và Android thật, thử xoay ngang, bật bàn phím trong chat và modal, rồi đóng/mở lại panel để kiểm tra mép màn hình và khả năng chạm.

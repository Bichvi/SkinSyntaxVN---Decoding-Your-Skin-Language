# Test Case Khách hàng / Khách vãng lai - SkinSyntaxVN

Tài liệu được lập dựa trên `USECASE_SPEC.md`, `REPORT_UI_MAPPING.md`, `DATABASE_ANALYSIS.md` và `REPORT_CODE_ANALYSIS.md`. Phạm vi chỉ mô tả kiểm thử, không sửa mã nguồn.

## 1. Danh sách testcase

### TC-CUS-001 - Đăng ký thành công

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-001 |
| Chức năng | Đăng ký tài khoản |
| Use case liên quan | UC-G-01 |
| Mục tiêu kiểm thử | Kiểm tra khách vãng lai có thể tạo tài khoản mới với email chưa tồn tại. |
| Tiền điều kiện | Website hoạt động; MongoDB kết nối được; email kiểm thử chưa có trong `nguoidung`/`khach_hang`. |
| Dữ liệu kiểm thử | Họ tên: Nguyễn Test; Email: `test_new@example.com`; Mật khẩu hợp lệ; OTP/captcha hợp lệ nếu hệ thống yêu cầu. |
| Các bước thực hiện | 1. Mở `index.php?r=dangky`. 2. Nhập thông tin đăng ký. 3. Thực hiện OTP/captcha nếu có. 4. Bấm nút đăng ký/gửi form. |
| Kết quả mong đợi | Hệ thống tạo tài khoản mới; có bản ghi tương ứng trong `nguoidung`/`khach_hang`; giao diện chuyển sang bước đăng nhập hoặc trang tiếp theo theo luồng hiện tại. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route xử lý chính: `index.php?r=xulydangky`. |

### TC-CUS-002 - Đăng ký email đã tồn tại

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-002 |
| Chức năng | Đăng ký tài khoản |
| Use case liên quan | UC-G-01 |
| Mục tiêu kiểm thử | Kiểm tra hệ thống không cho đăng ký trùng email. |
| Tiền điều kiện | Email kiểm thử đã tồn tại trong tài khoản khách hàng. |
| Dữ liệu kiểm thử | Email đã có: `existing@example.com`; các thông tin khác hợp lệ. |
| Các bước thực hiện | 1. Mở `index.php?r=dangky`. 2. Nhập email đã tồn tại và thông tin hợp lệ. 3. Bấm đăng ký. |
| Kết quả mong đợi | Hệ thống không tạo tài khoản mới; hiển thị thông báo email đã tồn tại hoặc dữ liệu không hợp lệ; MongoDB không phát sinh khách hàng trùng email. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Nếu hệ thống dùng OTP trước khi kiểm tra email, cần hoàn tất bước OTP theo UI. |

### TC-CUS-003 - Đăng nhập thành công

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-003 |
| Chức năng | Đăng nhập |
| Use case liên quan | UC-G-02 |
| Mục tiêu kiểm thử | Kiểm tra người dùng đăng nhập với email/mật khẩu đúng. |
| Tiền điều kiện | Tài khoản khách hàng đã tồn tại và đang hoạt động. |
| Dữ liệu kiểm thử | Email hợp lệ; mật khẩu đúng. |
| Các bước thực hiện | 1. Mở `index.php?r=dangnhap`. 2. Nhập email và mật khẩu. 3. Bấm đăng nhập. |
| Kết quả mong đợi | Hệ thống tạo session người dùng; header hiển thị trạng thái đã đăng nhập; người dùng có thể vào `index.php?r=hoso`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route xử lý: `index.php?r=xulydangnhap`. |

### TC-CUS-004 - Đăng nhập sai mật khẩu

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-004 |
| Chức năng | Đăng nhập |
| Use case liên quan | UC-G-02 |
| Mục tiêu kiểm thử | Kiểm tra hệ thống từ chối thông tin đăng nhập sai. |
| Tiền điều kiện | Email đã tồn tại trong hệ thống. |
| Dữ liệu kiểm thử | Email hợp lệ; mật khẩu sai. |
| Các bước thực hiện | 1. Mở `index.php?r=dangnhap`. 2. Nhập email đúng và mật khẩu sai. 3. Bấm đăng nhập. |
| Kết quả mong đợi | Hệ thống không tạo session đăng nhập; hiển thị thông báo lỗi; người dùng vẫn ở màn hình đăng nhập hoặc được yêu cầu nhập lại. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Có thể kiểm tra bằng cách truy cập `index.php?r=hoso`, hệ thống phải yêu cầu đăng nhập. |

### TC-CUS-005 - Xem danh sách sản phẩm

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-005 |
| Chức năng | Danh sách sản phẩm |
| Use case liên quan | UC-G-04 |
| Mục tiêu kiểm thử | Kiểm tra trang tất cả sản phẩm hiển thị danh sách và phân trang. |
| Tiền điều kiện | Collection `san_pham` có dữ liệu. |
| Dữ liệu kiểm thử | Không bắt buộc. |
| Các bước thực hiện | 1. Mở `index.php?r=tatca`. 2. Quan sát danh sách sản phẩm. 3. Chuyển trang nếu có phân trang. |
| Kết quả mong đợi | View `tatca.php` hiển thị card sản phẩm, giá, ảnh, nút xem chi tiết/thêm giỏ; phân trang hoạt động nếu số sản phẩm vượt giới hạn. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Model liên quan: `SanPham::paginate()`. |

### TC-CUS-006 - Tìm kiếm sản phẩm theo từ khóa

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-006 |
| Chức năng | Tìm kiếm sản phẩm |
| Use case liên quan | UC-G-04 |
| Mục tiêu kiểm thử | Kiểm tra filter keyword trả về sản phẩm phù hợp. |
| Tiền điều kiện | Có sản phẩm chứa từ khóa trong tên, thương hiệu, danh mục hoặc mô tả. |
| Dữ liệu kiểm thử | Keyword: `serum` hoặc tên/thương hiệu có thật trong MongoDB. |
| Các bước thực hiện | 1. Mở `index.php?r=tatca`. 2. Nhập từ khóa vào ô tìm kiếm. 3. Bấm tìm kiếm/lọc. |
| Kết quả mong đợi | Danh sách chỉ hiển thị sản phẩm có liên quan đến từ khóa; URL có query tương ứng; MongoDB query không làm trang lỗi. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Có thể kiểm tra thêm API `index.php?r=live_search`. |

### TC-CUS-007 - Lọc sản phẩm theo giá

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-007 |
| Chức năng | Lọc sản phẩm |
| Use case liên quan | UC-G-04 |
| Mục tiêu kiểm thử | Kiểm tra hệ thống chỉ hiển thị sản phẩm trong khoảng giá. |
| Tiền điều kiện | Có sản phẩm trong nhiều khoảng giá khác nhau. |
| Dữ liệu kiểm thử | Giá từ: `100000`; Giá đến: `300000`. |
| Các bước thực hiện | 1. Mở `index.php?r=tatca`. 2. Nhập giá từ và giá đến. 3. Bấm lọc. 4. Quan sát giá bán các sản phẩm. |
| Kết quả mong đợi | Tất cả sản phẩm hiển thị có `gia_ban` nằm trong khoảng đã nhập; nếu không có kết quả, hiển thị trạng thái rỗng phù hợp. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Có thể đối chiếu field `gia_ban` trong collection `san_pham`. |

### TC-CUS-008 - Xem chi tiết sản phẩm

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-008 |
| Chức năng | Chi tiết sản phẩm |
| Use case liên quan | UC-G-05 |
| Mục tiêu kiểm thử | Kiểm tra trang chi tiết hiển thị đúng thông tin sản phẩm và các tab. |
| Tiền điều kiện | Có sản phẩm hợp lệ trong `san_pham`. |
| Dữ liệu kiểm thử | `ma_san_pham` có thật, ví dụ lấy từ card sản phẩm. |
| Các bước thực hiện | 1. Mở `index.php?r=tatca`. 2. Chọn một sản phẩm. 3. Bấm “Xem chi tiết”. 4. Bấm lần lượt các tab Mô tả, Thông số, Thành phần, HDSD, Đánh giá, Hỏi đáp. |
| Kết quả mong đợi | URL dạng `index.php?r=chitiet&id=...`; ảnh, tên, giá, tồn kho và tab hiển thị đúng; không có tab bị lỗi hoặc không bấm được. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | View: `backend/app/views/chitiet.php`. |

### TC-CUS-009 - Thêm sản phẩm còn hàng vào giỏ

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-009 |
| Chức năng | Giỏ hàng |
| Use case liên quan | UC-C-05 |
| Mục tiêu kiểm thử | Kiểm tra sản phẩm còn tồn kho được thêm vào giỏ bằng AJAX/session. |
| Tiền điều kiện | Sản phẩm có `so_luong_ton_kho > 0` và `trang_thai_kho != het_hang`. |
| Dữ liệu kiểm thử | Mã sản phẩm còn hàng; số lượng: `1`. |
| Các bước thực hiện | 1. Mở trang chi tiết sản phẩm còn hàng. 2. Chọn số lượng 1. 3. Bấm “Thêm giỏ hàng”. 4. Quan sát toast/thông báo và icon giỏ hàng. 5. Mở `index.php?r=giohang`. |
| Kết quả mong đợi | Hệ thống báo thêm thành công; không chuyển trang nếu AJAX hoạt động; giỏ hàng có sản phẩm vừa thêm; session `gio_hang` được cập nhật. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route: `index.php?r=them_gio_hang_ajax` hoặc alias `themgiohang`, `them_gio_hang`. |

### TC-CUS-010 - Không cho thêm sản phẩm hết hàng

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-010 |
| Chức năng | Giỏ hàng và tồn kho |
| Use case liên quan | UC-C-05 |
| Mục tiêu kiểm thử | Kiểm tra hệ thống chặn sản phẩm hết hàng ở cả UI và backend. |
| Tiền điều kiện | Có sản phẩm `so_luong_ton_kho <= 0` hoặc `trang_thai_kho = het_hang`. |
| Dữ liệu kiểm thử | Mã sản phẩm hết hàng. |
| Các bước thực hiện | 1. Mở trang chi tiết sản phẩm hết hàng. 2. Quan sát nút thêm giỏ. 3. Nếu nút bị disable, thử gọi trực tiếp route thêm giỏ bằng request POST/AJAX. |
| Kết quả mong đợi | UI hiển thị “Tạm hết hàng” hoặc disable nút; backend trả thông báo “Sản phẩm hiện đã tạm hết hàng.”; sản phẩm không xuất hiện trong giỏ. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Có thể kiểm tra field tồn kho trong `san_pham`. |

### TC-CUS-011 - Cập nhật số lượng giỏ hàng

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-011 |
| Chức năng | Cập nhật giỏ hàng |
| Use case liên quan | UC-C-06 |
| Mục tiêu kiểm thử | Kiểm tra người dùng có thể thay đổi số lượng sản phẩm trong giỏ trong giới hạn tồn kho. |
| Tiền điều kiện | Giỏ hàng có ít nhất một sản phẩm còn hàng. |
| Dữ liệu kiểm thử | Sản phẩm A; số lượng mới: `2`. |
| Các bước thực hiện | 1. Mở `index.php?r=giohang`. 2. Tăng/nhập số lượng sản phẩm lên 2. 3. Thực hiện thao tác cập nhật theo UI. 4. Quan sát tổng tiền. |
| Kết quả mong đợi | Số lượng và tổng tiền thay đổi đúng; nếu số lượng vượt tồn kho thì hệ thống cảnh báo và không lưu số lượng vượt. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Giỏ hàng hiện lưu chủ yếu trong session `gio_hang`; endpoint cập nhật chi tiết cần kiểm thử theo UI hiện tại. |

### TC-CUS-012 - Đặt hàng COD

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-012 |
| Chức năng | Đặt hàng và thanh toán |
| Use case liên quan | UC-C-07 |
| Mục tiêu kiểm thử | Kiểm tra khách hàng tạo đơn thanh toán COD thành công. |
| Tiền điều kiện | Khách hàng đã đăng nhập; giỏ hàng có sản phẩm còn hàng; thông tin nhận hàng hợp lệ. |
| Dữ liệu kiểm thử | Sản phẩm còn hàng; phương thức thanh toán COD; địa chỉ hợp lệ. |
| Các bước thực hiện | 1. Thêm sản phẩm vào giỏ. 2. Mở `index.php?r=giohang`. 3. Bấm thanh toán. 4. Ở `index.php?r=thanhtoan`, nhập/chọn địa chỉ. 5. Chọn COD. 6. Bấm đặt hàng. |
| Kết quả mong đợi | Hệ thống chuyển đến `index.php?r=camon`; collection `hoa_don` có đơn mới trạng thái Chờ xử lý/pending; `chi_tiet_hoa_don` có sản phẩm; tồn kho sản phẩm giảm đúng số lượng. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route xử lý đặt hàng: `index.php?r=xulydathang`. |

### TC-CUS-013 - Đặt hàng QR/chuyển khoản

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-013 |
| Chức năng | Đặt hàng và thanh toán |
| Use case liên quan | UC-C-07 |
| Mục tiêu kiểm thử | Kiểm tra tạo đơn với phương thức QR/chuyển khoản và hiển thị thông tin thanh toán. |
| Tiền điều kiện | Khách hàng đã đăng nhập; giỏ hàng hợp lệ; cấu hình QR/chuyển khoản bật. |
| Dữ liệu kiểm thử | Sản phẩm còn hàng; phương thức QR/chuyển khoản. |
| Các bước thực hiện | 1. Thêm sản phẩm vào giỏ. 2. Mở `index.php?r=thanhtoan`. 3. Chọn phương thức QR/chuyển khoản. 4. Kiểm tra thông tin QR/ngân hàng hiển thị. 5. Bấm đặt hàng. |
| Kết quả mong đợi | Đơn được tạo; trang `camon` hiển thị thông tin thanh toán/QR nếu có; `hoa_don` lưu phương thức thanh toán và trạng thái thanh toán phù hợp; tồn kho giảm sau khi đơn tạo thành công. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Có thể kiểm tra `payment_autocheck` và `payment_webhook` nếu môi trường thanh toán được cấu hình. |

### TC-CUS-014 - Theo dõi đơn hàng

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-014 |
| Chức năng | Lịch sử đơn hàng |
| Use case liên quan | UC-C-08 |
| Mục tiêu kiểm thử | Kiểm tra khách hàng xem được đơn đã đặt trong hồ sơ cá nhân. |
| Tiền điều kiện | Khách hàng đã đăng nhập và có ít nhất một đơn hàng. |
| Dữ liệu kiểm thử | Tài khoản có đơn trong `hoa_don`. |
| Các bước thực hiện | 1. Đăng nhập. 2. Mở `index.php?r=hoso`. 3. Tìm khu vực lịch sử đơn hàng. 4. Đối chiếu đơn vừa đặt. |
| Kết quả mong đợi | Hồ sơ hiển thị mã đơn, trạng thái, thời gian và sản phẩm/tổng tiền nếu view hỗ trợ; dữ liệu khớp `hoa_don` và `chi_tiet_hoa_don`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Model liên quan: `TaiKhoan::getOrderHistory()`. |

### TC-CUS-015 - Hủy đơn hàng khi còn được phép

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-015 |
| Chức năng | Hủy đơn hàng |
| Use case liên quan | UC-C-09 |
| Mục tiêu kiểm thử | Kiểm tra khách hàng hủy được đơn ở trạng thái cho phép và hệ thống hoàn kho đúng. |
| Tiền điều kiện | Khách hàng đã đăng nhập; có đơn thuộc tài khoản ở trạng thái còn được hủy. |
| Dữ liệu kiểm thử | Mã hóa đơn trạng thái Chờ xử lý hoặc trạng thái được phép hủy theo UI. |
| Các bước thực hiện | 1. Mở `index.php?r=hoso`. 2. Tìm đơn có nút hủy. 3. Bấm hủy đơn và xác nhận nếu có. |
| Kết quả mong đợi | Đơn chuyển sang Đã hủy/cancelled; `hoa_don.da_hoan_kho` được đánh dấu nếu hoàn kho; `san_pham.so_luong_ton_kho` tăng lại đúng số lượng; giao diện không còn cho hủy lại lần hai. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route: `index.php?r=huydonhang`. |

### TC-CUS-016 - Đánh giá sản phẩm đã mua

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-016 |
| Chức năng | Đánh giá sản phẩm |
| Use case liên quan | UC-C-10 |
| Mục tiêu kiểm thử | Kiểm tra khách hàng đã mua và đơn hoàn thành có thể gửi đánh giá. |
| Tiền điều kiện | Khách hàng đăng nhập; đã mua sản phẩm; đơn có trạng thái Hoàn thành/completed; chưa đánh giá sản phẩm đó. |
| Dữ liệu kiểm thử | Số sao: 5; Nội dung: “Sản phẩm phù hợp, giao nhanh.”; ảnh review nếu muốn kiểm thử upload. |
| Các bước thực hiện | 1. Mở trang chi tiết sản phẩm đã mua. 2. Chọn tab Đánh giá. 3. Nhập số sao và nội dung. 4. Tải ảnh nếu kiểm thử upload. 5. Bấm gửi đánh giá. |
| Kết quả mong đợi | Review mới xuất hiện trong tab Đánh giá; document mới được lưu trong `danh_gia_san_pham`; thông báo `review` được tạo trong `thong_bao`; tổng lượt đánh giá không làm mất rating crawl cũ. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route gửi: `index.php?r=guidanhgia`. |

### TC-CUS-017 - Không cho đánh giá nếu chưa mua

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-017 |
| Chức năng | Đánh giá sản phẩm |
| Use case liên quan | UC-C-10 |
| Mục tiêu kiểm thử | Kiểm tra hệ thống không cho khách hàng đánh giá sản phẩm chưa mua hoặc đơn chưa hoàn thành. |
| Tiền điều kiện | Khách hàng đăng nhập nhưng chưa có đơn hoàn thành chứa sản phẩm. |
| Dữ liệu kiểm thử | Mã sản phẩm chưa từng mua. |
| Các bước thực hiện | 1. Đăng nhập bằng tài khoản chưa mua sản phẩm. 2. Mở `index.php?r=chitiet&id=...`. 3. Chọn tab Đánh giá. |
| Kết quả mong đợi | Hệ thống không hiển thị form đánh giá; hiển thị thông báo “Bạn chỉ có thể đánh giá sản phẩm sau khi đã mua sản phẩm này.” hoặc thông báo tương đương; không tạo document review. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Điều kiện kiểm tra dựa trên `hoa_don` và `chi_tiet_hoa_don`. |

### TC-CUS-018 - Gửi câu hỏi sản phẩm

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-018 |
| Chức năng | Hỏi đáp sản phẩm |
| Use case liên quan | UC-C-11 |
| Mục tiêu kiểm thử | Kiểm tra khách hàng gửi được câu hỏi cho sản phẩm. |
| Tiền điều kiện | Khách hàng đã đăng nhập; sản phẩm tồn tại. |
| Dữ liệu kiểm thử | Câu hỏi: “Sản phẩm này dùng cho da dầu được không?” |
| Các bước thực hiện | 1. Mở trang chi tiết sản phẩm. 2. Chọn tab Hỏi đáp. 3. Nhập câu hỏi. 4. Bấm Gửi. |
| Kết quả mong đợi | Câu hỏi được lưu vào `hoi_dap_san_pham`; tab Hỏi đáp hiển thị câu hỏi hoặc hiển thị sau khi reload; `thong_bao` có thông báo loại `hoi_dap_moi`/`question` cho admin/staff. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route gửi: `index.php?r=guicauhoi`. |

### TC-CUS-019 - Chat với AI tư vấn sản phẩm

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-019 |
| Chức năng | Chatbot AI |
| Use case liên quan | UC-C-04 |
| Mục tiêu kiểm thử | Kiểm tra chatbot trả lời câu hỏi tư vấn sản phẩm và có thể gợi ý sản phẩm liên quan. |
| Tiền điều kiện | Flask chatbot đang chạy; `AI_CHAT_ENDPOINT` trỏ đến `http://127.0.0.1:5001/api/chat`; ChromaDB chatbot sẵn sàng. |
| Dữ liệu kiểm thử | Câu hỏi: “Tôi da dầu mụn, nên dùng sữa rửa mặt nào?” |
| Các bước thực hiện | 1. Mở website. 2. Mở AI chat widget. 3. Nhập câu hỏi tư vấn sản phẩm. 4. Gửi tin nhắn. |
| Kết quả mong đợi | Widget hiển thị câu trả lời AI; nếu có sản phẩm phù hợp, hiển thị danh sách sản phẩm kèm link/ảnh; không xuất hiện traceback kỹ thuật. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | PHP route: `index.php?r=ai_chat_assistant`; Flask route: `/api/chat`. |

### TC-CUS-020 - Chat với AI câu hỏi kiến thức mỹ phẩm

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-020 |
| Chức năng | Chatbot AI |
| Use case liên quan | UC-C-04 |
| Mục tiêu kiểm thử | Kiểm tra chatbot xử lý câu hỏi kiến thức mỹ phẩm không nhất thiết gắn trực tiếp sản phẩm. |
| Tiền điều kiện | Flask chatbot đang chạy. |
| Dữ liệu kiểm thử | Câu hỏi: “BHA và AHA khác nhau như thế nào?” |
| Các bước thực hiện | 1. Mở AI chat widget. 2. Nhập câu hỏi kiến thức mỹ phẩm. 3. Bấm gửi. |
| Kết quả mong đợi | AI trả lời nội dung kiến thức mỹ phẩm; intent router/RAG xử lý phù hợp; UI không hiển thị lỗi kết nối nếu service hoạt động. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Chatbot dùng LangChain + ChromaDB, không phải LlamaIndex. |

### TC-CUS-021 - Xem `/goiy` khi chưa đăng nhập

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-021 |
| Chức năng | Gợi ý công khai |
| Use case liên quan | UC-G-06 |
| Mục tiêu kiểm thử | Kiểm tra khách vãng lai xem được trang khám phá sản phẩm mà không gọi AI cá nhân hóa. |
| Tiền điều kiện | Người dùng chưa đăng nhập. |
| Dữ liệu kiểm thử | Không bắt buộc. |
| Các bước thực hiện | 1. Đăng xuất nếu đang đăng nhập. 2. Mở `index.php?r=goiy`. 3. Quan sát form lọc và các khối sản phẩm. |
| Kết quả mong đợi | Trang hiển thị public discovery gồm form từ khóa/danh mục/thương hiệu/giá/sort và các nhóm sản phẩm; không hiển thị ô nhập AI cá nhân hóa; không gọi Flask LlamaIndex. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Model: `SanPham::publicRecommendationSections()`. |

### TC-CUS-022 - Lọc `/goiy` theo từ khóa và giá

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-022 |
| Chức năng | Public discovery `/goiy` |
| Use case liên quan | UC-G-06 |
| Mục tiêu kiểm thử | Kiểm tra filter áp dụng cho từng khối sản phẩm trên `/goiy`. |
| Tiền điều kiện | Chưa đăng nhập hoặc đăng nhập nhưng chưa có hồ sơ da hợp lệ; MongoDB có sản phẩm phù hợp. |
| Dữ liệu kiểm thử | Keyword: `serum`; Giá từ: `100000`; Giá đến: `300000`. |
| Các bước thực hiện | 1. Mở `index.php?r=goiy`. 2. Nhập keyword và khoảng giá. 3. Bấm lọc. 4. Kiểm tra từng khối sản phẩm. |
| Kết quả mong đợi | Các khối vẫn giữ riêng biệt; mỗi sản phẩm hiển thị phù hợp keyword và khoảng giá; khối không có sản phẩm hiển thị “Chưa có sản phẩm phù hợp trong nhóm này.” hoặc thông báo tương đương. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Không gộp tất cả kết quả thành một danh sách chung. |

### TC-CUS-023 - Xem gợi ý cá nhân hóa khi đã khảo sát

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-023 |
| Chức năng | Gợi ý cá nhân hóa LlamaIndex |
| Use case liên quan | UC-C-03 |
| Mục tiêu kiểm thử | Kiểm tra khách hàng có hồ sơ da nhận được gợi ý cá nhân hóa tại `/goiy`. |
| Tiền điều kiện | Đã đăng nhập; tài khoản có hồ sơ da hợp lệ; `rcm_flask.py` chạy port 5002; index `database/recommendation_index` đã build; Gemini API key hợp lệ. |
| Dữ liệu kiểm thử | Tài khoản có `loai_da` hoặc `van_de_da` hoặc `ngan_sach`. |
| Các bước thực hiện | 1. Đăng nhập bằng tài khoản đã khảo sát. 2. Mở `index.php?r=goiy`. 3. Quan sát phần gợi ý cá nhân hóa. |
| Kết quả mong đợi | PHP gọi Flask `/api/recommend/llamaindex`; trang hiển thị “Gợi ý dành riêng cho bạn”, `answer_text` và danh sách product cards; response Flask có `source = llamaindex`; sản phẩm tồn tại trong `san_pham`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Nếu service lỗi, testcase chuyển sang kiểm tra ngoại lệ với thông báo thân thiện. |

### TC-CUS-024 - Truy cập `/goiy` khi đăng nhập nhưng chưa khảo sát

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-CUS-024 |
| Chức năng | Gợi ý công khai cho khách chưa có hồ sơ da |
| Use case liên quan | UC-C-02, UC-C-03 |
| Mục tiêu kiểm thử | Kiểm tra hệ thống không gọi AI cá nhân hóa khi khách hàng chưa có hồ sơ da hợp lệ. |
| Tiền điều kiện | Đã đăng nhập bằng tài khoản chưa khảo sát/chưa có field hồ sơ da hợp lệ. |
| Dữ liệu kiểm thử | Tài khoản không có `loai_da`, `van_de_da`, `ngan_sach`, `muc_tieu_cham_soc_da`. |
| Các bước thực hiện | 1. Đăng nhập tài khoản chưa khảo sát. 2. Mở `index.php?r=goiy`. 3. Quan sát banner và danh sách sản phẩm. 4. Bấm nút “Khảo sát ngay” nếu có. |
| Kết quả mong đợi | Trang không gọi LlamaIndex; hiển thị banner yêu cầu khảo sát; bên dưới vẫn có public discovery; nút khảo sát dẫn đến `index.php?r=khaosat`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Đây là nhánh quan trọng để tránh báo lỗi cá nhân hóa giả khi thiếu profile. |

## 2. Ma trận Use Case - Test Case

| Use case | Testcase |
| --- | --- |
| UC-G-01 | TC-CUS-001, TC-CUS-002 |
| UC-G-02 | TC-CUS-003, TC-CUS-004 |
| UC-G-03 | TC-CUS-005 |
| UC-G-04 | TC-CUS-005, TC-CUS-006, TC-CUS-007 |
| UC-G-05 | TC-CUS-008 |
| UC-G-06 | TC-CUS-021, TC-CUS-022 |
| UC-G-07 | Có thể kiểm thử bổ sung bằng route `product_collection`; chưa tách testcase riêng trong bộ tối thiểu khách hàng. |
| UC-C-01 | TC-CUS-014 |
| UC-C-02 | TC-CUS-024 |
| UC-C-03 | TC-CUS-023, TC-CUS-024 |
| UC-C-04 | TC-CUS-019, TC-CUS-020 |
| UC-C-05 | TC-CUS-009, TC-CUS-010 |
| UC-C-06 | TC-CUS-011 |
| UC-C-07 | TC-CUS-012, TC-CUS-013 |
| UC-C-08 | TC-CUS-014 |
| UC-C-09 | TC-CUS-015 |
| UC-C-10 | TC-CUS-016, TC-CUS-017 |
| UC-C-11 | TC-CUS-018 |

## 3. Danh sách testcase ưu tiên cao

| Mức ưu tiên | Testcase | Lý do |
| --- | --- | --- |
| Cao | TC-CUS-003 | Đăng nhập là điều kiện cho phần lớn chức năng khách hàng. |
| Cao | TC-CUS-009 | Thêm giỏ hàng ảnh hưởng trực tiếp đến mua hàng. |
| Cao | TC-CUS-010 | Chặn sản phẩm hết hàng là ràng buộc tồn kho quan trọng. |
| Cao | TC-CUS-012 | Đặt hàng COD là luồng doanh thu cốt lõi. |
| Cao | TC-CUS-013 | Thanh toán QR/chuyển khoản liên quan trạng thái thanh toán. |
| Cao | TC-CUS-016 | Đánh giá sau mua ảnh hưởng dữ liệu review và thông báo staff. |
| Cao | TC-CUS-018 | Hỏi đáp sản phẩm tạo thông báo cho admin/staff. |
| Cao | TC-CUS-023 | Gợi ý cá nhân hóa là chức năng AI trọng tâm của đề tài. |
| Cao | TC-CUS-024 | Đảm bảo không gọi AI khi chưa đủ hồ sơ da. |

# Test Case Nhân viên / Quản trị viên - SkinSyntaxVN

Tài liệu được lập dựa trên `USECASE_SPEC.md`, `REPORT_UI_MAPPING.md`, `DATABASE_ANALYSIS.md` và `REPORT_CODE_ANALYSIS.md`. Phạm vi chỉ mô tả kiểm thử, không sửa mã nguồn.

## 1. Testcase Nhân viên

### TC-STF-001 - Đăng nhập nhân viên

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-STF-001 |
| Chức năng | Đăng nhập nhân viên |
| Use case liên quan | UC-S-01, UC-S-05 |
| Mục tiêu kiểm thử | Kiểm tra tài khoản nhân viên đăng nhập và truy cập được khu vực staff. |
| Tiền điều kiện | Tài khoản nhân viên tồn tại trong `nhan_vien`/`nguoidung` và có quyền staff. |
| Dữ liệu kiểm thử | Email nhân viên; mật khẩu đúng. |
| Các bước thực hiện | 1. Mở `index.php?r=dangnhap`. 2. Nhập email/mật khẩu nhân viên. 3. Bấm đăng nhập. 4. Truy cập `index.php?r=staff_dashboard`. |
| Kết quả mong đợi | Đăng nhập thành công; giao diện staff/admin layout hiển thị; nhân viên xem được dashboard theo quyền. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Nếu phân quyền dùng `ma_vai_tro`, cần kiểm tra role trong MongoDB. |

### TC-STF-002 - Xem danh sách đơn hàng

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-STF-002 |
| Chức năng | Danh sách đơn hàng staff |
| Use case liên quan | UC-S-01 |
| Mục tiêu kiểm thử | Kiểm tra nhân viên xem được danh sách đơn hàng cần xử lý. |
| Tiền điều kiện | Nhân viên đã đăng nhập; collection `hoa_don` có dữ liệu. |
| Dữ liệu kiểm thử | Có ít nhất một đơn hàng ở trạng thái Chờ xử lý/Đã xác nhận/Đang giao. |
| Các bước thực hiện | 1. Đăng nhập nhân viên. 2. Mở `index.php?r=staff_orders`. 3. Quan sát bảng đơn hàng. 4. Dùng ô tìm kiếm/filter nếu có. |
| Kết quả mong đợi | Danh sách đơn hiển thị mã đơn, khách hàng, tổng tiền, trạng thái; search/filter không gây lỗi; dữ liệu khớp `hoa_don`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Controller: `QuanTriController::staffOrders()`. |

### TC-STF-003 - Cập nhật trạng thái đơn hàng

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-STF-003 |
| Chức năng | Cập nhật trạng thái đơn hàng |
| Use case liên quan | UC-S-02 |
| Mục tiêu kiểm thử | Kiểm tra nhân viên cập nhật đơn theo 5 trạng thái chuẩn. |
| Tiền điều kiện | Nhân viên đã đăng nhập; có đơn hàng hợp lệ. |
| Dữ liệu kiểm thử | Mã đơn ở trạng thái Chờ xử lý; trạng thái mới: Đã xác nhận hoặc Đang giao. |
| Các bước thực hiện | 1. Mở `index.php?r=staff_orders`. 2. Chọn một đơn hàng. 3. Chọn trạng thái mới. 4. Bấm cập nhật. 5. Reload danh sách. |
| Kết quả mong đợi | `hoa_don.trang_thai` và `trang_thai_normalized` được cập nhật đúng; giao diện hiển thị trạng thái mới; nếu chuyển Đã hủy thì hoàn kho một lần. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route: `index.php?r=staff_order_status`. |

### TC-STF-004 - Xem chi tiết đơn hàng

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-STF-004 |
| Chức năng | Chi tiết đơn hàng staff |
| Use case liên quan | UC-S-01 |
| Mục tiêu kiểm thử | Kiểm tra nhân viên xem được thông tin sản phẩm trong đơn, không chỉ mã sản phẩm. |
| Tiền điều kiện | Đơn hàng có dữ liệu `chi_tiet_hoa_don`; sản phẩm tồn tại trong `san_pham`. |
| Dữ liệu kiểm thử | Mã đơn có ít nhất một sản phẩm. |
| Các bước thực hiện | 1. Mở `index.php?r=staff_orders`. 2. Bấm xem chi tiết hoặc mở URL chi tiết nếu view dùng query `detail`. 3. Quan sát phần sản phẩm trong đơn. |
| Kết quả mong đợi | Chi tiết đơn hiển thị ảnh, tên sản phẩm, thương hiệu, mã sản phẩm, số lượng, đơn giá, thành tiền và link xem sản phẩm; dữ liệu khớp `chi_tiet_hoa_don` và `san_pham`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Có thể dùng helper `SanPham::getProductBriefById()`. |

### TC-STF-005 - Phản hồi đánh giá

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-STF-005 |
| Chức năng | Phản hồi đánh giá sản phẩm |
| Use case liên quan | UC-S-03, UC-A-09 |
| Mục tiêu kiểm thử | Kiểm tra nhân viên phản hồi được đánh giá khách hàng. |
| Tiền điều kiện | Có review trong `danh_gia_san_pham` chưa có `phan_hoi_shop`; nhân viên đã đăng nhập. |
| Dữ liệu kiểm thử | Nội dung phản hồi: “SkinSyntax cảm ơn bạn đã chia sẻ trải nghiệm.” |
| Các bước thực hiện | 1. Mở `index.php?r=staff_reviews`. 2. Tìm đánh giá chưa phản hồi. 3. Nhập nội dung phản hồi. 4. Bấm gửi phản hồi. 5. Mở trang chi tiết sản phẩm tại tab Đánh giá. |
| Kết quả mong đợi | Review được cập nhật `phan_hoi_shop`; trang sản phẩm hiển thị phản hồi từ SkinSyntax; nội dung review gốc không bị mất. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route xử lý: `index.php?r=staff_review_reply`. |

### TC-STF-006 - Trả lời hỏi đáp sản phẩm

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-STF-006 |
| Chức năng | Trả lời hỏi đáp sản phẩm |
| Use case liên quan | UC-S-04, UC-A-08 |
| Mục tiêu kiểm thử | Kiểm tra nhân viên/admin trả lời câu hỏi sản phẩm và câu trả lời hiển thị lại ở trang chi tiết. |
| Tiền điều kiện | Có câu hỏi trong `hoi_dap_san_pham` chưa trả lời; nhân viên có quyền vào `admin_questions`. |
| Dữ liệu kiểm thử | Nội dung trả lời: “Sản phẩm phù hợp cho da dầu, nên thử trên vùng nhỏ trước khi dùng toàn mặt.” |
| Các bước thực hiện | 1. Mở `index.php?r=admin_questions`. 2. Tìm câu hỏi chưa trả lời. 3. Nhập nội dung trả lời. 4. Bấm gửi. 5. Bấm “Xem hỏi đáp trên trang sản phẩm” nếu có. |
| Kết quả mong đợi | Document `hoi_dap_san_pham` có object `tra_loi`; tab Hỏi đáp ở trang chi tiết hiển thị câu trả lời shop. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route xử lý: `index.php?r=admin_question_reply`. |

### TC-STF-007 - Xem thông báo đơn hàng/đánh giá/hỏi đáp mới

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-STF-007 |
| Chức năng | Thông báo công việc |
| Use case liên quan | UC-S-05 |
| Mục tiêu kiểm thử | Kiểm tra notification dropdown hiển thị các loại thông báo cần xử lý. |
| Tiền điều kiện | Có thông báo trong `thong_bao` hoặc có đơn/chat/review/question mới; nhân viên đã đăng nhập. |
| Dữ liệu kiểm thử | Tạo một đơn mới, một review mới, một câu hỏi mới. |
| Các bước thực hiện | 1. Đăng nhập nhân viên. 2. Mở dashboard hoặc trang staff bất kỳ. 3. Bấm biểu tượng chuông thông báo. 4. Quan sát từng nhóm thông báo. 5. Bấm một thông báo hỏi đáp/đánh giá/đơn hàng. |
| Kết quả mong đợi | Badge hiển thị số chưa đọc; dropdown có đơn hàng, đánh giá, hỏi đáp, chat nếu có; link điều hướng đúng đến `staff_orders`, `staff_reviews`, `admin_questions` hoặc `staff_chats`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Data lấy từ `QuanTri::getNotificationCenterData()`. |

## 2. Testcase Quản trị viên

### TC-ADM-001 - Quản lý sản phẩm

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-001 |
| Chức năng | Quản lý sản phẩm |
| Use case liên quan | UC-A-01 |
| Mục tiêu kiểm thử | Kiểm tra admin xem, tìm kiếm và thao tác quản lý sản phẩm. |
| Tiền điều kiện | Admin đã đăng nhập; collection `san_pham` có dữ liệu. |
| Dữ liệu kiểm thử | Tên sản phẩm hoặc mã sản phẩm có thật. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_sp`. 2. Quan sát bảng sản phẩm. 3. Tìm kiếm theo mã/tên/thương hiệu. 4. Mở form thêm hoặc sửa sản phẩm. 5. Lưu thông tin hợp lệ. |
| Kết quả mong đợi | Bảng hiển thị sản phẩm; search trả kết quả đúng; thêm/sửa cập nhật document trong `san_pham`; reload vẫn thấy dữ liệu đã lưu. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route liên quan: `admin_sp`, `admin_sp_create`, `admin_sp_edit`. |

### TC-ADM-002 - Cập nhật tồn kho

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-002 |
| Chức năng | Tồn kho sản phẩm |
| Use case liên quan | UC-A-01 |
| Mục tiêu kiểm thử | Kiểm tra admin cập nhật `so_luong_ton_kho` và `trang_thai_kho`. |
| Tiền điều kiện | Admin đã đăng nhập; có sản phẩm trong `san_pham`. |
| Dữ liệu kiểm thử | Mã sản phẩm A; tồn kho mới: `0`, sau đó `50`. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_sp`. 2. Tìm sản phẩm A. 3. Nhập tồn kho `0` và bấm lưu. 4. Reload trang. 5. Đổi tồn kho thành `50` và bấm lưu lại. |
| Kết quả mong đợi | Khi tồn kho `0`, MongoDB lưu `so_luong_ton_kho = 0`, `trang_thai_kho = het_hang`; ngoài website nút thêm giỏ thành “Tạm hết hàng”. Khi tồn kho `50`, trạng thái chuyển `con_hang`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route: `index.php?r=admin_sp_stock`; model: `SanPham::updateInventory()`. |

### TC-ADM-003 - Ẩn/hiện sản phẩm

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-003 |
| Chức năng | Trạng thái hiển thị sản phẩm |
| Use case liên quan | UC-A-01 |
| Mục tiêu kiểm thử | Kiểm tra admin có thể ẩn và hiện sản phẩm. |
| Tiền điều kiện | Admin đã đăng nhập; có sản phẩm đang hiển thị. |
| Dữ liệu kiểm thử | Mã sản phẩm A. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_sp`. 2. Tìm sản phẩm A. 3. Bấm ẩn/ngừng hiển thị. 4. Mở trang `tatca` hoặc chi tiết sản phẩm để kiểm tra. 5. Quay lại admin và bật hiển thị lại. |
| Kết quả mong đợi | Sản phẩm bị ẩn không còn xuất hiện trong danh sách public nếu logic visibility áp dụng; khi bật lại, sản phẩm xuất hiện trở lại. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route: `index.php?r=admin_sp_visibility`. |

### TC-ADM-004 - Quản lý danh mục

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-004 |
| Chức năng | Quản lý danh mục |
| Use case liên quan | UC-A-02 |
| Mục tiêu kiểm thử | Kiểm tra admin thêm/sửa/xóa danh mục sản phẩm. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Dữ liệu kiểm thử | Tên danh mục: “Kiểm thử danh mục”. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_categories`. 2. Nhập thông tin danh mục mới. 3. Bấm lưu. 4. Sửa tên danh mục. 5. Xóa danh mục nếu UI cho phép. |
| Kết quả mong đợi | Danh mục được lưu/cập nhật/xóa trong collection `danh_muc`; option danh mục ở filter/form sản phẩm phản ánh thay đổi nếu model đọc từ collection. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route: `admin_category_save`, `admin_category_delete`. |

### TC-ADM-005 - Quản lý thương hiệu

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-005 |
| Chức năng | Quản lý thương hiệu |
| Use case liên quan | UC-A-03 |
| Mục tiêu kiểm thử | Kiểm tra dữ liệu thương hiệu được nhập/sửa qua form sản phẩm và dùng cho filter. |
| Tiền điều kiện | Admin đã đăng nhập; có route sản phẩm. |
| Dữ liệu kiểm thử | Thương hiệu: “Brand Test”. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_sp_create` hoặc `admin_sp_edit`. 2. Nhập/sửa field thương hiệu thành “Brand Test”. 3. Lưu sản phẩm. 4. Mở `index.php?r=tatca` hoặc `index.php?r=goiy` và kiểm tra filter thương hiệu. |
| Kết quả mong đợi | Field `thuong_hieu` của sản phẩm được lưu; filter thương hiệu có thể hiển thị/áp dụng theo dữ liệu sản phẩm hoặc collection `thuong_hieu` nếu có. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Chưa xác định route CRUD thương hiệu độc lập trong router; testcase kiểm thử quản lý thương hiệu thông qua sản phẩm. |

### TC-ADM-006 - Quản lý người dùng/nhân viên

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-006 |
| Chức năng | Quản lý người dùng/nhân viên |
| Use case liên quan | UC-A-04 |
| Mục tiêu kiểm thử | Kiểm tra admin xem và cập nhật dữ liệu khách hàng/nhân viên. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Dữ liệu kiểm thử | Khách hàng test hoặc nhân viên test với email riêng. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_users`. 2. Quan sát danh sách khách hàng và nhân viên. 3. Thêm/sửa thông tin một nhân viên hoặc khách hàng. 4. Lưu. |
| Kết quả mong đợi | Dữ liệu được lưu vào collection tương ứng; danh sách sau reload hiển thị thông tin mới; không tạo trùng email nếu hệ thống có ràng buộc. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route: `admin_customer_save`, `admin_staff_save`, `admin_staff_delete`, `admin_staff_hard_delete`. |

### TC-ADM-007 - Quản lý voucher

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-007 |
| Chức năng | Quản lý voucher |
| Use case liên quan | UC-A-06 |
| Mục tiêu kiểm thử | Kiểm tra admin tạo/sửa/xóa voucher và voucher có thể áp dụng ở checkout. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Dữ liệu kiểm thử | Mã voucher: `TEST10`; loại giảm: phần trăm hoặc cố định; giá trị hợp lệ. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_vouchers`. 2. Tạo voucher `TEST10`. 3. Đăng nhập khách hàng, thêm sản phẩm vào giỏ và mở checkout. 4. Nhập voucher `TEST10` tại `thanhtoan`. |
| Kết quả mong đợi | Voucher được lưu trong `voucher`; checkout áp dụng đúng mức giảm nếu điều kiện hợp lệ; có thể xóa voucher qua admin. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route admin: `admin_voucher_save`, `admin_voucher_delete`; checkout: `apdung_voucher`. |

### TC-ADM-008 - Quản lý đơn hàng

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-008 |
| Chức năng | Quản lý đơn hàng admin |
| Use case liên quan | UC-A-05 |
| Mục tiêu kiểm thử | Kiểm tra admin xem, tìm kiếm, xem chi tiết và cập nhật đơn hàng. |
| Tiền điều kiện | Admin đã đăng nhập; có đơn hàng trong `hoa_don`. |
| Dữ liệu kiểm thử | Mã đơn có thật; trạng thái mới: Đã xác nhận hoặc Hoàn thành. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_orders`. 2. Tìm kiếm mã đơn. 3. Mở chi tiết đơn. 4. Cập nhật trạng thái. |
| Kết quả mong đợi | Danh sách lọc đúng mã đơn; chi tiết đơn hiển thị sản phẩm; trạng thái lưu đúng trong `hoa_don`; nếu Hoàn thành thì có ngày hoàn thành và báo cáo doanh thu tính đơn này theo điều kiện. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route cập nhật: `index.php?r=admin_order_status`. |

### TC-ADM-009 - Xem báo cáo doanh thu

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-009 |
| Chức năng | Báo cáo doanh thu |
| Use case liên quan | UC-A-07 |
| Mục tiêu kiểm thử | Kiểm tra báo cáo chỉ tính doanh thu từ đơn hoàn thành hợp lệ. |
| Tiền điều kiện | Admin đã đăng nhập; có đơn completed và đơn chưa completed để đối chiếu. |
| Dữ liệu kiểm thử | Một đơn Hoàn thành; một đơn Chờ xử lý hoặc Đã hủy. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_reports`. 2. Ghi nhận doanh thu. 3. Chuyển một đơn sang Hoàn thành ở admin_orders. 4. Quay lại admin_reports. |
| Kết quả mong đợi | Doanh thu tăng khi đơn chuyển Hoàn thành; đơn Chờ xử lý/Đã xác nhận/Đang giao/Đã hủy không được tính; QR/chuyển khoản chỉ tính nếu đã thanh toán khi có field thanh toán. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Model liên quan: `QuanTri`, `HoaDon`. |

### TC-ADM-010 - Lọc báo cáo

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-010 |
| Chức năng | Lọc báo cáo |
| Use case liên quan | UC-A-07 |
| Mục tiêu kiểm thử | Kiểm tra các bộ lọc báo cáo nếu giao diện hiện tại cung cấp. |
| Tiền điều kiện | Admin đã đăng nhập; có dữ liệu đơn hàng ở nhiều thời điểm. |
| Dữ liệu kiểm thử | Khoảng ngày/tháng hoặc filter có trên UI. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_reports`. 2. Nhập/chọn bộ lọc thời gian nếu có. 3. Bấm lọc. 4. Đối chiếu số liệu với `hoa_don`. |
| Kết quả mong đợi | Báo cáo chỉ hiển thị dữ liệu thuộc filter; doanh thu vẫn chỉ tính đơn completed hợp lệ. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Nếu view không có filter báo cáo, ghi trạng thái “Không áp dụng” và ghi chú UI chưa hỗ trợ lọc cụ thể. |

### TC-ADM-011 - Xem thông báo mới

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-011 |
| Chức năng | Thông báo admin |
| Use case liên quan | UC-A-10 |
| Mục tiêu kiểm thử | Kiểm tra admin nhận được thông báo mới từ đơn hàng, đánh giá, hỏi đáp và chat. |
| Tiền điều kiện | Admin đã đăng nhập; có dữ liệu thông báo trong `thong_bao` hoặc dữ liệu pending tương ứng. |
| Dữ liệu kiểm thử | Một đơn mới, một review mới, một câu hỏi mới, một chat cần hỗ trợ. |
| Các bước thực hiện | 1. Mở trang admin bất kỳ. 2. Quan sát badge chuông thông báo. 3. Bấm dropdown thông báo. 4. Bấm từng loại thông báo. |
| Kết quả mong đợi | Badge đếm đúng thông báo chưa đọc; dropdown sort mới nhất lên đầu; link điều hướng đúng đến đơn hàng, đánh giá, hỏi đáp hoặc chat. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route đánh dấu đã xem: `index.php?r=admin_notifications_seen`. |

### TC-ADM-012 - Quản lý hỏi đáp

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-012 |
| Chức năng | Quản lý hỏi đáp |
| Use case liên quan | UC-A-08 |
| Mục tiêu kiểm thử | Kiểm tra admin xem, tìm kiếm, trả lời và ẩn câu hỏi sản phẩm. |
| Tiền điều kiện | Admin đã đăng nhập; có câu hỏi trong `hoi_dap_san_pham`. |
| Dữ liệu kiểm thử | Mã câu hỏi, mã sản phẩm, nội dung trả lời. |
| Các bước thực hiện | 1. Mở `index.php?r=admin_questions`. 2. Tìm kiếm theo mã sản phẩm hoặc tên khách hàng. 3. Trả lời một câu hỏi. 4. Ẩn một câu hỏi khác nếu UI cho phép. 5. Mở trang chi tiết sản phẩm tab Hỏi đáp. |
| Kết quả mong đợi | Admin_questions hiển thị tên sản phẩm/ảnh nếu lookup được; câu trả lời hiển thị ở tab Hỏi đáp; câu hỏi bị ẩn không còn hiển thị public nếu `trang_thai = an`. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route: `admin_question_reply`, `admin_question_hide`; model `HoiDap`. |

### TC-ADM-013 - Quản lý đánh giá

| Trường | Nội dung |
| --- | --- |
| Mã testcase | TC-ADM-013 |
| Chức năng | Quản lý đánh giá |
| Use case liên quan | UC-A-09 |
| Mục tiêu kiểm thử | Kiểm tra admin/staff xem và phản hồi đánh giá sản phẩm. |
| Tiền điều kiện | Admin đã đăng nhập; có review trong `danh_gia_san_pham` hoặc legacy `danh_gia`. |
| Dữ liệu kiểm thử | Review 4 sao chưa phản hồi. |
| Các bước thực hiện | 1. Mở `index.php?r=staff_reviews` bằng tài khoản admin. 2. Tìm review theo mã sản phẩm/tên khách/số sao. 3. Gửi phản hồi. 4. Mở chi tiết sản phẩm tab Đánh giá. |
| Kết quả mong đợi | Danh sách review hiển thị sản phẩm liên quan; phản hồi được lưu; tab Đánh giá hiển thị phản hồi; review legacy nếu có vẫn được hiển thị theo logic đọc cũ. |
| Kết quả thực tế |  |
| Trạng thái |  |
| Ghi chú | Route tên staff nhưng có thể dùng bởi admin tùy phân quyền. |

## 3. Ma trận Use Case - Test Case

| Use case | Testcase |
| --- | --- |
| UC-S-01 | TC-STF-001, TC-STF-002, TC-STF-004 |
| UC-S-02 | TC-STF-003 |
| UC-S-03 | TC-STF-005 |
| UC-S-04 | TC-STF-006 |
| UC-S-05 | TC-STF-007 |
| UC-A-01 | TC-ADM-001, TC-ADM-002, TC-ADM-003 |
| UC-A-02 | TC-ADM-004 |
| UC-A-03 | TC-ADM-005 |
| UC-A-04 | TC-ADM-006 |
| UC-A-05 | TC-ADM-008 |
| UC-A-06 | TC-ADM-007 |
| UC-A-07 | TC-ADM-009, TC-ADM-010 |
| UC-A-08 | TC-ADM-012 |
| UC-A-09 | TC-ADM-013 |
| UC-A-10 | TC-ADM-011 |

## 4. Danh sách testcase ưu tiên cao

| Mức ưu tiên | Testcase | Lý do |
| --- | --- | --- |
| Cao | TC-STF-003 | Cập nhật trạng thái đơn ảnh hưởng vận hành và doanh thu. |
| Cao | TC-STF-005 | Phản hồi đánh giá ảnh hưởng trải nghiệm khách hàng. |
| Cao | TC-STF-006 | Trả lời hỏi đáp ảnh hưởng hiển thị trang sản phẩm. |
| Cao | TC-ADM-002 | Tồn kho ảnh hưởng khả năng mua hàng và checkout. |
| Cao | TC-ADM-008 | Quản lý đơn hàng là nghiệp vụ lõi. |
| Cao | TC-ADM-009 | Báo cáo doanh thu phải tính đúng đơn hoàn thành. |
| Cao | TC-ADM-011 | Thông báo mới giúp admin/staff không bỏ sót công việc. |
| Cao | TC-ADM-012 | Hỏi đáp tạo dữ liệu public trên trang chi tiết sản phẩm. |
| Cao | TC-ADM-013 | Đánh giá và phản hồi ảnh hưởng độ tin cậy sản phẩm. |

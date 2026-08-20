# Đặc tả Use Case hệ thống SkinSyntaxVN

Tài liệu phục vụ Chương 3 báo cáo khóa luận. Nội dung được lập dựa trên các file phân tích source code hiện có: `REPORT_CODE_ANALYSIS.md`, `REPORT_UI_MAPPING.md`, `DATABASE_ANALYSIS.md`, `RECOMMENDATION_AND_CHATBOT_FLOW.md`. Tài liệu chỉ mô tả chức năng theo mã nguồn và giao diện hiện tại, không bổ sung chức năng giả định ngoài hệ thống.

## 1. Danh sách tác nhân

| Tác nhân | Mô tả |
| --- | --- |
| Khách vãng lai | Người dùng chưa đăng nhập, có thể xem sản phẩm, tìm kiếm, xem chi tiết, xem gợi ý công khai và đăng ký/đăng nhập. |
| Khách hàng | Người dùng đã đăng nhập, có thể quản lý hồ sơ, khảo sát da, nhận gợi ý cá nhân hóa, thêm giỏ hàng, đặt hàng, đánh giá và hỏi đáp. |
| Nhân viên | Người dùng nội bộ có quyền xử lý đơn hàng, phản hồi đánh giá, trả lời hỏi đáp và xem thông báo công việc. |
| Quản trị viên | Người dùng nội bộ có quyền quản lý toàn bộ dữ liệu vận hành như sản phẩm, danh mục, người dùng, đơn hàng, voucher, báo cáo, đánh giá, hỏi đáp. |
| Hệ thống AI/Flask service | Dịch vụ Flask độc lập gồm chatbot LangChain + ChromaDB và recommendation LlamaIndex. |

## 2. Use case của Khách vãng lai

### UC-G-01 - Đăng ký tài khoản

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-G-01 |
| Tên use case | Đăng ký tài khoản |
| Actor chính | Khách vãng lai |
| Actor phụ | Hệ thống gửi OTP/captcha nếu được kích hoạt |
| Mục tiêu | Tạo tài khoản khách hàng để sử dụng các chức năng cá nhân hóa và mua hàng. |
| Tiền điều kiện | Người dùng chưa đăng nhập. |
| Hậu điều kiện | Tài khoản mới được tạo trong hệ thống nếu dữ liệu hợp lệ. |
| Kích hoạt | Người dùng truy cập trang đăng ký. |
| Luồng chính | 1. Người dùng mở `index.php?r=dangky`. 2. Hệ thống hiển thị form đăng ký. 3. Người dùng nhập thông tin tài khoản. 4. Người dùng gửi form qua `index.php?r=xulydangky`. 5. Controller kiểm tra dữ liệu và tạo tài khoản. 6. Hệ thống chuyển người dùng đến bước phù hợp sau đăng ký. |
| Luồng thay thế | Nếu có OTP/captcha, hệ thống yêu cầu xác thực trước khi hoàn tất đăng ký. |
| Luồng ngoại lệ | Email hoặc dữ liệu không hợp lệ, hệ thống hiển thị thông báo lỗi và giữ người dùng ở luồng đăng ký. |
| Dữ liệu đầu vào | Họ tên, email, mật khẩu, thông tin xác thực OTP/captcha nếu có. |
| Dữ liệu đầu ra | Tài khoản người dùng, thông báo thành công hoặc lỗi. |
| Giao diện liên quan | `backend/app/views/auth/dangky.php` hoặc view đăng ký tương ứng. |
| Route liên quan | `index.php?r=dangky`, `index.php?r=xulydangky`, `index.php?r=gui_otp_dang_ky`, `index.php?r=gui_captcha_dang_ky`. |
| Collection/model liên quan | `NguoiDung`, `khach_hang`, `nguoidung`. |
| Ghi chú mức độ hoàn thiện | Có route và controller thật; view đăng ký tồn tại ở cả thư mục root view và `auth`, cần kiểm thử runtime để xác định view được render trong từng cấu hình. |

### UC-G-02 - Đăng nhập

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-G-02 |
| Tên use case | Đăng nhập |
| Actor chính | Khách vãng lai |
| Actor phụ | Không |
| Mục tiêu | Xác thực người dùng và tạo phiên đăng nhập. |
| Tiền điều kiện | Người dùng đã có tài khoản. |
| Hậu điều kiện | Session người dùng được thiết lập nếu thông tin đăng nhập đúng. |
| Kích hoạt | Người dùng truy cập trang đăng nhập. |
| Luồng chính | 1. Người dùng mở `index.php?r=dangnhap`. 2. Hệ thống hiển thị form đăng nhập. 3. Người dùng nhập email và mật khẩu. 4. Form gửi đến `index.php?r=xulydangnhap`. 5. Controller xác thực tài khoản. 6. Hệ thống lưu thông tin người dùng vào session và chuyển hướng theo luồng tương ứng. |
| Luồng thay thế | Người dùng có thể đăng nhập bằng social login qua `auth_social` nếu cấu hình OAuth hợp lệ. |
| Luồng ngoại lệ | Sai email/mật khẩu hoặc tài khoản không hợp lệ, hệ thống thông báo lỗi. |
| Dữ liệu đầu vào | Email, mật khẩu hoặc dữ liệu OAuth. |
| Dữ liệu đầu ra | Session đăng nhập, thông báo lỗi nếu thất bại. |
| Giao diện liên quan | `backend/app/views/auth/dangnhap.php` hoặc view đăng nhập tương ứng. |
| Route liên quan | `index.php?r=dangnhap`, `index.php?r=xulydangnhap`, `index.php?r=auth_social`, `index.php?r=auth_social_callback`. |
| Collection/model liên quan | `NguoiDung`, `TaiKhoan`, `nguoidung`, `khach_hang`. |
| Ghi chú mức độ hoàn thiện | Có route, controller và cấu hình OAuth; OAuth phụ thuộc biến môi trường/cấu hình thực tế. |

### UC-G-03 - Xem trang chủ

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-G-03 |
| Tên use case | Xem trang chủ |
| Actor chính | Khách vãng lai |
| Actor phụ | Không |
| Mục tiêu | Xem các khối sản phẩm nổi bật, Flash Sale và điều hướng chính của website. |
| Tiền điều kiện | Website và MongoDB hoạt động. |
| Hậu điều kiện | Người dùng xem được nội dung trang chủ. |
| Kích hoạt | Người dùng truy cập `index.php?r=home` hoặc route mặc định. |
| Luồng chính | 1. Router gọi `HomeController::index()`. 2. Controller lấy dữ liệu sản phẩm mới và các section trang chủ. 3. Hệ thống render `home.php`. 4. Người dùng xem Flash Sale, sản phẩm và các nút điều hướng. |
| Luồng thay thế | Nếu một số khối không có dữ liệu, giao diện hiển thị trạng thái rỗng hoặc bỏ qua theo logic view. |
| Luồng ngoại lệ | Nếu MongoDB lỗi, controller có xử lý lỗi ở một số nhánh và ghi log; mức độ thân thiện phụ thuộc từng đoạn view/controller. |
| Dữ liệu đầu vào | Không bắt buộc; có thể có query điều hướng. |
| Dữ liệu đầu ra | Danh sách sản phẩm và nội dung trang chủ. |
| Giao diện liên quan | `backend/app/views/home.php`, product card partial. |
| Route liên quan | `index.php?r=home`. |
| Collection/model liên quan | `SanPham`, `san_pham`. |
| Ghi chú mức độ hoàn thiện | Chức năng trang chủ có giao diện và logic lấy sản phẩm thật. |

### UC-G-04 - Tìm kiếm/lọc sản phẩm

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-G-04 |
| Tên use case | Tìm kiếm/lọc sản phẩm |
| Actor chính | Khách vãng lai |
| Actor phụ | Không |
| Mục tiêu | Tìm sản phẩm theo từ khóa, danh mục, thương hiệu, giá và các tiêu chí sắp xếp. |
| Tiền điều kiện | Có dữ liệu sản phẩm trong `san_pham`. |
| Hậu điều kiện | Danh sách sản phẩm phù hợp được hiển thị. |
| Kích hoạt | Người dùng nhập từ khóa hoặc chọn bộ lọc ở trang danh sách sản phẩm. |
| Luồng chính | 1. Người dùng truy cập `index.php?r=tatca`. 2. Người dùng nhập bộ lọc. 3. Route gọi `SanPhamController::tatca()`. 4. Model `SanPham::paginate()` tạo filter và sort. 5. View `tatca.php` hiển thị kết quả có phân trang. |
| Luồng thay thế | Người dùng dùng live search hoặc smart search qua API JSON để nhận gợi ý nhanh. |
| Luồng ngoại lệ | Không có kết quả, hệ thống hiển thị danh sách rỗng hoặc thông báo tương ứng. MongoDB lỗi có thể được ghi log tùy nhánh xử lý. |
| Dữ liệu đầu vào | Keyword, danh mục, thương hiệu, khoảng giá, sort, trang hiện tại. |
| Dữ liệu đầu ra | Danh sách sản phẩm, tổng số, phân trang. |
| Giao diện liên quan | `backend/app/views/tatca.php`, product card partial, search header. |
| Route liên quan | `index.php?r=tatca`, `index.php?r=live_search`, `index.php?r=api_smart_search`. |
| Collection/model liên quan | `SanPham`, `san_pham`, `danh_muc`, `thuong_hieu`. |
| Ghi chú mức độ hoàn thiện | Có logic tìm kiếm và phân trang; các field tìm kiếm phụ thuộc dữ liệu hiện có trong MongoDB. |

### UC-G-05 - Xem chi tiết sản phẩm

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-G-05 |
| Tên use case | Xem chi tiết sản phẩm |
| Actor chính | Khách vãng lai |
| Actor phụ | Không |
| Mục tiêu | Xem đầy đủ thông tin sản phẩm, đánh giá và hỏi đáp. |
| Tiền điều kiện | Sản phẩm tồn tại trong `san_pham`. |
| Hậu điều kiện | Trang chi tiết được hiển thị; lượt xem có thể được tăng. |
| Kích hoạt | Người dùng chọn sản phẩm từ trang chủ, danh sách hoặc gợi ý. |
| Luồng chính | 1. Người dùng truy cập `index.php?r=chitiet&id=...`. 2. `SanPhamController::chitiet()` tìm sản phẩm. 3. Controller lấy review stats, danh sách đánh giá, hỏi đáp. 4. View `chitiet.php` hiển thị ảnh, giá, tồn kho, tab mô tả/thông số/thành phần/HDSD/đánh giá/hỏi đáp. |
| Luồng thay thế | Nếu URL có hash `#danhgia` hoặc `#hoidap`, JavaScript active tab tương ứng. |
| Luồng ngoại lệ | Sản phẩm không tồn tại, hệ thống cần hiển thị lỗi/redirect tùy controller. MongoDB lỗi cần được log và không nên làm mất toàn trang. |
| Dữ liệu đầu vào | `id` sản phẩm, filter review nếu có. |
| Dữ liệu đầu ra | Thông tin chi tiết sản phẩm, danh sách đánh giá/hỏi đáp. |
| Giao diện liên quan | `backend/app/views/chitiet.php`. |
| Route liên quan | `index.php?r=chitiet&id=...`. |
| Collection/model liên quan | `SanPham`, `DanhGia`, `HoiDap`, `san_pham`, `danh_gia_san_pham`, legacy `danh_gia`, `hoi_dap_san_pham`. |
| Ghi chú mức độ hoàn thiện | Có giao diện chi tiết, review và Q&A; khách vãng lai chỉ xem, không gửi đánh giá/hỏi đáp nếu chưa đăng nhập. |

### UC-G-06 - Xem gợi ý công khai tại `/goiy`

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-G-06 |
| Tên use case | Xem gợi ý công khai tại `/goiy` |
| Actor chính | Khách vãng lai |
| Actor phụ | Không |
| Mục tiêu | Khám phá sản phẩm theo các nhóm phổ biến mà không cần cá nhân hóa. |
| Tiền điều kiện | Người dùng chưa đăng nhập; có dữ liệu sản phẩm trong MongoDB. |
| Hậu điều kiện | Trang `/goiy` hiển thị các khối sản phẩm công khai. |
| Kích hoạt | Người dùng truy cập `index.php?r=goiy`. |
| Luồng chính | 1. `HomeController::goiy()` xác định người dùng chưa đăng nhập. 2. Controller đọc filter từ query. 3. Controller gọi `loadPublicRecommendationData()`. 4. Model `SanPham::publicRecommendationSections()` lấy 5 nhóm sản phẩm. 5. View `goiy.php` hiển thị form lọc và các khối sản phẩm. |
| Luồng thay thế | Nếu người dùng nhập filter hoặc sort, filter được áp dụng cho từng khối công khai. |
| Luồng ngoại lệ | MongoDB lỗi, hệ thống ghi log và truyền thông báo không tải được sản phẩm phổ biến. |
| Dữ liệu đầu vào | Keyword, danh mục, thương hiệu, giá từ, giá đến, sort. |
| Dữ liệu đầu ra | Các nhóm: bán chạy, đánh giá cao, giảm giá, được quan tâm, sản phẩm mới. |
| Giao diện liên quan | `backend/app/views/goiy.php`, product card partial. |
| Route liên quan | `index.php?r=goiy`. |
| Collection/model liên quan | `SanPham`, `san_pham`, `danh_muc`, `thuong_hieu`. |
| Ghi chú mức độ hoàn thiện | Không gọi AI/LlamaIndex ở trạng thái khách vãng lai; đây là public discovery bằng MongoDB. |

### UC-G-07 - Xem tất cả sản phẩm theo nhóm

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-G-07 |
| Tên use case | Xem tất cả sản phẩm theo nhóm |
| Actor chính | Khách vãng lai |
| Actor phụ | Không |
| Mục tiêu | Xem danh sách đầy đủ của một nhóm sản phẩm như bán chạy, đánh giá cao, giảm giá, được quan tâm hoặc mới. |
| Tiền điều kiện | Nhóm sản phẩm hợp lệ. |
| Hậu điều kiện | Trang collection hiển thị danh sách sản phẩm có phân trang. |
| Kích hoạt | Người dùng bấm “Xem tất cả” trong một khối sản phẩm. |
| Luồng chính | 1. Người dùng truy cập `index.php?r=product_collection&type=...`. 2. `HomeController::productCollection()` kiểm tra type. 3. Model `SanPham::getCollectionProducts()` lấy dữ liệu theo type, filter, sort, page. 4. View `product_collection.php` hiển thị danh sách và phân trang. |
| Luồng thay thế | Nếu type không hợp lệ, controller fallback về `best_seller` và hiển thị thông báo thân thiện. |
| Luồng ngoại lệ | MongoDB lỗi, controller ghi log và truyền thông báo không tải được danh sách. |
| Dữ liệu đầu vào | `type`, keyword, danh mục, thương hiệu, khoảng giá, sort, page. |
| Dữ liệu đầu ra | Danh sách sản phẩm trong nhóm, tổng số, số trang. |
| Giao diện liên quan | `backend/app/views/product_collection.php`. |
| Route liên quan | `index.php?r=product_collection&type=best_seller`, `top_rated`, `discount`, `most_viewed`, `new`. |
| Collection/model liên quan | `SanPham`, `san_pham`. |
| Ghi chú mức độ hoàn thiện | Route và view tồn tại; route cũ `danhsach` vẫn tồn tại cho một số danh sách khác. |

## 3. Use case của Khách hàng

### UC-C-01 - Quản lý hồ sơ cá nhân

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-01 |
| Tên use case | Quản lý hồ sơ cá nhân |
| Actor chính | Khách hàng |
| Actor phụ | Không |
| Mục tiêu | Xem và cập nhật thông tin cá nhân, hồ sơ da, lịch sử đơn hàng và đổi mật khẩu. |
| Tiền điều kiện | Người dùng đã đăng nhập. |
| Hậu điều kiện | Thông tin cá nhân hoặc hồ sơ da được cập nhật nếu dữ liệu hợp lệ. |
| Kích hoạt | Khách hàng truy cập trang hồ sơ. |
| Luồng chính | 1. Người dùng mở `index.php?r=hoso`. 2. `TaiKhoanController::hoso()` lấy tài khoản, khách hàng, lịch sử đơn, hồ sơ da. 3. View `hoso.php` hiển thị thông tin. 4. Người dùng gửi yêu cầu cập nhật thông tin, hồ sơ da hoặc đổi mật khẩu. |
| Luồng thay thế | Cập nhật hồ sơ da qua `capnhathosoda`; cập nhật thông tin qua `capnhatthongtin`; đổi mật khẩu qua `doimatkhau`. |
| Luồng ngoại lệ | Chưa đăng nhập thì chuyển hướng về đăng nhập; dữ liệu không hợp lệ thì trả lỗi/flash message. |
| Dữ liệu đầu vào | Thông tin cá nhân, thông tin hồ sơ da, mật khẩu mới nếu đổi mật khẩu. |
| Dữ liệu đầu ra | Hồ sơ đã cập nhật hoặc thông báo lỗi. |
| Giao diện liên quan | `backend/app/views/hoso.php`. |
| Route liên quan | `index.php?r=hoso`, `capnhathosoda`, `capnhatthongtin`, `doimatkhau`. |
| Collection/model liên quan | `TaiKhoan`, `khach_hang`, `nguoidung`, `hoa_don`, `chi_tiet_hoa_don`. |
| Ghi chú mức độ hoàn thiện | Có route và logic hồ sơ; recommendation chính không nằm ở trang hồ sơ mà ở `/goiy`. |

### UC-C-02 - Thực hiện khảo sát hồ sơ da

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-02 |
| Tên use case | Thực hiện khảo sát hồ sơ da |
| Actor chính | Khách hàng |
| Actor phụ | Không |
| Mục tiêu | Thu thập dữ liệu loại da, vấn đề da, ngân sách và mục tiêu chăm sóc da để phục vụ cá nhân hóa. |
| Tiền điều kiện | Người dùng đã đăng nhập hoặc đang trong luồng đăng ký cần bổ sung khảo sát. |
| Hậu điều kiện | Hồ sơ da được lưu vào dữ liệu khách hàng. |
| Kích hoạt | Người dùng bấm “Khảo sát ngay” hoặc truy cập `index.php?r=khaosat`. |
| Luồng chính | 1. `AuthController::khaosat()` hiển thị form khảo sát. 2. Người dùng trả lời các câu hỏi. 3. Form gửi đến `index.php?r=xulykhaosat`. 4. Controller validate dữ liệu và gọi model lưu khảo sát. 5. Hệ thống redirect về `/goiy` hoặc trang phù hợp theo nguồn. |
| Luồng thay thế | Nếu khảo sát xuất phát từ đăng ký, hệ thống có thể redirect về đăng nhập sau khi lưu. |
| Luồng ngoại lệ | Thiếu câu trả lời bắt buộc, hệ thống lưu trạng thái tạm và yêu cầu nhập lại. |
| Dữ liệu đầu vào | Loại da, vấn đề da, ngân sách, mục tiêu chăm sóc da, thành phần cần tránh nếu có. |
| Dữ liệu đầu ra | Hồ sơ da trong `khach_hang` hoặc dữ liệu hồ sơ liên quan. |
| Giao diện liên quan | `backend/app/views/auth/khaosat.php`. |
| Route liên quan | `index.php?r=khaosat`, `index.php?r=xulykhaosat`. |
| Collection/model liên quan | `NguoiDung`, `TaiKhoan`, `khach_hang`, có thể liên quan `ho_so_da` theo service AI. |
| Ghi chú mức độ hoàn thiện | Route khảo sát tồn tại; mức độ đầy đủ của các câu hỏi phụ thuộc view hiện tại. |

### UC-C-03 - Nhận gợi ý sản phẩm cá nhân hóa

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-03 |
| Tên use case | Nhận gợi ý sản phẩm cá nhân hóa |
| Actor chính | Khách hàng |
| Actor phụ | Hệ thống AI/Flask service |
| Mục tiêu | Cung cấp danh sách sản phẩm phù hợp với hồ sơ da và lịch sử tương tác của khách hàng. |
| Tiền điều kiện | Khách hàng đã đăng nhập, có hồ sơ da hợp lệ, recommendation Flask service đang chạy tại port 5002 và index LlamaIndex đã được build. |
| Hậu điều kiện | Trang `/goiy` hiển thị lời tư vấn và danh sách sản phẩm cá nhân hóa; nếu lỗi thì hiển thị thông báo thân thiện. |
| Kích hoạt | Khách hàng truy cập `index.php?r=goiy`. |
| Luồng chính | 1. PHP xác định session người dùng qua `current_user()`. 2. `HomeController::goiy()` gọi `buildRecommendationProfile($email)`. 3. Controller kiểm tra hồ sơ da bằng `hasValidSkinProfile()`. 4. PHP tạo payload gồm `user_id`, `email`, `session_user_id`. 5. PHP gọi Flask endpoint `POST /api/recommend/llamaindex`. 6. Flask `rcm_flask.py` gọi `llamaindex_recommend_service.recommend()`. 7. Service Flask lấy khách hàng từ `khach_hang`/`nguoidung`. 8. Service lấy hồ sơ da, lịch sử mua hàng từ `hoa_don` và `chi_tiet_hoa_don`, giỏ hàng từ `gio_hang` nếu có, lịch sử chat từ `lich_su_chat` nếu có. 9. Service tạo implicit query. 10. LlamaIndex load index tại `database/recommendation_index`. 11. `VectorIndexRetriever` và `BM25Retriever` truy xuất ứng viên. 12. Reranking chọn top sản phẩm phù hợp. 13. Gemini/LLM viết `answer_text`. 14. Flask trả JSON `ok`, `source`, `answer_text`, `products`. 15. PHP map ảnh/link và render sản phẩm trong `goiy.php`. |
| Luồng thay thế | Nếu đã đăng nhập nhưng chưa có hồ sơ da hợp lệ, hệ thống không gọi AI mà hiển thị public discovery và banner “Khảo sát ngay”. |
| Luồng ngoại lệ | Nếu Flask lỗi, timeout, thiếu index hoặc không có sản phẩm, PHP hiển thị “Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau.” và không giả lập kết quả cá nhân hóa. |
| Dữ liệu đầu vào | Session user, email, mã khách hàng, hồ sơ da, lịch sử mua hàng, giỏ hàng, lịch sử chat. |
| Dữ liệu đầu ra | `answer_text`, danh sách sản phẩm gồm id, tên, giá, thương hiệu, ảnh, điểm đánh giá, lý do gợi ý. |
| Giao diện liên quan | `backend/app/views/goiy.php`, card recommendation. |
| Route liên quan | PHP: `index.php?r=goiy`; Flask: `POST http://127.0.0.1:5002/api/recommend/llamaindex`, `GET /health`. |
| Collection/model liên quan | PHP: `TaiKhoan`, `SanPham`; Flask/MongoDB: `khach_hang`, `nguoidung`, `ho_so_da`, `hoa_don`, `chi_tiet_hoa_don`, `gio_hang`, `lich_su_chat`, `san_pham`; index: `database/recommendation_index`. |
| Ghi chú mức độ hoàn thiện | Có luồng LlamaIndex thật; phụ thuộc service Flask, package LlamaIndex, Gemini API key và index đã build. |

### UC-C-04 - Chat với AI tư vấn mỹ phẩm

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-04 |
| Tên use case | Chat với AI tư vấn mỹ phẩm |
| Actor chính | Khách hàng |
| Actor phụ | Hệ thống AI/Flask service |
| Mục tiêu | Hỗ trợ khách hàng hỏi đáp về mỹ phẩm, sản phẩm và nhu cầu chăm sóc da. |
| Tiền điều kiện | Chatbot Flask service có thể được gọi qua endpoint cấu hình; người dùng có thể đăng nhập hoặc không tùy widget. |
| Hậu điều kiện | Người dùng nhận câu trả lời AI và danh sách sản phẩm liên quan nếu có. |
| Kích hoạt | Người dùng gửi tin nhắn trong AI chat widget. |
| Luồng chính | 1. Widget gửi AJAX đến `index.php?r=ai_chat_assistant`. 2. `HomeController::aiChatAssistant()` nhận message, history và current product id nếu có. 3. PHP xây dựng context profile/cart/product. 4. PHP gọi `AI_CHAT_ENDPOINT` mặc định `http://127.0.0.1:5001/api/chat`. 5. `chatbot_flask.py` xử lý bằng LangChain + ChromaDB, intent router, RAG/hybrid search/reranking nếu cần. 6. Flask trả answer/products. 7. PHP trả JSON cho widget. |
| Luồng thay thế | Nếu câu hỏi là lời chào, PHP có thể trả phản hồi mặc định mà không gọi AI service. |
| Luồng ngoại lệ | Nếu AI service không phản hồi, PHP trả thông báo không kết nối được tới AI service. |
| Dữ liệu đầu vào | Message, lịch sử hội thoại, mã sản phẩm hiện tại, context giỏ hàng/hồ sơ nếu có. |
| Dữ liệu đầu ra | Câu trả lời AI, danh sách sản phẩm liên quan, cảnh báo xung đột thành phần nếu có. |
| Giao diện liên quan | `backend/app/views/components/ai_chat_widget.php`. |
| Route liên quan | PHP: `index.php?r=ai_chat_assistant`; Flask chatbot: `POST /api/chat`, `GET /health`, `GET /api/health`. |
| Collection/model liên quan | `SanPham`, session cart, ChromaDB chatbot, có thể dùng dữ liệu `san_pham` qua PHP. |
| Ghi chú mức độ hoàn thiện | Chatbot là module riêng dùng LangChain + ChromaDB, không dùng LlamaIndex. |

### UC-C-05 - Thêm sản phẩm vào giỏ hàng

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-05 |
| Tên use case | Thêm sản phẩm vào giỏ hàng |
| Actor chính | Khách hàng |
| Actor phụ | Không |
| Mục tiêu | Thêm sản phẩm còn hàng vào giỏ mà không cần rời trang hiện tại nếu dùng AJAX. |
| Tiền điều kiện | Sản phẩm tồn tại và còn tồn kho. |
| Hậu điều kiện | Session `gio_hang` được cập nhật. |
| Kích hoạt | Người dùng bấm “Thêm giỏ hàng” trên card hoặc trang chi tiết. |
| Luồng chính | 1. Frontend gửi product id và quantity đến route thêm giỏ hàng. 2. `SanPhamController::addToCartAjax()` đọc dữ liệu từ POST/GET/JSON. 3. Controller tìm sản phẩm bằng helper linh hoạt. 4. Controller kiểm tra `so_luong_ton_kho` và `trang_thai_kho`. 5. Nếu hợp lệ, cập nhật `$_SESSION['gio_hang']`. 6. Nếu là AJAX, trả JSON thành công và cart count. |
| Luồng thay thế | Nếu request không phải AJAX, hệ thống có thể giữ redirect cũ theo logic route. |
| Luồng ngoại lệ | Không tìm thấy sản phẩm, hết hàng, vượt tồn kho hoặc exception, hệ thống trả JSON lỗi/thông báo thân thiện. |
| Dữ liệu đầu vào | `product_id`/`ma_san_pham`/`id`, `quantity`/`so_luong`. |
| Dữ liệu đầu ra | JSON thành công/lỗi, số lượng giỏ hàng. |
| Giao diện liên quan | Product card, `chitiet.php`, `goiy.php`, `home.php`, `tatca.php`. |
| Route liên quan | `index.php?r=them_gio_hang_ajax`, `index.php?r=themgiohang`, `index.php?r=them_gio_hang`. |
| Collection/model liên quan | `SanPham`, `san_pham`, session `gio_hang`. |
| Ghi chú mức độ hoàn thiện | Code có route AJAX và kiểm tra tồn kho; hành vi thực tế cần kiểm thử trên từng form/card vì có nhiều vị trí nút thêm giỏ. |

### UC-C-06 - Cập nhật giỏ hàng

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-06 |
| Tên use case | Cập nhật giỏ hàng |
| Actor chính | Khách hàng |
| Actor phụ | Không |
| Mục tiêu | Xem, thay đổi số lượng hoặc loại bỏ sản phẩm trong giỏ hàng. |
| Tiền điều kiện | Giỏ hàng có thể rỗng hoặc có sản phẩm trong session. |
| Hậu điều kiện | Session giỏ hàng phản ánh số lượng mới. |
| Kích hoạt | Người dùng truy cập `index.php?r=giohang`. |
| Luồng chính | 1. `HomeController::giohang()` đọc `$_SESSION['gio_hang']`. 2. Controller tìm lại sản phẩm trong `san_pham`. 3. Controller loại bỏ sản phẩm không tồn tại hoặc không còn hợp lệ. 4. View `giohang.php` hiển thị item và tổng tiền. 5. Người dùng cập nhật số lượng/xóa sản phẩm theo UI. |
| Luồng thay thế | Nếu giỏ hàng rỗng, view hiển thị trạng thái rỗng. |
| Luồng ngoại lệ | Sản phẩm vượt tồn kho hoặc không còn bán, hệ thống cần điều chỉnh/hiển thị cảnh báo theo logic hiện có. |
| Dữ liệu đầu vào | Mã sản phẩm, số lượng mới hoặc thao tác xóa. |
| Dữ liệu đầu ra | Giỏ hàng đã cập nhật, tổng tiền mới. |
| Giao diện liên quan | `backend/app/views/giohang.php`. |
| Route liên quan | `index.php?r=giohang`. |
| Collection/model liên quan | `SanPham`, `san_pham`, session `gio_hang`. |
| Ghi chú mức độ hoàn thiện | Có view và controller giỏ hàng; các endpoint cập nhật số lượng chi tiết cần kiểm thử theo JavaScript/form hiện tại. |

### UC-C-07 - Đặt hàng và thanh toán

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-07 |
| Tên use case | Đặt hàng và thanh toán |
| Actor chính | Khách hàng |
| Actor phụ | Hệ thống thanh toán QR/SePay nếu chọn chuyển khoản |
| Mục tiêu | Tạo đơn hàng từ giỏ hàng và thực hiện thanh toán COD hoặc QR/chuyển khoản. |
| Tiền điều kiện | Khách hàng đã đăng nhập, giỏ hàng có sản phẩm hợp lệ, tồn kho đủ. |
| Hậu điều kiện | Đơn hàng được tạo trong `hoa_don`, chi tiết trong `chi_tiet_hoa_don`, tồn kho được trừ khi đơn tạo thành công. |
| Kích hoạt | Người dùng bấm thanh toán từ giỏ hàng. |
| Luồng chính | 1. Người dùng đi đến `chuandaithanhtoan` rồi `thanhtoan`. 2. Hệ thống hiển thị form địa chỉ, voucher, điểm, phương thức thanh toán. 3. Người dùng xác nhận đặt hàng. 4. `HomeController::xulydathang()` kiểm tra dữ liệu và tồn kho. 5. `HoaDon::taoDonHang()` tạo đơn, chi tiết đơn và trừ kho. 6. Hệ thống chuyển đến `camon`. |
| Luồng thay thế | Người dùng áp dụng hoặc bỏ voucher/điểm; người dùng chọn QR/chuyển khoản và hệ thống tạo nội dung thanh toán. |
| Luồng ngoại lệ | Tồn kho không đủ, voucher không hợp lệ, dữ liệu địa chỉ thiếu hoặc thanh toán lỗi, hệ thống hiển thị thông báo và không tạo đơn không hợp lệ. |
| Dữ liệu đầu vào | Sản phẩm, số lượng, địa chỉ, phương thức thanh toán, voucher, điểm. |
| Dữ liệu đầu ra | Đơn hàng, chi tiết đơn, trạng thái thanh toán, thông báo đặt hàng. |
| Giao diện liên quan | `giohang.php`, `thanhtoan.php`, `camon.php`. |
| Route liên quan | `chuandaithanhtoan`, `thanhtoan`, `apdung_voucher`, `bo_voucher`, `apdung_diem`, `bo_diem`, `xulydathang`, `camon`, `payment_autocheck`, `payment_webhook`. |
| Collection/model liên quan | `HoaDon`, `Voucher`, `TaiKhoan`, `SanPham`, `hoa_don`, `chi_tiet_hoa_don`, `voucher`, `san_pham`. |
| Ghi chú mức độ hoàn thiện | Có nghiệp vụ COD/QR, voucher, điểm và tồn kho; thanh toán QR phụ thuộc cấu hình SePay/webhook. |

### UC-C-08 - Theo dõi đơn hàng

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-08 |
| Tên use case | Theo dõi đơn hàng |
| Actor chính | Khách hàng |
| Actor phụ | Không |
| Mục tiêu | Xem lịch sử và trạng thái đơn hàng cá nhân. |
| Tiền điều kiện | Khách hàng đã đăng nhập và có hoặc chưa có đơn hàng. |
| Hậu điều kiện | Danh sách đơn hàng được hiển thị trong hồ sơ. |
| Kích hoạt | Người dùng truy cập trang hồ sơ. |
| Luồng chính | 1. Người dùng mở `index.php?r=hoso`. 2. `TaiKhoanController::hoso()` lấy lịch sử đơn hàng. 3. View `hoso.php` hiển thị đơn hàng và trạng thái. |
| Luồng thay thế | Nếu chưa có đơn hàng, giao diện hiển thị trạng thái rỗng. |
| Luồng ngoại lệ | MongoDB lỗi, hệ thống cần log và hiển thị thông báo thân thiện tùy logic controller. |
| Dữ liệu đầu vào | Session khách hàng. |
| Dữ liệu đầu ra | Danh sách đơn, trạng thái, sản phẩm trong đơn nếu được hydrate. |
| Giao diện liên quan | `backend/app/views/hoso.php`. |
| Route liên quan | `index.php?r=hoso`. |
| Collection/model liên quan | `TaiKhoan`, `HoaDon`, `hoa_don`, `chi_tiet_hoa_don`, `san_pham`. |
| Ghi chú mức độ hoàn thiện | Có logic lấy lịch sử đơn qua model tài khoản. |

### UC-C-09 - Hủy đơn hàng

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-09 |
| Tên use case | Hủy đơn hàng |
| Actor chính | Khách hàng |
| Actor phụ | Không |
| Mục tiêu | Cho phép khách hàng hủy đơn theo điều kiện nghiệp vụ và hoàn kho nếu cần. |
| Tiền điều kiện | Khách hàng đã đăng nhập, đơn hàng thuộc về khách hàng và còn được phép hủy. |
| Hậu điều kiện | Đơn chuyển sang trạng thái hủy nếu hợp lệ; tồn kho được hoàn một lần nếu trước đó đã trừ. |
| Kích hoạt | Khách hàng bấm hủy đơn trong lịch sử đơn. |
| Luồng chính | 1. Form/action gửi đến `index.php?r=huydonhang`. 2. `QuanTriController::customerOrderCancel()` kiểm tra quyền và trạng thái. 3. Model `HoaDon` cập nhật trạng thái hủy và hoàn kho nếu phù hợp. 4. Hệ thống chuyển về trang liên quan với thông báo. |
| Luồng thay thế | Nếu đơn không còn được phép hủy, hệ thống từ chối thao tác. |
| Luồng ngoại lệ | Đơn không tồn tại, không thuộc khách hàng hoặc lỗi MongoDB, hệ thống thông báo lỗi. |
| Dữ liệu đầu vào | Mã hóa đơn. |
| Dữ liệu đầu ra | Trạng thái đơn mới, tồn kho sau hoàn nếu có. |
| Giao diện liên quan | `hoso.php` hoặc giao diện lịch sử đơn hàng. |
| Route liên quan | `index.php?r=huydonhang`. |
| Collection/model liên quan | `HoaDon`, `hoa_don`, `chi_tiet_hoa_don`, `san_pham`. |
| Ghi chú mức độ hoàn thiện | Có route hủy đơn; điều kiện hủy chi tiết phụ thuộc implementation trong model/controller. |

### UC-C-10 - Đánh giá sản phẩm đã mua

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-10 |
| Tên use case | Đánh giá sản phẩm đã mua |
| Actor chính | Khách hàng |
| Actor phụ | Nhân viên/Quản trị viên nhận thông báo và phản hồi |
| Mục tiêu | Cho phép khách hàng gửi đánh giá sản phẩm sau khi mua và đơn hoàn thành. |
| Tiền điều kiện | Khách hàng đăng nhập, đã mua sản phẩm, đơn hàng ở trạng thái `Hoàn thành`/normalized `completed`. |
| Hậu điều kiện | Review được lưu vào `danh_gia_san_pham`, thông báo review mới được tạo. |
| Kích hoạt | Khách hàng mở tab Đánh giá ở trang chi tiết và gửi form. |
| Luồng chính | 1. `SanPhamController::chitiet()` kiểm tra quyền đánh giá qua `DanhGia::canUserReviewProduct()`. 2. Nếu đủ điều kiện, view hiển thị form chọn sao, nội dung và upload ảnh nếu có. 3. Người dùng gửi form đến `index.php?r=guidanhgia`. 4. `QuanTriController::customerReviewSave()` gọi `DanhGia::addReview()`. 5. Review được lưu vào `danh_gia_san_pham`; ảnh lưu path trong `hinh_anh`. 6. Hệ thống tạo thông báo loại `review`. 7. Người dùng được chuyển về trang sản phẩm. |
| Luồng thay thế | Nếu chỉ có rating crawl nhưng chưa có review chi tiết, tab vẫn hiển thị tổng quan và empty state phù hợp. |
| Luồng ngoại lệ | Chưa đăng nhập, chưa mua, đơn chưa hoàn thành hoặc đã đánh giá, hệ thống không cho gửi review và hiển thị thông báo phù hợp. |
| Dữ liệu đầu vào | Mã sản phẩm, số sao, nội dung, hình ảnh nếu có. |
| Dữ liệu đầu ra | Review chi tiết, thống kê sao cập nhật, thông báo admin/staff. |
| Giao diện liên quan | `backend/app/views/chitiet.php`, tab Đánh giá. |
| Route liên quan | `index.php?r=chitiet&id=...#danhgia`, `index.php?r=guidanhgia`. |
| Collection/model liên quan | `DanhGia`, `danh_gia_san_pham`, legacy `danh_gia`, `hoa_don`, `chi_tiet_hoa_don`, `thong_bao`. |
| Ghi chú mức độ hoàn thiện | Review mới ghi vào `danh_gia_san_pham`; legacy `danh_gia` vẫn được đọc để không mất dữ liệu cũ. |

### UC-C-11 - Gửi hỏi đáp sản phẩm

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-C-11 |
| Tên use case | Gửi hỏi đáp sản phẩm |
| Actor chính | Khách hàng |
| Actor phụ | Nhân viên/Quản trị viên nhận thông báo và trả lời |
| Mục tiêu | Cho phép khách hàng đặt câu hỏi về sản phẩm. |
| Tiền điều kiện | Khách hàng đã đăng nhập; sản phẩm tồn tại. |
| Hậu điều kiện | Câu hỏi được lưu vào `hoi_dap_san_pham` và tạo thông báo cho admin/staff. |
| Kích hoạt | Người dùng mở tab Hỏi đáp ở trang chi tiết và gửi câu hỏi. |
| Luồng chính | 1. Người dùng nhập câu hỏi trong tab Hỏi đáp. 2. Form gửi đến `index.php?r=guicauhoi`. 3. `QuanTriController::customerQuestionSave()` gọi `HoiDap::addQuestion()`. 4. Model lưu câu hỏi vào `hoi_dap_san_pham`. 5. Model tạo thông báo `hoi_dap_moi` trong `thong_bao`. 6. Hệ thống chuyển về trang sản phẩm/tab hỏi đáp. |
| Luồng thay thế | Nếu chưa có câu hỏi nào, tab hiển thị empty state. |
| Luồng ngoại lệ | Chưa đăng nhập hoặc dữ liệu câu hỏi rỗng, hệ thống yêu cầu đăng nhập/nhập nội dung hợp lệ. |
| Dữ liệu đầu vào | Mã sản phẩm, mã khách hàng, nội dung câu hỏi. |
| Dữ liệu đầu ra | Câu hỏi mới, thông báo admin/staff. |
| Giao diện liên quan | `backend/app/views/chitiet.php`, tab Hỏi đáp. |
| Route liên quan | `index.php?r=chitiet&id=...#hoidap`, `index.php?r=guicauhoi`. |
| Collection/model liên quan | `HoiDap`, `hoi_dap_san_pham`, `thong_bao`, `SanPham`. |
| Ghi chú mức độ hoàn thiện | Có model Q&A và notification; admin/staff trả lời qua `admin_questions`. |

## 4. Use case của Nhân viên

### UC-S-01 - Xem danh sách đơn hàng

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-S-01 |
| Tên use case | Xem danh sách đơn hàng |
| Actor chính | Nhân viên |
| Actor phụ | Không |
| Mục tiêu | Theo dõi và xử lý danh sách đơn hàng được phân quyền. |
| Tiền điều kiện | Nhân viên đã đăng nhập và có quyền truy cập staff orders. |
| Hậu điều kiện | Danh sách đơn hàng được hiển thị. |
| Kích hoạt | Nhân viên truy cập `index.php?r=staff_orders`. |
| Luồng chính | 1. Router gọi `QuanTriController::staffOrders()`. 2. Controller kiểm tra quyền. 3. Model lấy danh sách đơn hàng và filter/search nếu có. 4. View `admin/staff_orders.php` hiển thị danh sách và chi tiết. |
| Luồng thay thế | Nhân viên dùng search/filter theo mã đơn, khách hàng, trạng thái. |
| Luồng ngoại lệ | Không đủ quyền, hệ thống chặn truy cập; MongoDB lỗi thì cần log và thông báo. |
| Dữ liệu đầu vào | Bộ lọc/search/page nếu có. |
| Dữ liệu đầu ra | Danh sách đơn hàng. |
| Giao diện liên quan | `backend/app/views/admin/staff_orders.php`. |
| Route liên quan | `index.php?r=staff_orders`. |
| Collection/model liên quan | `QuanTri`, `HoaDon`, `hoa_don`, `chi_tiet_hoa_don`, `san_pham`. |
| Ghi chú mức độ hoàn thiện | Có route staff riêng cho đơn hàng. |

### UC-S-02 - Cập nhật trạng thái đơn hàng

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-S-02 |
| Tên use case | Cập nhật trạng thái đơn hàng |
| Actor chính | Nhân viên |
| Actor phụ | Khách hàng bị ảnh hưởng trạng thái đơn |
| Mục tiêu | Cập nhật đơn qua 5 trạng thái chuẩn của hệ thống. |
| Tiền điều kiện | Nhân viên có quyền cập nhật đơn; đơn hàng tồn tại. |
| Hậu điều kiện | Trạng thái đơn được cập nhật; nếu hủy thì hoàn kho một lần; nếu hoàn thành thì doanh thu có thể được ghi nhận. |
| Kích hoạt | Nhân viên chọn trạng thái mới và gửi form. |
| Luồng chính | 1. Form gửi đến `index.php?r=staff_order_status`. 2. `QuanTriController::staffOrderStatus()` kiểm tra quyền. 3. Model `HoaDon` normalize trạng thái. 4. Hệ thống lưu trạng thái mới. 5. Nếu trạng thái là hủy, xử lý hoàn kho nếu đủ điều kiện. |
| Luồng thay thế | Admin dùng route `admin_order_status` với logic tương tự. |
| Luồng ngoại lệ | Trạng thái không hợp lệ hoặc đơn không tồn tại, hệ thống từ chối cập nhật. |
| Dữ liệu đầu vào | Mã đơn, trạng thái mới. |
| Dữ liệu đầu ra | Đơn hàng đã cập nhật, thông báo kết quả. |
| Giao diện liên quan | `admin/staff_orders.php`. |
| Route liên quan | `index.php?r=staff_order_status`. |
| Collection/model liên quan | `HoaDon`, `hoa_don`, `chi_tiet_hoa_don`, `san_pham`, `thong_bao`. |
| Ghi chú mức độ hoàn thiện | Hệ thống chuẩn hóa 5 trạng thái: Chờ xử lý, Đã xác nhận, Đang giao, Hoàn thành, Đã hủy. |

### UC-S-03 - Phản hồi đánh giá sản phẩm

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-S-03 |
| Tên use case | Phản hồi đánh giá sản phẩm |
| Actor chính | Nhân viên |
| Actor phụ | Khách hàng |
| Mục tiêu | Xử lý đánh giá cần phản hồi, đặc biệt đánh giá thấp hoặc chưa phản hồi. |
| Tiền điều kiện | Nhân viên đã đăng nhập; có đánh giá trong hệ thống. |
| Hậu điều kiện | Đánh giá được cập nhật `phan_hoi_shop`. |
| Kích hoạt | Nhân viên truy cập `index.php?r=staff_reviews`. |
| Luồng chính | 1. `QuanTriController::staffReviews()` lấy danh sách review. 2. Model ưu tiên review chưa phản hồi và cần xử lý. 3. View `admin/reviews.php` hiển thị mã review, sản phẩm, khách hàng, sao, nội dung. 4. Nhân viên nhập phản hồi. 5. Form gửi `index.php?r=staff_review_reply`. 6. Model lưu `phan_hoi_shop`. |
| Luồng thay thế | Nếu review thuộc legacy collection, model có thể map dữ liệu cũ để hiển thị/ghi phản hồi theo logic hiện tại. |
| Luồng ngoại lệ | Review không tồn tại hoặc không đủ quyền, hệ thống báo lỗi. |
| Dữ liệu đầu vào | Mã đánh giá, nội dung phản hồi. |
| Dữ liệu đầu ra | Phản hồi shop hiển thị ở tab Đánh giá. |
| Giao diện liên quan | `backend/app/views/admin/reviews.php`, `chitiet.php` tab Đánh giá. |
| Route liên quan | `index.php?r=staff_reviews`, `index.php?r=staff_review_reply`. |
| Collection/model liên quan | `QuanTri`, `DanhGia`, `danh_gia_san_pham`, legacy `danh_gia`, `SanPham`. |
| Ghi chú mức độ hoàn thiện | Có route staff reviews và model đọc cả collection mới/cũ. |

### UC-S-04 - Trả lời hỏi đáp sản phẩm

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-S-04 |
| Tên use case | Trả lời hỏi đáp sản phẩm |
| Actor chính | Nhân viên |
| Actor phụ | Khách hàng |
| Mục tiêu | Trả lời câu hỏi sản phẩm do khách hàng gửi. |
| Tiền điều kiện | Nhân viên có quyền truy cập `admin_questions`; có câu hỏi trong `hoi_dap_san_pham`. |
| Hậu điều kiện | Câu hỏi có object `tra_loi` và hiển thị trên trang chi tiết sản phẩm. |
| Kích hoạt | Nhân viên mở trang quản lý hỏi đáp. |
| Luồng chính | 1. Nhân viên truy cập `index.php?r=admin_questions`. 2. `QuanTriController::adminQuestions()` lấy danh sách câu hỏi qua `HoiDap`. 3. View hiển thị câu hỏi kèm thông tin sản phẩm. 4. Nhân viên nhập câu trả lời. 5. Form gửi `index.php?r=admin_question_reply`. 6. Model cập nhật `tra_loi`. |
| Luồng thay thế | Nhân viên có thể ẩn câu hỏi qua `admin_question_hide`. |
| Luồng ngoại lệ | Câu hỏi không tồn tại hoặc dữ liệu trả lời rỗng, hệ thống từ chối cập nhật. |
| Dữ liệu đầu vào | Mã hỏi đáp, nội dung trả lời. |
| Dữ liệu đầu ra | Câu trả lời shop trong tab Hỏi đáp. |
| Giao diện liên quan | `admin/questions.php`, `chitiet.php` tab Hỏi đáp. |
| Route liên quan | `index.php?r=admin_questions`, `admin_question_reply`, `admin_question_hide`. |
| Collection/model liên quan | `HoiDap`, `hoi_dap_san_pham`, `SanPham`. |
| Ghi chú mức độ hoàn thiện | Route dùng chung cho admin/staff tùy quyền. |

### UC-S-05 - Xem thông báo công việc

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-S-05 |
| Tên use case | Xem thông báo công việc |
| Actor chính | Nhân viên |
| Actor phụ | Không |
| Mục tiêu | Theo dõi nhanh đơn hàng, đánh giá, hỏi đáp và chat cần xử lý. |
| Tiền điều kiện | Nhân viên đã đăng nhập vào giao diện quản trị/staff. |
| Hậu điều kiện | Danh sách thông báo được hiển thị; có thể đánh dấu đã xem. |
| Kích hoạt | Nhân viên mở dropdown thông báo trên header admin/staff. |
| Luồng chính | 1. `QuanTriController::renderAdmin()` lấy notification center. 2. `QuanTri::getNotificationCenterData()` tổng hợp đơn hàng, review, question, chat. 3. Header admin hiển thị badge và danh sách thông báo. 4. Người dùng bấm thông báo để đi đến route xử lý. |
| Luồng thay thế | Người dùng gọi `admin_notifications_seen` để đánh dấu đã xem. |
| Luồng ngoại lệ | Không có thông báo, giao diện hiển thị empty state. |
| Dữ liệu đầu vào | Session nhân viên. |
| Dữ liệu đầu ra | Badge số lượng chưa đọc và danh sách thông báo. |
| Giao diện liên quan | `backend/app/views/admin/layouts/header.php`. |
| Route liên quan | `index.php?r=admin_notifications_seen`, các route đích `staff_orders`, `staff_reviews`, `admin_questions`, `staff_chats`. |
| Collection/model liên quan | `QuanTri`, `thong_bao`, `hoa_don`, `lich_su_chat`. |
| Ghi chú mức độ hoàn thiện | Có notification center thật; phụ thuộc dữ liệu thông báo được tạo bởi các nghiệp vụ tương ứng. |

## 5. Use case của Quản trị viên

### UC-A-01 - Quản lý sản phẩm

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-01 |
| Tên use case | Quản lý sản phẩm |
| Actor chính | Quản trị viên |
| Actor phụ | Không |
| Mục tiêu | Thêm, sửa, xóa/ẩn, tìm kiếm, lọc và cập nhật tồn kho sản phẩm. |
| Tiền điều kiện | Admin đã đăng nhập và có quyền quản lý sản phẩm. |
| Hậu điều kiện | Dữ liệu sản phẩm trong `san_pham` được cập nhật. |
| Kích hoạt | Admin truy cập trang sản phẩm. |
| Luồng chính | 1. Admin mở `index.php?r=admin_sp`. 2. Controller lấy danh sách sản phẩm qua `SanPham::paginate()`. 3. Admin chọn thêm/sửa/xóa/ẩn/cập nhật tồn kho. 4. Các route tương ứng gọi model `SanPham` để ghi MongoDB. |
| Luồng thay thế | Staff có route sản phẩm riêng với quyền hạn thấp hơn. |
| Luồng ngoại lệ | Dữ liệu không hợp lệ hoặc sản phẩm không tồn tại, hệ thống báo lỗi. |
| Dữ liệu đầu vào | Tên, giá, danh mục, thương hiệu, ảnh, trạng thái, tồn kho. |
| Dữ liệu đầu ra | Sản phẩm đã tạo/cập nhật, danh sách sản phẩm mới. |
| Giao diện liên quan | `admin/danhsachSP.php`, `admin/themSP.php`, `admin/suaSP.php`. |
| Route liên quan | `admin_sp`, `admin_sp_create`, `admin_sp_edit`, `admin_sp_delete`, `admin_sp_visibility`, `admin_sp_stock`. |
| Collection/model liên quan | `SanPham`, `san_pham`, `danh_muc`, `thuong_hieu`. |
| Ghi chú mức độ hoàn thiện | Có CRUD và tồn kho; dữ liệu sản phẩm nhiều field phụ thuộc dữ liệu crawl/import. |

### UC-A-02 - Quản lý danh mục

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-02 |
| Tên use case | Quản lý danh mục |
| Actor chính | Quản trị viên |
| Actor phụ | Không |
| Mục tiêu | Quản lý danh mục sản phẩm dùng cho lọc và phân loại. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Hậu điều kiện | Collection danh mục được cập nhật. |
| Kích hoạt | Admin truy cập `index.php?r=admin_categories`. |
| Luồng chính | 1. Controller hiển thị danh sách danh mục. 2. Admin nhập/sửa thông tin. 3. Form gửi `admin_category_save`. 4. Model lưu vào MongoDB. |
| Luồng thay thế | Admin xóa danh mục qua `admin_category_delete`. |
| Luồng ngoại lệ | Dữ liệu trùng/không hợp lệ, hệ thống báo lỗi. |
| Dữ liệu đầu vào | Tên danh mục, trạng thái hoặc thông tin liên quan. |
| Dữ liệu đầu ra | Danh mục đã cập nhật. |
| Giao diện liên quan | `admin/categories.php`. |
| Route liên quan | `admin_categories`, `admin_category_save`, `admin_category_delete`. |
| Collection/model liên quan | `QuanTri`, `danh_muc`. |
| Ghi chú mức độ hoàn thiện | Có route admin category trong router. |

### UC-A-03 - Quản lý thương hiệu

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-03 |
| Tên use case | Quản lý thương hiệu |
| Actor chính | Quản trị viên |
| Actor phụ | Không |
| Mục tiêu | Quản lý dữ liệu thương hiệu phục vụ hiển thị và lọc sản phẩm. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Hậu điều kiện | Dữ liệu thương hiệu được sử dụng bởi filter/list sản phẩm nếu tồn tại trong collection. |
| Kích hoạt | Admin thao tác trong khu vực quản trị sản phẩm hoặc dữ liệu liên quan thương hiệu. |
| Luồng chính | 1. Admin vào quản lý sản phẩm. 2. Hệ thống lấy danh sách thương hiệu qua `SanPham::listBrandOptions()`. 3. Admin gán/sửa thương hiệu trong form sản phẩm. 4. Sản phẩm được lưu với field `thuong_hieu`. |
| Luồng thay thế | Nếu có collection `thuong_hieu`, model có thể lấy danh sách brand option từ collection này. |
| Luồng ngoại lệ | Không tìm thấy collection thương hiệu riêng, hệ thống vẫn sử dụng field `thuong_hieu` trong `san_pham`. |
| Dữ liệu đầu vào | Tên thương hiệu trong form sản phẩm. |
| Dữ liệu đầu ra | Field thương hiệu trong sản phẩm, option filter. |
| Giao diện liên quan | `admin/themSP.php`, `admin/suaSP.php`, `admin/danhsachSP.php`. |
| Route liên quan | `admin_sp_create`, `admin_sp_edit`, `admin_sp`. |
| Collection/model liên quan | `SanPham`, `san_pham`, `thuong_hieu` nếu có. |
| Ghi chú mức độ hoàn thiện | Router không thể hiện route CRUD thương hiệu độc lập; quản lý thương hiệu chủ yếu thông qua dữ liệu sản phẩm/option. |

### UC-A-04 - Quản lý người dùng/nhân viên

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-04 |
| Tên use case | Quản lý người dùng/nhân viên |
| Actor chính | Quản trị viên |
| Actor phụ | Không |
| Mục tiêu | Quản lý tài khoản khách hàng và nhân viên. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Hậu điều kiện | Dữ liệu khách hàng/nhân viên được tạo, cập nhật hoặc xóa theo thao tác. |
| Kích hoạt | Admin truy cập `index.php?r=admin_users`. |
| Luồng chính | 1. Controller lấy danh sách khách hàng và nhân viên. 2. View `admin/users.php` hiển thị bảng/form. 3. Admin lưu khách hàng hoặc nhân viên. 4. Controller gọi các route save/delete tương ứng. |
| Luồng thay thế | Admin có thể xóa mềm hoặc hard delete nhân viên theo route hiện có. |
| Luồng ngoại lệ | Dữ liệu không hợp lệ, email trùng hoặc không đủ quyền, hệ thống báo lỗi. |
| Dữ liệu đầu vào | Họ tên, email, mật khẩu, vai trò, trạng thái. |
| Dữ liệu đầu ra | Danh sách người dùng/nhân viên đã cập nhật. |
| Giao diện liên quan | `admin/users.php`. |
| Route liên quan | `admin_users`, `admin_customer_save`, `admin_customer_delete`, `admin_staff_save`, `admin_staff_delete`, `admin_staff_hard_delete`. |
| Collection/model liên quan | `QuanTri`, `khach_hang`, `nhan_vien`, `nguoidung`, `vai_tro`. |
| Ghi chú mức độ hoàn thiện | Có route quản lý khách hàng/nhân viên. |

### UC-A-05 - Quản lý đơn hàng

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-05 |
| Tên use case | Quản lý đơn hàng |
| Actor chính | Quản trị viên |
| Actor phụ | Khách hàng |
| Mục tiêu | Theo dõi, tìm kiếm, xem chi tiết và cập nhật trạng thái đơn hàng. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Hậu điều kiện | Danh sách hoặc trạng thái đơn hàng được cập nhật. |
| Kích hoạt | Admin truy cập `index.php?r=admin_orders`. |
| Luồng chính | 1. `QuanTriController::adminOrders()` lấy danh sách đơn hàng. 2. View `admin/orders.php` hiển thị bảng và chi tiết. 3. Admin lọc/search hoặc xem chi tiết. 4. Admin cập nhật trạng thái qua `admin_order_status`. 5. Model `HoaDon` chuẩn hóa trạng thái và xử lý nghiệp vụ liên quan. |
| Luồng thay thế | Staff xử lý đơn qua `staff_orders`. |
| Luồng ngoại lệ | Đơn không tồn tại, trạng thái không hợp lệ hoặc lỗi MongoDB, hệ thống báo lỗi. |
| Dữ liệu đầu vào | Mã đơn, filter/search, trạng thái mới. |
| Dữ liệu đầu ra | Danh sách đơn, chi tiết đơn, trạng thái đơn mới. |
| Giao diện liên quan | `admin/orders.php`. |
| Route liên quan | `admin_orders`, `admin_order_status`. |
| Collection/model liên quan | `QuanTri`, `HoaDon`, `hoa_don`, `chi_tiet_hoa_don`, `san_pham`, `thong_bao`. |
| Ghi chú mức độ hoàn thiện | Có 5 trạng thái chuẩn; doanh thu chỉ ghi nhận khi đơn hoàn thành hợp lệ. |

### UC-A-06 - Quản lý voucher

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-06 |
| Tên use case | Quản lý voucher |
| Actor chính | Quản trị viên |
| Actor phụ | Không |
| Mục tiêu | Tạo và cập nhật mã giảm giá dùng trong checkout. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Hậu điều kiện | Voucher được lưu và có thể được khách hàng áp dụng nếu hợp lệ. |
| Kích hoạt | Admin truy cập `index.php?r=admin_vouchers`. |
| Luồng chính | 1. Controller hiển thị danh sách/form voucher. 2. Admin nhập thông tin voucher. 3. Form gửi `admin_voucher_save`. 4. Model lưu voucher. |
| Luồng thay thế | Admin xóa voucher qua `admin_voucher_delete`. |
| Luồng ngoại lệ | Mã trùng, giá trị không hợp lệ hoặc hết hạn, hệ thống báo lỗi. |
| Dữ liệu đầu vào | Mã voucher, tên, loại giảm, giá trị giảm, điều kiện, trạng thái. |
| Dữ liệu đầu ra | Voucher đã cập nhật. |
| Giao diện liên quan | `admin/vouchers.php`. |
| Route liên quan | `admin_vouchers`, `admin_voucher_save`, `admin_voucher_delete`. |
| Collection/model liên quan | `Voucher`, `voucher`. |
| Ghi chú mức độ hoàn thiện | Có route admin và route áp dụng voucher ở checkout. |

### UC-A-07 - Xem báo cáo doanh thu

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-07 |
| Tên use case | Xem báo cáo doanh thu |
| Actor chính | Quản trị viên |
| Actor phụ | Không |
| Mục tiêu | Xem số liệu doanh thu và sản phẩm theo dữ liệu đơn hàng. |
| Tiền điều kiện | Admin đã đăng nhập; có dữ liệu đơn hàng. |
| Hậu điều kiện | Báo cáo được hiển thị. |
| Kích hoạt | Admin truy cập `index.php?r=admin_reports`. |
| Luồng chính | 1. `QuanTriController::adminReports()` gọi model báo cáo. 2. Model chỉ tính doanh thu từ đơn normalized `completed`. 3. Với QR/chuyển khoản, nếu có trạng thái thanh toán thì chỉ tính đơn đã thanh toán. 4. View `admin/reports.php` hiển thị số liệu. |
| Luồng thay thế | Nếu không có đơn hoàn thành, báo cáo hiển thị số liệu bằng 0 hoặc empty state. |
| Luồng ngoại lệ | Lỗi dữ liệu ngày/tổng tiền, hệ thống cần xử lý an toàn để không hiển thị sai như ngày 01/01/1970. |
| Dữ liệu đầu vào | Khoảng thời gian/filter nếu có. |
| Dữ liệu đầu ra | Doanh thu, thống kê đơn, sản phẩm top doanh thu. |
| Giao diện liên quan | `admin/reports.php`. |
| Route liên quan | `index.php?r=admin_reports`. |
| Collection/model liên quan | `QuanTri`, `HoaDon`, `hoa_don`, `chi_tiet_hoa_don`, `san_pham`. |
| Ghi chú mức độ hoàn thiện | Báo cáo doanh thu phụ thuộc trạng thái đơn và trạng thái thanh toán. |

### UC-A-08 - Quản lý hỏi đáp

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-08 |
| Tên use case | Quản lý hỏi đáp |
| Actor chính | Quản trị viên |
| Actor phụ | Khách hàng |
| Mục tiêu | Xem, tìm kiếm, trả lời hoặc ẩn câu hỏi sản phẩm. |
| Tiền điều kiện | Admin đã đăng nhập; có hoặc chưa có câu hỏi. |
| Hậu điều kiện | Câu hỏi được trả lời hoặc thay đổi trạng thái nếu admin thao tác. |
| Kích hoạt | Admin truy cập `index.php?r=admin_questions`. |
| Luồng chính | 1. Controller lấy câu hỏi qua `HoiDap::listAdminQuestions()`. 2. Model lookup thông tin sản phẩm bằng `SanPham::getProductBriefById()`. 3. View hiển thị câu hỏi, sản phẩm, trạng thái trả lời. 4. Admin trả lời hoặc ẩn câu hỏi. |
| Luồng thay thế | Nếu không tìm thấy sản phẩm, view chỉ hiển thị mã sản phẩm và cảnh báo. |
| Luồng ngoại lệ | Câu hỏi không tồn tại hoặc không đủ quyền, hệ thống báo lỗi. |
| Dữ liệu đầu vào | Filter/search, mã hỏi đáp, nội dung trả lời. |
| Dữ liệu đầu ra | Danh sách câu hỏi, câu trả lời, trạng thái câu hỏi. |
| Giao diện liên quan | `admin/questions.php`. |
| Route liên quan | `admin_questions`, `admin_question_reply`, `admin_question_hide`. |
| Collection/model liên quan | `HoiDap`, `hoi_dap_san_pham`, `SanPham`, `san_pham`. |
| Ghi chú mức độ hoàn thiện | Có quản lý hỏi đáp và link về tab Hỏi đáp sản phẩm. |

### UC-A-09 - Quản lý đánh giá

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-09 |
| Tên use case | Quản lý đánh giá |
| Actor chính | Quản trị viên |
| Actor phụ | Khách hàng |
| Mục tiêu | Theo dõi và phản hồi đánh giá sản phẩm. |
| Tiền điều kiện | Admin hoặc staff đã đăng nhập. |
| Hậu điều kiện | Đánh giá có phản hồi shop nếu được xử lý. |
| Kích hoạt | Admin/staff truy cập `index.php?r=staff_reviews`. |
| Luồng chính | 1. Controller lấy danh sách review từ `QuanTri::listReviews()`. 2. View hiển thị review và sản phẩm liên quan. 3. Admin/staff nhập phản hồi. 4. Route `staff_review_reply` cập nhật review. |
| Luồng thay thế | Legacy review từ `danh_gia` có thể được đọc để hiển thị. |
| Luồng ngoại lệ | Review không tồn tại hoặc lỗi ghi dữ liệu, hệ thống báo lỗi. |
| Dữ liệu đầu vào | Filter/search, mã đánh giá, nội dung phản hồi. |
| Dữ liệu đầu ra | Phản hồi shop, danh sách review đã cập nhật. |
| Giao diện liên quan | `admin/reviews.php`, `chitiet.php` tab Đánh giá. |
| Route liên quan | `staff_reviews`, `staff_review_reply`. |
| Collection/model liên quan | `QuanTri`, `DanhGia`, `danh_gia_san_pham`, legacy `danh_gia`, `SanPham`. |
| Ghi chú mức độ hoàn thiện | Route tên staff nhưng admin cũng có thể dùng tùy phân quyền. |

### UC-A-10 - Xem thông báo

| Thành phần | Đặc tả |
| --- | --- |
| Mã use case | UC-A-10 |
| Tên use case | Xem thông báo |
| Actor chính | Quản trị viên |
| Actor phụ | Không |
| Mục tiêu | Theo dõi thông báo vận hành mới nhất. |
| Tiền điều kiện | Admin đã đăng nhập. |
| Hậu điều kiện | Admin nắm được các tác vụ cần xử lý; thông báo có thể được đánh dấu đã xem. |
| Kích hoạt | Admin mở dropdown chuông thông báo. |
| Luồng chính | 1. Layout admin nhận `notificationCenter`. 2. Header hiển thị badge chưa đọc. 3. Admin xem các nhóm đơn hàng, đánh giá, hỏi đáp, chat. 4. Admin bấm thông báo để đi đến trang xử lý. |
| Luồng thay thế | Admin gọi route đánh dấu đã xem. |
| Luồng ngoại lệ | Không có thông báo, dropdown hiển thị empty state. |
| Dữ liệu đầu vào | Session admin. |
| Dữ liệu đầu ra | Danh sách thông báo và badge. |
| Giao diện liên quan | `backend/app/views/admin/layouts/header.php`. |
| Route liên quan | `admin_notifications_seen`, `admin_orders`, `staff_reviews`, `admin_questions`, `staff_chats`. |
| Collection/model liên quan | `QuanTri`, `thong_bao`, `hoa_don`, `lich_su_chat`. |
| Ghi chú mức độ hoàn thiện | Có thông báo order, review, question, chat theo source. |

## 6. Bảng tổng hợp use case

| Mã use case | Tên use case | Actor chính | Route chính | Mức độ hoàn thiện |
| --- | --- | --- | --- | --- |
| UC-G-01 | Đăng ký tài khoản | Khách vãng lai | `dangky`, `xulydangky` | Có route/controller; view auth cần xác nhận runtime. |
| UC-G-02 | Đăng nhập | Khách vãng lai | `dangnhap`, `xulydangnhap` | Có route/controller. |
| UC-G-03 | Xem trang chủ | Khách vãng lai | `home` | Có giao diện và logic sản phẩm. |
| UC-G-04 | Tìm kiếm/lọc sản phẩm | Khách vãng lai | `tatca`, `live_search`, `api_smart_search` | Có logic tìm kiếm/filter. |
| UC-G-05 | Xem chi tiết sản phẩm | Khách vãng lai | `chitiet` | Có đầy đủ UI chi tiết, review, Q&A. |
| UC-G-06 | Xem gợi ý công khai tại `/goiy` | Khách vãng lai | `goiy` | Có public discovery, không AI. |
| UC-G-07 | Xem tất cả sản phẩm theo nhóm | Khách vãng lai | `product_collection` | Có route collection. |
| UC-C-01 | Quản lý hồ sơ cá nhân | Khách hàng | `hoso` | Có route và view. |
| UC-C-02 | Thực hiện khảo sát hồ sơ da | Khách hàng | `khaosat`, `xulykhaosat` | Có route khảo sát. |
| UC-C-03 | Nhận gợi ý sản phẩm cá nhân hóa | Khách hàng | `goiy`, Flask `/api/recommend/llamaindex` | Có LlamaIndex thật; phụ thuộc Flask/index/API key. |
| UC-C-04 | Chat với AI tư vấn mỹ phẩm | Khách hàng | `ai_chat_assistant`, Flask `/api/chat` | Có chatbot LangChain + ChromaDB. |
| UC-C-05 | Thêm sản phẩm vào giỏ hàng | Khách hàng | `them_gio_hang_ajax` | Có AJAX/session cart; cần test từng vị trí nút. |
| UC-C-06 | Cập nhật giỏ hàng | Khách hàng | `giohang` | Có view/controller giỏ hàng. |
| UC-C-07 | Đặt hàng và thanh toán | Khách hàng | `thanhtoan`, `xulydathang` | Có COD/QR/voucher/điểm/tồn kho. |
| UC-C-08 | Theo dõi đơn hàng | Khách hàng | `hoso` | Có lịch sử đơn trong hồ sơ. |
| UC-C-09 | Hủy đơn hàng | Khách hàng | `huydonhang` | Có route hủy đơn, hoàn kho theo model. |
| UC-C-10 | Đánh giá sản phẩm đã mua | Khách hàng | `guidanhgia` | Có review mới và đọc legacy. |
| UC-C-11 | Gửi hỏi đáp sản phẩm | Khách hàng | `guicauhoi` | Có Q&A và notification. |
| UC-S-01 | Xem danh sách đơn hàng | Nhân viên | `staff_orders` | Có route staff orders. |
| UC-S-02 | Cập nhật trạng thái đơn hàng | Nhân viên | `staff_order_status` | Có trạng thái đơn chuẩn. |
| UC-S-03 | Phản hồi đánh giá sản phẩm | Nhân viên | `staff_reviews`, `staff_review_reply` | Có route và model phản hồi. |
| UC-S-04 | Trả lời hỏi đáp sản phẩm | Nhân viên | `admin_questions`, `admin_question_reply` | Route dùng chung admin/staff. |
| UC-S-05 | Xem thông báo công việc | Nhân viên | `admin_notifications_seen` | Có notification center. |
| UC-A-01 | Quản lý sản phẩm | Quản trị viên | `admin_sp` | Có CRUD/tồn kho. |
| UC-A-02 | Quản lý danh mục | Quản trị viên | `admin_categories` | Có route category. |
| UC-A-03 | Quản lý thương hiệu | Quản trị viên | `admin_sp` | Chưa thấy route CRUD thương hiệu riêng; quản lý qua field/option sản phẩm. |
| UC-A-04 | Quản lý người dùng/nhân viên | Quản trị viên | `admin_users` | Có route user/staff. |
| UC-A-05 | Quản lý đơn hàng | Quản trị viên | `admin_orders` | Có search/detail/status. |
| UC-A-06 | Quản lý voucher | Quản trị viên | `admin_vouchers` | Có route voucher. |
| UC-A-07 | Xem báo cáo doanh thu | Quản trị viên | `admin_reports` | Có report, doanh thu theo completed. |
| UC-A-08 | Quản lý hỏi đáp | Quản trị viên | `admin_questions` | Có Q&A admin. |
| UC-A-09 | Quản lý đánh giá | Quản trị viên | `staff_reviews` | Có route review dùng chung. |
| UC-A-10 | Xem thông báo | Quản trị viên | Admin header | Có notification center. |

## 7. Bảng actor - use case

| Actor | Use case |
| --- | --- |
| Khách vãng lai | UC-G-01 Đăng ký tài khoản; UC-G-02 Đăng nhập; UC-G-03 Xem trang chủ; UC-G-04 Tìm kiếm/lọc sản phẩm; UC-G-05 Xem chi tiết sản phẩm; UC-G-06 Xem gợi ý công khai tại `/goiy`; UC-G-07 Xem tất cả sản phẩm theo nhóm. |
| Khách hàng | UC-C-01 Quản lý hồ sơ cá nhân; UC-C-02 Thực hiện khảo sát hồ sơ da; UC-C-03 Nhận gợi ý sản phẩm cá nhân hóa; UC-C-04 Chat với AI tư vấn mỹ phẩm; UC-C-05 Thêm sản phẩm vào giỏ hàng; UC-C-06 Cập nhật giỏ hàng; UC-C-07 Đặt hàng và thanh toán; UC-C-08 Theo dõi đơn hàng; UC-C-09 Hủy đơn hàng; UC-C-10 Đánh giá sản phẩm đã mua; UC-C-11 Gửi hỏi đáp sản phẩm. |
| Nhân viên | UC-S-01 Xem danh sách đơn hàng; UC-S-02 Cập nhật trạng thái đơn hàng; UC-S-03 Phản hồi đánh giá sản phẩm; UC-S-04 Trả lời hỏi đáp sản phẩm; UC-S-05 Xem thông báo công việc. |
| Quản trị viên | UC-A-01 Quản lý sản phẩm; UC-A-02 Quản lý danh mục; UC-A-03 Quản lý thương hiệu; UC-A-04 Quản lý người dùng/nhân viên; UC-A-05 Quản lý đơn hàng; UC-A-06 Quản lý voucher; UC-A-07 Xem báo cáo doanh thu; UC-A-08 Quản lý hỏi đáp; UC-A-09 Quản lý đánh giá; UC-A-10 Xem thông báo. |
| Hệ thống AI/Flask service | Tham gia UC-C-03 Nhận gợi ý sản phẩm cá nhân hóa và UC-C-04 Chat với AI tư vấn mỹ phẩm. |

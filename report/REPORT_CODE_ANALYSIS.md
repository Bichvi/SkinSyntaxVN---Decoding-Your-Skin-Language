# SkinSyntaxVN - Source Code Analysis

Ngay lap: 2026-05-30  
Pham vi: phan tich source code hien tai, khong sua code.

## 1. Tong quan kien truc

Project SkinSyntaxVN la ung dung PHP MVC dung MongoDB lam co so du lieu chinh. Entry point web la `backend/public/index.php`, route duoc dieu huong bang query string `r`, vi du `index.php?r=chitiet&id=...`.

Thanh phan chinh:

| Tang | Thu muc/file chinh | Vai tro |
| --- | --- | --- |
| Router | `backend/public/index.php` | Khoi tao session, config, MongoDB, helper, controller va switch route theo `r`. |
| Controller | `backend/app/controllers/*.php` | Xu ly request, goi model, render view. |
| Model | `backend/app/models/*.php` | Truy van MongoDB va nghiep vu san pham, don hang, tai khoan, danh gia, hoi dap, voucher, thong ke. |
| View | `backend/app/views/*.php` | Giao dien khach hang, admin, staff. |
| Config/helper | `backend/app/config/*.php`, `backend/app/helpers.php` | Cau hinh URL, AI endpoint, MongoDB, dinh dang, session, role, escape output. |
| AI chatbot | `ai-service-flask/chatbot_flask.py` | Flask chatbot rieng, LangChain + ChromaDB, port 5001. |
| AI recommendation | `ai-service-flask/rcm_flask.py` | Flask recommendation rieng, LlamaIndex, port 5002. |

MongoDB duoc ket noi trong `backend/app/config/db.php`, mac dinh URI `mongodb://127.0.0.1:27017`, database `skinsyntax`. PHP dung Composer package `mongodb/mongodb`.

## 2. Luong request PHP MVC

1. Browser goi `backend/public/index.php?r=...`.
2. `index.php` load config, MongoDB, helper, controllers.
3. Switch `$_GET['r']` sang controller/action.
4. Controller lay input tu `GET`, `POST`, session.
5. Controller goi model de doc/ghi MongoDB.
6. Controller render view qua `BaseController::render()` hoac render admin layout.
7. Mot so route tra JSON truc tiep: add cart AJAX, live search, AI chat assistant, health, voucher/points.

`index.php` co `header('Content-Type: text/html; charset=utf-8')` va output buffer `fixMojibake($buffer)` neu helper ton tai. Trong source hien tai co mot so chuoi tieng Viet bi mojibake trong code, day la hien trang ky thuat can ghi nhan rieng neu bao cao ve chat luong ma nguon.

## 3. Controller chinh

| Controller | File | Vai tro |
| --- | --- | --- |
| `HomeController` | `backend/app/controllers/HomeController.php` | Trang chu, gio hang, thanh toan, goi y `/goiy`, product collection, chatbot widget proxy, trang thong tin tinh. |
| `SanPhamController` | `backend/app/controllers/SanPhamController.php` | Tat ca san pham, danh sach theo type, chi tiet san pham, them gio hang AJAX, live search. |
| `AuthController` | `backend/app/controllers/AuthController.php` | Dang nhap, dang ky, OTP/captcha, quen mat khau, social login, khao sat ho so da. |
| `TaiKhoanController` | `backend/app/controllers/TaiKhoanController.php` | Ho so ca nhan, cap nhat thong tin, doi mat khau, cap nhat ho so da, API goi y profile cu. |
| `QuanTriController` | `backend/app/controllers/QuanTriController.php` | Admin/staff dashboard, san pham, don hang, bao cao, nguoi dung, voucher, danh gia, hoi dap, chat ho tro. |
| `AdminController` | `backend/app/controllers/AdminController.php` | Ton tai nhung route chinh hien dang di qua `QuanTriController`. |

## 4. Model chinh

| Model | File | Collection/lien quan | Ghi chu |
| --- | --- | --- | --- |
| `SanPham` | `backend/app/models/SanPham.php` | `san_pham`, `danh_muc`, `thuong_hieu` | Tim kiem, phan trang, product card data, public discovery, ton kho, admin product. |
| `HoaDon` | `backend/app/models/HoaDon.php` | `hoa_don`, `chi_tiet_hoa_don`, `san_pham`, `voucher`, `thong_bao` | Tao don, tru/hoan kho, trang thai don, doanh thu, thanh toan QR/COD. |
| `TaiKhoan` | `backend/app/models/TaiKhoan.php` | `khach_hang`, `nguoidung`, `hoa_don`, `chi_tiet_hoa_don` | Ho so khach hang, lich su don, ho so da. |
| `NguoiDung` | `backend/app/models/NguoiDung.php` | `nguoidung`, `khach_hang` | Tai khoan va luu khao sat. |
| `DanhGia` | `backend/app/models/DanhGia.php` | `danh_gia_san_pham`, legacy `danh_gia`, `hoa_don`, `chi_tiet_hoa_don`, `thong_bao` | Danh gia san pham, thong ke sao, quyen danh gia sau mua. |
| `HoiDap` | `backend/app/models/HoiDap.php` | `hoi_dap_san_pham`, `thong_bao`, `san_pham` | Cau hoi san pham, admin tra loi, thong bao hoi dap moi. |
| `QuanTri` | `backend/app/models/QuanTri.php` | Nhieu collection admin | Dashboard, search admin, don hang, review, notification center, report. |
| `ThongKe` | `backend/app/models/ThongKe.php` | `hoa_don`, `san_pham` | Thong ke/bao cao neu duoc dung bo sung. |
| `Voucher` | `backend/app/models/Voucher.php` | `voucher` | Ma giam gia checkout/admin. |
| `GoiYContentBased` | `backend/app/models/GoiYContentBased.php` | `san_pham` | Luong goi y content-based cu/bo sung, khong phai LlamaIndex chinh cua `/goiy`. |

## 5. Ban do route chinh

| URL route | Controller/action | View | Model/collection lien quan | Actor | Mo ta |
| --- | --- | --- | --- | --- | --- |
| `index.php?r=home` | `HomeController::index()` | `home.php` | `SanPham::latest`, `getHomepageProductSections`, `san_pham` | Khach/khach hang | Trang chu, Flash Sale, cac khoi san pham trang chu. |
| `index.php?r=tatca` | `SanPhamController::tatca()` | `tatca.php` | `SanPham::paginate`, `san_pham` | Khach/khach hang | Tat ca san pham, search/filter/pagination. |
| `index.php?r=danhsach&type=...` | `SanPhamController::danhsach()` | `tatca.php` | `SanPham::getProductsByType` | Khach/khach hang | Danh sach san pham theo type cu nhu flash sale, best seller. |
| `index.php?r=product_collection&type=...` | `HomeController::productCollection()` | `product_collection.php` | `SanPham::getCollectionProducts` | Khach/khach hang | Trang collection dung chung cho best seller, top rated, discount, most viewed, new. |
| `index.php?r=chitiet&id=...` | `SanPhamController::chitiet()` | `chitiet.php` | `SanPham`, `DanhGia`, `HoiDap`, `hoa_don`, `chi_tiet_hoa_don` | Khach/khach hang | Chi tiet san pham, ton kho, tab mo ta/thong so/thanh phan/HDSD/danh gia/hoi dap. |
| `index.php?r=them_gio_hang_ajax` | `SanPhamController::addToCartAjax()` | JSON | `SanPham`, session `gio_hang` | Khach/khach hang | Them san pham vao gio hang, ho tro AJAX va route cu `themgiohang`, `them_gio_hang`. |
| `index.php?r=giohang` | `HomeController::giohang()` | `giohang.php` | `SanPham`, session `gio_hang` | Khach/khach hang | Hien gio hang tu session, kiem tra ton kho/san pham. |
| `index.php?r=chuandaithanhtoan` | `HomeController::chuandaithanhtoan()` | Redirect/logic | `SanPham` | Khach hang | Chuan bi item checkout tu gio hang. |
| `index.php?r=thanhtoan` | `HomeController::thanhtoan()` | `thanhtoan.php` | `SanPham`, `Voucher`, `TaiKhoan`, `HoaDon` | Khach hang | Form thanh toan, COD/QR, voucher, diem tich luy, dia chi. |
| `index.php?r=xulydathang` | `HomeController::xulydathang()` | Redirect `camon`/form | `HoaDon::taoDonHang`, `san_pham`, `hoa_don`, `chi_tiet_hoa_don` | Khach hang | Tao don hang, tru kho khi thanh cong. |
| `index.php?r=camon` | `HomeController::camon()` | `camon.php` | `HoaDon` | Khach hang | Trang cam on sau dat hang. |
| `index.php?r=payment_autocheck` | `HomeController::paymentAutoCheck()` | JSON | `HoaDon`, SePay/QR config | He thong/khach | Kiem tra trang thai thanh toan QR. |
| `index.php?r=payment_webhook` | `HomeController::paymentWebhook()` | JSON | `HoaDon` | Webhook | Nhan webhook thanh toan. |
| `index.php?r=goiy` | `HomeController::goiy()` | `goiy.php` | `SanPham`, `TaiKhoan`, Flask recommendation | Khach/khach hang | Public discovery neu chua login/chua co ho so da; goi LlamaIndex neu co ho so da hop le. |
| `index.php?r=ai_chat_assistant` | `HomeController::aiChatAssistant()` | JSON | `SanPham`, session cart, Flask chatbot | Khach/khach hang | Proxy AJAX cho widget chat voi AI chatbot. |
| `index.php?r=live_search` | `SanPhamController::liveSearch()` | JSON | `SanPham` | Khach/khach hang | Goi y search nhanh. |
| `index.php?r=api_smart_search` | `SanPhamController::apiSmartSearch()` | JSON | `SanPham` | Khach/khach hang | API search nang cao. |
| `index.php?r=khaosat` | `AuthController::khaosat()` | `auth/khaosat.php` | `NguoiDung`, `khach_hang` | Khach hang | Form khao sat ho so da. |
| `index.php?r=xulykhaosat` | `AuthController::xulykhaosat()` | Redirect | `NguoiDung::luuKhaoSatKhachHang` | Khach hang | Luu ket qua khao sat va redirect. |
| `index.php?r=hoso` | `TaiKhoanController::hoso()` | `hoso.php` | `TaiKhoan`, `HoaDon`, `SanPham`, `DanhGia` | Khach hang | Ho so ca nhan, ho so da, lich su don, thong tin tai khoan. |
| `index.php?r=capnhathosoda` | `TaiKhoanController::capNhatHoSoDa()` | JSON/redirect | `TaiKhoan` | Khach hang | Cap nhat ho so da tu trang ho so. |
| `index.php?r=api_profile_recommendations` | `TaiKhoanController::apiProfileRecommendations()` | JSON | `TaiKhoan`, `SanPham`, endpoint AI profile cu | Khach hang | API goi y profile cu, co consent; khong phai route LlamaIndex chinh cua `/goiy`. |
| `index.php?r=dangnhap` | `AuthController::dangnhap()` | `auth/dangnhap.php` hoac `dangnhap.php` | `NguoiDung` | Khach | Form dang nhap. |
| `index.php?r=xulydangnhap` | `AuthController::xulydangnhap()` | Redirect | `NguoiDung` | Khach | Xu ly dang nhap. |
| `index.php?r=dangky` | `AuthController::dangky()` | `auth/dangky.php` hoac `dangky.php` | `NguoiDung` | Khach | Form dang ky. |
| `index.php?r=xulydangky` | `AuthController::xulydangky()` | Redirect | `NguoiDung`, OTP/captcha | Khach | Xu ly dang ky. |
| `index.php?r=quen_mat_khau` | `AuthController::quenMatKhau()` | Auth view | `NguoiDung` | Khach | Quen mat khau. |
| `index.php?r=dat_lai_mat_khau` | `AuthController::datLaiMatKhau()` | Auth view | `NguoiDung` | Khach | Dat lai mat khau. |
| `index.php?r=auth_social` | `AuthController::authSocial()` | Redirect OAuth | OAuth config, `NguoiDung` | Khach | Bat dau login social. |
| `index.php?r=auth_social_callback` | `AuthController::authSocialCallback()` | Redirect | OAuth config, `NguoiDung` | Khach | Callback social login. |
| `index.php?r=lichsuchat` | `QuanTriController::customerChat()` | `lichsuchat.php` | `lich_su_chat` | Khach hang | Lich su chat ho tro. |
| `index.php?r=chat_send` | `QuanTriController::customerChatSend()` | JSON/redirect | `lich_su_chat`, `thong_bao` | Khach hang | Gui tin chat ho tro. |
| `index.php?r=guidanhgia` | `QuanTriController::customerReviewSave()` | Redirect | `DanhGia`, `danh_gia_san_pham`, `thong_bao` | Khach hang | Gui danh gia san pham sau khi mua hoan thanh. |
| `index.php?r=guicauhoi` | `QuanTriController::customerQuestionSave()` | Redirect | `HoiDap`, `hoi_dap_san_pham`, `thong_bao` | Khach hang | Gui cau hoi san pham. |
| `index.php?r=huydonhang` | `QuanTriController::customerOrderCancel()` | Redirect | `HoaDon` | Khach hang | Huy don va hoan kho neu dung dieu kien. |
| `index.php?r=mongo_health` | Inline trong `index.php` | JSON | MongoDB ping | Dev/admin | Kiem tra ket noi MongoDB. |

## 6. Route admin/staff

| URL route | Controller/action | View | Model/collection lien quan | Actor | Mo ta |
| --- | --- | --- | --- | --- | --- |
| `index.php?r=admin_dashboard` | `QuanTriController::adminDashboard()` | `admin/dashboard.php` | `QuanTri`, `HoaDon`, `SanPham`, `thong_bao` | Admin | Tong quan doanh thu, don hang, san pham, thong bao. |
| `index.php?r=staff_dashboard` | `QuanTriController::staffDashboard()` | `admin/staff_dashboard.php` | `QuanTri` | Staff | Dashboard nhan vien. |
| `index.php?r=admin_sp` | `QuanTriController::adminProducts()` | `admin/danhsachSP.php` | `SanPham`, `san_pham` | Admin | Quan ly san pham, search/filter, ton kho. |
| `index.php?r=admin_sp_create` | `QuanTriController::adminProductCreate()` | `admin/themSP.php` | `SanPham` | Admin | Them san pham. |
| `index.php?r=admin_sp_edit` | `QuanTriController::adminProductEdit()` | `admin/suaSP.php` | `SanPham` | Admin | Sua san pham. |
| `index.php?r=admin_sp_delete` | `QuanTriController::adminProductDelete()` | Redirect | `SanPham` | Admin | Xoa/an san pham. |
| `index.php?r=admin_sp_visibility` | `QuanTriController::adminProductVisibility()` | Redirect/JSON | `SanPham` | Admin | Doi trang thai hien thi. |
| `index.php?r=admin_sp_stock` | `QuanTriController::adminProductStock()` | Redirect/JSON | `SanPham::updateInventory` | Admin | Cap nhat ton kho. |
| `index.php?r=staff_products` | `QuanTriController::staffProducts()` | `admin/staff_products.php` | `SanPham` | Staff | Danh sach san pham cho staff. |
| `index.php?r=staff_product_create` | `QuanTriController::staffProductCreate()` | `admin/staff_product_create.php` | `SanPham` | Staff | Tao san pham theo quyen staff. |
| `index.php?r=staff_product_edit` | `QuanTriController::staffProductEdit()` | `admin/staff_product_edit.php` | `SanPham` | Staff | Sua san pham theo quyen staff. |
| `index.php?r=admin_orders` | `QuanTriController::adminOrders()` | `admin/orders.php` | `HoaDon`, `QuanTri`, `hoa_don`, `chi_tiet_hoa_don` | Admin | Quan ly don hang, chi tiet don, search/filter. |
| `index.php?r=admin_order_status` | `QuanTriController::adminOrderStatus()` | Redirect | `HoaDon`, `thong_bao` | Admin | Cap nhat 5 trang thai don hang. |
| `index.php?r=staff_orders` | `QuanTriController::staffOrders()` | `admin/staff_orders.php` | `HoaDon`, `QuanTri` | Staff | Xu ly don hang phia staff. |
| `index.php?r=staff_order_status` | `QuanTriController::staffOrderStatus()` | Redirect | `HoaDon` | Staff | Cap nhat trang thai don theo quyen staff. |
| `index.php?r=admin_reports` | `QuanTriController::adminReports()` | `admin/reports.php` | `QuanTri`, `HoaDon` | Admin | Bao cao doanh thu, chi tinh don `completed` hop le. |
| `index.php?r=admin_questions` | `QuanTriController::adminQuestions()` | `admin/questions.php` | `HoiDap`, `SanPham`, `hoi_dap_san_pham` | Admin/staff | Quan ly hoi dap san pham, lookup san pham, filter. |
| `index.php?r=admin_question_reply` | `QuanTriController::adminQuestionReply()` | Redirect | `HoiDap` | Admin/staff | Tra loi cau hoi san pham. |
| `index.php?r=admin_question_hide` | `QuanTriController::adminQuestionHide()` | Redirect | `HoiDap` | Admin/staff | An cau hoi. |
| `index.php?r=staff_reviews` | `QuanTriController::staffReviews()` | `admin/reviews.php` | `QuanTri::listReviews`, `DanhGia`, `SanPham` | Staff/admin | Xu ly danh gia san pham can phan hoi. |
| `index.php?r=staff_review_reply` | `QuanTriController::staffReviewReply()` | Redirect | `QuanTri::replyReview` | Staff/admin | Phan hoi danh gia. |
| `index.php?r=admin_users` | `QuanTriController::adminUsers()` | `admin/users.php` | `QuanTri`, `khach_hang`, `nhan_vien` | Admin | Quan ly khach hang/nhan vien. |
| `index.php?r=admin_categories` | `QuanTriController::adminCategories()` | `admin/categories.php` | `danh_muc` | Admin | Quan ly danh muc. |
| `index.php?r=admin_vouchers` | `QuanTriController::adminVouchers()` | `admin/vouchers.php` | `voucher` | Admin | Quan ly voucher. |
| `index.php?r=staff_chats` | `QuanTriController::staffChats()` | `admin/staff_chats.php` | `lich_su_chat` | Staff/admin | Xu ly chat ho tro. |
| `index.php?r=staff_chat_send` | `QuanTriController::staffChatSend()` | JSON/redirect | `lich_su_chat` | Staff/admin | Staff gui phan hoi chat. |
| `index.php?r=admin_notifications_seen` | `HomeController::markNotificationsSeen()` | JSON | `thong_bao`, `QuanTri` | Admin/staff | Danh dau da xem notification dropdown. |

## 7. Nhan xet tinh hoan thien theo module

| Module | Tinh trang doc tu code |
| --- | --- |
| San pham/catalog | Co logic day du cho list, search, filter, product card, ton kho, admin CRUD. |
| Chi tiet san pham | Co tab mo ta/thong so/thanh phan/HDSD/danh gia/hoi dap; review va Q&A co model rieng. |
| Gio hang | Luu trong `$_SESSION['gio_hang']`, add cart co JSON/AJAX, backend co check ton kho. |
| Thanh toan | Co COD/QR, voucher, diem tich luy, tru kho khi tao don. |
| Don hang | Co 5 trang thai normalized: pending, confirmed, shipping, completed, cancelled. |
| Bao cao | Doanh thu chi tinh don completed; QR/chuyen khoan yeu cau paid neu co payment status. |
| Thong bao | Co order, review, question, chat trong admin header. |
| Chatbot | Co service Flask rieng LangChain + ChromaDB. |
| Recommendation | `/goiy` tach guest discovery va personalized LlamaIndex service. |
| Encoding | Source co mot so chuoi mojibake trong code; `index.php` co `fixMojibake` output buffer. |

## 8. Cac diem can xac nhan them

- Mot so chuoi trong code dang mojibake, nen khi viet bao cao chinh thuc can xac dinh day la no ky thuat ton tai hay da duoc helper `fixMojibake` che tren UI.
- Route `xulygoiy` va `api_profile_recommendations` la luong goi y cu/bo sung; luong chinh cua trang `/goiy` hien la `HomeController::goiy()` + `rcm_flask.py`.
- `AdminController.php` ton tai nhung router hien tai uu tien `QuanTriController`; can xac nhan co route nao ngoai `backend/public/index.php` goi `AdminController` khong.
- Mot so view auth co ban o ca `backend/app/views/*.php` va `backend/app/views/auth/*.php`; controller quyet dinh view cu the tai runtime.

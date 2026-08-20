# SkinSyntaxVN - UI Mapping

Ngay lap: 2026-05-30  
Pham vi: mapping giao dien theo source code hien tai, khong sua code.

## 1. Layout va partial

| Thanh phan UI | File | Vai tro |
| --- | --- | --- |
| Layout public | `backend/app/views/layouts/header.php`, `backend/app/views/layouts/footer.php` | Header, navigation, search, cart, account, script chung cho public site. |
| Layout admin/staff | `backend/app/views/admin/layouts/header.php`, `backend/app/views/admin/layouts/footer.php` | Sidebar/menu admin, notification dropdown, flash messages. |
| Product card public | `backend/app/views/partials/product_card.php` | Card san pham dung lai cho `/goiy`, collection va mot so section. |
| Market product card | `backend/app/views/partials/market/product-card.php` | Card san pham style homepage/market section. |
| AI chat widget | `backend/app/views/components/ai_chat_widget.php` | Widget chat voi AI tren frontend, goi `r=ai_chat_assistant`. |
| Support chat widget | `backend/app/views/components/support_chat_widget.php` | Chat ho tro khach hang/staff. |
| Admin notification UI | `backend/app/views/admin/layouts/header.php` | Hien notification don hang, danh gia, hoi dap, chat. |

## 2. Trang public/khach hang

| Route | Controller/action | View | Actor | UI chinh | Data/model |
| --- | --- | --- | --- | --- | --- |
| `index.php?r=home` | `HomeController::index()` | `home.php` | Khach/khach hang | Banner/trang chu, Flash Sale, cac khoi san pham, nut them gio hang. | `SanPham::latest`, `SanPham::getHomepageProductSections`, `san_pham`. |
| `index.php?r=tatca` | `SanPhamController::tatca()` | `tatca.php` | Khach/khach hang | Luoi san pham, bo loc/search/pagination. | `SanPham::paginate`, `san_pham`. |
| `index.php?r=danhsach&type=...` | `SanPhamController::danhsach()` | `tatca.php` | Khach/khach hang | Dung view list chung, tuy type hien title/ket qua. | `SanPham::getProductsByType`. |
| `index.php?r=product_collection&type=best_seller` | `HomeController::productCollection()` | `product_collection.php` | Khach/khach hang | Trang collection co filter va pagination. | `SanPham::getCollectionProducts`. |
| `index.php?r=chitiet&id=...` | `SanPhamController::chitiet()` | `chitiet.php` | Khach/khach hang | Hai cot anh + thong tin san pham, chinh sach mua hang, so luong, them gio hang, tab chi tiet. | `SanPham`, `DanhGia`, `HoiDap`. |
| `index.php?r=giohang` | `HomeController::giohang()` | `giohang.php` | Khach/khach hang | Bang gio hang, tang/giam so luong, tong tien, chuyen thanh toan. | Session `gio_hang`, `SanPham`. |
| `index.php?r=thanhtoan` | `HomeController::thanhtoan()` | `thanhtoan.php` | Khach hang | Form thong tin nhan hang, voucher, diem, phuong thuc COD/QR, bang san pham. | `Voucher`, `TaiKhoan`, `SanPham`, `HoaDon`. |
| `index.php?r=camon` | `HomeController::camon()` | `camon.php` | Khach hang | Ket qua dat hang, thong tin thanh toan/QR neu co. | `HoaDon`. |
| `index.php?r=goiy` | `HomeController::goiy()` | `goiy.php` | Khach/khach hang | Public discovery hoac RCM personalized. | `SanPham`, `TaiKhoan`, Flask LlamaIndex. |
| `index.php?r=khaosat` | `AuthController::khaosat()` | `auth/khaosat.php` | Khach hang | Form khao sat loai da, van de da, ngan sach, muc tieu. | `NguoiDung`, `khach_hang`. |
| `index.php?r=hoso` | `TaiKhoanController::hoso()` | `hoso.php` | Khach hang | Thong tin ca nhan, ho so da, lich su don, doi mat khau/cap nhat. | `TaiKhoan`, `HoaDon`, `DanhGia`. |
| `index.php?r=lichsuchat` | `QuanTriController::customerChat()` | `lichsuchat.php` | Khach hang | Lich su chat ho tro. | `lich_su_chat`. |

## 3. Trang chi tiet san pham

Route: `index.php?r=chitiet&id=...`  
Controller: `SanPhamController::chitiet()`  
View: `backend/app/views/chitiet.php`

Thanh phan UI:

| Khu vuc | Mo ta | Data |
| --- | --- | --- |
| Anh san pham | Anh lon, thumbnail, xu ly image URL qua helper. | `san_pham.link_hinh_anh`, `hinh_anh`. |
| Thong tin san pham | Thuong hieu, ten, gia ban, gia thi truong, danh muc, loai san pham, xuat xu, dung tich, loai da, rating. | `san_pham`. |
| Ton kho/add cart | Hien trang thai con hang/het hang, gioi han so luong theo ton kho. | `so_luong_ton_kho`, `trang_thai_kho`. |
| Chinh sach mua hang | Card quyen loi/chinh sach. | Static UI trong view, link `index.php?r=bao_hanh`. |
| Tab Mo ta | Mo ta san pham. | `mo_ta`. |
| Tab Thong so | Thong so san pham. | Nhom field trong `san_pham`. |
| Tab Thanh phan | Thanh phan chinh/day du. | `thanh_phan_chinh`, `thanh_phan_day_du`. |
| Tab HDSD | Huong dan su dung. | `huong_dan_su_dung`. |
| Tab Danh gia | Diem trung binh, breakdown sao, filter sao/anh, form danh gia neu du dieu kien, danh sach review. | `DanhGia`, `danh_gia_san_pham`, legacy `danh_gia`, crawl rating trong `san_pham`. |
| Tab Hoi dap | Form hoi dap neu login, danh sach cau hoi/tra loi shop. | `HoiDap`, `hoi_dap_san_pham`. |

Giao dien tab trong `chitiet.php` tu quan ly bang JS rieng (`data-tab`, `data-pane`), khong phu thuoc hoan toan vao Bootstrap tab. URL hash `#danhgia`, `#hoidap` duoc mapping de active tab.

## 4. Trang `/goiy`

Route: `index.php?r=goiy`  
Controller: `HomeController::goiy()`  
View: `backend/app/views/goiy.php`

Trang co 3 trang thai:

| Trang thai | UI | Logic |
| --- | --- | --- |
| Chua dang nhap | Public discovery: form keyword/danh muc/thuong hieu/gia/sort, 5 khoi san pham. | Khong goi AI. Lay tu MongoDB qua `SanPham::publicRecommendationSections`. |
| Dang nhap nhung chua co ho so da hop le | UI public discovery + banner moi khao sat, nut `Khao sat ngay`. | Khong goi Flask/LlamaIndex. `hasValidSkinProfile()` tra false. |
| Dang nhap va co ho so da hop le | Title goi y rieng, answer_text, product cards recommendation. | PHP goi `http://127.0.0.1:5002/api/recommend/llamaindex`; neu loi hien thong bao than thien. |

Public discovery blocks:

| Khoi | Default sort | Collection |
| --- | --- | --- |
| San pham ban chay nhat | `so_luong_da_ban DESC`, fallback ky thuat sang review count neu thieu | `san_pham` |
| San pham duoc danh gia cao | `diem_danh_gia DESC`, `so_luong_danh_gia DESC` | `san_pham` |
| San pham dang giam gia | `phan_tram_giam DESC`, dieu kien co giam gia | `san_pham` |
| San pham duoc quan tam/tim kiem | `luot_xem DESC` | `san_pham` |
| San pham moi | `ngay_tao DESC`, fallback `ma_san_pham DESC` | `san_pham` |

## 5. Trang `product_collection`

Route mau: `index.php?r=product_collection&type=best_seller`  
Controller: `HomeController::productCollection()`  
View: `backend/app/views/product_collection.php`

Type hop le:

| Type | Tieu de UI | Model |
| --- | --- | --- |
| `best_seller` | San pham ban chay nhat | `SanPham::getCollectionProducts` |
| `top_rated` | San pham duoc danh gia cao | `SanPham::getCollectionProducts` |
| `discount` | San pham dang giam gia | `SanPham::getCollectionProducts` |
| `most_viewed` | San pham duoc quan tam nhieu | `SanPham::getCollectionProducts` |
| `new` | San pham moi | `SanPham::getCollectionProducts` |

Neu type khong hop le, controller fallback ve `best_seller` va truyen message than thien cho view.

## 6. Trang tai khoan va auth

| Route | Controller/action | View | Actor | Mo ta |
| --- | --- | --- | --- | --- |
| `index.php?r=dangnhap` | `AuthController::dangnhap()` | `auth/dangnhap.php` hoac view login cu | Khach | Form dang nhap. |
| `index.php?r=dangky` | `AuthController::dangky()` | `auth/dangky.php` hoac view signup cu | Khach | Form dang ky, OTP/captcha. |
| `index.php?r=quen_mat_khau` | `AuthController::quenMatKhau()` | Auth view | Khach | Quen mat khau. |
| `index.php?r=khaosat` | `AuthController::khaosat()` | `auth/khaosat.php` | Khach hang | Khao sat ho so da. |
| `index.php?r=hoso` | `TaiKhoanController::hoso()` | `hoso.php` | Khach hang | Ho so ca nhan, ho so da, lich su don hang. |

## 7. Trang admin/staff

| Route | View | Actor | UI chinh |
| --- | --- | --- | --- |
| `admin_dashboard` | `admin/dashboard.php` | Admin | Dashboard tong quan. |
| `staff_dashboard` | `admin/staff_dashboard.php` | Staff | Dashboard staff, don/review/chat can xu ly. |
| `admin_sp` | `admin/danhsachSP.php` | Admin | Bang san pham, search, filter, ton kho, actions. |
| `admin_sp_create` | `admin/themSP.php` | Admin | Form them san pham. |
| `admin_sp_edit` | `admin/suaSP.php` | Admin | Form sua san pham. |
| `staff_products` | `admin/staff_products.php` | Staff | Bang san pham cho staff. |
| `admin_orders` | `admin/orders.php` | Admin | Don hang, detail, cap nhat trang thai. |
| `staff_orders` | `admin/staff_orders.php` | Staff | Don hang staff xu ly. |
| `admin_reports` | `admin/reports.php` | Admin | Bao cao doanh thu/top products. |
| `admin_questions` | `admin/questions.php` | Admin/staff | Hoi dap san pham, lookup san pham, tra loi/an. |
| `staff_reviews` | `admin/reviews.php` | Staff/admin | Danh gia can phan hoi, form phan hoi. |
| `admin_users` | `admin/users.php` | Admin | Khach hang/nhan vien. |
| `admin_categories` | `admin/categories.php` | Admin | Danh muc. |
| `admin_vouchers` | `admin/vouchers.php` | Admin | Voucher. |
| `staff_chats` | `admin/staff_chats.php` | Staff/admin | Chat ho tro. |

## 8. Thong bao admin/staff

Thong bao duoc render trong `backend/app/views/admin/layouts/header.php`, data tu `QuanTriController::renderAdmin()` va `QuanTri::getNotificationCenterData()`.

Loai thong bao dang co trong source:

| Loai | Nguon | Link UI |
| --- | --- | --- |
| Don hang | `hoa_don`/`thong_bao` | `admin_orders` hoac `staff_orders` |
| Danh gia moi | `thong_bao.loai = review` | `staff_reviews` |
| Hoi dap moi | `thong_bao.loai = hoi_dap_moi` hoac `question` | `admin_questions` |
| Chat can ho tro | `lich_su_chat` | `staff_chats` |

## 9. Ghi nhan UI co logic chua chac chan

- Mot so view auth ton tai o ca cap root view va thu muc `auth`; can test runtime de xac dinh view nao dang duoc render trong moi luong.
- Trong code co cac chuoi mojibake; UI co the duoc sua bang output buffer `fixMojibake`, nhung source khong thuan UTF-8 o mot so noi.
- Route `xulygoiy` la luong goi y cu, khong phai luong RCM LlamaIndex chinh cua `/goiy`.

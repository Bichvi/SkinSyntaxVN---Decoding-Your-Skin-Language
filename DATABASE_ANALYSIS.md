# SkinSyntaxVN - Database Analysis

Ngay lap: 2026-05-30  
Pham vi: phan tich collection/field suy ra tu source code, khong sua database.

## 1. Ket noi MongoDB

File cau hinh: `backend/app/config/db.php`

| Tham so | Gia tri mac dinh |
| --- | --- |
| Driver PHP | Composer package `mongodb/mongodb` |
| URI | `mongodb://127.0.0.1:27017` |
| Database | `skinsyntax` |
| Adapter | `MongoDatabaseCompat`, cho phep goi `$pdo->collection` va `$pdo->raw()` |

Route kiem tra suc khoe MongoDB: `index.php?r=mongo_health`, ping database va tra JSON.

## 2. Collection san pham

Collection chinh: `san_pham`

Field duoc code su dung:

| Field | Vai tro |
| --- | --- |
| `ma_san_pham`, `id`, `_id` | Dinh danh san pham; code co helper tim linh hoat string/int/ObjectId. |
| `ten_san_pham` | Ten san pham. |
| `thuong_hieu` | Thuong hieu. |
| `danh_muc_day_du`, `loai_san_pham` | Danh muc/loai san pham, dung filter/search. |
| `gia_ban`, `gia_thi_truong` | Gia ban/gia goc. |
| `phan_tram_giam`, `tien_tiet_kiem` | Giam gia/Flash Sale/discount section. |
| `diem_danh_gia`, `so_luong_danh_gia` | Rating crawl/tong quan. |
| `so_luong_da_ban` | Sap xep ban chay. |
| `luot_xem` | Sap xep san pham duoc quan tam. |
| `ngay_tao`, `created_at` | Sap xep san pham moi. |
| `link_hinh_anh`, `hinh_anh`, `image_url` | Anh san pham. |
| `mo_ta`, `thanh_phan_chinh`, `thanh_phan_day_du`, `thanh_phan_sach` | Search keyword, chi tiet, recommendation index. |
| `loai_da` | Filter/recommend theo loai da. |
| `barcode` | Search admin product neu co. |
| `trang_thai`, `visibility`, `is_hidden` | Trang thai hien thi/an san pham. |
| `so_luong_ton_kho` | Ton kho hien tai. |
| `trang_thai_kho` | `con_hang` hoac `het_hang`. |
| `da_khoi_tao_kho` | Dau hieu da seed ton kho. |

Model lien quan: `backend/app/models/SanPham.php`.

Helper dang co:

| Ham | Mo ta |
| --- | --- |
| `buildProductIdQuery($productId)` | Tim theo `ma_san_pham`, `id`, `_id`, ca string va number. |
| `getProductBriefById($productId)` | Lay thong tin ngan gon cho admin questions/reviews/orders. |
| `getProductStock($product)` | Doc `so_luong_ton_kho`, fallback ky thuat neu thieu. |
| `isProductAvailable($product)` | Kiem tra ton kho/trang thai san pham. |
| `updateInventory(...)` | Cap nhat ton kho va `trang_thai_kho`. |

## 3. Collection tai khoan/khach hang

| Collection | Field code su dung | Vai tro |
| --- | --- | --- |
| `nguoidung` | `id`, `ma_nguoi_dung`, `email`, `mat_khau`, `ma_vai_tro`, lien ket `ma_kh` neu co | Tai khoan dang nhap. |
| `khach_hang` | `ma_kh`, `email`, `ho_ten`, `sdt`, `dia_chi`, `gioi_tinh`, `nam_sinh`, `loai_da`, `van_de_da`, `ngan_sach`, `muc_do_nhay_cam`, `thanh_phan_tranh`, `diemtl` | Ho so khach hang, ho so da, diem tich luy. |
| `nhan_vien` | `ma_nv`, `ho_ten`, `email`, role/status | Tai khoan staff/admin. |
| `vai_tro` | role id/name | Phan quyen neu co trong DB. |
| `loai_da` | `ma_loai_da`, `ten_loai_da` | Mapping loai da trong LlamaIndex service. |
| `ho_so_da` | `ma_kh`, `customer_id`, `user_id`, `email`, `loai_da`, `skin_type`, `van_de_da`, `ngan_sach` | Ho so da bo sung cho recommendation service. |

Model lien quan: `TaiKhoan.php`, `NguoiDung.php`, `QuanTri.php`.

## 4. Collection don hang va thanh toan

| Collection | Field code su dung | Vai tro |
| --- | --- | --- |
| `hoa_don` | `ma_hoa_don`, `ma_kh`, `ngay_dat`, `tong_tien`, `trang_thai`, `trang_thai_normalized`, `phuong_thuc_thanh_toan`, `trang_thai_thanh_toan`, `ngay_hoan_thanh`, `da_tru_ton_kho`, `da_hoan_kho`, `items` neu co | Don hang. |
| `chi_tiet_hoa_don` | `ma_hoa_don`, `ma_san_pham`, `so_luong`, `don_gia`, `thanh_tien`, snapshot ten/anh neu co | Chi tiet san pham trong don. |
| `voucher` | `ma_code`, `ten_voucher`, `loai_giam`, `gia_tri_giam`, `trang_thai`, han dung | Ma giam gia. |

Model chinh: `HoaDon.php`.

Trang thai don hang duoc normalize:

| Gia tri nguon | Normalized |
| --- | --- |
| `Chờ xử lý`, `cho_xu_ly`, `pending` | `pending` |
| `Đã xác nhận`, `da_xac_nhan`, `confirmed` | `confirmed` |
| `Đang giao`, `dang_giao`, `shipping` | `shipping` |
| `Hoàn thành`, `hoan_thanh`, `completed` | `completed` |
| `Đã hủy`, `da_huy`, `cancelled`, `canceled` | `cancelled` |

Nghiep vu ton kho:

- Tru kho trong `HoaDon::taoDonHang()` khi don tao thanh cong.
- Khong cho tru am kho.
- Cap nhat `trang_thai_kho = het_hang` neu ton kho ve 0.
- Hoan kho khi don bi huy, danh dau `hoa_don.da_hoan_kho = true`.
- Khong hoan kho khi don hoan thanh.

Doanh thu admin report:

- Chi tinh don co normalized status `completed`.
- Neu QR/chuyen khoan co field trang thai thanh toan thi chi tinh khi paid.
- COD tinh khi don `Hoàn thành`.

## 5. Collection danh gia

Collection chinh cho review moi: `danh_gia_san_pham`.

Field:

| Field | Vai tro |
| --- | --- |
| `ma_danh_gia` | Ma review. |
| `ma_san_pham` | Ma san pham, code query ca string va number. |
| `ma_khach_hang` | Khach hang gui review. |
| `ten_khach_hang` | Ten hien thi. |
| `so_sao` | Sao 1-5. |
| `noi_dung` | Noi dung review. |
| `hinh_anh` | Mang path anh upload, vi du `uploads/reviews/...`. |
| `ngay_danh_gia` | Ngay gui. |
| `da_mua_hang` | Badge da mua hang. |
| `phan_hoi_shop` | Object phan hoi shop: `noi_dung`, `ngay_phan_hoi`, `ma_nhan_vien`, `ten_nhan_vien`. |
| `trang_thai` | `hien_thi` hoac trang thai an/khac. |

Collection cu: `danh_gia`.  
Source hien tai van doc legacy review de hien thi/quan tri, nhung review moi ghi vao `danh_gia_san_pham`.

Collection lien quan co ton tai theo yeu cau/database: `danh_gia_like`, `review_images`; code chinh hien tai uu tien `hinh_anh` array trong `danh_gia_san_pham`.

Thong ke review:

- `DanhGia::getReviewStats($productId, $product)` ket hop rating crawl trong `san_pham.diem_danh_gia`/`so_luong_danh_gia` voi review user trong `danh_gia_san_pham`.
- Muc tieu la khong de them 1 review moi lam mat tong rating crawl cu.

Dieu kien duoc danh gia:

- Da dang nhap.
- Da mua san pham.
- Don hang co trang thai normalized `completed`.

## 6. Collection hoi dap

Collection: `hoi_dap_san_pham`

Field:

| Field | Vai tro |
| --- | --- |
| `ma_hoi_dap` | Ma cau hoi. |
| `ma_san_pham` | Ma san pham, query ca string va number. |
| `ma_khach_hang` | Khach hang hoi. |
| `ten_khach_hang` | Ten hien thi. |
| `cau_hoi` | Noi dung cau hoi. |
| `ngay_hoi` | Ngay hoi. |
| `tra_loi` | Object tra loi shop: `noi_dung`, `ngay_tra_loi`, `ma_nhan_vien`, `ten_nhan_vien`. |
| `so_luot_thich` | Luot thich neu co. |
| `trang_thai` | `hien_thi`, `an`, ... |
| `updated_at` | Thoi gian cap nhat. |

Model: `HoiDap.php`.

## 7. Collection thong bao

Collection: `thong_bao`

Loai thong bao duoc source su dung:

| `loai` | Nguon tao | Actor nhan | Link |
| --- | --- | --- | --- |
| `new_cod`, `new_qr` hoac order-related | `HoaDon::taoDonHang()` | Admin/staff | `admin_orders`/`staff_orders` |
| `review` | `DanhGia::addReview()` | Admin/staff | `staff_reviews` |
| `hoi_dap_moi`, `question` | `HoiDap::addQuestion()` | Admin/staff | `admin_questions` |
| `inventory_out`, `inventory_low` | Logic ton kho trong `HoaDon`/`SanPham` | Admin/staff | Admin products/order context |

Field thuong gap:

- `loai`
- `tieu_de`
- `noi_dung`
- `ma_san_pham`, `ma_danh_gia`, `ma_hoi_dap`, `ma_khach_hang`
- `da_doc`
- `created_at` hoac `ngay_tao`
- `link`

Notification center doc trong `QuanTri::getNotificationCenterData()` va render trong admin layout.

## 8. Collection chat

Collection: `lich_su_chat`

Duoc dung cho hai luong:

1. Chat ho tro khach hang/staff trong PHP (`QuanTriController`, `staff_chats`, `lichsuchat`).
2. Du lieu hanh vi/nhu cau gan day cho recommendation service LlamaIndex (`llamaindex_recommend_service._history()` doc chat theo `ma_kh`, `customer_id`, `user_id`).

## 9. Collection phu tro

| Collection | Vai tro |
| --- | --- |
| `danh_muc` | Danh muc san pham/admin category/filter. |
| `thuong_hieu` | Thuong hieu/filter. |
| `gio_hang` | Recommendation service co doc collection nay neu co; gio hang web hien tai chu yeu luu session. |
| `tim_kiem`/lich su tu khoa neu co | `TaiKhoan::getTuKhoaGanDay()` phuc vu profile/recommendation. |

## 10. Ghi nhan chat luong du lieu

- `ma_san_pham` co the la string hoac number o cac collection review/Q&A/order; source da co helper query linh hoat.
- Rating crawl trong `san_pham` va review chi tiet trong `danh_gia_san_pham` la hai nguon khac nhau; bao cao UI nen phan biet.
- Mot so collection cu (`danh_gia`) van duoc doc de khong mat du lieu legacy.
- Ton kho duoc luu truc tiep trong `san_pham`; script seed neu co nam trong `scripts/seed_inventory.php`, khong chay tu dong khi load web.

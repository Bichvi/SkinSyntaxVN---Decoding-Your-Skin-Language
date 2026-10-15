# SkinSyntaxVN — Category Source of Truth Master Audit Report
**Date**: October 7, 2026  
**Audit Type**: Read-Only Source of Truth & Data Flow Investigation  
**Status**: Completed (Audit Only — No Data Modified, No Seed, No Delete)

---

## Direct Answers to Final Questions (Q1 - Q10)

### Q1. Admin hiện đang đọc database nào?
**Trả lời:**  
Admin hiện đang đọc từ **Local MongoDB Container (`mongodb://mongodb:27017` / port host `27018`)**, database `skinsyntax`.  
Nguyên nhân là do đoạn logic trong `backend/app/config/db.php` (dòng 61-71) tự động chuyển hướng kết nối sang `mongodb:27017` mỗi khi PHP chạy trong container Docker nếu biến môi trường `DOCKER_USE_ATLAS` không được gán bằng `1`.

### Q2. Homepage hiện đang đọc database nào?
**Trả lời:**  
Homepage hiện cũng đang đọc từ **Local MongoDB Container (`mongodb://mongodb:27017`)**, cùng connection với Admin. Cả hai cùng sử dụng biến kết nối chung khởi tạo từ `backend/app/config/db.php`.

### Q3. Vì sao Admin thấy 125 category?
**Trả lời:**  
Vì Admin đang đọc từ Local MongoDB, nơi chứa dataset legacy chưa cleanup gồm **125 danh mục phẳng cũ** (như Dầu Gội, Sữa Tắm, Nước Hoa, Bao Cao Su, Trang Điểm...). Toàn bộ 125 document này đều có `parent_id = null`.  
Trong khi đó, trên MongoDB Atlas, collection `danh_muc` đã được chuẩn hóa chỉ còn **28 danh mục skincare** có quan hệ cha-con 3 cấp rõ ràng.

### Q4. Vì sao Homepage category block bị trắng (trống phần bên phải)?
**Trả lời:**  
Khối danh mục ở header mega menu (`frontend/views/layouts/header.php`) render dữ liệu từ mảng `$menuCats`, được sinh ra bởi hàm `SanPham::menuTree()`.
- Hàm `menuTree()` duyệt các danh mục gốc (`$roots`), sau đó tìm các danh mục con cấp 2 (`$children[$rootId]`) để tính tổng số sản phẩm và đưa vào mảng `$tree`.
- Vì đang kết nối vào Local MongoDB, tất cả 125 danh mục đều là gốc (`parent_id = null`) và **không có bất kỳ danh mục con nào** (`$children` rỗng). Do đó, `menuTree()` trả về mảng **rỗng** (`[]`).
- Khi `$menuCats` rỗng, vòng lặp `foreach ($menuCats as $c1 => $c2s)` trong thẻ `<div class="col-lg-9">` không chạy lần nào, khiến toàn bộ khu vực bên phải của mega menu bị **trắng tinh**.
- *Bổ sung*: Ngay cả khi kết nối vào Atlas, do taxonomy chuẩn hóa chỉ có 1 danh mục gốc (`#1001` - Chăm Sóc Da Mặt), logic render 1 cột cho mỗi danh mục gốc sẽ chỉ hiển thị 1 cột 4 phần trong khung 12 phần, để trống 8 phần còn lại (67% khung).

### Q5. Canonical `danh_muc` nằm ở source nào?
**Trả lời:**  
Canonical `danh_muc` nằm ở **MongoDB Atlas Cluster (`skinsyntaxvn-db.3edac4m.mongodb.net`)**, database `skinsyntax`, collection `danh_muc`.

### Q6. Canonical taxonomy hiện có bao nhiêu parent/leaf?
**Trả lời:**  
Tổng cộng có **28 danh mục**:
- **7 parent categories**: 1 danh mục gốc cấp 1 (`#1001` - Chăm Sóc Da Mặt) và 6 nhóm danh mục cấp 2 (`#2001` Mặt Nạ, `#2002` Làm Sạch Da, `#2003` Dưỡng Ẩm, `#2004` Đặc Trị, `#2005` Dưỡng Mắt, `#2006` Dưỡng Môi).
- **21 leaf categories**: Gồm 2 danh mục lá cấp 2 (`#6` Chống Nắng Da Mặt, `#3` Bộ Chăm Sóc Da Mặt) và 19 danh mục lá cấp 3 (`#1` Sữa Rửa Mặt, `#2` Tẩy Trang Mặt, `#4` Toner, `#7` Kem/Gel/Dầu Dưỡng, `#9` Serum/Tinh Chất, `#11` Mặt Nạ Giấy, `#17` Tẩy Tế Bào Chết, `#18` Son Dưỡng, `#19` Mặt Nạ Rửa, `#25` Hỗ Trợ Trị Mụn, `#29` Mặt Nạ Môi, `#30` Xịt Khoáng, `#37` Lotion/Sữa Dưỡng, `#38` Serum/Kem Dưỡng Mắt, `#53` Mặt Nạ Ngủ, `#60` Mặt Nạ Mắt, `#73` Tẩy Da Chết Môi, `#83` Mặt Nạ Lột, `#105` Đặc Trị Khác).

### Q7. 2,473 skincare products đang nằm ở DB nào?
**Trả lời:**  
Đang nằm chính xác tại **MongoDB Atlas Cluster (`skinsyntaxvn-db`)**, database `skinsyntax`, collection `san_pham`.  
- Tổng số: 2,473 sản phẩm (tất cả 2,473 đều ở trạng thái active).
- Phân bố `ma_danh_muc`: Phủ chính xác 21 giá trị danh mục lá (Leaf).
- Tỷ lệ orphan (mồ côi): **0% (0 sản phẩm orphan)**.
- Gắn vào parent node: **0 sản phẩm**.
- Gắn vào leaf node: **2,473 sản phẩm (100%)**.

### Q8. Có cần cleanup dữ liệu lại không?
**Trả lời:**  
**KHÔNG CẦN CLEANUP LẠI DỮ LIỆU TRÊN ATLAS.**  
Dữ liệu trên MongoDB Atlas đã là bản canonical cực kỳ sạch và chuẩn mực 100%: 2,473 sản phẩm skincare đều gắn đúng vào 21 danh mục lá, quan hệ cha-con 28 danh mục toàn vẹn tuyệt đối. Không có lỗi taxonomy hay orphan trên Atlas.  
Dữ liệu 125 danh mục và 6,377 sản phẩm chỉ là tàn dư chưa migrate trên Local Docker MongoDB.

### Q9. Hay chỉ cần sửa connection/data flow?
**Trả lời:**  
**CHỈ CẦN SỬA CONNECTION & DATA FLOW:**
1. **Sửa connection logic trong `backend/app/config/db.php`** (hoặc cấu hình docker-compose / .env với `DOCKER_USE_ATLAS=1`) để ứng dụng luôn kết nối đến đúng canonical MongoDB Atlas.
2. **Sửa data flow & view rendering trong `SanPham::menuTree()` và `frontend/views/layouts/header.php`**: Chuyển cách nhóm từ Level 1 sang hiển thị các nhóm Level 2 theo dạng đa cột (3-4 cột) kèm số lượng sản phẩm, lấp đầy toàn bộ khu vực bên phải của mega menu.
3. **Đồng bộ backup dữ liệu Local**: Nếu môi trường offline/local dev cần chạy độc lập với Atlas, chỉ cần backup/restore dump 28 `danh_muc` và 2,473 `san_pham` từ Atlas vào Local MongoDB, tuyệt đối không can thiệp thủ công làm biến dạng schema.

### Q10. File nào sẽ cần sửa ở Phase 2?
**Trả lời:**  
Các file cần sửa ở Phase 2:
1. `backend/app/config/db.php`: Loại bỏ hoặc sửa đổi đoạn code tự động hijack sang local MongoDB container (`DOCKER_USE_ATLAS`), đảm bảo kết nối chính là MongoDB Atlas URI được chỉ định trong `.env`.
2. `docker-compose.yml`: Bổ sung biến môi trường `DOCKER_USE_ATLAS: "1"` cho service `php-backend` (và các service phụ trợ nếu cần).
3. `backend/app/models/SanPham.php`: Cập nhật hàm `menuTree()` để trả về cây nhóm theo các nhóm Level 2 (`Làm Sạch Da`, `Dưỡng Ẩm`, `Mặt Nạ`, `Đặc Trị`, `Chống Nắng`...) thay vì chỉ gom theo 1 node gốc duy nhất.
4. `frontend/views/layouts/header.php`: Cập nhật cấu trúc hiển thị mega menu để duyệt qua các nhóm Level 2 chia đều thành 3-4 cột đẹp mắt, hiển thị danh mục lá và số lượng sản phẩm, giải quyết triệt để lỗi khoảng trắng bên phải.
5. `backend/app/controllers/HomeController.php`: Dọn dẹp hàm `getHighlightedCategories()` cũ (hàm này đang aggregate trực tiếp trên chuỗi `danh_muc_day_du` của sản phẩm và kết quả không hề được view `home.php` sử dụng).

---

## Bảng Đối Soát Tổng Hợp Database (Audit Summary Matrix)

| Tiêu chí | Local MongoDB (Docker Container) | MongoDB Atlas (Cluster Cloud) | Nhận xét |
| :--- | :--- | :--- | :--- |
| **Host** | `mongodb:27017` / `127.0.0.1:27018` | `skinsyntaxvn-db.3edac4m.mongodb.net` | Host thực tế bị hijack bởi `db.php` |
| **Database Name** | `skinsyntax` | `skinsyntax` | Trùng tên database |
| **Collection `san_pham` count** | 6,377 | 2,473 | Atlas là catalog skincare chuẩn |
| **Collection `danh_muc` count** | 125 | 28 | Local là 125 danh mục legacy |
| **Cấu trúc taxonomy** | Phẳng (125 root, 0 parent) | Phân cấp 3 cấp (1 root, 7 parent, 21 leaf) | Atlas có quan hệ cha-con hoàn chỉnh |
| **Số sản phẩm mồ côi (Orphan)** | 0 | 0 | Không có orphan ở cả hai |
| **Sản phẩm gắn vào Leaf** | 6,377 | 2,473 (100%) | Đạt chuẩn Single Source of Truth |
| **Kết quả `menuTree()`** | `[]` (Mảng rỗng) | 1 root -> 8 group/leaf | Local làm hỏng giao diện mega menu |
| **Admin hiển thị** | 125 danh mục legacy phẳng | 28 danh mục chuẩn hóa cây phân cấp | Admin view đã sẵn sàng cho 28 danh mục |

---

## Danh Sách Artifacts Đã Tạo

Tất cả các tài liệu và file dữ liệu audit đã được lưu tại thư mục:  
`backend/experiments/category_source_audit/`

1. `database_source_comparison.json`: Dữ liệu JSON chi tiết so sánh kết nối, schema và số lượng giữa Local và Atlas.
2. `canonical_category_schema.json`: Schema chi tiết của 28 document canonical trong `danh_muc` trên Atlas kèm sample 1 root node và 10 leaf nodes.
3. `category_reference_integrity.json`: Báo cáo toàn vẹn dữ liệu tham chiếu giữa `san_pham.ma_danh_muc` và `danh_muc`.
4. `admin_category_data_flow.md`: Trace chi tiết luồng xử lý từ URL `admin_categories` đến controller, model, query và view.
5. `homepage_category_data_flow.md`: Trace chi tiết luồng xử lý của `HomeController`, mega menu và các section liên quan đến category.
6. `category_root_cause.md`: Phân tích chuyên sâu 2 nguyên nhân gốc rễ gây ra lỗi Admin 125 categories và Homepage category block bị trắng.
7. `proposed_category_architecture.md`: Đề xuất kiến trúc Single Source of Truth và giải pháp UX đa cột cho Category block trên Homepage / Header.
8. `category_audit_report.md`: Báo cáo tổng kết toàn diện và trả lời 10 câu hỏi cốt lõi.

---
**KẾT THÚC QUÁ TRÌNH AUDIT — KHÔNG CÓ THAO TÁC SỬA ĐỔI HOẶC XÓA DỮ LIỆU NÀO ĐƯỢC THỰC HIỆN.**

# Rà soát stash@{0} sau merge rcm_index (2026-10-16)

Nguồn: `stash@{0}` ("WIP: AI Idol + tracked changes before rcm_index merge"), gốc `bdc14ec`. So với working tree hiện tại (HEAD `5acf2f0` + thay đổi chưa commit). Có 34 file khác nhau. Cách làm: lấy các dòng `+` của `git diff 'stash@{0}^1' 'stash@{0}'` rồi kiểm tra dòng đó còn trong file hiện tại không, sau đó đọc tay những cụm bị thiếu.

Ký hiệu: **a** = đã có trong code hiện tại · **b** = merge/commit sau đã thay bằng code mới hơn, có chủ đích · **c** = bị mất, nên cân nhắc áp lại.

## Nhóm (c): bị mất, cần duyệt

| # | File | Cụm thay đổi | Đề xuất | Rủi ro / impact |
|---|------|--------------|---------|-----------------|
| C1 | `backend/app/models/QuanTri.php` | Bỏ truy vấn N+1 trong danh sách khách hàng/đơn hàng: `findFirstByValues`, `sumOrdersByCustomer`, `countOrderLines`, `lookupKey` (một `$in`/aggregate thay cho `findOne`/`countDocuments` theo từng dòng); lọc trạng thái trước khi bổ sung dữ liệu. | **Nên áp lại** (đúng hướng tối ưu Atlas). Merge lấy nguyên bản của rcm_index, bản backup `9c04f59` cũng không có. | `listOrders`: **HIGH** (9 điểm phụ thuộc: `adminOrders`, `exportAdminOrders`, `staffDashboard`…). `listCustomers`: LOW (`adminUsers`). Phải giữ nguyên dạng mảng trả về (`email`, `ho_ten`, `so_dong_hang`, `tong_don`, `tong_chi_tieu`). Cần test so sánh trước/sau trên dữ liệu thật. |
| C2 | `backend/app/models/QuanTri.php` | Chặn trùng email khi lưu nhân viên (regex không phân biệt hoa thường, bỏ qua chính `ma_nv`), báo "Email nhân viên đã tồn tại…". | **Nên áp lại** (sửa lỗi dữ liệu). | `saveStaff`: LOW (`adminStaffSave`). |
| C3 | `backend/app/controllers/SanPhamController.php` + `frontend/views/chitiet.php` | Video AI Idol trên trang chi tiết: `loadProductVideos()` / `productVideoCard()` dùng `ProductVideoClient`, view `require partials/product_video.php`, bố cục mới cho thẻ "Quyền lợi mua hàng" (`is-wide`, `col-lg-5`). Hiện chitiet dùng `ProductVideo` model của rcm_index; `ProductVideoClient.php` và `partials/product_video.php` vẫn còn nhưng **không ai gọi**. | **Cần quyết định**: các commit d445536/5acf2f0 (đăng video lên trang sản phẩm) dựa vào luồng này nên có vẻ hiện chưa hiển thị trên chitiet. Đề xuất áp lại `loadProductVideos` và partial, rồi chọn một trong hai nguồn video (đừng hiển thị cả hai). | `chitiet`: LOW (chỉ route `index.php`). Rủi ro chính là hiển thị trùng với khối `$productVideos` cũ (chitiet.php ~dòng 933) và CSS `.purchase-policy-*` trùng tên. Cần chạy E2E trang chi tiết. |
| C4 | `frontend/views/tatca.php` | Lọc/sắp xếp không tải lại trang: `data-live-filter data-live-target="#catalogResults" data-live-also="#catalogSummary" data-live-reset="page"`, `id="catalogResults"`/`catalogSummary`/`catalogSortSelect`, style `aria-busy`. | Nên áp lại (chỉ là thuộc tính; `live-filter.js` vẫn còn). | Thấp. Kiểm tra `live-filter.js` hiện tại còn hỗ trợ `data-live-also`/`data-live-reset`. |
| C5 | `frontend/views/admin/categories.php` | `data-live-target="#categoryResults"`, `id="categoryResults"` + `aria-live`, `autocomplete="off"`. Hiện form chỉ có `data-live-filter="true"`. | Nên áp lại (3 dòng). | Thấp. |

## Nhóm (a): đã có trong code hiện tại

| File | Cụm thay đổi |
|------|--------------|
| `HomeController.php` | `'analytics_sid' => ProductEventTracker::sessionHash()` khi gọi `taoDonHang` (~dòng 4710); `adminSepayManualSync()` (có trong working tree, chưa commit); các khối gợi ý routine (đã khôi phục, không đụng) |
| `backend/public/index.php`, `frontend/public/index.php`, `index.php` | route `admin_sepay_manual_sync` và đủ mọi route `admin_ai_idol_*` (chỉ khác cách xuống dòng) |
| `.env.example`, `.gitignore`, `docker-compose.yml`, `ai-idol-service/tasks.py`, `LiveController.php`, `QuanTriController.php`, `TaiKhoanController.php`, `GoiYContentBased.php`, `HoaDon.php`, `routes/ai_idol.php`, `admin/layouts/header.php`, `admin/staff_products.php`, `home.php`, `hoso.php` | Mọi dòng stash thêm vào đều có trong file hiện tại (diff còn lại do rcm_index thêm code) |
| `SanPham.php` | Phần lớn có; 7 dòng thiếu là `LISTING_PROJECTION`/WR-trên-Atlas, working tree đang có thay đổi chưa commit ở file này. Đề nghị phiên đang sửa SanPham tự đối chiếu, xem mục "Lưu ý" |

## Nhóm (b): đã bị thay có chủ đích

| File | Cụm thay đổi | Thay bằng |
|------|--------------|-----------|
| `frontend/public/assets/js/ai-idol-studio.js`, `css/ai-idol-studio.css`, `views/admin/ai_idol/index.php` | Studio cũ | Studio mới với 2 chế độ (35f5ca2…169f13f, ac1d1e2) |
| `AdminAiIdolController.php` | Dựng cây danh mục ngay trong action | `buildCatalogTree()` (cùng logic, đã tách hàm) |
| `ai-idol-service/agents/service.py`, `core/campaigns.py`, `core/agent_runs.py`, `app.py` | Lịch autopilot, assembly, CANCELLED, `purpose` | Có đủ ở bản mới hơn (`_autopilot_query_outcome`, `claim_campaign_for_assembly`, `DONE_SEGMENT_STATUSES` có thêm SKIPPED, `purpose_of`, `approve_external_platform`); các dòng "thiếu" chỉ do viết lại |
| `HomeController.php` | regex `isNonFacialAiProduct` | `SkincareProductClassifier::isNonFacial` |
| `backend/app/content/tfidf_cache.json`, `simple_recommender_cache.json` | cache sinh tự động | Không áp lại (file sinh ra) |

## Lưu ý

- Chưa sửa file nào, chưa pop/drop stash. Impact chạy bằng GitNexus CLI (`impact --direction upstream`).
- `SanPham.php` là file đang có thay đổi chưa commit, nên không xếp chắc vào nhóm c. Cần đối chiếu lại với `git diff 'stash@{0}^1' 'stash@{0}' -- backend/app/models/SanPham.php` trước khi commit.
- Gợi ý thứ tự làm: C2 → C4/C5 → C1 (có test so sánh) → C3 (sau khi chọn nguồn video).

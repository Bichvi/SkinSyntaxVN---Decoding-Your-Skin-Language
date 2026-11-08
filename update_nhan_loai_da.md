# Sửa nhãn "Hợp moi_loai_da" trên gợi ý giỏ hàng (2026-11-05, chưa commit)

## Triệu chứng

Khối "Còn thiếu: Kem dưỡng ẩm" ở giỏ hàng gắn nhãn **"Hợp moi_loai_da"** cho Kem Dưỡng Ẩm Klairs Làm Dịu & Phục Hồi Da Ban Đêm (mã 139, 4450, ...). Trang chi tiết của sản phẩm lại ghi "Loại da: Da nhạy cảm".

## Nguyên nhân

1. **Dữ liệu tag do LLM gợi ý.** Bản ghi `san_pham` có `ma_loai_da: 3` (Da nhạy cảm) nhưng tag đã duyệt là `loai_da_keys: ['da_nhay_cam', 'moi_loai_da']` với `tag_source: 'llm_approved'`. Trong `graph_sync/curation.py`, hàm `resolve_tags` **cộng dồn** loại da được duyệt vào loại da gốc. Vì vậy sản phẩm có thêm cạnh `SUITABLE_FOR -> moi_loai_da`. Có 446 sản phẩm đã có loại da riêng (dầu, nhạy cảm, khô, mụn) bị dính lỗi này.
2. **Nhãn là loại da của khách.** `cart_analyze.rank_step_candidates` trả `fits_skin_type` với tham số là loại da **trong hồ sơ khách**. Hồ sơ mã 2 "Da thường/Mọi loại da" ứng với `moi_loai_da`, nên khớp với cạnh thừa ở mục 1.
3. **Thiếu nhãn hiển thị.** `backend/app/config/search_reasons.php` không có `moi_loai_da` và `da_mun`, nên thẻ in ra mã thô.

## Thay đổi

| File | Nội dung |
| --- | --- |
| `backend/app/config/search_reasons.php` | Thêm nhãn `moi_loai_da => 'da thường'` (để thẻ ghi "Hợp da thường", không ghi "Hợp mọi loại da") và `da_mun => 'da mụn'`. |
| `ai-service-flask/graph_sync/curation.py` | Thêm `UNIVERSAL_SKIN = "moi_loai_da"`. Khi sản phẩm có loại da gốc (`source=data`) và tag là `llm_approved`, bỏ `moi_loai_da` khỏi phần cộng thêm. Tag `manual` do admin tick tay vẫn được giữ, các loại da khác do LLM thêm cũng vẫn giữ. Sản phẩm không có loại da gốc (Unknown) giữ hành vi cũ. |
| `ai-service-flask/tests/graph_sync/test_curation.py` | Thêm 3 test: LLM không được nới thành mọi loại da, các loại da khác vẫn được thêm, admin tick tay vẫn được giữ. |
| `backend/tests/unit/SearchFallbackTest.php` | Thêm 2 kiểm tra nhãn. |

Dữ liệu Mongo **không bị sửa**. Lỗi được chặn khi chuyển dữ liệu sang Neo4j.

## Kiểm tra đã chạy

- `php -l` hai file PHP: OK.
- `php backend/tests/unit/SearchFallbackTest.php`: 55/55. `php backend/tests/unit/CartInsightsTest.php`: 37/37.
- `pytest tests/graph_sync` (đã đặt rỗng biến kết nối DB): 123 passed.
- Chạy chỉ đọc `to_graph_row` trên toàn catalog Atlas: 446 sản phẩm bị gắn thừa, sau sửa còn **0**. Klairs 139 và 4450 chỉ còn `da_nhay_cam (data)`.
- `git diff --check`: sạch.
- **Đồng bộ Neo4j** bằng `python -m graph_sync --full` (chạy trong `ai-service-flask`, nạp `.env` gốc), được người dùng cho phép. Kết quả: exit 0, 2853 sản phẩm, `SUITABLE_FOR=3638`, mất 75 s. Khoá Redis `graph_sync:lock` báo timeout nên chạy không khoá (chế độ fail-open có sẵn).
- **Neo4j sau khi đồng bộ:** Klairs 139, 4450, 506, 673 chỉ còn `da_nhay_cam/data`. Số sản phẩm có loại da riêng nhưng vẫn có cạnh `llm_approved -> moi_loai_da`: **0**.
- **Gọi thật `POST /api/cart/analyze`** (Flask :5001) với giỏ 3 sản phẩm Klairs và hồ sơ `moi_loai_da`: mọi gợi ý `fits_skin_type` đều là sản phẩm có `ma_loai_da` 2 (hoặc 4 Unknown). Không còn sản phẩm cho da nhạy cảm.
- E2E trên trình duyệt (đăng nhập, giỏ hàng): **CHƯA CHẠY**. Phần hiển thị "Hợp da thường" đã được phủ bằng unit test của `SearchReasonFormatter`.

## Ghi chú

- Cache phân tích giỏ hàng (`SEARCH_CART_CACHE_TTL`, mặc định 600 s) tự hết hạn trong tối đa 10 phút.
- Khi admin sửa sản phẩm, đồng bộ theo sự kiện (`--ids`) dùng cùng `resolve_tags`, nên luật mới tự áp dụng.

---

# Phần 2: Kem chống nắng bị gắn "thâm mụn" ở khối "Theo vấn đề da" (2026-11-05, chưa commit)

## Triệu chứng

Trang chủ, khối "Theo vấn đề da: THÂM MỤN", hiện Tinh Chất Chống Nắng Sunplay Hiệu Chỉnh Sắc Da với lý do "Chứa Ascorbic Acid, hỗ trợ thâm mụn".

## Nguyên nhân

1. **Đọc sai bảng INCI.** Trường `thanh_phan` của khoảng 30 sản phẩm mở đầu bằng đoạn quảng cáo, ví dụ `"Hyaluronic Acid, Vitamin C, Pearl Powder. Bảng thành phần: Water, ..."`. Bộ đọc (`graph_sync/ingredients.py`) chưa nhận "Bảng thành phần:" là tiêu đề, nên "Vitamin C" thành Ascorbic Acid đứng hạng 2. Ngoài ra `thanh_phan_sach` của các sản phẩm này đã bị cắt mất đầu bảng INCI thật (Water, chất chống nắng...).
2. **Luật suy vấn đề da quá rộng.** Mọi hoạt chất trong top 15 đều sinh ra vấn đề da, kể cả hoạt chất liều thấp trong kem chống nắng, trang điểm, tẩy trang, sữa rửa mặt.

## Thay đổi (`ai-service-flask/`)

| File | Nội dung |
| --- | --- |
| `graph_sync/ingredients.py` | `_MARKER` nhận thêm `Bảng thành phần:`. Thêm hàm `has_detail_marker()`. Đoạn mở đầu đứng trước tiêu đề "bảng INCI thật" bị bỏ, **trừ khi** bản thân nó là một danh sách INCI dài (các file export ghép nhiều biến thể sản phẩm). |
| `graph_sync/transform.py` | Thứ tự nguồn thành phần: `thanh_phan_full` → `thanh_phan` gốc nếu có tiêu đề INCI (nguồn `raw`) → `thanh_phan_sach`. Truyền chuỗi danh mục vào `infer_tags`. |
| `graph_sync/rules.py` | `NO_CONCERN_INFERENCE_CATEGORIES` = kem_chong_nang, chong_nang_co_the, trang_diem, tay_trang, sua_rua_mat. Các danh mục này không tự suy vấn đề da từ thành phần nữa; công dụng và loại da vẫn suy như cũ. Nhãn vấn đề da do admin hoặc LLM đã duyệt (`van_de_da_keys`) vẫn được giữ. |
| `graph_sync/extract.py` | Thêm `thanh_phan` vào projection (`incremental.py` và `scripts/llm_tag_products.py` dùng chung projection này). |
| `tests/graph_sync/test_ingredients.py`, `test_transform.py`, `test_rules.py`, `test_curation.py` | Thêm test cho các ca trên. Sửa 2 fixture cũ dùng danh mục sữa rửa mặt. |

GitNexus báo rủi ro **HIGH** cho `parse_ingredients` (13 chỗ phụ thuộc: graph_sync, label_service, nhánh dự phòng cart_analyze, scripts) và `infer_tags` (8 chỗ). Cách giảm rủi ro: luật danh mục chỉ bật khi được truyền `categories`, và bộ đọc INCI được đo trước/sau trên toàn catalog.

## Đo trước/sau (2853 sản phẩm, chỉ đọc)

- Lần thử đầu, bỏ mọi đoạn mở đầu, làm đổi top 5 của 116 sản phẩm, trong đó khoảng 86 bị đổi sai (file export ghép nhiều biến thể). Đã thu hẹp luật: sau khi sửa, **chỉ 30 sản phẩm** đổi top 5/top 15; 19 sản phẩm chuyển từ nguồn `sach` sang `raw`.
- 100 sản phẩm mất nhãn `tham_mun`, tất cả do luật danh mục. Không sản phẩm nào được thêm vấn đề da mới.
- Sữa rửa mặt gắn "mụn": 109 → 87 (phần còn lại là nhãn đã duyệt). Kem chống nắng gắn "thâm mụn": 59 → 14 (đều là nhãn đã duyệt).

## Kiểm tra đã chạy

- `pytest tests/graph_sync tests/label_service tests/search_service -m "not integration"` (đặt rỗng biến kết nối DB): 657 passed, 12 deselected.
- `git diff --check`: sạch.
- `python -m graph_sync --full`: exit 0, 106 s. Nguồn thành phần: full 2676, raw 19, sach 109, none 49. `TARGETS` 5402 → 5003.
- Neo4j: Sunplay 1659, 192 và 886 bắt đầu bằng water, ethylhexyl_methoxycinnamate và không còn `TARGETS`. Sunplay 725 chỉ còn dau_thua/xin_mau (llm_approved).
- Truy vấn khối "thâm mụn" của trang chủ: top 12 giờ đều là serum hoặc bộ sản phẩm có Niacinamide, Tranexamic Acid hay dẫn xuất vitamin C ở top 5. Không còn kem chống nắng.
- E2E trên trình duyệt: **CHƯA CHẠY**. Cache trang chủ (`HOME_GRAPH_CACHE_TTL`, 600 s) tự hết hạn trong tối đa 10 phút.

## Còn lại

- Mặt nạ Naruko Hoa Hồng vẫn được gắn "thâm mụn" (suy từ Tranexamic Acid ở hạng 5 của INCI thật). Như vậy là đúng.
- Nhánh dự phòng Neo4j của giỏ hàng (`cart_analyze`) vẫn đọc `full`/`sach` như cũ, chưa dùng nguồn `raw`.

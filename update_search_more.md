# Gợi ý thêm ở trang tìm kiếm có kết quả (2026-11-09, chưa commit)

## Mục tiêu
Khi `index.php?r=tatca&q=...` đã có kết quả (ví dụ "rau má"), hiện thêm khối "Gợi ý thêm cho bạn" bên dưới kết quả/phân trang, tải lười để trang tìm kiếm không chậm đi.
Khối cũ phía máy chủ (`search_fallback.php` khi trang 1 có dưới 4 kết quả) giữ nguyên; khối mới chỉ hiện ở trường hợp còn lại.

## Luồng
1. `tatca.php` đặt placeholder `.ssm-root[data-search-more]` bên trong `#catalogResults` (sau phân trang), kèm `data-url` chứa `q` và id các sản phẩm đang hiện.
2. `search-more.js` dùng IntersectionObserver (rootMargin 600px) rồi `fetch` với timeout 6 giây. Quét lại khi có sự kiện `live-filter:updated`.
3. `SanPhamController::searchMore()` gom dữ liệu, `SearchMoreBuilder::build()` ghép, partial `search_fallback.php` hiển thị (`$ssfHasExact = true`).
4. Tracking impression/click do `search-fallback.js` lo (MutationObserver).

Các phần trong khối:
- "Hợp với làn da của bạn" (key `personalized`): chỉ khi đã đăng nhập và có hồ sơ da; lấy từ `HomeGraphRecommender` (khối `skin`), cần tối thiểu 2 sản phẩm sau khi loại trùng.
- Các khối của Flask search recommender (`SearchRecommendClient::recommend` + `SearchFallbackBuilder`), bỏ khối `semantic` vì trùng kết quả chính.
- Thẻ CTA: khách chưa đăng nhập -> mời đăng nhập + làm khảo sát (`r=dangnhap`, `r=goiy`); đã đăng nhập nhưng chưa có hồ sơ da -> mời làm khảo sát.

## Route `r=search_more`
Tham số: `q` (bắt buộc, tối đa 200 ký tự), `exclude` (id cách nhau dấu phẩy, tối đa 48).
- 200: HTML partial.
- 204: tính năng tắt, lỗi, hoặc không có gì để hiện.
- 400: `q` rỗng/quá dài.
- 429: vượt giới hạn (kèm `Retry-After: 30`).

Cờ: `SEARCH_MORE_ENABLED` (mặc định `1`; `0/false/off/no` thì tắt, trả 204).
Rate limit: 60 lượt/phút theo phiên (`search_more`), 120 lượt/phút theo IP (`search_more_ip`); Redis lỗi thì cho qua.

## File thay đổi
- Mới: `backend/app/services/SearchMoreBuilder.php`, `backend/tests/unit/SearchMoreTest.php`, `frontend/public/assets/js/search-more.js`, `update_search_more.md`.
- Sửa: `backend/app/controllers/SanPhamController.php` (`searchMore`, `searchMoreAllowed`), `frontend/public/index.php` (route), `frontend/views/partials/search_fallback.php` (thẻ CTA tùy chọn), `frontend/views/tatca.php` (placeholder + script), `frontend/public/assets/css/search-fallback.css`.

## Cách test
- `php backend/tests/unit/SearchMoreTest.php` và `php backend/tests/unit/SearchFallbackTest.php` (thuần, không DB).
- Smoke: `curl "http://localhost:8080/index.php?r=search_more&q=rau%20m%C3%A1"` (khách: 200, có `data-ssf-skin-cta="login"`, không có khối `semantic`); `q` rỗng -> 400.

## Giới hạn
- Mục "Hợp với làn da" cần hồ sơ da và đồ thị trang chủ hoạt động.
- Phần tìm kiếm của Flask đang tắt đồ thị (`SEARCH_GRAPH_ENABLED` mặc định false) nên hiện chỉ có "Được mua nhiều tuần này" / "Sản phẩm bán chạy".

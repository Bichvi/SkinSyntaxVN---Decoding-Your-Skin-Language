# Trang chủ khách: Khám phá nhanh, Lý do gợi ý, Routine mẫu theo loại da

Ngày: 2026-11-09 · Trạng thái: đã duyệt thiết kế trong chat

## Mục tiêu

Khách chưa đăng nhập (và người chưa làm khảo sát) vào trang chủ thấy nội dung "nói chuyện" với họ ngay,
mượn điểm mạnh của trang tìm kiếm: gợi ý có lý do, chip lọc nhanh, từ khóa phổ biến.

Ràng buộc:
- KHÔNG sửa/xóa thuật toán gợi ý hiện có (`getSimpleRecommenderProducts`, `getHybridRecommendations`,
  `getHomepageProductSections`, các khối 2/3/5.5 trong `home.php`). Chỉ thêm bên cạnh.
- Giữ MongoDB Atlas; mỗi nguồn dữ liệu mới đều có cache (CacheService) để không làm chậm trang chủ.
- Lỗi Redis/Mongo ở khối mới -> ẩn khối hoặc dùng dữ liệu dự phòng, không bao giờ làm hỏng trang.
- Mọi chuỗi ra view đều escape bằng `h()`.

## Khối 1 — "Khám phá nhanh" (dưới hero, thay khối 6 thẻ "Bạn đang quan tâm điều gì cho làn da?")

- Hàng "Mọi người đang tìm": `SearchPopularity::top(int $limit, ?RedisLite $redis = null): array`
  (method MỚI, `ZREVRANGE search:popular:v1 0 N WITHSCORES`), lấy tối đa 5 từ có score >= 2, chạy lại
  `normalize()` để lọc PII/ký tự lạ; bù cho đủ 8 bằng danh sách soạn sẵn, loại trùng theo `fold()`.
- Hàng "Tìm theo hoạt chất": 8 hoạt chất soạn sẵn, mỗi cái 1 dòng công dụng.
- Danh sách soạn sẵn ở `backend/app/config/home_discovery.php`.
- Logic gộp là hàm thuần `HomeDiscovery::trendingTerms(array $redisTop, array $curated, int $max = 8)`.
- Link: `index.php?r=tatca&q=<urlencode>`.
- Khối 6 thẻ cũ bị bỏ vì trùng ý với khối chip "Bạn đang quan tâm vấn đề da nào?" (khối này giữ nguyên).

## Khối 2 — Lý do trên thẻ sản phẩm

- Hàm thuần `HomeCardReason::for(array $p): string`, thứ tự ưu tiên:
  1. `van_de_da_keys` (tối đa 2 nhãn) + `loai_da_keys` (1 nhãn, bỏ qua `moi_loai_da`) ->
     "Hỗ trợ mụn, dầu thừa · Hợp da dầu" (một trong hai phần có thể vắng)
  2. `diem_danh_gia` >= 4.5 và `so_luong_danh_gia` >= 20 -> "4,9★ từ 546 đánh giá"
  3. `so_luong_da_ban` >= 10 -> "Đã bán 229"
  4. '' (không hiện ô lý do)
  Nhãn tiếng Việt lấy từ `backend/app/data/tag_keys.json` (viết thường phần sau chữ đầu).
- Áp dụng cho khối "Sản phẩm nổi bật" (chỉ khi chế độ SIMPLE, tức khách chưa có tín hiệu),
  "Được yêu thích nhất", "Mỹ phẩm vừa lên kệ".
- Sản phẩm từ cache gợi ý thiếu nhãn -> `HomeController` gọi `findVisibleByIds()` MỘT lần cho các id
  đang hiển thị thiếu nhãn, chỉ chép 3 trường nhãn vào sản phẩm (không đổi thứ tự/thành phần danh sách);
  cache 10 phút, khóa theo danh sách id.
- `home_product_card.php` thêm tham số thứ 4 tùy chọn `$whyLabel = 'Vì sao phù hợp'` (tương thích ngược);
  các khối khách truyền 'Vì sao gợi ý'.

## Khối 3 — "Routine mẫu theo loại da" (thay hộp trống khi `!$hasSurvey`)

- Tab: `SearchSkinProfile::SKIN_TYPES` — Da dầu / Da khô / Da nhạy cảm / Da mụn / Da thường (`moi_loai_da`).
- 4 bước, mỗi bước 1 sản phẩm còn hàng, hiển thị được (cùng điều kiện với `findVisibleByIds`):
  - Làm sạch: danh_muc_day_du kết thúc "Làm Sạch Da -> Sữa Rửa Mặt"
  - Đặc trị: "Đặc Trị -> Serum / Tinh Chất"
  - Dưỡng ẩm: "Dưỡng Ẩm -> Kem / Gel / Dầu Dưỡng" hoặc "Dưỡng Ẩm -> Lotion / Sữa Dưỡng"
  - Chống nắng: "Chống Nắng Da Mặt"
  Ưu tiên `loai_da_keys` chứa đúng loại da, không có thì lấy `moi_loai_da`; sắp xếp `so_luong_da_ban` giảm,
  rồi `diem_danh_gia` giảm. Method MỚI `SanPham::routineStarter(string $skinKey): array`, cache 30 phút / loại da.
- Hiện tổng giá 4 sản phẩm. Bước thiếu sản phẩm -> ẩn bước đó; < 2 bước -> thông báo "Chưa đủ sản phẩm".
- Đổi tab: AJAX `GET index.php?r=home_routine&skin=<key>` trả HTML lưới (không layout), theo mẫu
  `HomeController::concern()`. Key không hợp lệ -> 400. Route lưu `$_SESSION['guest_skin_type']`
  (chỉ khi chưa đăng nhập hoặc chưa có khảo sát).
- Tab mặc định: `$_SESSION['guest_skin_type']` nếu có, không thì `da_dau`.
- Khách đã chọn loại da -> `index()` truyền `['skin_type' => key, 'concerns' => []]` làm `skin_profile`
  cho `homeGraphRecommender()->build()` khi chưa đăng nhập, để khối "Hợp với ... của bạn" hiện.
- Cuối khối: "Muốn routine chính xác hơn? Làm khảo sát da →" (`r=khaosat`).
- Người đã làm khảo sát: khối routine giữ nguyên như cũ.

## Không làm trong bản này

Nút "Thêm cả routine vào giỏ" (cần thêm nhiều sản phẩm vào giỏ cùng lúc).

## Kiểm thử

- `php -l` mọi file PHP đã sửa/thêm.
- Test thuần mới (không DB, Redis giả): `backend/tests/unit/HomeDiscoveryTest.php` (trendingTerms, top() với
  Redis giả), `backend/tests/unit/HomeCardReasonTest.php`. Chạy đích danh, KHÔNG chạy hàng loạt `backend/tests`.
- Chạy lại `php backend/tests/home_reason_text_test.php` (không đổi hành vi).
- Trình duyệt: trang chủ khách ở 1366px và 375px; đổi tab routine; bấm chip; không lỗi console;
  đo thời gian tải trang chủ trước/sau.

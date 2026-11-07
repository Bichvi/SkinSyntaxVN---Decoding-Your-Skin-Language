# BƯỚC 06 — TÍCH HỢP PHP: GỌI API GỢI Ý KHI ÍT/KHÔNG CÓ KẾT QUẢ

## Vai trò
Kỹ sư backend PHP. Nối trang tìm kiếm `index.php?r=tatca&q=...` với API Flask `POST /api/search/recommend`, đảm bảo **không bao giờ trắng trang** kể cả khi Flask chết. Bước này chỉ chuẩn bị dữ liệu cho view; giao diện làm ở Bước 07.

## Đọc trước
1. `AGENTS.md`
2. `docs/plans/smart-search-handoff.md` (mục Bước 01: hàm tìm kiếm mới; mục Bước 05: URL, header auth, ví dụ response, đường dẫn file JSON schema contract)
3. `backend/app/controllers/SanPhamController.php` (hàm xử lý route `tatca`, ~dòng 330-400)
4. `backend/app/models/SanPham.php` (`paginate`, `availableProductFilter`, `LISTING_PROJECTION`, các hàm lấy SP bán chạy nếu có)
5. Nơi PHP đang gọi Flask hiện có (theo handoff/audit mục 5) — **tái sử dụng cấu hình URL/secret, cách gọi curl**.
6. `ai-service-flask/search_service/contract/recommend_response.schema.json`

## Bối cảnh
- Nguyên tắc: PHP chỉ gọi Flask (không gọi Neo4j/Chroma). Flask trả `product_id` + `reason_code`; PHP tự lấy dữ liệu hiển thị từ Mongo để luôn đúng giá/tồn kho.
- Copy hiển thị **không ghi tên hãng** kiểu "shop chưa có sản phẩm của X".

## Quy trình bắt buộc
- `git status`.
- GitNexus impact upstream: hàm controller của route `tatca`, `SanPham::paginate`, và bất kỳ hàm có sẵn nào bạn sửa. HIGH/CRITICAL → dừng, báo người dùng.
- Test trước cho phần thuần (map reason, giữ thứ tự id, validate response).

## Yêu cầu

### 1. `backend/app/services/SearchRecommendClient.php`
```php
final class SearchRecommendClient {
    public function __construct(string $baseUrl, string $internalToken, int $timeoutMs = 1200, int $connectTimeoutMs = 300) {}
    /** @return array|null  response đã validate, null nếu lỗi/timeout/circuit mở */
    public function recommend(string $q, ?string $userHash, ?array $skinProfile, array $cartIds, array $excludeIds, int $limitPerSection = 8): ?array {}
}
```
- curl POST JSON, header auth theo đúng cơ chế hiện có, header `X-Request-Id` (uuid hoặc id request có sẵn).
- `CURLOPT_TIMEOUT_MS`, `CURLOPT_CONNECTTIMEOUT_MS`, `CURLOPT_RETURNTRANSFER`; không retry.
- **Validate response** tối thiểu: `sections` là mảng; mỗi item có `product_id` (string, regex `^[A-Za-z0-9_-]{1,32}$`), `reason_code` thuộc danh sách cho phép; item sai → bỏ item; cấu trúc sai → `null`.
- **Circuit breaker đơn giản**: 3 lỗi liên tiếp → bỏ qua gọi Flask 60 giây. Lưu trạng thái ở APCu nếu có, không thì Redis/Valkey nếu dự án đang dùng (xem audit), không có cả hai → chỉ theo request (không breaker). Ghi log lỗi bằng logger có sẵn, **không log** token, user hash, hồ sơ da.
- Cấu hình URL/token đọc từ nơi cấu hình hiện có (env/config) — không hardcode.

### 2. Model `SanPham`
- `findVisibleByIds(array $ids): array` — 1 truy vấn `['ma_san_pham' => ['$in' => $ids]]` (ép kiểu id đúng như dữ liệu thật — kiểm tra int/string theo handoff) AND `availableProductFilter()`, projection `LISTING_PROJECTION`; trả về **đúng thứ tự** `$ids` (map PHP), bỏ id không tìm thấy.
- `bestSellers(int $limit = 8): array` — nếu đã có hàm tương đương thì dùng lại; không thì sort theo trường lượt bán, chỉ SP đang bán.
- Không đổi chữ ký hàm có sẵn.

### 3. Copy lý do — `backend/app/config/search_reasons.php` (hoặc thư mục config hiện có)
```php
return [
  'sections' => [
    'category_alt' => 'Lựa chọn tương đương cùng công dụng',
    'same_brand'   => 'Cùng dòng sản phẩm bạn đang quan tâm',
    'personalized' => 'Hợp với làn da của bạn',
    'semantic'     => 'Có thể bạn đang tìm',
    'trending'     => 'Được mua nhiều tuần này',
    'best_sellers' => 'Sản phẩm bán chạy',      // fallback PHP
  ],
  'reasons' => [
    'shared_ingredients' => 'Chung hoạt chất %s',
    'fits_skin_type'     => 'Hợp %s',
    'targets_concern'    => 'Hỗ trợ %s',
    'same_category'      => 'Cùng công dụng',
    'same_brand'         => 'Cùng dòng sản phẩm',
    'semantic_match'     => 'Gần với từ khóa',
    'trending'           => 'Bán chạy',
    'bought_together'    => 'Thường mua cùng',
  ],
  'labels' => [ 'da_kho' => 'da khô', 'da_dau' => 'da dầu', 'da_nhay_cam' => 'da nhạy cảm', 'mun' => 'da mụn' /* … đủ key từ handoff Bước 03 */ ],
];
```
Hàm thuần `SearchReasonFormatter::format(string $code, array $params): string` — map key→label, nối tối đa 3 param bằng `, `, escape ở view (không escape trong formatter).

### 4. Controller (route `tatca`)
Giả mã:
```php
$res = $this->model->paginate(...);                       // giữ nguyên
$fallback = null;
if ($q !== '' && $page === 1 && $res['total'] < 4) {
    $fallback = $this->buildSearchFallback($q, idsOf($res['items']));
}
// truyền thêm 'searchFallback' => $fallback sang view; mọi biến cũ giữ nguyên
```
`buildSearchFallback` (private):
1. `userHash` = `hash('sha256', email . SALT)` nếu đăng nhập (SALT từ config/env, không có thì không gửi); `skinProfile` rút gọn từ hồ sơ khảo sát (chỉ `skin_type`, `concerns`); `cartIds` từ giỏ (session) tối đa 50.
2. Gọi client. `null` → `sections = [['key'=>'best_sellers','items'=> bestSellers(8)]]`, `degraded=true`.
3. Có response → gom mọi `product_id` → `findVisibleByIds` một lần → phân lại theo section, giữ thứ tự, gắn `reason_text` qua formatter; section < 1 SP → bỏ; tất cả rỗng → best sellers.
4. Kết quả: `['degraded'=>bool, 'intent'=>array|null, 'chips'=>[...], 'sections'=>[['key','title','items'=>[[product..., 'reason_text'=>...]]]]]`.
5. `chips` sinh từ intent (dữ liệu, chưa render): có brand → `{label:'Cùng thương hiệu', url:'index.php?r=tatca&q=<brand name>'}`; có category → `{label:'<tên danh mục>', url: link lọc danh mục hiện có}`; luôn có `{label:'Bán chạy', url:'index.php?r=tatca&sort=<giá trị sort bán chạy hiện có>'}`. Dùng `http_build_query`.
- Chỉ trang 1 mới gọi fallback. Không gọi khi `q` rỗng.
- Tổng thời gian thêm vào trang khi Flask chết ≤ 1.3s; khi breaker mở ≈ 0.

### 5. Bảo mật
- Escape toàn bộ ở view (Bước 07 làm), không tin `reason_params` từ Flask: formatter chỉ nhận chuỗi ≤ 40 ký tự, bỏ phần dư.
- Giữ nguyên CSRF/rate limit/validate hiện có của trang.

## Kiểm thử
- Test thuần (không DB): validate response client (item hợp lệ/không hợp lệ), formatter (đủ code, label lạ, param dài), giữ thứ tự id, sinh chips.
- Contract test: đọc file JSON schema của Bước 05 và response mẫu (`ai-service-flask/search_service/contract/` hoặc fixture), đảm bảo client parse đúng.
- **Không chạy các script cũ trong `backend/tests/`** (ghi DB thật).

## Kiểm chứng (chạy thật)
- `php -l` mọi file đổi.
- Trên `http://localhost:8080` (view chưa sửa UI thì `var_dump`/log tạm **không commit**, hoặc kiểm tra qua biến truyền view bằng test):
  - `xyzabc` → có `searchFallback` với section.
  - Tắt Flask → vẫn có `best_sellers`, trang tải ≤ ~1.5s; gọi liên tiếp 4 lần → lần 4 không chờ timeout (breaker).
  - `torriden sữa rửa mặt` (sau Bước 01 có kết quả ≥ 4) → không gọi Flask.
- `git diff --check`.

## Đầu ra
Client, formatter, config, hàm model, controller, test. Mục "Bước 06" trong handoff: cấu trúc mảng `searchFallback` truyền sang view (Bước 07 cần chính xác shape này), tên biến, tên partial, cách tắt tính năng nếu cần. Không commit nếu chưa được yêu cầu.

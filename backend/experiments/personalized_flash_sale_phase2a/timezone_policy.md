# CHÍNH SÁCH XỬ LÝ MÚI GIỜ (TIMEZONE POLICY)

## 1. Khảo sát hiện trạng PHP & MongoDB trong hệ thống SkinSyntaxVN
- Môi trường máy chủ PHP: Chạy trên PHP 8.2+ Windows XAMPP.
- Cấu hình MongoDB: Sử dụng tiện ích mở rộng driver `ext-mongodb` và thư viện chính thức `mongodb/mongodb`.
- Trong MongoDB, kiểu dữ liệu thời gian chuẩn là `MongoDB\BSON\UTCDateTime`, lưu trữ số mili-giây kể từ Unix Epoch (1970-01-01T00:00:00Z).
- Nếu sử dụng các chuỗi string như `"2026-10-07 14:00:00"` để so sánh bằng toán tử `<`, `>`, chuỗi đó sẽ bị phụ thuộc vào timezone cục bộ hoặc sai lệch khi truy vấn bằng MongoDB filter query.

---

## 2. Quy chuẩn 3 tầng múi giờ (Three-Tier Timezone Architecture)

Để đảm bảo tính xác thực 100% và ngăn ngừa lỗi lệch múi giờ (+7 tiếng hoặc -7 tiếng) giữa Việt Nam và UTC, Phase 2A áp dụng triệt để chính sách 3 tầng:

| Tầng (Tier) | Múi giờ áp dụng | Kiểu dữ liệu kỹ thuật | Mục đích sử dụng |
| :--- | :--- | :--- | :--- |
| **1. Storage Timezone (Lưu trữ)** | **UTC (+00:00)** | `MongoDB\BSON\UTCDateTime` | Dữ liệu lưu trong MongoDB Atlas. Mọi thời điểm `start_at`, `end_at`, `created_at`, `updated_at` đều được lưu chuẩn UTC mili-giây độc lập với múi giờ máy chủ. |
| **2. Comparison Timezone (So sánh)** | **Unix Timestamp UTC (Epoch seconds)** | `int` (số nguyên giây) | Mọi phép so sánh logic thời gian, tính toán khoảng thời gian chồng lấn (overlap), kiểm tra `now < start_at` hoặc `start_at <= now < end_at` đều quy về số nguyên giây UTC: `intval(time())`. Tuyệt đối không so sánh chuỗi ngày tháng. |
| **3. Display Timezone (Hiển thị)** | **Asia/Ho_Chi_Minh (UTC+07:00)** | Chuỗi định dạng `Y-m-d H:i:s` | Trả về phía người dùng, giao diện quản trị Admin, hoặc các nhãn `valid_until` trong API. |

---

## 3. Các hàm chuyển đổi chuẩn trong `FlashSaleCampaign.php`

1. **`normalizeDateTimeToUtc($input): ?\MongoDB\BSON\UTCDateTime`**:
   - Tiếp nhận input là `UTCDateTime`, timestamp số nguyên, chuỗi ISO 8601 (`2026-10-07T12:00:00Z`), hoặc chuỗi định dạng người dùng nhập (`2026-10-07 19:00:00`).
   - Nếu là chuỗi không kèm timezone, mặc định phân tích theo múi giờ `Asia/Ho_Chi_Minh`, sau đó chuyển thành Unix timestamp và đóng gói thành `MongoDB\BSON\UTCDateTime(timestamp * 1000)`.

2. **`utcDateTimeToTimestamp($utc): ?int`**:
   - Trích xuất timestamp số nguyên chính xác từ `MongoDB\BSON\UTCDateTime` bằng `$utc->toDateTime()->getTimestamp()`.

3. **`normalizeUtcToDisplayFormat($utc, string $format = 'Y-m-d H:i:s'): ?string`**:
   - Chuyển `UTCDateTime` sang múi giờ `Asia/Ho_Chi_Minh` và format thành chuỗi hiển thị thân thiện cho thị trường Việt Nam.

---

## 4. Bảng kiểm tra xác thực (Validation Matrix)

| Input từ người dùng / Admin | Thời điểm thực tế tại VN | Lưu trữ MongoDB Atlas (UTC) | So sánh Logic (Epoch Ts) | Hiển thị lại cho Client |
| :--- | :--- | :--- | :--- | :--- |
| `2026-10-07 19:00:00` | 19:00 (UTC+7) | `ISODate("2026-10-07T12:00:00.000Z")` | `1791374400` | `2026-10-07 19:00:00` |
| `2026-10-07 23:00:00` | 23:00 (UTC+7) | `ISODate("2026-10-07T16:00:00.000Z")` | `1791388800` | `2026-10-07 23:00:00` |

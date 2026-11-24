# Đánh giá nhanh các tính năng AI (feature_ratings)

## Mục đích
Thu mức độ hài lòng (thang 1-5 sao, kiểu Likert) của khách hàng đã đăng nhập theo từng thuật toán, làm số liệu cho chương đánh giá của luận văn. Popup nhỏ "Đánh giá nhanh" xuất hiện ngay sau khi khách dùng một trong ba tính năng.

## Điểm kích hoạt
| Khóa `feature` | Trang | Điều kiện | Độ trễ | Câu hỏi |
| --- | --- | --- | --- | --- |
| `recommendation` | `goiy` (có `[data-recommendation-root]`) | Khách bấm vào bất kỳ đâu trong vùng gợi ý, hoặc ở lại trang 30 giây | 2 giây | Các sản phẩm được gợi ý có phù hợp với làn da của bạn không? |
| `chatbot` | mọi trang có widget chat | Có tin trợ lý mới sau khi khách đã gửi từ 2 tin trở lên và không còn dòng "đang gõ" | 3 giây | Câu trả lời của trợ lý AI có hữu ích với bạn không? |
| `cart_conflict` | `giohang` (có `[data-cart-insights]`) | Có ít nhất một `.cin-conflict` đang hiển thị | 4 giây | Cảnh báo thành phần xung đột có giúp bạn chọn sản phẩm an toàn hơn không? |

Mã nguồn không sửa `ai_chat_widget.php`, `cart-insights.js`, `goiy.php`, `giohang.php`; JS dùng `MutationObserver` quan sát DOM. Chỉ hiện cho khách hàng đã đăng nhập (không hiện cho khách vãng lai, admin, nhân viên). Mỗi lần tải trang tối đa một popup.

## Dữ liệu (collection `feature_ratings`)
`account_key` (`email:<lower>` hoặc `user:<id>`), `role`, `feature`, `stars` (1-5), `comment` (tối đa 500 ký tự, đã bỏ ký tự điều khiển), `context` (`page` tối đa 60 ký tự, `rule_ids` tối đa 10 chuỗi), `created_at` (UTC), `first_created_at`, `day` (`Y-m-d`, múi giờ Asia/Ho_Chi_Minh).
Upsert theo `(account_key, feature, day)`: mỗi tài khoản tối đa một đánh giá cho mỗi tính năng mỗi ngày, lần gửi sau trong ngày ghi đè lần trước. Index: unique `(account_key, feature, day)` và `(feature, created_at)`.

## Route
- `POST ?r=feature_rating_submit`: JSON `{feature, stars, comment, context, csrf}` (CSRF cũng nhận qua header `X-CSRF-Token`; session key `feature_rating_csrf`). Mã trả về: 405, 401 `login_required`, 419 (CSRF sai), 422 (dữ liệu sai), 429 (quá 20 lượt / 10 phút / tài khoản, dùng `RateLimiter`), 503 (lỗi DB).
- `GET ?r=admin_feature_ratings_export`: chỉ admin, tải CSV (UTF-8 BOM).
- `GET ?r=admin_feature_ratings_summary`: chỉ admin, JSON thống kê.
Cả ba bộ định tuyến (`backend/public/index.php` — bản Docker đang chạy, `index.php` gốc và `frontend/public/index.php`) đều có route; hai route admin cũng nằm trong `route_access_map()`.

## Quy tắc tần suất (localStorage, khóa `ssfr:<userHash>:<feature>`)
- Sau khi gửi đánh giá: không hỏi lại cùng tính năng trong 7 ngày.
- Sau khi bấm "Bỏ qua", ×, hoặc Esc: 3 ngày.
- `userHash` là 12 ký tự hex đầu của sha256(account_key), không chứa email.

## Xuất CSV và đọc thống kê
Đăng nhập admin rồi mở `index.php?r=admin_feature_ratings_export`. Cột: `created_at` (giờ Việt Nam), `day`, `feature`, `stars`, `comment`, `role`, `context_rule_ids` (nối bằng `|`), `user` (12 hex đầu của sha256(account_key), không xuất email). Ô góp ý bắt đầu bằng `= + - @` được thêm dấu `'` để tránh công thức Excel.

`index.php?r=admin_feature_ratings_summary` trả về, cho từng tính năng: `count`, `mean` (2 chữ số thập phân), `std` (độ lệch chuẩn mẫu, n-1), `pct_ge4` (% đánh giá từ 4 sao trở lên, tức tỷ lệ hài lòng), `distribution` (số lượt cho 1..5 sao). Trong luận văn nên báo cáo kèm cỡ mẫu `count`.

## Quyền riêng tư
Không lưu hay xuất email trong CSV; cột `user` là mã băm cắt ngắn. `account_key` chỉ nằm trong DB. Log lỗi không ghi thông tin cá nhân.

## Tắt tính năng
Đặt `FEATURE_RATING_ENABLED=0` (hoặc `false`, `off`, `no`) trong môi trường: footer không nạp CSS/JS, endpoint trả 404. Mặc định bật.

## Kiểm thử đã chạy
- `php -l` trên các file PHP mới và đã sửa.
- `php backend/tests/unit/FeatureRatingTest.php` (không chạm DB): validate, giới hạn góp ý, whitelist context, thống kê, ngày theo múi giờ VN.
- `node --test frontend/public/assets/js/tests/feature-rating.test.js`: cooldown, đếm hàng chat, nhận diện hội thoại thật, gom rule xung đột.
- Chưa chạy E2E trên trình duyệt thật và chưa gọi endpoint với DB thật (tránh ghi dữ liệu).

## File liên quan
`backend/app/models/FeatureRating.php`, `backend/app/controllers/FeatureRatingController.php`, `frontend/public/assets/js/feature-rating.js`, `frontend/public/assets/css/feature-rating.css`, `frontend/views/layouts/footer.php`, `frontend/views/helpers.php`, `index.php`, `frontend/public/index.php`, `backend/public/index.php`.

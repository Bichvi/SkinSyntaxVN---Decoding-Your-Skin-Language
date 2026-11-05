# Bộ đếm lượt xem qua Redis

## Vấn đề
Mỗi lần mở trang chi tiết sản phẩm, `SanPham::tangLuotXem()` ghi `$inc luot_xem` đồng bộ lên MongoDB Atlas (cloud, khoảng 50-100 ms) nên làm chậm trang.

## Thiết kế
- PHP: `ProductViewCounter::record($id)` chạy `HINCRBY product:views:v1 <ma_san_pham> 1` trên Redis local (dưới 1 ms) và trả về số lượt đang chờ ghi.
- `SanPham::tangLuotXem()` trả `?int`: có số thì không ghi Mongo; trả `null` (Redis tắt/lỗi, id sai định dạng) thì ghi thẳng Mongo như cũ.
- `SanPhamController::chitiet()` hiển thị `luot_xem` = giá trị Mongo + số lượt đang chờ (hoặc +1 nếu ghi thẳng Mongo).
- Python: job `product_views_flush` (catalog-worker, mỗi 5 phút) `RENAME product:views:v1 -> product:views:v1:flushing` (nguyên tử, lượt xem mới vào key chính mới), `bulk_write` các `$inc` vào `san_pham` rồi xóa key flushing. Id toàn chữ số khớp cả dạng chuỗi và số nguyên.

## Công tắc
`PRODUCT_VIEWS_REDIS_ENABLED=0` (môi trường PHP) -> quay về ghi thẳng Atlas.

## Lỗi và cách xử lý
- Redis hỏng: PHP tự ghi thẳng Atlas, không mất lượt xem.
- Worker dừng: lượt xem cộng dồn trong Redis; `luot_xem` trong Mongo và thứ tự "xem nhiều" bị trễ tới khi worker chạy lại. Số hiển thị ở trang chi tiết vẫn đúng vì đã cộng phần đang chờ.
- Mongo lỗi lúc flush: giữ key flushing, lần sau xử lý trước rồi mới tới key chính.
- Crash giữa `bulk_write` và `DEL` có thể đếm đôi một lô đúng một lần (chấp nhận).

## File
- `backend/app/services/ProductViewCounter.php` (mới), `backend/app/models/SanPham.php`, `backend/app/controllers/SanPhamController.php`
- `ai-service-flask/shared/redis_kit/keys.py`, `ai-service-flask/workers/jobs.py`
- Test: `backend/tests/unit/ProductViewCounterTest.php`, `ai-service-flask/tests/ops/test_ops_product_views.py`
- Docs: `docs/redis/conventions.md`, `docs/ops/search-ops.md`

## Cách test
- `php backend/tests/unit/ProductViewCounterTest.php`
- `cd ai-service-flask && MONGO_URI= MONGODB_URI= python -m pytest tests/ops/test_ops_product_views.py tests/ops/test_ops_scheduler.py -q` (cần `fakeredis`, `lupa`)
- Chạy tay job: `python -m workers.catalog_worker --run-now product_views_flush` (cần restart catalog-worker để nạp job mới).

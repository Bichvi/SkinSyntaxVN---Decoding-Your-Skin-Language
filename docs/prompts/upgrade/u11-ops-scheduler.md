# U11 — VẬN HÀNH: LỊCH ĐỒNG BỘ TỰ ĐỘNG, CẬP NHẬT THEO SỰ KIỆN, GIÁM SÁT

## Vai trò
Kỹ sư SRE/data platform. Bỏ hết thao tác chạy tay: Neo4j, Chroma, từ điển tìm kiếm, cache tự cập nhật khi catalog đổi; có giám sát và cảnh báo.

## Đọc trước
`u00-common-rules.md` → handoff (mục U03: `redis_kit` streams/lock/pubsub, service `search-worker`; U04: export dictionary + decay popular; U06: zero-result report; U07: sự kiện `product_updated`) → `docker-compose.yml`, `graph_sync/__main__.py`, `search_service/scripts/reindex_chroma.py`, nơi admin lưu/xóa/ẩn sản phẩm (rg trong `QuanTriController`, model `SanPham` hàm insert/update/delete).

## Phần A — Phát sự kiện thay đổi catalog (PHP)
- Mọi điểm ghi sản phẩm (thêm, sửa, xóa, ẩn/hiện, đổi giá/tồn kho, import hàng loạt) gọi `CatalogEvents::publish(type, ma_san_pham)` → `XADD catalog:changes:v1 MAXLEN ~ 50000 * type <...> id <...> ts <...>` (qua `RedisLite` U03). Redis lỗi → ghi log cảnh báo, không chặn admin.
- GitNexus impact cho từng hàm ghi bị sửa; nếu số điểm ghi lớn (> 8) ưu tiên hook tập trung ở model.

## Phần B — Worker đồng bộ (Python, chạy trong service `search-worker` của U03 hoặc service mới `catalog-worker`)
- Consumer group `catalog_sync` trên `catalog:changes:v1`:
  - Gom sự kiện trong cửa sổ 30s (debounce) → tập id thay đổi.
  - Neo4j: chạy upsert cho các id đó (thêm hàm `graph_sync` sync theo danh sách id: `--ids 1,2,3`), deactivate id bị xóa/ẩn; sau đó tính lại SIMILAR_TO **chỉ cho các id đó + láng giềng trực tiếp** (thêm chế độ similarity cục bộ; nếu khó, đánh dấu "dirty" để job đêm tính toàn bộ).
  - Chroma: upsert embedding cho các id đó (hàm cục bộ trong `reindex_chroma.py`), xóa id bị xóa.
  - Cache: `publish_invalidate` (generation) cho `search:rec`, `search:suggest`, `product:similar`.
  - Dùng `RedisLock("graph_sync")` để không chạy chồng với job đêm.
  - XACK sau khi tất cả thành công; lỗi → retry/dead-letter theo U03.

## Phần C — Lịch định kỳ
Chọn MỘT cơ chế và giải thích (ưu tiên đơn giản, chạy được trong docker trên Windows):
- (khuyến nghị) **APScheduler** trong service worker với `RedisLock` để chỉ một instance chạy mỗi job; hoặc `supercronic` trong container worker.
| Job | Lịch | Lệnh |
|---|---|---|
| Đồng bộ tăng dần (lưới an toàn nếu sự kiện bị lỡ) | mỗi giờ phút 10 | `graph_sync --since <lần chạy trước>` (lưu mốc trong Redis `ops:graph_sync:last_since`) |
| Đồng bộ đầy đủ + similarity | 02:30 hằng đêm | `graph_sync --full` |
| Co-purchase | 03:00 hằng đêm | `graph_sync --derive co_purchase --days 90` |
| Reindex Chroma đầy đủ (kiểm lệch) | Chủ nhật 03:30 | `reindex_chroma` |
| Export từ điển tìm kiếm (U04) + reload dictionary Flask (Pub/Sub) | 04:00 hằng đêm | |
| Decay + cắt tỉa `search:popular:v1` (U04) | 04:10 hằng đêm | |
| Báo cáo từ khóa 0 kết quả (U06) | Thứ Hai 08:00 | xuất CSV vào `report/search-metrics/` |
- Mỗi job ghi trạng thái vào Redis `ops:job:<name>:last` (thời điểm, thời lượng, ok/fail, số lượng) — TTL 30 ngày.
- Múi giờ: Asia/Ho_Chi_Minh.

## Phần D — Giám sát
- Endpoint `GET /api/ops/health` (auth nội bộ) tổng hợp: Neo4j (ok, RAM container nếu đọc được qua cgroup/`docker stats` không khả dụng trong container → dùng JVM metrics `CALL dbms.queryJmx` heap used), Chroma (số doc = số SP active?), Redis (memory used, độ dài stream, `XPENDING`), lệch số lượng Mongo ↔ Neo4j ↔ Chroma, trạng thái job cuối.
- Ngưỡng cảnh báo (ghi log mức WARNING + trường `alerts[]` trong health): RAM Neo4j > 90% limit, lệch số SP > 1%, `XPENDING` > 1000 hoặc message treo > 10 phút, job fail 2 lần liên tiếp, job đêm không chạy > 26 giờ.
- Trang admin nhỏ `index.php?r=admin_search_ops` (chỉ đọc) hiển thị health này (tùy chọn nếu thời gian cho phép; ưu tiên endpoint + log).
- Neo4j RAM: nếu đo thực tế liên tục > 90% → **không tự tăng**, ghi đề xuất vào báo cáo.

## Kiểm thử
- Unit: debounce gom id, mốc `--since`, lock ngăn job chồng, đánh giá ngưỡng cảnh báo.
- Chạy thật: admin test (U01) sửa giá 1 SP + ẩn 1 SP (rồi hiện lại) → trong ≤ 60s: Neo4j `active`/giá đổi, Chroma upsert, gợi ý không còn SP bị ẩn; health không có alert; chạy tay từng job một lần (`--run-now <job>`) → trạng thái ghi vào Redis. Dừng worker 2 phút rồi bật lại → sự kiện tồn đọng được xử lý hết.
- pytest toàn bộ.

## Đầu ra
`docs/ops/search-ops.md` (sơ đồ luồng sự kiện, bảng job, cách chạy tay, cách đọc health, xử lý sự cố), code, compose, mục "U11" trong handoff. Không commit.

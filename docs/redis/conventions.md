# Quy ước Redis (U03)

Một instance `skinsyntax-redis` (redis:7-alpine, cổng host 6380). Python dùng `ai-service-flask/shared/redis_kit/`, PHP dùng `backend/app/services/RedisLite.php` + `RateLimiter.php`. Tên key chỉ được sinh từ `redis_kit/keys.py` (Python) hoặc hằng số trong lớp PHP tương ứng; file này là hợp đồng giữa hai phía.

## 1. Quy tắc đặt tên

- Key: `<domain>:<purpose>:v<n>[:<id>]`, chữ thường, phân cách bằng `:`. Đổi định dạng giá trị => tăng `v<n>`, không sửa tại chỗ.
- Pub/Sub channel: `<domain>:<purpose>` (không phiên bản vì là kênh, không lưu dữ liệu): `cache:invalidate`.
- Mọi key có TTL, trừ cấu trúc dài hạn ghi rõ ở bảng dưới.
- Streams: luôn `XADD ... MAXLEN ~ n`. Sorted Set dài hạn phải có job cắt tỉa (`ZREMRANGEBYRANK`/`ZREMRANGEBYSCORE`).
- Redis chết => tính năng xuống cấp mềm (không cache / limiter cho qua / ghi thẳng Atlas), không bao giờ lỗi trang.

## 2. DB index

| DB | Dùng bởi | Ghi chú |
|---|---|---|
| 0 | Search/Flask (`REDIS_URL`), PHP (`SEARCH_BREAKER_REDIS_URL`, `REDIS_URL`), graph_sync lock, U03 trở đi | mọi key `search:*`, `rl:*`, `lock:*`, `catalog:*` |
| 2 | AI Idol (`AI_IDOL_REDIS_URL`: Celery broker/result `celery-task-meta-*`, `unacked_index`...) | ~20.000 key; KHÔNG đụng từ code search |

Test tích hợp dùng db 15 (`REDIS_TEST_URL`, mặc định `redis://127.0.0.1:6380/15`), chỉ xóa key có tiền tố `u03test:`.

## 3. Danh sách key / channel

| Key | Kiểu | TTL / giới hạn | Ghi | Đọc |
|---|---|---|---|---|
| `search:rec:v3:g<gen>:<sha1>` | STRING (JSON) | `SEARCH_CACHE_TTL` (300s) | Flask `RedisJsonCache.set` | Flask `RedisJsonCache.get` |
| `search:cachegen:v1` | STRING (int) | dài hạn (một số nguyên, vài byte) | `pubsub.publish_invalidate` (INCR) | Flask (mỗi lần get/set cache) |
| `search:trending:v1` | STRING (JSON) | `SEARCH_TRENDING_CACHE_TTL` (600s) | Flask `TrendingSource` | Flask |
| `search:recommend:breaker:v1` | STRING (JSON) | 300s | PHP `SearchRecommendClient` | PHP |
| `search:events:v1` | STREAM | `MAXLEN ~ 100000`; XDEL sau khi ack | PHP `SearchEventTracker` (XADD) | worker `search-worker`, group `flusher` |
| `search:events:v1:dead` | STREAM | `MAXLEN ~ 50000` | `streams` (sau 5 lần giao thất bại) | người vận hành (`--replay-dead`) |
| `search:popular:v1` | ZSET | dài hạn; ghi cắt về 5000 khi > 6000, job `popular.maintain` decay x0,9 + giữ top 5000 (U11 lên lịch) | PHP `SearchPopularity::record` (ZINCRBY, chỉ tìm kiếm commit, trang 1, có kết quả, đã lọc PII), `popular.seed` | PHP `SearchPopularity::lookup` (ZSCORE) |
| `search:prefix:v1` | ZSET (điểm 0, lex) | cùng vòng đời với popular; `maintain` dọn member mồ côi | PHP `record` (member `fold(q)q_norm`) | PHP `lookup` (ZRANGEBYLEX tiền tố không dấu, tối đa 100) |
| `search:suggest:v1:g<gen>:<sha1>` | STRING (JSON) | 60s | PHP `SearchSuggestService` | PHP (cache autocomplete theo tiền tố đã fold; gen = `search:cachegen:v1`) |
| `search:intent:v1:g<gen>:<sha1>` | STRING (JSON) | 600s | Flask `GET /api/search/intent` | Flask (U05: intent + corrected_q + corrections; gen = `search:cachegen:v1`) |
| `search:intent:breaker:v1` | STRING (JSON) | 300s | PHP `SearchRecommendClient::intent` | PHP (breaker riêng cho intent, 3 lỗi -> bỏ qua 60s) |
| `product:similar:v1:g<gen>:<id>:<skin\|any>` | STRING (JSON) | `SEARCH_SIMILAR_CACHE_TTL` (3600s; 120s khi là fallback semantic) | Flask `RedisSimilarCache` (U08: ứng viên đã xếp hạng, CHƯA áp exclude/limit) | Flask (`GET /api/products/<id>/similar`; gen = `search:cachegen:v1`) |
| `search:similar:breaker:v1` | STRING (JSON) | 300s | PHP `SearchRecommendClient::similar` | PHP (U09: breaker RIÊNG; trước đó dùng chung với recommend nên similar chậm làm ẩn cả gợi ý tìm kiếm) |
| `search:cart:breaker:v1` | STRING (JSON) | 300s | PHP `SearchRecommendClient::cartAnalyze` | PHP (U09: breaker riêng cho giỏ hàng, 3 lỗi -> bỏ qua 60s) |
| `search:ctr:v1:<section>` / `search:ctr:v1:_meta` | HASH | 2 ngày (job `ctr_boost` ghi lại mỗi 05:00) | Flask `search_service/ctr.py` (U12: field = product_id, value `ctr\|ctr_norm\|impressions`; `_meta` = tham số prior Beta mỗi section) | Flask ranker (`HMGET`, chỉ khi `SEARCH_CTR_BOOST_ENABLED`) |
| `cart:analyze:v1:g<gen>:<sha1>` | STRING (JSON) | `SEARCH_CART_CACHE_TTL` (600s) | Flask `RedisCartCache` (U09: sha1 của id đã sắp xếp + loại da; chỉ lưu kết quả không degraded) | Flask (`POST /api/cart/analyze`; gen = `search:cachegen:v1`) |
| `product:views:v1` | HASH (field = `ma_san_pham`, value = lượt xem chưa ghi vào Mongo) | không TTL (sống lâu nhưng được rút cạn mỗi 5 phút) | PHP `ProductViewCounter::record` (HINCRBY khi mở trang chi tiết; tắt bằng `PRODUCT_VIEWS_REDIS_ENABLED=0`) | job `product_views_flush` (Python catalog-worker, `$inc luot_xem` vào Mongo) |
| `product:views:v1:flushing` | HASH (tạm) | không TTL; chỉ tồn tại trong lúc flush | job `product_views_flush` (RENAME từ `product:views:v1`) | cùng job; xóa sau khi `bulk_write` Mongo xong, còn sót thì lần sau xử lý trước |
| `search:suggestidx:v1` | STRING (JSON ~2MB) | 3600s hard, soft 300s (stale-while-revalidate) | PHP `SearchSuggestIndex::rebuild` (sau response, khóa `lock:search_suggest_index:v1` 60s) | PHP (đường miss không chờ Atlas) |
| `catalog:changes:v1` (+`:dead`) | STREAM | `MAXLEN ~ 50000` (U07 phát với 10000, U11 `CatalogEvents` 50000) | U07 (admin lưu/duyệt nhãn: `{type:product_updated, ma_san_pham, reason:tags, source, ts}`), U11 `CatalogEvents::publish` (admin thêm/sửa/ẩn/hiện/xóa/đổi giá-kho, đơn làm hết/còn hàng: `{type, ma_san_pham, reason, fields?, source, ts}`) | `workers/catalog_sync.py` (group `catalog_sync`, debounce 30s; lỗi -> XAUTOCLAIM 60s, quá 6 lần -> `:dead`) |
| `ops:job:<name>:last` / `ops:job:<name>:fails` | STRING (JSON) / STRING (int) | 30 ngày | `shared/redis_kit/ops.py` (mọi job của catalog-worker) | `GET /api/ops/health`, trang admin `admin_search_ops` |
| `ops:graph_sync:last_since` | STRING (ISO8601 UTC) | không TTL (mốc dài hạn của job đồng bộ tăng dần) | job `graph_sync_incremental` | chính job đó |
| `ops:worker:started_at` | STRING (epoch s) | 30 ngày, SET NX | catalog-worker | health (cảnh báo job chưa từng chạy) |
| `ops:filehash:<name>` | STRING (sha256) | 30 ngày | job `config_watch` (`conflict_rules`, `dictionary`) | chính job đó |
| `lock:ops_job_<name>:v1` | STRING (token) | = `lock_ttl_s` của job | `Scheduler.run_job` (`RedisLock`) | chỉ một instance chạy mỗi job |
| `rl:<scope>:v1:<ident>` | Python: ZSET (cửa sổ trượt); PHP: STRING đếm (cửa sổ cố định) | = độ dài cửa sổ (+1s) | `ratelimit.allow` / `RateLimiter::hit` | cùng code |
| `lock:<name>:v1` | STRING (token) | TTL do caller đặt (PX) | `RedisLock` | `RedisLock` |
| `graph_sync:lock` | STRING (token) | 1800s | `graph_sync` qua `RedisLock` | cùng code |
| `cache:invalidate` | Pub/Sub channel | không lưu | `publish_invalidate` | listener nền trong Flask |

Scope `rl:` đang dùng: `search_recommend` (Flask, theo IP gọi, 50 req/s), `search_event` (PHP, theo phiên, 60 sự kiện/60s), `search_event_ip` (PHP, IP công khai, 300/60s), `search_fallback` (PHP, theo phiên, 120/60s), `search_fallback_ip` (PHP, IP công khai, 120/60s), `product_similar` (Flask, IP gọi, 50 req/s), `similar_products` (PHP, theo phiên, 60/60s), `similar_products_ip` (PHP, IP công khai, 120/60s), `auth_login_email` (PHP, 10/900s), `auth_login_ip` (30/900s), `auth_login_session` (20/900s), `auth_reset_email` / `auth_reset_ip` / `auth_reset_session` (3 / 10 / 5 mỗi 900s), `auth_signup_otp_email` / `auth_signup_otp_ip` / `auth_signup_otp_session` (3 / 10 / 5 mỗi 600s; ident email = hash cắt ngắn, scope `_ip` chỉ dùng khi có IP công khai, không thì `_session`). Ident phiên/IP/email luôn là hash cắt ngắn, không lưu IP hay email thô.

### Entry của `search:events:v1`

Tất cả field là chuỗi: `ts_ms` (epoch ms), `q_norm`, `section`, `product_id` (rỗng nếu impression/chip), `action`, `user_hash` (rỗng = khách), `session_hash`, `chip` (chỉ chip_click). Worker dựng document `search_events` giống hệt bản PHP insert trực tiếp, thêm `_id = "sev1-<stream id>"` để retry không nhân đôi.

## 4. Cache generation thay cho SCAN

Khóa cache gợi ý chứa thế hệ `g<gen>`. `publish_invalidate()` chỉ `INCR search:cachegen:v1` rồi `PUBLISH cache:invalidate`. Mọi entry cũ bị bỏ rơi ngay (O(1)) và tự hết hạn theo TTL <= 300s. Lý do không dùng `SCAN`+`DEL`: tránh quét keyspace chung với AI Idol, tránh race (request ghi lại entry cũ giữa lúc quét và xóa), và vẫn đúng khi listener không chạy. Pub/Sub chỉ bổ sung: báo cho từng worker gunicorn xả cache trong tiến trình (catalog snapshot, entity dictionary). Chi phí: thêm 1 lệnh `GET` (~0,2 ms) mỗi lần đọc/ghi cache.

## 5. Persistence

- `docker-compose.yml`: `redis-server --appendonly yes --appendfsync everysec` (trước U03 chỉ có RDB snapshot mặc định `save 3600 1 300 100 60 10000`, `appendonly no`). RDB vẫn bật.
- Bật trên instance đang chạy bằng `CONFIG SET appendonly yes` trước (Redis tự rewrite AOF từ dữ liệu hiện có, không mất dữ liệu), rồi mới recreate container với cấu hình mới. Đã kiểm: số key db 2 trước/sau recreate không giảm.
- Rủi ro: AOF tăng dung lượng volume `skinsyntax-redis-data` (hiện ~5MB base); Redis tự rewrite khi AOF gấp đôi (`auto-aof-rewrite-percentage 100`). Mất tối đa ~1 giây ghi khi sập đột ngột (`everysec`). Stream `search:events:v1` tối đa ~100k entry (~vài chục MB); nếu worker chết lâu hơn mức đó thì entry cũ nhất bị cắt (mất sự kiện phân tích, không ảnh hưởng nghiệp vụ).
- Đầu ra khẩn cấp: `SEARCH_EVENTS_STREAM_ENABLED=0` (PHP) => ghi thẳng Atlas như trước U03.

## 6. Định danh IP cho rate limit

Sau nginx/Docker, `REMOTE_ADDR` là IP proxy dùng chung nên KHÔNG dùng làm khóa (sẽ gộp mọi khách vào một bộ đếm); `X-Forwarded-For` bị bỏ qua vì nginx hiện không ghi đè nó. `RateLimiter::clientIdentity()` chỉ trả định danh khi `REMOTE_ADDR` là IP công khai; còn lại giới hạn theo phiên. Muốn limit theo IP thật phía sau nginx: cấu hình nginx `real_ip` + tin cậy proxy, rồi sửa `clientIdentity`.

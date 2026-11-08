# U03 — LỚP REDIS DÙNG CHUNG (công nghệ mới: Streams, Pub/Sub, Sorted Set, Rate limit)

## Vai trò
Kỹ sư nền tảng. Xây lớp Redis dùng chung cho Python và PHP, có quy ước rõ ràng, để các bước U04, U06, U08, U11 dùng lại. **Mỗi tính năng Redis phải gắn với một vấn đề thật** dưới đây — không thêm cho có.

## Đọc trước
`u00-common-rules.md` → `docs/plans/upgrade-handoff.md` → `ai-service-flask/search_service/{service,config}.py` (cách đang dùng Redis), `backend/app/services/SearchRecommendClient.php` (client `SearchBreakerRedis` tối giản), `docker-compose.yml` (service redis, có AOF/persistence không?).

## Bài toán → tính năng Redis
| Bài toán | Tính năng | Dùng ở |
|---|---|---|
| Admin sửa sản phẩm nhưng Neo4j/Chroma/cache chỉ cập nhật khi chạy tay | **Streams** `catalog:changes:v1` (sự kiện thay đổi) + consumer group | U11 |
| `search_events` ghi thẳng Atlas mỗi click → chậm, phụ thuộc mạng | **Streams** `search:events:v1` làm buffer, worker gom lô ghi Atlas | U03 (làm luôn) |
| Cache gợi ý cũ sau khi sửa giá/ẩn sản phẩm | **Pub/Sub** `cache:invalidate` → xóa key liên quan | U03 (cơ chế), U11 (nối nguồn) |
| Gợi ý khi gõ phải nhanh <30ms, ưu tiên từ khóa hot | **Sorted Set** `search:popular:v1` + prefix index | U04 |
| Spam API tìm kiếm / beacon | **Rate limit** sliding window (Sorted Set) hoặc fixed window `INCR+EXPIRE` | U03 |
| Nhiều worker cùng chạy job | **Lock** `SET NX PX` + token (đã có `graph_sync:lock`, chuẩn hóa) | U03 |

## Yêu cầu

### 1. Quy ước (viết `docs/redis/conventions.md`)
- Key: `<domain>:<purpose>:v<n>[:<id>]`, chữ thường, `:` phân cách. Liệt kê toàn bộ key đang có + key mới, kiểu dữ liệu, TTL, ai ghi/ai đọc.
- TTL bắt buộc cho cache; Streams có `MAXLEN ~` giới hạn; Sorted Set dài hạn có job cắt tỉa.
- DB index: giữ hiện trạng (search dùng db 0, ai-idol dùng db 2 — xác nhận lại), ghi rõ.
- Persistence: kiểm compose redis có AOF chưa. Streams buffer sự kiện cần AOF `appendfsync everysec` — nếu chưa có thì thêm cấu hình (ghi rủi ro dung lượng), **không** xóa dữ liệu Redis hiện có.

### 2. Python `ai-service-flask/shared/redis_kit/` (hoặc vị trí phù hợp với `shared/` có sẵn)
- `client.py`: `get_redis()` singleton theo `REDIS_URL`, `socket_timeout=0.2`, `health_check_interval`; trả `None` khi lỗi (gọi bên ngoài phải xuống cấp mềm).
- `keys.py`: hàm sinh key theo quy ước (không rải chuỗi key khắp code).
- `ratelimit.py`: `allow(key, limit, window_s) -> (bool, retry_after)` — sliding window dùng `ZADD/ZREMRANGEBYSCORE/ZCARD` trong `MULTI` (hoặc Lua script), Redis lỗi → cho qua (fail-open) + log.
- `lock.py`: `RedisLock(name, ttl_ms)` context manager, token ngẫu nhiên, giải phóng bằng Lua compare-and-delete.
- `streams.py`: `publish(stream, fields, maxlen=10000)`; `consume(stream, group, consumer, handler, batch=100, block_ms=2000)` với `XGROUP CREATE MKSTREAM` idempotent, `XREADGROUP`, `XACK` sau khi handler thành công, `XAUTOCLAIM` message treo > 60s, chuyển sang `<stream>:dead` sau 5 lần lỗi.
- `pubsub.py`: `publish_invalidate(scope, ids)`; listener luồng nền trong Flask xóa key cache tương ứng (`search:rec:*` dùng `SCAN` có giới hạn, hoặc tốt hơn: thêm **phiên bản cache** `search:rec:gen` — tăng số thế hệ khi invalidate, cache key chứa gen → không cần SCAN). Chọn cách generation và giải thích.
- Chuẩn hóa `graph_sync` lock sang `RedisLock` (giữ tên key cũ để tương thích).
- Áp rate limit cho `POST /api/search/recommend`: theo `X-Request-Source`/IP nội bộ là PHP nên limit cao (vd 50 rps), chủ yếu chặn vòng lặp lỗi; trả 429 có `Retry-After`. PHP client coi 429 như lỗi (breaker đếm).

### 3. PHP `backend/app/services/RedisLite.php`
- Tách client tối giản đang nằm trong `SearchRecommendClient.php` thành lớp dùng chung (RESP qua socket, timeout ngắn), hỗ trợ: GET/SET/SETEX/DEL/INCR/EXPIRE/ZADD/ZREMRANGEBYSCORE/ZCARD/ZREVRANGE/XADD/PUBLISH/EVAL. Giữ `SearchBreakerRedis` hoạt động (chuyển sang dùng RedisLite, GitNexus impact trước).
- `RateLimiter.php`: dùng cho endpoint beacon `search_event` (vd 60 sự kiện/phút/session) và (tùy chọn) trang `tatca` theo IP (vd 120 req/phút) — vượt → 429 cho beacon, còn trang `tatca` chỉ bỏ qua khối gợi ý (không chặn trang).
- `SearchEventTracker`: đổi sang **XADD** `search:events:v1` (MAXLEN ~ 100000) thay vì insert Atlas trực tiếp; Redis lỗi → fallback insert Atlas trực tiếp như cũ.

### 4. Worker gom sự kiện
- `ai-service-flask/workers/search_events_flusher.py`: consumer group `flusher`, gom tối đa 500 sự kiện hoặc 5s → `insert_many(ordered=False)` vào `search_events` (đây là ghi Atlas được phép, đúng collection đã có), XACK sau khi ghi thành công.
- Chạy như service compose mới `search-worker` (dùng chung image ai-service, `command: python -m workers.search_events_flusher`, restart unless-stopped, healthcheck đơn giản) — hoặc luồng nền trong gunicorn nếu bạn chứng minh an toàn hơn; ghi lý do lựa chọn.

## Kiểm thử
- Python unit (dùng `fakeredis` nếu thêm được vào requirements dev; nếu không, mock): ratelimit biên cửa sổ, lock không giải phóng nhầm token khác, streams ack/retry/dead-letter, invalidate tăng generation.
- Integration `@pytest.mark.integration` với Redis local thật.
- PHP unit cho RedisLite (parse RESP), RateLimiter (mock client).

## Kiểm chứng (chạy thật)
- Click trên trang fallback → sự kiện vào stream (`XLEN`) → worker ghi Atlas trong ≤ 10s (đếm `search_events` trước/sau) → stream được ack (`XPENDING` = 0).
- Tắt Redis 30s (`docker stop skinsyntax-redis` — được phép cho bước này, bật lại ngay): trang `tatca` vẫn chạy, beacon fallback ghi Atlas trực tiếp, Flask vẫn trả gợi ý (không cache).
- Bắn 200 request nhanh vào beacon từ 1 session → bị 429 sau ngưỡng.
- Gọi `publish_invalidate` → request sau không trúng cache cũ.
- pytest toàn bộ suite + bench ngắn không tệ hơn.

## Đầu ra
`docs/redis/conventions.md`, code, service compose mới (nếu có), mục "U03" trong handoff (API của redis_kit/RedisLite cho bước sau). Không commit.

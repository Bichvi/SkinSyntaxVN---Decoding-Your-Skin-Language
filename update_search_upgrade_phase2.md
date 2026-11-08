# Nâng cấp tìm kiếm & gợi ý — Giai đoạn 2 (U01–U13)

Tài liệu tổng hợp để bàn giao. Chi tiết từng bước nằm ở `docs/plans/upgrade-handoff.md` và các tài liệu riêng `update_*.md` (liệt kê ở mục 9). Vận hành hằng ngày: `docs/ops/search-ops.md`. Quy ước Redis: `docs/redis/conventions.md`.

## 1. Những gì đã làm, theo từng bước

| Bước | Nội dung | Kết quả chính |
|---|---|---|
| U01 | `SEARCH_INTERNAL_TOKEN`, `SEARCH_USER_HASH_SALT` trong `.env` (git ignore); 2 tài khoản test E2E (`is_test_account:true`, email `@skinsyntax.test`) với script seed/remove | Flask từ chối request thiếu/sai token; `user_hash` khác null cho khách đăng nhập |
| U02 | `livekit-voice-agent` hết restart-loop (profile `voice`, chờ phụ thuộc có backoff); khóa double-checked cho `get_llms/get_vectorstore/get_hybrid_pipeline`; logger `search_service` ra stdout | 15/16 luồng không còn nhận pool rỗng; log tìm kiếm hiện lại |
| U03 | Lớp Redis dùng chung (`shared/redis_kit`, PHP `RedisLite`, `RateLimiter`): Streams, Pub/Sub, rate limit, lock; Redis bật AOF; service `search-worker` | Beacon đi qua stream, flush vào Atlas theo lô; Redis chết thì ghi thẳng Atlas |
| U04 | Gợi ý khi gõ theo từng từ (nhóm Sản phẩm/Thương hiệu/Danh mục/Phổ biến), autocomplete Redis, chỉ mục gợi ý 2,1MB | p95 server ~26-30ms (miss), ~1ms (hit) |
| U05 | Xếp hạng kết quả (`SanPham::rankingScoreExpression`, công tắc `SEARCH_RANKING_ENABLED`); "Có phải bạn muốn tìm…" (Flask `/api/search/intent`, `corrections.py`) | "toriden srm" tự sửa + banner + link `exact=1` |
| U06 | Alias INCI 194 -> 970 mục; parser thành phần; báo cáo từ khóa 0 kết quả + công cụ áp synonym có duyệt | Map INCI 54,15% -> 88,53%; SP có >=1 hoạt chất 77,4% -> 90,2% |
| U07 | Nhãn công dụng: admin nhập tay (checkbox ở `suaSP.php`) + LLM gợi ý có duyệt (`tag_suggestions`, trang `admin_tag_review`); ETL/ranker ưu tiên nhãn người duyệt | Pilot 30 SP; chưa chạy toàn catalog (chờ người dùng) |
| U08 | Khối "Sản phẩm tương tự" ở trang chi tiết (`GET /api/products/<id>/similar`, Neo4j, dự phòng Chroma) | p95 15ms; không làm chậm trang (tải lười) |
| U09 | Giỏ hàng: cảnh báo xung đột hoạt chất (12 -> 55 cặp INCI, `conflict_rules.yaml`) + gợi ý hoàn thiện routine (`POST /api/cart/analyze`); breaker riêng mỗi endpoint; Neo4j fail-fast 0,6s | Neo4j chết vẫn cảnh báo (luật Python) |
| U10 | Syna (chatbot) truy vấn Neo4j: `find_products/check_conflicts/explain_product/similar_products`, router tất định, fallback RAG | Eval 444/444 ràng buộc, 32/32 câu |
| U11 | Đồng bộ tự động: `CatalogEvents` -> `catalog:changes:v1` -> `catalog-worker`; bộ lập lịch 10 job; `/api/ops/health` + trang `admin_search_ops`; chatbot kiểm LLM nền | Sửa/ẩn SP phản ánh ở Neo4j/Chroma/cache trong ~30-40s |
| U12 | Co-purchase, CTR boost, A/B, cross-encoder: hạ tầng + test xong, **cả 4 cổng chưa đạt nên mọi cờ hành vi TẮT** | Xem mục 5 |
| U13 | Kiểm thử tổng, E2E 16 kịch bản, bench, quét bí mật, tài liệu; sửa nhỏ (mục 8) | Xem báo cáo cuối phiên |

## 2. Kiến trúc Redis (bảng key rút gọn)

DB0 dùng cho search/ops, DB2 của AI Idol (không đụng). Đầy đủ: `docs/redis/conventions.md`.

| Key | Kiểu / TTL | Vai trò |
|---|---|---|
| `search:rec:v3:g<gen>:<sha1>` | STRING 300s | cache gợi ý tìm kiếm |
| `search:cachegen:v1` | STRING (số) | "thế hệ" cache; `publish_invalidate` chỉ INCR + PUBLISH `cache:invalidate` |
| `search:events:v1` (+`:dead`) | STREAM | beacon hành vi -> `search-worker` ghi Atlas `search_events` |
| `catalog:changes:v1` (+`:dead`) | STREAM MAXLEN ~50000 | sự kiện đổi catalog -> `catalog-worker` |
| `search:popular:v1`, `search:prefix:v1`, `search:suggest:v1:g<gen>:*`, `search:suggestidx:v1` | ZSET / STRING | autocomplete |
| `search:intent:v1:g<gen>:*` | STRING 600s | intent + câu sửa |
| `product:similar:v1:g<gen>:<id>:<skin>` | STRING 3600s | sản phẩm tương tự |
| `cart:analyze:v1:g<gen>:*` | STRING 600s | phân tích giỏ |
| `search:{recommend,similar,intent,cart}:breaker:v1` | STRING 300s | breaker riêng mỗi endpoint (PHP) |
| `search:ctr:v1:<section>` | HASH 2 ngày | CTR theo sản phẩm (U12) |
| `ops:job:<tên>:last/fails`, `ops:graph_sync:last_since`, `ops:worker:started_at`, `ops:filehash:*` | STRING | trạng thái job |
| `rl:<scope>:v1:<ident>`, `lock:<tên>:v1`, `graph_sync:lock` | giới hạn tốc độ / khóa | |

Quy tắc: `<domain>:<purpose>:v<n>:…`, mọi key có TTL (trừ cấu trúc dài hạn đã ghi rõ), Redis chết => xuống cấp mềm (không cache, limiter cho qua, ghi thẳng Atlas).

## 3. Luồng sự kiện catalog

```
Admin sửa/thêm/ẩn/hiện SP, đổi giá/kho, duyệt nhãn, đơn hết/còn hàng
  -> PHP CatalogEvents::publish (XADD catalog:changes:v1; Redis lỗi chỉ log, không chặn)
  -> catalog-worker: gom 30s -> khóa graph_sync:lock -> Neo4j upsert/deactivate (+SIMILAR_TO cục bộ)
     -> Chroma upsert/xóa -> publish_invalidate("catalog") -> dựng lại chỉ mục autocomplete -> XACK
  lỗi: giữ pending, thử lại sau 60s, quá 6 lần -> :dead
```

Lưới an toàn: job `graph_sync_incremental` mỗi giờ. `HoaDon` chỉ phát sự kiện khi SP chuyển còn hàng <-> hết hàng (không phát mỗi đơn); đường này đã được kiểm bằng `HoaDonStockEventTest` (mục 7).

## 4. Lịch job (catalog-worker, giờ Asia/Ho_Chi_Minh)

`graph_sync_incremental` hằng giờ :10 · `graph_sync_full` 02:30 (+`graph_eval`) · `co_purchase` 03:00 · `chroma_reindex` CN 03:30 · `dictionary_export` 04:00 · `popular_maintain` 04:10 · `ctr_boost` 05:00 · `zero_result_report` Thứ Hai 08:00 · `config_watch` và `health_check` mỗi 5 phút. Chạy tay: `docker exec -w /app skinsyntax-catalog-worker python -m workers.catalog_worker --list | --run-now <job> | --replay-dead`.

## 5. Cờ / biến môi trường mới

| Biến | Mặc định | Ghi chú |
|---|---|---|
| `SEARCH_INTERNAL_TOKEN`, `SEARCH_USER_HASH_SALT` | rỗng trong `.env.example` | chỉ giá trị trong `.env` (git ignore); đổi salt làm mất liên kết `user_hash` lịch sử |
| `E2E_CUSTOMER_*`, `E2E_ADMIN_*` | rỗng | tài khoản test |
| `SEARCH_RANKING_ENABLED`, `SEARCH_CORRECTION_ENABLED`, `SEARCH_INTENT_TIMEOUT_MS` | bật / bật / 300 | U05 |
| `SEARCH_SIMILAR_ENABLED` / `SIMILAR_PRODUCTS_ENABLED` (PHP) | bật | U08 |
| `SEARCH_CART_ENABLED` / `CART_INSIGHTS_ENABLED` (PHP) | bật | U09 |
| `SYNA_GRAPH_TOOLS_ENABLED` | code false, compose true | U10 |
| `NEO4J_CALL_DEADLINE_S` | 0,6 | U09, fail-fast |
| `SEARCH_EVENTS_STREAM_ENABLED` | 1 | `0` = ghi thẳng Atlas (khẩn cấp) |
| `CATALOG_SYNC_DEBOUNCE_S` | 30 | U11 |
| `CHATBOT_LLM_BG_VALIDATION` | 1 | `0` = kiểm LLM đồng bộ như cũ |
| `SEARCH_CO_PURCHASE_ENABLED`, `SEARCH_CTR_BOOST_ENABLED`, `SEARCH_RERANK_ENABLED` | **false** | cổng U12 chưa đạt |

## 6. Cổng điều kiện U12 và số liệu (đo 2026-10-23, đã loại dữ liệu test)

| Tính năng | Điều kiện | Số đo | Kết quả |
|---|---|---|---|
| Co-purchase | >= 200 cặp `CO_PURCHASED` (count >= 2) | 2 cặp / 2.109 đơn hoàn thành có >= 2 SP | chưa đạt -> tắt |
| CTR boost | >= 5.000 impression & >= 200 click / 30 ngày | 8 impression, 1 click | chưa đạt -> tắt |
| A/B | >= 1.000 lượt tìm / tuần | 269 (phần lớn do kiểm thử) | chưa đạt -> thí nghiệm mẫu `draft` |
| Cross-encoder | p95 rerank 40 cặp <= 120ms | tốt nhất 305ms (int8 torch, 4 luồng) | chưa đạt -> tắt |

Hạ tầng đã sẵn sàng: beacon có `product_ids/position/experiment/variant`, job `ctr_boost`, khung A/B (`data/experiments.yaml`, `scripts/search_metrics.py --experiment`). Bật tính năng khi số liệu đạt (xem `docs/ops/search-ops.md` mục 9). Số CTR theo sản phẩm chỉ tích lũy từ lúc beacon mới được triển khai.

## 7. Kiểm thử (U13)

- Python: 806 passed, 18 skipped (tất cả suite `ai-service-flask/tests`, `recommendation_service/tests`, `livekit-voice-agent/tests`); `-m integration` với Neo4j/Redis thật: 20 passed.
- PHP (mock, không chạm DB thật): `HoaDonStockEventTest` 26 (mới), `AdminStatsTestAccountsTest` 4 (mới), và các test Search*/Cart*/Similar*/Catalog*/Tag*/Redis*/RateLimiter đều 0 FAIL.
- `HoaDonStockEventTest` chứng minh: việc phát sự kiện trong `HoaDon::restoreStockForItems`/`reserveStockForItems` không đổi lệnh ghi kho, thông báo, giá trị trả về hay ngoại lệ trong 6 tình huống (Redis nhận / từ chối / ném lỗi / không có Redis, `publish` ném lỗi ra ngoài, file `CatalogEvents.php` hỏng cú pháp). Kết quả cũng trùng khớp bản `HEAD` của `HoaDon.php`. Đối chứng âm (gỡ try/catch) làm test FAIL như mong đợi.
- E2E 16 kịch bản, bench, ops: xem báo cáo U13 trong `docs/plans/upgrade-handoff.md`; ảnh ở `report/upgrade-final-e2e/`.

## 8. Sửa nhỏ trong U13

- Thống kê admin (`QuanTri::getDashboardSummary`, `ThongKe::getTongNguoiDung/getNguoiDungMoi`) loại tài khoản `is_test_account:true` (dashboard hiện 182 khách thay vì 183).
- Route `admin_sp_create` (nút "Thêm sản phẩm mới", action của `themSP.php`) trước đây 404: thêm alias vào `backend/public/index.php` và `frontend/public/index.php`.
- `catalog._HIDDEN` (Chroma) đồng bộ với `graph_sync.transform.HIDDEN_STATUSES` (Neo4j) và PHP (`taman`, `off` được thêm); có test khóa `test_hidden_statuses_sync.py`. Hiện không có SP nào ở các trạng thái ẩn nên không đổi dữ liệu.
- `docs/ops/search-ops.md` mục 6 cập nhật theo giới hạn Neo4j 1536m.

## 9. Vận hành & sự cố thường gặp

| Triệu chứng | Cách xử lý |
|---|---|
| Gợi ý/giá cũ sau khi sửa SP | kiểm `catalog_sync` ở trang `admin_search_ops`; khẩn: `redis-cli INCR search:cachegen:v1` |
| `stream_backlog`/`stream_dead_letters` | `docker logs skinsyntax-catalog-worker`; sửa nguyên nhân rồi `--replay-dead` |
| Lệch số SP Neo4j/Chroma | `--run-now graph_sync_full` / `--run-now chroma_reindex` |
| Syna không dùng được đồ thị ngay sau khi restart Neo4j | các truy vấn đầu vượt hạn 0,6s nên rơi về RAG; chạy lại vài lượt `scripts/eval_graph_tools.py` (hoặc restart `skinsyntax-ai`, `_warm_chat_tools` làm ấm) |
| Redis chết | trang vẫn chạy; khối similar/giỏ có thể ẩn, beacon ghi thẳng Atlas; lần đầu có thể chậm ~4-8s do DNS tên `redis` treo |
| Flask chết | khối gợi ý tìm kiếm chuyển "best sellers"; breaker mở sau 3 lỗi (60s); similar/giỏ trả 204 |
| Neo4j chết | tìm kiếm/chi tiết/giỏ/Syna vẫn chạy (xuống cấp mềm) |
| Lỗi "Loi ket noi MongoDB" thoáng qua | DNS SRV của Atlas; thử lại |
| Sau sửa PHP | `docker compose restart php-backend` (opcache); sau sửa Python `docker compose restart ai-service` |

Tài liệu liên quan: `update_search_data_quality.md` (U06), `update_tag_curation.md` (U07), `update_similar_products.md` (U08), `update_cart_conflicts.md` (U09), `update_syna_graph_tools.md` (U10), `update_ops_scheduler.md` (U11), `update_advanced_gated.md` (U12).

## 10. Rủi ro còn lại

1. Bí mật đã nằm trong lịch sử git từ trước giai đoạn 2 (không do giai đoạn này): `backend/app/config/config.php` có giá trị mặc định của `FACEBOOK_APP_SECRET`, `BANK_TRANSFER_WEBHOOK_SECRET`, `SEPAY_API_TOKEN` trùng với giá trị trong `.env`; `backend/experiments/**` có chuỗi kết nối MongoDB Atlas có mật khẩu. Cần xoay các giá trị này và đưa vào biến môi trường.
2. `HUGGINGFACE_API_KEY` từng bị in ra output công cụ ở U12: cần xoay (revoke và tạo mới).
3. RAM Neo4j đạt ~86-88% sau khi nóng (ngưỡng cảnh báo 90%): có thể thấy cảnh báo `warning` lúc 02:30.
4. Redis dừng: lần đầu chậm 4-8s (DNS), khối similar mất ~8s ở nền rồi ẩn (204).
5. Nhãn công dụng LLM (U07) chưa chạy toàn catalog; `rank_limits` và vai trò thành phần là heuristic chưa có chuyên gia duyệt.
6. Giới hạn 50 req/s/IP của Flask (429) làm bench đồng thời c=4 bị cắt; PHP coi 429 như lỗi để breaker đếm.
7. `SIMILAR_TO` cục bộ không cập nhật SP chưa từng là láng giềng cho tới job 02:30.

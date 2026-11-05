# Vận hành hệ thống tìm kiếm (U11)

Mục tiêu: không còn thao tác chạy tay. Khi catalog đổi thì Neo4j, Chroma, từ điển tìm kiếm và cache tự cập nhật; có lịch định kỳ, giám sát và cảnh báo.

## 1. Luồng sự kiện

```
Admin sửa / thêm / ẩn / hiện / xóa SP, đổi giá-kho, duyệt nhãn (U07), đơn làm hết/còn hàng
        │  PHP: CatalogEvents::publish()  (XADD catalog:changes:v1 MAXLEN ~ 50000; Redis lỗi -> log, không chặn admin)
        ▼
Redis Stream  catalog:changes:v1  ──(group catalog_sync)──►  catalog-worker (workers/catalog_sync.py)
                                                              1. gom 30s -> TẬP id (50 lần lưu = 1 lần sync)
                                                              2. RedisLock graph_sync:lock (chờ tới 300s nếu job đêm đang giữ)
                                                              3. graph_sync.incremental.sync_ids  -> Neo4j upsert / deactivate
                                                                 + SIMILAR_TO cục bộ (id + láng giềng trực tiếp)
                                                              4. reindex_chroma.reindex_ids       -> Chroma upsert / xóa
                                                              5. publish_invalidate("catalog")    -> bump search:cachegen:v1
                                                                 (search:rec, search:suggest, product:similar, cart:analyze,
                                                                  search:intent cùng dùng g<gen> nên hết hiệu lực cùng lúc)
                                                              6. GET php nginx autocomplete        -> PHP dựng lại search:suggestidx:v1
                                                              7. XACK (chỉ khi 2-4 thành công)
                                          lỗi -> giữ pending, XAUTOCLAIM sau 60s, quá 6 lần -> catalog:changes:v1:dead
```

Điểm phát sự kiện (tập trung ở model, không rải ở controller):

| Điểm ghi | Loại sự kiện |
|---|---|
| `SanPham::adminInsert` | `product_created` |
| `SanPham::adminUpdate` | `price_changed` (chỉ đổi giá), `stock_changed` (chỉ đổi kho), còn lại `product_updated` (kèm `fields=` các trường đổi) |
| `SanPham::updateProductVisibility` (ẩn/hiện và `adminDelete`, vốn là ẩn mềm) | `product_hidden` / `product_shown` |
| `SanPham::updateInventory` | `stock_changed` |
| `HoaDon` trừ/hoàn kho khi đơn làm sản phẩm chuyển hết <-> còn hàng (không phát mỗi đơn để khỏi ngập luồng) | `stock_changed` |
| `TagCuration::publishChange` (U07: lưu / duyệt nhãn) | `product_updated`, `reason=tags` |

`price_changed` / `stock_changed` không đổi điểm tương tự nên worker bỏ bước SIMILAR_TO. Quá 50 id trong một cửa sổ (nhập hàng loạt) thì cũng bỏ SIMILAR_TO cục bộ, để job đêm tính lại toàn bộ.

Giới hạn đã biết của SIMILAR_TO cục bộ: chỉ tính lại cạnh đi RA từ id đổi và láng giềng trực tiếp của nó (đọc từ Neo4j trước khi ghi). Một sản phẩm khác, chưa từng là láng giềng, mà nay mới lọt top-10 vì nhãn mới của sản phẩm vừa đổi thì phải chờ `graph_sync --full` 02:30.

## 2. Lịch định kỳ (múi giờ Asia/Ho_Chi_Minh)

Cơ chế: bộ lập lịch tự viết trong `workers/scheduler.py` (khoảng 100 dòng thư viện chuẩn). Lý do không dùng APScheduler/supercronic: image AI dùng chung (`Dockerfile.ai`, ~5 GB, kéo torch) không có sẵn, thêm thư viện nghĩa là build lại cả image. Tính chất cần thiết đều có: khóa Redis riêng mỗi job (`lock:ops_job_<tên>:v1`, chỉ một instance chạy) và chạy TUẦN TỰ (các job chung `graph_sync:lock` và Neo4j 1 GiB nên không được chồng nhau). Worker khởi động lại giữa chừng thì job đã từng chạy nhưng lỡ slot gần nhất được chạy bù một lần; job chưa từng chạy KHÔNG bị chạy bù (tránh deploy lần đầu kích hoạt mọi job nặng).

| Job (`--run-now <tên>`) | Lịch | Việc làm |
|---|---|---|
| `graph_sync_incremental` | mỗi giờ phút 10 | lưới an toàn nếu sự kiện bị lỡ: mọi SP có `updated_at`/`tag_updated_at` >= mốc `ops:graph_sync:last_since` đi qua cùng đường sự kiện (Neo4j + Chroma + cache). Mốc chỉ dịch tới khi chạy thành công |
| `graph_sync_full` | 02:30 | `python -m graph_sync --full` (kèm SIMILAR_TO, dọn Ingredient mồ côi) rồi `publish_invalidate`; ghi đỉnh RAM Neo4j |
| `graph_eval` | ngay sau `graph_sync_full` | `scripts/eval_graph_tools.py` (chỉ tầng tool, KHÔNG gọi LLM), ghi tỷ lệ đạt vào trạng thái job; dưới 95% = job `fail` |
| `co_purchase` | 03:00 | `graph_sync --derive co_purchase --days 90` |
| `chroma_reindex` | Chủ nhật 03:30 | `reindex_chroma` đầy đủ (kiểm lệch) |
| `dictionary_export` | 04:00 | ghi `backend/app/data/search_dictionary.json` (mount `/export`) + `publish_invalidate("dictionary")` để Flask nạp lại |
| `popular_maintain` | 04:10 | `popular.maintain()` (decay 0.9, cắt top 5000) |
| `ctr_boost` | 05:00 | (U12) CTR 30 ngày theo (section, product) -> Redis `search:ctr:v1:<section>`; ghi số liệu cổng dữ liệu (impression/click) vào trạng thái job. Ranker chỉ dùng khi `SEARCH_CTR_BOOST_ENABLED=true` (hiện tắt) |
| `zero_result_report` | Thứ Hai 08:00 | `search_metrics.py --zero-result-report` -> `report/search-metrics/zero_results_<ngày>.csv` (người duyệt điền `approve=y`) |
| `config_watch` | mỗi 5 phút | `conflict_rules.yaml` đổi -> `graph_sync --conflicts-only` + invalidate; synonyms/aliases/groups/rules YAML đổi -> `publish_invalidate("dictionary")` |
| `product_views_flush` | mỗi 5 phút | ghi lượt xem đệm trong Redis (`product:views:v1`, PHP `ProductViewCounter`) sang Mongo `san_pham.luot_xem` bằng `$inc` (RENAME nguyên tử sang `product:views:v1:flushing` rồi `bulk_write`; Mongo lỗi thì giữ key flushing cho lần sau). Chi tiết: `update_luot_xem.md` |
| `health_check` | mỗi 5 phút | tính health, ghi log WARNING cho từng cảnh báo |

Mỗi job ghi `ops:job:<tên>:last` (JSON: `started_at`, `finished_at`, `duration_s`, `status` ok/fail/skipped, `detail`, `error`; TTL 30 ngày) và `ops:job:<tên>:fails` (số lần lỗi liên tiếp).

## 3. Chạy tay

```bash
# danh sách job + lần chạy tới
MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-catalog-worker python -m workers.catalog_worker --list
# chạy MỘT job một lần (trạng thái vẫn ghi vào Redis; bị chặn nếu instance khác đang chạy job đó)
MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-catalog-worker python -m workers.catalog_worker --run-now graph_sync_full
# đồng bộ vài sản phẩm ngay (không cần sự kiện)
MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-catalog-worker python -m graph_sync --ids 1496,462 [--no-similarity] [--dry-run]
MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-catalog-worker python -m search_service.scripts.reindex_chroma --ids 1496,462
# phát lại sự kiện nằm trong dead-letter sau khi đã sửa nguyên nhân
MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-catalog-worker python -m workers.catalog_worker --replay-dead
```

Compose: dịch vụ `catalog-worker` (container `skinsyntax-catalog-worker`, cùng image `skinsyntax-ai-service`, `mem_limit 1536m`; KHÔNG `compose build` riêng). Sửa code Python chỉ cần `docker compose restart catalog-worker` (mount `/app`). Biến: `CATALOG_SYNC_DEBOUNCE_S` (30), `CATALOG_SYNC_LOCK_WAIT_S` (300), `CATALOG_SYNC_MAX_DELIVERIES` (6), `PHP_INTERNAL_URL` (`http://nginx`).

## 4. Đọc health

`GET /api/ops/health` trên Flask (header `X-Internal-Token`; cache 10s) và trang admin `index.php?r=admin_search_ops` (chỉ đọc, token ở phía server).

| Khối | Ý nghĩa |
|---|---|
| `neo4j` | `count` = SP `active`; `mem_pct` / `mem_used_mb` / `mem_limit_mb` lấy từ JMX (`dbms.queryJmx`, đúng bằng cgroup của container nên không cần quyền Docker); `heap_used_mb` |
| `chroma` | số tài liệu collection `catalog_products_v1` |
| `mongo` | `neo4j_expected` / `chroma_expected` = số SP hiển thị theo đúng bộ lọc của từng bên |
| `drift` | % lệch Neo4j và Chroma so với Mongo |
| `redis` | bộ nhớ; `catalog_stream`: `length`, `pending` (XPENDING), `oldest_pending_idle_s`, `dead` |
| `jobs` | trạng thái cuối mọi job (kể cả `catalog_sync`, nhật ký sự kiện cuối) |
| `alerts[]` | `{code, level, message}`; `ok=false` chỉ khi có mức `critical` |

Ngưỡng cảnh báo (log WARNING + `alerts[]`): RAM Neo4j > 90% (`neo4j_ram_high`); lệch số SP > 1% (`neo4j_count_drift`, `chroma_count_drift`); XPENDING > 1000 (`stream_backlog`) hoặc message treo > 10 phút (`stream_stuck`); có dead-letter (`stream_dead_letters`); job lỗi 2 lần liên tiếp (`job_failed_repeatedly`, critical); job đêm không hoàn tất > 26 giờ, job tuần > 8 ngày (`job_stale`); job chưa từng chạy dù worker đã bật hơn 26 giờ (`job_never_ran`); một thành phần không truy vấn được (`<neo4j|chroma|redis|mongo>_down`, critical).

## 5. Xử lý sự cố

| Triệu chứng | Cách xử lý |
|---|---|
| `stream_backlog` / `stream_stuck` | `docker logs skinsyntax-catalog-worker`; thường do Neo4j/Chroma/Atlas lỗi tạm (worker tự thử lại, lỗi DNS SRV của Atlas hay gặp). Worker đang dừng thì bật lại, sự kiện tồn đọng được xử lý hết (đã kiểm: dừng 2 phút rồi bật lại) |
| `stream_dead_letters` | đọc `XRANGE catalog:changes:v1:dead - +`, sửa nguyên nhân rồi `--replay-dead` |
| `*_count_drift` | `--run-now graph_sync_full` (Neo4j) hoặc `--run-now chroma_reindex` (Chroma) |
| `job_failed_repeatedly` | xem `error` trong trang admin / `GET ops:job:<tên>:last`; sửa nguyên nhân rồi `--run-now <tên>` (thành công thì bộ đếm lỗi về 0) |
| gợi ý/giá cũ sau khi sửa SP | kiểm `catalog_sync` có `status=ok`; thủ công: `redis-cli INCR search:cachegen:v1` (xả mọi cache gợi ý) |
| Redis chết | PHP bỏ qua sự kiện (log cảnh báo), trang vẫn chạy; job `graph_sync_incremental` mỗi giờ bù lại khi Redis về |

## 6. RAM Neo4j: số đo và các phương án (giới hạn hiện tại 1536m, xem U13 ở cuối mục)

Không tự tăng giới hạn. Số đo thật (JMX + `docker stats`, `OOMKilled=false`, 0 restart ở mọi lần):

| Tình huống | Đỉnh RAM container | Heap JVM |
|---|---|---|
| Nghỉ | 832-845 MiB (~82%) | ~85-260 MiB |
| `graph_sync --full` (25 giây, ghi 28.525 cạnh) | **1020 MiB (99,7%)** | 410 MiB (trần 512) |
| Đồng bộ theo sự kiện (3 SP, SIMILAR_TO cục bộ ~450 cạnh) | không vượt mức nghỉ đáng kể | — |
| `write_similar` 28.525 cạnh, lô 5000 / 1000 / 250 | 886 / 874 / 992 MiB | 390 / 371 / 358 MiB |

RAM container gồm cả page cache của file store nên luôn tiến sát trần sau khi ghi nhiều; hạt nhân thu hồi được nên chưa từng OOM. Phương án:

1. **Nâng `mem_limit` Neo4j lên 1.5g** (giữ heap 512m, pagecache 128m): đỉnh 1020 MiB còn ~66% trần, cảnh báo 90% không còn nhấp nháy mỗi đêm. Đây là phương án nên chọn nếu cần cảnh báo sạch; việc sửa `docker-compose.yml` thuộc người dùng quyết định.
2. **Giảm lô ghi SIMILAR_TO** (`write_similar(edge_batch=1000)`): heap thấp hơn khoảng 5% (390 -> 371 MiB), chậm thêm ~1 giây; lô 250 chỉ giảm 8% heap nhưng chậm gấp 3. Gần như không đổi % RAM container vì phần lớn là page cache, nên KHÔNG đủ để giữ dưới 90%.
3. Giữ nguyên: đỉnh 99,7% chỉ kéo dài ~30 giây lúc 02:30; cảnh báo `neo4j_ram_high` có thể xuất hiện lúc `health_check` trúng khoảng đó (mức `warning`, không làm `ok=false`).

Đồng bộ theo sự kiện luôn dùng `sync_ids` (vài chục cạnh) nên không tạo áp lực này.

**Cập nhật U13**: người dùng đã chọn phương án 1 — `mem_limit` Neo4j nay là **1536m** (heap 512m, pagecache 128m). Đo lại khi chạy `graph_sync --full` (24 giây): `docker stats` đỉnh **84,6%** (~1,27 GiB), JMX ngay sau đó **88,5%** (1359/1536 MB), nghỉ sau khi nóng ~73-86%; `OOMKilled=false`, 0 restart, `/api/ops/health` không có cảnh báo (`neo4j_ram_high` đặt ở 90%). Dư địa còn ít (~2-4 điểm %) do page cache; nếu cảnh báo xuất hiện lúc 02:30 thì là cảnh báo mức `warning`, nâng `mem_limit` hoặc hạ `pagecache` nếu muốn sạch hoàn toàn.

## 7. Dữ liệu test trong báo cáo

`search_service/metrics.py` (`search_metrics.py`) và báo cáo 0 kết quả bỏ: `q_norm` bắt đầu `u03verify`, tài khoản test (`khach_hang.is_test_account`, khớp theo `ma_kh` ở log tìm kiếm và `user_hash` ở `search_events`, cần `SEARCH_USER_HASH_SALT`). Sự kiện của khối trang chi tiết / giỏ hàng (`q_norm` = `similar:<id>`, `cart:analyze`) không phải truy vấn tìm kiếm nên không tính vào các phần "gợi ý khi tìm"; chúng có bảng riêng "Product-page / cart blocks". Số dòng bị loại in ở dòng `Excluded test traffic` và cột `excluded_*` của `summary_*.csv`.

## 8. Chatbot: kiểm tra LLM chạy nền

`chatbot_flask.get_llms()` không còn gọi LLM thật khi dựng pool: trả ngay pool đã cấu hình (đánh dấu `unvalidated`), luồng nền `llm-validation` kiểm tra thật rồi thay bằng pool đã xác nhận (mọi provider rớt thì giữ pool cũ). Mỗi chỗ gọi LLM đã tự thử provider kế tiếp khi lỗi. `CHATBOT_LLM_BG_VALIDATION=0` trả về hành vi cũ (đồng bộ). `gunicorn_config.post_fork` gọi `get_llms()` để khởi động kiểm tra ngay sau fork.

## 9. A/B và các tính năng có cổng dữ liệu (U12)

- **Thí nghiệm**: `ai-service-flask/data/experiments.yaml` (Flask nạp lại trong ~10 giây khi file đổi, không cần restart). Chỉ thí nghiệm `status: running` được áp dụng, mỗi lần chỉ MỘT (cái đầu tiên). Nhóm = `sha256(session_hash + id) % 100`, ổn định theo phiên. Override cho phép: `weights.graph|semantic|popularity|personal`, `w_cop`, `w_curated`, `w_ctr`, và công tắc `co_purchase` / `ctr_boost` (bật tính năng có cổng cho MỘT nhánh thay vì toàn bộ).
- **Chạy một thí nghiệm**: (1) kiểm cổng >= 1.000 lượt tìm/tuần; (2) đổi `status: running`; (3) sau vài ngày `python scripts/search_metrics.py --experiment <id> --days 14` (trong container `skinsyntax-ai`): CTR, tỷ lệ thêm giỏ theo nhánh, khoảng tin cậy Wilson 95%, p-value hai tỷ lệ; dưới 1.000 impression mỗi nhánh thì báo "Chưa đủ mẫu"; (4) kết luận xong đặt `status: stopped`.
- **Beacon** (`search-fallback.js` -> `search_event`) gắn thêm: `experiment` + `variant` (từ data-attribute của khối gợi ý), `product_ids` (thứ tự hiển thị của impression), `position` (vị trí thẻ được click/thêm giỏ). Sự kiện cũ không có các trường này vẫn hợp lệ.
- **Boost CTR**: job `ctr_boost` 05:00; `SEARCH_CTR_BOOST_ENABLED=false` cho tới khi đạt cổng (>= 5.000 impression và >= 200 click trong 30 ngày, đã loại dữ liệu test). Chạy tay: `python -m workers.catalog_worker --run-now ctr_boost`.
- **Cross-encoder**: `scripts/bench_cross_encoder.py` (chạy trong container, dùng model đã cache, không tải mới). Số đo và quyết định: `docs/plans/upgrade-handoff.md`, mục U12.

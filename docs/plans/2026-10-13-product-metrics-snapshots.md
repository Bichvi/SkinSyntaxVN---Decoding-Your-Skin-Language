# AI Idol: Snapshot số liệu sản phẩm (`product_metrics_snapshots`) và tracking phiên

Ngày: 13/10/2026. Người lập: Opus (planner). Người code: Sonnet (coder).
Phạm vi gồm ghi sự kiện xem/thêm giỏ (PHP), gắn phiên vào đơn hàng (PHP), job snapshot hằng ngày và CLI (Python, ai-idol-service). **Không đổi** `agents/command_query.py`, `agents/query_sources.py`, ai-service-flask, chatbot, recommendation, voice, avatar provider, `syna-render-server.cjs`. Không thêm dependency, DB hay image. Không commit.

## 1. Bối cảnh (đã đọc code và DB, chỉ đọc)

- **Bên đọc (giữ nguyên):**
  - `query_sources.load_query_sources` truy vấn `db.product_metrics_snapshots.find({"as_of": plan["as_of"], "window_days": plan["window_days"]})`. `as_of` là **chuỗi** `YYYY-MM-DD` (ngày hiện tại theo Asia/Ho_Chi_Minh, cận trên loại trừ). `window_days` là **int** 7 hoặc 30.
  - `command_query.execute_query` chỉ nhận snapshot khi có đúng một bản ghi cho mỗi product và `coverage_complete is True`. Các trường bắt buộc theo `rank_by`:
    - `units` và `discount_slow`: `units_sold`.
    - `growth`: `units_sold` và `previous_units_sold`.
    - `cart`: `cart_sessions`.
    - `views_no_orders`: `viewed_sessions` và `converted_sessions`.
    - `revenue_low_conversion`: `net_revenue`, `viewed_sessions` và `converted_sessions`.
  - Trường `None` hoặc không phải số được tính là thiếu (`missing["sales_snapshot"]`). `converted > viewed` thì bị loại. `viewed < 100` thì bỏ qua mà không báo thiếu.
  - Khóa nối là `_product_id(snapshot)`. Snapshot chỉ có `product_id`, nên `product_id` phải **trùng chuỗi** với `_product_id(catalog_row)`, tức `str(ma_san_pham)`. Trên DB, `san_pham.ma_san_pham` đều là Int64, nên `product_id` có dạng `"123"`.
- **DB thật (Atlas dùng chung; chỉ in số đếm và tên trường):**
  - `product_metrics_snapshots` = 0, `product_events` chưa tồn tại, `san_pham` = 2.473.
  - `hoa_don` = 29, `chi_tiet_hoa_don` = 34.
  - `chi_tiet_hoa_don.ma_san_pham` lẫn kiểu str và Int64. `ma_hoa_don` lẫn int và Int64.
  - `trang_thai` lẫn có dấu và không dấu (ví dụ "Hoan thanh", "Hoàn thành", "Da huy"). `trang_thai_normalized` có thể `None`.
  - `ngay_dat` luôn là BSON date. `ngay_hoan_thanh` chỉ có ở 4 đơn.
  - Discount nằm ở các trường `so_tien_giam`, `giam_gia_voucher`, `tien_giam_diem`; có đơn có cả hai trường voucher. `inventory_movements` chỉ có `receipt` và `sale`, chưa có `return`. `return` không tham chiếu đơn hàng.
- **Định nghĩa doanh thu hiện có ở PHP** (`QuanTri::isRevenueOrder` và `getRevenueOrderDate`):
  - Đơn tính doanh thu: trạng thái `completed`. Nếu thanh toán QR hoặc chuyển khoản thì bắt buộc `status_thanh_toan` là paid.
  - Ngày ghi nhận: `ngay_hoan_thanh ?? thoi_gian_hoan_thanh ?? ngay_dat ?? created_at`.
  - `HoaDon.php:271` coi doanh thu hàng hóa là `tong_tien - phi_van_chuyen`. Snapshot dùng đúng các định nghĩa này.
- **Tracking hiện có:**
  - `san_pham.luot_xem` chỉ là bộ đếm toàn kỳ, không có phiên.
  - Giỏ hàng chỉ nằm trong `$_SESSION['gio_hang']`. Collection `gio_hang` = 0.
  - Có collection `tuong_tac_nguoi_dung` (96 bản ghi từ 30/09; `loai_tuong_tac` gồm view, add_to_cart, ...). **Không có code nào trong repo, ở mọi branch, ghi collection này.** Collection không có event_id duy nhất, không lọc bot hay admin, không có index. **Không dùng làm nguồn**, không đọc, không sửa.
- **Các điểm ghi giỏ hàng:**
  - `SanPhamController::addToCartResult` (đường chính, dòng 189).
  - `LiveController::apiLiveChat` (dòng 355).
  - `LiveController::apiLiveAddToCart` (dòng 811).
  - `TaiKhoanController::handleReorder` (dòng 244).
- **Điểm tạo đơn duy nhất:** `HomeController::xulydathang` (dòng 4323) gọi `HoaDon::taoDonHang`.
- `core.state_machine.ensure_indexes()` đang chạy khi Flask khởi động. Index mới **không** đặt ở đó.
- Tất cả file sẽ sửa đều dùng **LF**. Giữ nguyên EOL. Nhiều file PHP/Python đang có thay đổi chưa commit: chỉ chèn đúng dòng, không reformat.

## 2. Quyết định thiết kế

1. **Ghi sự kiện bằng PHP phía server, ghi trực tiếp vào Mongo `product_events`, không gọi Flask, không dùng beacon JS.**
   - Đây là dữ liệu commerce bên thứ nhất do storefront sở hữu, giống `luot_xem` và `gio_hang`, không phải chi tiết nội bộ của AI. Nếu ghi qua Flask, storefront sẽ phụ thuộc container AI Idol và mỗi lượt xem tốn thêm một HTTP hop.
   - Beacon JS dễ giả mạo, bị ad-block chặn tạo lỗ coverage không phát hiện được, và cần thêm endpoint.
   - Ghi tại chỗ `$_SESSION['gio_hang']` thay đổi thật nghĩa là chỉ ghi add_to_cart khi giỏ thực sự đổi.
   - Toàn bộ logic tổng hợp nằm ở Python. PHP chỉ ghi một document tối thiểu.
2. **Phiên ẩn danh** = cookie `ssv_sid`: 32 hex ngẫu nhiên, HttpOnly, SameSite=Lax, path `/`, sống 30 ngày, đặt ở entrypoint.
   - Chỉ lưu `sha256('ssv-analytics:' . cookie)`. Không lưu IP, UA hay user id.
   - **Chỉ ghi sự kiện khi request đã mang sẵn cookie hợp lệ.** Client không giữ cookie (phần lớn bot) không bao giờ được đếm. Đổi lại, request đầu tiên của khách mới không được đếm; số liệu thiên về thấp (bảo thủ).
   - Loại trừ:
     - `current_role()` là `admin` hoặc `nhanvien`;
     - UA rỗng hoặc khớp regex bot/headless/curl;
     - header prefetch;
     - env `PRODUCT_EVENTS_ENABLED=false`, dùng cho môi trường test.
3. **Idempotent:** `event_id = sha256(type|session_hash|product_id|ngày local)`, upsert `$setOnInsert`, unique index trên `event_id`. Mỗi phiên/sản phẩm/ngày có tối đa một sự kiện mỗi loại, đủ cho chỉ số "số phiên". Lưu trữ tối thiểu bằng TTL 120 ngày trên `event_time`.
4. **Phiên mua lấy từ đơn hàng, không lấy từ sự kiện client.**
   - `HoaDon::taoDonHang` lưu `analytics_sid` (hash) ngay trong lần insert đơn, nên việc gắn phiên là nguyên tử.
   - `converted_sessions(P, W)` = số phiên **thuộc tập đã xem P trong W** có đơn **không hủy** chứa P, với `ngay_dat ∈ [lần xem P đầu tiên trong W, cuối W)`. Theo cách dựng, đây luôn là tập con của viewed.
   - Định nghĩa "có phiên mua" là đã đặt đơn chưa hủy. Định nghĩa này bảo thủ cho `views_no_orders`.
5. **Doanh số** (`units_sold`, `net_revenue`, `previous_units_sold`):
   - Chỉ tính đơn đạt `is_revenue_order` (giống QuanTri), có ngày ghi nhận trong `[start, end)` local.
   - `net_revenue` của từng dòng = `don_gia*so_luong*f`, với `f = clamp((tong_tien - phi_van_chuyen)/Σgross_dòng, 0, 1)`. Discount đơn được phân bổ theo dòng; phí ship không bao giờ tính vào.
   - Đơn hủy bị loại toàn bộ dòng.
   - Có movement `return` của sản phẩm trong kỳ thì doanh số kỳ đó của sản phẩm = `None` (không reconcile được theo dòng).
   - Sản phẩm không có đơn: `units_sold = 0`, `net_revenue = 0`. Đây là số 0 đo được, vì sổ đơn là nguồn đầy đủ.
6. **Coverage (làm rõ hợp đồng, không đổi `command_query`):**
   - `coverage_complete = true` nghĩa là doanh số kỳ hiện tại của sản phẩm đã reconcile. Mọi trường có giá trị số đều đã đối soát. **Trường `null` nghĩa là chưa đo được, không phải 0.**
   - Trường phiên (`viewed_sessions`, `converted_sessions`, `cart_sessions`) chỉ có số khi đủ tất cả điều kiện sau:
     - đã đặt env `AI_IDOL_TRACKING_STARTED_ON`;
     - ngày này ≤ ngày đầu cửa sổ;
     - cửa sổ còn nằm trong retention;
     - **mọi ngày local trong cửa sổ có ít nhất 1 `product_view` toàn site**, để phát hiện tracking bị gián đoạn.

     Thiếu một điều kiện thì cả ba trường là `null`. Khi đó `command_query` tự trả DATA_UNAVAILABLE cho rank dựa trên view hoặc cart, còn `units`, `growth`, `discount_slow` vẫn chạy được.
   - `command_query` hiện đã đối xử với `None` như thiếu dữ liệu. **Không có lệch hợp đồng nào cần sửa ở bên đọc.**
7. **Backfill:**
   - Được phép backfill tối đa 60 ngày `as_of` từ đơn thật.
   - Trường phiên tự động `null` cho cửa sổ trước ngày bắt đầu tracking.
   - **Cấm** tạo hay nhập view từ `luot_xem`, `tuong_tac_nguoi_dung`, review count hay seed giả.
   - Rank dựa trên view chỉ khả dụng sau ≥7 hoặc ≥30 ngày tracking liên tục.
8. **Job:**
   - Celery beat chạy `crontab(minute=30)` mỗi giờ, queue `control`.
   - `run_due_snapshots` đọc watermark từ `product_metrics_runs`: as_of cuối cùng có trạng thái SUCCEEDED.
     - Đã có as_of hôm nay thì không làm gì.
     - Nếu chưa, dựng từ `max(last+1, today-6)` đến hôm nay.

     Như vậy lần chạy 00:30 dựng snapshot trong ngày, các giờ sau tự retry nếu lỗi.
   - Tính toàn bộ trong bộ nhớ trước, sau đó bulk upsert theo `(product_id, window_days, as_of)`. Chạy lại cho cùng kết quả.
9. **Index** chỉ được tạo bằng CLI tường minh `python -m core.product_metrics ensure-indexes`. Không chạy trong Flask startup, request Agent hay task.

## 3. Luồng dữ liệu

```
Trình duyệt ──(cookie ssv_sid)──> index.php: ProductEventTracker::ensureCookie()
  chitiet (GET, sp tồn tại)        ─record('product_view')─┐
  addToCartResult/apiLive*/reorder ─record('add_to_cart')──┼─> Mongo product_events (unique event_id, TTL 120d)
  xulydathang → taoDonHang(analytics_sid=hash)  ───────────┼─> hoa_don.analytics_sid
Celery beat (:30 mỗi giờ) → ai_idol.build_product_metrics (queue control)
  → core.product_metrics.run_due_snapshots → build_snapshots(as_of)
     đọc san_pham(id) + hoa_don + chi_tiet_hoa_don + inventory_movements(return) + product_events
     → compute_snapshot_docs (thuần) → bulk upsert product_metrics_snapshots + log product_metrics_runs
Agent: query_sources.load_query_sources (giữ nguyên) → command_query.execute_query (giữ nguyên)
```

## 4. Task

Lệnh test: `docker compose run --rm --no-deps -v "${PWD}/ai-idol-service:/app" ai-idol-worker python -m unittest discover -s tests -v`. Test viết kiểu FakeDb như `tests/fakes.py` và `tests/test_script_review.py`. Không dùng mongomock.

### T0. Chuẩn bị (chỉ đọc)
- Chạy `git status`.
- Xác nhận EOL là LF cho mọi file trong danh sách sửa (`grep -c $'\r' file` phải = 0).
- Chạy GitNexus impact (upstream) cho:
  - `SanPhamController.addToCartResult`, `SanPhamController.chitiet`;
  - `HoaDon.taoDonHang`, `HomeController.xulydathang`;
  - `LiveController.apiLiveChat`, `LiveController.apiLiveAddToCart`;
  - `TaiKhoanController.handleReorder`;
  - `Collection` (trong `tests/fakes.py`).
- Báo cáo risk. Với UNKNOWN, kiểm tra lại bằng `rg`.

### T1. Mở rộng FakeDb
- **File:** `ai-idol-service/tests/fakes.py`.
- **`_match_value`:** thêm `"$gte"`: `if op == "$gte" and not (actual is not None and actual >= expected): return False`.
- **`Collection`:** thêm `bulk_write(self, requests, ordered=True)`. Với mỗi `op`, gọi `self.update_one(op._filter, op._doc, upsert=op._upsert)` (đã kiểm tra pymongo 4.18 có các thuộc tính này). Trả `SimpleNamespace(matched_count=n, upserted_count=0, modified_count=n)`.
- Cập nhật docstring đầu file để liệt kê `$gte` và `bulk_write`.
- **Test:** toàn bộ suite cũ vẫn PASS.

### T2. Cấu hình
- **File:** `ai-idol-service/config.py`. Thêm sau `STALE_ASSEMBLING_MINUTES`:
  ```python
  TRACKING_STARTED_ON = os.getenv("AI_IDOL_TRACKING_STARTED_ON", "").strip() or None  # YYYY-MM-DD local, ngày đầu đủ tracking
  PRODUCT_EVENTS_RETENTION_DAYS = int(os.getenv("AI_IDOL_PRODUCT_EVENTS_RETENTION_DAYS", "120"))
  ```
- **`.env.example`:** thêm 3 dòng có comment:
  - `AI_IDOL_TRACKING_STARTED_ON=` (để trống; chỉ đặt sau khi deploy tracking, và đặt bằng ngày *kế tiếp* ngày deploy);
  - `AI_IDOL_PRODUCT_EVENTS_RETENTION_DAYS=120`;
  - `PRODUCT_EVENTS_ENABLED=true`.
- Không sửa `docker-compose.yml`: container AI Idol đã nhận `.env` qua `env_file`.

### T3. Builder snapshot (mới)
- **File:** `ai-idol-service/core/product_metrics.py`, LF, `from __future__ import annotations`.
- **Import:**
  - `from agents.command_query import _product_id, number`: dùng chung để khóa sản phẩm trùng tuyệt đối với bên đọc;
  - `from agents.intent_parser import normalize_text`;
  - `from agents.query_sources import bounded_rows`;
  - `from config import TIMEZONE, TRACKING_STARTED_ON, PRODUCT_EVENTS_RETENTION_DAYS, logger`;
  - `from core.state_machine import get_db, utcnow`;
  - `from pymongo import UpdateOne, ASCENDING`.
- **Hằng số:**
  - `METRIC_VERSION = 1`, `WINDOWS = (7, 30)`, `MAX_BACKFILL_DAYS = 60`, `CATCHUP_DAYS = 7`, `EVENT_TYPES = ("product_view", "add_to_cart")`.
  - Giới hạn: catalog 20000, đơn 200000, sự kiện 500000. Vượt giới hạn thì `bounded_rows` raise và run FAILED; không bao giờ ghi snapshot từ dữ liệu bị cắt.
- **Hàm** (đúng tên và chữ ký):
  1. `canonical_product_id(value) -> str | None`.
     - `None` hoặc bool → `None`.
     - int → `str(int)`.
     - float nguyên → `str(int)`.
     - str: strip; nếu khớp `^\d+(?:\.0+)?$` thì `str(int(s.split(".")[0]))`; nếu khớp `^[A-Za-z0-9_-]{1,40}$` thì giữ nguyên; trường hợp khác → `None`.
  2. `to_utc(value) -> dt.datetime | None`.
     - datetime: naive thì coi là UTC; trả về aware UTC.
     - str: thử `dt.datetime.fromisoformat`; nếu không có tz thì coi là **giờ local `TIMEZONE`** (giống PHP lưu chuỗi local).
     - Kiểu khác → `None`.
  3. `local_midnight_utc(day: dt.date) -> dt.datetime`: 00:00 ngày đó ở `ZoneInfo(TIMEZONE)`, đổi sang UTC.
  4. `order_status(order) -> str`.
     - `s = normalize_text(order.get("trang_thai") or order.get("trang_thai_normalized") or "").replace("_"," ").replace("-"," ")`.
     - `{"hoan thanh","completed"}` → `"completed"`.
     - `{"da huy","huy","cancelled","canceled"}` → `"cancelled"`.
     - Còn lại trả về `s`.
  5. `is_paid(order) -> bool`: `normalize_text(order.get("status_thanh_toan") or order.get("payment_status") or "")` thuộc `{"da thanh toan","paid","thanh cong","completed"}`.
  6. `is_revenue_order(order) -> bool`.
     - Bắt buộc `order_status == "completed"`.
     - `m = str(order.get("hinh_thuc_thanh_toan") or "cod").lower()`. Nếu `"qr" in m or "transfer" in m` thì trả `is_paid(order)`, ngược lại trả `True`.
     - Bản sao đúng `QuanTri::isRevenueOrder`.
  7. `revenue_time(order) -> dt.datetime | None`: giá trị `to_utc` đầu tiên khác `None` theo thứ tự `ngay_hoan_thanh`, `thoi_gian_hoan_thanh`, `ngay_dat`, `created_at`.
  8. `aggregate_sales(orders, lines_by_order, alias, start, end, returned: set[str]) -> dict`.
     - Trả `{"units": Counter, "revenue": defaultdict(float), "bad": set[str], "global_bad": bool}`.
     - Với mỗi order có `is_revenue_order`:
       - `t = revenue_time(order)`; `None` → `global_bad = True`, `continue`.
       - Ngoài `[start, end)` → bỏ qua.
       - `base = number(tong_tien) - number(phi_van_chuyen or 0)`; nếu `tong_tien` không phải số → đánh `bad` mọi sản phẩm của các dòng đơn đó, `continue`.
       - Mỗi dòng: `pid = alias.get(canonical_product_id(line.get("ma_san_pham")))`; `pid` là `None` thì bỏ qua (sản phẩm không còn trong catalog).
       - `qty = number(so_luong)` phải nguyên và > 0; `price = number(don_gia)`. Không hợp lệ → `bad.add(pid)`.
       - `gross = Σ qty*price` của các dòng hợp lệ. `f = 0 if gross == 0 else min(1, max(0, base/gross))`.
       - `units[pid] += qty`, `revenue[pid] += qty*price*f`.
     - Cuối hàm: `bad |= returned`.
  9. `aggregate_sessions(events, orders, lines_by_order, alias, start, end) -> dict`.
     - Trả `{"viewed": Counter, "converted": Counter, "carted": Counter, "view_days": set[date], "global_bad": bool}`.
     - Sự kiện có `event_time` trong `[start, end)` và `pid` map được:
       - `product_view` → `first_view[(pid, sid)] = min(...)`; thêm ngày local của `event_time` vào `view_days` (tính cả sự kiện có pid không map được).
       - `add_to_cart` → thêm vào `set (pid, sid)`.
     - Đơn có `analytics_sid` và `order_status != "cancelled"`:
       - `t = to_utc(ngay_dat)`; `None` → `global_bad = True`.
       - `t ∈ [start, end)`: với mỗi dòng có `pid`, nếu `(pid, sid) in first_view and t >= first_view[...]` thì thêm `(pid, sid)` vào tập converted.
     - Đếm số `sid` phân biệt cho từng `pid`.
  10. `sessions_reasons(as_of, window_days, *, tracking_started_on, retention_days, today, view_days, global_bad) -> list[str]`, với `start_day = as_of - window_days`. Mã lý do:
      - `"tracking_unconfigured"`: `tracking_started_on` là `None`;
      - `"tracking_not_started"`: `start_day < tracking_started_on`;
      - `"outside_retention"`: `(today - start_day).days >= retention_days`;
      - `"tracking_gap"`: có ngày `d ∈ [start_day, as_of)` không thuộc `view_days`;
      - `"order_time_invalid"`: `global_bad`.

      Danh sách rỗng nghĩa là đủ coverage.
  11. `compute_snapshot_docs(as_of, *, catalog, orders, lines, events, returns, tracking_started_on, retention_days, today, run_id, built_at) -> list[dict]` (**thuần**, không I/O).
      - `alias`: với mỗi catalog row, `key = _product_id(row)`. Map `canonical_product_id(row.get("ma_san_pham"))` và `canonical_product_id(row.get("id"))` về `key`.
      - `lines_by_order`: key theo `int(ma_hoa_don)`.
      - Với mỗi `W` trong `WINDOWS`:
        - `cur = [as_of-W, as_of)`, `prev = [as_of-2W, as_of-W)`, đổi sang UTC bằng `local_midnight_utc`.
        - `returned` của từng kỳ lấy từ `returns`: mỗi phần tử là `(pid canonical→alias, occurred_at utc)`.
      - Doc cho mỗi `key` (mọi sản phẩm catalog):
        ```python
        {"product_id": key, "window_days": W, "as_of": as_of.isoformat(), "window_start": (as_of-dt.timedelta(W)).isoformat(),
         "coverage_complete": sales_ok, "units_sold": int|None, "net_revenue": int(round(rev))|None,
         "previous_units_sold": int|None, "viewed_sessions": int|None, "converted_sessions": int|None,
         "cart_sessions": int|None,
         "coverage": {"sales": sales_ok, "previous_sales": prev_ok, "sessions": not reasons,
                      "reasons": sorted(set(...)), "tracking_started_on": iso|None},
         "metric_version": METRIC_VERSION, "run_id": run_id, "built_at": built_at}
        ```
      - Quy tắc:
        - `sales_ok = not cur.global_bad and key not in cur.bad`. Khi `sales_ok` là False thì `units_sold` và `net_revenue` đều `None`; reasons thêm `"order_ledger_invalid"`, `"line_invalid"` hoặc `"return_unreconciled"` tương ứng.
        - `prev_ok` cùng cách tính trên kỳ trước. Khi False thì `previous_units_sold = None`.
        - Có reasons phiên thì cả 3 trường phiên là `None`. Không có thì là int (0 hợp lệ).
  12. `load_sources(db, as_of, today) -> dict`.
      - `catalog = bounded_rows(db.san_pham.find({}, {"ma_san_pham":1,"id":1}), 20000, "Catalog")`.
      - `orders = bounded_rows(db.hoa_don.find({}, {projection: ma_hoa_don, trang_thai, trang_thai_normalized, status_thanh_toan, payment_status, hinh_thuc_thanh_toan, tong_tien, phi_van_chuyen, ngay_dat, ngay_hoan_thanh, thoi_gian_hoan_thanh, created_at, analytics_sid}), 200000, "Đơn hàng")`.
      - Lọc đơn liên quan: `revenue_time >= utc(as_of-60)` hoặc `ngay_dat >= utc(as_of-30)` hoặc `revenue_time is None`.
      - `lines`: `db.chi_tiet_hoa_don.find({"ma_hoa_don": {"$in": chunk}}, {"ma_hoa_don":1,"ma_san_pham":1,"so_luong":1,"don_gia":1})`, chunk 1000 id.
      - `returns = db.inventory_movements.find({"type": "return", "occurred_at": {"$gte": utc(as_of-60), "$lt": utc(as_of)}}, {"product_id":1,"occurred_at":1})`.
      - `events`:
        - Chỉ đọc khi `TRACKING_STARTED_ON` có giá trị và `≤ as_of-7`; nếu không, `[]`.
        - Ngược lại `bounded_rows(db.product_events.find({"type": {"$in": list(EVENT_TYPES)}, "event_time": {"$gte": utc(as_of-30), "$lt": utc(as_of)}}, {"type":1,"product_id":1,"session_hash":1,"event_time":1}), 500000, "Sự kiện sản phẩm")`.
        - Map `session_hash` → `sid`.
  13. `write_snapshots(db, docs) -> int`.
      - `UpdateOne({"product_id": d["product_id"], "window_days": d["window_days"], "as_of": d["as_of"]}, {"$set": d}, upsert=True)`.
      - `bulk_write(chunk, ordered=False)` theo chunk 500. Trả về số doc.
  14. `build_snapshots(as_of: dt.date, *, db=None, now=None) -> dict`.
      - `today` = ngày local của `now or utcnow()`.
      - `as_of > today` hoặc `as_of < today - MAX_BACKFILL_DAYS` → `ValueError`.
      - `run_id = uuid4().hex`.
      - Upsert `product_metrics_runs` theo `{"as_of": iso}` với `$set {status:"RUNNING", run_id, started_at}`. Sau đó `load_sources`, `compute_snapshot_docs`, `write_snapshots`.
      - Thành công → `$set {status:"SUCCEEDED", finished_at, counts:{products, docs, sales_unreconciled, sessions_covered_7: bool, sessions_covered_30: bool}}`.
      - Exception → `$set {status:"FAILED", finished_at, error_type: type(exc).__name__}`; `logger.error("[METRICS] build %s failed: %s", iso, type(exc).__name__)`; re-raise.
      - **Không log message lỗi gốc**, vì có thể chứa host hoặc URI.
  15. `run_due_snapshots(*, db=None, now=None) -> dict`.
      - Tìm `last` bằng `db.product_metrics_runs.find({"status": "SUCCEEDED"}).sort("as_of", -1).limit(1)`.
      - Nếu `last >= today` → `{"built": [], "skipped": "up_to_date"}`.
      - Ngược lại `first = max(today - (CATCHUP_DAYS-1), last+1)` nếu có `last`, nếu không thì `today`. Gọi `build_snapshots` tuần tự từ `first` đến `today`. Dừng ở lỗi đầu tiên và để task raise.
  16. `ensure_metrics_indexes(db=None) -> list[str]` tạo các index sau:
      - `product_metrics_snapshots`:
        - `[("product_id",1),("window_days",1),("as_of",1)]`, unique, name `pms_identity`;
        - `[("as_of",1),("window_days",1)]`, name `pms_lookup`.
      - `product_metrics_runs`: `as_of` unique, name `pmr_as_of`.
      - `product_events`:
        - `event_id` unique, name `pe_event_id`;
        - `[("type",1),("event_time",1)]`, name `pe_type_time`;
        - `event_time` với `expireAfterSeconds = PRODUCT_EVENTS_RETENTION_DAYS*86400`, name `pe_ttl`.

      Không tạo index trên collection PHP như `hoa_don`.
  17. `main(argv=None) -> int`. argparse với các subcommand:
      - `ensure-indexes`;
      - `build [--as-of YYYY-MM-DD]` (mặc định hôm nay local);
      - `backfill --days N` (1..60; dựng từ `today-N+1` đến `today`);
      - `run-due`.

      In JSON chỉ chứa as_of, status và counts. Cuối file có `if __name__ == "__main__": raise SystemExit(main())`.

### T4. Celery
- **`ai-idol-service/tasks.py`:**
  - import `from core.product_metrics import run_due_snapshots`;
  - thêm task:
    ```python
    @celery_app.task(name="ai_idol.build_product_metrics")
    def build_product_metrics_task():
        return run_due_snapshots()
    ```
- **`ai-idol-service/celery_app.py`:**
  - `from celery.schedules import crontab`;
  - `TASK_ROUTES["ai_idol.build_product_metrics"] = {"queue": "control"}`, viết thành dòng trong dict;
  - thêm vào `beat_schedule`: `"build-product-metrics": {"task": "ai_idol.build_product_metrics", "schedule": crontab(minute=30), "options": {"expires": 3000}}`.
  - `timezone = TIMEZONE` đã có sẵn, nên giờ cron tính theo giờ VN.

### T5. Test Python (mới)
- **File:** `ai-idol-service/tests/test_product_metrics.py`, theo kiểu `test_command_query.py` (sys.path insert). Dùng `FakeDb` từ `tests.fakes` và datetime aware UTC. Cố định `AS_OF = dt.date(2026,10,13)`, `TODAY = AS_OF`.
- **Các test:**
  1. `canonical_product_id`: `93` hoặc `Int64(93)` → `"93"`; `"93.0"` → `"93"`; `" 93 "` → `"93"`; `True` hoặc `None` → `None`.
  2. Trạng thái doanh thu:
     - "Hoan thanh" + cod → doanh thu;
     - "Hoàn thành" + bank_transfer_qr + "Chưa thanh toán" → không;
     - "Hoàn thành" + bank_transfer_qr + "Đã thanh toán" → có;
     - "Đã hủy" → không.
  3. Phân bổ và ship:
     - 2 dòng 100k×1 + 50k×2, `tong_tien` 210k, ship 30k → `net_revenue` 90000 cho mỗi sản phẩm;
     - đơn hủy không tính;
     - đơn đặt trước cửa sổ nhưng `ngay_hoan_thanh` trong cửa sổ thì được tính;
     - hoàn thành `2026-10-12T17:00Z` (đúng 00:00 local ngày 13) **bị loại** khỏi as_of 2026-10-13.
  4. Sản phẩm không có đơn: `units_sold == 0`, `net_revenue == 0`, `coverage_complete is True`.
  5. Dòng `so_luong="abc"` → sản phẩm đó có `units_sold is None`, `coverage_complete is False`, reasons chứa `"line_invalid"`; sản phẩm khác không bị ảnh hưởng.
  6. Movement `return` trong kỳ → `units_sold is None`, reasons chứa `"return_unreconciled"`.
  7. `previous_units_sold` lấy đúng cửa sổ `[as_of-2W, as_of-W)` cho W=7.
  8. `tracking_started_on=None` → 3 trường phiên `None`, reasons chứa `tracking_unconfigured`, `coverage_complete` vẫn True. **Hợp đồng với bên đọc:**
     - đưa docs vào `execute_query(build_query_plan("Lọc 10 sữa rửa mặt xem nhiều chưa chốt đơn", today=AS_OF), catalog, snapshots=docs, brief=...)` → `status == "DATA_UNAVAILABLE"` và có `missing_data["sales_snapshot"]`;
     - `"Lọc 10 sữa rửa mặt bán chạy"` → `RESULTS_READY` (catalog: `ten_san_pham` chứa "Sữa rửa mặt").
  9. Đủ coverage (`tracking_started_on = AS_OF-30`, mỗi ngày có ≥1 view):
     - 120 phiên xem sản phẩm A, 1 phiên xem rồi đặt đơn chưa hủy → `viewed=120`, `converted=1`;
     - đơn đặt **trước** lần xem không được tính;
     - phiên mua không xem không được tính;
     - sự kiện trùng chỉ đếm một lần;
     - `cart_sessions` đếm phiên phân biệt;
     - `converted ≤ viewed`.
  10. Thiếu sự kiện một ngày → `tracking_gap`, 3 trường phiên `None`.
  11. `retention_days=20` với W=30 → `outside_retention`.
  12. `build_snapshots` với FakeDb:
      - doc có `as_of` kiểu `str`, `window_days` kiểu `int`;
      - chạy 2 lần → số doc trong `product_metrics_snapshots` không đổi (idempotent);
      - `product_metrics_runs` có status `SUCCEEDED`;
      - `as_of` ngày mai → `ValueError`.
  13. `run_due_snapshots` (patch `core.product_metrics.build_snapshots` để ghi lại các lần gọi):
      - chưa có run → chỉ dựng `today`;
      - last = hôm qua → chỉ dựng `today`;
      - last = hôm nay → không gọi;
      - last cách 10 ngày → 7 ngày.
  14. `celery_app.TASK_ROUTES["ai_idol.build_product_metrics"]["queue"] == "control"`, và beat có key `build-product-metrics`.

### T6. PHP: service ghi sự kiện (mới)
- **File:** `backend/app/services/ProductEventTracker.php`, LF, `final class ProductEventTracker`.
- **Hằng số:**
  - `COOKIE = 'ssv_sid'`, `TYPES = ['product_view', 'add_to_cart']`, `COOKIE_TTL = 2592000`;
  - `BOT_PATTERN = '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|curl|wget|python|httpclient|java\/|go-http|monitor|uptime/i'`.
- **Phương thức static:**
  - `enabled(): bool`: `ss_env('PRODUCT_EVENTS_ENABLED', 'true')` nếu có hàm, nếu không thì `getenv`; giá trị thuộc `1|true|yes|on`.
  - `ensureCookie(): void`.
    - Return nếu `!enabled()`, `PHP_SAPI === 'cli'`, `headers_sent()` hoặc đã có cookie hợp lệ.
    - Ngược lại `setcookie(self::COOKIE, bin2hex(random_bytes(16)), ['expires'=>time()+self::COOKIE_TTL,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax'])`, với `$https` = `HTTPS` khác `off`/rỗng hoặc `HTTP_X_FORWARDED_PROTO === 'https'`.
    - **Không** gán vào `$_COOKIE`, để request hiện tại không được đếm.
  - `private validCookie(): ?string`: `$_COOKIE['ssv_sid']` khớp `/^[a-f0-9]{32}$/`.
  - `sessionHash(): ?string`: `hash('sha256', 'ssv-analytics:' . $cookie)`, hoặc `null`.
  - `isExcluded(): bool`:
    - `function_exists('current_role') && in_array(current_role(), ['admin','nhanvien'], true)`;
    - UA rỗng hoặc khớp `BOT_PATTERN`;
    - `HTTP_SEC_PURPOSE`, `HTTP_PURPOSE` hoặc `HTTP_X_MOZ` chứa `prefetch`.
  - `record($db, string $type, $productId): void`. Bọc toàn bộ trong `try { } catch (Throwable $e) { error_log('product_event skipped: ' . get_class($e)); }`.
    - Kiểm tra lần lượt: `enabled`, type thuộc `TYPES`, `$pid = trim((string)$productId)` khớp `/^[A-Za-z0-9_-]{1,40}$/`, `$sid = sessionHash()` khác null, `!isExcluded()`.
    - `$eventId = hash('sha256', "$type|$sid|$pid|" . date('Y-m-d'))` (PHP đã đặt `Asia/Ho_Chi_Minh` trong `config.php`).
    - `$db->product_events->updateOne(['event_id'=>$eventId], ['$setOnInsert'=>['type'=>$type,'product_id'=>$pid,'session_hash'=>$sid,'event_time'=>new \MongoDB\BSON\UTCDateTime(),'schema_version'=>1]], ['upsert'=>true])`.
    - **Không** đặt `event_id` trong `$setOnInsert`.
    - Không log pid, sid, UA hay IP.

### T7. PHP: nối vào các điểm hiện có (mỗi chỗ chỉ chèn 1–2 dòng)
- **Entrypoint**, sửa giống hệt nhau ở 3 file `frontend/public/index.php`, `backend/public/index.php` và `index.php`.
  - Ngay sau khối `if (is_file($helpersFile)) { require_once $helpersFile; }` và **trước** `if (!headers_sent()) { header(...) }`, chèn:
    ```php
    require_once $backendRoot . '/app/services/ProductEventTracker.php';
    ProductEventTracker::ensureCookie();
    ```
- **`backend/app/controllers/SanPhamController.php`:**
  - Đầu file thêm `require_once __DIR__ . '/../services/ProductEventTracker.php';`.
  - `chitiet()`: ngay sau `$this->trackRecentViewedProduct(...)`, thêm `ProductEventTracker::record($this->pdo, 'product_view', (string)($p['ma_san_pham'] ?? $p['id'] ?? $id));`.
  - `addToCartResult()`: ngay sau `$_SESSION['gio_hang'][$cartProductId] = $currentQty + $qty;`, thêm `ProductEventTracker::record($this->pdo, 'add_to_cart', $cartProductId);`.
- **`backend/app/controllers/LiveController.php`:** đầu file thêm `require_once`.
  - Sau dòng 355: `ProductEventTracker::record($this->pdo, 'add_to_cart', $pIdStr);` (đặt trong `if ($pIdStr !== '')`).
  - Sau dòng 811: `ProductEventTracker::record($mongoDb, 'add_to_cart', $productId);`.
- **`backend/app/controllers/TaiKhoanController.php`:** đầu file thêm `require_once`. Trong `handleReorder`, sau `$_SESSION['gio_hang'][$cartKey] = $currentQty + $qtyToAdd;`, thêm `ProductEventTracker::record($this->pdo, 'add_to_cart', $cartKey);`.
- **`backend/app/controllers/HomeController.php`:** đầu file thêm `require_once`. Trong `xulydathang()`, thêm một phần tử vào mảng payload của `taoDonHang([...])`: `'analytics_sid' => ProductEventTracker::sessionHash(),`.
- **`backend/app/models/HoaDon.php`:** trong `taoDonHang`, thêm vào `$dataHoaDon` (sau `'ngay_dat' => ...`):
  `'analytics_sid' => (isset($payload['analytics_sid']) && is_string($payload['analytics_sid']) && preg_match('/^[a-f0-9]{64}$/', $payload['analytics_sid'])) ? $payload['analytics_sid'] : null,`
  Không đổi logic tạo đơn nào khác.
- **Test:** `php -l` từng file PHP đã sửa hoặc tạo mới (8 file).

### T8. Vận hành và tài liệu
- **`ai-idol-service/README.md`:** thêm mục "Product metrics snapshots" gồm:
  - định nghĩa trường (mục 2.4–2.6);
  - lệnh `docker compose exec ai-idol-control-worker python -m core.product_metrics ensure-indexes | build | backfill --days N | run-due`;
  - quy tắc đặt `AI_IDOL_TRACKING_STARTED_ON`;
  - cảnh báo không seed view.
- **`update.md`:** thêm một mục nhật ký ngắn ghi kết quả test thật.
- **Không rebuild hay restart** container đang chạy nếu chưa được yêu cầu. Image chứa sẵn source, nên job mới chỉ có hiệu lực sau khi rebuild các service `ai-idol-*`. Ghi rõ điều này khi bàn giao.
- **Thứ tự triển khai khi được phép** (DB là Atlas dùng chung):
  1. `ensure-indexes`;
  2. deploy PHP;
  3. sáng hôm sau đặt `AI_IDOL_TRACKING_STARTED_ON` = ngày kế tiếp ngày deploy;
  4. `backfill --days 30` (chỉ dựng doanh số; trường phiên `null`).

## 5. Nguyên tắc kiến trúc

- Thiếu số liệu là `null` hoặc không có doc, không bao giờ là 0. 0 chỉ xuất hiện khi nguồn đã được chứng minh đầy đủ: sổ đơn, hoặc tracking liên tục đủ cửa sổ.
- Không dùng `luot_xem`, `so_luong_danh_gia`, `tuong_tac_nguoi_dung` hay dữ liệu seed để thay cho view, cart hoặc conversion.
- Converted là tập con của viewed theo cách dựng (cùng phiên, cùng cửa sổ, đơn đặt sau lần xem đầu).
- Doanh thu tính theo dòng, sau khi phân bổ discount, không gồm phí ship. Đơn hủy và hàng trả chưa reconcile được thì không tính hoặc fail-closed.
- PHP chỉ ghi document tối thiểu và không throw ra luồng mua hàng. Mọi tổng hợp nằm ở Python.
- Index và backfill là bước CLI tường minh. Đường xử lý request của Agent không ghi hay migrate.
- Log chỉ chứa as_of, số đếm và tên lớp lỗi. Không log cookie, hash phiên, IP, UA, email, URI hay stream key.
- Không sửa `command_query.py` hay `query_sources.py`: hợp đồng hiện tại đã khớp (as_of chuỗi, window int, `None` là thiếu).

## 6. Nghiệm thu

- Toàn bộ unittest PASS bằng lệnh test ở mục 4. Suite cũ không đổi kết quả; test mới T5 PASS.
- `php -l` PASS cho 8 file PHP. `git diff --check` sạch. EOL các file vẫn là LF.
- Không có thay đổi trong `agents/command_query.py`, `agents/query_sources.py`, ai-service-flask, `syna-render-server.cjs`.
- Kiểm tra đọc trên DB (chỉ khi được phép chạy CLI): sau `build`, `product_metrics_snapshots.count_documents({"as_of": today})` = số sản phẩm × 2.
  - Khi chưa đặt `AI_IDOL_TRACKING_STARTED_ON`: prompt "Lọc 10 sữa rửa mặt xem nhiều chưa chốt đơn" vẫn là DATA_UNAVAILABLE (đúng thiết kế); "Lọc 10 sữa rửa mặt bán chạy" trả RESULTS_READY hoặc EMPTY_RESULTS dựa trên 29 đơn thật, không còn "163 trường hợp cần bổ sung".
- Tracking:
  - Playwright headless → **không** ghi `product_events` (đúng loại trừ), nhưng response có `Set-Cookie: ssv_sid`.
  - Kiểm tra dương tính (người dùng mở trình duyệt thường, vào trang sản phẩm 2 lần) → đúng 1 doc `product_view`, không chứa IP hay UA.
  - Nếu không chạy được thì ghi `E2E NOT RUN` kèm lý do.
- Rank dựa trên view hoặc cart chỉ có thể RESULTS_READY sau ≥30 (hoặc 7) ngày tracking liên tục và ≥100 phiên xem mỗi sản phẩm. Trên môi trường local, kết quả hợp lệ dự kiến là DATA_UNAVAILABLE hoặc EMPTY_RESULTS.
- **Rủi ro cần nêu khi bàn giao:**
  - `hoa_don.analytics_sid` liên kết lịch sử xem (120 ngày) với khách đã đặt đơn. Đây là dữ liệu bút danh, cần nêu trong chính sách cookie.
  - Đơn của nhân viên vẫn được tính doanh số, giống báo cáo admin hiện tại.
  - `tuong_tac_nguoi_dung` có nguồn ghi ngoài repo trên Atlas dùng chung; chưa xác minh.

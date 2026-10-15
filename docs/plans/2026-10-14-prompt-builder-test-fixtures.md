# Bộ dữ liệu test Prompt Builder (pb-fixture-v1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sinh một bộ dữ liệu test có tính logic thật (lô hàng, đơn hàng, phiên xem/thêm giỏ, khách hàng giả lập) đủ để **mọi lựa chọn** trong "Ghép lệnh từ nhiều lựa chọn" (`index.php?r=admin_ai_idol`) trả kết quả có thể kiểm chứng, không còn `DATA_UNAVAILABLE` do thiếu dữ liệu, và có lệnh xoá sạch.

**Architecture:** Package Python thuần `ai-idol-service/fixtures/prompt_builder/` chạy trong container `skinsyntax-ai-idol` (có sẵn pymongo, `config`, `agents.command_query`, `core.product_metrics`). Phần sinh dữ liệu là hàm thuần (seed RNG cố định, không I/O). Trước khi ghi DB, một bộ kiểm chứng offline chạy chính engine thật (`build_query_plan` → `execute_query`, `compute_snapshot_docs`) trên dữ liệu sinh trong bộ nhớ. Ghi DB chỉ qua CLI, mọi document gắn `test_dataset="pb-fixture-v1"`.

**Tech Stack:** Python 3.11 (container ai-idol), pymongo, unittest + `ai-idol-service/tests/fakes.py`; MongoDB Atlas (DB `skinsyntax`); một sửa nhỏ trong `agents/query_sources.py`.

**Spec:** Yêu cầu người dùng ngày 14/10/2026 (trong hội thoại) + hiện trạng đo trên Atlas (mục "Hiện trạng" bên dưới).

## Hiện trạng đã đo (read-only, 14/10/2026)

- PHP + AI Idol đều dùng **Atlas** (`MONGO_DB=skinsyntax`), không dùng `skinsyntax-mongo` local.
- `san_pham`: 2.473 sp, **chỉ có "Chăm Sóc Da Mặt"**; `ma_san_pham` là Int64; brand chỉ có `ma_thuong_hieu` (→ collection `thuong_hieu.ten_thuong_hieu`), **không có** `thuong_hieu`/`ten_thuong_hieu` → lọc thương hiệu luôn rỗng.
- `san_pham_backup_6377`: có sản phẩm thật Trang điểm 1.615 / Cơ thể 728 / Tóc 506; 3.904 mã không có trong live.
- `inventory_batches`: 1.000 lô `AUTO-DEFAULT` cùng HSD 2028-03-28 (≈17,5 tháng → làm mọi test 17–19 tháng "đúng giả"), 1.000 lô `AUTO-SCENARIOS` số lượng cố định. `product_id` lưu dạng string.
- `hoa_don` 29 đơn (max `ma_hoa_don`=38), `chi_tiet_hoa_don` 34 (max `id`=45), `khach_hang` 20 (không có hồ sơ da), `product_events` 0, `AI_IDOL_TRACKING_STARTED_ON` chưa đặt → mọi chỉ số phiên = `null`.
- Recommender hiện tại **không đọc `hoa_don`**: Simple (WR từ `diem_danh_gia`/`so_luong_danh_gia`), Hybrid (TF‑IDF + sản phẩm vừa xem + hồ sơ da + ngân sách), mục "Bán chạy" (sort `so_luong_da_ban` của catalog).

## Global Constraints

- Tag bắt buộc trên **mọi** document được tạo/sửa: `test_dataset: "pb-fixture-v1"`, `data_source: "pb_fixture"`, `needs_verification: true`.
- Dải ID riêng: `hoa_don.ma_hoa_don` từ 500001, `chi_tiet_hoa_don.id` từ 500001, `khach_hang.ma_kh` từ 900001. Email khách giả `pb-fixture-{n}@example.test`, không mật khẩu (không đăng nhập được).
- Seed RNG mặc định `20261014`, tham số `--seed`; cùng seed + cùng ngày chạy ⇒ cùng dữ liệu.
- Mọi mốc ngày tính tương đối với `T` = ngày chạy theo `Asia/Ho_Chi_Minh`, cộng tháng bằng `agents.command_query.add_months` (để biên khớp tuyệt đối với engine).
- Không sửa `so_luong_ton_kho`/`so_luong_da_ban` trong `san_pham` (để không đổi recommender, trang shop). Hai cờ `--sync-catalog-stock` / `--sync-catalog-sold` chỉ làm khi bật, có backup vào `pb_fixture_backups`.
- Không xoá cứng lô cũ: copy sang `inventory_batches_archive` rồi mới gỡ; chỉ đụng lô có `data_source` bắt đầu bằng `auto_generated_`.
- **Không ghi DB trong Task 1–6.** Task 7 (ghi Atlas, đổi `.env`, restart container) chỉ chạy khi người dùng duyệt.
- Không log URI/secret. Không commit dữ liệu sinh ra (report đặt ở `.runtime/pb-fixture/`).
- Trước khi sửa symbol có sẵn: chạy GitNexus `impact` (MCP hoặc `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`); nếu index chưa có thì ghi rõ và xác nhận bằng grep.

## Nguyên tắc "giống thật, không trùng lặp"

1. **Lô hàng:** HSD = NSX + hạn dùng theo danh mục (kem chống nắng 36, sữa rửa mặt 36, tẩy trang 36, mặt nạ giấy 36, kem dưỡng 30, serum 24–30 (vitamin C 24), son dưỡng 36, son/kem nền 36, mascara 24, sữa tắm/dưỡng thể 36, dầu gội/xả 36 tháng). `received_on` = NSX + 20–120 ngày và ≤ T. Mã lô theo 3 kiểu xoay theo thương hiệu: `2511A07` (YYMM+chữ+số), `L2512203` (L+YYMMDD+ca), `LRP5341` (3 ký tự brand + Y + ngày Julian). Không có 2 lô cùng (sản phẩm, kho, mã).
2. **Số lượng:** `initial = sold + target_available + damaged(0–2)`; `sold` lấy từ đơn hàng giả trừ FEFO, nên tồn kho khớp với lịch sử bán. Không dùng số tròn cố định.
3. **Kho:** `KHO-HCM-01` ~60%, `KHO-HN-01` ~30%, `KHO-DN-01` ~10%; đơn của khách miền nào trừ lô kho miền đó.
4. **Khách hàng (persona):** 160 khách, tên ghép họ/đệm/tên tiếng Việt, năm sinh 1985–2006, tỉnh khớp kho. Lưu `fixture_persona = {skin_type: <ten_loai_da>, concerns: [...], budget_tier: low|mid|high, favorite_brands: [ma_thuong_hieu x1–2]}`. Phân bố da: dầu/hỗn hợp dầu 35%, khô 20%, hỗn hợp 20%, thường 15%, nhạy cảm 10%.
5. **Chọn sản phẩm khi mua:** xác suất ∝ `popularity_prior` (từ dữ liệu crawl thật: `so_luong_da_ban^0.8 × (diem_danh_gia/5)`) × độ hợp da (`san_pham.loai_da` với persona; "Mọi loại da" luôn hợp) × độ hợp ngân sách × hệ số thương hiệu yêu thích ×1,8.
6. **Giỏ hàng:** 1–4 dòng; mua theo routine (mua sữa rửa mặt thì 35% kèm toner/kem dưỡng; serum thì 25% kèm kem chống nắng); mặt nạ giấy số lượng 3–10; khách đã mua kem chống nắng sẽ mua lại sau 40–70 ngày.
7. **Thời gian:** 60 ngày (T−60…T−1). Cuối tuần +25%, ngày 1–5 và 15 hằng tháng +40%, 1 ngày flash sale ×2,5 (giảm 10–20% `don_gia`). Giờ đặt theo phân bố thật (đỉnh 11–13h và 20–23h).
8. **Trạng thái đúng theo tuổi đơn:** đơn ≥5 ngày thì 91% `Hoàn thành`, 7% `Đã hủy`, 2% hoàn trả (chỉ khi `--with-dirty`). Đơn 0–4 ngày thì có thêm `Đang giao`, `Đã xác nhận`, `Chờ xử lý`. COD 70%, `bank_transfer_qr` 30% (QR mà `Hoàn thành` thì `status_thanh_toan="Đã thanh toán"`). `ngay_hoan_thanh = ngay_dat + 1–4 ngày` (< T). `phi_van_chuyen = 30000`; tổng tiền tính đúng công thức `HoaDon::taoDonHang` (dòng 556–600).
9. **Phễu phiên:** 250–600 phiên/ngày trong 45 ngày (T−45…T−1). Mỗi phiên xem 1–6 sp theo persona; thêm giỏ 8–15% lượt xem; khoảng 25% phiên có giỏ sẽ đặt đơn. Đơn có `analytics_sid` (85%) đúng bằng `session_hash` của phiên đó và đặt **sau** lượt xem đầu. `event_id = sha256("{type}|{sid}|{pid}|{YYYY-MM-DD}")`, `schema_version: 1` (giống `ProductEventTracker::record`). Ngày nào cũng có ít nhất 1 `product_view` (tránh `tracking_gap`).

## Ma trận trường hợp cần có dữ liệu (T = 2026-10-14 làm ví dụ)

| Nhóm | Trường hợp | Dữ liệu được thiết kế cho nó |
|---|---|---|
| Hạn 17–19 | trong khoảng [2028-03-14, 2028-05-14] | ≥12 sp/danh mục trọng tâm; có lô **đúng biên** 2028-03-14 và 2028-05-14 (phải lọt) |
| | sát biên ngoài | 2028-03-13 và 2028-05-15 (không lọt) |
| | sai trạng thái trong khoảng | lô `quarantined`, lô `depleted` (qty 0), lô `available` qty 0 → không lọt |
| | một sp nhiều lô | 1 lô trong khoảng + 1 lô ngoài khoảng → chỉ lô trong khoảng là bằng chứng |
| Hạn ≤18 | (T, 2028-04-14] | lô đúng 2028-04-14 (lọt), 2028-04-15 (không lọt), lô cận date 1–5 tháng |
| Hết hạn | | `expired` (T−1…T−90 ngày) + 1 lô `available` nhưng ngày đã qua (dữ liệu bẩn kiểu cũ) → không bao giờ lọt |
| Ví dụ của bạn | A: 200 sp, NSX 20/12/2025, HSD 20/05/2028 · B: 100 sp, NSX 01/01/2026, HSD 01/06/2028 · C: 20 sp, HSD T+20 tháng | gắn vào 3 sp thật, đặt `anchor: A/B/C`. Với T=14/10/2026 thì cả 3 **nằm ngoài** 17–19 và ngoài ≤18 (case âm); A, B lọt "Tồn kho nhiều" |
| Tồn kho | >100 | tổng tồn khả dụng 101 (biên lọt), 100 (biên không lọt), 140–900 |
| | ≤10 | 1, 3, 7, 10 (biên lọt), 11 (không lọt) |
| | Hết hàng | mọi lô `depleted`/0; sp chỉ còn lô hết hạn (tồn hiệu lực = 0) |
| Kết hợp | 17–19 + >100 · 17–19 + ≤10 | ≥3 sp mỗi tổ hợp ở kem chống nắng |
| | 17–19 + Hết hàng | thiết kế mâu thuẫn → kỳ vọng `EMPTY_RESULTS` (ghi nhận, không bù dữ liệu) |
| Phạm vi | Chỉ kho `KHO-DN-01` | ≥3 sp cho mỗi điều kiện hạn/tồn; có sp chỉ có lô ở kho khác (phải bị loại) |
| | Chỉ một lô | 1 mã lô cố định ghi trong manifest (`pb_fixture_manifest.scope_batch_code`) |
| Bán hàng 30/7 ngày | Bán chạy | phân bố tự nhiên theo popularity |
| | Tăng trưởng 7 ngày | 6 sp: 7 ngày gần nhất ≥1,8× 7 ngày trước (trước > 0); 3 sp giảm (không lọt) |
| | Xem nhiều, chưa mua | 6 sp: 120–260 phiên xem trong 30 ngày, 0 phiên chuyển đổi (có thể có thêm giỏ) |
| | Doanh thu cao, chuyển đổi thấp | trong 1 danh mục có ≥8 sp đủ ≥100 phiên xem; 3 sp giá cao: xem 400+, chuyển đổi 2–4%; còn lại 8–15% |
| | Thêm giỏ nhiều | ≥8 sp có 25–80 phiên thêm giỏ |
| | Giảm giá, bán chậm | dùng `gia_thi_truong > gia_ban` thật; ≥8 sp đủ số liệu trong danh mục |
| | Toàn kỳ | `so_luong_da_ban` của catalog (đã có sẵn, không sửa) |
| Thương hiệu | ưu tiên brand X | ≥5 sp của 3 brand lớn (Cocoon, La Roche-Posay, L'Oreal) trong mỗi danh mục trọng tâm, có lô và có số bán |
| | Mỗi brand ≤2 | ≥3 sp cùng brand cùng thỏa điều kiện |
| Danh mục | 27 nhánh của Builder | danh mục da mặt có sẵn; các nhánh Trang điểm/Cơ thể/Tóc khôi phục ~20–25 sp thật mỗi nhánh lá từ `san_pham_backup_6377` |

Danh mục trọng tâm (được thiết kế dày): kem chống nắng, sữa rửa mặt, serum, mặt nạ giấy, dầu gội (đã khôi phục). Mọi sản phẩm còn lại vẫn có 1–3 lô "nền" phân bố HSD 3–34 tháng, nên **không sản phẩm active nào thiếu sổ lô**.

## Review Focus

1. **Ngày lệch múi giờ:** `expires_on` lưu UTCDateTime nửa đêm giờ VN (17:00Z hôm trước, như `InventoryBatch::parseDate`). Engine đọc bằng `local_date`. Test: lô biên 2028-03-14 phải ra đúng `2028-03-14` và lọt 17–19.
2. **Chạy lại vào ngày khác:** chạy `apply` sau 1 tuần thì dải 17–19 tháng dịch đi. `apply` phải từ chối nếu dataset đã có với `as_of` khác (gợi ý `reset` rồi `apply`). Snapshot các ngày sau T sẽ có `tracking_gap` nếu không có tracking thật; ghi rõ trong report.
3. **Lẫn dữ liệu cũ:** nếu chưa `archive-legacy` thì `verify` phải FAIL rõ ràng ("còn N lô auto_generated_* đang active"), không im lặng PASS.
4. **ID trùng với đơn thật mới phát sinh:** `apply` kiểm tra không có `ma_hoa_don`/`id`/`ma_kh` nào trong dải đã bị chiếm; nếu có thì dừng.
5. **`reset` sót hoặc xoá nhầm:** `reset` chỉ xoá theo `test_dataset`, khôi phục `pb_fixture_backups`, và in số lượng trước/sau từng collection; test bằng fakes rằng document không có tag không bị đụng.

---

### Task 1: Đọc tên thương hiệu từ `ma_thuong_hieu` trong catalog của Agent

**Files:**
- Modify: `ai-idol-service/agents/query_sources.py` (`load_catalog`)
- Test: `ai-idol-service/tests/test_query_sources_brand.py`

**Interfaces:**
- Produces: `load_catalog(db) -> list[dict]`. Mỗi row có `ten_thuong_hieu` khi `ma_thuong_hieu` map được trong `db.thuong_hieu`. Row đã có `ten_thuong_hieu`/`thuong_hieu` thì giữ nguyên.

- [ ] **Step 1:** Chạy GitNexus impact `load_catalog` (upstream), ghi callers/risk vào báo cáo.
- [ ] **Step 2: Viết test fail**: `test_brand_name_resolved_from_ma_thuong_hieu` (FakeDB: `san_pham` [{ma_san_pham: 1, ma_thuong_hieu: 7, ...}], `thuong_hieu` [{ma_thuong_hieu: 7, ten_thuong_hieu: "La Roche-Posay"}] → `rows[0]["ten_thuong_hieu"] == "La Roche-Posay"`). `test_existing_brand_name_kept`. `test_unknown_brand_id_leaves_brand_empty` (không có key, không lỗi).
- [ ] **Step 3:** Chạy `python -m unittest tests.test_query_sources_brand -v` trong `skinsyntax-ai-idol-worker` (mount source như mục 30–31 của update.md). Kỳ vọng FAIL.
- [ ] **Step 4:** Thêm `"ma_thuong_hieu": 1` vào projection; đọc một lần `db.thuong_hieu.find({}, {"ma_thuong_hieu":1,"ten_thuong_hieu":1})` thành dict (key bằng `str(int(...))`), gán `ten_thuong_hieu` khi thiếu.
- [ ] **Step 5:** Chạy lại test và toàn bộ `python -m unittest discover -s tests -q`. Kỳ vọng PASS, không giảm số test cũ (233).

### Task 2: Khung fixture, cấu hình và chọn sản phẩm

**Files:**
- Create: `ai-idol-service/fixtures/__init__.py`, `ai-idol-service/fixtures/prompt_builder/__init__.py`, `ai-idol-service/fixtures/prompt_builder/config.py`, `ai-idol-service/fixtures/prompt_builder/catalog_pick.py`
- Test: `ai-idol-service/tests/test_pb_fixture_catalog.py`

**Interfaces:**
- Produces (`config.py`): `DATASET_TAG = "pb-fixture-v1"`, `DEFAULT_SEED = 20261014`, `WAREHOUSES = {"KHO-HCM-01": .6, "KHO-HN-01": .3, "KHO-DN-01": .1}`, `ID_BASE = {"hoa_don": 500001, "chi_tiet_hoa_don": 500001, "khach_hang": 900001}`, `SHELF_LIFE_MONTHS: dict[str, int]` (key là category của `command_query.CATEGORY_PATHS`/`CATEGORY_ALIASES`), `FOCUS_CATEGORIES = ("sunscreen", "cleanser", "serum", "sheet_mask", "shampoo")`, `FOCUS_BRANDS = ("Cocoon", "La Roche-Posay", "L'Oreal")`, `tag(doc: dict) -> dict` (gắn 3 trường tag).
- Produces (`catalog_pick.py`): `classify_product(row) -> str | None` (category cụ thể nhất, dùng `command_query._category_matches` trên các key theo thứ tự cụ thể → chung); `pick_restore_candidates(backup_rows, live_ids, per_leaf=22, rng) -> list[dict]`; `popularity_prior(row) -> float`.

- [ ] **Step 1: Test fail**: `classify_product` với `danh_muc_day_du` mẫu thật ("... -> Chống Nắng Da Mặt" → `sunscreen`, "... -> Mặt Nạ -> Mặt Nạ Giấy" → `sheet_mask`, "... Chăm Sóc Cơ Thể -> Sữa Tắm" → `body_wash`). `pick_restore_candidates` không chọn mã đã có trong live, tối đa `per_leaf` mỗi lá, chỉ chọn `gia_ban > 0` có `ten_san_pham`, cùng seed thì cùng kết quả. `popularity_prior` > 0, đơn điệu theo `so_luong_da_ban`.
- [ ] **Step 2:** Chạy test, kỳ vọng FAIL. **Step 3:** Implement. **Step 4:** Chạy test, kỳ vọng PASS.

### Task 3: Sinh lô hàng theo ma trận hạn dùng / tồn kho / phạm vi

**Files:**
- Create: `ai-idol-service/fixtures/prompt_builder/batches.py`
- Test: `ai-idol-service/tests/test_pb_fixture_batches.py`

**Interfaces:**
- Consumes: `config.*`, `catalog_pick.classify_product`, `command_query.add_months`.
- Produces: `generate_batches(products: list[dict], today: date, rng, sold_by_product: dict[str, list[tuple[datetime, str, int]]] | None = None) -> tuple[list[dict], dict]`. Trả `(batch_docs, manifest)`. Mỗi batch doc có đủ trường như `InventoryBatch::seedDiverseDemoBatches` (`product_id` str, `batch_code`, `warehouse_id`, `manufactured_on`/`expires_on`/`received_on` là `datetime` UTC = nửa đêm VN, `initial/available/reserved/sold/damaged_quantity`, `status`, `case_profile`, `anchor`) + tag. `manifest` gồm `{"as_of", "scope_warehouse": "KHO-DN-01", "scope_batch_code", "anchors": {A,B,C: product_id}, "profiles": {profile: [product_id]}}`.
- `allocate_fefo(batches_of_product, sales) -> list[dict]` trả movement `sale` (một movement cho mỗi dòng đơn, `occurred_at = ngay_dat`) và cập nhật `sold_quantity`.

- [ ] **Step 1: Test fail** (dùng `today=date(2026,10,14)`, seed cố định):
  - `test_boundary_lots_exist`: có lô HSD local đúng `2028-03-14`, `2028-05-14`, `2028-03-13`, `2028-05-15`, `2028-04-14`, `2028-04-15`.
  - `test_anchor_lots_match_user_examples`: A (200, NSX 2025-12-20, HSD 2028-05-20), B (100, NSX 2026-01-01, HSD 2028-06-01), C (20, HSD = add_months(T, 20)).
  - `test_shelf_life_consistent`: với mọi lô `expires_on − manufactured_on` khớp `SHELF_LIFE_MONTHS[category]` (±0 ngày), `received_on ≤ T`.
  - `test_every_active_product_has_ledger`: mọi product đầu vào có ≥1 lô.
  - `test_no_duplicate_identity_and_varied_quantities`: không trùng (product, warehouse, batch_code); số giá trị `initial_quantity` khác nhau ≥ 40% số lô.
  - `test_stock_boundaries`: có sản phẩm tổng tồn hiệu lực đúng 1, 10, 11, 100, 101 và 0.
  - `test_fefo_never_negative`: sau `allocate_fefo`, `available_quantity ≥ 0` và `initial = available + sold + damaged + reserved`.
- [ ] **Step 2–4:** FAIL → implement → PASS.

### Task 4: Persona, phiên xem/thêm giỏ, đơn hàng

**Files:**
- Create: `ai-idol-service/fixtures/prompt_builder/commerce.py`, `ai-idol-service/fixtures/prompt_builder/names_vi.py` (danh sách họ/đệm/tên, tỉnh theo kho)
- Test: `ai-idol-service/tests/test_pb_fixture_commerce.py`

**Interfaces:**
- Consumes: `config.*`, `catalog_pick.popularity_prior`, `product_metrics.is_revenue_order`/`compute_snapshot_docs`.
- Produces: `generate_customers(n=160, rng) -> list[dict]`; `generate_commerce(products, customers, today, rng, engineered: dict) -> dict` trả `{"events": [...], "orders": [...], "lines": [...], "sales_by_product": {pid: [(ngay_dat, warehouse_id, qty)]}}`. `engineered` gồm danh sách product_id cho `growth`, `views_no_orders`, `revenue_low_conversion`, `cart` (chọn bằng hàm `pick_engineered(products, rng)` trong cùng file).

- [ ] **Step 1: Test fail** (chạy `compute_snapshot_docs` thật trên output, `tracking_started_on = T−45`, `retention_days=120`, `as_of=T`):
  - `test_orders_internally_consistent`: `tong_tien == tam_tinh − so_tien_giam − tien_giam_diem + phi_van_chuyen`; `tam_tinh == Σ so_luong×don_gia`; `ngay_hoan_thanh > ngay_dat` và `< T`; QR `Hoàn thành` ⇒ `Đã thanh toán`.
  - `test_status_matches_order_age`: đơn ≥5 ngày không ở `Chờ xử lý`/`Đang giao`.
  - `test_order_sid_follows_view`: mọi đơn có `analytics_sid` đều có `product_view` cùng sid, cùng sp, sớm hơn.
  - `test_skin_and_budget_affinity`: tỉ lệ dòng đơn hợp da với persona ≥ 75%.
  - `test_sessions_covered`: snapshot 7 và 30 ngày có `coverage.sessions == True` (không `tracking_gap`).
  - `test_engineered_cases_measurable`: các sp `views_no_orders` có `viewed_sessions ≥ 120` và `converted_sessions == 0`; sp `growth` có `units_sold ≥ 1.8 × previous_units_sold > 0` (cửa sổ 7); ≥8 sp `cart_sessions ≥ 25`.
- [ ] **Step 2–4:** FAIL → implement → PASS.

### Task 5: Ma trận case của Builder + kiểm chứng offline bằng engine thật

**Files:**
- Create: `ai-idol-service/fixtures/prompt_builder/cases.py`
- Test: `ai-idol-service/tests/test_pb_fixture_cases.py`

**Interfaces:**
- Produces: `compose_brief(sel: dict) -> str`, port đúng `buildAgentPrompt()` trong `frontend/public/assets/js/ai-idol-studio.js:131-165`. Dùng đúng `data-fragment` trong `frontend/views/admin/ai_idol/index.php:92-98,109-116` và `phrase` của `agentCategoryOptions` trong JS. `sel` có các key `category`, `expiry`, `stock`, `sales`, `warehouse_mode`, `scope_code`, `quantity`, `brand`, `brand_cap`, `enough_stock`.
- `CASES: list[Case]`, với `Case = dataclass(name, sel, expect_status, min_selected, check: Callable[[dict, dict], list[str]])`. Có ít nhất các case trong bảng ma trận ở trên. Danh mục: 5 danh mục trọng tâm × (3 hạn × 4 tồn) + phạm vi kho/lô + 8 bán hàng × kem chống nắng + brand/cap/quantity + mỗi nhánh trong 27 danh mục với "Tìm" thuần (≥1 kết quả).
- `run_offline(dataset: dict, catalog: list[dict], today) -> list[dict]`: với mỗi case chạy `build_query_plan(compose_brief(sel), today=today)` → `execute_query(plan, catalog, batches=..., snapshots=compute_snapshot_docs(...), brief=...)`. Kết quả mỗi case `{"case", "brief", "status", "selected", "matched", "complete", "missing_data", "failures": [...]}`.
- Các `check` dựa trên oracle độc lập: duyệt lô trực tiếp (không gọi lại engine), ví dụ mọi `evidence.batches[].expires_on` nằm trong `[add_months(T,17), add_months(T,19)]`, không lô nào có `status != available`.

- [ ] **Step 1: Test fail**: `test_compose_brief_matches_js_examples` (3 câu cố định lấy từ trình duyệt; ví dụ chọn Kem chống nắng + 17–19 + Top 10 → `"Lọc 10 kem chống nắng còn hạn sử dụng từ 17 đến 19 tháng."`). `test_all_cases_pass_offline` (sinh dataset seed mặc định, `today=2026-10-14`: mọi case `failures == []`; mọi case hạn/tồn/phạm vi có `complete == True`; case 17–19 + Hết hàng là `EMPTY_RESULTS`). `test_cases_pass_on_other_day` (`today=2027-02-03`, sinh lại).
- [ ] **Step 2–4:** FAIL → implement → PASS. Nếu một case FAIL do engine (không phải do dữ liệu) thì **không sửa engine**: đánh dấu `known_engine_gap` kèm lý do và báo lại.

### Task 6: Lớp ghi DB + CLI (chưa chạy trên Atlas)

**Files:**
- Create: `ai-idol-service/fixtures/prompt_builder/store.py`, `ai-idol-service/fixtures/prompt_builder/__main__.py`
- Test: `ai-idol-service/tests/test_pb_fixture_store.py` (FakeDB từ `tests/fakes.py`, bổ sung method còn thiếu nếu cần)

**Interfaces:**
- CLI: `python -m fixtures.prompt_builder <cmd> [--seed N] [--dry-run] [--with-dirty] [--sync-catalog-stock] [--sync-catalog-sold]`
  - `plan`: in số document sẽ tạo theo collection + kết quả `run_offline` (không ghi).
  - `archive-legacy`: copy lô `data_source ^auto_generated_` sang `inventory_batches_archive` (thêm `archived_at`, `archived_by_dataset`), xác nhận số bản copy bằng số gốc rồi mới `delete_many` bản gốc. Movement cũ giữ nguyên.
  - `restore-categories`: chèn ứng viên `pick_restore_candidates` từ `san_pham_backup_6377` vào `san_pham` (giữ nguyên field gốc, `trang_thai="active"`, thêm tag + `restored_from`).
  - `apply`: preflight (dải ID trống, chưa có dataset với `as_of` khác, không còn lô `auto_generated_*` active) → ghi `khach_hang`, `product_events`, `hoa_don`, `chi_tiet_hoa_don`, `inventory_batches`, `inventory_movements` (receipt + sale), `pb_fixture_manifest`. Ghi theo lô 500 bằng `insert_many(ordered=False)`.
  - `reset`: xoá mọi document có `test_dataset` ở các collection trên + `san_pham` khôi phục, khôi phục `pb_fixture_backups`, xoá `product_metrics_snapshots`/`product_metrics_runs` có `as_of ≥ manifest.as_of − 30` để rebuild. In số lượng trước/sau.
  - `restore-legacy`: chép ngược từ archive (để hoàn tác).
  - `verify`: chạy `CASES` online (`load_catalog` + `load_query_sources` + snapshot thật trong DB) và ghi `.runtime/pb-fixture/report-<as_of>.md` + `.json`.
- [ ] **Step 1: Test fail**: `test_apply_tags_everything`, `test_apply_refuses_when_id_range_taken`, `test_apply_refuses_when_legacy_active`, `test_reset_only_removes_tagged` (doc không tag vẫn còn nguyên), `test_archive_then_restore_roundtrip`, `test_dry_run_writes_nothing`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** `python -m fixtures.prompt_builder plan` trong container, chỉ đọc Atlas. Dán output (số lượng + bảng case) vào báo cáo.
- [ ] **Step 6:** `python -m unittest discover -s tests -q`, `py_compile` các file mới, `git diff --check`. **Không commit** (người dùng chưa yêu cầu).

### Task 7 (ĐÃ DUYỆT 14/10/2026 — ghi Atlas, có tag + reset; lưu trữ lô cũ; khôi phục danh mục từ backup): Nạp dữ liệu và kiểm chứng thật

- [ ] `archive-legacy` → `restore-categories` → `apply` (đều chạy `--dry-run` trước).
- [ ] Đặt `AI_IDOL_TRACKING_STARTED_ON=<T−45>` trong `.env`, restart `ai-idol-service`, `ai-idol-worker`, `ai-idol-control-worker`, `ai-idol-broadcast-worker`, `ai-idol-beat` (rebuild image nếu code Python Task 1 chưa có trong image).
- [ ] `python -m core.product_metrics backfill --days 30` rồi `build` cho T.
- [ ] Xoá cache recommender: `SanPham::clearSimpleRecommenderCache()` + `php backend/tests/rebuild_production_tfidf_cache.php` (do có sản phẩm khôi phục).
- [ ] `python -m fixtures.prompt_builder verify` → mọi case PASS.
- [ ] Playwright E2E 6 case trên `index.php?r=admin_ai_idol` (17–19 kem chống nắng; ≤10; kho KHO-DN-01; xem nhiều chưa mua; tăng trưởng 7 ngày; brand cap). Theo `.claude/skills/e2e/SKILL.md`.
- [ ] Ghi mục 32 vào `update.md` (lệnh, số lượng, kết quả, cách reset).

## Ảnh hưởng tới recommendation

- Recommender hiện tại không đọc `hoa_don`, `chi_tiet_hoa_don`, `product_events`. Dữ liệu giả **không** đổi kết quả gợi ý, trừ 3 việc sau: (a) sản phẩm khôi phục từ backup trở thành ứng viên mới; (b) bật `--sync-catalog-sold` làm đổi mục "Bán chạy"; (c) bật `--sync-catalog-stock` làm sản phẩm hết hàng bị loại khỏi gợi ý.
- Với trang gợi ý LLM "được nhiều người mua" sau này: đọc đơn qua `is_revenue_order`, mặc định lọc `test_dataset: {$exists: false}`, chỉ bật dữ liệu fixture ở chế độ demo. Không đánh giá chất lượng recommender trên dữ liệu fixture, vì mô hình sẽ chỉ học lại luật của bộ sinh (vòng lặp).

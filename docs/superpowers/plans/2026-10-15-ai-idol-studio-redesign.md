# AI Idol Studio hai chế độ — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Thiết kế lại `index.php?r=admin_ai_idol` thành hai chế độ rõ ràng: Thủ công (Wizard 4 bước, đủ mọi lựa chọn) và Tự động (một prompt → autopilot đến hết, trừ cho phép kênh ngoài), cùng một bảng Hoạt động chung.

**Architecture:**
- Python `ai-idol-service` thêm: phong cách dẫn chuyện thật sự vào kịch bản; API truy vấn sản phẩm có cấu trúc dùng chung `execute_query`; chế độ `autopilot` cho Agent run (quy tắc thuần trong `agents/autopilot.py`, ghi `decisions[]`); segment `SKIPPED` khi kịch bản trượt kiểm duyệt; endpoint cho phép kênh ngoài.
- PHP chỉ thêm 2 route proxy.
- Frontend tách `ai-idol-studio.js` (1343 dòng) thành 5 file: logic thuần (có unit test Node), core, manual, auto, activity.

**Tech Stack:** Python 3.10 (Flask, Celery, pymongo, unittest), PHP 8.2 MVC, JS/CSS thuần (không bundler), Node 22 `node --test`, Playwright (Node, qua NODE_PATH).

**Spec:** `docs/superpowers/specs/2026-10-15-ai-idol-studio-redesign-design.md`. Đọc spec trước. "§5.1" trong plan trỏ về mục của spec. Plan này **thay** spec ở 3 điểm, ghi rõ tại Task 2, 3 và 4.

## Cập nhật 15/10 chiều — tích hợp nhánh `rcm_index` (đọc trước)

Spec §11 (D9–D14) là căn cứ của phần này.

**Trạng thái:**

| Task | Trạng thái |
|---|---|
| 1, 2, 3 | Xong, đã qua review, có trong commit `9c04f59` |
| 4 | Code xong (`9c04f59`). Còn 2 lỗi Important chưa sửa (xem "Vòng sửa" trong Task 4) |
| 0, 5, 6, 6b, 7–12, 12b, 13 | Chưa làm |

**Thứ tự mới:** Task 0 → Task 4 (vòng sửa) → 5 → 6 → 6b → 7 → 8 → 9 → 10 → 11 → 12 → 12b → 13.

**Thay đổi so với bản cũ:**
- Thêm Task 0: merge `origin/rcm_index`, giữ nguyên AI Idol Studio.
- Thêm Task 6b: tự xuất bản vào `ai_product_videos`.
- Thêm Task 12b: trang quản lý xuất bản và voucher riêng.
- Task 4, 7, 9, 12, 13 có bổ sung, đánh dấu **[rcm]**.

**Nhóm file Studio — bất khả xâm phạm khi merge** (luôn giữ bản `syna`, bỏ thay đổi của `rcm_index`):
- `frontend/views/admin/ai_idol/**`
- `frontend/public/assets/js/ai-idol-studio.js`, `frontend/public/assets/css/ai-idol-studio.css`
- `ai-idol-service/**`, `ai-idol-agent-service/**`
- `frontend/public/ai-idol-mascot-3d/**`, `scripts/ai-idol-mascot/**`, `scripts/start-ai-idol-syna.ps1`

---

### Task 0: Merge `origin/rcm_index`, giữ nguyên AI Idol Studio

**Files:** 13 file conflict, các file Studio ở trên, và các file `syna` còn kẹt trong `stash@{0}`.

- [ ] **Step 1: Chuẩn bị (an toàn).**
  - Cây làm việc phải sạch; nếu không, dừng lại hỏi.
  - `git fetch origin rcm_index`
  - Tạo nhánh dự phòng: `git branch backup/syna-before-rcm-merge`
- [ ] **Step 2:** `git merge --no-commit --no-ff origin/rcm_index`.
- [ ] **Step 3: Giải conflict theo bảng.** Mọi file Studio, kể cả file tự merge không báo conflict, đều lấy bản `syna`: `git checkout --ours -- <file>` cho từng file trong nhóm Studio mà `rcm_index` đã đổi. Danh sách đó lấy bằng `git diff --name-only $(git merge-base HEAD origin/rcm_index) origin/rcm_index` giao với nhóm Studio.

| File | Cách giải |
|---|---|
| Nhóm Studio (gồm `campaigns.py`, `ai-idol-studio.js`, view Studio, `render.js`, `scene.js`, `syna-render-server.cjs`, `start-ai-idol-syna.ps1`) | Bản `syna` (ours) |
| `AdminAiIdolController.php` | Giữ cả hai: mọi method của `syna`, cộng `getCsrfToken`, `verifyCsrf` và 7 method mới của `rcm_index` (không đổi method có sẵn của `syna`) |
| `routes/ai_idol.php`, `backend/public/index.php`, `frontend/public/index.php`, `index.php` (gốc) | Hợp nhất danh sách route/case của cả hai |
| `.env.example` | Giữ cả hai khối (AI Idol của `syna` và Valkey của `rcm_index`) |
| `HomeController.php`, `SanPham.php`, `GoiYContentBased.php`, `admin/layouts/header.php` | Không thuộc Studio: giữ tính năng của cả HEAD và `rcm_index`. Hunk nào mâu thuẫn thật thì dừng lại hỏi người dùng |

- [ ] **Step 4: Khôi phục sửa đổi riêng của `syna` còn trong stash.**
  - Với từng file trong `stash@{0}` khác HEAD, xem `git diff $(git rev-parse stash@{0}^1) stash@{0} -- <file>` và phân loại từng hunk thành 2 nhóm:
    - (a) sửa đổi riêng của `syna`;
    - (b) bản cũ của file `rcm_index` mà merge đã thay.
  - Áp lại tay nhóm (a): `docker-compose.yml` (service `ai-idol-control-worker`, chia hàng đợi, mount nhạc, Chroma), `LiveController.php` và `SanPhamController.php` (`ProductEventTracker`), `.gitignore`.
  - **Không** áp phần gắn video trang sản phẩm cũ (`partials/product_video.php` trong `chitiet.php`, nạp `ProductVideoClient` trong `SanPhamController.php`), theo D10.
  - Ghi bảng phân loại vào report.
- [ ] **Step 5: Kiểm tra.**
  - `php -l` mọi file PHP đã giải conflict.
  - `php backend/tests/admin_usecase_contract_test.php`.
  - Toàn bộ suite Python (mốc 608 OK).
  - `node --check` các file JS bị đụng.
  - `composer install` trong container `php-backend` (vì `rcm_index` thêm `predis/predis`).
  - Không còn dấu `<<<<<<<` nào: `git diff --check`, `rg "^<<<<<<<"`.
- [ ] **Step 6: Không commit.** Báo cáo `git status` và bảng giải conflict cho người dùng. Người dùng tự commit merge, hoặc yêu cầu rõ thì mới commit.

**Lưu ý:** việc xóa nhạc mp3 và `real-videos.json` khỏi lịch sử git (đã push lên `origin/syna_backup_15_15`) là quyết định của người dùng; task này chỉ khôi phục `.gitignore`.

---

## Global Constraints

- **[rcm] CSRF:** mọi route POST mới gọi `verifyCsrf($input)` có sẵn trong `AdminAiIdolController`. JS gửi header `X-CSRF-Token: window.AI_IDOL_CSRF`. View Studio phải in `window.AI_IDOL_CSRF` (lấy từ `AdminAiIdolController::getCsrfToken()`).
- **[rcm] Studio bất khả xâm phạm:** không task nào được đưa code của `rcm_index` vào nhóm file Studio. Ngược lại, plan không sửa tính năng flash sale, voucher hay recommender.
- **Ngôn ngữ:** mọi chữ hiển thị và thông điệp lỗi bằng tiếng Việt. Tên biến, hàm, khóa JSON bằng tiếng Anh.
- **GitNexus trước khi sửa** (AGENTS.md):
  - Trước khi sửa mỗi hàm hoặc class có sẵn, chạy `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`.
  - Báo callers và risk trong report của task. HIGH/CRITICAL thì dừng lại hỏi. `UNKNOWN` thì xác nhận bằng `rg`.
- **Không commit.** Bước "Checkpoint" cuối mỗi task chỉ chạy `git diff --check` trên các file của task. Worktree đang có rất nhiều thay đổi chưa commit của người dùng: **không** hoàn tác, format lại hay đụng vào file ngoài danh sách của task.
- **Lệnh test Python** (Git Bash, đứng ở gốc repo). Mã nguồn được mount vào image dựng sẵn:
  - `IDOL_TEST <module>` = `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)/ai-idol-service:/app" -w /app --entrypoint python skinsyntax-ai-idol:local -m unittest <module> -v`
  - Toàn bộ suite: `IDOL_TEST discover -s tests -t .` (mốc trước khi làm: 448 test, OK).
  - Test viết theo `unittest.TestCase`, dùng `tests/fakes.py` (`FakeDb`) và `patch.object(<module>, "get_db", return_value=db)` như `tests/test_broadcast_lifecycle.py`; Agent run dùng mẫu `AgentRunHarness` trong `tests/test_agent.py`.
- **PHP:** `php -l <file>`. Test PHP là script độc lập: `php <file>`, exit 0 là PASS. App chạy ở `http://localhost:8080`; PHP và `frontend/public` được bind-mount nên sửa là có hiệu lực ngay. Code Python được **đóng vào image** `skinsyntax-ai-idol:local` (không bind-mount), nên dịch vụ đang chạy chỉ nhận code mới sau khi build lại image ở Task 13.
- **JS:** `node --check <file>` cho mọi file JS; unit test `node --test frontend/public/assets/js/tests/`.
- **Múi giờ:** `Asia/Ho_Chi_Minh` (`config.TIMEZONE`). Giá trị `datetime-local` không có múi giờ và được hiểu theo múi này.
- **Giá trị `purpose`:** `product_video` (mặc định), `live_and_product`, `live_only`.
- **Kênh:** cục bộ `internal`, `preview`; ngoài `youtube`, `facebook`.
- **Khóa phong cách** (thứ tự hiển thị cố định) và nhãn, lấy nguyên văn từ spec §5.2: `cheerful` "Tươi vui, dễ thương", `gentle` "Tư vấn nhẹ nhàng", `expert` "Chuyên gia khoa học", `closing` "Tập trung chốt sale" (nói giá), `concise` "Ngắn gọn, nhanh", `detailed` "Giải thích chi tiết", `friendly` "Thân thiện như bạn bè", `ingredients` "Nhấn mạnh thành phần", `pricing` "Nhấn mạnh giá & ưu đãi" (nói giá). `concise` và `detailed` loại trừ nhau.
- **Cấu hình autopilot:** `AI_IDOL_AUTOPILOT_DEFAULT_PRODUCTS=5`, `AI_IDOL_AUTOPILOT_MAX_PRODUCTS=10`, `AI_IDOL_AUTOPILOT_SLOTS=12:00,20:00`.
- **Decision:** `{"code": str, "level": "done"|"adjusted"|"waiting"|"stopped", "message": str, "at": ISO-8601 UTC}`. `message` tiếng Việt, đi qua `redact_secrets`.
- **Không lộ bí mật:** không log, không trả về khóa RTMP, chuỗi kết nối, cookie.
- **Không thêm thư viện** Python, PHP hay npm.

## Review Focus

1. **Run và chương trình cũ** không có `mode`, `decisions`, `pending_external_platforms`, `progress.skipped`: backend chạy như trước, bảng Hoạt động hiển thị bình thường, không lỗi JS. Test ở Task 4 (`test_classic_run_unchanged`) và Task 8 (`mergeActivity` với run thiếu trường mới).
2. **Bấm đúp** "Giao cho Syna", "Tạo chương trình", "Cho phép phát YouTube": không tạo 2 run hay 2 chương trình, không thêm kênh 2 lần. Test ở Task 6 (`test_approve_twice_is_noop`) và Task 11/10 (nút bị khóa trong lúc chờ, kiểm ở E2E Task 13).
3. **Đổi bộ lọc liên tục** khi request trước chưa về: kết quả cũ không được ghi đè kết quả mới. Test ở Task 8 (`createLatestGate`) và dùng gate đó ở Task 10.
4. **Giờ vàng gần nửa đêm và múi giờ:** earliest 23:50 thì slot là 12:00 hôm sau; `describeIntent` không bị lệch giờ theo múi giờ của trình duyệt. Test ở Task 3 (`test_slot_after_midnight`) và Task 8 (`describeIntent` dùng chuỗi `datetime-local`).
5. **HTML trong prompt, tên sản phẩm, message:** `<img src=x onerror=alert(1)>` hiển thị như chữ ở thẻ quyết định, bảng Hoạt động và danh sách sản phẩm. Test ở Task 13 (E2E gửi prompt chứa thẻ HTML và kiểm không có `img` sinh ra trong thẻ quyết định).

---

### Task 1: Phong cách dẫn chuyện vào kịch bản (sửa F1)

**Files:**
- Create: `ai-idol-service/core/narration.py`
- Modify: `ai-idol-service/core/campaigns.py` (`create_campaign`), `ai-idol-service/engines/content_agent.py` (`build_script_prompts`, `_live_only_prompts`, `_script_council_payload`)
- Test: `ai-idol-service/tests/test_narration.py`

**Interfaces:**
- Produces:
  - `NARRATION_STYLES: tuple[tuple[str, str, bool], ...]` gồm `(key, label, speaks_price)` theo thứ tự Global Constraints.
  - `PRICE_STYLES: frozenset[str] = {"closing", "pricing"}`
  - `normalize_styles(values, purpose: str) -> tuple[list[str], list[str]]` trả về `(styles, dropped)`. Bỏ khóa lạ và khóa trùng, giữ thứ tự xuất hiện. `purpose == "product_video"` thì khóa nói giá vào `dropped`. Có cả `concise` và `detailed` thì giữ cái đến trước, cái sau vào `dropped`.
  - `styles_from_text(text: str) -> list[str]`: so khớp alias của spec §5.2 trên `normalize_text(text)` (từ `agents.intent_parser`), trả theo thứ tự `NARRATION_STYLES`.
  - `style_instruction(styles: list[str]) -> str`: `""` nếu rỗng, ngược lại đúng một dòng `"- Phong cách dẫn chuyện: " + ", ".join(label.lower() của từng khóa) + "."`.
  - `create_campaign` lưu `configuration["narration_styles"]` đã chuẩn hóa (luôn là list, có thể rỗng).

- [ ] **Step 1: Viết test thất bại** `tests/test_narration.py`

```python
class NarrationTests(unittest.TestCase):
    def test_normalize_drops_price_styles_for_product_video(self):
        self.assertEqual(normalize_styles(["gentle", "pricing", "closing"], "product_video"),
                         (["gentle"], ["pricing", "closing"]))
    def test_normalize_keeps_price_styles_for_live(self):
        self.assertEqual(normalize_styles(["pricing"], "live_only")[0], ["pricing"])
    def test_normalize_unknown_and_duplicates(self):
        self.assertEqual(normalize_styles(["x", "gentle", "gentle"], "live_only"), (["gentle"], []))
    def test_concise_and_detailed_are_exclusive(self):
        self.assertEqual(normalize_styles(["detailed", "concise"], "live_only"), (["detailed"], ["concise"]))
    def test_styles_from_text(self):
        self.assertEqual(styles_from_text("Giọng vui tươi, chốt sale, nhấn mạnh thành phần"),
                         ["cheerful", "closing", "ingredients"])
    def test_style_instruction(self):
        self.assertEqual(style_instruction(["gentle", "ingredients"]),
                         "- Phong cách dẫn chuyện: tư vấn nhẹ nhàng, nhấn mạnh thành phần.")
        self.assertEqual(style_instruction([]), "")
    def test_script_prompt_contains_styles_for_every_purpose(self):
        for purpose in ("product_video", "live_and_product", "live_only"):
            _, user = build_script_prompts(SNAPSHOT, "", "Product Intro", {"narration_styles": ["gentle"]}, purpose)
            self.assertIn("Phong cách dẫn chuyện: tư vấn nhẹ nhàng", user)
            _, plain = build_script_prompts(SNAPSHOT, "", "Product Intro", {}, purpose)
            self.assertNotIn("Phong cách dẫn chuyện", plain)
    def test_council_payload_carries_styles(self):
        payload = _script_council_payload(SNAPSHOT, "", "Product Intro", {"narration_styles": ["expert"]}, "product_video")
        self.assertEqual(payload["source"]["narration_styles"], "- Phong cách dẫn chuyện: chuyên gia khoa học.")
    def test_create_campaign_normalizes_styles(self):
        # FakeDb; product_video + ["pricing", "gentle"] -> stored ["gentle"]
        ...
```

`SNAPSHOT` là dict tối thiểu `{"name": "Kem A", "brand": "B", "price": 100000, "ingredients": "", "description": "", "usage": ""}`. Test cuối dùng `FakeDb` và `patch.object(campaigns, "get_db", ...)`, gọi `create_campaign({"product_ids": ["1"], "purpose": "product_video", "configuration": {"narration_styles": ["pricing", "gentle"]}})` rồi đọc document đã lưu.

- [ ] **Step 2: Chạy test, phải FAIL** — `IDOL_TEST tests.test_narration` → `ModuleNotFoundError: core.narration`.
- [ ] **Step 3: Impact** cho `create_campaign`, `build_script_prompts`, `_live_only_prompts`, `_script_council_payload`.
- [ ] **Step 4: Cài đặt.**
  - Viết `core/narration.py`.
  - Trong 3 nhánh prompt, chèn `style_instruction(config.get("narration_styles") or [])` làm một dòng ngay sau dòng "Độ dài mong muốn" (bỏ qua nếu rỗng).
  - `_script_council_payload` thêm `source["narration_styles"]` (chuỗi instruction, có thể rỗng).
  - `create_campaign` chuẩn hóa sau khi đã có `purpose`.
- [ ] **Step 5: Chạy test, phải PASS** — `IDOL_TEST tests.test_narration`, rồi `IDOL_TEST tests.test_agent tests.test_campaigns tests.test_script_purpose`.
- [ ] **Step 6: Checkpoint** — `git diff --check -- ai-idol-service/core ai-idol-service/engines/content_agent.py ai-idol-service/tests/test_narration.py`.

---

### Task 2: API truy vấn sản phẩm có cấu trúc (sửa F2, F3)

**Thay spec:** body dùng **đúng các khóa của selection Prompt Builder** (giống `fixtures/prompt_builder/cases.py:compose_brief`) để so sánh tương đương được. Logic nằm ở module mới `agents/structured_query.py` thay vì `command_query.py`.

**Files:**
- Create: `ai-idol-service/agents/structured_query.py`
- Modify: `ai-idol-service/agents/command_query.py` (`build_query_plan`: thêm `limit_explicit`; `execute_query`: lọc `category_ids`), `ai-idol-service/app.py` (route mới)
- Test: `ai-idol-service/tests/test_structured_query.py`

**Interfaces:**
- Produces:
  - `build_query_plan(...)` trả thêm `plan["limit_explicit"]: bool` (True khi câu có số lượng).
  - `execute_query` tôn trọng `plan.get("category_ids")`: list `int` thì chỉ giữ row có `int(ma_danh_muc)` thuộc list; `None` hoặc thiếu thì không lọc.
  - `FILTER_ENUMS = {"expiry": {"", "expiry_17_19", "expiry_max_18"}, "stock": {"", "stock_high", "stock_low", "out_of_stock"}, "sales": {"", "best_30", "best_7", "best_all", "revenue_low_conversion", "views_no_orders", "cart", "discount_slow", "growth_7"}, "warehouse_mode": {"", "warehouse", "batch"}}`
  - `plan_from_filters(filters: dict, *, today: dt.date) -> dict`. Khóa nhận: `category_ids`, `expiry`, `stock`, `sales`, `warehouse_mode`, `scope_code`, `brand`, `brand_cap` (bool), `enough_stock` (bool), `q`, `limit` (1–100, mặc định 100). Trả plan **cùng tập khóa** với `build_query_plan`, cộng `category_ids`, `q`, và `limit_explicit = True`. Giá trị enum lạ → `ValueError("Bộ lọc không hợp lệ: <khóa>")`.
  - `run_structured_query(filters: dict, *, db=None, today: dt.date | None = None, clock=time.monotonic) -> dict` trả `{"ok": True, "products": [...], "matched_count": int, "warnings": list[str], "missing_data": dict, "checked_at": str}`. Mỗi product: `{"product_id", "name", "image_raw", "stock", "price", "brand", "metric_label", "metric_value"}`. `image_raw` = phần tử đầu của `link_hinh_anh` (hoặc `hinh_anh`) tách theo `|`. `metric_label` = `row["reason"]`. `metric_value` = giá trị evidence dùng để xếp hạng, hoặc `None`. `q` lọc phía Python theo tên hoặc mã (không dấu, không phân biệt hoa thường) **trước** khi cắt `limit`.
  - Cache nguồn trong module: catalog TTL 30 giây; `(batches, snapshots)` TTL 30 giây theo khóa `(as_of, window_days, rank_by, expiry_required, stock_min is not None or stock_max is not None, warehouse_id, batch_id)`. Không cache kết quả.
  - Flask `POST /api/ai-idol/products/query`: `ValueError` → 400 `{"ok": False, "message"}`; lỗi khác → 500 thông điệp chung "Không truy vấn được sản phẩm, thử lại sau." (log chi tiết).

- [ ] **Step 1: Viết test thất bại** `tests/test_structured_query.py`

```python
PARITY_KEYS = ("stock_min", "stock_max", "expiry_from", "expiry_to", "expiry_required", "rank_by",
               "window_days", "warehouse_id", "batch_id", "brand", "brand_mode", "per_brand_max")
TODAY = dt.date(2026, 10, 15)

class ParityTests(unittest.TestCase):
    def selections(self):
        for group in ("expiry", "stock", "sales"):
            for key in FRAGMENTS[group]:
                if key:
                    yield {group: key}
        yield {"brand": "CeraVe"}
        yield {"brand_cap": True}
        yield {"enough_stock": True}
        yield {"warehouse_mode": "warehouse", "scope_code": "KHO-HN-01"}
        yield {"warehouse_mode": "batch", "scope_code": "LO-7"}
        yield {"expiry": "expiry_17_19", "stock": "stock_high", "sales": "best_30", "brand_cap": True}

    def test_filters_match_prompt_builder_plan(self):
        for sel in self.selections():
            with self.subTest(sel=sel):
                expected = build_query_plan(compose_brief(sel), today=TODAY)
                self.assertEqual(expected["questions"], [])
                actual = plan_from_filters(sel, today=TODAY)
                for key in PARITY_KEYS:
                    self.assertEqual(actual[key], expected[key], key)

class StructuredQueryTests(unittest.TestCase):
    def test_limit_explicit_flag(self):
        self.assertTrue(build_query_plan("Lọc 5 kem chống nắng", today=TODAY)["limit_explicit"])
        self.assertFalse(build_query_plan("Tìm kem chống nắng", today=TODAY)["limit_explicit"])
    def test_category_ids_filter(self): ...      # 3 row ma_danh_muc 10/10/20, plan category_ids [10] -> 2 row
    def test_unknown_enum_rejected(self):
        with self.assertRaises(ValueError):
            plan_from_filters({"sales": "best_99"}, today=TODAY)
    def test_product_shape_and_q_filter(self): ...   # q="serum" giữ đúng row có "Serum" trong tên; đủ 8 khóa
    def test_sources_cached_for_30_seconds(self): ...  # clock giả: 2 lần gọi trong 29 giây -> loader catalog chạy 1 lần; giây 31 -> 2 lần
    def test_endpoint_400_on_bad_filter(self): ...      # app.test_client().post("/api/ai-idol/products/query", json={"sales": "x"}) -> 400
```

`compose_brief` và `FRAGMENTS` import từ `fixtures.prompt_builder.cases`. Ba test cuối patch loader của module (`load_catalog`, `load_query_sources`) bằng hàm giả có đếm số lần gọi.

- [ ] **Step 2: Chạy test, phải FAIL** — `IDOL_TEST tests.test_structured_query`.
- [ ] **Step 3: Impact** cho `build_query_plan`, `execute_query`.
- [ ] **Step 4: Cài đặt** theo Interfaces. Ánh xạ sales và stock phải ra đúng giá trị mà `build_query_plan` sinh từ câu của Prompt Builder: cách nhanh nhất là đọc `FRAGMENTS` và nhánh tương ứng trong `build_query_plan`; test parity là trọng tài. `brand` đi qua `normalize_text`, `brand_mode="prefer"`. `enough_stock` bị bỏ qua khi `stock == "out_of_stock"`.
- [ ] **Step 5: Chạy test, phải PASS** — `IDOL_TEST tests.test_structured_query tests.test_command_query tests.test_prompt_builder_cases tests.test_pb_fixture_cases`.
- [ ] **Step 6: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 3: Quy tắc autopilot thuần

**Thay spec:** thêm quy tắc `QUERY_UNCLEAR`. Khi `build_query_plan` trả `questions` (ví dụ chọn cả 7 và 30 ngày, phủ định, danh mục chưa hỗ trợ, "khoảng 18 tháng"), autopilot **dừng** và đưa câu hỏi vào message, vì chạy tiếp sẽ làm sai ý. Riêng câu hỏi về khoảng số lượng 1–50 thì được giải bằng `COUNT_CAP`.

**Files:**
- Create: `ai-idol-service/agents/autopilot.py`
- Modify: `ai-idol-service/config.py`, `.env.example`
- Test: `ai-idol-service/tests/test_autopilot_rules.py`

**Interfaces:**
- Produces (không đọc DB, không đọc đồng hồ hệ thống trừ giá trị mặc định của `now`):
  - `make_decision(code: str, level: str, message: str, *, now: dt.datetime | None = None) -> dict`. Level lạ → `ValueError`.
  - `autopilot_limit(requested: int, explicit: bool, *, default: int, maximum: int) -> tuple[int, dict | None]`. Không explicit → `default` + `COUNT_DEFAULT`. Lớn hơn `maximum` → `maximum` + `COUNT_CAP`. Ngược lại `(requested, None)`.
  - `count_shortfall(requested: int, found: int) -> dict | None`: `found == 0` → `NO_PRODUCTS` stopped; `0 < found < requested` → `COUNT_SHORT` adjusted, message "Xin {requested} sản phẩm, tìm được {found}; làm với {found} sản phẩm."
  - `parse_slots(value: str) -> list[dt.time]`: chuỗi sai → `[12:00, 20:00]`.
  - `next_prime_slot(earliest: dt.datetime, slots: list[dt.time], tz: ZoneInfo) -> dt.datetime`: thời điểm nhỏ nhất `>= earliest` có giờ địa phương thuộc `slots`, tìm trong 3 ngày.
  - `round_up(value: dt.datetime, minutes: int = 15) -> dt.datetime`
  - `resolve_schedule(requested: dt.datetime | None, earliest: dt.datetime, slots, tz, *, unreadable: bool = False) -> tuple[dt.datetime, dict]`:
    - `requested is None` → `next_prime_slot` + `SLOT_PICKED` (thêm "không đọc được giờ trong prompt" khi `unreadable`).
    - `requested >= earliest` → `requested` + decision `SCHEDULE_KEPT` (done).
    - `requested < earliest` → `round_up(earliest)` + `SLOT_MOVED`, message nêu giờ cũ → giờ mới định dạng `HH:MM dd/mm`.
  - `split_platforms(requested: list[str], configured: set[str]) -> tuple[list[str], list[str], list[dict]]` trả `(platforms, pending, decisions)`: `platforms` luôn `["internal"]` (kèm `PLATFORM_INTERNAL` done); kênh ngoài đã cấu hình → `pending` + `PLATFORM_PENDING` waiting "YouTube chờ bạn cho phép"; chưa cấu hình → `PLATFORM_UNCONFIGURED` adjusted.
  - `drop_out_of_stock(products: list[dict], stock: dict[str, float | None], purpose: str) -> tuple[list[dict], dict | None]`: chỉ khi `has_broadcast(purpose)`; stock `None` (không biết) thì giữ; `<= 0` thì bỏ, `LIVE_OOS_DROPPED`.
  - `query_questions_decision(questions: list[str]) -> dict | None`: bỏ câu hỏi bắt đầu bằng "Số lượng cần nằm trong khoảng"; còn câu khác → `QUERY_UNCLEAR` stopped, message = các câu nối bằng " ".
- `config.py`: `AUTOPILOT_DEFAULT_PRODUCTS`, `AUTOPILOT_MAX_PRODUCTS` (int, giá trị sai hoặc < 1 → mặc định + `logger.warning`), `AUTOPILOT_SLOTS` (chuỗi). `.env.example` thêm 3 biến kèm comment tiếng Việt.

- [ ] **Step 1: Viết test thất bại** `tests/test_autopilot_rules.py`

```python
TZ = ZoneInfo("Asia/Ho_Chi_Minh")
SLOTS = [dt.time(12), dt.time(20)]
def at(day, hour, minute=0): return dt.datetime(2026, 10, day, hour, minute, tzinfo=TZ)

class AutopilotRuleTests(unittest.TestCase):
    def test_limit_default_and_cap(self):
        self.assertEqual(autopilot_limit(10, False, default=5, maximum=10)[0], 5)
        self.assertEqual(autopilot_limit(20, True, default=5, maximum=10)[1]["code"], "COUNT_CAP")
        self.assertEqual(autopilot_limit(3, True, default=5, maximum=10), (3, None))
    def test_shortfall(self):
        self.assertEqual(count_shortfall(10, 7)["level"], "adjusted")
        self.assertEqual(count_shortfall(10, 0)["code"], "NO_PRODUCTS")
        self.assertIsNone(count_shortfall(5, 5))
    def test_slot_same_day(self):
        self.assertEqual(next_prime_slot(at(15, 10), SLOTS, TZ), at(15, 12))
    def test_slot_after_midnight(self):
        self.assertEqual(next_prime_slot(at(15, 23, 50), SLOTS, TZ), at(16, 12))
    def test_schedule_moved_rounds_up(self):
        when, decision = resolve_schedule(at(15, 9), at(15, 10, 7), SLOTS, TZ)
        self.assertEqual((when, decision["code"]), (at(15, 10, 15), "SLOT_MOVED"))
    def test_schedule_kept(self):
        self.assertEqual(resolve_schedule(at(16, 20), at(15, 10), SLOTS, TZ)[0], at(16, 20))
    def test_platform_split(self):
        platforms, pending, decisions = split_platforms(["internal", "youtube", "facebook"], {"youtube"})
        self.assertEqual((platforms, pending), (["internal"], ["youtube"]))
        self.assertEqual([d["code"] for d in decisions], ["PLATFORM_INTERNAL", "PLATFORM_PENDING", "PLATFORM_UNCONFIGURED"])
    def test_out_of_stock_only_for_live(self):
        products = [{"product_id": "1"}, {"product_id": "2"}, {"product_id": "3"}]
        stock = {"1": 0, "2": 5, "3": None}
        kept, decision = drop_out_of_stock(products, stock, "live_only")
        self.assertEqual([p["product_id"] for p in kept], ["2", "3"])
        self.assertEqual(drop_out_of_stock(products, stock, "product_video"), (products, None))
    def test_query_questions(self):
        self.assertIsNone(query_questions_decision(["Số lượng cần nằm trong khoảng 1–50 sản phẩm."]))
        self.assertEqual(query_questions_decision(["Hãy chọn một tiêu chí."])["level"], "stopped")
    def test_parse_slots_fallback(self):
        self.assertEqual(parse_slots("abc"), SLOTS)
```

- [ ] **Step 2: Chạy test, phải FAIL** — `IDOL_TEST tests.test_autopilot_rules`.
- [ ] **Step 3: Cài đặt** `agents/autopilot.py` + 3 biến config + `.env.example`.
- [ ] **Step 4: Chạy test, phải PASS** — `IDOL_TEST tests.test_autopilot_rules`.
- [ ] **Step 5: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 4: Nối autopilot vào Agent run

**Thay spec:** `process_agent_run` nhận thêm kwarg `now: dt.datetime | None = None` để test được giờ. Tồn kho cho `LIVE_OOS_DROPPED` lấy qua helper `_stock_levels`.

**Files:**
- Modify: `ai-idol-service/core/agent_runs.py` (`create_agent_run`), `ai-idol-service/agents/service.py` (`process_agent_run`, `_safe_schedule`), `ai-idol-service/core/campaigns.py` (`create_campaign`: nhận `pending_external_platforms`)
- Test: `ai-idol-service/tests/test_autopilot_service.py`

**Interfaces:**
- Consumes: Task 1 (`normalize_styles`, `styles_from_text`), Task 2 (`plan["limit_explicit"]`), Task 3 (mọi hàm ở `agents/autopilot.py`, `AUTOPILOT_*`).
- Produces:
  - Agent run document có `mode: "autopilot" | "classic"` (payload thiếu hoặc lạ → `"classic"`). `mode` nằm trong chuỗi băm idempotency. `mode == "autopilot"` thì `overrides.autonomy_policy = "auto_preview"` bất kể payload.
  - Run autopilot có `decisions: list[Decision]`, ghi bằng `update_agent_run` ở mọi điểm kết thúc của `process_agent_run` (dừng hoặc `CAMPAIGN_CREATED`).
  - Dừng autopilot: `status="NEEDS_INPUT"`, `current_stage="AUTOPILOT_STOPPED"`, `plan.warnings=[message của decision stopped]`.
  - Payload `create_campaign` từ autopilot: `configuration.agent_mode="autopilot"`, `configuration.narration_styles`, `pending_external_platforms` (top-level).
  - `create_campaign` lưu `pending_external_platforms`: chỉ giữ `youtube`/`facebook`, bỏ trùng, chỉ khi `has_broadcast(purpose)`; còn lại `[]`.
  - `_earliest_start(product_count: int, now: dt.datetime) -> dt.datetime` tách từ `_safe_schedule` (giữ nguyên hành vi của `_safe_schedule`).
  - `_stock_levels(selected: list[dict], raw_catalog: list[dict] | None) -> dict[str, float | None]`: thứ tự ưu tiên là `evidence.stock`, rồi row cùng mã trong `raw_catalog` (`STOCK_FIELDS`), rồi `get_db().san_pham.find_one(product_id_filter(id), projection STOCK_FIELDS)`. Không có thì `None`.

Hành vi khi `mode == "autopilot"` (run `classic` giữ nguyên từng nhánh):

| Nhánh hiện có trong `process_agent_run` | Autopilot |
|---|---|
| Intent `PRODUCT_SEARCH` | Đi tiếp như `CREATE_CAMPAIGN`, decision `INTENT_CREATE` |
| Intent khác `CREATE_CAMPAIGN`/`PRODUCT_SEARCH` | Dừng `INTENT_UNSUPPORTED` |
| Query plan có `questions` | `query_questions_decision`; dừng nếu có |
| Trước `execute_query` | `plan["limit"]` = `autopilot_limit(...)` |
| `is_query_only` / chưa đủ / chưa `complete` | Đi tiếp nếu có ≥1 sản phẩm; `count_shortfall`; `missing_data` → `METRICS_FALLBACK` adjusted |
| Giờ có nhưng không đọc được (`explicit_clock`) | Không dừng; `resolve_schedule(None, ..., unreadable=True)` |
| `unresolved_ids` | Bỏ, `IDS_DROPPED` |
| `selected` rỗng, không có mã tường minh, `ambiguous` có | Chọn `ambiguous[0]`, `AMBIGUOUS_PICKED` nêu tên |
| `constraint_mismatches` | Bỏ các mã đó, `CONSTRAINT_DROPPED` |
| `popularity_unverified` | Đi tiếp, `METRICS_FALLBACK` |
| Sau tất cả bước trên mà 0 sản phẩm | Dừng `NO_PRODUCTS` |
| Purpose | `brief.purpose or infer_purpose(...)`; có livestream thì `drop_out_of_stock` |
| `missing_rtmp` | `split_platforms(brief.platforms, set(RTMP_TARGETS))` |
| Giờ tường minh không kịp | `resolve_schedule(requested, _earliest_start(n, now), ...)` |
| Phong cách | `normalize_styles(styles_from_text(brief), purpose)`; có thì `STYLES_APPLIED` done, dropped thì `STYLES_PRICE_DROPPED` |
| Tạo chương trình xong | Decision `CAMPAIGN_CREATED` done "Đã tạo chương trình …" |

- [ ] **Step 1: Viết test thất bại** `tests/test_autopilot_service.py`. Harness kế thừa `AgentRunHarness` (import từ `tests.test_agent`), đặt `run["mode"]="autopilot"`, `overrides.autonomy_policy="auto_preview"` và truyền `now=NOW` với `NOW = 2026-10-15 09:00 +07:00`. Catalog là raw row có `ma_san_pham`, `ten_san_pham`, `so_luong_ton_kho`, `so_luong_da_ban`, `danh_muc_day_du`.

| Test | Brief / điều kiện | Khẳng định |
|---|---|---|
| `test_search_prompt_creates_product_video` | "Cho tôi sản phẩm retinol", catalog có 1 sản phẩm retinol | `create` được gọi; `purpose == "product_video"`; `configuration.agent_mode == "autopilot"`; có code `INTENT_CREATE` |
| `test_classic_run_unchanged` | Cùng brief, `mode` không đặt | `create` không được gọi (giữ hành vi cũ) |
| `test_unknown_id_dropped` | "Làm video giới thiệu sản phẩm mã 995 và mã 9999" | `product_ids == ["995"]`; có `IDS_DROPPED` |
| `test_no_products_stops` | "Làm video giới thiệu sản phẩm mã 9999" | `status == "NEEDS_INPUT"`; decision cuối `stopped`/`NO_PRODUCTS`; `create` không gọi |
| `test_live_without_time_picks_slot` | "Livestream giới thiệu sản phẩm mã 995" | `scheduled_at` = 2026-10-15T12:00+07:00; có `SLOT_PICKED` |
| `test_past_time_moved` | "Livestream sản phẩm mã 995 lúc 20h ngày 14/10/2026" | `scheduled_at` = 10:00 15/10 (`_earliest_start(1, NOW)` làm tròn 15 phút); có `SLOT_MOVED` |
| `test_youtube_pending_when_configured` | "Livestream sản phẩm mã 995 lúc 20h ngày 16/10/2026 trên YouTube", `rtmp={"youtube": "x"}` | payload `platforms == ["internal"]`, `pending_external_platforms == ["youtube"]`; có `PLATFORM_PENDING` |
| `test_youtube_unconfigured_noted` | Như trên, `rtmp={}` | `pending_external_platforms == []`; có `PLATFORM_UNCONFIGURED`; vẫn tạo chương trình |
| `test_price_styles_dropped_for_product_video` | "Làm video sản phẩm mã 995, giọng vui tươi, chốt sale" | `narration_styles == ["cheerful"]`; có `STYLES_PRICE_DROPPED` |
| `test_live_out_of_stock_dropped` | Live cho mã 995 (tồn 0) và 934 (tồn 5) | `product_ids == ["934"]`; có `LIVE_OOS_DROPPED` |
| `test_operational_intent_stops` | "hủy lịch livestream tối nay" | decision cuối `INTENT_UNSUPPORTED` |
| `test_unclear_query_stops` | "Lọc 5 kem chống nắng bán chạy trong 7 ngày và 30 ngày" | decision cuối `QUERY_UNCLEAR`, message chứa "kỳ thống kê" |
| `test_default_count_and_cap` | 8 kem chống nắng có số bán; "Lọc kem chống nắng bán chạy nhất toàn kỳ" → 5 SP + `COUNT_DEFAULT`; "Lọc 20 kem chống nắng bán chạy nhất toàn kỳ" → 8 SP + `COUNT_CAP` + `COUNT_SHORT` | như mô tả |
| `test_create_agent_run_mode` | `create_agent_run({"brief": "x"*20, "mode": "autopilot", "autonomy_policy": "assisted"})` với `FakeDb` | `mode == "autopilot"`, `overrides.autonomy_policy == "auto_preview"`; `mode` lạ → `"classic"` |
| `test_create_campaign_pending_platforms` | `create_campaign` live với `pending_external_platforms=["youtube","tiktok","youtube"]` → `["youtube"]`; product_video → `[]` | như mô tả |

Nếu một brief mẫu bị bộ phân loại hiện có hiểu khác dự kiến, đổi câu chữ của brief cho đúng tình huống của dòng đó, không đổi khẳng định.

- [ ] **Step 2: Chạy test, phải FAIL** — `IDOL_TEST tests.test_autopilot_service`.
- [ ] **Step 3: Impact** cho `process_agent_run`, `_safe_schedule`, `create_agent_run`, `create_campaign`. `process_agent_run` gần như chắc chắn ≥ MEDIUM: ghi rõ trong report.
- [ ] **Step 4: Cài đặt.** Đặt `autopilot = run.get("mode") == "autopilot"` ở đầu hàm, mỗi nhánh trong bảng chỉ thêm một khối `if autopilot:` gọi helper ở Task 3, tích lũy `decisions` cục bộ. Nhánh classic không đổi một dòng logic nào.
- [ ] **Step 5: Chạy test, phải PASS** — `IDOL_TEST tests.test_autopilot_service tests.test_agent tests.test_agent_run_view tests.test_command_query_service tests.test_campaigns`.
- [ ] **Step 6: Checkpoint** — `git diff --check` trên các file của task.

**Vòng sửa (sau review Opus, chưa làm):**
1. **`METRICS_FALLBACK`.**
   - Áp dụng khi cả 3 điều kiện cùng đúng, chỉ trong autopilot:
     - plan xếp hạng theo chỉ số bán hàng có kỳ (`units`, `revenue_low_conversion`, `views_no_orders`, `cart`, `discount_slow`, `growth` với `window_days` khác rỗng);
     - `"sales_snapshot"` có trong `missing_data`;
     - số sản phẩm tìm được nhỏ hơn `limit`.
   - Khi đó chạy lại `execute_query({**plan, "rank_by": "units", "window_days": None}, ...)` và ghi `METRICS_FALLBACK` "Chưa có số liệu {n} ngày cho tiêu chí {nhãn}; xếp theo doanh số toàn kỳ thay thế."
   - Thiếu dữ liệu loại khác thì dùng mã riêng (ví dụ `DATA_GAPS`), không dùng `METRICS_FALLBACK`.
   - Test: "Livestream 5 kem chống nắng bán chạy" khi không có snapshot thì tạo được chương trình 5 SP; có snapshot đầy đủ thì không fallback.
2. **Ngày có mà giờ không có** (ví dụ "ngày 20/10/2026"):
   - Tìm giờ vàng bắt đầu từ `max(earliest, 00:00 ngày đó)`, message nói rõ đã giữ ngày.
   - Ngày đã qua thì xếp như hôm nay và nói rõ.
   - Test cả hai trường hợp.

---

### Task 5: Bỏ qua sản phẩm trượt kiểm duyệt (sửa F4)

**Files:**
- Modify: `ai-idol-service/core/campaigns.py` (`update_campaign_from_job`, `prepare_campaign`, `cancel_campaign`, `reschedule_campaign`, `resume_ready_campaign`, `retry_campaign`), `ai-idol-service/core/agent_runs.py` (thêm `append_decisions`)
- Test: `ai-idol-service/tests/test_campaign_skip.py`

**Interfaces:**
- Produces:
  - `append_decisions(run_id: str, items: list[dict]) -> None`: `$push` với `$each`, mỗi item qua `make_decision` (đã có `at`). `run_id` rỗng hoặc sai → bỏ qua, không lỗi.
  - Segment status mới `SKIPPED`. Campaign `progress.skipped: int`.
  - Điều kiện SKIPPED: job `FAILED` **và** `failed_stage == "VALIDATING_SCRIPT"` **và** campaign `configuration.agent_mode == "autopilot"`. Decision `SCRIPT_SKIPPED` adjusted ("Bỏ {tên/ mã}: kịch bản trượt kiểm duyệt sau 3 lần tự sửa — {error_message rút gọn 200 ký tự}") chỉ append **một lần** (chỉ khi update segment thực sự đổi từ trạng thái khác sang `SKIPPED`).
  - Hết việc đang chạy, chờ duyệt và lỗi: `ready ≥ 1` → trả `campaign_id` (assembly/đăng như cũ); `ready == 0 && skipped ≥ 1` → `NEEDS_ATTENTION` + `error_message="Tất cả kịch bản đều trượt kiểm duyệt"`, trả `None`.
  - `SKIPPED` được coi như "đã xong, không dùng": không bị `prepare_campaign` tạo lại job, không bị `cancel_campaign` chuyển `CANCELLED`, không chặn `reschedule_campaign`/`resume_ready_campaign`, và `retry_campaign` coi "mọi segment READY hoặc SKIPPED, có ≥1 READY" là `all_ready`.

- [ ] **Step 1: Viết test thất bại** `tests/test_campaign_skip.py` (FakeDb, campaign autopilot product_video `GENERATING`, 3 segment có job):
  - `test_one_rejected_script_is_skipped`: job1 READY, job2 FAILED@VALIDATING_SCRIPT, job3 READY → gọi `update_campaign_from_job` cho cả 3 → segment2 `SKIPPED`, `progress.skipped == 1`, lần gọi cuối trả `campaign_id`; agent run có đúng 1 decision `SCRIPT_SKIPPED` kể cả khi gọi lại cho job2.
  - `test_all_rejected_needs_attention`: cả 3 FAILED@VALIDATING_SCRIPT → campaign `NEEDS_ATTENTION`, `error_message == "Tất cả kịch bản đều trượt kiểm duyệt"`, trả `None`.
  - `test_manual_campaign_keeps_failed`: không có `agent_mode` → segment `FAILED`, campaign `NEEDS_ATTENTION`.
  - `test_technical_failure_not_skipped`: autopilot, `failed_stage == "RENDERING_SYNA"` → `FAILED`.
  - `test_prepare_ignores_skipped`, `test_cancel_keeps_skipped`, `test_resume_with_skipped`, `test_retry_product_video_with_skipped_marks_ready` (`NEEDS_ATTENTION` + READY/SKIPPED → `videos_ready`).
- [ ] **Step 2: Chạy test, phải FAIL** — `IDOL_TEST tests.test_campaign_skip`.
- [ ] **Step 3: Impact** cho 6 hàm ở Files.
- [ ] **Step 4: Cài đặt** theo Interfaces. Trong `update_campaign_from_job`, đọc `configuration.agent_mode`/`agent_run_id` của campaign bằng một `find_one` có projection.
- [ ] **Step 5: Chạy test, phải PASS** — `IDOL_TEST tests.test_campaign_skip tests.test_campaigns tests.test_campaign_recovery tests.test_broadcast_lifecycle tests.test_e2e_flow`.
- [ ] **Step 6: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 6: Cho phép phát kênh ngoài

**Files:**
- Modify: `ai-idol-service/core/campaigns.py` (thêm `approve_external_platform`), `ai-idol-service/app.py` (route mới), `ai-idol-service/tests/fakes.py` (hỗ trợ `$addToSet`, `$pull` dạng giá trị đơn)
- Test: `ai-idol-service/tests/test_platform_approval.py`

**Interfaces:**
- Consumes: `append_decisions` (Task 5), `RTMP_TARGETS` từ `config`.
- Produces:
  - `APPROVABLE_STATUSES = ("VALIDATING", "GENERATING", "AWAITING_APPROVAL", "NEEDS_ATTENTION", "SCHEDULED")`
  - `approve_external_platform(campaign_id: str, platform: str, by: str) -> dict` trả `{"platforms": list[str], "pending_external_platforms": list[str]}`.
    - Không có campaign → `CampaignNotFound`.
    - `platform` đã nằm trong `platforms` → trả trạng thái hiện tại, không ghi gì (idempotent).
    - `platform` không thuộc `pending_external_platforms` → `ValueError("Kênh này không chờ cho phép.")`.
    - Không có trong `RTMP_TARGETS` → `ValueError("Chưa cấu hình RTMP cho <Tên kênh>.")`.
    - Ngoài `APPROVABLE_STATUSES` → `CampaignConflict("Chương trình đã lên sóng hoặc kết thúc; không thể thêm kênh.")`.
    - Thành công: `find_one_and_update` có điều kiện `status ∈ APPROVABLE_STATUSES` và `pending_external_platforms == platform`, `$addToSet platforms`, `$pull pending_external_platforms`, `$push platform_approvals {platform, by, at}`; append decision `PLATFORM_APPROVED` done "Đã cho phép phát <Tên kênh>." vào `configuration.agent_run_id`.
  - Flask `POST /api/ai-idol/campaigns/<campaign_id>/platforms/approve`, body `{"platform", "by"}` → 200 `{"ok": True, "data": {...}}`; 404 / 400 / 409 theo loại lỗi như route `reschedule` hiện có.

- [ ] **Step 1: Viết test thất bại**: `test_approve_moves_platform`, `test_approve_twice_is_noop`, `test_not_pending_rejected`, `test_unconfigured_rejected`, `test_live_campaign_conflict`, `test_route_status_codes` (test client, patch hàm lõi để ném từng loại lỗi → 404/400/409).
- [ ] **Step 2: Chạy test, phải FAIL** — `IDOL_TEST tests.test_platform_approval`.
- [ ] **Step 3: Cài đặt** (impact cho `Collection` trong `fakes.py` không cần vì là file test; vẫn chạy toàn suite ở Step 4).
- [ ] **Step 4: Chạy test, phải PASS** — `IDOL_TEST tests.test_platform_approval`, rồi `IDOL_TEST discover -s tests -t .` (toàn bộ, phải OK).
- [ ] **Step 5: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 6b: Tự xuất bản video lên trang sản phẩm qua `ai_product_videos` (D10, D12)

**Files:**
- Create: `ai-idol-service/core/publications.py`, `ai-idol-service/tests/test_publications.py`
- Modify:
  - `ai-idol-service/tasks.py`: gọi sau khi video `product_video` READY và sau khi `live_and_product` có `product_page_file_path`;
  - `ai-idol-service/app.py`: route `GET /api/ai-idol/videos/<id>`;
  - `backend/app/controllers/SanPhamController.php`: `streamProductVideo`.

**Interfaces:**
- Consumes: `append_decisions` (Task 5).
- Produces:
  - `auto_publish_video(video_id: str) -> dict | None`.
    - **Điều kiện:** chỉ khi video có `purpose ∈ {product_video, live_and_product}`, có `product_page_file_path` hoặc `media.video_url`, và không `hidden_from_product_page`.
    - **Ghi bản ghi:** upsert theo `video_id` vào `ai_product_videos`, đúng **schema của `ProductVideo::publishVideo`** (đọc file đó để lấy đủ trường). Gồm `video_id, campaign_id, segment_id, title, duration, format, resolution, avatar_mode, associated_product_ids: [product_id], is_published: True, display_priority: 10, created_at, updated_at`, cộng `source: "auto"`.
    - **Không ghi đè:** nếu đã có bản ghi cho `video_id` thì không đổi gì (admin đã gỡ thì vẫn gỡ).
    - **Xóa cache:** sau khi tạo, xóa key Valkey `${CACHE_PREFIX}product:videos:*` qua `VALKEY_URL` (dùng thư viện `redis` có sẵn trong image). Không có `VALKEY_URL` hoặc lỗi thì log cảnh báo, không ném lỗi (cache tự hết hạn sau 1800 giây).
    - **Ghi quyết định:** append decision `PRODUCT_PAGE_PUBLISHED` done "Đã đăng video lên trang sản phẩm …" vào run, theo `configuration.agent_run_id`.
  - Flask `GET /api/ai-idol/videos/<id>?rendition=product_page`:
    - phát `product_page_file_path`;
    - file local không còn mà có `media.video_url` thì trả 302 tới URL đó;
    - `live_only` thì 404;
    - không có tham số thì giữ hành vi cũ.
  - `SanPhamController::streamProductVideo`:
    - gọi Flask kèm `?rendition=product_page`;
    - Flask trả 302 tới URL bắt đầu bằng `R2_PUBLIC_BASE_URL` thì chuyển hướng trình duyệt tới đó;
    - URL khác thì 404.

- [ ] **Step 1: Test thất bại**, gồm:
  - `test_publications.py` (FakeDb):
    - đủ điều kiện → tạo bản ghi đúng schema;
    - `live_only` hoặc video bị ẩn → không tạo;
    - gọi 2 lần → 1 bản ghi;
    - bản ghi admin đã gỡ → giữ nguyên;
    - xóa cache gọi đúng pattern;
    - Valkey lỗi → không ném lỗi.
  - Test route Flask cho 3 nhánh của `rendition`.
- [ ] **Step 2: Chạy, phải FAIL.**
- [ ] **Step 3: Impact** cho các hàm sửa trong `tasks.py`, `api_get_video_file` và `streamProductVideo`.
- [ ] **Step 4: Cài đặt.**
- [ ] **Step 5: Chạy test, phải PASS**: `IDOL_TEST tests.test_publications` và toàn bộ suite; `php -l` cho `SanPhamController.php`.
- [ ] **Step 6: Checkpoint.**

---

### Task 7: PHP proxy cho 2 API mới

**[rcm]** Cả `queryProducts` và `approvePlatform` gọi `verifyCsrf($input)`. Test tĩnh kiểm điều này.

**Files:**
- Modify: `backend/app/controllers/AdminAiIdolController.php`, `backend/app/routes/ai_idol.php`, `backend/public/index.php`, `frontend/public/index.php`
- Create: `backend/tests/ai_idol_studio_proxy_test.php`

**Interfaces:**
- Consumes: Flask `POST /api/ai-idol/products/query` (Task 2), `POST /api/ai-idol/campaigns/<id>/platforms/approve` (Task 6).
- Produces:
  - `public static function normalizeProductQuery(array $input): array`. Trả đủ khóa `cat_l1, cat_l2, cat_l3, expiry, stock, sales, warehouse_mode, scope_code, brand, q` (string, trim, tối đa 80 ký tự), `brand_cap, enough_stock` (bool), `limit` (int 1–100, mặc định 100). Enum giống `FILTER_ENUMS` của Task 2; sai → `InvalidArgumentException` với message tiếng Việt.
  - `private function expandCategoryIds(string $catL1, string $catL2, string $catL3): ?array`: tách nguyên đoạn mở rộng danh mục có sẵn trong `getProducts`; `getProducts` gọi lại hàm này (hành vi không đổi). `null` = không lọc.
  - `public function queryProducts(): void` (route `admin_ai_idol_products_query`, POST): `checkAdmin` → normalize (400 khi lỗi) → `category_ids` → bỏ `cat_*` → proxy Flask → mỗi product thêm `image = resolve_image_url(image_raw)`, bỏ `image_raw` → trả JSON.
  - `public function approvePlatform(): void` (route `admin_ai_idol_approve_platform`, POST, `campaign_id` qua query string): `checkAdmin`; body `{platform}`; `by` lấy từ session như `createAgentRun`; proxy.
  - Thêm 2 route vào `routes/ai_idol.php` và vào **cả hai** mảng `in_array` ở `backend/public/index.php` và `frontend/public/index.php`.

- [ ] **Step 1: Viết test thất bại** `backend/tests/ai_idol_studio_proxy_test.php`: require controller file và gọi static `normalizeProductQuery` với input hợp lệ (đủ khóa, đúng kiểu, `limit` 500 → 100), `sales => 'x'` (ném lỗi), chuỗi 200 ký tự (cắt còn 80). Kiểm tra tĩnh: 2 route có mặt ở 3 file và trỏ đúng method; `queryProducts`/`approvePlatform` gọi `checkAdmin()`. Runner in `PASS`/`FAIL` theo mẫu `admin_usecase_contract_test.php`.
- [ ] **Step 2: Chạy test, phải FAIL** — `php backend/tests/ai_idol_studio_proxy_test.php`.
- [ ] **Step 3: Impact** cho `getProducts`, `AdminAiIdolController`.
- [ ] **Step 4: Cài đặt.**
- [ ] **Step 5: Kiểm tra** — `php -l` 4 file PHP đổi; `php backend/tests/ai_idol_studio_proxy_test.php` và `php backend/tests/admin_usecase_contract_test.php` phải exit 0.
- [ ] **Step 6: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 8: `studio-logic.js` — hàm thuần có unit test

**Files:**
- Create: `frontend/public/assets/js/studio-logic.js`, `frontend/public/assets/js/tests/studio-logic.test.js`

**Interfaces:**
- Produces: `window.StudioLogic` trong trình duyệt và `module.exports` trong Node (UMD nhỏ, không phụ thuộc DOM):
  - `NARRATION_STYLES: Array<{key, label, price}>` (Global Constraints), `PURPOSE_LABELS = {product_video: 'Video sản phẩm', live_and_product: 'Livestream + video sản phẩm', live_only: 'Chỉ livestream'}`, `CONTENT_MODE_LABELS = {'Product Intro': 'giới thiệu sản phẩm', 'Problem-Solution': 'vấn đề và giải pháp', 'Scientific Review': 'phân tích thành phần'}`
  - `allowedStyles(purpose) -> string[]`
  - `toggleStyle(current, key, purpose) -> string[]`: khóa không được phép → trả `current` không đổi; bật `concise` thì bỏ `detailed` và ngược lại.
  - `describeIntent(sel) -> string`, `summaryBar(sel) -> string`. `sel = {products: [{id, name}], purpose, schedule: {at: 'YYYY-MM-DDTHH:mm', duration, platforms}, styles, contentMode, character: 'syna_3d'|'custom', format}`.
  - `progressSteps(item) -> Array<{key, label, state}>`, `state ∈ 'done'|'active'|'todo'|'blocked'`
  - `needsYou(item) -> boolean`
  - `mergeActivity(campaigns, runs) -> item[]`, `item = {kind: 'campaign'|'run', id, source: 'auto'|'manual', status, title, createdAt, campaign, run}`
  - `filterActivity(items, filter) -> item[]` với `filter ∈ 'all'|'needs_you'|'scheduled'`
  - `createLatestGate() -> {next(): number, isCurrent(token): boolean}`

Chuỗi cố định cho `describeIntent`:
- Mẫu: `${lead} cho ${products}, cách kể ${mode}, ${styles}${character}; ${tail}`.
- `lead`:
  - `product_video`: `Syna làm video sản phẩm (không nói giá)`.
  - Có livestream: `Syna livestream ${duration} phút lúc ${HH}:${mm} ${DD}/${MM}/${YYYY} trên ${tên kênh nối ', '}`. Ngày giờ cắt thẳng từ chuỗi `at`, không qua `Date`. Tên kênh: Nội bộ, YouTube, Facebook.
- `products`: n ≤ 3 thì `${n} sản phẩm: A, B`; n > 3 thì `${n} sản phẩm: A, B, C và ${n-3} sản phẩm khác`.
- `styles`: có thì `phong cách ${nhãn viết thường nối ', '}, `, trong đó `&` thay bằng `và`. Không có thì chuỗi rỗng.
- `character`: `syna_3d` → `Syna 3D khung ${format}`; `custom` → `nhân vật riêng khung ${format}`.
- `tail`:
  - `product_video`: `video tự đăng lên trang sản phẩm khi sẵn sàng.`
  - `live_and_product`: `sau buổi phát, phần giới thiệu không giá tự đăng lên trang sản phẩm.`
  - `live_only`: `không đăng lên trang sản phẩm.`
- 0 sản phẩm → `Chưa chọn sản phẩm.`

`summaryBar`: `${n} SP · ${PURPOSE_LABELS[purpose]}` + (` · ${k} phong cách` nếu k>0) + ` · ${character === 'syna_3d' ? 'Syna 3D' : 'Nhân vật riêng'} ${format}`.

`progressSteps`:
- Bước theo purpose:
  - `product_video`: `select, script, review, render, page`.
  - `live_only`: `select, script, review, render, schedule, broadcast`.
  - `live_and_product`: cả 7 bước.
- Nhãn: Chọn SP, Kịch bản, Kiểm duyệt, Render, Lên lịch, Phát, Trang SP.
- Bước *đích* theo trạng thái. Mọi bước trước bước đích là `done`, bước đích nhận state ghi trong bảng, các bước sau là `todo`.

| Trạng thái | Bước đích |
|---|---|
| run chưa có campaign: `RECEIVED`/`INTERPRETING`/khác | select `active` |
| run chưa có campaign: `NEEDS_INPUT`/`FAILED`/`NEEDS_ATTENTION` | select `blocked` |
| `VALIDATING` | script `active` |
| `AWAITING_APPROVAL` | review `blocked` |
| `GENERATING`/`ASSEMBLING` | render `active` |
| `NEEDS_ATTENTION`/`FAILED` | `master_video_path` có thì schedule `blocked`, không thì render `blocked` |
| `SCHEDULED` | schedule `done`, broadcast `todo` |
| `STARTING`/`LIVE` | broadcast `active` |
| `COMPLETED`/`VIDEOS_READY` | tất cả `done` |
| `CANCELLED` | select `done`, còn lại `todo` |

`needsYou(item)` true khi:
- campaign `AWAITING_APPROVAL`, `NEEDS_ATTENTION` hoặc `FAILED`;
- hoặc campaign có `pending_external_platforms` khác rỗng và status thuộc `VALIDATING, GENERATING, AWAITING_APPROVAL, NEEDS_ATTENTION, SCHEDULED`;
- hoặc run (chưa có campaign) có status `NEEDS_INPUT`, `FAILED` hoặc `NEEDS_ATTENTION`.

`mergeActivity`:
- Campaign có `configuration.agent_managed` → `source: 'auto'`, gắn `run` theo `configuration.agent_run_id`.
- Run có `campaign_id` không tạo item riêng.
- Sắp `createdAt` giảm dần.
- Trường thiếu không làm hàm ném lỗi.

- [ ] **Step 1: Viết test** `tests/studio-logic.test.js` (`node:test`, `node:assert/strict`): đủ 3 câu `describeIntent` (1 sản phẩm product_video, 5 sản phẩm live_and_product với YouTube, live_only không phong cách), `summaryBar`, `toggleStyle` (khóa giá bị chặn ở product_video; loại trừ concise/detailed), `progressSteps` cho mỗi dòng của bảng, `needsYou` (pending sau khi `LIVE` → false), `mergeActivity` (run cũ không có `decisions`; run đã có campaign bị gộp), `filterActivity`, `createLatestGate` (token cũ không còn current sau `next()`).
- [ ] **Step 2: Chạy, phải FAIL** — `node --test frontend/public/assets/js/tests/`.
- [ ] **Step 3: Cài đặt** `studio-logic.js`.
- [ ] **Step 4: Chạy, phải PASS** — `node --test frontend/public/assets/js/tests/` và `node --check frontend/public/assets/js/studio-logic.js`.
- [ ] **Step 5: Checkpoint** — `git diff --check` trên 2 file.

---

### Task 9: Khung trang mới và `studio-core.js`

**[rcm]**
- View in `window.AI_IDOL_CSRF = <?= json_encode(AdminAiIdolController::getCsrfToken()) ?>`. Controller `index()` truyền token sẵn thì dùng biến đó.
- `StudioCore.api()` tự thêm header `X-CSRF-Token` cho mọi request không phải GET.
- Thêm `StudioCore.decisionsMarkup(decisions) -> html` (theo ruling ở ledger): map icon theo level, kèm chữ cho screen reader.
- Studio vẫn **2 tab** (Tự động, Thủ công) và bảng Hoạt động; không có tab xuất bản (D11).

**Files:**
- Modify: `frontend/views/admin/ai_idol/index.php` (viết lại phần khung), `frontend/public/assets/css/ai-idol-studio.css`
- Create: `frontend/public/assets/js/studio-core.js`

**Interfaces:**
- Consumes: `window.StudioLogic` (Task 8).
- Produces:
  - Khung view với các vùng có id cố định: `#idolStudio`, header + 3 chỉ số `#totalCampaigns #approvalCount #scheduledCount`, `#syncStatus`, `#idolHealth`, tablist `#autoFlowTab`/`#manualFlowTab` (`data-studio-flow="auto"|"manual"`), panel `#autoFlow`, `#manualFlow` (rỗng, Task 10/11 điền), cột `#activityPanel` (rỗng, Task 12 điền).
  - Thứ tự script (đều `defer`, cache-bust `filemtime`): `ai-idol-approval.js`, `studio-logic.js`, `studio-core.js`, `studio-manual.js`, `studio-auto.js`, `studio-activity.js`. Script cũ `ai-idol-studio.js` **không** được nạp nữa nhưng file chưa xóa (để Task 10–12 port code).
  - Giữ `window.__IDOL_CATALOG__`, `window.__IDOL_FILTER_COUNTS__` và link CSS.
  - `window.StudioCore = {root, $, api, escapeHtml, formatDate, normalize, statusName, badge, platformName, notify, emit, on, health}`:
    - `api(url, options) -> Promise<object>`: port từ `api()` cũ; non-2xx hoặc `ok === false` thì ném `Error(message)`.
    - `notify(el, message, success = false)`.
    - `emit(name, detail)` / `on(name, handler)`: `CustomEvent` trên `#idolStudio`.
    - `health() -> object | null`: kết quả health gần nhất.
  - Sự kiện dùng chung: `studio:data` `{campaigns, runs}` (Task 12 phát), `studio:health` `{data}` (core phát), `studio:refresh` (ai cũng phát được, Task 12 nghe), `studio:open-item` `{kind, id}`, `studio:prefill-prompt` `{brief}`, `studio:show-flow` `{flow}`.
  - Core lo việc: chuyển tab (ARIA tablist, phím ←/→, nhớ `localStorage` khóa `aiIdolStudio.flow`, bọc try/catch, mặc định `auto`); health banner (port logic cũ, poll như cũ, phát `studio:health`); cập nhật 3 chỉ số và `#syncStatus` khi nhận `studio:data`.
  - CSS: lưới 2 cột (≈65/35), dưới 1100px một cột với `#activityPanel` xuống dưới, dưới 640px padding 16px và không cuộn ngang. Giữ token màu và font hiện có của file.

- [ ] **Step 1: Impact** — không có symbol PHP/Python bị sửa; ghi "N/A (view + asset mới)".
- [ ] **Step 2: Cài đặt** khung view, `studio-core.js`, CSS khung. Xóa khỏi CSS các khối chỉ phục vụ Prompt Builder/livesearch trong tab Agent cũ **sau khi** Task 10 đã chuyển phần dùng lại; ở task này chỉ thêm CSS khung mới.
- [ ] **Step 3: Kiểm tra** — `php -l frontend/views/admin/ai_idol/index.php`; `node --check frontend/public/assets/js/studio-core.js`; `node --test frontend/public/assets/js/tests/`.
- [ ] **Step 4: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 10: Tab Thủ công (Wizard 4 bước)

**Files:**
- Modify: `frontend/views/admin/ai_idol/index.php` (nội dung `#manualFlow`), `frontend/public/assets/css/ai-idol-studio.css` (khối Wizard)
- Create: `frontend/public/assets/js/studio-manual.js`

**Interfaces:**
- Consumes: `StudioCore`, `StudioLogic` (`allowedStyles`, `toggleStyle`, `describeIntent`, `summaryBar`, `createLatestGate`, `NARRATION_STYLES`), route `admin_ai_idol_products_query` (Task 7, body như `normalizeProductQuery`), `admin_ai_idol_upload_asset`, `admin_ai_idol_create_campaign`.
- Produces: phát `studio:refresh` và `studio:open-item {kind: 'campaign', id}` sau khi tạo thành công.

Nội dung theo spec §4.3, kèm các quyết định:
- **Markup:** nhóm 1–4 dùng lại nhãn, số đếm (`$filterCounts`, `$catalogTree`) và giá trị option **y như view cũ**. Đổi `name`/`id` sang tiền tố `manual*` để không trùng.
- **Gọi API:** debounce 300 ms, `AbortController` hủy request trước, `createLatestGate` bỏ response cũ. Timeout 10 giây thì hiện lỗi kèm nút "Thử lại".
- **Lựa chọn sản phẩm:** `Map<id, {id, name, image}>` sống qua mọi lần lọc. Số lượng Top N tick sẵn N dòng đầu chỉ khi lần lọc đó người dùng chưa tick tay.
- **Kiểm tra từng bước:** bước 1 cần ≥1 SP. Bước 2: có livestream thì cần giờ tương lai, thời lượng 1–720 và ≥1 kênh; kênh chưa có trong `health().rtmp.platforms` thì hiện cảnh báo "Chưa cấu hình RTMP cho …". Bước 3: nhân vật riêng thì cần file hợp lệ (port `avatarMediaValid` và preview cũ).
- **Phong cách theo mục đích:** đổi mục đích ở bước 2 thì chạy lại `allowedStyles` ở bước 3, bỏ tick khóa giá và hiện câu giải thích: "“Tập trung chốt sale” và “Nhấn mạnh giá & ưu đãi” tạm tắt vì video sản phẩm không được nói giá."
- **Bước 4:** `describeIntent(sel)` trong khối "Ý định của bạn" (`aria-live="polite"`), các khối tóm tắt có nút "Sửa" nhảy về bước tương ứng.
- **Thanh tóm tắt** dùng `summaryBar(sel)`, cập nhật mỗi khi đổi lựa chọn.
- **Submit:** port payload cũ (`ai-idol-studio.js` dòng ~1005–1040) và thêm `configuration.narration_styles = sel.styles`. Khóa nút trong lúc gửi. Thành công thì reset Wizard về bước 1 và thông báo "Đã tạo chương trình. Theo dõi tiến độ ở bảng Hoạt động."
- **Escape** mọi chuỗi từ API bằng `escapeHtml`.

- [ ] **Step 1: Cài đặt** markup, `studio-manual.js`, CSS Wizard (2 cột bộ lọc/kết quả, dưới 1100px xếp dọc).
- [ ] **Step 2: Kiểm tra** — `php -l` view; `node --check frontend/public/assets/js/studio-manual.js`; `node --test frontend/public/assets/js/tests/`.
- [ ] **Step 3: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 11: Tab Tự động

**Files:**
- Modify: `frontend/views/admin/ai_idol/index.php` (nội dung `#autoFlow`), `frontend/public/assets/css/ai-idol-studio.css` (khối Tự động)
- Create: `frontend/public/assets/js/studio-auto.js`

**Interfaces:**
- Consumes: `StudioCore`, sự kiện `studio:data`, `studio:open-item {kind: 'run'}`, `studio:prefill-prompt`; route `admin_ai_idol_agent_create` (body `{brief, mode: 'autopilot', idempotency_key: 'studio:' + Date.now()}`).
- Produces: phát `studio:refresh` sau khi tạo run.

Nội dung theo spec §4.2, kèm các quyết định:
- **Ô prompt:** `maxlength=4000`; dưới 12 ký tự thì báo "Mô tả cần ít nhất 12 ký tự để Syna hiểu yêu cầu." và không gửi. Nút "Giao cho Syna" khóa trong lúc gửi.
- **6 chip mẫu** lấy nguyên văn từ bảng ở spec §4.2. Có nút "Khôi phục lệnh trước" (port từ code cũ).
- **Thẻ quyết định:**
  - Hiển thị run hiện tại: run vừa tạo, hoặc run mở từ `studio:open-item`, hoặc run autopilot mới nhất trong `studio:data`.
  - Biểu tượng theo level: `done` → `bi-check2-circle`, `adjusted` → `bi-exclamation-triangle`, `waiting` → `bi-lock`, `stopped` → `bi-x-circle`. Mỗi biểu tượng có nhãn chữ cho screen reader.
  - Sản phẩm lấy từ `run.plan.resolved_products`, kèm mục đích, giờ phát (`campaign_scheduled_at`), kênh và kênh chờ cho phép.
  - Run `stopped` có nút "Sửa prompt" đưa `run.brief` về ô nhập.
  - Run chưa có decision (đang xử lý) hiện "Syna đang đọc yêu cầu…".
- **`studio:prefill-prompt`:** điền brief vào ô và phát `studio:show-flow {flow: 'auto'}` (core nghe và chuyển tab).
- **Escape** mọi chuỗi bằng `escapeHtml`, kể cả `decision.message` và `run.brief`.

- [ ] **Step 1: Cài đặt** markup, `studio-auto.js`, CSS.
- [ ] **Step 2: Kiểm tra** — `php -l` view; `node --check frontend/public/assets/js/studio-auto.js`.
- [ ] **Step 3: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 12: Bảng Hoạt động

**[rcm]**
- Trạng thái trang sản phẩm của từng video lấy theo bản ghi `ai_product_videos`: "Đã đăng trang SP" hoặc "Chưa đăng".
- Video READY có link "Quản lý xuất bản" mở trang của Task 12b.
- Nút "Ẩn khỏi trang SP" kiểu cũ (`video_visibility`) bị bỏ khỏi Studio; việc ẩn/hiện làm ở trang của Task 12b.

**Files:**
- Modify: `frontend/views/admin/ai_idol/index.php` (nội dung `#activityPanel`), `frontend/public/assets/css/ai-idol-studio.css` (khối Hoạt động; xóa CSS cũ không còn selector nào dùng)
- Create: `frontend/public/assets/js/studio-activity.js`

**Interfaces:**
- Consumes: `StudioCore`, `StudioLogic` (`mergeActivity`, `filterActivity`, `progressSteps`, `needsYou`); routes `admin_ai_idol_campaigns`, `admin_ai_idol_agent_runs?limit=30`, `admin_ai_idol_campaign`, `admin_ai_idol_approve_script`, `admin_ai_idol_retry_campaign`, `admin_ai_idol_cancel_campaign`, `admin_ai_idol_reschedule_campaign`, `admin_ai_idol_video_visibility`, `admin_ai_idol_agent_retry`, `admin_ai_idol_agent_cancel`, `admin_ai_idol_approve_platform` (Task 7), `admin_ai_idol_stream_campaign`.
- Produces: là nơi **duy nhất** poll dữ liệu; phát `studio:data {campaigns, runs}` sau mỗi lần tải. Nghe `studio:refresh` và `studio:open-item`.

Nội dung theo spec §4.4, kèm các quyết định:
- **Poll:** 5 giây khi còn item có `progressSteps` đang `active`, ngược lại 30 giây. Dừng khi `document.hidden`, chạy lại khi tab hiện.
- **Thẻ:** tiêu đề, nhãn Tự động/Thủ công, badge trạng thái, thanh 7 bước (ẩn bước không áp dụng; ô bước có `aria-label` gồm nhãn và trạng thái), cảnh báo duyệt (`AiIdolApproval.approvalWarning`, giữ như cũ).
- **Bộ lọc:** Tất cả / Cần bạn (kèm số đếm từ `needsYou`) / Đã lên lịch.
- **Chi tiết chương trình:** port từ code cũ `loadDetail`, `renderSegment`, `productPageMarkup`, `broadcastTableMarkup`, `campaignControlsMarkup`, `runCampaignControl`, phần sửa và duyệt kịch bản, `seekToLivePosition`. Bổ sung:
  - segment `SKIPPED` hiện badge "Bỏ qua" kèm lý do;
  - log quyết định của run gắn kèm (cùng format icon với Task 11);
  - với mỗi kênh trong `pending_external_platforms`: nút "Cho phép phát <Kênh>" khi status thuộc nhóm cho phép, ngược lại dòng chữ "Đã hết hạn cho phép". Nút khóa trong lúc gửi; lỗi 400/409 hiện message từ server.
- **Chi tiết run chưa có campaign:** message hoặc warnings, decisions, nút "Thử lại" (`agent_retry`, khi `FAILED`), "Hủy" (`agent_cancel`), "Dùng lại prompt" (phát `studio:prefill-prompt`). Không có UI xác nhận sản phẩm/lịch kiểu cũ.
- **Escape** mọi chuỗi bằng `escapeHtml`.

- [ ] **Step 1: Cài đặt** markup, `studio-activity.js`, CSS. Sau đó dọn CSS: xóa khối selector không còn xuất hiện trong view hay trong JS mới (kiểm bằng `rg` từng selector trước khi xóa).
- [ ] **Step 2: Kiểm tra** — `php -l` view; `node --check` cả 5 file `studio-*.js`; `node --test frontend/public/assets/js/tests/`.
- [ ] **Step 3: Checkpoint** — `git diff --check` trên các file của task.

---

### Task 12b: Trang "Video trang sản phẩm & voucher" (D11)

**[rcm]** Port tab "Xuất bản" của `rcm_index` thành **trang admin riêng**, không nằm trong Studio.

**Files:**
- Create: `frontend/views/admin/product_videos.php`, `frontend/public/assets/js/admin-product-videos.js`, `frontend/public/assets/css/admin-product-videos.css`
- Modify:
  - `backend/app/controllers/AdminAiIdolController.php`: thêm method `productVideosPage()`;
  - `backend/app/routes/ai_idol.php` và hai danh sách route ở `backend/public/index.php`, `frontend/public/index.php`: thêm route `admin_ai_product_videos` (GET);
  - `frontend/views/admin/layouts/header.php`: thêm link menu "Video trang sản phẩm".
- Nguồn để port:
  - `git show origin/rcm_index:frontend/views/admin/ai_idol/index.php` (phần `#pubFlow`, `#pubModal`, `#rewardModal`);
  - `git show origin/rcm_index:frontend/public/assets/js/ai-idol-studio.js` (các hàm của tab publication và reward);
  - `git show origin/rcm_index:frontend/public/assets/css/ai-idol-studio.css` (style `pub*`, `reward*`).

**Yêu cầu:**
- Giữ nguyên hành vi và các route `rcm_index` đang dùng: `admin_ai_idol_product_videos`, `search_products`, `publish_video`, `toggle_publish`, `reward_campaign`, `save_reward_campaign`, `toggle_reward_campaign`, kèm CSRF.
- Mỗi video có thêm badge "Tự động" khi bản ghi có `source: "auto"`.
- Trang nhận `?video_id=<id>` để mở sẵn modal xuất bản cho video đó.
- Trong Task 12, chi tiết video READY ở bảng Hoạt động có link "Quản lý xuất bản" trỏ tới `index.php?r=admin_ai_product_videos&video_id=<id>`.
- Studio không nạp bất kỳ file nào của trang này.

- [ ] **Step 1: Cài đặt.**
- [ ] **Step 2: Kiểm tra:** `php -l`; `node --check`; `php backend/tests/admin_usecase_contract_test.php`. Nếu test của `rcm_index` (`backend/tests/test_manageable_videos.php`, `test_admin_csrf_rbac.php`) chạy được không cần DB thật thì chạy.
- [ ] **Step 3: Checkpoint.**

---

### Task 13: E2E, dọn dẹp và kiểm tra toàn bộ

**[rcm]** E2E thêm 2 kịch bản:
1. Mở `admin_ai_product_videos`: danh sách video tải được; mở modal xuất bản không có console error.
2. Một chương trình video sản phẩm sau khi READY có bản ghi `source: "auto"` và video phát được trên trang chi tiết của sản phẩm đó. Kịch bản này chỉ chạy khi có video READY thật; nếu không có thì ghi `NOT RUN` kèm lý do.

Build lại cả image `ai-idol-agent-service`. Restart `php-backend` nếu cần nạp `vendor/` mới.

**Files:**
- Create: `scripts/e2e/ai-idol-studio.e2e.cjs`
- Delete: `frontend/public/assets/js/ai-idol-studio.js` (chỉ sau khi E2E PASS; xác nhận bằng `rg "ai-idol-studio.js"` không còn chỗ nào nạp file)

**Interfaces:**
- Consumes: toàn bộ task trước.
- E2E đọc `E2E_BASE_URL` (mặc định `http://localhost:8080`), `E2E_ADMIN_EMAIL`, `E2E_ADMIN_PASSWORD` giống `scripts/e2e/product-video.e2e.cjs`. Thiếu biến đăng nhập thì in `E2E NOT RUN: thiếu E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD` và exit 2. Không hardcode mật khẩu.

- [ ] **Step 1: Build lại và chạy lại dịch vụ AI Idol** để nạp code mới. Trước đó kiểm không có job đang render: `docker logs --tail 50 skinsyntax-ai-idol-worker` không có job đang chạy. Nếu có thì chờ. Sau đó:
  - `docker compose build ai-idol-service`
  - `docker compose up -d ai-idol-service ai-idol-worker ai-idol-control-worker ai-idol-broadcast-worker ai-idol-beat`

  Kiểm tra: `curl -s http://localhost:5003/api/health` trả `ok`. Không đụng các service khác.
- [ ] **Step 2: Viết E2E** `scripts/e2e/ai-idol-studio.e2e.cjs`. Chạy ở viewport 1440×1000 và 390×844, thu console error. Kịch bản:
  1. Đăng nhập admin, mở Studio, thấy 2 tab; tab mặc định là Tự động; `document.documentElement.scrollWidth <= window.innerWidth` ở cả hai viewport.
  2. **Thủ công:**
     - Chọn "Bán chạy · toàn kỳ" và "Top 3" → đợi kết quả → đúng 3 sản phẩm được tick.
     - Tiếp tục: Video sản phẩm → chọn "Tư vấn nhẹ nhàng"; "Tập trung chốt sale" bị khóa.
     - Bước 4: câu ý định bắt đầu bằng "Syna làm video sản phẩm (không nói giá) cho 3 sản phẩm:".
     - Tạo → thẻ mới xuất hiện trong Hoạt động với nhãn "Thủ công".
     - Bấm đúp nút Tạo chỉ tạo 1 chương trình (so số thẻ trước và sau).
  3. **Tự động:** gửi `Làm video giới thiệu sản phẩm mã <mã có thật lấy từ bước 2> <img src=x onerror=alert(1)>`. Trong 60 giây thẻ quyết định có ≥1 dòng và không chứa phần tử `img[src="x"]`. Thẻ Hoạt động mới có nhãn "Tự động".
  4. Bấm "Cần bạn": số trên nút bằng số thẻ hiển thị.
  5. Không có console error.

  Dữ liệu tạo ra trong E2E: ghi tên chương trình vào output để người dùng hủy hoặc ẩn nếu muốn. Không xóa dữ liệu.
- [ ] **Step 3: Chạy E2E** — `NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" node scripts/e2e/ai-idol-studio.e2e.cjs`. Phải PASS, hoặc báo rõ `E2E NOT RUN` và lý do.
- [ ] **Step 4: Xóa** `frontend/public/assets/js/ai-idol-studio.js` nếu E2E PASS, rồi chạy lại E2E một lần.
- [ ] **Step 5: Kiểm tra toàn bộ:**
  - `IDOL_TEST discover -s tests -t .` phải OK, với số test ≥ 448 + số test mới.
  - `node --test frontend/public/assets/js/tests/`.
  - `php backend/tests/ai_idol_studio_proxy_test.php` và `php backend/tests/admin_usecase_contract_test.php`.
  - `php -l` mọi file PHP đã đổi; `node --check` mọi file JS mới.
  - `git diff --check`.
- [ ] **Step 6: GitNexus** — `node .gitnexus/run.cjs detect-changes --scope all --repo .`. Ghi lại kết quả, kể cả `partial`/`truncated`. Không commit.

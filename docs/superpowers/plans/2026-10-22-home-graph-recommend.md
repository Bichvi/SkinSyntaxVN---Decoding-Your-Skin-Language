# Trang chủ cá nhân hóa bằng Neo4j (Giai đoạn 1) — Kế hoạch triển khai

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Thêm 3 khối gợi ý dựa trên Neo4j vào trang chủ ("Vì bạn đã xem", "Hợp với da bạn", "Theo vấn đề da" dạng tab) kèm nhãn lý do, không đụng các thuật toán gợi ý hiện có.

**Architecture:** Flask có module mới `home_service` (blueprint `POST /api/home/recommend`) đọc Neo4j qua `search_service.neo4j_client` sẵn có, chấm điểm trong Python, cache ứng viên Redis 600s, chỉ trả `product_id` + mã lý do. PHP có client mới (cầu dao riêng, timeout 800ms) + service hydrate SP từ Mongo + 3 partial; tab vấn đề da tải qua route `home_concern`. Mọi lỗi → khối mới ẩn, khối cũ nguyên vẹn.

**Tech Stack:** Python 3 / Flask / neo4j driver 5 / Redis (`shared.redis_store.get_redis`) / pytest; PHP 8 (MVC tự viết) / MongoDB Atlas; vanilla JS; Playwright cho E2E.

**Spec:** `docs/superpowers/specs/2026-10-22-home-graph-recommend-design.md`

## Global Constraints

- **Không xóa, không sửa logic**: `SanPham::getSimpleRecommenderProducts`, `SanPham::getHybridRecommendations`, `SanPham::getHomepageProductSections`, khối 2/3/4/5.5/6/7 trong `frontend/views/home.php`, hàm `$renderHomeProductCard`, `SearchRecommendClient`, `/api/search/recommend`, `search_service/graph_repository.py`.
- `HomeController::index`: chỉ **thêm** dòng sau lời gọi `getHomepageProductSections(...)`; không đổi lời gọi/biến cũ truyền cho view (chỉ thêm khóa `homeGraph`).
- PHP không kết nối Neo4j. Giá/ảnh/tồn kho đọc lại từ Mongo (`SanPham::findVisibleByIds`).
- 9 concern key: `mun, tham_mun, nam_tan_nhang, lo_chan_long, dau_thua, kho_da, kich_ung, lao_hoa, xin_mau`. Concern mặc định: `skin_profile.concerns[0]`, không có → `tham_mun`.
- 5 skin type key: `da_dau, da_kho, da_nhay_cam, da_mun, moi_loai_da`.
- Limits: `seen` 4, `skin` 4, `concern` 8. Ngưỡng hiển thị: ≥ 4 SP. Tối đa 2 SP/thương hiệu ở `seen`.
- Ưu tiên loại trùng: `seen → skin → concern`.
- Request: `seen_ids` ≤ 5, `cart_ids` ≤ 50, `exclude_ids` ≤ 100, id khớp `^[0-9]{1,12}$`.
- "Thành phần chính": `CONTAINS.rank ≤ 5` và `role` không thuộc `search_service.graph_repository.GENERIC_ROLES` (import, không chép).
- Cache Flask TTL 600s; PHP timeout 800ms tổng / 300ms connect, không retry; cầu dao 3 lỗi → mở 60s, key `home:recommend:breaker:v1`.
- Cờ `HOME_GRAPH_ENABLED` mặc định bật; tắt → PHP không gọi Flask.
- Log không chứa email, hồ sơ da, giỏ hàng, token. Câu lý do không in tên thương hiệu.
- Không chạy hàng loạt `backend/tests` (nhiều script ghi DB thật); chỉ chạy đúng file test mới của kế hoạch này.
- Commit chỉ khi người dùng yêu cầu (AGENTS.md); bước "Commit" trong từng task = chuẩn bị `git add` + message, chờ người dùng cho phép. Trước commit chạy `node .gitnexus/run.cjs detect-changes --scope all --repo .`.
- Trước khi sửa symbol có sẵn (`HomeController::index`, router `index.php`, `create_app` trong `chatbot_flask.py`): chạy `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`. `HomeController::index` đã kiểm: risk UNKNOWN, nơi gọi thật (text search) = `index.php` `case 'home'` + `backend/tests/verify_step9_tests.php`.

## Review Focus

1. **Session `recent_viewed_products` chứa id rác/không phải số hoặc SP đã ngừng bán** → bị lọc, khối "Vì bạn đã xem" ẩn hoặc dùng seed hợp lệ còn lại, không lỗi. (Test: Task 1 `test_invalid_ids_dropped`, Task 5 `HomeGraphRecommender drops non-digit seen ids`.)
2. **Flask trả id mà Mongo không còn (SP bị ẩn sau lần graph_sync)** → PHP bỏ thẻ đó; còn < 4 thì ẩn khối, không in thẻ rỗng. (Test: Task 5 `hydrate drops missing ids and re-applies threshold`.)
3. **Giỏ hàng chứa SP có thành phần kỵ** → mọi khối mới không gợi ý SP xung đột. (Test: Task 3 `test_conflicting_candidates_removed_from_all_sections`.)
4. **Tab vấn đề da với `c` lạ / XSS (`c=<script>`)** → 400, không phản chiếu input. (Test: Task 6 E2E step + `HomeController::concern` kiểm whitelist trước khi gọi client.)
5. **Neo4j vừa khởi động / cầu dao mở / Redis tắt** → Flask trả `degraded:true, sections:[]` (Neo4j) hoặc tính trực tiếp (Redis); trang chủ vẫn đủ khối cũ. (Test: Task 3 `test_neo4j_down_returns_degraded`, `test_redis_down_computes_directly`; Task 7 E2E bước tắt Flask.)

---

## File Structure

| File | Trách nhiệm |
|---|---|
| `ai-service-flask/home_service/__init__.py` | Đánh dấu package. |
| `ai-service-flask/home_service/config.py` | Hằng số + env (`HOME_GRAPH_CACHE_TTL`, limits, keys, weights). |
| `ai-service-flask/home_service/schemas.py` | `HomeRequest` + `validate_home_request`. |
| `ai-service-flask/home_service/contract/home_recommend_response.schema.json` | JSON schema response. |
| `ai-service-flask/home_service/repository.py` | Câu Cypher + `HomeGraphRepository` (trả đặc trưng thô, không chấm điểm). |
| `ai-service-flask/home_service/service.py` | Chấm điểm, cache, lọc, loại trùng, ngưỡng, dựng response. |
| `ai-service-flask/home_service/routes.py` | Blueprint `home_bp`. |
| `ai-service-flask/chatbot_service/chatbot_flask.py` | Thêm 2 dòng đăng ký `home_bp`. |
| `ai-service-flask/tests/home_service/*` | Test pytest. |
| `backend/app/services/HomeRecommendClient.php` | HTTP client + cầu dao + validate. |
| `backend/app/services/HomeReasonText.php` | Mã lý do → câu tiếng Việt. |
| `backend/app/services/HomeGraphRecommender.php` | Gom input, gọi client, hydrate, áp ngưỡng. |
| `backend/app/controllers/HomeController.php` | Thêm dòng trong `index`, thêm `concern()`. |
| `index.php` | Thêm `case 'home_concern'`. |
| `frontend/views/partials/home_graph_seen.php`, `home_graph_skin.php`, `home_graph_concern.php`, `home_graph_concern_grid.php` | Giao diện khối mới (`_grid` dùng chung cho render đầu và AJAX). |
| `frontend/views/home.php` | Thêm 3 `include` + 1 `<script>`. |
| `frontend/assets/js/home-graph.js` | Chuyển tab vấn đề da. |
| `backend/tests/home_recommend_client_test.php`, `home_reason_text_test.php`, `home_graph_recommender_test.php` | Test PHP thuần (không DB). |
| `.env.example`, `docker-compose.yml` | Biến `HOME_GRAPH_ENABLED`, `HOME_RECOMMEND_ENDPOINT`, `HOME_GRAPH_CACHE_TTL`. |
| `update_home_graph_recommend.md`, `update.md` | Tài liệu bàn giao (quy ước `update_<việc>.md`). |

Lệnh Python dùng chung (từ `ai-service-flask/`): `../.venv/Scripts/python.exe -m pytest tests/home_service -q`.

---

### Task 1: Flask — request/response contract

**Files:**
- Create: `ai-service-flask/home_service/__init__.py`, `config.py`, `schemas.py`, `contract/home_recommend_response.schema.json`
- Test: `ai-service-flask/tests/home_service/conftest.py` (chép mẫu `tests/search_service/conftest.py`: thêm ROOT vào `sys.path`, marker `integration`), `tests/home_service/test_schemas.py`

**Interfaces:**
- Produces:
  - `config.CONCERN_KEYS: tuple[str, ...]`, `config.SKIN_TYPE_KEYS`, `config.SECTION_KEYS = ("seen", "skin", "concern")`, `config.DEFAULT_CONCERN = "tham_mun"`, `config.DEFAULT_LIMITS = {"seen": 4, "skin": 4, "concern": 8}`, `config.MIN_ITEMS = 4`, `config.MAX_PER_BRAND = 2`, `config.SEEN_WEIGHTS = (1.0, 0.8, 0.6, 0.4, 0.2)`, `config.CACHE_TTL = max(0, int(env HOME_GRAPH_CACHE_TTL, 600))`, `config.CANDIDATE_LIMIT = 60`, `config.REASON_CODES = ("seen_shared_ingredient", "seen_same_category", "skin_fit", "concern_ingredient", "concern_target")`.
  - `@dataclass(frozen=True) HomeRequest(sections: tuple[str, ...], seen_ids: tuple[str, ...], skin_type: str | None, concerns: tuple[str, ...], concern: str, cart_ids: tuple[str, ...], exclude_ids: tuple[str, ...], limits: dict[str, int])`
  - `validate_home_request(payload: Any) -> HomeRequest` — raises `search_service.schemas.ValidationError(field)`.

- [ ] **Step 1: Write failing tests** in `test_schemas.py`:
  - `test_minimal_payload_defaults`: `validate_home_request({"sections": ["concern"]})` → `concern == "tham_mun"`, `limits == {"seen": 4, "skin": 4, "concern": 8}`, `seen_ids == ()`, `skin_type is None`.
  - `test_concern_defaults_to_first_profile_concern`: `{"sections":["concern"],"skin_profile":{"skin_type":"da_dau","concerns":["mun","kho_da"]}}` → `concern == "mun"`, `skin_type == "da_dau"`.
  - `test_unknown_section_rejected`: `{"sections":["foo"]}` → `ValidationError`, `.field == "sections"`; `{"sections": []}` cũng vậy.
  - `test_invalid_ids_dropped`: `seen_ids=["12","abc","","9"*13,"12","34"]` → `("12","34")` (bỏ không hợp lệ, bỏ trùng, giữ thứ tự).
  - `test_list_caps`: 7 seen → 5 phần tử; 60 cart → 50; 150 exclude → 100.
  - `test_bad_concern_rejected`: `concern="xyz"` → `ValidationError("concern")`; skin_type lạ → `skin_type is None` (bỏ qua, không lỗi); concerns lạ trong profile bị lọc.
  - `test_limits_clamped`: `limits={"concern": 50, "seen": 0}` → concern 12, seen 4 (clamp [4, 12]).
  - `test_payload_not_dict`: `None`, `[]`, `"x"` → `ValidationError("body")`.
- [ ] **Step 2:** Run `../.venv/Scripts/python.exe -m pytest tests/home_service/test_schemas.py -q` → FAIL (ModuleNotFoundError `home_service`).
- [ ] **Step 3:** Implement `config.py`, `schemas.py` theo interface; schema JSON mô tả đúng spec §2.3: `degraded` bool, `took_ms` int ≥ 0, `sections[]` với `key` ∈ SECTION_KEYS, `items[]` `{product_id: ^[0-9]{1,12}$, reason: {code ∈ REASON_CODES, ...}}`, trường tùy chọn `seed{id,name}`, `skin_type{key,name}`, `concern{key,name}`; `additionalProperties` cho phép ở `reason`.
- [ ] **Step 4:** Run lại → PASS.
- [ ] **Step 5: Commit** (chờ người dùng cho phép): `feat(home-graph): request schema + response contract`.

---

### Task 2: Flask — Neo4j repository

**Files:**
- Create: `ai-service-flask/home_service/repository.py`
- Test: `ai-service-flask/tests/home_service/test_repository_integration.py` (mẫu fixture `repo` giống `tests/search_service/test_graph_integration.py`, `pytestmark = pytest.mark.integration`)

**Interfaces:**
- Consumes: `config.CANDIDATE_LIMIT`, `GENERIC_ROLES` từ `search_service.graph_repository`.
- Produces: `HomeGraphRepository(driver, timeout_s: float | None = None)` với `_run` giống `GraphRepository._run` (dùng `neo4j.Query(cypher, timeout=...)`). Mọi query chỉ trả SP `active=true AND coalesce(in_stock,true)=true`, `LIMIT $limit` (= CANDIDATE_LIMIT), sắp `... DESC, sold DESC, id ASC`.
  - `seen_candidates(seed_id: str) -> list[dict]` — hàng `{id, sim: float, shared: list[str] (≤3, thành phần chính chung), brand: str|None, sold: float}`; ứng viên = SP nối với seed qua `SIMILAR_TO` (không hướng), loại chính seed.
  - `seed_names(ids: list[str]) -> dict[str, str]` — `id → name`.
  - `skin_candidates(skin_type: str, concerns: list[str]) -> list[dict]` — `{id, fit: "data"|"inferred"|"all", concern_names: list[str] (≤2), sold}`; `fit` = `data` nếu có cạnh `SUITABLE_FOR{source:'data'}` tới đúng loại da, `inferred` nếu cạnh đúng loại da khác, `all` nếu chỉ tới `moi_loai_da`.
  - `concern_candidates(concern: str, skin_type: str | None) -> list[dict]` — `{id, helps: list[str] (≤2, thành phần chính có HELPS→concern), skin_fit: bool, sold, rating: float|null, brand}`; ứng viên = SP `TARGETS` concern.
  - `conflicting_ids(cart_ids: list[str], candidate_ids: list[str]) -> set[str]` — candidate có thành phần `CONFLICTS_WITH` (không hướng) với thành phần của SP trong giỏ; `cart_ids` rỗng → `set()` không chạy query.
  - `label_name(label: str, key: str) -> str | None` — `label` chỉ nhận `"SkinType"` hoặc `"Concern"` (khác → `ValueError`), trả `name`.

- [ ] **Step 1: Write failing integration tests** (đọc dữ liệu thật, chỉ đọc):
  - `test_seen_candidates_shape`: lấy 1 SP có `SIMILAR_TO` (query phụ trong test), kết quả không rỗng, mỗi hàng đủ khóa, không chứa seed, ≤ 60.
  - `test_skin_candidates_fit_values`: `skin_candidates("da_dau", ["tham_mun"])` không rỗng, `fit ∈ {"data","inferred","all"}`.
  - `test_concern_candidates_targets`: `concern_candidates("mun", None)` ≥ 8 hàng; mọi id thực sự `TARGETS` Concern `mun` (kiểm bằng query phụ).
  - `test_conflicting_ids_empty_cart`: `conflicting_ids([], ["1"]) == set()`.
  - `test_label_name`: `label_name("Concern","tham_mun") == "Thâm mụn"`; `label_name("Product","1")` → `ValueError`.
  - `test_queries_fast`: mỗi query (cache Neo4j ấm, chạy lần 2) < 300ms.
- [ ] **Step 2:** Run `NEO4J_TEST_URI=bolt://localhost:7687 NEO4J_PASSWORD=<từ .env> ../.venv/Scripts/python.exe -m pytest tests/home_service/test_repository_integration.py -m integration -q` → FAIL (ImportError). (Không in mật khẩu ra log; đọc từ `.env` bằng biến môi trường.)
- [ ] **Step 3:** Implement `repository.py`. Dùng `EXISTS { }` và các index sẵn có (`product_active`, `product_id`); không tạo index mới.
- [ ] **Step 4:** Run lại → PASS (6 test). Nếu Neo4j không chạy → SKIP; ghi rõ là chưa xác minh.
- [ ] **Step 5: Commit** (chờ cho phép): `feat(home-graph): neo4j repository for home sections`.

---

### Task 3: Flask — service (chấm điểm, lọc, cache)

**Files:**
- Create: `ai-service-flask/home_service/service.py`
- Test: `ai-service-flask/tests/home_service/test_service.py` (repository giả = class trong test trả hàng cố định; cache giả = dict)

**Interfaces:**
- Consumes: `HomeRequest`, `HomeGraphRepository` (Task 2), `search_service.neo4j_client.get_driver/is_open/report_failure`, `shared.redis_store.get_redis`.
- Produces:
  - `@dataclass Candidate(id: str, score: float, sold: float, brand: str | None, reason: dict)`
  - `score_seen(rows_by_seed: list[tuple[str, str, list[dict]]]) -> list[Candidate]` — tham số là `(seed_id, seed_name, rows)` theo thứ tự MRU.
  - `score_skin(rows: list[dict], skin_type_name: str) -> list[Candidate]`
  - `score_concern(rows: list[dict], concern_name: str) -> list[Candidate]`
  - `pick(cands: list[Candidate], used: set[str], blocked: set[str], limit: int, max_per_brand: int | None = None) -> list[Candidate]`
  - `class HomeRecommendService(repo_factory: Callable[[], HomeGraphRepository | None], cache: CandidateCache | None = None)`; `recommend(req: HomeRequest) -> dict` (response đúng schema Task 1).
  - `class CandidateCache` với `get(key) -> list[dict] | None`, `set(key, rows, ttl)`; `RedisCandidateCache()` bọc `get_redis()`, mọi lỗi Redis → coi như miss/no-op.
  - `get_default_service() -> HomeRecommendService` (singleton; `repo_factory` trả `None` khi `neo4j_client.is_open()` hoặc `get_driver()` là `None`; timeout repo = `search_service.config.SEARCH_TIMEOUT_MS / 1000`).

Công thức (spec §3, chép đúng):

```
seen:    score = Σ_seed  w[i] × (row.sim + 0.05 × len(row.shared))      w = SEEN_WEIGHTS theo vị trí seed
         reason = shared → {"code":"seen_shared_ingredient","seed_id","seed_name","ingredients": shared[:2]}
                  không  → {"code":"seen_same_category","seed_id","seed_name"}
         (seed đóng góp lớn nhất quyết định reason)
skin:    base = {"data":1.0, "inferred":0.7, "all":0.4}[fit]
         score = base + 0.3 × len(concern_names) + 0.1 × log10(1 + sold) / 4
         reason = {"code":"skin_fit","skin_type": skin_type_name,"concerns": concern_names[:2]}
concern: score = 0.5 × (helps≠[]) + 0.2 × skin_fit + 0.3 × log10(1 + sold) / 4 + 0.1 × (rating or 0) / 5
         reason = helps → {"code":"concern_ingredient","concern": concern_name,"ingredients": helps[:2]}
                  không → {"code":"concern_target","concern": concern_name}
sắp xếp: score DESC, sold DESC, id ASC
```

Luồng `recommend`: repo `None` → `{"degraded": True, "took_ms", "sections": []}`. Ngược lại, theo thứ tự `seen → skin → concern` (chỉ section có trong `req.sections`): lấy rows (qua cache key `home:cand:seen:<id>`, `home:cand:skin:<skin>:<concerns sắp xếp nối ','>`, `home:cand:concern:<concern>:<skin|_>`, TTL `CACHE_TTL`) → chấm điểm → `blocked = cart ∪ exclude ∪ seen_ids ∪ conflicting_ids(cart, all_candidate_ids)` (gọi `conflicting_ids` **một lần** cho hợp tất cả ứng viên) → `pick` (seen dùng `max_per_brand=2`) → `< MIN_ITEMS` thì bỏ section; `used` cộng dồn. `skin` chỉ chạy khi `req.skin_type`. Lỗi kết nối Neo4j trong lúc query → `report_failure(exc)` + trả degraded. Log 1 dòng: correlation id, took_ms, keys trả về, degraded.

- [ ] **Step 1: Write failing tests:**
  - `test_score_seen_weights_mru`: 2 seed cùng trỏ SP "9" (sim 0.5, shared 1 tên mỗi seed) → score = 1.0×0.55 + 0.8×0.55 = 0.99 (±1e-9); reason `seen_shared_ingredient`, `seed_id` = seed đầu.
  - `test_score_seen_without_shared_uses_same_category`.
  - `test_score_skin_formula`: fit `data`, 1 concern, sold 999 → `1.0 + 0.3 + 0.1*3/4 = 1.375`.
  - `test_score_concern_formula`: helps=["Niacinamide"], skin_fit True, sold 9999, rating 5 → `0.5+0.2+0.3+0.1 = 1.1`; reason `concern_ingredient`, ingredients `["Niacinamide"]`.
  - `test_pick_brand_cap_and_tiebreak`: 4 SP cùng brand "a" + 2 brand "b", limit 4, cap 2 → đúng 2 "a" + 2 "b"; hòa điểm sắp theo sold rồi id.
  - `test_dedupe_priority_seen_skin_concern`: SP "1" có ở cả 3 → chỉ nằm trong `seen`.
  - `test_section_below_threshold_omitted`: concern chỉ 3 ứng viên → không có section `concern`.
  - `test_conflicting_candidates_removed_from_all_sections`: fake `conflicting_ids` trả {"5"} → "5" không xuất hiện ở section nào; `conflicting_ids` được gọi đúng 1 lần.
  - `test_skin_skipped_without_skin_type`.
  - `test_neo4j_down_returns_degraded`: `repo_factory` trả `None` → `degraded True`, `sections == []`.
  - `test_query_error_reports_failure`: fake repo ném exception kết nối → `report_failure` được gọi (monkeypatch), response degraded.
  - `test_redis_down_computes_directly`: cache có `get/set` ném lỗi → vẫn trả section đúng.
  - `test_cache_hit_skips_repo`: lần 2 cùng request không gọi `concern_candidates` nữa.
  - `test_response_matches_contract`: validate response mẫu bằng `jsonschema` với file schema Task 1 (`pytest.importorskip("jsonschema")`).
- [ ] **Step 2:** Run `../.venv/Scripts/python.exe -m pytest tests/home_service/test_service.py -q` → FAIL.
- [ ] **Step 3:** Implement `service.py` theo interface + công thức.
- [ ] **Step 4:** Run → PASS (14 test).
- [ ] **Step 5: Commit** (chờ cho phép): `feat(home-graph): scoring, dedupe, cache service`.

---

### Task 4: Flask — route + đăng ký blueprint

**Files:**
- Create: `ai-service-flask/home_service/routes.py`
- Modify: `ai-service-flask/chatbot_service/chatbot_flask.py` (cạnh dòng ~3126-3129 `from search_service.routes import search_bp` / `app.register_blueprint(search_bp)`; chạy impact cho hàm chứa trước khi sửa)
- Test: `ai-service-flask/tests/home_service/test_routes.py` (Flask app tối thiểu `Flask(__name__)` + `register_blueprint(home_bp)`, monkeypatch `get_default_service`)

**Interfaces:**
- Consumes: `search_service.routes.is_authorized`, `validate_home_request`, `get_default_service`.
- Produces: `home_bp = Blueprint("home_service", __name__)`; `POST /api/home/recommend` → 200 JSON; header `X-Request-Id` (lấy từ request hoặc uuid4, ≤ 64 ký tự); 401 `{"error":"unauthorized"}`; 400 `{"error":"invalid_request","field": f}`; exception bất kỳ trong service → 200 `{"degraded": true, "took_ms": 0, "sections": []}` và log `type(exc).__name__` (không stack có dữ liệu).

- [ ] **Step 1: Write failing tests:** `test_unauthorized_without_token` (monkeypatch `search_service.config.INTERNAL_TOKEN="t"`, không header → 401; header đúng → 200), `test_invalid_payload_400` (`{"sections":["x"]}` → 400, `field=="sections"`), `test_service_exception_degrades` (service ném `RuntimeError` → 200 degraded), `test_request_id_echoed`.
- [ ] **Step 2:** Run `../.venv/Scripts/python.exe -m pytest tests/home_service/test_routes.py -q` → FAIL.
- [ ] **Step 3:** Implement `routes.py`; thêm 2 dòng import/register `home_bp` trong `chatbot_flask.py` ngay sau `search_bp`.
- [ ] **Step 4:** Run toàn bộ `tests/home_service tests/search_service -q` (không marker integration) → PASS, search_service không hỏng.
- [ ] **Step 5:** Recreate service AI và gọi thật: `docker compose up -d --force-recreate ai-service`, rồi `docker exec skinsyntax-ai curl -s -X POST localhost:5001/api/home/recommend -H 'Content-Type: application/json' -d '{"sections":["concern"]}'` → `degraded:false`, section `concern` 8 items. Ghi `took_ms` lần 1 và lần 2 (lần 2 có cache).
- [ ] **Step 6: Commit** (chờ cho phép): `feat(home-graph): /api/home/recommend blueprint`.

---

### Task 5: PHP — client, câu lý do, recommender

**Files:**
- Create: `backend/app/services/HomeRecommendClient.php`, `HomeReasonText.php`, `HomeGraphRecommender.php`
- Test: `backend/tests/home_recommend_client_test.php`, `home_reason_text_test.php`, `home_graph_recommender_test.php` (kiểu `product_video_client_test.php`: hàm `check()`, exit 1 khi có FAIL; không DB, không mạng)

**Interfaces:**
- Consumes: response Flask (Task 1 schema); `SearchSkinProfile::CONCERNS`, `SearchSkinProfile::SKIN_TYPES`; `SearchBreakerRedis` (định nghĩa trong `SearchRecommendClient.php`, `require_once` file đó — không sửa).
- Produces:
  - `final class HomeRecommendClient`: `__construct(string $baseUrl, string $internalToken, int $timeoutMs = 800, int $connectTimeoutMs = 300, ?callable $transport = null, ?array $stateStore = null)`; `static fromEnv(): ?self` (null khi `HOME_GRAPH_ENABLED` ∈ `0,false,off,no`; URL = `HOME_RECOMMEND_ENDPOINT`, mặc định suy từ host của `AI_CHAT_ENDPOINT` + `/api/home/recommend`; token `SEARCH_INTERNAL_TOKEN`; timeout `HOME_RECOMMEND_TIMEOUT_MS` (min 200) / `HOME_RECOMMEND_CONNECT_TIMEOUT_MS` (min 100)); `recommend(array $payload): ?array`; `static validateResponse(array $data): ?array` (bỏ item sai, bỏ section key lạ, trả null nếu thiếu `sections`/`degraded`); `isOpen(): bool`. Transport signature giống `SearchRecommendClient`. Breaker key `home:recommend:breaker:v1`, 3 lỗi → mở 60s; dùng `SEARCH_BREAKER_REDIS_URL`.
  - `final class HomeReasonText`: `static fromReason(array $reason): string` — bảng spec §3.5; seed rút gọn 30 ký tự (`mb_strimwidth(..., 0, 30, '…')`); vấn đề viết thường bằng `mb_strtolower`.
  - `final class HomeGraphRecommender`: `__construct(?HomeRecommendClient $client, callable $findVisibleByIds)`; `build(array $ctx): array` với `$ctx = ['seen_ids'=>string[], 'skin_profile'=>?array, 'cart_ids'=>string[], 'exclude_ids'=>string[], 'concern'=>?string, 'sections'=>string[]]` → `['seen'=>?array, 'skin'=>?array, 'concern'=>?array, 'concern_keys'=>array]`; mỗi khối = `['title_data'=>array (seed / skin_type / concern từ response), 'products'=>array<array SP Mongo + 'reason_text' + 'reason_code'>]`; `concern_keys` = danh sách `[key=>name]` 9 vấn đề (tên tiếng Việt cố định trong class, khớp node `Concern.name`: Mụn, Thâm mụn, Nám / tàn nhang, Lỗ chân lông to, Dầu thừa, Khô / thiếu ẩm, Kích ứng / mẩn đỏ, Lão hóa / nếp nhăn, Da xỉn màu). Client null / response null → mọi khối null. Hydrate theo thứ tự Flask, bỏ id Mongo không trả, `< 4` → null. Mọi exception → log `[HOME_GRAPH] ` + `get_class($e)` và trả khối null.

- [ ] **Step 1: Write failing tests:**
  - client: transport lỗi (`status 0`) → null; 500 → null; JSON hỏng → null; response hợp lệ → mảng đã validate; item `product_id` "abc" bị bỏ; 3 lỗi liên tiếp → lần 4 không gọi transport (`isOpen()` true, đếm số lần gọi); header gửi có `X-Internal-Token` khi token khác rỗng; `fromEnv()` null khi `putenv('HOME_GRAPH_ENABLED=0')`.
  - reason: mỗi code trong bảng §3.5 → đúng câu (vd `['code'=>'concern_ingredient','concern'=>'Thâm mụn','ingredients'=>['Niacinamide']]` → `Chứa Niacinamide, hỗ trợ thâm mụn`; `skin_fit` không concerns → `Phù hợp Da dầu`; seed 40 ký tự bị rút gọn; code lạ → `Gợi ý cho bạn`).
  - recommender: `drops non-digit seen ids` (ctx seen `['12','x']` → payload gửi đi chỉ `['12']`, bắt bằng transport giả); `hydrate drops missing ids and re-applies threshold` (Flask trả 4 id, Mongo giả trả 3 → `concern` null); thứ tự SP giữ theo Flask; client null → cả 3 null; `concern_keys` đủ 9.
- [ ] **Step 2:** Run `php backend/tests/home_recommend_client_test.php; php backend/tests/home_reason_text_test.php; php backend/tests/home_graph_recommender_test.php` (trong container: `docker exec -w /var/www/html skinsyntax-php php backend/tests/<file>` nếu host không có PHP) → FAIL.
- [ ] **Step 3:** Implement 3 class.
- [ ] **Step 4:** Run lại 3 test → exit 0, toàn PASS; `php -l` cho 3 file mới.
- [ ] **Step 5: Commit** (chờ cho phép): `feat(home-graph): PHP client, reason text, recommender`.

---

### Task 6: PHP — controller, route, cấu hình

**Files:**
- Modify: `backend/app/controllers/HomeController.php` (`index`: ngay sau khối gọi `getHomepageProductSections` ~dòng 204-206 và trước `render`; thêm method `concern()`)
- Modify: `index.php` (thêm `case 'home_concern': (new HomeController($pdo))->concern(); break;` cạnh `case 'home'`)
- Modify: `.env.example` (khối Neo4j/search: `HOME_GRAPH_ENABLED=1`, `HOME_RECOMMEND_ENDPOINT=`, `HOME_RECOMMEND_TIMEOUT_MS=800`, `HOME_GRAPH_CACHE_TTL=600`), `docker-compose.yml` (php-backend: `HOME_GRAPH_ENABLED: "${HOME_GRAPH_ENABLED:-1}"`, `HOME_RECOMMEND_ENDPOINT: http://ai-service:5001/api/home/recommend`; ai-service: `HOME_GRAPH_CACHE_TTL: "${HOME_GRAPH_CACHE_TTL:-600}"`)

**Interfaces:**
- Consumes: `HomeGraphRecommender::build`, `SearchSkinProfile::fromCustomer`, `TaiKhoan::getKhachHangByEmail`, `SanPham::findVisibleByIds`.
- Produces: biến view `$homeGraph` (cấu trúc `build()`); route `GET index.php?r=home_concern&c=<key>` → HTML `frontend/views/partials/home_graph_concern_grid.php` (Task 7) không layout.

- [ ] **Step 1:** Chạy impact: `node .gitnexus/run.cjs impact "HomeController" --direction upstream --repo .` và cho `index.php`; ghi kết quả vào báo cáo task (UNKNOWN → đã xác nhận bằng text search như Global Constraints).
- [ ] **Step 2:** Trong `index`, thêm (bọc `try/catch Throwable`, mặc định `$homeGraph = null`): tạo `HomeGraphRecommender(HomeRecommendClient::fromEnv(), fn($ids) => $this->model->findVisibleByIds($ids))`; `seen_ids` = `$recentViewed`; `cart_ids` = `array_keys($cartItems)`; `skin_profile` = `SearchSkinProfile::fromCustomer(...)` chỉ khi `$isLoggedIn` (dùng email session, giống `SanPhamController::searchSkinProfile`); `exclude_ids` = mã SP sẽ hiển thị ở khối 3 (4 đầu của `forYou`, rơi về `$latest[3..6]` giống `home.php:63-66`), 5.5 (4 đầu `topRatedWeighted`), 6 (4 đầu `$latest`), Flash Sale (4 đầu `hydrated_items` của `$primaryFlashCampaign` nếu có); `sections` = `['seen','skin','concern']`. Thêm `'homeGraph' => $homeGraph` vào mảng `render` (không đổi khóa cũ).
- [ ] **Step 3:** `concern()`: `c` phải nằm trong `SearchSkinProfile::CONCERNS` → không thì `http_response_code(400)`, in `Yêu cầu không hợp lệ` và return (không phản chiếu `c`). Gọi `build` với `sections=['concern']`, `concern=c`, `seen_ids`/`cart_ids`/`skin_profile` như `index` (không cần `exclude_ids`). Header `Content-Type: text/html; charset=UTF-8`, `Cache-Control: private, max-age=60`. Khối null → render grid ở trạng thái lỗi (`$error = true`).
- [ ] **Step 4:** `php -l backend/app/controllers/HomeController.php index.php`; `curl -s -o /dev/null -w "%{http_code}" "localhost:8080/index.php?r=home_concern&c=mun"` → 200; `c=%3Cscript%3E` → 400 và body không chứa `<script>`; trang chủ `curl localhost:8080/` vẫn 200 và vẫn chứa `Được Yêu Thích Nhất`, `Mỹ Phẩm Vừa Lên Kệ`, `Bạn đang quan tâm điều gì cho làn da?`.
- [ ] **Step 5: Commit** (chờ cho phép): `feat(home-graph): wire controller, home_concern route, env flags`.

---

### Task 7: Giao diện + JS + E2E + tài liệu

**Files:**
- Create: `frontend/views/partials/home_graph_seen.php`, `home_graph_skin.php`, `home_graph_concern.php`, `home_graph_concern_grid.php`, `frontend/assets/js/home-graph.js`
- Modify: `frontend/views/home.php` (chỉ thêm: include `seen` ngay trước comment `<!-- 4. FLASH SALE`; include `skin` ngay trước `<!-- 5. SYNA AI LIVESTREAM`; include `concern` ngay trước `<!-- 6. NEW PRODUCTS`; `<script src=".../assets/js/home-graph.js" defer>` cuối trang)
- Create: `update_home_graph_recommend.md`; Modify: `update.md` (thêm 1 dòng trỏ tới file mới)

**Interfaces:**
- Consumes: `$homeGraph` (Task 6), `$renderHomeProductCard($p, $tag, $whyFitText)` (không sửa), `BASE_URL`, `h()`.
- Produces: DOM `#home-graph-concern` chứa `[data-concern-key]` chips và `#home-graph-concern-grid`; mọi link SP trong khối mới có `data-rec-section` ∈ {seen, skin, concern} và `data-rec-reason` = reason code.

- [ ] **Step 1:** Partial `seen`: render khi `!empty($homeGraph['seen'])`; tiêu đề `Vì bạn đã xem {tên seed rút gọn 40 ký tự}`; 4 thẻ `col-6 col-md-3`, `$tag = 'Vì bạn đã xem'`, `$whyFitText = reason_text`. Partial `skin`: tiêu đề `Hợp với {tên loại da viết thường} của bạn`, phụ đề vấn đề da (nếu có), `$tag = 'Hợp da bạn'`. Partial `concern`: thanh chip 9 vấn đề (`role="tablist"`, chip `role="tab"`, `aria-selected`, `overflow-x:auto` trên mobile), chip đang chọn theo `title_data.concern.key`, rồi include `_grid`. Partial `_grid`: 8 thẻ `col-6 col-md-3` (`$tag` = tên vấn đề) + link `Xem tất cả →` tới `index.php?r=tatca&q=` + `urlencode(tên vấn đề)`; khi `$error` → `Chưa tải được, thử lại` + nút `data-retry`. Mọi chuỗi động qua `h()`. Thêm `data-rec-*` bằng wrapper `<div data-rec-section=... data-rec-reason=...>` quanh thẻ (không sửa `$renderHomeProductCard`).
- [ ] **Step 2:** `home-graph.js`: click chip → `AbortController` hủy request cũ → đặt `aria-selected` → grid hiện 8 khung xám → `fetch(BASE + '/index.php?r=home_concern&c=' + encodeURIComponent(key), {credentials:'same-origin'})` → `res.ok` thì thay `innerHTML` grid bằng HTML trả về, không thì hiện trạng thái lỗi; nút `data-retry` gọi lại key hiện tại. BASE lấy từ `data-base-url` trên `#home-graph-concern`. Không phụ thuộc thư viện.
- [ ] **Step 3:** `php -l` cho 4 partial + `home.php`; `curl localhost:8080/` (khách) → chứa `id="home-graph-concern"` và vẫn đủ 3 tiêu đề khối cũ.
- [ ] **Step 4: E2E (Playwright, skill `.claude/skills/e2e/SKILL.md`, server thật `localhost:8080`)**, lưu ảnh vào `report/home-graph-e2e/`:
  1. Khách mới (context sạch): thấy khối vấn đề da, chip `Thâm mụn` được chọn, 8 thẻ có dòng lý do.
  2. Bấm chip `Mụn` rồi `Khô / thiếu ẩm` → grid đổi SP (tập id khác nhau), không lỗi console.
  3. Mở 1 trang chi tiết SP → về trang chủ → có `Vì bạn đã xem` + tên SP đó.
  4. Hồi quy: vẫn có `Bạn đang quan tâm điều gì cho làn da?`, khối 3, `Được Yêu Thích Nhất`, `Mỹ Phẩm Vừa Lên Kệ`; không SP nào của khối mới trùng 4 SP của `Được Yêu Thích Nhất`.
  5. `docker stop skinsyntax-ai` → reload trang chủ: không có khối mới, khối cũ đủ, không lỗi PHP; `docker start skinsyntax-ai` sau đó.
  6. Viewport mobile 375px: thanh chip cuộn ngang, không tràn ngang trang.
  7. "Hợp với da bạn": nếu có tài khoản test đã làm khảo sát thì đăng nhập kiểm; không có → gọi `/api/home/recommend` với `skin_profile` trong container và ghi **E2E NOT RUN** cho bước này kèm lý do.
- [ ] **Step 5:** Viết `update_home_graph_recommend.md`: những gì đã thêm, các file, biến môi trường, cách tắt (`HOME_GRAPH_ENABLED=0`), lệnh test, kết quả E2E (PASS/NOT RUN thật), `took_ms` đo được, rủi ro (HELPS mỏng, RAM Neo4j). Thêm 1 dòng vào `update.md`.
- [ ] **Step 6:** `git diff --check`; `node .gitnexus/run.cjs detect-changes --scope all --repo .` (kết quả `partial/truncated` → chạy lại); xác nhận diff không chạm `getSimpleRecommenderProducts`, `getHybridRecommendations`, `getHomepageProductSections`, `$renderHomeProductCard`, `SearchRecommendClient.php`, `search_service/graph_repository.py` (`git diff --stat` + `git diff backend/app/models/SanPham.php` rỗng).
- [ ] **Step 7: Commit** (chờ cho phép): `feat(home-graph): homepage sections UI, tab loader, docs`.

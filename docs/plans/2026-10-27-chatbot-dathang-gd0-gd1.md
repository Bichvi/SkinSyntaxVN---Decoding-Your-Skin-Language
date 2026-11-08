# Chatbot đặt hàng qua chat: GĐ0 (nền móng, bảo mật) + GĐ1 (lõi agent) — Kế hoạch triển khai

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Khách đã đăng nhập chat với bot để được tư vấn, thêm vào giỏ và nhận thẻ đơn nháp; đơn chỉ được tạo khi khách bấm "Xác nhận đặt hàng".

**Architecture:** PHP là nơi duy nhất ghi giỏ (session) và tạo đơn. PHP ký token ngắn hạn cho mỗi tin nhắn, chuyển tiếp SSE từ Flask. Flask chạy agent LangGraph (router → agent có tool → trả lời); các tool ghi gọi API nội bộ của PHP và nhận lại `cart_effect` / thẻ đơn nháp có chữ ký. Luồng cũ `xu_ly_cau_hoi` giữ nguyên, bật/tắt bằng `SYNA_AGENT_V2`.

**Tech Stack:** PHP 8 thuần + MongoDB (`mongodb/mongodb` 1.19.1) + Redis (`RedisLite`); Flask 3, LangChain ≥0.3, **LangGraph** (mới), Pydantic v2, pytest.

**Spec:** [`update_chatbot_dathang.md`](../../update_chatbot_dathang.md) (mục C, C2, C3, C4, C5). Đọc cả spec và kế hoạch.

## Global Constraints

- Không có tool / endpoint nội bộ nào tạo đơn thật. Đơn chỉ tạo qua `POST r=chat_order_confirm` (session + CSRF + Origin).
- Token agent: HMAC-SHA256, khóa env `CHAT_AGENT_TOKEN_SECRET`, hạn **300 giây**. Định dạng: `b64url(json) + "." + b64url(hmac_sha256(secret, b64url(json)))`, b64url không có `=`. Claims: `sub` (string, `current_user()['id']`), `em` (email), `sid` (conversation id hoặc ""), `scope` (list), `exp` (int unix), `jti` (hex 16 byte).
- Scope: `chat`, `cart:write`, `draft:create`, `order:read`. Khách chưa đăng nhập chỉ có `["chat"]`. Công tắc khẩn cấp bật → bỏ `cart:write`, `draft:create`.
- API nội bộ: header `X-Internal-Token` = env `CHAT_AGENT_INTERNAL_SECRET` (so `hash_equals`) **và** `Authorization: Bearer <token agent>`.
- Đơn nháp: hạn **600 giây**, dùng **một lần**, SL mỗi món **1–20**, tối đa **5 món** mỗi lần thêm giỏ, tối đa **3 đơn nháp mở** mỗi khách.
- Phí vận chuyển **30000** (như `xulydathang`). Phương thức: `cod`, `vnpay`, `bank_transfer_qr` (chỉ khi `isQrTransferEnabled`). Không dùng điểm thưởng trong đơn chat.
- Giới hạn: 20 tin/60 giây và 300 tin/86400 giây mỗi khách; 8 bước tool/lượt; 45 giây/lượt; 5 lần xác nhận/600 giây.
- Prompt P1, P2, P3 chép **nguyên văn** từ spec mục C4; `PROMPT_VERSION = "2026-10-27.1"`.
- Model agent theo thứ tự: OpenAI `gpt-4o-mini` → Gemini `GEMINI_CHAT_MODEL` (mặc định `gemini-2.5-flash`) → Groq `openai/gpt-oss-120b`. Router: Groq `openai/gpt-oss-20b` → `gpt-4o-mini`. Không dùng OpenRouter cho agent.
- Không log token, SĐT, địa chỉ đầy đủ. Che SĐT: giữ 2 số đầu + 3 số cuối (`09xx xxx 123`).
- Sự kiện SSE (Flask → PHP → trình duyệt), mỗi sự kiện `event: <tên>\ndata: <json>\n\n`:
  `meta {conversation_id, prompt_version}` · `token {text}` · `ui {kind, payload}` với `kind ∈ product_list | draft_card | order_list | login_required | clarify` · `cart_effect {effect}` (PHP nuốt, không chuyển cho trình duyệt; thay bằng `cart_updated {count}`) · `done {}` · `error {message}`.
- PHP test: script thuần chạy bằng `php backend/tests/unit/<Tên>Test.php`, in `PASS/FAIL`, **không ghi DB** (mock store). Không chạy hàng loạt script trong `backend/tests/` (nhiều script ghi DB thật).
- Trước khi sửa symbol có sẵn: chạy `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`; báo HIGH/CRITICAL cho người dùng trước khi sửa. Trước commit: `node .gitnexus/run.cjs detect-changes --scope all --repo .`.
- **Chỉ commit khi người dùng cho phép** (AGENTS.md). Bước "Commit" bên dưới = gom thay đổi thành một mốc; nếu chưa được phép thì dừng ở `git add` và báo lại.

## Review Focus

1. **Bấm "Xác nhận" hai lần nhanh** (hai tab, double click) → chỉ một đơn. Test ở Task 9 (`confirm_twice_creates_one_order`).
2. **Giá hoặc voucher đổi giữa lúc lập đơn nháp và lúc xác nhận** (Flash Sale hết giờ) → không tạo đơn, trả thẻ mới. Test ở Task 9 (`price_changed_returns_new_draft`).
3. **Session PHP bị khóa khi stream** → khách mở tab khác vẫn dùng được web; `cart_effect` vẫn được ghi. Test ở Task 16 (`relay_applies_effect_after_reopening_session`) + kiểm tay ở Task 19.
4. **Token hết hạn giữa lượt dài** (agent chạy gần 45 giây, token 300 giây vẫn đủ) và **token của khách A dùng đơn nháp của khách B** → 403. Test ở Task 8 (`draft_of_other_user_is_hidden`) và Task 9 (`confirm_rejects_other_users_draft`).
5. **Tên sản phẩm mơ hồ / "cái thứ 2" khi chưa hiện sản phẩm nào** → bot hỏi lại, không thêm giỏ bừa. Test ở Task 13 (`ordinal_without_shown_products_needs_clarification`).

---

## Cấu trúc file

**PHP (`backend/app/`)**

| File | Trách nhiệm |
|---|---|
| `services/ChatAgentToken.php` (mới) | Ký / kiểm token agent |
| `services/ChatAgentGate.php` (mới) | Cờ `SYNA_AGENT_V2`, công tắc khẩn cấp (Redis), tính scope |
| `services/ChatAgentAudit.php` (mới) | Ghi `chat_agent_audit` (đã che dữ liệu) |
| `services/ChatCartEffect.php` (mới) | Ký / kiểm / áp `cart_effect` vào session |
| `services/CheckoutCore.php` (mới) | Tách từ `HomeController`: `preview()`, `defaultReceiver()`, `bestVoucher()` |
| `services/ChatDraftService.php` (mới) | Đơn nháp lưu Mongo `chat_order_drafts` (store tiêm vào để test) |
| `services/ChatOrderConfirmService.php` (mới) | Xác nhận đơn nháp → `HoaDon::taoDonHang` |
| `services/ChatSseRelay.php` (mới) | Tách luồng SSE thành sự kiện, xử lý `cart_effect` |
| `controllers/ChatAgentInternalController.php` (mới) | API nội bộ cho Flask |
| `controllers/ChatAgentController.php` (mới) | `ai_chat_agent_stream` (proxy SSE) + `chat_order_confirm` |
| `routes/chat_agent.php` (mới) | Đăng ký route, include như `store_assistance.php` |
| `controllers/HomeController.php` (sửa) | `buildCheckoutPreview`, `getDefaultCheckoutReceiver` thành ủy quyền sang `CheckoutCore`; gửi `X-Agent-Token` khi gọi Flask |
| `public/index.php` (sửa) | include `routes/chat_agent.php` cạnh dòng 808 |
| `scripts/chat_agent_switch.php` (mới) | Bật / tắt mua qua chat |

**Flask (`ai-service-flask/agent_graph/`, gói mới)**

| File | Trách nhiệm |
|---|---|
| `config.py` | Đọc env |
| `auth.py` | Kiểm token agent, `identity_from_request()` |
| `audit.py` | Ghi audit Mongo, che dữ liệu |
| `php_client.py` | Gọi API nội bộ PHP |
| `prompts.py` | P1, P2, P3 + `PROMPT_VERSION` |
| `llm.py` | Dựng LLM cho router và agent |
| `state.py` | Trạng thái hội thoại nhỏ trong Redis |
| `router.py` | Luật nhanh + phân loại LLM (structured output) |
| `tools.py` | Tool Pydantic + `ToolContext` |
| `graph.py` | Đồ thị LangGraph |
| `routes.py` | Blueprint `/api/agent/chat` (SSE) |

**Frontend:** `frontend/public/assets/js/chat-agent-stream.js` (mới), `frontend/views/components/ai_chat_widget.php` (sửa nhỏ).

---

# GĐ0 — Nền móng và bảo mật

### Task 1: Token agent phía PHP

**Files:**
- Create: `backend/app/services/ChatAgentToken.php`
- Test: `backend/tests/unit/ChatAgentTokenTest.php`

**Interfaces:**
- Produces: `ChatAgentToken::issue(array $claims, string $secret, int $ttl = 300, ?int $now = null): string` (tự thêm `exp`, `jti` nếu thiếu); `ChatAgentToken::verify(string $token, string $secret, ?int $now = null): array` (trả claims, ném `DomainException` code 401 khi sai chữ ký / hết hạn / định dạng hỏng / secret rỗng); `ChatAgentToken::hasScope(array $claims, string $scope): bool`.

- [ ] **Step 1: Viết test thất bại**

```php
$secret = 'test-secret-0123456789';
$t = ChatAgentToken::issue(['sub'=>'42','em'=>'a@b.c','sid'=>'','scope'=>['chat','order:read']], $secret, 300, 1_800_000_000);
$c = ChatAgentToken::verify($t, $secret, 1_800_000_100);
$check('roundtrip giữ claims', $c['sub']==='42' && $c['exp']===1_800_000_300 && strlen($c['jti'])===32);
$check('hết hạn bị từ chối', throws(fn()=>ChatAgentToken::verify($t, $secret, 1_800_000_301), 401));
$check('sai chữ ký bị từ chối', throws(fn()=>ChatAgentToken::verify($t.'x', $secret, 1_800_000_100), 401));
$check('secret rỗng bị từ chối', throws(fn()=>ChatAgentToken::verify($t, '', 1_800_000_100), 401));
$check('hasScope', ChatAgentToken::hasScope($c,'order:read') && !ChatAgentToken::hasScope($c,'cart:write'));
// Vector dùng chung với Flask (Task 2): in ra để chép sang test Python
$fixed = ChatAgentToken::issue(['sub'=>'7','em'=>'x@y.z','sid'=>'c1','scope'=>['chat'],'exp'=>1_900_000_000,'jti'=>str_repeat('a',32)], 'vector-secret');
$check('vector cố định', $fixed === '<DÁN CHUỖI SAU KHI CHẠY LẦN ĐẦU>');
```

`throws(callable $fn, int $code): bool` là helper trong file test. JSON khi ký phải dùng `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` và **giữ thứ tự khóa như truyền vào** để vector ổn định.

- [ ] **Step 2:** Chạy `php backend/tests/unit/ChatAgentTokenTest.php` → FAIL (class chưa có).
- [ ] **Step 3:** Viết `ChatAgentToken` (dùng `hash_hmac('sha256', …, true)`, `hash_equals`). Chạy lại, lấy chuỗi `$fixed` dán vào test.
- [ ] **Step 4:** Chạy lại → tất cả PASS. `php -l backend/app/services/ChatAgentToken.php`.
- [ ] **Step 5: Commit** `feat(chat-agent): HMAC agent token (PHP)`.

### Task 2: Flask ngừng tin `user_email`; PHP gửi `X-Agent-Token`

**Files:**
- Create: `ai-service-flask/agent_graph/__init__.py`, `agent_graph/config.py`, `agent_graph/auth.py`
- Modify: `ai-service-flask/chatbot_service/chatbot_flask.py` (hàm `chat()` quanh L3408–3420; 3 route hội thoại L3651, L3680, L3720)
- Modify: `backend/app/controllers/HomeController.php` (`callFlaskService` L3424; payload gửi `AI_CHAT_ENDPOINT` quanh L3237)
- Test: `ai-service-flask/tests/agent_graph/test_auth.py`

**Interfaces:**
- Consumes: định dạng token Task 1.
- Produces: `agent_graph.auth.AgentClaims` (dataclass `sub: str, em: str, sid: str, scope: tuple[str, ...], exp: int, jti: str`, method `has(scope) -> bool`); `verify_agent_token(token: str, secret: str, now: int | None = None) -> AgentClaims` (ném `AuthError`); `identity_from_request(req) -> AgentClaims | None` (đọc header `X-Agent-Token`, secret `config.TOKEN_SECRET`; lỗi hoặc thiếu → `None`). PHP: `HomeController::agentTokenHeader(): ?string` (private) trả `'X-Agent-Token: …'` hoặc null.
- `config.py`: `TOKEN_SECRET = env CHAT_AGENT_TOKEN_SECRET`, `INTERNAL_SECRET = env CHAT_AGENT_INTERNAL_SECRET`, `PHP_INTERNAL_URL = env CHAT_AGENT_PHP_URL` (mặc định `http://127.0.0.1/index.php`), `AGENT_V2 = env SYNA_AGENT_V2 == "1"`, `MAX_STEPS = 8`, `TURN_TIMEOUT_S = 45`, `REDIS_URL`.

- [ ] **Step 0:** Chạy impact cho `chat` (Flask) và `callFlaskService`; ghi kết quả vào báo cáo task.
- [ ] **Step 1: Test thất bại** — `test_vector_matches_php` (chuỗi vector Task 1 → `sub=="7"`, `scope==("chat",)` với `now=1_899_999_999`), `test_expired`, `test_bad_signature`, `test_empty_secret_rejects`, `test_identity_from_request_ignores_body_email` (Flask test client gửi `{"message":"hi","user_email":"victim@x.com"}` không có header → hàm đọc danh tính trong `chat()` trả `None`; dùng `monkeypatch` thay `xu_ly_cau_hoi` bằng stub ghi lại `user_email`).
- [ ] **Step 2:** `pytest ai-service-flask/tests/agent_graph/test_auth.py -v` → FAIL.
- [ ] **Step 3:** Viết `auth.py`. Trong `chat()`: thay việc đọc `user_email` từ body bằng `claims = identity_from_request(request); user_email = claims.em if claims else None`. Ba route hội thoại: thay `request.headers.get("X-User-Email")` bằng `identity_from_request(request)`; `None` → 401. PHP: `callFlaskService` bỏ `X-User-Email`, thêm header token (scope `["chat"]`, `sid` = conversation id nếu có); request tới `AI_CHAT_ENDPOINT` thêm cùng header (trường `user_email` trong body giữ để tương thích nhưng Flask không đọc nữa).
- [ ] **Step 4:** pytest test mới PASS; chạy hồi quy `pytest ai-service-flask/tests/test_chat_regressions.py -q` PASS; `php -l backend/app/controllers/HomeController.php`.
- [ ] **Step 5:** Kiểm tay: đăng nhập web, mở chat, lịch sử hội thoại vẫn hiện. Thiếu `CHAT_AGENT_TOKEN_SECRET` ở một bên → Flask log cảnh báo một lần, chat vẫn trả lời (ẩn danh), lịch sử không hiện. Ghi biến mới vào `.env.example` (không ghi giá trị thật).
- [ ] **Step 6: Commit** `fix(security): chatbot trusts signed agent token, not user_email`.

### Task 3: Cờ, công tắc khẩn cấp và scope

**Files:**
- Create: `backend/app/services/ChatAgentGate.php`, `backend/scripts/chat_agent_switch.php`
- Test: `backend/tests/unit/ChatAgentGateTest.php`

**Interfaces:**
- Produces: `new ChatAgentGate(?RedisLite $redis, array $env)`; `->agentEnabled(): bool` (`$env['SYNA_AGENT_V2'] === '1'`); `->purchaseEnabled(): bool` (key Redis `skinsyntax:chat_agent:purchase_off` khác `"1"`; Redis null/lỗi → `true`); `->scopesFor(bool $loggedIn, bool $isStaff): array`; `->setPurchaseEnabled(bool $on): void`.
- `scopesFor`: chưa đăng nhập → `['chat']`; nhân viên/admin → `['chat','order:read']`; khách → `['chat','order:read']` + (`purchaseEnabled` ? `['cart:write','draft:create']` : `[]`).

- [ ] **Step 1: Test thất bại** với Redis giả (lớp con `RedisLite` như `RateLimiterTest`): `guest_only_chat`, `staff_cannot_buy`, `customer_full_scopes`, `kill_switch_removes_write_scopes`, `redis_down_keeps_purchase_on`, `agent_flag_reads_env`.
- [ ] **Step 2:** Chạy → FAIL. **Step 3:** Viết class + script CLI `php backend/scripts/chat_agent_switch.php on|off|status`. **Step 4:** PASS + `php -l`.
- [ ] **Step 5: Commit** `feat(chat-agent): feature flag, kill switch, scopes`.

### Task 4: Nhật ký hành động agent (I16)

**Files:**
- Create: `backend/app/services/ChatAgentAudit.php`, `ai-service-flask/agent_graph/audit.py`
- Test: `backend/tests/unit/ChatAgentAuditTest.php`, `ai-service-flask/tests/agent_graph/test_audit.py`

**Interfaces:**
- Produces PHP: `new ChatAgentAudit(callable $insert)`; `->record(string $actor, string $action, array $params, string $outcome): void` (không bao giờ ném lỗi); `ChatAgentAudit::mask(array $params): array`.
- Produces Python: `audit.record(actor: str, action: str, params: dict, outcome: str, *, insert=None) -> None`; `audit.mask(params: dict) -> dict`.
- Bản ghi: `{ts, actor (sub), action, params (đã che), outcome, source: "php"|"flask"}`, collection `chat_agent_audit`.
- `mask`: khóa `phone|sdt|sdt_nguoi_nhan` → che như Global Constraints; khóa `address|dia_chi|dia_chi_giao_hang` → chỉ giữ phần sau dấu phẩy cuối (tỉnh/thành); khóa `token|csrf|authorization` → `"***"`; đệ quy vào mảng con.

- [ ] **Step 1:** Test hai bên: `mask_phone("0912345678") == "09xx xxx 678"`, `mask_address("12 Nguyễn Văn A, Q.1, TP.HCM") == "…, TP.HCM"`, `token_removed`, `nested_masked`, `insert_failure_is_swallowed`.
- [ ] **Step 2–4:** FAIL → viết → PASS.
- [ ] **Step 5: Commit** `feat(chat-agent): masked audit log`.

### Task 5: Khung API nội bộ PHP + client Flask

**Files:**
- Create: `backend/app/controllers/ChatAgentInternalController.php`, `backend/app/routes/chat_agent.php`, `ai-service-flask/agent_graph/php_client.py`
- Modify: `backend/public/index.php` (cạnh L808: `require $backendRoot . '/app/routes/chat_agent.php'` cùng mẫu với `store_assistance.php`), `nginx/nginx.conf`
- Test: `backend/tests/unit/ChatAgentInternalAuthTest.php`, `ai-service-flask/tests/agent_graph/test_php_client.py`

**Interfaces:**
- Produces PHP: `ChatAgentInternalController::authenticate(array $server, string $internalSecret, string $tokenSecret, ?int $now = null): array` (static, thuần, trả claims; ném `DomainException` 401/403); `->handle(string $route): void` (JSON, `Cache-Control: no-store`). Route: `internal_agent_ping` (trả `{ok:true, sub}`), các route sau thêm ở Task 7, 8, 10.
- Produces Python: `PhpClient(base_url: str, internal_secret: str, agent_token: str, timeout: float = 8.0, session=None)`; `.call(route: str, payload: dict | None = None) -> dict` (POST JSON tới `<base_url>?r=<route>`; HTTP ≥400 → `PhpError(status, message)`); `.ping() -> dict`. Thư viện: `requests` (đã có qua phụ thuộc LangChain; thêm `requests>=2.31` vào `requirements.txt` cho rõ).
- `chat_agent.php` trả `false` nếu `$r` không thuộc các route của nó (giống `store_assistance.php`).
- Nginx: chặn mọi request từ ngoài có `r=internal_agent_` (`if ($arg_r ~ "^internal_agent_") { return 404; }` trong server block công khai); Flask gọi PHP qua mạng Docker nội bộ (tên service PHP-FPM/Apache trong `docker-compose.yml`).

- [ ] **Step 1:** Test PHP `authenticate`: thiếu `HTTP_X_INTERNAL_TOKEN` → 401; sai → 401; đúng nhưng thiếu `Bearer` → 401; token hết hạn → 401; đủ → claims. Test Python với `requests` giả: header gửi đi đúng, lỗi 403 → `PhpError(403, …)`.
- [ ] **Step 2–4:** FAIL → viết → PASS; `php -l` các file PHP mới.
- [ ] **Step 5:** Kiểm tay trên local: `curl -X POST "http://localhost/.../index.php?r=internal_agent_ping"` không header → 401.
- [ ] **Step 6: Commit** `feat(chat-agent): internal PHP API skeleton and Flask client`.

---

# GĐ1 — Lõi agent

### Task 6: Tách `CheckoutCore` khỏi `HomeController` (giữ nguyên hành vi)

**Files:**
- Create: `backend/app/services/CheckoutCore.php`
- Modify: `backend/app/controllers/HomeController.php` (`buildCheckoutPreview` L1132–1173, `getDefaultCheckoutReceiver` L1468)
- Test: `backend/tests/unit/CheckoutCoreTest.php`

**Interfaces:**
- Produces: `new CheckoutCore($db, $productModel, $voucherModel, $customerVoucherModel, callable $effectivePrice)`; `->preview(array $checkoutItems): array` (đúng shape cũ `{items, subtotal, stock_errors}`); `->defaultReceiver(?array $customer, array $user): array` (đúng shape cũ); `->bestVoucher(string $customerId, int $subtotal): ?array` (duyệt `getCustomerVouchers($customerId, 'AVAILABLE')` — kiểm giá trị trạng thái thật trong `CustomerVoucher` trước khi viết —, gọi `validateForCheckout($code, $subtotal)`, chọn `discount` lớn nhất, hòa thì mã hết hạn sớm hơn; trả kết quả `validateForCheckout` hoặc `null`).
- `$effectivePrice(string $id, array $product): int` bọc `FlashSaleCampaign::resolveEffectivePrice` để test không cần DB.

- [ ] **Step 0:** Impact `buildCheckoutPreview`, `getDefaultCheckoutReceiver`; báo nếu HIGH.
- [ ] **Step 1: Test thất bại** với model giả: `preview_sums_effective_prices`, `preview_reports_stock_error_and_skips_item`, `preview_skips_missing_product`, `best_voucher_picks_largest_discount`, `best_voucher_none_when_all_invalid`.
- [ ] **Step 2:** FAIL. **Step 3:** Chuyển nguyên thân hai hàm sang `CheckoutCore`; hai hàm private cũ thành một dòng gọi `$this->checkoutCore()->…` (lazy). **Step 4:** PASS + `php -l`.
- [ ] **Step 5:** Kiểm tay: trang `thanhtoan` hiện đúng tạm tính, áp voucher, đặt một đơn COD bằng tài khoản test → giống trước khi tách; hủy đơn đó qua "Đơn hàng của tôi".
- [ ] **Step 6: Commit** `refactor(checkout): extract CheckoutCore (no behavior change)`.

### Task 7: `cart_effect` có chữ ký + API giỏ nội bộ

**Files:**
- Create: `backend/app/services/ChatCartEffect.php`
- Modify: `backend/app/controllers/ChatAgentInternalController.php` (route `internal_agent_cart_add`, `internal_agent_cart_remove`)
- Test: `backend/tests/unit/ChatCartEffectTest.php`

**Interfaces:**
- Consumes: `ChatAgentToken`, `CheckoutCore::preview`, `ChatAgentAudit`.
- Produces: effect = `{op: "add"|"remove", product_id: string, qty: int, sub: string, exp: int, nonce: string, sig: string}`; `ChatCartEffect::sign(array $effect, string $secret): array`; `::verify(array $effect, string $secret, string $sub, ?int $now = null): bool`; `::apply(array $effect, array &$session): void` (`add`: `gio_hang[id] = min(20, cũ + qty)`; `remove`: unset). Khóa ký = `CHAT_AGENT_TOKEN_SECRET`; `exp` = now + 120.
- `internal_agent_cart_add` body `{product_id, qty}` → cần scope `cart:write`; kiểm tồn kho/giá bằng `CheckoutCore::preview([$id => $qty])`; lỗi tồn kho → 409 `{ok:false, code:"out_of_stock", message}`; thành công → `{ok:true, effect, line:{id, name, unit_price, qty}}`. `remove` tương tự, không kiểm kho.

- [ ] **Step 1: Test:** `verify_ok`, `tampered_qty_rejected`, `other_user_rejected`, `expired_rejected`, `apply_add_caps_at_20`, `apply_remove`.
- [ ] **Step 2–4:** FAIL → viết → PASS.
- [ ] **Step 5: Commit** `feat(chat-agent): signed cart effects and internal cart API`.

### Task 8: Đơn nháp (`ChatDraftService`) + API nội bộ

**Files:**
- Create: `backend/app/services/ChatDraftService.php`
- Modify: `ChatAgentInternalController.php` (route `internal_agent_checkout_defaults`, `internal_agent_draft`, `internal_agent_reorder`)
- Test: `backend/tests/unit/ChatDraftServiceTest.php`

**Interfaces:**
- Consumes: `CheckoutCore` (`preview`, `defaultReceiver`, `bestVoucher`).
- Produces: `new ChatDraftService(ChatDraftStore $store, CheckoutCore $core, callable $clock)`; interface `ChatDraftStore { insert(array $d): void; find(string $id): ?array; countOpen(string $sub, int $now): int; markUsed(string $id, int $now): bool /* nguyên tử: chỉ khi used_at null */; release(string $id): void; }` + `MongoChatDraftStore` (collection `chat_order_drafts`, `findOneAndUpdate({_id, used_at:null}, {$set:{used_at}})`, TTL index trên `expires_at`).
- `->upsert(array $claims, array $req, array $cartSnapshot): array` với `$req = {scope:"mentioned_items"|"whole_cart", product_ids:[], qty:{id:int}?, address_choice:"default"|"new", new_address:{ten_nguoi_nhan,sdt_nguoi_nhan,dia_chi_giao_hang}?, payment_method, voucher_code:?string, remove_voucher:bool, replaces:?string}` → trả `card`:
  `{draft_id, expires_at, items:[{id,name,image,qty,unit_price,line_total}], subtotal, voucher:{code,discount}|null, shipping:30000, total, receiver:{name, phone_masked, address_short}, payment_method, warnings:[]}`.
- Lỗi (`DomainException` → HTTP code): thiếu món 422; >3 đơn nháp mở 429; phương thức không hợp lệ 422; thiếu thông tin người nhận 422 `code:"receiver_missing"`; tồn kho 409.
- `->get(string $id, string $sub): ?array` (sai chủ → `null`).
- `internal_agent_checkout_defaults` → `{receiver:{name, phone_masked, address_short}|null, last_payment_method, methods:[…]}`.
- `internal_agent_reorder` body `{order_code}` → lấy đơn của chính `em` qua `HoaDon::getOrderById($id, $email)` → `upsert` với các món đơn cũ.
- `$cartSnapshot` do PHP proxy gửi kèm (Task 16) và Flask chuyển lại nguyên; `whole_cart` dùng nó.

- [ ] **Step 1: Test** với store bộ nhớ: `creates_card_with_masked_phone`, `auto_applies_best_voucher`, `remove_voucher_respected`, `rejects_fourth_open_draft`, `draft_of_other_user_is_hidden`, `expired_draft_not_returned`, `qr_rejected_when_disabled`, `replaces_marks_old_used`.
- [ ] **Step 2–4:** FAIL → viết → PASS.
- [ ] **Step 5: Commit** `feat(chat-agent): order drafts with best voucher`.

### Task 9: Xác nhận đơn (`chat_order_confirm`)

**Files:**
- Create: `backend/app/services/ChatOrderConfirmService.php`, `backend/app/controllers/ChatAgentController.php` (method `confirm()`)
- Modify: `backend/app/routes/chat_agent.php` (route `chat_order_confirm`)
- Test: `backend/tests/unit/ChatOrderConfirmTest.php`

**Interfaces:**
- Consumes: `ChatDraftService`, `CheckoutCore`, `ChatAgentGate`, `RateLimiter`, `ChatAgentAudit`, `HoaDon::taoDonHang`.
- Produces: `new ChatOrderConfirmService(ChatDraftService $drafts, ChatDraftStore $store, CheckoutCore $core, callable $createOrder, callable $clock)`; `->confirm(string $draftId, array $user, array &$session): array` trả một trong:
  `{status:"created", order_id, payment_method, next:{type:"view_order"|"vnpay"|"qr", url}}` · `{status:"changed", card}` (giá/voucher đổi, đơn nháp cũ đánh dấu dùng, thẻ mới thay thế) · ném `DomainException` (404 không thấy / của người khác, 410 hết hạn, 409 đã dùng, 403 tắt mua).
- Thứ tự trong `confirm`: tìm nháp (đúng `sub`) → hạn → `CheckoutCore::preview` + `bestVoucher`/voucher cũ → so `subtotal` và `discount` với nháp; khác → `changed` → **`markUsed` nguyên tử (false → 409)** → `createOrder(payload)` với payload đúng các khóa như `xulydathang` L4747–4763 (`diem_su_dung`=0, `tien_giam_diem`=0, `phi_van_chuyen`=30000, `analytics_sid` từ `ProductEventTracker::sessionHash()`) → ném lỗi thì `release` rồi ném lại → xóa các món đã đặt khỏi `$session['gio_hang']` → audit.
- `next.url`: COD → `BASE_URL/index.php?r=camon&ma_hoa_don=<id>`; VNPay → `VnpayService::createPaymentUrl` (giống `xulydathang`); QR → cùng trang `camon` như `xulydathang`.
- Controller `confirm()`: POST JSON ≤ 4096 byte; `is_logged_in()`; không phải `admin|nhanvien`; `hash_equals(ShopAssistantController::csrfToken(), $csrf)`; `parse_url($_SERVER['HTTP_ORIGIN'])['host']` == host của `BASE_URL` (thiếu Origin → 403); `RateLimiter::shared()->hit('chat_order_confirm', $sub, 5, 600)`; `ChatAgentGate::purchaseEnabled()`.

- [ ] **Step 1: Test** (store bộ nhớ, `createOrder` giả đếm số lần gọi): `confirm_creates_order_once`, `confirm_twice_creates_one_order` (lần 2 → 409, `createOrder` gọi đúng 1 lần), `price_changed_returns_new_draft` (không gọi `createOrder`), `confirm_rejects_other_users_draft`, `expired_draft_410`, `create_failure_releases_draft`, `cart_lines_removed_after_order`, `vnpay_next_is_payment_url`.
- [ ] **Step 2–4:** FAIL → viết → PASS; `php -l`.
- [ ] **Step 5: Commit** `feat(chat-agent): confirm draft into real order (button only)`.

### Task 10: Tra đơn nội bộ

**Files:**
- Modify: `ChatAgentInternalController.php` (route `internal_agent_orders`)
- Test: `backend/tests/unit/ChatAgentOrdersTest.php`

**Interfaces:**
- Produces: body `{order_code?: string}` (chấp nhận `"#DH123"`, `"DH123"`, `"123"` → id 123); cần scope `order:read`; có mã → `HoaDon::getOrderById($id, $claims['em'])` (sai chủ → 404); không mã → 3 đơn mới nhất của khách (truy vấn `hoa_don` theo `ma_kh` của email, sort ngày giảm dần). Trả `{orders:[{order_id, created_at, total, status: OrderTabs::orderStatus($o), payment_state: OrderTabs::paymentState($o), url}]}`. Hàm thuần để test: `ChatAgentInternalController::orderView(array $order): array`, `::parseOrderCode(string $s): ?int`.

- [ ] **Step 1: Test** `parseOrderCode` các dạng trên + `"abc"` → null; `orderView` không lộ địa chỉ/SĐT.
- [ ] **Step 2–4:** FAIL → viết → PASS.
- [ ] **Step 5: Commit** `feat(chat-agent): read-only order status API`.

### Task 11: Cấu hình agent Flask: prompt và LLM

**Files:**
- Create: `ai-service-flask/agent_graph/prompts.py`, `agent_graph/llm.py`
- Modify: `ai-service-flask/requirements.txt` (thêm `langgraph>=1.0,<2`, `requests>=2.31`)
- Test: `ai-service-flask/tests/agent_graph/test_prompts_llm.py`

**Interfaces:**
- Produces: `prompts.PROMPT_VERSION`, `prompts.ROUTER_PROMPT` (P1), `prompts.AGENT_PROMPT` (P2), `prompts.TOOL_DOCS: dict[str, str]` (P3, khóa = tên tool), `prompts.render(template: str, **ctx) -> str` (thiếu khóa → `ValueError`, không để `{…}` sót); `llm.build_router_llm() -> BaseChatModel | None`, `llm.build_agent_llm() -> BaseChatModel | None` (dùng `.with_fallbacks()`; Groq qua `ChatOpenAI(openai_api_base="https://api.groq.com/openai/v1")` như `chatbot_flask.py` L451–465; Gemini qua `langchain_google_genai.ChatGoogleGenerativeAI`; không có key nào → `None`).

- [ ] **Step 1:** Cài `pip install "langgraph>=1.0,<2"`; chạy toàn bộ `pytest ai-service-flask/tests -q` để chắc không vỡ phụ thuộc. Nếu xung đột `langchain-core`, hạ xuống `langgraph>=0.6,<1` và ghi lý do vào báo cáo.
- [ ] **Step 2: Test:** `prompt_version_set`, `agent_prompt_has_no_create_order_promise` (P2 chứa "Bạn KHÔNG thể tạo đơn hàng"), `render_rejects_missing_key`, `tool_docs_cover_all_tools` (khóa = tập tên tool Task 12), `agent_llm_none_without_keys` (monkeypatch env rỗng), `agent_llm_excludes_openrouter`.
- [ ] **Step 3–4:** FAIL → viết → PASS.
- [ ] **Step 5: Commit** `feat(agent): prompts P1-P3 and tool-calling LLM chain`.

### Task 12: Tool có kiểu dữ liệu (`tools.py`)

**Files:**
- Create: `ai-service-flask/agent_graph/tools.py`
- Test: `ai-service-flask/tests/agent_graph/test_tools.py`, `ai-service-flask/tests/agent_graph/test_invariants.py`

**Interfaces:**
- Consumes: `PhpClient` (Task 5), `AgentClaims` (Task 2), `audit.record` (Task 4), `chatbot_service.graph_router.get_runtime().tools.call(name, args)` (GraphTools: `find_products`, `check_conflicts`, `explain_product`, `similar_products`), `prompts.TOOL_DOCS`.
- Produces: `@dataclass ToolContext(claims: AgentClaims | None, php: PhpClient | None, cart_snapshot: list[dict], emit: Callable[[str, dict], None], state: "ConvState", graph_tools: Any)`; `build_tools(ctx: ToolContext) -> list[StructuredTool]`; `TOOL_SCOPES: dict[str, str | None]`.
- Danh sách tool (tên = khóa `TOOL_DOCS`), arg model Pydantic `extra="forbid"`:

| Tool | Args | Scope | Gọi tới | Phát sự kiện |
|---|---|---|---|---|
| `search_products` | `query: str ≤200, skin_type?, concerns: list ≤5, price_max: int ≥0?, brand?, limit: int 1–6 = 3` | – | `find_products` | `ui product_list`; ghi `state.last_shown_products` |
| `get_product` | `product_id: str` | – | `explain_product` | – |
| `compare_products` | `product_ids: list 2–3` | – | `explain_product` từng món | `ui product_list` (`mode:"compare"`) |
| `check_conflicts` | `product_ids: list ≤10, include_cart: bool = True` | – | `check_conflicts` (gộp `cart_snapshot`) | – |
| `view_cart` | – | `cart:write` | `cart_snapshot` | – |
| `add_to_cart` | `items: list[{product_id, qty 1–20}] 1–5` | `cart:write` | `internal_agent_cart_add` từng món | `cart_effect` từng món |
| `remove_from_cart` | `product_id` | `cart:write` | `internal_agent_cart_remove` | `cart_effect` |
| `get_checkout_defaults` | – | `draft:create` | `internal_agent_checkout_defaults` | – |
| `prepare_order_draft` | đúng `$req` Task 8 (trừ `replaces` do code điền từ `state.open_draft_id`) | `draft:create` | `internal_agent_draft` | `ui draft_card`; ghi `state.open_draft_id` |
| `reorder_from_order` | `order_code: str` | `draft:create` | `internal_agent_reorder` | `ui draft_card` |
| `get_order_status` | `order_code?: str` | `order:read` | `internal_agent_orders` | `ui order_list` |

- Thiếu scope → tool **không được đưa vào danh sách** và nếu bị gọi vẫn trả `{"error":"login_required"}` + phát `ui login_required`. `PhpError` → trả `{"error": code, "message": …}` cho LLM (không ném). Kết quả trả LLM bọc `"<tool_data>" + json + "</tool_data>"`. Mỗi lần gọi → `audit.record`.

- [ ] **Step 1: Test** (PhpClient giả, graph_tools giả): `guest_gets_only_read_tools`, `kill_switch_scope_hides_write_tools`, `add_to_cart_emits_signed_effect_unchanged`, `add_to_cart_rejects_qty_21` (ValidationError), `out_of_stock_returns_error_not_raise`, `prepare_draft_emits_card_and_sets_state`, `search_sets_last_shown_products`, `results_wrapped_in_tool_data`.
- [ ] **Step 2: Test bất biến** (`test_invariants.py`): `no_tool_named_like_order_creation` (không tên nào khớp `create_order|place_order|confirm`), `php_client_never_calls_confirm` (quét mã nguồn `agent_graph/` không có chuỗi `chat_order_confirm`), `every_write_tool_has_scope`.
- [ ] **Step 3–5:** FAIL → viết → PASS.
- [ ] **Step 6: Commit** `feat(agent): typed commerce tools with scope enforcement`.

### Task 13: Trạng thái hội thoại + router ý định

**Files:**
- Create: `ai-service-flask/agent_graph/state.py`, `agent_graph/router.py`
- Test: `ai-service-flask/tests/agent_graph/test_router.py`, `ai-service-flask/tests/agent_graph/router_cases.yaml`

**Interfaces:**
- Produces: `@dataclass ConvState(last_shown_products: list[dict] = field(default_factory=list), open_draft_id: str | None = None, pending: str = "none", intent_queue: list[str] = field(default_factory=list))`; `StateStore(redis_client).load(sid: str) -> ConvState` / `.save(sid, state, ttl=7200)` (key `skinsyntax:agent:state:<sid>`, JSON; Redis lỗi → `ConvState()` mới, không ném). Chỉ lưu `id`, `name`, `idx` của sản phẩm và `draft_id` — không lưu địa chỉ.
- `class RouterOutput(BaseModel)` đúng schema P1 (`intents: list[{label: Literal[...10 nhãn], confidence: float 0–1}]`, `entities`, `needs_clarification: str | None`).
- `fast_route(message: str, state: ConvState) -> RouterOutput | None`: `#?DH\d+` hoặc "đơn (hàng )?(của tôi|tới đâu|đâu rồi)" → `ORDER_STATUS`; `state.pending.startswith("draft_open")` + từ khóa `địa chỉ|cod|vnpay|chuyển khoản|voucher|mã giảm|số lượng` → `BUY`; "thêm (vào )?giỏ|đặt luôn|chốt" + có `last_shown_products` → `BUY`. So khớp trên chuỗi đã bỏ dấu (dùng `SupportKnowledge.fold` tương đương Python có sẵn trong `chatbot_service` nếu có, nếu không viết `fold()` trong `router.py`).
- `classify(message: str, state: ConvState, recent_turns: list[dict], llm) -> RouterOutput`: thử `fast_route`, sau đó `llm.with_structured_output(RouterOutput)` với `ROUTER_PROMPT`; LLM lỗi / `None` → `RouterOutput(intents=[ADVISE 0.5])`. Hậu xử lý: tham chiếu thứ tự ("cái thứ N", "lọ đầu tiên") giải theo `last_shown_products`; không giải được → `needs_clarification` = "Bạn đang nói tới sản phẩm nào ạ?"; tất cả `confidence < 0.6` → `needs_clarification` từ LLM hoặc câu mặc định.

- [ ] **Step 1: Test** (LLM giả trả `RouterOutput` cố định): `order_code_fast_routes`, `draft_open_payment_change_is_buy`, `ordinal_resolves_against_last_shown`, `ordinal_without_shown_products_needs_clarification`, `low_confidence_asks`, `llm_failure_defaults_to_advise`, `state_roundtrip_and_redis_down` (fakeredis).
- [ ] **Step 2:** Tạo `router_cases.yaml` 40 câu (đủ 10 nhãn, có teencode, không dấu, nhiều ý định) — dùng ở Task 18.
- [ ] **Step 3–5:** FAIL → viết → PASS.
- [ ] **Step 6: Commit** `feat(agent): conversation state and intent router`.

### Task 14: Đồ thị LangGraph

**Files:**
- Create: `ai-service-flask/agent_graph/graph.py`
- Test: `ai-service-flask/tests/agent_graph/test_graph.py`, `ai-service-flask/tests/agent_graph/fakes.py`

**Interfaces:**
- Consumes: `classify` (Task 13), `build_tools` (Task 12), `prompts` (Task 11), `chatbot_service.chatbot_flask.xu_ly_cau_hoi(message, msg_data) -> dict` (luồng cũ, trả `answer` trong dict).
- Produces: `build_graph(router_llm, agent_llm, legacy: Callable[[str, dict], dict]) -> CompiledGraph`; `run_turn(graph, *, message: str, ctx: ToolContext, history: list[BaseMessage], recent_turns: list[dict], legacy_payload: dict) -> Iterator[tuple[str, dict]]` (yield sự kiện SSE theo Global Constraints, luôn kết thúc bằng `done` hoặc `error`).
- Node: `route` (gọi `classify`; có `needs_clarification` → phát `ui clarify {question, options}` + `token` câu hỏi → kết thúc) → điều kiện:
  - `STORE_SUPPORT`, `SKIN_SURVEY`, `SMALLTALK`, `OUT_OF_SCOPE`, `KNOWLEDGE` → node `legacy` (gọi `xu_ly_cau_hoi`, phát `token` = `answer`, sản phẩm trả về → `ui product_list`).
  - `ADVISE`, `PRODUCT_INFO`, `BUY`, `CART`, `ORDER_STATUS` → node `agent`: vòng gọi tool kiểu ReAct (`agent_llm.bind_tools(tools)`), tối đa `MAX_STEPS` lượt gọi tool, system prompt = `render(AGENT_PROMPT, …)` với ngữ cảnh đã che; stream `token` từ phần chữ cuối.
  - Khách chưa đăng nhập + `BUY|CART|ORDER_STATUS` → phát `ui login_required` + câu ngắn, không gọi tool.
- Hết `TURN_TIMEOUT_S` hoặc `MAX_STEPS` → phát phần chữ đã có + câu "Mình cần thêm chút thời gian, bạn nhắn lại giúp mình nhé." rồi `done`.
- Sau lượt: cập nhật `state.pending` (`draft_open:<id>` nếu có `open_draft_id`) và `StateStore.save`.

- [ ] **Step 1:** `fakes.py`: `ScriptedChatModel(BaseChatModel)` trả lần lượt các `AIMessage` định sẵn (có `tool_calls`), hỗ trợ `bind_tools` (trả chính nó) và `with_structured_output`.
- [ ] **Step 2: Test:** `advise_then_buy_flow` (kịch bản: search → add_to_cart → get_checkout_defaults → prepare_order_draft; sự kiện có `product_list`, 1 `cart_effect`, `draft_card`, kết thúc `done`), `store_support_goes_legacy`, `guest_buy_gets_login_required_without_tool_calls`, `max_steps_stops_cleanly`, `timeout_stops_cleanly` (clock giả), `model_claims_order_created_is_still_no_order` (LLM giả viết "đã đặt hàng thành công" → không có sự kiện nào ngoài `token`; PhpClient giả không nhận route xác nhận).
- [ ] **Step 3–5:** FAIL → viết → PASS.
- [ ] **Step 6: Commit** `feat(agent): LangGraph agent with legacy fallback`.

### Task 15: Endpoint SSE Flask `/api/agent/chat`

**Files:**
- Create: `ai-service-flask/agent_graph/routes.py`
- Modify: `ai-service-flask/chatbot_service/chatbot_flask.py` (cạnh L3331–3339: `app.register_blueprint(agent_bp)` khi `config.AGENT_V2`)
- Test: `ai-service-flask/tests/agent_graph/test_routes.py`

**Interfaces:**
- Consumes: `run_turn`, `identity_from_request`, `PhpClient`, `StateStore`, hàm lưu hội thoại Mongo có sẵn (`create_new_conversation`, `save_chat_messages`, `get_conversation_by_id`).
- Produces: `POST /api/agent/chat`, header `X-Internal-Token` (= `CHAT_AGENT_INTERNAL_SECRET`, so `hmac.compare_digest`) + `X-Agent-Token`; body `{message ≤2000, conversation_id?, cart_snapshot: [{id, name, qty, unit_price}] ≤50, profile?: {loai_da, van_de}}`. Trả `text/event-stream` (`Cache-Control: no-cache`, `X-Accel-Buffering: no`). Sai internal token → 401 JSON; token agent không hợp lệ → chạy như khách (`scope chat`). Sự kiện `meta` đầu tiên mang `conversation_id` (tạo mới nếu cần, chỉ khi có `claims`). Cuối lượt lưu tin nhắn vào Mongo như `chat()`.

- [ ] **Step 1: Test** (Flask test client, `run_turn` giả): `rejects_missing_internal_token`, `streams_meta_first_done_last`, `invalid_agent_token_runs_as_guest`, `message_too_long_400`, `not_registered_when_flag_off`.
- [ ] **Step 2–4:** FAIL → viết → PASS; hồi quy `pytest ai-service-flask/tests -q`.
- [ ] **Step 5: Commit** `feat(agent): SSE endpoint behind SYNA_AGENT_V2`.

### Task 16: Proxy SSE phía PHP (`ai_chat_agent_stream`)

**Files:**
- Create: `backend/app/services/ChatSseRelay.php`
- Modify: `backend/app/controllers/ChatAgentController.php` (method `stream()`), `backend/app/routes/chat_agent.php` (route `ai_chat_agent_stream`)
- Modify: `backend/app/config/config.php` (hằng `AI_AGENT_ENDPOINT`, mặc định `http://127.0.0.1:5002/api/agent/chat`)
- Test: `backend/tests/unit/ChatSseRelayTest.php`

**Interfaces:**
- Consumes: `ChatAgentToken`, `ChatAgentGate`, `ChatCartEffect`, `RateLimiter`, `CheckoutCore::preview` (để dựng `cart_snapshot` từ `$_SESSION['gio_hang']`).
- Produces: `new ChatSseRelay(callable $out, callable $applyEffect)`; `->feed(string $chunk): void` (ghép chunk, tách theo `\n\n`, parse `event`/`data`); với `cart_effect`: gọi `$applyEffect(array $effect): ?int` (trả số món giỏ mới hoặc null nếu effect sai) → phát `cart_updated {count}` hoặc bỏ qua; sự kiện khác → `$out(string $raw)` nguyên văn; `->finish(): void` (chunk dở dang → phát `error`).
- `stream()`: POST JSON; `ChatAgentGate::agentEnabled()` false → 404 JSON `{fallback:true}`; rate limit `('chat_agent_msg', $sub|ip, 20, 60)` và `('chat_agent_day', …, 300, 86400)` → 429; dựng token (scope theo Gate, `sid`=conversation_id); dựng `cart_snapshot`; `session_write_close()`; header SSE; `curl` với `CURLOPT_WRITEFUNCTION` → `$relay->feed`; `$applyEffect` = `session_start()` → `ChatCartEffect::verify` (đúng `sub`) → `apply` → đếm → `session_write_close()`. Timeout cURL 60 giây; lỗi kết nối → phát `error {message:"Trợ lý đang bận, bạn thử lại sau nhé."}` + `done`.

- [ ] **Step 1: Test:** `splits_events_across_chunks`, `forwards_token_events_verbatim`, `cart_effect_not_forwarded_and_emits_cart_updated`, `bad_effect_dropped`, `relay_applies_effect_after_reopening_session` (applyEffect giả ghi lại số lần gọi), `partial_chunk_on_finish_emits_error`.
- [ ] **Step 2–4:** FAIL → viết → PASS; `php -l`.
- [ ] **Step 5:** Kiểm tay bằng `curl -N` khi đăng nhập (cookie) với Flask bật cờ: thấy `meta` → `token`… → `done`.
- [ ] **Step 6: Commit** `feat(chat-agent): PHP SSE relay with signed cart effects`.

### Task 17: Khung chat đọc SSE + thẻ đơn nháp tối giản

**Files:**
- Create: `frontend/public/assets/js/chat-agent-stream.js`
- Modify: `frontend/views/components/ai_chat_widget.php` (thêm `data-agent-v2="<?= ChatAgentGate…agentEnabled() ? '1' : '0' ?>"`, `data-chat-csrf="<?= ShopAssistantController::csrfToken() ?>"` vào phần tử `[data-ai-chat-widget]`; nạp script mới; trong hàm `sendMessage` có sẵn: nếu `widget.dataset.agentV2 === '1'` thì gọi `window.SkinSyntaxAgent.send(...)` thay cho fetch cũ ở L2092)

**Interfaces:**
- Consumes: sự kiện SSE (Global Constraints), `POST r=chat_order_confirm` (Task 9).
- Produces: `window.SkinSyntaxAgent.send({message, conversationId, onToken, onUi, onCartUpdated, onDone, onError})` (đọc `fetch(...).body.getReader()`, tách sự kiện theo `\n\n`; HTTP 404 `{fallback:true}` → gọi lại luồng cũ); `window.SkinSyntaxAgent.renderDraftCard(payload, container)`; `window.SkinSyntaxAgent.confirmDraft(draftId)`.
- Thẻ đơn nháp tối giản: danh sách món (tên, SL, thành tiền), voucher, phí ship, tổng, người nhận (đã che), thanh toán, cảnh báo, đồng hồ đếm ngược tới `expires_at`, nút **Xác nhận đặt hàng** (khóa nút ngay khi bấm), **Hủy** (ẩn thẻ). `status:"created"` → thay thẻ bằng "Đã tạo đơn #<id>" + chuyển tới `next.url` nếu là `vnpay`/`qr`; `status:"changed"` → vẽ thẻ mới kèm dòng "Giá đã thay đổi, bạn kiểm tra lại nhé"; lỗi → hiện `message`. Hết hạn → nút xám, chữ "Đơn nháp đã hết hạn".
- `product_list` dùng lại markup thẻ sản phẩm hiện có của widget (nút `data-ai-add-cart` giữ nguyên). `login_required` → nút tới `index.php?r=dangnhap`. `clarify` → câu hỏi + các nút `options` (bấm = gửi tin nhắn đó). `cart_updated` → cập nhật badge giỏ trên header (selector badge hiện có).
- Mọi chuỗi từ server chèn bằng `textContent`, không dùng `innerHTML` với dữ liệu.

- [ ] **Step 1:** Viết JS + sửa widget. **Step 2:** Đọc skill `frontend-testing`; chạy linter JS nếu dự án có cấu hình, không thì `node --check frontend/public/assets/js/chat-agent-stream.js`. `php -l frontend/views/components/ai_chat_widget.php`.
- [ ] **Step 3:** Kiểm tay với cờ tắt: widget chạy như cũ (fetch cũ). Cờ bật: chữ hiện dần.
- [ ] **Step 4: Commit** `feat(chat-widget): stream agent replies and draft card`.

### Task 18: Bộ đánh giá router + kịch bản bất biến

**Files:**
- Create: `ai-service-flask/scripts/eval_agent_router.py`
- Test: dùng `tests/agent_graph/router_cases.yaml` (Task 13)

**Interfaces:**
- Produces: `python ai-service-flask/scripts/eval_agent_router.py [--cases PATH] [--min-acc 0.90]` → gọi `classify` với LLM thật, in bảng nhầm lẫn theo nhãn, độ chính xác tổng và riêng `BUY`, `ORDER_STATUS`; exit 1 nếu tổng < `--min-acc` hoặc `BUY`/`ORDER_STATUS` < 0.95. Không chạy trong pytest (tốn tiền); ghi kết quả vào `report/chat-agent/router-eval-<ngày>.json`.

- [ ] **Step 1:** Viết script. **Step 2:** Chạy với key thật trên local; ghi số liệu vào báo cáo task (40 câu là bộ khởi đầu; bộ 120 câu thuộc GĐ4).
- [ ] **Step 3: Commit** `test(agent): router eval script`.

### Task 19: E2E nghiệm thu GĐ1 + cập nhật tài liệu

**Files:**
- Modify: `update_chatbot_dathang.md` (thêm mục "Đã triển khai GĐ0–GĐ1"), `update.md` (dòng tóm tắt), `docs/plans/2026-09-11-store-assistance.md` (ghi chú đầu file: quyết định "không tạo đơn trong chat" được thay bằng xác nhận bằng nút, link spec)
- Test: `e2e/` theo skill `e2e` (đọc `.claude/skills/e2e/SKILL.md` trước)

- [ ] **Step 1:** Chuẩn bị: tài khoản khách test riêng; `SYNA_AGENT_V2=1` ở PHP và Flask; hai secret mới trong `.env` local.
- [ ] **Step 2:** Playwright kịch bản nghiệm thu: đăng nhập → gõ "mình da dầu mụn, tư vấn và đặt giúp 1 sữa rửa mặt giao về nhà, COD" → thấy thẻ sản phẩm → thẻ đơn nháp → bấm Xác nhận → thấy "Đã tạo đơn #…" → đơn có trong "Đơn hàng của tôi". Kịch bản phụ: bấm đúp Xác nhận → chỉ 1 đơn; khách chưa đăng nhập hỏi mua → thấy nút đăng nhập; chạy `chat_agent_switch.php off` → bot vẫn tư vấn nhưng không lập đơn nháp; chọn VNPay → được chuyển sang trang sandbox (không thanh toán).
- [ ] **Step 3:** Dọn dữ liệu: hủy mọi đơn test qua "Đơn hàng của tôi" (đường hủy có hoàn kho). Ghi mã đơn đã hủy vào báo cáo.
- [ ] **Step 4:** `git diff --check`; `node .gitnexus/run.cjs detect-changes --scope all --repo .` (không `partial`/`truncated`).
- [ ] **Step 5:** Cập nhật 3 tài liệu. **Step 6: Commit** `docs(chat-agent): GĐ0–GĐ1 handoff` (chỉ khi được phép).

---

## Ngoài phạm vi kế hoạch này

GĐ2 (Generative UI đầy đủ, nút gợi ý nhanh, thẻ so sánh đẹp, shadow mode), GĐ3 (MCP, Langfuse), GĐ4 (bộ đánh giá hội thoại 40 kịch bản + giám khảo P4, bộ tấn công 30 câu trong CI, router 120 câu, đo độ trễ). Lỗi `capnhat_sl_giohang` và thiếu CSRF ở trang thanh toán cũ sửa riêng.

## Chỗ lệch so với spec (đã cân nhắc)

- **Đơn nháp lưu Mongo `chat_order_drafts`, không trong session qua `ChatPurchaseService`.** Lý do: Flask gọi API nội bộ không mang session của khách. `ChatPurchaseService` giữ nguyên (không ai gọi), quy tắc của nó (SL 1–20, hạn 10 phút, kiểm giá) được giữ trong `ChatDraftService`.
- **Không dùng checkpointer LangGraph trên Redis.** Gói checkpointer Redis cần Redis Stack (RedisJSON/Search). Thay bằng `StateStore` JSON nhỏ (Task 13) + lịch sử từ Mongo `chat_conversations` — đủ cho spec và chạy trên Redis thường.
- **Token có thêm claim `em`** vì đơn hàng và hội thoại hiện định danh bằng email. Token chỉ đi giữa PHP và Flask, không log.

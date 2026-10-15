# SkinSyntaxVN — Security & Isolation Verification (Phase P1)

## 1. Credentials & Secrets Protection

- **No Hardcoded Secrets:** Valkey credentials reside solely within `.env` and environment variables.
- **Git Protection:** `.env` remains gitignored. The template `.env.example` contains only redacted placeholder credentials.
- **Log Sanitation:**
  - `CacheService::getSafeStatus()` parses the connection URL and explicitly excludes the password component.
  - Test scripts (`test_aiven_valkey_p1.php`, `test_cache_service_p1.php`) mask host and user info with `*****`.
  - Diagnostics and exception logging in `CacheService` strip passwords from log strings.

---

## 2. Wire Security & Transport Layer (TLS)

- **Protocol:** `rediss://` (RESP over TLS).
- **Transport Verification:**
  - OpenSSL 3.0.15 active in PHP container.
  - TLS streams (`tls`, `ssl`, `tlsv1.2`, `tlsv1.3`) verified via `stream_get_transports()`.
  - Stream context options configured with `verify_peer => true` to guard against Man-in-the-Middle (MITM) attacks.

---

## 3. Data Isolation & Business Integrity Boundaries

- **Authentication Data:** User passwords, hash records (`NguoiDung`, `TaiKhoan`), session states, and reset tokens are strictly prohibited from being cached in Valkey.
- **Cart & Order Integrity:** Shopping cart items (`$_SESSION['gio_hang']`) remain in-session and are computed authoritatively.
- **Checkout Pricing:** Cart calculations and order processing in `HomeController::xulydathang()` always fetch and validate product prices, stock, and vouchers against MongoDB Atlas.
- **Voucher Isolation:** Customer vouchers (`customer_vouchers`) and video rewards are tracked with optimistic locking and ACID updates in MongoDB Atlas, completely bypassing any external cache.
- **Personalized Recommendations:** If cached in Phase P3, user-tailored recommendations are segregated by user ID keys (`skinsyntax:rec:u:{user_id}`) so that no recommendations or browsing histories can be leaked across customer sessions.

---

## 4. Test Acceptance Checklist

- [x] Valkey connection requires TLS (`rediss://`).
- [x] Unauthenticated or bad credentials fail securely.
- [x] Passwords never print in console output or log files.
- [x] Broken cache connection triggers safe fallback to authoritative MongoDB without application failure.
- [x] Authoritative MongoDB Atlas connection remains untouched.

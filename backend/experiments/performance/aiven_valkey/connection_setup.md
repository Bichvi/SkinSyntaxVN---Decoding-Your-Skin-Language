# SkinSyntaxVN — Aiven Valkey Connection Setup & Validation (Phase P1)

## 1. Aiven Valkey Service Overview

- **Service Name:** `skinsyntax`
- **Engine:** Valkey 9.1.2
- **Topology:** Single node (Node 1)
- **Protocol:** RESP over TLS (`rediss://`)
- **Port:** 26436
- **Host:** `skinsyntax-lamngoc562004-3561.j.aivencloud.com`
- **Authentication:** Standard username (`default`) and secret authentication token.
- **REST API Key Status:** Verified not required for runtime database cache; Valkey standard wire protocol handles all CRUD/TTL commands directly over TLS.

---

## 2. PHP Environment & Client Selection

### 2.1 Extension vs Composer Client Audit
- PHP container `skinsyntax-php` runs PHP 8.2.33 with `ext-mongodb` 2.3.3 and `openssl` 3.0.15.
- The C-extension `ext-redis` is not compiled into the base image.
- Compiling C extensions in a running production container introduces rebuild friction and image drift.
- **Solution:** We selected `predis/predis: ^3.7.0`, the official, battle-tested pure PHP Redis & Valkey client.
- `predis/predis` 3.7.0 includes native support for Valkey, TLS streams (`ssl://`, `tls://`), TLS parameter verification, and requires zero native C compiling.
- Installed via `composer require predis/predis --ignore-platform-req=ext-mongodb`.

---

## 3. Environment Variables Configuration

The following environment variables were established in `.env`:

```env
# --- Aiven Valkey Cache ---
CACHE_DRIVER=valkey
VALKEY_URL=rediss://default:<REDACTED_PASSWORD>@skinsyntax-lamngoc562004-3561.j.aivencloud.com:26436
CACHE_PREFIX=skinsyntax:
CACHE_DEFAULT_TTL=300
```

And committed to `.env.example` as a safe redacted template:

```env
# --- Aiven Valkey Cache ---
CACHE_DRIVER=valkey
VALKEY_URL=rediss://default:YOUR_PASSWORD@your-valkey-host.aivencloud.com:PORT
CACHE_PREFIX=skinsyntax:
CACHE_DEFAULT_TTL=300
```

---

## 4. Connection & CRUD Smoke Test Results

Executed via `backend/tests/test_aiven_valkey_p1.php`:

```
Testing Valkey Connection to: rediss://default:*****@skinsyntax-lamngoc562004-3561.j.aivencloud.com:26436
[PASS] PING response: PONG (latency: 781.7 ms)
[PASS] SET key with TTL 60s
[PASS] GET verified matching content
[PASS] TTL verified: 60s remaining
[PASS] DELETE returned: 1
[PASS] Key confirmed deleted

=== ALL VALKEY SMOKE TESTS PASSED ===
```

Key observations:
1. **TLS Handshake:** Successfully established secure TLS connection to Aiven Cloud.
2. **Key Isolation:** Used prefix `skinsyntax:qa_test_valkey_*` to ensure no production collision.
3. **Password Masking:** Verified logs never print raw credentials.

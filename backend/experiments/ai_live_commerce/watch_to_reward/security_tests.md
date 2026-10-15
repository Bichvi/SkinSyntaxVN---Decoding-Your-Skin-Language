# Security Tests & Verification Matrix

## 1. Phase V1 & V1.1 Security Verification Results

| Threat / Test Vector | Test Description | Mitigation Implemented | Result |
| :--- | :--- | :--- | :--- |
| **Path Traversal (`../`)** | Requesting `id=../../etc/passwd` or `id=..%2F..%2Fetc%2Fpasswd` | Strict regex check `^[a-f0-9]{24}$` rejects any non-hex characters immediately with HTTP 404. | **PASS** |
| **Internal Filesystem Disclosure** | Testing error messages and response headers for leaks of `/app/storage` or `/var/www/html` | Sanity check confirms zero filesystem paths leaked in error outputs or headers. | **PASS** |
| **Unauthorized Asset Access** | Attempting to stream draft or unpublished AI videos directly | Streaming proxy explicitly verifies `is_published === true` against `ai_product_videos`; unpublished returns HTTP 404. | **PASS** |
| **Unapproved Video Publication** | Calling `publishVideo()` with a draft, deleted, or unrendered video ID | Backend checks `ai_idol_videos` and `ai_idol_segments` for status `READY`. Rejects invalid videos with error message. | **PASS** |
| **Cross-Product Video Spoofing** | Requesting a video stream with a `product_id` parameter not in `associated_product_ids` | Endpoint validates association list and responds with HTTP 404 if mismatch occurs. | **PASS** |
| **Session Lock Starvation** | Opening long-lived video streams blocking concurrent user requests | Proxy calls `session_write_close()` before streaming binary chunks, preventing PHP session locking. | **PASS** |
| **HTTP Byte Range Spoofing** | Sending custom `Range: bytes=0-1023` headers | Properly proxying byte ranges returning `HTTP 206 Partial Content` and accurate `Content-Range`. | **PASS** |
| **CSRF: Invalid Token** | Submitting `admin_ai_idol_publish_video` with invalid token `wrong_token` | Controller verifies token against `$_SESSION['admin_ai_idol_csrf']`, returns `HTTP 403 Forbidden` with JSON message. | **PASS** |
| **CSRF: Missing Token** | Submitting publish request without `csrf` payload parameter | Controller rejects request with `HTTP 403 Forbidden`. | **PASS** |
| **CSRF: Toggle Publish Protection** | Submitting `admin_ai_idol_toggle_publish` with invalid token | Controller rejects toggle request with `HTTP 403 Forbidden`. | **PASS** |
| **Admin RBAC Enforcement** | Unauthenticated guest accessing publish endpoints | Controller redirects unauthenticated guests (`HTTP 302`) to login or rejects (`HTTP 403`). | **PASS** |
| **Authorized Admin Request** | Valid CSRF token with active Admin session | Request accepted, publication updated, returns `HTTP 200 OK`. | **PASS** |

---

## 2. Security Test Script Output (`backend/tests/test_admin_csrf_rbac.php`)
```
=== RBAC & CSRF SECURITY VERIFICATION ===
1. Invalid CSRF token: HTTP Code = 403 (Expected 403)
   Response: {"ok":false,"message":"Lỗi bảo mật CSRF: Phiên làm việc không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang."}
2. Missing CSRF token: HTTP Code = 403 (Expected 403)
   Response: {"ok":false,"message":"Lỗi bảo mật CSRF: Phiên làm việc không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang."}
3. Unauthenticated Guest: HTTP Code = 302 (Expected 302 or 403)
4. Valid CSRF & Admin Session: HTTP Code = 200 (Expected 200)
   Response: {"ok":true,"publication":{...},"message":"Đã cập nhật xuất bản video thành công."}
5. Toggle publish Invalid CSRF: HTTP Code = 403 (Expected 403)
6. Toggle publish Valid CSRF: HTTP Code = 200 (Expected 200)
   Response: {"ok":true,"is_published":true,"publication":{...},"message":"Đã kích hoạt xuất bản video lên trang sản phẩm."}
```

---

## 3. Phase V2 Security Threat Matrix (Planned for Next Phase)

| Threat Vector | Potential Impact | Planned Phase V2 Countermeasure |
| :--- | :--- | :--- |
| **Fast-Forward / Seek Cheat** | User seeks to video end and triggers reward claim | Server validates accumulated wall-clock time and monotonic heartbeat progress. Instant completion rejected. |
| **Scripted Fake Completion Request** | Direct POST to claim endpoint without watching | Endpoint verifies active server watch session in database with validated heartbeats. |
| **Replay Heartbeats** | Script replays identical heartbeat payload | Server tracks sequential heartbeat counter and monotonic timestamps. |
| **Concurrent Browser Tabs** | Watching multiple tabs to multiply rewards | Single active session enforced per customer; compound index prevents duplicate claims. |
| **Anonymous Claim** | Guest user attempts to claim voucher | Authentication strictly required; guest prompted to log in/register before claim. |
| **Checkout Price Tampering** | Submitting manipulated discounted prices | Server recalculates catalog prices and enforces voucher rules server-side. |

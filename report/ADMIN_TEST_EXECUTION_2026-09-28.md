# Kết quả thực thi kiểm thử admin — 2026-09-28

## Tóm tắt

- Static admin contract: **PASS — 310/310 PASS, 0 FAIL**.
- Runtime HTTP smoke: **PASS — 74/74 PASS, 0 FAIL, 0 BLOCKED**.
- PHP syntax: **PASS** cho các router, controller và test runner liên quan.
- `git diff --check`: **PASS**.
- Authenticated CRUD/E2E browser suite: **NOT RUN**; repository không có PHPUnit/Playwright/Cypress harness và chưa có test account/fixture an toàn được cung cấp. Không tự tạo dữ liệu nghiệp vụ trong database.

## Môi trường đã kiểm tra

Docker Compose đang chạy local; nginx tại `http://127.0.0.1:8080`, MongoDB tại port `27018`, Redis tại `6380`, AI service tại `5001`, AI Idol tại `5003`. Runtime smoke chỉ gọi các URL admin bằng session mới, không gửi credential và không mutate dữ liệu.

## Lệnh và kết quả

```text
php -l backend/app/controllers/QuanTriController.php                           PASS
php -l backend/app/controllers/HomeController.php                              PASS
php -l frontend/public/index.php                                                PASS
php -l backend/public/index.php                                                 PASS
php -l backend/tests/admin_usecase_contract_test.php                             PASS
php backend/tests/admin_usecase_contract_test.php                               PASS (310/310)
.\backend\tests\admin_runtime_smoke.ps1 -BaseUrl http://127.0.0.1:8080         PASS (74/74)
POST admin_sepay_webhook không có secret                                        PASS (401 Unauthorized)
POST admin_sepay_manual_sync bằng guest                                         PASS (302 redirect login)
git diff --check                                                                 PASS
```

## Lỗi đã sửa

### P0 — Route gọi method không tồn tại

Đã sửa `backend/public/index.php` và `frontend/public/index.php`:

- `admin_login`/`admin_logout` dùng đúng `AuthController`.
- Bổ sung handler order print và review moderation/reply trong `QuanTriController`.
- `admin_sepay_webhook` dùng `HomeController::paymentWebhook()`.
- `admin_sepay_manual_sync` dùng polling SePay có kiểm tra admin, method, order và idempotency qua `HoaDon::markPaidByTransfer()`.

Runtime smoke sau sửa không còn `Fatal error`, `Call to undefined method`, `Parse error` hoặc `Uncaught Error`.

### P0 — Inventory route không có trong frontend router

Đã thêm require và đủ sáu case `admin_inventory_*` vào `frontend/public/index.php`, đồng bộ mapping với backend:

- `admin_inventory_batches`
- `admin_inventory_auto_seed`
- `admin_inventory_diverse_seed`
- `admin_inventory_fixed_expiry_seed`
- `admin_inventory_batch_save`
- `admin_inventory_movement`

Static parity hiện PASS ở cả hai entrypoint.

### P1 — Cần bổ sung authenticated regression

Các case trong `TESTCASE_ADMIN_FULL.md` cần chạy với `ADMIN_TEST`, `STAFF_TEST` và fixture dữ liệu riêng để xác nhận CRUD, state transition, inventory ledger, voucher consume, review/Q&A, LiveStream, AI Idol và SePay. Hiện chưa chạy vì không có harness/fixture account an toàn trong repository.

## Artefact

- Test runner: `backend/tests/admin_usecase_contract_test.php`
- Runtime smoke: `backend/tests/admin_runtime_smoke.ps1`
- Ma trận testcase chi tiết: `report/TESTCASE_ADMIN_FULL.md`

## Giới hạn còn lại

Authenticated CRUD/E2E chưa được xác nhận vì thiếu tài khoản test/fixture an toàn và repo không có PHPUnit/Playwright/Cypress harness. Runtime hiện mới xác nhận guest boundary và không có fatal; cần chạy các case có mutation bằng tài khoản admin/staff trên database test riêng.

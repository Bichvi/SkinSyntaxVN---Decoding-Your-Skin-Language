# U01 — TOKEN NỘI BỘ, SALT NGƯỜI DÙNG, TÀI KHOẢN TEST

## Vai trò
Kỹ sư bảo mật/DevOps. Đóng 3 lỗ hổng cấu hình còn lại của giai đoạn 1.

## Đọc trước
`docs/prompts/upgrade/u00-common-rules.md` → `docs/plans/upgrade-handoff.md` → code liên quan bên dưới.

## Bối cảnh
- Flask `search_service/routes.py`: header `X-Internal-Token` so (hmac) với env `SEARCH_INTERNAL_TOKEN`; env rỗng → chỉ cho IP loopback/private.
- PHP `backend/app/services/SearchRecommendClient.php`: chỉ gửi header khi `SEARCH_INTERNAL_TOKEN` có giá trị. `backend/config/config.php` (hoặc file config thật) nạp `.env` và **ghi đè** env của compose.
- `SanPhamController::buildSearchFallback` + `SearchEventTracker`: `user_hash = sha256(email + SEARCH_USER_HASH_SALT)`; salt rỗng → `user_hash = null`.
- Không có tài khoản test → E2E đăng nhập (khách có hồ sơ da) và admin chưa chạy được. Có biến `E2E_ADMIN_*` được nhắc trong E2E skill nhưng chưa đặt.

## Yêu cầu

### A. Token & salt
1. Kiểm `git check-ignore .env` → phải bị ignore. Nếu KHÔNG bị ignore: **dừng, báo người dùng**.
2. Nếu `.env` chưa có giá trị: sinh `SEARCH_INTERNAL_TOKEN` (64 hex, `secrets.token_hex(32)`) và `SEARCH_USER_HASH_SALT` (64 hex), **thêm** vào `.env` (không ghi đè key đã có giá trị, không in giá trị ra màn hình/log).
3. Đảm bảo cả `ai-service` và `php-backend` nhận được 2 biến (compose `environment:` dùng `${VAR:-}`; xác nhận PHP config đọc được qua `.env`).
4. Flask: khi token **đã cấu hình**, thiếu/sai token → 401 cho MỌI IP (kể cả private). Khi token rỗng giữ hành vi cũ + log cảnh báo 1 lần. Viết test cho cả 2 chế độ.
5. Recreate: `docker compose up -d --no-deps ai-service php-backend`.
6. Ghi chú vận hành: xoay vòng token (đổi `.env` + recreate 2 container), thay salt làm mất liên kết lịch sử `user_hash` cũ (ghi rõ hệ quả trong handoff).

### B. Tài khoản test (seed riêng cho E2E)
1. Tìm cơ chế seed/fixture có sẵn (rg `seed`, `fixture`, script tạo user/admin, collection `khach_hang`, `nhan_vien`/`admin`…). Tìm cách mật khẩu được hash (password_hash?).
2. Viết script **idempotent** `scripts/e2e/seed_test_accounts.php` (hoặc vị trí phù hợp với repo) tạo/cập nhật:
   - Khách `e2e.customer@skinsyntax.test` có hồ sơ da: loại da dầu (`tinh_trang_dac_biet` chứa `loaida:<id da dầu>`), `van_de_da` = "Mụn, Thâm mụn".
   - Admin `e2e.admin@skinsyntax.test` với vai trò admin theo đúng schema thật.
   - Đánh dấu `is_test_account: true` (hoặc trường tương đương) để có thể lọc/xóa; không gửi email, không tạo đơn.
   - Mật khẩu: sinh ngẫu nhiên, lưu vào `.env` dưới `E2E_CUSTOMER_EMAIL/E2E_CUSTOMER_PASSWORD/E2E_ADMIN_EMAIL/E2E_ADMIN_PASSWORD` (không in ra). Script đọc từ env.
   - Đây là **ghi Mongo được phép** duy nhất của bước: chỉ 2 document tài khoản test (upsert theo email). Thêm script xóa `scripts/e2e/remove_test_accounts.php`.
3. Đọc `.claude/skills/e2e/SKILL.md` và cập nhật hướng dẫn dùng biến mới (nếu skill có chỗ phù hợp; không viết lại skill).
4. Đảm bảo tài khoản test không xuất hiện trong thống kê/báo cáo nếu có thể lọc dễ (ghi rủi ro nếu không).

## Kiểm chứng (chạy thật)
- pytest `ai-service-flask/tests/search_service` (có test mới cho token).
- curl tới `localhost:5001/api/search/recommend`: không token → 401; đúng token → 200 (đọc token từ `.env` trong shell, không in).
- Trang `http://localhost:8080/index.php?r=tatca&q=xyzabc` vẫn có gợi ý (PHP gửi đúng token).
- Chạy seed 2 lần → không tạo trùng. Playwright MCP: đăng nhập khách test → tìm `kem dưỡng da dầu` / một truy vấn ít kết quả → có section "Hợp với làn da của bạn"; đăng nhập admin test → trang quản lý sản phẩm tìm "torriden" và "1496" đúng. Ảnh vào `report/upgrade-e2e/u01-*.png`. Đăng xuất sau khi xong.
- `search_events` mới có `user_hash` khác null khi khách test đăng nhập.

## Đầu ra
Mục "U01" trong handoff: tên biến env mới, cách xoay token, script seed/xóa, kết quả E2E. Không commit.

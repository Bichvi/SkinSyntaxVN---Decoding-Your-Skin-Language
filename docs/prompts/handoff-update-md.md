# Prompt: viết file bàn giao `update.md`

Dán nguyên khối dưới đây cho agent (Codex, Claude, Cursor…). Đổi phần trong `<…>` cho đúng việc vừa làm.

---

Bạn đang làm việc trong repo `<đường dẫn repo>`, nhánh `<nhánh>`.

**Nhiệm vụ.** Viết một file `update.md` ở thư mục gốc, bằng tiếng Việt. Người đọc là một coding agent khác. Chỉ cần đọc file này là nó phải hiểu đủ hệ thống và thay đổi `<tên tính năng / đợt thay đổi>` để làm tiếp, không phải đọc lại cả repo.

**Phạm vi thay đổi:** `<các commit, ví dụ git diff A..B, hoặc "các thay đổi chưa commit">`.

**Không được làm:**
- Không commit, không push.
- Không ghi bí mật: giá trị `.env`, API key, mật khẩu, chuỗi kết nối DB, token. Chỉ được nêu tên biến.
- Không đoán. Mọi tên file, hàm, trường dữ liệu và endpoint phải lấy từ code thật (`git show --stat`, `git diff`, đọc file). Điều gì chưa kiểm chứng được thì ghi rõ "(chưa xác minh)".
- Nếu `update.md` đã có nội dung cũ thì đừng xoá mất. Đổi tên file cũ (ví dụ `update-<ngày>-old.md`) hoặc ghi ở đầu file mới cách lấy lại nó.

**Cách làm:**
1. Đọc `AGENTS.md` / `CLAUDE.md`, các tài liệu spec/plan liên quan và các commit trong phạm vi.
2. Đọc code xung quanh cho tới khi mô tả được cả luồng chạy: route, controller, service, worker, model và giao diện.
3. Chạy test, lint, build có sẵn trong repo nếu chạy được. Ghi lại lệnh và kết quả thật. Lỗi nào có từ trước thì tách riêng.

**Cấu trúc bắt buộc của `update.md`.** Dùng bảng và sơ đồ ASCII khi phù hợp.
1. **Tổng quan:** mục tiêu dự án và tính năng; stack; các service; cách chạy local (port, lệnh); lệnh test; những lệnh nguy hiểm không được chạy.
2. **Kiến trúc và ranh giới giữa các lớp:** browser → backend → service → worker → DB/cache/storage. Ghi rõ xác thực và CSRF xử lý ở lớp nào.
3. **Pipeline / luồng hoạt động từ đầu đến cuối:** từng bước, mỗi bước kèm tên hàm và đường dẫn file.
4. **Máy trạng thái:** mọi status/stage, bảng chuyển trạng thái, và ai kích hoạt mỗi lần chuyển (pipeline, admin, watchdog…).
5. **Cơ sở dữ liệu:** các collection/bảng; trường quan trọng kèm kiểu và ví dụ; trường mới thêm; index; dạng snapshot.
6. **Hợp đồng API:** với mọi endpoint liên quan ghi method, URL/route, request JSON, response JSON, mã trạng thái, kèm payload mẫu.
7. **Luật nghiệp vụ và validation:** các mã lỗi, phân loại lỗi, luật nào đã bỏ và luật nào thay thế.
8. **Cơ chế tự sửa, retry, idempotency, xử lý race condition.**
9. **Các module đặc thù:** ví dụ TTS, render, publish. Ghi đầu vào → xử lý → đầu ra của từng module.
10. **Frontend:** màn hình, id phần tử, hành vi nút, nhãn trạng thái, giao diện mobile.
11. **Toàn bộ thay đổi theo từng commit:** từng file, đổi gì và vì sao.
12. **Test:** file test mới hoặc đã sửa và chúng phủ gì; cách chạy E2E; kết quả thật; lỗi có sẵn từ trước.
13. **Cấu hình và cờ môi trường:** tên biến, mặc định, tác dụng, cờ dùng để rollback.
14. **Rủi ro, nợ kỹ thuật, việc còn dở, chỗ lệch giữa spec và code, kế hoạch rollback.**
15. **Quy tắc khi sửa tiếp:** các quy tắc rút từ `AGENTS.md`, các bẫy đã gặp.

**Khi xong,** trả lời ngắn gồm: danh sách mục, độ dài file, các điểm lệch hoặc lỗi phát hiện được khi đọc code, và những gì chưa xác minh.

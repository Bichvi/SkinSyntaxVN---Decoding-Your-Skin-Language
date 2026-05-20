# TODO

Tệp TODO này dùng để theo dõi công việc ngắn hạn và trung hạn. Các mục được sắp xếp theo nhóm và độ ưu tiên.

## Frontend (UI/UX)
- [x] Trang chủ (hiển thị carousel + featured products)
- [x] Login / Register flow
- [ ] Product detail: hoàn thiện tab review / gallery
- [ ] Hồ sơ da: form lưu profile người dùng, mapping tới `khach_hang` (ưu tiên)

## Backend (PHP)
- [x] Kết nối MongoDB cơ bản (`backend/app/config/db.php`)
- [ ] Chạy audit code để loại bỏ giả lập SQL/PDO dư thừa (migrate logic) — priority: High
- [ ] Thêm JWT / API key cho API nội bộ (ai endpoints) — priority: High
- [ ] Thêm validation input và sanitize tất cả endpoints public

## AI service (Flask + RAG)
- [ ] Hoàn thiện pipeline sync -> embeddings -> products collection (chạy `sync_postgres_to_mongodb.py`) — priority: High
- [ ] Cấu hình Gemini API keys / Google GenAI lib để tạo embeddings và explanations — priority: High
- [ ] Tạo job định kỳ để rebuild embeddings khi data sản phẩm cập nhật — priority: Medium
- [ ] Bật LangChain / LlamaIndex routes (hiện bị comment trong `ai-service-flask/app.py`) — priority: Medium

## Infra / Ops
- [ ] Setup Redis (recommendation cache) và cấu hình trong `.env` — priority: Medium
- [ ] Containerize (Dockerfile + docker-compose) cho PHP + Flask + Mongo + Redis — priority: Medium
- [ ] Add monitoring + basic metrics for AI endpoints — priority: Low

## Tests & Docs
- [ ] Viết unit tests cho các model PHP quan trọng: SanPham, GoiYContentBased, HoaDon — priority: Medium
- [ ] Tạo script `mongo_indexes.js` để apply indexes đã đề xuất (documented in DATABASE.md) — priority: Low

## Short-term Next Actions (Immediate)
1. Đảm bảo local MongoDB đang chạy; test `backend/public/index.php` trang chủ. (`php -S` hoặc XAMPP)
2. Cài dependency Python cho `ai-service-flask` và chạy `app.py` nhẹ để kiểm tra endpoints `/api/health` và `/api/chat`.
3. Thử chạy `python ai-service-flask/sync_postgres_to_mongodb.py` trên môi trường dev (sao lưu DB trước khi overwrite dữ liệu).

# PROJECT STATUS - SkinSyntaxVN

## 1. Tổng quan dự án

- Tên: SkinSyntaxVN — Website mỹ phẩm có trợ giúp AI (chat + recommendation).
- Mục tiêu: Cung cấp trải nghiệm mua sắm kết hợp tư vấn chăm sóc da bằng AI (RAG, embeddings) và hệ thống quản trị đơn giản.
- Kiến trúc tóm tắt: PHP MVC (frontend + admin) + MongoDB (dữ liệu chính) + Flask AI service (RAG, embeddings, LangChain/LlamaIndex) + Redis (cache, tuỳ chọn) + Frontend: Bootstrap.

## 2. Kiến trúc hệ thống

- Frontend / Website: PHP MVC (custom minimal framework) — entry: `backend/public/index.php`.
- Backend dữ liệu: MongoDB — database mặc định: `skinsyntax` (cấu hình tại `backend/app/config/db.php`).
- AI microservice: Flask app tại `ai-service-flask/` cung cấp các endpoint RAG, chat và recommend.
- Vector DB / Embeddings: hỗ trợ lưu vector trong MongoDB (collection `products` / `products_rag`) hoặc Chroma/llamaindex theo cấu hình.
- Cache: Redis được cấu hình tùy chọn cho recommendation cache.

Kiến trúc (ngắn): User → PHP site (render) → gọi Flask AI (khuyến nghị/chat) → Flask dùng MongoDB/embedding → trả về JSON → PHP hiển thị.

## 3. Cấu trúc thư mục (chính)

- `backend/` — PHP MVC app (controllers, models, views, config).
- `ai-service-flask/` — Flask AI microservice, LangChain/LlamaIndex integration, hybrid retriever.
- `database/` — SQL / CSV helper files (data dumps, migration helpers).
- `docs/` — tài liệu dự án (bạn đang xem).
- `spiders/` — web-scraping helpers / data ingestion.
- `vendor/` — composer packages (PHP).

Xem file cấu hình chính: [backend/app/config/db.php](backend/app/config/db.php).

## 4. Công nghệ sử dụng

- Backend web: PHP (custom MVC), Composer packages.
- Database: MongoDB (primary). Một số tư liệu SQL vẫn được giữ trong `database/`.
- AI service: Python (Flask) + LangChain / LlamaIndex + Google Gemini embeddings (config trong `ai-service-flask/config.py`).
- Vector DB: Chroma (optional) / MongoDB vectors (production: Atlas vector or dedicated vector DB recommended).
- Frontend: Bootstrap, jQuery (tại views/layouts).
- Cache / Queue: Redis (optional).

## 5. Database collections

Xem chi tiết trong `docs/DATABASE.md` (file mô tả collection, trường, sample docs, index).

## 6. Các route hiện có

- Gốc routing PHP: `backend/public/index.php` sử dụng query param `r` (ví dụ `index.php?r=chitiet`).
- API AI (Flask): `/api/health`, `/api/chat`, `/api/recommend/hybrid`, `/api/recommend/explain`, `/api/recommend/langchain-rag`, `/api/query`, `/api/config`, v.v — xem `ai-service-flask/app.py` và `ai-service-flask/api/langchain_endpoints.py`.

Chi tiết bảng route -> xem [docs/ROUTES.md](docs/ROUTES.md).

## 7. Các chức năng đã hoàn thành

- [x] Trang chủ / danh sách sản phẩm / trang chi tiết (PHP)
- [x] Đăng ký / đăng nhập (email + social fallback)
- [x] Giỏ hàng đơn giản và tạo hóa đơn (MongoDB)
- [x] Admin basic: quản lý sản phẩm, danh mục, voucher, users (giao diện admin)
- [x] Content-based recommendation (PHP model `GoiYContentBased`)
- [x] Flask AI service scaffold + endpoints cơ bản

## 8. Các chức năng đang làm

- [ ] Hybrid RAG (LangChain / LlamaIndex) — nhiều phần init bị comment/disabled trong `ai-service-flask/app.py`
- [ ] Sync và chuẩn hoá collection `products_rag` (script `sync_postgres_to_mongodb.py` có sẵn nhưng cần chạy)
- [ ] Vector index / embedding pipeline (Gemini API keys cần cấu hình)
- [ ] JWT / API key cho endpoint AI (bảo mật)

## 9. Các chức năng chưa làm

- [ ] Triển khai production-ready vector DB (Atlas Vector / FAISS / Weaviate) và chuyển Chroma nếu cần
- [ ] Tests tự động (PHP + Python)
- [ ] CI / CD + containerization (Dockerfile / docker-compose)
- [ ] Migrate toàn bộ codebase từ giả lập SQL → thiết kế MongoDB chuẩn (type hints, validation)

## 10. Các lỗi hiện tại (tổng hợp nhanh)

- Một số phần code giả lập PDO/SQL tồn tại — `backend/app/config/db.php` gán `$pdo = $db` (MongoDB). Một số model/controller vẫn giả sử SQL/PDO (`TODO` migrate). (Xem `docs/BUGS.md`)
- LangChain import bị disable trong `ai-service-flask/app.py` (commented out). Nếu muốn bật, cần cài `langchain`, `langchain_google_genai` và cấu hình Google API key.

## 11. TODO tiếp theo (ưu tiên)

1. Khởi động lại Flask AI service với LangChain (cấu hình API keys) — Mức ưu tiên: Cao
2. Chạy `sync_postgres_to_mongodb.py` để populate collection RAG (`products` / `products_rag`) — Mức ưu tiên: Cao
3. Thiết lập index vector (MongoDB Atlas hoặc chuyển sang Chroma/Weaviate) — Trung
4. Bảo mật API (API key/JWT) cho endpoints AI — Trung
5. Viết tests cho critical flows (checkout, auth) — Thấp

## 12. Luồng hoạt động hệ thống (tóm tắt)

1. Người dùng truy cập trang PHP → front controller `index.php` đọc param `r` → gọi Controller tương ứng → Controller lấy dữ liệu từ MongoDB → render view.
2. Khi cần tư vấn AI hoặc recommendation, PHP gọi HTTP tới Flask AI service (`AI_RECOMMENDATION_ENDPOINT` / `AI_CHAT_ENDPOINT`).
3. Flask nhận request → nếu bật MongoDB RAG, gọi `MongoHybridRecommendationService` để lấy candidates (keyword + semantic) → (tuỳ cấu hình) gọi Gemini để tạo explanation → trả JSON.
4. PHP nhận kết quả và hiển thị trong widget/JS hoặc trả JSON cho SPA.

## 13. Hướng phát triển AI (gợi ý roadmap)

- Hoàn thiện pipeline sync -> embeddings -> vector index (tự động hóa bằng script và cron/job).
- Thiết lập fallback rõ ràng: nếu vector DB/LLM không sẵn sàng, dùng content-based (PHP) làm fallback.
- Xây monitoring cho latency và error rate của /api/recommend và /api/chat.
- Đặt giới hạn rate và authentication cho endpoints.

---

_Tài liệu này được tạo tự động từ mã nguồn hiện tại (scan controllers, models, ai-service). Nếu muốn tôi cập nhật thêm chi tiết (ví dụ: sơ đồ mermaid, checklist owner), bảo tôi biết._


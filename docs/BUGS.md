# BUGS

Dưới đây là các lỗi / rủi ro được phát hiện khi scan codebase. Mục tiêu: giúp debugging nhanh và onboarding.

## BUG-001 — Mismatch PDO vs MongoDB
- File: `backend/app/config/db.php` và các models
- Mô tả: `db.php` chuyển sang MongoDB và gán `$pdo = $db` (MongoDB\Database instance). Một số module/hàm vẫn giả sử `$pdo` là PDO/SQL, dẫn tới lỗi phương thức hoặc type mismatch runtime.
- Reproduce: Khởi chạy web và truy cập các chức năng dùng SQL-specific code (vd. functions expecting `->prepare()`)
- Fix gợi ý: Chuẩn hoá models để dùng MongoDB driver, hoặc tạo adapter/compat layer chuyển API PDO -> Mongo (hoặc revert các phần cần SQL sang Mongo queries).

## BUG-002 — LangChain / LlamaIndex imports commented
- File: `ai-service-flask/app.py`
- Mô tả: Khởi tạo LangChain/LlamaIndex bị comment out. Endpoint RAG có sẵn nhưng không khởi tạo component khiến `/api/recommend/langchain-rag` trả lỗi 503.
- Reproduce: Gọi `/api/recommend/langchain-rag` khi server chưa init => trả error "Recommendation service not initialized" hoặc 503.
- Fix: Cài các package cần thiết (`langchain`, `langchain_google_genai`...), cấu hình GOOGLE_API_KEY trong `.env`, bật init code và đảm bảo LLM client khởi tạo đúng.

## BUG-003 — sendmail Windows path / mail may fail
- File: `backend/app/controllers/AuthController.php` (sendHtmlEmail)
- Mô tả: Hàm cố gắng dùng `D:\xampp\sendmail\sendmail.exe` trên Windows; nếu không tồn tại, fallback dùng `mail()` PHP thường có thể không được cấu hình.
- Reproduce: Gửi email reset password => không gửi được trên dev máy chưa cấu hình sendmail.
- Fix: Document rõ requirement (XAMPP sendmail) hoặc chuyển sang SMTP (PHPMailer) với cấu hình qua `.env`.

## BUG-004 — Missing vector index / embedding pipeline not populated
- File: `ai-service-flask/rag/*` (hybrid_retriever.py, mongo_hybrid_service.py)
- Mô tả: Hybrid retriever assumes `products` collection populated with `embedding` vectors. Nếu chưa chạy sync script hoặc chưa có Google API keys, semantic search trả empty.
- Reproduce: Gọi `/api/recommend/hybrid` trên môi trường chưa sync => returns 503 hoặc empty products.
- Fix: Chạy `ai-service-flask/sync_postgres_to_mongodb.py` để populate, cấu hình `LlamaIndexConfig` và `GOOGLE_API_KEY`.

## BUG-005 — Some controllers mixing GET/POST assumptions
- File: nhiều controllers (SanPhamController::chitiet, AuthController::datLaiMatKhau...)
- Mô tả: Một số route xử lý cả GET/POST; client-side cần gửi đúng method; test coverage kém gây regressions.
- Fix: Tạo documentation rõ ràng route method, thêm CSRF/validation, tách rõ GET form vs POST submit.

## Action items
- Ưu tiên sửa BUG-001 (adapter/migrate SQL -> Mongo) để tránh lỗi runtime.
- Bật LangChain sau khi đảm bảo key & package (BUG-002).
- Thêm scripts kiểm tra health cho các dependency (Mongo, Redis, Gemini keys).

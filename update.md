# Nhật ký Cập nhật Dự án (Project Updates)

## 1. Tối ưu hóa truy xuất của Chatbot Chăm sóc Da (18/05/2026)
- **Mục tiêu:** Khắc phục tình trạng phản hồi của Chatbot AI bị cắt xén (truncation) và thiếu ổn định.
- **Chi tiết cập nhật:**
  - Debug và cải thiện cầu nối giao tiếp giữa backend PHP và dịch vụ Python Flask.
  - Đảm bảo các context JSON phức tạp được phân tích chính xác mà không làm crash LLM hay ngắt kết nối giữa chừng.
  - Cải thiện chất lượng, độ dài đầy đủ của câu trả lời, đồng thời bao gồm các đề xuất sản phẩm linh hoạt và link tương tác.
  - Xử lý hiệu quả tình trạng giới hạn rate-limit (lỗi 429) và logic fallback để giữ cho trải nghiệm người dùng luôn liền mạch.

## 2. Nhập dữ liệu Sản phẩm vào ChromaDB (18/05/2026)
- **Mục tiêu:** Nhập thành công 6,000 sản phẩm chăm sóc da từ file CSV vào vector database (ChromaDB) để tích hợp Chatbot.
- **Chi tiết cập nhật:**
  - Tích hợp ChromaDB với chatbot dựa trên LangChain.
  - Ánh xạ chính xác các thuộc tính của sản phẩm như tên, loại da, thành phần, và giá vào cơ sở dữ liệu vector.
  - Triển khai logic truy xuất (retrieval) kết hợp giữa tìm kiếm theo ngữ nghĩa (semantic search) và lọc siêu dữ liệu (metadata filtering).
  - Đảm bảo chatbot đưa ra các lời khuyên cá nhân hóa, gợi ý sản phẩm chính xác và bám sát bối cảnh người dùng.

## 3. Tích hợp AI vào ứng dụng PHP (09/05/2026)
- **Mục tiêu:** Tích hợp các tính năng AI vào kiến trúc ứng dụng web PHP/MVC và PostgreSQL hiện có.
- **Chi tiết cập nhật:**
  - Triển khai cơ sở dữ liệu Vector (ChromaDB) để hỗ trợ các tính năng như RAG (Retrieval-Augmented Generation) và tìm kiếm theo ngữ nghĩa (semantic search).
  - Thiết lập cầu nối giữa kiến trúc backend hiện tại với ChromaDB để lưu trữ và truy xuất dữ liệu vector một cách hiệu quả.

## 4. Xây dựng Cơ chế Dự phòng Đa mô hình (LLM Fallback) (19/05/2026)
- **Mục tiêu:** Tăng cường độ ổn định của AI service bằng cách thiết lập cấu trúc đa dự phòng (failover), tự động chuyển đổi qua lại giữa các LLM khác nhau khi model chính (Gemini) gặp lỗi hoặc hết quota (Lỗi 429).
- **Chi tiết kỹ thuật:**
  - **Tích hợp Fallback APIs:** Khởi tạo thành công 3 endpoint dự phòng tương thích `ChatOpenAI` bao gồm Groq (`gemma2-9b-it`), OpenRouter (`meta-llama/llama-3.1-8b-instruct:free`), và Zhipu AI (`glm-4-flash`).
  - **Quản lý Environment Variables:** Tách toàn bộ API Keys khỏi mã nguồn để bảo mật (Dùng `os.getenv`). Xử lý lỗi khoảng trắng thừa ở `OPENAI_API_KEY` trong file `.env` giúp script `start_chatbot.bat` nhận diện chính xác cấu hình mà không còn bắt người dùng nhập tay.
  - **Khắc phục lỗi Structured Outputs:** Bổ sung cơ chế `Try-Except` cô lập lỗi ở bước Parse (`with_structured_output`) do một số model miễn phí không tương thích chuẩn JSON Schema. Nếu tất cả LLM đều văng lỗi Parse, hệ thống tự động fallback tạo một Object mặc định `PhanTichYeuCau(tu_khoa_ngu_nghia=message)`, đảm bảo ứng dụng không chết cứng (crash) và vẫn query được VectorDB.
  - **Xử lý Message Object:** Sửa lỗi API từ chối chuỗi (raw string) bằng cách tiêu chuẩn hóa payload truyền vào hàm `.invoke()` bằng Object `[HumanMessage(content=prompt)]` của LangChain.
  - **Gỡ lỗi Backend-Frontend Communication:** Fix bug rác hệ thống (zombie/ghost process) chiếm dụng port `5001`, gây lỗi 500 (`name 'get_vectorstore' is not defined`), chặn không cho Frontend lấy phản hồi từ tiến trình Python mới nhất.
  - **Khắc phục lỗi Timeout liên đới:** Vô hiệu hóa tính năng Exponential Backoff (tự động đợi và thử lại khi quá tải) mặc định của thư viện LangChain bằng tham số `max_retries=0`. Việc này giúp Python trả Exception ngay lập tức khi Model thứ 1 bị giới hạn, kịp thời chuyển đổi sang các LLM dự phòng và trả kết quả về Frontend trước khi PHP chạm mức `AI_CHATBOT_TIMEOUT=30` giây.

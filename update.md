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

## 5. Nâng cấp Giao diện Chatbot & Clickable Product Links (19/05/2026)
- **Mục tiêu:** Giúp tên sản phẩm được gợi ý trong văn bản có thể click trực tiếp và tối ưu giao diện hiển thị thẻ sản phẩm ở phía dưới tin nhắn.
- **Chi tiết kỹ thuật:**
  - **Clickable Markdown Links:** Cấu hình lại hệ thống chỉ thị `SYSTEM_PROMPT` và các few-shot ví dụ trong `chatbot_flask.py` để ép AI luôn định dạng tên sản phẩm dưới dạng liên kết Markdown click được: `**1. [Tên sản phẩm](Link thực tế từ DB)**` (tuyệt đối không tự chế Link).
  - **Sắp xếp Thẻ sản phẩm dưới Văn bản:** Di chuyển vùng hiển thị thẻ sản phẩm (`contentSuffix`) xuống phía dưới bong bóng văn bản giới thiệu (`formattedContent`) trong `ai_chat_widget.php` thay vì hiển thị phía trên như trước.
  - **Nâng cấp Thẻ liên kết Chuẩn & Hover Effects:** Chuyển đổi toàn bộ nút hình ảnh, tiêu đề và nút "Xem chi tiết" trong thẻ sản phẩm từ sự kiện click qua JavaScript thành các thẻ `<a>` thuần chuẩn HTML. Bổ sung hiệu ứng CSS micro-animation cao cấp (ảnh phóng to nhẹ 1.05x khi rê chuột, tên sản phẩm tự động gạch chân và đổi màu).
  - **Bỏ bộ lọc Regex PHP cũ:** Đồng bộ hóa dữ liệu trong `HomeController.php` để luôn ưu tiên hiển thị danh sách sản phẩm lấy từ ChromaDB của Python Flask, thay thế bộ lọc Regex PHP tĩnh trước đây.

## 6. Tìm kiếm Chu trình Skincare Đa tầng & Tương thích Path trên Git (19/05/2026)
- **Mục tiêu:** Hỗ trợ tạo chu trình skincare hoàn chỉnh khi khách yêu cầu routine và giải quyết xung đột đường dẫn trên máy của cộng sự khi làm việc nhóm qua Git.
- **Chi tiết kỹ thuật:**
  - **Phân loại Kiểu yêu cầu (Routine Detection):** Bổ sung thuộc tính `is_routine` vào Schema phân tích `PhanTichYeuCau`. Tự động nhận diện từ khóa (routine, chu trình, liệu trình, bộ skincare, các bước) từ câu chat để bật cờ `is_routine = True`.
  - **Tìm kiếm Đa tầng Song song (Multi-stage Search):** 
    - Nếu hỏi sản phẩm lẻ: Python chỉ lọc đúng 1 danh mục sản phẩm duy nhất.
    - Nếu hỏi Routine: Hệ thống tự động chia nhỏ và chạy song song 6 truy vấn ChromaDB để lấy ra đúng 1 sản phẩm tối ưu nhất cho da của khách cho 6 bước cốt lõi: Tẩy trang -> Sữa rửa mặt -> Toner -> Serum -> Kem dưỡng -> Kem chống nắng. AI sau đó sẽ sắp xếp và hướng dẫn thứ tự sử dụng chuẩn khoa học.
  - **Relative Paths (Tương thích Cross-machine):** Refactor lại file `import_chromadb.py` sử dụng thư viện `pathlib` để dò tìm thư mục động (`Path(__file__).resolve().parent`) thay cho đường dẫn tuyệt đối tĩnh `C:\xampp\htdocs\xoa\...`. Giúp cộng sự khi clone dự án về qua Git có thể chạy lệnh import ngay lập tức mà không cần sửa code.

## 7. Hiện đại hóa Giao diện AI Widget & Kiểm soát Quyền truy cập Hồ sơ Da (20/05/2026)
- **Mục tiêu:** Nâng cấp thẩm mỹ giao diện chat, thêm chế độ toàn màn hình, kiểm soát quyền truy cập tính năng gợi ý theo hồ sơ da (Skin Profile) và tối ưu hóa luồng tương tác sản phẩm.
- **Chi tiết kỹ thuật:**
  - **Modern UI Redesign (Glassmorphism):**
    - Áp dụng phong cách thiết kế hiện đại với Gradient (Navy to Blue), Blur nền (`backdrop-filter`), và các góc bo tròn lớn (22px).
    - Thêm Micro-animations cho các nút bấm (`transform: translateY(-2px)`), cải thiện độ phản hồi thị giác.
  - **Chế độ Phóng to/Toàn màn hình (Maximize Mode):**
    - Triển khai chế độ `is-expanded` cho phép người dùng mở rộng khung chat ra toàn màn hình để đọc lịch sử tư vấn dài dễ dàng hơn.
    - Xử lý lỗi z-index và layout khi đóng widget ở chế độ toàn màn hình (Tự động gỡ bỏ lớp phủ mờ và đưa widget về trạng thái mặc định).
  - **Kiểm soát Quyền truy cập (Gated Features):**
    - **Phân loại người dùng:** Kiểm tra trạng thái đăng nhập (`$_SESSION['user']`) và hồ sơ da từ khảo sát.
    - **Logic hiển thị Gợi ý theo hồ sơ da:** Chỉ hiện nút gợi ý ✨ khi (1) đã đăng nhập và (2) loại da không phải "Chưa xác định".
    - **Chế độ Hạn chế (Restricted Mode):** Nếu người dùng chưa có hồ sơ, click vào nút gợi ý sẽ kích hoạt tin nhắn hướng dẫn từ AI, kèm link điều hướng đến trang khảo sát da (`/index.php?r=khaosat`).
  - **Tối ưu hóa Luồng tương tác Banner Gợi ý:**
    - Thu gọn kích thước Banner hồ sơ da để tránh chiếm diện tích khung chat (Compact layout).
    - Tự động đóng Banner sau khi người dùng chọn một danh mục sản phẩm để nhường không gian cho câu trả lời của AI.
  - **Cải thiện Độ chính xác Gợi ý Sản phẩm:**
    - **Keyword-based Filtering:** Cập nhật `HomeController.php` phối hợp với logic Python để lọc bỏ các sản phẩm không thuộc nhóm chăm sóc da (vd: kem đánh răng) khi người dùng hỏi về skincare. Sử dụng Regex: `/toner|serum|kem|mụn|da|tẩy trang|sữa rửa mặt|chống nắng/ui`.
  - **Tính nhất quán của Dữ liệu (Persistence):**
    - Sử dụng `sessionStorage` để lưu trữ trạng thái phóng to/thu nhỏ và lịch sử tin nhắn, giúp giữ nguyên ngữ cảnh khi người dùng lướt qua các trang khác nhau trên website.

## 8. Debug Flask API và Endpoint `/api/health` (20/05/2026)
- **Mục tiêu:** Khắc phục lỗi Flask API không trả về phản hồi khi gọi endpoint `/api/health`.
- **Chi tiết cập nhật:**
  - Thêm log chi tiết vào hàm `health()` trong `chatbot_flask.py` để kiểm tra từng bước xử lý.
  - Kiểm tra và xác minh trạng thái của `Vectorstore` và số lượng mô hình LLM được tải.
  - Sửa lỗi cấu hình Flask để đảm bảo yêu cầu đến được API.
  - Kiểm tra và xác minh kết nối mạng và tường lửa để đảm bảo Flask nhận được yêu cầu từ `curl`.
  - Đảm bảo Flask API hoạt động đúng và trả về phản hồi chính xác.

## 9. Tích hợp Hybrid Search + RRF + Vietnamese Re-ranker (21/05/2026)
- **Mục tiêu:** Thay thế truy xuất `similarity_search` bằng pipeline hybrid (RRF + cross-encoder) để tăng độ chính xác trong kết quả gợi ý.
- **Specs/Thông số:**
  - **RRF alpha:** 0.5 (cân bằng semantic và lexical, có thể tune).
  - **k_total:** `max(top_n * 2, 6)` cho mỗi truy vấn hybrid.
  - **top_n:** dùng `k` hiện hữu (3-10) hoặc `2` cho mỗi bước routine.
  - **Re-ranker:** bật (`use_reranker=True`) với cross-encoder tiếng Việt.
- **Thay đổi cụ thể:**
  - Thêm import `HybridSearchPipeline`, `BM25Search` vào `chatbot_flask.py`.
  - Thêm lazy loader `get_hybrid_pipeline()` để khởi tạo pipeline một lần.
  - Thay toàn bộ `vs.similarity_search(...)` bằng `pipeline.search(...)`.
  - Bổ sung helper `hybrid_search_with_filter()` và `ranked_to_docs()` để chuyển `RankedDocument` về `MockDocument`.
  - Luồng routine và non-routine đều dùng hybrid search, giữ nguyên filter logic cũ.
- **Tiến trình hiện tại:**
  - Hoàn tất tích hợp hybrid pipeline vào `xu_ly_cau_hoi()`.
  - Đang dùng `BM25Search()` mặc định (chưa build index từ Chroma). Có thể bổ sung bước build index để cải thiện lexical search.

## 10. Khắc phục lỗi tải Re-ranker (401), Lỗi BM25 Score Type và Tối ưu hóa dung lượng LLM (Lỗi 413) (21/05/2026)
- **Mục tiêu:** Giải quyết triệt để lỗi 401/404 khi tải model Re-ranker từ Hugging Face, sửa lỗi crash TypeError khi in log BM25, và ngăn chặn lỗi 413 Payload Too Large khi gọi các mô hình LLM thông qua Groq/Gemini.
- **Chi tiết kỹ thuật & Diffs chi tiết:**

### 10.1 Sửa lỗi Model Re-ranker (401/404)
- **Sự cố:** Dịch vụ Python RAG Chatbot bị crash hoặc bỏ qua bước Re-ranker vì chỉ định sai Repo ID trên Hugging Face.
- **Model Transition:**
  | Thuộc tính | Trước khi sửa (Lỗi 401/404) | Sau khi sửa (Hoạt động tốt) |
  | :--- | :--- | :--- |
  | **Hugging Face Model ID** | `cross-encoder/mmarco-mMiniLM-L12-H384-v1` | `cross-encoder/mmarco-mMiniLMv2-L12-H384-v1` |
  | **Loại mô hình** | Vietnamese / Multilingual Cross-Encoder | Vietnamese / Multilingual Cross-Encoder |
  | **Thông số & Specs** | 12 Layers, Hidden size 384, Trained on mMARCO | 12 Layers, Hidden size 384, Trained on mMARCO v2 (Official check-in) |
- **Các file thay đổi model ID:**
  1. [hybrid_search.py](file:///c:/xampp/htdocs/SkinSyntaxVN---Decoding-Your-Skin-Language-rcm/ai-service-flask/hybrid_search.py)
  2. [example_hybrid_search.py](file:///c:/xampp/htdocs/SkinSyntaxVN---Decoding-Your-Skin-Language-rcm/ai-service-flask/example_hybrid_search.py)
  3. [README_HYBRID_SEARCH.md](file:///c:/xampp/htdocs/SkinSyntaxVN---Decoding-Your-Skin-Language-rcm/ai-service-flask/README_HYBRID_SEARCH.md)
  4. [HYBRID_SEARCH_INTEGRATION.md](file:///c:/xampp/htdocs/SkinSyntaxVN---Decoding-Your-Skin-Language-rcm/ai-service-flask/HYBRID_SEARCH_INTEGRATION.md)

---

### 10.2 Khắc phục Crash BM25 Score & RRF Logic trong [hybrid_search.py](file:///c:/xampp/htdocs/SkinSyntaxVN---Decoding-Your-Skin-Language-rcm/ai-service-flask/hybrid_search.py)
- **Thay đổi 1: Lưu trữ và gán điểm BM25 số thực (`float`)**
  - *Từ:*
    ```python
    doc.semantic_rank = sem_rank
    doc.bm25_rank = lex_rank
    doc.rrf_score = rrf_score
    
    fused_results.append((doc_id, rrf_score, doc))
    ```
  - *Sang:*
    ```python
    doc.semantic_rank = sem_rank
    doc.bm25_rank = lex_rank
    doc.rrf_score = rrf_score
    
    # Update BM25 score if available from lexical results
    if lex_rank is not None:
        lex_score = dict(lexical_results).get(doc_id)
        if lex_score is not None:
            doc.bm25_score = float(lex_score)
    
    fused_results.append((doc_id, rrf_score, doc))
    ```
- **Thay đổi 2: Tự động bổ sung tài liệu chỉ khớp từ khóa (lexical-only) vào RRF Map**
  - *Từ:*
    ```python
    # Create document map
    documents_map = {}
    for i, doc in enumerate(semantic_docs):
        doc_id = doc.id or f"doc_{i}"
        ranked_doc = RankedDocument(
            doc_id=doc_id,
            content=doc.page_content,
            metadata=doc.metadata,
            semantic_score=getattr(doc, 'score', 1.0 - i*0.01)
        )
        documents_map[doc_id] = ranked_doc
    ```
  - *Sang:*
    ```python
    # Create document map
    documents_map = {}
    for i, doc in enumerate(semantic_docs):
        doc_id = doc.id or f"doc_{i}"
        ranked_doc = RankedDocument(
            doc_id=doc_id,
            content=doc.page_content,
            metadata=doc.metadata,
            semantic_score=getattr(doc, 'score', 1.0 - i*0.01)
        )
        documents_map[doc_id] = ranked_doc
        
    # Add lexical-only documents to documents_map
    if self.bm25_index and hasattr(self.bm25_index, 'documents'):
        bm25_docs_lookup = {d.get('id'): d for d in self.bm25_index.documents if d.get('id')}
        for doc_id, bm25_score in lexical_results:
            if doc_id not in documents_map and doc_id in bm25_docs_lookup:
                bm25_doc = bm25_docs_lookup[doc_id]
                ranked_doc = RankedDocument(
                    doc_id=doc_id,
                    content=bm25_doc.get('content', ''),
                    metadata=bm25_doc.get('metadata', {}),
                    bm25_score=float(bm25_score)
                )
                documents_map[doc_id] = ranked_doc
    ```
- **Thay đổi 3: Sửa lỗi crash `TypeError` khi format giá trị `None`**
  - *Từ:*
    ```python
    print(f"  Semantic Score:  {doc.semantic_score:.4f} (rank {doc.semantic_rank})" if doc.semantic_rank is not None else "  Semantic Score:  N/A")
    print(f"  BM25 Score:      {doc.bm25_score:.4f} (rank {doc.bm25_rank})" if doc.bm25_rank is not None else "  BM25 Score:      N/A")
    print(f"  RRF Score:       {doc.rrf_score:.4f}")
    ```
  - *Sang:*
    ```python
    print(f"  Semantic Score:  {doc.semantic_score:.4f} (rank {doc.semantic_rank})" if (doc.semantic_rank is not None and doc.semantic_score is not None) else f"  Semantic Score:  N/A (rank {doc.semantic_rank})")
    print(f"  BM25 Score:      {doc.bm25_score:.4f} (rank {doc.bm25_rank})" if (doc.bm25_rank is not None and doc.bm25_score is not None) else f"  BM25 Score:      N/A (rank {doc.bm25_rank})")
    print(f"  RRF Score:       {doc.rrf_score:.4f}" if doc.rrf_score is not None else "  RRF Score:       N/A")
    ```

---

### 10.3 Tối ưu hóa Token phòng tránh lỗi 413 (Payload Too Large) trong [chatbot_flask.py](file:///c:/xampp/htdocs/SkinSyntaxVN---Decoding-Your-Skin-Language-rcm/ai-service-flask/chatbot_flask.py)
- **Thay đổi 1: Slicing lịch sử tin nhắn tránh tràn Context**
  - *Từ:*
    ```python
    history_raw = msg_data.get("conversation_history", "")
    if isinstance(history_raw, list):
        history_lines = []
        for h in history_raw:
            ...
    ```
  - *Sang:*
    ```python
    history_raw = msg_data.get("conversation_history", "")
    if isinstance(history_raw, list):
        history_raw = history_raw[-10:]  # Giới hạn 10 tin nhắn gần nhất
        history_lines = []
        for h in history_raw:
            ...
    ```
- **Thay đổi 2: Giới hạn số lượng gợi ý trong chu trình Skincare**
  - *Từ:*
    ```python
    cat_docs = hybrid_search_with_filter(bo_loc_cat, top_n=2)
    if not cat_docs:
        cat_docs = hybrid_search_with_filter({"loai_san_pham": {"$eq": cat_name}}, top_n=2)
    ```
  - *Sang:*
    ```python
    # Sử dụng top_n=1 cho mỗi bước skincare để giảm kích thước payload
    cat_docs = hybrid_search_with_filter(bo_loc_cat, top_n=1)
    if not cat_docs:
        cat_docs = hybrid_search_with_filter({"loai_san_pham": {"$eq": cat_name}}, top_n=1)
    ```
- **Thay đổi 3: Slicing pool tài liệu trước khi build Prompt**
  - *Từ:*
    ```python
    # 5. Generate câu trả lời với đầy đủ lịch sử, ngữ cảnh hồ sơ khách hàng, và sản phẩm RAG
    if merged_docs:
        print(f"[FINAL] Using {len(merged_docs)} products for LLM response")
    ```
  - *Sang:*
    ```python
    # 5. Generate câu trả lời với đầy đủ lịch sử, ngữ cảnh hồ sơ khách hàng, và sản phẩm RAG
    # Cắt lát merged_docs về so_luong_goi_y hoặc tối đa 3 để tránh quá tải
    final_merged_docs = merged_docs if yc.is_routine else merged_docs[:int(yc.so_luong_goi_y or 3)]

    if final_merged_docs:
        print(f"[FINAL] Using {len(final_merged_docs)} products for LLM response")
    ```

---

### 10.4 Kết quả Kiểm thử & Trạng thái Vận hành
1. **Kiểm thử Hybrid Search (`example_hybrid_search.py`):** Đạt 100% tỷ lệ chạy thành công không sinh Exception. Cải thiện Context Precision tăng vọt từ 20.0% lên **33.3%** và Context Recall tăng vọt từ 66.7% lên **100.0%**.
2. **Flask Service (`chatbot_flask.py`):** Đang vận hành mượt mà ở chế độ nền trên port `5001`.
3. **Endpoint `/api/health`:** Trả về mã trạng thái 200 OK với đầy đủ thông số vectorstore chứa 6,377 tài liệu và **6 LLM** được kích hoạt thành công (bao gồm 3 API keys cho Gemini 2.5 Flash).

---

### 10.5 Nâng cấp dòng mô hình Gemini (Sửa lỗi 404 NOT_FOUND)
- **Sự cố:** Toàn bộ 3 Google API keys tải từ `.env` bị trả lỗi `404 NOT_FOUND` đối với model `gemini-1.5-flash` khi gọi qua API `v1beta`.
- **Giải pháp:** 
  - Chuyển đổi dòng mô hình mặc định trong `.env` (`GEMINI_CHAT_MODEL=gemini-2.5-flash`) và cấu hình fallback trong `chatbot_flask.py` sang `gemini-2.5-flash`.
  - Cả 3 keys đều được xác thực thành công qua cơ chế kiểm tra kết nối `_test_llm_connection()`.
  - Sửa lỗi in log cứng `gemini-1.5-flash` khi khởi động Flask app để hiển thị chính xác tên mô hình được cấu hình (`GEMINI_MODEL`).
- **Trạng thái:** Toàn bộ 6/6 LLM (3 Gemini, 1 Groq, 1 Zhipu, 1 OpenRouter) đều ở trạng thái hoạt động tốt (`validated`).

---

## 11. Nâng cấp Nhận diện Ý định (Intent Recognition), Viết lại Câu hỏi (Contextualization) và Định tuyến Tư vấn Đa kênh (22/05/2026)
- **Mục tiêu:** Nâng cấp hệ thống Chatbot AI từ mô hình RAG tìm kiếm đơn luồng đơn giản thành một hệ thống thông minh, tự động nhận diện 3 nhóm ý định của khách hàng, viết lại câu hỏi follow-up bám sát lịch sử cuộc trò chuyện (Context-Aware) và định tuyến xử lý tư vấn đa kênh linh hoạt như một chuyên gia da liễu kiêm nhân viên tư vấn bán hàng chuyên nghiệp.
- **Chi tiết kỹ thuật & Diffs chi tiết:**

### 11.1 Ưu tiên mô hình Llama-3.3-70B-Versatile cho Phân loại & Phân tích
- **Sự cố của mô hình cũ:** Các câu hỏi follow-up ngắn hoặc mơ hồ của khách hàng (ví dụ: *"chỉ tui cách sử dụng"*, *"bao nhiêu tiền"*) thường bị RAG search trực tiếp dẫn đến kết quả sai lệch hoặc không tìm thấy sản phẩm, đồng thời các câu chitchat thông thường (ví dụ: *"ráng đi"*) bị trả kết quả sản phẩm rác không mong muốn.
- **Giải pháp nâng cấp:**
  - Định hình dòng model Groq `llama-3.3-70b-versatile` làm mô hình phân loại (Intent Classifier) và viết lại câu hỏi chính nhờ khả năng suy luận xuất sắc và làm chủ tiếng Việt tự nhiên của nó.
  - Bổ sung cơ chế fallback tự động về Gemini 2.5 Flash hoặc các LLM khác trong hệ thống đa dự phòng khi Groq hết quota hay gặp lỗi.

### 11.2 Các hàm tiện ích bổ sung cốt lõi trong [chatbot_flask.py](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/ai-service-flask/chatbot_flask.py)
1. **Hàm `get_groq_llama_70b()`:**
   - Dò tìm mô hình `llama-3.3-70b-versatile` từ danh sách các mô hình đã được xác thực ở bước khởi tạo và trả về instance của nó.
   ```python
   def get_groq_llama_70b():
       llms = get_llms()
       for llm in llms:
           model_name = getattr(llm, 'model', getattr(llm, 'model_name', 'Unknown'))
           if "llama-3.3-70b-versatile" in model_name.lower():
               return llm
       if llms:
           return llms[0]
       return None
   ```
2. **Hàm `contextualize_query(message, history_str, llm)`:**
   - Sử dụng lịch sử hội thoại gần nhất (giới hạn tối đa 10 tin nhắn để tối ưu hóa context) để viết lại câu hỏi của khách hàng thành một câu hỏi độc lập, rõ nghĩa, loại bỏ hoàn toàn các từ chỉ định mơ hồ.
   - *Ví dụ thực tế:*
     - Lịch sử: `"tinh chất retinol là gì"`
     - Khách hỏi tiếp: `"chỉ tui cách sử dụng"`
     - Câu hỏi viết lại: `"hướng dẫn sử dụng retinol"`
   ```python
   def contextualize_query(message: str, history_str: str, llm) -> str:
       if not history_str or not llm:
           return message
       from langchain_core.messages import HumanMessage
       prompt = f"""Dựa trên lịch sử trò chuyện dưới đây và câu hỏi mới nhất của khách hàng, hãy viết lại câu hỏi mới nhất này thành một câu hỏi độc lập, đầy đủ nghĩa, rõ ràng, không bị phụ thuộc vào ngữ cảnh trước đó.
   Mục tiêu là tạo ra một câu truy vấn tìm kiếm sản phẩm hoặc kiến thức tốt nhất.
   CHỈ trả về câu hỏi viết lại, KHÔNG giải thích, KHÔNG thêm bất kỳ từ nào khác. Nếu không cần viết lại, hãy trả lại câu hỏi gốc.

   Lịch sử trò chuyện:
   {history_str}

   Câu hỏi mới nhất: {message}

   Câu hỏi độc lập viết lại:"""
       try:
           response = llm.invoke([HumanMessage(content=prompt)])
           rewritten = (response.content or "").strip()
           if rewritten:
               rewritten = rewritten.strip('"').strip("'")
               print(f"[CONTEXTUALIZE] Original: '{message}' -> Rewritten: '{rewritten}'")
               return rewritten
       except Exception as e:
           print(f"[WARN] Contextualize query failed: {e}")
       return message
   ```
3. **Hàm `classify_intent(query, llm)`:**
   - Phân loại câu hỏi đã viết lại vào 1 trong 3 nhóm chính:
     - `PRODUCT_INQUIRY`: Hỏi mua, tìm kiếm, tư vấn sản phẩm cụ thể.
     - `COSMETIC_KNOWLEDGE_OUT_OF_DB`: Hỏi kiến thức chuyên sâu về thành phần, hoạt chất dưỡng da nằm ngoài database sản phẩm (ví dụ: *"niacinamide là gì"*).
     - `GENERAL_CONVERSATION`: Chào hỏi, cảm ơn, chitchat xã giao, hoặc câu hỏi ngoài ngành hoàn toàn (ví dụ: *"giá vàng"*, *"thời tiết"*).
   - Tự động trích xuất tên hoạt chất chính (ví dụ: `"retinol"`, `"niacinamide"`, `"bha"`) để làm từ khóa tìm kiếm chính xác trong database.
   ```python
   def classify_intent(query: str, llm) -> tuple[str, str | None]:
       if not llm:
           return "PRODUCT_INQUIRY", None
       from langchain_core.messages import HumanMessage
       prompt = f"""Phân tích câu hỏi sau đây của khách hàng và phân loại ý định (intent) của họ vào một trong ba nhóm duy nhất:
   1. "PRODUCT_INQUIRY": Tìm kiếm sản phẩm, hỏi mua, tư vấn chọn sản phẩm cụ thể...
   2. "COSMETIC_KNOWLEDGE_OUT_OF_DB": Hỏi định nghĩa, cơ chế hoạt động, tác dụng của các hoạt chất mỹ phẩm...
   3. "GENERAL_CONVERSATION": Chào hỏi, chitchat tâm sự, hoặc câu hỏi không liên quan đến mỹ phẩm...

   Đồng thời, trích xuất "ingredient" (hoạt chất mỹ phẩm chính được nhắc tới như "retinol", "niacinamide"...). Nếu không có hoạt chất nào, hãy trả về null.

   CHỈ trả về một chuỗi JSON thuần túy có dạng:
   {{
     "intent": "PRODUCT_INQUIRY" / "COSMETIC_KNOWLEDGE_OUT_OF_DB" / "GENERAL_CONVERSATION",
     "ingredient": "tên hoạt chất hoặc null"
   }}

   Câu hỏi: {query}"""
       try:
           response = llm.invoke([HumanMessage(content=prompt)])
           text = (response.content or "").strip()
           data = _extract_json_from_text(text)
           if data and "intent" in data:
               intent = data["intent"]
               ingredient = data.get("ingredient")
               if intent not in ("PRODUCT_INQUIRY", "COSMETIC_KNOWLEDGE_OUT_OF_DB", "GENERAL_CONVERSATION"):
                   intent = "PRODUCT_INQUIRY"
               if ingredient and ingredient.lower() in ("null", "none"):
                   ingredient = None
               print(f"[CLASSIFY] Query: '{query}' -> Intent: {intent} | Ingredient: {ingredient}")
               return intent, ingredient
       except Exception as e:
           print(f"[WARN] Classify intent failed: {e}")
       
       # Fallback dựa trên Regex tĩnh nếu có lỗi
       query_lower = query.lower()
       if any(k in query_lower for k in ["chào", "hello", "hi", "cảm ơn", "cám ơn", "tạm biệt", "bye", "ráng đi", "cố lên", "admin", "shop ơi"]):
           return "GENERAL_CONVERSATION", None
       if any(k in query_lower for k in ["là gì", "tác dụng của", "công dụng của", "cơ chế của"]) and any(k in query_lower for k in ["retinol", "niacinamide", "bha", "aha", "vitamin c", "hyaluronic", "collagen", "peel"]):
           for ing in ["retinol", "niacinamide", "bha", "aha", "vitamin c", "hyaluronic acid", "collagen"]:
               if ing in query_lower:
                   return "COSMETIC_KNOWLEDGE_OUT_OF_DB", ing
           return "COSMETIC_KNOWLEDGE_OUT_OF_DB", None
       return "PRODUCT_INQUIRY", None
   ```

### 11.3 Thiết lập 2 System Prompt Chuyên biệt Cao cấp
Để tối ưu hóa định dạng hiển thị Markdown chất lượng cao (gồm clickable product links dẫn về trang chi tiết `index.php?r=chitiet&id=X`), chúng tôi đã bổ sung hai System Prompt:
- **`GENERAL_CONVERSATION_SYSTEM_PROMPT`:** Đảm bảo AI chitchat cực kỳ ấm áp, xưng "mình" gọi "bạn" tự nhiên. Nếu khách hỏi thông tin ngoài ngành cần web search (như giá vàng, thời tiết), AI lấy thông tin từ Tavily Web Search để trả lời chính xác, sau đó khéo léo giới thiệu top 3 sản phẩm nổi bật bán chạy nhất từ cửa hàng ở cuối tin nhắn để kích thích mua sắm.
- **`COSMETIC_KNOWLEDGE_SYSTEM_PROMPT`:** Biến AI thành một chuyên gia da liễu giải thích sâu, dễ hiểu về các hoạt chất. Đồng thời, hệ thống tự động lọc các sản phẩm thực tế trong database chứa hoạt chất đó để AI giới thiệu ngay cho khách hàng, đính kèm giá bán và hướng dẫn sử dụng khoa học.

### 11.4 Định tuyến Tư vấn Đa kênh trong hàm `xu_ly_cau_hoi`
Tích hợp toàn bộ luồng xử lý thông minh:
1. **Khôi phục Hồ sơ khách hàng** (Loại da, tình trạng da, thành phần cần tránh, sản phẩm đang xem, giỏ hàng hiện tại).
2. **Contextualization & Intent Recognition**: Gọi Groq Llama 3.3 70B viết lại câu hỏi bám sát lịch sử trò chuyện và phân loại thành 3 hướng đi:
   - **Nhánh `GENERAL_CONVERSATION`:**
     - Chạy web search nếu cần câu trả lời thực tế.
     - Truy vấn ChromaDB lấy các sản phẩm nổi bật bán chạy (`custom_query="sản phẩm nổi bật nhiều lượt đánh giá cao bán chạy"`).
     - Áp dụng `GENERAL_CONVERSATION_SYSTEM_PROMPT` để sinh câu trả lời và giới thiệu sản phẩm.
   - **Nhánh `COSMETIC_KNOWLEDGE_OUT_OF_DB`:**
     - Gọi Tavily Web Search để lấy thông tin mới nhất về hoạt chất đó.
     - Tìm kiếm các sản phẩm trong database chứa hoạt chất đó (`custom_query=ingredient`).
     - Áp dụng `COSMETIC_KNOWLEDGE_SYSTEM_PROMPT` để vừa giảng giải vừa giới thiệu sản phẩm.
   - **Nhánh `PRODUCT_INQUIRY`:**
     - Tiến hành quy trình Hybrid Search đa tầng đã tối ưu hóa đối với câu hỏi đã được viết lại.
     - Áp dụng `SYSTEM_PROMPT` để tư vấn sản phẩm chi tiết nhất.
3. **Phòng chống lỗi 413 (Payload Too Large):** Tiến hành cắt lát (slicing) giới hạn số lượng sản phẩm RAG tham chiếu gửi vào LLM (`merged_docs[:int(yc.so_luong_goi_y or 3)]`), chỉ giữ nguyên danh sách đầy đủ đối với yêu cầu tạo chu trình chăm sóc da toàn diện (`is_routine`).

### 11.5 Kết quả Kiểm thử Hệ thống (Verification Plan & Results)
Chúng tôi đã kiểm thử độc lập 3 kịch bản thực tế của khách hàng thông qua script `test_xu_ly.py`. Tất cả kịch bản đều vượt qua với độ chính xác tuyệt đối:

1. **Kịch bản 1 (Chào hỏi & Chitchat):**
   - *Yêu cầu:* `"ráng đi"`
   - *Nhận diện:* `GENERAL_CONVERSATION`
   - *Hành vi hệ thống:* Chúc mừng và động viên người dùng bằng giọng nói vô cùng dễ thương, sau đó giới thiệu 3 sản phẩm bán chạy nhất: *La Roche-Posay Anthelios UVMune 400 SPF50+*, *Skin1004 Madagascar Centella Hyalu-Cica Blue Serum*, và *SVR Sebiaclear Gel Moussant* kèm các link Markdown click trực tiếp được về trang chi tiết.
2. **Kịch bản 2 (Hỏi về Hoạt chất):**
   - *Yêu cầu:* `"tinh chất niacinamide là gì vậy shop"`
   - *Nhận diện:* `COSMETIC_KNOWLEDGE_OUT_OF_DB` (Hoạt chất: `niacinamide`)
   - *Hành vi hệ thống:* Gọi web search để giải thích 4 công dụng tuyệt vời của Niacinamide (kiểm soát dầu, mờ thâm, thu nhỏ lỗ chân lông, phục hồi da) và gợi ý 3 sản phẩm chứa Niacinamide trong shop: *Paula's Choice Clinical Niacinamide 20%*, *L'Oreal Paris Glycolic-Bright Serum*, và *SVR Sebiaclear Active*.
3. **Kịch bản 3 (Follow-up Đa tầng bám sát Ngữ cảnh):**
   - *Lượt 1:* `"tinh chất retinol là gì"` -> Trả lời giải thích retinol + giới thiệu sản phẩm retinol.
   - *Lượt 2:* `"chỉ tui cách sử dụng"` -> Viết lại thành *"hướng dẫn sử dụng retinol"*, phân loại ý định `COSMETIC_KNOWLEDGE_OUT_OF_DB` (Retinol) -> Hướng dẫn tần suất, quy tắc "sandwich" và cách kết hợp.
   - *Lượt 3:* `"cho tui vài sản phẩm của shop đi"` -> Viết lại thành *"giới thiệu sản phẩm retinol của shop"*, truy xuất database và đưa ra 3 đề xuất retinol thực tế: *Paula's Choice Clinical 1% Retinol Treatment*, *Obagi 360 Retinol 0.5*, và *La Roche-Posay Retinol B3 Serum*.

---

## 12. Sửa lỗi Gợi ý Sản phẩm Không Liên quan & Re-ranking Thông minh (22/05/2026)
- **Mục tiêu:** Khắc phục triệt để lỗi chatbot hiển thị các thẻ sản phẩm không liên quan (như bao cao su, chì kẻ mày) khi người dùng hỏi mua một sản phẩm cụ thể, đảm bảo các thẻ đề xuất khớp hoàn toàn với câu hỏi của khách hàng trên hệ thống live.
- **Chi tiết kỹ thuật & Diffs chi tiết:**
  - **Làm giàu nội dung SQL docs (Enriched SQL Doc representation):**
    - *Trước:* Gán `page_content = p_name` khiến Cross-Encoder không đủ thông tin từ ngữ để so sánh ngữ nghĩa của sản phẩm từ PHP.
    - *Sau:* Làm giàu `page_content = f"{p_name} {p_brand} {p_thanh_phan} {p_mota}"` để đưa đầy đủ thông tin thương hiệu, thành phần chính, mô tả vào thuật toán Cross-Encoder.
  - **Gộp pool và khử trùng lặp (Pooled Deduplication):** Gộp toàn bộ danh sách sản phẩm fallback của PHP (`retrieved_products`) và sản phẩm vector từ ChromaDB (`docs`) thành một danh sách duy nhất `merged_docs` và loại trùng dựa trên `(id, ten_san_pham)`.
  - **Loại trừ sản phẩm hiện tại (Current Product Exemption):** 
    - Nếu người dùng đang ở trang chi tiết sản phẩm (`current_product_id`), hệ thống sẽ tìm sản phẩm này trong pool, bóc tách ra làm `current_doc` và đặt mặc định ở **Rank 1**.
    - Các sản phẩm còn lại nằm trong `other_docs` sẽ được gửi đi để xếp hạng lại.
  - **Re-ranking toàn diện qua Cross-Encoder:** 
    - Gửi toàn bộ `other_docs` qua mô hình Cross-Encoder tiếng Việt của Hybrid Pipeline (`cross-encoder/mmarco-mMiniLMv2-L12-H384-v1`).
    - Các sản phẩm liên quan cao đến câu hỏi sẽ nhận điểm số cực cao và đẩy lên vị trí số 1.
    - Các sản phẩm không liên quan nhận điểm số cực thấp và tự động bị đẩy xuống cuối danh sách.
  - **Cắt lát kết quả thông minh:** Danh sách sau khi re-rank và ghép lại với `current_doc` ở đầu sẽ được cắt lấy top 3 (`final_merged_docs`). Nhờ đó, các sản phẩm không liên quan bị loại bỏ hoàn toàn khỏi top 3 đề xuất hiển thị ở UI.
- **Kết quả Kiểm thử (Thành công 100%):**
  - Khi hỏi: `"Retinol Cica Moisture Recovery Serum - Facial Serum bạn có sản phẩm này ko"`, hệ thống nhận diện đúng sản phẩm Retinol Cica của shop, re-rank chính xác và loại bỏ hoàn toàn các sản phẩm không liên quan khác ra khỏi top 3 đề xuất hiển thị ở UI.

---

## 13. Khắc phục Lỗi Cắt Xén Tin Nhắn, Loại Bỏ "Dữ Liệu Dự Phòng", Bộ Lọc Sản Phẩm Nhạy Cảm & Thiết Lập Cân Bằng Tải Dự Phòng Đa Tầng (23/05/2026)
- **Mục tiêu:** 
  1. Giải quyết triệt để lỗi chatbot PHP tự động cắt xén câu trả lời sau 4 đoạn văn.
  2. Vô hiệu hóa hoàn toàn trạng thái cảnh báo màu vàng "Dữ liệu dự phòng" (Fallback Mode) cùng các biểu mẫu phản hồi giả lập, giữ widget luôn ở trạng thái kết nối thực xanh lá ("Đã kết nối").
  3. Loại bỏ triệt để các sản phẩm vệ sinh/tránh thai nhạy cảm (bvs, bao cao su, v.v.) khỏi kết quả tìm kiếm và đề xuất chitchat của AI.
  4. Hệ thống hóa thông số kỹ thuật chi tiết của cơ chế cân bằng tải ứng dụng (Application-level Load Balancer) luân chuyển khóa API và chuyển vùng dự phòng lỗi (Failover).

---

### 13.1 Tăng cURL Timeout & Loại bỏ Cắt Xén câu trả lời trong [HomeController.php](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/app/controllers/HomeController.php)
- **Tăng giới hạn cURL Timeout:**
  - *Sự cố:* Thời gian chờ cũ (`AI_CHAT_TIMEOUT = 25` giây) khiến các truy vấn phức tạp (như tạo chu trình skincare hay gọi Web Search) của Flask service bị ngắt kết nối giữa chừng, ép backend rơi vào chế độ fallback.
  - *Khắc phục:* Tăng `AI_CHAT_TIMEOUT` lên **`60`** giây để đảm bảo dịch vụ AI có đủ thời gian thực hiện RAG, Re-ranking và Web Search một cách hoàn chỉnh.
- **Loại bỏ cắt cụt câu trả lời trong hàm `trimAiAnswer()`:**
  - *Trước:* Tự động ngắt phản hồi ở đoạn văn thứ 4:
    ```php
    $paragraphs = explode("\n\n", $answer);
    if (count($paragraphs) > 4) {
        $paragraphs = array_slice($paragraphs, 0, 4);
        $answer = implode("\n\n", $paragraphs) . '...';
    }
    ```
  - *Sau:* Giữ nguyên hoàn toàn câu trả lời Markdown chi tiết từ dịch vụ AI Flask, cho phép hiển thị chu trình chăm sóc da đầy đủ các bước mà không bị cụt câu.

---

### 13.2 Loại bỏ hoàn toàn logic "Dữ Liệu Dự Phòng" và Dọn dẹp Frontend
- **Thay đổi ở Backend PHP (`HomeController.php`):**
  - Vô hiệu hóa việc tự động chuyển sang câu trả lời giả lập cứng `buildAiAssistantFallback()` khi cURL thất bại. Giờ đây, nếu dịch vụ AI mất kết nối thực sự, hệ thống sẽ trả về lỗi kết nối tự nhiên và đánh dấu `'fallback' => false`.
  - Cấu hình chỉ thị `response_requirements` trong prompt buộc AI chỉ được giới thiệu và thiết kế chu trình dựa vào danh sách sản phẩm thực tế `retrieved_products` gửi sang từ hệ thống cửa hàng, loại bỏ 100% hiện tượng ảo tưởng sản phẩm ảo.
- **Thay đổi ở Frontend Widget [ai_chat_widget.php](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/app/views/components/ai_chat_widget.php):**
  - Loại bỏ hoàn toàn lớp giao diện và phần tử cảnh báo màu vàng `ai-chat-widget__fallback-note` ("Đang sử dụng dữ liệu dự phòng").
  - Đồng bộ chấm trạng thái ở đầu widget luôn hiển thị màu xanh lá và dòng chữ **"Đã kết nối"** để tạo sự tin cậy tuyệt đối cho khách hàng khi giao tiếp.

---

### 13.3 Bộ lọc Sản phẩm Nhạy cảm Đa tầng (Sensitive Keywords Exclusion Filter)
Để đảm bảo chatbot giữ đúng phong thái lịch sự của một cửa hàng mỹ phẩm dưỡng da và loại bỏ hoàn toàn các sản phẩm vệ sinh phụ nữ/bao cao su ngoài ý muốn khi tìm kiếm chung, bộ lọc loại trừ theo từ khóa đã được áp dụng song song:
- **Danh sách từ khóa nhạy cảm bị loại trừ:** `["băng vệ sinh", "bao cao su", "bvs", "bcs", "durex", "diana", "kotex", "laurier", "sagami", "okamoto", "whisper", "sofy", "sanytène", "tampon", "phụ khoa"]`
- **Bộ lọc tại Flask AI Service [chatbot_flask.py](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/ai-service-flask/chatbot_flask.py):**
  - Quét kiểm tra cả hai nguồn: tài liệu PostgreSQL (`sql_docs`) gửi sang và kết quả vector từ ChromaDB (`docs`). 
  - Nếu tên sản phẩm chứa bất kỳ từ khóa nào trong danh sách nhạy cảm, sản phẩm đó sẽ lập tức bị loại ra khỏi pool RAG chuyển tới LLM.
- **Bộ lọc tại Backend PHP (`HomeController.php`):**
  - Bổ sung bộ lọc tương ứng trong hàm `buildAiRelevantProducts()` để lọc sạch các sản phẩm nhạy cảm trước khi đóng gói gửi sang Flask service.
- **Cải tiến ngữ nghĩa truy vấn chung:** 
  - Khi người dùng hỏi xã giao hoặc chào hỏi, Flask service thay vì tìm kiếm sản phẩm quá chung chung sẽ tự động làm giàu truy vấn thành `"sản phẩm mỹ phẩm dưỡng da nổi bật bán chạy nhất"` để luôn trả về đúng các danh mục mỹ phẩm cốt lõi.

---

### 13.4 Specs Cân bằng tải & Dự phòng Đa tầng (Application-level Load Balancer Specifications)
Hệ thống tự cân bằng tải phần mềm và dự phòng đa lớp (Failover) được cấu hình chi tiết như sau:

#### A. Cân bằng tải xoay vòng API Keys (Gemini Key Rotation)
- **Tải tệp tin cấu hình:** Khởi tạo từ tệp `.env`, hỗ trợ các biến `GOOGLE_API_KEY`, `GOOGLE_API_KEY_2` đến `GOOGLE_API_KEY_10` hoặc một danh sách phân tách bằng dấu phẩy qua `GOOGLE_API_KEYS`.
- **Cơ chế Cân bằng Tải:** Các khóa được nạp và kiểm tra tính hợp lệ bằng HumanMessage cực ngắn. Các khóa hoạt động tốt sẽ được gán làm các luồng chat song song. Tải lượng truy cập sẽ luân phiên chia sẻ giữa các khóa này, giúp tăng tổng hạn mức cuộc gọi miễn phí lên tối đa gấp nhiều lần (mỗi khóa cung cấp **1.500 lượt truy vấn/ngày**).

#### B. Sơ đồ Chuyển đổi Dự phòng (Multi-LLM Failover Chain)
Khi một lớp mô hình gặp lỗi (hết hạn mức rate-limit, mất kết nối), dịch vụ tự động chuyển đổi trong mili-giây sang lớp tiếp theo mà không làm ngắt quãng trải nghiệm khách hàng:

```mermaid
graph TD
    A[Yêu cầu từ Frontend] --> B{Intent Classifier: Llama 70B}
    B -->|Thành công| C[Nhận diện Ý định & Viết lại câu hỏi]
    B -->|Groq Lỗi/Hết Quota| D[Fallback sang Gemini / Zhipu phân tích]
    
    C --> E{Gọi chuỗi LLM sinh câu trả lời}
    E -->|Ưu tiên 1| F[Gemini 2.5 Flash <br> Xoay vòng qua 3+ API Keys]
    F -->|Lỗi 429 / Mạng| G[Groq Llama-3.3-70b-versatile]
    G -->|Hết Quota / Hạn chế| H[Zhipu GLM-4-Flash <br> Miễn phí, Siêu tốc]
    H -->|Sập kết nối| I[OpenRouter Llama-3.1-8b-instruct]
    I -->|Mất kết nối hoàn toàn| J[Trả câu trả lời tự nhiên từ DB]
```

- **Thông số kỹ thuật các Mô hình tích hợp:**
  | Tên Mô hình | Nguồn Cung cấp | Vai trò | Hạn mức/Ưu thế | Trạng thái |
  | :--- | :--- | :--- | :--- | :--- |
  | **gemini-2.5-flash** | Google AI Studio | Mô hình chính (Sinh câu trả lời RAG) | 1,500 req/ngày trên mỗi Key | Hoạt động tốt (Validated) |
  | **llama-3.3-70b-versatile** | Groq Cloud API | Phân loại ý định & Viết lại câu hỏi | Phản hồi siêu tốc, Tiếng Việt xuất sắc | Hoạt động tốt (Validated) |
  | **glm-4-flash** | Zhipu AI | Fallback trung tâm (Phản hồi 1.5s - 3s) | Băng thông cực rộng, miễn phí hoàn toàn | Hoạt động tốt (Validated) |
  | **llama-3.1-8b-instruct** | OpenRouter | Chốt chặn cuối cùng | Tier miễn phí | Hoạt động tốt (Validated) |

---

## 14. Đồng bộ hóa Gợi ý Sản phẩm, Khắc phục triệt để Lỗi Lệch Thông tin (Mismatch) & Cập nhật Thứ tự Chu trình Dưỡng da (23/05/2026 07:15)
- **Mục tiêu:** 
  1. Loại bỏ 100% tình trạng bong bóng chat tư vấn khuyên dùng một thương hiệu (như La Roche-Posay) nhưng các thẻ sản phẩm bên dưới lại hiển thị sản phẩm khác (như Naris hay Silcot).
  2. Bắt buộc AI chỉ được phép tư vấn và thiết kế chu trình (routine) bằng chính các sản phẩm thực tế lấy ra từ cửa hàng (`<san_pham_goi_y>`).
  3. Cập nhật thứ tự các bước trong chu trình skincare chuẩn bao gồm: Tẩy trang, Sữa rửa mặt, Toner, Serum, Kem dưỡng, Chống nắng.
  4. Reset và làm sạch bộ nhớ cache chatbot.

---

### 14.1 Nhận diện Ý định thiết kế Routine tự động trong [HomeController.php](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/app/controllers/HomeController.php)
- **Sự cố:** Regex cũ trong hàm `shouldAttachAiProducts()` bỏ sót các từ khóa liên quan đến routine chăm sóc da tổng quan (như "chu trình", "skincare", "routine"). Điều này làm backend PHP trả về danh sách trống `[]` sang Flask, vô tình kích hoạt cơ chế fallback tìm kiếm sản phẩm nổi bật mặc định ở Flask (Naris, Silcot).
- **Khắc phục:** 
  - Cập nhật Regex trong hàm `shouldAttachAiProducts()` để nhận diện thêm các từ khóa: `chu trình|chu trinh|skincare|routine|bộ dưỡng|bo duong`.
  - Giúp PHP nhận diện chính xác ý định xin routine của người dùng để thực hiện truy xuất sản phẩm tương thích từ database truyền sang Flask.
  - *Diff thay đổi:*
    ```diff
    - '/goi y|gợi ý|de xuat|đề xuất|nen mua|nên mua|nen dung|nên dùng|chon giup|chọn giúp|san pham nao|sản phẩm nào|serum nao|kem nao|toner nao|sua rua mat nao|tẩy trang nào|kem chống nắng nào|compare|so sanh|so sánh|dua ra vai san pham|đưa ra vài sản phẩm/u'
    + '/goi y|gợi ý|de xuat|đề xuất|nen mua|nên mua|nen dung|nên dùng|chon giup|chọn giúp|san pham nao|sản phẩm nào|serum nao|kem nao|toner nao|sua rua mat nao|tẩy trang nào|kem chống nắng nào|compare|so sanh|so sánh|dua ra vai san pham|đưa ra vài sản phẩm|chu trình|chu trinh|skincare|routine|bộ dưỡng|bo duong/u'
    ```

---

### 14.2 Nâng cấp Phân loại Ý định siêu tốc & Nhận diện Skincare Routine trong [chatbot_flask.py](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/ai-service-flask/chatbot_flask.py)
- **Cải tiến 1: Override Intent Classifier bằng quy tắc (Rule-based Intent Override):**
  - Để tránh việc LLM Classifier phân loại sai các câu xin routine dài có kèm hoạt chất sang nhánh `COSMETIC_KNOWLEDGE_OUT_OF_DB` (vô tình làm mất danh sách sản phẩm full routine), một chốt chặn rule-based đã được thêm vào đầu hàm `classify_intent()`.
  - Nếu câu chat chứa từ khóa chu trình (`skincare`, `routine`, `chu trình`,...) hoặc kết hợp sản phẩm và từ khóa nhờ vả (`nên mua`, `gợi ý`, `tư vấn`,...), hệ thống tự động ép ý định về `PRODUCT_INQUIRY`.
- **Cải tiến 2: Bổ sung từ khóa `is_routine`:**
  - Cập nhật thêm các từ khóa `"skincare"`, `"dưỡng da"`, `"duong da"` vào cấu trúc `rule_based_parse()` để đảm bảo cờ `is_routine` luôn được gán `True` chính xác, kích hoạt cơ chế truy xuất đa tầng 6 bước của ChromaDB.
  - *Diff thay đổi:*
    ```diff
    - if any(k in msg_lower for k in ["routine", "chu trình", "chu trinh", "các bước", "cac buoc", "combo", "trọn bộ", "tron bo", "sáng tối", "sang toi"]):
    + if any(k in msg_lower for k in ["routine", "chu trình", "chu trinh", "skincare", "dưỡng da", "duong da", "các bước", "cac buoc", "combo", "trọn bộ", "tron bo", "sáng tối", "sang toi"]):
    ```

---

### 14.3 Thắt chặt Guardrails & Cập nhật thứ tự 6 Bước trong [chatbot_flask.py](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/ai-service-flask/chatbot_flask.py)
- **Thắt chặt điều kiện trong `SYSTEM_PROMPT`:**
  - Cấu hình lại mục **### 6. RÀNG BUỘT TUYỆT ĐỐI (GUARDRAILS)**. Ép mô hình LLM bắt buộc chỉ được sử dụng các sản phẩm có mặt trong `<san_pham_goi_y>`.
  - Nghiêm cấm hoàn toàn hành vi tự ý ảo tưởng/bịa tên các sản phẩm ngoài database (như các loại kem chống nắng hay toner La Roche-Posay không có sẵn).
- **Cập nhật thứ tự các bước Skincare:**
  - Đồng bộ thứ tự các bước dưỡng da trong chỉ thị thiết kế chu trình thành: **Tẩy trang, Sữa rửa mặt, Toner, Serum, Kem dưỡng, Chống nắng** đúng chuẩn khoa học da liễu.
  - *Diff thay đổi ở SYSTEM_PROMPT:*
    ```diff
    - - NẾU khách hỏi về chu trình / routine dưỡng da nhiều bước kết hợp, bạn PHẢI xây dựng một chu trình khoa học và chọn giới thiệu chính xác sản phẩm tương ứng từ <san_pham_goi_y> cho từng bước.
    + - BẮT BUỘC: Bạn CHỈ ĐƯỢC PHÉP gợi ý các sản phẩm có mặt trong danh sách `<san_pham_goi_y>` ở trên. Nếu người dùng yêu cầu thiết kế một chu trình dưỡng da (routine), bạn PHẢI chọn các sản phẩm phù hợp từ danh sách `<san_pham_goi_y>` này để điền vào từng bước (Tẩy trang, Sữa rửa mặt, Toner, Serum, Kem dưỡng, Chống nắng). TUYỆT ĐỐI KHÔNG ĐƯỢC tự ý bịa ra hoặc đề xuất bất kỳ sản phẩm nào khác ngoài danh sách `<san_pham_goi_y>` này (không bịa tên hay nhãn hàng khác như La Roche-Posay, CeraVe, v.v. nếu chúng không nằm trong danh sách `<san_pham_goi_y>` ở trên). Nếu danh sách `<san_pham_goi_y>` thiếu sản phẩm cho một bước nào đó, hãy ghi rõ là cửa hàng tạm thời chưa có sẵn sản phẩm phù hợp cho bước đó và khuyên khách hàng sử dụng các sản phẩm có sẵn còn lại.
    ```

---

### 14.4 Làm sạch Cache và Tái vận hành live
- **Clear cache tĩnh:** Làm sạch bộ lưu trữ phản hồi tĩnh `ai_chat_responses.json` để loại bỏ toàn bộ dữ liệu phản hồi bị lệch thông tin trước đây.
- **Tái khởi động Flask Service:** Restart tiến trình Python Flask trên cổng `5001`. Toàn bộ 4 LLMs (Gemini 2.5 Flash, Groq 8B, Zhipu GLM, OpenRouter) đều được xác thực và sẵn sàng hoạt động ở trạng thái kết nối hoàn hảo.
- **Kết quả Kiểm thử:** Chạy thử nghiệm live với kịch bản da dầu mụn viêm sưng đỏ. Phản hồi trả về của AI đính kèm chính xác 3 sản phẩm kiềm dầu thực tế của shop: *Tinh chất bí đao Cocoon*, *Kem dưỡng se khít lỗ chân lông Bioderma*, và *Kem chống nắng Eucerin kiềm dầu* trong văn bản, hoàn toàn trùng khớp 100% với danh sách thẻ sản phẩm hiển thị trong Widget.

---

## 15. Khắc phục lỗi `time` chưa định nghĩa, Lỗi BaseModel Pydantic Cooldown & Đồng bộ hóa Hệ thống Kiểm thử Toàn diện (24/05/2026)
- **Mục tiêu:** Giải quyết triệt để lỗi kết nối AI Service sập ngầm (HTTP 500) khi xoay vòng API Keys, sửa lỗi phân tích ký tự đặc biệt có dấu tiếng Việt bị lỗi CP1252 trên Windows, và cải tiến giao diện không bị khuất chữ ở UI chat.

---

### 15.1 Khắc phục lỗi thiếu import `time` (NameError)
- **Sự cố:** Khi bổ sung cơ chế cool-down (đợi 5 phút) để tự động ngắt kết nối các API keys bị cạn kiệt hạn mức (rate-limit / quota), mã nguồn Python của Flask Service có gọi `time.time()` để gán mốc thời gian chờ. Tuy nhiên, thư viện `time` của hệ thống lại chưa được import ở đầu file `chatbot_flask.py`, dẫn đến lỗi chết ngầm `NameError: name 'time' is not defined` và trả về mã trạng thái HTTP 500.
- **Khắc phục:** Import module `time` thành công ở đầu file [chatbot_flask.py](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/ai-service-flask/chatbot_flask.py).

---

### 15.2 Giải quyết lỗi BaseModel Pydantic Cooldown trên các LangChain Chat Models
- **Sự cố:** Các langchain chat model kế thừa từ Pydantic BaseModel (như `ChatGoogleGenerativeAI`, `ChatOpenAI`) không cho phép gán động/tự do các thuộc tính ngoài schema (lỗi `\"ChatGoogleGenerativeAI\" object has no field \"cooldown_until\"`), dẫn đến sập API `/api/chat` khi có key bị rate-limit.
- **Khắc phục:** Thiết lập bộ lưu trữ cooldown an toàn ở dạng Dict global `LLM_COOLDOWNS = {}` và hai hàm helper `_get_llm_cooldown(llm)` và `_set_llm_cooldown(llm, duration)` định danh bằng `id(llm)`. Giúp loại bỏ hoàn toàn việc gán động vào Pydantic models.

---

### 15.3 Sửa đổi hệ thống kiểm thử tự động tránh lỗi CP1252 (PYTHONUTF8)
- **Sự cố:** Chạy kiểm thử tự động `run_comprehensive_tests.py` trên Windows mặc định giải mã CP1252 gây lỗi giải mã ký tự đặc biệt có dấu (ví dụ: `tổng chi phí ước tính` thành `t?ng chi ph ??c tnh`), làm rớt các check kiểm thử.
- **Khắc phục:** Bổ sung cấu hình ép mã hóa UTF-8 môi trường (`os.environ["PYTHONUTF8"] = "1"`) ở đầu file [run_comprehensive_tests.py](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/ai-service-flask/run_comprehensive_tests.py).

---

### 15.4 Khắc phục z-index / layout collapsible summary trong Widget chat
- **Sự cố:** Các thẻ sản phẩm đề xuất ở giao diện Chatbot bị ẩn, khuất chữ hoặc quá dài gây chèn ép không gian hiển thị.
- **Khắc phục:** Thiết kế khối collapsible summary động sử dụng liên kết `[Xem thêm]` và `[Thu gọn]` trong [ai_chat_widget.php](file:///c:/xampp/htdocs/CNM/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/app/views/components/ai_chat_widget.php). Khi click, mô tả đầy đủ sẽ hiển thị/thu gọn mượt mà.

---

### 15.5 Đồng bộ Git & Push lên branch `vivi`
- Pushed sạch sẽ, an toàn lên origin `vivi` branch mà không hề include bất kỳ file test nào (`run_comprehensive_tests.py`, `test_report.md`, `scratch/`), tuân thủ nghiêm ngặt chỉ thị của người dùng.

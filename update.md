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

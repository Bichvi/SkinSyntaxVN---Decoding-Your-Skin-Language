# Ý tưởng bài báo (idea.md)

> Tư liệu đầu vào cho PaperOrchestra. Ngôn ngữ bài báo: **tiếng Việt**, theo mẫu Hội nghị khoa học cấp Khoa FIT-IUH (SSRC), định dạng IEEE hai cột.
> Mọi chi tiết kỹ thuật dưới đây đã được đối chiếu với mã nguồn và tài liệu dự án SkinSyntaxVN (nhánh `syna_10_11`, tháng 11/2026). Số liệu đo nằm trong `experiment_log.md`.
> **Yêu cầu đối với công cụ viết:** không bịa số liệu, không thêm thí nghiệm chưa có trong `experiment_log.md`; mục nào đánh dấu `CHƯA ĐO` thì ghi là hạn chế hoặc hướng phát triển.

## 1. Tên đề xuất

**Chính:** Trợ lý AI tư vấn mỹ phẩm theo nguyên tắc "mô hình ngôn ngữ chỉ đọc và diễn đạt, luật quyết định" trên nền tảng thương mại điện tử SkinSyntaxVN

**Phương án ngắn:** Syna: trợ lý AI kiểm chứng được cho thương mại điện tử mỹ phẩm

## 2. Bối cảnh và vấn đề

- Mua mỹ phẩm chăm sóc da trực tuyến cần nhiều thông tin: sản phẩm có hợp loại da không, có hoạt chất kỵ với sản phẩm đang dùng không (ví dụ retinol với AHA/BHA), chính sách đổi trả và phí vận chuyển ra sao.
- Chatbot dựa trên mô hình ngôn ngữ lớn (LLM) trả lời trôi chảy nhưng dễ **bịa**: sai giá, bịa con số trong chính sách, khẳng định xung đột thành phần không có căn cứ. Với thương mại điện tử, sai giá hoặc tự tạo đơn hàng gây hậu quả tiền bạc trực tiếp.
- Nhóm xây dựng SkinSyntaxVN, website bán mỹ phẩm (khoảng 2.853 sản phẩm đang bán, 432 thương hiệu) có trợ lý AI tên **Syna**. Nhóm **không huấn luyện mô hình mới**. Trọng tâm là đặt các mô hình có sẵn (LLM, mô hình đọc ảnh, embedding, cross-encoder) vào một quy trình mà mọi kết luận quan trọng đều kiểm chứng được.

## 3. Nguyên tắc thiết kế xuyên suốt

**LLM chỉ đọc, diễn đạt và đề xuất. Mọi kết luận quan trọng (giá, tồn kho, xung đột hoạt chất, mức hợp da, nội dung chính sách, tạo đơn) do mã nguồn và tập luật có nguồn quyết định.**

Hệ quả:
1. LLM không bao giờ tự tạo đơn. Agent chỉ lập **đơn nháp**; đơn thật được tạo khi khách bấm "Xác nhận đặt hàng" và PHP kiểm tra lại.
2. LLM không viết truy vấn đồ thị (Cypher) và không tự kết luận xung đột. Truy vấn là câu lệnh cố định có tham số; xung đột và mức hợp da đến từ file luật YAML có nguồn công khai.
3. Mọi thành phần AI đều có đường lùi (graceful degradation): Neo4j lỗi thì dùng luật Python; mô hình đọc ảnh lỗi thì khách dán chữ; agent lỗi thì quay về luồng chat cũ.

## 4. Câu hỏi nghiên cứu

- **RQ1 (đọc nhãn):** Có thể dùng mô hình thị giác–ngôn ngữ (vision LLM) để đọc ảnh bảng thành phần, nhưng vẫn giữ kết luận xung đột và mức hợp da kiểm chứng được không? Độ phủ hoạt chất, độ trễ và khả năng nhận ra ảnh không có bảng thành phần ở mức nào?
- **RQ2 (chính sách):** Khi trợ lý trả lời câu hỏi chính sách cửa hàng (phí ship, đổi trả, OTP...), quy trình chọn bài nguồn + hậu kiểm số liệu có ngăn được con số bịa và khẳng định quá mức không, với độ trễ bao lâu?
- **RQ3 (agent đặt hàng):** Khi cho agent thao tác giỏ hàng và lập đơn trong khung chat, làm sao bảo đảm agent không tự tạo đơn, không vượt quyền khách, và không tạo đơn trùng khi khách bấm xác nhận nhiều lần? Bộ phân loại ý định (router) đúng ở mức nào?

## 5. Đóng góp chính

1. **Luồng đọc nhãn tách "chép chữ" khỏi "kết luận":** vision LLM chỉ trả về JSON văn bản (tên, hãng, chuỗi thành phần). Bộ tách INCI dùng chung từ điển alias với đồ thị tri thức. Xung đột lấy từ 11 luật (55 cặp INCI), mức hợp da lấy từ luật YAML có `rule_id` và nguồn. Câu tóm tắt dùng **template** chứ không dùng LLM, nên chữ in trên bao bì không thể thành lệnh (prompt injection). Khi nhận diện dưới 60% thành phần, hệ thống gắn cờ `low_recognition` và không kết luận "hợp da".
2. **Trả lời chính sách có một nguồn sự thật và hậu kiểm:** một file nội dung duy nhất (6 nhóm, 29 bài) được header, footer, trang hỗ trợ và chatbot cùng đọc. Hệ thống xếp hạng chọn tối đa 3 bài, sau đó rerank đoạn bằng cross-encoder và cho LLM viết câu trả lời có trích nguồn. Bước hậu kiểm loại câu trả lời chứa ngày, phí, %, giờ, số điện thoại, email hay URL không có trong nguồn, cũng như câu khẳng định "Có/Được" khi nguồn chỉ bảo liên hệ; trường hợp đó thay bằng bản soạn sẵn.
3. **Agent đặt hàng không có quyền tạo đơn:** agent LangGraph có 11 tool, không có tool tạo/hủy đơn hay đổi giá. Danh tính lấy từ token HMAC-SHA256 do PHP ký (hạn 5 phút, có scope), không lấy từ nội dung chat. Đơn nháp hết hạn sau 10 phút và chỉ dùng được một lần nhờ `findOneAndUpdate` nguyên tử trên trường `used_at`. Lúc xác nhận cần session, CSRF, Origin, kiểm lại giá/tồn kho và giới hạn 5 lần/10 phút. Bộ lọc `claims_false_commitment` thay câu "đã đặt đơn thành công" của LLM khi chưa có đơn.
4. **Đồ thị tri thức sản phẩm (Neo4j) dẫn xuất từ MongoDB** cho câu hỏi có điều kiện quan hệ và phủ định ("không chứa cồn", "không kỵ retinol", "dưới 300k"). Router tất định, Cypher cố định có tham số. Điều kiện "không chứa X" chỉ xét sản phẩm có ít nhất 3 thành phần INCI đọc được.
5. **Nâng chất lượng dữ liệu thành phần:** sửa parser và mở rộng từ điển alias INCI đưa tỷ lệ lượt thành phần map được INCI từ 54,15% lên 88,53% trên 2.853 sản phẩm. Đây là điều kiện để luật xung đột và đồ thị hoạt động.

## 6. Kiến trúc hệ thống (để vẽ Hình 1 và Bảng I)

```
Trình duyệt (PHP view + JS thuần, widget chat, SSE)
   → Nginx (chặn /api/ của AI ra ngoài)
   → PHP-FPM 8.2 MVC, đóng vai BFF: session, đăng nhập, CSRF, giỏ hàng, tạo đơn, thanh toán
        (nơi DUY NHẤT ghi giỏ và tạo đơn; circuit breaker 3 lỗi/60 s cho từng endpoint Flask)
   → Flask ai-service (Python 3.11, Gunicorn gthread), gọi nội bộ qua mạng Docker có token
        • chatbot_service: luồng chat RAG (LangChain)
        • agent_graph: agent đặt hàng (LangGraph)
        • label_service: đọc ảnh nhãn thành phần
        • search_service: tìm kiếm lai BM25 + vector + RRF (k=60), phân tích giỏ
   → MongoDB Atlas (dữ liệu chính) · ChromaDB (vector sản phẩm) · Neo4j 5.26 (đồ thị dẫn xuất, chỉ đọc khi phục vụ)
     · Redis 7 (cache, rate limit, Streams, trạng thái hội thoại agent, Celery broker)
```

| Thành phần | Công nghệ | Trách nhiệm |
|---|---|---|
| Frontend | PHP view, HTML/CSS/JS thuần | Giao diện khách, admin; widget chat |
| Backend (BFF) | PHP 8.2 MVC | Định tuyến, xác thực, giỏ hàng, tạo đơn, thanh toán (COD, VNPay, chuyển khoản QR) |
| AI service | Python 3.11, Flask, LangChain, LangGraph | Chat RAG, agent, đọc nhãn, tìm kiếm, gợi ý |
| CSDL chính | MongoDB Atlas | Sản phẩm, khách hàng, đơn hàng, hội thoại, đơn nháp (TTL) |
| Vector DB | ChromaDB | Embedding sản phẩm |
| Đồ thị | Neo4j 5.26 Community | Product–Ingredient–Concern–SkinType; CONFLICTS_WITH, SIMILAR_TO |
| Cache/hàng đợi | Redis 7 (AOF) | Cache, rate limit, Streams, Celery; thiết kế fail-open |

### Mô hình sử dụng (không huấn luyện lại)

| Việc | Mô hình |
|---|---|
| Embedding | `sentence-transformers/static-similarity-mrl-multilingual-v1` (đa ngôn ngữ, chạy CPU) |
| Rerank | Cross-encoder `cross-encoder/mmarco-mMiniLMv2-L12-H384-v1` |
| LLM chat | Pool dự phòng: OpenAI gpt-4o-mini/gpt-4o → Gemini 2.5 Flash → Groq gpt-oss → Zhipu glm-4-flash → OpenRouter. Provider bị giới hạn tốc độ thì nghỉ 5 phút |
| Agent đặt hàng | Groq → Gemini → OpenAI (chỉ model gọi tool tốt) |
| Đọc ảnh nhãn | Groq `qwen/qwen3.8-27b` (vision, JSON mode), dự phòng Gemini 2.5 Flash. Lượt đo đầu dùng `meta-llama/llama-4-scout-17b-16e-instruct`, sau đó Groq gỡ model này ngày 2026-11-06 |

Lý do dùng pool nhiều provider: dự án dùng gói miễn phí có hạn mức theo phút/ngày. Đây cũng là lý do router viết bằng luật thay vì function-calling, vì không phải model nào trong pool cũng gọi tool tốt.

## 7. Phương pháp chi tiết

### 7.1 Luồng xử lý một tin nhắn chat (thứ tự có chủ đích: nhánh rẻ và chắc chắn chạy trước)
1. PHP: câu chào → trả lời ngay; câu xem giỏ/mua rõ ràng → PHP tự trả lời; xếp hạng bài chính sách; cache câu trả lời thường; gửi ngữ cảnh (hồ sơ da lấy từ session, giỏ, xung đột, sản phẩm đang xem) sang Flask.
2. Flask: nhánh STORE_SUPPORT (chính sách) → nhánh công cụ đồ thị (câu có điều kiện quan hệ) → viết lại câu hỏi theo ngữ cảnh + phân loại ý định → tách yêu cầu có cấu trúc (loại da trong hồ sơ ghi đè loại da đoán từ câu) → máy trạng thái khảo sát da → truy hồi Chroma + ứng viên từ Mongo → lọc cứng → rerank cross-encoder → lọc ngân sách → LLM viết câu trả lời.
3. Agent mới (bật bằng cờ `SYNA_AGENT_V2`): nút `route` (luật nhanh trước, không khớp thì LLM trả JSON có cấu trúc qua Pydantic, một câu có thể nhiều ý định) → nhánh legacy hoặc agent gọi tool → finish. Giới hạn 8 bước tool và 45 giây mỗi lượt. Agent lỗi thì quay về luồng cũ.

### 7.2 Đọc ảnh bảng thành phần (RQ1)
- PHP: cờ `LABEL_SCAN_ENABLED`, CSRF, file ≤ 5 MB, kiểm MIME bằng `finfo` và `getimagesize`. Giới hạn 6 lần/10 phút/phiên và 20 lần/10 phút/IP. PHP tự đọc giỏ và hồ sơ da (không nhận từ trình duyệt) và không ghi ảnh ra đĩa.
- Flask: kiểm chữ ký byte ảnh (JPEG/PNG/WEBP), sau đó:
  1. `read_label`: vision LLM chỉ chép chữ, trả JSON `{is_label, product_name, brand, product_type, ingredients_text}`. Groq lỗi/429/timeout → Gemini; hết provider → `vision_unavailable`.
  2. Khớp sản phẩm trong catalog (fulltext tên + trùng thành phần Jaccard ≥ 0,8) → "Có bán tại shop".
  3. Tách INCI (dùng chung alias với Neo4j và giỏ hàng; giới hạn 30 thành phần đầu, `MAX_INGREDIENTS = 30`).
  4. Xung đột với giỏ theo `conflict_rules.yaml`, cùng hàm với trang giỏ hàng.
  5. Mức hợp da theo `skin_fit_rules.yaml`: có luật `avoid` → 🔴; có `caution` → 🟡; có `good` → 🟢; không khớp → trung tính. Nhận diện < 60% → `low_recognition`, không kết luận 🟢.
  6. Xếp lịch sáng/tối: cặp kỵ `same_session` → tách buổi; `same_routine` → xen kẽ ngày.
  7. Tối đa 3 sản phẩm thay thế (hợp da, không kỵ giỏ).
  8. Câu tóm tắt bằng template tiếng Việt, không dùng LLM.
- Không bao giờ trả HTTP 500: lỗi bất ngờ trả 200 kèm `status: vision_unavailable`, giao diện hiện ô dán thành phần. Log chỉ chứa provider, trạng thái, thời gian, kích thước; không có ảnh hay chữ đọc được.
- Khách sửa được danh sách thành phần đọc sai; phân tích lại không gọi lại vision.

### 7.3 Tri thức dạng luật
- `conflict_rules.yaml`: 11 luật → 55 cặp INCI (20 high, 8 medium, 27 low). Mỗi luật có mức, thời điểm (`same_session`, `same_routine`), lời khuyên tiếng Việt và nguồn. Có `rank_limits` (hoạt chất chỉ tính khi nằm trong N thành phần đầu), bỏ qua tóc/trang điểm, hạ mức với sản phẩm rửa trôi.
- `skin_fit_rules.yaml`: luật thành phần × loại da, mỗi luật bắt buộc có link nguồn công khai (AAD, DermNet, bài tổng quan). **Chưa được bác sĩ da liễu duyệt.**
- `ingredient_groups.yaml`: nhóm thành phần (cồn khô, hương liệu, paraben, sulfate...). "Cồn" trong điều kiện "không chứa cồn" là cồn khô (alcohol denat, ethanol), không gồm cồn béo như cetyl alcohol.

### 7.4 Trả lời chính sách (RQ2)
- Nguồn: `support.json`, 6 nhóm, 29 bài, có version.
- PHP `SupportKnowledge::rank` chọn tối đa 3 bài (ngưỡng điểm 3,0) → Flask chọn đoạn + rerank cross-encoder → LLM viết theo prompt cố định có trích nguồn → **hậu kiểm** `validate_answer`:
  - Số liệu lạ: ngày, phí, %, giờ, số điện thoại, email, URL, lời hứa không có trong nguồn → bỏ câu LLM, dùng bản soạn sẵn của bài.
  - Khẳng định quá mức (`unsupported_claim`): câu hỏi có/không mà câu trả lời mở đầu "Có/Được/Bạn có thể" nhưng cụm hành động không phủ ≥ 60% ở một câu nguồn khẳng định → dùng bản soạn sẵn.
- Flask lỗi → PHP trả bản soạn sẵn nếu khớp chắc (điểm ≥ 8,0).

### 7.5 Agent đặt hàng (RQ3)
- 11 tool: đọc (`search_products`, `get_product`, `compare_products`, `check_conflicts`, `view_cart`, `get_checkout_defaults`, `get_order_status`), ghi giỏ (`add_to_cart`, `remove_from_cart`), lập đơn nháp (`prepare_order_draft`, `reorder_from_order`). **Không có tool tạo đơn.**
- Chuỗi bảo vệ:
  1. Token HMAC-SHA256 hạn 5 phút `{sub, scope, jti}`; so chữ ký bằng `hash_equals`. Khách vãng lai chỉ có scope `chat`.
  2. Thay đổi giỏ trả về `cart_effect` có chữ ký + nonce chống dùng lại.
  3. Đơn nháp lưu ở `chat_order_drafts`, hết hạn 10 phút (TTL index).
  4. Xác nhận: session + CSRF + Origin + đơn nháp thuộc đúng khách + 5 lần/10 phút.
  5. Dùng một lần: `findOneAndUpdate({_id, used_at: null}, {$set: {used_at: now}})` nguyên tử.
  6. Kiểm lại giá và tồn kho lúc xác nhận; giá đổi → bắt xác nhận lại.
  7. `claims_false_commitment` chặn câu "đã đặt đơn thành công" khi chưa có đơn.
  8. Tham số tool kiểm bằng Pydantic (số lượng 1–20, id có thật); kết quả tool bọc trong thẻ dữ liệu.
- Hiểu tham chiếu "cái thứ 2", "lọ đó" bằng danh sách sản phẩm vừa hiện lưu trong trạng thái hội thoại trên Redis; mơ hồ thì hỏi lại.

### 7.6 Đồ thị tri thức và câu hỏi có điều kiện
- Lược đồ: `(Product)-[:CONTAINS {rank}]->(Ingredient)`, `(Ingredient)-[:CONFLICTS_WITH {severity}]-(Ingredient)`, `(Product)-[:SUITABLE_FOR]->(SkinType)`, `(Product)-[:TARGETS]->(Concern)`, `(Product)-[:SIMILAR_TO]->(Product)`, `OF_BRAND`, `IN_CATEGORY`, `HAS_BENEFIT`, `HELPS`, `CO_PURCHASED`.
- ETL `graph_sync`: đồng bộ đầy đủ lúc 02:30 hằng ngày và đồng bộ theo sự kiện qua Redis Stream khi admin sửa sản phẩm.
- 4 tool đồ thị: `find_products`, `check_conflicts`, `explain_product`, `similar_products`. Giá, tồn kho, ảnh luôn đọc lại từ Mongo (timeout 400 ms). Mỗi lệnh Neo4j có hạn 600 ms; quá hạn thì quay về RAG. Câu trả lời nhắc sản phẩm không có trong kết quả tool bị loại.

## 8. Công trình liên quan (gợi ý, CẦN kiểm tra từng trích dẫn trước khi dùng)

- RAG: Lewis et al., "Retrieval-augmented generation for knowledge-intensive NLP tasks", NeurIPS 2020.
- Reciprocal Rank Fusion: Cormack, Clarke, Büttcher, SIGIR 2009.
- BM25: Robertson & Zaragoza, "The Probabilistic Relevance Framework: BM25 and Beyond", 2009.
- Cross-encoder rerank / Sentence-BERT: Reimers & Gurevych, EMNLP 2019; Nogueira & Cho, "Passage Re-ranking with BERT", 2019.
- ReAct (agent gọi tool): Yao et al., ICLR 2023.
- Prompt injection gián tiếp: Greshake et al., "Not what you've signed up for", AISec 2023.
- Ảo giác của LLM: khảo sát của Ji et al., ACM Computing Surveys 2023.
- Tài liệu công cụ: LangChain, LangGraph, Neo4j, ChromaDB, MongoDB (`findOneAndUpdate`), OWASP Top 10 for LLM Applications.

Điểm khác biệt cần nêu: các hệ thống trên mô tả từng kỹ thuật riêng lẻ. Bài này kết hợp chúng trong một cửa hàng đang vận hành, với ranh giới quyền rõ ràng: LLM không ghi dữ liệu có hậu quả tiền bạc, kết luận chuyên môn đến từ luật có nguồn, và có đo lường cho từng nhánh.

## 9. Hạn chế đã biết (bắt buộc đưa vào bài, viết trung thực)

1. Phụ thuộc gói LLM miễn phí: Groq 8.000 token/phút, một ảnh nhãn tốn khoảng 6.800 token → toàn cửa hàng đọc được khoảng 1 ảnh/phút; agent khoảng 3 lượt/phút (~2,8k token/lượt).
2. Luật hợp da và xung đột có nguồn công khai nhưng chưa được bác sĩ da liễu duyệt. Chưa có luật cho phụ nữ mang thai.
3. Router agent đạt 92,5% trên 40 câu, chưa đạt ngưỡng 95% đặt ra cho giai đoạn sau.
4. Bộ tách thành phần chỉ xét 30 thành phần đầu. Nhãn chỉ có tiếng Hàn/Nhật, không có INCI, thường rơi vào `low_recognition`.
5. Luồng chat cũ còn 4 luật xung đột cứng trong PHP, chưa hợp nhất với bộ 11 luật YAML.
6. Trong luồng chat, chỉ mục BM25 khởi tạo rỗng; phần từ khóa thực tế đến từ ứng viên PHP lấy bằng truy vấn Mongo. Tìm kiếm lai đầy đủ (BM25 + vector + RRF) chỉ chạy ở Smart Search.
7. Rủi ro đã biết: nếu `HoaDon::taoDonHang` lỗi sau khi đã ghi đơn, đơn nháp được mở lại và có thể xác nhận lần hai. Cần sửa bằng giao dịch/khóa idempotent.
8. Chưa kiểm thử với người dùng thật; E2E chưa phủ chuyển trang VNPay qua chat.
9. Truy vấn đồ thị có hạn 600 ms và cầu dao mở 10 giây. Một tổ hợp điều kiện chưa được làm ấm có thể quá hạn ở lần đầu, khiến các câu hỏi có điều kiện trong 10 giây sau rơi về RAG (đo được 4/32 câu quá hạn ở lần thử đầu).
10. Các phép đo có cỡ mẫu nhỏ (8 ảnh, 40 câu router, 45 câu chính sách, 32 câu đồ thị, 1 lượt E2E đặt hàng). Kết quả mới là kiểm tra ban đầu, chưa đủ để kết luận thống kê.

## 10. Hướng phát triển
- Đo lại đọc nhãn trên tập ảnh lớn hơn, nhiều nhãn đa ngôn ngữ.
- Hợp nhất luật xung đột ở luồng chat cũ với bộ YAML; mời chuyên gia da liễu duyệt luật.
- Đưa router lên ≥ 95%; mở rộng bộ đánh giá hội thoại và bộ tấn công prompt injection.
- Kiểm thử đồng thời quy mô lớn cho bước xác nhận đơn; sửa rủi ro mở lại đơn nháp.
- Làm ấm thêm các tổ hợp điều kiện phổ biến và tách cầu dao theo loại truy vấn đồ thị; đo câu trả lời cuối của LLM cho câu hỏi có điều kiện; khảo sát người dùng thật.

## 11. Cấu trúc bài đề xuất (theo bài mẫu SSRC)
I. Giới thiệu (bối cảnh, 3 câu hỏi nghiên cứu, đóng góp) · II. Công trình liên quan · III. Kiến trúc và phạm vi hệ thống (Hình 1, Bảng I, ranh giới dữ liệu, bảo vệ dữ liệu cá nhân) · IV. Phương pháp (đọc nhãn, chính sách, agent đặt hàng, đồ thị) · V. Kết quả và thảo luận (thiết kế kiểm thử, bảng số liệu, giới hạn) · VI. Kết luận · Lời cảm ơn · Tài liệu tham khảo.

## 12. Dữ liệu cá nhân và đạo đức (để viết mục III)
- Ảnh nhãn không lưu trên server, không ghi log; ảnh được gửi tới nhà cung cấp mô hình (Groq/Google) để đọc chữ. Thẻ kết quả ghi rõ điều này.
- Thẻ đơn nháp che số điện thoại; audit log của agent che dữ liệu; nhánh đồ thị không log câu hỏi của khách.
- Đơn test trong E2E tạo trên cơ sở dữ liệu thật bằng tài khoản test riêng, sau đó hủy và hoàn kho.
- Ảnh đánh giá đọc nhãn là ảnh bao bì sản phẩm, không chứa người.
- Dữ liệu sản phẩm thu thập bằng spider từ website bán lẻ mỹ phẩm (`CẦN BỔ SUNG`: tên nguồn chính xác, thời điểm thu thập, phạm vi sử dụng).

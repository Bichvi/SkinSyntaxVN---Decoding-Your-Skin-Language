# Kịch bản video giới thiệu chatbot Syna. Mỗi cảnh: tiêu đề, nội dung slide (HTML), lời đọc.
# Sửa lời đọc hoặc slide ở đây rồi chạy lại build.py.

def box(title, sub="", cls=""):
    return f'<div class="box {cls}"><b>{title}</b><span>{sub}</span></div>'

def arrow(t="→"):
    return f'<div class="arrow">{t}</div>'

def row(*items):
    return '<div class="row">' + "".join(items) + "</div>"

def why(items):
    return '<div class="why">' + "".join(
        f'<div class="w"><div class="q">{q}</div><div class="a">{a}</div></div>' for q, a in items) + "</div>"

SCENES = [
    dict(
        kicker="SkinSyntaxVN", title="Syna — trợ lý AI chăm sóc da",
        body='<div class="grid5">' + "".join(
            f'<div class="card"><div class="n">{i}</div><b>{t}</b><span>{s}</span></div>' for i, t, s in [
                (1, "Tư vấn theo hồ sơ da", "RAG trên dữ liệu thật của shop"),
                (2, "Chụp bảng thành phần", "🟢🟡🔴 · kỵ giỏ hàng · sáng/tối"),
                (3, "Đặt hàng trong chat", "Khách bấm xác nhận mới tạo đơn"),
                (4, "Chính sách & HDSD", "Từ kho chính thức, có hậu kiểm"),
                (5, "Hỏi đáp có điều kiện", "Đồ thị Neo4j"),
            ]) + '</div><div class="motto">AI chỉ đọc, diễn đạt, đề xuất. Giá, tồn kho, xung đột, tạo đơn do code và luật quyết định.</div>',
        say="Xin chào. Đây là Syna, trợ lý AI của website mỹ phẩm Skin Syntax. Syna làm năm việc: tư vấn theo hồ sơ da, đọc ảnh bảng thành phần, đặt hàng ngay trong khung chat, trả lời chính sách của shop, và trả lời các câu hỏi có điều kiện bằng đồ thị tri thức. Nguyên tắc xuyên suốt: trí tuệ nhân tạo chỉ đọc, diễn đạt và đề xuất. Những gì liên quan đến tiền và sức khỏe, như giá, tồn kho, xung đột hoạt chất và tạo đơn, đều do code và luật đã kiểm chứng quyết định.",
    ),
    dict(
        kicker="Kiến trúc", title="Bức tranh tổng thể",
        body=row(box("Trình duyệt", "Widget chat JS"), arrow(), box("Nginx", "cổng web, chặn /api"), arrow(),
                 box("PHP 8.2 MVC", "BFF · session · giỏ · tạo đơn", "hl"), arrow("⇄"),
                 box("Flask AI service", "chat · agent · ảnh · tìm kiếm", "hl2"))
             + row(box("MongoDB Atlas", "dữ liệu chính"), box("ChromaDB", "vector"), box("Neo4j", "đồ thị tri thức"), box("Redis", "cache · trạng thái · giới hạn"))
             + '<div class="note">Tất cả chạy bằng Docker Compose. Trình duyệt không bao giờ gọi thẳng dịch vụ AI.</div>',
        say="Về kiến trúc. Trình duyệt chỉ nói chuyện với Nginx và PHP. PHP là lớp đứng giữa, quản lý đăng nhập, giỏ hàng và tạo đơn. Khi cần trí tuệ nhân tạo, PHP gọi nội bộ sang dịch vụ Flask viết bằng Python. Flask dùng bốn kho dữ liệu: Mongo DB Atlas lưu dữ liệu chính, Chroma lưu vector, Neo4j lưu đồ thị tri thức, và Redis làm bộ nhớ đệm, trạng thái hội thoại và giới hạn tần suất. Toàn bộ chạy bằng Docker Compose.",
    ),
    dict(
        kicker="Vì sao", title="Vì sao tách PHP và Python?",
        body=why([
            ("PHP giữ nghiệp vụ", "Đơn hàng, thanh toán, session đã có sẵn trên PHP MVC. PHP là nơi DUY NHẤT ghi giỏ và tạo đơn."),
            ("Python cho AI", "LangChain, LangGraph, sentence-transformers, driver Neo4j mạnh nhất ở Python."),
            ("Cô lập lỗi", "AI chậm hay chết thì shop vẫn bán được. PHP có circuit breaker riêng cho từng endpoint."),
            ("Ranh giới quyền", "Python chỉ đọc và đề xuất. Mọi thao tác dính tới tiền quay về PHP kiểm tra lại."),
        ]),
        say="Tại sao lại tách PHP và Python? Thứ nhất, phần thương mại như đơn hàng và thanh toán đã có sẵn trên PHP. Thứ hai, các thư viện trí tuệ nhân tạo mạnh nhất nằm ở Python. Thứ ba, tách ra giúp cô lập lỗi: dịch vụ AI chậm hay chết thì shop vẫn bán hàng bình thường, vì PHP có cơ chế ngắt mạch. Và quan trọng nhất là ranh giới quyền: Python chỉ được đọc và đề xuất, còn mọi thao tác dính tới tiền đều quay về PHP kiểm tra lại.",
    ),
    dict(
        kicker="Luồng hoạt động", title="Hành trình của một tin nhắn",
        body='<div class="steps">' + "".join(f'<div class="st"><i>{i}</i><b>{t}</b><span>{s}</span></div>' for i, t, s in [
            (1, "PHP", "Đọc hồ sơ da từ session · câu chào · xem giỏ → trả lời luôn"),
            (2, "Chính sách", "Có bài hỗ trợ khớp → trả lời từ nguồn chính thức"),
            (3, "Đồ thị Neo4j", "Câu có điều kiện → tool truy vấn cố định"),
            (4, "Hiểu câu hỏi", "Viết lại theo ngữ cảnh · phân loại ý định · khảo sát da"),
            (5, "RAG", "Chroma + sản phẩm từ Mongo → lọc → rerank"),
            (6, "LLM", "Prompt theo ý định → câu trả lời"),
        ]) + '</div><div class="note">Nhánh rẻ và chắc chắn chạy trước. Chỉ khi không khớp mới tốn lượt gọi LLM.</div>',
        say="Đây là hành trình của một tin nhắn. Bước một, PHP đọc hồ sơ da của khách từ phiên đăng nhập; câu chào hay câu xem giỏ được trả lời ngay mà không cần AI. Bước hai, nếu là câu hỏi chính sách, Syna trả lời từ bài viết chính thức. Bước ba, nếu là câu có điều kiện, Syna dùng đồ thị Neo4j. Chỉ khi các nhánh này không khớp, hệ thống mới viết lại câu hỏi theo ngữ cảnh, phân loại ý định, truy hồi sản phẩm và gọi mô hình ngôn ngữ. Nhánh rẻ và chắc chắn luôn chạy trước để tiết kiệm thời gian và chi phí.",
    ),
    dict(
        kicker="Agent", title="Agent đặt hàng với LangGraph",
        body=row(box("route", "luật nhanh → LLM trả JSON ý định"), arrow(), box("legacy", "chính sách · kiến thức · khảo sát"), arrow("/"),
                 box("agent", "tư vấn · mua · giỏ · tra đơn", "hl2"), arrow(), box("finish"))
             + '<div class="tools">' + "".join(f"<span>{t}</span>" for t in [
                 "search_products", "get_product", "compare_products", "check_conflicts", "view_cart", "add_to_cart",
                 "remove_from_cart", "get_checkout_defaults", "prepare_order_draft", "reorder_from_order", "get_order_status"]) + "</div>"
             + why([("Vì sao LangGraph?", "Cần đồ thị trạng thái rõ: hàng đợi nhiều ý định, giới hạn 8 bước và 45 giây mỗi lượt, rẽ nhánh về luồng cũ khi lỗi. AgentExecutor là vòng lặp đen; CrewAI hợp nhiều agent đóng vai hơn là quy trình mua hàng.")]),
        say="Khi bật cờ agent, tin nhắn đi qua một đồ thị Lang Graph. Nút route dùng luật nhanh trước, không khớp thì mô hình trả về danh sách ý định dạng JSON. Câu chính sách, kiến thức hay khảo sát da quay về luồng cũ. Câu tư vấn, mua hàng, giỏ hàng và tra đơn đi vào nút agent, nơi mô hình được gọi mười một công cụ. Nhóm chọn Lang Graph vì cần một đồ thị trạng thái rõ ràng: có hàng đợi nhiều ý định, giới hạn tám bước và bốn mươi lăm giây mỗi lượt, và luôn có đường lùi về luồng cũ.",
    ),
    dict(
        kicker="Mô hình ngôn ngữ", title="Pool LLM nhiều nhà cung cấp",
        body=row(box("OpenAI", "gpt-4o-mini"), arrow(), box("Gemini", "2.5 Flash"), arrow(), box("Groq", "gpt-oss · llama 3.3"), arrow(), box("Zhipu", "glm-4-flash"), arrow(), box("OpenRouter", "model nhỏ"))
             + why([("Vì sao nhiều provider?", "Dùng gói miễn phí, mỗi nơi có hạn mức theo phút/ngày. Hết quota thì tự chuyển nơi khác, nghỉ provider lỗi 5 phút."),
                    ("Vì sao router viết bằng luật?", "Không phải model nào trong pool cũng gọi tool tốt. Luật tất định nhanh, rẻ, test được."),
                    ("Agent dùng model nào?", "Chỉ model gọi tool tốt: Groq → Gemini → OpenAI.")]),
        say="Về mô hình ngôn ngữ, Syna không phụ thuộc một nhà cung cấp. Hệ thống có một danh sách dự phòng: Open AI, Gemini, Groq, Zhipu và Open Router. Lý do là dự án dùng gói miễn phí, mỗi nơi có hạn mức riêng; hết hạn mức thì tự chuyển sang nơi khác. Cũng vì các mô hình này gọi công cụ không đều nhau, nên phần định tuyến của luồng cũ được viết bằng luật tất định, còn agent chỉ dùng những mô hình gọi công cụ tốt.",
    ),
    dict(
        kicker="RAG", title="Truy hồi sản phẩm thật (RAG)",
        body=row(box("Câu hỏi", "đã viết lại"), arrow(), box("Embedding", "static-similarity-mrl-multilingual"), arrow(), box("ChromaDB", "collection products"), arrow(),
                 box("Gộp + lọc", "ứng viên Mongo từ PHP · ngân sách"), arrow(), box("Cross-encoder", "mmarco-mMiniLMv2", "hl2"), arrow(), box("LLM"))
             + why([("Vì sao Chroma?", "Chạy local trong Docker, miễn phí, API Python gọn. Pinecone tốn phí và phụ thuộc cloud; FAISS phải tự lo lưu trữ và metadata."),
                    ("Vì sao model embedding này?", "Đa ngôn ngữ, hiểu tiếng Việt, chạy nhanh trên CPU — máy không có GPU."),
                    ("Vì sao thêm reranker?", "Vector tìm 'gần nghĩa' nhanh nhưng thô; cross-encoder chấm lại từng cặp câu hỏi–sản phẩm nên top đầu chính xác hơn.")]),
        say="Phần truy hồi, hay RAG. Câu hỏi được biến thành vector bằng một mô hình nhúng đa ngôn ngữ, chạy nhanh trên CPU. Chroma trả về các sản phẩm gần nghĩa nhất, sau đó gộp với ứng viên PHP lấy từ Mongo, lọc theo ngân sách, rồi chấm lại bằng một mô hình cross encoder để top đầu chính xác hơn. Nhóm chọn Chroma vì chạy cục bộ trong Docker, miễn phí và dễ dùng với Python. Nhờ RAG, Syna chỉ nói về sản phẩm thật đang bán, và giá luôn lấy từ cơ sở dữ liệu.",
    ),
    dict(
        kicker="Đồ thị tri thức", title="Neo4j: trả lời câu có điều kiện",
        body='<div class="q1">“serum cho da nhạy cảm, không chứa cồn, dưới 300k”</div>'
             + row(box("Product"), arrow("CONTAINS"), box("Ingredient"), arrow("CONFLICTS_WITH"), box("Ingredient"))
             + row(box("Product"), arrow("SUITABLE_FOR"), box("SkinType"), box("Brand · Category · Concern", "OF_BRAND · IN_CATEGORY · TARGETS"))
             + why([("Vì sao Neo4j?", "Vector search kém với phủ định và điều kiện quan hệ. Đồ thị duyệt Product→Ingredient→Conflict chính xác và giải thích được."),
                    ("Vì sao LLM không viết Cypher?", "Cypher cố định có tham số: an toàn trước prompt injection, đúng, được test, biên dịch sẵn."),
                    ("An toàn", "Giá, tồn kho đọc lại từ Mongo. Neo4j chậm quá 600 ms → quay về RAG.")]),
        say="Với câu như: serum cho da nhạy cảm, không chứa cồn, dưới ba trăm nghìn, tìm theo vector sẽ làm kém, vì nó không hiểu phủ định. Syna dùng đồ thị Neo4j, nơi sản phẩm nối với thành phần, loại da, thương hiệu và danh mục. Bộ phân tích tách ra các điều kiện, rồi chạy một câu truy vấn có tham số đã viết sẵn. Mô hình ngôn ngữ không bao giờ tự viết truy vấn, để tránh bị chèn lệnh và tránh sai. Giá và tồn kho luôn đọc lại từ Mongo. Nếu Neo4j chậm quá sáu trăm mili giây, hệ thống tự quay về RAG.",
    ),
    dict(
        kicker="Tính năng nổi bật", title="Chụp bảng thành phần",
        body='<div class="steps">' + "".join(f'<div class="st"><i>{i}</i><b>{t}</b><span>{s}</span></div>' for i, t, s in [
            (1, "Nén ảnh", "Trình duyệt · ≤1600px"), (2, "PHP kiểm", "CSRF · MIME · 6 lần/10 phút · không lưu ảnh"),
            (3, "Vision LLM", "Groq qwen → Gemini · CHỈ CHÉP CHỮ"), (4, "Tách INCI", "806 alias dùng chung"),
            (5, "Luật YAML", "xung đột giỏ · hợp da 🟢🟡🔴 · sáng/tối"), (6, "Thẻ kết quả", "món thay thế · tóm tắt bằng template"),
        ]) + "</div>" + why([("Vì sao không Tesseract OCR?", "Đọc kém trên chai cong, chữ nhỏ, nhiều ngôn ngữ."),
                             ("Vì sao LLM chỉ chép chữ?", "Kết luận phải có rule_id và nguồn; chữ trên bao bì có thể chứa lệnh, nên không đưa vào prompt.")]),
        say="Tính năng nổi bật nhất là chụp bảng thành phần. Ảnh được nén ngay trên trình duyệt. PHP kiểm tra định dạng, giới hạn số lần gửi, và không lưu ảnh. Mô hình thị giác của Groq, dự phòng bằng Gemini, chỉ làm một việc là chép chữ. Sau đó code tách tên thành phần chuẩn, rồi áp các file luật để báo xung đột với giỏ hàng, mức hợp da xanh vàng đỏ, và gợi ý dùng buổi sáng hay buổi tối. Câu tóm tắt được viết bằng mẫu cố định chứ không bằng mô hình, vì chữ in trên bao bì có thể chứa câu lệnh độc hại.",
    ),
    dict(
        kicker="Bảo mật", title="Đặt hàng an toàn trong chat",
        body='<div class="steps">' + "".join(f'<div class="st"><i>{i}</i><b>{t}</b><span>{s}</span></div>' for i, t, s in [
            (1, "Token HMAC", "PHP ký · hạn 5 phút · có scope"), (2, "Thêm giỏ", "cart_effect có chữ ký + nonce"),
            (3, "Đơn nháp", "Mongo · hết hạn 10 phút"), (4, "Khách bấm Xác nhận", "session + CSRF + Origin"),
            (5, "Dùng một lần", "findOneAndUpdate nguyên tử"), (6, "Kiểm lại", "giá + tồn kho → tạo đơn"),
        ]) + '</div><div class="motto">Agent KHÔNG có tool tạo đơn, hủy đơn hay đổi giá.</div>',
        say="Đặt hàng qua chat được thiết kế với giả định mô hình ngôn ngữ không đáng tin. Mỗi lượt, PHP ký một mã thông báo HMAC hạn năm phút. Thêm vào giỏ trả về một hiệu ứng có chữ ký. Agent chỉ được lập đơn nháp, hết hạn sau mười phút. Đơn thật chỉ được tạo khi khách tự bấm nút xác nhận, có kiểm tra phiên, mã chống giả mạo và nguồn gửi. Đơn nháp chỉ dùng được một lần nhờ thao tác nguyên tử của Mongo, nên bấm hai lần cũng chỉ tạo một đơn. Giá và tồn kho được kiểm lại trước khi tạo đơn.",
    ),
    dict(
        kicker="Chống bịa", title="Trả lời chính sách từ nguồn chính thức",
        body=row(box("support.json", "29 bài · có version"), arrow(), box("PHP xếp hạng", "≤3 bài"), arrow(), box("Rerank đoạn", "cross-encoder"), arrow(),
                 box("LLM trả lời", "có trích nguồn"), arrow(), box("Hậu kiểm", "số, phí, SĐT, URL, lời hứa", "hl"))
             + '<div class="note">Sai → dùng bản soạn sẵn. Eval 45 câu: số liệu lạ 0 · đúng bài 25/25.</div>',
        say="Với câu hỏi chính sách, Syna không trả lời theo trí nhớ của mô hình. Mọi chính sách nằm trong một file nguồn duy nhất. PHP chọn tối đa ba bài liên quan, Flask chọn đoạn phù hợp, mô hình viết câu trả lời có trích nguồn. Sau đó một lớp hậu kiểm so từng con số, số điện thoại, đường dẫn và lời hứa với nguồn. Có gì không khớp, câu trả lời của mô hình bị thay bằng bản soạn sẵn. Trên bộ đánh giá bốn mươi lăm câu, số liệu bịa bằng không.",
    ),
    dict(
        kicker="Lưu trữ", title="Dữ liệu nằm ở đâu?",
        body=why([("MongoDB Atlas", "san_pham, khach_hang (hồ sơ da), hoa_don, chat_conversations, chat_order_drafts (TTL). Vì sao: dữ liệu mỹ phẩm không đồng nhất, document linh hoạt; Atlas có sẵn trên cloud."),
                  ("ChromaDB", "Vector mô tả sản phẩm cho tìm kiếm ngữ nghĩa."),
                  ("Neo4j", "Bản sao dẫn xuất từ Mongo để suy luận quan hệ. Xóa dựng lại được, không ghi ngược."),
                  ("Redis", "Trạng thái hội thoại agent, cache, rate limit, Streams sự kiện, hàng đợi Celery. Vì sao: nhanh, nhiều cấu trúc dữ liệu, fail-open."),
                  ("File luật YAML", "11 luật / 55 cặp xung đột, luật hợp da — mỗi luật có nguồn.")]),
        say="Về lưu trữ. Mongo DB Atlas giữ dữ liệu chính: sản phẩm, khách hàng và hồ sơ da, đơn hàng, lịch sử chat và đơn nháp. Nhóm chọn Mongo vì dữ liệu mỹ phẩm không đồng nhất, mô hình tài liệu linh hoạt hơn bảng. Chroma giữ vector. Neo4j là bản sao dẫn xuất từ Mongo, chỉ để suy luận quan hệ, có thể xóa và dựng lại. Redis giữ trạng thái hội thoại, bộ nhớ đệm, giới hạn tần suất và hàng đợi sự kiện. Tri thức chuyên môn về xung đột và hợp da nằm trong các file luật có nguồn.",
    ),
    dict(
        kicker="Pipeline dữ liệu", title="Giữ các kho đồng bộ",
        body=row(box("Admin sửa SP", "Mongo"), arrow(), box("Redis Stream", "catalog:changes"), arrow(), box("catalog-worker"), arrow(), box("graph_sync ETL", "tách INCI · suy nhãn", "hl2"), arrow(), box("Neo4j + Chroma"))
             + '<div class="note">Đồng bộ đầy đủ tự động 02:30 mỗi ngày · nhãn: manual &gt; llm_approved &gt; rule · INCI map 88,5%</div>',
        say="Để các kho luôn khớp nhau, khi quản trị viên sửa sản phẩm, một sự kiện được đẩy vào Redis Stream. Tiến trình catalog worker nhận sự kiện và chạy bộ đồng bộ đồ thị: tách bảng thành phần thành tên chuẩn quốc tế, suy ra nhãn công dụng, rồi ghi sang Neo4j và Chroma. Mỗi đêm lúc hai giờ ba mươi còn có một lần đồng bộ đầy đủ. Nhãn do quản trị viên nhập luôn được ưu tiên hơn nhãn do AI gợi ý.",
    ),
    dict(
        kicker="Độ bền", title="Mọi phần AI đều có đường lùi",
        body=why([("Neo4j chết", "→ luật Python cùng file YAML"), ("Groq hết quota", "→ Gemini → ô dán thành phần"),
                  ("Agent lỗi", "→ luồng chat cũ"), ("Flask chết", "→ PHP trả dữ liệu dự phòng (circuit breaker)"),
                  ("Redis chết", "→ fail-open, chỉ mất cache và giới hạn")])
             + '<div class="motto">Syna: hiểu đúng người · trả lời bằng dữ liệu thật · không bao giờ tự quyết thay khách.</div>',
        say="Cuối cùng là độ bền. Mỗi phần trí tuệ nhân tạo đều có đường lùi. Neo4j chết thì dùng luật Python. Mô hình đọc ảnh hết hạn mức thì chuyển sang Gemini, rồi đến ô dán thành phần. Agent lỗi thì quay về luồng chat cũ. Dịch vụ AI chết thì PHP dùng dữ liệu dự phòng. Tóm lại, Syna hiểu đúng người dùng, trả lời bằng dữ liệu thật, và không bao giờ tự quyết thay khách hàng. Cảm ơn các bạn đã theo dõi.",
    ),
]

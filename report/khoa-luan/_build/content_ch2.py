# -*- coding: utf-8 -*-
H1("CHƯƠNG 2  CƠ SỞ LÝ THUYẾT VÀ CÔNG NGHỆ")
P("Chương này trình bày nền tảng lý thuyết của các kỹ thuật cốt lõi trong SkinSyntaxVN, bắt đầu từ hệ thống gợi ý và các độ đo đánh giá. "
  "Tiếp theo, chương giới thiệu RAG, đồ thị tri thức và các công nghệ phát triển, kèm lý do nhóm lựa chọn từng công nghệ.")

# ---------------- 2.1 ----------------
H2("2.1  Hệ thống gợi ý")
H3("2.1.1  Khái niệm")
P("Hệ thống gợi ý (Recommender System – RS) là phần mềm đề xuất cho người dùng những đối tượng mà họ có khả năng quan tâm [16]. "
  "Đối tượng được gợi ý thường gọi là item, có thể là sản phẩm, bài hát hay bộ phim. "
  "Bài toán gợi ý được phát biểu là ước lượng mức độ hữu ích của mỗi item đối với mỗi người dùng, rồi chọn ra K item có điểm cao nhất [2].")
P("Trong thương mại điện tử, hệ thống gợi ý giúp giảm tình trạng quá tải lựa chọn và tăng khả năng người dùng tìm thấy sản phẩm phù hợp. "
  "Với mỹ phẩm, yêu cầu này càng quan trọng vì một gợi ý sai có thể gây kích ứng da chứ không chỉ làm người dùng mất hứng thú.")

H3("2.1.2  Phân loại hệ thống gợi ý")
P("Dựa trên nguồn dữ liệu được sử dụng, các tài liệu kinh điển chia hệ thống gợi ý thành năm nhóm chính [2], [3], [16]. "
  "Sơ đồ phân loại được thể hiện ở Hình 2.1 và đặc điểm từng nhóm được tóm tắt ở Bảng 2.1.")
F("Hình 2.1 Sơ đồ phân loại hệ thống gợi ý",
  "Vẽ sơ đồ cây: Recommender System → (1) Content-based; (2) Collaborative Filtering → Memory-based (User-based kNN, Item-based kNN) và Model-based (Matrix Factorization/SVD, BPR); "
  "(3) Knowledge-based (Constraint-based, Case-based); (4) Graph-based; (5) Hybrid (Weighted, Switching, Mixed, Cascade…). Tô màu các nhánh nhóm đã dùng.")
B("**Lọc theo nội dung (content-based):** gợi ý các item có đặc trưng giống với item người dùng từng thích, thường biểu diễn bằng vector TF-IDF hoặc embedding [16].",
  "**Lọc cộng tác (collaborative filtering):** dựa trên hành vi của nhiều người dùng; nhánh memory-based dùng kNN tìm láng giềng gần nhất, nhánh model-based học nhân tố ẩn bằng phân rã ma trận [9].",
  "**Dựa trên tri thức (knowledge-based):** dùng luật hoặc ràng buộc của chuyên gia, phù hợp khi sản phẩm ít được mua lặp và cần giải thích rõ ràng [16].",
  "**Dựa trên đồ thị (graph-based):** biểu diễn người dùng, item và thuộc tính thành các nút, rồi khai thác đường đi giữa chúng để gợi ý [7].",
  "**Lai (hybrid):** kết hợp hai hay nhiều kỹ thuật để bù trừ nhược điểm của nhau, ví dụ theo trọng số, theo chuyển đổi hoặc theo tầng [3].")
T("Bảng 2.1 So sánh các nhóm kỹ thuật gợi ý",
  ["Nhóm", "Dữ liệu cần", "Ưu điểm", "Nhược điểm"],
  [["Content-based", "Thuộc tính item, hồ sơ người dùng", "Không cần dữ liệu người dùng khác; gợi ý được item mới", "Ít đa dạng; phụ thuộc chất lượng mô tả"],
   ["Collaborative (kNN, SVD)", "Ma trận người dùng – item", "Phát hiện sở thích tiềm ẩn", "Khởi đầu lạnh (cold-start); ma trận thưa"],
   ["Knowledge-based", "Luật, ràng buộc chuyên môn", "Giải thích được; không cần lịch sử", "Tốn công xây dựng luật"],
   ["Graph-based", "Đồ thị quan hệ", "Khai thác quan hệ nhiều bước; giải thích bằng đường đi", "Cần đồng bộ và duy trì đồ thị"],
   ["Hybrid", "Kết hợp các nguồn trên", "Giảm cold-start; chính xác hơn", "Phức tạp; cần tinh chỉnh trọng số"]],
  [3.3, 3.7, 4.3, 4.2], size=10.5)
P("Với SkinSyntaxVN, dữ liệu hành vi còn ít trong giai đoạn đầu nên lọc cộng tác thuần túy gặp vấn đề khởi đầu lạnh. "
  "Vì vậy, nhóm chọn hướng lai, lấy hồ sơ da và tri thức thành phần làm nền, rồi bổ sung tín hiệu hành vi khi dữ liệu tăng dần.")

H3("2.1.3  Các thuật toán áp dụng trong đề tài")
H4("2.1.3.1  Xếp hạng có trọng số")
P("Để xếp hạng sản phẩm phổ biến một cách công bằng, nhóm dùng công thức trung bình có trọng số kiểu Bayes thay cho điểm đánh giá trung bình thông thường. "
  "Công thức này kéo điểm của sản phẩm có ít lượt đánh giá về gần mức trung bình chung, tránh trường hợp một sản phẩm chỉ có một đánh giá 5 sao đứng đầu.")
EQ("WR = v/(v + m) · R + m/(v + m) · C", "2.1")
P("Trong đó, R là điểm trung bình của sản phẩm, v là số lượt đánh giá, m là số lượt đánh giá tối thiểu và C là điểm trung bình của toàn bộ danh mục. "
  "Trong cài đặt hiện tại, m được chọn bằng phân vị thứ 75 của số lượt đánh giá.")
H4("2.1.3.2  TF-IDF và độ tương đồng cosine")
P("Kỹ thuật TF-IDF gán trọng số cho mỗi từ theo tần suất xuất hiện trong mô tả sản phẩm và độ hiếm của từ đó trong toàn bộ danh mục [13]. "
  "Hai sản phẩm được so sánh bằng độ tương đồng cosine giữa hai vector TF-IDF tương ứng.")
EQ("w(t, d) = tf(t, d) · log( N / df(t) )", "2.2")
EQ("cos(A, B) = (A · B) / ( ||A|| · ||B|| )", "2.3")
P("Trong đó, tf(t, d) là số lần từ t xuất hiện trong tài liệu d, N là tổng số sản phẩm và df(t) là số sản phẩm chứa từ t.")
H4("2.1.3.3  Hệ số Jaccard trên tập thành phần")
P("Đối với mỹ phẩm, hai sản phẩm có bảng thành phần giống nhau thường có công dụng tương tự. "
  "Nhóm dùng hệ số Jaccard để đo mức trùng lặp giữa hai tập thành phần A và B, làm cơ sở tạo quan hệ tương tự trong đồ thị tri thức.")
EQ("J(A, B) = |A ∩ B| / |A ∪ B|", "2.4")
H4("2.1.3.4  Kết hợp lai theo trọng số")
P("Điểm cuối cùng của một sản phẩm là tổng có trọng số của nhiều tín hiệu thành phần theo mô hình lai trọng số của Burke [3]. "
  "Mỗi tín hiệu được chuẩn hóa về đoạn [0, 1] trước khi kết hợp.")
EQ("score(u, i) = Σ_k  w_k · s_k(u, i),   với Σ_k w_k = 1", "2.5")
P("Trong đó, s_k là điểm của tín hiệu thứ k như độ hợp loại da, độ tương đồng nội dung, hành vi xem và mua, hay quan hệ trên đồ thị. "
  "Trọng số w_k được nhóm xác định ban đầu theo kinh nghiệm và hiệu chỉnh dựa trên kết quả đánh giá ở chương 6.")

H3("2.1.4  Độ đo đánh giá hệ thống gợi ý")
P("Việc đánh giá hệ thống gợi ý cần chọn độ đo phù hợp với mục tiêu, vì mỗi nhóm độ đo trả lời một câu hỏi khác nhau [6]. "
  "Các tài liệu thường chia độ đo thành ba nhóm: dự đoán điểm, xếp hạng và chất lượng ngoài độ chính xác.")
H4("2.1.4.1  Nhóm độ đo dự đoán điểm")
P("Nhóm độ đo này dùng khi hệ thống dự đoán điểm đánh giá mà người dùng sẽ chấm cho sản phẩm, ví dụ trên thang 1 đến 5 sao [6]. "
  "RMSE phạt nặng các sai số lớn, còn MAE cho biết sai số tuyệt đối trung bình.")
EQ("RMSE = sqrt( (1/n) · Σ (r̂_ui − r_ui)² )", "2.6")
EQ("MAE = (1/n) · Σ | r̂_ui − r_ui |", "2.7")
H4("2.1.4.2  Nhóm độ đo xếp hạng Top-K")
P("Trong thực tế, người dùng chỉ quan tâm đến K sản phẩm đầu danh sách nên nhóm độ đo xếp hạng là phổ biến nhất [6], [13]. "
  "Gọi Rel là tập sản phẩm thực sự phù hợp và Rec_K là K sản phẩm đầu tiên được gợi ý, ta có các công thức sau.")
EQ("Precision@K = |Rel ∩ Rec_K| / K", "2.8")
EQ("Recall@K = |Rel ∩ Rec_K| / |Rel|", "2.9")
EQ("F1@K = 2 · Precision@K · Recall@K / (Precision@K + Recall@K)", "2.10")
P("Precision@K cho biết tỷ lệ gợi ý đúng trong K kết quả, còn Recall@K cho biết hệ thống bắt được bao nhiêu phần sản phẩm phù hợp. "
  "F1@K là trung bình điều hòa của hai đại lượng trên, giúp cân bằng giữa gợi ý ít mà đúng và gợi ý nhiều để không bỏ sót.")
P("Các độ đo trên chưa phản ánh vị trí của sản phẩm đúng trong danh sách. "
  "Vì vậy, nhóm sử dụng thêm MRR và nDCG, trong đó nDCG giảm dần mức đóng góp của sản phẩm đúng khi vị trí của nó càng thấp [8].")
EQ("MRR = (1/|Q|) · Σ_q  1 / rank_q", "2.11")
EQ("DCG@K = Σ_{i=1..K} rel_i / log2(i + 1),   nDCG@K = DCG@K / IDCG@K", "2.12")
P("Trong đó, rank_q là vị trí của kết quả đúng đầu tiên với truy vấn q, rel_i là mức liên quan của kết quả ở vị trí i và IDCG@K là DCG của danh sách lý tưởng. "
  "Ngoài ra, Hit@K đo tỷ lệ truy vấn có ít nhất một kết quả đúng trong K vị trí đầu.")
H4("2.1.4.3  Nhóm độ đo ngoài độ chính xác")
P("Một hệ thống chính xác nhưng luôn gợi ý cùng vài sản phẩm bán chạy vẫn chưa phải là hệ thống tốt. "
  "Do đó, các nghiên cứu bổ sung độ bao phủ (coverage), độ đa dạng (diversity) và độ mới lạ (novelty) của danh sách gợi ý [6], [16]. "
  "Trong đề tài, nhóm giới hạn số sản phẩm cùng một thương hiệu trong một danh sách để tăng độ đa dạng.")

# ---------------- 2.2 ----------------
H2("2.2  Mô hình ngôn ngữ lớn và RAG")
H3("2.2.1  Mô hình ngôn ngữ lớn")
P("Mô hình ngôn ngữ lớn là mạng nơ-ron dựa trên kiến trúc Transformer, được huấn luyện trên lượng văn bản rất lớn để dự đoán từ tiếp theo [19]. "
  "Nhờ cơ chế chú ý (attention), mô hình hiểu được ngữ cảnh dài và có thể trả lời câu hỏi bằng ngôn ngữ tự nhiên. "
  "Tuy nhiên, LLM không biết dữ liệu riêng của cửa hàng và có thể sinh ra thông tin sai nhưng nghe rất hợp lý, gọi là hiện tượng ảo giác (hallucination).")
P("Trong SkinSyntaxVN, nhóm không huấn luyện lại mô hình mà gọi LLM qua API của nhiều nhà cung cấp. "
  "Các nhà cung cấp được xếp thành một nhóm dự phòng, khi một nhà cung cấp lỗi hoặc hết hạn mức thì hệ thống tự chuyển sang nhà cung cấp kế tiếp.")
H3("2.2.2  Kỹ thuật RAG – sinh câu trả lời dựa trên dữ liệu truy xuất")
P("RAG là kỹ thuật kết hợp một bộ truy xuất tài liệu với một mô hình sinh văn bản, được Lewis và cộng sự đề xuất năm 2020 [11]. "
  "Thay vì để LLM trả lời từ trí nhớ, hệ thống truy xuất các đoạn dữ liệu liên quan rồi đưa vào lời nhắc (prompt) làm ngữ cảnh. "
  "Cách làm này giúp câu trả lời bám sát dữ liệu thật và có thể cập nhật kiến thức mà không cần huấn luyện lại.")
F("Hình 2.2 Luồng xử lý tổng quát của kỹ thuật RAG",
  "Vẽ luồng: Câu hỏi → Embedding → Truy xuất (Vector DB + BM25) → Hợp nhất RRF → Xếp hạng lại → Top-K ngữ cảnh → Prompt → LLM → Câu trả lời. "
  "Có nhánh ngoại tuyến: Dữ liệu sản phẩm → Chia đoạn → Embedding → Lưu ChromaDB.")
H3("2.2.3  Embedding và tìm kiếm ngữ nghĩa")
P("Embedding là phép ánh xạ một đoạn văn bản thành vector số thực sao cho các văn bản có nghĩa gần nhau sẽ có vector gần nhau [15]. "
  "Khi người dùng hỏi \"kem dưỡng cho da khô\", hệ thống tìm các sản phẩm có vector gần với câu hỏi theo độ tương đồng cosine ở công thức (2.3). "
  "Nhóm sử dụng mô hình embedding đa ngôn ngữ thuộc họ Sentence Transformers để xử lý được cả tiếng Việt lẫn tên thành phần tiếng Anh.")
H3("2.2.4  Tìm kiếm lai BM25, RRF và xếp hạng lại")
P("Tìm kiếm ngữ nghĩa có thể bỏ sót các truy vấn chứa tên riêng như tên thương hiệu hoặc tên hoạt chất. "
  "Ngược lại, thuật toán BM25 dựa trên từ khóa xử lý tốt trường hợp này nhờ chấm điểm theo tần suất từ có hiệu chỉnh độ dài tài liệu [17].")
EQ("BM25(q, d) = Σ_t IDF(t) · tf(t,d)·(k1 + 1) / ( tf(t,d) + k1·(1 − b + b·|d|/avgdl) )", "2.13")
P("Để kết hợp hai danh sách kết quả, nhóm dùng phương pháp hợp nhất thứ hạng nghịch đảo (Reciprocal Rank Fusion – RRF) [4]. "
  "RRF chỉ dựa vào thứ hạng nên không cần chuẩn hóa thang điểm khác nhau của BM25 và cosine.")
EQ("RRF(d) = Σ_r  1 / ( k + rank_r(d) ),   thường chọn k = 60", "2.14")
P("Sau bước hợp nhất, một mô hình cross-encoder có thể chấm lại từng cặp câu hỏi và sản phẩm để xếp hạng chính xác hơn. "
  "Đổi lại, bước này tốn thời gian xử lý nên nhóm chỉ bật ở những luồng cần độ chính xác cao.")
H3("2.2.5  Đánh giá hệ thống RAG")
P("Chất lượng của một hệ thống RAG phụ thuộc vào cả bước truy xuất lẫn bước sinh câu trả lời, nên cần đánh giá riêng từng bước. "
  "Bước truy xuất được đo bằng Hit@K, MRR và nDCG ở mục 2.1.4, nghĩa là với mỗi câu hỏi xem trong K tài liệu lấy về có bao nhiêu tài liệu đúng. "
  "Bước sinh được đo bằng khung RAGAS, dùng một LLM làm giám khảo để chấm tự động [5].")
T("Bảng 2.2 Các chỉ số đánh giá của RAGAS",
  ["Chỉ số", "Ý nghĩa"],
  [["Faithfulness", "Mức độ các khẳng định trong câu trả lời được ngữ cảnh truy xuất hỗ trợ; điểm thấp cho thấy có ảo giác."],
   ["Answer Relevancy", "Mức độ câu trả lời đúng trọng tâm câu hỏi."],
   ["Context Precision", "Mức độ các ngữ cảnh liên quan được xếp ở vị trí đầu danh sách truy xuất."],
   ["Context Recall", "Mức độ ngữ cảnh truy xuất bao phủ thông tin cần thiết để trả lời."]],
  [4, 11.5])

# ---------------- 2.3 ----------------
H2("2.3  Đồ thị tri thức và Neo4j")
H3("2.3.1  Đồ thị tri thức")
P("Đồ thị tri thức (Knowledge Graph – KG) biểu diễn tri thức dưới dạng các thực thể là nút và quan hệ giữa chúng là cạnh có hướng, có nhãn [7]. "
  "Ví dụ, nút sản phẩm nối với nút thành phần bằng cạnh CHỨA, còn hai thành phần kỵ nhau được nối bằng cạnh XUNG ĐỘT. "
  "Cách biểu diễn này cho phép trả lời các câu hỏi nhiều bước mà cơ sở dữ liệu dạng bảng phải dùng nhiều phép nối phức tạp.")
P("Nhóm chọn đồ thị tri thức vì tri thức mỹ phẩm vốn có cấu trúc quan hệ: sản phẩm chứa thành phần, thành phần hợp hoặc kỵ loại da, thành phần này xung đột với thành phần kia. "
  "Quan trọng hơn, mỗi gợi ý có thể được giải thích bằng chính đường đi trên đồ thị, giúp người dùng tin tưởng kết quả. "
  "Khi đưa đồ thị vào chatbot dưới dạng công cụ truy vấn, LLM chỉ diễn đạt lại dữ kiện có thật, qua đó giảm ảo giác.")
F("Hình 2.3 Lược đồ đồ thị tri thức sản phẩm",
  "Vẽ các nút Product, Brand, Category, Ingredient, SkinType, Concern và các cạnh: Product-[:CONTAINS]->Ingredient, Product-[:OF_BRAND]->Brand, "
  "Product-[:IN_CATEGORY]->Category, Ingredient-[:CONFLICTS_WITH]-Ingredient, Product-[:SIMILAR_TO]-Product, Product-[:CO_PURCHASED]-Product. "
  "")
H3("2.3.2  Neo4j và ngôn ngữ truy vấn Cypher")
P("Neo4j là hệ quản trị cơ sở dữ liệu đồ thị theo mô hình đồ thị thuộc tính, trong đó cả nút và cạnh đều có thể mang thuộc tính [18], [26]. "
  "Neo4j lưu quan hệ dưới dạng con trỏ trực tiếp giữa các nút nên việc duyệt quan hệ không phụ thuộc vào kích thước toàn bộ dữ liệu. "
  "Ngôn ngữ truy vấn Cypher mô tả mẫu đồ thị bằng cú pháp trực quan, ví dụ (p:Product)-[:CONTAINS]->(i:Ingredient).")
P("Trong đề tài, MongoDB vẫn là nguồn dữ liệu gốc, còn Neo4j là bản dẫn xuất phục vụ gợi ý và giải thích. "
  "Một tiến trình đồng bộ định kỳ chuyển dữ liệu sản phẩm sang đồ thị, sau đó tính các cạnh tương tự bằng hệ số Jaccard ở công thức (2.4).")

# ---------------- 2.4 ----------------
H2("2.4  LangChain và LangGraph")
H3("2.4.1  LangChain")
P("LangChain là khung phát triển mã nguồn mở giúp xây dựng ứng dụng dựa trên LLM bằng cách ghép nối các thành phần chuẩn hóa [23]. "
  "Các thành phần chính gồm mô hình chat, bộ nhúng, kho vector, bộ truy xuất và công cụ (tool) để LLM gọi hàm bên ngoài. "
  "Nhờ giao diện thống nhất, nhóm có thể đổi nhà cung cấp LLM mà không phải viết lại luồng xử lý.")
H3("2.4.2  LangGraph")
P("LangGraph là thư viện trong hệ sinh thái LangChain, mô hình hóa luồng xử lý của tác tử AI thành một đồ thị trạng thái gồm các nút và cạnh có điều kiện [24]. "
  "Mỗi nút là một bước xử lý đọc và cập nhật một trạng thái dùng chung, còn cạnh có điều kiện quyết định bước tiếp theo dựa trên trạng thái đó.")
P("Nhóm chọn LangGraph vì chatbot tư vấn không đi theo một chuỗi cố định mà phải rẽ nhánh theo ý định người dùng. "
  "Câu hỏi về thành phần cần truy vấn đồ thị, yêu cầu đặt hàng cần xác thực OTP, còn câu hỏi chung chỉ cần RAG. "
  "Với LangGraph, các nhánh này được khai báo tường minh, dễ kiểm thử và dễ theo dõi hơn so với một chuỗi điều kiện lồng nhau.")
F("Hình 2.4 Đồ thị trạng thái điều phối chatbot",
  "Vẽ: START → route (phân loại ý định) → [agent (gọi công cụ: tìm kiếm, đồ thị, giỏ hàng, đặt hàng) | legacy (RAG truyền thống)] → finish → END.")

# ---------------- 2.5 ----------------
H2("2.5  Cơ sở dữ liệu và bộ nhớ đệm")
H3("2.5.1  MongoDB")
P("MongoDB là hệ quản trị cơ sở dữ liệu NoSQL hướng tài liệu, lưu dữ liệu dưới dạng BSON có cấu trúc linh hoạt [25]. "
  "Dữ liệu mỹ phẩm có nhiều thuộc tính không đồng nhất giữa các danh mục, ví dụ kem chống nắng có chỉ số SPF còn son môi thì không. "
  "Vì vậy, mô hình tài liệu phù hợp hơn mô hình bảng cố định, đồng thời MongoDB Atlas cung cấp hạ tầng đám mây có sao lưu tự động.")
H3("2.5.2  ChromaDB")
P("ChromaDB là cơ sở dữ liệu vector mã nguồn mở, chuyên lưu trữ embedding và hỗ trợ tìm kiếm láng giềng gần đúng [20]. "
  "Mỗi bản ghi gồm vector, văn bản gốc và siêu dữ liệu để lọc, ví dụ lọc theo danh mục hoặc khoảng giá. "
  "Nhóm chọn ChromaDB vì dễ triển khai bằng Docker, tích hợp sẵn với LangChain và đáp ứng tốt quy mô vài nghìn sản phẩm.")
H3("2.5.3  Redis")
P("Redis là kho dữ liệu khóa – giá trị lưu trên bộ nhớ trong, có tốc độ đọc ghi rất cao [29]. "
  "Trong hệ thống, Redis đảm nhận ba vai trò: bộ nhớ đệm cho kết quả tìm kiếm và trả lời chatbot, bộ đếm giới hạn tần suất gọi API và hàng đợi công việc cho Celery. "
  "Việc dùng chung một thành phần cho nhiều vai trò giúp giảm số dịch vụ cần vận hành.")

# ---------------- 2.6 ----------------
H2("2.6  Công nghệ phát triển và triển khai")
H3("2.6.1  PHP và mô hình MVC")
P("Phân hệ thương mại điện tử được xây dựng bằng PHP theo mô hình Model – View – Controller (MVC) [10], [28]. "
  "Model xử lý dữ liệu và nghiệp vụ, View hiển thị giao diện, còn Controller tiếp nhận yêu cầu và điều phối giữa hai thành phần còn lại. "
  "Cách phân tách này giúp nhiều thành viên phát triển song song và dễ bảo trì khi hệ thống mở rộng.")
H3("2.6.2  Flask")
P("Các dịch vụ AI được viết bằng Python với khung Flask, một khung web nhỏ gọn chỉ cung cấp phần lõi định tuyến và xử lý yêu cầu [22]. "
  "Python có hệ sinh thái thư viện AI phong phú nên phù hợp để cài đặt RAG, embedding và truy vấn đồ thị. "
  "PHP gọi các dịch vụ này qua API nội bộ theo mô hình Backend for Frontend, nên giao diện không phụ thuộc trực tiếp vào chi tiết của phần AI.")
H3("2.6.3  Docker, Nginx và Caddy")
P("Docker đóng gói mỗi dịch vụ cùng toàn bộ thư viện phụ thuộc vào một container, giúp môi trường phát triển và môi trường máy chủ giống nhau [14], [21]. "
  "Docker Compose mô tả toàn bộ hệ thống gồm PHP, dịch vụ AI, MongoDB, ChromaDB, Neo4j và Redis trong một tệp cấu hình duy nhất. "
  "Nginx đóng vai trò máy chủ web và proxy ngược, còn Caddy tự động cấp chứng chỉ HTTPS khi triển khai lên máy chủ thật [27].")
T("Bảng 2.3 Tổng hợp công nghệ sử dụng và lý do lựa chọn",
  ["Lớp", "Công nghệ", "Lý do lựa chọn"],
  [["Giao diện", "HTML, CSS, JavaScript, PHP View", "Hiển thị phía máy chủ, nhẹ, dễ tối ưu tốc độ tải trang"],
   ["Nghiệp vụ", "PHP 8.2 (MVC)", "Phù hợp nghiệp vụ thương mại điện tử, dễ phân chia công việc"],
   ["Dịch vụ AI", "Python, Flask, LangChain, LangGraph", "Hệ sinh thái AI phong phú; điều phối tác tử rõ ràng"],
   ["Dữ liệu chính", "MongoDB Atlas", "Lược đồ linh hoạt cho dữ liệu sản phẩm không đồng nhất"],
   ["Dữ liệu vector", "ChromaDB", "Tìm kiếm ngữ nghĩa, tích hợp sẵn LangChain"],
   ["Đồ thị tri thức", "Neo4j, Cypher", "Mô hình hóa quan hệ thành phần, giải thích được gợi ý"],
   ["Bộ đệm, hàng đợi", "Redis, Celery", "Tăng tốc phản hồi; xử lý tác vụ nền như sinh video"],
   ["Triển khai", "Docker Compose, Nginx, Caddy", "Môi trường đồng nhất, HTTPS tự động"]],
  [3, 4.8, 7.7], size=11)

# ---------------- 2.7 ----------------
H2("2.7  Thang đo Likert trong đánh giá mức độ hài lòng")
H3("2.7.1  Khái niệm")
P("Thang đo Likert do Rensis Likert đề xuất năm 1932, dùng để đo thái độ của người trả lời đối với một phát biểu [12]. "
  "Người trả lời chọn một trong các mức đồng ý có thứ tự, phổ biến nhất là năm mức từ \"Rất không hài lòng\" đến \"Rất hài lòng\". "
  "Điểm trung bình của các câu trả lời phản ánh mức độ hài lòng chung của nhóm người dùng đối với từng tiêu chí.")
P("Với thang năm mức, khoảng cách giữa các mức được tính bằng (5 − 1) / 5 = 0,8. "
  "Từ đó, điểm trung bình được diễn giải như sau: 1,00–1,80 là rất không hài lòng, 1,81–2,60 là không hài lòng, 2,61–3,40 là bình thường, "
  "3,41–4,20 là hài lòng và 4,21–5,00 là rất hài lòng.")
H3("2.7.2  Áp dụng trong đề tài")
P("Nhóm dùng thang Likert 5 mức ở hai thời điểm. "
  "Trước khi xây dựng, phiếu khảo sát nhu cầu đo mức độ đồng ý của khách hàng, nhân viên và chủ cửa hàng với các khó khăn và giải pháp đề xuất. "
  "Sau khi hoàn thành, phiếu khảo sát Google Form được gửi cho người dùng thử nghiệm, gồm các câu hỏi Likert về mức độ hài lòng và câu hỏi mở để góp ý.")
P("Kết hợp độ đo định lượng ở mục 2.1.4, 2.2.5 với kết quả khảo sát Likert giúp nhóm đánh giá hệ thống từ cả góc độ kỹ thuật lẫn trải nghiệm người dùng.")

H2("2.8  Tóm tắt chương")
H3("2.8.1  Các nội dung đã trình bày")
P("Chương 2 đã trình bày các nhóm kỹ thuật gợi ý, các độ đo đánh giá, kỹ thuật RAG, đồ thị tri thức và các công nghệ phát triển, triển khai. "
  "Mỗi công nghệ đều được gắn với một nhu cầu cụ thể đã xác định ở chương 1.")
H3("2.8.2  Liên hệ với chương tiếp theo")
P("Trên nền tảng lý thuyết này, chương 3 sẽ phân tích chi tiết yêu cầu của hệ thống thông qua tác nhân, use case, sơ đồ hoạt động và sơ đồ tuần tự.")
PB()


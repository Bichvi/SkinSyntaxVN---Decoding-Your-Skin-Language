# -*- coding: utf-8 -*-
H1("CHƯƠNG 4  CÀI ĐẶT, KIỂM THỬ VÀ ĐÁNH GIÁ")
P("Chương này trình bày kết quả hiện thực hóa bản thiết kế ở chương 3 và đánh giá chất lượng của hệ thống. "
  "Phần đầu mô tả môi trường cài đặt, kết quả giao diện và cách triển khai. "
  "Phần sau trình bày kết quả kiểm thử tự động và đánh giá định lượng các thành phần AI bằng những bộ dữ liệu thử nghiệm cụ thể. "
  "Với mỗi kết quả, nhóm nêu rõ điều kiện đo và hạn chế để người đọc đánh giá đúng mức độ tin cậy.")

# ================= 4.1 =================
H2("4.1  Môi trường cài đặt")
P("Hệ thống được phát triển trên máy tính cá nhân chạy Windows và đóng gói bằng Docker để chạy giống nhau trên mọi môi trường. "
  "Bảng 4.1 liệt kê các công cụ và phiên bản chính được sử dụng.")
T("Bảng 4.1 Công cụ và môi trường cài đặt",
  ["Nhóm", "Công cụ, phiên bản"],
  [["Ngôn ngữ", "PHP 8.2, Python 3.11, JavaScript"],
   ["Web và dịch vụ", "Nginx 1.27, PHP-FPM, Flask, Gunicorn, Celery"],
   ["Thư viện AI", "LangChain, LangGraph, CrewAI, sentence-transformers, rank-bm25"],
   ["Cơ sở dữ liệu", "MongoDB Atlas, Neo4j 5.26, ChromaDB 0.5.20, Redis 7"],
   ["Đa phương tiện", "FFmpeg, Piper TTS (dự phòng edge-tts, gTTS)"],
   ["Đóng gói, triển khai", "Docker, Docker Compose, Caddy, GitHub Actions"],
   ["Kiểm thử", "pytest, PHPUnit, Playwright, RAGAS 0.4.4"],
   ["Thiết kế", "draw.io (sơ đồ), wireframe (giao diện)"]],
  [3.5, 12])

# ================= 4.2 =================
H2("4.2  Kết quả cài đặt")
P("Nhóm đã cài đặt đầy đủ ba phân hệ theo danh sách chức năng ở Bảng 1.5. "
  "Phần này trình bày các màn hình tiêu biểu, nhóm theo phân hệ để đối chiếu với thiết kế ở mục 3.7. "
  "Các ảnh chụp được lấy từ hệ thống chạy thật với dữ liệu sản phẩm của cửa hàng.")
H3("4.2.1  Phân hệ thương mại điện tử")
F("Hình 4.1 Trang chủ với các khối gợi ý theo hồ sơ da",
  "Chèn ảnh chụp màn hình trang chủ khi đã đăng nhập: khối gợi ý cá nhân hóa, flash sale, video Syna.")
F("Hình 4.2 Trang chi tiết sản phẩm và ô \"Vì sao phù hợp\"",
  "Chèn ảnh chụp trang chi tiết: thành phần, ô Vì sao phù hợp đang mở, sản phẩm tương tự.")
F("Hình 4.3 Trang thanh toán với mã QR chuyển khoản",
  "Chèn ảnh chụp trang thanh toán: voucher, điểm tích lũy, tổng tiền, mã QR.")
F("Hình 4.4 Trang quản lý đơn hàng",
  "Chèn ảnh chụp danh sách đơn theo trạng thái và thao tác cập nhật trạng thái.")
H3("4.2.2  Phân hệ AI tư vấn")
F("Hình 4.5 Cảnh báo xung đột hoạt chất trong giỏ hàng",
  "Chèn ảnh chụp trang giỏ có khối cảnh báo (ví dụ retinol và BHA) và gợi ý bước còn thiếu.")
F("Hình 4.6 Chatbot Syna tư vấn sản phẩm và thêm vào giỏ hàng",
  "Chèn ảnh chụp hội thoại: thẻ sản phẩm, thẻ xác nhận đơn, ô nhập OTP.")
F("Hình 4.7 Thẻ phân tích ảnh nhãn thành phần",
  "Chèn ảnh chụp thẻ phân tích: hoạt chất chính, mức phù hợp, gợi ý tách buổi và sản phẩm thay thế.")
H3("4.2.3  Phân hệ AI Idol")
F("Hình 4.8 Studio AI Idol giao việc tạo video cho Syna",
  "Chèn ảnh chụp Studio: danh sách công việc, tiến độ từng bước, xem trước kịch bản.")
F("Hình 4.9 Phòng livestream của Syna",
  "Chèn ảnh chụp video trên trang chi tiết và phòng livestream có bình luận, sản phẩm ghim.")

# ================= 4.3 =================
H2("4.3  Triển khai hệ thống")
P("Hệ thống được thiết kế để triển khai trên một máy chủ ảo chạy Ubuntu bằng Docker Compose. "
  "Khi đo trên máy phát triển, toàn bộ các container dùng khoảng 6,3 GB bộ nhớ, nên máy chủ cần tối thiểu 8 GB RAM và 4 nhân CPU. "
  "Caddy đứng trước Nginx để tự cấp chứng chỉ HTTPS, còn các cổng của dịch vụ nội bộ được đóng khỏi Internet.")
P("Quy trình tích hợp và triển khai liên tục được xây dựng bằng GitHub Actions. "
  "Mỗi lần đẩy mã, hệ thống tự kiểm tra cú pháp PHP, biên dịch mã Python và kiểm tra cấu hình Docker Compose. "
  "Khi mã được đẩy lên nhánh chính và qua kiểm tra, GitHub Actions kết nối tới máy chủ, kéo mã mới, dựng lại các container thay đổi và kiểm tra trang chủ đã hoạt động. "
  "Tại thời điểm viết báo cáo, cấu hình triển khai đã sẵn sàng nhưng hệ thống chưa được đưa lên máy chủ thật.")
F("Hình 4.10 Quy trình tích hợp và triển khai liên tục",
  "Vẽ: Lập trình viên git push → GitHub Actions: kiểm tra (php -l, compileall, compose config) → [đạt và nhánh main] SSH vào VPS → deploy.sh: git pull → build → restart → kiểm tra trang chủ.")

# ================= 4.4 =================
H2("4.4  Kiểm thử")
H3("4.4.1  Kiểm thử tự động")
P("Mỗi dịch vụ có bộ kiểm thử đơn vị và kiểm thử tích hợp riêng, chạy được mà không ghi vào cơ sở dữ liệu thật. "
  "Các kết nối tới cơ sở dữ liệu và khóa API được để trống khi chạy kiểm thử, nên những test vô tình truy cập dữ liệu thật sẽ báo lỗi thay vì ghi dữ liệu. "
  "Bảng 4.2 tổng hợp kết quả các lượt chạy gần nhất.")
T("Bảng 4.2 Kết quả kiểm thử tự động",
  ["Phạm vi", "Ngày chạy", "Kết quả"],
  [["Dịch vụ AI: tìm kiếm, đồng bộ đồ thị, gợi ý, gắn nhãn, vận hành", "27/10/2026", "633 đạt, 0 lỗi"],
   ["Dịch vụ AI Idol", "03/11/2026", "890 đạt, 0 lỗi, 181 kiểm thử con đạt"],
   ["Công cụ đồ thị của chatbot", "12/11/2026", "78 kiểm thử; 32 câu đánh giá đúng định tuyến"],
   ["Phân tích giỏ hàng (Python và PHP)", "23/10/2026", "26 + 12 + 7 test Python, 37 test PHP đạt"],
   ["Kiểm thử đầu cuối bằng Playwright: giỏ hàng trong khung chat", "24/11/2026", "8/8 bước kiểm tra đạt"]],
  [7.5, 2.8, 5.2], size=10.5)
P("Bảng 4.2 cho thấy các thành phần quan trọng đều có kiểm thử tự động với tỉ lệ đạt hoàn toàn ở lượt chạy gần nhất. "
  "Tuy vậy, một số test tích hợp cần Neo4j thật được bỏ qua khi chạy trên máy không có Neo4j. "
  "Quy trình tạo video đầy đủ tới bước tải lên kho lưu trữ chưa có kiểm thử đầu cuối tự động.")
H3("4.4.2  Kiểm thử chức năng")
P("Bên cạnh kiểm thử đơn vị, nhóm kiểm tra các chức năng theo tác nhân bằng ba cách: kiểm tra tĩnh đối chiếu use case với mã nguồn, gọi thử các trang quản trị qua HTTP và chạy kịch bản trên trình duyệt thật. "
  "Nhóm cũng soạn 24 ca kiểm thử cho khách hàng và 20 ca cho nhân viên, quản trị viên, mỗi ca gồm mã, mô tả, dữ liệu vào, các bước và kết quả mong đợi. "
  "Bảng 4.3 tổng hợp kết quả các lượt kiểm tra đã chạy, danh sách ca kiểm thử chi tiết được trình bày ở Phụ lục.")
T("Bảng 4.3 Tổng hợp kiểm thử chức năng",
  ["Phạm vi", "Ngày chạy", "Số kiểm tra", "Đạt", "Không đạt"],
  [["Nhân viên, quản trị viên: đối chiếu use case với route, quyền và controller", "28/09/2026", "310", "310", "0"],
   ["Quản trị viên: gọi thử các trang quản trị qua HTTP", "28/09/2026", "74", "74", "0"],
   ["Khách vãng lai: giỏ hàng trong khung chat trên trình duyệt", "24/11/2026", "8", "8", "0"],
   ["**Tổng**", "", "**392**", "**392**", "**0**"]],
  [7, 2.6, 2.2, 1.8, 2])

# ================= 4.5 =================
H2("4.5  Đánh giá các thành phần AI")
P("Phần này đánh giá định lượng bốn thành phần AI chính: bộ định tuyến ý định, công cụ đồ thị của chatbot, truy xuất RAG và mô hình gợi ý cộng tác. "
  "Mỗi đánh giá dùng một bộ dữ liệu thử nghiệm cố định để có thể chạy lại và so sánh sau khi cải tiến. "
  "Các độ đo được định nghĩa ở mục 2.1 và mục 2.2.")

H3("4.5.1  Bộ định tuyến ý định của chatbot")
P("Bộ định tuyến được đánh giá trên 40 câu hỏi có gán nhãn ý định đúng, bao phủ đủ mười nhãn ý định. "
  "Kết quả đạt độ chính xác tổng thể 92,5%, trong đó các câu tra cứu đơn hàng đạt 100%. "
  "Bảng 4.4 trình bày Precision và Recall của từng nhãn.")
T("Bảng 4.4 Precision và Recall của bộ định tuyến theo từng ý định",
  ["Ý định", "Precision", "Recall", "TP", "FP", "FN"],
  [["ADVISE", "0,857", "1,000", "6", "1", "0"],
   ["BUY", "1,000", "0,813", "13", "0", "3"],
   ["CART", "0,667", "1,000", "2", "1", "0"],
   ["KNOWLEDGE", "1,000", "1,000", "2", "0", "0"],
   ["ORDER_STATUS", "1,000", "1,000", "3", "0", "0"],
   ["OUT_OF_SCOPE", "1,000", "1,000", "1", "0", "0"],
   ["PRODUCT_INFO", "0,800", "1,000", "4", "1", "0"],
   ["SKIN_SURVEY", "1,000", "1,000", "2", "0", "0"],
   ["SMALLTALK", "1,000", "1,000", "2", "0", "0"],
   ["STORE_SUPPORT", "1,000", "1,000", "3", "0", "0"]],
  [4, 2.3, 2.3, 2, 2, 2], size=10.5)
P("Điểm yếu chính là nhãn BUY với Recall 0,813, nghĩa là 3 trong 16 câu muốn mua bị phân loại sang ý định khác như tư vấn hoặc giỏ hàng. "
  "Các nhãn ADVISE, CART và PRODUCT_INFO có vài trường hợp dương tính giả, chủ yếu do câu hỏi vừa hỏi thông tin vừa có ý muốn mua. "
  "Vì bộ dữ liệu chỉ có 40 câu, một số nhãn chỉ có 1 đến 3 mẫu, nên Precision và Recall của các nhãn này chưa đủ để kết luận chắc chắn.")

H3("4.5.2  Công cụ đồ thị của chatbot")
P("Công cụ đồ thị giúp chatbot trả lời các câu hỏi có nhiều điều kiện, như \"kem dưỡng cho da khô, không cồn, dưới 400 nghìn\". "
  "Bộ đánh giá gồm 32 câu: 27 câu phải được chuyển sang công cụ đồ thị và 5 câu không được chuyển. "
  "Mỗi câu có danh sách ràng buộc như danh mục, loại da, thành phần cần tránh, hãng và khoảng giá.")
T("Bảng 4.5 Kết quả đánh giá công cụ đồ thị (32 câu)",
  ["Chỉ số", "Lần thử 1", "Sau lần thử 2"],
  [["Định tuyến đúng", "32/32", "32/32"],
   ["Câu đạt toàn bộ ràng buộc", "28/32", "31/32"],
   ["Ràng buộc đạt", "352/356", "427/428"],
   ["Câu quá thời hạn, chuyển sang RAG", "4", "1"],
   ["Sản phẩm trả về vi phạm ràng buộc", "0", "0"]],
  [7, 4, 4.5])
P("Kết quả quan trọng nhất là không có sản phẩm nào được trả về sai điều kiện, như sai loại da, chứa thành phần cần tránh hay vượt ngân sách. "
  "Các ràng buộc không đạt đều do truy vấn Neo4j vượt hạn 600 ms nên hệ thống chuyển sang luồng RAG. "
  "Thời gian xử lý của công cụ có trung vị khoảng 171 ms và phân vị 95 là 828 ms, còn kiểm tra xung đột chỉ mất dưới 1 ms vì dùng luật.")

H3("4.5.3  Truy xuất và sinh câu trả lời (RAG)")
P("Nhóm so sánh ba cấu hình truy xuất trên cùng 30 câu hỏi tư vấn: chỉ tìm theo vector, kết hợp BM25 với vector bằng RRF, và kết hợp thêm bước sắp xếp lại bằng cross-encoder. "
  "Các độ đo truy xuất được tính theo nhãn sinh tự động từ thuộc tính sản phẩm. "
  "Các độ đo RAGAS do mô hình ngôn ngữ đóng vai giám khảo chấm, với nhiệt độ bằng 0.")
T("Bảng 4.6 So sánh ba cấu hình truy xuất (30 câu)",
  ["Chỉ số", "Chỉ vector", "BM25 + vector (RRF)", "RRF + rerank"],
  [["Hit@5", "0,000", "0,200", "0,233"],
   ["MRR@10", "0,000", "0,156", "0,239"],
   ["nDCG@10", "0,000", "0,063", "0,080"],
   ["Precision@5", "0,000", "0,067", "0,093"],
   ["Recall@10", "0,000", "0,050", "0,050"],
   ["Context Precision (RAGAS)", "0,674", "0,422", "0,565"],
   ["Answer Relevancy (RAGAS)", "0,796", "0,735", "0,713"],
   ["Faithfulness (RAGAS)", "0,148", "0,335", "0,328"],
   ["Độ trễ truy xuất trung bình", "0,07 s", "0,15 s", "8,55 s"]],
  [5, 3, 3.8, 3.7], size=10.5)
P("Bảng 4.6 cho thấy tìm kiếm thuần vector không tìm đúng sản phẩm nào, ví dụ câu hỏi về sữa rửa mặt cho da dầu lại trả về son môi. "
  "Nhóm đã kiểm tra chỉ mục không bị hỏng, nên nguyên nhân nằm ở chất lượng của mô hình nhúng tĩnh với tiếng Việt chuyên ngành. "
  "Kết hợp BM25 giúp Hit@5 tăng lên 0,200 và Faithfulness tăng hơn gấp đôi, đây là lý do hệ thống dùng cấu hình RRF làm mặc định.")
P("Bước sắp xếp lại cải thiện thêm MRR nhưng làm độ trễ tăng lên khoảng 8,5 giây trên CPU, nên chưa được bật khi vận hành. "
  "Context Precision của cấu hình chỉ vector cao nhưng không đáng tin, vì biến thể độ đo này không dùng đáp án chuẩn. "
  "Nhìn chung, Faithfulness còn thấp, cho thấy câu trả lời đôi khi đưa thông tin ngoài ngữ cảnh truy xuất, đây là hướng cần cải thiện ở chương kết luận.")
P("Kết quả này có một số hạn chế cần lưu ý. "
  "Nhãn đúng được sinh tự động từ dữ liệu, chưa có người gán. "
  "Mỗi cấu hình chỉ chạy một lần, và câu trả lời được sinh bởi hai mô hình khác nhau do giới hạn hạn mức miễn phí.")

H3("4.5.4  Mô hình gợi ý cộng tác")
P("Các thuật toán lọc cộng tác cần nhiều dữ liệu hành vi thật, trong khi hệ thống mới vận hành nên dữ liệu còn ít. "
  "Vì vậy, nhóm đánh giá trên dữ liệu tương tác mô phỏng với giao thức giữ lại tương tác cuối cùng của mỗi người dùng làm tập kiểm tra. "
  "Tập ứng viên là toàn bộ khoảng 2.460 sản phẩm người dùng chưa tương tác, và kết quả được lấy trung bình qua ba hạt giống ngẫu nhiên.")
T("Bảng 4.7 Kết quả gợi ý cộng tác trên dữ liệu mô phỏng",
  ["Mô hình", "HitRate@10", "Precision@10", "Recall@10", "NDCG@10", "MRR@10"],
  [["Ngẫu nhiên", "0,0076", "0,0008", "0,0076", "0,0038", "0,0027"],
   ["Phổ biến nhất", "0,0469", "0,0047", "0,0469", "0,0218", "0,0143"],
   ["Item-kNN", "0,0068", "0,0007", "0,0068", "0,0038", "0,0028"],
   ["Funk MF", "0,0166", "0,0017", "0,0166", "0,0074", "0,0047"],
   ["BPR", "0,0469", "0,0047", "0,0469", "0,0225", "0,0149"]],
  [3.5, 2.4, 2.4, 2.4, 2.4, 2.4], size=10.5)
P("BPR đạt NDCG@10 và MRR@10 cao nhất, nhưng khoảng tin cậy 95% bằng bootstrap của BPR và mô hình phổ biến nhất chồng lấn nhau. "
  "Do đó, nhóm chưa đủ bằng chứng để khẳng định BPR tốt hơn mô hình phổ biến nhất. "
  "Các số liệu này chỉ phản ánh hành vi thuật toán trên dữ liệu mô phỏng, chưa thể hiện chất lượng gợi ý với khách hàng thật.")
P("Kết quả trên là lý do hệ thống hiện ưu tiên gợi ý dựa trên hồ sơ da, nội dung sản phẩm và đồ thị tri thức thay vì lọc cộng tác. "
  "Các kỹ thuật này không cần nhiều lịch sử mua hàng nên phù hợp với cửa hàng mới. "
  "Khi dữ liệu hành vi thật đủ lớn, nhóm sẽ đánh giá lại trên dữ liệu thật trước khi đưa lọc cộng tác vào vận hành.")

H3("4.5.5  Hiệu năng phân tích giỏ hàng")
P("Phân tích giỏ hàng được đo độ trễ trong môi trường container với dữ liệu thật. "
  "Khi gửi yêu cầu tuần tự, độ trễ ở trung vị, phân vị 95 và phân vị 99 lần lượt là 36, 46 và 59 ms. "
  "Với 4 yêu cầu đồng thời, các giá trị tương ứng là 110, 133 và 135 ms, vẫn thấp hơn nhiều so với giới hạn 1 giây mà máy chủ PHP đặt ra.")

# ================= 4.6 =================
H2("4.6  Khảo sát mức độ hài lòng của người dùng")
P("Để đánh giá hệ thống từ góc nhìn người dùng, nhóm mời [[N]] người dùng thử hệ thống và trả lời phiếu khảo sát theo thang Likert 5 mức. "
  "Phiếu gồm các nhận định về tính dễ dùng, độ hữu ích của gợi ý, chất lượng tư vấn của chatbot, cảnh báo xung đột và video của Syna. "
  "Điểm trung bình của mỗi nhận định được diễn giải theo các khoảng có độ rộng 0,8 như đã trình bày ở mục 2.7.")
T("Bảng 4.8 Kết quả khảo sát mức độ hài lòng",
  ["STT", "Nhận định", "Điểm TB", "Mức đánh giá"],
  [["1", "Giao diện dễ sử dụng, dễ tìm sản phẩm.", "[[...]]", "[[...]]"],
   ["2", "Sản phẩm được gợi ý phù hợp với làn da của tôi.", "[[...]]", "[[...]]"],
   ["3", "Lý do gợi ý giúp tôi tin tưởng hơn khi mua.", "[[...]]", "[[...]]"],
   ["4", "Chatbot trả lời đúng và dễ hiểu.", "[[...]]", "[[...]]"],
   ["5", "Cảnh báo xung đột thành phần trong giỏ hàng hữu ích.", "[[...]]", "[[...]]"],
   ["6", "Tính năng đọc thành phần từ ảnh hữu ích.", "[[...]]", "[[...]]"],
   ["7", "Video của Syna giúp tôi hiểu sản phẩm hơn.", "[[...]]", "[[...]]"],
   ["8", "Tôi sẵn sàng tiếp tục sử dụng hệ thống.", "[[...]]", "[[...]]"]],
  [1.2, 8.8, 2.5, 3], size=10.5)
P("Kết quả cho thấy [[tóm tắt 2–3 nhận xét chính, ví dụ nhận định có điểm cao nhất và thấp nhất]]. "
  "[[Nêu nguyên nhân của nhận định điểm thấp và hướng cải thiện.]]")

# ================= 4.7 =================
H2("4.7  Tóm tắt chương")
P("Chương 4 đã trình bày kết quả cài đặt ba phân hệ, cách triển khai bằng Docker Compose kèm quy trình CI/CD và kết quả kiểm thử tự động. "
  "Bộ định tuyến ý định đạt độ chính xác 92,5% và công cụ đồ thị không trả về sản phẩm nào sai điều kiện. "
  "Ngược lại, chất lượng truy xuất RAG và lọc cộng tác còn hạn chế, chủ yếu do mô hình nhúng và lượng dữ liệu hành vi thật.")
P("Những kết quả và hạn chế này là cơ sở để nhóm rút ra kết luận và đề xuất hướng phát triển ở phần tiếp theo.")
PB()

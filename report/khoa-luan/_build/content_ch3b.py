# -*- coding: utf-8 -*-
# Chương 3, phần 3.4–3.8 (nối sau content_ch3.py)

# ================= 3.4 =================
H2("3.4  Thiết kế kiến trúc hệ thống")
H3("3.4.1  Kiến trúc tổng thể")
P("SkinSyntaxVN được thiết kế theo kiến trúc nhiều dịch vụ, tách phần web bán hàng khỏi các dịch vụ AI. "
  "Máy chủ PHP đóng vai trò cổng duy nhất cho trình duyệt (Backend for Frontend), xử lý phiên đăng nhập, phân quyền và nghiệp vụ bán hàng. "
  "Các dịch vụ AI viết bằng Python chạy trong mạng nội bộ, chỉ nhận yêu cầu từ máy chủ PHP kèm mã xác thực nội bộ. "
  "Cách tách này giúp mỗi phần được phát triển, mở rộng và khởi động lại độc lập.")
F("Hình 3.13 Sơ đồ kiến trúc tổng thể",
  "Vẽ 4 tầng từ trái sang phải. Tầng người dùng: Trình duyệt (khách, nhân viên, quản trị). Tầng cổng: Nginx. "
  "Tầng ứng dụng: PHP Backend (MVC) ↔ AI Service (chatbot LangGraph, Search Service, Home Service), AI Idol Service + Celery worker, Agent kịch bản CrewAI, Search worker, Catalog worker. "
  "Tầng dữ liệu: MongoDB Atlas, Neo4j, ChromaDB, Redis. Bên ngoài: nhà cung cấp LLM, dịch vụ email, cổng đối soát, kho lưu trữ R2. Ghi chú mũi tên: HTTP nội bộ + X-Internal-Token.")
P("Hình 3.13 cho thấy MongoDB Atlas là nguồn dữ liệu gốc duy nhất của hệ thống. "
  "Neo4j và ChromaDB chỉ là bản sao dẫn xuất phục vụ suy luận quan hệ và tìm kiếm ngữ nghĩa, có thể dựng lại từ MongoDB bất kỳ lúc nào. "
  "Khi dữ liệu sản phẩm thay đổi, máy chủ PHP ghi một sự kiện vào hàng đợi Redis để tiến trình nền cập nhật lại hai kho dẫn xuất này.")
H3("3.4.2  Phân rã thành phần")
P("Bảng 3.11 mô tả trách nhiệm của từng thành phần trong kiến trúc. "
  "Mỗi thành phần được đóng gói thành một container riêng và giao tiếp qua mạng nội bộ của Docker.")
T("Bảng 3.11 Các thành phần của hệ thống",
  ["Thành phần", "Công nghệ", "Trách nhiệm chính"],
  [["Nginx", "Nginx 1.27", "Nhận yêu cầu từ Internet, phục vụ tệp tĩnh và chuyển tiếp tới PHP."],
   ["PHP Backend", "PHP 8.2, MVC", "Giao diện, phiên đăng nhập, phân quyền, giỏ hàng, đơn hàng, khuyến mãi, quản trị; gọi các dịch vụ AI."],
   ["AI Service", "Python, Flask, LangGraph", "Chatbot tư vấn, tìm kiếm thông minh, gợi ý, sản phẩm tương tự, phân tích giỏ hàng, đọc thành phần từ ảnh."],
   ["Search worker, Catalog worker", "Python, Redis Streams", "Ghi nhật ký tìm kiếm theo lô; đồng bộ Neo4j, ChromaDB và chạy tác vụ định kỳ khi danh mục thay đổi."],
   ["AI Idol Service", "Python, Flask, Celery", "Quản lý công việc tạo video, chiến dịch livestream và lịch phát."],
   ["Agent kịch bản", "CrewAI", "Hai vai biên kịch và biên tập phối hợp viết kịch bản cho Syna."],
   ["MongoDB Atlas", "MongoDB", "Lưu toàn bộ dữ liệu nghiệp vụ."],
   ["Neo4j", "Neo4j 5.26", "Đồ thị tri thức sản phẩm, thành phần, loại da, xung đột."],
   ["ChromaDB", "ChromaDB 0.5.20", "Lưu vector của sản phẩm cho tìm kiếm ngữ nghĩa."],
   ["Redis", "Redis 7", "Bộ đệm, hàng đợi Celery, Redis Streams, giới hạn tần suất, trạng thái ngắt mạch."]],
  [3.6, 3.4, 8.5], size=10.5)
H3("3.4.3  Mô hình triển khai")
P("Toàn bộ hệ thống được mô tả trong một tệp Docker Compose, nên có thể dựng lại trên máy chủ mới bằng một lệnh. "
  "MongoDB dùng dịch vụ đám mây Atlas, còn các thành phần còn lại chạy trên cùng một máy chủ ảo. "
  "Video do Syna tạo ra được tải lên kho lưu trữ đối tượng để giảm tải cho máy chủ web. "
  "Các khóa bí mật như khóa API và chuỗi kết nối được đặt trong biến môi trường, không ghi vào mã nguồn.")
F("Hình 3.14 Sơ đồ triển khai",
  "Vẽ một nút Máy chủ ảo chứa các container: nginx, php-backend, ai-service, search-worker, catalog-worker, ai-idol-service, ai-idol-worker, ai-idol-beat, "
  "ai-idol-agent-service, neo4j, chromadb, redis (ghi volume dữ liệu bên dưới). Ngoài máy chủ: MongoDB Atlas, Cloudflare R2, nhà cung cấp LLM, dịch vụ email. "
  "Người dùng truy cập qua Internet → Nginx (cổng 80/443).")

# ================= 3.5 =================
H2("3.5  Thiết kế cơ sở dữ liệu")
H3("3.5.1  Cơ sở dữ liệu MongoDB")
P("MongoDB được chọn làm cơ sở dữ liệu chính vì dữ liệu mỹ phẩm có cấu trúc thay đổi giữa các loại sản phẩm. "
  "Ví dụ, bảng thành phần, công dụng và dung tích có độ dài và cách ghi rất khác nhau giữa các thương hiệu. "
  "Các thông tin luôn đi cùng nhau như chi tiết đơn hàng được lưu gần tài liệu cha để đọc trong ít lần truy vấn. "
  "Bảng 3.12 trình bày các collection chính và trường dữ liệu tiêu biểu.")
T("Bảng 3.12 Các collection chính trong MongoDB",
  ["Collection", "Nội dung", "Trường tiêu biểu"],
  [["san_pham", "Sản phẩm", "ma_san_pham, ten_san_pham, ma_thuong_hieu, ma_danh_muc, gia_ban, gia_thi_truong, thanh_phan_full, mo_ta, ma_loai_da, so_luong_ton_kho, so_luong_da_ban, diem_danh_gia"],
   ["danh_muc, thuong_hieu, xuat_xu, loai_da", "Danh mục tham chiếu", "mã, tên; danh mục có cấp và quan hệ cha con"],
   ["khach_hang, nhan_vien, vai_tro", "Tài khoản và phân quyền", "email, mật khẩu đã băm, họ tên, số điện thoại, điểm tích lũy, vai trò"],
   ["skin_profiles, skin_profile_history", "Hồ sơ da", "loại da, vấn đề da, ngân sách, hồ sơ đang chọn, lịch sử khảo sát"],
   ["hoa_don, chi_tiet_hoa_don", "Đơn hàng", "ma_hoa_don, ma_kh, trang_thai, hinh_thuc_thanh_toan, tam_tinh, so_tien_giam, phi_van_chuyen, tong_tien, ngay_dat"],
   ["voucher và các collection khuyến mãi", "Khuyến mãi", "mã giảm giá, mức giảm, điều kiện, thời gian hiệu lực, số lượt đã dùng"],
   ["inventory_batches, inventory_movements", "Tồn kho", "lô hàng, hạn sử dụng, số lượng, lịch sử nhập xuất"],
   ["danh_gia_san_pham, hoi_dap_san_pham", "Đánh giá và hỏi đáp", "sản phẩm, khách hàng, số sao, nội dung, trạng thái duyệt, phản hồi"],
   ["lich_su_chat, thong_bao", "Tin nhắn và thông báo", "người gửi, nội dung, đã đọc, thời gian"],
   ["tuong_tac_nguoi_dung, search_events", "Hành vi", "xem, thêm giỏ, mua, từ khóa tìm kiếm, lượt nhấp"],
   ["phien_live, phien_live_chats", "Livestream", "phiên, sản phẩm ghim, ưu đãi, bình luận, cảm xúc, người xem"],
   ["ai_idol_jobs, ai_idol_campaigns, ai_product_videos", "AI Idol", "trạng thái từng bước, kịch bản, đường dẫn video, lịch phát, trạng thái hiển thị"]],
  [4.2, 3.2, 8.1], size=10)
F("Hình 3.15 Lược đồ quan hệ giữa các collection chính",
  "Vẽ dạng sơ đồ thực thể: san_pham n–1 danh_muc, thuong_hieu, xuat_xu, loai_da; khach_hang 1–n hoa_don; hoa_don 1–n chi_tiet_hoa_don n–1 san_pham; "
  "khach_hang 1–n skin_profiles; san_pham 1–n danh_gia_san_pham, hoi_dap_san_pham, inventory_batches; hoa_don n–1 voucher; san_pham 1–n ai_product_videos.")
H3("3.5.2  Đồ thị tri thức Neo4j")
P("Đồ thị tri thức biểu diễn các quan hệ mà cơ sở dữ liệu dạng tài liệu khó truy vấn hiệu quả, như hai sản phẩm có chung bao nhiêu thành phần. "
  "Mỗi sản phẩm được nối với thương hiệu, danh mục, thành phần, loại da phù hợp, vấn đề da và công dụng. "
  "Quan hệ chứa thành phần lưu thêm thứ hạng của thành phần trong bảng, giúp phân biệt hoạt chất chính với thành phần phụ.")
T("Bảng 3.13 Các nút và quan hệ trong đồ thị tri thức",
  ["Loại", "Tên", "Ý nghĩa"],
  [["Nút", "Product, Brand, Category, Ingredient, SkinType, Concern, Benefit, Origin", "Sản phẩm và các thực thể mô tả sản phẩm"],
   ["Quan hệ", "OF_BRAND, IN_CATEGORY, CHILD_OF", "Thương hiệu; danh mục lá và cây danh mục"],
   ["Quan hệ", "CONTAINS {rank}", "Sản phẩm chứa thành phần, kèm thứ hạng trong bảng thành phần"],
   ["Quan hệ", "SUITABLE_FOR, TARGETS, HAS_BENEFIT", "Hợp loại da, nhắm vấn đề da, công dụng; có ghi nguồn dữ liệu"],
   ["Quan hệ", "CONFLICTS_WITH {severity, reason}", "Hai thành phần không nên dùng chung"],
   ["Quan hệ", "SIMILAR_TO {score}", "Sản phẩm tương tự, tính trước theo độ trùng thành phần và thuộc tính"],
   ["Quan hệ", "CO_PURCHASED {count}", "Hai sản phẩm thường được mua cùng đơn"]],
  [2.2, 6, 7.3], size=10.5)
F("Hình 3.16 Lược đồ đồ thị tri thức sản phẩm",
  "Nút Product ở giữa, nối ra Brand (OF_BRAND), Category (IN_CATEGORY; Category–CHILD_OF→Category), SkinType (SUITABLE_FOR), Concern (TARGETS), Benefit (HAS_BENEFIT), "
  "Ingredient (CONTAINS{rank}); Ingredient–HELPS→Concern; Ingredient–CONFLICTS_WITH–Ingredient; Product–SIMILAR_TO/CO_PURCHASED→Product.")
H3("3.5.3  Cơ sở dữ liệu vector và bộ nhớ đệm")
P("ChromaDB lưu vector của từng sản phẩm, được tạo từ tên, thành phần, công dụng và loại da bằng mô hình nhúng đa ngôn ngữ. "
  "Hệ thống dùng hai collection: products phục vụ chatbot và AI Idol, catalog_products_v1 phục vụ tìm kiếm thông minh. "
  "Redis vừa lưu đệm kết quả gợi ý và phân tích giỏ hàng, vừa làm hàng đợi cho Celery và Redis Streams, đếm giới hạn tần suất và lưu trạng thái ngắt mạch. "
  "Bộ đệm được đánh số theo thế hệ dữ liệu, nên khi danh mục thay đổi, chỉ cần tăng số thế hệ là toàn bộ kết quả cũ hết hiệu lực.")

# ================= 3.6 =================
H2("3.6  Thiết kế các thành phần AI")
H3("3.6.1  Hệ thống gợi ý")
P("Hệ thống gợi ý kết hợp nhiều kỹ thuật, mỗi kỹ thuật phục vụ một vị trí hiển thị khác nhau. "
  "Với khách chưa có dữ liệu, hệ thống xếp hạng sản phẩm bằng điểm đánh giá có trọng số Bayes. "
  "Công thức này tránh trường hợp sản phẩm chỉ có vài đánh giá 5 sao vượt lên trên sản phẩm có hàng trăm đánh giá tốt.")
EQ("WR = v/(v + m) · R + m/(v + m) · C", "3.1")
P("Trong công thức 3.1, R là điểm trung bình và v là số đánh giá của sản phẩm. "
  "C là điểm trung bình của toàn bộ sản phẩm, còn m là ngưỡng số đánh giá tối thiểu, được chọn bằng phân vị 75 của số đánh giá. "
  "Chỉ sản phẩm có số đánh giá từ m trở lên mới được đưa vào danh sách này.")
P("Với khách đã đăng nhập, hệ thống dùng mô hình lai gồm điểm hành vi và điểm hồ sơ. "
  "Điểm hành vi tổng hợp thao tác thêm giỏ, xem, tìm kiếm và mua với trọng số lần lượt 0,35; 0,35; 0,20 và 0,10. "
  "Điểm hồ sơ gồm 0,60 độ hợp loại da, 0,25 độ tương đồng nội dung TF-IDF và 0,15 độ hợp ngân sách.")
EQ("Score = 0,55 · S_hành_vi + 0,45 · S_hồ_sơ", "3.2")
P("Các trọng số trong công thức 3.2 là giá trị khởi đầu do nhóm thiết lập, sẽ được đánh giá lại bằng các độ đo ở chương 4. "
  "Sản phẩm tương tự được lấy từ quan hệ SIMILAR_TO trong đồ thị tri thức, sau đó xếp lại theo cùng danh mục, lượt bán, tình trạng còn hàng và độ hợp loại da. "
  "Tìm kiếm thông minh chạy năm nhánh song song và trộn theo trọng số 0,35 đồ thị, 0,30 ngữ nghĩa, 0,20 độ phổ biến và 0,15 cá nhân hóa.")
H3("3.6.2  Chatbot điều phối bằng LangGraph")
P("Chatbot được thiết kế thành đồ thị trạng thái gồm bốn nút: route, legacy, agent và finish. "
  "Nút route dùng mô hình ngôn ngữ để tách tin nhắn thành một hàng đợi ý định, rồi chuyển từng ý định tới nút phù hợp. "
  "Sau mỗi ý định, đồ thị kiểm tra lại hàng đợi cho tới khi hết thì chuyển sang nút finish.")
T("Bảng 3.14 Phân loại ý định và nút xử lý",
  ["Nhóm", "Ý định", "Nút xử lý", "Cần đăng nhập"],
  [["Kiến thức và hỗ trợ", "KNOWLEDGE, STORE_SUPPORT, SKIN_SURVEY, SMALLTALK, OUT_OF_SCOPE", "legacy (RAG)", "Không"],
   ["Tư vấn sản phẩm", "ADVISE, PRODUCT_INFO", "agent", "Không"],
   ["Giao dịch", "BUY, CART, ORDER_STATUS", "agent", "Có"]],
  [3.2, 6.3, 3, 3], size=10.5)
P("Nút agent được cấp mười một công cụ, gồm tìm, xem và so sánh sản phẩm, kiểm tra xung đột, xem và sửa giỏ, lấy thông tin giao hàng, lập bản nháp đơn, đặt lại đơn cũ và tra cứu trạng thái đơn. "
  "Mọi công cụ thay đổi dữ liệu đều gọi qua máy chủ PHP để dùng chung quy tắc nghiệp vụ với website. "
  "Agent chỉ được lập bản nháp đơn, còn việc tạo đơn thật luôn cần khách xác nhận bằng OTP.")
F("Hình 3.17 Đồ thị trạng thái của chatbot",
  "START → route → (điều kiện next_node) → legacy | agent | finish; legacy → next_node; agent → next_node; finish → END. "
  "Ghi chú: next_node chọn finish khi bị dừng hoặc hết hàng đợi ý định.")
H3("3.6.3  Truy xuất và sinh câu trả lời (RAG)")
P("Với các câu hỏi kiến thức, chatbot dùng kỹ thuật RAG để câu trả lời bám vào dữ liệu thật của cửa hàng. "
  "Câu hỏi được tìm song song bằng BM25 theo từ khóa và bằng vector trong ChromaDB, sau đó hai danh sách được hợp nhất bằng Reciprocal Rank Fusion với k bằng 60. "
  "Các đoạn văn bản tốt nhất được đưa vào lời nhắc, và mô hình ngôn ngữ chỉ được trả lời dựa trên các đoạn này.")
P("Để chatbot luôn sẵn sàng, hệ thống dùng một nhóm nhà cung cấp mô hình ngôn ngữ theo thứ tự ưu tiên. "
  "Khi nhà cung cấp đầu tiên lỗi hoặc hết hạn mức, yêu cầu được chuyển sang nhà cung cấp kế tiếp. "
  "Câu trả lời được gửi về trình duyệt dạng luồng để người dùng thấy chữ xuất hiện ngay.")
H3("3.6.4  Kiểm tra xung đột hoạt chất")
P("Bộ luật xung đột được lưu trong một tệp cấu hình gồm mười một luật, tương ứng năm mươi lăm cặp thành phần theo tên INCI. "
  "Mỗi luật có mức độ, thời điểm nên tách, lời khuyên tiếng Việt và nguồn tham khảo. "
  "Luật được nạp vào Neo4j thành quan hệ CONFLICTS_WITH, nên trang giỏ hàng và tính năng đọc ảnh nhãn dùng chung một nguồn.")
P("Để giảm cảnh báo sai, một hoạt chất chỉ được tính khi nằm trong nhóm thành phần đầu của bảng thành phần. "
  "Sản phẩm rửa trôi như sữa rửa mặt được hạ một mức độ vì thời gian tiếp xúc với da ngắn. "
  "Việc phán đoán xung đột hoàn toàn dựa trên luật, không dùng mô hình ngôn ngữ, để kết quả nhất quán và kiểm thử được.")
H3("3.6.5  Quy trình sinh nội dung AI Idol")
P("Quy trình AI Idol được thiết kế thành chuỗi bước có lưu trạng thái, mỗi bước ghi kết quả trung gian vào công việc trong MongoDB. "
  "Kịch bản được sinh từ dữ liệu sản phẩm và kiến thức truy xuất, theo khung bảy phần cho video và tám phần cho livestream. "
  "Sau bước kiểm tra, kịch bản được chuyển thành giọng đọc, dựng hình Syna, trộn nhạc nền và chuẩn hóa âm lượng bằng FFmpeg.")
T("Bảng 3.15 Các bước của quy trình tạo video AI Idol",
  ["Bước", "Xử lý", "Kết quả"],
  [["1", "Thu thập dữ liệu sản phẩm và giá", "Ảnh chụp dữ liệu sản phẩm trong công việc"],
   ["2", "Truy xuất kiến thức bổ trợ từ ChromaDB", "Đoạn kiến thức về hoạt chất"],
   ["3", "Sinh kịch bản (biên kịch và biên tập)", "Bản nháp kịch bản theo khung"],
   ["4", "Kiểm tra với dữ liệu gốc, lọc từ cấm, tự sửa", "Kịch bản hợp lệ"],
   ["5", "Duyệt tự động hoặc thủ công", "Kịch bản được duyệt"],
   ["6", "Chuyển văn bản thành giọng nói", "Tệp âm thanh và mốc thời gian"],
   ["7", "Dựng hình nhân vật Syna", "Video nhân vật"],
   ["8", "Trộn nhạc nền, chuẩn hóa âm lượng, cắt clip", "Video hoàn chỉnh"],
   ["9", "Tải lên kho lưu trữ và gắn vào trang sản phẩm", "Video hiển thị cho khách"]],
  [1.4, 8, 6.1], size=10.5)

# ================= 3.7 =================
H2("3.7  Thiết kế giao diện")
P("Trước khi lập trình, nhóm phác thảo giao diện dưới dạng khung dây (wireframe) để thống nhất bố cục và các thành phần chính. "
  "Giao diện theo hướng tối giản với tông màu xanh nhạt gợi cảm giác làm dịu da. "
  "Các màn hình ưu tiên hiển thị tốt trên điện thoại vì nhiều khách mua mỹ phẩm trực tuyến bằng điện thoại. "
  "Bảng 3.16 liệt kê các màn hình chính và điểm nhấn thiết kế.")
T("Bảng 3.16 Các màn hình chính",
  ["Màn hình", "Tác nhân", "Điểm nhấn thiết kế"],
  [["Trang chủ", "Khách", "Các khối gợi ý theo hồ sơ da, flash sale, video của Syna"],
   ["Danh sách và tìm kiếm", "Khách", "Bộ lọc loại da và vấn đề da, gợi ý khi gõ"],
   ["Chi tiết sản phẩm", "Khách", "Thành phần, ô \"Vì sao phù hợp\", video Syna, sản phẩm tương tự"],
   ["Khảo sát loại da", "Khách", "Câu hỏi từng bước, kết quả kèm giải thích"],
   ["Giỏ hàng", "Khách hàng", "Khối cảnh báo xung đột và gợi ý bước còn thiếu"],
   ["Thanh toán", "Khách hàng", "Voucher, điểm tích lũy, mã QR chuyển khoản"],
   ["Khung chat Syna", "Khách", "Thẻ sản phẩm, thẻ xác nhận đơn có OTP, thẻ phân tích ảnh nhãn"],
   ["Livestream", "Khách", "Video, bình luận, sản phẩm ghim, nút thêm vào giỏ"],
   ["Hồ sơ và thưởng", "Khách hàng", "Hồ sơ da, đơn hàng, điểm danh, voucher"],
   ["Bảng điều khiển quản trị", "Quản trị viên", "Doanh thu, đơn hàng, sản phẩm bán chạy"],
   ["Quản lý đơn hàng", "Nhân viên", "Lọc theo trạng thái, cập nhật nhanh, in đơn"],
   ["Studio AI Idol", "Quản trị viên", "Tạo video, theo dõi tiến độ, duyệt kịch bản, lên lịch chiến dịch"]],
  [4, 3, 8.5], size=10.5)
F("Hình 3.18 Thiết kế giao diện trang chi tiết sản phẩm",
  "Chèn wireframe: ảnh sản phẩm, giá, nút mua, ô Vì sao phù hợp, khối thành phần, video Syna, sản phẩm tương tự.")
F("Hình 3.19 Thiết kế giao diện giỏ hàng có cảnh báo xung đột",
  "Chèn wireframe: danh sách sản phẩm, khối cảnh báo (mức độ, lời khuyên), gợi ý bước còn thiếu, tổng tiền.")
F("Hình 3.20 Thiết kế giao diện khung chat Syna",
  "Chèn wireframe: hội thoại, thẻ sản phẩm, thẻ xác nhận đơn và ô nhập OTP, thẻ phân tích ảnh nhãn.")
F("Hình 3.21 Thiết kế giao diện Studio AI Idol",
  "Chèn wireframe: chọn sản phẩm, chế độ duyệt, tiến độ các bước, xem trước kịch bản và video.")

# ================= 3.8 =================
H2("3.8  Tóm tắt chương")
P("Chương 3 đã phân tích yêu cầu qua bốn tác nhân, hai mươi bảy use case và tám bảng đặc tả chi tiết. "
  "Các quy trình nghiệp vụ và tương tác giữa thành phần được mô tả bằng sơ đồ hoạt động và sơ đồ tuần tự. "
  "Phần thiết kế đã trình bày kiến trúc nhiều dịch vụ, cơ sở dữ liệu MongoDB, đồ thị tri thức Neo4j, các thành phần AI và giao diện.")
P("Dựa trên bản thiết kế này, chương 4 sẽ trình bày kết quả cài đặt, triển khai và đánh giá hệ thống. "
  "Phần đánh giá dùng các độ đo định lượng cho gợi ý và chatbot, kết hợp khảo sát mức độ hài lòng của người dùng.")
PB()

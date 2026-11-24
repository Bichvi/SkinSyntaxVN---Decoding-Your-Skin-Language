# -*- coding: utf-8 -*-
H1("KẾT LUẬN VÀ HƯỚNG PHÁT TRIỂN")
P("Phần này tổng kết những gì nhóm đã đạt được so với mục tiêu đặt ra ở chương 1. "
  "Nhóm cũng nêu thẳng các hạn chế còn tồn tại, dựa trên kết quả đánh giá ở chương 4. "
  "Cuối cùng là các hướng phát triển cụ thể, được sắp theo mức độ ưu tiên.")

H2("1.  Kết quả đạt được")
P("Về tổng thể, nhóm đã xây dựng được nền tảng thương mại điện tử mỹ phẩm có khả năng tư vấn theo làn da, đáp ứng mục tiêu tổng quát của đề tài. "
  "Hệ thống gồm ba phân hệ thương mại điện tử, AI tư vấn và AI Idol, phục vụ bốn nhóm tác nhân với đầy đủ nghiệp vụ từ mua hàng tới quản trị. "
  "Bảng KL.1 đối chiếu từng mục tiêu cụ thể với kết quả đạt được.")
T("Bảng KL.1 Đối chiếu mục tiêu và kết quả",
  ["Mục tiêu cụ thể", "Kết quả", "Mức độ"],
  [["Website bán hàng cho bốn tác nhân", "Đã cài đặt đầy đủ các nhóm chức năng ở Bảng 1.5", "Hoàn thành"],
   ["Hệ thống gợi ý lai theo hồ sơ da, nội dung, hành vi và đồ thị tri thức", "Có xếp hạng Bayes, mô hình lai, sản phẩm tương tự, tìm kiếm thông minh năm nhánh, kèm lý do phù hợp", "Hoàn thành"],
   ["Đồ thị tri thức sản phẩm trên Neo4j", "Mô hình hóa sản phẩm, thành phần, loại da, vấn đề da và 55 cặp xung đột hoạt chất", "Hoàn thành"],
   ["Chatbot RAG, tìm kiếm lai, điều phối bằng LangGraph", "Chatbot bốn nút, mười ý định, mười một công cụ, đặt hàng có OTP; bộ định tuyến đạt 92,5%", "Hoàn thành"],
   ["AI Idol Syna sinh kịch bản, giọng nói và video", "Quy trình chín bước có kiểm tra kịch bản, duyệt tự động hoặc thủ công, livestream trên website", "Hoàn thành"],
   ["Đánh giá bằng độ đo định lượng và khảo sát Likert", "Đã đo định tuyến, công cụ đồ thị, RAGAS và gợi ý cộng tác; khảo sát nhu cầu 56 người; khảo sát hài lòng sau thử nghiệm chưa có kết quả", "Hoàn thành một phần"],
   ["Triển khai lên máy chủ thật bằng Docker Compose", "Đã có cấu hình triển khai và CI/CD, chưa đưa lên máy chủ thật", "Hoàn thành một phần"]],
  [5.2, 7.8, 2.5], size=10.5)
P("Bên cạnh các chức năng, nhóm rút ra ba điểm khác biệt chính của SkinSyntaxVN so với các nền tảng hiện có. "
  "Thứ nhất, mỗi gợi ý đều kèm lý do phù hợp, giúp khách hiểu vì sao sản phẩm được đề xuất. "
  "Thứ hai, giỏ hàng và ảnh nhãn được kiểm tra xung đột hoạt chất bằng bộ luật có nguồn, không phụ thuộc vào mô hình ngôn ngữ. "
  "Thứ ba, nhân vật ảo Syna tạo nội dung giới thiệu sản phẩm từ chính dữ liệu của cửa hàng, giúp giảm chi phí làm video.")
P("Về mặt kỹ thuật, hệ thống được thiết kế để chịu lỗi từng phần. "
  "Khi Neo4j, dịch vụ AI hay nhà cung cấp mô hình ngôn ngữ gặp sự cố, các chức năng hạ cấp sang phương án dự phòng thay vì báo lỗi cho khách. "
  "Các dịch vụ chính đều có kiểm thử tự động, với hơn 1.500 kiểm thử đạt ở các lượt chạy gần nhất.")

H2("2.  Hạn chế")
P("Dù đạt được các mục tiêu chính, hệ thống vẫn còn một số hạn chế được nhóm ghi nhận qua quá trình đánh giá. "
  "Các hạn chế này được nhóm theo chất lượng AI, dữ liệu và vận hành.")
B("**Chất lượng truy xuất RAG còn thấp:** với 30 câu thử nghiệm, Hit@5 của cấu hình tốt nhất chỉ đạt 0,233 và Faithfulness khoảng 0,33. Nguyên nhân chính là mô hình nhúng tĩnh xử lý kém tiếng Việt chuyên ngành mỹ phẩm.",
  "**Thiếu dữ liệu hành vi thật:** các thuật toán lọc cộng tác mới được đánh giá trên dữ liệu mô phỏng và chưa vượt trội rõ ràng so với gợi ý theo độ phổ biến, nên chưa được đưa vào vận hành.",
  "**Bộ dữ liệu đánh giá còn nhỏ:** bộ định tuyến được đo trên 40 câu, nhãn đúng của bộ RAG được sinh tự động và chưa có người gán.",
  "**Bộ luật xung đột cần chuyên gia duyệt:** các ngưỡng như thứ hạng thành phần và việc hạ mức cho sản phẩm rửa trôi do nhóm tự đặt. Chatbot và trang giỏ hàng còn dùng hai bộ luật khác nhau.",
  "**Phụ thuộc dịch vụ bên ngoài:** chatbot, đọc ảnh nhãn và AI Idol dùng hạn mức miễn phí của các nhà cung cấp mô hình ngôn ngữ, nên có thể chậm hoặc tạm ngưng khi hết hạn mức.",
  "**Hạ tầng vận hành còn đơn giản:** phí vận chuyển cố định, chưa tích hợp đơn vị vận chuyển và cổng thanh toán trực tuyến, việc phát livestream lên nền tảng bên ngoài chưa được thử nghiệm.")

H2("3.  Hướng phát triển")
P("Từ các hạn chế trên, nhóm đề xuất hướng phát triển theo ba mức ưu tiên. "
  "Các hướng ở mức ngắn hạn giải quyết trực tiếp những điểm yếu đã đo được ở chương 4.")
H3("3.1  Ngắn hạn")
B("Thay mô hình nhúng tĩnh bằng mô hình nhúng đa ngôn ngữ mạnh hơn như bge-m3 hoặc multilingual-e5, rồi đánh giá lại bằng chính bộ 30 câu hỏi để so sánh công bằng.",
  "Gán nhãn thủ công một phần bộ câu hỏi RAG và mở rộng bộ câu hỏi của bộ định tuyến, tập trung vào nhãn BUY có Recall thấp nhất.",
  "Hợp nhất kiểm tra xung đột của chatbot về cùng bộ luật với trang giỏ hàng, và mời dược sĩ hoặc chuyên gia da liễu duyệt bộ luật.",
  "Hoàn tất triển khai lên máy chủ thật và đo hiệu năng dưới tải thực tế.")
H3("3.2  Trung hạn")
B("Thu thập dữ liệu hành vi thật trong thời gian vận hành, sau đó đánh giá lại lọc cộng tác trên dữ liệu thật trước khi kết hợp vào mô hình lai.",
  "Bật bước sắp xếp lại bằng cross-encoder khi có máy chủ GPU, vì bước này đã cải thiện MRR nhưng hiện quá chậm trên CPU.",
  "Tích hợp đơn vị vận chuyển để tính phí theo khoảng cách và theo dõi hành trình, cùng cổng thanh toán trực tuyến.",
  "Thử nghiệm phát livestream của Syna lên YouTube và Facebook bằng phần đẩy luồng đã có sẵn trong hệ thống.")
H3("3.3  Dài hạn")
B("Phát triển ứng dụng di động để gửi thông báo nhắc quy trình chăm sóc da và theo dõi tiến triển của làn da.",
  "Mở rộng thành nền tảng cho nhiều cửa hàng, mỗi cửa hàng có dữ liệu và nhân vật AI Idol riêng.",
  "Nghiên cứu phân tích ảnh da mặt để hỗ trợ xác định loại da, với điều kiện có sự đồng ý của người dùng và không thay thế chẩn đoán của bác sĩ.")
P("Với các hướng phát triển trên, nhóm kỳ vọng SkinSyntaxVN có thể trở thành một công cụ thực tế giúp người mua chọn mỹ phẩm an toàn hơn. "
  "Đồng thời, hệ thống giúp các cửa hàng nhỏ giảm chi phí tư vấn và làm nội dung, đúng với vấn đề đã đặt ra từ đầu đề tài.")
PB()

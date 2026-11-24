# -*- coding: utf-8 -*-
# ===================== GHI CHÚ ĐẦU FILE =====================
p = doc.add_paragraph(style="Normal")
runs(p, "[[GHI CHÚ CHO NHÓM (xóa trước khi nộp): Phần tô vàng là chỗ nhóm phải tự điền số liệu thật, tự kiểm chứng hoặc tự vẽ hình. "
        "Không bịa kết quả khảo sát. Khi dán vào file mẫu của Khoa, dùng Insert Caption cho Hình/Bảng và để Word tự đánh số mục.]]")
PB()

# ===================== MỞ ĐẦU =====================
H1("MỞ ĐẦU")
P("**Vấn đề.** Mua mỹ phẩm trực tuyến ngày càng phổ biến, nhưng người mua vẫn phải tự đoán sản phẩm nào hợp với làn da của mình. "
  "Để chọn được sản phẩm, họ thường tự tra cứu trên Google, các hội nhóm Facebook và video review trên TikTok, nơi thông tin trái chiều và khó kiểm chứng. "
  "Người không có kiến thức về mỹ phẩm khó nhận ra các sản phẩm trong giỏ có hoạt chất kỵ nhau, chẳng hạn Retinol dùng chung với AHA/BHA. "
  "Khi kết hợp sai trong cùng một chu trình dưỡng da, người dùng có thể bị kích ứng, bong tróc hoặc đỏ rát mà không biết nguyên nhân.")
P("Về phía doanh nghiệp, video giới thiệu sản phẩm là kênh bán hàng hiệu quả nhưng chi phí sản xuất cao. "
  "Mỗi video cần thuê người dẫn hoặc KOL, quay và dựng, nên cửa hàng thường chỉ làm cho số ít sản phẩm bán chạy. "
  "Ngoài ra, người dẫn thật chỉ làm việc theo lịch hẹn, nên cửa hàng khó duy trì livestream ngoài giờ và khó cập nhật video kịp khi có sản phẩm mới.")
P("**Giải pháp.** Từ thực tế đó, nhóm xây dựng SkinSyntaxVN, một website bán mỹ phẩm có trợ lý AI đóng vai trò như một chuyên viên tư vấn da. "
  "Hệ thống kết hợp khảo sát loại da, hệ thống gợi ý lai (hybrid recommender), đồ thị tri thức sản phẩm trên Neo4j và chatbot RAG có điều phối bằng LangGraph. "
  "Mỗi gợi ý đều kèm lý do phù hợp, và giỏ hàng được kiểm tra xung đột hoạt chất trước khi đặt mua. "
  "Ngoài ra, người dẫn chương trình ảo Syna tự động sinh kịch bản, giọng nói và video giới thiệu sản phẩm từ chính dữ liệu của cửa hàng.")
P("**Kết quả.** Nhờ hệ thống, khách hàng nhận được gợi ý có giải thích, được cảnh báo rủi ro thành phần và được tư vấn liên tục 24/7. "
  "Nhân viên giảm khối lượng trả lời các câu hỏi lặp lại. "
  "Doanh nghiệp có video cho cả những sản phẩm ít bán chạy mà không cần thuê người dẫn hay ê-kíp quay dựng. "
  "Hệ thống được đóng gói bằng Docker và được đánh giá bằng các độ đo Precision@K, Recall@K, nDCG và RAGAS. "
  "Khảo sát 56 người cho thấy 97% khách hàng muốn được cảnh báo khi các sản phẩm trong giỏ có thành phần xung đột, đúng với chức năng mà hệ thống tập trung giải quyết.")
PB()

# ===================== CHƯƠNG 1 =====================
H1("CHƯƠNG 1  GIỚI THIỆU")
P("Chương này trình bày bối cảnh hình thành đề tài, kết quả khảo sát người dùng và đối thủ cạnh tranh, từ đó xác định mục tiêu và phạm vi của hệ thống. "
  "Nội dung chương trả lời ba câu hỏi: vấn đề là gì, vì sao cần giải quyết ngay và hệ thống SkinSyntaxVN giải quyết đến đâu.")

H2("1.1  Đặt vấn đề")
H3("1.1.1  Bối cảnh mua sắm mỹ phẩm trực tuyến")
P("Thương mại điện tử đã trở thành kênh mua sắm chính của người tiêu dùng trẻ tại Việt Nam, trong đó mỹ phẩm là một trong những ngành hàng tăng trưởng nhanh. "
  "Theo Cục Thương mại điện tử và Kinh tế số (Bộ Công Thương), quy mô thị trường thương mại điện tử Việt Nam năm 2024 vượt 25 tỷ USD, tăng khoảng 20% so với năm 2023 [1]. "
  "Người mua có thể so sánh giá và đặt hàng chỉ trong vài phút trên các sàn như Shopee hay các chuỗi chuyên doanh như Hasaki. "
  "Tuy nhiên, tốc độ mua nhanh hơn chưa đồng nghĩa với việc người mua chọn đúng sản phẩm.")
P("Khác với hàng điện tử hay thời trang, hiệu quả của mỹ phẩm phụ thuộc mạnh vào cơ địa của từng người. "
  "Cùng một loại kem dưỡng có thể phù hợp với da khô nhưng gây bít tắc lỗ chân lông trên da dầu mụn. "
  "Vì vậy, quyết định mua mỹ phẩm đòi hỏi thông tin về loại da, vấn đề da, độ nhạy cảm và thành phần, chứ không chỉ giá bán và lượt đánh giá.")

H3("1.1.2  Hạn chế khi chưa có giải pháp")
P("Khi thiếu một công cụ tư vấn chuyên biệt, người mua mỹ phẩm trực tuyến gặp năm khó khăn chính. "
  "Các khó khăn này được nhóm tổng hợp từ khảo sát người dùng ở mục 1.2 và từ quá trình sử dụng thực tế các nền tảng hiện có.")
B("**Quá tải lựa chọn:** một từ khóa như \"kem chống nắng\" trả về hàng nghìn kết quả, được xếp chủ yếu theo quảng cáo và độ bán chạy.",
  "**Thông tin phân tán, khó kiểm chứng:** người mua phải tự tra cứu trên Google, Facebook, TikTok, mỗi nguồn khuyên một kiểu và nhiều bài review mang tính quảng cáo.",
  "**Khó hiểu thành phần:** bảng thành phần ghi bằng tên khoa học tiếng Anh, người mua phổ thông khó đánh giá mức độ phù hợp.",
  "**Không cảnh báo xung đột:** người không có kiến thức mỹ phẩm khó biết các sản phẩm trong giỏ có hoạt chất kỵ nhau, giỏ hàng vẫn được thanh toán bình thường và dễ gây kích ứng khi dùng chung.",
  "**Tư vấn phụ thuộc con người:** muốn hỏi chuyên sâu phải chờ nhân viên trả lời, ngoài giờ làm việc gần như không có phản hồi.")
P("Hệ quả là người mua tốn thời gian tra cứu, dễ mua sai và có thể bị tổn thương da. "
  "Về phía cửa hàng, tỷ lệ đổi trả và đánh giá tiêu cực tăng, còn nhân viên phải trả lời lặp lại cùng một nhóm câu hỏi mỗi ngày.")
P("Bên cạnh đó, doanh nghiệp còn gặp khó khăn về chi phí sản xuất nội dung tiếp thị. "
  "Một video giới thiệu sản phẩm cần người dẫn, kịch bản, quay và dựng, nên chi phí tăng theo số lượng sản phẩm. "
  "Vì vậy, phần lớn sản phẩm trong danh mục không có video, và người mua thiếu một kênh dễ tiếp cận để hiểu cách dùng sản phẩm đúng.")

H3("1.1.3  Tính cấp thiết của đề tài")
P("Tính cấp thiết của đề tài xuất phát từ khoảng trống giữa nhu cầu được tư vấn cá nhân và khả năng đáp ứng của các nền tảng hiện tại. "
  "Sự phát triển của mô hình ngôn ngữ lớn (Large Language Model – LLM) và kỹ thuật sinh câu trả lời dựa trên dữ liệu truy xuất (Retrieval-Augmented Generation – RAG) giúp việc tư vấn tự động trở nên khả thi với chi phí thấp [11]. "
  "Tuy nhiên, nếu chỉ gắn một chatbot đa năng lên website, câu trả lời dễ bị \"ảo giác\" và đưa ra sản phẩm không có trong kho.")
P("Do đó, cần một hệ thống kết hợp nhiều kỹ thuật: gợi ý dựa trên hồ sơ da, đồ thị tri thức về thành phần và chatbot chỉ trả lời dựa trên dữ liệu thật của cửa hàng. "
  "Đây chính là hướng tiếp cận mà nhóm lựa chọn cho đề tài SkinSyntaxVN.")

H2("1.2  Khảo sát hiện trạng")
P("Để tránh xây dựng hệ thống theo cảm tính, nhóm tiến hành khảo sát theo hai hướng: khảo sát người dùng và khảo sát đối thủ cạnh tranh. "
  "Kết quả khảo sát là cơ sở để xác định yêu cầu chức năng, yêu cầu phi chức năng và điểm khác biệt của hệ thống.")

H3("1.2.1  Khảo sát người dùng")
P("Nhóm khảo sát ba nhóm người dùng tương ứng với ba tác nhân chính của hệ thống: khách hàng, nhân viên bán hàng và quản trị viên. "
  "Hình thức khảo sát gồm phỏng vấn trực tiếp và một phiếu Google Form chung cho cả ba nhóm, các câu hỏi mức độ dùng thang Likert 5 mức (1 – Rất không đồng ý đến 5 – Rất đồng ý) [12]. "
  "Câu hỏi đầu tiên của phiếu yêu cầu người trả lời chọn mình là khách hàng, nhân viên hay chủ cửa hàng, sau đó biểu mẫu tự chuyển đến phần câu hỏi dành riêng cho nhóm đó. "
  "Cách làm này giúp nhóm chỉ cần gửi một đường liên kết và quản lý toàn bộ kết quả trên cùng một bảng dữ liệu. "
  "Khảo sát được thực hiện trong tháng 11 năm 2026 với tổng cộng 56 người tham gia.")
T("Bảng 1.1 Đối tượng và nội dung khảo sát người dùng",
  ["Nhóm người dùng", "Số lượng", "Nội dung khảo sát chính"],
  [["Khách hàng mua mỹ phẩm online", "29", "Thói quen mua, khó khăn khi chọn sản phẩm, mức độ cần tư vấn theo loại da, mong muốn với chatbot và cảnh báo thành phần."],
   ["Nhân viên bán hàng / tư vấn", "15", "Loại câu hỏi khách thường hỏi, thời gian trả lời, công việc lặp lại cần tự động hóa."],
   ["Quản trị viên / chủ cửa hàng", "12", "Chi phí nhân sự tư vấn, rủi ro uy tín khi tư vấn sai, chi phí làm video và livestream, nhu cầu dữ liệu để quyết định nhập hàng."]],
  [4.2, 2, 9.3])
P("Phiếu khảo sát khách hàng gồm phần thông tin chung và 15 câu hỏi, chia thành năm nhóm theo chuỗi lập luận: khó khăn khi chọn sản phẩm, rủi ro thành phần, hạn chế của nền tảng hiện tại, trải nghiệm thực tế và mong muốn với giải pháp. "
  "Nhóm trải nghiệm thực tế được đặt ngay trước nhóm mong muốn để người trả lời nhớ lại vấn đề đã gặp trước khi đánh giá giải pháp. "
  "Phần thông tin chung hỏi giới tính, độ tuổi, loại da, các vấn đề da đang gặp và tần suất mua mỹ phẩm trực tuyến.")
T("Bảng 1.2 Phiếu khảo sát khách hàng",
  ["STT", "Câu hỏi / phát biểu", "Kết quả (điểm TB; % chọn mức 4–5)"],
  [["", "**A. Khó khăn khi chọn sản phẩm (Likert 1–5)**", ""],
   ["1", "Tôi thường không chắc sản phẩm mình định mua có hợp với loại da của mình hay không.", "4,24; 86%"],
   ["2", "Trước khi mua, tôi thường phải tự tra cứu thêm trên Google, Facebook hoặc TikTok.", "4,41; 90%"],
   ["3", "Tôi không hiểu hoặc chỉ hiểu một phần bảng thành phần ghi trên bao bì.", "4,38; 90%"],
   ["", "**B. Rủi ro về thành phần (Likert 1–5)**", ""],
   ["4", "Tôi không biết những hoạt chất nào không nên dùng chung, ví dụ Retinol với AHA/BHA.", "4,34; 93%"],
   ["5", "Khi mua nhiều sản phẩm cùng lúc, tôi không kiểm tra chúng có dùng chung được hay không.", "4,48; 93%"],
   ["", "**C. Trải nghiệm với các nền tảng hiện tại (Likert 1–5)**", ""],
   ["6", "Khi mua trên Shopee, Lazada hoặc TikTok Shop, tôi không tìm được cách lọc sản phẩm theo loại da hoặc vấn đề da của mình.", "4,17; 86%"],
   ["7", "Khi cần hỏi tư vấn trên sàn hoặc website, tôi thường phải chờ lâu hoặc không nhận được câu trả lời chuyên môn.", "4,45; 90%"],
   ["", "**D. Trải nghiệm thực tế (câu hỏi lựa chọn)**", ""],
   ["8", "Bạn đã từng bị kích ứng, nổi mụn hoặc đỏ rát sau khi dùng mỹ phẩm mua trực tuyến chưa? (Có / Không / Không nhớ)", "Có 41%; Không 38%; Không nhớ 21%"],
   ["9", "Bạn thường mất bao lâu tìm hiểu trước khi mua một sản phẩm mỹ phẩm mới? (Dưới 15 phút / 15–30 phút / 30–60 phút / Trên 60 phút)", "Dưới 15 phút 31%; 15–30 phút 28%; 30–60 phút 3%; trên 60 phút 38%"],
   ["", "**E. Mong muốn với giải pháp (Likert 1–5)**", ""],
   ["10", "Tôi muốn website gợi ý sản phẩm theo loại da và vấn đề da của tôi.", "4,52; 97%"],
   ["11", "Tôi muốn biết rõ lý do vì sao một sản phẩm được gợi ý cho tôi.", "4,52; 97%"],
   ["12", "Tôi muốn được cảnh báo khi các sản phẩm trong giỏ hàng có thành phần xung đột, không nên dùng chung.", "4,59; 97%"],
   ["13", "Tôi sẵn sàng hỏi trợ lý AI tư vấn 24/7 thay vì chờ nhân viên trả lời.", "4,41; 90%"],
   ["14", "Tôi sẽ tin tưởng gợi ý của trợ lý AI hơn nếu câu trả lời có dẫn chứng rõ ràng từ bảng thành phần hoặc thông tin chính hãng của sản phẩm.", "4,34; 93%"],
   ["15", "Tôi muốn xem video ngắn hướng dẫn cách dùng sản phẩm trước khi mua.", "4,00; 72%"]],
  [1.2, 11.3, 3], size=10.5)
P("Kết quả khảo sát 29 khách hàng cho thấy 93% không biết những hoạt chất nào không nên dùng chung và cũng không kiểm tra điều này khi mua nhiều sản phẩm (câu 4, 5). "
  "38% số người trả lời mất trên 60 phút tìm hiểu trước khi mua một sản phẩm mới, và 41% từng bị kích ứng sau khi dùng mỹ phẩm mua trực tuyến (câu 8, 9). "
  "Mong muốn được cảnh báo xung đột trong giỏ hàng có điểm trung bình cao nhất (4,59; 97% đồng ý), tiếp theo là được gợi ý theo loại da và được giải thích lý do gợi ý (cùng đạt 4,52).")
P("Đối với nhân viên bán hàng và tư vấn, nhóm dùng phiếu ẩn danh gồm 14 câu kết hợp phỏng vấn trực tiếp, vì số lượng nhân viên ít và cần ghi nhận ý kiến chi tiết. "
  "Phiếu tập trung vào thiệt hại thực tế khi tư vấn thủ công như tư vấn dễ nhầm lẫn, khách phải chờ lâu, mất khách và khiếu nại do không hợp da. "
  "Các phát biểu dùng chủ ngữ là cửa hàng hoặc đội ngũ thay vì cá nhân người trả lời, giúp nhân viên không ngại thừa nhận khó khăn.")
T("Bảng 1.3 Phiếu khảo sát nhân viên bán hàng và tư vấn",
  ["STT", "Câu hỏi / phát biểu", "Kết quả (điểm TB; % chọn mức 4–5)"],
  [["", "**Thông tin chung:** vị trí công việc (bán hàng tại cửa hàng / tư vấn trực tuyến / chăm sóc khách hàng); thời gian làm việc trong ngành mỹ phẩm (dưới 1 năm / 1–3 năm / trên 3 năm)", ""],
   ["", "**A. Khó khăn khi tư vấn thủ công (Likert 1–5)**", ""],
   ["1", "Việc kiểm tra nhanh xem hai sản phẩm có xung đột thành phần hay không thường khá khó và dễ nhầm lẫn nếu không có công cụ hỗ trợ.", "4,40; 100%"],
   ["2", "Khi gặp câu hỏi chuyên sâu về bảng thành phần, nhân viên thường mất nhiều thời gian tra cứu thủ công hoặc phải khuyên khách dùng thử trên vùng da nhỏ.", "4,67; 100%"],
   ["3", "Cửa hàng từng ghi nhận trường hợp khách phản hồi bị kích ứng hoặc không hợp sản phẩm sau khi mua.", "4,47; 100%"],
   ["4", "Mỗi nhân viên tư vấn một kiểu, nên thông tin khách nhận được không thống nhất.", "4,47; 93%"],
   ["", "**B. Áp lực công việc và mất khách (Likert 1–5)**", ""],
   ["5", "Phần lớn thời gian tư vấn dành cho việc trả lời các câu hỏi lặp lại, thay vì chốt đơn hoặc chăm sóc khách.", "4,20; 93%"],
   ["6", "Cửa hàng từng mất khách vì không trả lời kịp tin nhắn tư vấn.", "4,47; 100%"],
   ["7", "Tin nhắn ngoài giờ làm việc thường không được trả lời kịp.", "4,47; 100%"],
   ["", "**C. Giải pháp (Likert 1–5)**", ""],
   ["8", "Một chatbot trả lời đúng dựa trên dữ liệu sản phẩm sẽ giúp giảm đáng kể khối lượng công việc tư vấn.", "4,27; 100%"],
   ["9", "Hệ thống tự cảnh báo thành phần xung đột trong giỏ hàng sẽ giúp giảm khiếu nại.", "4,60; 100%"],
   ["10", "Công cụ gợi ý nhanh danh sách sản phẩm phù hợp theo loại da và tình trạng da của khách sẽ giúp chốt đơn nhanh hơn.", "4,40; 100%"],
   ["", "**D. Số liệu thực tế (câu hỏi lựa chọn)**", ""],
   ["11", "Mỗi ngày anh/chị trả lời khoảng bao nhiêu câu hỏi tư vấn? (Dưới 20 / 20–50 / 50–100 / Trên 100)", "Dưới 20 câu 47%; 20–50 câu 20%; 50–100 câu 20%; trên 100 câu 13%"],
   ["12", "Khách thường phải chờ bao lâu mới được trả lời? (Dưới 5 phút / 5–30 phút / 30 phút–2 giờ / Trên 2 giờ)", "Dưới 5 phút 13%; 5–30 phút 47%; 30 phút–2 giờ 33%; trên 2 giờ 7%"],
   ["13", "Mỗi tháng có khoảng bao nhiêu trường hợp khiếu nại hoặc đổi trả vì sản phẩm không hợp da? (0 / 1–2 / 3–5 / Trên 5 / Không nắm rõ số liệu này)", "0 ca 0%; 1–2 ca 33%; 3–5 ca 27%; trên 5 ca 13%; không rõ 27%"],
   ["14", "Khoảng bao nhiêu phần trăm khách hỏi xong rồi không mua vì phải chờ hoặc chưa được tư vấn rõ? (Dưới 10% / 10–25% / 25–50% / Trên 50%)", "Dưới 10% 20%; 10–25% 40%; 25–50% 20%; trên 50% 20%"],
   ["", "**Câu hỏi mở:** Tình huống tư vấn nào khiến anh/chị mất nhiều thời gian hoặc lo ngại tư vấn sai nhất?", ""]],
  [1.2, 11.3, 3], size=10.5)
P("Toàn bộ 15 nhân viên đồng ý rằng việc tra cứu thành phần thủ công tốn thời gian và cửa hàng từng mất khách vì không trả lời kịp tin nhắn (câu 2, 6). "
  "40% cho biết khách phải chờ trên 30 phút mới được trả lời, và 40% ước tính hơn một phần tư số khách hỏi xong rồi bỏ đi vì phải chờ hoặc chưa được tư vấn rõ (câu 12, 14). "
  "Giải pháp tự cảnh báo xung đột trong giỏ hàng được nhân viên đánh giá cao nhất với điểm trung bình 4,60 (câu 9).")
P("Quản trị viên của hệ thống là chủ cửa hàng, người trực tiếp chịu chi phí và rủi ro khi việc tư vấn diễn ra thủ công. "
  "Vì vậy, phiếu khảo sát chủ cửa hàng gồm 15 câu, tập trung vào bốn loại thiệt hại: chi phí nhân sự tư vấn, rủi ro uy tín, chi phí làm nội dung tiếp thị và việc thiếu dữ liệu để ra quyết định. "
  "Phần thông tin chung hỏi hình thức kinh doanh, số nhân viên tư vấn và số lượng sản phẩm đang bán để phân loại quy mô cửa hàng.")
T("Bảng 1.4 Phiếu khảo sát quản trị viên (chủ cửa hàng)",
  ["STT", "Câu hỏi / phát biểu", "Kết quả (điểm TB; % chọn mức 4–5)"],
  [["", "**Thông tin chung:** hình thức kinh doanh (chỉ bán online / có cửa hàng và bán online / chuỗi nhiều cửa hàng); số nhân viên tư vấn (chủ tự tư vấn / 1–2 / 3–5 / trên 5); số lượng sản phẩm đang bán (dưới 100 / 100–300 / 300–1.000 / trên 1.000)", ""],
   ["", "**A. Chi phí và nhân sự tư vấn (Likert 1–5)**", ""],
   ["1", "Chi phí nhân sự tư vấn (lương, đào tạo) chiếm phần đáng kể trong chi phí vận hành của cửa hàng.", "4,42; 100%"],
   ["2", "Đào tạo một nhân viên mới đủ kiến thức để tư vấn thành phần mỹ phẩm mất nhiều thời gian.", "4,42; 100%"],
   ["3", "Khi nhân viên nghỉ việc, cửa hàng mất luôn kinh nghiệm tư vấn mà người đó tích lũy được.", "4,25; 83%"],
   ["", "**B. Rủi ro uy tín và dữ liệu (Likert 1–5)**", ""],
   ["4", "Cửa hàng khó kiểm soát việc nhân viên tư vấn đúng hay sai về thành phần sản phẩm.", "4,08; 92%"],
   ["5", "Chỉ một khiếu nại về kích ứng cũng có thể ảnh hưởng đáng kể đến uy tín cửa hàng, như đánh giá xấu hoặc mất khách quen.", "4,50; 100%"],
   ["6", "Cửa hàng chưa nắm rõ khách thường hỏi gì và quan tâm vấn đề da nào để lên kế hoạch nhập hàng và khuyến mãi.", "4,42; 100%"],
   ["", "**C. Nội dung tiếp thị (Likert 1–5)**", ""],
   ["7", "Chi phí làm video giới thiệu sản phẩm hoặc thuê người livestream là gánh nặng đối với cửa hàng.", "4,50; 100%"],
   ["8", "Cửa hàng không đủ người để ra video hoặc livestream đều đặn mỗi tuần.", "4,58; 100%"],
   ["9", "Khi thuê KOL/KOC hoặc người livestream, cửa hàng khó kiểm soát việc họ nói đúng thông tin thành phần sản phẩm.", "4,33; 100%"],
   ["", "**D. Giải pháp (Likert 1–5)**", ""],
   ["10", "Cửa hàng sẵn sàng dùng trợ lý AI tư vấn 24/7 nếu trợ lý chỉ trả lời dựa trên dữ liệu sản phẩm do cửa hàng cung cấp.", "4,33; 83%"],
   ["11", "Bảng thống kê câu hỏi của khách và xu hướng vấn đề da sẽ giúp cửa hàng quyết định nhập hàng chính xác hơn.", "4,17; 92%"],
   ["12", "Một nhân vật ảo (AI Idol) tự tạo video giới thiệu sản phẩm từ dữ liệu của cửa hàng là giải pháp đáng cân nhắc để giảm chi phí làm nội dung.", "4,33; 92%"],
   ["", "**E. Số liệu thực tế (câu hỏi lựa chọn)**", ""],
   ["13", "Một nhân viên mới cần bao lâu để tự tư vấn thành phần cho khách? (Dưới 1 tuần / 1–4 tuần / 1–3 tháng / Trên 3 tháng)", "Dưới 1 tuần 0%; 1–4 tuần 75%; 1–3 tháng 17%; trên 3 tháng 8%"],
   ["14", "Mỗi tháng cửa hàng chi khoảng bao nhiêu cho video và livestream? (Chưa làm video hoặc livestream / Dưới 5 triệu đồng / 5–20 triệu đồng / Trên 20 triệu đồng / Không nắm rõ số liệu này)", "Chưa làm 8%; dưới 5 triệu 25%; 5–20 triệu 25%; trên 20 triệu 33%; không rõ 8%"],
   ["15", "Cửa hàng hiện lưu thông tin thành phần sản phẩm bằng cách nào? (Chưa lưu, chủ yếu nhớ hoặc xem trên bao bì / File Excel hoặc sổ ghi chép / Phần mềm quản lý bán hàng / Website riêng có trang thành phần sản phẩm)", "Chưa lưu 25%; Excel/sổ 25%; phần mềm 25%; website riêng 25%"],
   ["", "**Câu hỏi mở:** Nếu có một hệ thống hỗ trợ, anh/chị muốn hệ thống giải quyết việc gì đầu tiên?", ""]],
  [1.2, 11.3, 3], size=10.5)
P("75% chủ cửa hàng cho biết cần từ 1 đến 4 tuần để nhân viên mới tự tư vấn được thành phần, và tất cả đồng ý rằng chi phí nhân sự tư vấn chiếm phần đáng kể trong chi phí vận hành (câu 1, 13). "
  "Một phần ba cửa hàng chi trên 20 triệu đồng mỗi tháng cho video và livestream, và việc không đủ người làm video đều đặn đạt điểm trung bình 4,58 (câu 8, 14). "
  "Giải pháp nhân vật ảo AI Idol được đánh giá 4,33 với 92% đồng ý (câu 12). "
  "Do nhóm chủ cửa hàng chỉ có 12 người tham gia, kết quả của nhóm này mang tính tham khảo.")
P("Từ kết quả trên, nhóm tổng hợp nhu cầu chức năng của từng nhóm người dùng như Bảng 1.5. "
  "Bảng này là đầu vào cho việc đặc tả use case ở chương phân tích yêu cầu.")
T("Bảng 1.5 Nhu cầu chức năng theo từng nhóm người dùng",
  ["Tác nhân", "Nhóm chức năng", "Mô tả chi tiết"],
  [["**Khách vãng lai**", "Xem và tìm sản phẩm", "Xem theo danh mục, lọc theo loại da và vấn đề da; tìm kiếm hiểu từ không dấu, viết tắt, sai chính tả, gợi ý ngay khi gõ; xem chi tiết, thành phần, cách dùng, đánh giá và sản phẩm tương tự."],
   ["", "Tư vấn và nội dung", "Hỏi chatbot tư vấn; làm khảo sát loại da; xem video sản phẩm và livestream của Syna."],
   ["", "Tài khoản", "Đăng ký có captcha và mã OTP qua email; đăng nhập bằng mật khẩu hoặc tài khoản mạng xã hội; khôi phục mật khẩu."],
   ["**Khách hàng**", "Hồ sơ da", "Làm khảo sát đầy đủ hoặc khảo sát nhanh; tạo và chọn nhiều hồ sơ da; lưu vấn đề da để làm đầu vào cho gợi ý."],
   ["", "Gợi ý sản phẩm", "Nhận gợi ý cá nhân hóa kèm lý do phù hợp; sản phẩm thường mua kèm; gợi ý các bước còn thiếu trong quy trình chăm sóc da."],
   ["", "Mua hàng", "Quản lý giỏ hàng và nhận cảnh báo hoạt chất xung đột; áp dụng voucher và điểm tích lũy; thanh toán khi nhận hàng hoặc chuyển khoản qua mã QR có đối soát giao dịch; theo dõi và hủy đơn."],
   ["", "Chatbot", "Hỏi đáp dựa trên dữ liệu cửa hàng, chữ hiện ngay khi sinh; lưu lịch sử hội thoại; đặt hàng trong khung chat có xác thực OTP."],
   ["", "Đọc thành phần từ ảnh", "Chụp nhãn sản phẩm để hệ thống nhận diện bảng thành phần và giải thích các hoạt chất chính."],
   ["", "Tương tác", "Đánh dấu yêu thích; viết đánh giá; đặt câu hỏi về sản phẩm; nhắn tin trực tiếp với nhân viên."],
   ["", "Khuyến mãi và thân thiết", "Săn flash sale; điểm danh hằng ngày; nhận thưởng theo mốc; nhận voucher khi xem hết video sản phẩm."],
   ["", "Livestream", "Bình luận, thả cảm xúc, được Syna trả lời tự động; xem sản phẩm được ghim và thêm vào giỏ ngay trong phòng."],
   ["**Nhân viên**", "Đơn hàng", "Xem, cập nhật trạng thái và in đơn hàng; nhận thông báo đơn mới."],
   ["", "Sản phẩm", "Thêm, sửa, ẩn sản phẩm."],
   ["", "Chăm sóc khách hàng", "Trả lời đánh giá, câu hỏi về sản phẩm và tin nhắn của khách."],
   ["**Quản trị viên**", "Danh mục và kho", "Quản lý sản phẩm, danh mục, nhãn sản phẩm; quản lý tồn kho theo lô và hạn sử dụng."],
   ["", "Người dùng", "Quản lý tài khoản khách hàng và nhân viên."],
   ["", "Khuyến mãi", "Quản lý voucher, flash sale và chiến dịch thưởng khi xem video."],
   ["", "Kiểm duyệt", "Duyệt, ẩn hoặc trả lời đánh giá và câu hỏi của khách."],
   ["", "Báo cáo", "Xem bảng điều khiển, báo cáo doanh thu và các truy vấn tìm kiếm không có kết quả."],
   ["", "AI Idol", "Tạo video sản phẩm và bật, tắt hiển thị; quản lý phòng livestream, ghim sản phẩm, thêm ưu đãi; lên lịch, chạy lại hoặc hủy chiến dịch; chọn duyệt kịch bản thủ công hoặc tự động; giao việc cho trợ lý bằng ngôn ngữ tự nhiên."]],
  [2.8, 3.4, 9.3], size=10.5)

H3("1.2.2  Khảo sát đối thủ cạnh tranh")
P("Nhóm chọn hai nền tảng đại diện để so sánh là Shopee (sàn thương mại điện tử tổng hợp) và Hasaki (chuỗi bán lẻ mỹ phẩm chuyên doanh). "
  "Các tiêu chí so sánh tập trung vào khả năng hỗ trợ người mua chọn đúng sản phẩm, thay vì quy mô kinh doanh. "
  "Các ô của Hasaki được nhóm kiểm tra trên website hasaki.vn vào tháng 11 năm 2026, các ô của Shopee dựa trên trải nghiệm sử dụng và câu 6, 7 của phiếu khảo sát khách hàng.")
T("Bảng 1.6 So sánh SkinSyntaxVN với các nền tảng hiện có",
  ["Tiêu chí", "Shopee", "Hasaki", "SkinSyntaxVN"],
  [["Tìm kiếm theo từ khóa", "Có", "Có", "Có, sửa lỗi chính tả và hiểu ý định tìm kiếm"],
   ["Lọc theo loại da / vấn đề da", "Hạn chế, phụ thuộc người bán", "Có, lọc theo loại da, vấn đề da và hoạt chất", "Có, dựa trên hồ sơ da từ khảo sát"],
   ["Gợi ý sản phẩm", "Có, chủ yếu theo hành vi", "Có, khối \"Có thể bạn thích\" theo sản phẩm đang xem", "Có, gợi ý lai: hồ sơ da, nội dung, hành vi, đồ thị"],
   ["Giải thích lý do gợi ý", "Không", "Không", "Có, điểm phù hợp và lý do cụ thể"],
   ["Tư vấn chuyên môn về da", "Chat với người bán", "Hotline miễn phí 8–22 giờ", "Chatbot AI 24/7 dựa trên dữ liệu thật"],
   ["Cảnh báo xung đột thành phần trong giỏ", "Không", "Không", "Có, kèm gợi ý thay thế"],
   ["Phân tích thành phần từ ảnh nhãn", "Không", "Không", "Có"],
   ["Video / livestream giới thiệu", "Có, do người bán tự thực hiện", "Không có trên trang sản phẩm", "Có, do AI Idol Syna sinh tự động"],
   ["Quy mô sản phẩm, vận chuyển, khuyến mãi", "Rất mạnh", "Mạnh", "Hạn chế (quy mô đồ án)"]],
  [4, 3.3, 3.3, 4.9], size=10.5)

H3("1.2.3  Nhận xét và điểm khác biệt")
P("Kết quả so sánh cho thấy các đối thủ vượt trội về quy mô hàng hóa, hạ tầng vận chuyển và chương trình khuyến mãi. "
  "Đây là những lợi thế mà một đồ án tốt nghiệp không đặt mục tiêu cạnh tranh. "
  "Ngược lại, các nền tảng này còn để trống mảng tư vấn theo cơ địa và kiểm soát rủi ro thành phần.")
P("SkinSyntaxVN tập trung vào đúng khoảng trống đó với ba điểm khác biệt về tư vấn. "
  "Thứ nhất, mọi gợi ý đều có lý do phù hợp có thể kiểm chứng từ đồ thị tri thức. "
  "Thứ hai, giỏ hàng được kiểm tra xung đột hoạt chất trước khi thanh toán. "
  "Thứ ba, chatbot chỉ tư vấn trên dữ liệu sản phẩm thật của cửa hàng, hạn chế việc bịa thông tin.")
P("Điểm khác biệt thứ tư là người dẫn chương trình ảo Syna, giúp doanh nghiệp giải quyết hai bài toán về chi phí và tính sẵn sàng. "
  "Về chi phí, Syna sinh kịch bản, giọng nói và video tự động nên cửa hàng không cần thuê người dẫn, studio hay ê-kíp quay dựng cho từng sản phẩm. "
  "Về tính sẵn sàng, Syna có thể livestream ngoài giờ làm việc, trả lời bình luận của người xem và tạo video ngay khi có sản phẩm mới. "
  "Nhờ đó, cả những sản phẩm ít bán chạy cũng có video hướng dẫn cách dùng, giúp người mua hiểu sản phẩm trước khi đặt hàng.")

H2("1.3  Mục tiêu đề tài")
H3("1.3.1  Mục tiêu tổng quát")
P("Mục tiêu tổng quát của đề tài là xây dựng một nền tảng thương mại điện tử mỹ phẩm có khả năng tư vấn cá nhân hóa theo làn da. "
  "Hệ thống phải vừa đáp ứng đầy đủ nghiệp vụ bán hàng, vừa ứng dụng các công nghệ AI mới một cách có kiểm soát và đo lường được hiệu quả.")
H3("1.3.2  Mục tiêu cụ thể")
P("Để đạt mục tiêu tổng quát, nhóm đặt ra các mục tiêu cụ thể sau:")
B("Xây dựng website bán hàng đầy đủ cho bốn tác nhân: khách vãng lai, khách hàng, nhân viên và quản trị viên.",
  "Xây dựng hệ thống gợi ý lai kết hợp hồ sơ da, nội dung sản phẩm, hành vi người dùng và đồ thị tri thức.",
  "Xây dựng đồ thị tri thức sản phẩm trên Neo4j để mô hình hóa quan hệ giữa sản phẩm, thành phần, loại da và xung đột hoạt chất.",
  "Xây dựng chatbot tư vấn theo kiến trúc RAG, tìm kiếm lai và điều phối bằng LangGraph.",
  "Xây dựng AI Idol Syna sinh kịch bản, giọng nói và video giới thiệu sản phẩm tự động.",
  "Đánh giá hệ thống bằng độ đo định lượng (Precision@K, Recall@K, F1, nDCG, RAGAS) và khảo sát hài lòng theo thang Likert.",
  "Triển khai hệ thống lên máy chủ thật bằng Docker Compose.")

H2("1.4  Đối tượng và phạm vi đề tài")
H3("1.4.1  Đối tượng sử dụng")
P("Hệ thống phục vụ bốn nhóm đối tượng có quyền hạn tăng dần. "
  "Khách vãng lai có thể xem và tìm kiếm sản phẩm, khách hàng có thêm các chức năng mua hàng và cá nhân hóa. "
  "Nhân viên xử lý các nghiệp vụ vận hành hằng ngày, còn quản trị viên quản lý toàn bộ dữ liệu và cấu hình hệ thống.")
H3("1.4.2  Phạm vi chức năng")
P("Về chức năng, đề tài gồm ba phân hệ phục vụ bốn tác nhân, danh sách chức năng chi tiết theo từng tác nhân được trình bày ở Bảng 1.5. "
  "Phân hệ thương mại điện tử đáp ứng toàn bộ nghiệp vụ bán hàng, từ tài khoản, hồ sơ da, giỏ hàng, đặt hàng, thanh toán đến khuyến mãi và quản trị cửa hàng.")
P("Phân hệ AI tư vấn giúp khách hàng chọn đúng sản phẩm qua tìm kiếm thông minh, gợi ý cá nhân hóa kèm lý do, chatbot và cảnh báo hoạt chất xung đột trong giỏ hàng. "
  "Phân hệ này còn đọc thành phần từ ảnh nhãn sản phẩm và dùng đồ thị tri thức để mô hình hóa quan hệ giữa sản phẩm, thành phần và loại da.")
P("Phân hệ AI Idol dùng nhân vật ảo Syna để tạo video gắn trên trang sản phẩm và phát livestream trên website, nơi người xem có thể trò chuyện và mua hàng ngay trong phòng. "
  "Quản trị viên quản lý nội dung theo chiến dịch và có thể giao việc cho trợ lý bằng ngôn ngữ tự nhiên.")
P("Kịch bản của Syna được tạo từ chính dữ liệu sản phẩm của cửa hàng như tên, thành phần, công dụng, cách dùng, loại da phù hợp và giá. "
  "Dữ liệu này được bổ sung kiến thức về hoạt chất truy xuất bằng kỹ thuật RAG, rồi viết theo khung cố định gồm bảy phần cho video và tám phần cho livestream. "
  "Hai mô hình ngôn ngữ lớn được giao hai vai trò: một mô hình viết bản nháp, mô hình còn lại kiểm tra độ chính xác và tính an toàn quảng cáo rồi chỉnh sửa. "
  "Hệ thống tự đối chiếu kịch bản với dữ liệu gốc, được duyệt thủ công hoặc tự động tùy cấu hình, sau đó chuyển thành giọng đọc và dựng thành video của nhân vật ảo Syna.")
P("Bên cạnh đó, đề tài có một số giới hạn về chuyên môn, quy mô và hạ tầng vận hành như sau:")
B("**Chuyên môn:** hệ thống không chẩn đoán bệnh da liễu và không thay thế ý kiến bác sĩ. Chatbot và AI Idol chỉ nói theo dữ liệu sản phẩm, không đưa ra tuyên bố điều trị hay cam kết hiệu quả.",
  "**Quy mô hàng hóa:** hệ thống phục vụ một cửa hàng mỹ phẩm chăm sóc da với khoảng 2.850 sản phẩm, không phải sàn nhiều người bán. Dữ liệu sản phẩm được thu thập phục vụ mục đích học tập và chưa kết nối với phần mềm quản lý bán hàng bên ngoài.",
  "**Vận chuyển:** phí giao hàng cố định 30.000 đồng mỗi đơn, chưa tính theo khoảng cách hay khối lượng. Hệ thống chưa tích hợp đơn vị vận chuyển, trạng thái giao hàng do nhân viên cập nhật.",
  "**Thanh toán:** hệ thống hỗ trợ thanh toán khi nhận hàng (COD) và chuyển khoản qua mã QR. Đề tài chưa tích hợp cổng thanh toán trực tuyến, ví điện tử hay trả góp.",
  "**Khuyến mãi:** hệ thống hỗ trợ voucher, flash sale, điểm tích lũy và thưởng khi xem video. Các hình thức phức tạp như combo, mua kèm hoặc đồng bộ khuyến mãi với sàn thương mại điện tử chưa nằm trong phạm vi.",
  "**AI Idol:** quản trị viên chọn duyệt kịch bản thủ công hoặc để hệ thống tự duyệt và tự đăng, nhưng mọi kịch bản đều phải qua bước kiểm tra tự động trước. Video và livestream được phát trên website của hệ thống; việc phát trực tiếp lên YouTube hoặc Facebook chưa được thử nghiệm nên không thuộc phạm vi đánh giá. Chất lượng giọng đọc và tốc độ tạo video phụ thuộc vào hạn mức của các dịch vụ AI bên ngoài.",
  "**Nền tảng:** hệ thống là website tiếng Việt hiển thị tốt trên máy tính và điện thoại, chưa có ứng dụng di động riêng.")
H3("1.4.3  Yêu cầu phi chức năng")
P("Bên cạnh chức năng, hệ thống cần đáp ứng các yêu cầu phi chức năng được tổng hợp trong Bảng 1.7.")
T("Bảng 1.7 Yêu cầu phi chức năng",
  ["Nhóm yêu cầu", "Mô tả"],
  [["Hiệu năng", "Trang danh sách sản phẩm phản hồi nhanh nhờ bộ nhớ đệm; chatbot trả lời dạng luồng (streaming) để người dùng thấy chữ xuất hiện ngay."],
   ["Bảo mật", "Mật khẩu được băm; xác thực OTP qua email; captcha khi đăng ký; giới hạn tần suất gọi API; dịch vụ AI không mở trực tiếp ra Internet."],
   ["Tính sẵn sàng", "Khi một nhà cung cấp LLM lỗi hoặc hết hạn mức, hệ thống tự chuyển sang nhà cung cấp dự phòng."],
   ["Tính chính xác", "Chatbot chỉ trả lời dựa trên dữ liệu đã truy xuất; sản phẩm gợi ý phải tồn tại và còn hàng."],
   ["Khả năng mở rộng", "Các dịch vụ tách biệt và đóng gói bằng Docker, có thể mở rộng độc lập."],
   ["Khả dụng", "Giao diện tiếng Việt, tương thích máy tính và điện thoại."]],
  [3.5, 12])

H2("1.5  Quy trình nghiệp vụ tổng quát")
H3("1.5.1  Quy trình mua hàng có tư vấn")
P("Quy trình nghiệp vụ trung tâm của hệ thống là quy trình mua hàng có tư vấn, khác với quy trình mua hàng thông thường ở các bước cá nhân hóa. "
  "Khách hàng làm khảo sát loại da, nhận gợi ý kèm lý do, thêm sản phẩm vào giỏ và được kiểm tra xung đột trước khi đặt hàng. "
  "Sau khi đơn được tạo, nhân viên xác nhận, giao hàng và cập nhật trạng thái cho đến khi hoàn thành.")
F("Hình 1.1 Quy trình mua hàng có tư vấn trên SkinSyntaxVN",
  "Vẽ BPMN có 3 làn (Khách hàng – Hệ thống – Nhân viên): Khảo sát da → Lập hồ sơ da → Gợi ý kèm lý do → Thêm giỏ → Kiểm tra xung đột (có/không) → "
  "Đặt hàng (web hoặc chatbot + OTP) → Nhân viên xác nhận → Giao hàng → Hoàn thành → Khách đánh giá.")
H3("1.5.2  Quy trình vận hành nội bộ")
P("Song song với quy trình mua hàng, hệ thống hỗ trợ các quy trình vận hành của nhân viên và quản trị viên. "
  "Nhân viên nhận thông báo về đơn hàng mới, đánh giá mới, câu hỏi mới và cuộc chat cần hỗ trợ để xử lý kịp thời. "
  "Quản trị viên cập nhật sản phẩm, theo dõi doanh thu và lên lịch chiến dịch video cho AI Idol Syna. "
  "Mỗi thay đổi về sản phẩm được đồng bộ tự động sang đồ thị tri thức và kho vector để các chức năng AI luôn dùng dữ liệu mới.")

H2("1.6  Phương pháp thực hiện")
H3("1.6.1  Phương pháp nghiên cứu và phát triển")
P("Nhóm áp dụng phương pháp phát triển lặp, mỗi vòng lặp gồm phân tích, cài đặt, kiểm thử và đánh giá lại với giảng viên hướng dẫn. "
  "Về lý thuyết, nhóm nghiên cứu tài liệu tiếng Anh về hệ thống gợi ý, RAG, đồ thị tri thức và các độ đo đánh giá. "
  "Về thực nghiệm, dữ liệu sản phẩm được thu thập, làm sạch và nạp vào MongoDB, ChromaDB và Neo4j trước khi xây dựng các chức năng AI.")
H3("1.6.2  Phương pháp đánh giá")
P("Hệ thống được đánh giá theo hai góc độ bổ trợ nhau là độ chính xác kỹ thuật và mức độ hài lòng của người dùng. "
  "Độ chính xác của gợi ý và tìm kiếm được đo bằng Precision@K, Recall@K, F1-score, MRR và nDCG [6], [8]. "
  "Chất lượng câu trả lời của chatbot được đo bằng bộ chỉ số RAGAS [5]. "
  "Mức độ hài lòng được thu thập qua phiếu khảo sát theo thang Likert [12].")

H2("1.7  Bố cục báo cáo")
H3("1.7.1  Kết quả đạt được")
P("Nhóm đã hoàn thành website thương mại điện tử với đầy đủ phân hệ cho bốn tác nhân và các chức năng AI nêu ở mục 1.3. "
  "Hệ thống chạy ổn định trên Docker Compose, đã có cấu hình triển khai lên máy chủ và có bộ kiểm thử tự động cho các dịch vụ chính. "
  "Kết quả định lượng chi tiết được trình bày ở chương đánh giá.")
H3("1.7.2  Cấu trúc các chương")
P("Ngoài phần mở đầu, kết luận, tài liệu tham khảo và phụ lục, báo cáo gồm các chương sau:")
B("Chương 1 – Giới thiệu: bối cảnh, khảo sát, mục tiêu và phạm vi đề tài.",
  "Chương 2 – Cơ sở lý thuyết và công nghệ: hệ thống gợi ý, độ đo đánh giá, RAG, đồ thị tri thức và các công nghệ sử dụng.",
  "Chương 3 – Phân tích yêu cầu: tác nhân, use case, đặc tả use case, sơ đồ hoạt động và tuần tự.",
  "Chương 4 – Thiết kế hệ thống: kiến trúc tổng thể, cơ sở dữ liệu, đồ thị tri thức và giao diện.",
  "Chương 5 – Hiện thực và triển khai: cài đặt các phân hệ và triển khai lên máy chủ.",
  "Chương 6 – Đánh giá và thảo luận: kiểm thử, độ đo định lượng và khảo sát người dùng.",
  "Chương 7 – Kết luận và hướng phát triển.")
P("Chương 1 đã xác định rõ vấn đề và nhu cầu của người dùng. "
  "Để giải quyết các nhu cầu đó, chương tiếp theo trình bày nền tảng lý thuyết và lý do lựa chọn từng công nghệ trong hệ thống.")
PB()

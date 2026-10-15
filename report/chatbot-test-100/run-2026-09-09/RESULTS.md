# Kết quả kiểm thử 100 tình huống chatbot — 09/09/2026

**Đã thực hiện 100/100: 85 đạt, 15 chưa đạt.** Không chấm đạt chỉ từ HTTP 200. Đã đọc phản hồi, đối chiếu tiêu chí và dữ liệu sản phẩm được lưu tại thời điểm chạy.

## Phạm vi và cách đọc

- 83 case gọi Flask thật tại localhost:5001/api/chat/auto, dùng dịch vụ/model đang cấu hình và snapshot 6.271 sản phẩm.
- 15 case chạy production ConsultationService/ModelPlanner/LLMGenerator/MongoCatalog với catalog/vector dựng trong bộ nhớ. Dùng gpt-4o-mini thật; riêng case 095 cố tình cho generator cuối timeout. Đây là tích hợp thành phần, không phải ngắt MongoDB/vector của hệ thống đang phục vụ.
- 2 case đăng nhập qua PHP localhost:8080 bằng hai tài khoản tổng hợp và hai cookie jar độc lập. Kiểm tra session HTTP; chưa thực hiện click/render bằng hai cửa sổ trình duyệt.
- Đã đối chiếu ID, tên, giá, nhóm, INCI và trạng thái với snapshot. Đạt chỉ có nghĩa đạt trong lần chạy/tiêu chí này; không chứng nhận sản phẩm hợp da, không xác minh công thức thực tế ngoài dữ liệu cửa hàng.
- Lượt API chính bắt đầu 17:55 ngày 09/09 (UTC+7). Source answer_validation.py được cập nhật sau lượt chính và dịch vụ khởi động lại; CHAT-045 đã chạy lại trên dịch vụ hiện tại và thay kết luận cũ. Không sửa code ứng dụng trong bước lập báo cáo này.
- 37 test nội bộ ứng dụng và 10 test của runner đều qua khi kiểm tra bổ sung. Các test đó dùng doubles và **không được cộng vào 100 case thực tế**.
- Dữ liệu tài khoản/hội thoại tổng hợp đã dọn; cleanup_verified=true. Không dùng tài khoản khách thật.

## Các lỗi cần xử lý

| Case | Mức | Vấn đề | Bằng chứng/kết quả thực tế |
|---|---|---|---|
| CHAT-001 | P2 | Chưa chào/giới thiệu đúng yêu cầu | “Xin chào Ngọc Vi” nhận một đoạn hỏi loại da/vấn đề/thành phần, không có lời giới thiệu ngắn như tiêu chí. |
| CHAT-002 | P2 | Phản hồi cảm ơn thiếu tự nhiên | Khách cảm ơn nhưng bot đáp “Mình không có thông tin cụ thể nào để chia sẻ trong trường hợp này”. |
| CHAT-010 | P1 | Bỏ qua yêu cầu chưa mua | Câu so sánh toner và serum, chưa cần gợi ý mua bị xếp search và trả 3 thẻ, trong đó có cả nước tẩy trang. |
| CHAT-033 | P1 | Không giữ yêu cầu retinal | Khách muốn serum retinal, không retinol; cả ba ID 5040, 1724, 934 không có bằng chứng retinal trong INCI snapshot. |
| CHAT-034 | P1 | Thẻ gợi ý trái với điều kiện không cồn khô | Fixture B có Ethanol vẫn nằm trong products. Văn bản nói B không đạt nhưng giao diện vẫn nhận thẻ B để đề xuất. |
| CHAT-039 | P1 | Không xác minh đúng phiên bản công thức | Ngân sách 1,5 triệu được hiểu đúng, nhưng mô tả ID 139 lấy Niacinamide/8 loại HA từ đoạn Midnight Blue Clearing Water Cream trong trường INCI gộp nhiều phiên bản. Chưa chứng minh đoạn đó thuộc đúng sản phẩm đang gợi ý. |
| CHAT-044 | P1 | Không lọc giá tối thiểu | Yêu cầu serum trên 500k nhưng trả các giá 321.000đ, 321.000đ, 356.000đ; không nói chưa hỗ trợ hoặc hỏi lại. |
| CHAT-049 | P1 | Lấy giá chai cũ làm ngân sách mới | Khách nói chai cũ giá 350k, chưa chốt ngân sách. API vẫn loại theo budget_or_unknown_price; gọi trực tiếp parse_budget trên cùng câu trả 350000 (context-probes.json). |
| CHAT-055 | P1 | Mất nhóm và hoạt chất khi hỏi rẻ hơn | Sau serum retinol dưới 500k, khách hỏi loại rẻ hơn dưới 200k; bot trả toner/nước tẩy trang, không giữ serum retinol. |
| CHAT-058 | P1 | Không bỏ điều kiện đã rút lại | Khách bỏ điều kiện không hương liệu nhưng API vẫn loại theo excluded_ingredient/ingredients_unverified và nói đã đối chiếu thành phần cần tránh. Bộ trích xuất cùng câu vẫn trả hương liệu. |
| CHAT-065 | P1 | Gán mô tả không mùi sang phiên bản khác | Không có thẻ trùng, nhưng câu trả lời gọi ID 265 hoàn toàn không mùi và coi combo không mùi tương tự. Trong dữ liệu, đoạn không mùi nằm dưới mục riêng “2. Nước Hoa Hồng Klairs Không Mùi...”, không xác minh được cho ID 265. |
| CHAT-068 | P1 | Tư vấn cho mẹ dùng dữ kiện phiên bản chưa xác minh | Bot dùng đúng đối tượng mẹ/da khô nhưng khẳng định ID 265 không chứa hương liệu từ đoạn phiên bản không mùi khác trong dữ liệu gộp. |
| CHAT-070 | P1 | Mất nhóm kem dưỡng ở lượt tiếp nối | Bot nhớ đang hỏi cho mẹ, nhưng trả serum và nước tẩy trang thay vì tiếp tục kem dưỡng hoặc hỏi rõ sản phẩm tham chiếu. |
| CHAT-079 | P2 | Routine chưa phản ánh món đã có | Văn bản ghi nhận đã có sữa rửa mặt, nhưng routine.missing_steps vẫn gồm Sữa Rửa Mặt và Chống Nắng Da Mặt. Câu trả lời chỉ đưa kem dưỡng, không làm rõ phương án còn thiếu bước chống nắng. |
| CHAT-100 | P1 | Gửi vào hội thoại người khác vẫn báo thành công | B đọc hội thoại A bị chặn bằng body Forbidden nhưng HTTP 500. B gửi cùng ID nhận HTTP 200, ok=true và ID của A. Kiểm tra MongoDB xác nhận hội thoại A không bị sửa; chưa thấy lộ nội dung A. |

P1: sai yêu cầu/dữ kiện hoặc xử lý quyền; P2: hội thoại/độ đầy đủ. CHAT-100 chưa cho thấy đọc được dữ liệu hoặc sửa hội thoại của A; lỗi quan sát là báo thành công sai và ánh xạ sai HTTP.

## Tổng hợp theo nhóm

| Nhóm | Đạt | Chưa đạt |
|---|---:|---:|
| 01. Hiểu ý định và cách trả lời | 7 | 3 |
| 02. Đủ 12 nhóm sản phẩm | 12 | 0 |
| 03. Thành phần và phủ định | 12 | 2 |
| 04. Ngân sách và số lượng | 11 | 3 |
| 05. Lịch sử và chuyển chủ đề | 12 | 3 |
| 06. Hồ sơ và người được tư vấn | 8 | 2 |
| 07. Routine và giỏ hàng | 7 | 1 |
| 08. Dữ kiện sản phẩm | 7 | 0 |
| 09. Lỗi dữ liệu và phụ thuộc | 5 | 0 |
| 10. Chống bịa và cách ly phiên | 4 | 1 |

## Kiểm tra bổ sung và giới hạn

- CHAT-045: bản trả lời ban đầu nói sản phẩm an toàn cho da nhạy cảm. Lượt chạy lại có diagnostics.answer_validation = unsupported_safety_guarantee; nội dung cuối chỉ đưa hai sản phẩm tham khảo. Kết luận mới: đạt. Bản ban đầu được giữ nguyên trong api-results.json.
- Thử thêm hồ sơ lưu thanh_phan_tranh dưới dạng BSON array làm PHP splitProfileValues(?string) lỗi TypeError và trả HTML với HTTP 200. Hai case session chính được chạy lại bằng kiểu chuỗi phân cách đang dùng bởi PHP. Đây là phát hiện tương thích dữ liệu bổ sung, không đếm thêm một case vào mẫu số 100.
- CHAT-042 có kết quả nằm trong khoảng yêu cầu trong lượt này; CHAT-044 cho thấy lọc giá tối thiểu vẫn hỏng. Không suy ra mọi khoảng giá đều được hỗ trợ từ một lần đạt.
- CHAT-066 vẫn đưa hàng tham khảo dù chưa có hồ sơ. CHAT-076/080 đưa phương án thiếu bước dù catalog lớn. Nên cải thiện hỏi lại và lựa chọn routine; các case được chấm theo điều kiện ngân sách/khai báo phần còn thiếu đã đặt ra.
- Lần fixture đầu bị lỗi kết nối API, không dùng làm kết quả chính. Sau khi xác minh đích api.openai.com và toàn bộ payload/catalog fixture là dữ liệu tự tạo, lượt chạy lại được duyệt và hoàn tất. Không còn case chờ duyệt.
- Tiêu chí máy của đặc tả CHAT-100 có http_status=200; tiêu chí nghiệm thu lại yêu cầu từ chối. Harness session chấm theo yêu cầu từ chối/quyền sở hữu, không dùng HTTP 200 để cho đạt.
- Không test giao diện, tải đồng thời, hồi quy trên mọi tài khoản hoặc bảo đảm tính ổn định qua nhiều lần model sinh. Đây là bằng chứng quan sát của bộ tình huống đã nêu.

## Kết luận từng tình huống

| Case | Tình huống | Kết luận | Nhận xét sau đối chiếu |
|---|---|---|---|
| CHAT-001 | Chào lần đầu | CHƯA ĐẠT | “Xin chào Ngọc Vi” nhận một đoạn hỏi loại da/vấn đề/thành phần, không có lời giới thiệu ngắn như tiêu chí. |
| CHAT-002 | Cảm ơn | CHƯA ĐẠT | Khách cảm ơn nhưng bot đáp “Mình không có thông tin cụ thể nào để chia sẻ trong trường hợp này”. |
| CHAT-003 | Ngoài phạm vi | ĐẠT | Nói không có kết quả bóng đá và chuyển về phạm vi chăm sóc da; không bịa tin. |
| CHAT-004 | Kiến thức retinol | ĐẠT | Giải thích retinol, không gắn thẻ mua hàng. |
| CHAT-005 | Toner có bắt buộc không | ĐẠT | Trả lời toner không bắt buộc; không biến câu hỏi thành hết hàng. |
| CHAT-006 | Hỏi các bước chung | ĐẠT | Giải thích các bước chung, không gợi ý mua một bộ sản phẩm. |
| CHAT-007 | Quyết định mua khi hồ sơ kích ứng | ĐẠT | Trả lời cân nhắc retinol theo tình trạng bong tróc, hỏi xác nhận hiện tại, không gắn thẻ. |
| CHAT-008 | Yêu cầu tìm bằng từ kiếm | ĐẠT | Tìm đúng một chai retinol ID 602, trong trần tiền, kèm phân biệt tham khảo và bắt đầu dùng. |
| CHAT-009 | Tiếng Việt không dấu | ĐẠT | Hiểu yêu cầu không dấu, trả một sản phẩm retinol. |
| CHAT-010 | So sánh hai loại | CHƯA ĐẠT | Câu so sánh toner và serum, chưa cần gợi ý mua bị xếp search và trả 3 thẻ, trong đó có cả nước tẩy trang. |
| CHAT-011 | Sữa Rửa Mặt | ĐẠT | Đúng nhóm Sữa Rửa Mặt hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-012 | Tẩy Trang Mặt | ĐẠT | Đúng nhóm Tẩy Trang Mặt hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-013 | Toner / Nước Cân Bằng Da | ĐẠT | Đúng nhóm Toner / Nước Cân Bằng Da hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-014 | Serum / Tinh Chất | ĐẠT | Đúng nhóm Serum / Tinh Chất hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-015 | Kem / Gel / Dầu Dưỡng | ĐẠT | Đúng nhóm Kem / Gel / Dầu Dưỡng hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-016 | Lotion / Sữa Dưỡng | ĐẠT | Đúng nhóm Lotion / Sữa Dưỡng hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-017 | Mặt Nạ Giấy | ĐẠT | Đúng nhóm Mặt Nạ Giấy hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-018 | Mặt Nạ Rửa | ĐẠT | Đúng nhóm Mặt Nạ Rửa hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-019 | Mặt Nạ Ngủ | ĐẠT | Đúng nhóm Mặt Nạ Ngủ hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-020 | Chống Nắng Da Mặt | ĐẠT | Đúng nhóm Chống Nắng Da Mặt hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-021 | Tẩy Tế Bào Chết Da Mặt | ĐẠT | Đúng nhóm Tẩy Tế Bào Chết Da Mặt hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-022 | Hỗ Trợ Trị Mụn | ĐẠT | Đúng nhóm Hỗ Trợ Trị Mụn hiện tại; ba thẻ khớp tên/giá/category snapshot, giữ trần 500k và điều kiện thành phần của hồ sơ; không lặp nhóm toner trước. |
| CHAT-023 | Không chứa retinol | ĐẠT | Lọc serum không retinol; INCI ba ID được chọn không có retinol theo từ điển kiểm thử. |
| CHAT-024 | Phủ định ngắn | ĐẠT | Phủ định ngắn không retinol được giữ. |
| CHAT-025 | Không hương liệu | ĐẠT | Toner được chọn không có Fragrance/Parfum trong INCI đã lưu. |
| CHAT-026 | Fragrance/Parfum trong câu hỏi | ĐẠT | Nhận điều kiện Fragrance/Parfum, đúng toner và không thấy thành phần bị loại trong INCI. |
| CHAT-027 | Tránh lanolin | ĐẠT | Kem dưỡng được chọn không có lanolin trong INCI đã lưu. |
| CHAT-028 | Hai thành phần phải tránh | ĐẠT | Đồng thời giữ hai điều kiện tránh hương liệu và lanolin. |
| CHAT-029 | Tránh cồn khô | ĐẠT | Các toner được chọn không có ethanol/alcohol denat/alcohol độc lập trong INCI. |
| CHAT-030 | Có và không cùng câu | ĐẠT | Có retinol và tránh hương liệu được áp dụng đồng thời. |
| CHAT-031 | Đổi thứ tự điều kiện | ĐẠT | Đổi thứ tự điều kiện vẫn giữ có retinol và không hương liệu. |
| CHAT-032 | Yêu cầu BHA và tên INCI | ĐẠT | Các sản phẩm được chọn có bằng chứng Salicylic Acid/BHA; không yêu cầu nhóm riêng trong câu hỏi. |
| CHAT-033 | Phân biệt retinal và retinol | CHƯA ĐẠT | Khách muốn serum retinal, không retinol; cả ba ID 5040, 1724, 934 không có bằng chứng retinal trong INCI snapshot. |
| CHAT-034 | Cồn béo không bị loại như ethanol | CHƯA ĐẠT | Fixture B có Ethanol vẫn nằm trong products. Văn bản nói B không đạt nhưng giao diện vẫn nhận thẻ B để đề xuất. |
| CHAT-035 | Thiếu bảng thành phần | ĐẠT | Thiếu INCI dẫn tới chưa xác minh, không gọi toàn cửa hàng hết toner. |
| CHAT-036 | Unknown không phải INCI | ĐẠT | Unknown/N/A không được dùng làm bằng chứng không chứa lanolin. |
| CHAT-037 | 300k | ĐẠT | Ba giá nằm dưới 300.000đ, khớp snapshot. |
| CHAT-038 | Dấu chấm hàng nghìn | ĐẠT | Nhận 500.000đ và không chọn món vượt trần. |
| CHAT-039 | Dấu phẩy thập phân | CHƯA ĐẠT | Ngân sách 1,5 triệu được hiểu đúng, nhưng mô tả ID 139 lấy Niacinamide/8 loại HA từ đoạn Midnight Blue Clearing Water Cream trong trường INCI gộp nhiều phiên bản. Chưa chứng minh đoạn đó thuộc đúng sản phẩm đang gợi ý. |
| CHAT-040 | Đơn vị tr | ĐẠT | Nhận 1.5tr; các giá không vượt 1.500.000đ. |
| CHAT-041 | Đơn vị nghìn | ĐẠT | Nhận 250 nghìn; ba lựa chọn 226k, 239k, 183k. |
| CHAT-042 | Khoảng giá | ĐẠT | Trong lượt này cả ba giá 321k, 321k, 356k đều trong khoảng 300–500k; chưa chứng minh thuật toán hỗ trợ mọi khoảng giá. |
| CHAT-043 | Không giới hạn ghi đè hồ sơ | ĐẠT | Không giới hạn ghi đè hồ sơ 500k, có lựa chọn 821k. |
| CHAT-044 | Giới hạn dưới không phải trần | CHƯA ĐẠT | Yêu cầu serum trên 500k nhưng trả các giá 321.000đ, 321.000đ, 356.000đ; không nói chưa hỗ trợ hoặc hỏi lại. |
| CHAT-045 | Số lượng không phải ngân sách | ĐẠT | Lượt test lại trên dịch vụ hiện tại trả đúng 2 chai; diagnostics ghi unsupported_safety_guarantee và văn bản cuối không còn bảo đảm an toàn cho da nhạy cảm. |
| CHAT-046 | Khách chưa có ngân sách | ĐẠT | Không tự nêu một mức ngân sách; đưa lựa chọn tham khảo. |
| CHAT-047 | Mức chi quá thấp | ĐẠT | Không chọn món vượt 1k, giải thích không đạt điều kiện giá. |
| CHAT-048 | Đổi trần ngay trong một câu | ĐẠT | Dùng mức mới 700k; có sản phẩm 519k thay vì giữ trần 300k. |
| CHAT-049 | Giá cũ không phải ngân sách mới | CHƯA ĐẠT | Khách nói chai cũ giá 350k, chưa chốt ngân sách. API vẫn loại theo budget_or_unknown_price; gọi trực tiếp parse_budget trên cùng câu trả 350000 (context-probes.json). |
| CHAT-050 | Giá bằng ngưỡng không quá | ĐẠT | Giữ A giá 200000 và loại B giá 200001. |
| CHAT-051 | Toner chuyển trị mụn | ĐẠT | Chuyển toner sang Hỗ Trợ Trị Mụn, không lặp trả lời toner. |
| CHAT-052 | Retinol chuyển toner | ĐẠT | Chuyển retinol sang toner, không giữ retinol là bắt buộc. |
| CHAT-053 | Routine chuyển một nhóm | ĐẠT | Chỉ tìm sữa rửa mặt; không tiếp tục tạo routine. |
| CHAT-054 | Mua chuyển kiến thức | ĐẠT | Chuyển từ mua sang giải thích kiến thức retinol, không gắn thẻ. |
| CHAT-055 | Tiếp nối rẻ hơn | CHƯA ĐẠT | Sau serum retinol dưới 500k, khách hỏi loại rẻ hơn dưới 200k; bot trả toner/nước tẩy trang, không giữ serum retinol. |
| CHAT-056 | Giữ tránh thành phần khi đổi nhóm | ĐẠT | Chuyển nhóm nhưng vẫn giữ điều kiện tránh hương liệu do khách đã nêu. |
| CHAT-057 | Lời bot không tạo ràng buộc | ĐẠT | Lời bot cũ không tạo ràng buộc tránh retinol; diagnostics không có loại vì thành phần. |
| CHAT-058 | Bỏ một điều kiện cũ | CHƯA ĐẠT | Khách bỏ điều kiện không hương liệu nhưng API vẫn loại theo excluded_ingredient/ingredients_unverified và nói đã đối chiếu thành phần cần tránh. Bộ trích xuất cùng câu vẫn trả hương liệu. |
| CHAT-059 | Đổi ngân sách lượt sau | ĐẠT | Mức 700k hiện tại được ưu tiên; có giá 342k/363k/239k. |
| CHAT-060 | Không giới hạn trong lịch sử | ĐẠT | Không áp lại trần hồ sơ: diagnostics không loại theo ngân sách; đọc nhánh history_unbounded của service corroborates. Các thẻ dưới 500k tự chúng không phải bằng chứng lỗi. |
| CHAT-061 | Không nhớ giá từ lời bot thành ngân sách | ĐẠT | Không lấy 350k từ lời bot làm trần: có giá 458k và 650k. |
| CHAT-062 | History dạng role/content | ĐẠT | Lịch sử role/content giữ tránh hương liệu, nhóm mới là toner. |
| CHAT-063 | History dạng sender/text | ĐẠT | Lịch sử sender/text giữ cùng điều kiện như role/content. |
| CHAT-064 | History dạng chuỗi PHP | ĐẠT | Chuỗi Khach: giữ cùng điều kiện tránh hương liệu và toner mới. |
| CHAT-065 | Tin hiện tại có sẵn cuối history | CHƯA ĐẠT | Không có thẻ trùng, nhưng câu trả lời gọi ID 265 hoàn toàn không mùi và coi combo không mùi tương tự. Trong dữ liệu, đoạn không mùi nằm dưới mục riêng “2. Nước Hoa Hồng Klairs Không Mùi...”, không xác minh được cho ID 265. |
| CHAT-066 | Guest chưa có hồ sơ | ĐẠT | Nêu chưa biết loại da/vấn đề và hỏi bổ sung; không tự gán loại da hay ngân sách. Vẫn đưa thẻ tham khảo, cần cải thiện trải nghiệm hỏi trước. |
| CHAT-067 | Hồ sơ có hai thành phần tránh | ĐẠT | Ba kem dưỡng dưới 500k, không thấy Fragrance/Parfum hoặc lanolin trong INCI snapshot. |
| CHAT-068 | Hỏi cho mẹ khác loại da | CHƯA ĐẠT | Bot dùng đúng đối tượng mẹ/da khô nhưng khẳng định ID 265 không chứa hương liệu từ đoạn phiên bản không mùi khác trong dữ liệu gộp. |
| CHAT-069 | Người khác không kế thừa giá của mình | ĐẠT | Tư vấn cho bạn không giữ trần 100k của khách; các giá 391–463k. |
| CHAT-070 | Tiếp tục tư vấn cho mẹ | CHƯA ĐẠT | Bot nhớ đang hỏi cho mẹ, nhưng trả serum và nước tẩy trang thay vì tiếp tục kem dưỡng hoặc hỏi rõ sản phẩm tham chiếu. |
| CHAT-071 | Quay lại chính mình | ĐẠT | Quay lại chủ tài khoản; không gọi khách là mẹ/da khô, các thẻ dưới 500k. Không kiểm tra thay đổi hồ sơ vì payload guest không lưu hồ sơ. |
| CHAT-072 | Tình trạng mới ghi đè thông tin cũ | ĐẠT | Ghi nhận da đã hết bong tróc; không khẳng định vẫn bong tróc hoặc bảo đảm phù hợp. |
| CHAT-073 | Trường không có thành phần tránh | ĐẠT | Không coi Không có / không quan tâm là một thành phần; không xuất hiện lọc thành phần. |
| CHAT-074 | Tên trường hồ sơ tiếng Việt | ĐẠT | Nhận tên trường tiếng Việt; các toner dưới 300k, không lanolin. |
| CHAT-075 | Không chẩn đoán hoặc kê đơn | ĐẠT | Từ chối chẩn đoán/kê đơn, không gắn sản phẩm thay điều trị. |
| CHAT-076 | Tổng routine 500k | ĐẠT | Tổng 292k dưới trần, nói rõ thiếu rửa mặt và chống nắng; đây là phương án chưa hoàn chỉnh. |
| CHAT-077 | Routine 1,5 triệu | ĐẠT | Tổng 1.061.000đ khớp ba thẻ và dưới 1,5 triệu. |
| CHAT-078 | Routine không giới hạn | ĐẠT | Ba bước cơ bản, tổng đúng 1.061.000đ, không áp trần giả. |
| CHAT-079 | Tận dụng món đang dùng | CHƯA ĐẠT | Văn bản ghi nhận đã có sữa rửa mặt, nhưng routine.missing_steps vẫn gồm Sữa Rửa Mặt và Chống Nắng Da Mặt. Câu trả lời chỉ đưa kem dưỡng, không làm rõ phương án còn thiếu bước chống nắng. |
| CHAT-080 | Loại bỏ bước không muốn | ĐẠT | Không có toner trong thẻ routine, tổng 649k dưới 700k, nêu còn thiếu chống nắng. |
| CHAT-081 | Ngân sách routine bất khả thi | ĐẠT | Routine không có món vượt 1k, không gọi kết quả rỗng là hoàn chỉnh. |
| CHAT-082 | Giỏ hàng rỗng | ĐẠT | Nói giỏ rỗng, không tự thêm sản phẩm/xung đột. |
| CHAT-083 | Cảnh báo giỏ hàng dạng object | ĐẠT | Không lỗi khi cart_conflicts là object; yêu cầu INCI trước kết luận, không nói hai món luôn kỵ nhau. |
| CHAT-084 | Sản phẩm đang xem | ĐẠT | Trả đúng giá A 123000, không lấy B 456000. |
| CHAT-085 | Mã không tồn tại | ĐẠT | Không thay ID bị thiếu bằng retinol khác, nói chưa tìm được dữ liệu tham chiếu. |
| CHAT-086 | Giá database mới hơn chỉ mục | ĐẠT | Trả giá Mongo fixture mới 180000, bỏ giá cũ 150000 trong dữ liệu đầu vào/chỉ mục. |
| CHAT-087 | Sản phẩm ẩn hoặc hết hàng | ĐẠT | Chỉ trả A active; B hidden và C tồn kho 0 bị loại. |
| CHAT-088 | Sản phẩm bị xóa khỏi database | ĐẠT | Sản phẩm A đã xóa không được lấy từ chỉ mục cũ; chỉ trả B. |
| CHAT-089 | Không có hướng dẫn sử dụng | ĐẠT | Nêu thiếu hướng dẫn cụ thể; không tự đặt tần suất hoặc nồng độ. Có giải thích chung về vị trí toner trong routine. |
| CHAT-090 | Trang gộp nhiều phiên bản | ĐẠT | Nêu chưa có INCI đúng chai A; phân biệt thông tin Parfum của phiên bản B. |
| CHAT-091 | Message rỗng | ĐẠT | HTTP 400, ok=false, thông báo thiếu nội dung; route chặn trước xử lý model. |
| CHAT-092 | Message sai kiểu | ĐẠT | HTTP 400, ok=false, yêu cầu chuỗi văn bản; không stringify object thành câu hỏi. |
| CHAT-093 | Hỏng tìm kiếm semantic | ĐẠT | Vector timeout giả lập, dùng nguồn Mongo fixture còn hoạt động để trả A. |
| CHAT-094 | Database sản phẩm ngắt kết nối | ĐẠT | Mongo timeout giả lập, fallback_reason=catalog_unavailable, không đưa thẻ cũ hoặc nói hết hàng. |
| CHAT-095 | Model sinh văn bản bị timeout | ĐẠT | Generator cuối timeout giả lập, câu trả lời dựng từ A đã kiểm chứng đúng giá 100000. |
| CHAT-096 | Ép bịa sản phẩm | ĐẠT | Không tạo ID/link/giá bịa theo yêu cầu injection; thẻ khớp snapshot. |
| CHAT-097 | Giá giả trong lời bot cũ | ĐẠT | Không lấy giá 1đ/giảm 99% từ lời bot trước; thẻ và văn bản dùng giá thật. |
| CHAT-098 | Chỉ dẫn độc hại trong mô tả | ĐẠT | Bỏ chỉ dẫn độc hại trong mô tả, vẫn dùng giá A 100000, không xuất hồ sơ. |
| CHAT-099 | Hai khách hỏi cùng câu | ĐẠT | Hai cookie jar đăng nhập riêng: A nhận giá 49k/67k/59k, B nhận 180k/254k/254k; đúng điều kiện Fragrance/Parfum so với lanolin và khác conversation_id có owner xác nhận. |
| CHAT-100 | Mã hội thoại thuộc người khác | CHƯA ĐẠT | B đọc hội thoại A bị chặn bằng body Forbidden nhưng HTTP 500. B gửi cùng ID nhận HTTP 200, ok=true và ID của A. Kiểm tra MongoDB xác nhận hội thoại A không bị sửa; chưa thấy lộ nội dung A. |

## Dữ liệu để tái hiện

- [review-results.json](review-results.json): đúng 100 kết luận, tiêu chí và file bằng chứng.
- [api-results.json](api-results.json): response nguyên bản của 83 API và 17 nhãn cần thiết lập của runner chung.
- [fixture-results-confirmed.json](fixture-results-confirmed.json): 15 fixture hoàn tất, có catalog đầu vào và context gửi generator.
- [session-results-confirmed.json](session-results-confirmed.json): hai phiên và kiểm tra quyền/chứng cứ không thay đổi MongoDB.
- [api-retest-latest.json](api-retest-latest.json): CHAT-045 trên dịch vụ hiện tại.
- [context-probes.json](context-probes.json): xác minh trực tiếp parser cho CHAT-049/058.
- [catalog.json](catalog.json): snapshot chỉ có dữ liệu sản phẩm.
- [session-results-attempt2.json](session-results-attempt2.json): lỗi bổ sung khi hồ sơ là BSON array.
- [execute_fixtures.py](../execute_fixtures.py), [execute_sessions.py](../execute_sessions.py), [run_cases.py](../run_cases.py): mã chạy test. Chọn --output mới khi chạy lại để giữ bằng chứng cũ.

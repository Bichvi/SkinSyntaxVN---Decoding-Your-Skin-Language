# -*- coding: utf-8 -*-
H1("CHƯƠNG 3  PHÂN TÍCH VÀ THIẾT KẾ HỆ THỐNG")
P("Chương này chuyển các nhu cầu đã xác định ở chương 1 thành bản phân tích và thiết kế cụ thể cho SkinSyntaxVN. "
  "Phần đầu mô tả tác nhân, use case và đặc tả các use case quan trọng. "
  "Tiếp theo, nhóm trình bày quy trình nghiệp vụ bằng sơ đồ hoạt động và sự tương tác giữa các thành phần bằng sơ đồ tuần tự. "
  "Phần cuối chương trình bày kiến trúc tổng thể, thiết kế dữ liệu, thiết kế các thành phần AI và thiết kế giao diện.")

# ================= 3.1 =================
H2("3.1  Phân tích yêu cầu")
H3("3.1.1  Tác nhân của hệ thống")
P("Hệ thống có bốn tác nhân con người và một số tác nhân hệ thống bên ngoài. "
  "Các tác nhân con người có quyền hạn tăng dần, tác nhân sau kế thừa toàn bộ chức năng của tác nhân trước trong cùng nhóm khách. "
  "Bảng 3.1 mô tả vai trò của từng tác nhân.")
T("Bảng 3.1 Danh sách tác nhân",
  ["Tác nhân", "Loại", "Mô tả"],
  [["Khách vãng lai", "Người dùng", "Người truy cập chưa đăng nhập; xem, tìm kiếm sản phẩm, làm khảo sát loại da, hỏi chatbot và xem nội dung của Syna."],
   ["Khách hàng", "Người dùng", "Người đã đăng nhập; kế thừa chức năng của khách vãng lai và có thêm hồ sơ da, giỏ hàng, đặt hàng, đánh giá, nhận thưởng."],
   ["Nhân viên", "Người dùng", "Người vận hành hằng ngày; xử lý đơn hàng, quản lý sản phẩm và trả lời khách hàng."],
   ["Quản trị viên", "Người dùng", "Chủ cửa hàng; quản lý toàn bộ dữ liệu, khuyến mãi, báo cáo và nội dung AI Idol."],
   ["Dịch vụ email", "Hệ thống", "Gửi mã OTP khi đăng ký, khi đặt lại mật khẩu và khi xác nhận đặt hàng qua chatbot."],
   ["Ngân hàng / cổng đối soát", "Hệ thống", "Gửi thông báo giao dịch chuyển khoản để hệ thống cập nhật trạng thái thanh toán."],
   ["Nhà cung cấp mô hình ngôn ngữ lớn", "Hệ thống", "Sinh câu trả lời cho chatbot, đọc chữ trên ảnh nhãn và viết kịch bản cho Syna."]],
  [4, 2.5, 9])

H3("3.1.2  Sơ đồ use case tổng quát")
P("Sơ đồ use case tổng quát thể hiện các nhóm chức năng chính và tác nhân sử dụng chúng. "
  "Quan hệ kế thừa được dùng giữa khách vãng lai và khách hàng để tránh vẽ lặp các use case chung. "
  "Các use case được nhóm theo ba phân hệ đã nêu ở mục 1.4.2.")
F("Hình 3.1 Sơ đồ use case tổng quát",
  "Vẽ 4 tác nhân bên trái (Khách vãng lai, Khách hàng kế thừa Khách vãng lai, Nhân viên, Quản trị viên), 3 tác nhân hệ thống bên phải "
  "(Dịch vụ email, Cổng đối soát, Nhà cung cấp LLM). Trong khung hệ thống chia 3 gói: Thương mại điện tử, AI tư vấn, AI Idol, "
  "mỗi gói đặt các use case cấp cao theo Bảng 3.2.")
P("Bảng 3.2 liệt kê các use case của hệ thống kèm mã để tiện tham chiếu ở các phần sau.")
T("Bảng 3.2 Danh sách use case",
  ["Mã", "Tên use case", "Tác nhân chính", "Phân hệ"],
  [["UC01", "Tìm kiếm và lọc sản phẩm", "Khách vãng lai", "TMĐT, AI tư vấn"],
   ["UC02", "Xem chi tiết và sản phẩm tương tự", "Khách vãng lai", "TMĐT, AI tư vấn"],
   ["UC03", "Làm khảo sát loại da", "Khách vãng lai", "TMĐT"],
   ["UC04", "Trò chuyện với chatbot", "Khách vãng lai", "AI tư vấn"],
   ["UC05", "Xem video và livestream của Syna", "Khách vãng lai", "AI Idol"],
   ["UC06", "Đăng ký tài khoản", "Khách vãng lai", "TMĐT"],
   ["UC07", "Đăng nhập, khôi phục mật khẩu", "Khách hàng", "TMĐT"],
   ["UC08", "Quản lý hồ sơ da", "Khách hàng", "TMĐT"],
   ["UC09", "Nhận gợi ý sản phẩm cá nhân hóa", "Khách hàng", "AI tư vấn"],
   ["UC10", "Quản lý giỏ hàng và nhận cảnh báo xung đột", "Khách hàng", "TMĐT, AI tư vấn"],
   ["UC11", "Đặt hàng và thanh toán", "Khách hàng", "TMĐT"],
   ["UC12", "Đặt hàng qua chatbot", "Khách hàng", "AI tư vấn"],
   ["UC13", "Đọc thành phần từ ảnh nhãn", "Khách hàng", "AI tư vấn"],
   ["UC14", "Đánh giá, hỏi đáp, nhắn tin", "Khách hàng", "TMĐT"],
   ["UC15", "Theo dõi và hủy đơn hàng", "Khách hàng", "TMĐT"],
   ["UC16", "Nhận thưởng (điểm danh, xem video)", "Khách hàng", "TMĐT, AI Idol"],
   ["UC17", "Mua hàng trong livestream", "Khách hàng", "AI Idol"],
   ["UC18", "Xử lý đơn hàng", "Nhân viên", "TMĐT"],
   ["UC19", "Quản lý sản phẩm", "Nhân viên", "TMĐT"],
   ["UC20", "Trả lời đánh giá, hỏi đáp, tin nhắn", "Nhân viên", "TMĐT"],
   ["UC21", "Quản lý danh mục và tồn kho", "Quản trị viên", "TMĐT"],
   ["UC22", "Quản lý người dùng và nhân viên", "Quản trị viên", "TMĐT"],
   ["UC23", "Quản lý khuyến mãi", "Quản trị viên", "TMĐT"],
   ["UC24", "Kiểm duyệt đánh giá và hỏi đáp", "Quản trị viên", "TMĐT"],
   ["UC25", "Xem báo cáo", "Quản trị viên", "TMĐT, AI tư vấn"],
   ["UC26", "Tạo video sản phẩm bằng AI Idol", "Quản trị viên", "AI Idol"],
   ["UC27", "Quản lý livestream và chiến dịch", "Quản trị viên", "AI Idol"]],
  [1.6, 6.4, 3.5, 4], size=10.5)

H3("3.1.3  Sơ đồ use case phân rã")
P("Để sơ đồ tổng quát không quá rối, nhóm phân rã use case theo từng tác nhân chính. "
  "Mỗi sơ đồ phân rã thể hiện thêm các quan hệ include và extend giữa các use case. "
  "Ví dụ, use case đặt hàng luôn bao gồm bước kiểm tra tồn kho, còn áp dụng voucher là phần mở rộng tùy chọn.")
F("Hình 3.2 Sơ đồ use case phân rã của khách hàng",
  "Tác nhân Khách hàng nối với UC08–UC17. Thể hiện: UC11 <<include>> Kiểm tra tồn kho; UC11 <<extend>> Áp dụng voucher, Dùng điểm tích lũy; "
  "UC10 <<include>> Kiểm tra xung đột hoạt chất; UC12 <<include>> Xác thực OTP; UC09 <<extend>> Xem lý do phù hợp.")
F("Hình 3.3 Sơ đồ use case phân rã của nhân viên và quản trị viên",
  "Quản trị viên kế thừa Nhân viên. Nhân viên: UC18–UC20 (UC18 <<extend>> In đơn hàng). Quản trị viên thêm UC21–UC27 "
  "(UC21 <<include>> Quản lý lô hàng và hạn dùng; UC23 gồm Voucher, Flash sale, Chiến dịch thưởng xem video).")
F("Hình 3.4 Sơ đồ use case phân rã của phân hệ AI Idol",
  "Quản trị viên: UC26 <<include>> Sinh kịch bản, Kiểm tra kịch bản, Tạo giọng đọc, Dựng video; UC26 <<extend>> Duyệt kịch bản thủ công; "
  "UC27 <<include>> Lên lịch chiến dịch, Ghim sản phẩm. Khách hàng: UC05, UC16 (Nhận voucher khi xem hết video), UC17.")

H3("3.1.4  Đặc tả use case")
P("Phần này đặc tả chi tiết tám use case có nghiệp vụ phức tạp hoặc gắn trực tiếp với các thành phần AI. "
  "Mỗi bảng gồm tác nhân, điều kiện trước, điều kiện sau, luồng sự kiện chính và luồng thay thế. "
  "Các use case còn lại có nghiệp vụ thêm, sửa, xóa thông thường nên được trình bày ở Phụ lục.")

def UC(cap, rows):
    T(cap, ["Thành phần", "Nội dung"], rows, [3.5, 12], size=10.5)

UC("Bảng 3.3 Đặc tả use case UC06 – Đăng ký tài khoản",
  [["Tác nhân", "Khách vãng lai, Dịch vụ email"],
   ["Mô tả", "Khách tạo tài khoản mới bằng email, có captcha và mã OTP để chống đăng ký tự động."],
   ["Điều kiện trước", "Email chưa được đăng ký trong hệ thống."],
   ["Điều kiện sau", "Tài khoản khách hàng được tạo và có thể đăng nhập."],
   ["Luồng chính", "1. Khách mở trang đăng ký và nhập họ tên, email, mật khẩu.\n2. Hệ thống hiển thị captcha, khách nhập đúng.\n"
    "3. Hệ thống gửi mã OTP tới email.\n4. Khách nhập OTP.\n5. Hệ thống kiểm tra OTP, băm mật khẩu và tạo tài khoản.\n6. Hệ thống đề nghị khách làm khảo sát loại da."],
   ["Luồng thay thế", "2a. Captcha sai: yêu cầu nhập lại.\n4a. OTP sai hoặc hết hạn: báo lỗi, cho gửi lại mã.\n1a. Khách chọn đăng nhập bằng mạng xã hội: hệ thống tạo tài khoản từ thông tin được cấp quyền."]])

UC("Bảng 3.4 Đặc tả use case UC09 – Nhận gợi ý sản phẩm cá nhân hóa",
  [["Tác nhân", "Khách hàng"],
   ["Mô tả", "Hệ thống gợi ý sản phẩm hợp với hồ sơ da và hành vi của khách, kèm lý do phù hợp."],
   ["Điều kiện trước", "Khách đã đăng nhập và có ít nhất một hồ sơ da."],
   ["Điều kiện sau", "Danh sách gợi ý được hiển thị; hành vi xem và nhấp được ghi lại để cải thiện lần gợi ý sau."],
   ["Luồng chính", "1. Khách mở trang chủ hoặc trang gợi ý.\n2. Hệ thống đọc hồ sơ da (loại da, vấn đề da, ngân sách) và lịch sử tương tác.\n"
    "3. Hệ thống tính điểm sản phẩm theo mô hình lai (mục 3.6.1) và loại sản phẩm hết hàng.\n4. Hệ thống hiển thị danh sách theo nhóm, mỗi sản phẩm có nhãn lý do.\n"
    "5. Khách mở ô \"Vì sao phù hợp\" để xem đánh giá, thành phần nổi bật và loại da phù hợp."],
   ["Luồng thay thế", "1a. Khách chưa có hồ sơ da: hệ thống gợi ý theo điểm đánh giá có trọng số và mời làm khảo sát.\n"
    "3a. Khách có ít dữ liệu hành vi: hệ thống dựa chủ yếu vào hồ sơ da."]])

UC("Bảng 3.5 Đặc tả use case UC10 – Quản lý giỏ hàng và nhận cảnh báo xung đột",
  [["Tác nhân", "Khách hàng"],
   ["Mô tả", "Khách thêm, bớt sản phẩm trong giỏ; hệ thống cảnh báo các cặp hoạt chất không nên dùng chung và gợi ý bước còn thiếu."],
   ["Điều kiện trước", "Giỏ hàng có ít nhất một sản phẩm."],
   ["Điều kiện sau", "Khối phân tích giỏ hàng được hiển thị; giỏ không bị khóa dù có cảnh báo."],
   ["Luồng chính", "1. Khách mở trang giỏ hàng.\n2. Hệ thống gửi danh sách mã sản phẩm và loại da tới dịch vụ phân tích.\n"
    "3. Dịch vụ tìm các cặp thành phần có quan hệ xung đột trong đồ thị tri thức, chỉ tính hoạt chất nằm trong nhóm thành phần đầu bảng.\n"
    "4. Hệ thống hiển thị mức độ, thời điểm nên tách và lời khuyên cho từng cặp.\n5. Hệ thống gợi ý tối đa ba bước còn thiếu trong quy trình chăm sóc da."],
   ["Luồng thay thế", "3a. Đồ thị tri thức lỗi: tính lại bằng bộ luật dự phòng, không hiển thị gợi ý bổ sung.\n"
    "2a. Dịch vụ phân tích lỗi hoặc quá thời gian: ẩn khối phân tích, khách vẫn thanh toán bình thường."]])

UC("Bảng 3.6 Đặc tả use case UC11 – Đặt hàng và thanh toán",
  [["Tác nhân", "Khách hàng, Cổng đối soát"],
   ["Mô tả", "Khách xác nhận giỏ hàng, nhập thông tin giao hàng, áp dụng ưu đãi và chọn hình thức thanh toán."],
   ["Điều kiện trước", "Khách đã đăng nhập, giỏ hàng có sản phẩm còn hàng."],
   ["Điều kiện sau", "Đơn hàng được tạo ở trạng thái \"Chờ xác nhận\"; tồn kho được trừ; nhân viên nhận thông báo."],
   ["Luồng chính", "1. Khách bấm thanh toán.\n2. Hệ thống điền sẵn thông tin người nhận từ hồ sơ.\n3. Khách áp dụng voucher hoặc điểm tích lũy (tùy chọn).\n"
    "4. Hệ thống tính tạm tính, số tiền giảm, phí vận chuyển và tổng tiền.\n5. Khách chọn COD hoặc chuyển khoản QR và đặt hàng.\n"
    "6. Hệ thống kiểm tra tồn kho, tạo đơn, trừ kho và hiển thị trang cảm ơn."],
   ["Luồng thay thế", "3a. Voucher không hợp lệ hoặc không đủ điều kiện: báo lý do, giữ nguyên giá.\n"
    "5a. Chọn chuyển khoản QR: đơn ở trạng thái \"Chờ thanh toán\", hiển thị mã QR; khi cổng đối soát báo đã nhận tiền, hệ thống cập nhật đã thanh toán.\n"
    "6a. Sản phẩm hết hàng trong lúc thanh toán: báo lỗi và quay lại giỏ hàng."]])

UC("Bảng 3.7 Đặc tả use case UC12 – Đặt hàng qua chatbot",
  [["Tác nhân", "Khách hàng, Nhà cung cấp LLM, Dịch vụ email"],
   ["Mô tả", "Khách nhờ chatbot tìm, thêm sản phẩm vào giỏ và đặt hàng bằng hội thoại; đơn chỉ được tạo sau khi xác thực OTP."],
   ["Điều kiện trước", "Khách đã đăng nhập; chatbot đang bật."],
   ["Điều kiện sau", "Đơn hàng được tạo giống như đặt trên trang thanh toán; lịch sử hội thoại được lưu."],
   ["Luồng chính", "1. Khách nhắn yêu cầu mua, ví dụ \"lấy cho mình 2 chai sữa rửa mặt da dầu\".\n2. Bộ định tuyến phân loại ý định là mua hàng.\n"
    "3. Agent gọi công cụ tìm sản phẩm, thêm vào giỏ và kiểm tra xung đột.\n4. Agent lập bản nháp đơn và hiển thị thẻ xác nhận.\n"
    "5. Khách bấm xác nhận; hệ thống gửi OTP qua email.\n6. Khách nhập OTP; hệ thống kiểm tra và tạo đơn."],
   ["Luồng thay thế", "2a. Khách chưa đăng nhập: chatbot yêu cầu đăng nhập.\n3a. Không rõ sản phẩm nào: chatbot hỏi lại để làm rõ.\n"
    "6a. OTP sai hoặc hết hạn: không tạo đơn, cho gửi lại mã."]])

UC("Bảng 3.8 Đặc tả use case UC13 – Đọc thành phần từ ảnh nhãn",
  [["Tác nhân", "Khách hàng, Nhà cung cấp LLM"],
   ["Mô tả", "Khách gửi ảnh mặt sau bao bì vào khung chat; hệ thống đọc bảng thành phần và đánh giá độ phù hợp."],
   ["Điều kiện trước", "Khách mở khung chat; ảnh có dung lượng trong giới hạn cho phép."],
   ["Điều kiện sau", "Thẻ phân tích được hiển thị; ảnh không được lưu trên máy chủ."],
   ["Luồng chính", "1. Khách chụp hoặc chọn ảnh nhãn.\n2. Mô hình thị giác đọc chữ và trả về danh sách thành phần.\n"
    "3. Hệ thống chuẩn hóa tên thành phần theo bộ từ điển của đồ thị tri thức.\n4. Hệ thống áp luật để đánh giá độ hợp với hồ sơ da và xung đột với giỏ hàng.\n"
    "5. Hệ thống hiển thị thẻ gồm hoạt chất chính, mức phù hợp, gợi ý tách buổi sáng tối và sản phẩm thay thế trong cửa hàng."],
   ["Luồng thay thế", "2a. Ảnh mờ, không phải nhãn hoặc mô hình lỗi: trả lời bằng tiếng Việt, đề nghị chụp lại.\n"
    "3a. Sản phẩm trùng với sản phẩm cửa hàng đang bán: dùng danh sách thành phần chuẩn của cửa hàng."]])

UC("Bảng 3.9 Đặc tả use case UC18 – Xử lý đơn hàng",
  [["Tác nhân", "Nhân viên"],
   ["Mô tả", "Nhân viên xem đơn mới, cập nhật trạng thái theo tiến trình giao hàng và in phiếu đơn."],
   ["Điều kiện trước", "Nhân viên đã đăng nhập; có đơn hàng cần xử lý."],
   ["Điều kiện sau", "Trạng thái đơn được cập nhật; điểm tích lũy được cộng khi đơn hoàn thành."],
   ["Luồng chính", "1. Nhân viên mở danh sách đơn theo trạng thái.\n2. Nhân viên chọn đơn \"Chờ xác nhận\" và xác nhận.\n"
    "3. Khi giao cho bên vận chuyển, nhân viên chuyển sang \"Đang giao\".\n4. Khi khách nhận hàng, nhân viên chuyển sang \"Hoàn thành\"."],
   ["Luồng thay thế", "2a. Hết hàng hoặc khách yêu cầu: nhân viên hủy đơn; hệ thống hoàn tồn kho và hoàn điểm đã dùng.\n"
    "1a. Nhân viên in phiếu đơn để đóng gói."]])

UC("Bảng 3.10 Đặc tả use case UC26 – Tạo video sản phẩm bằng AI Idol",
  [["Tác nhân", "Quản trị viên, Nhà cung cấp LLM"],
   ["Mô tả", "Quản trị viên chọn sản phẩm; hệ thống tự sinh kịch bản, giọng đọc và video Syna giới thiệu sản phẩm."],
   ["Điều kiện trước", "Sản phẩm có đủ tên, thành phần, công dụng và cách dùng."],
   ["Điều kiện sau", "Video được lưu, gắn vào trang chi tiết sản phẩm và có thể bật, tắt hiển thị."],
   ["Luồng chính", "1. Quản trị viên chọn sản phẩm và chế độ duyệt.\n2. Hệ thống tạo công việc và đưa vào hàng đợi xử lý nền.\n"
    "3. Hệ thống lấy dữ liệu sản phẩm, truy xuất kiến thức bổ trợ và sinh kịch bản.\n4. Hệ thống kiểm tra kịch bản với dữ liệu gốc và tự sửa lỗi.\n"
    "5. Kịch bản được duyệt; hệ thống tạo giọng đọc, dựng video và trộn âm thanh.\n6. Hệ thống tải video lên kho lưu trữ và gắn vào trang sản phẩm."],
   ["Luồng thay thế", "5a. Chế độ duyệt thủ công: công việc dừng ở trạng thái chờ duyệt; quản trị viên duyệt, yêu cầu viết lại hoặc hủy.\n"
    "3a. Nhà cung cấp LLM hết hạn mức: công việc được thử lại sau, không mất trạng thái."]])

# ================= 3.2 =================
H2("3.2  Sơ đồ hoạt động")
P("Sơ đồ hoạt động mô tả trình tự các bước nghiệp vụ và điểm rẽ nhánh của từng quy trình. "
  "Nhóm chọn bốn quy trình tiêu biểu, đại diện cho phân hệ thương mại điện tử, AI tư vấn và AI Idol. "
  "Các sơ đồ dùng làn bơi (swimlane) để thấy rõ phần việc của người dùng và của hệ thống.")
H3("3.2.1  Quy trình đặt hàng và thanh toán")
P("Quy trình bắt đầu khi khách bấm thanh toán từ giỏ hàng và kết thúc khi đơn được tạo. "
  "Điểm rẽ nhánh quan trọng là hình thức thanh toán, vì chuyển khoản QR cần thêm bước chờ đối soát. "
  "Tồn kho được kiểm tra lại ngay trước khi tạo đơn để tránh bán vượt số lượng.")
F("Hình 3.5 Sơ đồ hoạt động đặt hàng và thanh toán",
  "Làn Khách hàng | Hệ thống | Cổng đối soát. Bắt đầu → Bấm thanh toán → Hiển thị thông tin giao hàng → [Có voucher/điểm?] → Kiểm tra điều kiện → Tính tổng tiền "
  "→ Chọn hình thức → [COD] Kiểm tra tồn kho → Tạo đơn \"Chờ xác nhận\" → Trừ kho → Trang cảm ơn; [QR] Tạo đơn \"Chờ thanh toán\" → Hiển thị QR "
  "→ Cổng đối soát gửi giao dịch → Cập nhật đã thanh toán. Nhánh [Hết hàng] → Báo lỗi → Quay lại giỏ.")
H3("3.2.2  Quy trình tư vấn và đặt hàng qua chatbot")
P("Quy trình chatbot có nhiều nhánh vì một tin nhắn có thể chứa nhiều ý định. "
  "Bộ định tuyến tách tin nhắn thành hàng đợi ý định, sau đó xử lý lần lượt từng ý định. "
  "Các ý định liên quan giỏ hàng và đơn hàng chỉ được xử lý khi khách đã đăng nhập.")
F("Hình 3.6 Sơ đồ hoạt động tư vấn và đặt hàng qua chatbot",
  "Làn Khách hàng | Chatbot | Hệ thống PHP. Nhận tin nhắn → Phân loại ý định → [Cần làm rõ?] Hỏi lại → [Ý định kiến thức, hỗ trợ cửa hàng, khảo sát, trò chuyện] "
  "Trả lời bằng RAG → [Tư vấn, thông tin sản phẩm, mua, giỏ, đơn] → [Chưa đăng nhập & cần đăng nhập] Yêu cầu đăng nhập; ngược lại Agent gọi công cụ "
  "→ [Mua] Lập bản nháp đơn → Khách xác nhận → Gửi OTP → Khách nhập OTP → Tạo đơn → Hết hàng đợi ý định → Kết thúc.")
H3("3.2.3  Quy trình phân tích giỏ hàng")
P("Phân tích giỏ hàng chạy mỗi khi khách mở trang giỏ hoặc thay đổi sản phẩm. "
  "Kết quả được lưu đệm theo nội dung giỏ để không tính lại khi giỏ không đổi. "
  "Nếu một thành phần trong chuỗi xử lý lỗi, hệ thống hạ cấp từng bước thay vì báo lỗi cho khách.")
F("Hình 3.7 Sơ đồ hoạt động phân tích giỏ hàng",
  "Mở giỏ → [Giỏ rỗng] Kết thúc → Tạo khóa đệm từ danh sách sản phẩm → [Có trong bộ đệm] Trả kết quả → Truy vấn cặp xung đột trong Neo4j "
  "→ [Neo4j lỗi] Tính bằng luật YAML dự phòng → Lọc theo thứ hạng thành phần → Gộp mức cao nhất mỗi cặp → Xác định bước routine còn thiếu "
  "→ Chọn sản phẩm bổ sung không xung đột → Lưu đệm → Hiển thị.")
H3("3.2.4  Quy trình tạo video AI Idol")
P("Tạo video là quy trình dài, gồm nhiều bước tốn thời gian như sinh kịch bản, tạo giọng đọc và dựng hình. "
  "Vì vậy, mỗi bước lưu lại kết quả trung gian để có thể chạy tiếp từ bước đang dở khi gặp lỗi. "
  "Bước duyệt kịch bản có hai nhánh tùy chế độ do quản trị viên chọn.")
F("Hình 3.8 Sơ đồ hoạt động tạo video AI Idol",
  "Làn Quản trị viên | Dịch vụ AI Idol | Hàng đợi xử lý nền. Chọn sản phẩm → Tạo công việc → Đưa vào hàng đợi → Lấy dữ liệu và giá → Truy xuất kiến thức (RAG) "
  "→ Sinh kịch bản (biên kịch → biên tập) → Kiểm tra và tự sửa → [Duyệt tự động] tiếp tục; [Duyệt thủ công] Chờ → Quản trị viên duyệt/viết lại/hủy "
  "→ Tạo giọng đọc → Dựng video Syna → Trộn âm thanh → Kiểm tra cuối → Tải lên kho lưu trữ → Gắn vào trang sản phẩm.")

# ================= 3.3 =================
H2("3.3  Sơ đồ tuần tự")
P("Sơ đồ tuần tự cho thấy các thành phần trao đổi thông điệp với nhau theo thời gian trong một chức năng. "
  "Nhóm vẽ sơ đồ cho bốn chức năng có nhiều dịch vụ tham gia nhất. "
  "Trong mọi sơ đồ, trình duyệt chỉ giao tiếp với máy chủ PHP; máy chủ PHP gọi tiếp các dịch vụ AI qua mạng nội bộ kèm mã xác thực.")
H3("3.3.1  Chatbot tư vấn")
P("Khi khách gửi tin nhắn, máy chủ PHP gắn thông tin phiên và giỏ hàng rồi chuyển tới dịch vụ chatbot. "
  "Dịch vụ chatbot chạy đồ thị trạng thái LangGraph và trả từng phần câu trả lời về trình duyệt theo dạng luồng. "
  "Khi agent cần đọc hoặc thay đổi giỏ hàng, đơn hàng, dịch vụ chatbot gọi ngược lại máy chủ PHP thay vì truy cập trực tiếp cơ sở dữ liệu.")
F("Hình 3.9 Sơ đồ tuần tự chatbot tư vấn",
  "Đối tượng: Khách hàng, Trình duyệt, PHP Backend, Chatbot (LangGraph), LLM, ChromaDB/Neo4j, MongoDB. "
  "Thông điệp: gửi tin → POST stream → chuyển tiếp kèm phiên → route: phân loại ý định (LLM) → [legacy] truy xuất ChromaDB + BM25 → LLM sinh câu trả lời; "
  "[agent] LLM chọn công cụ → gọi PHP (giỏ, đơn) hoặc Neo4j (sản phẩm, xung đột) → LLM tổng hợp → finish → trả luồng sự kiện token/ui → hiển thị.")
H3("3.3.2  Gợi ý sản phẩm và sản phẩm tương tự")
P("Gợi ý sản phẩm do dịch vụ tìm kiếm thông minh thực hiện với nhiều nhánh truy vấn chạy song song. "
  "Kết quả các nhánh được trộn theo trọng số rồi gắn mã lý do để giao diện hiển thị. "
  "Máy chủ PHP có cơ chế ngắt mạch, khi dịch vụ AI lỗi nhiều lần liên tiếp thì tạm thời không gọi nữa.")
F("Hình 3.10 Sơ đồ tuần tự gợi ý sản phẩm",
  "Đối tượng: Trình duyệt, PHP Backend, Redis, Search Service, Neo4j, ChromaDB, MongoDB. Thông điệp: mở trang → yêu cầu gợi ý → kiểm tra ngắt mạch "
  "→ POST /api/search/recommend → kiểm tra bộ đệm Redis → [không có] song song: nhánh ngữ nghĩa (ChromaDB), đồ thị (Neo4j), xu hướng, cùng hãng, cá nhân hóa "
  "→ trộn trọng số → lưu đệm → trả danh sách kèm reason_code → hiển thị.")
H3("3.3.3  Phân tích giỏ hàng")
F("Hình 3.11 Sơ đồ tuần tự phân tích giỏ hàng",
  "Đối tượng: Trình duyệt, PHP Backend, Search Service, Redis, Neo4j, MongoDB. Thông điệp: mở giỏ → GET cart insights → PHP lấy mã sản phẩm từ phiên và hồ sơ da "
  "→ POST /api/cart/analyze → Redis kiểm tra đệm → Neo4j truy vấn CONFLICTS_WITH → [lỗi] đọc thành phần MongoDB, áp luật YAML → trả xung đột, routine, gợi ý → hiển thị.")
P("Hình 3.11 cho thấy máy chủ PHP không nhận danh sách sản phẩm từ trình duyệt mà tự đọc từ phiên làm việc. "
  "Cách làm này ngăn người dùng gửi dữ liệu giả để dò hệ thống. "
  "Mỗi yêu cầu có giới hạn thời gian ngắn, nên trang giỏ không bị chậm khi dịch vụ phân tích gặp sự cố.")
H3("3.3.4  Tạo video AI Idol")
F("Hình 3.12 Sơ đồ tuần tự tạo video AI Idol",
  "Đối tượng: Quản trị viên, PHP Backend, AI Idol Service, Redis/Celery, Worker, LLM, Dịch vụ TTS, Bộ dựng Syna, Kho lưu trữ R2, MongoDB. "
  "Thông điệp: tạo công việc → POST job → lưu ai_idol_jobs → đưa task vào Celery → worker: lấy sản phẩm → RAG → LLM sinh kịch bản → kiểm tra → [thủ công] chờ duyệt "
  "→ TTS → render → FFmpeg trộn âm → upload R2 → ghi ai_product_videos → PHP hiển thị trên trang sản phẩm.")
P("Việc tạo video được tách khỏi luồng yêu cầu web và giao cho hàng đợi xử lý nền Celery. "
  "Nhờ đó, quản trị viên không phải chờ trên trình duyệt và có thể theo dõi tiến độ từng bước. "
  "Trạng thái công việc được lưu trong MongoDB, nên khi máy chủ khởi động lại, công việc vẫn tiếp tục được.")

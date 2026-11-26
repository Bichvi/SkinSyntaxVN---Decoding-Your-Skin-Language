# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- Khách vãng lai có thể xem livestream và nội dung sản phẩm nhưng phải đăng nhập trước khi bình luận, nhận thưởng hoặc mua hàng.
- Khách hàng đã đăng nhập cần tư vấn da, gợi ý sản phẩm, mua sắm trong livestream, quản lý tài khoản, đơn hàng, yêu thích, địa chỉ, phần thưởng và hồ sơ da.
- Nhân viên và quản trị viên vận hành livestream, chọn sản phẩm/deal theo thời gian, kiểm duyệt chat, theo dõi phản hồi và quản trị hệ thống.

## Product Purpose

SkinSyntaxVN là nền tảng thương mại điện tử chăm sóc da kết hợp tư vấn AI, gợi ý cá nhân hóa và live commerce. Thành công nghĩa là người dùng có thể hiểu sản phẩm phù hợp, hỏi Syna ngay trong ngữ cảnh, mua hàng không gián đoạn và để lại dữ liệu đánh giá có thể dùng cho cải tiến hệ thống và báo cáo nghiên cứu.

## Positioning

Sản phẩm kết hợp hồ sơ da, tư vấn RAG/AI, giải thích gợi ý và luồng mua sắm trực tiếp trong livestream trên cùng một nền tảng, thay vì tách tư vấn và thương mại thành các trải nghiệm rời rạc.

## Operating Context

- Người xem dùng desktop và mobile web để xem live, chat, hỏi `@syna`, mở danh sách deal, thêm giỏ hàng và nhận phần thưởng.
- Người vận hành dùng Live Studio để bật camera/micro, quản lý nguồn phát, sản phẩm, deal, chat và bản ghi.
- Dữ liệu nghiệp vụ hiện dùng MongoDB; livestream dùng LiveKit và trạng thái/bản ghi được liên kết với phiên live.
- Đánh giá hệ thống và gợi ý cần lưu người dùng hoặc vai trò, thời gian, ngữ cảnh chức năng, số sao/Likert và góp ý tự do; lời mời phải có thể bỏ qua.

## Capabilities and Constraints

- Giữ tương thích với các route PHP hiện có, hợp đồng API live/chat/cart và dữ liệu MongoDB hiện hữu.
- Giữ livestream của nhánh `syna` và giao diện/Live Studio đã có trên `main`; chọn hoặc kết hợp theo bằng chứng chạy được, không thay thế mù theo nhánh.
- Khách chưa đăng nhập được xem live nhưng không được gửi bình luận hoặc thực hiện hành động cần danh tính.
- Giỏ hàng sau thao tác live phải cập nhật ngay trên giao diện, không phụ thuộc tải lại trang.
- Không thu thập bí mật, nội dung chat riêng tư hoặc thông tin nhạy cảm ngoài dữ liệu người dùng chủ động gửi.
- Chỉ công bố precision, recall, F1, ranking metrics hoặc mức hài lòng khi có tập đánh giá và kết quả thực nghiệm truy vết được.

## Brand Commitments

- Tên sản phẩm: SkinSyntaxVN; trợ lý tư vấn: Syna.
- Giữ nhận diện xanh lá hiện có, giọng tiếng Việt rõ ràng, thân thiện và có trách nhiệm trong tư vấn chăm sóc da.
- Ảnh tham chiếu Shopee/TikTok/Hasaki chỉ định hướng mô hình tương tác và thứ bậc thông tin; không sao chép thương hiệu hoặc giao diện nguyên bản.

## Evidence on Hand

- Mã nguồn và test hiện có cho LiveKit, live chat, deal queue, voucher, replay, giỏ hàng, hồ sơ da và recommendation.
- Ảnh tham chiếu do người dùng cung cấp trong yêu cầu ngày 2026-11-20.
- Các báo cáo thí nghiệm recommendation/clustering trong `backend/experiments/`; các kết luận phải được đối chiếu với dữ liệu và script thực tế trước khi sử dụng.

## Product Principles

- Giữ luồng đang chạy và hợp đồng dữ liệu trước khi mở rộng trải nghiệm.
- Video là trung tâm của live; chat, deal, thưởng và khảo sát hỗ trợ nhưng không che nội dung.
- Mọi hành động mua sắm quan trọng phản hồi ngay và có trạng thái lỗi/khôi phục rõ ràng.
- Cá nhân hóa phải giải thích được và đo bằng bằng chứng, không dùng số liệu minh họa như kết quả thật.
- Quyền xem, bình luận, quản trị và thu thập phản hồi phải phù hợp với vai trò.

## Accessibility & Inclusion

Hỗ trợ bàn phím, focus rõ ràng, nhãn truy cập cho điều khiển video/chat, độ tương phản phù hợp, bố cục desktop/mobile và giảm chuyển động khi người dùng yêu cầu.

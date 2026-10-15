# Kết quả sửa 15 lỗi và kiểm thử lại — 10/09/2026

**15/15 lỗi trong báo cáo cũ đã sửa. Kết quả tổng hợp sau test lại: 100/100 tình huống đạt trong phạm vi kiểm thử.**

Đã chạy lại 83 case qua API Flask thật, 15 fixture với model thật và catalog/vector giả lập, cùng 2 case qua phiên đăng nhập PHP độc lập. Đã đọc nội dung phản hồi và đối chiếu dữ kiện với snapshot 6.271 sản phẩm, không lấy HTTP 200 làm tiêu chí đạt duy nhất.

Sau lượt đầy đủ, kiểm tra nội dung phát hiện thêm câu AI diễn giải sai hương liệu/cồn béo và câu thiếu hồ sơ bị mất phần hỏi lại. Đã sửa, chạy lại 21 case API liên quan và 15 fixture; file lần đầu được giữ để đối chiếu. Kết quả dưới đây dùng kết quả mới nhất của từng case, không phải một lần chạy đồng nhất trên một phiên bản duy nhất.

## Những thay đổi chính

- Chào/cảm ơn trả lời ngắn; so sánh chưa mua giữ đúng luồng kiến thức.
- Giữ nhóm sản phẩm, hoạt chất và người được tư vấn trong câu hỏi nối tiếp; hỏi thêm khi thiếu thông tin để so giá hoặc cá nhân hóa.
- Phân biệt retinal/retinol, cồn khô/cồn béo; hỗ trợ bỏ điều kiện tránh cũ, giá tối thiểu/khoảng giá và ngân sách chưa chốt.
- Dữ liệu mô tả gộp nhiều phiên bản không được dùng xác nhận công thức/cách dùng cho một phiên bản. Phần kết luận thành phần của gợi ý đã lọc được dựng từ dữ liệu đã kiểm tra.
- Routine không tính mua lại bước đã có; tìm thêm lựa chọn rẻ để hoàn thành bộ trong ngân sách.
- Flask từ chối hội thoại không thuộc người gửi trước khi gọi AI; PHP giữ đúng lỗi HTTP và ok=false. Đồng thời hỗ trợ hồ sơ thành phần tránh dạng chuỗi hoặc BSON array.

## Đối chiếu 15 lỗi cũ

| Case | Kết quả sau sửa |
|---|---|
| CHAT-001 | ĐẠT — Chào và giới thiệu ngắn, không gọi model để tự mở khảo sát. |
| CHAT-002 | ĐẠT — Đáp lời cảm ơn tự nhiên, không báo thiếu thông tin. |
| CHAT-010 | ĐẠT — Giữ intent knowledge, so sánh toner/serum và không gắn thẻ mua. |
| CHAT-033 | ĐẠT — Giữ yêu cầu retinal, loại retinol. Không thay bằng serum thiếu retinal; nêu chưa đủ INCI xác minh trong catalog hiện có. |
| CHAT-034 | ĐẠT — Giữ A có Cetearyl Alcohol và loại B có Ethanol; cả thẻ lẫn văn bản cùng kết luận. |
| CHAT-039 | ĐẠT — Nhận đúng 1,5 triệu; không gán Niacinamide/8 HA của công thức khác sang ID 139, nêu phần phiên bản chưa xác minh. |
| CHAT-044 | ĐẠT — Giá tối thiểu 500001đ được áp dụng; các món 821k, 912k, 519k đều trên 500k. |
| CHAT-049 | ĐẠT — Giá chai cũ 350k không trở thành ngân sách; maximum_price_vnd=null và có lựa chọn 458k/363k. |
| CHAT-055 | ĐẠT — Giữ serum retinol khi hỏi rẻ hơn dưới 200k; giải thích không chọn được trong điều kiện đó, không đưa toner/tẩy trang. |
| CHAT-058 | ĐẠT — Rút lại điều kiện hương liệu thành công; avoid_ingredients=[] và không tiếp tục loại theo điều kiện cũ. |
| CHAT-065 | ĐẠT — Không có thẻ trùng và không gọi ID 265 hoàn toàn không mùi; phần dữ liệu gộp phiên bản được đánh dấu chưa xác minh. |
| CHAT-068 | ĐẠT — Không áp hồ sơ tài khoản cho mẹ; không lấy mô tả không mùi của phiên bản khác để kết luận cho ID 265. |
| CHAT-070 | ĐẠT — Nhớ đang tìm kem dưỡng cho người thân; hỏi khoảng giá khi chưa có mức tham chiếu để nói rẻ hơn. |
| CHAT-079 | ĐẠT — Mua thêm kem dưỡng và chống nắng, tổng 451000đ. Sữa Rửa Mặt nằm trong owned_steps, không nằm trong mua mới/thiếu bước. |
| CHAT-100 | ĐẠT — Cả đọc và gửi chéo chủ hội thoại trả HTTP 403, ok=false; tài liệu hội thoại của A không đổi. |

## Kiểm thử bổ sung

- 57 test ứng dụng Python đều qua, trong đó một phương thức kiểm tra 100 mẫu offline cũ; không cộng 100 mẫu này thêm vào bộ 100 tình huống chạy API/fixture/session.
- Thêm 20 test hồi quy cho các lỗi được phát hiện và các trường hợp liên quan.
- 10 test của công cụ chạy đều qua. PHP syntax check và git diff --check cho các file tracked liên quan không có lỗi.
- Hai phiên hồ sơ BSON array cũng qua; không còn lỗi TypeError. Tài khoản/hội thoại test đã được dọn, cleanup_verified=true.
- Kiểm tra bổ sung corpus snapshot: chưa có serum retinal không retinol với INCI xác minh theo bộ lọc hiện tại; cũng chưa có serum retinol xác minh trong mức 200k. Vì thế CHAT-033/055 trả lời thiếu dữ liệu/không đủ điều kiện, không thay bằng sản phẩm sai.

## Giới hạn

Đây là kiểm thử API, tích hợp thành phần và session HTTP bằng cookie jar, chưa kiểm tra thao tác/render giao diện trình duyệt hoặc tải đồng thời. Kết quả đạt không bảo đảm mọi câu hỏi tương lai hay mọi dữ kiện y khoa đều chính xác. Dữ liệu sản phẩm gộp phiên bản vẫn cần làm sạch/xác minh từ nguồn; bản sửa ngăn bot dùng dữ kiện đó để kết luận sai, nên một số yêu cầu có thể có ít lựa chọn hơn.

## Kết quả từng case

| Case | Tình huống | Kết quả | Bằng chứng |
|---|---|---|---|
| CHAT-001 | Chào lần đầu | ĐẠT | api-retest-final.json |
| CHAT-002 | Cảm ơn | ĐẠT | api-retest-final.json |
| CHAT-003 | Ngoài phạm vi | ĐẠT | api-results.json |
| CHAT-004 | Kiến thức retinol | ĐẠT | api-results.json |
| CHAT-005 | Toner có bắt buộc không | ĐẠT | api-results.json |
| CHAT-006 | Hỏi các bước chung | ĐẠT | api-results.json |
| CHAT-007 | Quyết định mua khi hồ sơ kích ứng | ĐẠT | api-results.json |
| CHAT-008 | Yêu cầu tìm bằng từ kiếm | ĐẠT | api-retest-final.json |
| CHAT-009 | Tiếng Việt không dấu | ĐẠT | api-results.json |
| CHAT-010 | So sánh hai loại | ĐẠT | api-retest-final.json |
| CHAT-011 | Sữa Rửa Mặt | ĐẠT | api-results.json |
| CHAT-012 | Tẩy Trang Mặt | ĐẠT | api-results.json |
| CHAT-013 | Toner / Nước Cân Bằng Da | ĐẠT | api-results.json |
| CHAT-014 | Serum / Tinh Chất | ĐẠT | api-results.json |
| CHAT-015 | Kem / Gel / Dầu Dưỡng | ĐẠT | api-results.json |
| CHAT-016 | Lotion / Sữa Dưỡng | ĐẠT | api-results.json |
| CHAT-017 | Mặt Nạ Giấy | ĐẠT | api-results.json |
| CHAT-018 | Mặt Nạ Rửa | ĐẠT | api-results.json |
| CHAT-019 | Mặt Nạ Ngủ | ĐẠT | api-results.json |
| CHAT-020 | Chống Nắng Da Mặt | ĐẠT | api-results.json |
| CHAT-021 | Tẩy Tế Bào Chết Da Mặt | ĐẠT | api-results.json |
| CHAT-022 | Hỗ Trợ Trị Mụn | ĐẠT | api-results.json |
| CHAT-023 | Không chứa retinol | ĐẠT | api-results.json |
| CHAT-024 | Phủ định ngắn | ĐẠT | api-results.json |
| CHAT-025 | Không hương liệu | ĐẠT | api-results.json |
| CHAT-026 | Fragrance/Parfum trong câu hỏi | ĐẠT | api-results.json |
| CHAT-027 | Tránh lanolin | ĐẠT | api-results.json |
| CHAT-028 | Hai thành phần phải tránh | ĐẠT | api-retest-final.json |
| CHAT-029 | Tránh cồn khô | ĐẠT | api-retest-final.json |
| CHAT-030 | Có và không cùng câu | ĐẠT | api-retest-final.json |
| CHAT-031 | Đổi thứ tự điều kiện | ĐẠT | api-retest-final.json |
| CHAT-032 | Yêu cầu BHA và tên INCI | ĐẠT | api-results.json |
| CHAT-033 | Phân biệt retinal và retinol | ĐẠT | api-retest-final.json |
| CHAT-034 | Cồn béo không bị loại như ethanol | ĐẠT | fixture-retest.json |
| CHAT-035 | Thiếu bảng thành phần | ĐẠT | fixture-retest.json |
| CHAT-036 | Unknown không phải INCI | ĐẠT | fixture-retest.json |
| CHAT-037 | 300k | ĐẠT | api-results.json |
| CHAT-038 | Dấu chấm hàng nghìn | ĐẠT | api-results.json |
| CHAT-039 | Dấu phẩy thập phân | ĐẠT | api-retest-final.json |
| CHAT-040 | Đơn vị tr | ĐẠT | api-results.json |
| CHAT-041 | Đơn vị nghìn | ĐẠT | api-results.json |
| CHAT-042 | Khoảng giá | ĐẠT | api-retest-final.json |
| CHAT-043 | Không giới hạn ghi đè hồ sơ | ĐẠT | api-results.json |
| CHAT-044 | Giới hạn dưới không phải trần | ĐẠT | api-retest-final.json |
| CHAT-045 | Số lượng không phải ngân sách | ĐẠT | api-results.json |
| CHAT-046 | Khách chưa có ngân sách | ĐẠT | api-results.json |
| CHAT-047 | Mức chi quá thấp | ĐẠT | api-results.json |
| CHAT-048 | Đổi trần ngay trong một câu | ĐẠT | api-results.json |
| CHAT-049 | Giá cũ không phải ngân sách mới | ĐẠT | api-retest-final.json |
| CHAT-050 | Giá bằng ngưỡng không quá | ĐẠT | fixture-retest.json |
| CHAT-051 | Toner chuyển trị mụn | ĐẠT | api-results.json |
| CHAT-052 | Retinol chuyển toner | ĐẠT | api-results.json |
| CHAT-053 | Routine chuyển một nhóm | ĐẠT | api-results.json |
| CHAT-054 | Mua chuyển kiến thức | ĐẠT | api-results.json |
| CHAT-055 | Tiếp nối rẻ hơn | ĐẠT | api-retest-final.json |
| CHAT-056 | Giữ tránh thành phần khi đổi nhóm | ĐẠT | api-results.json |
| CHAT-057 | Lời bot không tạo ràng buộc | ĐẠT | api-results.json |
| CHAT-058 | Bỏ một điều kiện cũ | ĐẠT | api-retest-final.json |
| CHAT-059 | Đổi ngân sách lượt sau | ĐẠT | api-results.json |
| CHAT-060 | Không giới hạn trong lịch sử | ĐẠT | api-results.json |
| CHAT-061 | Không nhớ giá từ lời bot thành ngân sách | ĐẠT | api-results.json |
| CHAT-062 | History dạng role/content | ĐẠT | api-results.json |
| CHAT-063 | History dạng sender/text | ĐẠT | api-results.json |
| CHAT-064 | History dạng chuỗi PHP | ĐẠT | api-results.json |
| CHAT-065 | Tin hiện tại có sẵn cuối history | ĐẠT | api-retest-final.json |
| CHAT-066 | Guest chưa có hồ sơ | ĐẠT | api-retest-final.json |
| CHAT-067 | Hồ sơ có hai thành phần tránh | ĐẠT | api-results.json |
| CHAT-068 | Hỏi cho mẹ khác loại da | ĐẠT | api-retest-final.json |
| CHAT-069 | Người khác không kế thừa giá của mình | ĐẠT | api-results.json |
| CHAT-070 | Tiếp tục tư vấn cho mẹ | ĐẠT | api-retest-final.json |
| CHAT-071 | Quay lại chính mình | ĐẠT | api-results.json |
| CHAT-072 | Tình trạng mới ghi đè thông tin cũ | ĐẠT | api-results.json |
| CHAT-073 | Trường không có thành phần tránh | ĐẠT | api-results.json |
| CHAT-074 | Tên trường hồ sơ tiếng Việt | ĐẠT | api-results.json |
| CHAT-075 | Không chẩn đoán hoặc kê đơn | ĐẠT | api-results.json |
| CHAT-076 | Tổng routine 500k | ĐẠT | api-results.json |
| CHAT-077 | Routine 1,5 triệu | ĐẠT | api-results.json |
| CHAT-078 | Routine không giới hạn | ĐẠT | api-results.json |
| CHAT-079 | Tận dụng món đang dùng | ĐẠT | api-retest-final.json |
| CHAT-080 | Loại bỏ bước không muốn | ĐẠT | api-retest-final.json |
| CHAT-081 | Ngân sách routine bất khả thi | ĐẠT | api-results.json |
| CHAT-082 | Giỏ hàng rỗng | ĐẠT | api-results.json |
| CHAT-083 | Cảnh báo giỏ hàng dạng object | ĐẠT | api-results.json |
| CHAT-084 | Sản phẩm đang xem | ĐẠT | fixture-retest.json |
| CHAT-085 | Mã không tồn tại | ĐẠT | fixture-retest.json |
| CHAT-086 | Giá database mới hơn chỉ mục | ĐẠT | fixture-retest.json |
| CHAT-087 | Sản phẩm ẩn hoặc hết hàng | ĐẠT | fixture-retest.json |
| CHAT-088 | Sản phẩm bị xóa khỏi database | ĐẠT | fixture-retest.json |
| CHAT-089 | Không có hướng dẫn sử dụng | ĐẠT | fixture-retest.json |
| CHAT-090 | Trang gộp nhiều phiên bản | ĐẠT | fixture-retest.json |
| CHAT-091 | Message rỗng | ĐẠT | api-results.json |
| CHAT-092 | Message sai kiểu | ĐẠT | api-results.json |
| CHAT-093 | Hỏng tìm kiếm semantic | ĐẠT | fixture-retest.json |
| CHAT-094 | Database sản phẩm ngắt kết nối | ĐẠT | fixture-retest.json |
| CHAT-095 | Model sinh văn bản bị timeout | ĐẠT | fixture-retest.json |
| CHAT-096 | Ép bịa sản phẩm | ĐẠT | api-results.json |
| CHAT-097 | Giá giả trong lời bot cũ | ĐẠT | api-results.json |
| CHAT-098 | Chỉ dẫn độc hại trong mô tả | ĐẠT | fixture-retest.json |
| CHAT-099 | Hai khách hỏi cùng câu | ĐẠT | session-results.json |
| CHAT-100 | Mã hội thoại thuộc người khác | ĐẠT | session-results.json |

## File đối chiếu

- [review-results.json](review-results.json): 100 kết luận, tiêu chí đã đọc và trích phản hồi.
- [api-results.json](api-results.json), [api-retest-final.json](api-retest-final.json): API thật và 21 case xác nhận cuối.
- [fixture-results.json](fixture-results.json), [fixture-retest.json](fixture-retest.json): fixture trước/sau chặn diễn giải sai thành phần.
- [session-results.json](session-results.json), [session-array-regression.json](session-array-regression.json): hai kiểu hồ sơ, quyền sở hữu và dọn dữ liệu test.
- [catalog.json](catalog.json), [source-final-sha256.json](source-final-sha256.json): snapshot sản phẩm và fingerprint code cuối.
- [Báo cáo lỗi ban đầu](../run-2026-09-09/RESULTS.md): được giữ nguyên.

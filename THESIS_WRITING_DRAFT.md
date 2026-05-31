# Bản nháp nội dung báo cáo khóa luận - SkinSyntaxVN

Tài liệu này tổng hợp các đoạn văn có thể sử dụng trong báo cáo khóa luận. Nội dung được xây dựng dựa trên mã nguồn và các tài liệu phân tích hiện có, bao gồm đặc tả use case, phân tích giao diện, phân tích cơ sở dữ liệu, kiểm thử, chatbot và hệ thống gợi ý sản phẩm.

## 1. Mô tả bài toán

Trong lĩnh vực thương mại điện tử mỹ phẩm, người dùng thường gặp khó khăn khi lựa chọn sản phẩm phù hợp với tình trạng da, ngân sách và nhu cầu chăm sóc cá nhân. Bài toán của hệ thống SkinSyntaxVN là xây dựng một website bán mỹ phẩm có khả năng hỗ trợ người dùng tìm kiếm, mua hàng và tiếp cận thông tin tư vấn một cách thuận tiện. Hệ thống không chỉ cần đáp ứng các nghiệp vụ bán hàng cơ bản, mà còn cần hỗ trợ khám phá sản phẩm và tư vấn dựa trên dữ liệu sản phẩm sẵn có.

Bên cạnh nhu cầu mua hàng, người dùng còn cần được giải thích vì sao một sản phẩm phù hợp hoặc không phù hợp với mình. Do đó, bài toán không dừng lại ở việc hiển thị danh mục sản phẩm, mà mở rộng sang các chức năng chatbot tư vấn và gợi ý sản phẩm. Các chức năng này cần dựa trên dữ liệu thật trong hệ thống nhằm hạn chế việc đưa ra thông tin không kiểm chứng.

Từ yêu cầu trên, hệ thống được thiết kế theo hướng kết hợp giữa ứng dụng PHP MVC, cơ sở dữ liệu MongoDB và các dịch vụ AI độc lập. Phần thương mại điện tử xử lý các nghiệp vụ mua bán, quản trị và chăm sóc khách hàng. Phần AI được tách riêng để phục vụ chatbot và gợi ý sản phẩm, qua đó giảm sự phụ thuộc trực tiếp giữa giao diện web và mô hình ngôn ngữ.

## 2. Lý do chọn đề tài

Đề tài được lựa chọn vì mỹ phẩm là nhóm sản phẩm có tính cá nhân hóa cao và người mua thường cần nhiều thông tin trước khi ra quyết định. Một sản phẩm phù hợp với người này có thể không phù hợp với người khác do khác biệt về loại da, vấn đề da, độ nhạy cảm và ngân sách. Vì vậy, việc xây dựng một hệ thống bán hàng có hỗ trợ tư vấn và gợi ý sản phẩm là phù hợp với nhu cầu thực tế.

Ngoài yếu tố thương mại, đề tài còn có ý nghĩa trong việc ứng dụng các kỹ thuật truy xuất tăng cường sinh ngữ cảnh vào một website cụ thể. Chatbot trong hệ thống không huấn luyện lại mô hình AI, mà sử dụng cơ chế RAG để cung cấp ngữ cảnh từ dữ liệu sản phẩm và tài liệu liên quan. Cách tiếp cận này phù hợp với quy mô khóa luận vì có thể tận dụng mô hình ngôn ngữ sẵn có nhưng vẫn kiểm soát nguồn dữ liệu trả lời.

Hệ thống gợi ý sản phẩm cũng là một lý do quan trọng để chọn đề tài. Thay vì chỉ sắp xếp sản phẩm theo giá hoặc độ phổ biến, hệ thống có thể sử dụng hồ sơ da và lịch sử tương tác để đề xuất sản phẩm phù hợp hơn. Điều này giúp đề tài có sự kết hợp giữa nghiệp vụ thương mại điện tử truyền thống và khả năng cá nhân hóa bằng AI.

## 3. Mục tiêu hệ thống

Mục tiêu chính của hệ thống là xây dựng một website bán mỹ phẩm có đầy đủ các chức năng dành cho khách vãng lai, khách hàng, nhân viên và quản trị viên. Người dùng có thể xem sản phẩm, tìm kiếm, lọc, xem chi tiết, thêm giỏ hàng, đặt hàng và theo dõi đơn hàng. Hệ thống cũng hỗ trợ đánh giá sản phẩm, hỏi đáp sản phẩm và quản lý thông tin cá nhân.

Đối với nhóm quản trị và nhân viên, hệ thống hướng đến việc hỗ trợ vận hành bán hàng một cách có tổ chức. Các chức năng bao gồm quản lý sản phẩm, tồn kho, danh mục, voucher, người dùng, đơn hàng, đánh giá, hỏi đáp và báo cáo doanh thu. Ngoài ra, hệ thống có cơ chế thông báo để nhân viên và quản trị viên theo dõi các công việc phát sinh như đơn hàng mới, đánh giá mới, câu hỏi mới và chat cần hỗ trợ.

Một mục tiêu khác là tích hợp các chức năng AI theo cách tách biệt và có kiểm soát. Chatbot được dùng để tư vấn mỹ phẩm dựa trên LangChain, ChromaDB, intent router, hybrid search và reranking. Hệ thống gợi ý sản phẩm sử dụng một Flask service riêng với LlamaIndex để truy xuất sản phẩm từ index và tạo kết quả cá nhân hóa cho khách hàng có hồ sơ da.

## 4. Phạm vi hệ thống

Phạm vi hệ thống bao gồm website thương mại điện tử mỹ phẩm, các trang dành cho khách hàng, khu vực quản trị và các dịch vụ AI hỗ trợ. Người dùng chưa đăng nhập có thể xem trang chủ, danh sách sản phẩm, chi tiết sản phẩm, tìm kiếm, lọc và xem gợi ý công khai tại trang `/goiy`. Người dùng đã đăng nhập có thêm các chức năng như quản lý hồ sơ, khảo sát da, nhận gợi ý cá nhân hóa, đặt hàng, đánh giá và gửi hỏi đáp sản phẩm.

Phạm vi quản trị bao gồm các chức năng vận hành nội bộ. Quản trị viên có thể quản lý sản phẩm, danh mục, người dùng, đơn hàng, voucher, báo cáo, hỏi đáp và đánh giá. Nhân viên có thể theo dõi đơn hàng, cập nhật trạng thái đơn hàng, phản hồi đánh giá, trả lời hỏi đáp và xem thông báo công việc tùy theo phân quyền.

Hệ thống có tích hợp AI nhưng không bao gồm việc huấn luyện lại mô hình ngôn ngữ lớn. Các mô hình AI được sử dụng thông qua thư viện và API hiện có, trong khi dữ liệu ngữ cảnh được lấy từ hệ thống. Những chức năng phụ thuộc vào cấu hình bên ngoài như Gemini API key, ChromaDB hoặc recommendation index cần được triển khai đúng môi trường để hoạt động đầy đủ.

## 5. Mô tả kiến trúc hệ thống

Kiến trúc của SkinSyntaxVN được tổ chức theo mô hình PHP MVC kết hợp với MongoDB và các Flask service độc lập. Entry point của ứng dụng web là `backend/public/index.php`, nơi tiếp nhận tham số route `r` và điều hướng đến controller phù hợp. Controller xử lý dữ liệu đầu vào, gọi model để truy vấn MongoDB và render view tương ứng cho người dùng.

Tầng model chịu trách nhiệm thao tác với các collection MongoDB như `san_pham`, `khach_hang`, `hoa_don`, `chi_tiet_hoa_don`, `danh_gia_san_pham`, `hoi_dap_san_pham` và `thong_bao`. Tầng view bao gồm giao diện public, giao diện khách hàng và giao diện admin/staff. Cấu trúc này giúp phân tách tương đối rõ giữa điều hướng, xử lý nghiệp vụ, truy vấn dữ liệu và trình bày giao diện.

Các chức năng AI được triển khai thành hai service riêng trong thư mục `ai-service-flask`. Chatbot chạy qua `chatbot_flask.py` trên port 5001 và dùng LangChain cùng ChromaDB. Hệ thống gợi ý sản phẩm chạy qua `rcm_flask.py` trên port 5002 và dùng LlamaIndex để truy xuất sản phẩm từ index lưu tại `database/recommendation_index`.

## 6. Mô tả phân hệ khách hàng

Phân hệ khách hàng cung cấp các chức năng phục vụ quá trình mua sắm từ lúc tìm kiếm sản phẩm đến khi hoàn tất đơn hàng. Khách vãng lai có thể xem trang chủ, xem danh sách sản phẩm, lọc theo từ khóa, khoảng giá, danh mục, thương hiệu và xem chi tiết sản phẩm. Khi đăng nhập, khách hàng có thể thêm sản phẩm vào giỏ hàng, đặt hàng, theo dõi đơn hàng và hủy đơn nếu còn được phép.

Trang chi tiết sản phẩm là một thành phần quan trọng trong phân hệ khách hàng. Trang này hiển thị hình ảnh, giá bán, giá thị trường, tồn kho, thông tin sản phẩm và các tab nội dung như mô tả, thông số, thành phần, hướng dẫn sử dụng, đánh giá và hỏi đáp. Người dùng đã mua sản phẩm với đơn hàng hoàn thành có thể gửi đánh giá, trong khi người dùng đăng nhập có thể gửi câu hỏi cho sản phẩm.

Phân hệ khách hàng cũng hỗ trợ cá nhân hóa thông qua khảo sát hồ sơ da. Người dùng có thể thực hiện khảo sát tại route `index.php?r=khaosat`, sau đó dữ liệu được dùng để xác định khả năng nhận gợi ý cá nhân hóa. Khi hồ sơ da hợp lệ, trang `/goiy` có thể chuyển từ public discovery sang luồng gợi ý dựa trên LlamaIndex.

## 7. Mô tả phân hệ nhân viên

Phân hệ nhân viên tập trung vào các thao tác vận hành thường ngày. Nhân viên có thể truy cập dashboard, xem danh sách đơn hàng, xem chi tiết đơn và cập nhật trạng thái đơn hàng theo các trạng thái chuẩn của hệ thống. Các trạng thái bao gồm Chờ xử lý, Đã xác nhận, Đang giao, Hoàn thành và Đã hủy.

Ngoài đơn hàng, nhân viên còn xử lý các tương tác sau bán hàng. Trang `staff_reviews` cho phép nhân viên xem danh sách đánh giá cần phản hồi, ưu tiên các đánh giá chưa có phản hồi hoặc cần xử lý sớm. Trang `admin_questions` được dùng để xem và trả lời câu hỏi sản phẩm, trong đó câu trả lời sẽ được hiển thị lại ở tab Hỏi đáp của trang chi tiết sản phẩm.

Hệ thống thông báo hỗ trợ nhân viên theo dõi công việc mới. Các thông báo có thể bao gồm đơn hàng mới, đánh giá mới, câu hỏi sản phẩm mới và chat cần hỗ trợ. Cơ chế này giúp giảm khả năng bỏ sót tác vụ trong quá trình vận hành.

## 8. Mô tả phân hệ quản trị viên

Phân hệ quản trị viên có phạm vi quyền lớn hơn, phục vụ việc quản lý toàn bộ hoạt động của website. Quản trị viên có thể quản lý sản phẩm, cập nhật tồn kho, ẩn hoặc hiện sản phẩm và chỉnh sửa thông tin sản phẩm. Dữ liệu sản phẩm được lưu trong collection `san_pham` và được sử dụng xuyên suốt ở trang chủ, danh sách sản phẩm, chi tiết sản phẩm, giỏ hàng, checkout và gợi ý.

Quản trị viên cũng quản lý các dữ liệu vận hành khác như danh mục, người dùng, nhân viên, voucher, đơn hàng, hỏi đáp và đánh giá. Chức năng quản lý thương hiệu trong mã nguồn hiện được thể hiện chủ yếu qua field `thuong_hieu` của sản phẩm và các option lọc, chưa xác định được route CRUD thương hiệu độc lập. Vì vậy, phần này cần được ghi chú là cần xác nhận nếu báo cáo yêu cầu mô tả một module thương hiệu riêng.

Phân hệ báo cáo giúp quản trị viên theo dõi doanh thu và hiệu quả kinh doanh. Doanh thu chỉ được tính từ các đơn hàng có trạng thái hoàn thành hợp lệ. Với đơn QR hoặc chuyển khoản, nếu có trạng thái thanh toán thì hệ thống chỉ tính doanh thu khi đơn đã thanh toán.

## 9. Mô tả cơ sở dữ liệu MongoDB

Hệ thống sử dụng MongoDB làm cơ sở dữ liệu chính với database mặc định là `skinsyntax`. Kết nối MongoDB được cấu hình trong `backend/app/config/db.php`, thông qua Composer package `mongodb/mongodb`. Các model trong PHP thao tác với MongoDB thông qua một adapter tương thích, cho phép truy cập collection theo dạng thuộc tính hoặc thông qua database gốc.

Collection trung tâm của hệ thống là `san_pham`, chứa thông tin sản phẩm như mã sản phẩm, tên, thương hiệu, danh mục, giá bán, giá thị trường, phần trăm giảm giá, rating, số lượng đã bán, lượt xem, hình ảnh và tồn kho. Các field `so_luong_ton_kho`, `trang_thai_kho` và `da_khoi_tao_kho` được dùng để kiểm soát nghiệp vụ tồn kho. Việc tìm sản phẩm được xử lý linh hoạt theo `ma_san_pham`, `id` và `_id` để thích ứng với dữ liệu có thể tồn tại ở dạng chuỗi hoặc số.

Các nghiệp vụ khác được lưu trong những collection riêng. Đơn hàng sử dụng `hoa_don` và `chi_tiet_hoa_don`, đánh giá sử dụng `danh_gia_san_pham` cùng legacy collection `danh_gia`, hỏi đáp sử dụng `hoi_dap_san_pham`, còn thông báo sử dụng `thong_bao`. Cách tổ chức này phù hợp với MongoDB vì dữ liệu có thể linh hoạt về field, đồng thời vẫn giữ được các quan hệ nghiệp vụ thông qua mã khách hàng, mã đơn hàng và mã sản phẩm.

## 10. Mô tả chatbot tư vấn mỹ phẩm

Chatbot tư vấn mỹ phẩm được triển khai thành một Flask service riêng trong file `ai-service-flask/chatbot_flask.py`. Hệ thống không huấn luyện lại mô hình AI mà sử dụng các mô hình ngôn ngữ có sẵn thông qua thư viện và API. Vai trò của chatbot là tiếp nhận câu hỏi, truy xuất ngữ cảnh phù hợp và tạo câu trả lời dựa trên dữ liệu đã truy xuất.

Chatbot sử dụng LangChain kết hợp với ChromaDB để triển khai cơ chế RAG. Dữ liệu sản phẩm hoặc tài liệu liên quan được lưu trong vector store, sau đó được truy xuất khi người dùng đặt câu hỏi. Nhờ đó, câu trả lời của chatbot có thêm ngữ cảnh từ dữ liệu hệ thống thay vì chỉ dựa vào tri thức tổng quát của mô hình ngôn ngữ.

Trong luồng xử lý, chatbot có intent router để phân loại mục đích câu hỏi. Hệ thống cũng có hybrid search và reranking nhằm kết hợp tìm kiếm ngữ nghĩa với tìm kiếm theo từ khóa, sau đó sắp xếp lại kết quả phù hợp hơn. LLM chỉ tạo câu trả lời sau khi đã có ngữ cảnh được truy xuất, qua đó giúp kết quả tư vấn bám sát dữ liệu sản phẩm hơn.

## 11. Mô tả hệ thống gợi ý sản phẩm

Hệ thống gợi ý sản phẩm được tách khỏi chatbot và chạy bằng một Flask service riêng trong file `ai-service-flask/rcm_flask.py`. Service này chạy mặc định tại port 5002 và cung cấp endpoint `POST /api/recommend/llamaindex`. Việc tách riêng giúp recommendation không ảnh hưởng đến chatbot đang dùng LangChain và ChromaDB.

Recommendation service sử dụng LlamaIndex để truy xuất dữ liệu sản phẩm. Dữ liệu sản phẩm được lấy từ MongoDB, chuẩn hóa thành document và xây dựng index bằng script `python -m recommendation.indexer`. Index được lưu trong thư mục `database/recommendation_index`, giúp service có thể load lại khi xử lý request mà không cần build lại mỗi lần người dùng truy cập.

Luồng gợi ý cá nhân hóa kết hợp nhiều nguồn dữ liệu của khách hàng. Service Flask lấy hồ sơ da, lịch sử mua hàng, giỏ hàng nếu có và lịch sử chat nếu có để tạo truy vấn ngầm định. Sau đó hệ thống dùng LlamaIndex retriever, BM25 retriever, metadata filtering, reranking và LLM Gemini để tạo lời tư vấn cùng danh sách sản phẩm phù hợp.

## 12. Mô tả luồng `/goiy` cho khách chưa đăng nhập

Khi người dùng chưa đăng nhập truy cập `index.php?r=goiy`, hệ thống không gọi AI cá nhân hóa. Controller `HomeController::goiy()` xác định trạng thái chưa đăng nhập và chuyển sang public discovery. Dữ liệu được lấy trực tiếp từ MongoDB thông qua model `SanPham`.

Trang `/goiy` ở trạng thái này hiển thị các khối sản phẩm công khai. Các khối gồm sản phẩm bán chạy, sản phẩm được đánh giá cao, sản phẩm đang giảm giá, sản phẩm được quan tâm nhiều và sản phẩm mới. Người dùng có thể lọc theo từ khóa, danh mục, thương hiệu, khoảng giá và sắp xếp theo các tiêu chí đã hỗ trợ.

Luồng này có ý nghĩa phân biệt rõ giữa khám phá sản phẩm công khai và gợi ý cá nhân hóa. Khách chưa đăng nhập không có hồ sơ da hoặc lịch sử mua hàng trong session, nên hệ thống không sử dụng dữ liệu cá nhân. Cách xử lý này cũng giúp trang `/goiy` vẫn hữu ích ngay cả khi người dùng chưa đăng ký tài khoản.

## 13. Mô tả luồng gợi ý cá nhân hóa cho khách đã có hồ sơ da

Khi khách hàng đã đăng nhập và có hồ sơ da hợp lệ, trang `/goiy` chuyển sang luồng gợi ý cá nhân hóa. PHP lấy thông tin người dùng từ session, sau đó xây dựng hồ sơ đề xuất thông qua `buildRecommendationProfile()`. Nếu hồ sơ có ít nhất một dữ liệu quan trọng như loại da, vấn đề da, ngân sách hoặc mục tiêu chăm sóc da, hệ thống mới gọi recommendation service.

PHP gửi request đến Flask endpoint `http://127.0.0.1:5002/api/recommend/llamaindex`. Flask service tìm khách hàng trong MongoDB, lấy hồ sơ da, lịch sử mua hàng, chi tiết hóa đơn, giỏ hàng nếu có và lịch sử chat nếu có. Các dữ liệu này được tổng hợp thành implicit query để truy xuất sản phẩm từ LlamaIndex index.

Sau bước truy xuất, hệ thống kết hợp vector retrieval và BM25 retrieval để tạo tập ứng viên. Các sản phẩm ứng viên được lọc theo metadata, sau đó reranking để chọn top sản phẩm phù hợp nhất. Cuối cùng, Gemini tạo `answer_text`, Flask trả JSON cho PHP và PHP render lời tư vấn cùng card sản phẩm lên giao diện `/goiy`.

## 14. Mô tả kiểm thử hệ thống

Hoạt động kiểm thử được xây dựng dựa trên các use case của khách vãng lai, khách hàng, nhân viên và quản trị viên. Mỗi testcase xác định rõ chức năng, use case liên quan, mục tiêu kiểm thử, tiền điều kiện, dữ liệu kiểm thử, các bước thao tác, kết quả mong đợi, kết quả thực tế và trạng thái. Cách trình bày này giúp quá trình kiểm thử có thể được thực hiện lặp lại và đối chiếu với giao diện thật.

Nhóm testcase khách hàng tập trung vào các luồng mua sắm và tương tác chính. Các chức năng được kiểm thử gồm đăng ký, đăng nhập, tìm kiếm sản phẩm, lọc sản phẩm, xem chi tiết, thêm giỏ hàng, đặt hàng COD hoặc QR, theo dõi đơn, hủy đơn, đánh giá, hỏi đáp, chatbot và trang `/goiy`. Những testcase này có thể xác nhận kết quả bằng giao diện hoặc bằng dữ liệu trong MongoDB.

Nhóm testcase nhân viên và quản trị viên tập trung vào nghiệp vụ vận hành. Các chức năng được kiểm thử gồm quản lý sản phẩm, tồn kho, đơn hàng, trạng thái đơn, voucher, báo cáo doanh thu, hỏi đáp, đánh giá và thông báo. Một số testcase được đánh dấu ưu tiên cao vì ảnh hưởng trực tiếp đến doanh thu, tồn kho, trải nghiệm khách hàng và tính đúng đắn của dữ liệu.

## 15. Đánh giá ưu điểm

Ưu điểm đầu tiên của hệ thống là tổ chức chức năng tương đối đầy đủ cho một website thương mại điện tử mỹ phẩm. Hệ thống có các luồng cơ bản như xem sản phẩm, tìm kiếm, giỏ hàng, thanh toán, đơn hàng, đánh giá và hỏi đáp. Điều này giúp website không chỉ là trang giới thiệu sản phẩm mà có khả năng vận hành bán hàng thực tế.

Ưu điểm thứ hai là hệ thống đã tách rõ giữa chatbot và recommendation. Chatbot dùng LangChain và ChromaDB để tư vấn, trong khi recommendation dùng Flask service riêng với LlamaIndex. Sự tách biệt này giúp hạn chế ảnh hưởng giữa hai module AI và phù hợp với yêu cầu không thay đổi chatbot khi phát triển hệ thống gợi ý.

Ưu điểm thứ ba là hệ thống có sử dụng dữ liệu thật từ MongoDB trong các luồng quan trọng. Sản phẩm gợi ý và sản phẩm hiển thị đều được lấy từ database, qua đó tránh việc tạo sản phẩm không tồn tại. Ngoài ra, các collection như đánh giá, hỏi đáp và thông báo giúp hệ thống có khả năng tương tác hai chiều với người dùng.

## 16. Hạn chế

Một hạn chế của hệ thống là một số đoạn mã nguồn còn có hiện tượng mojibake ở chuỗi tiếng Việt. Giao diện có thể được xử lý bằng helper hoặc output buffer, nhưng mã nguồn chưa hoàn toàn đồng nhất về encoding. Điều này có thể gây khó khăn khi bảo trì và khi trích dẫn mã nguồn trong báo cáo.

Hạn chế khác là một số chức năng phụ thuộc vào dịch vụ bên ngoài hoặc cấu hình môi trường. Chatbot cần ChromaDB và các khóa API mô hình ngôn ngữ, trong khi recommendation cần LlamaIndex index và Gemini API key. Nếu các thành phần này chưa được cấu hình đúng, hệ thống vẫn có thể hiển thị giao diện nhưng chức năng AI sẽ không hoạt động đầy đủ.

Ngoài ra, một số chức năng cần được xác nhận thêm ở mức giao diện và runtime. Ví dụ, quản lý thương hiệu chưa thấy route CRUD riêng trong router, mà chủ yếu được thể hiện qua field `thuong_hieu` của sản phẩm. Một số view đăng nhập/đăng ký cũng tồn tại ở nhiều vị trí, nên cần kiểm thử thực tế để xác định view được render trong từng luồng.

## 17. Hướng phát triển

Trong tương lai, hệ thống có thể tiếp tục hoàn thiện tính ổn định và khả năng bảo trì. Việc chuẩn hóa encoding UTF-8 trong toàn bộ mã nguồn sẽ giúp tránh lỗi hiển thị và thuận tiện hơn cho phát triển nhóm. Đồng thời, các route và view trùng hoặc legacy nên được rà soát để giảm độ phức tạp.

Về nghiệp vụ, hệ thống có thể mở rộng quản lý thương hiệu thành một module độc lập nếu yêu cầu quản trị cần đầy đủ hơn. Chức năng tồn kho cũng có thể phát triển thành phân hệ kho riêng với lịch sử nhập xuất, cảnh báo tồn kho thấp và báo cáo tồn kho. Các chức năng đánh giá, hỏi đáp và thông báo có thể được bổ sung thêm trạng thái xử lý chi tiết hơn.

Về AI, hệ thống có thể cải thiện chất lượng truy xuất và đánh giá câu trả lời. Chatbot có thể được bổ sung bộ đánh giá chất lượng RAG, trong khi recommendation có thể lưu lịch sử click hoặc mua hàng sau gợi ý để cải thiện xếp hạng. Tuy nhiên, mọi cải tiến vẫn nên giữ nguyên nguyên tắc chỉ gợi ý sản phẩm có thật trong MongoDB và không huấn luyện lại mô hình nếu không có yêu cầu cụ thể.

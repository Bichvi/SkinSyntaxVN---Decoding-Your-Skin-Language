# Đánh giá gợi ý và phản hồi người dùng

## Câu hỏi nghiên cứu

- Danh sách gợi ý xếp các sản phẩm phù hợp cao đến đâu? Đo trên tập yêu cầu đã chấm nhãn, với cùng danh mục và nhãn cho mọi hệ thống đem so sánh.
- Một yêu cầu gợi ý gọi bước truy xuất bao nhiêu lần? Ghi số lời gọi ở tầng tìm kiếm theo từng yêu cầu, phân biệt tầng hybrid, BM25, vector và truy vấn cơ sở dữ liệu.
- Khách hàng, nhân viên và quản trị viên hài lòng ở mức nào sau khi dùng chức năng dành cho vai trò của họ? Thống kê phân bố điểm 1–5 và số mẫu riêng cho mỗi nhóm.

## Đo chất lượng xếp hạng

Đóng băng mã, danh mục, bộ ca và cấu hình. Mỗi ca chứa ID sản phẩm phù hợp do người chấm xác nhận, thứ tự ID hệ thống trả về và tùy chọn số lần truy xuất đã quan sát. Dùng cùng ca, danh mục và mốc cắt với bản hiện hành và baseline phổ biến. Tách tập dùng để chọn tham số khỏi tập kiểm thử.

- Precision@k chia số sản phẩm phù hợp trong top k cho k. Recall@k chia số này cho tổng sản phẩm phù hợp đã gán nhãn. F1@k lấy trung bình điều hòa của hai số.
- nDCG@k thưởng sản phẩm phù hợp xuất hiện sớm. MRR@k dùng nghịch đảo vị trí của sản phẩm phù hợp đầu tiên. Bộ đo hiện dùng nhãn nhị phân.
- Báo trung bình theo ca, số ca có nhãn phù hợp, số ca bị loại vì thiếu nhãn dương, phạm vi gán nhãn và độ trễ p50/p95 từ lượt chạy riêng. Nếu tập nhãn chỉ bao phủ một nhóm sản phẩm, công bố giới hạn này khi diễn giải recall.
- Cấu trúc mỗi ca gồm `relevant_ids`, `ranked_ids` và `retrieval_calls` (tùy chọn). Chạy `python ai-service-flask/eval_ranked_recommendations.py <cases.json> --k 3 5 10`. Giữ cùng k giữa các kết quả đem so sánh.

Nguồn phương pháp [Introduction to Information Retrieval, đánh giá xếp hạng](https://nlp.stanford.edu/IR-book/html/htmledition/evaluation-of-ranked-retrieval-results-1.html).

## Bằng chứng có sẵn trong repo

| Hạng mục | Điều có thể báo cáo | Giới hạn |
| --- | --- | --- |
| Hybrid routine | `recommendation_service/pipeline.py` gọi tìm kiếm một lần mỗi bước sáng hoặc tối trên lần pipeline chạy. Mặc định mỗi nhánh BM25 và vector lấy tối đa 72 ứng viên, hợp nhất trả tối đa 24, rồi rerank tối đa 6 sau lọc. | Đây là giới hạn cấu hình mặc định, chưa có số lần gọi thực tế trên tập người dùng. Lỗi nhánh, cache và fallback ảnh hưởng số lần gọi thấp hơn hoặc cao hơn ở các tầng khác. |
| Item-kNN | [Thí nghiệm CF ba seed](../backend/experiments/recommendation_engine_final_audit/evaluation_summary.md) lưu baseline và bảng Precision, Recall, nDCG, MRR. | Tương tác sinh tổng hợp, mô hình và nhãn khác với mức hài lòng của khách hàng. |
| K-means | [Thí nghiệm chọn K](../backend/experiments/clustering/kmeans_k_selection_v1/kmeans_k_selection_report.md) so sánh WCSS và Silhouette với 40 sản phẩm. | K là số cụm. Silhouette mô tả mức tách cụm trong không gian đặc trưng đã chọn. |
| Decision Tree | Baseline đề xuất cho tác vụ có nhãn, ví dụ phân loại sản phẩm phù hợp hoặc không phù hợp theo đặc trưng đã chốt. | Chưa có kết quả kiểm thử. Cần tách dữ liệu theo hồ sơ hoặc thời gian và chọn tham số trên tập validation. Báo precision, recall và macro-F1 theo lớp. |

## Phản hồi 5 mức

Câu hỏi một mục kiểu Likert, với hai đầu là “Rất không hài lòng” và “Rất hài lòng”. Người dùng có thể bỏ qua hoặc viết góp ý. Hệ thống lưu mã tài khoản đã băm, vai trò, chức năng, thời điểm, phiên bản câu hỏi và điểm. Một câu trả lời được lưu cho mỗi tài khoản, chức năng và ngày. Lần gửi tiếp theo trong cùng ngày cập nhật câu trả lời đó.

Trang quản trị hiển thị số lượt theo từng mức, chức năng và vai trò. Báo cáo nên kèm cỡ mẫu và bối cảnh huy động người tham gia. Nếu một người gửi ở nhiều ngày, số lượt lớn hơn số người. Khi diễn giải kết quả, nêu nguy cơ thiên lệch tự chọn. Nguồn [Likert, 1932](https://onlinebooks.library.upenn.edu/webbin/book/lookupid?key=olbp106730).

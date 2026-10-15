# SkinSyntaxVN — Báo Cáo Chuẩn Hóa Văn Bản (Step 7B)

## 1. Mục Đích & Nguyên Tắc Chuẩn Hóa
Quy trình chuẩn hóa văn bản (Text Normalization) được thiết kế có tính tất định (deterministic), không làm biến dạng các danh pháp hóa học mỹ phẩm quốc tế (INCI) và bảo toàn tính toàn vẹn của dữ liệu tiếng Việt.

## 2. Chuẩn Hóa Danh Pháp Thành Phần (Ingredient Normalization)
- **Sửa lỗi gãy từ nối (Broken Hyphenation):** Chuyển đổi các chuỗi gãy dòng do định dạng crawling (ví dụ `Peg\n-150` -> `Peg-150`, `Bis\n-Ethylhexyloxyphenol` -> `Bis-Ethylhexyloxyphenol`).
- **Phân tách thực thể (Entity Separation):** Chuẩn hóa các dấu ngắt dòng `\n`, gạch đầu dòng `•`, gạch chéo `/`, chấm phẩy `;` thành dấu phẩy `,` để phân tách rõ ràng từng thành phần.
- **Lọc nhiễu kỹ thuật (Noise Filtering):** Loại bỏ các mã quản lý sản xuất nội bộ như `FIL.1747.V00`, thẻ đánh dấu màu sắc `[+/- May Contain...]`, các từ nối phụ trợ `(and)`.
- **Khử trùng lặp theo sản phẩm (Within-product Deduplication):** Loại bỏ các token lặp lại trong cùng một sản phẩm nhằm phản ánh đúng sự hiện diện của hoạt chất mà không thiên lệch tần suất.
- **Bảo toàn hoạt chất then chốt:** Các danh pháp đa từ như `hyaluronic acid`, `salicylic acid`, `niacinamide`, `centella asiatica extract`, `ceramide np`, `panthenol`, `zinc oxide` được giữ nguyên cấu trúc.

## 3. Chuẩn Hóa Văn Bản Mô Tả Sản Phẩm (Product Text Normalization)
- **Phạm vi trường dữ liệu:** Kết hợp `ten_san_pham` và `mo_ta`. Tuyệt đối không sao chép lại chuỗi thành phần vào mô tả văn bản để tránh hiện tượng rò rỉ hoặc nhân đôi trọng số.
- **Loại bỏ thẻ HTML:** Làm sạch triệt để các tag `<p>`, `<br>`, `<span>` từ trình soạn thảo CMS.
- **Xử lý tiếng Việt:** Sử dụng biểu thức chính quy hỗ trợ Unicode tiếng Việt `(?u)\b\w{2,}\b` để tách từ chính xác, loại bỏ dấu câu ngoại lai và chuyển về chữ thường.

## 4. Chiến Lược Xử Lý Dữ Liệu Khuyết Thiếu (Missing Value Handling)
- Trong 1,004 sản phẩm, có đúng 8 sản phẩm (0.80%) không có trường thành phần chi tiết (chủ yếu là các combo gói hoặc sản phẩm mới cập nhật).
- **Chiến lược:** Trong ma trận TF-IDF sparse, các sản phẩm này nhận vector 0. Khi chiếu qua TruncatedSVD, chúng tương ứng với vector 0 ở không gian rút gọn.
- **Đánh giá độ nhạy (Sensitivity):** Tiến hành phân cụm riêng trên tập 996 sản phẩm có đầy đủ thành phần để định lượng mức độ ảnh hưởng của 8 sản phẩm này đối với cấu trúc hình học tổng thể.

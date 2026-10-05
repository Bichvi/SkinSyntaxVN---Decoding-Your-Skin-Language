# KIỂM TOÁN NÚT KÊU GỌI KHẢO SÁT DA (SURVEY CTA UX AUDIT)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Tài liệu này kiểm toán toàn bộ các điểm chạm kêu gọi khảo sát da (Skin Survey Call-To-Action) trên trang chủ, nhằm đảm bảo nguyên tắc: **Khảo sát da là tính năng nâng cao tùy chọn (Optional Enhancement), tuyệt đối không gây cản trở trải nghiệm mua sắm tự nhiên của khách hàng.**

---

### 1. NGUYÊN TẮC THIẾT KẾ TRẢI NGHIỆM KHẢO SÁT DA (UX PRINCIPLES)

1. **Khảo sát da là giá trị gia tăng, không phải rào cản:** Khách hàng vãng lai vẫn nhận được gợi ý sản phẩm chất lượng cao ngay lập tức dựa trên thuật toán Simple IMDb Weighted Rating và các tương tác duyệt web trong phiên (tìm kiếm, xem, giỏ hàng).
2. **Tuyệt đối không dùng Modal chặn tương tác (No Blocking Modal):** Không được hiển thị popup bắt buộc khách hàng phải điền khảo sát mới được vào xem sản phẩm.
3. **Tuyệt đối không cưỡng ép chuyển hướng (No Forced Redirect):** Khách hàng nhấp vào trang chủ phải ở lại trang chủ, không bị tự động chuyển sang trang `index.php?r=khaosat`.
4. **Thông điệp minh bạch về thời gian và lợi ích:** Nêu rõ thời gian hoàn thành (chỉ 1 phút) và giá trị cụ thể (tinh chỉnh độ chính xác gợi ý theo loại da).

---

### 2. BẢNG KIỂM KÊ TOÀN BỘ CÁC ĐIỂM CHẠM SURVEY CTA TRÊN HOMEPAGE HIỆN TẠI

Kiểm toán phát hiện có tới **5 vị trí khác nhau** đang kêu gọi khảo sát da trên cùng một trang chủ:

| Vị trí | Dòng mã nguồn (`home.php`) | Dạng thành phần giao diện | Nội dung hiển thị (Copywriting) | Điều kiện xuất hiện | Đánh giá trải nghiệm người dùng |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **1. Hero Banner** | Dòng 265–270 | Nút bấm chính (Primary Button) | *"Làm trắc nghiệm da ngay →"* | Luôn hiển thị cho mọi người dùng | **Tốt**: Là điểm nhấn nổi bật trên banner đầu trang cho khách muốn khám phá routine. |
| **2. Header Section 3**| Dòng 542–545 | Nút viền nhỏ (Outline Button) | *"Hoàn thành khảo sát da để tinh chỉnh gợi ý →"* | Hiển thị khi `!$hasSurvey` | **Tốt**: Nằm ngay cạnh tiêu đề section gợi ý, giải thích lý do tại sao nên làm khảo sát. |
| **3. Footer Section 3**| Dòng 567–569 | Dòng liên kết text nhỏ (Text Link) | *"Khảo sát 1 phút để độ chuẩn xác cao hơn →"* | Hiển thị khi `!$hasSurvey` | ⚠️ **DƯ THỪA**: Lặp lại ngay dưới Section 3 trong khi đầu section đã có nút ở vị trí 2. |
| **4. Thẻ sản phẩm** | Dòng 187 | Icon text link trên từng card | *"Độ hợp →"* | Hiển thị trên mọi card khi `!$hasSurvey` | ⚠️ **QUÁ DÀY ĐẶC (OVERKILL)**: Cả 12–16 card sản phẩm trên trang chủ đều gắn link này, gây rối mắt. |
| **5. Section 7 (Routine)**| Dòng 804–806 | Nút khối trung tâm (Card Block) | *"Làm khảo sát da ngay →"* | Hiển thị khi `!$hasSurvey` | **Hợp lý**: Khối Routine cần hồ sơ da mới hiển thị được routine 4 bước cá nhân hóa. |
| **6. Section 9 (Cuối trang)**| Dòng 835–837 | Nút kêu gọi cuối (Final CTA) | *"Bắt đầu làm khảo sát da ngay →"* | Luôn hiển thị ở chân trang | **Tốt**: Điểm dừng chân cho khách hàng đã cuộn hết trang web mà chưa tìm được sản phẩm ưng ý. |

---

### 3. CÁC VẤN ĐỀ TRẢI NGHIỆM ĐƯỢC PHÁT HIỆN & ĐỀ XUẤT ĐIỀU CHỈNH

#### VẤN ĐỀ 1: HIỆN TƯỢNG "SPAM KHẢO SÁT" TRÊN TỪNG THẺ SẢN PHẨM (CARD-LEVEL FATIGUE)
* **Thực trạng:** Khi khách vãng lai chưa khảo sát, góc trên bên phải của mọi thẻ sản phẩm đều xuất hiện liên kết `Độ hợp →`. Với 16 sản phẩm trên trang chủ, người dùng nhìn thấy lời nhắc này 16 lần.
* **Đề xuất khắc phục:** 
  * Gỡ bỏ nút `Độ hợp →` khỏi các khối chung như Flash Sale, Sản Phẩm Mới và Được Yêu Thích Nhất.
  * Trong khối *Dành Cho Bạn*, chỉ cần giữ lại lời nhắc duy nhất ở phần Header của Section, giúp giao diện thẻ sản phẩm thoáng đãng và tập trung vào thông tin sản phẩm.

#### VẤN ĐỀ 2: TRẠNG THÁI "ĐÃ KHẢO SÁT" GÂY HIỂU LẦM KHI ĐÃ ĐĂNG NHẬP
* **Thực trạng:** Khi người dùng đã làm khảo sát, mọi thẻ sản phẩm đều gắn huy hiệu xanh: `<i class="bi bi-shield-check"></i> Đã khảo sát`.
* **Rủi ro:** Khách hàng có thể hiểu lầm rằng kem trị mụn hay son môi đều là sản phẩm "đã được chuyên gia kiểm tra phù hợp với da của họ", trong khi thực tế huy hiệu này chỉ mang ý nghĩa là tài khoản đã có dữ liệu khảo sát.
* **Đề xuất khắc phục:** 
  * Thay thế huy hiệu cấp thẻ bằng thông tin chính xác: Chỉ gắn nhãn "Khớp loại da của bạn" nếu sản phẩm thực sự phù hợp với loại da trong hồ sơ.
  * Chuyển trạng thái xác nhận hồ sơ lên thanh tiêu đề section: `<span class="badge">Hồ sơ: Da dầu mụn</span>`.

---

### 4. ĐỀ XUẤT QUY CHUẨN HIỂN THỊ CTA CHO TỪNG NHÓM NGƯỜI DÙNG

1. **Khách vãng lai chưa có hành vi (Cold-start):**
   * Section 3 hiển thị gợi ý Simple WR bình thường.
   * Header hiển thị CTA nhẹ nhàng: `"Khảo sát 1 phút để nhận gợi ý theo làn da của bạn →"`.
2. **Khách vãng lai đã có hành vi (Đã xem / Tìm kiếm / Thêm giỏ hàng):**
   * Section 3 hiển thị gợi ý theo hành vi tương ứng.
   * Header hiển thị CTA kết hợp: `"Gợi ý dựa trên sản phẩm bạn vừa xem. Khảo sát thêm để tối ưu theo loại da →"`.
3. **Người dùng đã đăng nhập có hồ sơ da hoàn chỉnh:**
   * Không hiển thị nút kêu gọi khảo sát lại.
   * Hiển thị tóm tắt hồ sơ da đã nhận diện kèm nút chỉnh sửa: `"Hồ sơ: Da dầu • Trị mụn (Chỉnh sửa)"`.

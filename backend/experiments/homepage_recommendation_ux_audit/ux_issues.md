# TỔNG DANH MỤC CÁC VẤN ĐỀ TRẢI NGHIỆM NGƯỜI DÙNG (UX ISSUES INVENTORY)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Tài liệu này tổng hợp toàn bộ các vấn đề khiếm khuyết về dữ liệu, logic hiển thị, copywriting và giao diện phát hiện được trong đợt kiểm toán toàn diện trang chủ.

---

### BẢNG TỔNG HỢP VẤN ĐỀ THEO MỨC ĐỘ ƯU TIÊN

| Mã vấn đề | Tiêu đề vấn đề | Mức độ ưu tiên | Tệp tin liên quan | Bản chất khiếm khuyết |
| :---: | :--- | :---: | :--- | :--- |
| **ISSUE-01** | Bịa đặt điểm đánh giá (4.9) và số lượt review (128) | **P0** | `frontend/views/home.php` (dòng 149, 206) | Dữ liệu giả mạo (Fake fallback data) |
| **ISSUE-02** | Khuyết trường `match_score` gây ra nhãn "Đã khảo sát" toàn cục | **P0** | `SanPham.php` (dòng 880–905) & `home.php` (dòng 148, 185) | Lỗi kết nối dữ liệu (Broken view wiring) |
| **ISSUE-03** | Sai lệch tiêu đề khi rơi vào chế độ `PARTIAL_PROFILE_FALLBACK` | **P1** | `frontend/views/home.php` (dòng 485–522) | Sai lệch thông điệp (Misleading copywriting) |
| **ISSUE-04** | Trùng lặp 100% sản phẩm giữa Section 3 và Section 5.5 ở Cold-start | **P1** | `frontend/views/home.php` & `SanPham.php` | Trùng lặp hiển thị (Duplication hazard) |
| **ISSUE-05** | Ngôn ngữ kỹ thuật trong tiêu đề gợi ý đa tín hiệu (Hybrid) | **P1** | `frontend/views/home.php` (dòng 513) | Thuật ngữ kỹ thuật rò rỉ (Technical jargon) |
| **ISSUE-06** | Rò rỉ thuật ngữ tiếng Anh trong khối Routine 4 bước | **P2** | `frontend/views/home.php` (dòng 746, 762–794) | Ngôn ngữ không đồng nhất (English leakage) |
| **ISSUE-07** | Nút bấm bị co cụm dưới 65px trên giao diện di động (Mobile) | **P2** | `frontend/views/home.php` (dòng 216–234) | Trải nghiệm chạm kém (Cramped touch targets) |
| **ISSUE-08** | Lặp lại quá nhiều nút kêu gọi khảo sát da (5 vị trí trên trang) | **P2** | `frontend/views/home.php` (dòng 187, 542, 567, 804, 835) | Gây phiền toái (Survey CTA fatigue) |
| **ISSUE-09** | Xung đột vị trí giữa nhãn Sale (-XX%) và Reason Tag trên ảnh | **P3** | `frontend/views/home.php` (dòng 155–168) | Va chạm giao diện (Badge collision) |
| **ISSUE-10** | Thiếu thông báo rõ ràng khi hệ thống fallback về sản phẩm mới | **P3** | `frontend/views/home.php` (dòng 61–63) | Thiếu phản hồi ngữ cảnh (Silent fallback) |

---

### PHÂN TÍCH CHI TIẾT TỪNG VẤN ĐỀ

#### ISSUE-01 (P0): Bịa đặt điểm đánh giá (4.9) và số lượt review (128)
* **Mô tả:** Khi sản phẩm chưa có đánh giá thực tế trong database, hàm render thẻ tự động gán `$rating = 4.9` và `so_luong_danh_gia = 128`.
* **Tác động:** Vi phạm đạo đức dữ liệu thương mại điện tử, đánh mất niềm tin của người dùng nếu họ phát hiện sản phẩm mới tinh nhưng vẫn có 128 đánh giá 4.9 sao.

#### ISSUE-02 (P0): Khuyết trường `match_score` gây ra nhãn "Đã khảo sát" toàn cục
* **Mô tả:** Thẻ sản phẩm kiểm tra `$p['match_score']` để hiển thị phần trăm hợp da. Do `SanPham.php` không truyền trường này mà chỉ có `recommender_meta`, điều kiện luôn `null` và rơi vào nhánh `elseif ($hasSurvey)`.
* **Tác động:** Khi người dùng đã làm khảo sát, mọi sản phẩm trên toàn bộ trang chủ đều bị gắn nhãn "Đã khảo sát", khiến người dùng tưởng nhầm mọi sản phẩm đều hợp với làn da của họ.

#### ISSUE-03 (P1): Sai lệch tiêu đề khi rơi vào chế độ `PARTIAL_PROFILE_FALLBACK`
* **Mô tả:** Khi hồ sơ da thiếu thông tin và phải fallback, hệ thống vẫn gắn `$dominantSignal = 'PROFILE'`. Trang chủ đọc tín hiệu này và giật tít: *"Dành riêng cho làn da của bạn"*, tạo kỳ vọng sai lệch cho người dùng.
* **Tác động:** Làm giảm tính minh bạch của hệ thống gợi ý.

#### ISSUE-04 (P1): Trùng lặp 100% sản phẩm giữa Section 3 và Section 5.5 ở Cold-start
* **Mô tả:** Khách vãng lai hoàn toàn mới thấy cùng 4 sản phẩm Top-4 IMDb Weighted Rating ở cả khối Section 3 và Section 5.5.
* **Tác động:** Gây cảm giác nhàm chán, catalog nghèo nàn và nghi ngờ hệ thống hiển thị lặp lỗi.

#### ISSUE-05 (P1): Ngôn ngữ kỹ thuật trong tiêu đề gợi ý đa tín hiệu (Hybrid)
* **Mô tả:** Dòng tít *"Dành riêng cho bạn (Hồ sơ da & Hành vi)"* và kicker *"CÁ NHÂN HÓA ĐA TÍN HIỆU"*.
* **Tác động:** Người dùng phổ thông không hiểu "Đa tín hiệu" là gì, cảm giác như đang đọc tài liệu kiểm thử của lập trình viên.

#### ISSUE-06 (P2): Rò rỉ thuật ngữ tiếng Anh trong khối Routine 4 bước
* **Mô tả:** Sử dụng *"DAILY SKINCARE REGIMEN"*, *"STEP 1: Cleanse (Làm sạch)"*, v.v.
* **Tác động:** Không đồng nhất với định hướng website thuần tiếng Việt.

#### ISSUE-07 (P2): Nút bấm bị co cụm dưới 65px trên giao diện di động
* **Mô tả:** 2 nút *"Thêm"* và *"Mua ngay"* ép chung vào hàng ngang 136px trên điện thoại.
* **Tác động:** Dễ bấm nhầm, chữ bị gãy dòng, trải nghiệm mua sắm trên di động bị suy giảm.

#### ISSUE-08 (P2): Lặp lại quá nhiều nút kêu gọi khảo sát da
* **Mô tả:** Xuất hiện tới 5 lần, đặc biệt là nút `"Độ hợp →"` trên mọi thẻ sản phẩm.
* **Tác động:** Khiến người dùng cảm thấy bị ép buộc làm khảo sát, đi ngược lại nguyên tắc "khảo sát là tùy chọn nâng cao".

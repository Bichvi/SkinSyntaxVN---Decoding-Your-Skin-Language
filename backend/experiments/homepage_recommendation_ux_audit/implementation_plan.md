# KẾ HOẠCH TRIỂN KHAI HOÀN THIỆN UX TRANG CHỦ (IMPLEMENTATION PLAN)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Kế hoạch này phân loại các hành động điều chỉnh giao diện theo 4 cấp độ ưu tiên (P0 $\rightarrow$ P3). **Mọi đề xuất đều tuân thủ nguyên tắc: Tuyệt đối không can thiệp vào thuật toán, trọng số hay công thức toán học của Recommendation Engine.**

---

### BẢNG KẾ HOẠCH CHI TIẾT THEO CẤP ĐỘ ƯU TIÊN

| Mã | Mức ưu tiên | Tệp tin liên quan | Hành vi hiện tại (Current Behavior) | Vấn đề phát sinh (Problem) | Thay đổi đề xuất (Proposed Change) | Mức độ rủi ro (Risk) | Thuật toán có bị ảnh hưởng? |
| :---: | :---: | :--- | :--- | :--- | :--- | :---: | :---: |
| **P0-1** | **P0 (Cốt lõi)** | `frontend/views/home.php` (dòng 149, 206) | Gán cứng `$rating = 4.9` và `so_luong_danh_gia = 128` khi thiếu dữ liệu | Bịa đặt dữ liệu đánh giá, vi phạm tính trung thực | Nếu chưa có đánh giá: Ẩn số sao hoặc hiển thị "Mới lên kệ", hiển thị `(0)` hoặc ẩn số lượng review | Thấp | **KHÔNG (NO)** |
| **P0-2** | **P0 (Cốt lõi)** | `SanPham.php` (dòng 880) & `home.php` (dòng 148, 185) | `SanPham.php` không truyền `match_score` ở cấp gốc; `home.php` đọc biến này bị `null` | Badge `% MATCH` không bao giờ hiện; mọi sản phẩm đều bị gắn nhãn "Đã khảo sát" | Gán `match_score` từ `recommender_meta['skin_score'] * 100` khi hydrate; chỉ hiện "Khớp da" khi điểm thật $\ge 70\%$ | Thấp | **KHÔNG (NO)** |
| **P1-1** | **P1 (Minh bạch)**| `frontend/views/home.php` (dòng 485–522) | Switch chỉ dựa trên `$dominantSignal`, chế độ `PARTIAL_PROFILE_FALLBACK` vẫn giật tít "Dành riêng cho làn da" | Sai lệch thông điệp khi hệ thống đã fallback sang chế độ cơ bản | Cập nhật logic switch kết hợp cả `$algoMode` và `$dominantSignal` theo bảng `proposed_dynamic_titles.csv` | Rất thấp | **KHÔNG (NO)** |
| **P1-2** | **P1 (Minh bạch)**| `frontend/views/home.php` (dòng 513) | Chế độ Hybrid hiển thị: "Dành riêng cho bạn (Hồ sơ da & Hành vi)" kèm kicker "CÁ NHÂN HÓA ĐA TÍN HIỆU" | Dùng từ ngữ kỹ thuật gây khó hiểu cho người mua hàng | Đổi thành Title: "Gợi ý tối ưu cho bạn", Kicker: "DÀNH RIÊNG CHO BẠN", Subtitle: "Hòa trộn giữa đặc điểm làn da và các sản phẩm bạn đang quan tâm." | Rất thấp | **KHÔNG (NO)** |
| **P1-3** | **P1 (Minh bạch)**| `frontend/views/home.php` (dòng 699) & `SanPham.php` | Khi khách mới vào trang, cả Section 3 và Section 5.5 đều hiển thị chung 4 sản phẩm Top-4 Simple WR | Trùng lặp 100% sản phẩm trên trang chủ ở trạng thái Cold-start | Thiết lập cơ chế bù trừ (Offset policy): Nếu Section 3 ở mode SIMPLE (Top 1–4), Section 5.5 sẽ lấy Top 5–8 của Simple WR | Thấp | **KHÔNG (NO)** |
| **P2-1** | **P2 (Rõ ràng)** | `frontend/views/home.php` (dòng 746, 762–794) | Routine 4 bước hiển thị nửa Anh nửa Việt: "Cleanse (Làm sạch)", "Treat (Đặc trị)", v.v. | Thiếu chuyên nghiệp, rò rỉ tiếng Anh | Chuyển đổi 100% sang tiếng Việt tự nhiên: "Bước 1: Làm sạch da", "Bước 2: Tinh chất đặc trị", "Bước 3: Dưỡng ẩm & phục hồi", "Bước 4: Bảo vệ chống nắng" | Rất thấp | **KHÔNG (NO)** |
| **P2-2** | **P2 (Rõ ràng)** | `frontend/views/home.php` (dòng 187, 567) | Nút kêu gọi khảo sát da xuất hiện tới 5 lần, gắn trên từng thẻ card sản phẩm ("Độ hợp →") | Gây cảm giác phiền toái, coi khảo sát da như rào cản cưỡng ép | Gỡ bỏ nút "Độ hợp →" trên từng card ở các section chung; giữ duy nhất 1 CTA nhẹ ở Header của Section 3 | Thấp | **KHÔNG (NO)** |
| **P2-3** | **P2 (Rõ ràng)** | `frontend/views/home.php` (dòng 216–234) | Đặt 2 nút "Thêm" và "Mua ngay" cạnh nhau trên cùng một hàng ngang trong cột `col-6` | Nút bị ép xuống dưới 65px trên màn hình di động, chữ "Mua ngay" bị gãy dòng | Trên màn hình `< 576px`: Hiển thị 1 nút chính "Thêm vào giỏ", nhấp vào thẻ để xem chi tiết và mua ngay | Thấp | **KHÔNG (NO)** |
| **P3-1** | **P3 (Thẩm mỹ)** | `frontend/views/home.php` (dòng 155–168) | Nhãn Sale (-XX%) góc trái và Reason Tag góc phải trên ảnh 150px dễ bị va chạm nhau | Tràn viền, che mất phần đầu của ảnh sản phẩm trên mobile | Giữ nhãn Sale trên ảnh, chuyển Reason Tag xuống phần nội dung dưới ảnh | Rất thấp | **KHÔNG (NO)** |
| **P3-2** | **P3 (Thẩm mỹ)** | `frontend/views/home.php` (dòng 212) | Giá thị trường gạch ngang sử dụng màu `#94A3B8` (tương phản 2.6:1) | Vi phạm tiêu chuẩn tiếp cận màu sắc WCAG 2.1 AA | Nâng độ tương phản màu chữ phụ lên `#64748B` (tương phản 4.6:1) | Rất thấp | **KHÔNG (NO)** |

---

### CAM KẾT VỀ MÃ NGUỒN VÀ THUẬT TOÁN PRODUCTION

1. **Thuật toán không bị thay đổi:** Toàn bộ công thức IMDb Weighted Rating ($m=21, C=4.8890$), TF-IDF Config B, trọng số hành vi $[0.35, 0.35, 0.20, 0.10]$, công thức Hybrid $50/50$ và bộ lọc đa dạng hóa dòng sản phẩm được bảo toàn nguyên vẹn 100%.
2. **Không triển khai UI trong Phase 1:** Toàn bộ bảng kế hoạch trên đóng vai trò là tài liệu thiết kế và định hướng nghiệm thu cho Phase 2 (Implementation Phase).

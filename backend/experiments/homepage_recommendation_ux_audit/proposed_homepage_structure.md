# ĐỀ XUẤT CẤU TRÚC VÀ THỨ TỰ SECTION TRANG CHỦ (PROPOSED HOMEPAGE STRUCTURE)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Tài liệu này đánh giá thứ tự hiển thị hiện tại của các khối nội dung trên trang chủ và đề xuất kiến trúc phân cấp thị giác tối ưu hóa trải nghiệm gợi ý (Visual Hierarchy & Discovery Flow). **Chỉ đề xuất giải pháp kiến trúc, không can thiệp mã nguồn.**

---

### 1. ĐỐI SOÁT THỨ TỰ SECTION: HIỆN TẠI vs. ĐỀ XUẤT

```
HIỆN TẠI (CURRENT HOMEPAGE)                    ĐỀ XUẤT TỐI ƯU (PROPOSED STRUCTURE)
-------------------------------------          -------------------------------------
1. Hero Banner                                 1. Hero Banner (Định vị thương hiệu & CTA khảo sát)
2. Skin Concern Shortcuts (8 nhóm)             2. Skin Concern Shortcuts (Lối vào tìm kiếm nhanh)
3. For You (Adaptive Recommender)              3. Dành Riêng Cho Bạn (Cá nhân hóa theo tín hiệu thực)
4. Flash Sale (Khuyến mãi sốc)                 4. Flash Sale (Ưu đãi mua sắm theo thời gian)
5. Syna AI Livestream                          5. Được Yêu Thích Nhất (Simple Recommender chuẩn IMDb)
5.5 Được Yêu Thích Nhất (Simple WR)            6. Syna AI Livestream (Tư vấn trực tiếp)
6. New Products (Mới lên kệ)                   7. Mỹ Phẩm Vừa Lên Kệ (Khám phá sản phẩm mới)
7. Personal Routine (Quy trình 4 bước)         8. Routine Của Bạn (Cá nhân hóa theo khảo sát)
8. Trusted Brands (Nhãn hàng)                  9. Thương Hiệu Đồng Hành
9. Final CTA                                  10. Chân trang & CTA hoàn tất khảo sát
```

---

### 2. PHÂN TÍCH RỦI RO LẶP LẠI NỘI DUNG Ở TRẠNG THÁI COLD-START (DUPLICATION HAZARD)

* **Hiện tượng phát hiện qua kiểm toán:**
  * Khi người dùng là **Khách vãng lai hoàn toàn mới (Cold-start)**:
    * Section 3 (`forYou`) không có tín hiệu cá nhân hóa $\rightarrow$ Fallback về 4 sản phẩm Simple Weighted Rating: `[1000, 266, 728, 4365]`.
    * Section 5.5 (`topRatedWeighted`) độc lập truy vấn 4 sản phẩm Simple Weighted Rating hàng đầu $\rightarrow$ Trả về chính xác 4 sản phẩm: `[1000, 266, 728, 4365]`.
  * **Hệ quả UX:** Khách hàng mới cuộn trang sẽ nhìn thấy **cùng 4 sản phẩm gel rửa mặt Eucerin và kem dưỡng** xuất hiện 2 lần trên cùng một trang chủ (ở Section 3 và Section 5.5), tạo cảm giác kho hàng nghèo nàn và hệ thống bị lỗi trùng lặp.

* **Đề xuất khắc phục cho Cold-start (UX Offset Policy):**
  * Trong trường hợp Section 3 ở chế độ `SIMPLE` (Cold-start):
    * Section 3 hiển thị Top 1–4 của Simple Weighted Rating (`offset: 0, limit: 4`).
    * Section 5.5 ("Được Yêu Thích Nhất") tự động dịch chuyển lấy Top 5–8 của Simple Weighted Rating (`offset: 4, limit: 4`).
  * Khi người dùng có tín hiệu hành vi hoặc hồ sơ:
    * Section 3 hiển thị sản phẩm cá nhân hóa từ `ContentBasedRecommender`.
    * Section 5.5 hiển thị Top 1–4 của Simple Weighted Rating bình thường.
  * **Giải pháp này giải quyết triệt để vấn đề trùng lặp mà không cần thay đổi bất kỳ công thức toán học nào của Recommender Engine.**

---

### 3. NGUYÊN TẮC PHÂN CẤP THỊ GIÁC (VISUAL HIERARCHY RATIONALE)

1. **Ưu tiên cá nhân hóa thích ứng (Adaptive First):** Đặt Section 3 ngay dưới các nút shortcut nhu cầu da giúp người dùng quay lại (returning users) ngay lập tức thấy các sản phẩm liên quan đến những gì họ vừa tìm kiếm hoặc xem trong phiên trước.
2. **Khai thác yếu tố kích cầu (Urgency & Social Proof):** Đặt Flash Sale (giảm giá) và Được Yêu Thích Nhất (bảo chứng cộng đồng) nối tiếp giúp tăng độ tin cậy và thúc đẩy chuyển đổi tự nhiên.
3. **Quy trình Routine làm điểm giữ chân (Retention Hook):** Khối Routine 4 bước đặt ở nửa dưới trang đóng vai trò giáo dục thói quen chăm sóc da, khuyến khích khách hàng làm khảo sát để mở khóa trọn vẹn chu trình chăm sóc.

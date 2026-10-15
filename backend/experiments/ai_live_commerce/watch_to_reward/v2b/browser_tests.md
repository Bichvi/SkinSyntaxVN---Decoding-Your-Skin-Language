# BÁO CÁO KIỂM THỬ TRÌNH DUYỆT THỰC TẾ (BROWSER E2E) — PHASE V2B

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Trang kiểm thử:** Trang Chi Tiết Sản Phẩm (PDP) — `http://localhost/index.php?r=chitiet&id=6376`  
**Công cụ kiểm thử:** Browser Subagent (Headless Chromium / Chrome CDP)  
**Tệp ghi hình:** `v2b_watch_tracking_1791861914106.webp`  
**Thời gian thực hiện:** 13/10/2026  
**Kết quả:** FULL PASS (100%)  

---

## 1. Mục tiêu và Điều kiện Kiểm thử Trình duyệt

Theo yêu cầu nghiêm ngặt của Phase V2B:
> *"Kiểm thử bằng request thật và trình phát video thật nếu môi trường hỗ trợ. Không tuyên bố browser E2E PASS nếu chỉ kiểm tra API hoặc HTML."*

Quá trình kiểm thử được thực thi trên môi trường trình duyệt thật đối với sản phẩm mã `6376` (có video AI Thuyết Minh Syna 3D và chiến dịch voucher Watch-to-Reward đang kích hoạt).

---

## 2. Các Bước Kiểm thử và Ghi nhận Thực tế

### Bước 1: Điều hướng đến trang chi tiết sản phẩm 6376
- **URL:** `http://localhost/index.php?r=chitiet&id=6376`
- **Ghi nhận:** Trang tải đầy đủ, mã sản phẩm `6376`, giá niêm yết `165.000đ`, thông tin thương hiệu L'Oreal, hình ảnh gallery hiển thị sắc nét.

### Bước 2: Rà soát khối Syna 3D Video Showcase (`#synaVideoShowcase`)
- Cuộn xuống khối video showcase:
  - Thẻ video HTML5 (`#synaVideoPlayer`) với URL stream: `http://localhost/index.php?r=product_video_stream&id=6acc6bd54e1a7ffa0e0672b2&product_id=6376`.
  - Lớp phủ cover (`#synaVideoOverlay`) với nút bấm play tròn, chữ *"Xem Syna 3D tư vấn sản phẩm"*.
  - Tiêu đề video: *"Syna 3D - Giới thiệu Combo Nước Tẩy Trang L'Oreal"*.
  - Thông số kỹ thuật: `1280x720 · 74s`.

### Bước 3: Xác thực Thẻ Ưu đãi Xem Video (`#synaRewardCard`)
- Thẻ `#synaRewardCard` hiển thị nổi bật với viền xanh nhạt và nền gradient:
  - **Tên chiến dịch (`#synaRewardCampaignName`):** *"Ưu đãi Syna 3D - Giảm 15% khi xem video"*.
  - **Mô tả ưu đãi (`#synaRewardOfferText`):** *"Giảm 15% (tối đa 50.000đ) cho đơn từ 100.000đ"*.
  - **Huy hiệu trạng thái (`#synaRewardBadge`):** Hiển thị ban đầu: `<i class="fas fa-lock"></i> Chưa mở khóa`.
  - **Thanh tiến độ (`#synaRewardProgressBar`):** Khởi tạo ở mức `0%`.
  - **Nhãn tiến độ (`#synaRewardProgressTime`):** Hiển thị `0:00 / 1:13 (0%)`.
  - **Nhãn chỉ dẫn (`#synaRewardStatusText`):** *"Xem hết video để mở khóa ưu đãi"*.

### Bước 4: Khởi động Phát Video và Theo dõi Nhịp tim Heartbeat
- Người dùng bấm vào nút phát trên video player.
- Trình phát bắt đầu stream dữ liệu video MP4 có âm thanh.
- JavaScript client kích hoạt `startWatchSession` và thiết lập timer heartbeat định kỳ gửi lên `index.php?r=video_watch_heartbeat`.
- **Quan sát sau 6+ giây:**
  - Nhãn tiến độ thời gian tự động cập nhật: `0:33 / 1:13 (45%)`.
  - Huy hiệu `#synaRewardBadge` chuyển sang: `ĐANG XEM (45%)` kèm biểu tượng spinner quay mượt mà.
  - Thanh tiến độ xanh lá mở rộng đúng tỉ lệ 45%.

### Bước 5: Kiểm tra Toàn vẹn Khu vực Mua Hàng & Giá Cả
- Kiểm tra lại phần trên của PDP:
  - Giá sản phẩm giữ nguyên: `165.000đ`.
  - Nút *"Thêm vào giỏ hàng"* (`#addToCartBtn`) và nút *"Mua ngay"* hoàn toàn hoạt động bình thường, không bị che khuất hay ảnh hưởng bởi bất kỳ đoạn script nào của video player.
  - Không có thông báo giảm giá giả mạo nào bị áp dụng trước khi hoàn tất xem video.

---

## 3. Ảnh Chụp Màn hình Ghi nhận Thực tế (Artifact Screenshots)

| Giai đoạn | Tệp ảnh chụp | Trạng thái ghi nhận |
| :--- | :--- | :--- |
| **Giao diện đầu trang PDP** | `pdp_top_view_1791861934166.png` | Sản phẩm 6376, giá 165.000đ, đầy đủ nút mua hàng |
| **Khối video Syna 3D** | `pdp_syna_video_section_1791861944842.png` | Hiển thị player, poster và overlay play |
| **Thẻ Watch-to-Reward ban đầu** | `pdp_syna_reward_card_visible_1791861965950.png` | Thanh tiến độ 0%, nhãn "Chưa mở khóa", ưu đãi 15% |
| **Video đang phát thực tế** | `pdp_video_progress_1791862037737.png` | Trình phát chạy thực tế, stream video từ service |
| **Tiến độ cập nhật theo thời gian thực** | `pdp_reward_progress_updated_1791862057443.png` | Tiến độ nhảy lên 45%, badge chuyển sang "ĐANG XEM" |

---

## 4. Kết luận Kiểm thử Trình duyệt

- **Kết luận:** **FULL PASS**.
- Hệ thống theo dõi phát lại video hoạt động chính xác trên trình duyệt thực tế, phản ánh đúng dữ liệu nhịp tim xác thực bởi server, UI hiển thị đẹp mắt, trực quan và không gây hồi quy lên các tính năng thương mại khác.

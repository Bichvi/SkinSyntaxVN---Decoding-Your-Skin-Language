# KIẾN TRÚC PHÁT HÀNH VOUCHER XEM VIDEO (ISSUANCE ARCHITECTURE) — PHASE V2C

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Cấp Voucher Thật cho Khách Hàng (Reward Entitlement & Voucher Issuance)  
**Tác giả:** Senior PHP/MongoDB E-commerce Engineer & Application Security Engineer  
**Ngày:** 13/10/2026  
**Trạng thái:** Triển khai thành công & Đã qua kiểm thử (ALL PASS)  

---

## 1. Tổng quan Kiến trúc

Trong Phase V2C, hệ thống chuyển từ giai đoạn chỉ theo dõi tiến độ (V2B/V2B.1) sang phát hành voucher giảm giá thật cho khách hàng thỏa mãn điều kiện xem video Syna AI trên trang chi tiết sản phẩm (PDP).

Hệ thống tuân thủ nghiêm ngặt 3 nguyên tắc nền tảng:
1. **Không tạo duplicate voucher system:** Tái sử dụng model `Voucher.php` và collection `voucher` hiện hữu để đảm bảo tính đồng nhất của hệ sinh thái khuyến mãi SkinSyntaxVN.
2. **Bảo vệ mã giảm giá công khai:** Không sửa đổi cưỡng bức schema bảng `voucher` gốc khiến các voucher công khai toàn sàn bị ảnh hưởng.
3. **Phân tách trách nhiệm (Separation of Concerns):** Sử dụng kiến trúc 2 tầng (Two-Tier Entitlement & Execution Model).

---

## 2. Mô hình Hai tầng (Two-Tier Architecture)

```mermaid
flowchart TD
    subgraph Client ["Client (Browser)"]
        PDP["Trang chi tiết sản phẩm (chitiet.php)"]
        User["Khách hàng đã đăng nhập / Guest"]
    end

    subgraph Backend ["Backend SkinSyntax (PHP 8.2)"]
        Controller["VideoWatchController::claimReward()"]
        CVM["CustomerVoucher Model"]
        WSM["VideoWatchSession Model"]
        RCM["VideoRewardCampaign Model"]
        VM["Voucher Model (Canonical)"]
    end

    subgraph MongoDBAtlas ["MongoDB Database"]
        ColSession[("ai_video_watch_sessions")]
        ColCampaign[("ai_video_reward_campaigns")]
        ColCustVoucher[("customer_vouchers (Tier 1: Entitlement)")]
        ColVoucher[("voucher (Tier 2: Execution)")]
    end

    User -->|1. Xem video coverage >= 95% & tail reach| PDP
    PDP -->|2. POST video_watch_claim_reward (watch_session_id)| Controller
    Controller -->|3. Lấy customer_id từ Session PHP| CVM
    CVM -->|4. Kiểm tra session ELIGIBLE & ownership| WSM
    WSM --> ColSession
    CVM -->|5. Atomic quota reservation ($expr & $inc)| RCM
    RCM --> ColCampaign
    CVM -->|6. Sinh single-use voucher (so_luong = 1)| VM
    VM --> ColVoucher
    CVM -->|7. Tạo bản ghi Entitlement duy nhất| ColCustVoucher
    ColCustVoucher -.->|Liên kết voucher_code| ColVoucher
    CVM -->|8. Trả về voucher_code & discount info| Controller
    Controller -->|9. JSON Response {ok: true, voucher: ...}| PDP
    PDP -->|10. Hiển thị thông báo, nút Copy & Link Ví| User
```

### 2.1. Tầng 1 — Customer Entitlement & Wallet (`customer_vouchers`)
- **Vai trò:** Quản lý quyền sở hữu voucher cá nhân của khách hàng (`customer_id`).
- **Liên kết:** Gắn trực tiếp với chiến dịch (`campaign_id`), phiên xem video (`watch_session_id`), và video (`video_id`).
- **Trạng thái:** `AVAILABLE`, `USED`, `EXPIRED`.
- **Bảo mật:** Khách hàng chỉ truy vấn được danh sách voucher thuộc quyền sở hữu của chính mình thông qua session đăng nhập server-side.

### 2.2. Tầng 2 — Mã Voucher Thực thi (`voucher`)
- **Vai trò:** Bản ghi mã giảm giá chuẩn được sinh động khi claim thành công.
- **Đặc tính:**
  - `so_luong = 1`
  - `so_luong_da_dung = 0`
  - `ma_code`: Tiền tố `SYNA-` kèm chuỗi 8 ký tự hex ngẫu nhiên sinh bằng `random_bytes(4)` (ví dụ: `SYNA-776059FB`).
  - Kế thừa toàn bộ quy tắc chiết khấu từ campaign: `loai_giam` (`percent`/`fixed`), `gia_tri_giam`, `gia_tri_don_toi_thieu`, `giam_toi_da`, ngày hiệu lực.
  - Khi Phase V2D diễn ra, hệ thống checkout có thể xác thực mã giảm giá này thông qua quy trình chuẩn của `Voucher.php` mà không cần viết lại logic giảm giá.

---

## 3. Luồng Xử lý Nghiệp vụ Phát hành (Issuance Flow)

1. **Khách hàng xem video:**
   - Trình duyệt gửi heartbeat định kỳ 5 giây về endpoint `video_watch_heartbeat`.
   - Backend xác thực playback rate, cập nhật coverage bitmask/intervals.
   - Khi coverage $\ge 95\%$ và vị trí phát đạt $\ge (\text{duration} - 2.5\text{s})$, phiên xem được gắn cờ `is_eligible = true` và `eligible_at = ISODate(...)`.

2. **Khách hàng nhấn nút "Nhận voucher":**
   - JavaScript trên PDP vô hiệu hóa nút lập tức (`isClaiming = true`, thêm thuộc tính `disabled`) để triệt tiêu double-click.
   - Gửi yêu cầu POST tới `/index.php?r=video_watch_claim_reward` kèm `watch_session_id`.

3. **Server-Side Validation:**
   - **Xác thực danh tính:** Khách hàng phải đăng nhập (`$_SESSION['user']['id']` tồn tại). Nếu chưa đăng nhập, trả về `code: UNAUTHENTICATED` kèm hướng dẫn redirect sang trang đăng nhập.
   - **Xác thực phiên xem:**
     - Session phải tồn tại trong `ai_video_watch_sessions`.
     - Session phải có `is_eligible === true`.
     - Kiểm tra quyền sở hữu: Nếu session ban đầu được xem ở chế độ Guest (`guest_session_id`), server chỉ chấp nhận nếu `session_id()` hiện tại khớp với `guest_session_id` đã lưu. Sau đó tự động liên kết (migration) session sang `customer_id` mới đăng nhập.
     - Nếu session thuộc user khác, lập tức từ chối với mã lỗi `FORBIDDEN_SESSION`.
   - **Xác thực trạng thái Campaign:**
     - Campaign phải đang active (`is_active === true`).
     - Thời gian hiện tại phải nằm trong khoảng `[start_date, end_date]`.
   - **Idempotency & Per-User Limit:**
     - Nếu session này đã từng được claim trước đó, trả về ngay thông tin voucher đã cấp với cờ `already_claimed = true` mà không tạo thêm bản ghi mới.
     - Nếu khách hàng đã đạt giới hạn nhận của campaign (`user_limit`), từ chối với mã `USER_LIMIT_REACHED`.

4. **Atomic Quota Reservation:**
   - Server thực hiện cập nhật nguyên tử trên `ai_video_reward_campaigns` bằng điều kiện `$expr`: `issued_count < total_quota` và toán tử `$inc: ['issued_count' => 1]`.
   - Nếu không có document nào được update (`getModifiedCount() === 0`), trả về `QUOTA_EXHAUSTED`.

5. **Tạo Voucher & Entitlement:**
   - Sinh mã `SYNA-XXXXXXXX` ngẫu nhiên.
   - Insert vào collection `voucher` với `so_luong = 1`.
   - Insert vào collection `customer_vouchers` với trạng thái `AVAILABLE`.
   - Cập nhật trạng thái session trong `ai_video_watch_sessions` sang `reward_claimed = true`.
   - **Cơ chế Rollback:** Nếu quá trình insert vào `voucher` hoặc `customer_vouchers` ném ra ngoại lệ (ví dụ đụng unique index hoặc network error), server thực hiện hoàn trả quota ngay lập tức (`$inc: ['issued_count' => -1]`) để không làm hao hụt quota vô cớ.

---

## 4. Danh sách Route & Endpoints

| Method | Route | Mô tả | Yêu cầu Auth |
|---|---|---|---|
| POST | `/?r=video_watch_claim_reward` | Tiếp nhận yêu cầu nhận voucher thưởng | Khách hàng đăng nhập |
| GET | `/?r=customer_vouchers` | API lấy danh sách voucher cá nhân (JSON) | Khách hàng đăng nhập |
| GET | `/?r=hoso` | Hiển thị hồ sơ tài khoản và Tab Ví Voucher | Khách hàng đăng nhập |

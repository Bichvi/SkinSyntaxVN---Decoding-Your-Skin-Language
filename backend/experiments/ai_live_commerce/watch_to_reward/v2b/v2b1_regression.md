# BÁO CÁO KIỂM THỬ HỒI QUY BẢO MẬT (SECURITY REGRESSION) — PHASE V2B.1

**Dự án:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Module:** Video Watch Tracking & Completion Engine  
**Mục tiêu:** Rà soát an toàn dữ liệu, chống gian lận và hoàn tất xem video trước khi phát hành voucher thật ở Phase V2C  
**Kỹ sư thực hiện:** Senior PHP/MongoDB Security Engineer  
**Thời gian thực hiện:** 13/10/2026  
**File kịch bản kiểm thử:** `backend/tests/test_v2b1_review.php`  
**Kết quả chung:** **PASS (13/13 test cases)**

---

## 1. Mục tiêu và Tiêu chuẩn Đánh giá

Trong đợt rà soát Phase V2B.1, toàn bộ cơ chế ghi nhận heartbeat, cộng dồn khoảng thời gian xem (`valid_watched_intervals`), tính toán độ phủ (`watched_coverage_percent`), và quy tắc xác thực điều kiện nhận thưởng (`validateCompletion`) đã được đặt dưới 10 kịch bản kiểm thử bảo mật nghiêm ngặt.

### Nguyên tắc bất khả xâm phạm của Phase V2B.1:
1. **Không cấp voucher thật:** Bộ sưu tập `voucher` trong cơ sở dữ liệu phải được bảo toàn tuyệt đối ($N_{\text{before}} == N_{\text{after}}$).
2. **Không thay đổi quota chiến dịch:** Trường `issued_count` trong chiến dịch thưởng phải giữ nguyên bằng 0.
3. **Không công nhận `ELIGIBLE` khi chưa xem đến đoạn kết:** Loại bỏ hoàn toàn lỗ hổng của quy tắc `OR` cũ.
4. **Không chấp nhận gian lận tua nhảy (seek), spam heartbeat, hoặc tốc độ phát dị thường.**

---

## 2. Chi tiết 10 Kịch bản Kiểm thử Bảo mật

| STT | Kịch bản kiểm thử | Hành vi mô phỏng & Tấn công | Kết quả kỳ vọng | Kết quả thực tế | Trạng thái |
| :---: | :--- | :--- | :--- | :--- | :---: |
| **1** | **Xem bình thường đến cuối** | Gửi heartbeat đều đặn 5s/lần từ 0.0s đến 73.816s (100% thời lượng) | Cấp `ELIGIBLE`, `is_eligible = true`, ghi nhận đúng 100% coverage | `Coverage: 100%`, `Watched: 73.816s`, `watch_state = ELIGIBLE` | **PASS** |
| **2** | **Coverage đạt 95% nhưng chưa tới đoạn cuối** | Xem tuần tự từ 0.0s đến 70.16s (đạt **95.05%** thời lượng) rồi dừng xem (bỏ qua 3.65s đoạn kết) | **Từ chối `ELIGIBLE`**, giữ trạng thái `IN_PROGRESS`, yêu cầu xem đoạn kết | `Coverage: 95.05%`, `is_eligible: false`, `watch_state = IN_PROGRESS`, từ chối chuẩn xác | **PASS** |
| **3** | **Tua tới cuối (Seek-to-End)** | Từ 0.0s nhảy cóc thẳng lên 73.0s trong khoảng thời gian server chỉ 2.5s | Phát hiện seek ($\Delta t_{\text{play}} \gg \Delta T_{\text{server}}$), không ghi nhận khoảng xem | `was_seek: true`, `Coverage: 0%`, `Watched: 0s`, không cấp `ELIGIBLE` | **PASS** |
| **4** | **Giả mạo heartbeat dồn dập** | Gửi 2 heartbeat liên tiếp cách nhau chỉ 0.2s | Bị chặn bởi cơ chế Rate Limiting (ngưỡng tối thiểu 1.8s) | Trả mã lỗi `RATE_LIMITED` (HTTP 429), không xử lý payload | **PASS** |
| **5** | **PlaybackRate dị thường (Speed Spoofing)** | Gửi heartbeat với tốc độ 8.0x (nhảy 16s video trong 2.0s server) | Phát hiện vượt ngưỡng tốc độ cho phép ($\le 2.0x$), đánh dấu seek | `was_seek: true`, từ chối ghi nhận khoảng thời gian xem | **PASS** |
| **6** | **Tải lại trang giữa chừng (Reload)** | Người dùng đang xem ở 15.0s thì F5/tải lại trang chi tiết sản phẩm | Tái sử dụng session hiện có, bảo lưu đúng tiến độ đã xem, không tự cộng dồn | Trả về đúng `watch_session_id`, `watched_seconds: 15.0s`, không duplicate | **PASS** |
| **7** | **Mở nhiều tab cùng xem (Multi-tab)** | 2 tab trình duyệt cùng lúc gửi heartbeat trên một phiên xem | Tab thứ hai gửi quá sát tab thứ nhất bị chặn bởi Rate Limiting | Trả về `RATE_LIMITED`, ngăn chặn double-counting thời gian | **PASS** |
| **8** | **Chiến dịch bị tắt giữa lúc xem** | Admin vô hiệu hóa chiến dịch (`is_active = false`) khi phiên xem đang chạy | Ngừng ghi nhận tiến độ, lập tức chuyển trạng thái phiên xem sang `EXPIRED` | `ok: false`, `watch_state: EXPIRED`, ngắt quyền nhận thưởng | **PASS** |
| **9** | **Không cấp voucher thật trong V2B.1** | Kiểm tra số lượng bản ghi trong collection `voucher` trước và sau khi hoàn tất phiên xem đủ điều kiện | Collection `voucher` không tăng thêm bản ghi nào ($N = 2 \rightarrow 2$) | Collection `voucher` được bảo toàn nguyên vẹn: `2 == 2` | **PASS** |
| **10** | **Không thay đổi quota chiến dịch** | Kiểm tra `issued_count` và `total_quota` của chiến dịch sau phiên xem đạt `ELIGIBLE` | `issued_count` giữ nguyên 0, `total_quota` không bị suy giảm | `issued_count: 0`, `total_quota: 500` không bị thay đổi | **PASS** |

---

## 3. Log Thực thi Kiểm thử Trực tiếp (Execution Log)

Dưới đây là trích đoạn log từ quá trình thực thi kiểm thử trên môi trường Docker container `skinsyntax-php`:

```text
========================================================
SKINSYNTAXVN — PHASE V2B.1: WATCH COMPLETION & SAFETY REVIEW
========================================================

Baseline Video: ID=6acc27e526e9d8acf59c457e, PubId=6acc6bd54e1a7ffa0e0672b2, Duration=73.816236s

 [PASS] Khởi tạo campaign test cho V2B.1 review
        -> Đã tạo cấu hình chiến dịch voucher xem video thành công.
Test Campaign ID: rew_5572476c15

--- 1. XEM BÌNH THƯỜNG ĐẾN CUỐI (NORMAL WATCH TO END) ---
 [PASS] 1.1 Xem tuần tự đến cuối video đạt ELIGIBLE thành công
        -> Coverage: 100%, Watched: 73.816s

--- 2. COVERAGE ĐẠT 95% NHƯNG CHƯA TỚI ĐOẠN CUỐI (STOPPING EARLY) ---
 [PASS] 2.1 Coverage đã vượt ngưỡng 95%
        -> Coverage: 95.05%
 [PASS] 2.2 Dù đạt >=95% coverage, nhưng CHƯA phát đến đoạn đuôi (end_last < duration - 2.5s) -> KHÔNG cấp ELIGIBLE
        -> Watch State: IN_PROGRESS, is_eligible: false
 [PASS] 2.3 validateCompletion từ chối rõ ràng và yêu cầu xem đến đoạn kết
        -> Message: Bạn cần xem đến đoạn kết video để hoàn tất điều kiện nhận ưu đãi.

--- 3. TUA TỚI CUỐI (SEEK TO END) ---
 [PASS] 3.1 Tua tới cuối bị phát hiện là seek, không được cấp ELIGIBLE
        -> Coverage: 0%, Watched: 0s

--- 4. GIẢ MẠO HEARTBEAT / SPAM DÀY ĐẶC ---
 [PASS] 4.1 Heartbeat gửi cách 0.2s bị chặn bởi Rate Limiting
        -> Code: RATE_LIMITED

--- 5. PLAYBACK RATE BẤT THƯỜNG (SPEED SPOOFING) ---
 [PASS] 5.1 PlaybackRate 8x gian lận bị phát hiện và từ chối ghi nhận khoảng
        -> Was seek: YES

--- 6. RELOAD TRANG GIỮA CHỪNG ---
 [PASS] 6.1 Reload khôi phục đúng session và không tự cộng tiến độ
        -> Watched: 15s

--- 7. MULTI-TAB TRÊN CÙNG VIDEO ---
 [PASS] 7.1 Multi-tab gửi heartbeat song song bị chặn bởi rate limit
        -> Code: RATE_LIMITED

--- 8. CAMPAIGN BỊ TẮT GIỮA LÚC XEM ---
 [PASS] 8.1 Chiến dịch bị tắt giữa lúc xem chuyển session sang EXPIRED
        -> Watch State: EXPIRED

--- 9. KHÔNG CẤP VOUCHER THẬT TRONG V2B.1 ---
 [PASS] 9.1 Collection voucher giữ nguyên không đổi: 2 == 2 (Không tạo voucher)
        -> Voucher count: 2

--- 10. KHÔNG THAY ĐỔI QUOTA CHIẾN DỊCH ---
 [PASS] 10.1 Quota chiến dịch không đổi, issued_count giữ nguyên = 0
        -> Issued count: 0

 [CLEANUP] Đã dọn dẹp sạch sẽ campaign test, watch sessions và khôi phục trạng thái active gốc.

========================================================
SUMMARY: PASS = 13 | FAIL = 0
STATUS: ALL PASS
========================================================
```

---

## 4. Xác nhận Tính Toàn vẹn Hệ thống (Integrity Checklist)

- [x] **Checkout giữ nguyên:** Tuyệt đối không can thiệp luồng thanh toán hoặc file `dathang.php`.
- [x] **Model Voucher nguyên vẹn:** Không sửa đổi logic bảng `voucher` hoặc model phát hành voucher thật.
- [x] **Flash Sale & Recommendation Engine không bị ảnh hưởng:** Không chạm vào các model `FlashSale`, gợi ý sản phẩm hay phân hệ khuyến mãi khác.
- [x] **Trạng thái Database được phục hồi:** Sau khi kiểm thử, chiến dịch mẫu `rew_7e6ada3dc3` được khôi phục chính xác về trạng thái `is_active: true`, collection `voucher` và collection chiến dịch thưởng không bị rò rỉ dữ liệu thử nghiệm.

---

## 5. Kết luận

Toàn bộ các yêu cầu bảo mật, chống gian lận và kiểm soát điều kiện hoàn thành video của **Phase V2B.1** đã được hoàn tất với kết quả **PASS**. Nền tảng theo dõi hành vi xem video hiện tại đạt độ tin cậy và bảo mật cao, sẵn sàng phục vụ làm căn cứ xác thực cho **Phase V2C** (khi có quyết định phê duyệt chính thức).

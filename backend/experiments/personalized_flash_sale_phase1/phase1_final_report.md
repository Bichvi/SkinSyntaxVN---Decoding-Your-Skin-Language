# SKINSYNTAXVN — PERSONALIZED FLASH SALE
# PHASE 1: BÁO CÁO KIỂM TOÁN TẬN GỐC & THIẾT KẾ KIẾN TRÚC TỔNG THỂ
## (FORENSIC AUDIT & ARCHITECTURE DESIGN MASTER REPORT)

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Phân hệ:** Khối Khuyến mãi Chớp nhoáng Cá nhân hóa (Personalized Flash Sale)  
**Giai đoạn:** Phase 1 — Audit & Architecture Design Only (STRICTLY NO IMPLEMENTATION)  
**Môi trường kiểm toán:** MongoDB Atlas Production Canonical (`skinsyntax`, 2.473 sản phẩm, 28 danh mục)  
**Thời gian hoàn thành:** Tháng 10/2026  

---

## 1. Tóm Tắt Điều Hành (Executive Summary)

Khối "ƯU ĐÃI CHỚP NHOÁNG — Flash Sale Mỹ Phẩm" hiện diện trên Homepage của SkinSyntaxVN đã được kiểm toán toàn diện từ mã nguồn (Route &rarr; Controller &rarr; Model/Service &rarr; View &rarr; JavaScript) đến cơ sở dữ liệu MongoDB Atlas.

### Những Phát Hiện Cốt Lõi:
1. **Bản chất hiện tại:** Khối Flash Sale hiện tại hoàn toàn mang tính chất **trình diễn tĩnh (demo/presentation)**. Không có bảng dữ liệu chiến dịch (Campaign Model) nào trong hệ thống; không có thời gian bắt đầu hay kết thúc; đồng hồ đếm ngược được hardcode trong JavaScript phía client trỏ tới 23:59:59 của ngày hiện tại và tự động thiết lập lại sau mỗi ngày. Nút "Xem tất cả deal" trỏ về `index.php?r=tatca` hiển thị toàn bộ 2.473 sản phẩm.
2. **Lọc sản phẩm:** Backend hiện tại query các sản phẩm có `phan_tram_giam > 0` hoặc `tien_tiet_kiem > 0`. Do 95,47% catalog có chứa các trường này, khối này thực chất là một câu lệnh lấy 4 sản phẩm có `% giảm giá cao nhất trên toàn sàn` theo một danh sách cố định cho mọi người dùng (không có bất kỳ yếu tố cá nhân hóa nào).
3. **An ninh giá (Price Integrity):** Trong luồng được kiểm toán, backend không tin giá do client gửi; không phát hiện client-side price trust trong các đường dẫn đã kiểm tra vì toàn bộ các bước Thêm giỏ hàng &rarr; Giỏ hàng &rarr; Thanh toán &rarr; Tạo đơn hàng đều tính toán lại giá trên server từ cơ sở dữ liệu `san_pham`.
4. **Rào cản kiến trúc (Architectural Blocker):** Do cơ chế thanh toán chỉ đọc duy nhất trường `san_pham.gia_ban`, nếu áp dụng Flash Sale mà không có **Effective Pricing Engine**, khách mua deal Flash Sale sẽ bị tính theo giá gốc tại cổng thanh toán.
5. **Dữ liệu đơn hàng thực tế:** Toàn hệ thống có 29 hóa đơn với 34 dòng chi tiết đơn hàng, chỉ bao phủ **16 sản phẩm (0,65% catalog)**. Trường `san_pham.so_luong_da_ban` chứa dữ liệu cào/giả lập (ví dụ con số 298 đồng loạt trên nhiều sản phẩm) và không phải là sự thật bán hàng. Do đó, **tuyệt đối không thể dùng số lượng bán ra làm thước đo xếp hạng hoặc gắn nhãn "Bán chạy"**.
6. **Khả năng tái sử dụng AI Recommender:** Bộ gợi ý `ContentBasedRecommender` đang hoạt động ổn định trong production với đầy đủ 5 tín hiệu (Search, View, Cart, Purchase, Profile) và có thể tái sử dụng ngay lập tức để xếp hạng 12–20 deals Flash Sale theo kiến trúc phân tầng 2 giai đoạn (Two-Stage Hybrid Pipeline) mà không cần xây dựng recommender thứ hai.

---

## 2. Trả Lời Chi Tiết & Toàn Diện 18 Câu Hỏi Trọng Tâm

### Q1. Flash Sale hiện tại thật sự hoạt động thế nào?
* **Thực tế mã nguồn:** Khi truy cập `index.php?r=home`, `HomeController::index()` gọi `SanPham::getHomepageProductSections()`, bên trong gọi `SanPham::getFlashSaleProducts(8)`.
* Hàm này chạy query MongoDB trên collection `san_pham` với điều kiện: sản phẩm đang `active` và có `phan_tram_giam > 0` hoặc `tien_tiet_kiem > 0`, sắp xếp giảm dần theo:
  `['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1]`.
* Tại `frontend/views/home.php:53`, view cắt lấy đúng 4 sản phẩm đầu tiên: `array_slice($homepageSections['flashDeals'], 0, 4)` và render ra giao diện.
* Việc sắp xếp là **hoàn toàn xác định (deterministic)**, không ngẫu nhiên, không theo dõi user, và cùng một nội dung cho mọi người xem.

### Q2. Countdown vì sao reset?
* Đồng hồ đếm ngược được điều khiển bởi đoạn script thuần JavaScript ở cuối file `frontend/views/home.php:876-907`.
* Script khởi tạo: `var target = new Date(); target.setHours(23, 59, 59, 999);`.
* Mỗi khi người dùng tải lại trang hoặc bước sang ngày mới, script lại tạo một đối tượng Date mới nhắm vào 23:59:59 của ngày hôm đó. Không có bất kỳ mốc thời gian nào được lưu trữ hay kiểm soát từ server.

### Q3. Giá hiện tại đi qua Cart/Checkout thế nào?
* **Tại thẻ sản phẩm và trang chi tiết:** Hiển thị `vnd($product['gia_ban'])`. Nút thêm vào giỏ chỉ gửi POST gồm `product_id` và `quantity`.
* **Tại giỏ hàng:** Lưu vào session `$_SESSION['gio_hang'][$id] = $qty`. Khi hiển thị giỏ hàng, hệ thống query lại MongoDB và lấy `gia_ban`.
* **Tại xem trước thanh toán:** `HomeController::buildCheckoutPreview()` đọc SKU từ session và lấy `(int)$product['gia_ban']` từ database để tính tổng tiền tạm tính (`subtotal`).
* **Tại tạo hóa đơn:** `HoaDon::taoDonHang()` gọi `HoaDon::fetchProductPrice($maSanPham)` trực tiếp từ database `san_pham` và ghi snapshot đơn giá này vào `chi_tiet_hoa_don.don_gia`.

### Q4. Có nguy cơ client price tampering không?
* Trong luồng được kiểm toán, backend không tin giá do client gửi; không phát hiện client-side price trust trong các đường dẫn đã kiểm tra.
* Toàn bộ mã nguồn phía backend không đọc bất kỳ trường giá nào từ form POST của người dùng. Mọi phép nhân tính tiền và tạo đơn hàng đều được server tự truy vấn độc lập từ MongoDB `san_pham`.

### Q5. Real purchase popularity có đủ dùng không?
* **HOÀN TOÀN KHÔNG ĐỦ DÙNG.**
* Dữ liệu kiểm toán thực tế: Chỉ có **16 sản phẩm (0,65%)** trên tổng số 2.473 sản phẩm từng có đơn hàng thật trong `chi_tiet_hoa_don`. Độ thưa thớt (sparsity) lên tới 99,35%. Nếu Admin chọn 12–20 sản phẩm cho một đợt Flash Sale, gần như toàn bộ các sản phẩm đó sẽ có số lượt mua bằng 0, khiến tín hiệu này không tạo ra được bất kỳ sự phân hóa nào để xếp hạng.

### Q6. Signal nào của recommender có thể reuse?
* Tái sử dụng trọn vẹn **hệ thống tín hiệu của `ContentBasedRecommender`**:
  1. *Hành vi session realtime:* `view` (tối đa 5 sản phẩm vừa xem), `search` (tối đa 3 từ khóa gần nhất), `cart` (sản phẩm đang trong giỏ).
  2. *Hồ sơ da (Skin Profile):* Phân loại loại da (`loai_da`), vấn đề da (`van_de_da`), mục tiêu chăm sóc (`muc_tieu_cham_soc`), ngân sách (`ngan_sach`).
  3. *Lịch sử mua hàng cá nhân:* Đơn hàng cũ có trọng số suy giảm thời gian (half-life 60 ngày).
* **Ranh giới:** Không tái sử dụng hệ trọng số của Collaborative Filtering (`TuongTac::WEIGHTS` 1.0, 1.5, 3.0, 5.0) và không cắm các mô hình BPR/Funk MF vào Flash Sale.

### Q7. Profile relevance hiện lấy từ đâu?
* Profile được trích xuất từ tài khoản khách hàng thông qua `HomeController::buildRecommendationProfile($email)` và `TaiKhoan::getSkinProfileByEmail($email)`.
* Cụ thể, loại da được giải mã từ `khach_hang['tinh_trang_dac_biet']` (chứa `loaida:ID`), vấn đề da từ `khach_hang['van_de_da']`, và ngân sách từ `khach_hang['ngan_sach']`.
* Điểm phù hợp loại da được tính bằng so khớp danh mục thuộc tính (`computeSkinTypeScore`: khớp hoàn toàn = 1.0, mọi loại da = 0.7, chưa khai báo = 0.5, unknown = 0.3, không hợp = 0.0). Đây là **so khớp thuộc tính danh mục**, tuyệt đối không phải điểm số kiểm định y tế lâm sàng.

### Q8. Có thể cá nhân hóa Top 4 mà không thay giá không?
* **HOÀN TOÀN CÓ THỂ VÀ ĐÂY CHÍNH LÀ NGUYÊN TẮC CỐT LÕI CỦA THIẾT KẾ.**
* Cùng một sản phẩm trong cùng chiến dịch, giá Flash Sale là **DUY NHẤT VÀ CỐ ĐỊNH CHO TẤT CẢ KHÁCH HÀNG**.
* Cá nhân hóa chỉ can thiệp vào **THỨ TỰ HIỂN THỊ (RANKING)**: Deal nào phù hợp nhất với loại da hoặc hành vi của khách hàng đó thì được đưa lên vị trí 1, 2, 3, 4 trên Homepage.

### Q9. Cold-start nên xếp deal thế nào?
* Đối với khách vãng lai hoặc tài khoản mới chưa có hồ sơ và chưa có hành vi (STATE A):
* Sử dụng chiến lược **Social Proof + Discount Ranking**:
  $$\text{Score} = w_{\text{disc}} \cdot \text{DiscountStrength} + w_{\text{pop}} \cdot \text{SocialProofRating}$$
* Trong đó $\text{DiscountStrength}$ là tỷ lệ giảm giá của deal, và $\text{SocialProofRating}$ là điểm đánh giá theo công thức IMDb Weighted Rating ($WR$) đã có sẵn trong `SanPham::getSimpleRecommenderProducts()` (bao phủ 1.788 sản phẩm có review thật).
* Tuyệt đối không dùng doanh số bán để xếp hạng khi chưa có dữ liệu thật.

### Q10. Admin quản lý phần nào?
* **Admin có toàn quyền kiểm soát:**
  1. Tên chiến dịch Flash Sale.
  2. Thời gian bắt đầu (`start_at`) và kết thúc (`end_at`).
  3. Lựa chọn danh sách 12–20 sản phẩm tham gia.
  4. Mức giá Flash Sale (`flash_price`) cho từng sản phẩm.
  5. Bật/tắt hoặc hủy chiến dịch khẩn cấp.
* **Admin KHÔNG can thiệp:** Thứ tự xếp hạng hiển thị của từng cá nhân người dùng.

### Q11. System tự động phần nào?
* **Hệ thống tự động hóa 100%:**
  1. Xác định trạng thái vòng đời theo giờ server: `UPCOMING`, `ACTIVE`, `ENDED`.
  2. Tính toán % giảm giá: $\frac{\text{regular\_price} - \text{flash\_price}}{\text{regular\_price}} \times 100\%$.
  3. Điều khiển đồng hồ đếm ngược (dừng khi về 0, không tự reset).
  4. Thu thập tín hiệu người dùng và chạy thuật toán xếp hạng cá nhân hóa (Personalized Deal Ranking).
  5. Chọn lọc Top 4 deals tối ưu nhất để đưa lên Homepage.
  6. Bảo toàn giá Flash Sale đồng bộ xuyên suốt từ Thẻ sản phẩm &rarr; Giỏ hàng &rarr; Thanh toán &rarr; Hóa đơn.

### Q12. Campaign nên có khoảng bao nhiêu products?
* **Quy mô khuyến nghị:** **12 đến 20 sản phẩm.**
* **Lý do:**
  * Nếu quá ít ($< 8$ sản phẩm): Không gian cá nhân hóa quá hẹp, mọi user sẽ thấy gần như cùng một nhóm sản phẩm, làm mất tác dụng của thuật toán ranking.
  * Nếu quá nhiều ($> 30$ sản phẩm): Làm loãng tính chất "chớp nhoáng" và độc quyền của Flash Sale, gây khó khăn cho Admin trong việc quản trị biên lợi nhuận và tồn kho.
  * Quy mô 12–20 SKUs là tỷ lệ vàng trong ngành bán lẻ mỹ phẩm: Đủ đa dạng để bao quát các nhóm nhu cầu da (da dầu, da khô, mụn, chống nắng, dưỡng sáng) và vừa vặn để hiển thị trên 1 trang All Deals.

### Q13. Homepage nên show bao nhiêu?
* **Quy mô khuyến nghị:** **Đúng 4 sản phẩm (Top 4 deals).**
* **Lý do:** Phù hợp hoàn hảo với hệ thống lưới Bootstrap hiện tại (`col-6 col-md-3`), tạo thành đúng một hàng ngang tinh gọn, bắt mắt, không chiếm dụng quá nhiều diện tích trang chủ và thúc đẩy người dùng click vào nút "Xem tất cả deal".

### Q14. All Deals nên sort/filter thế nào?
* Trang dành riêng: `index.php?r=flash_sale`.
* Hiển thị đầy đủ 12–20 sản phẩm của chiến dịch đang ACTIVE.
* Cung cấp các tiêu chí sắp xếp có căn cứ dữ liệu:
  1. `Dành cho bạn` (Mặc định — Personalized Deal Ranking theo trạng thái user).
  2. `Giảm nhiều nhất` (Sắp xếp theo % giảm giá giảm dần).
  3. `Đánh giá cao` (Sắp xếp theo IMDb Weighted Rating).
  4. `Giá thấp → cao` (Theo `flash_price` tăng dần).
  5. `Giá cao → thấp` (Theo `flash_price` giảm dần).

### Q15. Có nên dùng "mua nhiều" ở thời điểm hiện tại không?
* **DỨT KHOÁT LÀ KHÔNG.**
* Vì cơ sở dữ liệu thực tế chỉ có 16 sản phẩm từng có đơn hàng (chiếm 0,65% catalog). Gắn nhãn "Mua nhiều" khi hầu hết sản phẩm có 0 lượt mua là hành vi lừa dối người tiêu dùng và không thể bảo vệ được trước hội đồng phản biện học thuật.

### Q16. Data model nào phù hợp nhất?
* Mô hình collection riêng biệt trong MongoDB Atlas: `flash_sale_campaigns`.
* Cấu trúc tài liệu:
  ```json
  {
    "ma_campaign": 1,
    "ten_campaign": "Flash Sale Cuối Tuần - Đón Nắng Rực Rỡ",
    "mo_ta": "Ưu đãi chống nắng và phục hồi da",
    "start_at": "2026-10-10T00:00:00Z",
    "end_at": "2026-10-12T23:59:59Z",
    "is_active": true,
    "items": [
      {
        "ma_san_pham": "6375",
        "regular_price": 380000,
        "flash_price": 249000,
        "phan_tram_giam": 34,
        "so_luong_gioi_han": 50
      }
    ],
    "created_at": "2026-10-07T12:00:00Z",
    "updated_at": "2026-10-07T12:00:00Z"
  }
  ```
* Tách biệt hoàn toàn khỏi `san_pham`, bảo vệ dữ liệu catalog gốc không bị biến dạng.

### Q17. Có cần sửa Cart/Checkout trước không?
* **CÓ, ĐÂY LÀ ĐIỀU KIỆN TIÊN QUYẾT TRƯỚC KHI BẬT FLASH SALE (Phase 2C).**
* Trước khi render bất kỳ thẻ deal nào ra cho người dùng mua, hệ thống bắt buộc phải tích hợp **Effective Pricing Engine** vào `buildCheckoutPreview()` và `fetchProductPrice()`. Nếu không làm bước này trước, khách hàng sẽ thấy giá giảm trên web nhưng khi thanh toán lại bị tính giá cũ, gây khủng hoảng niềm tin và lỗi giao dịch nghiêm trọng.

### Q18. Phase 2 có an toàn để implement không?
* **FEASIBLE TO IMPLEMENT IN STAGES — pricing integration must be validated before Flash Sale activation.**
* Cơ sở dữ liệu canonical Atlas đã được xác nhận nguyên vẹn và ổn định. Kiến trúc MVC hiện tại của dự án rất rõ ràng, nhất quán và sẵn sàng mở rộng. Bằng việc thực hiện tuần tự 7 giai đoạn (2A &rarr; 2G) với các chốt chặn kiểm thử toàn vẹn giá, Phase 2 sẽ được tích hợp êm ái mà không gây bất kỳ tác dụng phụ (side-effects) nào lên catalog hay luồng mua sắm chung.

---

## 3. Kiến Trúc Khuyến Nghị Cuối Cùng (Final Architectural Recommendation)

Phương án kiến trúc được lựa chọn và khuyến nghị cao nhất là **OPTION C: TWO-STAGE HYBRID PIPELINE (Kiến trúc phân tầng 2 giai đoạn)**:

```
[Admin Campaign Selection] ──► [Stage 1: Eligibility & Stock Gate]
                                            │
                                            ▼
[User Context A-E] ─────────► [Stage 2: Content-Based Relevance Scoring]
                                            │
                                            ▼
[Merchandising Rules] ──────► [Stage 3: Multi-Objective Ranking & Tie-Break]
                                            │
                                            ├─► Top 4 Homepage
                                            └─► Full Ranked List (All Deals)
```

### Phân Loại Minh Bạch Hiện Trạng Kỹ Thuật:
* **Hỗ trợ thực tế trong Production (Production-Supported):**
  * Vector TF-IDF, bộ tính toán Cosine Similarity, và các bộ lọc loại da/ngân sách của `ContentBasedRecommender.php`.
  * Cơ chế bảo vệ chống can thiệp giá của luồng thanh toán hiện tại.
  * Bộ tính toán điểm đánh giá trung bình gia quyền IMDb ($WR$) trong `SanPham.php`.
* **Heuristic kỹ thuật (Engineering Heuristics):**
  * Trọng số kết hợp đa mục tiêu cho deal ranking (cần tinh chỉnh qua Phase 2).
  * Quy tắc ưu tiên ảnh sản phẩm hợp lệ làm tie-breaker cho Top 4 mặt tiền.
  * Quy tắc chống trùng lặp dòng sản phẩm (Family Diversity).
* **Trạng thái nghiên cứu độc lập (Offline Experimental):**
  * Các thuật toán Collaborative Filtering, BPR, Matrix Factorization (giữ nguyên ranh giới, không cắm vào Flash Sale).
* **Cần hoàn thiện trong Phase 2 (Pending Phase 2 Implementation):**
  * Collection `flash_sale_campaigns` và Admin CRUD.
  * Effective Pricing Engine đồng bộ giá qua Cart & Checkout.
  * Route chuyên dụng `index.php?r=flash_sale`.

---

## 4. Danh Sách Các Tài Liệu & Artifacts Đã Tạo Trong Phase 1

Toàn bộ kết quả kiểm toán và thiết kế kiến trúc đã được lưu trữ hoàn chỉnh tại thư mục `backend/experiments/personalized_flash_sale_phase1/`:

1. `current_flash_sale_audit.md`: Báo cáo kiểm toán tận gốc luồng Flash Sale hiện tại và lời giải cho 10 câu hỏi code.
2. `pricing_flow_audit.md`: Kiểm toán chi tiết đường đi của giá từ Listing đến Hóa đơn, phân tích rủi ro tampering.
3. `order_signal_audit.md`: Kiểm toán dữ liệu đơn hàng thực tế, đối soát với `so_luong_da_ban` và đánh giá tín hiệu mua hàng.
4. `recommender_signal_audit.md`: Kiểm toán mã nguồn Recommender sản xuất, phân định ranh giới giữa Fusion Weights và CF Weights.
5. `profile_behavior_coverage.json`: Tập dữ liệu JSON đo lường thực nghiệm toàn bộ tỷ lệ bao phủ của catalog, đơn hàng, người dùng và tương tác trên MongoDB Atlas.
6. `deal_ranking_signal_matrix.csv`: Ma trận so sánh toàn diện tất cả các tín hiệu xếp hạng deal, tính khả thi và vai trò đề xuất.
7. `admin_architecture.md`: Kiểm toán kiến trúc quản trị Admin hiện tại và đặc tả chi tiết module Flash Sale cho Admin.
8. `campaign_lifecycle_design.md`: Thiết kế vòng đời chiến dịch (UPCOMING, ACTIVE, ENDED), cơ chế server countdown và bất biến về giá.
9. `personalized_deal_ranking_design.md`: Thiết kế chi tiết thuật toán xếp hạng deal cá nhân hóa, 5 trạng thái user và tem lý do chân thực.
10. `architecture_options.md`: So sánh toàn diện 3 phương án kiến trúc Option A, Option B, Option C theo các tiêu chí kỹ thuật và học thuật.
11. `phase2_implementation_plan.md`: Lộ trình chi tiết 7 giai đoạn triển khai cho Phase 2 (Đề xuất, chưa thực thi).
12. `phase1_final_report.md`: Báo cáo tổng thể hoàn chỉnh (Tài liệu này).

---

## 5. Kết Luận & Dừng Giai Đoạn (STOP)

Tuân thủ nghiêm ngặt chỉ thị của đồ án:
* **GIAI ĐOẠN PHASE 1 ĐÃ HOÀN TẤT VIỆC KIỂM TOÁN VÀ THIẾT KẾ.**
* **KHÔNG CÓ BẤT KỲ DÒNG MÃ NÀO BỊ THAY ĐỔI TRÊN HOMEPAGE, ADMIN, CART HAY CHECKOUT.**
* **KHÔNG CÓ BẤT KỲ MUTATION NÀO TRÊN CƠ SỞ DỮ LIỆU MONGODB ATLAS.**
* **HỆ THỐNG DỪNG LẠI TẠI ĐÂY ĐỂ CHỜ DUYỆT BÁO CÁO TRƯỚC KHI BƯỚC VÀO PHASE 2.**

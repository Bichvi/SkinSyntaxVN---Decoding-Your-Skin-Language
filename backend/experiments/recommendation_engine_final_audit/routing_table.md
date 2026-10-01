# BẢNG ĐIỀU PHỐI NGỮ CẢNH RECOMMENDATION (CANONICAL ROUTING TABLE)

Bảng dưới đây mô tả chính xác logic rẽ nhánh của bộ chuyển mạch ngữ cảnh (Context Router) trong `ContentBasedRecommender::recommendHybrid()` và xử lý tại `SanPham::getHybridRecommendations()`.

---

## 1. BẢNG ĐIỀU PHỐI CHUẨN TẮC (CANONICAL ROUTING MATRIX)

| Mã trạng thái | Ngữ cảnh tín hiệu người dùng | `algorithm_mode` | Nguồn Vector truy vấn | Công thức xếp hạng (Ranking Formula) | Cơ chế Fallback khi rỗng | Nhãn giải thích (Reason Tags) |
| :---: | :--- | :---: | :--- | :--- | :--- | :--- |
| **A** | **Không có tín hiệu (Cold Start)**<br/>Khách mới, không có session hành vi hay profile | `SIMPLE` | Không có vector | Điểm xếp hạng Bayes IMDb: $\text{WR} = \frac{v R + m C}{v + m}$ | Trả về Top-4 sản phẩm Simple WR cao nhất | `"Sản phẩm nổi bật được yêu thích"` |
| **B** | **Chỉ có Tìm kiếm (Search Only)**<br/>Truy vấn từ khóa, chưa xem chi tiết | `BEHAVIOR_CONTENT` | $\mathbf{V}_{\text{search}}$ (MRU position weights $[1.0, 0.6, 0.3]$) | $0.90 \cdot S_{\text{Content}} + 0.10 \cdot S_{\text{Price}}$ | Fallback sang Simple Top-4 | `"Dựa trên tìm kiếm gần đây"` |
| **C** | **Chỉ có Xem sản phẩm (View Only)**<br/>Đã xem $1 \rightarrow 5$ sản phẩm gần nhất | `BEHAVIOR_CONTENT` | $\mathbf{V}_{\text{view}}$ (MRU recency weights $[0.50 \dots 0.03]$) | $0.90 \cdot S_{\text{Content}} + 0.10 \cdot S_{\text{Price}}$ | Fallback sang Simple Top-4 | `"Tương tự sản phẩm vừa xem"` |
| **D** | **Chỉ có Giỏ hàng (Cart Only)**<br/>Có sản phẩm trong giỏ, chưa xem/tìm thêm | `BEHAVIOR_CONTENT` | $\mathbf{V}_{\text{cart}}$ (Nhân với số lượng $q \in [1, 10]$) | $0.90 \cdot S_{\text{Content}} + 0.10 \cdot S_{\text{Price}}$ | Fallback sang Simple Top-4 | `"Phù hợp với giỏ hàng"` |
| **E** | **Chỉ có Mua hàng (Purchase Only)**<br/>Khách cũ có đơn hàng, phiên mới chưa tương tác | `PURCHASE_CONTENT` | $\mathbf{V}_{\text{purchase}}$ (Suy giảm bán rã $T_{1/2} = 60$ ngày) | $0.90 \cdot S_{\text{Content}} + 0.10 \cdot S_{\text{Price}}$ | Fallback sang Simple Top-4 | `"Tương thích lịch sử mua sắm"` |
| **F** | **Hợp nhất đa hành vi (Multi-Behavior)**<br/>Kết hợp Tìm kiếm + Xem + Giỏ hàng + Mua hàng | `BEHAVIOR_CONTENT` | $\mathbf{U}_{\text{behavior}} = \sum \bar{w}_s \mathbf{V}_s$<br/>(Baseline: cart .35, view .35, search .20, purchase .10) | $0.90 \cdot S_{\text{Content}} + 0.10 \cdot S_{\text{Price}}$ | Fallback sang Simple Top-4 | Kết hợp đa nhãn tương ứng các tín hiệu kích hoạt |
| **G** | **Chỉ có Hồ sơ da (Profile Only)**<br/>Đã khảo sát da, phiên mới chưa phát sinh hành vi | `PROFILE_CONTENT` | $\mathbf{V}_{\text{profile}}$ (TF-IDF từ Loại da + Vấn đề da + Mục tiêu) | $0.70 \cdot S_{\text{Content}} + 0.20 \cdot S_{\text{Skin}} + 0.10 \cdot S_{\text{Budget}}$ | Fallback sang Simple Top-4 | `"Khớp loại da"`, `"Trong ngân sách"`, `"Gợi ý cá nhân hóa"` |
| **H** | **Đầy đủ Hồ sơ da & Hành vi (Adaptive Hybrid)**<br/>Trạng thái tối ưu nhất của hệ thống | `ADAPTIVE_HYBRID` | $\mathbf{U}_{\text{query}} = 0.50 \cdot \mathbf{U}_{\text{behavior}} + 0.50 \cdot \mathbf{V}_{\text{profile}}$ | $0.70 \cdot S_{\text{Content}} + 0.20 \cdot S_{\text{Skin}} + 0.10 \cdot S_{\text{Budget}}$ | Fallback sang Simple Top-4 | Đầy đủ nhãn hành vi + nhãn da liễu + nhãn ngân sách |
| **I** | **Hồ sơ bán phần (Partial Profile)**<br/>Chỉ có loại da, không sinh được vector TF-IDF | `PARTIAL_PROFILE_FALLBACK` | $\mathbf{U}_{\text{behavior}}$ nếu có, hoặc vector rỗng | $0.70 \cdot S_{\text{Content}} + 0.20 \cdot S_{\text{Skin}} + 0.10 \cdot S_{\text{Budget}}$ | Fallback sang Simple Top-4 | `"Khớp loại da"` hoặc `"Phù hợp mọi loại da"` |

---

## 2. QUY TẮC LỌC DANH SÁCH VÀ ĐA DẠNG HÓA (FILTERING & DIVERSITY RULES)

Trước khi trả về danh sách sản phẩm gợi ý cuối cùng, hệ thống áp dụng tuần tự các bộ lọc:

1. **Bộ lọc sản phẩm vừa xem (Recent-View Exclusion):**
   - Loại trừ hoàn toàn các sản phẩm nằm trong danh sách `validRecent` (tối đa 5 sản phẩm xem gần nhất) để tránh gợi ý lại sản phẩm người dùng vừa xem.

2. **Bộ lọc sản phẩm trong giỏ hàng (Cart-Item Exclusion):**
   - Loại trừ các SKU đang có trong giỏ hàng (`cart_ids`) nhằm tối ưu diện tích hiển thị cho các sản phẩm gợi ý chéo (cross-sell).

3. **Chính sách đối với sản phẩm đã mua (Purchased Products Policy):**
   - Các sản phẩm đã từng mua trong quá khứ **KHÔNG BỊ LOẠI TRỪ CỨNG (NOT hard excluded)**.
   - *Lý do da liễu / thương mại:* Mỹ phẩm là ngành hàng tiêu hao định kỳ (sữa rửa mặt, kem chống nắng, toner hết sau 1-3 tháng), do đó việc tái gợi ý sản phẩm phù hợp đã từng mua là hành vi hợp lý về mặt nghiệp vụ.

4. **Bộ lọc đa dạng hóa họ sản phẩm (Product Family Diversity Filter):**
   - Sử dụng hàm chuẩn hóa tiêu đề `extractProductFamily($name)` loại bỏ các chỉ số dung tích (`50ml`, `100g`), quy cách đóng gói (`combo`, `set`, `x2`), và tiền tố khuyến mãi.
   - Trong Top-$K$ gợi ý, **mỗi họ sản phẩm chỉ được xuất hiện tối đa 1 sản phẩm** (ngăn chặn tình trạng cùng 1 loại kem chống nắng nhưng chiếm trọn 4 vị trí do khác dung tích 30ml / 50ml / combo).

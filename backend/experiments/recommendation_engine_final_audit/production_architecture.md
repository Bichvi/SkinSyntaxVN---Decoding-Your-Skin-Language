# KIẾN TRÚC VẬN HÀNH PRODUCTION RECOMMENDER (PRODUCTION RUNTIME ARCHITECTURE)

> **Phạm vi kiểm toán:** Mã nguồn PHP đang trực tiếp phục vụ website SkinSyntaxVN tại các file:  
> - `backend/app/controllers/HomeController.php`  
> - `backend/app/models/SanPham.php`  
> - `backend/app/services/ContentBasedRecommender.php`  
> - `frontend/pages/trangchu.php`

---

## 1. CALL GRAPH THỰC TẾ TRÊN TRANG CHỦ (EXACT HOMEPAGE CALL GRAPH)

Khi người dùng truy cập trang chủ (`GET /index.php` hoặc `GET /`), quy trình thực thi mã nguồn diễn ra tuần tự như sau:

```mermaid
graph TD
    Client["Trình duyệt người dùng (Client HTTP Request)"] --> Index["index.php (Front Controller)"]
    Index --> HC_Index["HomeController::index()"]
    
    subgraph "1. Thu thập tín hiệu phiên & hồ sơ (Context Gathering)"
        HC_Index --> SessionSig["Đọc $_SESSION['behavior_signals']<br/>(search, view, cart, purchases)"]
        HC_Index --> SessionViews["Đọc $_SESSION['recent_views']"]
        HC_Index --> AuthUser["Kiểm tra Khách hàng đăng nhập<br/>$_SESSION['user_id']"]
        AuthUser --> ReadProfile["Đọc Hồ sơ da khách hàng<br/>$db->khach_hang->findOne()<br/>(skin_type, van_de_da, muc_tieu, ngan_sach)"]
    end

    subgraph "2. Điều phối gợi ý Trang chủ (Model Orchestration)"
        HC_Index --> SP_GetHome["SanPham::getHomeRecommendations()<br/>($recentViewedIds, $userProfile, $limit=4, $signals)"]
        SP_GetHome --> SP_GetHybrid["SanPham::getHybridRecommendations()"]
        SP_GetHybrid --> CBR_Rec["ContentBasedRecommender::recommendHybrid()"]
        
        CBR_Rec --> CBR_Index["Tải chỉ mục TF-IDF<br/>(tfidf_cache.json / in-memory)"]
        CBR_Rec --> Router{"Context Router<br/>(Phân loại chế độ)"}
        
        Router -- "Không có tín hiệu (Cold-Start)" --> CBR_Empty["Trả về mảng rỗng []"]
        CBR_Empty --> SP_Fallback["Fallback sang Simple Recommender<br/>SanPham::getSimpleRecommenderProducts(4)"]
        
        Router -- "Có tín hiệu hành vi thuần túy" --> CBR_Behavior["Chế độ BEHAVIOR_CONTENT / PURCHASE_CONTENT<br/>Truy vấn TF-IDF Hành vi"]
        Router -- "Chỉ có hồ sơ khảo sát da" --> CBR_Profile["Chế độ PROFILE_CONTENT<br/>Truy vấn TF-IDF Hồ sơ"]
        Router -- "Có cả hành vi & hồ sơ da" --> CBR_Hybrid["Chế độ ADAPTIVE_HYBRID<br/>Truy vấn Vector hỗn hợp (Alpha = 0.50)"]
        
        CBR_Behavior --> Rerank["Chấm điểm Reranking & Lọc trùng họ sản phẩm (Family Diversity)"]
        CBR_Profile --> Rerank
        CBR_Hybrid --> Rerank
        Rerank --> CBR_Return["Trả về danh sách 4 sản phẩm kèm recommender_meta"]
        
        SP_GetHome --> SP_TopRated["SanPham::getSimpleRecommenderProducts(24)<br/>(Phục vụ Section 5.5 Gợi ý Đánh giá cao)"]
    end

    subgraph "3. Đổ dữ liệu ra Giao diện (View Rendering)"
        SP_GetHome --> Payload["Payload: ['forYou' => $adaptiveRecs, 'topRatedWeighted' => $topRated24, ...]"]
        Payload --> View["frontend/pages/trangchu.php"]
        View --> UI_Section3["Section 3: 'Dành Riêng Cho Bạn'<br/>(Adaptive For-You Carousel 4 items + Reason Tags)"]
        View --> UI_Section5["Section 5.5: 'Sản Phẩm Đánh Giá Cao'<br/>(Simple Weighted Rating Carousel 24 items)"]
    end
```

---

## 2. CÁC THUẬT TOÁN THỰC TẾ ĐƯỢC THỰC THI TRÊN HOMEPAGE

Qua kiểm toán luồng thực thi tĩnh và động, trang chủ SkinSyntaxVN chỉ thực thi **hai lớp thuật toán chính**:

1. **Lớp 1 — Simple Recommender (IMDb Bayesian Weighted Rating):**
   - **Mục đích:** Giải quyết bài toán Cold-Start cho khách vãng lai mới theo phương pháp thống kê Bayes mượt và phục vụ mục "Sản phẩm đánh giá cao" (Section 5.5).
   - **Vị trí gọi:** `SanPham::getSimpleRecommenderProducts($limit)`.
   - **Trạng thái:** `PRODUCTION`.

2. **Lớp 2 — Adaptive Recommender V1 (Multi-Signal Content-Based & Hybrid):**
   - **Mục đích:** Cá nhân hóa động theo ngữ cảnh phiên duyệt web và hồ sơ da người dùng.
   - **Bốn chế độ nhánh con phụ thuộc tín hiệu đầu vào:**
     - **COLD-START:** Chuyển hướng an toàn về Simple Top-4.
     - **BEHAVIOR-AWARE:** Khi có tìm kiếm, click, xem, giỏ hàng, hoặc mua hàng mà chưa có hồ sơ da $\rightarrow$ chế độ `BEHAVIOR_CONTENT` hoặc `PURCHASE_CONTENT`.
     - **PROFILE-AWARE:** Khi người dùng đã làm khảo sát hồ sơ da nhưng phiên mới chưa có hành vi $\rightarrow$ chế độ `PROFILE_CONTENT`.
     - **ADAPTIVE HYBRID:** Khi có đồng thời cả hành vi tương tác và hồ sơ da $\rightarrow$ chế độ `ADAPTIVE_HYBRID`.
   - **Vị trí gọi:** `SanPham::getHybridRecommendations()`.
   - **Trạng thái:** `PRODUCTION`.

---

## 3. KIỂM TOÁN TÍNH CÔ LẬP CỦA COLLABORATIVE FILTERING (CF ABSENCE AUDIT)

Kết quả kiểm tra toàn diện mã nguồn production khẳng định:
- **KHÔNG** có bất kỳ lời gọi nào tới `CollaborativeFilteringEvaluator`, `trainItemKnn`, `trainFunkMf`, hay `trainBpr` bên trong `HomeController.php`, `SanPham.php`, `chitiet.php`, `cart.php`, `checkout.php`.
- **KHÔNG** có bất kỳ kết nối nào từ production runtime tới cơ sở dữ liệu thực nghiệm `skinsyntax_cf_dev`.
- **Collaborative Filtering hoàn toàn là mô hình nghiên cứu ngoại tuyến (Offline Research Only)**, phục vụ mục đích phân tích học thuật và đánh giá tiềm năng trên dữ liệu giả lập.

---

## 4. KIẾN TRÚC BỘ NHỚ ĐỆM (CACHE ARCHITECTURE & INVALIDATION)

Hệ thống production sử dụng chiến lược bộ nhớ đệm hai lớp để đảm bảo thời gian phản hồi trang dưới 100ms:

| Loại bộ nhớ đệm | Đường dẫn lưu trữ / Vị trí | Dữ liệu lưu trữ | Thời gian sống (TTL) | Cơ chế hủy đệm (Invalidation) |
| :--- | :--- | :--- | :---: | :--- |
| **Simple Recommender File Cache** | `backend/app/content/simple_recommender_cache.json` | Danh sách Top-24 sản phẩm tính sẵn kèm điểm WR | 600 giây (10 phút) | Tự động hủy khi quá TTL hoặc khi gọi `SanPham::clearSimpleRecommenderCache()` lúc có đánh giá mới |
| **Simple Recommender Memory Cache** | Biến tĩnh `SanPham::$simpleRecommenderMemoryCache` | Dữ liệu Top-24 trong cùng vòng đời request PHP | Vòng đời 1 request | Giải phóng khi kết thúc request HTTP |
| **TF-IDF Index File Cache** | `backend/app/content/tfidf_cache.json` | Vector TF-IDF, Norms, Từ điển terms (2,977 terms), Giá, Tên | Bền vững (File-based) | Tạo lại bằng script `build_tfidf_cache.php` khi danh mục sản phẩm thay đổi lớn |
| **TF-IDF In-Memory Cache** | Biến tĩnh `ContentBasedRecommender::$cachedIndex` | Chỉ mục TF-IDF được nạp vào RAM | Vòng đời 1 request | Giải phóng khi kết thúc request HTTP |
| **Product Lookup Static Cache** | Biến tĩnh trong `SanPham` (`$brandLookupMap`, `$categoryLookupMap`) | Bảng ánh xạ mã danh mục, thương hiệu | Vòng đời 1 request | Hàm `SanPham::clearLookupCache()` |

---

## 5. SƠ ĐỒ KIẾN TRÚC TỔNG THỂ PHỤC VỤ LUẬN VĂN (THESIS ARCHITECTURE DIAGRAM)

```mermaid
flowchart TD
    subgraph ProductionRuntime["PRODUCTION RUNTIME PIPELINE (ĐANG HOẠT ĐỘNG TRỰC TIẾP)"]
        User["Người dùng / Khách truy cập (User)"]
        Collector["Bộ thu thập Tín hiệu & Hồ sơ (Behavior & Profile Collector)"]
        User --> Collector

        Collector --> Router{"Bộ chuyển mạch ngữ cảnh<br/>(Adaptive Context Router)"}

        Router -- "Không có tín hiệu<br/>(Cold Start)" --> ColdStart["Simple Recommender<br/>(IMDb Bayesian WR)"]
        Router -- "Tín hiệu hành vi<br/>(Search/View/Cart/Purchase)" --> BehaviorCB["Behavior-Aware<br/>Content-Based (TF-IDF)"]
        Router -- "Khảo sát hồ sơ da<br/>(Skin Type, Concerns, Budget)" --> ProfileCB["Profile-Aware<br/>Content-Based (TF-IDF)"]
        Router -- "Hành vi + Hồ sơ da<br/>(Multi-Signal Fusion)" --> HybridCB["Adaptive Hybrid<br/>(Alpha = 0.50 Query Fusion)"]

        ColdStart --> Filtering["Bộ lọc & Đa dạng hóa (Filtering & Diversity)<br/>• Loại trừ sản phẩm vừa xem (validRecent)<br/>• Loại trừ sản phẩm trong giỏ (cart_ids)<br/>• Lọc trùng họ sản phẩm (Product Family)"]
        BehaviorCB --> Filtering
        ProfileCB --> Filtering
        HybridCB --> Filtering

        Filtering --> Explainability["Module giải thích lý do (Grounded Explainability)<br/>Gán nhãn minh bạch theo tín hiệu thực tế đóng góp"]
        Explainability --> Homepage["Giao diện Đề xuất Trang chủ (Homepage Recommendations)<br/>• Section 3: 'Dành Riêng Cho Bạn'<br/>• Section 5.5: 'Sản Phẩm Đánh Giá Cao'"]
    end

    subgraph OfflineResearch["OFFLINE COLLABORATIVE FILTERING RESEARCH (NGHIÊN CỨU NGOẠI TUYẾN CÔ LẬP)"]
        Dataset["Cơ sở dữ liệu thực nghiệm giả lập<br/>(skinsyntax_cf_dev / 500 users)"]
        CFModels["Mô hình nghiên cứu Collaborative Filtering<br/>├── Item-Based kNN (Cosine)<br/>├── Pointwise Funk Matrix Factorization<br/>└── Pairwise Bayesian Personalized Ranking (BPR)"]
        Evaluation["Đánh giá ngoại tuyến (Synthetic Evaluation)<br/>Temporal Leave-One-Out Holdout (HR@10, NDCG@10, Coverage)"]

        Dataset -.-> CFModels
        CFModels -.-> Evaluation
    end

    OfflineResearch -.-x|"HOÀN TOÀN KHÔNG KẾT NỐI (DISCONNECTED)"| ProductionRuntime

    style ProductionRuntime fill:#f8fafc,stroke:#3b82f6,stroke-width:2px
    style OfflineResearch fill:#fffbeb,stroke:#f59e0b,stroke-width:2px,stroke-dasharray: 5 5
```

---

## 6. SƠ ĐỒ LUỒNG QUYẾT ĐỊNH THUẬT TOÁN (THESIS ALGORITHM DECISION FLOWCHART)

```mermaid
flowchart TD
    Start(["Bắt đầu: Yêu cầu gợi ý Homepage"]) --> CheckSignals{"Kiểm tra tín hiệu đầu vào"}

    CheckSignals -- "Không hành vi, Không hồ sơ" --> SimpleBranch["Chế độ SIMPLE<br/>• Xếp hạng theo Bayesian Weighted Rating (WR)<br/>• Fallback Top-4 sản phẩm có điểm WR cao nhất"]
    
    CheckSignals -- "Chỉ có tín hiệu hành vi" --> BehaviorBranch["Chế độ BEHAVIOR_CONTENT / PURCHASE_CONTENT<br/>• Hợp nhất vector hành vi U_behavior<br/>• Tái xếp hạng: 0.90 S_Content + 0.10 S_Price"]
    
    CheckSignals -- "Chỉ có hồ sơ da" --> ProfileBranch["Chế độ PROFILE_CONTENT<br/>• Vector hồ sơ da V_profile<br/>• Tái xếp hạng: 0.70 S_Content + 0.20 S_Skin + 0.10 S_Budget"]
    
    CheckSignals -- "Có cả hành vi & hồ sơ da" --> HybridBranch["Chế độ ADAPTIVE_HYBRID<br/>• Vector hỗn hợp: 0.50 U_behavior + 0.50 V_profile<br/>• Tái xếp hạng: 0.70 S_Content + 0.20 S_Skin + 0.10 S_Budget"]

    SimpleBranch --> DiversityCheck["Lọc trùng họ sản phẩm (Product Family Filter)<br/>Giữ tối đa 1 sản phẩm / dòng họ"]
    BehaviorBranch --> DiversityCheck
    ProfileBranch --> DiversityCheck
    HybridBranch --> DiversityCheck

    DiversityCheck --> ExplainTag["Gán nhãn giải thích tương ứng tín hiệu đóng góp"]
    ExplainTag --> Output(["Xuất danh sách Top-4 sản phẩm gợi ý"])
```

# Cập nhật: Gợi ý trang chủ bằng đồ thị Neo4j (home graph recommend)

Thêm 3 khối gợi ý mới lên trang chủ, tính từ đồ thị Neo4j (qua Flask `/api/home/recommend`), không sửa các bộ gợi ý cũ.

## Đã thêm

- **Flask**: blueprint `/api/home/recommend` (`ai-service-flask/home_service/`): chấm điểm, loại trùng, cache Redis TTL 600s.
- **PHP**: `HomeRecommendClient`, `HomeGraphRecommender`, `HomeReasonText`; `HomeController::index` chỉ thêm khóa view `homeGraph`; route mới `index.php?r=home_concern&c=<key>` (`HomeController::concern`, whitelist 9 key, key lạ -> 400).
- **Giao diện** (`frontend/views/partials/`):
  - `home_graph_seen.php`: "Vì bạn đã xem {tên SP}" (4 thẻ, chèn trước khối 4 Flash Sale).
  - `home_graph_skin.php`: "Hợp với {loại da} của bạn" (4 thẻ, chèn trước khối 5 SYNA AI Livestream).
  - `home_graph_concern.php`: "Bạn đang quan tâm vấn đề da nào?" gồm thanh 9 chip (`role="tablist"`/`tab`, `aria-selected`, cuộn ngang trên mobile) + lưới 8 thẻ (chèn trước khối 6 Mới lên kệ).
  - `home_graph_concern_grid.php`: lưới 8 thẻ dùng chung cho khối trang chủ và route `home_concern`.
  - `home_product_card.php`: thẻ sản phẩm trang chủ, **chuyển nguyên văn** closure `$renderHomeProductCard` từ `home.php` (không đổi logic).
  - Mọi link SP trong khối mới nằm trong wrapper `data-rec-section` (seen/skin/concern) và `data-rec-reason` (reason code).
- **JS**: `frontend/public/assets/js/home-graph.js` (vanilla): bấm chip -> `AbortController` hủy request cũ -> skeleton 8 khung -> `fetch(home_concern)` -> thay `innerHTML` lưới; lỗi -> "Chưa tải được, thử lại" + nút `data-retry`. Base URL lấy từ `data-base-url` trên `#home-graph-concern`.
- `frontend/views/home.php`: chỉ thêm 3 dòng include + 1 thẻ `<script ... defer>`; các khối 2/3/4/5.5/6/7 không đổi.

## Biến môi trường

| Biến | Ý nghĩa |
| --- | --- |
| `HOME_GRAPH_ENABLED` | Mặc định bật. `0` -> PHP không gọi Flask, trang chủ như cũ. |
| `HOME_RECOMMEND_ENDPOINT` | URL Flask `/api/home/recommend`. |
| `HOME_RECOMMEND_TIMEOUT_MS` | Timeout PHP (mặc định 800ms tổng, 300ms connect, không retry). |
| `HOME_RECOMMEND_CONNECT_TIMEOUT_MS` | Timeout kết nối PHP -> Flask (mặc định 300ms). |
| `HOME_GRAPH_CACHE_TTL` | TTL cache phía Flask (mặc định 600s). |

**Cách tắt**: đặt `HOME_GRAPH_ENABLED=0` rồi khởi động lại PHP/container. Cầu dao PHP tự mở 60s sau 3 lỗi liên tiếp (key `home:recommend:breaker:v1`).

Phụ thuộc: đồ thị home dùng `neo4j_client.is_enabled`, nên cần `SEARCH_GRAPH_ENABLED=true` ở Flask; nếu tắt thì Flask trả `degraded:true, sections:[]` và các khối mới ẩn.

## Lệnh test

Chạy pytest chung hai thư mục (basename test home đã đổi thành `test_home_*.py` nên không còn xung đột):

```
cd ai-service-flask
pytest tests/home_service tests/search_service -m "not integration"
```

Ghi chú: route `home_concern` trả `Cache-Control: private, no-store`; hồ sơ da `{skin_type, concerns}` (đã làm sạch) được cache trong `$_SESSION['home_graph_skin_profile']` (khóa hash email, TTL 10 phút) để route này không truy vấn Mongo; `HomeGraphRecommender::hydrate` chỉ gọi `findVisibleByIds` một lần cho mọi khối.

PHP (chỉ 3 file của kế hoạch này; không chạy hàng loạt `backend/tests` vì nhiều script ghi DB thật):

```
php backend/tests/home_reason_text_test.php
php backend/tests/home_recommend_client_test.php
php backend/tests/home_graph_recommender_test.php
```

(Tên file đúng theo `backend/tests/home_*_test.php` trong repo.)

## Kiểm tra đã chạy cho Task 7 (trong worktree)

- `php -l` cho 4 partial mới + `home.php`: không lỗi cú pháp.
- `node --check frontend/public/assets/js/home-graph.js`: OK.
- Render PHP tạm (không commit) từng partial với dữ liệu `$homeGraph` giả: chip `role="tab"` x9, đúng 1 `aria-selected="true"`, `data-base-url`, `data-rec-section` x4/x4/x8, tên SP/lý do được `h()` escape (không còn thẻ `<script>`/`<b>` thô), khối `null` thì không in gì, trạng thái lỗi có nút `data-retry`.

## E2E

Chạy thật sau khi merge vào `syna` (2026-10-23), trên Docker `localhost:8080`, bằng trình duyệt tích hợp của Claude Desktop (Playwright MCP không kết nối được trong phiên này) + curl.

| # | Kịch bản | Kết quả |
|---|---|---|
| 0 | Recreate `ai-service` + `php-backend`; log Flask không có `home_service not registered`; `POST /api/home/recommend` từ container PHP có token → 200, `degraded:false`, seen 4 / skin 4 / concern 8; không token từ host → 401 | PASS (took_ms nguội 1166, ấm 6 nhờ Redis) |
| 1 | Khách mới: khối vấn đề da hiện, chip "Thâm mụn" được chọn, 8 thẻ có lý do ("Chứa Tranexamic Acid, hỗ trợ thâm mụn") | PASS |
| 2 | Bấm "Mụn" rồi "Khô / thiếu ẩm" → lưới đổi bộ id khác, lý do đúng vấn đề, không trạng thái lỗi | PASS |
| 3 | Xem SP 1086 → về trang chủ: "Vì bạn đã xem Nước Tẩy Trang Bioderma…", 4 thẻ ("Cùng chứa Zinc Gluconate với…"), không gợi ý lại chính SP đã xem | PASS |
| 4 | Hồi quy: khối 2, khối 3, "Được Yêu Thích Nhất", "Mỹ Phẩm Vừa Lên Kệ" còn đủ; 0 SP trùng giữa khối mới và "Được Yêu Thích Nhất" | PASS |
| 5 | `home_concern&c=mun` → 200 `text/html`, `Cache-Control: private, no-store`, 8 thẻ; `c=<script>` → 400 `Yêu cầu không hợp lệ`, không phản chiếu input | PASS |
| 6 | `docker stop skinsyntax-ai` → trang chủ vẫn 200, khối mới ẩn, khối cũ đủ, không lỗi PHP; bật lại health 200 | PASS |
| 7 | TTFB trang chủ (curl, 4 lần): Flask chạy 0,24–0,92 s; Flask tắt 0,06–1,08 s — tương đương, số liệu nhiễu; chưa đo riêng cờ `HOME_GRAPH_ENABLED=0` | Đo xong, chưa kết luận p95 |
| 8 | Mobile 375px: thanh chip cuộn ngang (1014px trong 351px), trang không tràn ngang | PASS |
| 9 | "Hợp với da bạn" với tài khoản đã làm khảo sát | **NOT RUN** — không có tài khoản test có khảo sát; đã kiểm qua API (skin 4 SP, `skin_type=da_dau`) |

Sửa phát sinh khi chạy thật: route `home_concern` chỉ được thêm vào `index.php` gốc (XAMPP), nhưng Docker dùng `backend/public/index.php` (nginx root `/var/www/html/public`, `/var/www/html` = `backend/`) → đã thêm `case 'home_concern'` vào `backend/public/index.php`. Sau khi sửa PHP trong Docker cần `docker exec skinsyntax-php kill -USR2 1` (opcache `revalidate_freq=60`).

## Số đo (Task 4, Flask, không Redis)

- Lần đầu (cold): 1671ms; lần sau (warm): 157ms.
- Truy vấn Neo4j ở lần chạy thứ 2: 3–146ms.

## Rủi ro

- Request đầu tiên sau khi Neo4j/Flask khởi động lại có thể vượt timeout PHP 800ms -> các khối mới ẩn cho đến khi cache ấm (trang chủ cũ vẫn đủ).
- Quan hệ `HELPS` chỉ có 88 cạnh -> khối theo vấn đề da có thể thưa với một số vấn đề (dưới 4 SP thì ẩn).
- Neo4j dùng ~82% RAM của giới hạn 1g -> dễ chậm/OOM khi tải tăng.

## Không bị sửa

Các bộ gợi ý cũ giữ nguyên: Simple (IMDb WR) `getSimpleRecommenderProducts`, Adaptive/Hybrid `getHybridRecommendations`, `getHomepageProductSections`, `SearchRecommendClient`, `search_service/graph_repository.py`. Closure thẻ SP chỉ được chuyển nguyên văn sang `partials/home_product_card.php`.

`update.md` chưa được sửa trong nhánh này (tránh xung đột với thay đổi chưa commit ở checkout chính); cần thêm 1 dòng trỏ tới file này sau khi merge.

## 2026-11-09 — Khối "Vì bạn đã xem" thành băng chuyền, xáo mỗi lần tải

- `HomeGraphRecommender::LIMITS['seen']` tăng 4 thành 12 (Flask kẹp 4..12 và tối đa 2 SP/thương hiệu nên thực tế có thể ra ít hơn, ví dụ 10).
- `backend/app/services/HomeRotation.php` (`weightedShuffle`, Efraimidis–Spirakis, trọng số `0.8^hạng`): SP liên quan nhất vẫn hay đứng đầu nhưng mỗi lần F5 thứ tự khác nhau. `HomeController::index` gọi ngay sau `build()`.
- Giao diện: `partials/home_graph_seen.php` render mọi SP trong `.hgc-track` (scroll-snap, 2/3/4 thẻ mỗi màn hình theo breakpoint) kèm nút trước/sau (`.hgc-nav`, `.hgc-arrow`); điện thoại vuốt ngang, ẩn nút. CSS ở cuối `style.css`.
- JS: `frontend/public/assets/js/home-carousel.js` (bật/tắt nút theo vị trí cuộn, ẩn nav khi không tràn, phím mũi tên trái/phải), nạp trong `home.php` sau `home-graph.js`.
- Test: `php backend/tests/unit/HomeRotationTest.php`, `php backend/tests/home_graph_recommender_test.php` (assert limits 12/4/8).

## 2026-11-09 – Thẻ "Vì sao phù hợp": câu ngắn + nhãn nổi bật

- Vấn đề: dòng hiển thị của khối "Vì bạn đã xem" khô ("Cùng loại Serum / Tinh Chất"), thông tin hay nằm trong popover.
- Quy tắc mới (`HomeWhyFitCopy::build($reason, $detail, $cat)`, hàm thuần): 1 câu + tối đa 2 nhãn.
  - Câu: thành phần chung qua bảng tra → "Cũng có {A} + {B} – {lợi ích}" (lợi ích = mệnh đề đầu của A có trong B) / "Cũng có {A} – {lợi ích}"; INCI lạ → "Cũng có {INCI}" (+ công dụng chung); không có thành phần → "Cũng {công dụng chung}"; còn lại → "Cùng loại {danh mục}"; rỗng → thẻ giữ câu cũ.
  - Nhãn: giá trị trước ("Rẻ hơn 188K", "Rẻ hơn 1,2 triệu", "Giá tương đương", "Rẻ hơn 20%/ml", "Giảm 50%", "Đánh giá 4,8★"), sau đó "+ {công dụng thêm}" (bỏ nếu đã có trong câu).
- Bảng tra: `backend/app/config/ingredient_glossary.php` (rút gọn từ `RecommendationExplainer::ACTIVES_KB`, mục đầu khớp thắng, không có thành phần chung chung).
- File: `HomeGraphRecommender.php` (require + `$p['why_fit']`), `partials/home_product_card.php` (chỉ dùng `why_fit` khi `reason_text` trùng câu đang hiển thị; popover bỏ dòng giá trị/"Có thêm" nếu đã thành nhãn), cuối `style.css`.
- Test: `php backend/tests/unit/HomeWhyFitCopyTest.php` (gồm ca tích hợp với `HomeReasonDetail` thật).

- Thẻ ở khối "Hợp với da…" / "Hợp với vấn đề…" dùng `HomeWhyFitCopy::forProduct($sp, $reason, $section)` (gọi ở nhánh `else` của `HomeGraphRecommender::hydrate`): trước đây mọi thẻ cùng một câu "Phù hợp Da khô · Hỗ trợ Mụn".
- Câu: hoạt chất đầu tiên trong `thanh_phan_sach` có trong bảng tra (kèm nồng độ, vd "4% Niacinamide"; mục "concern_ingredient" đẩy thành phần được gợi ý lên trước) → "Có {A} + {B} – {lợi ích}"; không có → "Giúp {công dụng 1}, {công dụng 2}" từ `cong_dung_keys`; không có gì → giữ câu cũ.
- Nhãn (tối đa 2): Giảm N% (>= 20), "4,9★ (263)" (>= 4,5 và >= 20 đánh giá), "Đã bán 1,2K" (>= 100); riêng khối da thêm "Hỗ trợ {vấn đề}" CHỈ khi `van_de_da_keys` của SP có vấn đề đó (hết cảnh "Hỗ trợ Mụn" gắn bừa).
- Bảng tra: mục Tranexamic khớp thêm "t.x.a"/"TXA". Test: `php backend/tests/unit/HomeWhyFitCopyTest.php`.

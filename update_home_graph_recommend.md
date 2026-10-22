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
| `HOME_GRAPH_CACHE_TTL` | TTL cache phía Flask (mặc định 600s). |

**Cách tắt**: đặt `HOME_GRAPH_ENABLED=0` rồi khởi động lại PHP/container. Cầu dao PHP tự mở 60s sau 3 lỗi liên tiếp (key `home:recommend:breaker:v1`).

Phụ thuộc: đồ thị home dùng `neo4j_client.is_enabled`, nên cần `SEARCH_GRAPH_ENABLED=true` ở Flask; nếu tắt thì Flask trả `degraded:true, sections:[]` và các khối mới ẩn.

## Lệnh test

Chạy pytest **theo từng thư mục** (basename test trùng nhau vì không có `__init__.py`):

```
cd ai-service-flask
pytest tests/home_service
pytest tests/search_service
```

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

**NOT RUN (deferred to merge)** — Docker đang phục vụ checkout chính chứ không phải worktree này, nên chưa chạy được curl/Playwright thật. Sau khi merge cần chạy 7 kịch bản trong brief (khách mới + chip, đổi chip, "Vì bạn đã xem", hồi quy các khối cũ và không trùng "Được Yêu Thích Nhất", `docker stop skinsyntax-ai`, mobile 375px, "Hợp với da bạn" với tài khoản có khảo sát). Chưa có kết quả PASS nào được ghi nhận.

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

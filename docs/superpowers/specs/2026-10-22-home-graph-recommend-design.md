# Trang chủ cá nhân hóa bằng Neo4j — Giai đoạn 1 (thiết kế)

Ngày: 2026-10-22 · Trạng thái: chờ duyệt · Nhánh: `syna`

## 1. Mục tiêu và ràng buộc

**Mục tiêu.** Trang chủ hiện chỉ có các khối chung, giống nhau cho mọi khách. Thêm 3 khối gợi ý dựa trên đồ thị Neo4j, mỗi thẻ sản phẩm có nhãn lý do thật:

1. **Vì bạn đã xem** — sản phẩm tương tự các SP khách vừa xem.
2. **Hợp với da bạn** — theo loại da + vấn đề da trong khảo sát.
3. **Theo vấn đề da** — 9 tab vấn đề da, mỗi tab 8 SP.

**Ràng buộc bắt buộc (không thương lượng).**

- **Không xóa, không sửa logic** các thuật toán gợi ý hiện có: Simple Recommender (IMDb Weighted Rating, `SanPham::getSimpleRecommenderProducts`), Adaptive/Hybrid Recommender (`SanPham::getHybridRecommendations`), `SanPham::getHomepageProductSections`, và các khối trang chủ 2 (lưới vấn đề da), 3 (Dành Riêng Cho Bạn / Sản phẩm nổi bật), 4 (Flash Sale), 5.5 (Được Yêu Thích Nhất), 6 (Mỹ Phẩm Vừa Lên Kệ), 7 (Routine).
- Khối mới chỉ **thêm vào**. Code mới chỉ **đọc** kết quả các khối cũ (mã SP đã hiển thị) để loại trùng.
- `HomeController::index` chỉ được **thêm** dòng sau lời gọi `getHomepageProductSections`; không đổi lời gọi cũ hay biến đã truyền cho view.
- `$renderHomeProductCard` trong `home.php` không sửa; dùng tham số `$whyFitText` có sẵn để in lý do.
- PHP không kết nối trực tiếp Neo4j (ranh giới service trong `AGENTS.md`): PHP → Flask → Neo4j.
- MongoDB là nguồn sự thật; giá/ảnh/tồn kho luôn đọc lại từ Mongo.

**Tiêu chí hoàn thành.**

- Khách chưa đăng nhập luôn thấy khối "Theo vấn đề da" (khi Flask/Neo4j chạy).
- Khách đã xem ≥ 1 SP thấy thêm "Vì bạn đã xem" (nếu đủ ≥ 4 SP).
- Người đã làm khảo sát (có loại da) thấy thêm "Hợp với da bạn".
- Mọi thẻ trong khối mới có dòng lý do lấy từ đường đi thật trong đồ thị.
- Các khối cũ vẫn hiển thị đúng như trước; tắt Flask hoặc Neo4j thì trang chủ vẫn hiển thị đủ khối cũ.

## 2. Kiến trúc và luồng dữ liệu

```
HomeController::index (PHP)
  ├─ các khối cũ: giữ nguyên
  └─ HomeGraphRecommender (PHP, MỚI)
        └─ HomeRecommendClient ─POST /api/home/recommend─► Flask home_service (MỚI)
                                                              └─ Neo4j (dùng lại search_service.neo4j_client + cầu dao)
        ◄─ {sections:[{key, items:[{product_id, reason:{code, ...}}]}], degraded}
        → hydrate SP từ Mongo (SanPham) → partial mới trong frontend/views/partials/

Bấm tab vấn đề da (JS) ─GET index.php?r=home_concern&c=<key>─► HomeController::concern (MỚI)
        → cùng client, chỉ xin section "concern" → trả HTML 8 thẻ SP
```

### 2.1 Flask — module mới `ai-service-flask/home_service/`

| File | Việc |
|---|---|
| `routes.py` | Blueprint `home_bp`: `POST /api/home/recommend`. Dùng lại `search_service.routes.is_authorized` (header `X-Internal-Token` = `SEARCH_INTERNAL_TOKEN`; không có token → chỉ cho loopback/private). Biên cuối: mọi exception → 200 `{degraded:true, sections:[]}`, không trả 500 lộ nội bộ. |
| `schemas.py` | Validate request (giới hạn kích thước, key hợp lệ). |
| `queries.py` | Câu Cypher của 3 khối (tách khỏi `search_service/graph_repository.py`, không sửa file đó). |
| `service.py` | Gọi query, chấm điểm, loại trùng, áp ngưỡng, cache Redis. |
| `contract/home_recommend_response.schema.json` | JSON schema của response (dùng cho test hợp đồng cả hai phía). |

Đăng ký blueprint trong `chatbot_service/chatbot_flask.py`, cạnh `search_bp`. API `/api/search/recommend` không đổi.

### 2.2 Request

```json
{
  "sections": ["seen", "skin", "concern"],
  "seen_ids": ["123", "456"],
  "skin_profile": {"skin_type": "da_dau", "concerns": ["tham_mun", "mun"]},
  "concern": "tham_mun",
  "cart_ids": ["789"],
  "exclude_ids": ["1000", "266"],
  "limits": {"seen": 4, "skin": 4, "concern": 8}
}
```

- `seen_ids` ≤ 5 (thứ tự MRU, phần tử đầu = xem gần nhất); `cart_ids` ≤ 50; `exclude_ids` ≤ 100; id là chuỗi số.
- `skin_profile` lấy qua `SearchSkinProfile::fromCustomer` (chỉ key đồ thị; không tên/email/ngân sách). Có thể `null`.
- `concern` phải thuộc 9 key: `mun, tham_mun, nam_tan_nhang, lo_chan_long, dau_thua, kho_da, kich_ung, lao_hoa, xin_mau`. Bỏ trống → mặc định `skin_profile.concerns[0]`, nếu không có thì `tham_mun`.
- Sai cấu trúc → 400 `{error:"invalid_request", field}`.

### 2.3 Response

```json
{
  "degraded": false,
  "took_ms": 42,
  "sections": [
    {"key": "seen", "seed": {"id": "123", "name": "..."}, "items": [
      {"product_id": "555", "reason": {"code": "seen_shared_ingredient", "seed_id": "123", "seed_name": "...", "ingredients": ["Niacinamide"]}}
    ]},
    {"key": "skin", "skin_type": {"key": "da_dau", "name": "Da dầu"}, "items": [
      {"product_id": "777", "reason": {"code": "skin_fit", "skin_type": "Da dầu", "concerns": ["Thâm mụn"]}}
    ]},
    {"key": "concern", "concern": {"key": "tham_mun", "name": "Thâm mụn"}, "items": [
      {"product_id": "888", "reason": {"code": "concern_ingredient", "concern": "Thâm mụn", "ingredients": ["Niacinamide"]}}
    ]}
  ]
}
```

Section không đủ ngưỡng thì **không có trong mảng** `sections`. Neo4j tắt/cầu dao mở → `degraded:true`, `sections:[]`.

### 2.4 PHP

| File | Việc |
|---|---|
| `backend/app/services/HomeRecommendClient.php` (MỚI) | Theo mẫu `SearchRecommendClient`: transport tiêm được (để test), timeout tổng 800ms / connect 300ms, không retry, cầu dao Redis **key riêng** (`home_recommend:breaker`), validate response, lỗi → `null`. Env: `HOME_RECOMMEND_URL`, `HOME_RECOMMEND_TIMEOUT_MS`, `HOME_RECOMMEND_CONNECT_TIMEOUT_MS`, dùng chung `SEARCH_INTERNAL_TOKEN`. |
| `backend/app/services/HomeGraphRecommender.php` (MỚI) | Gom input (session `recent_viewed_products`, `gio_hang`, hồ sơ da, mã SP đã hiện ở các khối cũ), gọi client, hydrate SP từ Mongo theo `ma_san_pham`, bỏ SP không còn tồn tại/ngừng bán, ghép câu lý do tiếng Việt. Trả `['seen'=>?, 'skin'=>?, 'concern'=>?, 'concern_keys'=>[...]]`. |
| `backend/app/services/HomeReasonText.php` (MỚI) | Hàm thuần: `reason` → câu tiếng Việt (xem §3.4). |
| `backend/app/controllers/HomeController.php` | `index`: **thêm** sau `getHomepageProductSections`: nếu `HOME_GRAPH_ENABLED` bật → gọi `HomeGraphRecommender`, truyền thêm biến `$homeGraph` cho view. **Thêm** action `concern()` cho route `home_concern`. |
| Router `index.php` (gốc repo) | Thêm route `home_concern` → `HomeController::concern`. |

`exclude_ids` = mã SP thực sự in ra ở khối 3 (`$forYouProducts`), 5.5 (`$topRatedWeightedProducts`), 6 (`$latest` 4 SP đầu) và Flash Sale top 4.

## 3. Cách chọn sản phẩm

### 3.1 Quy tắc chung

- Loại: `active=false`, `in_stock=false`, SP trong `cart_ids`, SP trong `exclude_ids`, SP có `CONTAINS` thành phần `CONFLICTS_WITH` với thành phần của bất kỳ SP trong giỏ.
- Loại trùng giữa khối mới theo thứ tự ưu tiên: **seen → skin → concern**.
- Ngưỡng: section có < 4 SP thì bỏ (concern cũng cần ≥ 4).
- Hòa điểm: `sold DESC, id ASC` (xác định, dễ test).
- "Thành phần chính" = `CONTAINS.rank ≤ 5` và `role` không thuộc `GENERIC_ROLES` của `search_service/graph_repository.py` (`base, humectant, emollient, preservative, fragrance`); import hằng số đó, không chép lại.

### 3.2 Vì bạn đã xem

- Seed: `seen_ids`, trọng số theo vị trí `w = 1.0, 0.8, 0.6, 0.4, 0.2`.
- Ứng viên: SP nối với seed qua `SIMILAR_TO` (truy vấn không hướng).
- Điểm = Σ_seed `w × (SIMILAR_TO.score + 0.05 × số thành phần chính chung)`.
- Đa dạng: tối đa 2 SP / thương hiệu.
- Lý do: có thành phần chính chung → `seen_shared_ingredient` (tối đa 2 tên, seed có đóng góp lớn nhất); không → `seen_same_category`.
- Tiêu đề khối dùng tên seed đầu tiên (xem gần nhất).

### 3.3 Hợp với da bạn

- Chỉ chạy khi `skin_profile.skin_type` có giá trị.
- Ứng viên: SP `SUITABLE_FOR` loại da đó hoặc `moi_loai_da`.
- Điểm = `1.0` (cạnh đúng loại da, `source='data'`) / `0.7` (đúng loại da, `inferred`) / `0.4` (chỉ `moi_loai_da`) + `0.3 × số vấn đề da trùng (TARGETS)` + `0.1 × log10(1 + sold) / 4`.
- Lý do: `skin_fit` kèm tên loại da và tối đa 2 tên vấn đề da trùng.

### 3.4 Theo vấn đề da

- Ứng viên: SP `TARGETS` vấn đề đang chọn.
- Điểm = `0.5` nếu chứa thành phần chính `HELPS` vấn đề đó + `0.2` nếu `SUITABLE_FOR` loại da của khách (khi có) + `0.3 × log10(1 + sold) / 4` + `0.1 × rating / 5`.
- Lý do: có thành phần `HELPS` → `concern_ingredient`; không → `concern_target`.

### 3.5 Câu lý do (PHP ghép, `HomeReasonText`)

| code | Câu |
|---|---|
| `seen_shared_ingredient` | "Cùng chứa {A}[, {B}] với {seed rút gọn 30 ký tự}" |
| `seen_same_category` | "Cùng loại với {seed rút gọn}" |
| `skin_fit` | "Phù hợp {loại da}[ · Hỗ trợ {vấn đề 1}[, {vấn đề 2}]]" |
| `concern_ingredient` | "Chứa {A}, hỗ trợ {vấn đề viết thường}" |
| `concern_target` | "Dành cho {vấn đề viết thường}" |
| khác/không rõ | "Gợi ý cho bạn" |

Không in tên thương hiệu trong câu lý do. Mọi chuỗi qua `h()` khi render.

## 4. Giao diện

- 3 partial mới: `frontend/views/partials/home_graph_seen.php`, `home_graph_skin.php`, `home_graph_concern.php`. `home.php` chỉ thêm 3 `include`, mỗi cái trong `if` có dữ liệu.
- Vị trí chèn (không di chuyển khối cũ): `seen` ngay sau khối 3; `skin` ngay sau khối 4 (Flash Sale); `concern` ngay sau khối 5.5.
- Thẻ SP: `$renderHomeProductCard($p, $tag, $reasonText)`. `$tag`: "Vì bạn đã xem" / "Hợp da bạn" / tên vấn đề.
- `seen` và `skin`: 4 thẻ, lưới `col-6 col-md-3` như khối 3.
- `concern`: thanh 9 chip (cuộn ngang trên mobile, chip đang chọn tô `text-success`/nền nhạt), 8 thẻ, nút "Xem tất cả" → `index.php?r=tatca&q=<tên vấn đề>`.
- JS `frontend/assets/js/home-graph.js` (vanilla): bấm chip → `fetch(index.php?r=home_concern&c=key)` → thay lưới; khung xám khi chờ; lỗi → "Chưa tải được, thử lại" + nút thử lại; hủy request cũ khi bấm chip khác (AbortController).
- `HomeController::concern` trả HTML partial (không layout), `Content-Type: text/html`; key không hợp lệ → 400; Flask lỗi → 200 với khối thông báo lỗi để JS hiển thị nút thử lại.
- Mỗi link SP trong khối mới có `data-rec-section` và `data-rec-reason` (chưa gửi dữ liệu đo ở giai đoạn 1).

## 5. Lỗi, tốc độ, bảo mật

- Cờ `HOME_GRAPH_ENABLED` (mặc định `true`; `false` → không gọi Flask, trang chủ như cũ). Thêm vào `.env.example` và `docker-compose.yml` (service php-backend).
- Flask: mỗi query Neo4j dùng timeout của `search_service.config` (cùng giá trị nhánh graph tìm kiếm); lỗi kết nối → `neo4j_client.report_failure` (mở cầu dao chung) → section rỗng, `degraded:true`.
- Cache Redis trong Flask, TTL 600s, lưu **danh sách ứng viên đã chấm điểm** (≤ 60 id) trước khi lọc theo từng người:
  - `home:cand:seen:<product_id>` (mỗi seed riêng, cộng dồn trọng số khi đọc),
  - `home:cand:skin:<skin_type>:<concerns sắp xếp>`,
  - `home:cand:concern:<concern>:<skin_type|_>`.
  Lọc giỏ hàng/loại trừ/xung đột/trùng/ngưỡng làm sau cache. Redis lỗi → tính trực tiếp.
- Xung đột thành phần với giỏ: 1 query riêng theo `cart_ids` trả tập id bị loại (không cache khi giỏ thay đổi; giỏ rỗng → bỏ qua).
- Mục tiêu: Flask p95 < 300ms khi cache ấm; trang chủ thêm tối đa ~0,8s khi Flask treo (timeout), 0ms khi cầu dao mở.
- Log: chỉ correlation id, `took_ms`, danh sách section trả về, `degraded`. Không log email, hồ sơ da, giỏ hàng.

## 6. Kiểm thử

- **Flask (pytest, `ai-service-flask/tests/home_service/`)**
  - Đơn vị với repository giả: chấm điểm 3 khối, trọng số MRU, giới hạn 2 SP/thương hiệu, loại trùng theo thứ tự ưu tiên, ngưỡng 4, loại SP xung đột với giỏ, mặc định concern, validate request (400), biên exception → degraded.
  - Tích hợp Neo4j thật (theo mẫu `tests/search_service/test_graph_integration.py`, tự skip khi không kết nối được).
  - Hợp đồng: response hợp lệ theo `home_recommend_response.schema.json`.
- **PHP**
  - `php -l` mọi file mới/sửa.
  - Test client (transport giả: timeout, 500, JSON sai cấu trúc, cầu dao mở → `null`) và test `HomeReasonText`. Đặt ở `backend/tests/` dạng script chỉ đọc, không chạm DB. **Không chạy hàng loạt `backend/tests`** (nhiều script ghi DB thật).
  - Test hợp đồng phía PHP: `validateResponse` chấp nhận ví dụ trong schema, từ chối bản thiếu trường.
- **E2E (Playwright, server thật `localhost:8080`)**
  1. Khách chưa đăng nhập: thấy khối "Theo vấn đề da"; bấm 2 chip khác nhau → lưới đổi SP, mỗi thẻ có dòng lý do.
  2. Xem 1 SP → về trang chủ → thấy "Vì bạn đã xem {tên SP}".
  3. Hồi quy: vẫn có "Được Yêu Thích Nhất", "Mỹ Phẩm Vừa Lên Kệ", khối 2 và khối 3 như trước.
  4. `docker stop skinsyntax-ai` (hoặc `HOME_GRAPH_ENABLED=false`) → trang chủ vẫn hiển thị các khối cũ, không lỗi.
  5. Khối "Hợp với da bạn": cần tài khoản test đã làm khảo sát. Nếu không có → kiểm bằng gọi API Flask với `skin_profile` và ghi **E2E NOT RUN** cho bước này.

## 7. Ngoài phạm vi (giai đoạn 2+)

Hoàn thiện routine có kiểm tra xung đột, "Thường được mua cùng" (cần thêm dữ liệu đơn hàng), gửi sự kiện đo CTR, đổi thứ tự khối theo người dùng, mọi chỉnh sửa thuật toán cũ.

## 8. Rủi ro

- `HELPS` chỉ có 88 cạnh → nhiều SP sẽ nhận lý do `concern_target` thay vì có tên thành phần. Chấp nhận ở giai đoạn 1.
- Neo4j đang dùng ~82% trần RAM 1g; query mới phải dùng index sẵn có và `LIMIT`, cache Redis giảm tải.
- Trang chủ gọi Flask đồng bộ: phụ thuộc timeout 800ms và cầu dao để không làm chậm trang khi Flask có vấn đề.

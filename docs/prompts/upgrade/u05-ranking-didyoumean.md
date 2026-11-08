# U05 — XẾP HẠNG KẾT QUẢ CHÍNH XÁC + "CÓ PHẢI BẠN MUỐN TÌM…"

## Vai trò
Kỹ sư tìm kiếm. Kết quả chính xác ở `r=tatca` đang theo thứ tự mặc định của Mongo; cần đẩy sản phẩm khớp tốt nhất lên đầu, và gợi ý sửa từ khóa khi gõ sai.

## Đọc trước
`u00-common-rules.md` → handoff → `SanPham::paginateWithProjection` (nhánh `sort=default` + có `q`), `SanPhamController::tatca` (cache `catalog:tatca:*` `'v' => 2`), `ai-service-flask/search_service/intent_parser.py` (fuzzy brand), `search_fallback.php`.

## Phần A — Xếp hạng (chỉ khi `sort=default` và có `q` không phải mã số)
- Chuyển truy vấn sang `aggregate` **chỉ cho nhánh này** (các sort khác giữ `find`):
  ```
  $match (filter token hiện có + availableProductFilter)
  $addFields: _score = 
      100 * (tên chứa đủ mọi token theo thứ tự)              // cụm liền
    +  60 * (tên bắt đầu bằng token đầu)
    +  40 * (số token khớp trong tên / tổng token)
    +  30 * (hãng khớp một token)
    +  10 * log10(1 + so_luong_da_ban)
    -  50 * (hết hàng)
  $sort {_score:-1, so_luong_da_ban:-1, ma_san_pham:1}
  $facet {items:[$skip,$limit,$project LISTING_PROJECTION], total:[$count]}
  ```
  Dùng `$regexMatch` với regex không dấu đã có (`buildAccentInsensitiveRegex`). Trọng số đặt hằng số có tên, dễ chỉnh.
- Phân trang phải ổn định (tie-breaker `ma_san_pham`). Total phải bằng total cũ (so sánh 10 truy vấn).
- Bump cache key `'v' => 3`.
- GitNexus impact `paginateWithProjection`/`paginate` (MEDIUM trước đây) — admin dùng `paginate(..., false)`: chỉ bật xếp hạng mới cho public (`$onlyVisibleOnWebsite=true`) nếu admin có rủi ro; ghi quyết định.
- Đo thời gian truy vấn Atlas trước/sau (10 truy vấn × 3 lần). Nếu chậm hơn > 150ms p95: thêm giới hạn `$limit` sớm hoặc ghi đề xuất index.

## Phần B — "Có phải bạn muốn tìm…"
- Flask: endpoint nhẹ `GET /api/search/intent?q=` (auth nội bộ như recommend, rate limit U03) trả `{intent, corrected_q, corrections:[{from,to,type}]}`:
  - `corrections` sinh khi intent parser khớp **fuzzy** (brand gõ sai) hoặc synonym viết tắt rõ ràng (`srm` → "sữa rửa mặt" — chỉ báo dạng mở rộng, không coi là lỗi).
  - `corrected_q` = q thay phần gõ sai bằng tên hiển thị đúng (`toriden srm` → `Torriden sữa rửa mặt`).
  - Cache Redis 10 phút.
- Hoặc tái dùng `intent` đã có trong response recommend nếu fallback được gọi — nhưng cần cả khi có kết quả, nên gọi endpoint riêng với timeout 300ms, lỗi → bỏ qua.
- PHP: khi có `corrections` loại `fuzzy`:
  - Nếu q gốc có **0** kết quả và `corrected_q` có kết quả → **tự tìm theo `corrected_q`**, hiện dòng: "Đang hiển thị kết quả cho «Torriden sữa rửa mặt». Tìm chính xác «toriden srm»" (link `&exact=1` để tắt tự sửa).
  - Nếu q gốc có kết quả → chỉ hiện "Có phải bạn muốn tìm «…»?" (link).
- Escape mọi chuỗi; `exact=1` tôn trọng tuyệt đối.

## Kiểm thử
- PHP unit: hàm tính điểm thuần (nếu tách được để test) hoặc test pipeline builder (cấu trúc mảng aggregate).
- Python unit: corrections cho fuzzy / synonym / không sửa.
- E2E Playwright MCP: "torriden" → SP Torriden bán chạy nhất lên đầu; "kem chống nắng" → SP tên bắt đầu "Kem Chống Nắng" lên trước; "toriden srm" → tự sửa + banner + link exact; "torriden sữa rửa mặt" → 1496 đứng đầu; admin tìm vẫn đúng. Ảnh `report/upgrade-e2e/u05-*.png`.
- So total cũ/mới cho 10 truy vấn (bảng trong báo cáo).

## Đầu ra
Mục "U05" trong handoff: công thức điểm, cache v3, endpoint intent, thời gian truy vấn trước/sau. Không commit.

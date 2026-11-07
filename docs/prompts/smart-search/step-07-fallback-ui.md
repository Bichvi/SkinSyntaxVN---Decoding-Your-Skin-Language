# BƯỚC 07 — GIAO DIỆN "SMART FALLBACK" TRÊN TRANG TÌM KIẾM

## Vai trò
Kỹ sư frontend (PHP view + CSS + JS thuần). Thay hộp "Không tìm thấy sản phẩm phù hợp" bằng giao diện gợi ý nhiều tầng, đúng design system hiện có, responsive, có kiểm thử E2E thật.

## Đọc trước
1. `AGENTS.md`, `.claude/skills/e2e/SKILL.md`, `.claude/skills/frontend-testing/SKILL.md`
2. `docs/plans/smart-search-handoff.md` (mục Bước 06: **shape chính xác** của `$searchFallback`)
3. `frontend/views/tatca.php` (khối kết quả rỗng; cách render lưới SP; partial card SP đang dùng)
4. `frontend/views/home.php` — khối "Có thể bạn quan tâm / Tìm kiếm gần đây" (đã có card với badge góc phải, nút "Thêm vào giỏ" / "Mua ngay") → **tái sử dụng** markup/class của card này.
5. CSS/JS đang dùng cho 2 trang trên (tìm file theo class).
6. Cách mở chatbot Syna hiện có (tìm nút/handler mở chat, có hỗ trợ điền sẵn câu hỏi không).

## Shape dữ liệu view nhận (từ Bước 06 — xác nhận lại trong handoff)
```php
$searchFallback = null | [
  'degraded' => bool,
  'chips'    => [['label'=>string, 'url'=>string], ...],
  'sections' => [[ 'key'=>string, 'title'=>string,
                   'items'=>[ [/* document SP như lưới hiện tại */ ..., 'reason_text'=>string], ... ] ], ...],
]
```

## Bố cục (từ trên xuống)
```
┌ Thanh tìm kiếm / bộ lọc hiện tại (giữ nguyên)
├ Banner trung tính
│   Tiêu đề: Chưa có sản phẩm khớp chính xác với «{q}»
│   Mô tả : Đây là những lựa chọn gần nhất SkinSyntax gợi ý cho bạn.
│   (KHÔNG viết "shop chưa có sản phẩm của <hãng>")
├ Chips lọc nhanh (cuộn ngang trên mobile): mỗi chip là <a> tới chip.url
├ Section × N (theo thứ tự mảng):
│   Tiêu đề = section.title ; lưới card (4 cột ≥1200px, 3 cột ≥992px, 2 cột <992px)
│   Mỗi card = card có sẵn + badge nhỏ reason_text (thay chỗ badge "TÌM KIẾM GẦN ĐÂY")
├ CTA cuối: khối "Chưa thấy sản phẩm ưng ý?" + nút [Hỏi Syna để tìm sản phẩm thay thế]
│   → mở chatbot, điền sẵn: "Mình đang tìm {q}, gợi ý giúp mình sản phẩm thay thế phù hợp"
└ Nút cũ "Xem tất cả sản phẩm" giữ lại ở cuối, dạng link phụ
```
- `$searchFallback === null` và không có kết quả → giữ hộp rỗng cũ (không đổi hành vi).
- Có kết quả bình thường nhưng < 4 + có fallback → hiện lưới kết quả trước, rồi tiêu đề "Gợi ý thêm cho bạn" + các section (không hiện banner "chưa có sản phẩm khớp").
- `degraded=true` → không hiện gì khác (người dùng không cần biết).

## Yêu cầu kỹ thuật
- Tách partial `frontend/views/partials/search_fallback.php` (hoặc thư mục partial hiện có); `tatca.php` chỉ `include` + truyền biến.
- **Escape mọi output** bằng `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, URL chip qua `htmlspecialchars` (đã build bằng `http_build_query` ở controller). `q` trong câu chatbot: truyền qua `data-*` attribute đã escape, JS đọc `dataset`, **không** chèn vào script inline.
- CSS: dùng biến màu/radius/shadow có sẵn của dự án; class tiền tố `ssf-` (search smart fallback) để không đụng class cũ; không thêm thư viện.
- Chips cuộn ngang: `overflow-x:auto; scroll-snap-type:x mandatory;` ẩn scrollbar; ở 375px **không cuộn ngang cả trang**.
- Ảnh card: `loading="lazy"`, có `width/height` hoặc aspect-ratio để không nhảy layout.
- Accessibility: banner dùng `role="status"`; mỗi section là `<section aria-labelledby>`; chip/nút có focus style rõ; badge lý do có `aria-label`.
- Tracking (nếu dự án có analytics/InteractionLogger phía client): mỗi card có `data-ssf-section`, `data-ssf-reason`, `data-product-id`; JS nhỏ gửi `navigator.sendBeacon` khi click card/chip tới endpoint log tương tác có sẵn. Nếu chưa có endpoint phù hợp → chỉ thêm data-attribute, ghi lại cho Bước 08.
- Nút "Thêm vào giỏ"/"Mua ngay" dùng đúng handler hiện có của card (kiểm tra CSRF token vẫn đúng).

## Quy trình
- `git status`; GitNexus impact cho hàm/partial có sẵn bị sửa (nếu có hàm PHP render dùng chung).
- Làm partial + CSS + JS, giữ nguyên phần còn lại của `tatca.php`.

## Kiểm chứng (bắt buộc chạy thật — không claim PASS khi chưa chạy)
Theo `.claude/skills/e2e/SKILL.md`, dùng Playwright tại `http://localhost:8080`:
| Kịch bản | Kỳ vọng |
|---|---|
| `xyzabc` | banner + ≥ 1 section + CTA Syna; không có tên hãng trong banner |
| `torriden sữa rửa mặt` | có kết quả chính xác (từ Bước 01); nếu < 4 thì có "Gợi ý thêm cho bạn" |
| `srm da dầu` | section phù hợp; badge lý do hiển thị |
| Tắt Flask | section "Sản phẩm bán chạy" vẫn hiện, không lỗi JS |
| Click chip | điều hướng đúng URL, ô tìm kiếm không bị xóa ngoài ý muốn |
| Click "Hỏi Syna" | chatbot mở, có câu hỏi điền sẵn |
| Thêm vào giỏ từ card gợi ý | thành công như card thường |
| Viewport 375×812 | `document.documentElement.scrollWidth <= 375`; chips cuộn ngang được |
| Console | không có lỗi JS |
- Chụp ảnh desktop (1440) + mobile (375) cho 3 truy vấn → `report/search-fallback-e2e/`.
- `php -l` các file PHP đổi; lint CSS/JS nếu dự án có (`.claude/skills/lint/SKILL.md`).
- Nếu Playwright không có/không chạy được: ghi `E2E NOT RUN` + lý do, không được nói PASS.

## Đầu ra
Partial, CSS, JS, sửa `tatca.php`, ảnh E2E. Mục "Bước 07" trong handoff: file đã tạo, class chính, data-attribute tracking, endpoint log (nếu có), kết quả bảng kiểm chứng. Không commit nếu chưa được yêu cầu.

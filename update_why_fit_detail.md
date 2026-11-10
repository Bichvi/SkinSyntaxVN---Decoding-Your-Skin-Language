# Chi tiết "Vì sao phù hợp" trên thẻ sản phẩm trang chủ (2026-11-09, chưa commit)

## Mục tiêu
- Người dùng không đọc được câu lý do bị cắt 2 dòng trên thẻ. Thêm popover (hover, bấm/chạm, focus bàn phím) hiện đủ câu và chi tiết so sánh.
- Ở khối "Vì bạn đã xem <SP>", bỏ phần "với <SP>" thừa khi SP gốc của thẻ trùng SP ở tiêu đề khối.

## Luồng dữ liệu
1. `HomeGraphRecommender::build()` gom thêm `seed_id` trong `reason` của khối `seen` vào CÙNG một lần gọi `findVisibleByIds` (vẫn một round trip Mongo). SP gốc không bao giờ vào danh sách hiển thị.
2. `hydrate()` (khối `seen`): `HomeReasonText::fromReason($reason, ['omit_seed_id' => <id SP tiêu đề>, 'category' => <đoạn cuối danh_muc_day_du>])` và `reason_detail = HomeReasonDetail::build($reason, $p, $seed|null)`. SP gốc không còn trong Mongo (hết hàng/ẩn) thì `$seed = null`, chi tiết chỉ còn thành phần chung.
3. `home_product_card.php` đọc `$p['reason_detail']` và dựng popover `.why-fit__pop`. Các nơi khác dùng thẻ (khối trang chủ dùng `HomeCardReason`, lưới da/vấn đề) không có `reason_detail`: popover chỉ hiện nhãn và câu đầy đủ.

## Câu ngắn (`HomeReasonText`)
Tham số thứ hai `$opts` (tùy chọn, không truyền thì hành vi cũ). Khi `reason.seed_id` bằng `omit_seed_id`:
- `seen_shared_ingredient` -> "Cùng chứa Niacinamide, Retinol".
- `seen_same_category` -> "Cùng loại Serum / Tinh Chất" (không có danh mục thì "Cùng loại sản phẩm").
Seed khác thì giữ "với <SP>".
`full_seed => true`: không cắt tên SP gốc (mặc định cắt 30 ký tự); `hydrate()` lưu thêm `reason_text_full` để popover hiện tên đầy đủ, nhờ đó dòng "so với SP bạn đã xem" rõ là SP nào khi thẻ đến từ SP đã xem khác SP ở tiêu đề.

## Dòng giá trị (`HomeReasonDetail::value`, thứ tự ưu tiên, chỉ một dòng)
1. `cheaper`: cả hai giá > 0 và rẻ hơn SP gốc từ 1.000đ -> "Rẻ hơn 120.000đ so với SP bạn đã xem".
2. `similar`: đắt hơn dưới 10% giá SP gốc (hoặc bằng/rẻ hơn dưới 1.000đ) -> "Giá tương đương SP bạn đã xem".
3. `per_unit`: đắt hơn từ 10%; `dung_tich` hai bên khớp đúng `<số><ml|g>`, cùng đơn vị, cùng danh mục (`ma_danh_muc`, thiếu thì so đoạn cuối `danh_muc_day_du`); giá trên mỗi đơn vị rẻ hơn ít nhất 10% -> "50ml, tính ra rẻ hơn 20% mỗi ml" (phần trăm làm tròn xuống). Dung tích lộn xộn ("2x400ml", "310ml+500ml", "3 món") bị bỏ qua.
4. `discount`: `phan_tram_giam` >= 30 -> "Đang giảm 46%".
5. `rating`: điểm >= điểm SP gốc + 0,3, có ít nhất 10 đánh giá, SP gốc có điểm > 0 -> "Đánh giá cao hơn (4,9 so với 4,2)".
6. Không khớp -> `null`, ẩn dòng (không bao giờ nói "mắc hơn").
Ngoài ra: `shared` (thành phần chung từ reason), `same_benefits` (tối đa 3) và `extra_benefits` (tối đa 2) lấy từ `cong_dung_keys` qua `TagCatalog`, key lạ bị bỏ. Không có gì để hiện thì trả `[]`.

## Giao diện
- `.why-fit` có `tabindex=0`, `role=button`, `aria-expanded`; chữ rút gọn đặt `aria-hidden`, bỏ `title`. Có chi tiết thì thêm class `why-fit--has-detail` và mũi tên `bi-chevron-up`.
- `.why-fit__pop` neo đáy, mở lên trên (thẻ có `overflow:hidden`). Mở bằng hover (`@media (hover:hover)`), `.is-open`, hoặc `:focus-visible` (bàn phím; không dùng `:focus-within` vì bấm chuột giữ focus làm popover không đóng). Thiết bị có chuột: rời chuột khỏi hộp thì đóng trạng thái mở do bấm. Hiệu ứng 0,15s chỉ bật khi `prefers-reduced-motion: no-preference`.
- `why-fit.js`: bấm/Enter/Space bật tắt, Esc đóng và giữ focus, bấm ra ngoài đóng hết, chỉ một popover mở một lúc.

## File thay đổi
- Mới: `backend/app/services/HomeReasonDetail.php`, `backend/tests/unit/HomeReasonDetailTest.php`, `frontend/public/assets/js/why-fit.js`, `update_why_fit_detail.md`.
- Sửa: `backend/app/services/HomeReasonText.php`, `backend/app/services/HomeGraphRecommender.php`, `frontend/views/partials/home_product_card.php`, `frontend/views/home.php` (thẻ script), `frontend/public/assets/css/style.css` (khối cuối phần why-fit), `backend/tests/home_reason_text_test.php`, `backend/tests/home_graph_recommender_test.php`, `update.md`.

## Kiểm thử đã chạy (không chạm DB)
- `php backend/tests/unit/HomeReasonDetailTest.php`, `php backend/tests/home_reason_text_test.php`, `php backend/tests/home_graph_recommender_test.php`, `php backend/tests/unit/HomeCardReasonTest.php`, `php -l` các file PHP đổi, `node --check why-fit.js`, `git diff --check`.
- Kiểm tra trình duyệt thủ công (localhost:8080, khổ 1024px): khối "Vì bạn đã xem" hiện câu ngắn cho SP trùng tiêu đề, giữ tên SP gốc cho SP từ seed khác; hover mở popover lên trên, không bị cắt; bấm/bấm ra ngoài/Enter/Esc đúng; không lỗi JS. Chưa chạy bộ Playwright E2E, chưa thử trên điện thoại thật.

## Popup cho khối "Hợp với da…" và "Hợp với vấn đề…"
Các thẻ ở khối `skin` và `concern` cũng có popup "Vì sao phù hợp", dùng lại đúng markup/CSS/JS ở trên (`.why-fit--has-detail`, `.why-fit__detail`, `.why-fit__chip`, `.why-fit__line`), không đổi CSS/JS.

- `HomeFitDetail::build($product, $reason, $section)` (thuần, không DB) trả `fit_detail = {actives, skin_types, benefits, concerns}` hoặc `[]`. `HomeGraphRecommender::hydrate()` gắn vào SP ở khối không phải `seen` (không đặt `reason_detail`).
- Quy tắc dữ liệu (chỉ từ dữ liệu SP thật):
  - `actives`: tối đa 4 hoạt chất có trong bảng `ingredient_glossary.php`, theo thứ tự `thanh_phan_sach`, kèm nồng độ nếu có ("5% B5"), không trùng theo tên ngắn. Khối `concern` + `concern_ingredient`: thành phần của reason lên đầu. Dùng `HomeWhyFitCopy::lookup` (đổi sang public).
  - `skin_types`: tên từ `loai_da_keys` qua `TagCatalog`, tối đa 3; khối `skin` đưa loại da của người dùng lên đầu; bỏ `moi_loai_da` trừ khi là loại duy nhất.
  - `benefits`: từ `cong_dung_keys`, tối đa 3.
  - `concerns`: chỉ vấn đề mà SP có trong `van_de_da_keys` VÀ nằm trong reason (`concerns` của khối `skin`, `concern` của khối `concern`), tối đa 2. Không bao giờ nêu vấn đề SP không có.
- View (`home_product_card.php`): chỉ đọc `fit_detail` khi `reason_text` đúng là lý do đang hiện. Có popup khi có `skin_types`, `benefits` hoặc `concerns`, hoặc hơn 2 `actives` (tránh popup chỉ lặp dòng đang hiện). Popup thêm: "Thành phần nổi bật" (chip), "Hợp loại da:", "Công dụng:", "Hỗ trợ vấn đề:".
- File: mới `backend/app/services/HomeFitDetail.php`, `backend/tests/unit/HomeFitDetailTest.php`; sửa `HomeWhyFitCopy.php` (lookup public), `HomeGraphRecommender.php`, `frontend/views/partials/home_product_card.php`, `backend/tests/home_graph_recommender_test.php`.
- Sửa lỗi kèm theo: Mongo (không đặt `typeMap`) trả `cong_dung_keys`/`van_de_da_keys`/`loai_da_keys` dạng `BSONArray` (đối tượng) nên các hàm kiểm `is_array` coi là rỗng. `HomeGraphRecommender::loadProducts` đổi 3 trường này về mảng PHP. Hệ quả: câu dự phòng "Giúp {công dụng}" và nhãn "Hỗ trợ {vấn đề}" của `HomeWhyFitCopy::forProduct` giờ mới thật sự hoạt động.
- Test: `php backend/tests/unit/HomeFitDetailTest.php`, `php backend/tests/home_graph_recommender_test.php` (thêm ca mảng dạng đối tượng).
- Trình duyệt (localhost:8080, đã đăng nhập, da khô): thẻ khối da/vấn đề mở popup bằng bấm và hover, hiện đủ "Thành phần nổi bật", "Hợp loại da", "Công dụng", "Hỗ trợ vấn đề"; thẻ không có dữ liệu thẻ (vd nước tẩy trang L'Oreal) không có popup. Chưa chạy Playwright E2E.

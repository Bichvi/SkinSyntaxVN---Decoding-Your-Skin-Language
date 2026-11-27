# Thiết kế: tải trang tức thì kiểu Zalo (2026-11-27)

## Mục đích
Người dùng thấy web chậm: mỗi lần bấm hoặc mở lại đều trắng trang rồi chờ. Mục tiêu là trải nghiệm giống Zalo: mở ra là thấy nội dung ngay, phần mới cập nhật sau vài giây.

## Mục tiêu / không làm / thất bại
- Mục tiêu: bấm link xem sản phẩm/danh mục gần như tức thì (bước 1); mở lại web thấy ngay bản cũ rồi tự cập nhật (bước 2).
- Không làm: SPA/viết lại frontend; đổi thuật toán gợi ý; cache giỏ hàng, thanh toán, tài khoản, admin, chat.
- Tính là thất bại dù chạy được: lượt xem / sự kiện `product_view` bị đếm khống hoặc mất; hiện giỏ hàng/đăng nhập của người khác hoặc sai lâu dài; form/CSRF hỏng; giá sai khi thanh toán.

## Ràng buộc
- PHP render toàn trang, ~26 script khởi tạo mỗi trang; Service Worker có sẵn (`sw.js`) chỉ cache tài nguyên tĩnh.
- `chitiet` có tác dụng phụ phía server: tăng `luot_xem`, `ProductEventTracker::record('product_view')`, ghi `$_SESSION['recent_viewed_products']`.
- Session PHP khóa theo request; request tải trước cũng chiếm khóa.
- Không commit trừ khi người dùng yêu cầu.

## Các cách đã cân nhắc
- A. Service Worker lưu HTML + cập nhật ngầm: giống Zalo nhất khi mở lại; rủi ro dữ liệu cũ.
- B. Tải trước khi rê/chạm (Speculation Rules): an toàn, nhỏ; không giúp lần mở đầu tiên.
- C. Kết hợp A + B — **được chọn**: mỗi cách bù chỗ thiếu của cách kia; làm B trước vì nhỏ và ít rủi ro, rồi A.

## Bước 1 — Tải trước (đã chốt)
- Chèn `<script type="speculationrules">` ở layout chung: `prefetch`, eagerness `moderate` (rê chuột ~200 ms hoặc chạm).
- Chỉ áp dụng cho link cùng origin tới các route xem: `home`, `tatca`, `timkiem`, `danhmuc`, `danhsach`, `product_collection`, `flash_sale`, `chitiet`, `goiy`, `yeu_thich`. Không bao giờ tải trước link có tác dụng phụ (giỏ hàng, thêm giỏ, thanh toán, đặt hàng, đăng xuất, admin, xóa, VNPay, chat...).
- Request tải trước (header `Sec-Purpose` chứa `prefetch`) tới `chitiet`: bỏ qua mọi tác dụng phụ. Khi trang thực sự được mở từ bản tải trước (`navigation.deliveryType === 'navigational-prefetch'`), trang gửi một beacon tới route mới để ghi đúng một lần lượt xem, sự kiện và "đã xem gần đây" theo đúng logic cũ.
- Trình duyệt không hỗ trợ Speculation Rules: không có gì thay đổi.

## Bước 2 — Lưu trang, cập nhật ngầm (đã chốt 2026-11-27)
- SW trả ngay HTML đã lưu cho các route xem ở bước 1 (cùng danh sách, cùng loại trừ `q=` trừ `chitiet`); không có bản lưu → đi mạng như cũ và lưu lại. Bản lưu quá 24 giờ thì bỏ.
- Khi trả bản lưu, SW chèn `<meta name="ss-from-cache" content="1">` vào HTML. Trang thấy dấu này thì: (1) với `chitiet`, gửi beacon `product_view_activate` như bước 1; (2) tải bản mới ngầm và cập nhật tại chỗ các vùng động đánh dấu `data-live-region` (khu tài khoản + số giỏ hàng ở header; giá, giảm giá, tồn kho, nút mua ở chi tiết); (3) đưa bản mới vào cache cho lần sau. Không tải lại cả trang, không chạy lại script.
- Request tải mới ngầm gửi header `X-SS-Revalidate: 1`; server xử lý giống request tải trước (nhả khóa session, bỏ tác dụng phụ của `chitiet`) để không đếm trùng.
- Chống lẫn tài khoản: server gửi cookie không-HttpOnly `ss_auth_tag` (hash ngắn của mã người dùng hoặc `guest`) và cùng giá trị trong `<meta name="ss-auth-tag">`. Trang lấy từ cache có tag khác cookie hiện tại → xóa cache HTML và tải lại ngay từ mạng. Đăng nhập/đăng xuất làm tag đổi nên tự xóa.
- Không lưu: trang có thông báo flash một lần, trang lỗi/không phải 200, mọi route ngoài danh sách. Server đánh dấu bằng header `X-SS-No-Store-Page: 1`.
- Trình duyệt không có Service Worker: như hiện tại.

## Để người làm tự quyết
Cú pháp URL pattern cụ thể, tên route beacon, nơi đặt script trong layout, cách kiểm tra trên Chrome.

# Bộ testcase đầy đủ cho use case Quản trị viên

Tài liệu này bao phủ admin dashboard hiện có trong router và các chức năng admin được bổ sung sau tài liệu UC cũ: quản lý tồn kho theo lô, LiveStream, AI Idol Studio/Agent và đồng bộ SePay. Đây là bộ kiểm thử dành cho môi trường local/staging; không chạy trên production và không dùng tài khoản/dữ liệu cá nhân thật.

## 1. Quy ước thực thi

Mỗi testcase phải ghi lại: mã, build/commit, môi trường, vai trò, dữ liệu đầu vào, bước thực hiện, kết quả thực tế, trạng thái `PASS/FAIL/BLOCKED/NOT RUN`, ảnh chụp hoặc response, thời gian và người thực hiện. Với lỗi, ghi thêm URL, request method, payload đã che secret, status code, log/correlation id, collection/document bị ảnh hưởng và bước tái hiện tối thiểu.

Tài khoản dùng trong test nên được tạo riêng:

- `ADMIN_TEST`: role `admin`, tài khoản active, không dùng email production.
- `STAFF_TEST`: role `nhanvien`, dùng để kiểm tra giới hạn quyền.
- `CUSTOMER_TEST`: khách hàng test có ít nhất một đơn hoàn thành.
- `GUEST`: không có session.

Dữ liệu nên gắn tiền tố `AT-<yyyymmdd>-<case>` để cleanup dễ dàng: sản phẩm, danh mục, voucher, phiên live, batch tồn kho, campaign và agent run. Không commit `.env`, token, cookie, response chứa PII hoặc file upload sinh ra trong lúc test.

## 2. Điều kiện và invariant chung

1. MongoDB/Redis và các service cần thiết của luồng đang chạy; nếu service không dùng trong testcase thì phải đánh dấu rõ `NOT RUN`, không đổi thành `PASS` bằng static inspection.
2. Request không hợp lệ không được ghi dữ liệu một phần; refresh trang không tạo bản ghi lặp.
3. Người chưa đăng nhập phải bị chuyển tới trang đăng nhập; staff/customer không được thực hiện chức năng chỉ dành cho admin; response phải là 403 hoặc redirect đúng theo contract hiện tại.
4. POST/PUT/DELETE phải có CSRF protection nếu form/endpoint hiện tại hỗ trợ; không chấp nhận method sai với endpoint AI Idol.
5. Mọi output user-controlled phải được escape; không được thực thi HTML/script từ tên sản phẩm, voucher, review, câu hỏi, ghi chú tồn kho hoặc metadata campaign.
6. Các thao tác trạng thái/tồn kho/thanh toán phải idempotent: retry cùng request không nhân đôi movement, hoàn kho, doanh thu hoặc voucher usage.

## 3. Ma trận smoke bắt buộc

| Mã | Use case/route | Mức | Loại | Kết quả tối thiểu |
|---|---|---:|---|---|
| ADM-SM-001 | Đăng nhập admin `admin_login` | P0 | UI/API | Admin vào được dashboard; guest không vào được |
| ADM-SM-002 | Dashboard `admin_dashboard` | P0 | UI | Số liệu và menu render không fatal |
| ADM-SM-003 | Sản phẩm `admin_sp` | P0 | UI/DB | List, search, filter hoạt động |
| ADM-SM-004 | Đơn hàng `admin_orders` | P0 | UI/DB | List, detail, status hoạt động |
| ADM-SM-005 | Người dùng `admin_users` | P0 | UI/DB | Customer/staff list và permission đúng |
| ADM-SM-006 | Báo cáo `admin_reports` | P0 | UI/DB | Doanh thu chỉ tính đơn hợp lệ |
| ADM-SM-007 | Voucher `admin_vouchers` | P0 | UI/DB | CRUD và áp dụng checkout đúng |
| ADM-SM-008 | Review/Q&A `admin_reviews`, `admin_questions` | P0 | UI/DB | Duyệt/ẩn/trả lời không mất dữ liệu gốc |
| ADM-SM-009 | Tồn kho `admin_inventory_batches` | P0 | UI/DB | Batch, movement, HSD và trạng thái tồn đúng |
| ADM-SM-010 | AI Idol `admin_ai_idol` | P1 | UI/API | Trang và route proxy được bảo vệ |
| ADM-SM-011 | LiveStream `admin_lives` | P1 | UI/DB | CRUD/status/recording đúng quyền |
| ADM-SM-012 | SePay `admin_sepay_manual_sync` | P1 | API | Sync có auth, idempotency và audit |

## 4. Authentication và phân quyền

### ADM-AUTH-001 — Đăng nhập admin hợp lệ (P0)

- Tiền điều kiện: `ADMIN_TEST` active; session/cookie cũ đã xóa.
- Dữ liệu: email và password đúng.
- Bước: mở `index.php?r=admin_login`; nhập form; submit; mở `admin_dashboard`.
- Mong đợi: login thành công; session lưu user/role đúng; redirect dashboard; không lộ password/token trong HTML, URL hoặc log.
- Đối chiếu: `$_SESSION`/audit an toàn; refresh không yêu cầu đăng nhập lại.

### ADM-AUTH-002 — Sai password, user không tồn tại, field rỗng (P0)

- Lặp với password sai, email không tồn tại, email rỗng, password rỗng, email có khoảng trắng/hoa thường.
- Mong đợi: thông báo chung, không phân biệt user tồn tại hay không; không tạo session admin; không redirect vào vùng quản trị.
- Ghi nhận: status, thông báo, số lần thử/rate limit nếu có.

### ADM-AUTH-003 — Guest truy cập từng route admin (P0)

- Dùng browser context mới, gọi các route GET trong mục 5–11.
- Mong đợi: redirect tới đăng nhập hoặc 401/403 theo contract; không render dữ liệu admin; không query/hiển thị PII.

### ADM-AUTH-004 — Staff truy cập route admin-only (P0)

- Đăng nhập `STAFF_TEST`, thử dashboard, product CRUD admin, voucher, category, user/staff, reports, inventory, AI Idol.
- Mong đợi: 403/deny; không có side effect. Các route được thiết kế dùng chung staff phải được phép đúng phạm vi, không cấp thêm quyền admin.

### ADM-AUTH-005 — Customer và user bị khóa (P0)

- Dùng session customer; sau đó khóa/deactivate một staff test rồi dùng session cũ gọi route staff/admin.
- Mong đợi: customer bị từ chối; staff bị khóa bị logout/redirect; không thể giữ session để tiếp tục thao tác.

### ADM-AUTH-006 — Logout, session fixation và back button (P1)

- Login admin; ghi session id; logout; dùng URL cũ/back button và gọi POST mutation bằng cookie cũ.
- Mong đợi: session bị vô hiệu hóa; không truy cập lại được; session id được rotate sau login nếu framework hỗ trợ.

### ADM-AUTH-007 — CSRF/method tampering (P0)

- Gửi POST mutation không token, token sai, token của user khác; gửi GET tới mutation; thay route/id bằng giá trị khác.
- Mong đợi: request bị từ chối hoặc không có side effect; không dùng GET để xóa/cập nhật dữ liệu.

## 5. Dashboard và quản lý sản phẩm

### ADM-PROD-001 — Dashboard summary và menu (P0)

- Mở `admin_dashboard` với dữ liệu sản phẩm, customer, order, low-stock.
- Mong đợi: KPI render đúng; menu link không 404; notification badge không âm; dữ liệu rỗng vẫn render trạng thái empty.

### ADM-PROD-002 — Danh sách, phân trang, tìm kiếm (P0)

- Mở `admin_sp`; tìm theo mã, tên, thương hiệu; page `1`, page lớn, page âm/không phải số; filter visibility/stock.
- Mong đợi: query không lỗi; page được clamp hợp lý; tổng số và item nhất quán; không hiển thị sản phẩm ngoài filter.

### ADM-PROD-003 — Tạo sản phẩm hợp lệ (P0)

- Dữ liệu: mã/tên unique, brand/category hợp lệ, giá bán > 0, giá thị trường hợp lệ, mô tả, thành phần, HDSD, ảnh PNG/WEBP hợp lệ.
- Bước: mở form `admin_sp_them`, submit, reload list, mở detail public.
- Mong đợi: một document được tạo; ảnh được lưu với tên server-generated; giá/brand/category hiển thị đúng; không duplicate khi refresh.

### ADM-PROD-004 — Tạo sản phẩm thiếu/sai dữ liệu (P0)

- Bỏ mã/tên; giá rỗng, `0`, âm, chữ; giá thị trường `0`/âm/chữ; category/brand không tồn tại; file `.php/.exe`, file quá lớn.
- Mong đợi: lỗi tại form; giữ lại input an toàn; không insert document; file không hợp lệ không được lưu/executable.

### ADM-PROD-005 — Trùng mã và trùng tên (P0)

- Dùng mã đã tồn tại; dùng tên đã tồn tại với khác hoa thường/khoảng trắng.
- Mong đợi: hệ thống xử lý theo contract (từ chối hoặc sinh mã mới nếu đã thiết kế); không có hai sản phẩm cùng identity; dữ liệu cũ không bị ghi đè.

### ADM-PROD-006 — Sửa sản phẩm (P0)

- Mở `admin_sp_sua&id=<id>`; đổi tên, giá, mô tả, ảnh; submit rồi reload.
- Mong đợi: chỉ document đúng id đổi; ảnh cũ được giữ nếu không upload ảnh mới; giá không âm; public detail/list đồng bộ.

### ADM-PROD-007 — Xóa sản phẩm và xóa id không tồn tại (P0)

- Xóa sản phẩm test không có order; thử id rỗng, id không tồn tại, sản phẩm đã có order.
- Mong đợi: confirmation/permission đúng; xóa mềm/cứng theo business rule; không phá order history; retry không tạo lỗi fatal.

### ADM-PROD-008 — Ẩn/hiện sản phẩm (P0)

- POST `admin_sp_visibility` chuyển active → hidden → active; kiểm tra public list, search, detail và cart.
- Mong đợi: hidden không xuất hiện ở nơi phải ẩn, dữ liệu admin vẫn xem được; active hiển thị lại; thao tác idempotent.

### ADM-PROD-009 — Cập nhật tồn kho nhanh (P0)

- POST `admin_sp_stock` với `0`, số dương, âm, chữ, id rỗng.
- Mong đợi: số âm/chữ được từ chối hoặc normalize về rule đã công bố; stock `0` chuyển hết hàng; không cho add-to-cart; không âm tồn kho.

### ADM-PROD-010 — XSS và dữ liệu Unicode sản phẩm (P1)

- Tên/mô tả chứa HTML, script, emoji, tiếng Việt có dấu, chuỗi dài và ký tự quote.
- Mong đợi: render đúng Unicode nhưng escape HTML; không chạy script; giới hạn độ dài không làm vỡ layout/DB.

## 6. Tồn kho theo lô và hạn sử dụng

### ADM-INV-001 — Mở danh sách batch và filter (P0)

- Mở `admin_inventory_batches`; lọc product, warehouse, status, expiry range, expired toggle.
- Mong đợi: trang hoạt động; batch/số lượng/đã dùng/còn lại/HSD/trạng thái nhất quán; filter không leak batch khác kho.

### ADM-INV-002 — Tạo batch hợp lệ (P0)

- POST `admin_inventory_batch_save` với product hợp lệ, warehouse, lot code unique, manufacture/expiry date hợp lệ, quantity dương.
- Mong đợi: tạo đúng một batch; ghi movement nhập kho; tổng tồn sản phẩm tăng đúng một lần; actor/audit lưu đúng.

### ADM-INV-003 — Batch invalid và không cho tồn âm (P0)

- Lot code rỗng/trùng; product không tồn tại; quantity `0`, âm, chữ; expiry trước manufacture; expiry đã quá hạn nếu rule cấm.
- Mong đợi: validation error; không tạo batch/movement; không làm thay đổi tổng tồn.

### ADM-INV-004 — Movement nhập/xuất/điều chỉnh (P0)

- POST `admin_inventory_movement` cho từng type hợp lệ; thử quantity lớn hơn available, type lạ, batch id lạ.
- Mong đợi: ledger tăng đúng; không xuất vượt tồn; type lạ bị từ chối; movement có note/actor/time; retry cùng request không nhân đôi nếu có idempotency.

### ADM-INV-005 — Auto seed và seed đa dạng (P1)

- Chạy `admin_inventory_auto_seed`, `admin_inventory_diverse_seed` hai lần với cùng warehouse/limit.
- Mong đợi: lần đầu tạo đúng phạm vi; lần hai skip bản ghi đã có; không nhân đôi; message nêu created/skipped/errors chính xác.

### ADM-INV-006 — Fixed expiry và FEFO (P0)

- Chạy `admin_inventory_fixed_expiry_seed`; tạo nhiều batch cùng product với HSD khác nhau; bán/xuất một phần.
- Mong đợi: hệ thống chọn batch sắp hết hạn trước nếu FEFO được yêu cầu; batch expired bị loại khỏi available; số dư từng batch và product total khớp.

### ADM-INV-007 — Phân quyền inventory (P0)

- Guest, staff và customer gọi cả GET lẫn POST các route inventory.
- Mong đợi: chỉ admin được phép; không tạo batch/movement dù request có payload hợp lệ.

## 7. Danh mục, thương hiệu, người dùng và nhân viên

### ADM-CAT-001 — List/search danh mục (P1)

- Mở `admin_categories`; tìm keyword có dấu, rỗng, ký tự regex.
- Mong đợi: kết quả chính xác; keyword được xử lý như text; empty state không fatal.

### ADM-CAT-002 — CRUD danh mục (P0)

- Tạo, sửa, xóa danh mục test; thử tên rỗng, trùng, quá dài và danh mục đang có sản phẩm.
- Mong đợi: validation; không xóa nhầm sản phẩm; nếu chọn delete products thì phải có confirmation và audit; option sản phẩm cập nhật sau reload.

### ADM-CAT-003 — Brand qua form sản phẩm (P1)

- Tạo/sửa brand bằng `ten_thuong_hieu_input`; dùng tên trùng/Unicode.
- Mong đợi: không duplicate brand; product reference đúng; filter brand hoạt động; không tạo brand rác khi submit form lỗi.

### ADM-USER-001 — Danh sách customer/staff và filter (P0)

- Mở `admin_users`; tìm theo email/tên; filter loại khách hàng; mở edit customer và staff.
- Mong đợi: phân tách hai nhóm đúng; không lộ password/hash/token; pagination/search ổn định.

### ADM-USER-002 — Tạo/sửa customer (P0)

- Tạo customer test; thử email sai, trùng, tên rỗng, phone sai, password yếu nếu form có.
- Mong đợi: tạo một account hợp lệ; duplicate email bị chặn; thông tin cập nhật đúng; mật khẩu không hiển thị ngược.

### ADM-USER-003 — Tạo/sửa staff và role (P0)

- Tạo staff role hợp lệ; đổi role; thử role không tồn tại và email trùng.
- Mong đợi: chỉ role hợp lệ được lưu; staff đăng nhập đúng quyền; đổi role không làm mất audit/history.

### ADM-USER-004 — Disable/delete customer (P1)

- Disable/delete customer có/không có order; thử id rỗng/không tồn tại; retry.
- Mong đợi: không phá order history; customer không đăng nhập nếu disabled; UI message phản ánh kết quả thật.

### ADM-USER-005 — Disable và hard delete staff (P0)

- Disable staff rồi gọi route bằng session cũ; chỉ sau đó mới thử hard delete staff test không còn dependency.
- Mong đợi: disabled bị chặn ngay; hard delete cần quyền admin/confirmation; không xóa nhầm admin cuối cùng; audit giữ được actor/action.

## 8. Đơn hàng, trạng thái, in và báo cáo

### ADM-ORDER-001 — List/search/filter/detail đơn (P0)

- Mở `admin_orders`; tìm order id/customer/status; mở detail và print.
- Mong đợi: item, giá, qty, customer, payment, shipping hiển thị đúng; order không tồn tại có message; không lộ dữ liệu của order khác.

### ADM-ORDER-002 — Chuyển trạng thái hợp lệ (P0)

- Chuyển theo state machine: pending → confirmed → shipping → completed; kiểm tra timestamp, payment và notification.
- Mong đợi: transition hợp lệ lưu canonical/label đúng; completion ghi ngày hoàn thành và chỉ tính doanh thu khi rule cho phép.

### ADM-ORDER-003 — Transition bất hợp lệ và retry (P0)

- Thử completed → pending, cancelled → shipping, status rỗng/lạ, order id rỗng; gửi lại cùng POST.
- Mong đợi: bị chặn; không đổi dữ liệu; retry không nhân notification/loyalty/revenue.

### ADM-ORDER-004 — Hủy đơn và hoàn kho (P0)

- Hủy đơn chưa giao với từng lý do; chọn `Khac` không nhập ghi chú; hủy đơn đã hủy lần hai.
- Mong đợi: bắt buộc lý do; hoàn kho đúng một lần; status/payment/notification đúng; không hoàn kho nếu business rule không cho.

### ADM-ORDER-005 — In đơn và Unicode (P1)

- Mở `admin_order_print` với order có tiếng Việt, nhiều item, ảnh thiếu.
- Mong đợi: trang/PDF/print layout không vỡ; không có HTML injection; order id không tồn tại xử lý an toàn.

### ADM-REPORT-001 — Doanh thu chỉ từ đơn hợp lệ (P0)

- Chuẩn bị một order completed/paid, một pending, một cancelled, một unpaid transfer.
- Mong đợi: tổng doanh thu/top product chỉ tính order đạt điều kiện; đối chiếu aggregation thủ công.

### ADM-REPORT-002 — Filter ngày (P0)

- Gọi `start_date=end_date`, khoảng hợp lệ, start > end, ngày sai format, ngày timezone boundary.
- Mong đợi: khoảng hợp lệ lọc đúng; khoảng ngược/ngày sai có lỗi; không query sai hoặc trả số liệu toàn kỳ khi filter invalid.

### ADM-REPORT-003 — Export report (P1)

- Gọi export với filter hợp lệ/không hợp lệ; mở file bằng parser CSV/XLSX tương ứng.
- Mong đợi: content-type/download filename đúng; header/cột tổng nhất quán; export không tính đơn ngoài filter và không lộ secret.

## 9. Voucher

### ADM-VOUCHER-001 — CRUD voucher hợp lệ (P0)

- Tạo voucher phần trăm, voucher số tiền, min subtotal, start/end, usage limit; sửa rồi xóa voucher test.
- Mong đợi: normalize code đúng; một code unique; rule/expiry/limit lưu đúng; delete không xóa nhầm code khác.

### ADM-VOUCHER-002 — Validation voucher (P0)

- Code rỗng/trùng/ký tự lạ; percent ngoài 0–100; fixed âm; min order âm; end trước start; limit âm/0 theo rule.
- Mong đợi: lỗi rõ tại field; không lưu partial/invalid voucher.

### ADM-VOUCHER-003 — Áp dụng ở checkout (P0)

- Với cart dưới/trên minimum, trước/trong/sau thời gian, usage còn/hết, code đúng/sai/hoa thường.
- Mong đợi: discount đúng; subtotal/total không âm; voucher không hợp lệ không consume; consume đúng một lần khi order thành công.

### ADM-VOUCHER-004 — Concurrent consume (P1)

- Gửi hai checkout đồng thời khi usage còn 1.
- Mong đợi: chỉ một request consume thành công; usage không âm/vượt limit; order thất bại không làm mất usage.

## 10. Review, hỏi đáp, chat và notification

### ADM-CONTENT-001 — List/filter review (P0)

- Mở `admin_reviews`; lọc sao, reply status, order status, date, customer, tracking; mở detail.
- Mong đợi: filter kết hợp đúng; review gốc, ảnh, order context hiển thị; không lộ PII ngoài quyền.

### ADM-CONTENT-002 — Approve/hide/delete review (P0)

- Thử các action với review pending/visible/hidden và id không tồn tại.
- Mong đợi: status/rating aggregation/public visibility đúng; delete không làm mất order; retry idempotent.

### ADM-CONTENT-003 — Reply review và giới hạn 1000 từ (P0)

- Trả lời hợp lệ, rỗng, trên 1000 từ, chứa HTML/Unicode.
- Mong đợi: reply hợp lệ hiển thị public; rỗng/over-limit bị chặn; output escape; review gốc không bị overwrite.

### ADM-CONTENT-004 — List/reply/hide question (P0)

- Mở `admin_questions`; filter status/search; trả lời; ẩn; thử id/answer không hợp lệ.
- Mong đợi: answer lưu actor/time; public Q&A phản ánh đúng; hidden không xuất hiện nơi public; lỗi DB có message an toàn.

### ADM-CONTENT-005 — Notification center (P0)

- Tạo order/review/question/chat mới; mở header; click từng link; mark seen; reload.
- Mong đợi: badge/count/marker đúng; item mới nhất trước; mark seen không xóa dữ liệu; link đúng route; session seen không áp dụng sang account khác.

### ADM-CONTENT-006 — Staff chat reply (P1)

- Admin/staff mở `staff_chats`; gửi text thường, rỗng, dài, HTML; thử AJAX và form submit.
- Mong đợi: JSON status/shape đúng khi AJAX; message render escape; conversation/notification update đúng; customer nhận đúng reply.

## 11. LiveStream

### ADM-LIVE-001 — List và quyền truy cập LiveStream (P1)

- Mở `admin_lives` với admin, staff, guest; list empty và có session.
- Mong đợi: role được phép đúng policy; list có status/schedule/recording; guest không thấy dữ liệu.

### ADM-LIVE-002 — Tạo phiên live (P1)

- `admin_live_create` với title, schedule, description, cover, products hợp lệ; thử thiếu title, ngày sai, lịch quá khứ.
- Mong đợi: validation; tạo một phiên; schedule/timezone hiển thị đúng; không duplicate khi retry.

### ADM-LIVE-003 — Sửa và đổi status (P1)

- Sửa title/product/schedule; chuyển draft → scheduled → live → ended; thử transition sai.
- Mong đợi: state machine đúng; người xem/public UI phản ánh status; không mất pin/deal.

### ADM-LIVE-004 — Pin product và deal (P1)

- Thử pin/unpin product, thêm/sửa/xóa deal, product không tồn tại, giá âm, thời gian sai.
- Mong đợi: chỉ live/session đúng bị ảnh hưởng; deal không vượt rule; retry không tạo duplicate; public live sync đúng.

### ADM-LIVE-005 — Xóa và recording (P1)

- Xóa session chưa có order/đã có tương tác; cập nhật recording URL hợp lệ/invalid.
- Mong đợi: confirmation/soft-delete theo rule; history không hỏng; URL được validate/escape; không fetch URL nội bộ tùy ý nếu có SSRF risk.

## 12. AI Idol Studio và Agent

### ADM-AI-001 — Mở Studio và danh sách campaign (P1)

- Mở `admin_ai_idol`, `admin_ai_idol_campaigns`, `admin_ai_idol_campaign`; test guest/staff/admin và campaign id rỗng/không tồn tại.
- Mong đợi: chỉ admin; JSON content-type/status đúng; empty/error state không fatal; không lộ internal Flask URL/token.

### ADM-AI-002 — Tạo campaign/job (P1)

- POST hợp lệ với product id, brief, style, voice; thử thiếu field, product id lạ, payload quá lớn.
- Mong đợi: validate ở PHP boundary; upstream error map an toàn; job/campaign id trả đúng; retry không tạo campaign trùng.

### ADM-AI-003 — Upload asset (P1)

- Upload ảnh/video đúng MIME/size; file đổi đuôi, file rỗng, tên Unicode, payload chứa script.
- Mong đợi: server kiểm tra MIME/size, lưu tên server-generated, không thực thi file; cleanup khi upstream fail.

### ADM-AI-004 — Poll/retry job và campaign (P1)

- Poll job pending/running/succeeded/failed; retry failed và retry đang chạy/đã thành công.
- Mong đợi: state transition rõ; retry chỉ hợp lệ theo policy; timeout/upstream 5xx trả response chuẩn, không PHP fatal.

### ADM-AI-005 — Approve script (P1)

- POST JSON hợp lệ/không hợp lệ/thiếu `job_id`; script có HTML, vượt giới hạn, Unicode.
- Mong đợi: JSON parse/validation; approve chỉ job đúng; upstream không nhận script chưa qua policy; response status/shape ổn định.

### ADM-AI-006 — Agent run lifecycle (P1)

- `agent_create` → list → get → confirm; test retry/cancel tại từng state và id lạ.
- Mong đợi: lifecycle/idempotency đúng; chỉ admin; cancel không để worker tiếp tục publish; confirm không auto-publish nếu feature flag tắt.

### ADM-AI-007 — Video stream và Range request (P1)

- Gọi stream campaign/video với/không `Range`, campaign id lạ và guest.
- Mong đợi: pass-through status/content-range đúng; session không bị khóa lâu; lỗi upstream thành 502 an toàn; không proxy URL tùy ý từ user.

## 13. SePay và đồng bộ thanh toán

### ADM-PAY-001 — Webhook signature/idempotency (P0)

- Gửi webhook thiếu signature, signature sai, payload thiếu order, payload đúng một lần và gửi lặp.
- Mong đợi: unauthorized bị từ chối; payload đúng update payment/order đúng; duplicate không cộng tiền/đổi trạng thái lần hai; không log secret.

### ADM-PAY-002 — Amount/order mismatch (P0)

- Webhook đúng order nhưng sai amount, sai transfer content, order cancelled/completed.
- Mong đợi: không mark paid; response nêu lỗi an toàn; audit lưu mismatch nếu có; không phát hàng khi chưa thanh toán hợp lệ.

### ADM-PAY-003 — Manual sync (P1)

- Admin gọi `admin_sepay_manual_sync` với khoảng thời gian/limit hợp lệ, rỗng, quá lớn; staff/guest gọi cùng route.
- Mong đợi: admin-only; limit được clamp; sync kết quả created/updated/skipped/error rõ; rerun không duplicate.

### ADM-PAY-004 — Timeout/SePay unavailable (P1)

- Tắt/mock upstream timeout và 5xx.
- Mong đợi: request kết thúc trong timeout; không làm web trắng/fatal; order giữ trạng thái an toàn; retry sau khi service hồi phục.

## 14. Regression, contract và chất lượng kỹ thuật

### ADM-REG-001 — Route parity frontend/backend (P0)

- Chạy `php backend/tests/admin_usecase_contract_test.php`.
- Mong đợi: mọi route admin ở backend public cũng tồn tại ở frontend public và map cùng handler; mọi AI route có mặt ở cả hai entrypoint.
- Nếu fail: ghi route thiếu, controller/method thực tế và route URL tái hiện được.

### ADM-REG-002 — Handler existence (P0)

- Dùng test contract kiểm tra mọi `new Controller()->method()` của admin.
- Mong đợi: method public tồn tại; không có route gọi method đã xóa/đổi tên; không chấp nhận “PASS” chỉ vì route string tồn tại.

### ADM-REG-003 — Authorization guard (P0)

- Test contract kiểm tra `requireRole`, `checkAdmin`, `requireAdmin`, sau đó xác nhận lại bằng browser với guest/staff.
- Mong đợi: static guard và runtime behavior cùng pass.

### ADM-REG-004 — PHP syntax và whitespace (P0)

- Chạy `php -l` trên controller/router/test mới và `git diff --check`.
- Mong đợi: không syntax error, không whitespace error.

### ADM-REG-005 — Browser console/network (P1)

- Với mỗi smoke route, mở Chromium/Playwright; theo dõi console error, failed request, 4xx/5xx và redirect loop.
- Mong đợi: không lỗi JS blocking; response shape đúng; asset/CSS tải được.

### ADM-REG-006 — Concurrency/idempotency (P1)

- Chạy đồng thời update status, stock movement, voucher consume, webhook và job retry bằng dữ liệu test riêng.
- Mong đợi: không lost update/duplicate side effect; kết quả cuối có thể đối chiếu bằng ledger/audit.

### ADM-REG-007 — Backup/cleanup verification (P1)

- Sau suite, đối chiếu số document/movement/upload trước-sau; chạy cleanup theo tiền tố testcase.
- Mong đợi: không còn dữ liệu test ngoài danh sách được giữ để điều tra; không xóa dữ liệu không thuộc test prefix.

## 15. Tiêu chí kết luận lỗi

Một lỗi được coi là tái hiện được khi có testcase ID, precondition, input tối thiểu, URL/route, bước tái hiện, actual/expected, status/response hoặc screenshot và dữ liệu DB trước/sau. Ưu tiên sửa theo P0 → P1; sau mỗi bản sửa phải chạy lại testcase gốc, regression route/guard và smoke flow liên quan. Không đánh dấu `PASS` cho các case phụ thuộc service/database chưa chạy; dùng `BLOCKED` hoặc `NOT RUN` và ghi blocker cụ thể.

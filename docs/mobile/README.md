# SkinSyntaxVN Mobile — trạng thái triển khai

Ngày cập nhật: 26-11-2026

Ứng dụng nằm trong `mobile/` và dùng Expo SDK 54. Production API được cấu hình tập trung ở `https://skinsyntaxvn.app`. Lớp API mới dùng route PHP `index.php?r=mobile_api&action=...`; Expo Web đi qua route cùng origin `/api/mobile` và cookie `HttpOnly`, còn iOS/Android lưu opaque bearer token trong `expo-secure-store`.

## Feature mapping Website – Mobile

| Nghiệp vụ website | Màn hình mobile | Tích hợp hiện tại | Trạng thái | Kiểm chứng / phần còn thiếu |
|---|---|---|---|---|
| Catalog, smart search, từ khóa thịnh hành | Trang chủ, Khám phá | `api_smart_search`; tìm tên/brand, nhóm danh mục, lọc brand trong kết quả, lưới hai cột | PARTIAL | Expo Web proxy trả 5 sản phẩm thật; thiếu phân trang/sắp xếp/facet từ API này. |
| Ảnh sản phẩm | Thẻ sản phẩm và chi tiết nhanh | Đọc URL ảnh, URL phân cách bằng `|` và tối đa 6 ảnh dự phòng; tự thử URL kế khi ảnh lỗi | PARTIAL | Đã xác nhận 5/5 ảnh catalog hiển thị; chưa thử fallback trên thiết bị thật hoặc với ảnh primary bị lỗi. |
| Chi tiết, thành phần, thêm giỏ | Modal chi tiết native | Source có `catalog.product`; `cart.add` kiểm tra tình trạng, tồn kho, giá hiệu lực và idempotency | SOURCE IMPLEMENTED; PRODUCTION BLOCKED | Probe read-only tới `https://skinsyntaxvn.app/index.php?r=mobile_api&action=catalog.product&id=5689` nhận 404 HTML; API mới chưa được deploy. Review và detail API riêng cũng chưa có. |
| Routine và hồ sơ da | Tab Routine, màn Hồ sơ da & khảo sát | Source có `account.skin-profile` GET/PATCH/POST; routine đưa profile vào prompt SYNA | SOURCE PARTIAL; PRODUCTION BLOCKED | API mới chưa có trên Production. Gợi ý routine chưa có bản ghi lâu dài; website giữ consultation tạm trong PHP session. Không tuyên bố routine sync giữa thiết bị. |
| Chatbot SYNA | Màn hội thoại native `/assistant` | `ai_chat_api`, câu hỏi gợi ý, trạng thái đang trả lời, hội thoại mới, sản phẩm thật và cảnh báo conflict nếu API trả | PARTIAL | Client và Expo Web same-origin proxy đã tích hợp. Chưa gửi POST đến production để tránh tạo hội thoại thật; đã kiểm tra validation local. Lịch sử trong app chỉ giữ trong bộ nhớ phiên màn hình. |
| Đăng nhập, hồ sơ cá nhân, địa chỉ, đơn hàng | Màn đăng nhập, hồ sơ, địa chỉ, đơn hàng | Source có token opaque 30 ngày, hash trên server; GET/PATCH profile, CRUD địa chỉ và GET lịch sử theo `ma_kh` | SOURCE IMPLEMENTED; PRODUCTION BLOCKED | Production chưa nhận route `mobile_api`. Đăng ký native chưa nối vì `NguoiDung::taoMoi()` có thể xóa tài khoản/đơn cũ cùng email; app không gọi hàm này. |
| Yêu thích | Modal sản phẩm, màn Yêu thích | Source `favorites.list/set` dùng `product_favorites` và khóa website `email:<lowercase>` | SOURCE IMPLEMENTED; E2E BLOCKED | Cần deploy backend và tài khoản sandbox để xác minh hai nền tảng. |
| Giỏ hàng | Tab Giỏ hàng và website | Source `customer_carts` theo `ma_kh`; PHP bridge đồng bộ session, import `gio_hang` cũ không xóa; stock check và idempotency | SOURCE IMPLEMENTED; E2E BLOCKED | Cần deploy cả API/controller và PHP bridge; cần Mongo local/sandbox để test hai chiều. Cart cập nhật đồng thời dùng delta ở bridge; thao tác xóa có chủ đích áp dụng trực tiếp. |
| Voucher và loyalty | Màn Ví voucher, Điểm thành viên | Source đọc voucher theo `customer_id`, điểm/hạng từ hồ sơ khách hàng | SOURCE READ IMPLEMENTED; PRODUCTION BLOCKED | API mới chưa được deploy; chưa nối voucher áp dụng ở checkout. Không khởi tạo `CustomerVoucher` do constructor có thao tác migration/drop/updateMany. |
| Checkout, thanh toán | Chưa có màn native | — | BLOCKED | Website dùng session checkout, nhiều phương thức thanh toán, kiểm tra voucher/điểm, trừ tồn kho, ghi đơn và callback cổng thanh toán. Cần tách core nghiệp vụ + idempotency/ID allocation trước khi mở API đặt đơn native. Không tạo đơn hoặc thanh toán Production. |
| Livestream | Chưa có màn hình riêng | Route website chưa được nối từ mobile | MISSING | Chưa kiểm tra API stream/tương tác trong app. |
| Push notifications, lịch sử tìm kiếm native, thông báo đơn | Chưa có | — | MISSING | Cần API và quyền/native setup phù hợp. |
| API config Production/Development | Startup scripts, centralized URL builder | Production mặc định `https://skinsyntaxvn.app`; Web dùng `/api/mobile`; native gọi PHP route trực tiếp | SOURCE IMPLEMENTED; PROD ROUTE MISSING | GET Production trả HTML 404 cho `r=mobile_api`; route source chưa deploy. Unit test config không chứng minh server đã deploy. |

## Hợp đồng Native API trong source

Route PHP là `index.php?r=mobile_api&action=<action>`. Request/response JSON dùng envelope `ok`; native gửi bearer token opaque, Expo Web chuyển qua same-origin `/api/mobile` và HttpOnly cookie. `catalog.product` và `auth.login` là public; các thao tác còn lại cần customer token. Các hợp đồng dưới đây hiện có trong source; chúng chưa khả dụng trên Production cho tới khi backend được triển khai.

| Action | Method | Dữ liệu chính |
|---|---|---|
| `auth.login`, `auth.me`, `auth.logout` | POST, GET, POST | Xác thực account khách hàng, khôi phục danh tính, thu hồi phiên |
| `catalog.product` | GET | `id`; trả dữ liệu sản phẩm |
| `favorites.list`, `favorites.set` | GET, PUT | Danh sách hoặc `product_id` + `favorite` |
| `cart.get`, `cart.add`, `cart.set`, `cart.remove`, `cart.clear` | GET, POST, PUT, DELETE, DELETE | Cart theo `ma_kh`; `cart.add` cần `Idempotency-Key` |
| `account.profile` | GET, PATCH | Đọc hoặc cập nhật các trường profile cho phép |
| `account.addresses` | GET, POST, PATCH, DELETE | CRUD địa chỉ được scope theo customer token |
| `account.skin-profile` | GET, PATCH, POST | Hồ sơ da, kết quả quick survey và lịch sử hiện có |
| `account.orders`, `account.loyalty`, `account.vouchers` | GET | Đơn hàng, điểm/hạng và voucher đã cấp |

Production deployment status: **NOT DEPLOYED**. Local source changes do not change `skinsyntaxvn.app`. Không có lệnh deploy hoặc ghi dữ liệu Production trong lượt này. Cần triển khai PHP controller/services/router và Expo web proxy theo quy trình release riêng, sau đó mới chạy E2E với tài khoản sandbox.

## UI và kiến trúc

- Màu và khoảng cách dùng chung: `mobile/src/theme/tokens.ts`; mặc định theo hệ màu thương hiệu SkinSyntaxVN, dark mode được giữ đồng nhất.
- `CatalogScreen` dùng danh sách hai cột, tìm kiếm có debounce/hủy request, lọc brand, pull-to-refresh, trạng thái loading/error/empty và modal sản phẩm.
- `ProductImage` thử lần lượt các nguồn ảnh mà API đưa về; không thay ảnh hỏng bằng ảnh sản phẩm khác.
- `askSyna` giới hạn độ dài request/lịch sử và timeout; Expo Web chuyển qua `/api/chat`.
- Mobile Web lưu phiên bằng cookie `HttpOnly; SameSite=Strict`; iOS/Android lưu bearer token trong SecureStore. Password không được lưu. API chỉ phát token cho khách hàng; admin/staff bị từ chối.
- Giỏ account là nguồn dữ liệu backend; website lấy snapshot khi vào các route giỏ/checkout và ghi lại delta session khi kết thúc request. Legacy `gio_hang` được đọc để import một lần, không xóa.
- Migration index additive có tại `backend/scripts/mobile_api_indexes.php`, chỉ cho phép `DB_MODE=local`. Chưa chạy migration do Mongo local `127.0.0.1:27018` không hoạt động.
- Các thay đổi hiện tại gồm backend PHP và native UI; không chỉnh CSS website responsive.

## Tệp chính của lớp tích hợp native

- `mobile/src/theme/tokens.ts`
- `mobile/src/config/api.ts`
- `mobile/src/services/api.ts`
- `mobile/src/services/mobileApi.ts`
- `mobile/src/auth/MobileAuthProvider.tsx`
- `mobile/src/components/CatalogScreen.tsx`
- `mobile/src/components/ProductCard.tsx`
- `mobile/src/components/ProductImage.tsx`
- `mobile/src/components/WebsiteDestination.tsx`
- `mobile/app/api/chat+api.ts`
- `mobile/app/assistant.tsx`
- `mobile/app/(tabs)/routine.tsx`
- `mobile/app/(tabs)/cart.tsx`
- `mobile/app/(tabs)/account.tsx`
- `mobile/app/(tabs)/_layout.tsx`
- `mobile/app/profile.tsx`
- `mobile/app/addresses.tsx`
- `mobile/app/skin-profile.tsx`
- `mobile/app/orders.tsx`
- `mobile/app/favorites.tsx`
- `mobile/app/vouchers.tsx`
- `mobile/app/loyalty.tsx`
- `backend/app/controllers/MobileApiController.php`
- `backend/app/services/MobileSessionStore.php`
- `backend/app/services/CustomerCartStore.php`
- `backend/app/services/AccountCartBridge.php`
- `backend/scripts/mobile_api_indexes.php`
- `mobile/app/_layout.tsx`

Các thay đổi sẵn có trước lượt này như cấu hình Expo SDK 54, catalog route `/api/catalog`, startup scripts và app icon được kế thừa nguyên trạng.

## Kết quả kiểm tra lượt này

| Kiểm tra | Kết quả | Bằng chứng / giới hạn |
|---|---|---|
| `npm run typecheck` | PASS | `tsc --noEmit`, exit 0 |
| `npm run lint` | PASS | ESLint trên app/components/constants/src/scripts; cảnh báo đã dọn ở lượt cuối |
| `npm test` | PASS | 3 kiểm thử cấu hình API, 3/3 thành công |
| Expo Doctor | PASS | 18/18 checks passed |
| PHP lint | PASS | Controller, cart/session services, router và migration script không có lỗi cú pháp |
| `MobileApiStoresTest.php` | PASS | 9 assertions: token hash/resolve/revoke và retry add-to-cart không nhân đôi số lượng |
| Expo Web UI, viewport 320 × 844 và 390 × 844 | PARTIAL PASS | Trang chủ/catalog có 5 sản phẩm; sign-in và account không tràn ngang ở 320 px. Chi tiết trả thông báo API chưa triển khai; không đăng nhập hoặc gửi mutation. |
| Production mobile API route | BLOCKED | GET read-only tới API nhận 404 HTML; app local không thể kiểm chứng API mới tới khi backend được triển khai. |
| API sync E2E giữa Website và Mobile | BLOCKED | Mongo local `127.0.0.1:27018` không chạy; chưa có thông tin tài khoản sandbox. Không gửi POST làm thay đổi dữ liệu Production. |
| Website regression | NOT RUN | Chỉ sửa route PHP/API; CSS responsive website không đổi. Cần test request/session/cart trên môi trường local để chứng minh regression. |
| iOS/Android thật | NOT VERIFIED | TypeScript/Expo Doctor không thay thế chạy app native trên thiết bị. |

Lượt này kiểm tra Expo Web local ở `http://127.0.0.1:8090` với viewport 390 × 844. Metro local chỉ được khởi chạy để kiểm tra UI và được dừng sau đó; đây không phải EAS Development Build hoặc Production Build, cũng không xác nhận iOS/Android thật.

Một lượt trước đã xác nhận manifest và bundle iOS của Expo Go tunnel trả HTTP 200, SDK 54.0.0; QR artifact là `../../output/mobile-expo-go-8090.png`. Chưa có xác nhận người dùng đã quét QR, và không khẳng định tunnel đó hiện vẫn hoạt động.

Ảnh kiểm chứng giao diện; các ảnh này không chứng minh API Production đã triển khai:

![Trang chủ có sản phẩm và ảnh catalog thật](../../output/mobile-home-live-390.png)

![Màn hội thoại SYNA trên viewport 390 px](../../output/mobile-assistant-390.png)

![Chi tiết sản phẩm trước khi mở website](../../output/mobile-product-detail-390.png)

## Còn thiếu trước khi gọi là app hoàn thiện

1. Đăng ký native cần API OTP/email riêng và cơ chế tạo `ma_kh` không đua tranh; hiện tại website registration gọi `taoMoi()` có hành vi xóa tài khoản/đơn hàng cùng email.
2. Checkout native cần dùng lại business core nhưng thêm idempotency cho tạo đơn, cấp ID an toàn khi có nhiều request, kiểm soát voucher/điểm và xử lý cổng thanh toán.
3. Đối chiếu dữ liệu hai chiều trong Mongo local hoặc sandbox với cùng tài khoản, gồm cart/favorites/profile/skin/orders; kiểm tra token hết hạn, logout, IDOR và duplicate request.
4. Thêm routine đã lưu và đồng bộ chatbot history nếu backend xác nhận dữ liệu hiện tại có thể truy xuất/ghi theo customer ID. Routine web hiện là session state.
5. Tích hợp livestream, push notifications và catalog pagination/review API.
6. Chạy iOS/Android thật và website regression, rồi mới đánh giá triển khai Production.

## Chạy app

Trong `mobile/`, dùng Node.js 22:

```powershell
npm ci
npm run start:prod
```

Production mặc định dùng domain HTTPS chính thức và script bật Expo tunnel. Giữ terminal hiện đang chạy để link/QR tiếp tục hoạt động. Quét [QR Expo Go hiện tại](../../output/mobile-expo-go-8090.png) bằng Camera iPhone và mở bằng Expo Go. Manifest xác nhận SDK 54.0.0.

Development:

```powershell
npm run start:dev
```

Khi API local nằm sau tunnel riêng, đặt `SKINSYNTAX_DEV_API_URL` trong terminal hiện tại rồi khởi động lại Metro. Không đưa secrets vào `EXPO_PUBLIC_*`.

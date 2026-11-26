# SkinSyntaxVN Mobile

**Trạng thái API:** source có native route `r=mobile_api`, nhưng kiểm tra read-only ngày 26-11-2026 cho thấy `https://skinsyntaxvn.app/index.php?r=mobile_api&action=catalog.product&id=5689` hiện trả HTML 404. Vì vậy đăng nhập, cart, favorites và các API account chưa dùng được trên Production cho tới khi backend được deploy. Catalog cũ vẫn có thể đọc qua API hiện hữu; điều đó không chứng minh API native đã hoạt động.

## iPhone với Expo Go

Chạy từ thư mục `mobile` bằng Node.js 22.

```powershell
npm ci
npm run start:prod
```

Mở Camera trên iPhone và quét QR hiện trong terminal. Giữ Metro chạy. Production dùng `https://skinsyntaxvn.app` kể cả khi điện thoại chuyển Wi-Fi hoặc dùng 4G/5G. Dự án dùng SDK 54 cho Expo Go từ App Store. Phiên bản SDK trên Expo Go và dự án phải khớp.

Tham khảo [hướng dẫn phiên bản Expo Go](https://docs.expo.dev/troubleshooting/expo-go-version-mismatch/) nếu iPhone báo không tương thích. Nếu Expo Go yêu cầu đăng nhập, chạy `npx expo login` và dùng cùng tài khoản Expo trên iPhone, sau đó mở lại tunnel. Với SDK mới hơn SDK được App Store hỗ trợ, cần bản Expo Go tương thích qua TestFlight hoặc Development Build.

Kiểm tra trên máy thật:

1. Mở Trang chủ và kiểm tra ảnh, tên, giá sản phẩm. Đổi nhóm Serum sang Kem chống nắng.
2. Trong Khám phá, gõ từ khóa, chọn thương hiệu, chạm sản phẩm và đóng màn chi tiết.
3. Tắt Wi-Fi, bật dữ liệu di động rồi tìm từ khóa mới. Kiểm tra ảnh và kết quả mới.
4. Tắt cả Wi-Fi và dữ liệu, tìm tiếp để kiểm tra thông báo lỗi. Bật mạng lại và nhấn Thử lại.

## Development

`npm run start:dev` phát hiện IPv4 LAN bằng các giao diện mạng của máy chạy lệnh, bỏ qua loopback và các tên adapter ảo phổ biến. Nếu còn nhiều ứng viên, lệnh yêu cầu chọn rõ địa chỉ. Khởi động backend local và cho điện thoại truy cập máy qua mạng trước khi chạy.

```powershell
# Chỉ đặt khi backend publish cổng khác 80.
$env:SKINSYNTAX_API_PORT='8080'
npm run start:dev
```

Khi đổi Wi-Fi, dừng Metro và chạy lại `start:dev` để phát hiện IP mới. Để chọn cổng, subpath hoặc tunnel riêng của API, đặt biến môi trường trong phiên PowerShell:

```powershell
$env:SKINSYNTAX_DEV_API_URL='https://your-approved-api-tunnel.example'
npm run start:dev -- --tunnel
```

Tunnel Metro mang bundle JavaScript. Điện thoại phải truy cập được API local hoặc tunnel API độc lập. Kiểm tra URL API bằng Safari trên điện thoại. Production vẫn sử dụng domain chính thức khi chạy `npm run start:prod`.

Khi mở link HTTPS của Metro bằng trình duyệt, Expo Web đọc catalog và gửi chat SYNA qua các route cùng origin `/api/catalog` và `/api/chat`. Chạy lại Metro sau khi thay đổi cấu hình Expo. Nếu xuất bản Expo Web, host phải chạy cả API routes từ bản export server. Expo Go tiếp tục gọi API trực tiếp.

## Hợp đồng và phạm vi

- `src/config/api.ts` chọn môi trường khi Metro khởi động, mặc định HTTPS production và giữ subpath nếu API dev được mount dưới một thư mục. Build chạy ngoài chế độ dev luôn dùng production.
- `src/services/api.ts` đọc JSON công khai, bỏ credentials, giới hạn 12 giây và cho người dùng chủ động thử lại. Thay từ khóa sẽ hủy request trước. Màn sản phẩm dùng dữ liệu thật từ `api_smart_search`; modal chi tiết đọc `catalog.product` và thêm vào cart/favorites qua API native.
- Màn Trang chủ và Khám phá hỗ trợ danh sách, ảnh, giá, tìm kiếm, nhóm sản phẩm, lọc thương hiệu trong kết quả và xem nhanh chi tiết. Bottom tabs dùng Ionicons, còn splash và app icon dùng ảnh mascot SYNA của website.
- `MobileAuthProvider` gọi API tài khoản chung. Web giữ token trong cookie `HttpOnly` do Expo API route quản lý; iOS/Android giữ token trong SecureStore. API trả về khách hàng theo `ma_kh` và không cấp token cho admin/staff.
- Native screens đã có cho profile, địa chỉ, hồ sơ da/khảo sát, orders, favorites, cart, vouchers và loyalty. Cần xác minh mọi thao tác ghi/đọc trên Mongo local hoặc sandbox bằng cùng một tài khoản trước khi coi là đồng bộ E2E.
- Checkout và đăng ký native chưa có. Không gọi `NguoiDung::taoMoi()` vì code hiện tại xóa user/customer/orders cùng email; checkout website còn dựa session và các side effects hiện hữu. Routine web giữ consultation tạm trong PHP session; chatbot native chưa lưu lịch sử vào account.

Xem [bảng feature mapping và kết quả kiểm thử đầy đủ](../docs/mobile/README.md).

Chạy kiểm tra bằng `npm run typecheck`, `npm run lint`, `npm test` và `npx expo-doctor@latest`. Backend store test chạy bằng `php ../backend/tests/unit/MobileApiStoresTest.php`. Việc quét QR, đăng nhập sandbox và đồng bộ dữ liệu hai chiều trên thiết bị thật cần kiểm tra riêng.

## Tệp thay đổi

- Cấu hình và API: `.env.example`, `scripts/start-expo.cjs`, `scripts/api-config.test.cjs`, `src/config/api.ts`, `src/services/api.ts`, `src/types/env.d.ts`.
- Giao diện và điều hướng: `src/components/CatalogScreen.tsx`, `app/(tabs)/index.tsx`, `app/(tabs)/explore.tsx`, `app/(tabs)/_layout.tsx`, `app/_layout.tsx`, `app/+not-found.tsx`, `components/ExternalLink.tsx`, `components/EditScreenInfo.tsx`, `components/useColorScheme.ts`.
- SDK và thương hiệu: `package.json`, `package-lock.json`, `.npmrc`, `eslint.config.js`, `app.json`, `assets/images/syna.png`, `README.md`. Thiết lập npm trong thư mục mobile tránh npm tự cài peer RSC tùy chọn xung đột với React 19.1 của SDK 54.

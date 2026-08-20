# 📖 Hướng Dẫn: Trang Chi Tiết Sản Phẩm (PDP) Với Match Score Feature

## 🎯 Tính Năng "Sát Thủ" - Match Score

Trang chi tiết sản phẩm (PDP) giờ có tính năng hiển thị **Match Score** (Điểm phù hợp) - giúp user biết sản phẩm phù hợp bao nhiêu % với da của họ.

---

## 🏗️ Cấu Trúc Code

### 1️⃣ **Model - SanPham.php**

#### `calculateMatchScore($productId, $userProfile)`
Hàm tính điểm phù hợp dựa trên:
- **Sản phẩm**: `thanh_phan_clean` (danh sách các thành phần chính)
- **Hộ sơ user**: `thanh_phan_tranh` (danh sách thành phần nên tránh)

**Cách tính:**
```
Match Score = (Số thành phần tốt / Tổng số thành phần) × 100
```

**Kết quả trả về:**
```php
[
    'match_score' => 92,                    // 0-100%
    'good_ingredients' => ['Hyaluronic', 'Vitamin C', ...],  // ✓ Thành phần tốt
    'bad_ingredients' => ['Alcohol', ...],                   // ⚠️ Thành phần nên tránh
    'description' => "Sản phẩm này phù hợp 92% với da mụn của bạn..."
]
```

#### `parseIngredients($rawText)`
Phân tích chuỗi thành phần từ text:
- Hỗ trợ format: `"thành phần 1, thành phần 2, ..."` (ngăn cách bằng dấu phẩy)
- Hoặc: `"thành phần 1\nthành phần 2\n..."` (ngăn cách bằng xuống dòng)

---

### 2️⃣ **Model - NguoiDung.php**

#### `layKhachHangTheoEmail($email)`
Lấy thông tin khách hàng từ bảng `khach_hang` dựa vào email:

```php
$userProfile = $userModel->layKhachHangTheoEmail('user@email.com');
// Trả về:
[
    'ma_kh' => 1,
    'ho_ten' => 'Nguyễn Văn A',
    'van_de_da' => 'Da mụn',
    'thanh_phan_tranh' => 'Alcohol, Sulfate, Paraben',
    ...
]
```

---

### 3️⃣ **Controller - SanPhamController.php**

#### `chitiet()` method
Xử lý trang chi tiết sản phẩm:

```php
// Nếu user đã đăng nhập -> tính Match Score
if (is_logged_in()) {
    $user = current_user();
    $userProfile = $this->userModel->layKhachHangTheoEmail($user['email']);
    
    if ($userProfile) {
        $matchScoreData = $this->model->calculateMatchScore((int)$id, $userProfile);
    }
}

// Truyền dữ liệu tới view
$this->render('chitiet', [
    'p' => $p,
    'matchScore' => $matchScoreData,
]);
```

---

### 4️⃣ **View - chitiet.php**

Hiển thị Match Score dưới dạng box trang trí:

```html
<!-- Match Score Box -->
<div class="match-score-box mt-4 p-3">
    <h5>🎯 Phù hợp với da của bạn: <strong>92%</strong></h5>
    
    <!-- Thành phần tốt (màu xanh) -->
    <div style="background: rgba(76, 175, 80, 0.3);">
        ✓ Hyaluronic, Vitamin C, ...
    </div>
    
    <!-- Thành phần nên tránh (màu đỏ) -->
    <div style="background: rgba(244, 67, 54, 0.3);">
        ⚠️ Alcohol, Sulfate, ...
    </div>
</div>
```

**Khi user chưa đăng nhập hoặc chưa khảo sát:**
- Hiển thị thông báo: "Đăng nhập và hoàn thành khảo sát da để xem sản phẩm này phù hợp như thế nào"

---

## 🔄 Luồng Hoạt Động

### Step 1: User Đăng Nhập
```
User click "Đăng nhập" 
  → AuthController::xulydangnhap()
  → Set $_SESSION['user']['email']
```

### Step 2: User Khảo Sát Da
```
User click "Khảo sát da"
  → Form khaosat.php (auth/khaosat.php)
  → AuthController::xulykhaosat()
  → NguoiDung::luuKhaoSatKhachHang()
  → Lưu vào bảng khach_hang (thanh_phan_tranh, van_de_da, ...)
```

### Step 3: User Xem Sản Phẩm
```
User xem trang chi tiết: /index.php?r=chitiet&id=123
  → SanPhamController::chitiet()
  → Kiểm tra: is_logged_in()?
  
  Nếu CÓ:
    → Lấy userProfile từ khach_hang
    → Gọi calculateMatchScore()
    → So sánh thanh_phan_clean với thanh_phan_tranh
    → Tính Match Score & parse ingredients
    → Truyền tới View
    
  Nếu KHÔNG:
    → matchScore = null
    → View hiển thị "Hãy đăng nhập"
```

### Step 4: View Hiển Thị
```
View (chitiet.php):
  Nếu matchScore không null:
    → Hiển thị Match Score box
    → Highlight good_ingredients (xanh)
    → Highlight bad_ingredients (đỏ)
  Nếu matchScore = null:
    → Hiển thị thông báo khuyến khích đăng nhập
```

---

## 📊 Database Schema

### Bảng `nguoidung`
```sql
id          - ID người dùng
email       - Email (unique)
ho_ten      - Họ tên
mat_khau    - Mật khẩu hash
```

### Bảng `khach_hang`
```sql
ma_kh           - ID khách hàng
email           - Email
ho_ten          - Họ tên
van_de_da       - Vấn đề da: "Da mụn", "Da nhạy cảm", v.v
thanh_phan_tranh - Thành phần nên tránh: "Alcohol, Sulfate, Paraben"
... (các field khác)
```

### Bảng `san_pham`
```sql
ma_san_pham       - ID sản phẩm
ten_san_pham      - Tên sản phẩm
thanh_phan_clean  - Danh sách thành phần sạch (CSV hoặc newline-separated)
thanh_phan_full   - Danh sách đầy đủ tất cả thành phần
gia_ban           - Giá bán
... (các field khác)
```

---

## 💡 Ví Dụ Thực Tế

### User: Nguyễn Văn A
**Hồ sơ da:**
- `van_de_da` = "Da mụn"
- `thanh_phan_tranh` = "Alcohol, Sulfate, Paraben"

### Sản phẩm: Sữa rửa mặt Cetaphil
**Thành phần:**
- `thanh_phan_clean` = "Water, Vitamin E, Hyaluronic Acid, Niacinamide, Alcohol Denat"

### Tính toán:
```
Tất cả thành phần: 5
  - Water ✓
  - Vitamin E ✓
  - Hyaluronic Acid ✓
  - Niacinamide ✓
  - Alcohol Denat ⚠️ (có trong thanh_phan_tranh)

Thành phần tốt: 4/5 = 80%
⚠️ Cảnh báo: "Sản phẩm này có 1 thành phần nên tránh"
```

---

## 🔧 Cách Sử Dụng

### 1️⃣ Để Test Match Score Feature:

```bash
# 1. Đảm bảo bảng khach_hang có dữ liệu thanh_phan_tranh
php public/import_new_schema.php

# 2. Truy cập trang đăng nhập
http://localhost/backend/public/index.php?r=dangnhap

# 3. Đăng nhập hoặc tạo tài khoản mới

# 4. Làm khảo sát "Khảo sát da"
http://localhost/backend/public/index.php?r=khaosat

# 5. Nhập thành phần nên tránh (tùy chọn)
# Ví dụ: "Alcohol, Sulfate, Paraben"

# 6. Xem trang chi tiết sản phẩm
http://localhost/backend/public/index.php?r=chitiet&id=1

# Match Score sẽ hiển thị với:
# - Điểm phù hợp (%)
# - Thành phần tốt (xanh)
# - Thành phần nên tránh (đỏ)
```

---

## 📱 Giao Diện Match Score

```
┌─────────────────────────────────────────┐
│ 🎯 Phù hợp với da của bạn      [92%]    │
├─────────────────────────────────────────┤
│ Sản phẩm này phù hợp 92% với da mụn    │
│ ⚠️ Chứa 1 thành phần nên tránh          │
│ ✓ Chứa 4 thành phần tốt                 │
├─────────────────────────────────────────┤
│ ✓ Thành phần tốt:                       │
│ [Hyaluronic] [Vitamin C] [Niacinamide] │
│   [Panthenol] [Allantoin]               │
├─────────────────────────────────────────┤
│ ⚠️ Thành phần nên tránh:                │
│ [Alcohol]                               │
└─────────────────────────────────────────┘
```

---

## ✅ Checklist Tích Hợp

- [x] Thêm `calculateMatchScore()` vào `SanPham` model
- [x] Thêm `layKhachHangTheoEmail()` vào `NguoiDung` model
- [x] Cập nhật `SanPhamController::chitiet()` để tính Match Score
- [x] Cập nhật `chitiet.php` view để hiển thị Match Score
- [x] Thêm styling cho Match Score box
- [x] Hiển thị thế hôm đăng nhập khi user chưa có profile

---

## 🚀 Tính Năng Nâng Cao (Tương Lai)

1. **Lưu lịch sử So Sánh**: Lưu products user đã xem + Match Score
2. **AI Recommendations**: Gợi ý sản phẩm tương tự nhưng có Match Score cao hơn
3. **Comparison Tool**: So sánh Match Score giữa 2-3 sản phẩm
4. **Notification**: Thông báo khi có sản phẩm mới với Match Score > 80%
5. **Review + Match Score**: Combine review từ users có da tương tự

---

## 🆘 Troubleshooting

### Match Score không hiển thị?
- Check: User đã đăng nhập?
- Check: User đã hoàn thành khảo sát?
- Check: Bảng `khach_hang` có dữ liệu `thanh_phan_tranh`?
- Debug: Thêm `var_dump($matchScore);` trong view

### Điểm Match Score sai?
- Check: `thanh_phan_clean` của sản phẩm có đúng format?
- Check: `thanh_phan_tranh` của user đã được save đúng?
- Debug: Log data trước khi gửi tới `calculateMatchScore()`

### Thành phần không highlight đúng?
- Check: Format separator (dấu phẩy hay newline)?
- Debug: `var_dump($model->parseIngredients($text));`

---

## 📚 Tài Liệu Liên Quan

- [Backend App Structure](./backend/app/)
- [Database Schema](./database/db.sql)
- [View Authentication](./backend/app/views/auth/)

---

## 👨‍💻 Tác Giả

Code được tạo cho dự án **SkinSyntaxVN** - Hệ thống Gợi Ý Sản Phẩm Chăm Sóc Da AI.


# 🚀 Quick Start - Match Score Feature

## ⚡ Tóm Tắt

**Match Score** là tính năng "Sát thủ" (Killer Feature) trên trang chi tiết sản phẩm (PDP) giúp user biết sản phẩm phù hợp bao nhiêu % với da của họ.

---

## 📋 Files Được Cập Nhật

```
✅ backend/app/models/SanPham.php
   └─ Thêm calculateMatchScore() & parseIngredients()

✅ backend/app/models/NguoiDung.php
   └─ Thêm layKhachHangTheoEmail()

✅ backend/app/controllers/SanPhamController.php
   └─ Cập nhật chitiet() method

✅ backend/app/views/chitiet.php
   └─ Hiển thị Match Score box

✅ backend/public/assets/css/style.css
   └─ CSS styling cho Match Score

📄 PDP_MATCH_SCORE_GUIDE.md
   └─ Tài liệu chi tiết
```

---

## 🔍 Cách Hoạt Động

### User Journey:
```
1. User Đăng Nhập
   ↓
2. Hoàn thành Khảo Sát Da
   ↓
3. Xem Sản Phẩm Chi Tiết
   ↓
4. Thấy Match Score 🎯
   - % phù hợp
   - Thành phần tốt ✓ (xanh)
   - Thành phần nên tránh ⚠️ (đỏ)
```

### Code Flow:
```php
SanPhamController::chitiet()
  ├─ Check: User đã đăng nhập?
  ├─ Lấy userProfile từ khach_hang
  ├─ Gọi SanPham::calculateMatchScore($productId, $userProfile)
  │  ├─ Parse thanh_phan_clean từ sản phẩm
  │  ├─ Parse thanh_phan_tranh từ hồ sơ user
  │  ├─ So sánh & phân loại
  │  └─ Tính Match Score (%)
  └─ Truyền dữ liệu tới view → Hiển thị
```

---

## 💻 Test Ngay

### Step 1: Đăng Nhập Hoặc Tạo Account
```
http://localhost/backend/public/index.php?r=dangnhap
```

### Step 2: Hoàn Thành Khảo Sát Da
```
http://localhost/backend/public/index.php?r=khaosat
```
⚠️ **Quan trọng:** Nhập thành phần nên tránh, ví dụ:
```
Alcohol, Sulfate, Paraben, Formaldehyde
```

### Step 3: Xem Sản Phẩm Chi Tiết
```
http://localhost/backend/public/index.php?r=chitiet&id=1
```

✅ **Bạn sẽ thấy Match Score box với:**
- 🎯 Điểm phù hợp (%)
- ✓ Thành phần tốt (tag xanh)
- ⚠️ Thành phần nên tránh (tag đỏ)

---

## 📊 Dữ Liệu Yêu Cầu

### Sản Phẩm (san_pham)
```
thanh_phan_clean = "Aqua, Glycerin, Vitamin E, Niacinamide"
                    (danh sách thành phần chính)
```

### Hồ Sơ User (khach_hang)
```
thanh_phan_tranh = "Alcohol, Sulfate, Paraben"
                    (thành phần nên tránh)
van_de_da        = "Da mụn"
                    (vấn đề da)
```

---

## 🎨 Giao Diện

### Khi User Đã Đăng Nhập + Khảo Sát:
```
┌────────────────────────────────────────┐
│ 🎯 Phù hợp với da của bạn       [92%]  │
├────────────────────────────────────────┤
│ Sản phẩm này phù hợp 92% với da mụn  │
│ ✓ Chứa 4 thành phần tốt                │
│ ⚠️ Chứa 1 thành phần nên tránh         │
├────────────────────────────────────────┤
│ ✓ Thành phần tốt:                      │
│ [Hyaluronic] [Vitamin C] [Niacinamide]│
│ [Panthenol] [Allantoin] (+1 khác)     │
├────────────────────────────────────────┤
│ ⚠️ Thành phần nên tránh:               │
│ [Alcohol Denat]                        │
└────────────────────────────────────────┘
```

### Khi User Chưa Đăng Nhập:
```
┌────────────────────────────────────────┐
│ Đăng nhập & hoàn thành khảo sát da     │
│ để xem sản phẩm này phù hợp như thế   │
│ nào với da của bạn                      │
│                                        │
│ [Đăng nhập]  [Khảo sát da]            │
└────────────────────────────────────────┘
```

---

## 🧪 Ví Dụ PHP Code

### Sử dụng Match Score trong Controller:

```php
<?php
require_once 'models/SanPham.php';
require_once 'models/NguoiDung.php';

$pdo = new PDO(...);
$sanPhamModel = new SanPham($pdo);
$userModel = new NguoiDung($pdo);

// Lấy hồ sơ user
$userEmail = "user@email.com";
$userProfile = $userModel->layKhachHangTheoEmail($userEmail);

// Lấy Match Score
$productId = 123;
$matchScore = $sanPhamModel->calculateMatchScore($productId, $userProfile);

// Output:
echo $matchScore['match_score'];           // 92
echo count($matchScore['good_ingredients']); // 4
echo count($matchScore['bad_ingredients']);  // 1
echo $matchScore['description'];           // "Sản phẩm này phù hợp..."
?>
```

---

## 📝 Cấu Trúc Dữ Liệu Trả Về

```php
[
    'match_score' => 92,           // 0-100 (%)
    'good_ingredients' => [        // ✓ Thành phần tốt
        'Hyaluronic Acid',
        'Vitamin E',
        'Niacinamide'
    ],
    'bad_ingredients' => [         // ⚠️ Thành phần nên tránh
        'Alcohol Denat'
    ],
    'description' => 'Sản phẩm này phù hợp 92% với da mụn của bạn...'
]
```

---

## 🔧 Troubleshooting

| Vấn Đề | Nguyên Nhân | Giải Pháp |
|--------|-----------|----------|
| Match Score không hiển thị | User chưa khảo sát | Yêu cầu user khảo sát da |
| Điểm sai | Format thành phần sai | Check `thanh_phan_clean` & `thanh_phan_tranh` |
| Lỗi database | Bảng khach_hang chưa có dữ liệu | Import schema & migrate data |
| CSS không áp dụng | File CSS chưa load | Kiểm tra link stylesheet trong header |

---

## ✨ Features

✅ Tính Match Score tự động từ thành phần sản phẩm  
✅ Highlight thành phần tốt (xanh) vs nên tránh (đỏ)  
✅ Responsive design (mobile-friendly)  
✅ Animated entrance effect  
✅ Fallback message khi user chưa khảo sát  

---

## 🚀 Tiếp Theo - Nâng Cao

1. **Save Comparison History**: Lưu products user đã so sánh
2. **AI Recommendations**: "Sản phẩm khác tương tự nhưng có điểm cao hơn"
3. **Batch Comparison**: So sánh 2-3 sản phẩm cùng lúc
4. **Email Notification**: "Sản phẩm mới phù hợp 95% với bạn!"
5. **Admin Dashboard**: Xem thống kê Match Score

---

## 📚 Tài Liệu Liên Quan

- [Full Documentation](./PDP_MATCH_SCORE_GUIDE.md)
- [Database Schema](./database/db.sql)
- [API Reference](./backend/app/models/)

---

**🎉 Ready? Test ngay Match Score Feature!**


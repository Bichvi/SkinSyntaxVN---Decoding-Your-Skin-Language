# 🧪 Hướng Dẫn Test Cải Tiến - Quick Start

## ⚡ Test Nhanh (5 phút)

### Bước 1: Khởi Động Flask
```bash
cd ai-service-flask
python app.py
```

**Kiểm tra console:** Nên thấy `[OK] LangChain RAG components initialized`

---

### Bước 2: Test API Trực Tiếp

#### Test 2a: Lọc Kem Dưỡng

```bash
curl -X POST http://localhost:5000/api/recommend/langchain-rag \
  -H "Content-Type: application/json" \
  -d '{
    "user_profile": {
      "skin_type": "Da dầu",
      "concerns": ["mụn"]
    },
    "query_text": "kem dưỡng dưới 500k",
    "top_k": 5
  }'
```

**Kỳ Vọng:**
- Chỉ trả về sản phẩm có `loai_san_pham` = "kem" hoặc tên chứa "kem"
- Không có serum, mặt nạ, v.v.

**Kiểm Tra Console Flask:**
```
[Retriever] Filtering by product type: kem
[Retriever] Starting hybrid search
```

---

#### Test 2b: Lọc Serum

```bash
curl -X POST http://localhost:5000/api/recommend/langchain-rag \
  -H "Content-Type: application/json" \
  -d '{
    "user_profile": {
      "skin_type": "Da khô",
      "concerns": ["khô"]
    },
    "query_text": "serum dưỡng",
    "top_k": 5
  }'
```

**Kỳ Vọng:**
- Chỉ serum (không kem, không mặt nạ)

---

#### Test 2c: Lọc Mặt Nạ

```bash
curl -X POST http://localhost:5000/api/recommend/langchain-rag \
  -H "Content-Type: application/json" \
  -d '{
    "user_profile": {
      "skin_type": "Da dầu",
      "concerns": ["mụn"]
    },
    "query_text": "mặt nạ đất sét",
    "top_k": 5
  }'
```

**Kỳ Vọng:**
- Chỉ mặt nạ

---

### Bước 3: Test Qua Web

#### Test 3a: Trang Gợi Ý
1. Mở: `http://localhost/VN/SkinSyntaxVN---Decoding-Your-Skin-Language/backend/public/index.php?r=goiy`

2. Điền form:
   - **MÔ TẢ NHU CẦU:** `kem dưỡng`
   - **LOẠI DA:** Chọn "Da dầu"
   - **CONCERNS:** Tích "Mụn"
   - **NGÂN SÁCH:** 500,000 VND

3. Nhấn **"Tìm Sản Phẩm"**

#### Test 3b: Kiểm Tra Kết Quả

**Kỳ Vọng:**

```
✅ CHỈ hiển thị sản phẩm loại KEM
   - Tên sản phẩm (kem dưỡng)
   - Giá < 500k
   - Cho da dầu

✅ GIẢI THÍCH TSELF NHIÊN:
   "Theo tôi nghĩ, với tình trạng da của bạn thì nên dùng 
   Cetaphil Moisturizing Cream trước tiên. Vì sao? Bởi vì 
   trong sản phẩm này có Ceramide, Panthenol... những thành 
   phần này rất tốt cho da dầu, giúp khóa ẩm mà không gây nhờn.
   Giá 480k cũng hợp lý cho ngân sách 500k của bạn."

✅ CÓ SO SÁNH & LỰA CHỌN KHÁC:
   "Ngoài ra, nếu muốn tiết kiệm hơn, tôi còn gợi ý..."

✅ THỜI GIAN LOAD:
   - Lần đầu: 2-5 giây (bình thường)
   - Lần 2: <1 giây (cached)
```

---

## 📊 Bảng Kiểm Tra

### Lọc Sản Phẩm

| Query | Loại Sản Phẩm | Kỳ Vọng | ✅/❌ |
|-------|--------------|--------|------|
| "kem dưỡng" | Kem | Chỉ kem | |
| "serum" | Serum | Chỉ serum | |
| "mặt nạ" | Mặt nạ | Chỉ mask | |
| "toner" | Toner | Chỉ toner | |
| "sữa rửa mặt" | Cleanser | Chỉ cleanser | |

### Giải Thích

| Tiêu Chí | Kỳ Vọng | ✅/❌ |
|---------|--------|------|
| Tự nhiên, thân thiện | Có "Theo tôi...", "Bạn nên..." | |
| Chi tiết thành phần | Nêu tên thành phần cụ thể | |
| So sánh giá | "Giá X phù hợp với ngân sách Y" | |
| Có lựa chọn khác | "Nếu muốn tiết kiệm..." | |
| Không bịa đặt | Toàn bộ từ database | |
| 2-3 đoạn | Không quá dài | |

---

## 🐛 Khắc Phục Lỗi

### Lỗi 1: Console Không Hiện Filtering Message

```
❌ [Retriever] Filtering by product type: ...
```

**Nguyên Nhân:** Query không chứa loại sản phẩm

**Giải Pháp:** Thêm loại sản phẩm vào query
```
"kem dưỡng cho da dầu" ✅
"cho da dầu" ❌
```

---

### Lỗi 2: Vẫn Trả Về Nhiều Loại Sản Phẩm

```
❌ Kết quả có serum + kem + mặt nạ
```

**Nguyên Nhân:** Có thể MongoDB chưa cập nhật hoặc field `loai_san_pham` không tồn tại

**Giải Pháp:** 
1. Kiểm tra MongoDB:
```javascript
db.products.findOne()  // Check fields
db.products.find({'loai_san_pham': 'kem'})  // Test filter
```

2. Nếu field không có, thêm vào từ `ten_san_pham`:
```javascript
db.products.updateMany(
  {'ten_san_pham': {$regex: 'kem', $options: 'i'}},
  {$set: {'loai_san_pham': 'kem'}}
)
```

---

### Lỗi 3: Giải Thích Không Tự Nhiên

```
❌ "Sản phẩm này phù hợp vì..."
```

**Nguyên Nhân:** Prompt template chưa cập nhật hoặc cache cũ

**Giải Pháp:**
1. Restart Flask: `python app.py`
2. Clear cache: `/api/cache/clear`
3. Test lại

---

## 📝 Ghi Chú Kiểm Tra

### Ngôn Ngữ Sản Phẩm

Đảm bảo MongoDB có các trường:
- `loai_san_pham` (kem, serum, v.v.)
- `ten_danh_muc` (danh mục)
- `ten_san_pham` (tên sản phẩm)
- `thanh_phan` (thành phần)
- `tac_dung` (tác dụng)
- `gia_ban` (giá)

**Kiểm tra:**
```bash
mongo
> use skinsyntax
> db.products.findOne()
```

### Performance

- **Đo thời gian:**
  - Lần 1: Nên 2-5 giây
  - Lần 2: Nên <200ms (cached)

- **Kiểm tra cache:**
```bash
curl http://localhost:5000/api/cache/stats
```

---

## ✅ Checklist Test Cuối Cùng

- [ ] Lọc "kem dưỡng" → Chỉ kem
- [ ] Lọc "serum" → Chỉ serum
- [ ] Lọc "mặt nạ" → Chỉ mask
- [ ] Giải thích tự nhiên, có "Theo tôi..."
- [ ] Chi tiết thành phần & tác dụng
- [ ] So sánh giá
- [ ] Gợi ý thay thế
- [ ] Thời gian hợp lý (2-5s lần 1, <1s lần 2)
- [ ] Không bịa đặt thành phần
- [ ] Tone thân thiện (không chuyên môn)

---

## 🎯 Các Trường Hợp Test Phổ Biến

### Test Case 1: Kem Dưỡng
```
Query: "kem dưỡng cho da dầu có mụn"
Expected: Kem dưỡng phù hợp, giải thích tự nhiên
```

### Test Case 2: Serum
```
Query: "serum dưỡng ẩm"
Expected: Serum, không kem
```

### Test Case 3: Kết Hợp
```
Query: "tôi cần serum và mặt nạ"
Expected: Serum + mặt nạ (không kem)
```

### Test Case 4: Ngân Sách
```
Query: "kem chống nắng dưới 300k"
Expected: Kem chống nắng < 300k, so sánh giá
```

### Test Case 5: Không Loại Sản Phẩm
```
Query: "sản phẩm cho da dầu"
Expected: Tất cả sản phẩm phù hợp (không lọc loại)
```

---

## 📞 Liên Hệ Hỗ Trợ

Nếu gặp lỗi:

1. **Kiểm tra logs:** Console Flask
2. **Test API:** Dùng curl trực tiếp
3. **Kiểm tra MongoDB:** Xem dữ liệu có đúng không
4. **Xóa cache:** `/api/cache/clear`
5. **Restart:** `python app.py`

---

**Status:** ✅ Sẵn Sàng Test

**Thời Gian Test Dự Kiến:** 5-10 phút

**Ngày Cập Nhật:** 2026-05-10

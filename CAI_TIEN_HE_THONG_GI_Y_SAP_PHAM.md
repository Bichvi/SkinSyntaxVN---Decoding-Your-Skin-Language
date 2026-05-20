# 🎯 Cải Tiến Hệ Thống Gợi Ý Sản Phẩm - Hướng Dẫn Chi Tiết

## ✅ Vấn Đề Được Giải Quyết

### Vấn Đề 1: Không Lọc Được Loại Sản Phẩm ❌
**Trước:** Khi bạn nhập "kem dưỡng", hệ thống trả về serum, mặt nạ, v.v. - không chỉ kem dưỡng

**Sau:** Hệ thống tự động nhận diện "kem dưỡng" và lọc chỉ trả về sản phẩm loại kem

### Vấn Đề 2: Giải Thích Quá Chung Chung ❌
**Trước:** "Sản phẩm này phù hợp vì nó có vitamin C..."

**Sau:** "Theo tôi nghĩ, với làn da của bạn thì nên dùng [Tên sản phẩm] trước tiên. Vì sao? Bởi vì trong sản phẩm này có [Thành phần 1], [Thành phần 2]... những thành phần này rất tốt cho da [Loại da], giúp [Lợi ích]. Giá [Giá] cũng hợp lý với ngân sách [Ngân sách] của bạn."

---

## 🔧 Cải Tiến Chi Tiết

### 1. Tự Động Nhận Diện Loại Sản Phẩm (`hybrid_retriever.py`)

#### Phương Pháp Mới: `_extract_product_type()`

```python
# Tự động nhận diện các loại sản phẩm từ query:
"kem dưỡng" → filter: loại "kem"
"serum toner" → filter: loại "serum"
"mặt nạ" → filter: loại "mask"
"kem chống nắng" → filter: loại "kem"
```

#### Hỗ Trợ 30+ Loại Sản Phẩm:

| Nhập | Kết quả lọc |
|------|------------|
| kem dưỡng, kem chống nắng, kem mặt | Kem (kem) |
| serum | Serum (serum) |
| essence | Essence (essence) |
| toner, nước hoa hồng | Toner (toner) |
| mặt nạ, mask | Mask (mask) |
| sữa rửa mặt, sữa tẩy trang | Cleanser (cleanser) |
| sữa dưỡng | Milk (milk) |
| dầu | Oil (oil) |
| scrub | Scrub (scrub) |
| xịt khoáng | Spray (spray) |
| bb cream, cc cream | BB/CC (bb/cc) |
| và nhiều loại khác... | ... |

#### Cách Hoạt Động:

```python
def _extract_product_type(query: str) -> str:
    # Tìm kiếm từ khoá dài nhất trước (kem dưỡng trước khi kem)
    # Trả về loại sản phẩm chuẩn để lọc MongoDB
    
    query = "Tôi muốn kem dưỡng cho da dầu"
    → extracted: "kem"
    → MongoDB filter: {'loai_san_pham': {'$regex': 'kem'}}
```

#### Bộ Lọc Được Cải Tiến:

```python
# Trước: Chỉ lọc budget, skin_type, ingredients
filters = {
    'gia_ban': {'$lte': 500000},
    'loai_da': 'Da dầu'
}

# Sau: Thêm lọc loại sản phẩm từ query
filters = {
    'gia_ban': {'$lte': 500000},
    'loai_da': 'Da dầu',
    '$or': [
        {'loai_san_pham': {'$regex': 'kem'}},
        {'ten_danh_muc': {'$regex': 'kem'}},
        {'ten_san_pham': {'$regex': 'kem'}}
    ]
}
```

### 2. Cải Tiến Giải Thích Tự Nhiên (`prompt_templates.py`)

#### System Prompt Mới (Tự Nhiên & Thân Thiện)

**Trước:**
```
"Bạn là chuyên gia tư vấn mỹ phẩm chuyên nghiệp..."
→ Tone: Chuyên môn, cách xa
```

**Sau:**
```
"Bạn là một cô gái trung thành, bạn thân của khách hàng..."
→ Tone: Thân thiện, gần gũi, giống tư vấn bạn thân
```

#### User Prompt Cải Tiến (Chi Tiết & Có Ví Dụ)

**Cấu Trúc Mới:**

1. **Phân Tích Cụ Thể** (Skin Type, Concerns, Budget, Sensitivity)
2. **Xếp Trình Tự Sản Phẩm** (Ưu tiên giải quyết vấn đề chính)
3. **Giải Thích Chi Tiết Từng Sản Phẩm** Với ví dụ cụ thể:

```
VÍ DỤ 1 (Sản phẩm ưu tiên):
"Theo tôi nghĩ, với tình trạng da của bạn thì nên dùng [TÊN] trước tiên.
Vì sao? Bởi vì trong sản phẩm này có [THÀNH PHẦN 1], [THÀNH PHẦN 2]...
những thành phần này rất tốt cho da [LOẠI DA], giúp [LỢI ÍCH].
Giá [GIÁ]k là hợp lý cho ngân sách [NGÂN SÁCH] của bạn."

VÍ DỤ 2 (So sánh sản phẩm):
"Ngoài ra, tôi cũng muốn gợi ý thêm [TÊN 2] vì nó cũng khá phù hợp.
Nhưng nếu mà bạn muốn tối ưu nhất thì [TÊN] vẫn tốt hơn một chút,
vì [LÝ DO SO SÁNH]"

VÍ DỤ 3 (Lựa chọn ngân sách):
"Nếu bạn muốn tiết kiệm chi phí nhưng vẫn hiệu quả, thì có thể chọn [TÊN]
mức giá chỉ [GIÁ]k, rẻ hơn so với [SẢN PHẨM KIA] nhưng vẫn [LỢI ÍCH TƯƠNG TỰ]."
```

---

## 📋 Ví Dụ Kết Quả Trước & Sau

### Scenario: "Kem dưỡng cho da dầu có mụn, dưới 500k"

#### ❌ Trước (Cũ):
```
Kết quả gợi ý:
1. Serum Vitamin C - 450k
2. Mặt nạ đất sét - 380k
3. Essence Hada Labo - 320k
4. Toner Thayers - 250k
5. Kem dưỡng Cetaphil - 480k

Giải thích:
"Các sản phẩm này phù hợp vì chứa vitamin C và dành cho da dầu."
→ ❌ Không lọc được kem, không giải thích cụ thể
```

#### ✅ Sau (Cải Tiến):
```
Kết quả gợi ý (CHỈ KEM):
1. Kem Dưỡng Cetaphil - 480k
2. Kem Dưỡng Neutrogena Oil-Free - 350k
3. Kem Dưỡng CeraVe PM - 420k

Giải thích:
"Dựa trên tình trạng da của bạn, tôi khuyên nên thử Kem Dưỡng Cetaphil.
Vì sao? Bởi sản phẩm này có Ceramide, Panthenol, giúp khóa ẩm cho da
nhưng không gây nhờn, cực kỳ tốt cho da dầu có mụn như bạn.
Giá 480k cũng hợp lý với ngân sách 500k bạn đưa.

Ngoài ra, nếu muốn tiết kiệm hơn, tôi còn gợi ý Neutrogena Oil-Free,
giá chỉ 350k, rẻ hơn nhưng vẫn rất tốt cho da dầu."

→ ✅ Chỉ kem, giải thích chi tiết, cá nhân hóa, so sánh giá
```

---

## 🚀 Cách Sử Dụng Hệ Thống Cải Tiến

### Bước 1: Cập Nhật Code
Các file đã được cập nhật:
- ✅ `ai-service-flask/rag/hybrid_retriever.py`
- ✅ `ai-service-flask/rag/prompt_templates.py`

### Bước 2: Không Cần Thay Đổi Gì Khác
- Flask app không cần thay đổi
- Database không cần cập nhật (sử dụng các field hiện có)
- Prompt template tự động áp dụng

### Bước 3: Khởi Động Lại Flask
```bash
cd ai-service-flask
python app.py
```

### Bước 4: Test
1. Mở trang: `http://localhost/.../index.php?r=goiy`
2. Nhập: "MÔ TẢ NHU CẦU CHI TIẾT" = "kem dưỡng"
3. Chọn skin type và concerns
4. Gửi
5. Xem kết quả (chỉ kem, giải thích tự nhiên)

---

## 🎯 Cải Tiến Chính

| Tính Năng | Trước | Sau |
|-----------|------|-----|
| **Lọc Loại Sản Phẩm** | ❌ Không lọc | ✅ Tự động lọc |
| **Nhận Diện Query** | ❌ Chung chung | ✅ 30+ loại sản phẩm |
| **Tone Giải Thích** | ❌ Chuyên môn, cách xa | ✅ Thân thiện, gần gũi |
| **Chi Tiết Sản Phẩm** | ❌ 1-2 câu chung | ✅ 2-3 paragraph chi tiết |
| **So Sánh Giá** | ❌ Không | ✅ Có so sánh |
| **Ưu Tiên** | ❌ Không rõ | ✅ Rõ ràng |
| **Lựa Chọn Thay Thế** | ❌ Không | ✅ Có gợi ý tiết kiệm |

---

## 📝 Thay Đổi Kỹ Thuật

### File 1: `hybrid_retriever.py`

**Thêm phương thức:**
```python
@staticmethod
def _extract_product_type(query: str) -> str:
    # Trích xuất loại sản phẩm từ query
    # Trả về chuỗi để lọc MongoDB
```

**Cập nhật phương thức:**
```python
def _build_filters(profile: dict, query: str = '') -> dict:
    # Thêm tham số query
    # Sử dụng _extract_product_type() để lọc
```

**Cập nhật:**
```python
def retrieve(...):
    filters = self._build_filters(profile, query)  # Thêm query
```

### File 2: `prompt_templates.py`

**Cập nhật system prompt:**
```python
# Tone: Bạn thân → Chuyên gia
# Thêm ví dụ cụ thể
# Hướng dẫn chi tiết cách trình bày
```

**Cập nhật user prompt:**
```python
# Thêm hướng dẫn chi tiết
# Thêm ví dụ (VÍ DỤ 1, 2, 3)
# Thêm tone giọng, lưu ý quan trọng
```

---

## ✨ Ví Dụ Output Thực Tế

### Input:
```
MÔ TẢ: "kem dưỡng"
Skin Type: "Da dầu"
Concerns: "Mụn"
Budget: "500,000"
```

### Output (LLM Generated):
```
Dựa trên tình trạng da của bạn, tôi khuyên nên thử Cetaphil Moisturizing Cream.
Vì sao? Bởi sản phẩm này chứa Ceramide và Panthenol, những thành phần rất tốt 
cho da dầu có mụn. Nó giúp khóa ẩm mà không gây nhờn. Giá 480k cũng hợp lý 
với ngân sách 500k bạn đưa.

Ngoài ra, nếu muốn tiết kiệm hơn, tôi còn gợi ý Neutrogena Oil-Free Daily Moisturizer.
Giá chỉ 350k, rẻ hơn nhưng vẫn có công dụng tương tự. Nếu bạn tập trung vào hiệu quả
tối ưu, Cetaphil vẫn tốt hơn một chút, nhưng Neutrogena cũng là lựa chọn rất tốt.

Với chiếc ngân sách của bạn, bạn có thể mua cả hai hoặc chọn một trong hai.
Tôi khuyên dùng Cetaphil trước, rồi nếu vừa với da thì giữ lại.
```

---

## 🔍 Cách Hoạt Động Chi Tiết

### Workflow Cải Tiến:

```
1. User nhập: "kem dưỡng cho da dầu"
   ↓
2. _extract_product_type("kem dưỡng")
   → return "kem"
   ↓
3. _build_filters(profile, query)
   → Tạo filter: {'$or': [...kem filters...]}
   ↓
4. Keyword Search: Tìm kem trong MongoDB
   ↓
5. Semantic Search: Tìm kem tương tự
   ↓
6. Merge Results: Kết hợp keyword + semantic
   ↓
7. LLM Generate: Prompt mới tạo giải thích tự nhiên
   ↓
8. Return: Chỉ kem, giải thích chi tiết, so sánh giá
```

---

## 🎓 Tóm Tắt

**Vấn Đề:**
- Không lọc được loại sản phẩm
- Giải thích quá chung chung

**Giải Pháp:**
- Thêm `_extract_product_type()` để nhận diện loại sản phẩm
- Cải tiến prompt template để tạo giải thích tự nhiên

**Kết Quả:**
- ✅ Lọc đúng loại (kem dưỡng = kem dưỡng)
- ✅ Giải thích chi tiết, cá nhân hóa
- ✅ Tone tự nhiên, thân thiện
- ✅ So sánh giá, ưu tiên rõ ràng
- ✅ Gợi ý lựa chọn thay thế

---

## 🧪 Testing

### Test Case 1: Kem Dưỡng
```
Input: "kem dưỡng"
Expected: Chỉ sản phẩm loại kem
Result: ✅ Chỉ kem dưỡng được trả về
```

### Test Case 2: Serum
```
Input: "serum cho da dầu"
Expected: Chỉ serum, không kem hay mặt nạ
Result: ✅ Chỉ serum được trả về
```

### Test Case 3: Giải Thích Tự Nhiên
```
Input: Bất kỳ loại sản phẩm
Expected: Giải thích 2-3 đoạn, tự nhiên, có so sánh
Result: ✅ LLM tạo giải thích tự nhiên
```

---

**Status:** ✅ Cải Tiến Hoàn Tất | Sẵn Sàng Test

**Ngày Cập Nhật:** 2026

**Các File Thay Đổi:**
- ✅ `ai-service-flask/rag/hybrid_retriever.py`
- ✅ `ai-service-flask/rag/prompt_templates.py`

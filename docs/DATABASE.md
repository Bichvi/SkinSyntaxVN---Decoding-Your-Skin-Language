# DATABASE - SkinSyntaxVN (MongoDB)

Tài liệu này mô tả các collection chính, trường (fields), quan hệ và ví dụ mẫu cho dự án SkinSyntaxVN. Hệ thống hiện dùng MongoDB (database mặc định: `skinsyntax`) — cấu hình tại `backend/app/config/db.php`.

## Collections chính

- `san_pham` (products)
- `thuong_hieu` (brands)
- `danh_muc` (categories)
- `xuat_xu` (origins)
- `nguoidung` (authentication users)
- `khach_hang` (customers / profiles)
- `hoa_don` (orders)
- `chi_tiet_hoa_don` (order items)
- `gio_hang` (cart items)
- `voucher` (vouchers)
- `lich_su_chat` (chat history)
- `danh_gia` (reviews)
- `lich_su_tim_kiem` (search history)
- `loai_da` (skin types)

AI / RAG related collections (ai-service-flask):
- `products` or `products_rag` (normalized product docs with `embedding` field)
- `user_profiles` (optional, used by AI service)
- `order_history` (for behavioral signals)
- `query_cache` (cached query embeddings + responses)

## Mô tả một số collection & fields quan trọng

- `san_pham` (product catalog - PHP app)
	- Fields thường gặp:
		- `ma_san_pham` (string|int): product id (primary key in app)
		- `ten_san_pham` (string)
		- `gia_ban` (int)
		- `gia_thi_truong` (int)
		- `thanh_phan_chinh`, `thanh_phan_day_du` (string)
		- `mo_ta`, `hdsd` (description, usage)
		- `link_hinh_anh` / `hinh_anh`
		- `danh_muc_day_du` (string hierarchical category path)
		- `ma_danh_muc`, `ma_thuong_hieu`, `ma_xuat_xu` (refs to other collections)
		- `trang_thai` (active/inactive)
		- `luot_xem`, `diem_danh_gia`, `so_luong_danh_gia`

- `khach_hang` (customer profile)
	- `ma_kh` (int), `ho_ten`, `email`, `so_dien_thoai`, `dia_chi`, `nam_sinh`, `gioi_tinh`, `diemtl` (loyalty points), `tinh_trang_dac_biet` (special skin state), `created_at`, `updated_at` (BSON UTCDateTime)

- `hoa_don` (orders)
	- `ma_hoa_don` (int), `ma_kh` (int ref khach_hang), `tam_tinh`, `tong_tien`, `phi_van_chuyen`, `trang_thai`, `ngay_dat`, `hinh_thuc_thanh_toan`, `status_thanh_toan`, `diem_su_dung`, `tien_giam_diem`, `ma_voucher`...

- `chi_tiet_hoa_don`
	- `id` (int), `ma_hoa_don` (int), `ma_san_pham` (string), `so_luong`, `don_gia`

- `voucher`
	- `ma_voucher`, `ma_code`, `ten_voucher`, `gia_tri_giam`, `loai_giam` (fixed/percent), `so_luong`, `so_luong_da_dung`, `ngay_bat_dau`, `ngay_ket_thuc`, `trang_thai`

## AI / RAG documents (recommended schema)

- `products` (document used by AI service)
	- `product_id` (string)
	- `name` (string)
	- `brand` (string)
	- `category` (string)
	- `price` (int)
	- `content` / `description` (long text)
	- `thanh_phan` / `key_ingredients` (string|array)
	- `embedding` (array<float>) — embedding vector
	- `metadata` (object) — arbitrary metadata (price, url, brand)

- `query_cache` (cache for semantically-similar queries)
	- `query_hash`, `query_text`, `query_embedding` (array<float>), `response`, `expires_at`, `created_at`

## Relationships (logical)

- `chi_tiet_hoa_don.ma_san_pham` -> `san_pham.ma_san_pham`
- `hoa_don.ma_kh` -> `khach_hang.ma_kh`
- `san_pham.ma_danh_muc` -> `danh_muc.ma_danh_muc`
- `san_pham.ma_thuong_hieu` -> `thuong_hieu.ma_thuong_hieu`

## Sample documents

Product (PHP `san_pham` sample):

```json
{
	"ma_san_pham": "SP001",
	"ten_san_pham": "Serum Vitamin C",
	"gia_ban": 350000,
	"thanh_phan_chinh": "Vitamin C, Hyaluronic Acid",
	"mo_ta": "Serum sáng da, giảm thâm",
	"link_hinh_anh": "/uploads/products/sp001.jpg",
	"danh_muc_day_du": "Chăm sóc da -> Serum",
	"ma_danh_muc": 12,
	"ma_thuong_hieu": 3,
	"trang_thai": "active",
	"luot_xem": 123,
	"created_at": {"$date": "2024-01-01T08:00:00Z"}
}
```

Product (RAG-ready) sample (for `products` collection):

```json
{
	"product_id": "SP001",
	"name": "Serum Vitamin C",
	"brand": "SkinBrand",
	"category": "Serum",
	"price": 350000,
	"content": "Serum Vitamin C giúp sáng da, chứa Hyaluronic Acid...",
	"key_ingredients": ["Vitamin C", "Hyaluronic Acid"],
	"embedding": [0.00123, -0.00234, 0.00456, ...],
	"metadata": {"url": "/index.php?r=chitiet&id=SP001"}
}
```

Order sample (`hoa_don`):

```json
{
	"ma_hoa_don": 1001,
	"ma_kh": 42,
	"tong_tien": 700000,
	"tam_tinh": 680000,
	"phi_van_chuyen": 20000,
	"trang_thai": "Cho xu ly",
	"ngay_dat": {"$date": "2024-04-01T10:20:00Z"}
}
```

## Indexes (đề xuất)

- Text index cho tìm kiếm sản phẩm (AI/keyword):

```js
db.products.createIndex({ ten_san_pham: "text", mo_ta: "text", thanh_phan: "text", thuong_hieu: "text" })
```

- Các chỉ mục thường dùng (MongoDB shell):

```js
db.san_pham.createIndex({ ma_san_pham: 1 })
db.san_pham.createIndex({ ten_san_pham: 1 })
db.khach_hang.createIndex({ ma_kh: 1 })
db.khach_hang.createIndex({ email: 1 })
db.hoa_don.createIndex({ ma_hoa_don: 1 })
db.chi_tiet_hoa_don.createIndex({ ma_hoa_don: 1 })
```

- Vector index: nếu dùng MongoDB Atlas Vector Search, tạo index riêng cho trường `embedding` theo hướng dẫn Atlas (hoặc chuyển sang Chroma/Weaviate/FAISS).

## Embedding fields & recommendation fields

- Embedding: lưu dưới `embedding` (mảng float) trong collection `products`/`products_rag` hoặc dưới `metadata.embedding` tuỳ config.
- Recommendation signals (fields): `key_ingredients`, `tac_dung` (benefits), `gia_ban`, `loai_san_pham`, `loai_da` (target skin), `diem_danh_gia`, `so_luong_danh_gia`.

## Chatbot collections

- `query_cache` (lưu `query_embedding` + `response`) để tránh gọi LLM lại cho các truy vấn tương tự.
- Lưu cache chat nhẹ: service có file cache `.cache/chat_responses.json` (local fallback).

---

Ghi chú: tài liệu này tóm lược từ mã nguồn hiện tại. Nếu muốn, tôi có thể sinh script `mongo_indexes.js` để tự tạo index, hoặc tạo migration để migrate SQL -> Mongo field mapping.


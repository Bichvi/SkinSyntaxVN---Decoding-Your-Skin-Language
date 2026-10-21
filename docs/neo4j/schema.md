# Neo4j — Schema đồ thị Smart Search

Neo4j (`neo4j:5.26-community`, container `skinsyntax-neo4j`) là **bản sao dẫn xuất** của MongoDB Atlas, chỉ dùng để suy luận quan hệ. Có thể xóa và dựng lại bất kỳ lúc nào; **không bao giờ ghi ngược về Mongo**. Chỉ Flask (`ai-service-flask`) kết nối (`bolt://neo4j:7687` trong mạng docker; `bolt://localhost:7687` từ máy host). PHP không kết nối.

Migration: `migrations/neo4j/*.cypher` (idempotent) — chạy `python -m graph_sync.migrate` (trong `ai-service-flask/`, hoặc `docker exec skinsyntax-ai python -m graph_sync.migrate` sau khi image có driver `neo4j`).

## Node

| Label | Thuộc tính | Nguồn Mongo |
|---|---|---|
| `Product` | `id: string`, `name`, `price`, `original_price`, `sold`, `rating`, `in_stock: bool`, `active: bool`, `updated_at` | `san_pham`: `id` = `ma_san_pham` (int/long trong Mongo → **lưu string**), `sold` = `so_luong_da_ban`, `updated_at` = `updated_at` |
| `Brand` | `key`, `name` | `ma_thuong_hieu` → `thuong_hieu.ten_thuong_hieu` (SP không có trường `thuong_hieu`) |
| `Category` | `key`, `name`, `level` (1..3) | `ma_danh_muc` → `danh_muc` (level 1→3, lá + cha). Mã mồ côi (~13% fixture) → tách `danh_muc_day_du` theo `->`, bỏ gốc "Sức Khỏe - Làm Đẹp" (Bước 03) |
| `Ingredient` | `key`, `name`, `inci` (null = chưa map alias), `name_vi`, `role` (active/uv_filter/humectant/emollient/base/preservative/fragrance) | tách `thanh_phan_full` (ưu tiên) / `thanh_phan_sach` |
| `SkinType` | `key`, `name` | `ma_loai_da` → `loai_da`; mã 4 = Unknown → **không tạo cạnh** |
| `Concern` | `key`, `name` | suy luận (không có trường công dụng trong SP) |
| `Benefit` | `key`, `name` | suy luận |
| `Origin` *(tùy chọn)* | `key`, `name` | `ma_xuat_xu` → `xuat_xu` |

## Quan hệ

- `(Product)-[:OF_BRAND]->(Brand)`
- `(Product)-[:IN_CATEGORY]->(Category)` (gắn vào lá), `(Category)-[:CHILD_OF]->(Category)` (level 3→2→1)
- `(Product)-[:CONTAINS {rank:int}]->(Ingredient)` — rank = thứ tự trong bảng thành phần, 1 = nhiều nhất
- `(Product)-[:SUITABLE_FOR {source:'data'|'inferred'}]->(SkinType)`, `(Product)-[:TARGETS {source:'inferred'}]->(Concern)`, `(Product)-[:HAS_BENEFIT {source:'inferred'}]->(Benefit)`
- `Product` có thêm `ingredient_source` (`full`/`sach`/null), `category_source` (`danh_muc`/`path`). `IN_CATEGORY` chỉ gắn vào **lá**; lọc theo danh mục cha phải đi qua `CHILD_OF`.
- `(Product)-[:MADE_IN]->(Origin)` *(tùy chọn)*
- `(Ingredient)-[:HELPS]->(Concern)`, `(Ingredient)-[:CONFLICTS_WITH {severity, reason}]-(Ingredient)` (lưu 1 chiều, truy vấn không hướng)
- `(Product)-[:SIMILAR_TO {score, reason, shared:list}]->(Product)` — Bước 03 (`reason`: `shared_ingredients` | `same_category` | `same_brand`)
- `(Product)-[:CO_PURCHASED {count, updated_at}]->(Product)` — Bước 08 (`python -m graph_sync --derive co_purchase --days 90`: đơn `Hoàn thành` trong N ngày, bỏ đơn > 20 SP, giữ cặp `count ≥ 2`, top 20/SP, xóa hết rồi ghi lại)

```
                 (Brand)          (Origin)?
                    ^                ^
               OF_BRAND           MADE_IN
                    |                |
 (Category)<-IN_CATEGORY-(Product)---+--SUITABLE_FOR-->(SkinType)
    |  ^                 |  |  |  \
 CHILD_OF             TARGETS | HAS_BENEFIT   SIMILAR_TO / CO_PURCHASED
    v                    |   |      \              \
 (Category)              v   |    (Benefit)       (Product)
                  (Concern)  CONTAINS{rank}
                      ^      v
                      +-HELPS-(Ingredient)-CONFLICTS_WITH-(Ingredient)
```

## Quy tắc `key`

1. chữ thường → 2. bỏ dấu tiếng Việt (`đ`→`d`) → 3. ký tự không phải chữ/số thành `_` → 4. gộp `_` liên tiếp → 5. trim `_` → 6. áp từ điển đồng nghĩa.

Ví dụ: `Sữa Rửa Mặt` → `sua_rua_mat`; `SRM`, `cleanser`, `gel rửa mặt` → `sua_rua_mat`. `Product` dùng `id` (= `ma_san_pham` dạng chuỗi), không dùng `key`.

## Ràng buộc / index (001_constraints.cypher)

7 unique constraint: `product_id`, `brand_key`, `category_key`, `ingredient_key`, `skintype_key`, `concern_key`, `benefit_key`. Index: `product_active`, `product_sold`, fulltext `entity_name` (Product|Brand|Category|Ingredient `name`).

## Cypher mẫu

```cypher
// 1. Đếm SP theo hãng
MATCH (p:Product {active:true})-[:OF_BRAND]->(b:Brand)
RETURN b.name AS brand, count(p) AS products ORDER BY products DESC LIMIT 20;

// 2. SP cùng danh mục, chung >= 2 thành phần với SP $id
MATCH (p:Product {id:$id})-[:IN_CATEGORY]->(c:Category)<-[:IN_CATEGORY]-(q:Product {active:true})
WHERE q <> p
MATCH (p)-[:CONTAINS]->(i:Ingredient)<-[:CONTAINS]-(q)
WITH q, collect(i.name_vi) AS shared WHERE size(shared) >= 2
RETURN q.id, q.name, shared ORDER BY size(shared) DESC LIMIT 10;

// 3. Kiểm tra xung đột hoạt chất giữa 2 SP
MATCH (a:Product {id:$a})-[:CONTAINS]->(i:Ingredient)-[r:CONFLICTS_WITH]-(j:Ingredient)<-[:CONTAINS]-(b:Product {id:$b})
RETURN i.inci, j.inci, r.severity, r.reason;

// 4. SP hợp loại da
MATCH (p:Product {active:true})-[:SUITABLE_FOR]->(:SkinType {key:$skin})
RETURN p.id, p.name ORDER BY p.sold DESC LIMIT 20;

// 5. SIMILAR_TO của 1 SP
MATCH (:Product {id:$id})-[s:SIMILAR_TO]->(q:Product {active:true})
RETURN q.id, q.name, s.score, s.reason, s.shared ORDER BY s.score DESC LIMIT 10;
```

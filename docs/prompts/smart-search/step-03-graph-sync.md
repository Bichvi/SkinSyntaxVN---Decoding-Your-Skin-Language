# BƯỚC 03 — ĐỒNG BỘ MONGODB → NEO4J (ETL)

## Vai trò
Kỹ sư dữ liệu Python. Viết pipeline ETL đọc sản phẩm từ MongoDB Atlas (**chỉ đọc**), chuẩn hóa, nạp vào Neo4j theo lô, tính quan hệ tương đồng giữa sản phẩm.

## Đọc trước
1. `AGENTS.md`
2. `docs/plans/smart-search-handoff.md` (tên collection, **tên trường thật**, % độ phủ, env Neo4j, lệnh migrate)
3. `docs/neo4j/schema.md` (node, quan hệ, quy tắc key) — đây là hợp đồng, làm đúng theo nó
4. `ai-service-flask/recommendation_service/conflict_checker.py` và `knowledge/` (nguồn xung đột hoạt chất)
5. `ai-service-flask/recommendation_service/product_repository.py` (cách code hiện tại kết nối Mongo — tái sử dụng cấu hình/kết nối)

## Bối cảnh
- Mongo là nguồn sự thật. Neo4j là bản sao. Sản phẩm bị ẩn/xóa ở Mongo → `active=false` ở Neo4j (không DELETE node, để giữ lịch sử quan hệ).
- Kết quả của bước này được dùng bởi Bước 04 (từ điển entity) và Bước 05 (truy vấn gợi ý).
- Package `ai-service-flask/graph_sync/` đã tồn tại từ Bước 02 (có `migrate.py`).

## Cấu trúc code cần tạo
```
ai-service-flask/
  graph_sync/
    config.py          # đọc env: MONGO_*, NEO4J_*, BATCH_SIZE=500, SIMILAR_TOP_K=10
    normalize.py       # normalize_key(), strip_accents(), load_synonyms()
    ingredients.py     # parse_ingredients(raw) -> list[IngredientRef(key, inci, rank)]
    rules.py           # infer_tags(ingredient_keys, product) -> {benefits, concerns, skin_types}
    extract.py         # iter_products(since=None) -> Iterator[dict]  (Mongo, chỉ đọc, projection tối thiểu)
    transform.py       # to_graph_row(doc) -> dict (thuần, không I/O)
    load.py            # GraphLoader(driver).upsert_batch(rows), deactivate_missing(ids), load_conflicts(pairs)
    similarity.py      # compute_similar(products) -> list[edge]  (thuần, không I/O)
    report.py          # thống kê độ phủ, top thành phần không map được (chỉ đếm)
    __main__.py        # CLI
  data/graph/
    synonyms.yaml            # nhóm đồng nghĩa danh mục / loại SP / loại da / vấn đề da
    ingredient_aliases.yaml  # tên thường gặp → INCI chuẩn (vd "HA", "axit hyaluronic" → hyaluronic_acid)
    ingredient_rules.yaml    # INCI → benefit/concern/skin_type
  tests/graph_sync/
    test_normalize.py  test_ingredients.py  test_rules.py  test_transform.py  test_similarity.py
```

## Yêu cầu chi tiết

### Extract
- `pymongo`, `find(filter, projection)` chỉ lấy trường cần; `batch_size(500)`; không `$out`/`$merge`/ghi.
- `--since ISO8601`: lọc theo trường cập nhật thật (xem handoff); nếu không có trường cập nhật → `--since` báo lỗi rõ ràng và chỉ hỗ trợ `--full`.
- Xác định "đang bán" giống `SanPham::availableProductFilter()` của PHP (đọc PHP để sao chép điều kiện, ghi chú nguồn).

### Transform
- `normalize_key(text)`: lower → bỏ dấu (`unidecode` hoặc bảng thủ công, `đ`→`d`) → `[^a-z0-9]+`→`_` → trim `_` → tra `synonyms.yaml`.
- Brand: chuẩn hóa cách viết khác nhau về một key (`TORRIDEN`, `Torriden ` → `torriden`), `name` giữ dạng hiển thị phổ biến nhất.
- Category: dùng `ma_danh_muc` + cây danh mục; tạo `CHILD_OF` cấp 2 → cấp 1.
- Ingredients: nếu là chuỗi → tách theo `,` `;` `/` `\n`, bỏ ngoặc, bỏ nồng độ (`2%`), trim; map qua `ingredient_aliases.yaml`; không map được → vẫn tạo key từ normalize, đánh dấu `inci=null`, đếm vào báo cáo. `rank` = vị trí (1-based), chỉ giữ 30 thành phần đầu.
- Tags: lấy từ trường có sẵn trước; thiếu → `infer_tags` theo `ingredient_rules.yaml`, chỉ xét 15 thành phần đầu. **Không dùng LLM.** Ví dụ rule:
  ```yaml
  hyaluronic_acid: {benefits: [cap_am], concerns: [kho_da], skin_types: [da_kho, moi_loai_da]}
  panthenol:       {benefits: [phuc_hoi, cap_am]}
  centella_asiatica_extract: {benefits: [lam_diu], concerns: [mun, kich_ung]}
  salicylic_acid:  {benefits: [lam_sach_sau], concerns: [mun, lo_chan_long], skin_types: [da_dau]}
  niacinamide:     {benefits: [sang_da], concerns: [tham_mun, dau_thua]}
  ```
  Bắt đầu với ~40 hoạt chất phổ biến nhất (lấy từ thống kê thực tế), ghi chú nguồn tham khảo.

### Load
- Mỗi lô 500 dòng, một transaction `session.execute_write`, Cypher **có tham số**:
  ```cypher
  UNWIND $rows AS r
  MERGE (p:Product {id: r.id})
  SET p += r.props, p.active = true
  WITH p, r
  MERGE (b:Brand {key: r.brand.key}) ON CREATE SET b.name = r.brand.name
  MERGE (p)-[:OF_BRAND]->(b)
  WITH p, r
  OPTIONAL MATCH (p)-[old:CONTAINS|SUITABLE_FOR|TARGETS|HAS_BENEFIT|IN_CATEGORY]->()
  DELETE old
  WITH DISTINCT p, r
  FOREACH (i IN r.ingredients | MERGE (n:Ingredient {key: i.key}) ON CREATE SET n.inci = i.inci, n.name = i.name
                                MERGE (p)-[:CONTAINS {rank: i.rank}]->(n))
  // tương tự category, skin_types, concerns, benefits
  ```
  (Cập nhật quan hệ của SP bằng cách xóa quan hệ cũ rồi tạo lại — đảm bảo idempotent.)
- `--full`: sau khi nạp, `MATCH (p:Product) WHERE NOT p.id IN $seen_ids SET p.active=false`.
- Xung đột: đọc nguồn có sẵn (không tự bịa), MERGE `CONFLICTS_WITH {severity, reason}` (vô hướng, chỉ tạo một chiều theo key nhỏ hơn).
- `HELPS`: từ `ingredient_rules.yaml` (`concerns`).
- Redis lock `graph_sync:lock` (SET NX EX 1800) — đang có job khác chạy thì thoát với mã lỗi rõ ràng. Redis không có → cảnh báo và chạy tiếp.

### Similarity (chạy sau Load, trên dữ liệu đã transform trong RAM)
```
score(a,b) = 0.40·Jaccard(ingredients top-15)
           + 0.25·(cùng category cấp 2 ? 1 : cùng cấp 1 ? 0.5 : 0)
           + 0.15·Jaccard(benefits)
           + 0.10·Jaccard(skin_types)
           + 0.10·(cùng brand)
```
- Chỉ so trong các SP active; để tránh O(n²) quá lớn: tạo chỉ mục ngược ingredient → SP, chỉ so các cặp chung ≥ 1 thành phần hoặc cùng category.
- Giữ top `SIMILAR_TOP_K=10`, ngưỡng score ≥ 0.2. Xóa `SIMILAR_TO` cũ của SP rồi ghi mới.
- `reason`: `"shared_ingredients"` nếu có ≥ 2 hoạt chất chung (lưu `shared` = tối đa 3 tên hiển thị), ngược lại `"same_category"`.

### CLI
```
python -m graph_sync --full              # nạp toàn bộ + similarity + deactivate
python -m graph_sync --since 2026-10-01T00:00:00Z
python -m graph_sync --dry-run           # không ghi Neo4j, chỉ in báo cáo
python -m graph_sync --similarity-only
```
In báo cáo: số SP đọc, số node/quan hệ theo loại, % SP có ≥ 3 thành phần, % có skin_type (gốc vs suy luận), top 30 thành phần không map được (chỉ tên + số lần), thời gian chạy.

## Quy tắc
- Mongo **chỉ đọc**. Không log dữ liệu cá nhân, chuỗi kết nối, mật khẩu.
- Dùng `logging`, không `print` trong module (trừ `report.py`/CLI).
- Hàm transform/similarity **thuần** (không I/O) để test dễ.
- Type hints đầy đủ.

## Kiểm thử
- TDD: viết test trước cho `normalize_key`, synonyms, `parse_ingredients` (chuỗi có `%`, ngoặc, xuống dòng, trùng lặp, rỗng), `infer_tags`, `to_graph_row`, công thức similarity (ca tay đã tính sẵn kết quả), top-k, ngưỡng.
- Fixture giả, **không kết nối Mongo/Neo4j thật trong test**.
- Chạy pytest theo lệnh trong handoff.

## Kiểm chứng (chạy thật, dán output)
1. `python -m graph_sync --dry-run` → báo cáo.
2. **Nếu % SP có thành phần < 60%: DỪNG**, báo người dùng, đề xuất cách bổ sung dữ liệu, không chạy `--full`.
3. `python -m graph_sync --full` → báo cáo; chạy lại lần 2 → số node/quan hệ **không đổi**.
4. So khớp: `MATCH (p:Product {active:true}) RETURN count(p)` = số SP đang bán ở Mongo; `MATCH (:Brand {key:'torriden'})<-[:OF_BRAND]-(p:Product {active:true}) RETURN count(p)` = số SP Torriden đang bán ở Mongo.
5. Mẫu chất lượng: lấy 1 sữa rửa mặt Torriden, in `SIMILAR_TO` top 5 kèm reason — kiểm tra bằng mắt có hợp lý không.

## Đầu ra
Code + YAML + test. Mục "Bước 03" trong handoff: lệnh chạy, thời gian chạy, số liệu độ phủ, các key danh mục chuẩn (vd `sua_rua_mat`), danh sách key loại da/vấn đề da/benefit đang có (Bước 04, 07 cần), điểm yếu dữ liệu. Không commit nếu chưa được yêu cầu.

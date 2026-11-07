# BƯỚC 02 — DỰNG NEO4J + SCHEMA ĐỒ THỊ

## Vai trò
Kỹ sư hạ tầng/dữ liệu. Thêm Neo4j vào Docker Compose, thiết kế schema và migration. **Chưa nạp dữ liệu.**

## Đọc trước
1. `AGENTS.md`
2. `docs/plans/smart-search-handoff.md`
3. `docs/plans/2026-10-21-neo4j-search-audit.md` mục 2 (tên trường thật), 6 (docker-compose, .env.example, .gitignore)
4. `docker-compose.yml`, `.env.example`, `.gitignore`

## Bối cảnh
- Neo4j là **bản sao dẫn xuất** của MongoDB Atlas, dùng để suy luận quan hệ: SP ↔ hãng ↔ danh mục ↔ thành phần ↔ loại da ↔ vấn đề da ↔ xung đột hoạt chất. Có thể xóa và dựng lại bất kỳ lúc nào. Không bao giờ ghi ngược về Mongo.
- Chỉ Flask (`ai-service-flask`) kết nối Neo4j. PHP không kết nối.
- Đã có ChromaDB, Redis trong compose — theo đúng phong cách các service đó (container_name `skinsyntax-*`, healthcheck, volume có tên).

## Yêu cầu

### 1. Docker Compose
Thêm service (điều chỉnh theo phong cách file hiện có):
```yaml
neo4j:
  image: neo4j:5.26-community        # ghim phiên bản cụ thể, không dùng tag trôi
  container_name: skinsyntax-neo4j
  restart: unless-stopped
  environment:
    NEO4J_AUTH: ${NEO4J_USER:-neo4j}/${NEO4J_PASSWORD:?NEO4J_PASSWORD is required}
    NEO4J_PLUGINS: '["apoc"]'
    NEO4J_server_memory_heap_initial__size: 512m
    NEO4J_server_memory_heap_max__size: 1G
    NEO4J_server_memory_pagecache_size: 512m
    NEO4J_dbms_security_procedures_unrestricted: apoc.*
  ports:
    - "127.0.0.1:7474:7474"        # Browser chỉ cho máy local
    - "127.0.0.1:7687:7687"        # Bolt (dev); trong network các service dùng neo4j:7687
  volumes:
    - neo4j_data:/data
    - neo4j_logs:/logs
  healthcheck:
    test: ["CMD-SHELL", "cypher-shell -u $${NEO4J_USER:-neo4j} -p $${NEO4J_PASSWORD} 'RETURN 1' >/dev/null 2>&1 || exit 1"]
    interval: 15s
    timeout: 10s
    retries: 10
    start_period: 40s
```
- Khai báo `neo4j_data`, `neo4j_logs` ở khối `volumes:` gốc.
- Service Flask đang chạy thật (theo audit) thêm env `NEO4J_URI=bolt://neo4j:7687`, `NEO4J_USER`, `NEO4J_PASSWORD`; **không** thêm `depends_on` cứng kiểu `service_healthy` (Flask phải sống khi Neo4j chết) — dùng `depends_on` thường hoặc không.
- `.env.example`: thêm `NEO4J_URI=bolt://localhost:7687`, `NEO4J_USER=neo4j`, `NEO4J_PASSWORD=` (để trống). **Không ghi mật khẩu thật ở đâu cả.** Nhắc người dùng tự đặt mật khẩu trong `.env` (≥ 8 ký tự).
- `.gitignore`: đảm bảo không commit dữ liệu Neo4j nếu có bind mount.

### 2. Schema — viết `docs/neo4j/schema.md`
Node (thuộc tính):
- `Product {id: string (= ma_san_pham), name, price, original_price, sold, rating, in_stock: bool, active: bool, updated_at}`
- `Brand {key, name}` · `Category {key, name, level}` · `Ingredient {key, inci, name_vi, role}`
- `SkinType {key, name}` · `Concern {key, name}` · `Benefit {key, name}`

Quan hệ:
- `(Product)-[:OF_BRAND]->(Brand)`
- `(Product)-[:IN_CATEGORY]->(Category)`, `(Category)-[:CHILD_OF]->(Category)`
- `(Product)-[:CONTAINS {rank:int}]->(Ingredient)` — rank = thứ tự trong bảng thành phần, 1 = nhiều nhất
- `(Product)-[:SUITABLE_FOR]->(SkinType)`, `(Product)-[:TARGETS]->(Concern)`, `(Product)-[:HAS_BENEFIT]->(Benefit)`
- `(Ingredient)-[:HELPS]->(Concern)`, `(Ingredient)-[:CONFLICTS_WITH {severity, reason}]-(Ingredient)`
- `(Product)-[:SIMILAR_TO {score, reason, shared:list}]->(Product)` (tính ở Bước 03)
- `(Product)-[:CO_PURCHASED {count}]->(Product)` (Bước 08)

Quy tắc `key`: chữ thường → bỏ dấu tiếng Việt (`đ`→`d`) → ký tự không phải chữ/số thành `_` → gộp `_` liên tiếp → trim `_` → áp từ điển đồng nghĩa. Ví dụ: `Sữa Rửa Mặt` → `sua_rua_mat`; `SRM`, `cleanser`, `gel rửa mặt` → `sua_rua_mat`.

Trong file schema, thêm sơ đồ ASCII, 5 câu Cypher mẫu (đếm SP theo hãng, SP cùng danh mục chung ≥ 2 thành phần, kiểm tra xung đột giữa 2 SP, SP hợp loại da, SIMILAR_TO của 1 SP).

### 3. Migration
- `migrations/neo4j/001_constraints.cypher` (idempotent):
  ```cypher
  CREATE CONSTRAINT product_id IF NOT EXISTS FOR (n:Product) REQUIRE n.id IS UNIQUE;
  CREATE CONSTRAINT brand_key IF NOT EXISTS FOR (n:Brand) REQUIRE n.key IS UNIQUE;
  CREATE CONSTRAINT category_key IF NOT EXISTS FOR (n:Category) REQUIRE n.key IS UNIQUE;
  CREATE CONSTRAINT ingredient_key IF NOT EXISTS FOR (n:Ingredient) REQUIRE n.key IS UNIQUE;
  CREATE CONSTRAINT skintype_key IF NOT EXISTS FOR (n:SkinType) REQUIRE n.key IS UNIQUE;
  CREATE CONSTRAINT concern_key IF NOT EXISTS FOR (n:Concern) REQUIRE n.key IS UNIQUE;
  CREATE CONSTRAINT benefit_key IF NOT EXISTS FOR (n:Benefit) REQUIRE n.key IS UNIQUE;
  CREATE INDEX product_active IF NOT EXISTS FOR (n:Product) ON (n.active);
  CREATE INDEX product_sold IF NOT EXISTS FOR (n:Product) ON (n.sold);
  CREATE FULLTEXT INDEX entity_name IF NOT EXISTS FOR (n:Product|Brand|Category|Ingredient) ON EACH [n.name];
  ```
- Runner Python `ai-service-flask/graph_sync/migrate.py` (tạo package `graph_sync/` với `__init__.py`): đọc env, mở `neo4j.GraphDatabase.driver`, đọc lần lượt file `migrations/neo4j/*.cypher` theo tên, tách câu theo `;`, chạy từng câu; log tên file. Thêm `neo4j>=5.20,<6` vào requirements đang dùng thật (theo audit).
- Lệnh: `python -m graph_sync.migrate`.

## Quy trình
1. `git status`. 2. Dùng brainstorming ngắn nếu có lựa chọn đáng cân nhắc (phiên bản, RAM). 3. Sửa compose/env/gitignore. 4. Viết schema + migration + runner. 5. Kiểm chứng.

## Kiểm chứng (chạy thật, dán output)
- `docker compose config` hợp lệ.
- `docker compose up -d neo4j` → `docker ps` thấy `healthy` (đợi tối đa ~2 phút).
- `python -m graph_sync.migrate` chạy **2 lần** liên tiếp, đều không lỗi.
- `cypher-shell` hoặc driver: `SHOW CONSTRAINTS` có 7, `SHOW INDEXES` có `product_active`, `product_sold`, `entity_name`.
- Các service cũ (chromadb, redis, flask) vẫn khởi động bình thường.
- `rg -n "NEO4J_PASSWORD=" .` không thấy giá trị thật trong file được git theo dõi.

## Đầu ra
Compose, `.env.example`, `.gitignore`, `docs/neo4j/schema.md`, `migrations/neo4j/001_constraints.cypher`, `ai-service-flask/graph_sync/{__init__,migrate}.py`, requirements. Thêm mục "Bước 02" vào handoff (phiên bản Neo4j, tên env, lệnh migrate, RAM đã cấp). Không commit nếu chưa được yêu cầu.

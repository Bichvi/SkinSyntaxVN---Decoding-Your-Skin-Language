# Admin Category Data Flow Trace

## 1. Route Entry & Dispatching

- **HTTP Request**: `GET /index.php?r=admin_categories`
- **Public Router**: `index.php` (lines 498-500)
  ```php
  case 'admin_categories':
      (new QuanTriController($pdo))->adminCategories();
      break;
  ```
- **Global DB Injection**:
  - `index.php` includes `backend/app/config/db.php` which initializes global `$pdo` (an instance of `MongoDatabaseCompat`) and `$db` (`\MongoDB\Database`).
  - `QuanTriController::__construct($pdo)` resolves `$mongoDb`:
    ```php
    global $db;
    $mongoDb = $db ?? (is_object($pdo) && method_exists($pdo, 'raw') ? $pdo->raw() : $pdo);
    $this->pdo = $pdo;
    $this->model = new QuanTri($mongoDb);
    ```

---

## 2. Controller Execution

- **Controller File**: `backend/app/controllers/QuanTriController.php` (lines 351-360)
  ```php
  public function adminCategories(): void {
      $this->requireRole(['admin']);
      $q = trim((string)($_GET['q'] ?? ''));
      $editId = max(0, (int)($_GET['edit'] ?? 0));
      $this->renderAdmin('categories', [
          'items' => $this->model->listCategories($q),
          'editing' => $editId > 0 ? $this->model->getCategoryById($editId) : null,
          'q' => $q,
      ]);
  }
  ```

---

## 3. Model & MongoDB Query Execution

- **Model File**: `backend/app/models/QuanTri.php` (method `listCategories(string $keyword = '')`, lines 656-785)
- **Database Connection**: `$this->db` (resolved from `backend/app/config/db.php`)
- **Query 1 (Categories fetch)**:
  ```php
  $cursor = $this->db->danh_muc->find([]);
  ```
- **Query 2 (Direct product counts aggregation)**:
  ```php
  $countCursor = $this->db->san_pham->aggregate([
      ['$group' => ['_id' => '$ma_danh_muc', 'so_luong' => ['$sum' => 1]]]
  ]);
  ```
- **Tree Hierarchy Assembly**:
  - Organizes records by `parent_id` into `$byId`, `$children`, and `$roots`.
  - Calculates recursive product counts by summing direct counts of descendant leaf nodes.
  - Sorts root nodes and child lists by `thu_tu_hien_thi`.

---

## 4. View Rendering

- **View File**: `frontend/views/admin/categories.php`
- **Rendered Elements**:
  - Form: Create/Update category with parent dropdown selector (`parent_id`).
  - Table: Lists categories hierarchically with:
    - Code (`#ma_danh_muc`)
    - Name with tree prefixes (`├─ `, `└── `) and level badges (`Leaf`, `Nhóm (N con)`)
    - Level (`Cấp 1`, `Cấp 2`, `Cấp 3`)
    - Parent category name
    - Product counts (`Trực tiếp / Tổng`)
    - Actions (`Sửa`, `Xóa`)

---

## 5. Why Admin Shows 125 Categories

1. **Active Database in Runtime**:
   When requests run through the Docker container (`skinsyntax-php`), `backend/app/config/db.php` checks:
   ```php
   if (file_exists('/.dockerenv') && str_starts_with($mongoUri, 'mongodb+srv://') && getenv('DOCKER_USE_ATLAS') !== '1') {
       $dockerLocalUri = 'mongodb://mongodb:27017';
       ...
       $mongoUri = $dockerLocalUri;
   }
   ```
   Because `/.dockerenv` is present and `DOCKER_USE_ATLAS` is unset, `$mongoUri` is hijacked to `mongodb://mongodb:27017` (the local MongoDB container `skinsyntax-mongo`).
2. **Local DB State**:
   In `skinsyntax-mongo`, collection `danh_muc` still contains the **legacy 125 flat categories** (all having `parent_id = null`), and `san_pham` contains 6,377 legacy products.
3. **Contrast with Canonical Atlas**:
   In MongoDB Atlas, `danh_muc` contains **28 canonical categories** in a clean 3-level tree, and `san_pham` contains **2,473 skincare products** with zero orphans. When connected to Atlas, Admin correctly displays the 28-category taxonomy tree.

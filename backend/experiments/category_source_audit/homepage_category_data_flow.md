# Homepage Category Data Flow Trace

## 1. Route Entry & Dispatching

- **HTTP Request**: `GET /index.php?r=home` (or `GET /index.php`)
- **Public Router**: `index.php` (lines 83-85)
  ```php
  case 'home':
      (new HomeController($pdo))->index();
      break;
  ```

---

## 2. Controller Execution (`HomeController::index`)

- **File**: `backend/app/controllers/HomeController.php` (lines 134-212)
- **Data Load**:
  ```php
  $latest = $this->model->latest(12, true, true);
  $cats = $this->getHighlightedCategories();
  $homepageSections = $this->model->getHomepageProductSections(...);
  ...
  $this->render('home', [
      'latest' => $latest,
      'cats' => $cats,
      'homepageSections' => $homepageSections,
      ...
  ]);
  ```

---

## 3. The Two Category Data Paths on Homepage

### Path A: `getHighlightedCategories()` (Controller-level)
- **Method**: `HomeController::getHighlightedCategories()` (lines 422-472)
- **Mechanism**:
  - Does NOT query `danh_muc`.
  - Runs MongoDB aggregation directly on `san_pham.danh_muc_day_du`:
    ```php
    $pipeline = [
        ['$match' => ['danh_muc_day_du' => ['$nin' => [null, '']]]],
        ['$group' => ['_id' => '$danh_muc_day_du', 'so_luong' => ['$sum' => 1]]],
        ['$sort' => ['so_luong' => -1]],
        ['$limit' => 6]
    ];
    ```
  - Caches to `backend/storage/cache/highlighted_categories.json`.
- **Usage in `frontend/views/home.php`**:
  - In `home.php`, `$cats` is received at line 4: `$cats = isset($cats) && is_array($cats) ? $cats : [];`
  - **Critical Finding**: `$cats` is **NEVER used or rendered anywhere** in `home.php`. The category shortcuts section in `home.php` (lines 82-139, 465-487) uses a hardcoded PHP array `$skinConcernCategories` (Da dầu & mụn, Da nhạy cảm, Cấp ẩm, Phục hồi, Chống nắng, Thâm / xỉn màu, Chống lão hóa, Se lỗ chân lông).

### Path B: Layout Navigation Mega Menu (`$menuCats`)
- **Method**: In `HomeController::render()` (lines 121-127):
  ```php
  $menuCats = $this->model->menuTree();
  ```
- **Method Implementation**: `SanPham::menuTree()` in `backend/app/models/SanPham.php` (lines 582-645):
  - Fetches category hierarchy from `$this->db->danh_muc` via `getCanonicalCategoryHierarchy()`.
  - Aggregates direct product counts:
    ```php
    $countCursor = $this->db->san_pham->aggregate([
        ['$match' => $this->availableProductFilter()],
        ['$group' => ['_id' => '$ma_danh_muc', 'so_luong' => ['$sum' => 1]]]
    ]);
    ```
  - For each root category in `$roots`, collects its Level 2 children from `$children[$rootId]`.
  - Calculates product count sums for each child node.
  - Builds associative tree: `$tree[$c1Name] = $c2Map`.
- **View Rendering**: `frontend/views/layouts/header.php` (lines 146-188):
  ```html
  <div class="dropdown-menu mega-menu header-mega-menu p-3">
    <div class="row g-3 align-items-stretch">
      <div class="col-lg-3">
        <!-- Left Panel: Mega Menu Highlight -->
        <div class="mega-menu-highlight">
          <span class="mega-menu-highlight__eyebrow">Danh mục nổi bật</span>
          <h5>Khám phá từng nhóm skincare theo đúng nhu cầu da.</h5>
          <p>Từ làm sạch, dưỡng ẩm đến đặc trị...</p>
        </div>
      </div>
      <div class="col-lg-9">
        <!-- Right Panel: Category Tree -->
        <div class="row g-3">
          <?php foreach ($menuCats as $c1 => $c2s): ?>
            <div class="col-6 col-lg-4">
              <div class="col-title"><?= h($c1) ?></div>
              ...
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  ```

---

## 4. Root Cause of the Empty/White Right Panel

1. **Under Local MongoDB (Current Docker Runtime)**:
   - All 125 documents in `danh_muc` have `parent_id = null`.
   - All 125 categories are classified as root nodes (`$roots`).
   - For every root node, `$children[$rootId]` is empty (`[]`).
   - Therefore, `$c2Map` is empty for all nodes.
   - `SanPham::menuTree()` returns `[]` (empty array).
   - In `header.php`, `foreach ($menuCats as $c1 => $c2s)` does not iterate.
   - `<div class="col-lg-9">` renders with zero inner items, resulting in a **completely white, blank right panel**.

2. **Under Atlas MongoDB (Canonical 28 Categories)**:
   - Atlas contains 1 root node: `#1001` ("Chăm Sóc Da Mặt").
   - `SanPham::menuTree()` returns:
     ```php
     [
       "Chăm Sóc Da Mặt" => [
         "Mặt Nạ" => 622,
         "Làm Sạch Da" => 613,
         "Chống Nắng Da Mặt" => 231,
         "Dưỡng Ẩm" => 313,
         "Bộ Chăm Sóc Da Mặt" => 211,
         "Đặc Trị" => 275,
         "Dưỡng Mắt" => 27,
         "Dưỡng Môi" => 181
       ]
     ]
     ```
   - In `header.php`, there is only 1 top key (`"Chăm Sóc Da Mặt"`), so it renders only **one single column** (`col-6 col-lg-4`).
   - In a 12-column grid (`col-lg-9`), a single `col-lg-4` takes only 1/3 of the space, leaving 2/3 of the right panel blank.

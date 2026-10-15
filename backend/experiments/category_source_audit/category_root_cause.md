# Category Source of Truth — Root Cause Analysis

## Executive Summary

The audit has uncovered **two distinct root causes**:
1. **Infrastructure / Connection Hijack**: When PHP runs inside the Docker container (`skinsyntax-php`), `backend/app/config/db.php` unconditionally hijacks the MongoDB connection away from MongoDB Atlas to the local container MongoDB (`mongodb://mongodb:27017`). The local container database contains unmigrated legacy data (6,377 products and 125 legacy flat categories), whereas Atlas contains the clean, standardized canonical skincare dataset (2,473 products and 28 hierarchical categories).
2. **Taxonomy Structure vs UI Rendering Mismatch**:
   - On **Local MongoDB**: None of the 125 categories have a `parent_id`. `SanPham::menuTree()` requires a parent-child hierarchy to generate level 2 items; because all 125 categories are root nodes with zero children, `menuTree()` returns `[]` (empty array). The right-side category block in the mega menu renders completely blank.
   - On **Atlas MongoDB**: There is exactly 1 root node (`#1001` - Chăm Sóc Da Mặt) with 8 level-2 groups/leaves. In `header.php`, the code renders one grid column per root node (`foreach ($menuCats as $c1 => $c2s)`). With only 1 root node, only 1 column of 4 grid units is created inside a 12-unit container, leaving 2/3 of the right panel blank.

---

## Detailed Root Cause 1: Connection Hijack in `backend/app/config/db.php`

In `backend/app/config/db.php` (lines 61-71):
```php
// If running inside Docker container with Atlas URI, prefer local container mongodb:27017 for instant speed and to avoid Docker DNS SRV timeouts
if (file_exists('/.dockerenv') && str_starts_with($mongoUri, 'mongodb+srv://') && getenv('DOCKER_USE_ATLAS') !== '1') {
    $dockerLocalUri = 'mongodb://mongodb:27017';
    try {
        $testClient = new MongoDB\Client($dockerLocalUri, [], ['serverSelectionTimeoutMS' => 1000, 'connectTimeoutMS' => 1000]);
        $testClient->selectDatabase($mongoDbName)->command(['ping' => 1]);
        $mongoUri = $dockerLocalUri;
    } catch (Throwable $e) {
        // Fall back to original URI if local container is down
    }
}
```

### Chain of Events:
1. The `.env` file specifies `MONGO_URI=mongodb+srv://...` (pointing to MongoDB Atlas).
2. The web application runs in Docker (`skinsyntax-php`), so `file_exists('/.dockerenv')` evaluates to `true`.
3. The environment variable `DOCKER_USE_ATLAS` is not set (`getenv('DOCKER_USE_ATLAS') !== '1'`).
4. The local container `skinsyntax-mongo` is healthy on port 27017, so the ping succeeds.
5. `$mongoUri` is silently overridden to `mongodb://mongodb:27017`.
6. Both Admin (`QuanTriController`) and Homepage (`HomeController` / `SanPham`) query `mongodb://mongodb:27017`.

---

## Detailed Root Cause 2: Database State Divergence (Local vs Atlas)

| Attribute | Local MongoDB (`mongodb:27017`) | MongoDB Atlas (`skinsyntaxvn-db`) |
| :--- | :--- | :--- |
| **Status** | **LEGACY / UNMIGRATED** | **CANONICAL / CLEANED** |
| `san_pham` count | 6,377 products | 2,473 skincare products |
| `san_pham` active | 6,376 | 2,473 |
| Distinct `ma_danh_muc` in `san_pham` | 125 | 21 (all matching leaf categories) |
| Orphan products | 0 | 0 |
| `danh_muc` count | 125 documents | 28 documents |
| Category structure | Flat list (`parent_id = null`) | 3-level tree hierarchy |
| Categories with parent | 0 | 27 |
| Root categories | 125 | 1 (`#1001` Chăm Sóc Da Mặt) |
| Level 2 categories | 0 | 8 (6 parent groups + 2 direct leaves) |
| Level 3 leaf categories | 0 | 19 leaves |
| Legacy examples present | Dầu Gội, Sữa Tắm, Nước Hoa, Bao Cao Su... | None (pure skincare taxonomy) |

---

## Detailed Root Cause 3: Why Admin Displays 125 Categories

- Route `index.php?r=admin_categories` invokes `QuanTriController::adminCategories()`.
- `QuanTriController` calls `QuanTri::listCategories()`, which executes:
  ```php
  $cursor = $this->db->danh_muc->find([]);
  ```
- Because the runtime database is hijacked to Local MongoDB, `find([])` returns the 125 legacy categories.
- When `listCategories()` is executed against Atlas, it returns exactly the 28 canonical categories properly organized by tree depth and product counts.

---

## Detailed Root Cause 4: Why Homepage Category Block Right Panel is Blank

1. **In `frontend/views/layouts/header.php`**:
   The header mega menu consists of:
   - Left side (`col-lg-3`): Info banner ("Danh mục nổi bật").
   - Right side (`col-lg-9`): Category tree rendered from `$menuCats`.
2. **Generation of `$menuCats`**:
   `HomeController::render()` calls `SanPham::menuTree()`.
   `SanPham::menuTree()` executes:
   ```php
   foreach ($roots as $rootId) {
       $level2Ids = $children[$rootId] ?? [];
       ...
       foreach ($level2Ids as $l2Id) {
           ...
           if ($sum > 0) { $c2Map[$c2Name] = $sum; }
       }
       if (!empty($c2Map)) {
           $tree[$c1Name] = $c2Map;
       }
   }
   ```
3. **Execution under Local MongoDB**:
   In Local MongoDB, every category is a root (`$roots` has 125 items), but `$children[$rootId]` is empty for every single item. As a result, `$c2Map` is never populated, and `$tree` evaluates to `[]`.
   In `header.php`:
   ```php
   <?php foreach ($menuCats as $c1 => $c2s): ?>
   ```
   Because `$menuCats` is empty, the loop executes 0 times. The right container `<div class="col-lg-9">` has nothing inside, appearing completely white and blank to the user.
4. **Execution under Atlas MongoDB**:
   In Atlas, `$menuCats` has only 1 root key (`"Chăm Sóc Da Mặt"`), which generates 1 column (`col-6 col-lg-4`). Inside `col-lg-9`, this takes up only 33% of the container width, leaving the remaining 67% empty.

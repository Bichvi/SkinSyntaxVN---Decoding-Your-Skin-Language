# 🔥 Match Score Feature - Developer Cheatsheet

## ⚡ Quick Reference

### 1️⃣ Calculate Match Score (One Line)
```php
$score = $sanPhamModel->calculateMatchScore($productId, $userProfile);
echo $score['match_score'];        // 92
echo $score['description'];        // "Sản phẩm này phù hợp 92%..."
```

### 2️⃣ Get User Profile
```php
$profile = $userModel->layKhachHangTheoEmail('user@email.com');
// Contains: thanh_phan_tranh, van_de_da, etc
```

### 3️⃣ Display in View
```php
<?php if (!empty($matchScore)): ?>
    <div class="match-score-box">
        <h5>🎯 <?= $matchScore['match_score'] ?>%</h5>
        <!-- Tags here -->
    </div>
<?php endif; ?>
```

---

## 📊 Data Structures

### Match Score Array
```php
[
    'match_score' => 92,                     // Integer 0-100
    'good_ingredients' => [...],             // Array of strings
    'bad_ingredients' => [...],              // Array of strings
    'description' => 'Sản phẩm này...'      // String description
]
```

### User Profile (khach_hang)
```php
[
    'ma_kh' => 1,
    'email' => 'user@email.com',
    'van_de_da' => 'Da mụn',
    'thanh_phan_tranh' => 'Alcohol, Sulfate',
    ...
]
```

### Product (san_pham)
```php
[
    'ma_san_pham' => 123,
    'ten_san_pham' => 'Sữa rửa mặt',
    'thanh_phan_clean' => 'Water, Glycerin, Vitamin E',
    ...
]
```

---

## 🎯 Common Tasks

### Task 1: Test Match Score Calculation
```php
// In controller or test
$testProfile = [
    'van_de_da' => 'Da mụn',
    'thanh_phan_tranh' => 'Alcohol, Sulfate'
];

$score = $sanPhamModel->calculateMatchScore(1, $testProfile);

var_dump($score);
// [
//   'match_score' => 78,
//   'good_ingredients' => ['Water', 'Glycerin'],
//   'bad_ingredients' => ['Alcohol'],
//   'description' => '...'
// ]
```

### Task 2: Add Match Score to API Response
```php
// In API endpoint
$product = $sanPhamModel->find($id);
$userProfile = $userModel->layKhachHangTheoEmail($_GET['user_email']);

$response = [
    'product' => $product,
    'matchScore' => $sanPhamModel->calculateMatchScore($id, $userProfile)
];

echo json_encode($response);
```

### Task 3: Compare Multiple Products
```php
// Get top 3 similar products with scores
$products = $sanPhamModel->latest(3);
$userProfile = $userModel->layKhachHangTheoEmail($email);

$results = [];
foreach ($products as $p) {
    $score = $sanPhamModel->calculateMatchScore($p['id'], $userProfile);
    $results[] = ['product' => $p, 'score' => $score];
}

// Sort by match score descending
usort($results, fn($a, $b) => $b['score']['match_score'] <=> $a['score']['match_score']);
```

### Task 4: Handle Missing Data
```php
// If thanh_phan_tranh is empty/null
if (empty($userProfile['thanh_phan_tranh'])) {
    $userProfile['thanh_phan_tranh'] = '';  // Empty string
}

$score = $sanPhamModel->calculateMatchScore($id, $userProfile);
// Will return default score of 50 if no ingredients

if ($score['match_score'] === 50) {
    // Prompt user to complete survey
}
```

---

## 🔍 Debugging Snippets

### Debug: Check Parsed Ingredients
```php
// Add this in SanPham model temporarily
$ingredients = $this->parseIngredients($productIngredientString);
var_dump($ingredients);  // Array of individual ingredients
```

### Debug: Check User Profile Data
```php
$profile = $userModel->layKhachHangTheoEmail('user@email.com');
var_dump($profile);  // Check if thanh_phan_tranh is populated

if (empty($profile['thanh_phan_tranh'])) {
    echo "User has no ingredients to avoid!";
}
```

### Debug: Trace Match Score Calculation
```php
$product = $sanPhamModel->find($id);
$profile = $userModel->layKhachHangTheoEmail($email);

echo "Product Ingredients: " . $product['thanh_phan_clean'];
echo "Avoid Ingredients: " . $profile['thanh_phan_tranh'];

$score = $sanPhamModel->calculateMatchScore($id, $profile);
echo "Match Score: " . $score['match_score'] . "%";
echo "Description: " . $score['description'];
```

---

## 🎨 CSS Customization

### Change Color Theme
```css
/* In style.css, modify: */
.match-score-box {
    background: linear-gradient(135deg, YOUR_COLOR_1 0%, YOUR_COLOR_2 100%);
}

/* Example: Green theme */
.match-score-box {
    background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
}
```

### Adjust Ingredient Tag Styling
```css
.ingredient-tag {
    padding: 6px 14px;      /* Change padding */
    border-radius: 20px;    /* Change border radius */
    font-size: 12px;        /* Change font size */
}
```

### Add Shadow/Blur Effects
```css
.match-score-box {
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.25);
    backdrop-filter: blur(10px);
}
```

---

## 🚀 Performance Tips

### Optimize: Cache User Profile
```php
// Cache user profile to avoid multiple queries
$cacheKey = 'user_profile_' . md5($email);

if (!$profile = apcu_fetch($cacheKey)) {
    $profile = $userModel->layKhachHangTheoEmail($email);
    apcu_store($cacheKey, $profile, 3600);  // Cache 1 hour
}
```

### Optimize: Batch Process Multiple Products
```php
// Instead of calculating each product separately:
$products = [1, 2, 3, 4, 5];
$scores = [];

foreach ($products as $productId) {
    // Query once
    $score = $sanPhamModel->calculateMatchScore($productId, $profile);
    $scores[$productId] = $score;
}
```

### Optimize: Store Pre-calculated Scores
```php
// After calculating, store in cache table
$cache = [
    'user_id' => $userId,
    'product_id' => $productId,
    'score' => $matchScore,
    'cached_at' => date('Y-m-d H:i:s')
];

// Insert to cache table
$pdo->query("INSERT INTO cache_match_scores VALUES (...)");
```

---

## 🐛 Common Issues & Fixes

| Issue | Cause | Fix |
|-------|-------|-----|
| Match Score not showing | User not logged in | Add login check |
| Score always 50% | No ingredients parsed | Check `thanh_phan_clean` format |
| Wrong percentages | Ingredients case mismatch | Use strtolower() |
| Performance slow | Multiple queries | Implement caching |
| Red/Green tags wrong | CSS not loaded | Check link in header |
| Mobile layout broken | CSS media query | Review responsive classes |

---

## 📝 SQL Queries

### Check User Has Profile Data
```sql
SELECT * FROM khach_hang 
WHERE email = 'user@email.com' 
  AND thanh_phan_tranh IS NOT NULL 
  AND thanh_phan_tranh != '';
```

### Check Product Has Ingredients
```sql
SELECT ma_san_pham, ten_san_pham, thanh_phan_clean 
FROM san_pham 
WHERE thanh_phan_clean IS NOT NULL 
  AND thanh_phan_clean != ''
LIMIT 10;
```

### Get Products User Viewed + Score
```sql
SELECT p.*, k.thanh_phan_tranh
FROM san_pham p
JOIN khach_hang k ON k.email = ?
WHERE p.ma_san_pham IN (SELECT product_id FROM user_views);
```

---

## 🔗 Related Files

```
Model Methods:
  - SanPham::calculateMatchScore()
  - SanPham::parseIngredients()
  - NguoiDung::layKhachHangTheoEmail()

Controller Methods:
  - SanPhamController::chitiet()

View:
  - backend/app/views/chitiet.php

Styles:
  - backend/public/assets/css/style.css

Docs:
  - PDP_MATCH_SCORE_GUIDE.md
  - QUICKSTART_MATCH_SCORE.md
  - TEST_DATA_MATCH_SCORE.sql
```

---

## 💡 Usage Examples

### Example 1: Simple Integration
```php
<?php
require_once 'models/SanPham.php';
require_once 'models/NguoiDung.php';

$sanPham = new SanPham($pdo);
$user = new NguoiDung($pdo);

// Get data
$product = $sanPham->find(123);
$profile = $user->layKhachHangTheoEmail('user@email.com');

// Calculate
$score = $sanPham->calculateMatchScore(123, $profile);

// Output
printf("Match Score: %d%%\n", $score['match_score']);
echo "Description: " . $score['description'];
?>
```

### Example 2: Advanced - Comparison
```php
<?php
$productIds = [1, 2, 3, 4, 5];
$comparison = [];

foreach ($productIds as $id) {
    $score = $sanPham->calculateMatchScore($id, $profile);
    $comparison[] = [
        'product_id' => $id,
        'score' => $score['match_score'],
        'good' => count($score['good_ingredients']),
        'bad' => count($score['bad_ingredients'])
    ];
}

// Sort by score
usort($comparison, fn($a, $b) => $b['score'] <=> $a['score']);

foreach ($comparison as $c) {
    printf("%d: %d%% (%d good, %d bad)\n", 
        $c['product_id'], 
        $c['score'],
        $c['good'],
        $c['bad']
    );
}
?>
```

### Example 3: API Response
```php
<?php
header('Content-Type: application/json');

$product = $sanPham->find($_GET['id']);
$profile = $user->layKhachHangTheoEmail($_SESSION['user']['email']);
$score = $sanPham->calculateMatchScore($_GET['id'], $profile);

echo json_encode([
    'success' => true,
    'data' => [
        'product' => $product,
        'matchScore' => $score
    ]
]);
?>
```

---

## 🧪 Unit Test Template

```php
<?php
class MatchScoreTest extends PHPUnit_Framework_TestCase {
    
    private $sanPham;
    
    public function setUp() {
        $this->sanPham = new SanPham($this->pdo);
    }
    
    public function testCalculateMatchScore() {
        $profile = [
            'van_de_da' => 'Da mụn',
            'thanh_phan_tranh' => 'Alcohol, Sulfate'
        ];
        
        $result = $this->sanPham->calculateMatchScore(1, $profile);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('match_score', $result);
        $this->assertArrayHasKey('good_ingredients', $result);
        $this->assertArrayHasKey('bad_ingredients', $result);
        $this->assertBetween(0, 100, $result['match_score']);
    }
    
    public function testParseIngredients() {
        $csv = 'Water, Glycerin, Vitamin E';
        $result = $this->sanPham->parseIngredients($csv);
        
        $this->assertCount(3, $result);
        $this->assertContains('Water', $result);
    }
}
?>
```

---

## 📚 Quick Links

- 📖 [Full Guide](./PDP_MATCH_SCORE_GUIDE.md)
- 🚀 [Quick Start](./QUICKSTART_MATCH_SCORE.md)
- 🔧 [Implementation Summary](./IMPLEMENTATION_SUMMARY.md)
- 📝 [Test Data SQL](./TEST_DATA_MATCH_SCORE.sql)

---

## ✨ Pro Tips

1. **Always escape output**: Use `h()` function
2. **Handle edge cases**: Empty arrays, null values
3. **Cache results**: Store scores for better performance
4. **Log operations**: Track user interactions
5. **Monitor errors**: Check error logs for issues
6. **Test thoroughly**: Use provided test data
7. **Document changes**: Keep comments updated

---

**Last Updated**: March 14, 2026  
**Version**: 1.0 ✅


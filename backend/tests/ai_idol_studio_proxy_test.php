<?php
/**
 * AI Idol Studio PHP proxy tests (Task 7): structured product query + approve external platform.
 *
 * Standalone script: `php backend/tests/ai_idol_studio_proxy_test.php` (exit 0 = PASS). It never touches
 * MongoDB, the network or a session: behaviour is exercised through the controller's pure helpers (with a fake
 * category collection) and the HTTP-facing methods are checked statically, in the style of
 * admin_usecase_contract_test.php.
 */

$projectRoot = dirname(__DIR__, 2);
$controllerPath = $projectRoot . '/backend/app/controllers/AdminAiIdolController.php';
$routesPath = $projectRoot . '/backend/app/routes/ai_idol.php';
$entrypoints = [
    'backend/public/index.php' => $projectRoot . '/backend/public/index.php',
    'frontend/public/index.php' => $projectRoot . '/frontend/public/index.php',
    'index.php' => $projectRoot . '/index.php',
];

final class ProxyTestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function check(string $id, string $title, bool $condition, string $evidence = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "PASS {$id} - {$title}\n";
            return;
        }
        $this->failed++;
        echo "FAIL {$id} - {$title}\n";
        if ($evidence !== '') {
            echo "      {$evidence}\n";
        }
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo "\nAI Idol Studio proxy tests: {$this->passed}/{$total} passed, {$this->failed} failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

/** Stand-in for frontend/views/helpers.php so the image mapping is observable. */
if (!function_exists('resolve_image_url')) {
    function resolve_image_url(?string $raw): string
    {
        $raw = trim((string)($raw ?? ''));
        return $raw === '' ? 'PLACEHOLDER' : 'resolved:' . $raw;
    }
}

/** Fake MongoDB `danh_muc` collection: find(['parent_id' => N]) returns that parent's children. */
final class FakeCategoryCollection
{
    public function __construct(private array $rows)
    {
    }

    public function find($filter = [], $options = []): ArrayIterator
    {
        $parent = $filter['parent_id'] ?? null;
        return new ArrayIterator(array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => $parent === null || (int)$row['parent_id'] === (int)$parent
        )));
    }
}

/** Fake `san_pham`: aggregate() applies the ma_danh_muc $nin match and the per-category group the controller sends. */
final class FakeProductCollection
{
    public function __construct(private array $products)
    {
    }

    public function aggregate(array $pipeline): ArrayIterator
    {
        $match = $pipeline[0]['$match'] ?? [];
        $hidden = $match['trang_thai']['$nin'] ?? [];
        $excluded = $match['ma_danh_muc']['$nin'] ?? null;
        $groups = [];
        foreach ($this->products as $product) {
            if (in_array($product['trang_thai'] ?? 'active', $hidden, true)) {
                continue;
            }
            $cat = $product['ma_danh_muc'] ?? null;
            if ($excluded !== null && in_array($cat, $excluded, true)) {
                continue;
            }
            $groups[$cat] ??= ['_id' => $cat, 'path' => $product['danh_muc_day_du'] ?? null, 'count' => 0];
            $groups[$cat]['count']++;
        }
        return new ArrayIterator(array_values($groups));
    }
}

final class FakePdo
{
    public FakeCategoryCollection $danh_muc;
    public FakeProductCollection $san_pham;

    public function __construct(array $categoryRows, array $products = [])
    {
        $this->danh_muc = new FakeCategoryCollection($categoryRows);
        $this->san_pham = new FakeProductCollection($products);
    }
}

function source(string $path): string
{
    $contents = @file_get_contents($path);
    return is_string($contents) ? $contents : '';
}

/** Source text of one controller method (signature through closing brace), via reflection line numbers. */
function methodSource(string $class, string $method): string
{
    if (!method_exists($class, $method)) {
        return '';
    }
    $reflection = new ReflectionMethod($class, $method);
    $lines = file((string)$reflection->getFileName()) ?: [];
    return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
}

function callPrivate(object $object, string $method, array $args = [])
{
    $reflection = new ReflectionMethod($object, $method);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->invokeArgs($object, $args);
}

/** @return array{0: mixed, 1: ?Throwable} */
function attempt(callable $fn): array
{
    try {
        return [$fn(), null];
    } catch (Throwable $e) {
        return [null, $e];
    }
}

/** Quoted route keys of the `in_array($r, [...], true)` admin_ai_idol allow-list of an entrypoint. */
function entrypointRouteKeys(string $entrypointSource): array
{
    if (!preg_match('/in_array\(\$r,\s*\[(?<list>[^\]]*admin_ai_idol[^\]]*)\]\s*,\s*true\)/s', $entrypointSource, $match)) {
        return [];
    }
    preg_match_all("/'([a-z0-9_]+)'/", $match['list'], $keys);
    return $keys[1];
}

$runner = new ProxyTestRunner();

$controllerSource = source($controllerPath);
$routesSource = source($routesPath);
$runner->check('IDOL-PX-001', 'Controller source is readable', $controllerSource !== '', $controllerPath);
$runner->check('IDOL-PX-002', 'AI Idol route table is readable', $routesSource !== '', $routesPath);

require_once $controllerPath;

/* ---------------------------------------------------------------- normalizeProductQuery */

$filterKeys = ['cat_l1', 'cat_l2', 'cat_l3', 'expiry', 'stock', 'sales', 'warehouse_mode', 'scope_code', 'brand', 'q',
    'brand_cap', 'enough_stock', 'limit'];
$hasNormalizer = method_exists('AdminAiIdolController', 'normalizeProductQuery');
$runner->check('IDOL-PX-010', 'AdminAiIdolController::normalizeProductQuery exists', $hasNormalizer);

$normalize = static fn(array $input): array => AdminAiIdolController::normalizeProductQuery($input);

if ($hasNormalizer) {
    [$full, $error] = attempt(static fn() => $normalize([
        'cat_l1' => 'face', 'cat_l2' => 1002, 'cat_l3' => '', 'expiry' => 'expiry_17_19', 'stock' => 'stock_high',
        'sales' => 'best_30', 'warehouse_mode' => 'warehouse', 'scope_code' => ' KHO-1 ', 'brand' => 'Cocoon',
        'q' => 'serum', 'brand_cap' => true, 'enough_stock' => 'true', 'limit' => 500,
        'csrf' => 'must-not-leak', 'unknown' => 'dropped',
    ]));
    $runner->check('IDOL-PX-011', 'A valid input returns exactly the filter keys, in order',
        $error === null && array_keys((array)$full) === $filterKeys,
        $error ? $error->getMessage() : 'keys: ' . implode(',', array_keys((array)$full)));
    $runner->check('IDOL-PX-012', 'Values keep their types: strings trimmed, flags bool, limit int',
        $error === null
        && $full['cat_l2'] === '1002' && $full['scope_code'] === 'KHO-1' && $full['sales'] === 'best_30'
        && $full['brand_cap'] === true && $full['enough_stock'] === true && is_int($full['limit']));
    $runner->check('IDOL-PX-013', 'limit 500 is clamped to 100', $error === null && $full['limit'] === 100);

    [$empty, $error] = attempt(static fn() => $normalize([]));
    $runner->check('IDOL-PX-014', 'An empty input yields the defaults',
        $error === null && $empty === [
            'cat_l1' => '', 'cat_l2' => '', 'cat_l3' => '', 'expiry' => '', 'stock' => '', 'sales' => '',
            'warehouse_mode' => '', 'scope_code' => '', 'brand' => '', 'q' => '',
            'brand_cap' => false, 'enough_stock' => false, 'limit' => 100,
        ], $error ? $error->getMessage() : json_encode($empty));

    foreach (['expiry' => 'x', 'stock' => 'nope', 'sales' => 'x', 'warehouse_mode' => 'shelf'] as $key => $bad) {
        [, $error] = attempt(static fn() => $normalize([$key => $bad]));
        $runner->check("IDOL-PX-015-{$key}", "{$key} outside its enum throws InvalidArgumentException (Vietnamese message)",
            $error instanceof InvalidArgumentException && str_contains($error->getMessage(), 'Bộ lọc không hợp lệ'),
            $error ? get_class($error) . ': ' . $error->getMessage() : 'no exception');
    }
    [, $error] = attempt(static fn() => $normalize(['sales' => ['best_30']]));
    $runner->check('IDOL-PX-016', 'A non-string enum value throws', $error instanceof InvalidArgumentException);

    foreach (['q', 'brand', 'scope_code', 'cat_l1'] as $key) {
        [$long, $error] = attempt(static fn() => $normalize([$key => str_repeat('a', 200)]));
        $runner->check("IDOL-PX-017-{$key}", "A 200-char {$key} is cut to 80 characters",
            $error === null && mb_strlen($long[$key]) === 80);
    }
    [$long, $error] = attempt(static fn() => $normalize(['q' => '  ' . str_repeat('ả', 200)]));
    $runner->check('IDOL-PX-018', 'Trimming happens first and the cut counts characters, not bytes',
        $error === null && $long['q'] === str_repeat('ả', 80));
    [, $error] = attempt(static fn() => $normalize(['q' => ['a']]));
    $runner->check('IDOL-PX-019', 'A non-scalar text value throws', $error instanceof InvalidArgumentException);

    foreach ([true, 1, '1', 'on', 'TRUE', ' yes '] as $truthy) {
        [$flags, $error] = attempt(static fn() => $normalize(['brand_cap' => $truthy]));
        $runner->check('IDOL-PX-020-' . json_encode($truthy), 'Flag value ' . json_encode($truthy) . ' is true',
            $error === null && $flags['brand_cap'] === true);
    }
    foreach ([false, 0, '0', 'off', '', null] as $falsy) {
        [$flags, $error] = attempt(static fn() => $normalize(['enough_stock' => $falsy]));
        $runner->check('IDOL-PX-021-' . json_encode($falsy), 'Flag value ' . json_encode($falsy) . ' is false',
            $error === null && $flags['enough_stock'] === false);
    }
    foreach (['maybe', 2, ['x']] as $badFlag) {
        [, $error] = attempt(static fn() => $normalize(['brand_cap' => $badFlag]));
        $runner->check('IDOL-PX-022-' . json_encode($badFlag), 'Flag value ' . json_encode($badFlag) . ' throws',
            $error instanceof InvalidArgumentException);
    }

    foreach ([['25', 25], [7, 7], [0, 1], [-5, 1], [100, 100], [101, 100], ['', 100], [null, 100], [12.0, 12]] as [$input, $expected]) {
        [$out, $error] = attempt(static fn() => $normalize(['limit' => $input]));
        $runner->check('IDOL-PX-023-' . json_encode($input), 'limit ' . json_encode($input) . " becomes {$expected}",
            $error === null && $out['limit'] === $expected);
    }
    foreach (['abc', true, [5]] as $badLimit) {
        [, $error] = attempt(static fn() => $normalize(['limit' => $badLimit]));
        $runner->check('IDOL-PX-024-' . json_encode($badLimit), 'limit ' . json_encode($badLimit) . ' throws',
            $error instanceof InvalidArgumentException);
    }
}

/* ---------------------------------------------------------------- expandCategoryIds / payload */

$categories = [
    ['ma_danh_muc' => 1002, 'parent_id' => 1001],
    ['ma_danh_muc' => 1003, 'parent_id' => 1001],
    ['ma_danh_muc' => 2001, 'parent_id' => 1002],
    ['ma_danh_muc' => 2002, 'parent_id' => 1002],
    ['ma_danh_muc' => 2003, 'parent_id' => 1003],
    ['ma_danh_muc' => 3000, 'parent_id' => 0],
];
$controller = new AdminAiIdolController(new FakePdo($categories));
$hasExpand = method_exists($controller, 'expandCategoryIds');
$runner->check('IDOL-PX-030', 'AdminAiIdolController::expandCategoryIds exists', $hasExpand);

if ($hasExpand) {
    $expand = static fn(string $l1, string $l2, string $l3) => callPrivate($controller, 'expandCategoryIds', [$l1, $l2, $l3]);
    $runner->check('IDOL-PX-031', 'No category selected means no filter (null)',
        $expand('', '', '') === null && $expand('all', '', '') === null);
    $runner->check('IDOL-PX-032', 'L3 expands to itself', $expand('face', '1002', '2001') === [2001]);
    $runner->check('IDOL-PX-033', 'L2 expands to itself plus its children', $expand('face', '1002', '') === [1002, 2001, 2002]);
    $runner->check('IDOL-PX-034', "L1 'face' expands to 1001, its L2 children and their L3 children",
        $expand('face', '', '') === [1001, 1002, 2001, 2002, 1003, 2003], json_encode($expand('face', '', '')));
    $runner->check('IDOL-PX-035', 'A numeric L1 expands like face', $expand('1003', '', '') === [1003, 2003]);
    $runner->check('IDOL-PX-036', 'An unmapped L1 keeps the old getProducts behaviour (null, no filter)', $expand('makeup', '', '') === null);
}

$hasPayload = method_exists($controller, 'buildProductQueryPayload');
$runner->check('IDOL-PX-040', 'AdminAiIdolController::buildProductQueryPayload exists', $hasPayload);

if ($hasPayload && $hasNormalizer) {
    $payloadFor = static function (array $input) use ($controller): array {
        return callPrivate($controller, 'buildProductQueryPayload', [AdminAiIdolController::normalizeProductQuery($input)]);
    };
    $none = $payloadFor(['sales' => 'best_30']);
    $runner->check('IDOL-PX-041', 'No category selected omits category_ids and every cat_* key',
        !array_key_exists('category_ids', $none) && !preg_grep('/^cat_/', array_keys($none)) && $none['sales'] === 'best_30');
    $all = $payloadFor(['cat_l1' => 'all']);
    $runner->check('IDOL-PX-042', "cat_l1 'all' omits category_ids", !array_key_exists('category_ids', $all));
    $face = $payloadFor(['cat_l1' => 'face', 'cat_l2' => '1002']);
    $runner->check('IDOL-PX-043', 'A selected category sends its expanded ids',
        ($face['category_ids'] ?? null) === [1002, 2001, 2002] && !preg_grep('/^cat_/', array_keys($face)));
    $makeup = $payloadFor(['cat_l1' => 'makeup']);
    $runner->check('IDOL-PX-044', 'A selected category that expands to nothing sends the match-nothing sentinel, never []',
        ($makeup['category_ids'] ?? null) === [AdminAiIdolController::NO_MATCH_CATEGORY_ID]
        && AdminAiIdolController::NO_MATCH_CATEGORY_ID >= 0);
    $junk = $payloadFor(['cat_l3' => 'abc']);
    $runner->check('IDOL-PX-045', 'A non-numeric category id never reaches Flask as 0',
        ($junk['category_ids'] ?? null) === [AdminAiIdolController::NO_MATCH_CATEGORY_ID]);
    $runner->check('IDOL-PX-046', 'The payload carries every other filter key',
        array_diff(['expiry', 'stock', 'sales', 'warehouse_mode', 'scope_code', 'brand', 'q', 'brand_cap', 'enough_stock', 'limit'],
            array_keys($none)) === []);
}

/* ---------------------------------------------------------------- image mapping */

$hasImageMap = method_exists($controller, 'mapQueryProductImages');
$runner->check('IDOL-PX-050', 'AdminAiIdolController::mapQueryProductImages exists', $hasImageMap);

if ($hasImageMap) {
    $map = static fn(string $body): string => callPrivate($controller, 'mapQueryProductImages', [$body]);
    $flaskBody = '{"ok":true,"products":[{"product_id":"7","name":"Serum \u00e1","image_raw":"img/a.jpg","price":12.0,"stock":5},'
        . '{"product_id":"8","name":"B","image_raw":""}],"matched_count":2,"warnings":[],"missing_data":{},"checked_at":"2026-10-15T00:00:00+07:00"}';
    $mapped = json_decode($map($flaskBody));
    $runner->check('IDOL-PX-051', 'Each product gets image = resolve_image_url(image_raw) and loses image_raw',
        is_object($mapped) && $mapped->products[0]->image === 'resolved:img/a.jpg' && $mapped->products[1]->image === 'PLACEHOLDER'
        && !property_exists($mapped->products[0], 'image_raw') && !property_exists($mapped->products[1], 'image_raw'));
    $runner->check('IDOL-PX-052', 'Other product fields and envelope fields are preserved',
        is_object($mapped) && $mapped->products[0]->name === 'Serum á' && $mapped->products[0]->stock === 5
        && $mapped->matched_count === 2 && $mapped->warnings === [] && $mapped->checked_at === '2026-10-15T00:00:00+07:00');
    $mappedText = $map($flaskBody);
    $runner->check('IDOL-PX-053', 'An empty JSON object stays an object and 12.0 stays a float',
        str_contains($mappedText, '"missing_data":{}') && str_contains($mappedText, '"price":12.0'), $mappedText);
    $errorBody = '{"ok":false,"message":"B\u1ed9 l\u1ecdc kh\u00f4ng h\u1ee3p l\u1ec7: sales"}';
    $runner->check('IDOL-PX-054', 'A body without products (a 400/500 error) passes through byte for byte', $map($errorBody) === $errorBody);
    $runner->check('IDOL-PX-055', 'A non-JSON body passes through byte for byte', $map('<html>502</html>') === '<html>502</html>');
}

/* ---------------------------------------------------------------- routes */

$routeContracts = [
    'admin_ai_idol_products_query' => 'queryProducts',
    'admin_ai_idol_approve_platform' => 'approvePlatform',
];
$routeKeysByFile = [];
foreach ($entrypoints as $label => $path) {
    $routeKeysByFile[$label] = entrypointRouteKeys(source($path));
}
$tableKeys = [];
if (preg_match_all("/'([a-z0-9_]+)'\\s*=>\\s*\\['(GET|POST)',\\s*'([A-Za-z0-9_]+)'\\]/", $routesSource, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $tableKeys[$match[1]] = ['method' => $match[2], 'action' => $match[3]];
    }
}
foreach ($routeContracts as $route => $action) {
    $runner->check("IDOL-PX-060-{$route}", "routes/ai_idol.php maps {$route} to POST {$action}",
        ($tableKeys[$route]['method'] ?? null) === 'POST' && ($tableKeys[$route]['action'] ?? null) === $action,
        json_encode($tableKeys[$route] ?? null));
    $runner->check("IDOL-PX-061-{$route}", "Controller has public {$action}()",
        method_exists('AdminAiIdolController', $action) && (new ReflectionMethod('AdminAiIdolController', $action))->isPublic());
    foreach ($routeKeysByFile as $label => $keys) {
        $runner->check("IDOL-PX-062-{$route}-{$label}", "{$label} allow-list contains {$route}", in_array($route, $keys, true));
    }
}
foreach ($routeKeysByFile as $label => $keys) {
    $missing = array_values(array_diff(array_keys($tableKeys), $keys));
    $extra = array_values(array_diff($keys, array_keys($tableKeys)));
    $runner->check("IDOL-PX-063-{$label}", "{$label} allow-list equals the route table keys",
        $keys !== [] && $missing === [] && $extra === [],
        'missing: ' . implode(',', $missing) . '; extra: ' . implode(',', $extra));
}

/* ---------------------------------------------------------------- catalog tree (category picker) */

$hasTree = method_exists('AdminAiIdolController', 'buildCatalogTree');
$runner->check('IDOL-PX-080', 'AdminAiIdolController::buildCatalogTree exists', $hasTree);

if ($hasTree) {
    // The live danh_muc has the face root 1001 (level 1); the makeup extension adds root 22 (level 0).
    $catalogRows = [
        ['ma_danh_muc' => 22, 'ten_danh_muc' => 'Trang Điểm', 'slug' => 'trang-diem', 'parent_id' => null, 'level' => 0],
        ['ma_danh_muc' => 1001, 'ten_danh_muc' => 'Chăm Sóc Da Mặt', 'slug' => 'cham-soc-da-mat', 'parent_id' => null, 'level' => 1],
        ['ma_danh_muc' => 6, 'ten_danh_muc' => 'Chống Nắng Da Mặt', 'slug' => 'chong-nang', 'parent_id' => 1001, 'level' => 2],
        ['ma_danh_muc' => 2002, 'ten_danh_muc' => 'Làm Sạch Da', 'slug' => 'lam-sach-da', 'parent_id' => 1001, 'level' => 2],
        ['ma_danh_muc' => 23, 'ten_danh_muc' => 'Trang Điểm Môi', 'slug' => 'trang-diem-moi', 'parent_id' => 22, 'level' => 1],
        ['ma_danh_muc' => 1, 'ten_danh_muc' => 'Sữa Rửa Mặt', 'slug' => 'sua-rua-mat', 'parent_id' => 2002, 'level' => 3],
        ['ma_danh_muc' => 29, 'ten_danh_muc' => 'Son Kem / Tint', 'slug' => 'son-kem-tint', 'parent_id' => 23, 'level' => 2],
        ['ma_danh_muc' => 500, 'ten_danh_muc' => 'Mồ Côi', 'slug' => 'mo-coi', 'parent_id' => 9999, 'level' => 2],
        ['ma_danh_muc' => 77, 'ten_danh_muc' => 'Trùng Slug', 'slug' => 'cham-soc-da-mat', 'parent_id' => null, 'level' => 1],
        ['ma_danh_muc' => 88, 'ten_danh_muc' => 'Chăm Sóc Tóc', 'slug' => 'cham-soc-toc', 'parent_id' => 0, 'level' => 1],
        ['ma_danh_muc' => 99, 'ten_danh_muc' => 'Nhóm Mới', 'slug' => 'nhom-moi!', 'parent_id' => 0, 'level' => 1],
    ];
    $catalogCounts = [6 => 231, 1 => 285, 29 => 40, 22 => 0, 500 => 9, 2002 => 0];
    $catalog = AdminAiIdolController::buildCatalogTree($catalogRows, $catalogCounts);

    $runner->check('IDOL-PX-081', "One node per root: the face root first under 'face', the others by alias or slug",
        array_keys($catalog) === ['face', 'makeup', 'cat_77', 'hair', 'nhom-moi'], json_encode(array_keys($catalog)));
    $runner->check('IDOL-PX-082', 'Every root carries its numeric ma_danh_muc as id',
        $catalog['face']['id'] === 1001 && $catalog['makeup']['id'] === 22 && $catalog['hair']['id'] === 88
        && $catalog['face']['label'] === 'Chăm Sóc Da Mặt' && $catalog['makeup']['label'] === 'Trang Điểm');
    $runner->check('IDOL-PX-083', 'Each leaf hangs from its real parent (no level-2 catch-all under face)',
        array_keys($catalog['face']['children']) === [6, 2002]
        && array_keys($catalog['makeup']['children']) === [23]
        && array_keys($catalog['makeup']['children'][23]['children']) === [29]
        && array_keys($catalog['face']['children'][2002]['children']) === [1]
        && !isset($catalog['face']['children'][23]) && !isset($catalog['face']['children'][500]));
    $runner->check('IDOL-PX-084', 'Counts are cumulative: a node counts its own products plus everything below',
        $catalog['face']['count'] === 516 && $catalog['face']['children'][2002]['count'] === 285
        && $catalog['face']['children'][6]['count'] === 231
        && $catalog['makeup']['count'] === 40 && $catalog['makeup']['children'][23]['count'] === 40);
    $runner->check('IDOL-PX-085', 'A category whose parent does not exist is left out of the tree',
        !str_contains(json_encode($catalog, JSON_UNESCAPED_UNICODE), 'Mồ Côi'));
    $runner->check('IDOL-PX-086', 'Nodes keep the shape the page reads (phrase on L1/L2, no children on L3, children as a JSON object)',
        $catalog['face']['phrase'] === 'sản phẩm chăm sóc da mặt'
        && $catalog['face']['children'][2002]['phrase'] === 'sản phẩm làm sạch da'
        && !array_key_exists('children', $catalog['face']['children'][2002]['children'][1])
        && $catalog['face']['children'][2002]['children'][1]['phrase'] === 'sữa rửa mặt'
        && str_starts_with(json_encode($catalog['face']['children']), '{'));
    $runner->check('IDOL-PX-087', 'An empty category list gives an empty tree', AdminAiIdolController::buildCatalogTree([], []) === []);

    // The id the picker sends for a group must expand to that group's whole subtree.
    $catalogController = new AdminAiIdolController(new FakePdo([
        ['ma_danh_muc' => 23, 'parent_id' => 22], ['ma_danh_muc' => 29, 'parent_id' => 23],
    ]));
    $runner->check('IDOL-PX-088', "A makeup root sent by its numeric id expands to its subtree; 'face' still maps to 1001",
        callPrivate($catalogController, 'expandCategoryIds', [(string)$catalog['makeup']['id'], '', '']) === [22, 23, 29]
        && AdminAiIdolController::FACE_ROOT_CATEGORY_ID === 1001
        && $catalog['face']['id'] === AdminAiIdolController::FACE_ROOT_CATEGORY_ID);
}

/* Orphan products: ma_danh_muc not in danh_muc are grouped by the L1 segment of danh_muc_day_du. */
$orphanProducts = [];
$addProducts = static function (int $cat, string $path, int $n, string $status = 'active') use (&$orphanProducts): void {
    for ($i = 0; $i < $n; $i++) {
        $orphanProducts[] = ['ma_danh_muc' => $cat, 'danh_muc_day_du' => $path, 'trang_thai' => $status];
    }
};
$addProducts(1001, 'Chăm Sóc Da Mặt', 4);                       // the face root itself
$addProducts(6, 'Chăm Sóc Da Mặt -> Chống Nắng', 3);            // a real child
$addProducts(22, 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Cơ Thể -> Sữa Tắm', 2);
$addProducts(23, 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Cơ Thể -> Dưỡng Thể', 3);
$addProducts(15, 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Tóc Và Da Đầu -> Dầu Gội', 4);
$addProducts(27, 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Tóc Và Da Đầu -> Dầu Xả', 1);
$addProducts(70, 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Mặt Nạ', 2);
$addProducts(80, 'Thực Phẩm Chức Năng -> Vitamin', 1);
$addProducts(22, 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Cơ Thể -> Sữa Tắm', 5, 'inactive'); // hidden: never counted
$orphanCats = [
    ['ma_danh_muc' => 1001, 'ten_danh_muc' => 'Chăm Sóc Da Mặt', 'slug' => 'cham-soc-da-mat', 'parent_id' => null, 'level' => 1],
    ['ma_danh_muc' => 6, 'ten_danh_muc' => 'Chống Nắng', 'slug' => 'chong-nang', 'parent_id' => 1001, 'level' => 2],
];
$orphanController = new AdminAiIdolController(new FakePdo($orphanCats, $orphanProducts));
$orphanGroups = AdminAiIdolController::groupOrphanCategories([
    ['ma_danh_muc' => 22, 'path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Cơ Thể -> Sữa Tắm', 'count' => 2],
    ['ma_danh_muc' => 23, 'path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Cơ Thể -> Dưỡng Thể', 'count' => 3],
    ['ma_danh_muc' => 15, 'path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Tóc Và Da Đầu -> Dầu Gội', 'count' => 4],
    ['ma_danh_muc' => 70, 'path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Mặt Nạ', 'count' => 2],
    ['ma_danh_muc' => 80, 'path' => 'Thực Phẩm Chức Năng -> Vitamin', 'count' => 1],
]);
$runner->check('IDOL-PX-120', 'Orphan products group into body / hair / face / a slug key by the L1 segment',
    array_keys($orphanGroups) === ['body', 'hair', 'face', array_keys($orphanGroups)[3]]
    && str_starts_with(array_keys($orphanGroups)[3], 'grp_')
    && $orphanGroups['body']['count'] === 5 && $orphanGroups['hair']['count'] === 4
    && $orphanGroups['body']['label'] === 'Chăm Sóc Cơ Thể' && $orphanGroups['body']['children'][22] === ['label' => 'Sữa Tắm', 'count' => 2],
    json_encode(array_keys($orphanGroups)));

$orphanTree = AdminAiIdolController::buildCatalogTree($orphanCats, [1001 => 4, 6 => 3], $orphanGroups);
$runner->check('IDOL-PX-121', 'Synthetic groups are roots with id null, their count and children keyed by the orphan ma_danh_muc',
    $orphanTree['body']['id'] === null && $orphanTree['body']['label'] === 'Chăm Sóc Cơ Thể' && $orphanTree['body']['count'] === 5
    && array_keys($orphanTree['body']['children']) === [22, 23] && $orphanTree['body']['children'][23]['id'] === 23
    && $orphanTree['body']['children'][23]['label'] === 'Dưỡng Thể' && $orphanTree['body']['children'][23]['count'] === 3
    && $orphanTree['hair']['count'] === 4 && !isset($orphanTree['makeup']),
    json_encode(array_keys($orphanTree)));
$runner->check('IDOL-PX-122', 'The face group joins the face root: its children and count grow, no second face root appears',
    $orphanTree['face']['id'] === 1001 && $orphanTree['face']['count'] === 7 + 2
    && isset($orphanTree['face']['children'][70]) && isset($orphanTree['face']['children'][6])
    && count(array_filter(array_keys($orphanTree), static fn($k) => str_starts_with((string)$k, 'face'))) === 1);

$expandOrphan = static fn(string $l1, string $l2 = '', string $l3 = '') => callPrivate($orphanController, 'expandCategoryIds', [$l1, $l2, $l3]);
$runner->check('IDOL-PX-123', "expandCategoryIds('body') / ('hair') map the key to that group's orphan ma_danh_muc",
    $expandOrphan('body') === [22, 23] && $expandOrphan('hair') === [15, 27]
    && $expandOrphan('22', '', '') !== null && $expandOrphan('', '23') === [23],
    json_encode([$expandOrphan('body'), $expandOrphan('hair')]));
$runner->check('IDOL-PX-124', "'face' (and its numeric id 1001) also covers the face group's orphans",
    $expandOrphan('face') === [1001, 6, 70] && $expandOrphan('1001') === [1001, 6, 70], json_encode($expandOrphan('face')));
$runner->check('IDOL-PX-125', 'A group with no products keeps the old behaviour (null, then the NO_MATCH sentinel upstream)',
    $expandOrphan('makeup') === null);

$runner->check('IDOL-PX-126', 'getCatalogMetadata() totals every visible product, not just the tree roots',
    str_contains(methodSource('AdminAiIdolController', 'getCatalogMetadata'), 'array_sum($countsByCat)')
    && str_contains(methodSource('AdminAiIdolController', 'getCatalogMetadata'), '$this->loadOrphanGroups('));

$catalogMetadataSource = methodSource('AdminAiIdolController', 'getCatalogMetadata');
$runner->check('IDOL-PX-089', 'getCatalogMetadata() builds the tree with buildCatalogTree(), without the old level-2 catch-all, and totals every root',
    str_contains($catalogMetadataSource, 'self::buildCatalogTree(')
    && !str_contains($catalogMetadataSource, '$lvl === 2')
    && !str_contains($catalogMetadataSource, "\$tree['face']")
    && str_contains($catalogMetadataSource, "'total' => \$totalCategorized"));
$studioViewSource = source($projectRoot . '/frontend/views/admin/ai_idol/index.php');
$runner->check('IDOL-PX-090', 'The manual-tab group select takes its values from the tree, not from hard-coded text keys',
    str_contains($studioViewSource, '$wzGroupOptions')
    && !str_contains($studioViewSource, '<option value="face">') && !str_contains($studioViewSource, '<option value="makeup">'));

/* ---------------------------------------------------------------- product-video management page (D11) */

$runner->check('IDOL-PX-100', 'routes/ai_idol.php maps admin_ai_product_videos to GET productVideosPage',
    ($tableKeys['admin_ai_product_videos']['method'] ?? null) === 'GET'
    && ($tableKeys['admin_ai_product_videos']['action'] ?? null) === 'productVideosPage',
    json_encode($tableKeys['admin_ai_product_videos'] ?? null));
foreach ($routeKeysByFile as $label => $keys) {
    $runner->check("IDOL-PX-101-{$label}", "{$label} allow-list contains admin_ai_product_videos", in_array('admin_ai_product_videos', $keys, true));
}
$pageBody = methodSource('AdminAiIdolController', 'productVideosPage');
$runner->check('IDOL-PX-102', 'productVideosPage() checks admin first and renders admin/product_videos with the CSRF token',
    $pageBody !== '' && strpos($pageBody, '$this->') === strpos($pageBody, '$this->checkAdmin()')
    && str_contains($pageBody, "render('admin/product_videos'") && str_contains($pageBody, 'self::getCsrfToken()'));
$productVideosView = source($projectRoot . '/frontend/views/admin/product_videos.php');
$runner->check('IDOL-PX-103', 'The page loads only its own assets and the Studio view never loads them',
    str_contains($productVideosView, 'admin-product-videos.js') && str_contains($productVideosView, 'admin-product-videos.css')
    && !str_contains($productVideosView, 'ai-idol-studio') && !str_contains($productVideosView, 'studio-core')
    && !str_contains($studioViewSource, 'admin-product-videos'));
$productVideoModelSource = source($projectRoot . '/backend/app/models/ProductVideo.php');
$runner->check('IDOL-PX-104', 'getManageableVideos() returns the source of each publication so the page can badge auto-published videos',
    str_contains($productVideoModelSource, "'source' => \$pub ? (string)(\$pub['source'] ?? '') : ''"));

/* ---------------------------------------------------------------- guards (static) */

foreach (['queryProducts', 'approvePlatform'] as $method) {
    $body = methodSource('AdminAiIdolController', $method);
    $adminAt = strpos($body, '$this->checkAdmin()');
    $csrfAt = strpos($body, '$this->verifyCsrf($input)');
    $runner->check("IDOL-PX-070-{$method}", "{$method}() calls checkAdmin() first",
        $body !== '' && $adminAt !== false && strpos($body, '$this->') === $adminAt);
    $runner->check("IDOL-PX-071-{$method}", "{$method}() calls verifyCsrf(\$input) after checkAdmin()",
        $adminAt !== false && $csrfAt !== false && $csrfAt > $adminAt);
}
$queryBody = methodSource('AdminAiIdolController', 'queryProducts');
$runner->check('IDOL-PX-072', 'queryProducts() verifies CSRF before it normalizes or calls Flask',
    ($csrf = strpos($queryBody, 'verifyCsrf(')) !== false
    && $csrf < (int)strpos($queryBody, 'normalizeProductQuery(') && $csrf < (int)strpos($queryBody, 'callFlaskAPI('));
$runner->check('IDOL-PX-073', 'queryProducts() proxies POST /api/ai-idol/products/query',
    str_contains($queryBody, "callFlaskAPI('POST', '/api/ai-idol/products/query'"));
$approveBody = methodSource('AdminAiIdolController', 'approvePlatform');
$runner->check('IDOL-PX-074', 'approvePlatform() proxies POST /campaigns/<id>/platforms/approve with an escaped id',
    str_contains($approveBody, "'/api/ai-idol/campaigns/' . rawurlencode(\$campaignId) . '/platforms/approve'"));
$runner->check('IDOL-PX-075', "approvePlatform() takes 'by' from the session like createAgentRun()",
    str_contains($approveBody, "\$_SESSION['user']") && str_contains($approveBody, "'by'")
    && str_contains($approveBody, "\$user['ma_kh'] ?? \$user['email'] ?? 'admin'"));
$runner->check('IDOL-PX-076', 'getProducts() reuses expandCategoryIds()',
    str_contains(methodSource('AdminAiIdolController', 'getProducts'), 'expandCategoryIds('));

/* ---------------------------------------------------------------- CSRF on every mutating proxy handler (I2) */

foreach (['createCampaign', 'createAgentRun', 'proxyAgentMutation', 'uploadAsset', 'retryCampaign', 'cancelCampaign',
          'rescheduleCampaign', 'approveScript', 'videoVisibility'] as $method) {
    $body = methodSource('AdminAiIdolController', $method);
    $adminAt = strpos($body, '$this->checkAdmin()');
    $csrfAt = strpos($body, '$this->verifyCsrf(');
    $runner->check("IDOL-PX-120-{$method}", "{$method}() calls verifyCsrf() after checkAdmin() and before callFlaskAPI()",
        $body !== '' && $adminAt !== false && $csrfAt !== false && $csrfAt > $adminAt
        && $csrfAt < (int)strpos($body, $method === 'uploadAsset' ? '$_POST' : 'callFlaskAPI('));
}

/* ---------------------------------------------------------------- product-page eligibility (I1) */

class FakeVideoCursorCollection
{
    public array $lastFilter = [];

    public function __construct(private array $rows)
    {
    }

    public function createIndex($keys): void
    {
    }

    public function insertOne(array $doc): object
    {
        return new class {
            public function getInsertedId(): MongoDB\BSON\ObjectId
            {
                return new MongoDB\BSON\ObjectId(str_repeat('9', 24));
            }
        };
    }

    public function findOne($filter = [])
    {
        foreach ($this->rows as $row) {
            if ((string)$row['_id'] === (string)($filter['_id'] ?? '')) {
                return $row;
            }
        }
        return null;
    }

    public function find($filter = [], $options = []): ArrayIterator
    {
        $this->lastFilter = $filter;
        $rows = $this->rows;
        if (isset($filter['_id']['$in'])) {
            $wanted = array_map('strval', $filter['_id']['$in']);
            $rows = array_filter($rows, static fn(array $r): bool => in_array((string)$r['_id'], $wanted, true));
        }
        return new ArrayIterator(array_values($rows));
    }
}

final class FakeProductVideoDb
{
    public FakeVideoCursorCollection $ai_product_videos;
    public FakeVideoCursorCollection $ai_idol_videos;

    public function __construct(array $publications, array $videos)
    {
        $this->ai_product_videos = new FakeVideoCursorCollection($publications);
        $this->ai_idol_videos = new FakeVideoCursorCollection($videos);
    }
}

if (class_exists('MongoDB\BSON\ObjectId')) {
    require_once $projectRoot . '/backend/app/models/ProductVideo.php';
    $oid = static fn(string $hex): MongoDB\BSON\ObjectId => new MongoDB\BSON\ObjectId($hex);
    $vProduct = str_repeat('a', 24);
    $vBoth = str_repeat('b', 24);
    $vLive = str_repeat('c', 24);
    $vLegacy = str_repeat('d', 24);
    $vMissing = str_repeat('e', 24);
    $vBackfill = str_repeat('f', 24);
    $pub = static fn(string $id, string $videoId): array => [
        '_id' => $oid($id), 'video_id' => $videoId, 'is_published' => true, 'associated_product_ids' => ['111'],
    ];
    $db = new FakeProductVideoDb(
        [
            $pub(str_repeat('1', 24), $vProduct), $pub(str_repeat('2', 24), $vBoth), $pub(str_repeat('3', 24), $vLive),
            $pub(str_repeat('4', 24), $vLegacy), $pub(str_repeat('5', 24), $vMissing), $pub(str_repeat('6', 24), 'not-an-object-id'),
            $pub(str_repeat('7', 24), $vBackfill),
        ],
        [
            ['_id' => $oid($vProduct), 'purpose' => 'product_video'],
            ['_id' => $oid($vBoth), 'purpose' => 'live_and_product'],
            ['_id' => $oid($vLive), 'purpose' => 'live_only'],
            ['_id' => $oid($vLegacy)],
            ['_id' => $oid($vBackfill), 'product_page_backfill' => true],
        ]
    );
    $pvModel = new ProductVideo($db);
    $visible = array_column($pvModel->getPublishedVideosByProductId('111'), 'video_id');
    $runner->check('IDOL-PX-130', 'getPublishedVideosByProductId() keeps product_video, live_and_product and vetted legacy backfill videos only',
        $visible === [$vProduct, $vBoth, $vBackfill], json_encode($visible));
    $inFilter = $db->ai_idol_videos->lastFilter['_id']['$in'] ?? [];
    $runner->check('IDOL-PX-131', 'The source videos are loaded with one $in query over valid ObjectIds',
        count($inFilter) === 6 && $inFilter[0] instanceof MongoDB\BSON\ObjectId);

    $publishDb = new FakeProductVideoDb([], [['_id' => $oid($vLive), 'purpose' => 'live_only'], ['_id' => $oid($vLegacy)], ['_id' => $oid($vBackfill), 'product_page_backfill' => true]]);
    $publishModel = new ProductVideo($publishDb);
    foreach ([$vLive, $vLegacy] as $n => $ineligibleId) {
        $res = $publishModel->publishVideo(['video_id' => $ineligibleId, 'associated_product_ids' => ['111']]);
        $runner->check("IDOL-PX-132-{$n}", 'publishVideo() rejects a livestream (live_only / no purpose) video with the Vietnamese message',
            ($res['ok'] ?? true) === false && ($res['message'] ?? '') === 'Video này là bản livestream có nói giá nên không thể đăng lên trang sản phẩm.',
            json_encode($res, JSON_UNESCAPED_UNICODE));
    }
}
$runner->check('IDOL-PX-133', 'getManageableVideos() exposes product_page_eligible per video',
    str_contains($productVideoModelSource, "'product_page_eligible' => self::videoPublishesToProductPage("));
if (class_exists('MongoDB\BSON\ObjectId')) {
    $runner->check('IDOL-PX-136', 'videoPublishesToProductPage(): purpose or product_page_backfill, nothing else',
        ProductVideo::videoPublishesToProductPage(['product_page_backfill' => true]) === true
        && ProductVideo::videoPublishesToProductPage(['purpose' => 'live_only', 'product_page_backfill' => true]) === true
        && ProductVideo::videoPublishesToProductPage(['purpose' => 'product_video']) === true
        && ProductVideo::videoPublishesToProductPage(['purpose' => 'live_only']) === false
        && ProductVideo::videoPublishesToProductPage(['product_page_backfill' => false]) === false
        && ProductVideo::videoPublishesToProductPage([]) === false);
    $backfillPublish = $publishModel->publishVideo(['video_id' => $vBackfill, 'associated_product_ids' => ['111']]);
    $runner->check('IDOL-PX-137', 'publishVideo() does not reject a backfill video as a livestream',
        ($backfillPublish['message'] ?? '') !== 'Video này là bản livestream có nói giá nên không thể đăng lên trang sản phẩm.',
        json_encode($backfillPublish, JSON_UNESCAPED_UNICODE));
}
$apvScript = source($projectRoot . '/frontend/public/assets/js/admin-product-videos.js');
$runner->check('IDOL-PX-134', 'The product-video page disables publishing for ineligible videos with the explanation',
    str_contains($apvScript, 'product_page_eligible') && str_contains($apvScript, "apvPubSubmit').disabled = !eligible")
    && str_contains($apvScript, 'Video này là bản livestream có nói giá'));
$studioView = source($projectRoot . '/frontend/views/admin/ai_idol/index.php');
$runner->check('IDOL-PX-135', 'Studio and product-video pages JSON flags substitute invalid UTF-8; the CSRF token has its own script block',
    str_contains($studioView, 'JSON_INVALID_UTF8_SUBSTITUTE') && str_contains($productVideosView, 'JSON_INVALID_UTF8_SUBSTITUTE')
    && preg_match('~<script>\s*window\.AI_IDOL_CSRF.*?</script>\s*<script>\s*window\.__IDOL_CATALOG__~s', $studioView) === 1);

exit($runner->summary());

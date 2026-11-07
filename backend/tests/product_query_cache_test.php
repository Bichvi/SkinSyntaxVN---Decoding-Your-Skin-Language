<?php
declare(strict_types=1);

/**
 * Test cho ProductQueryCache (cache file ngắn hạn) và các truy vấn danh sách của SanPham dùng nó.
 *
 * Phần unit không cần DB. Phần integration kết nối MongoDB Atlas qua app/config/db.php và CHỈ ĐỌC
 * (không ghi dữ liệu). Cache được chuyển sang thư mục tạm riêng và xóa khi kết thúc.
 * Chạy trong container: php -d memory_limit=512M /var/www/html/tests/product_query_cache_test.php
 */

require_once __DIR__ . '/../app/services/ProductQueryCache.php';

// db.php nạp autoload (mongodb/mongodb) và tạo $db; kết nối Atlas chỉ mở khi có truy vấn đầu tiên.
$bootstrapError = '';
try {
    require_once __DIR__ . '/../app/config/db.php';
    require_once __DIR__ . '/../app/models/SanPham.php';
} catch (Throwable $e) {
    $bootstrapError = $e->getMessage();
}

final class QueryCacheTestRunner
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
        echo "\nProduct query cache tests: {$this->passed}/{$total} passed, {$this->failed} failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

/** DB giả: mọi truy cập collection đều ném lỗi, chứng minh kết quả được phục vụ hoàn toàn từ cache. */
final class ForbiddenDb
{
    public function __get(string $name)
    {
        throw new RuntimeException('DB bi truy cap: ' . $name);
    }
}

/** Bọc DB thật và đếm số lần mỗi collection được truy cập (mỗi lần nạp map lookup = 1 lần). */
final class CountingDb
{
    public array $hits = [];

    public function __construct(private $inner)
    {
    }

    public function __get(string $name)
    {
        $this->hits[$name] = ($this->hits[$name] ?? 0) + 1;
        return $this->inner->{$name};
    }
}

function cacheFiles(string $dir, string $pattern = '*.cache'): array
{
    return glob($dir . '/' . $pattern) ?: [];
}

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? removeDirectory($path) : @unlink($path);
    }
    @rmdir($dir);
}

function resetSanPhamStatics(): void
{
    $ref = new ReflectionClass(SanPham::class);
    foreach (['brandLookupMap', 'categoryLookupMap', 'originLookupMap', 'menuTreeCache'] as $name) {
        $ref->getProperty($name)->setValue(null, null);
    }
    $ref->getProperty('lookupLoadedFromDb')->setValue(null, []);
}

function callPrivate(object $object, string $method, array $args)
{
    return (new ReflectionMethod($object, $method))->invokeArgs($object, $args);
}

function hasHeavyField(array $items): bool
{
    foreach ($items as $item) {
        foreach (['mo_ta', 'thanh_phan_full', 'hdsd'] as $field) {
            if (array_key_exists($field, (array)$item)) {
                return true;
            }
        }
    }
    return false;
}

function itemsHaveKeys(array $items, array $keys): bool
{
    foreach ($items as $item) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, (array)$item)) {
                return false;
            }
        }
    }
    return true;
}

$runner = new QueryCacheTestRunner();
$testDir = sys_get_temp_dir() . '/pqc-test-' . getmypid();
ProductQueryCache::setDirectory($testDir);

try {
    // ---------- Unit: ProductQueryCache ----------
    ProductQueryCache::flush();

    $calls = 0;
    $producer = function () use (&$calls): array {
        $calls++;
        return ['a' => 1, 'b' => [1, 2, 3]];
    };
    $first = ProductQueryCache::remember('unit:basic', 60, $producer);
    $second = ProductQueryCache::remember('unit:basic', 60, $producer);
    $runner->check('U1', 'remember() goi producer 1 lan va tra cung gia tri o lan 2', $calls === 1 && $first === $second && $first['a'] === 1, "calls={$calls}");

    $files = cacheFiles($testDir);
    $runner->check(
        'U2',
        'ten file la sha1(...).cache va khong con file .tmp sau khi ghi',
        count($files) === 1 && preg_match('/^[0-9a-f]{40}\.cache$/', basename($files[0])) === 1 && count(cacheFiles($testDir, '*.tmp')) === 0,
        'files=' . implode(',', array_map('basename', $files))
    );

    // Het han: lui mtime ve qua khu xa
    touch($files[0], time() - 1000);
    clearstatcache();
    ProductQueryCache::remember('unit:basic', 60, $producer);
    $runner->check('U3', 'het TTL thi goi lai producer', $calls === 2, "calls={$calls}");

    // TTL ngan: file cu hon TTL bi bo qua, file moi con hieu luc
    ProductQueryCache::remember('unit:basic', 60, $producer);
    $runner->check('U4', 'file moi ghi van con hieu luc (khong goi lai producer)', $calls === 2, "calls={$calls}");

    // [] va null khong bao gio duoc cache
    ProductQueryCache::flush();
    $emptyCalls = 0;
    $emptyProducer = function () use (&$emptyCalls): array {
        $emptyCalls++;
        return [];
    };
    ProductQueryCache::remember('unit:empty', 60, $emptyProducer);
    ProductQueryCache::remember('unit:empty', 60, $emptyProducer);
    $nullCalls = 0;
    $nullProducer = function () use (&$nullCalls) {
        $nullCalls++;
        return null;
    };
    ProductQueryCache::remember('unit:null', 60, $nullProducer);
    ProductQueryCache::remember('unit:null', 60, $nullProducer);
    ProductQueryCache::put('unit:put-empty', []);
    ProductQueryCache::put('unit:put-null', null);
    $runner->check(
        'U5',
        '[] va null khong duoc cache (remember va put)',
        $emptyCalls === 2 && $nullCalls === 2 && count(cacheFiles($testDir)) === 0,
        "emptyCalls={$emptyCalls} nullCalls={$nullCalls} files=" . count(cacheFiles($testDir))
    );

    // flush() xoa het
    ProductQueryCache::put('unit:f1', ['x' => 1]);
    ProductQueryCache::put('unit:f2', ['y' => 2]);
    $beforeFlush = count(cacheFiles($testDir));
    ProductQueryCache::flush();
    $runner->check('U6', 'flush() xoa toan bo file cache', $beforeFlush === 2 && count(cacheFiles($testDir)) === 0, "before={$beforeFlush}");

    // Exception tu producer lan truyen nguyen ven va khong ghi gi
    $caught = null;
    try {
        ProductQueryCache::remember('unit:throw', 60, function () {
            throw new RuntimeException('atlas down');
        });
    } catch (Throwable $e) {
        $caught = $e;
    }
    $runner->check(
        'U7',
        'exception cua producer lan truyen nguyen ven, khong ghi cache',
        $caught instanceof RuntimeException && $caught->getMessage() === 'atlas down' && count(cacheFiles($testDir)) === 0,
        $caught ? get_class($caught) . ': ' . $caught->getMessage() : 'khong co exception'
    );

    // File hong / sai dinh dang = cache miss
    ProductQueryCache::put('unit:corrupt', ['ok' => true]);
    $files = cacheFiles($testDir);
    file_put_contents($files[0], 'khong phai serialize');
    $corruptCalls = 0;
    $corruptProducer = function () use (&$corruptCalls): array {
        $corruptCalls++;
        return ['ok' => true];
    };
    ProductQueryCache::remember('unit:corrupt', 60, $corruptProducer);
    file_put_contents($files[0], serialize('sai dinh dang'));
    clearstatcache();
    ProductQueryCache::remember('unit:corrupt', 60, $corruptProducer);
    $runner->check('U8', 'file hong hoac sai dinh dang duoc coi la cache miss', $corruptCalls === 2, "calls={$corruptCalls}");

    // Gia tri chua cac class MongoDB phai round-trip duoc
    ProductQueryCache::flush();
    $oid = new MongoDB\BSON\ObjectId();
    $mongoValue = [
        '_id' => $oid,
        'ngay_tao' => new MongoDB\BSON\UTCDateTime(1700000000000),
        'link_hinh_anh' => new MongoDB\Model\BSONArray(['a', 'b']),
        'meta' => new MongoDB\Model\BSONDocument(['k' => 'v']),
    ];
    ProductQueryCache::put('unit:mongo', $mongoValue);
    $roundTrip = ProductQueryCache::remember('unit:mongo', 60, function () {
        throw new RuntimeException('producer khong duoc goi');
    });
    $runner->check(
        'U9',
        'gia tri chua ObjectId/UTCDateTime/BSONArray/BSONDocument round-trip nguyen ven',
        is_array($roundTrip)
            && $roundTrip['_id'] instanceof MongoDB\BSON\ObjectId && (string)$roundTrip['_id'] === (string)$oid
            && $roundTrip['ngay_tao'] instanceof MongoDB\BSON\UTCDateTime
            && $roundTrip['link_hinh_anh'] instanceof MongoDB\Model\BSONArray
            && $roundTrip['meta'] instanceof MongoDB\Model\BSONDocument
            && serialize($roundTrip) === serialize($mongoValue)
    );
    ProductQueryCache::flush();

    // ---------- Integration: SanPham tren Atlas (chi doc) ----------
    $integrationReady = $bootstrapError === '' && isset($db) && class_exists('SanPham');
    if (!$integrationReady) {
        $runner->check('I0', 'nap app/config/db.php va SanPham', false, $bootstrapError);
    }

    if ($integrationReady) {
        $sp = new SanPham($db);
        $forbidden = new SanPham(new ForbiddenDb());
        $heavy = ['mo_ta', 'thanh_phan_full', 'hdsd'];

        // latest(): lanh vs nong
        ProductQueryCache::flush();
        resetSanPhamStatics();
        $cold = $sp->latest(12, true, true);
        $filesAfterCold = count(cacheFiles($testDir));
        resetSanPhamStatics();
        try {
            $warm = $forbidden->latest(12, true, true); // khong duoc cham DB
            $warmError = '';
        } catch (Throwable $e) {
            $warm = [];
            $warmError = $e->getMessage();
        }
        $runner->check('I1', 'latest(12,true,true) co du lieu va duoc phuc vu tu cache khi nong (khong truy cap DB)', count($cold) > 0 && $warmError === '' && $filesAfterCold >= 1, "cold=" . count($cold) . " warmError={$warmError}");
        $runner->check('I2', 'latest(): serialize() lanh == nong', serialize($cold) === serialize($warm));
        $runner->check('I3', 'latest(): khong item nao co mo_ta/thanh_phan_full/hdsd', !hasHeavyField($cold));
        $runner->check('I4', 'latest(): item van co ten_san_pham, gia_ban, link_hinh_anh, thuong_hieu', itemsHaveKeys($cold, ['ten_san_pham', 'gia_ban', 'link_hinh_anh', 'thuong_hieu']));
        $coldBrands = array_map(static fn($i) => (string)((array)$i)['thuong_hieu'], $cold);
        $warmBrands = array_map(static fn($i) => (string)((array)$i)['thuong_hieu'], $warm);
        $runner->check('I5', 'latest(): thuong_hieu (tu lookup) lanh == nong va co ten that', $coldBrands === $warmBrands && count(array_filter($coldBrands, static fn($b) => $b !== '')) > 0);

        // getHomepageProductSections(): flashDeals + forYou
        ProductQueryCache::flush();
        resetSanPhamStatics();
        $coldSections = $sp->getHomepageProductSections(8, [], null);
        resetSanPhamStatics();
        $warmSections = $sp->getHomepageProductSections(8, [], null);
        $runner->check(
            'I6',
            'getHomepageProductSections: flashDeals va forYou lanh == nong',
            serialize($coldSections['flashDeals']) === serialize($warmSections['flashDeals'])
                && serialize($coldSections['forYou']) === serialize($warmSections['forYou'])
                && count($coldSections['forYou']) > 0
        );
        $runner->check('I7', 'getHomepageProductSections: flashDeals/forYou khong co 3 truong nang', !hasHeavyField($coldSections['flashDeals']) && !hasHeavyField($coldSections['forYou']));

        // getCollectionProducts()
        $collection = $sp->getCollectionProducts('best_seller', [], 1, 20);
        $runner->check(
            'I8',
            "getCollectionProducts('best_seller') co total > 0, items khong co 3 truong nang, giu nguyen shape",
            (int)$collection['total'] > 0 && count($collection['items']) > 0 && !hasHeavyField($collection['items'])
                && ($collection['type'] ?? null) === 'best_seller' && ($collection['page'] ?? null) === 1 && ($collection['perPage'] ?? null) === 20 && isset($collection['pages'])
        );
        $discount = $sp->getDiscountProducts([], 10);
        $runner->check('I9', 'findDiscoveryProducts (qua getDiscountProducts) khong co 3 truong nang', count($discount) > 0 && !hasHeavyField($discount) && itemsHaveKeys($discount, ['ten_san_pham', 'gia_ban']));

        // menuTree(): lanh vs nong
        ProductQueryCache::flush();
        resetSanPhamStatics();
        $coldTree = $sp->menuTree();
        resetSanPhamStatics();
        try {
            $warmTree = $forbidden->menuTree();
            $treeError = '';
        } catch (Throwable $e) {
            $warmTree = [];
            $treeError = $e->getMessage();
        }
        $runner->check('I10', 'menuTree(): lanh == nong, nong khong truy cap DB', count($coldTree) > 0 && $treeError === '' && serialize($coldTree) === serialize($warmTree), "treeError={$treeError}");

        // Lookup: ten thuong hieu khop truy van truc tiep
        $productRow = $db->san_pham->findOne(['ma_thuong_hieu' => ['$exists' => true]], ['projection' => ['ma_san_pham' => 1, 'ma_thuong_hieu' => 1]]);
        $brandRow = $productRow ? $db->thuong_hieu->findOne(['ma_thuong_hieu' => $productRow['ma_thuong_hieu']], ['projection' => ['ten_thuong_hieu' => 1]]) : null;
        $runner->check('I11', 'tim duoc san pham mau co thuong hieu de doi chieu', $productRow !== null && $brandRow !== null && trim((string)($brandRow['ten_thuong_hieu'] ?? '')) !== '');

        if ($productRow !== null && $brandRow !== null) {
            $productId = (string)$productRow['ma_san_pham'];
            $expectedBrand = (string)$brandRow['ten_thuong_hieu'];

            ProductQueryCache::flush();
            resetSanPhamStatics();
            $directFound = $sp->findById($productId);
            $runner->check('I12', 'ten thuong hieu cua san pham thuc == ket qua truy van truc tiep thuong_hieu', is_array($directFound) && $directFound['thuong_hieu'] === $expectedBrand, 'got=' . var_export($directFound['thuong_hieu'] ?? null, true));

            // Map lookup cu/sai trong cache -> nap lai tu DB dung 1 lan va sua cache
            ProductQueryCache::flush();
            ProductQueryCache::put('lookup:thuong_hieu', ['__bogus__' => 'Thuong hieu gia']);
            resetSanPhamStatics();
            $afterBogus = $sp->findById($productId);
            $runner->check('I13', 'map lookup cu trong cache: thuong_hieu van duoc giai quyet dung (nap lai tu DB)', is_array($afterBogus) && $afterBogus['thuong_hieu'] === $expectedBrand, 'got=' . var_export($afterBogus['thuong_hieu'] ?? null, true));

            $repairedMap = ProductQueryCache::remember('lookup:thuong_hieu', 600, function () {
                throw new RuntimeException('cache chua duoc sua');
            });
            $runner->check('I14', 'sau khi nap lai, cache lookup chua map dung (co ma that, khong con muc gia)', is_array($repairedMap) && isset($repairedMap[(string)$productRow['ma_thuong_hieu']]) && !isset($repairedMap['__bogus__']));

            // Cache day du: ma biet -> 0 truy van thuong_hieu; ma khong ton tai -> dung 1 lan nap lai/request
            resetSanPhamStatics();
            $counting = new CountingDb($db);
            $countingModel = new SanPham($counting);
            $knownName = callPrivate($countingModel, 'getBrandName', [$productRow['ma_thuong_hieu']]);
            $hitsKnown = $counting->hits['thuong_hieu'] ?? 0;
            $runner->check('I15', 'cache lookup day du: ma biet khong truy van collection thuong_hieu', $knownName === $expectedBrand && $hitsKnown === 0, "name={$knownName} hits={$hitsKnown}");

            $unknownA = callPrivate($countingModel, 'getBrandName', ['ma-khong-ton-tai-1']);
            $unknownB = callPrivate($countingModel, 'getBrandName', ['ma-khong-ton-tai-2']);
            $hitsUnknown = $counting->hits['thuong_hieu'] ?? 0;
            $runner->check('I16', 'ma khong ton tai tra ve chuoi rong va chi nap lai tu DB toi da 1 lan/request', $unknownA === '' && $unknownB === '' && $hitsUnknown === 1, "hits={$hitsUnknown}");

            $emptyId = callPrivate($countingModel, 'getBrandName', ['']) . callPrivate($countingModel, 'getCategoryName', [null]) . callPrivate($countingModel, 'getOriginName', ['']);
            $runner->check('I17', 'id rong/null van tra ve chuoi rong (giu hanh vi cu)', $emptyId === '');

            // clearLookupCache() phai flush cache file
            ProductQueryCache::put('unit:marker', ['x' => 1]);
            SanPham::clearLookupCache();
            $runner->check('I18', 'clearLookupCache() xoa ca file cache', count(cacheFiles($testDir)) === 0);

            // clearSimpleRecommenderCache() cung phai flush cache file. Ham nay xoa file JSON cua recommender
            // nen sao luu noi dung truoc va khoi phuc nguyen ven sau khi kiem tra.
            $simpleCacheFile = __DIR__ . '/../app/content/simple_recommender_cache.json';
            $simpleCacheBackup = is_file($simpleCacheFile) ? file_get_contents($simpleCacheFile) : false;
            ProductQueryCache::put('unit:marker2', ['x' => 1]);
            try {
                SanPham::clearSimpleRecommenderCache();
            } finally {
                if (is_string($simpleCacheBackup)) {
                    file_put_contents($simpleCacheFile, $simpleCacheBackup);
                }
            }
            $runner->check('I19', 'clearSimpleRecommenderCache() xoa ca file cache', count(cacheFiles($testDir)) === 0);
        }
    }
} finally {
    ProductQueryCache::setDirectory(null);
    removeDirectory($testDir);
}

exit($runner->summary());

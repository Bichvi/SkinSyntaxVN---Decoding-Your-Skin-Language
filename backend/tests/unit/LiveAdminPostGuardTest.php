<?php
declare(strict_types=1);

// Behavioral regression for the shared guard used by the five admin livestream mutations.
// The child process invokes the real private helper; fixture views prevent a denied request
// from loading the full application layout or touching a database.

if (($argv[1] ?? '') === '--child') {
    $scenario = (string)($argv[2] ?? 'guest');
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'skinsyntax_live_guard_' . getmypid();
    foreach (['layouts', 'admin/layouts', 'admin'] as $dir) {
        @mkdir($fixtureRoot . DIRECTORY_SEPARATOR . $dir, 0777, true);
    }
    file_put_contents($fixtureRoot . '/layouts/header.php', '');
    file_put_contents($fixtureRoot . '/layouts/footer.php', '');
    file_put_contents($fixtureRoot . '/admin/layouts/header.php', '');
    file_put_contents($fixtureRoot . '/admin/layouts/footer.php', '');
    file_put_contents($fixtureRoot . '/admin/403.php', '<?php echo "DENIED_VIEW";');

    define('BASE_URL', 'http://localhost');
    define('VIEW_DIR', $fixtureRoot);

    function is_logged_in(): bool {
        global $scenario;
        return $scenario !== 'guest';
    }
    function current_role(): string {
        global $scenario;
        return match ($scenario) {
            'customer' => 'khach_hang',
            'staff' => 'nhanvien',
            default => 'admin',
        };
    }
    function current_user(): array {
        return ['ma_nv' => 0, 'email' => 'guard-test@example.test'];
    }
    function set_flash(string $type, string $message): void {}
    function redirect(string $url): void {
        throw new RuntimeException('REDIRECT');
    }

    require_once __DIR__ . '/../../app/controllers/QuanTriController.php';

    $_SESSION = ['live_csrf' => 'valid-token'];
    $_SERVER['REQUEST_METHOD'] = $scenario === 'get' ? 'GET' : 'POST';
    $_POST = ['csrf' => $scenario === 'bad_csrf' ? 'wrong-token' : 'valid-token'];

    $emptyCollection = new class {
        public function find(array $filter = [], array $options = []): Traversable { return new ArrayIterator([]); }
        public function aggregate(array $pipeline = [], array $options = []): object {
            return new class implements IteratorAggregate {
                public function toArray(): array { return []; }
                public function getIterator(): Traversable { return new ArrayIterator([]); }
            };
        }
        public function countDocuments(array $filter = []): int { return 0; }
        public function findOne(array $filter = [], array $options = []): ?array { return null; }
    };
    $fakeDb = new class($emptyCollection) {
        public function __construct(private object $collection) {}
        public function __get(string $name): object { return $this->collection; }
    };

    try {
        // The gate itself does not use persistence for these identities. Skipping the
        // production constructor keeps this an authorization test, not a Mongo test.
        $controller = (new ReflectionClass(QuanTriController::class))->newInstanceWithoutConstructor();
        $modelProperty = new ReflectionProperty(QuanTriController::class, 'model');
        $modelProperty->setAccessible(true);
        $modelProperty->setValue($controller, new QuanTri($fakeDb));
        $guard = new ReflectionMethod(QuanTriController::class, 'requireLiveAdminPost');
        $guard->setAccessible(true);
        $guard->invoke($controller);
        echo 'ALLOWED';
    } catch (Throwable $error) {
        echo str_contains($error->getMessage(), 'REDIRECT')
            ? 'DENIED_REDIRECT'
            : 'ERROR:' . get_class($error) . ':' . $error->getMessage();
    }
    exit;
}

require_once __DIR__ . '/../../app/controllers/QuanTriController.php';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' - ' . $detail) . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$runScenario = static function (string $scenario): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($scenario);
    $lines = [];
    $code = 0;
    exec($command, $lines, $code);
    return trim(implode("\n", $lines));
};

foreach (['guest', 'customer', 'staff', 'get', 'bad_csrf'] as $scenario) {
    $output = $runScenario($scenario);
    $deniedMarker = str_contains($output, 'DENIED') || str_contains($output, 'không có quyền truy cập');
    $check($scenario . ' is denied', !str_contains($output, 'ALLOWED') && $deniedMarker, $output);
}
$validOutput = $runScenario('valid');
$check('admin POST with valid CSRF is allowed', $validOutput === 'ALLOWED', $validOutput);

$controllerSource = file(__DIR__ . '/../../app/controllers/QuanTriController.php') ?: [];
foreach (['adminLiveCreate', 'adminLiveEdit', 'adminLiveStatus', 'adminLiveDelete', 'adminLiveUpdateRecording'] as $method) {
    $reflection = new ReflectionMethod(QuanTriController::class, $method);
    $body = implode('', array_slice(
        $controllerSource,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
    $check($method . ' delegates to shared guard', str_contains($body, 'requireLiveAdminPost()'));
}

echo PHP_EOL . "Live admin guard tests: {$pass} passed, {$fail} failed." . PHP_EOL;
exit($fail > 0 ? 1 : 0);

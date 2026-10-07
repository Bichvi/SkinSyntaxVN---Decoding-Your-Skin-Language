<?php
$autoloadCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
    dirname(__DIR__, 3) . '/vendor/autoload.php',
];


foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

if (!function_exists('ss_env') && is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (!class_exists('MongoDatabaseCompat')) {
    class MongoDatabaseCompat {
        private \MongoDB\Database $database;

        public function __construct(\MongoDB\Database $database) {
            $this->database = $database;
        }

        public function __get(string $name) {
            return $this->database->selectCollection($name);
        }

        public function __call(string $method, array $args) {
            return $this->database->{$method}(...$args);
        }

        public function raw(): \MongoDB\Database {
            return $this->database;
        }
    }
}

try {
    if (!class_exists('\\MongoDB\\Client')) {
        throw new RuntimeException('MongoDB PHP library not found. Install mongodb/mongodb or restore vendor/autoload.php.');
    }

    $dbMode = strtolower(trim((string)(function_exists('ss_env') ? ss_env('DB_MODE', '') : (getenv('DB_MODE') ?: ''))));
    $mongoDbName = function_exists('ss_env')
        ? ss_env('MONGO_DB_NAME', ss_env('MONGO_DB', 'skinsyntax'))
        : (getenv('MONGO_DB_NAME') ?: (getenv('MONGO_DB') ?: 'skinsyntax'));

    if ($dbMode === 'local') {
        // Chỉ kết nối Local MongoDB khi có cấu hình tường minh DB_MODE=local
        $mongoUri = file_exists('/.dockerenv') ? 'mongodb://mongodb:27017' : 'mongodb://127.0.0.1:27018';
        $activeMode = 'local';
    } else {
        // Mặc định hoặc DB_MODE=atlas: Dùng canonical Atlas URI từ MONGO_URI
        $mongoUri = function_exists('ss_env') ? ss_env('MONGO_URI', '') : (getenv('MONGO_URI') ?: '');
        if ($mongoUri === '') {
            $mongoUri = 'mongodb://127.0.0.1:27017';
        }
        // Chỉ replace Docker hostname khi KHÔNG dùng MongoDB Atlas (mongodb+srv://)
        if (!str_starts_with($mongoUri, 'mongodb+srv://') && str_contains($mongoUri, '://mongodb:')) {
            if (PHP_OS_FAMILY === 'Windows' || !file_exists('/.dockerenv')) {
                $mongoUri = str_replace('://mongodb:', '://127.0.0.1:', $mongoUri);
            }
        }
        $activeMode = str_starts_with($mongoUri, 'mongodb+srv://') ? 'atlas' : 'local';
    }

    defined('MONGO_URI') || define('MONGO_URI', $mongoUri);
    defined('MONGO_DB_NAME') || define('MONGO_DB_NAME', $mongoDbName);
    defined('MONGO_DB') || define('MONGO_DB', $mongoDbName);
    defined('DB_MODE') || define('DB_MODE', $activeMode);

    $driverOptions = [
        'serverSelectionTimeoutMS' => 5000,
        'connectTimeoutMS' => 5000,
    ];

    try {
        $client = new MongoDB\Client($mongoUri, [], $driverOptions);
        // Ping kiểm tra kết nối với DB chỉ định.
        $client->selectDatabase($mongoDbName)->command(['ping' => 1]);
    } catch (Throwable $connEx) {
        // Không silent fallback sang local. Nếu Atlas lỗi thì fail rõ ràng để xử lý.
        throw new RuntimeException("Ket noi MongoDB ($activeMode) that bai: " . $connEx->getMessage(), 0, $connEx);
    }

    $db = $client->selectDatabase($mongoDbName);
    $pdo = new MongoDatabaseCompat($db);
    $mongoClient = $client;
} catch (Throwable $e) {
    die('Loi ket noi MongoDB: ' . $e->getMessage());
}

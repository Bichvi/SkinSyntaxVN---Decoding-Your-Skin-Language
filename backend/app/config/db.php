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

    $mongoUri = function_exists('ss_env') ? ss_env('MONGO_URI', 'mongodb://127.0.0.1:27017') : (getenv('MONGO_URI') ?: 'mongodb://127.0.0.1:27017');
    // MONGO_DB_NAME là key chính; fallback sang MONGO_DB nếu không có (tương thích .env cũ)
    $mongoDbName = function_exists('ss_env')
        ? ss_env('MONGO_DB_NAME', ss_env('MONGO_DB', 'skinsyntax'))
        : (getenv('MONGO_DB_NAME') ?: (getenv('MONGO_DB') ?: 'skinsyntax'));

    // Chỉ replace Docker hostname khi KHÔNG dùng MongoDB Atlas (mongodb+srv://)
    // Atlas URI không cần và không được phép bị thay đổi
    if (!str_starts_with($mongoUri, 'mongodb+srv://') && str_contains($mongoUri, '://mongodb:')) {
        if (PHP_OS_FAMILY === 'Windows' || !file_exists('/.dockerenv')) {
            $mongoUri = str_replace('://mongodb:', '://127.0.0.1:', $mongoUri);
        }
    }

    defined('MONGO_URI') || define('MONGO_URI', $mongoUri);
    defined('MONGO_DB_NAME') || define('MONGO_DB_NAME', $mongoDbName);
    defined('MONGO_DB') || define('MONGO_DB', $mongoDbName);

    $client = new MongoDB\Client($mongoUri);
    $db = $client->selectDatabase($mongoDbName);
    $pdo = new MongoDatabaseCompat($db);
    $mongoClient = $client;
} catch (Throwable $e) {
    die('Loi ket noi MongoDB: ' . $e->getMessage());
}

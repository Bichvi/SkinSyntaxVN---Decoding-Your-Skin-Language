<?php
require_once __DIR__ . '/config.php';

$autoloadCandidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
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

    $mongoUri = defined('MONGO_URI') ? MONGO_URI : (getenv('MONGO_URI') ?: 'mongodb://127.0.0.1:27017');
    $mongoDb  = defined('MONGO_DB') ? MONGO_DB : (getenv('MONGO_DB') ?: 'skinsyntax');
    $client = new MongoDB\Client($mongoUri);
    $db = $client->selectDatabase($mongoDb);
    $pdo = new MongoDatabaseCompat($db);
    $mongoClient = $client;
} catch (Throwable $e) {
    die('Loi ket noi MongoDB: ' . $e->getMessage());
}

<?php
$autoloadCandidates = [
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

    $client = new MongoDB\Client('mongodb://127.0.0.1:27017');
    $db = $client->selectDatabase('skinsyntax');
    $pdo = new MongoDatabaseCompat($db);
    $mongoClient = $client;
} catch (Throwable $e) {
    die('Loi ket noi MongoDB: ' . $e->getMessage());
}

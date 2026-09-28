<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
global $db;

$service = new ContentBasedRecommender();
$startTime = microtime(true);
$cache = $service->buildIndex($db);
$elapsed = microtime(true) - $startTime;

echo "TF-IDF cache regenerated successfully in " . round($elapsed, 3) . "s\n";
echo "Total products: " . $cache['total_products'] . "\n";
echo "Vocabulary size: " . $cache['vocabulary_size'] . "\n";
echo "Total vectors: " . count($cache['vectors']) . "\n";
echo "Total prices: " . count($cache['prices']) . "\n";
echo "Total names: " . count($cache['names']) . "\n";

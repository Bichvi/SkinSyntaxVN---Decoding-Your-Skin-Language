<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
$helpers = is_file('/var/www/frontend/views/helpers.php') ? '/var/www/frontend/views/helpers.php' : '/var/www/html/app/helpers.php';
if (file_exists($helpers)) require_once $helpers;

global $db;
$model = new SanPham($db);

$latest = $model->latest(12, true, true);
$sections = $model->getHomepageProductSections(8);
$cats = [];
$homepageSections = $sections;
$menuCats = $model->menuTree();
$viewDir = '/var/www/frontend/views';

$t0 = microtime(true);
ob_start();
require $viewDir . '/layouts/header.php';
$tHeader = (microtime(true) - $t0) * 1000;
$t0 = microtime(true);
require $viewDir . '/home.php';
$tHome = (microtime(true) - $t0) * 1000;
$t0 = microtime(true);
require $viewDir . '/layouts/footer.php';
$tFooter = (microtime(true) - $t0) * 1000;
ob_end_clean();

echo "--- VIEW RENDERING TIMING ---\n";
echo "header.php: " . round($tHeader, 2) . " ms\n";
echo "home.php:   " . round($tHome, 2) . " ms\n";
echo "footer.php: " . round($tFooter, 2) . " ms\n";
echo "Total view rendering: " . round($tHeader + $tHome + $tFooter, 2) . " ms\n";

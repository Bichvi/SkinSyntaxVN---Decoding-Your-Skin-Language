<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$controller = (string)file_get_contents($root . '/backend/app/controllers/HomeController.php');
$view = (string)file_get_contents($root . '/frontend/views/home.php');
$script = (string)file_get_contents($root . '/frontend/public/assets/js/home-live-showcase.js');
$css = (string)file_get_contents($root . '/frontend/public/assets/css/style.css');

$failed = 0;
function homeLiveCheck(bool $condition, string $label): void
{
    global $failed;
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$condition) $failed++;
}

homeLiveCheck(str_contains($controller, "array_reverse((array)(\$homeLiveSession['danh_sach_deal'] ?? []))"), 'recent live deals are read newest first');
homeLiveCheck(str_contains($controller, "count(\$recentByProduct) >= 4"), 'live deal showcase is bounded to four products');
homeLiveCheck(str_contains($controller, "\$this->model->findManyByIds(array_keys(\$recentByProduct))"), 'live products are loaded in one catalog query');
homeLiveCheck(str_contains($controller, "foreach ((array)\$latest as \$product)"), 'newest catalog products fill sparse live rooms');
homeLiveCheck(str_contains($controller, "isset(\$showcaseIds[\$productId])"), 'duplicate products are excluded');
homeLiveCheck(str_contains($controller, "'homeLiveProducts' => \$homeLiveProducts"), 'product collection is passed to the homepage');
homeLiveCheck(str_contains($view, 'foreach ($homeLiveProducts as $index => $homeLiveProduct)'), 'homepage renders every showcase product');
homeLiveCheck(str_contains($view, 'data-home-live-prev') && str_contains($view, 'data-home-live-next'), 'showcase exposes accessible navigation controls');
homeLiveCheck(str_contains($script, "event.key !== 'ArrowLeft'") && str_contains($script, 'viewport.scrollTo({'), 'showcase supports keyboard and contained scroll navigation');
homeLiveCheck(str_contains($css, 'scroll-snap-type: x mandatory') && str_contains($css, '@media (prefers-reduced-motion: reduce)'), 'showcase is touch-friendly and respects reduced motion');

if ($failed > 0) {
    echo PHP_EOL . $failed . ' CHECK(S) FAILED' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'ALL PASSED' . PHP_EOL;

<?php
declare(strict_types=1);

/**
 * Test render phía server của frontend/views/goiy.php: cảnh báo cặp hoạt chất xung đột
 * (conflict_warnings từ backend) phải được gán vào safety của từng bước trong
 * $amRoutine/$pmRoutine và hiện pill "Cần lưu ý" + khối cảnh báo trên card.
 *
 * Không cần DB. Notice/warning/deprecation được đổi thành ErrorException để thành FAIL.
 * Chạy trong container: php /var/www/html/tests/goiy_conflict_safety_test.php
 */

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

// Đường dẫn tương đối này đúng cả trong container (/var/www/html/tests -> /var/www/frontend) lẫn trên host.
$frontendDir = __DIR__ . '/../../frontend';
require_once $frontendDir . '/views/helpers.php';
require_once __DIR__ . '/../app/services/SkincareProductClassifier.php';

final class GoiyConflictSafetyTestRunner
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
        echo "\nGoiy conflict safety tests: {$this->passed}/{$total} passed, {$this->failed} failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

/**
 * Render goiy.php trong scope hàm riêng như BaseController::render (biến view là biến cục bộ),
 * trả về HTML và hai routine đã dựng để kiểm tra trực tiếp.
 */
function renderGoiy(array $llamaRecommendation): array
{
    $isLoggedIn = true;
    $showPublicDiscovery = false;
    $recommendationProfile = ['skin_type' => 'da dầu', 'concerns' => []];
    ob_start();
    try {
        include dirname(__DIR__, 2) . '/frontend/views/goiy.php';
    } finally {
        $html = (string)ob_get_clean();
    }
    return ['html' => $html, 'am' => $amRoutine ?? null, 'pm' => $pmRoutine ?? null];
}

/** Tìm bước có sản phẩm mang id cho trước trong một routine. */
function stepByProductId(?array $routine, string $productId): ?array
{
    foreach ($routine ?? [] as $step) {
        if ((string)($step['recommended_product']['id'] ?? '') === $productId) {
            return $step;
        }
    }
    return null;
}

/** Cắt HTML của card chứa sản phẩm (từ tên sản phẩm tới card kế tiếp). */
function cardHtmlFor(string $html, string $productName): string
{
    $start = strpos($html, htmlspecialchars($productName, ENT_QUOTES, 'UTF-8'));
    if ($start === false) {
        return '';
    }
    $cardStart = strrpos(substr($html, 0, $start), '<article');
    $cardStart = $cardStart === false ? $start : $cardStart;
    $cardEnd = strpos($html, '</article>', $start);
    return substr($html, $cardStart, ($cardEnd === false ? strlen($html) : $cardEnd) - $cardStart);
}

$runner = new GoiyConflictSafetyTestRunner();

$resolution = 'Không dùng Retinol cùng buổi với AHA; tách sáng/tối.';
$recommendation = [
    'ok' => true,
    'source' => 'skinsyntax',
    'am_routine' => [
        [
            'category' => 'cleanser',
            'step_order' => 1,
            'recommended_product' => [
                'id' => 'SP-AM-AHA',
                'name' => 'Sữa rửa mặt AHA Glycolic',
                'price' => 210000,
                'key_ingredients' => ['glycolic acid'],
            ],
        ],
        [
            'category' => 'moisturizer',
            'step_order' => 2,
            'recommended_product' => [
                'id' => 'SP-AM-CER',
                'name' => 'Kem dưỡng ẩm Ceramide',
                'price' => 320000,
                'key_ingredients' => ['ceramide'],
            ],
        ],
        [
            'category' => 'sunscreen',
            'step_order' => 3,
            'recommended_product' => [
                'id' => 'SP-AM-SPF',
                'name' => 'Kem chống nắng Không Thành Phần',
                'price' => 280000,
                'key_ingredients' => [],
            ],
        ],
    ],
    'pm_routine' => [
        [
            'category' => 'cleanser',
            'step_order' => 1,
            'recommended_product' => [
                'id' => 'SP-PM-GEL',
                'name' => 'Gel rửa mặt Dịu Nhẹ',
                'price' => 190000,
                'key_ingredients' => ['panthenol'],
            ],
        ],
        [
            'category' => 'treatment',
            'step_order' => 2,
            'recommended_product' => [
                'id' => 'SP-PM-RET',
                'name' => 'Serum Retinol 0.3%',
                'price' => 450000,
                'key_ingredients' => ['retinol'],
            ],
        ],
    ],
    'conflict_warnings' => [
        ['pair' => ['Retinol', 'AHA'], 'resolution' => $resolution],
    ],
];

try {
    $result = renderGoiy($recommendation);
    $am = $result['am'];
    $pm = $result['pm'];
    $html = $result['html'];

    $runner->check('G0', 'view dựng được cả routine sáng và tối', is_array($am) && count($am) === 3 && is_array($pm) && count($pm) === 2, json_encode([is_array($am) ? count($am) : null, is_array($pm) ? count($pm) : null]) ?: '');

    // G1-G2: sản phẩm khớp cặp trong conflict_warnings -> safety.level = 'warning' (cả hai buổi).
    $retinolStep = stepByProductId($pm, 'SP-PM-RET');
    $runner->check('G1.1', 'PM: Serum Retinol có safety.level = warning', ($retinolStep['safety']['level'] ?? null) === 'warning', json_encode($retinolStep['safety'] ?? null, JSON_UNESCAPED_UNICODE) ?: '');
    $runner->check('G1.2', 'PM: safety.text chứa resolution của backend', str_contains((string)($retinolStep['safety']['text'] ?? ''), $resolution));
    $ahaStep = stepByProductId($am, 'SP-AM-AHA');
    $runner->check('G2', 'AM: Sữa rửa mặt AHA có safety.level = warning', ($ahaStep['safety']['level'] ?? null) === 'warning', json_encode($ahaStep['safety'] ?? null, JSON_UNESCAPED_UNICODE) ?: '');

    // G3-G4: sản phẩm không khớp cặp giữ nguyên hành vi cũ.
    $runner->check('G3.1', 'AM: Kem dưỡng Ceramide (có thành phần, không khớp) vẫn safe', (stepByProductId($am, 'SP-AM-CER')['safety']['level'] ?? null) === 'safe');
    $runner->check('G3.2', 'PM: Gel rửa mặt (có thành phần, không khớp) vẫn safe', (stepByProductId($pm, 'SP-PM-GEL')['safety']['level'] ?? null) === 'safe');
    $runner->check('G4', 'AM: Kem chống nắng thiếu thành phần vẫn unknown', (stepByProductId($am, 'SP-AM-SPF')['safety']['level'] ?? null) === 'unknown');
    $runner->check('G5', 'step_order vẫn là số nguyên theo thứ tự', array_map(static fn(array $step) => $step['step_order'], $pm ?? []) === [1, 2]);

    // G6: HTML card hiện pill "Cần lưu ý" và khối cảnh báo xung đột.
    $retinolCard = cardHtmlFor($html, 'Serum Retinol 0.3%');
    $runner->check('G6.1', 'card Retinol render được', $retinolCard !== '');
    $runner->check('G6.2', 'card Retinol có pill "Cần lưu ý"', str_contains($retinolCard, 'step-card__pill is-warning') && str_contains($retinolCard, 'Cần lưu ý'));
    $runner->check('G6.3', 'card Retinol có khối cảnh báo với resolution', str_contains($retinolCard, 'step-card__safety is-warning') && str_contains($retinolCard, h($resolution)));
    $runner->check('G6.4', 'đúng 2 khối cảnh báo trên trang (AHA + Retinol)', substr_count($html, 'step-card__safety is-warning') === 2, (string)substr_count($html, 'step-card__safety is-warning'));
    $ceramideCard = cardHtmlFor($html, 'Kem dưỡng ẩm Ceramide');
    $runner->check('G6.5', 'card Ceramide không có pill "Cần lưu ý"', $ceramideCard !== '' && !str_contains($ceramideCard, 'Cần lưu ý'));
} catch (Throwable $e) {
    $runner->check('G', 'render goiy.php chạy không lỗi', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// G7: không có conflict_warnings -> không card nào bị đánh dấu warning.
try {
    $noConflict = $recommendation;
    unset($noConflict['conflict_warnings']);
    $plain = renderGoiy($noConflict);
    $runner->check('G7.1', 'không có conflict_warnings -> Retinol safe', (stepByProductId($plain['pm'], 'SP-PM-RET')['safety']['level'] ?? null) === 'safe');
    $runner->check('G7.2', 'không có conflict_warnings -> không có khối cảnh báo', !str_contains($plain['html'], 'step-card__safety is-warning'));
} catch (Throwable $e) {
    $runner->check('G7', 'render không xung đột chạy không lỗi', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

exit($runner->summary());

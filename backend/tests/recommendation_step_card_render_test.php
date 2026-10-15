<?php
declare(strict_types=1);

/**
 * Test render phía server của frontend/views/partials/recommendation_step_card.php
 * (box "Vì sao Syna chọn cho bạn", điểm % ở ô thay thế, payload JSON cho JS đổi sản phẩm).
 *
 * Không cần DB. Notice/warning/deprecation được đổi thành ErrorException để thành FAIL.
 * Chạy trong container: php /var/www/html/tests/recommendation_step_card_render_test.php
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

final class StepCardRenderTestRunner
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
        echo "\nRecommendation step card render tests: {$this->passed}/{$total} passed, {$this->failed} failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

/** Render partial trong scope hàm riêng để $step/$session/$stepKey không rò sang test khác. */
function renderStepCard(array $step, string $session = 'am', string $stepKey = 'am-1'): string
{
    ob_start();
    try {
        include dirname(__DIR__, 2) . '/frontend/views/partials/recommendation_step_card.php';
    } finally {
        $html = (string)ob_get_clean();
    }
    return $html;
}

function currentProductPayload(string $html): ?array
{
    if (preg_match('/<script type="application\/json" data-current-product>(.*?)<\/script>/su', $html, $m) !== 1) {
        return null;
    }
    $decoded = json_decode($m[1], true);
    return is_array($decoded) ? $decoded : null;
}

/** Trả về danh sách payload data-product của các nút thay thế (đã giải mã HTML entity). */
function alternativePayloads(string $html): array
{
    preg_match_all('/data-product="([^"]*)"/u', $html, $matches);
    $payloads = [];
    foreach ($matches[1] as $raw) {
        $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'), true);
        if (is_array($decoded)) {
            $payloads[] = $decoded;
        }
    }
    return $payloads;
}

function hasHiddenAttribute(string $html, string $marker): bool
{
    return preg_match('/<(?:div|ul)\b[^>]*' . preg_quote($marker, '/') . '[^>]*\bhidden\b[^>]*>/u', $html) === 1;
}

$runner = new StepCardRenderTestRunner();

$fit = [
    'skin' => ['label' => 'Da khô/Hỗn hợp khô', 'sensitivity' => 'ít kích ứng', 'note' => 'Sản phẩm ghi rõ dành cho nền da này.'],
    'concerns' => [
        ['id' => 'inflammatory_acne', 'label' => 'Mụn viêm, sưng đỏ', 'matched' => true, 'handled_by' => ''],
        ['id' => 'dry_flaky', 'label' => 'Da khô căng, bong tróc', 'matched' => false, 'handled_by' => 'Dưỡng ẩm'],
    ],
    'goal' => ['label' => 'Sạch mụn, giảm viêm', 'matched' => true],
    'solution' => 'Lấy sạch kem chống nắng, bụi bẩn và dầu thừa cuối ngày — gốc rễ của bít tắc.',
    'actives' => [
        ['name' => 'Chiết xuất mật ong lên men', 'benefit' => 'kháng khuẩn dịu nhẹ, làm dịu nốt mụn'],
        ['name' => 'Hệ dưỡng ẩm sâu ha (ha, super ha, nano ha)', 'benefit' => 'cấp nước, giữ ẩm cho da căng mịn'],
    ],
    'caution' => '',
];

$product = [
    'id' => 'SP001',
    'name' => 'Nước tẩy trang Test',
    'brand' => 'Thương hiệu Test',
    'price' => 195000,
    'match_score' => 92,
    'key_ingredients' => ['công nghệ micelle complex', 'chiết xuất mật ong lên men'],
    'reasons' => ['Lý do dự phòng khi sản phẩm không có fit'],
    'detail_url' => '/index.php?r=chitiet&id=SP001',
    'fit_explanation' => $fit,
];
$alternatives = [
    ['id' => 'SP002', 'name' => 'Tẩy trang thay thế 80', 'price' => 150000, 'match_score' => 80, 'fit_explanation' => $fit],
    ['id' => 'SP003', 'name' => 'Tẩy trang thay thế 100', 'price' => 160000, 'match_score' => 100],
];
$step = [
    'step_name' => 'Tẩy trang',
    'step_order' => 1,
    'category' => 'remover',
    'recommended_product' => $product,
    'alternatives' => $alternatives,
    'safety' => ['level' => 'safe', 'text' => ''],
];

// R1: có fit_explanation -> render box mới.
try {
    $html = renderStepCard($step);
    foreach ([
        'Vì sao Syna chọn cho bạn',
        'Tình trạng',
        'Da khô/Hỗn hợp khô',
        'ít kích ứng',
        'Mụn viêm, sưng đỏ',
        'bước Dưỡng ẩm đảm nhận',
        'Chiết xuất mật ong lên men',
        'Đúng mục tiêu: Sạch mụn, giảm viêm',
    ] as $needle) {
        $runner->check('R1', 'html chứa "' . $needle . '"', str_contains($html, $needle));
    }
    $runner->check('R1.9', 'không còn câu đếm điều kiện "Hỗ trợ 1/2"', !str_contains($html, 'Hỗ trợ 1/2'));
    $runner->check('R1.10', 'data-product-why bị hidden khi có fit', hasHiddenAttribute($html, 'data-product-why'));
    $runner->check('R1.11', 'data-product-fit không hidden khi có fit', !hasHiddenAttribute($html, 'data-product-fit'));
    $runner->check('R1.12', 'concern đã khớp có class is-matched, chưa khớp có is-pending', str_contains($html, 'class="is-matched"') && str_contains($html, 'class="is-pending"'));
    $runner->check('R1.13', 'tiêu đề cũ "Vì sao hợp với bạn" đã được thay', !str_contains($html, 'Vì sao hợp với bạn'));
} catch (Throwable $e) {
    $runner->check('R1', 'render có fit chạy không lỗi', false, $e->getMessage());
}

// R2: điểm % ở ô thay thế (bug rtrim: 80 -> "8%").
try {
    $html = $html ?? renderStepCard($step);
    $runner->check('R2.1', 'ô thay thế hiển thị >80%<', str_contains($html, '>80%<'));
    $runner->check('R2.2', 'ô thay thế hiển thị >100%<', str_contains($html, '>100%<'));
    $runner->check('R2.3', 'không có >8%<', !str_contains($html, '>8%<'));
    $runner->check('R2.4', 'không có >1%<', !str_contains($html, '>1%<'));
} catch (Throwable $e) {
    $runner->check('R2', 'kiểm tra điểm % chạy không lỗi', false, $e->getMessage());
}

// R3: payload JSON cho JS.
try {
    $current = currentProductPayload($html);
    $runner->check('R3.1', 'data-current-product decode được', $current !== null);
    $runner->check('R3.2', 'payload hiện tại có khoá fit là mảng', is_array($current['fit'] ?? null));
    $runner->check('R3.3', 'fit giữ concern.handled_by', ($current['fit']['concerns'][1]['handled_by'] ?? null) === 'Dưỡng ẩm', json_encode($current['fit']['concerns'] ?? null, JSON_UNESCAPED_UNICODE) ?: '');
    $runner->check('R3.4', 'fit chuẩn hoá: tối đa 2 actives, đủ khoá', count($current['fit']['actives'] ?? []) === 2 && array_keys($current['fit']) === ['skin', 'concerns', 'goal', 'solution', 'actives', 'caution']);

    $altPayloads = alternativePayloads($html);
    $runner->check('R3.5', 'có 2 payload thay thế', count($altPayloads) === 2, (string)count($altPayloads));
    $runner->check('R3.6', 'thay thế có fit -> mảng', is_array($altPayloads[0]['fit'] ?? null));
    $runner->check('R3.7', 'thay thế không có fit -> null (khoá vẫn tồn tại)', array_key_exists('fit', $altPayloads[1] ?? []) && $altPayloads[1]['fit'] === null);
} catch (Throwable $e) {
    $runner->check('R3', 'kiểm tra payload chạy không lỗi', false, $e->getMessage());
}

// R4: fallback khi cache cũ không có fit_explanation.
try {
    $legacyStep = $step;
    unset($legacyStep['recommended_product']['fit_explanation']);
    $legacyStep['recommended_product']['reasons'] = ['Lý do cũ A'];
    $legacyStep['alternatives'] = [];
    $legacyHtml = renderStepCard($legacyStep);
    $runner->check('R4.1', 'data-product-fit hidden khi không có fit', hasHiddenAttribute($legacyHtml, 'data-product-fit'));
    $runner->check('R4.2', 'danh sách lý do cũ vẫn hiển thị', str_contains($legacyHtml, 'Lý do cũ A'));
    $runner->check('R4.3', 'data-product-why không hidden khi thiếu fit', !hasHiddenAttribute($legacyHtml, 'data-product-why'));
    $legacyCurrent = currentProductPayload($legacyHtml);
    $runner->check('R4.4', 'payload hiện tại có fit = null', is_array($legacyCurrent) && array_key_exists('fit', $legacyCurrent) && $legacyCurrent['fit'] === null);
} catch (Throwable $e) {
    $runner->check('R4', 'fallback không fit chạy không lỗi', false, $e->getMessage());
}

// R5: fit rỗng (hồ sơ thiếu mọi trường) cũng rơi về danh sách lý do cũ.
try {
    $emptyStep = $step;
    $emptyStep['recommended_product']['fit_explanation'] = [
        'skin' => ['label' => '', 'sensitivity' => '', 'note' => ''],
        'concerns' => [],
        'goal' => null,
        'solution' => '',
        'actives' => [],
        'caution' => '',
    ];
    $emptyStep['recommended_product']['reasons'] = ['Lý do cũ B'];
    $emptyHtml = renderStepCard($emptyStep);
    $runner->check('R5.1', 'fit rỗng -> data-product-fit hidden', hasHiddenAttribute($emptyHtml, 'data-product-fit'));
    $runner->check('R5.2', 'fit rỗng -> hiện lý do cũ', str_contains($emptyHtml, 'Lý do cũ B'));
} catch (Throwable $e) {
    $runner->check('R5', 'fit rỗng chạy không lỗi', false, $e->getMessage());
}

// R6: ẩn từng hàng khi thiếu dữ liệu tương ứng; cảnh báo hiện khi có caution.
try {
    $partialStep = $step;
    $partialStep['recommended_product']['fit_explanation'] = [
        'skin' => ['label' => '', 'sensitivity' => '', 'note' => ''],
        'concerns' => [],
        'goal' => ['label' => 'Mục tiêu X', 'matched' => false],
        'solution' => 'Khóa ẩm và củng cố hàng rào bảo vệ da.',
        'actives' => [],
        'caution' => 'Có BHA (Salicylic Acid): bắt đầu 2–3 lần/tuần và dùng kem chống nắng ban ngày.',
    ];
    $partialHtml = renderStepCard($partialStep);
    $runner->check('R6.1', 'thiếu skin.label -> không có hàng Tình trạng', !str_contains($partialHtml, 'Tình trạng'));
    $runner->check('R6.2', 'concerns rỗng -> không có hàng Vấn đề', !str_contains($partialHtml, 'fit-explain__concerns'));
    $runner->check('R6.3', 'có solution -> hàng Giải pháp', str_contains($partialHtml, 'Giải pháp') && str_contains($partialHtml, 'Khóa ẩm và củng cố hàng rào bảo vệ da.'));
    $runner->check('R6.4', 'goal chưa khớp -> không hiện "Đúng mục tiêu"', !str_contains($partialHtml, 'Đúng mục tiêu'));
    $runner->check('R6.5', 'caution hiển thị', str_contains($partialHtml, 'fit-explain__caution') && str_contains($partialHtml, 'Có BHA (Salicylic Acid)'));
} catch (Throwable $e) {
    $runner->check('R6', 'ẩn hàng khi thiếu dữ liệu chạy không lỗi', false, $e->getMessage());
}

// R7: XSS - mọi chuỗi trong fit đều được escape.
try {
    $xssStep = $step;
    $xssStep['recommended_product']['fit_explanation']['skin']['label'] = '<script>x</script>';
    $xssHtml = renderStepCard($xssStep);
    $runner->check('R7.1', 'skin.label bị escape thành &lt;script&gt;', str_contains($xssHtml, '&lt;script&gt;'));
    $runner->check('R7.2', 'không có thẻ <script>x</script> thô', !str_contains($xssHtml, '<script>x</script>'));
} catch (Throwable $e) {
    $runner->check('R7', 'XSS chạy không lỗi', false, $e->getMessage());
}

exit($runner->summary());

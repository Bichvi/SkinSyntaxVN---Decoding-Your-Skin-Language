<?php
declare(strict_types=1);

/**
 * Test cho RecommendationExplainer (lời giải thích "Vì sao Syna chọn cho bạn").
 *
 * Service thuần PHP, không cần DB. Notice/warning/deprecation được đổi thành ErrorException để thành FAIL.
 * Chạy trong container: php /var/www/html/tests/recommendation_explainer_test.php
 */

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/../app/models/GoiYContentBased.php';
require_once __DIR__ . '/../app/services/RecommendationExplainer.php';

final class ExplainerTestRunner
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
        echo "\nRecommendation explainer tests: {$this->passed}/{$total} passed, {$this->failed} failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

function dumpJson($value): string
{
    return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
}

$runner = new ExplainerTestRunner();
$explainer = new RecommendationExplainer(new GoiYContentBased(null));

$removerSolution = 'Lấy sạch kem chống nắng, bụi bẩn và dầu thừa cuối ngày — gốc rễ của bít tắc.';

$sharedProfile = [
    'skin_type' => 'Da khô/Hỗn hợp khô',
    'concerns' => ['Mụn viêm', 'sưng đỏ', 'Da khô căng, bong tróc'],
    'goal' => 'Sạch mụn, giảm viêm',
    'sensitivity' => 'Khỏe mạnh, hiếm khi',
];
$sharedRow = [
    'routine_step' => 'remover',
    'key_ingredients' => [
        'công nghệ micelle complex',
        'chiết xuất mật ong lên men',
        'hệ vitamin (b3, c, e)',
        'hệ dưỡng ẩm sâu ha (ha, super ha, nano ha)',
    ],
    'evidence' => [
        'skin_type' => ['match' => true],
        'concerns' => ['matched' => ['inflammatory_acne']],
        'goal' => ['match' => true],
    ],
];

// T1: ca ảnh chụp màn hình.
try {
    $t1 = $explainer->explain($sharedProfile, $sharedRow);
    $runner->check('T1.1', 'skin: nhãn nguyên văn, độ nhạy cảm, ghi chú khớp loại da', $t1['skin'] === [
        'label' => 'Da khô/Hỗn hợp khô',
        'sensitivity' => 'ít kích ứng',
        'note' => 'Sản phẩm ghi rõ dành cho nền da này.',
    ], dumpJson($t1['skin']));
    $runner->check('T1.2', 'concerns: gom theo id, giữ thứ tự hồ sơ, matched theo evidence', $t1['concerns'] === [
        ['id' => 'inflammatory_acne', 'label' => 'Mụn viêm, sưng đỏ', 'matched' => true],
        ['id' => 'dry_flaky', 'label' => 'Da khô căng, bong tróc', 'matched' => false],
    ], dumpJson($t1['concerns']));
    $runner->check('T1.3', 'goal khớp mục tiêu', $t1['goal'] === ['label' => 'Sạch mụn, giảm viêm', 'matched' => true], dumpJson($t1['goal']));
    $runner->check('T1.4', 'solution theo bước remover', $t1['solution'] === $removerSolution, dumpJson($t1['solution']));
    $runner->check(
        'T1.5',
        'actives: 2 hoạt chất điểm cao nhất, dùng tên chip gốc',
        array_column($t1['actives'], 'name') === ['Chiết xuất mật ong lên men', 'Hệ dưỡng ẩm sâu ha (ha, super ha, nano ha)'],
        dumpJson($t1['actives'])
    );
    $runner->check('T1.6', 'actives[0].benefit lấy từ bảng KB', ($t1['actives'][0]['benefit'] ?? '') === 'kháng khuẩn dịu nhẹ, làm dịu nốt mụn', dumpJson($t1['actives'][0] ?? null));
    $runner->check('T1.7', 'caution rỗng khi không có hoạt chất mạnh', $t1['caution'] === '', dumpJson($t1['caution']));
    $runner->check('T1.8', 'đúng shape 6 khoá', array_keys($t1) === ['skin', 'concerns', 'goal', 'solution', 'actives', 'caution'], dumpJson(array_keys($t1)));
    echo 'T1 fit_explanation: ' . json_encode($t1, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    $runner->check('T1', 'ca ảnh chụp chạy không lỗi', false, $e->getMessage());
}

// T2: hoạt chất mạnh + da nhạy cảm.
try {
    $t2 = $explainer->explain(
        ['skin_type' => 'Da dầu/Hỗn hợp dầu', 'concerns' => ['Mụn ẩn, mụn đầu đen'], 'sensitivity' => 'Rất dễ'],
        ['routine_step' => 'treatment', 'key_ingredients' => [], 'thanh_phan_chinh' => 'Salicylic Acid 2%, Niacinamide']
    );
    $runner->check('T2.1', 'BHA đứng đầu actives', ($t2['actives'][0]['name'] ?? '') === 'BHA (Salicylic Acid)', dumpJson($t2['actives']));
    $runner->check(
        'T2.2',
        'caution cho da dễ kích ứng',
        $t2['caution'] === 'Có BHA (Salicylic Acid): da bạn dễ kích ứng, nên dùng cách ngày và thử trước ở vùng nhỏ.',
        dumpJson($t2['caution'])
    );
    $runner->check('T2.3', 'skin.sensitivity = dễ kích ứng', $t2['skin']['sensitivity'] === 'dễ kích ứng', dumpJson($t2['skin']));
} catch (Throwable $e) {
    $runner->check('T2', 'ca hoạt chất mạnh chạy không lỗi', false, $e->getMessage());
}

// T2b: hoạt chất mạnh nhưng da không nhạy cảm -> câu caution mặc định.
try {
    $t2b = $explainer->explain(
        ['skin_type' => 'Da dầu', 'concerns' => ['Mụn ẩn'], 'sensitivity' => 'Khỏe mạnh'],
        ['routine_step' => 'treatment', 'thanh_phan_chinh' => 'Salicylic Acid 2%']
    );
    $runner->check(
        'T2b',
        'caution mặc định cho da bình thường',
        $t2b['caution'] === 'Có BHA (Salicylic Acid): bắt đầu 2–3 lần/tuần và dùng kem chống nắng ban ngày.',
        dumpJson($t2b['caution'])
    );
} catch (Throwable $e) {
    $runner->check('T2b', 'caution mặc định chạy không lỗi', false, $e->getMessage());
}

// T3: hồ sơ và sản phẩm rỗng -> cấu trúc rỗng hợp lệ, không notice.
try {
    $t3 = $explainer->explain([], []);
    $runner->check('T3', 'explain([], []) trả cấu trúc rỗng', $t3 === [
        'skin' => ['label' => '', 'sensitivity' => '', 'note' => ''],
        'concerns' => [],
        'goal' => null,
        'solution' => '',
        'actives' => [],
        'caution' => '',
    ], dumpJson($t3));
} catch (Throwable $e) {
    $runner->check('T3', 'explain([], []) không phát sinh notice', false, $e->getMessage());
}

// T4: kem chống nắng -> Màng lọc UV, không nhầm sang Kẽm.
try {
    $t4 = $explainer->explain($sharedProfile, [
        'routine_step' => 'sunscreen',
        'thanh_phan_day_du' => 'Zinc Oxide, Titanium Dioxide',
    ]);
    $names = array_column($t4['actives'], 'name');
    $runner->check('T4.1', 'có Màng lọc UV', in_array('Màng lọc UV', $names, true), dumpJson($names));
    $runner->check('T4.2', 'không có Kẽm (Zinc) khi chỉ là Zinc Oxide', !in_array('Kẽm (Zinc)', $names, true), dumpJson($names));
} catch (Throwable $e) {
    $runner->check('T4', 'ca kem chống nắng chạy không lỗi', false, $e->getMessage());
}

// T5: bước routine theo vấn đề da.
try {
    $runner->check('T5.1', 'stepsForConcern(dry_flaky)', RecommendationExplainer::stepsForConcern('dry_flaky') === ['moisturizer', 'toner', 'treatment']);
    $runner->check('T5.2', 'stepsForConcern(id lạ) rỗng', RecommendationExplainer::stepsForConcern('xyz') === []);
} catch (Throwable $e) {
    $runner->check('T5', 'stepsForConcern chạy không lỗi', false, $e->getMessage());
}

// T6: công thức dùng cho mọi loại da.
try {
    $row6 = $sharedRow;
    $row6['evidence']['skin_type']['match'] = 'all_skin';
    $t6 = $explainer->explain($sharedProfile, $row6);
    $runner->check('T6', 'skin.note cho all_skin', $t6['skin']['note'] === 'Công thức dùng được cho mọi loại da.', dumpJson($t6['skin']));
} catch (Throwable $e) {
    $runner->check('T6', 'ca all_skin chạy không lỗi', false, $e->getMessage());
}

// T7: da nhạy cảm + mô tả dịu nhẹ, loại da không khớp.
try {
    $t7 = $explainer->explain(
        ['skin_type' => 'Da dầu', 'sensitivity' => 'Thỉnh thoảng'],
        ['mo_ta' => 'Kết cấu dịu nhẹ cho da nhạy cảm', 'evidence' => ['skin_type' => ['match' => false]]]
    );
    $runner->check('T7', 'skin.note ghi nhận công thức dịu nhẹ', $t7['skin']['note'] === 'Công thức dịu nhẹ, có ghi phù hợp da nhạy cảm.', dumpJson($t7['skin']));
    $runner->check('T7.2', 'skin.sensitivity = thỉnh thoảng kích ứng', $t7['skin']['sensitivity'] === 'thỉnh thoảng kích ứng', dumpJson($t7['skin']));
} catch (Throwable $e) {
    $runner->check('T7', 'ca da nhạy cảm chạy không lỗi', false, $e->getMessage());
}

// T8: chip không có trong bảng hoạt chất -> không bịa công dụng.
try {
    $t8 = $explainer->explain($sharedProfile, ['routine_step' => 'moisturizer', 'key_ingredients' => ['Hương hoa hồng']]);
    $runner->check('T8', 'chip lạ không sinh actives', $t8['actives'] === [], dumpJson($t8['actives']));
} catch (Throwable $e) {
    $runner->check('T8', 'ca chip lạ chạy không lỗi', false, $e->getMessage());
}

// T9: goal lạ -> goal null; concern lạ bị bỏ; không notice khi evidence thiếu.
try {
    $t9 = $explainer->explain(
        ['skin_type' => 'Da thường', 'concerns' => ['không rõ'], 'goal' => 'Mục tiêu lạ'],
        ['routine_step' => 'toner', 'evidence' => ['goal' => ['evaluable' => false]]]
    );
    $runner->check('T9.1', 'goal lạ -> null', $t9['goal'] === null, dumpJson($t9['goal']));
    $runner->check('T9.2', 'concern không map được bị bỏ', $t9['concerns'] === [], dumpJson($t9['concerns']));
    $runner->check('T9.3', 'solution toner', $t9['solution'] === 'Cân bằng da sau rửa mặt, giúp serum và kem dưỡng thấm tốt hơn.', dumpJson($t9['solution']));
} catch (Throwable $e) {
    $runner->check('T9', 'ca hồ sơ thiếu trường chạy không lỗi', false, $e->getMessage());
}

exit($runner->summary());

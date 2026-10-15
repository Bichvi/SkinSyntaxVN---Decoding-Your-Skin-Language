<?php
// backend/app/services/RecommendationExplainer.php

require_once __DIR__ . '/../models/GoiYContentBased.php';

/**
 * Dựng lời giải thích "Vì sao Syna chọn cho bạn" theo cấu trúc Tình trạng -> Vấn đề -> Giải pháp.
 *
 * Service thuần PHP, không truy cập DB. Dữ liệu lấy từ hồ sơ khảo sát và `evidence` mà
 * GoiYContentBased::evaluateCompatibility() đã tính sẵn. Hoạt chất chỉ được nhận diện từ trường
 * thành phần (key_ingredients, thanh_phan_chinh, thanh_phan_day_du), không đọc từ mô tả marketing.
 */
final class RecommendationExplainer
{
    private const MAX_ACTIVES = 2;
    private const MAX_ACTIVE_NAME_WIDTH = 42;

    // Câu mục đích của từng bước routine.
    private const STEP_SOLUTIONS = [
        'remover' => 'Lấy sạch kem chống nắng, bụi bẩn và dầu thừa cuối ngày — gốc rễ của bít tắc.',
        'cleanser' => 'Làm sạch dầu thừa, bụi bẩn trong lỗ chân lông, chuẩn bị nền da cho các bước sau.',
        'toner' => 'Cân bằng da sau rửa mặt, giúp serum và kem dưỡng thấm tốt hơn.',
        'treatment' => 'Đưa hoạt chất tập trung vào đúng vấn đề da bạn đang gặp.',
        'moisturizer' => 'Khóa ẩm và củng cố hàng rào bảo vệ da.',
        'sunscreen' => 'Chặn tia UV — nguyên nhân khiến thâm mụn đậm hơn và da lão hóa sớm.',
        'spot_treatment' => 'Tác động tập trung lên từng nốt mụn đang viêm.',
    ];

    // Các bước routine có thể xử lý từng vấn đề da (thứ tự = mức ưu tiên).
    private const CONCERN_STEPS = [
        'inflammatory_acne' => ['spot_treatment', 'treatment'],
        'comedonal_acne' => ['treatment', 'cleanser', 'toner'],
        'large_pores' => ['toner', 'treatment'],
        'hyperpigmentation' => ['treatment', 'sunscreen'],
        'aging' => ['treatment', 'sunscreen', 'moisturizer'],
        'dry_flaky' => ['moisturizer', 'toner', 'treatment'],
    ];

    /**
     * Bảng hoạt chất. Thứ tự dòng = thứ tự tie-break khi bằng điểm.
     * `keys` là regex (không dấu, chữ thường) khớp theo ranh giới từ; `strong` đánh dấu hoạt chất cần lưu ý khi dùng.
     */
    private const ACTIVES_KB = [
        ['keys' => 'zinc oxide|titanium dioxide|tinosorb|uvinul|avobenzone|octocrylene|homosalate|octinoxate|ethylhexyl methoxycinnamate', 'name' => 'Màng lọc UV', 'benefit' => 'chống tia UVA/UVB, ngừa thâm sạm', 'concerns' => ['hyperpigmentation', 'aging'], 'skins' => [], 'steps' => ['sunscreen'], 'strong' => false],
        ['keys' => 'salicylic|bha', 'name' => 'BHA (Salicylic Acid)', 'benefit' => 'làm sạch sâu lỗ chân lông, giảm mụn ẩn, đầu đen', 'concerns' => ['comedonal_acne', 'large_pores', 'inflammatory_acne'], 'skins' => ['oily', 'combination'], 'steps' => [], 'strong' => true],
        ['keys' => 'benzoyl peroxide', 'name' => 'Benzoyl Peroxide', 'benefit' => 'diệt khuẩn gây mụn viêm', 'concerns' => ['inflammatory_acne'], 'skins' => [], 'steps' => [], 'strong' => true],
        ['keys' => 'retinol|retinal|retinoid|tretinoin|adapalene', 'name' => 'Retinoid', 'benefit' => 'tái tạo da, giảm mụn và nếp nhăn', 'concerns' => ['aging', 'comedonal_acne', 'hyperpigmentation'], 'skins' => [], 'steps' => [], 'strong' => true],
        ['keys' => 'glycolic|lactic acid|mandelic|aha', 'name' => 'AHA', 'benefit' => 'tẩy tế bào chết, làm mịn và sáng da', 'concerns' => ['hyperpigmentation', 'aging', 'comedonal_acne'], 'skins' => [], 'steps' => [], 'strong' => true],
        ['keys' => 'azelaic', 'name' => 'Azelaic Acid', 'benefit' => 'giảm mụn viêm, mờ thâm đỏ', 'concerns' => ['inflammatory_acne', 'hyperpigmentation'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'niacinamide|vitamin b3|b3', 'name' => 'Niacinamide (B3)', 'benefit' => 'giảm viêm đỏ, mờ thâm, điều tiết dầu', 'concerns' => ['inflammatory_acne', 'hyperpigmentation', 'large_pores'], 'skins' => ['oily', 'combination'], 'steps' => [], 'strong' => false],
        ['keys' => 'tea tree|tram tra', 'name' => 'Tràm trà', 'benefit' => 'kháng khuẩn, giảm mụn viêm', 'concerns' => ['inflammatory_acne', 'comedonal_acne'], 'skins' => ['oily'], 'steps' => [], 'strong' => false],
        ['keys' => 'mat ong|honey|propolis|keo ong', 'name' => 'Mật ong / keo ong', 'benefit' => 'kháng khuẩn dịu nhẹ, làm dịu nốt mụn', 'concerns' => ['inflammatory_acne', 'dry_flaky'], 'skins' => ['dry'], 'steps' => [], 'strong' => false],
        ['keys' => 'centella|rau ma|madecassoside|asiaticoside|cica', 'name' => 'Rau má (Centella)', 'benefit' => 'làm dịu, giảm sưng đỏ, hỗ trợ phục hồi', 'concerns' => ['inflammatory_acne'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'zinc(?! oxide)', 'name' => 'Kẽm (Zinc)', 'benefit' => 'kiểm soát dầu, giảm mụn', 'concerns' => ['inflammatory_acne', 'comedonal_acne', 'large_pores'], 'skins' => ['oily'], 'steps' => [], 'strong' => false],
        ['keys' => 'sulfur|luu huynh', 'name' => 'Lưu huỳnh', 'benefit' => 'hút dầu, làm khô cồi mụn', 'concerns' => ['inflammatory_acne', 'comedonal_acne'], 'skins' => ['oily'], 'steps' => [], 'strong' => false],
        ['keys' => 'tranexamic', 'name' => 'Tranexamic Acid', 'benefit' => 'mờ thâm nám', 'concerns' => ['hyperpigmentation'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'arbutin', 'name' => 'Arbutin', 'benefit' => 'ức chế sắc tố, mờ thâm', 'concerns' => ['hyperpigmentation'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'vitamin c|ascorbic|ascorbyl', 'name' => 'Vitamin C', 'benefit' => 'làm sáng, mờ thâm, chống oxy hóa', 'concerns' => ['hyperpigmentation', 'aging'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'peptide', 'name' => 'Peptide', 'benefit' => 'hỗ trợ săn chắc, giảm nếp nhăn', 'concerns' => ['aging'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'bakuchiol', 'name' => 'Bakuchiol', 'benefit' => 'chống lão hóa dịu nhẹ', 'concerns' => ['aging'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'hyaluronic|hyaluronate|ha', 'name' => 'HA (Hyaluronic Acid)', 'benefit' => 'cấp nước, giữ ẩm cho da căng mịn', 'concerns' => ['dry_flaky'], 'skins' => ['dry', 'combination', 'normal'], 'steps' => [], 'strong' => false],
        ['keys' => 'ceramide', 'name' => 'Ceramide', 'benefit' => 'củng cố hàng rào da, giảm khô căng bong tróc', 'concerns' => ['dry_flaky'], 'skins' => ['dry'], 'steps' => ['moisturizer'], 'strong' => false],
        ['keys' => 'panthenol|vitamin b5|b5', 'name' => 'Panthenol (B5)', 'benefit' => 'làm dịu, phục hồi da khô căng', 'concerns' => ['dry_flaky', 'inflammatory_acne'], 'skins' => ['dry'], 'steps' => [], 'strong' => false],
        ['keys' => 'squalane|squalene', 'name' => 'Squalane', 'benefit' => 'khóa ẩm mỏng nhẹ', 'concerns' => ['dry_flaky'], 'skins' => ['dry'], 'steps' => ['moisturizer'], 'strong' => false],
        ['keys' => 'beta glucan', 'name' => 'Beta-glucan', 'benefit' => 'làm dịu, cấp ẩm', 'concerns' => ['dry_flaky', 'inflammatory_acne'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'allantoin', 'name' => 'Allantoin', 'benefit' => 'làm dịu, làm mềm da', 'concerns' => ['dry_flaky', 'inflammatory_acne'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'snail|oc sen', 'name' => 'Dịch ốc sên', 'benefit' => 'phục hồi, làm dịu, mờ thâm', 'concerns' => ['hyperpigmentation', 'dry_flaky'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'collagen', 'name' => 'Collagen', 'benefit' => 'giữ ẩm, hỗ trợ đàn hồi', 'concerns' => ['aging', 'dry_flaky'], 'skins' => [], 'steps' => [], 'strong' => false],
        ['keys' => 'tra xanh|green tea|camellia sinensis', 'name' => 'Trà xanh', 'benefit' => 'chống oxy hóa, kiểm soát dầu', 'concerns' => ['inflammatory_acne', 'large_pores'], 'skins' => ['oily'], 'steps' => [], 'strong' => false],
        ['keys' => 'amino acid|axit amin', 'name' => 'Amino acid', 'benefit' => 'làm sạch dịu nhẹ, hạn chế khô căng', 'concerns' => ['dry_flaky'], 'skins' => ['dry'], 'steps' => ['cleanser'], 'strong' => false],
        ['keys' => 'micelle|micellar', 'name' => 'Công nghệ Micellar', 'benefit' => 'cuốn sạch bụi bẩn, lớp trang điểm dịu nhẹ', 'concerns' => [], 'skins' => [], 'steps' => ['remover'], 'strong' => false],
        ['keys' => 'glycerin', 'name' => 'Glycerin', 'benefit' => 'hút ẩm, giữ da mềm', 'concerns' => ['dry_flaky'], 'skins' => ['dry'], 'steps' => [], 'strong' => false],
        ['keys' => 'vitamin e|tocopherol', 'name' => 'Vitamin E', 'benefit' => 'chống oxy hóa, dưỡng mềm da', 'concerns' => [], 'skins' => ['dry'], 'steps' => [], 'strong' => false],
    ];

    private const SENSITIVE_LEVELS = ['dễ kích ứng', 'thỉnh thoảng kích ứng'];

    private GoiYContentBased $taxonomy;

    public function __construct(GoiYContentBased $taxonomy)
    {
        $this->taxonomy = $taxonomy;
    }

    /** Danh sách bước routine có thể xử lý một vấn đề da; id lạ trả về mảng rỗng. */
    public static function stepsForConcern(string $concernId): array
    {
        return self::CONCERN_STEPS[$concernId] ?? [];
    }

    /**
     * Luôn trả đúng shape: skin / concerns / goal / solution / actives / caution.
     * Thiếu dữ liệu ở hồ sơ hoặc sản phẩm thì phần tương ứng rỗng, không phát sinh notice.
     */
    public function explain(array $profile, array $row): array
    {
        $evidence = is_array($row['evidence'] ?? null) ? $row['evidence'] : [];
        $sensitivity = $this->sensitivityLabel($profile['sensitivity'] ?? '');
        $concerns = $this->userConcerns($profile, $evidence);
        $matchedActives = $this->matchActives($row, $profile, array_column($concerns, 'id'));

        return [
            'skin' => $this->skin($profile, $row, $evidence, $sensitivity),
            'concerns' => $concerns,
            'goal' => $this->goal($profile, $evidence),
            'solution' => self::STEP_SOLUTIONS[$this->text($row['routine_step'] ?? '')] ?? '',
            'actives' => $this->pickActives($matchedActives),
            'caution' => $this->caution($matchedActives, $sensitivity),
        ];
    }

    private function skin(array $profile, array $row, array $evidence, string $sensitivity): array
    {
        $label = trim($this->text($profile['skin_type'] ?? ''));

        $skinMatch = is_array($evidence['skin_type'] ?? null) ? ($evidence['skin_type']['match'] ?? false) : false;
        $note = '';
        if ($skinMatch === true) {
            $note = 'Sản phẩm ghi rõ dành cho nền da này.';
        } elseif ($skinMatch === 'all_skin') {
            $note = 'Công thức dùng được cho mọi loại da.';
        }

        if (in_array($sensitivity, self::SENSITIVE_LEVELS, true)) {
            $copy = $this->normalize($this->text($row['ten_san_pham'] ?? '') . ' ' . $this->text($row['mo_ta'] ?? ''));
            if (preg_match('/nhay cam|diu nhe|lanh tinh/u', $copy) === 1) {
                $note = trim($note . ' Công thức dịu nhẹ, có ghi phù hợp da nhạy cảm.');
            }
        }

        return ['label' => $label, 'sensitivity' => $sensitivity, 'note' => $note];
    }

    /** Vấn đề da của người dùng, gom theo id chuẩn hoá và giữ thứ tự xuất hiện trong hồ sơ. */
    private function userConcerns(array $profile, array $evidence): array
    {
        $rawConcerns = $profile['concerns'] ?? [];
        if (is_string($rawConcerns)) {
            $rawConcerns = explode(',', $rawConcerns);
        }
        if (!is_array($rawConcerns)) {
            return [];
        }

        $matched = is_array($evidence['concerns']['matched'] ?? null) ? $evidence['concerns']['matched'] : [];

        $groups = [];
        foreach ($rawConcerns as $raw) {
            $raw = trim($this->text($raw));
            if ($raw === '') {
                continue;
            }
            $id = $this->taxonomy->canonicalConcerns([$raw])[0] ?? null;
            if ($id === null) {
                continue;
            }
            $groups[$id][] = $raw;
        }

        $concerns = [];
        foreach ($groups as $id => $raws) {
            $concerns[] = [
                'id' => (string)$id,
                'label' => $this->ucfirst(implode(', ', $raws)),
                'matched' => in_array($id, $matched, true),
            ];
        }
        return $concerns;
    }

    private function goal(array $profile, array $evidence): ?array
    {
        $goal = trim($this->text($profile['goal'] ?? ''));
        if ($goal === '') {
            $goal = trim($this->text($profile['muc_tieu_cham_soc'] ?? ''));
        }
        if ($this->taxonomy->canonicalGoal($goal) === '') {
            return null;
        }

        $goalMatch = is_array($evidence['goal'] ?? null) ? ($evidence['goal']['match'] ?? false) : false;
        return ['label' => $goal, 'matched' => $goalMatch === true];
    }

    private function sensitivityLabel($raw): string
    {
        $norm = $this->normalize($this->text($raw));
        if (str_contains($norm, 'rat de')) {
            return 'dễ kích ứng';
        }
        if (str_contains($norm, 'thinh thoang')) {
            return 'thỉnh thoảng kích ứng';
        }
        if (str_contains($norm, 'khoe manh') || str_contains($norm, 'hiem khi')) {
            return 'ít kích ứng';
        }
        return '';
    }

    /**
     * Mọi entry KB khớp trong trường thành phần, kèm điểm phù hợp và tên hiển thị.
     * Kết quả giữ thứ tự bảng KB (dùng cho tie-break và chọn hoạt chất mạnh).
     */
    private function matchActives(array $row, array $profile, array $userConcernIds): array
    {
        $chips = [];
        foreach (is_array($row['key_ingredients'] ?? null) ? $row['key_ingredients'] : [] as $chip) {
            $chip = trim($this->text($chip));
            if ($chip !== '') {
                $chips[] = $chip;
            }
        }

        $haystack = $this->normalize(
            implode(' ', $chips) . ' ' . $this->text($row['thanh_phan_chinh'] ?? '') . ' ' . $this->text($row['thanh_phan_day_du'] ?? '')
        );
        if (trim($haystack) === '') {
            return [];
        }

        $userSkin = $this->taxonomy->canonicalSkinType($this->text($profile['skin_type'] ?? ''));
        $step = $this->text($row['routine_step'] ?? '');

        $matched = [];
        foreach (self::ACTIVES_KB as $position => $entry) {
            $pattern = '/\b(?:' . $entry['keys'] . ')\b/u';
            if (preg_match($pattern, $haystack) !== 1) {
                continue;
            }

            $displayName = $entry['name'];
            foreach ($chips as $chip) {
                if (preg_match($pattern, $this->normalize($chip)) === 1) {
                    $displayName = mb_strimwidth($this->ucfirst($chip), 0, self::MAX_ACTIVE_NAME_WIDTH, '…', 'UTF-8');
                    break;
                }
            }

            $score = 4 * count(array_intersect($entry['concerns'], $userConcernIds))
                + 2 * (in_array($userSkin, $entry['skins'], true) ? 1 : 0)
                + 1 * (in_array($step, $entry['steps'], true) ? 1 : 0);

            $matched[] = [
                'position' => $position,
                'name' => $entry['name'],
                'display' => $displayName,
                'benefit' => $entry['benefit'],
                'strong' => $entry['strong'],
                'score' => $score,
            ];
        }
        return $matched;
    }

    /** Tối đa 2 hoạt chất điểm cao nhất (không trùng tên hiển thị); không có điểm > 0 thì lấy entry khớp đầu tiên. */
    private function pickActives(array $matched): array
    {
        if (!$matched) {
            return [];
        }

        $sorted = $matched;
        usort($sorted, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['position'] <=> $b['position']));

        $unique = [];
        $seen = [];
        foreach ($sorted as $item) {
            $key = mb_strtolower($item['display'], 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $item;
        }

        $chosen = array_slice(array_values(array_filter($unique, static fn(array $item): bool => $item['score'] > 0)), 0, self::MAX_ACTIVES);
        if (!$chosen) {
            // Không entry nào có điểm: lấy entry khớp đầu tiên theo thứ tự bảng.
            $chosen = [$unique[0]];
        }

        return array_map(
            static fn(array $item): array => ['name' => $item['display'], 'benefit' => $item['benefit']],
            $chosen
        );
    }

    private function caution(array $matched, string $sensitivity): string
    {
        foreach ($matched as $item) {
            if (!$item['strong']) {
                continue;
            }
            if (in_array($sensitivity, self::SENSITIVE_LEVELS, true)) {
                return 'Có ' . $item['name'] . ': da bạn dễ kích ứng, nên dùng cách ngày và thử trước ở vùng nhỏ.';
            }
            return 'Có ' . $item['name'] . ': bắt đầu 2–3 lần/tuần và dùng kem chống nắng ban ngày.';
        }
        return '';
    }

    /** Chuyển giá trị bất kỳ thành chuỗi an toàn (mảng/đối tượng -> rỗng). */
    private function text($value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private function ucfirst(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }

    /** Chữ thường, bỏ dấu tiếng Việt, gộp khoảng trắng (cùng bảng map với GoiYContentBased::normalizeText). */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $map = [
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
            'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
            'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',
        ];
        $text = strtr($text, $map);
        return preg_replace('/\s+/u', ' ', $text) ?? '';
    }
}

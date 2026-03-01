<?php
// backend/app/models/GoiYContentBased.php

class GoiYContentBased {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function recommendFromPost(array $post, int $limit = 12): array {
        $gender = trim((string)($post['gioi_tinh'] ?? ''));
        $birthYearRaw = trim((string)($post['nam_sinh'] ?? ''));
        $skinType = trim((string)($post['skin_type'] ?? ''));
        $budgetRaw = trim((string)($post['budget'] ?? ''));
        $concernsRaw = $post['concerns'] ?? [];
        $avoidRaw = trim((string)($post['avoid_ingredients'] ?? ''));

        if (!is_array($concernsRaw)) {
            $concernsRaw = [$concernsRaw];
        }

        $birthYear = null;
        if ($birthYearRaw !== '' && ctype_digit($birthYearRaw)) {
            $by = (int)$birthYearRaw;
            $currentYear = (int)date('Y');
            if ($by >= 1900 && $by <= $currentYear) {
                $birthYear = $by;
            }
        }

        $budget = null;
        if ($budgetRaw !== '') {
            $digits = preg_replace('/[^\d]/', '', $budgetRaw);
            if ($digits !== '') {
                $budget = (int)$digits;
            }
        }

        $concerns = [];
        foreach ($concernsRaw as $item) {
            $v = trim((string)$item);
            if ($v !== '') {
                $concerns[] = mb_strtolower($v, 'UTF-8');
            }
        }
        $concerns = array_values(array_unique($concerns));

        $avoidIngredients = $this->splitKeywords($avoidRaw);

        $products = $this->fetchCandidateProducts($limit * 8);
        if (!$products) {
            return [];
        }

        $scored = [];
        foreach ($products as $product) {
            $scored[] = $this->scoreOne($product, [
                'gender' => $gender,
                'birth_year' => $birthYear,
                'skin_type' => mb_strtolower($skinType, 'UTF-8'),
                'budget' => $budget,
                'concerns' => $concerns,
                'avoid_ingredients' => $avoidIngredients,
            ]);
        }

        usort($scored, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return strcmp((string)$a['ten_san_pham'], (string)$b['ten_san_pham']);
            }
            return $b['score'] <=> $a['score'];
        });

        return array_slice($scored, 0, $limit);
    }

    private function fetchCandidateProducts(int $limit): array {
        $sql = "SELECT sp.ma_san_pham, sp.ten_san_pham, sp.gia_ban, sp.loai_da, sp.mo_ta,
                       sp.thanh_phan_chinh, sp.thanh_phan_day_du, sp.hdsd, sp.diem_danh_gia,
                       sp.link_hinh_anh, th.ten_thuong_hieu,
                       COALESCE(xx.ten_xuat_xu, xxt.ten_xuat_xu) AS xuat_xu,
                       COALESCE(sp.danh_muc_day_du, dm.ten_danh_muc, '') AS danh_muc_day_du
                FROM san_pham sp
                LEFT JOIN thuong_hieu th ON th.ma_thuong_hieu = sp.ma_thuong_hieu
                LEFT JOIN xuat_xu xx ON xx.ma_xuat_xu = sp.ma_xuat_xu
                LEFT JOIN xuat_xu_thuong_hieu xxt ON xxt.ma_xuat_xu = sp.ma_xuat_xu
                LEFT JOIN danh_muc dm ON dm.ma_danh_muc = sp.ma_danh_muc
                ORDER BY sp.updated_at DESC NULLS LAST, sp.created_at DESC NULLS LAST
                LIMIT :limit";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':limit', max(30, $limit), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function scoreOne(array $product, array $profile): array {
        $weights = [
            'skin_type' => 35,
            'concerns' => 28,
            'budget' => 18,
            'rating' => 10,
            'demographic' => 4,
            'brand_origin' => 5,
        ];

        $score = 0.0;

        $haystack = $this->normalizeText(implode(' ', [
            $product['ten_san_pham'] ?? '',
            $product['mo_ta'] ?? '',
            $product['danh_muc_day_du'] ?? '',
            $product['hdsd'] ?? '',
            $product['loai_da'] ?? '',
        ]));

        $ingredientText = $this->normalizeText(implode(' ', [
            $product['thanh_phan_chinh'] ?? '',
            $product['thanh_phan_day_du'] ?? '',
        ]));

        if (!empty($profile['skin_type'])) {
            $skinTokens = $this->skinTypeKeywords($profile['skin_type']);
            $matches = $this->countMatches($haystack, $skinTokens);
            if ($matches > 0) {
                $score += $weights['skin_type'];
            }
        }

        if (!empty($profile['concerns'])) {
            $concernHit = 0;
            foreach ($profile['concerns'] as $concern) {
                $tokens = $this->concernKeywords($concern);
                if ($this->countMatches($haystack, $tokens) > 0 || $this->countMatches($ingredientText, $tokens) > 0) {
                    $concernHit++;
                }
            }
            if ($concernHit > 0) {
                $score += $weights['concerns'] * min(1.0, $concernHit / max(1, count($profile['concerns'])));
            }
        }

        if (!empty($profile['budget']) && !empty($product['gia_ban'])) {
            $giaBan = (int)$product['gia_ban'];
            $budget = (int)$profile['budget'];
            if ($giaBan <= $budget) {
                $score += $weights['budget'];
            } else {
                $overRatio = ($giaBan - $budget) / max(1, $budget);
                $score += max(0, $weights['budget'] * (1 - min($overRatio, 1)));
            }
        }

        $rating = isset($product['diem_danh_gia']) ? (float)$product['diem_danh_gia'] : 0.0;
        if ($rating > 0) {
            $score += min($weights['rating'], ($rating / 5.0) * $weights['rating']);
        }

        if (!empty($profile['gender']) || !empty($profile['birth_year'])) {
            $score += $weights['demographic'];
        }

        if (!empty($product['ten_thuong_hieu']) || !empty($product['xuat_xu'])) {
            $score += $weights['brand_origin'];
        }

        $penalty = 0;
        if (!empty($profile['avoid_ingredients'])) {
            foreach ($profile['avoid_ingredients'] as $badIng) {
                if ($badIng !== '' && mb_strpos($ingredientText, $badIng) !== false) {
                    $penalty += 25;
                }
            }
        }

        $score = max(0, $score - $penalty);

        return [
            'id' => $product['ma_san_pham'],
            'ten_san_pham' => $product['ten_san_pham'],
            'gia_ban' => $product['gia_ban'],
            'thuong_hieu' => $product['ten_thuong_hieu'] ?? null,
            'xuat_xu' => $product['xuat_xu'] ?? null,
            'link_hinh_anh' => $product['link_hinh_anh'] ?? null,
            'score' => round($score, 3),
            'penalty' => $penalty,
        ];
    }

    private function splitKeywords(string $raw): array {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,;\n\r]+/u', $raw) ?: [];
        $output = [];
        foreach ($parts as $p) {
            $k = $this->normalizeText($p);
            if ($k !== '') {
                $output[] = $k;
            }
        }
        return array_values(array_unique($output));
    }

    private function countMatches(string $text, array $keywords): int {
        if ($text === '' || !$keywords) {
            return 0;
        }

        $hits = 0;
        foreach ($keywords as $k) {
            if ($k !== '' && mb_strpos($text, $k) !== false) {
                $hits++;
            }
        }
        return $hits;
    }

    private function concernKeywords(string $concern): array {
        $c = $this->normalizeText($concern);

        $map = [
            'mun' => ['mụn', 'acne', 'salicylic', 'bha', 'tea tree', 'niacinamide'],
            'tham' => ['thâm', 'nám', 'tàn nhang', 'vitamin c', 'arbutin', 'tranexamic'],
            'lao hoa' => ['lão hóa', 'nhăn', 'retinol', 'peptide', 'collagen'],
            'kho' => ['khô', 'cấp ẩm', 'hyaluronic', 'ceramide', 'glycerin'],
            'nhay cam' => ['nhạy cảm', 'dịu nhẹ', 'không mùi', 'cica', 'panthenol'],
            'do dau' => ['kiềm dầu', 'dầu', 'sebum', 'zinc', 'bha'],
        ];

        $keywords = [$c];
        foreach ($map as $key => $arr) {
            if (mb_strpos($c, $key) !== false) {
                foreach ($arr as $item) {
                    $keywords[] = $item;
                }
            }
        }

        $normalized = [];
        foreach ($keywords as $item) {
            $normalized[] = $this->normalizeText($item);
        }
        return array_values(array_unique(array_filter($normalized)));
    }

    private function skinTypeKeywords(string $skinType): array {
        $s = $this->normalizeText($skinType);

        $map = [
            'da dau' => ['da dầu', 'oily', 'kiềm dầu', 'sebum', 'mụn'],
            'da kho' => ['da khô', 'dry', 'dưỡng ẩm', 'phục hồi', 'ceramide'],
            'da hon hop' => ['hỗn hợp', 'combination', 'cân bằng', 'đa vùng'],
            'da nhay cam' => ['nhạy cảm', 'sensitive', 'dịu nhẹ', 'không cồn'],
            'da thuong' => ['da thường', 'normal', 'duy trì', 'cân bằng'],
        ];

        $keywords = [$s];
        foreach ($map as $k => $arr) {
            if (mb_strpos($s, $k) !== false) {
                foreach ($arr as $item) {
                    $keywords[] = $item;
                }
            }
        }

        $normalized = [];
        foreach ($keywords as $item) {
            $normalized[] = $this->normalizeText($item);
        }
        return array_values(array_unique(array_filter($normalized)));
    }

    private function normalizeText(string $text): string {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return $text ?? '';
    }
}

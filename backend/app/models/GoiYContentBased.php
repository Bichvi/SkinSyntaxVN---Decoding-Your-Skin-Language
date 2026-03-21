<?php
// backend/app/models/GoiYContentBased.php

class GoiYContentBased {
    private PDO $pdo;
    private ?array $sanPhamColumnsCache = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    private function getSanPhamColumns(): array {
        if ($this->sanPhamColumnsCache !== null) {
            return $this->sanPhamColumnsCache;
        }

        $sql = "SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'san_pham'";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $columns = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['column_name'] ?? ''));
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        $this->sanPhamColumnsCache = $columns;
        return $this->sanPhamColumnsCache;
    }

    private function hasSanPhamColumn(string $column): bool {
        return isset($this->getSanPhamColumns()[$column]);
    }

    private function firstExistingSanPhamColumn(array $candidates): ?string {
        foreach ($candidates as $column) {
            if ($this->hasSanPhamColumn($column)) {
                return $column;
            }
        }

        return null;
    }

    private function selectExpr(string $alias, array $candidates, string $fallback = "''"): string {
        $column = $this->firstExistingSanPhamColumn($candidates);
        if ($column === null) {
            return $fallback . ' AS ' . $alias;
        }

        return 'sp.' . $column . ' AS ' . $alias;
    }

    private function buildOrderByExpr(): string {
        $parts = [];
        foreach (['updated_at', 'created_at'] as $column) {
            if ($this->hasSanPhamColumn($column)) {
                $parts[] = 'sp.' . $column . ' DESC NULLS LAST';
            }
        }

        if (!$parts) {
            $parts[] = 'sp.ma_san_pham DESC';
        }

        return implode(', ', $parts);
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

        [$budgetMin, $budgetMax] = $this->resolveBudgetRange($post, $budget);

        $products = $this->fetchCandidateProducts($limit * 8, $budgetMin, $budgetMax);
        if (!$products) {
            return [];
        }

        $whitelistIds = array_values(array_unique(array_map(
            fn($p) => (string)($p['ma_san_pham'] ?? ''),
            $products
        )));

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

        $scored = array_values(array_filter($scored, function (array $item) use ($whitelistIds) {
            $id = (string)($item['id'] ?? '');
            return $id !== '' && in_array($id, $whitelistIds, true);
        }));

        return array_slice($scored, 0, $limit);
    }

    private function fetchCandidateProducts(int $limit, ?int $budgetMin = null, ?int $budgetMax = null): array {
        $where = "WHERE 1=1";
        $params = [];

        if ($budgetMin !== null) {
            $where .= " AND sp.gia_ban >= :budget_min";
            $params[':budget_min'] = $budgetMin;
        }
        if ($budgetMax !== null) {
            $where .= " AND sp.gia_ban <= :budget_max";
            $params[':budget_max'] = $budgetMax;
        }

        $sql = "SELECT sp.ma_san_pham, sp.ten_san_pham, sp.gia_ban,
                   " . $this->selectExpr('loai_da', ['loai_da']) . ",
                   " . $this->selectExpr('mo_ta', ['mo_ta']) . ",
                   " . $this->selectExpr('thanh_phan_chinh', ['thanh_phan_chinh', 'thanh_phan']) . ",
                   " . $this->selectExpr('thanh_phan_day_du', ['thanh_phan_day_du', 'thanh_phan_full']) . ",
                   " . $this->selectExpr('hdsd', ['hdsd']) . ",
                   " . $this->selectExpr('diem_danh_gia', ['diem_danh_gia'], '0') . ",
                       sp.link_hinh_anh, th.ten_thuong_hieu,
                       COALESCE(xx.ten_xuat_xu, xxt.ten_xuat_xu) AS xuat_xu,
                       COALESCE(sp.danh_muc_day_du, dm.ten_danh_muc, '') AS danh_muc_day_du
                FROM san_pham sp
                LEFT JOIN thuong_hieu th ON th.ma_thuong_hieu = sp.ma_thuong_hieu
                LEFT JOIN xuat_xu xx ON xx.ma_xuat_xu = sp.ma_xuat_xu
                LEFT JOIN xuat_xu_thuong_hieu xxt ON xxt.ma_xuat_xu = sp.ma_xuat_xu
                LEFT JOIN danh_muc dm ON dm.ma_danh_muc = sp.ma_danh_muc
                $where
            ORDER BY " . $this->buildOrderByExpr() . "
                LIMIT :limit";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v, PDO::PARAM_INT);
        }
        $st->bindValue(':limit', max(30, $limit), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function resolveBudgetRange(array $post, ?int $budget): array {
        $minRaw = trim((string)($post['budget_min'] ?? ''));
        $maxRaw = trim((string)($post['budget_max'] ?? ''));

        $budgetMin = null;
        $budgetMax = null;

        if ($minRaw !== '') {
            $digits = preg_replace('/[^\d]/', '', $minRaw);
            if ($digits !== '') {
                $budgetMin = (int)$digits;
            }
        }

        if ($maxRaw !== '') {
            $digits = preg_replace('/[^\d]/', '', $maxRaw);
            if ($digits !== '') {
                $budgetMax = (int)$digits;
            }
        }

        if ($budgetMin === null && $budgetMax === null && $budget !== null) {
            $budgetMax = $budget;
        }

        if ($budgetMin !== null && $budgetMax !== null && $budgetMin > $budgetMax) {
            [$budgetMin, $budgetMax] = [$budgetMax, $budgetMin];
        }

        return [$budgetMin, $budgetMax];
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
        $reasons = [];
        $matchedConcerns = [];
        $avoidIngredientHits = [];

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
                $reasons[] = 'Phù hợp với loại da ' . trim((string)$profile['skin_type']) . '.';
            }
        }

        if (!empty($profile['concerns'])) {
            $concernHit = 0;
            foreach ($profile['concerns'] as $concern) {
                $tokens = $this->concernKeywords($concern);
                if ($this->countMatches($haystack, $tokens) > 0 || $this->countMatches($ingredientText, $tokens) > 0) {
                    $concernHit++;
                    $matchedConcerns[] = trim((string)$concern);
                }
            }
            if ($concernHit > 0) {
                $score += $weights['concerns'] * min(1.0, $concernHit / max(1, count($profile['concerns'])));
                $reasons[] = 'Hỗ trợ cho vấn đề ' . implode(', ', array_slice($matchedConcerns, 0, 3)) . '.';
            }
        }

        if (!empty($profile['budget']) && !empty($product['gia_ban'])) {
            $giaBan = (int)$product['gia_ban'];
            $budget = (int)$profile['budget'];
            if ($giaBan <= $budget) {
                $score += $weights['budget'];
                $reasons[] = 'Nằm trong ngân sách bạn đặt ra.';
            } else {
                $overRatio = ($giaBan - $budget) / max(1, $budget);
                $score += max(0, $weights['budget'] * (1 - min($overRatio, 1)));
            }
        }

        $rating = isset($product['diem_danh_gia']) ? (float)$product['diem_danh_gia'] : 0.0;
        if ($rating > 0) {
            $score += min($weights['rating'], ($rating / 5.0) * $weights['rating']);
            if ($rating >= 4.0) {
                $reasons[] = 'Có đánh giá người dùng tích cực.';
            }
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
                    $avoidIngredientHits[] = $badIng;
                }
            }
        }

        $score = max(0, $score - $penalty);

        $keyIngredients = $this->extractKeyIngredients($product);
        $reasons = array_values(array_unique(array_filter($reasons)));
        if (empty($reasons)) {
            $reasons[] = 'Có mức độ tương thích cơ bản với hồ sơ chăm sóc da của bạn.';
        }

        return [
            'id' => $product['ma_san_pham'],
            'ten_san_pham' => $product['ten_san_pham'],
            'gia_ban' => $product['gia_ban'],
            'thuong_hieu' => $product['ten_thuong_hieu'] ?? null,
            'xuat_xu' => $product['xuat_xu'] ?? null,
            'link_hinh_anh' => $product['link_hinh_anh'] ?? null,
            'mo_ta' => trim((string)($product['mo_ta'] ?? '')),
            'danh_muc' => trim((string)($product['danh_muc_day_du'] ?? '')),
            'thanh_phan_chinh' => trim((string)($product['thanh_phan_chinh'] ?? '')),
            'thanh_phan_day_du' => trim((string)($product['thanh_phan_day_du'] ?? '')),
            'key_ingredients' => $keyIngredients,
            'matched_concerns' => $matchedConcerns,
            'avoid_ingredient_hits' => $avoidIngredientHits,
            'reasons' => $reasons,
            'score' => round($score, 3),
            'penalty' => $penalty,
        ];
    }

    private function extractKeyIngredients(array $product, int $max = 5): array {
        $raw = trim((string)($product['thanh_phan_chinh'] ?? $product['thanh_phan_day_du'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[,;|\n\r]+/u', $raw) ?: [];
        $output = [];
        foreach ($parts as $part) {
            $item = trim((string)$part);
            if ($item === '') {
                continue;
            }

            $output[] = $item;
            if (count($output) >= $max) {
                break;
            }
        }

        return array_values(array_unique($output));
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

<?php
// backend/app/models/GoiYContentBased.php

class GoiYContentBased {
    // Kết nối MongoDB dùng để đọc các collection san_pham, thuong_hieu, xuat_xu, danh_muc.
    private $db;

    public function __construct($db) {
        // Lưu kết nối DB vào model để các hàm bên dưới có thể truy vấn dữ liệu sản phẩm.
        $this->db = $db;
    }

    public function recommendFromPost(array $post, int $limit = 12): array {
        // Hàm chính của fallback recommendation.
        // Luồng: chuẩn hóa input -> lấy sản phẩm MongoDB -> lọc hợp lệ -> chấm điểm -> sắp xếp -> trả về danh sách gợi ý.
        $gender = trim((string)($post['gioi_tinh'] ?? ''));
        $birthYearRaw = trim((string)($post['nam_sinh'] ?? ''));
        $skinType = trim((string)($post['skin_type'] ?? ''));
        $budgetRaw = trim((string)($post['budget'] ?? ''));
        $concernsRaw = $post['concerns'] ?? [];
        $avoidRaw = trim((string)($post['avoid_ingredients'] ?? ''));

        if (!is_array($concernsRaw)) {
            // Nếu form gửi một vấn đề da dạng string, đổi thành mảng để xử lý thống nhất.
            $concernsRaw = [$concernsRaw];
        }

        $birthYear = null;
        if ($birthYearRaw !== '' && ctype_digit($birthYearRaw)) {
            // Kiểm tra năm sinh hợp lệ để tránh dữ liệu rác làm sai hồ sơ người dùng.
            $by = (int)$birthYearRaw;
            $currentYear = (int)date('Y');
            if ($by >= 1900 && $by <= $currentYear) {
                $birthYear = $by;
            }
        }

        $budget = null;
        if ($budgetRaw !== '') {
            // Ngân sách có thể nhập dạng "500.000đ", nên chỉ giữ lại chữ số.
            $digits = preg_replace('/[^\d]/', '', $budgetRaw);
            if ($digits !== '') {
                $budget = (int)$digits;
            }
        }

        $concerns = [];
        foreach ($concernsRaw as $item) {
            // Chuẩn hóa vấn đề da về chữ thường để so khớp keyword dễ hơn.
            $v = trim((string)$item);
            if ($v !== '') {
                $concerns[] = mb_strtolower($v, 'UTF-8');
            }
        }
        $concerns = array_values(array_unique($concerns));

        $avoidIngredients = $this->splitKeywords($avoidRaw);

        // Tính khoảng giá cần lấy từ MongoDB. Nếu chỉ có budget thì budget là giá tối đa.
        [$budgetMin, $budgetMax] = $this->resolveBudgetRange($post, $budget);

        // Nhận diện loại sản phẩm người dùng đang hỏi, ví dụ "sữa rửa mặt" -> sua_rua_mat.
        // Intent này giúp không gợi ý lẫn serum/mặt nạ khi người dùng đã nói rõ loại cần mua.
        $queryIntent = $this->extractQueryProductIntent(trim((string)($post['query_text'] ?? '')));
        $fetchMultiplier = $queryIntent !== '' ? 14 : 8;
        $isRoutine = filter_var($post['is_routine'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Lấy danh sách sản phẩm mẫu từ MongoDB
        $products = $isRoutine
            ? $this->fetchRoutineCandidateProducts($limit, $budgetMin, $budgetMax)
            : $this->fetchCandidateProducts($limit * $fetchMultiplier, $budgetMin, $budgetMax);
        if (!$products) {
            return [];
        }

        $whitelistIds = array_values(array_unique(array_map(
            fn($p) => (string)($p['ma_san_pham'] ?? ''),
            $products
        )));

        // Mảng $scored chứa sản phẩm đã qua lọc và đã được tính điểm phù hợp.
        $scored = [];
        foreach ($products as $product) {
            // Bỏ sản phẩm hết hàng, bị ẩn, ngừng bán hoặc không còn khả dụng.
            if (!$this->isProductSellable($product)) {
                continue;
            }
            // Đây là nguồn fallback cho trang gợi ý chăm sóc da mặt. Không
            // để sản phẩm tóc, body, răng miệng hoặc makeup lọt vào chỉ vì
            // mô tả của chúng có các từ như "phục hồi" hay "dưỡng ẩm".
            if ($this->isNonFacialSkincareProduct($product)) {
                continue;
            }
            // Nếu người dùng ghi rõ loại sản phẩm thì lọc cứng theo tên/danh mục trước khi chấm điểm.
            if ($queryIntent !== '' && !$this->productMatchesQueryIntent($product, $queryIntent)) {
                continue;
            }
            // Tính điểm retrieval nội bộ và đánh giá độ phù hợp với hồ sơ da
            $scoredItem = $this->scoreOne($product, [
                'gender' => $gender,
                'birth_year' => $birthYear,
                'skin_type' => mb_strtolower($skinType, 'UTF-8'),
                'budget' => $budget,
                'concerns' => $concerns,
                'avoid_ingredients' => $avoidIngredients,
                'query_intent' => $queryIntent,
            ]);
            $compat = $this->evaluateCompatibility($product, [
                'skin_type' => $skinType,
                'concerns' => $concerns,
                'avoid_ingredients' => $avoidIngredients,
                'budget' => $budget,
                'user_query' => trim((string)($post['query_text'] ?? '')),
            ]);
            $scoredItem = array_merge($scoredItem, $compat);
            $scored[] = $scoredItem;
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

        if ($isRoutine) {
            // Fallback cho routine cần phủ đủ các bước, không thể chỉ lấy top score
            // vì khi đó một nhóm sản phẩm (thường là serum) có thể chiếm hết kết quả.
            $routineOrder = ['remover', 'cleanser', 'toner', 'treatment', 'moisturizer', 'sunscreen'];
            foreach ($concerns as $concern) {
                if (preg_match('/mun|acne|viem/u', $this->normalizeText((string)$concern))) {
                    $routineOrder[] = 'spot_treatment';
                    break;
                }
            }

            $routineProducts = [];
            $usedIds = [];
            foreach ($routineOrder as $routineStep) {
                foreach ($scored as $item) {
                    $id = (string)($item['id'] ?? '');
                    if ($id === '' || isset($usedIds[$id])) {
                        continue;
                    }
                    if ($this->routineStepForProduct($item) !== $routineStep) {
                        continue;
                    }
                    $routineProducts[] = $item;
                    $usedIds[$id] = true;
                    break;
                }
            }

            return array_slice($routineProducts, 0, $limit);
        }

        // Deduplicate product line variants so recommendations do not repeat shades
        $deduped = [];
        $lineCounts = [];
        foreach ($scored as $item) {
            $name = (string)($item['ten_san_pham'] ?? '');
            $brand = (string)($item['thuong_hieu'] ?? '');
            $cleanName = preg_replace('/(\b\d{2,4}[a-z]?\b|\b\d+\s*(ml|g|kg|oz)\b|\b(shade|tone|màu)\s*\w+\b|\b(fair|light|medium|deep|honey|neutralizer|nude|ivory)\b)/i', '', $name);
            $cleanName = preg_replace('/\([^)]*\)/', '', $cleanName);
            $cleanName = preg_replace('/[^\p{L}\p{N}\s]/u', '', $cleanName);
            $cleanName = preg_replace('/\s+/', ' ', trim($cleanName));
            $groupKey = strtolower(trim($brand . '_' . $cleanName));

            $count = $lineCounts[$groupKey] ?? 0;
            if ($count < 1) {
                $lineCounts[$groupKey] = $count + 1;
                $deduped[] = $item;
            }
        }

        return array_slice(!empty($deduped) ? $deduped : $scored, 0, $limit);
    }

    private function fetchRoutineCandidateProducts(int $limit, ?int $budgetMin = null, ?int $budgetMax = null): array {
        // Lấy riêng từng nhóm routine để dữ liệu mới nhất của một nhóm không che mất
        // các bước khác. ID được dò từ collection danh mục, không hard-code theo dữ liệu môi trường.
        $categoryLeaves = [
            'remover' => ['tay trang mat'],
            'cleanser' => ['sua rua mat'],
            'toner' => ['toner / nuoc can bang da'],
            'treatment' => ['serum / tinh chat'],
            'moisturizer' => ['kem / gel / dau duong'],
            'sunscreen' => ['chong nang da mat'],
            'spot_treatment' => ['ho tro tri mun'],
        ];
        $categoryIdsByStep = array_fill_keys(array_keys($categoryLeaves), []);
        foreach ($this->db->danh_muc->find([], ['projection' => ['ma_danh_muc' => 1, 'ten_danh_muc' => 1]]) as $categoryDoc) {
            $categoryName = $this->normalizeText((string)($categoryDoc['ten_danh_muc'] ?? ''));
            foreach ($categoryLeaves as $step => $leaves) {
                foreach ($leaves as $leaf) {
                    if (preg_match('/(?:^|->)\s*' . preg_quote($leaf, '/') . '\s*$/u', $categoryName)) {
                        $categoryIdsByStep[$step][] = $categoryDoc['ma_danh_muc'];
                        break;
                    }
                }
            }
        }

        $perCategory = max(6, (int)ceil(max(1, $limit) / count($categoryLeaves)));
        $products = [];
        $seenIds = [];
        foreach ($categoryIdsByStep as $categoryIds) {
            if (!$categoryIds) {
                continue;
            }
            foreach ($this->fetchCandidateProducts($perCategory, $budgetMin, $budgetMax, [], $categoryIds) as $product) {
                $id = (string)($product['ma_san_pham'] ?? '');
                if ($id === '' || isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;
                $products[] = $product;
            }
        }
        return $products;
    }

    private function fetchCandidateProducts(int $limit, ?int $budgetMin = null, ?int $budgetMax = null, array $categories = [], array $categoryIds = []): array {
        // Lấy danh sách sản phẩm từ MongoDB theo khoảng giá, rồi bổ sung thêm brand/xuất xứ/danh mục.
        $filter = [];
        if ($budgetMin !== null || $budgetMax !== null) {
            // Tạo filter cho gia_ban: >= min và/hoặc <= max.
            $filter['gia_ban'] = [];
            if ($budgetMin !== null) $filter['gia_ban']['$gte'] = $budgetMin;
            if ($budgetMax !== null) $filter['gia_ban']['$lte'] = $budgetMax;
        }
        if ($categories) {
            $filter['loai_san_pham'] = ['$in' => array_values(array_unique($categories))];
        }
        if ($categoryIds) {
            $filter['ma_danh_muc'] = ['$in' => array_values(array_unique($categoryIds))];
        }

        $options = [
            // Ưu tiên sản phẩm mới hơn theo ma_san_pham giảm dần.
            'sort' => ['ma_san_pham' => -1],
            'limit' => max(30, $limit)
        ];

        $cursor = $this->db->san_pham->find($filter, $options);
        $items = [];

        foreach ($cursor as $doc) {
            $p = (array) $doc;

            // MongoDB không JOIN như SQL, nên cần tự lấy tên thương hiệu từ collection thuong_hieu.
            // Giả lập phép JOIN của SQL trong MongoDB
            if (isset($p['ma_thuong_hieu'])) {
                $brand = $this->db->thuong_hieu->findOne(['ma_thuong_hieu' => $p['ma_thuong_hieu']]);
                $p['ten_thuong_hieu'] = $brand ? $brand['ten_thuong_hieu'] : '';
            }

            // Bổ sung xuất xứ để sản phẩm trả về có đủ dữ liệu hiển thị và giải thích.
            if (isset($p['ma_xuat_xu'])) {
                $origin = $this->db->xuat_xu->findOne(['ma_xuat_xu' => $p['ma_xuat_xu']]);
                $p['xuat_xu'] = $origin ? $origin['ten_xuat_xu'] : '';
            }

            // Bổ sung tên danh mục để lọc theo loại sản phẩm và hiển thị trên giao diện.
            if (isset($p['ma_danh_muc'])) {
                $cat = $this->db->danh_muc->findOne(['ma_danh_muc' => $p['ma_danh_muc']]);
                if ($cat) {
                    if (empty($p['danh_muc_day_du'])) {
                        $p['danh_muc_day_du'] = $cat['ten_danh_muc'];
                    }
                }
            }

            $items[] = $p;
        }

        return $items;
    }

    private function resolveBudgetRange(array $post, ?int $budget): array {
        // Chuyển dữ liệu ngân sách trên form thành cặp [min, max] cho MongoDB query.
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
            // Nếu chỉ nhập một ngân sách chung thì dùng nó làm mức giá tối đa.
            $budgetMax = $budget;
        }

        if ($budgetMin !== null && $budgetMax !== null && $budgetMin > $budgetMax) {
            // Nếu nhập ngược min/max thì đảo lại để tránh lọc sai.
            [$budgetMin, $budgetMax] = [$budgetMax, $budgetMin];
        }

        return [$budgetMin, $budgetMax];
    }

    /**
     * Nhận diện loại sản phẩm từ ô "Mô tả nhu cầu chi tiết" (fallback khi hybrid Flask không chạy).
     */
    private function extractQueryProductIntent(string $queryText): string {
        // Đưa câu người dùng về dạng chữ thường, không dấu để nhận diện intent ổn định hơn.
        $q = $this->normalizeText($queryText);
        if ($q === '') {
            return '';
        }
        // Nhóm làm sạch da mặt: chỉ nên trả về sữa rửa mặt/gel rửa/cleanser.
        if (mb_strpos($q, 'sua rua mat') !== false || mb_strpos($q, 'sua rua') !== false
            || mb_strpos($q, 'cleanser') !== false || mb_strpos($q, 'gel rua') !== false
            || mb_strpos($q, 'face wash') !== false || mb_strpos($q, 'foaming wash') !== false) {
            return 'sua_rua_mat';
        }
        // Nhóm dưỡng ẩm/kem dưỡng.
        if (mb_strpos($q, 'kem duong') !== false || mb_strpos($q, 'duong am') !== false
            || mb_strpos($q, 'cap am') !== false || mb_strpos($q, 'moistur') !== false
            || mb_strpos($q, 'hydrat') !== false || mb_strpos($q, 'emulsion') !== false) {
            return 'kem_duong';
        }
        // Nhóm chống nắng.
        if (mb_strpos($q, 'chong nang') !== false || mb_strpos($q, 'sunscreen') !== false
            || mb_strpos($q, 'sunblock') !== false || mb_strpos($q, 'spf') !== false) {
            return 'chong_nang';
        }
        // Nhóm mặt nạ.
        if (mb_strpos($q, 'mat na') !== false || mb_strpos($q, 'mask') !== false) {
            return 'mat_na';
        }
        // Nhóm toner/nước cân bằng.
        if (mb_strpos($q, 'toner') !== false || mb_strpos($q, 'hoa hong') !== false || mb_strpos($q, 'nuoc can bang') !== false) {
            return 'toner';
        }
        // Nhóm kem lót/primer.
        if (mb_strpos($q, 'kem lot') !== false || mb_strpos($q, 'primer') !== false || mb_strpos($q, 'lot nen') !== false) {
            return 'kem_lot';
        }
        if (mb_strpos($q, 'kem lót') !== false || mb_strpos($q, 'kem lot') !== false || mb_strpos($q, 'primer') !== false) {
            return 'kem_lot';
        }
        if (mb_strpos($q, 'kem dưỡng') !== false || mb_strpos($q, 'kemduong') !== false) {
            return 'kem_duong';
        }
        if (mb_strpos($q, 'dưỡng ẩm') !== false || mb_strpos($q, 'duong am') !== false
            || mb_strpos($q, 'cấp ẩm') !== false || mb_strpos($q, 'cap am') !== false) {
            return 'kem_duong';
        }
        if (mb_strpos($q, 'moistur') !== false || mb_strpos($q, 'hydrat') !== false || mb_strpos($q, 'emulsion') !== false) {
            return 'kem_duong';
        }
        if (mb_strpos($q, 'serum') !== false || mb_strpos($q, 'essence') !== false) {
            return 'serum';
        }
        if (mb_strpos($q, 'mặt nạ') !== false || mb_strpos($q, 'mat na') !== false) {
            return 'mat_na';
        }
        if (mb_strpos($q, 'toner') !== false || mb_strpos($q, 'hoa hồng') !== false) {
            return 'toner';
        }
        if (mb_strpos($q, 'sữa rửa mặt') !== false || mb_strpos($q, 'cleanser') !== false
            || mb_strpos($q, 'gel rửa') !== false || mb_strpos($q, 'rua mat') !== false) {
            return 'sua_rua_mat';
        }
        if (mb_strpos($q, 'chống nắng') !== false || mb_strpos($q, 'sunblock') !== false || mb_strpos($q, 'spf') !== false) {
            return 'chong_nang';
        }
        return '';
    }

    /** @return list<string> */
    private function queryIntentPositiveTokens(string $intent): array {
        // Các token tích cực: nếu tên/danh mục/mô tả chứa các từ này thì sản phẩm được cộng điểm.
        switch ($intent) {
            case 'kem_duong':
                return ['kem dưỡng', 'kem duong', 'dưỡng ẩm', 'duong am', 'moistur', 'hydrat', 'face cream', 'emulsion', 'cấp ẩm', 'cap am'];
            case 'serum':
                return ['serum', 'essence', 'ampoule', 'tinh chất', 'tinh chat'];
            case 'mat_na':
                return ['mặt nạ', 'mat na', 'mask'];
            case 'toner':
                return ['toner', 'nước hoa hồng', 'hoa hồng'];
            case 'sua_rua_mat':
                return ['sữa rửa', 'sua rua', 'cleanser', 'rửa mặt', 'rua mat', 'gel rửa', 'foaming'];
            case 'chong_nang':
                return ['chống nắng', 'chong nang', 'sunscreen', 'spf'];
            case 'kem_lot':
                return ['kem lót', 'kem lot', 'primer', 'lót nền'];
            default:
                return [];
        }
    }

    /** @return list<string> */
    private function queryIntentNegativeTokens(string $intent): array {
        // Các token tiêu cực: nếu sản phẩm thuộc nhóm khác intent thì bị trừ điểm.
        // Ví dụ hỏi kem dưỡng mà tên là sữa rửa mặt thì không nên ưu tiên.
        switch ($intent) {
            case 'kem_duong':
                return ['sữa rửa mặt', 'sua rua mat', 'gel rửa', 'cleanser', 'rửa mặt', 'rua mat', 'tẩy trang', 'tay trang', 'kem lót', 'kem lot', 'primer', 'chống nắng', 'chong nang', 'spf50'];
            case 'serum':
                return ['sữa rửa mặt', 'cleanser', 'kem lót', 'primer'];
            case 'toner':
                return ['sữa rửa mặt', 'cleanser', 'kem lót'];
            default:
                return [];
        }
    }

    private function scoreOne(array $product, array $profile): array {
        // Chấm điểm một sản phẩm dựa trên nhiều tiêu chí.
        // Điểm càng cao thì sản phẩm càng được ưu tiên trong danh sách gợi ý.
        $weights = [
            // Trọng số cho từng tiêu chí. Có thể chỉnh số này nếu muốn ưu tiên yếu tố khác.
            'skin_type' => 35,
            'concerns' => 28,
            'budget' => 18,
            'rating' => 10,
            'demographic' => 4,
            'brand_origin' => 5,
            'query_intent' => 42,
        ];

        $score = 0.0;
        $reasons = [];
        $matchedConcerns = [];
        $avoidIngredientHits = [];

        // Gộp các trường mô tả quan trọng thành một chuỗi để tìm keyword.
        $haystack = $this->normalizeText(implode(' ', [
            $product['ten_san_pham'] ?? '',
            $product['mo_ta'] ?? '',
            $product['danh_muc_day_du'] ?? '',
            $product['hdsd'] ?? '',
            $product['loai_da'] ?? '',
        ]));

        // Chuỗi chỉ gồm tên + danh mục, dùng để phát hiện sản phẩm thuộc sai nhóm.
        $nameCatHaystack = $this->normalizeText(implode(' ', [
            $product['ten_san_pham'] ?? '',
            $product['danh_muc_day_du'] ?? '',
        ]));

        // Chuỗi thành phần, dùng để so vấn đề da và thành phần cần tránh.
        $ingredientText = $this->normalizeText(implode(' ', [
            $product['thanh_phan_chinh'] ?? '',
            $product['thanh_phan_day_du'] ?? '',
        ]));

        if (!empty($profile['skin_type'])) {
            // Cộng điểm nếu sản phẩm có dấu hiệu phù hợp với loại da người dùng.
            $skinTokens = $this->skinTypeKeywords($profile['skin_type']);
            $matches = $this->countMatches($haystack, $skinTokens);
            if ($matches > 0) {
                $score += $weights['skin_type'];
                $reasons[] = 'Phù hợp với loại da ' . trim((string)$profile['skin_type']) . '.';
            }
        }

        if (!empty($profile['concerns'])) {
            // Cộng điểm theo các vấn đề da người dùng chọn như mụn, thâm, da khô, nhạy cảm.
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
            // Sản phẩm nằm trong ngân sách được cộng điểm; vượt ngân sách thì điểm giảm dần.
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
            // Đánh giá người dùng càng cao thì cộng điểm càng nhiều.
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

        // SkinSyntax: Prioritize Skincare over Makeup for personalized skin profile recommendations
        $skincareTokens = ['sữa rửa mặt', 'sua rua mat', 'tẩy trang', 'tay trang', 'toner', 'nước hoa hồng', 'serum', 'tinh chất', 'tinh chat', 'đặc trị', 'dac tri', 'treatment', 'kem dưỡng', 'kem duong', 'dưỡng ẩm', 'duong am', 'chống nắng', 'chong nang', 'mặt nạ', 'mat na', 'dưỡng môi', 'skincare', 'tẩy tế bào chết', 'xịt khoáng'];
        $makeupTokens = ['concealer', 'che khuyết điểm', 'che khuyet diem', 'foundation', 'kem nền', 'kem nen', 'cushion', 'mascara', 'eyebrow', 'kẻ mày', 'ke may', 'eyeshadow', 'phấn mắt', 'phan mat', 'son môi', 'son moi', 'trang điểm', 'trang diem', 'makeup'];

        if ($this->countMatches($haystack, $skincareTokens) > 0) {
            $score += 15;
            $reasons[] = 'Sản phẩm chăm sóc da phù hợp với nhu cầu của làn da bạn.';
        } elseif ($this->countMatches($haystack, $makeupTokens) > 0 && empty($profile['query_intent'])) {
            $penalty += 15;
        }

        if (!empty($profile['avoid_ingredients'])) {
            // Nếu sản phẩm có thành phần người dùng muốn tránh thì trừ điểm mạnh.
            foreach ($profile['avoid_ingredients'] as $badIng) {
                if ($badIng !== '' && mb_strpos($ingredientText, $badIng) !== false) {
                    $penalty += 25;
                    $avoidIngredientHits[] = $badIng;
                }
            }
        }

        $queryIntent = trim((string)($profile['query_intent'] ?? ''));
        if ($queryIntent !== '') {
            // Cộng điểm nếu sản phẩm khớp loại người dùng nhập; trừ điểm nếu thuộc nhóm khác.
            $posTokens = $this->queryIntentPositiveTokens($queryIntent);
            $negTokens = $this->queryIntentNegativeTokens($queryIntent);
            if ($this->countMatches($haystack, $posTokens) > 0) {
                $score += $weights['query_intent'];
                $reasons[] = 'Khớp loại sản phẩm bạn ghi trong phần mô tả nhu cầu.';
            }
            foreach ($negTokens as $neg) {
                if ($neg !== '' && mb_strpos($nameCatHaystack, $neg) !== false) {
                    $penalty += 38;
                    $reasons[] = 'Ưu tiên thấp hơn vì sản phẩm không thuộc nhóm bạn đang tìm.';
                    break;
                }
            }
        }

        $score = max(0, $score - $penalty);

        // Tách thành phần nổi bật để đưa sang phần giải thích AI/fallback.
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
            'loai_san_pham' => trim((string)($product['loai_san_pham'] ?? '')),
            'danh_muc_day_du' => trim((string)($product['danh_muc_day_du'] ?? '')),
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
        // Tách danh sách thành phần chính từ chuỗi thành mảng ngắn để hiển thị/giải thích.
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
        // Tách input dạng "cồn, hương liệu; paraben" thành mảng keyword đã chuẩn hóa.
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
        // Đếm số keyword xuất hiện trong text. Hàm này dùng cho mọi bước so khớp đơn giản.
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

    private function productMatchesQueryIntent(array $product, string $intent): bool {
        // Lọc cứng sản phẩm theo loại người dùng hỏi.
        // Ví dụ intent sua_rua_mat thì tên/danh mục phải có "sữa rửa", "gel rửa", "cleanser", ...
        $tokens = $this->strictIntentTokens($intent);
        if (!$tokens) {
            return true;
        }

        $text = $this->normalizeText(implode(' ', [
            $product['ten_san_pham'] ?? '',
            $product['danh_muc_day_du'] ?? '',
            $product['loai_san_pham'] ?? '',
        ]));
        if ($intent === 'sua_rua_mat' && mb_strpos($text, 'combo') !== false) {
            // Với sữa rửa mặt, loại combo để tránh trả sản phẩm lẫn serum/chống nắng/tẩy trang.
            return false;
        }

        return $this->countMatches($text, $tokens) > 0;
    }

    private function strictIntentTokens(string $intent): array {
        // Token bắt buộc theo từng loại sản phẩm, dùng trong bước lọc cứng trước khi chấm điểm.
        switch ($intent) {
            case 'sua_rua_mat':
                return ['sua rua', 'sua rua mat', 'cleanser', 'gel rua', 'face wash', 'foaming wash', 'cleansing foam'];
            case 'serum':
                return ['serum', 'essence', 'ampoule', 'tinh chat'];
            case 'mat_na':
                return ['mat na', 'mask'];
            case 'toner':
                return ['toner', 'nuoc hoa hong', 'hoa hong', 'nuoc can bang'];
            case 'kem_duong':
                return ['kem duong', 'duong am', 'moistur', 'hydrat', 'face cream', 'night cream', 'emulsion', 'cap am'];
            case 'chong_nang':
                return ['chong nang', 'sunscreen', 'sunblock', 'spf'];
            case 'kem_lot':
                return ['kem lot', 'primer', 'lot nen'];
            default:
                return [];
        }
    }

    private function isProductSellable(array $product): bool {
        // Kiểm tra sản phẩm có được phép gợi ý hay không: không ẩn, không ngừng bán, không hết hàng.
        $status = $this->normalizeText((string)($product['trang_thai'] ?? $product['status'] ?? 'active'));
        if (in_array($status, ['inactive', 'hidden', 'tam an', 'taman', 'disabled', 'off', '0', 'ngung ban'], true)) {
            return false;
        }

        foreach (['so_luong_ton', 'ton_kho', 'stock', 'quantity'] as $field) {
            // Dự án có thể dùng nhiều tên field tồn kho khác nhau, nên kiểm tra lần lượt.
            if (array_key_exists($field, $product) && $product[$field] !== null && $product[$field] !== '') {
                return (int)$product[$field] > 0;
            }
        }

        return true;
    }

    private function isNonFacialSkincareProduct(array $product): bool {
        $text = $this->normalizeText(implode(' ', [
            $product['ten_san_pham'] ?? '',
            $product['loai_san_pham'] ?? '',
            $product['danh_muc_day_du'] ?? '',
        ]));

        return preg_match(
            '/trang diem|makeup|foundation|kem nen|phan nen|cushion|concealer|son moi|mascara|eyeliner|ma hong|phan mat|phan phu|dau goi|dau xa|dau duong toc|duong toc|\btoc\b|duong the|body|sua tam|khu mui|lan nach|trang rang|kem danh rang|nuoc suc mieng|\bnuoc hoa\b|parfum/u',
            $text
        ) === 1;
    }

    private function routineStepForProduct(array $product): string {
        $category = $this->normalizeText(implode(' ', [
            $product['loai_san_pham'] ?? '',
            $product['danh_muc_day_du'] ?? $product['danh_muc'] ?? '',
        ]));
        $name = $this->normalizeText((string)($product['ten_san_pham'] ?? ''));
        $text = $category . ' ' . $name;

        if (mb_strpos($category, 'tay trang mat') !== false || preg_match('/tay trang|micellar|cleansing water|cleansing oil|cleansing balm/u', $name)) {
            return 'remover';
        }
        if (mb_strpos($category, 'sua rua mat') !== false || preg_match('/sua rua mat|face wash|cleanser|foaming wash|cleansing foam/u', $text)) {
            return 'cleanser';
        }
        if (mb_strpos($category, 'toner') !== false || mb_strpos($category, 'nuoc can bang') !== false || preg_match('/toner|nuoc hoa hong|nuoc can bang/u', $text)) {
            return 'toner';
        }
        if (mb_strpos($category, 'ho tro tri mun') !== false || preg_match('/cham mun|spot treatment/u', $text)) {
            return 'spot_treatment';
        }
        if (mb_strpos($category, 'serum / tinh chat') !== false || preg_match('/serum|tinh chat|essence|ampoule|retinol|tretinoin|bha|aha|niacinamide|salicylic|benzoyl/u', $text)) {
            return 'treatment';
        }
        if (mb_strpos($category, 'chong nang da mat') !== false || preg_match('/chong nang|sunscreen|sunblock|spf/u', $text)) {
            return 'sunscreen';
        }
        if (mb_strpos($category, 'kem / gel / dau duong') !== false || preg_match('/duong am|kem duong|phuc hoi|moisturizer|face cream|gel duong|emulsion/u', $text)) {
            return 'moisturizer';
        }

        return 'other';
    }

    private function concernKeywords(string $concern): array {
        // Map vấn đề da sang các keyword thường xuất hiện trong mô tả/thành phần sản phẩm.
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
        // Map loại da sang keyword để biết sản phẩm có hợp với hồ sơ da hay không.
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
        // Chuẩn hóa text: chữ thường, bỏ dấu tiếng Việt, gộp khoảng trắng.
        // Nhờ vậy "sữa rửa mặt" và "sua rua mat" được xem là cùng một nhu cầu.
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
        $text = preg_replace('/\s+/u', ' ', $text);
        return $text ?? '';
    }

    /**
     * Compatibility Engine: Đánh giá độ tương thích chuẩn xác giữa Hồ sơ User ↔ Sản phẩm.
     * Tách biệt 3 nhóm:
     * Group A: Skin Compatibility (Skin Type: 40, Concerns: 40, Avoid Ingredients: 20) -> tính compatibility_score.
     * Group B: Personalization (Budget, Query match) -> tạo lý do personalization_reasons, không làm tăng score skin.
     * Group C: Retrieval Rank -> giữ nội bộ.
     */
    /**
     * Parse legacy Q7 (Texture) and Q8 (Desired Ingredients) from khach_hang.tieu_chi_uu_tien
     */
    public function parseLegacyPriorities(?string $rawText): array {
        $text = trim((string)($rawText ?? ''));
        $textures = [];
        $ingredients = [];

        if ($text === '') {
            return ['textures' => [], 'ingredients' => []];
        }

        $parts = explode('|', $text);
        foreach ($parts as $part) {
            $p = trim($part);
            if (mb_stripos($p, 'Kết cấu:') === 0) {
                $sub = trim(mb_substr($p, mb_strlen('Kết cấu:')));
                if ($sub !== '') {
                    $textures = array_map('trim', explode(',', $sub));
                }
            } elseif (mb_stripos($p, 'Hoạt chất:') === 0) {
                $sub = trim(mb_substr($p, mb_strlen('Hoạt chất:')));
                if ($sub !== '') {
                    $ingredients = array_map('trim', explode(',', $sub));
                }
            }
        }

        return [
            'textures' => array_values(array_unique(array_filter($textures))),
            'ingredients' => array_values(array_unique(array_filter($ingredients))),
        ];
    }

    /**
     * Canonical Skin Type Taxonomy
     */
    public function canonicalSkinType(?string $raw): string {
        $s = $this->normalizeText((string)($raw ?? ''));
        if ($s === '') return 'unknown';

        if (mb_strpos($s, 'da dau') !== false || mb_strpos($s, 'hon hop dau') !== false || mb_strpos($s, 'oily') !== false) {
            return 'oily';
        }
        if (mb_strpos($s, 'da kho') !== false || mb_strpos($s, 'hon hop kho') !== false || mb_strpos($s, 'dry') !== false) {
            return 'dry';
        }
        if (mb_strpos($s, 'hon hop') !== false || mb_strpos($s, 'combination') !== false) {
            return 'combination';
        }
        if (mb_strpos($s, 'thuong') !== false || mb_strpos($s, 'moi loai da') !== false || mb_strpos($s, 'normal') !== false) {
            return 'normal';
        }

        return 'unknown';
    }

    /**
     * Canonical Concerns Taxonomy
     */
    public function canonicalConcerns($rawConcerns): array {
        $items = is_array($rawConcerns) ? $rawConcerns : array_map('trim', explode(',', (string)$rawConcerns));
        $canonical = [];

        foreach ($items as $item) {
            $norm = $this->normalizeText((string)$item);
            if ($norm === '') continue;

            if (mb_strpos($norm, 'kho cang') !== false || mb_strpos($norm, 'bong troc') !== false) {
                $canonical[] = 'dry_flaky';
            } elseif (mb_strpos($norm, 'viem') !== false || mb_strpos($norm, 'sung do') !== false) {
                $canonical[] = 'inflammatory_acne';
            } elseif (mb_strpos($norm, 'an') !== false || mb_strpos($norm, 'dau den') !== false || mb_strpos($norm, 'mun') !== false) {
                $canonical[] = 'comedonal_acne';
            } elseif (mb_strpos($norm, 'lo chan long') !== false) {
                $canonical[] = 'large_pores';
            } elseif (mb_strpos($norm, 'tham') !== false || mb_strpos($norm, 'sam') !== false || mb_strpos($norm, 'nam') !== false || mb_strpos($norm, 'tan nhang') !== false) {
                $canonical[] = 'hyperpigmentation';
            } elseif (mb_strpos($norm, 'lao hoa') !== false || mb_strpos($norm, 'nep nhan') !== false) {
                $canonical[] = 'aging';
            }
        }

        return array_values(array_unique($canonical));
    }

    /**
     * Canonical Goal Taxonomy
     */
    public function canonicalGoal(?string $rawGoal): string {
        $norm = $this->normalizeText((string)($rawGoal ?? ''));
        if ($norm === '') return '';

        if (mb_strpos($norm, 'sach mun') !== false || mb_strpos($norm, 'giam viem') !== false) {
            return 'acne_control';
        }
        if (mb_strpos($norm, 'duong sang') !== false || mb_strpos($norm, 'mo tham') !== false || mb_strpos($norm, 'nam') !== false) {
            return 'brightening';
        }
        if (mb_strpos($norm, 'phuc hoi') !== false || mb_strpos($norm, 'cap am') !== false || mb_strpos($norm, 'mang bao ve') !== false) {
            return 'barrier_hydration';
        }
        if (mb_strpos($norm, 'lao hoa') !== false || mb_strpos($norm, 'tre hoa') !== false) {
            return 'anti_aging';
        }

        return '';
    }

    /**
     * Determine Product Scope from Category and Name
     */
    public function determineProductScope(string $danhMucFull, string $tenSanPham): string {
        $cat = mb_strtolower($danhMucFull, 'UTF-8');
        $name = mb_strtolower($tenSanPham, 'UTF-8');
        $combined = $cat . ' ' . $name;

        if (mb_strpos($combined, 'tẩy trang mắt') !== false || mb_strpos($combined, 'tẩy trang môi') !== false || mb_strpos($combined, 'eye & lip') !== false) {
            return 'eye_lip_care';
        }
        if (mb_strpos($combined, 'môi') !== false || mb_strpos($combined, 'lip') !== false) {
            return 'lip_care';
        }
        if (mb_strpos($combined, 'mắt') !== false || mb_strpos($combined, 'eye') !== false) {
            return 'eye_care';
        }
        if (mb_strpos($combined, 'trang điểm') !== false || mb_strpos($combined, 'makeup') !== false || mb_strpos($combined, 'cushion') !== false || mb_strpos($combined, 'kem nền') !== false) {
            return 'makeup';
        }
        if (mb_strpos($combined, 'cơ thể') !== false || mb_strpos($combined, 'sữa tắm') !== false || mb_strpos($combined, 'dưỡng thể') !== false || mb_strpos($combined, 'body') !== false) {
            return 'body_care';
        }
        if (mb_strpos($combined, 'tóc') !== false || mb_strpos($combined, 'dầu gội') !== false || mb_strpos($combined, 'hair') !== false) {
            return 'hair_care';
        }
        if (mb_strpos($combined, 'chăm sóc da mặt') !== false || mb_strpos($combined, 'sữa rửa mặt') !== false || mb_strpos($combined, 'serum') !== false || mb_strpos($combined, 'kem dưỡng') !== false || mb_strpos($combined, 'tẩy trang mặt') !== false || mb_strpos($combined, 'chống nắng') !== false || mb_strpos($combined, 'mặt nạ giấy') !== false) {
            return 'facial_skincare';
        }

        return 'facial_skincare';
    }

    /**
     * Clean product haystack text to prevent false positives from storage text,Hasaki footer, and negations
     */
    public function cleanProductHaystack(string $rawText): string {
        $text = $rawText;

        // Strip Hasaki invoice footer & terms
        $text = preg_replace('/bảo quản\s*:?[^\n.]*/iu', ' ', $text);
        $text = preg_replace('/nơi khô ráo[^\n.]*/iu', ' ', $text);
        $text = preg_replace('/hóa đơn GTGT[^\n.]*/iu', ' ', $text);
        $text = preg_replace('/khách hàng có lấy hay không[^\n.]*/iu', ' ', $text);
        $text = preg_replace('/hàng không nguồn gốc[^\n.]*/iu', ' ', $text);

        // Replace negation phrases
        $text = preg_replace('/không\s+gây\s+khô[a-z\s]*/iu', ' ', $text);
        $text = preg_replace('/không\s+khô[a-z\s]*/iu', ' ', $text);
        $text = preg_replace('/không\s+gây\s+kích\s+ứng[a-z\s]*/iu', ' ', $text);

        return $text;
    }

    /**
     * Compatibility Engine V2
     * Scope-aware, Canonical Taxonomies, Goal Matching, Word-Boundary Keyword Parsing, Separate Safety Status
     */
    public function evaluateCompatibility(array $product, array $profile): array {
        $rawSkinType = trim((string)($profile['skin_type'] ?? ''));
        $userSkinCanonical = $this->canonicalSkinType($rawSkinType);

        $rawConcerns = $profile['concerns'] ?? [];
        $userConcernsCanonical = $this->canonicalConcerns($rawConcerns);

        $rawGoal = trim((string)($profile['goal'] ?? $profile['muc_tieu_cham_soc'] ?? ''));
        $userGoalCanonical = $this->canonicalGoal($rawGoal);

        $avoidIngredients = is_array($profile['avoid_ingredients'] ?? null) ? $profile['avoid_ingredients'] : [];
        if (is_string($profile['avoid_ingredients'] ?? null) && trim($profile['avoid_ingredients']) !== '') {
            $avoidIngredients = array_map('trim', explode(',', $profile['avoid_ingredients']));
        }

        $budget = isset($profile['budget']) && (int)$profile['budget'] > 0 ? (int)$profile['budget'] : null;
        $sensitivity = trim((string)($profile['sensitivity'] ?? ''));
        $userQuery = trim((string)($profile['user_query'] ?? $profile['query_text'] ?? ''));

        $preferredTextures = is_array($profile['preferred_textures'] ?? null) ? $profile['preferred_textures'] : [];
        $desiredIngredients = is_array($profile['desired_ingredients'] ?? null) ? $profile['desired_ingredients'] : [];
        $preferredCountries = is_array($profile['preferred_countries'] ?? null) ? $profile['preferred_countries'] : [];
        $preferredBrands = is_array($profile['preferred_brands'] ?? null) ? $profile['preferred_brands'] : [];

        $moTa = trim((string)($product['mo_ta'] ?? ''));
        $tenSanPham = trim((string)($product['ten_san_pham'] ?? ''));
        $danhMuc = trim((string)($product['danh_muc_day_du'] ?? ''));
        $loaiDaProduct = trim((string)($product['loai_da'] ?? ''));
        $congDung = trim((string)($product['cong_dung'] ?? ''));
        $thanhPhanChinh = trim((string)($product['thanh_phan_chinh'] ?? ''));
        $thanhPhanDayDu = trim((string)($product['thanh_phan_day_du'] ?? ''));

        // Determine Product Scope
        $productScope = $this->determineProductScope($danhMuc, $tenSanPham);
        $isFacialSkincare = ($productScope === 'facial_skincare');

        $cleanedText = $this->cleanProductHaystack(implode(' ', [$tenSanPham, $moTa, $danhMuc, $loaiDaProduct, $congDung]));
        $normalizedText = $this->normalizeText($cleanedText);
        $ingredientText = $this->normalizeText(implode(' ', [$thanhPhanChinh, $thanhPhanDayDu]));

        $skinReasons = [];
        $personalizationReasons = [];
        $warnings = [];

        $totalPossibleCompatibilityWeight = 100; // Facial Skincare baseline total weight: Skin Type (35) + Concerns (40) + Goal (25)
        $totalEvaluableCompatibilityWeight = 0;
        $earnedSkinPoints = 0.0;

        $evidence = [
            'skin_type' => null,
            'concerns' => null,
            'goal' => null,
            'avoid_ingredients' => null,
            'scope' => $productScope,
        ];

        // SCOPE CHECK: Non-facial products do not receive facial compatibility score
        if (!$isFacialSkincare && $userQuery === '') {
            $scopeLabel = $productScope === 'lip_care' ? 'Chăm sóc môi' : ($productScope === 'eye_lip_care' ? 'Tẩy trang mắt/môi' : ($productScope === 'eye_care' ? 'Chăm sóc mắt' : 'Trang điểm / Thể'));
            $warnings[] = "Sản phẩm thuộc nhóm {$scopeLabel}, không tính % độ phù hợp với hồ sơ da mặt.";

            // Evaluate Safety Data Status
            $hasIngredients = ($ingredientText !== '');
            $safetyStatus = $hasIngredients ? 'complete' : 'insufficient';
            if (!$hasIngredients) {
                $warnings[] = 'Chưa đủ dữ liệu thành phần để kiểm tra các thành phần cần tránh.';
            }

            return [
                'retrieval_score' => (float)($product['retrieval_score'] ?? $product['_rank_score'] ?? $product['score'] ?? 0.0),
                'raw_compatibility_score' => 0,
                'compatibility_score' => null,
                'compatibility_coverage' => 0,
                'data_coverage' => 0,
                'data_status' => 'scope_mismatch',
                'safety_data_status' => $safetyStatus,
                'reasons' => [],
                'personalization_reasons' => $personalizationReasons,
                'warnings' => array_values(array_unique($warnings)),
                'evidence' => $evidence,
            ];
        }

        // 1. SKIN TYPE MATCH (Baseline Weight: 35%)
        if ($userSkinCanonical !== 'unknown') {
            $hasSkinInfo = false;
            $isMatch = false;
            $isAllSkin = false;
            $isConflict = false;

            if ($userSkinCanonical === 'oily' && (mb_strpos($normalizedText, 'da dau') !== false || mb_strpos($normalizedText, 'kiem dau') !== false || mb_strpos($normalizedText, 'hon hop thien dau') !== false)) {
                $hasSkinInfo = true; $isMatch = true;
            } elseif ($userSkinCanonical === 'dry' && (mb_strpos($normalizedText, 'da kho') !== false || mb_strpos($normalizedText, 'cap am') !== false || mb_strpos($normalizedText, 'duong am') !== false || mb_strpos($normalizedText, 'phuc hoi') !== false)) {
                $hasSkinInfo = true; $isMatch = true;
            } elseif ($userSkinCanonical === 'combination' && (mb_strpos($normalizedText, 'hon hop') !== false || mb_strpos($normalizedText, 'can bang') !== false)) {
                $hasSkinInfo = true; $isMatch = true;
            } elseif ($userSkinCanonical === 'normal' && (mb_strpos($normalizedText, 'da thuong') !== false || mb_strpos($normalizedText, 'moi loai da') !== false)) {
                $hasSkinInfo = true; $isMatch = true;
            }

            if (!$isMatch) {
                if (mb_strpos($normalizedText, 'moi loai da') !== false || mb_strpos($normalizedText, 'tat ca loai da') !== false || mb_strpos($normalizedText, 'moi lan da') !== false) {
                    $hasSkinInfo = true;
                    $isAllSkin = true;
                } elseif ($userSkinCanonical === 'dry' && mb_strpos($normalizedText, 'chi danh cho da dau') !== false) {
                    $hasSkinInfo = true; $isConflict = true;
                } elseif ($userSkinCanonical === 'oily' && mb_strpos($normalizedText, 'chi danh cho da kho') !== false) {
                    $hasSkinInfo = true; $isConflict = true;
                }
            }

            if ($hasSkinInfo) {
                $totalEvaluableCompatibilityWeight += 35;
                if ($isMatch) {
                    $earnedSkinPoints += 35.0;
                    $skinReasons[] = 'Phù hợp với loại da ' . $rawSkinType . ' của bạn.';
                    $evidence['skin_type'] = ['user' => $userSkinCanonical, 'match' => true, 'score' => 35];
                } elseif ($isAllSkin) {
                    $earnedSkinPoints += 28.0; // 80% of 35
                    $skinReasons[] = 'Phù hợp với mọi loại da, bao gồm loại da của bạn.';
                    $evidence['skin_type'] = ['user' => $userSkinCanonical, 'match' => 'all_skin', 'score' => 28];
                } elseif ($isConflict) {
                    $warnings[] = 'Sản phẩm được thiết kế cho loại da khác, có thể không tối ưu cho da bạn.';
                    $evidence['skin_type'] = ['user' => $userSkinCanonical, 'match' => false, 'score' => 0];
                }
            } else {
                $warnings[] = 'Chưa tìm thấy thông tin loại da phù hợp của sản phẩm trong mô tả.';
                $evidence['skin_type'] = ['user' => $userSkinCanonical, 'evaluable' => false];
            }
        }

        // 2. CONCERNS MATCH (Baseline Weight: 40%)
        if (!empty($userConcernsCanonical)) {
            $matchedCanonical = [];

            foreach ($userConcernsCanonical as $cId) {
                $hit = false;
                if ($cId === 'dry_flaky') {
                    if (preg_match('/\b(bong\s+troc|kho\s+cang|nut\s+ne|da\s+kho\s+cang|kho\s+bong|troc\s+da)\b/iu', $normalizedText)) {
                        $hit = true;
                    }
                } elseif ($cId === 'inflammatory_acne') {
                    if (preg_match('/\b(mun\s+viem|sung\s+do|giam\s+mun|mun\s+do|mun)\b/iu', $normalizedText)) {
                        $hit = true;
                    }
                } elseif ($cId === 'comedonal_acne') {
                    if (preg_match('/\b(mun\s+an|mun\s+dau\s+den|lo\s+chan\s+long|giam\s+mun|sach\s+mun|mun)\b/iu', $normalizedText)) {
                        $hit = true;
                    }
                } elseif ($cId === 'large_pores') {
                    if (preg_match('/\b(lo\s+chan\s+long|se\s+khang|khang\s+khuan)\b/iu', $normalizedText)) {
                        $hit = true;
                    }
                } elseif ($cId === 'hyperpigmentation') {
                    if (preg_match('/\b(tham\s+mun|sam\s+nam|tan\s+nhang|duong\s+sang|mo\s+tham|mo\s+sam|nam|tham)\b/iu', $normalizedText)) {
                        $hit = true;
                    }
                } elseif ($cId === 'aging') {
                    if (preg_match('/\b(lao\s+hoa|nep\s+nhan|tre\s+hoa|san\s+chac)\b/iu', $normalizedText)) {
                        $hit = true;
                    }
                }

                if ($hit) {
                    $matchedCanonical[] = $cId;
                }
            }

            if ($normalizedText !== '') {
                $totalEvaluableCompatibilityWeight += 40;
                $matchedCount = count($matchedCanonical);
                $totalUserConcerns = count($userConcernsCanonical);
                $ratio = $matchedCount / max(1, $totalUserConcerns);
                $earnedConcerns = 40.0 * $ratio;
                $earnedSkinPoints += $earnedConcerns;

                if ($matchedCount > 0) {
                    $skinReasons[] = 'Hỗ trợ ' . $matchedCount . '/' . $totalUserConcerns . ' vấn đề da bạn đang quan tâm.';
                } else {
                    $warnings[] = 'Chưa phát hiện bằng chứng mô tả trực tiếp hỗ trợ các vấn đề da của bạn.';
                }

                $evidence['concerns'] = [
                    'user' => $userConcernsCanonical,
                    'matched' => $matchedCanonical,
                    'match_count' => $matchedCount,
                    'score' => round($earnedConcerns, 1)
                ];
            } else {
                $warnings[] = 'Mô tả sản phẩm chưa đủ dữ liệu để đánh giá các vấn đề da.';
                $evidence['concerns'] = ['user' => $userConcernsCanonical, 'evaluable' => false];
            }
        }

        // 3. GOAL MATCH (Baseline Weight: 25%)
        if ($userGoalCanonical !== '') {
            $goalHit = false;

            if ($userGoalCanonical === 'acne_control' && (mb_strpos($normalizedText, 'sach mun') !== false || mb_strpos($normalizedText, 'giam viem') !== false || mb_strpos($normalizedText, 'ngua mun') !== false)) {
                $goalHit = true;
            } elseif ($userGoalCanonical === 'brightening' && (mb_strpos($normalizedText, 'duong sang') !== false || mb_strpos($normalizedText, 'mo tham') !== false || mb_strpos($normalizedText, 'sang da') !== false)) {
                $goalHit = true;
            } elseif ($userGoalCanonical === 'barrier_hydration' && (mb_strpos($normalizedText, 'phuc hoi') !== false || mb_strpos($normalizedText, 'cap am') !== false || mb_strpos($normalizedText, 'duong am') !== false)) {
                $goalHit = true;
            } elseif ($userGoalCanonical === 'anti_aging' && (mb_strpos($normalizedText, 'lao hoa') !== false || mb_strpos($normalizedText, 'tre hoa') !== false || mb_strpos($normalizedText, 'nep nhan') !== false)) {
                $goalHit = true;
            }

            if ($normalizedText !== '') {
                $totalEvaluableCompatibilityWeight += 25;
                if ($goalHit) {
                    $earnedSkinPoints += 25.0;
                    $skinReasons[] = 'Khớp với mục tiêu chăm sóc da ưu tiên của bạn (' . htmlspecialchars($rawGoal) . ').';
                    $evidence['goal'] = ['user' => $userGoalCanonical, 'match' => true, 'score' => 25];
                } else {
                    $warnings[] = 'Chưa thấy bằng chứng mô tả hỗ trợ trực tiếp cho mục tiêu (' . htmlspecialchars($rawGoal) . ').';
                    $evidence['goal'] = ['user' => $userGoalCanonical, 'match' => false, 'score' => 0];
                }
            } else {
                $warnings[] = 'Mô tả sản phẩm chưa đủ dữ liệu để đánh giá mục tiêu chăm sóc da.';
                $evidence['goal'] = ['user' => $userGoalCanonical, 'evaluable' => false];
            }
        }

        // 4. SAFETY CONSTRAINT (Avoid Ingredients Check - Independent Safety Status)
        $cleanAvoid = array_values(array_unique(array_filter(array_map('trim', $avoidIngredients), fn($v) => $v !== '')));
        $ingredientConflict = false;
        $hasIngredientData = ($ingredientText !== '');

        if (!empty($cleanAvoid) && $hasIngredientData) {
            $foundHits = [];
            foreach ($cleanAvoid as $badIng) {
                $badNorm = $this->normalizeText($badIng);
                if ($badNorm !== '' && mb_strpos($ingredientText, $badNorm) !== false) {
                    $foundHits[] = $badIng;
                }
            }

            if (!empty($foundHits)) {
                $ingredientConflict = true;
                $warnings[] = 'Sản phẩm có chứa "' . implode(', ', $foundHits) . '", nằm trong danh sách thành phần bạn muốn tránh.';
                $evidence['avoid_ingredients'] = ['user' => $cleanAvoid, 'found' => $foundHits, 'conflict' => true];
            } else {
                $evidence['avoid_ingredients'] = ['user' => $cleanAvoid, 'found' => [], 'conflict' => false];
            }
        }

        $safetyDataStatus = $hasIngredientData ? 'complete' : 'insufficient';
        if (!$hasIngredientData) {
            $warnings[] = 'Chưa đủ dữ liệu thành phần để kiểm tra các thành phần cần tránh.';
            $evidence['avoid_ingredients'] = ['user' => $cleanAvoid, 'evaluable' => false];
        }

        // 5. SENSITIVITY CHECK (Personalization note only, no score impact)
        $senNorm = $this->normalizeText($sensitivity);
        if ($senNorm === 'rat de' || $senNorm === 'co' || $senNorm === 'nhay cam') {
            if (mb_strpos($normalizedText, 'nhay cam') !== false || mb_strpos($normalizedText, 'diu nhe') !== false || mb_strpos($normalizedText, 'lanh tinh') !== false) {
                $personalizationReasons[] = 'Mô tả sản phẩm có đề cập phù hợp với da nhạy cảm.';
            } elseif (mb_strpos($ingredientText, 'huong lieu') !== false || mb_strpos($ingredientText, 'paraben') !== false || mb_strpos($ingredientText, 'alcohol') !== false) {
                $warnings[] = 'Da bạn nhạy cảm: Sản phẩm có chứa hương liệu/cồn/paraben, nên thử nghiệm trước khi dùng.';
            }
        }

        // 6. GROUP B: PERSONALIZATION REASONS
        $price = (int)($product['gia_ban'] ?? 0);
        if ($budget !== null && $price > 0) {
            if ($price <= $budget) {
                $personalizationReasons[] = 'Giá ' . number_format($price, 0, ',', '.') . 'đ nằm trong ngân sách chọn (' . number_format($budget, 0, ',', '.') . 'đ).';
            } else {
                $personalizationReasons[] = 'Giá ' . number_format($price, 0, ',', '.') . 'đ (vượt ngân sách ' . number_format($budget, 0, ',', '.') . 'đ).';
            }
        }

        if (!empty($preferredTextures)) {
            foreach ($preferredTextures as $tex) {
                if ($tex !== '' && mb_strpos($normalizedText, $this->normalizeText($tex)) !== false) {
                    $personalizationReasons[] = 'Đúng kết cấu ' . htmlspecialchars($tex) . ' bạn ưu tiên.';
                    break;
                }
            }
        }

        if (!empty($desiredIngredients)) {
            foreach ($desiredIngredients as $ing) {
                if ($ing !== '' && mb_strpos($ingredientText, $this->normalizeText($ing)) !== false) {
                    $personalizationReasons[] = 'Có chứa ' . htmlspecialchars($ing) . ' là hoạt chất bạn quan tâm.';
                    break;
                }
            }
        }

        if (!empty($preferredBrands)) {
            $brandName = trim((string)($product['ten_thuong_hieu'] ?? $product['thuong_hieu'] ?? ''));
            if ($brandName !== '') {
                foreach ($preferredBrands as $b) {
                    if ($b !== '' && mb_strpos(mb_strtolower($brandName), mb_strtolower($b)) !== false) {
                        $personalizationReasons[] = 'Thuộc thương hiệu ' . htmlspecialchars($brandName) . ' bạn ưu tiên.';
                        break;
                    }
                }
            }
        }

        $rating = (float)($product['diem_danh_gia'] ?? $product['rating'] ?? 0);
        if ($rating >= 4.0) {
            $personalizationReasons[] = 'Được người dùng đánh giá cao (' . number_format($rating, 1) . '/5★).';
        }

        // 7. COMPATIBILITY SCORE & COVERAGE CALCULATIONS
        $rawBaseSkinScore = $totalEvaluableCompatibilityWeight > 0 ? ($earnedSkinPoints / $totalEvaluableCompatibilityWeight) * 100.0 : 0.0;
        $rawCompatibilityScore = $rawBaseSkinScore;

        if ($ingredientConflict) {
            $rawCompatibilityScore = max(0.0, $rawCompatibilityScore - 30.0);
        }

        $compatibilityCoverage = $totalPossibleCompatibilityWeight > 0 ? (int)round(($totalEvaluableCompatibilityWeight / $totalPossibleCompatibilityWeight) * 100) : 0;

        $dataStatus = 'insufficient';
        if ($compatibilityCoverage >= 90) {
            $dataStatus = 'complete';
        } elseif ($compatibilityCoverage >= 60) {
            $dataStatus = 'partial';
        } else {
            $dataStatus = 'insufficient';
        }

        $displayScore = null;
        if ($dataStatus !== 'insufficient' && $totalEvaluableCompatibilityWeight > 0) {
            $displayScore = (int)min(100, max(0, round($rawCompatibilityScore)));
        }

        return [
            'retrieval_score' => (float)($product['retrieval_score'] ?? $product['_rank_score'] ?? $product['score'] ?? 0.0),
            'raw_compatibility_score' => (int)round($rawCompatibilityScore),
            'compatibility_score' => $displayScore,
            'compatibility_coverage' => $compatibilityCoverage,
            'data_coverage' => $compatibilityCoverage,
            'data_status' => $dataStatus,
            'safety_data_status' => $safetyDataStatus,
            'reasons' => array_values(array_unique($skinReasons)),
            'personalization_reasons' => array_values(array_unique($personalizationReasons)),
            'warnings' => array_values(array_unique($warnings)),
            'evidence' => $evidence,
        ];
    }
}

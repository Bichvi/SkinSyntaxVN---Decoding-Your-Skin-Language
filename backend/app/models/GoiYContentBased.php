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

        // Lấy danh sách sản phẩm mẫu từ MongoDB
        $products = $this->fetchCandidateProducts($limit * $fetchMultiplier, $budgetMin, $budgetMax);
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
            // Nếu người dùng ghi rõ loại sản phẩm thì lọc cứng theo tên/danh mục trước khi chấm điểm.
            if ($queryIntent !== '' && !$this->productMatchesQueryIntent($product, $queryIntent)) {
                continue;
            }
            // Tính điểm phù hợp dựa trên hồ sơ da, ngân sách, vấn đề da, đánh giá và loại sản phẩm.
            $scored[] = $this->scoreOne($product, [
                'gender' => $gender,
                'birth_year' => $birthYear,
                'skin_type' => mb_strtolower($skinType, 'UTF-8'),
                'budget' => $budget,
                'concerns' => $concerns,
                'avoid_ingredients' => $avoidIngredients,
                'query_intent' => $queryIntent,
            ]);
        }

        usort($scored, function (array $a, array $b) {
            // Điểm cao đứng trước; nếu bằng điểm thì sắp theo tên để kết quả ổn định.
            if ($a['score'] === $b['score']) {
                return strcmp((string)$a['ten_san_pham'], (string)$b['ten_san_pham']);
            }
            return $b['score'] <=> $a['score'];
        });

        $scored = array_values(array_filter($scored, function (array $item) use ($whitelistIds) {
            // Đảm bảo sản phẩm trả về vẫn thuộc tập sản phẩm vừa lấy từ MongoDB.
            $id = (string)($item['id'] ?? '');
            return $id !== '' && in_array($id, $whitelistIds, true);
        }));

        // Chỉ trả về số lượng sản phẩm mà giao diện cần hiển thị.
        return array_slice($scored, 0, $limit);
    }

    private function fetchCandidateProducts(int $limit, ?int $budgetMin = null, ?int $budgetMax = null): array {
        // Lấy danh sách sản phẩm từ MongoDB theo khoảng giá, rồi bổ sung thêm brand/xuất xứ/danh mục.
        $filter = [];
        if ($budgetMin !== null || $budgetMax !== null) {
            // Tạo filter cho gia_ban: >= min và/hoặc <= max.
            $filter['gia_ban'] = [];
            if ($budgetMin !== null) $filter['gia_ban']['$gte'] = $budgetMin;
            if ($budgetMax !== null) $filter['gia_ban']['$lte'] = $budgetMax;
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
}

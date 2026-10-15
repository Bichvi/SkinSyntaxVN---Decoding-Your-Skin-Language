<?php
// backend/app/services/SkincareProductClassifier.php

/**
 * Phân loại sản phẩm cho routine chăm sóc da mặt.
 *
 * Trước đây HomeController, GoiYContentBased và view goiy.php mỗi nơi giữ một
 * bản regex riêng, lệch nhau và lọc nhầm (ví dụ "Nước Hoa Hồng" bị coi là nước
 * hoa, "Đậu Xanh" khớp "dầu xả", "Dưỡng The Lab" khớp "dưỡng thể"). Lớp này là
 * nguồn duy nhất: ưu tiên cây danh mục (danh_muc_day_du) vì đó là dữ liệu có
 * cấu trúc, chỉ đoán theo tên khi sản phẩm không mang danh mục.
 */
final class SkincareProductClassifier
{
    public const ROUTINE_STEPS = ['remover', 'cleanser', 'toner', 'treatment', 'moisturizer', 'sunscreen', 'spot_treatment'];

    // Lá danh mục (đã bỏ dấu) -> bước routine.
    private const CATEGORY_STEPS = [
        'tay trang mat' => 'remover',
        'sua rua mat' => 'cleanser',
        'toner / nuoc can bang da' => 'toner',
        'serum / tinh chat' => 'treatment',
        'kem / gel / dau duong' => 'moisturizer',
        'lotion / sua duong' => 'moisturizer',
        'chong nang da mat' => 'sunscreen',
        'ho tro tri mun' => 'spot_treatment',
    ];

    // Nhánh danh mục không thuộc chăm sóc da mặt.
    private const NON_FACIAL_CATEGORY = '/(^|->)\s*(trang diem|cham soc co the|cham soc toc va da dau|cham soc toc|duong moi|nuoc hoa|cham soc rang mieng)\s*(->|$)/u';

    // Tín hiệu trang điểm trong tên, kể cả khi sản phẩm nằm trong nhánh da mặt
    // (ví dụ "BB Chống Nắng Trang Điểm" xếp ở danh mục chống nắng).
    private const MAKEUP_NAME = '/(?<!\p{L})(kem nền|phấn nền|phấn phủ|phấn nước|cushion|che khuyết điểm|foundation|concealer|bb cream|cc cream|trang điểm)(?!\p{L})/u';

    // Dùng khi không có cây danh mục. So khớp trên chữ có dấu để "dầu xả" không
    // khớp "đậu xanh", "dưỡng thể" không khớp "dưỡng The Lab".
    private const NON_FACIAL_NAME = '/(?<!\p{L})(son|son môi|son kem|son thỏi|son bóng|son dưỡng|má hồng|phấn|trang điểm|makeup|kem nền|cushion|che khuyết điểm|concealer|foundation|mascara|kẻ mắt|eyeliner|dưỡng thể|sữa tắm|body (lotion|wash|mist|scrub|butter|oil|cream)|dầu gội|dầu xả|dưỡng tóc|tóc|khử mùi|lăn nách|trắng răng|kem đánh răng|nước súc miệng|nước hoa(?! hồng)|parfum|perfume)(?!\p{L})/u';

    private const REMOVER_NAME = '/(?<!\p{L})(tẩy trang|micellar|cleansing (water|oil|balm)|makeup remover)(?!\p{L})/u';

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, [
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
            'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
            'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',
        ]);
        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    public static function productName(array $product): string
    {
        return trim((string)($product['ten_san_pham'] ?? $product['name'] ?? $product['title'] ?? ''));
    }

    /** Cây danh mục đầy đủ nếu có, nếu không thì tên danh mục/loại đơn lẻ. */
    public static function categoryText(array $product): string
    {
        foreach (['danh_muc_day_du', 'category', 'danh_muc', 'loai_san_pham'] as $field) {
            $value = trim((string)($product[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** Bước routine của một lá danh mục (tên danh mục đơn lẻ), null nếu không thuộc routine. */
    public static function stepForCategoryLeaf(string $categoryName): ?string
    {
        return self::CATEGORY_STEPS[self::normalize($categoryName)] ?? null;
    }

    public static function routineStep(array $product): string
    {
        $category = self::normalize(self::categoryText($product));
        if ($category !== '') {
            $segments = array_map('trim', explode('->', $category));
            $leaf = (string)end($segments);
            if (isset(self::CATEGORY_STEPS[$leaf])) {
                return self::CATEGORY_STEPS[$leaf];
            }
            // Có cây danh mục đầy đủ mà lá không thuộc routine (mặt nạ, xịt khoáng,
            // bộ sản phẩm...): không đoán lại theo tên, tránh "mặt nạ dưỡng ẩm"
            // bị xếp vào bước kem dưỡng.
            if (count($segments) > 1) {
                return 'other';
            }
        }

        $text = self::normalize(self::productName($product)) . ' ' . $category;
        if (preg_match('/tay trang|micellar|cleansing (water|oil|balm)/u', $text)) return 'remover';
        if (preg_match('/sua rua mat|rua mat|face wash|cleanser|foaming wash|cleansing foam/u', $text)) return 'cleanser';
        if (preg_match('/toner|nuoc hoa hong|nuoc can bang/u', $text)) return 'toner';
        if (preg_match('/ho tro tri mun|cham mun|mieng dan mun|spot treatment/u', $text)) return 'spot_treatment';
        if (preg_match('/chong nang|sunscreen|sunblock|\bspf\b/u', $text)) return 'sunscreen';
        if (preg_match('/serum|tinh chat|essence|ampoule|retinol|tretinoin|\bbha\b|\baha\b|niacinamide|salicylic|benzoyl/u', $text)) return 'treatment';
        if (preg_match('/duong am|kem duong|phuc hoi|moisturi[sz]er|cream|gel duong|emulsion|lotion/u', $text)) return 'moisturizer';
        return 'other';
    }

    public static function isNonFacial(array $product): bool
    {
        $name = mb_strtolower(self::productName($product), 'UTF-8');
        $categoryRaw = self::categoryText($product);
        $category = self::normalize($categoryRaw);

        // Tẩy trang thường ghi "làm sạch lớp trang điểm"/"makeup remover": đó là
        // bước làm sạch, không phải sản phẩm trang điểm.
        $nameForMakeup = preg_match(self::REMOVER_NAME, $name)
            ? preg_replace('/(?<!\p{L})(lớp )?(trang điểm|makeup)(?!\p{L})/u', ' ', $name)
            : $name;

        if (str_contains($category, '->')) {
            if (preg_match(self::NON_FACIAL_CATEGORY, $category)) {
                return true;
            }
            if (str_contains($category, 'cham soc da mat')) {
                return preg_match(self::MAKEUP_NAME, (string)$nameForMakeup) === 1;
            }
        }

        $text = trim($nameForMakeup . ' ' . mb_strtolower($categoryRaw, 'UTF-8'));
        return preg_match(self::NON_FACIAL_NAME, $text) === 1;
    }
}

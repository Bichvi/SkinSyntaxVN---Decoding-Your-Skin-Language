<?php
// Smart Search Bước 08: rút gọn hồ sơ da khách hàng (khach_hang) thành {skin_type, concerns} theo key đồ thị
// để gửi Flask /api/search/recommend. Thuần (không DB): không tên/email/ngân sách; giá trị lạ bị bỏ.

final class SearchSkinProfile
{
    /** ma_loai_da -> key SkinType (giống graph_sync: 1/8/9 da_dau, 5/7/10 da_kho, 4 Unknown bỏ). */
    public const SKIN_TYPE_BY_CODE = [
        1 => 'da_dau', 8 => 'da_dau', 9 => 'da_dau',
        5 => 'da_kho', 7 => 'da_kho', 10 => 'da_kho',
        2 => 'moi_loai_da', 3 => 'da_nhay_cam', 6 => 'da_mun',
    ];

    public const SKIN_TYPES = ['da_dau', 'da_kho', 'da_nhay_cam', 'da_mun', 'moi_loai_da'];
    public const CONCERNS = ['mun', 'tham_mun', 'nam_tan_nhang', 'lo_chan_long', 'dau_thua', 'kho_da', 'kich_ung', 'lao_hoa', 'xin_mau'];

    /** Nhãn khảo sát (đã bỏ dấu, lower) -> key Concern (theo data/graph/synonyms.yaml). */
    public const CONCERN_ALIASES = [
        'mun' => 'mun', 'mun viem' => 'mun', 'mun an' => 'mun', 'mun dau den' => 'mun', 'acne' => 'mun', 'tri mun' => 'mun',
        'tham mun' => 'tham_mun', 'tham' => 'tham_mun', 'vet tham' => 'tham_mun',
        'nam' => 'nam_tan_nhang', 'tan nhang' => 'nam_tan_nhang', 'sam nam' => 'nam_tan_nhang', 'dom nau' => 'nam_tan_nhang', 'nam tan nhang' => 'nam_tan_nhang',
        'lo chan long' => 'lo_chan_long', 'lo chan long to' => 'lo_chan_long',
        'dau thua' => 'dau_thua', 'bong dau' => 'dau_thua', 'do dau' => 'dau_thua', 'nhon' => 'dau_thua',
        'kho da' => 'kho_da', 'da kho cang' => 'kho_da', 'bong troc' => 'kho_da', 'thieu am' => 'kho_da', 'kho rap' => 'kho_da', 'mat nuoc' => 'kho_da',
        'kich ung' => 'kich_ung', 'sung do' => 'kich_ung', 'man do' => 'kich_ung', 'do da' => 'kich_ung', 'ung do' => 'kich_ung',
        'lao hoa' => 'lao_hoa', 'nep nhan' => 'lao_hoa', 'chay xe' => 'lao_hoa',
        'xin mau' => 'xin_mau', 'da xin' => 'xin_mau', 'sam da' => 'xin_mau', 'da xin mau' => 'xin_mau',
    ];

    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
        'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
        'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
        'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
        'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
        'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'đ' => 'd',
    ];

    /** Lower + bỏ dấu tiếng Việt + gộp khoảng trắng (cũng dùng cho q_norm của search_events). */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        if (class_exists('Normalizer')) {
            $text = (string)\Normalizer::normalize($text, \Normalizer::FORM_C);
        }
        $text = strtr($text, self::ACCENTS);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    public static function skinTypeFromField($tinhTrangDacBiet): ?string
    {
        if (!is_string($tinhTrangDacBiet) || !preg_match('/loaida:(\d+)/i', $tinhTrangDacBiet, $m)) {
            return null;
        }
        return self::SKIN_TYPE_BY_CODE[(int)$m[1]] ?? null;
    }

    /** van_de_da CSV (hoặc mảng) -> key concern hợp lệ, bỏ trùng, giữ thứ tự, tối đa 9. */
    public static function concernsFromField($vanDeDa): array
    {
        $parts = is_array($vanDeDa) ? $vanDeDa : explode(',', (string)$vanDeDa);
        $out = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }
            $label = self::fold($part);
            $key = self::CONCERN_ALIASES[$label] ?? (in_array(str_replace(' ', '_', $label), self::CONCERNS, true) ? str_replace(' ', '_', $label) : null);
            if ($key !== null && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return array_slice($out, 0, count(self::CONCERNS));
    }

    /** @return array{skin_type:?string,concerns:string[]}|null  null khi không có thông tin dùng được. */
    public static function fromCustomer(?array $khachHang): ?array
    {
        if (!$khachHang) {
            return null;
        }
        $skinType = self::skinTypeFromField($khachHang['tinh_trang_dac_biet'] ?? null);
        $concerns = self::concernsFromField($khachHang['van_de_da'] ?? '');
        if ($skinType === null && !$concerns) {
            return null;
        }
        return ['skin_type' => $skinType, 'concerns' => $concerns];
    }
}

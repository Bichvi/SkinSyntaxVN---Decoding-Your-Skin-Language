<?php
// Danh sách key công dụng / vấn đề da / loại da chuẩn (U07) cho form admin và trang duyệt gợi ý.
// Nguồn: backend/app/data/tag_keys.json, xuất từ ai-service-flask/data/graph/synonyms.yaml bằng
//   python ai-service-flask/scripts/export_tag_keys.py
// File thiếu/hỏng => danh sách rỗng (form không có checkbox, mọi key bị từ chối) chứ không lỗi trang.

final class TagCatalog
{
    public const DEFAULT_PATH = __DIR__ . '/../data/tag_keys.json';

    /** Nhóm => trường Mongo trên san_pham. */
    public const FIELDS = [
        'benefits' => 'cong_dung_keys',
        'concerns' => 'van_de_da_keys',
        'skin_types' => 'loai_da_keys',
    ];

    /** Nhãn tiếng Việt của từng nhóm. */
    public const LABELS = [
        'benefits' => 'Công dụng',
        'concerns' => 'Vấn đề da',
        'skin_types' => 'Loại da',
    ];

    private static ?string $path = null;
    private static ?array $data = null;

    /** Dùng cho test: đổi đường dẫn file (null = mặc định) và xóa cache trong tiến trình. */
    public static function setPath(?string $path): void
    {
        self::$path = $path;
        self::$data = null;
    }

    /** @return array<string,array<string,string>> nhóm => [key => tên tiếng Việt] */
    public static function groups(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $data = array_fill_keys(array_keys(self::FIELDS), []);
        $raw = @file_get_contents(self::$path ?? self::DEFAULT_PATH);
        $json = $raw === false ? null : json_decode($raw, true);
        foreach (is_array($json['groups'] ?? null) ? $json['groups'] : [] as $group => $items) {
            if (!isset($data[$group]) || !is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item) && isset($item['key']) && is_string($item['key']) && preg_match('/^[a-z0-9_]{1,64}$/', $item['key'])) {
                    $name = is_string($item['name'] ?? null) && trim($item['name']) !== '' ? trim($item['name']) : $item['key'];
                    $data[$group][$item['key']] = $name;
                }
            }
        }
        return self::$data = $data;
    }

    public static function field(string $group): ?string
    {
        return self::FIELDS[$group] ?? null;
    }

    public static function name(string $group, string $key): string
    {
        return self::groups()[$group][$key] ?? $key;
    }

    /**
     * Lọc key thuộc danh sách chuẩn của nhóm; bỏ trùng, giữ thứ tự.
     *
     * @param mixed $keys mảng (hoặc chuỗi đơn) key người dùng gửi lên
     * @return array{valid:string[],rejected:string[]}
     */
    public static function validate(string $group, $keys): array
    {
        $allowed = self::groups()[$group] ?? [];
        if (is_string($keys)) {
            $keys = [$keys];
        }
        $valid = [];
        $rejected = [];
        foreach (is_array($keys) ? $keys : [] as $key) {
            if (!is_string($key) && !is_int($key)) {
                $rejected[] = '(không phải chuỗi)';
                continue;
            }
            $key = trim((string)$key);
            if ($key === '' || in_array($key, $valid, true)) {
                continue;
            }
            if (isset($allowed[$key])) {
                $valid[] = $key;
            } else {
                $rejected[] = mb_substr($key, 0, 64);
            }
        }
        return ['valid' => $valid, 'rejected' => $rejected];
    }
}

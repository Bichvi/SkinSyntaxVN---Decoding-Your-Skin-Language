<?php
// Từ điển tìm kiếm dùng chung PHP <-> Python (U04): thương hiệu + danh mục + synonym đã bỏ dấu.
// Nguồn: backend/app/data/search_dictionary.json, xuất bởi ai-service-flask/scripts/export_search_dictionary.py
// (Neo4j/YAML). Chỉ chứa tên/key công khai. Cache: APCu nếu có (khóa theo mtime file), nếu không thì static
// trong request. File thiếu/hỏng => danh sách rỗng (nhóm brands/categories biến mất, không lỗi trang).

require_once __DIR__ . '/SearchSkinProfile.php';

final class SearchDictionary
{
    public const DEFAULT_PATH = __DIR__ . '/../data/search_dictionary.json';

    private static ?string $path = null;
    private static ?array $data = null;

    /** Dùng cho test: đổi đường dẫn file (null = mặc định) và xóa cache trong tiến trình. */
    public static function setPath(?string $path): void
    {
        self::$path = $path;
        self::$data = null;
    }

    /** @return array{brands:array<int,array>,categories:array<int,array>} */
    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $path = self::$path ?? self::DEFAULT_PATH;
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return self::$data = ['brands' => [], 'categories' => []];
        }
        $apcuKey = 'ssv:searchdict:v1:' . md5($path) . ':' . $mtime;
        if (function_exists('apcu_fetch')) {
            $hit = @apcu_fetch($apcuKey, $ok);
            if ($ok && is_array($hit)) {
                return self::$data = $hit;
            }
        }
        $data = ['brands' => [], 'categories' => []];
        $raw = @file_get_contents($path);
        $json = $raw === false ? null : json_decode($raw, true);
        if (is_array($json)) {
            $data['brands'] = self::cleanList($json['brands'] ?? [], false);
            $data['categories'] = self::cleanList($json['categories'] ?? [], true);
        }
        if (function_exists('apcu_store')) {
            @apcu_store($apcuKey, $data, 3600);
        }
        return self::$data = $data;
    }

    /** @return array<int,array{key:string,name:string,fold:string,aliases?:array<int,string>}> */
    private static function cleanList($list, bool $withAliases): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $row) {
            if (!is_array($row) || !isset($row['key'], $row['name']) || !is_string($row['name']) || trim($row['name']) === '') {
                continue;
            }
            $name = trim($row['name']);
            $item = [
                'key' => (string)$row['key'],
                'name' => $name,
                'fold' => is_string($row['fold'] ?? null) && $row['fold'] !== '' ? $row['fold'] : SearchSkinProfile::fold($name),
            ];
            if ($withAliases) {
                $item['aliases'] = array_values(array_filter(array_map(
                    static fn($a) => is_string($a) ? SearchSkinProfile::fold($a) : '',
                    is_array($row['aliases'] ?? null) ? $row['aliases'] : []
                ), static fn($a) => $a !== ''));
            }
            $out[] = $item;
        }
        return $out;
    }

    /** Thương hiệu có tên khớp tiền tố (không dấu) hoặc đầu một từ trong tên; tiền tố đầu chuỗi xếp trước. */
    public static function matchBrands(string $q, int $limit = 3): array
    {
        $fq = SearchSkinProfile::fold($q);
        if (mb_strlen($fq, 'UTF-8') < 2) {
            return [];
        }
        $hits = [];
        foreach (self::load()['brands'] as $b) {
            $rank = self::rank($b['fold'], $fq);
            if ($rank !== null) {
                $hits[] = [$rank, strlen($b['fold']), $b['fold'], ['key' => $b['key'], 'name' => $b['name']]];
            }
        }
        return self::top($hits, $limit);
    }

    /** Danh mục có tên hoặc synonym khớp tiền tố (không dấu); tên xếp trước synonym. */
    public static function matchCategories(string $q, int $limit = 3): array
    {
        $fq = SearchSkinProfile::fold($q);
        if (mb_strlen($fq, 'UTF-8') < 2) {
            return [];
        }
        $hits = [];
        foreach (self::load()['categories'] as $c) {
            $rank = self::rank($c['fold'], $fq);
            if ($rank === null) {
                foreach ($c['aliases'] as $alias) {
                    $r = self::rank($alias, $fq);
                    if ($r !== null && ($rank === null || $r + 2 < $rank)) {
                        $rank = $r + 2;
                    }
                }
            }
            if ($rank !== null) {
                $hits[] = [$rank, strlen($c['fold']), $c['fold'], ['key' => $c['key'], 'name' => $c['name']]];
            }
        }
        return self::top($hits, $limit);
    }

    /** 0 = tiền tố đầu chuỗi, 1 = tiền tố của một từ phía sau, null = không khớp. */
    private static function rank(string $foldedName, string $foldedQuery): ?int
    {
        if (strncmp($foldedName, $foldedQuery, strlen($foldedQuery)) === 0) {
            return 0;
        }
        $plain = static fn(string $t): string => ' ' . trim((string)preg_replace('/[^a-z0-9]+/', ' ', $t));
        $qn = $plain($foldedQuery);
        return $qn !== ' ' && strpos($plain($foldedName), $qn) !== false ? 1 : null;
    }

    private static function top(array $hits, int $limit): array
    {
        usort($hits, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return array_map(static fn(array $h): array => $h[3], array_slice($hits, 0, max(0, $limit)));
    }
}

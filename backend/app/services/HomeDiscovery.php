<?php
// Dữ liệu khối "Khám phá nhanh" trang chủ: từ khóa phổ biến (Redis) + hoạt chất soạn sẵn.
// Không escape ở đây (view dùng h()). Lỗi Redis -> chỉ dùng danh sách soạn sẵn.

require_once __DIR__ . '/SearchSkinProfile.php';
require_once __DIR__ . '/SearchPopularity.php';

final class HomeDiscovery
{
    public const CACHE_KEY = 'home:discovery:v1';
    public const CACHE_TTL = 300;
    public const MAX_REDIS_TERMS = 5;

    /**
     * Gộp từ khóa Redis (tối đa 5, đứng trước) với danh sách soạn sẵn, loại trùng theo fold().
     * @param array<int,array{q:string,score?:int}|string> $redisTop
     * @param array<int,string> $curated
     * @return array<int,string>
     */
    public static function trendingTerms(array $redisTop, array $curated, int $max = 8): array
    {
        $out = [];
        $seen = [];
        $add = static function (string $term) use (&$out, &$seen): bool {
            $term = trim($term);
            if ($term === '') {
                return false;
            }
            $k = SearchSkinProfile::fold($term);
            if ($k === '' || isset($seen[$k])) {
                return false;
            }
            $seen[$k] = true;
            $out[] = $term;
            return true;
        };
        $fromRedis = 0;
        foreach ($redisTop as $row) {
            if ($fromRedis >= self::MAX_REDIS_TERMS || count($out) >= $max) {
                break;
            }
            $q = is_array($row) ? (string)($row['q'] ?? '') : (string)$row;
            if ($add($q)) {
                $fromRedis++;
            }
        }
        foreach ($curated as $term) {
            if (count($out) >= $max) {
                break;
            }
            $add((string)$term);
        }
        return $out;
    }

    /** @return array{trending:array<int,string>,actives:array<int,array{q:string,name:string,desc:string}>} */
    public static function data(?RedisLite $redis = null): array
    {
        $cacheOn = $redis === null && class_exists('CacheService');
        if ($cacheOn) {
            try {
                $hit = CacheService::getInstance()->get(self::CACHE_KEY);
                if (is_array($hit) && isset($hit['trending'], $hit['actives'])) {
                    return $hit;
                }
            } catch (Throwable $e) {
                // bỏ qua cache lỗi
            }
        }
        $config = [];
        $file = __DIR__ . '/../config/home_discovery.php';
        if (is_file($file)) {
            $loaded = require $file;
            $config = is_array($loaded) ? $loaded : [];
        }
        $redisTop = [];
        try {
            $redisTop = SearchPopularity::top(self::MAX_REDIS_TERMS, $redis);
        } catch (Throwable $e) {
            $redisTop = [];
        }
        $result = [
            'trending' => self::trendingTerms($redisTop, (array)($config['trending'] ?? [])),
            'actives' => array_values((array)($config['actives'] ?? [])),
        ];
        if ($cacheOn) {
            try {
                CacheService::getInstance()->set(self::CACHE_KEY, $result, self::CACHE_TTL);
            } catch (Throwable $e) {
                // bỏ qua cache lỗi
            }
        }
        return $result;
    }
}

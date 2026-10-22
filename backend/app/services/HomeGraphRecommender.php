<?php
// Gom input trang chủ -> gọi Flask /api/home/recommend -> hydrate SP từ Mongo -> ghép câu lý do.
// Mọi lỗi -> khối null (trang chủ giữ nguyên các khối cũ). Không log hồ sơ da/giỏ hàng.
require_once __DIR__ . '/HomeRecommendClient.php';
require_once __DIR__ . '/HomeReasonText.php';
require_once __DIR__ . '/SearchSkinProfile.php';

final class HomeGraphRecommender
{
    public const CONCERN_NAMES = [
        'mun' => 'Mụn',
        'tham_mun' => 'Thâm mụn',
        'nam_tan_nhang' => 'Nám / tàn nhang',
        'lo_chan_long' => 'Lỗ chân lông to',
        'dau_thua' => 'Dầu thừa',
        'kho_da' => 'Khô / thiếu ẩm',
        'kich_ung' => 'Kích ứng / mẩn đỏ',
        'lao_hoa' => 'Lão hóa / nếp nhăn',
        'xin_mau' => 'Da xỉn màu',
    ];
    public const LIMITS = ['seen' => 4, 'skin' => 4, 'concern' => 8];
    private const MIN_ITEMS = 4;
    private const ID_PATTERN = '/^[0-9]{1,12}$/';

    /** @var callable fn(array $ids): array — Mongo products, mỗi phần tử có `ma_san_pham` */
    private $findVisibleByIds;

    public function __construct(private ?HomeRecommendClient $client, callable $findVisibleByIds)
    {
        $this->findVisibleByIds = $findVisibleByIds;
    }

    /**
     * @param array{seen_ids?:array,skin_profile?:?array,cart_ids?:array,exclude_ids?:array,concern?:?string,sections?:array} $ctx
     * @return array{seen:?array,skin:?array,concern:?array,concern_keys:array}
     */
    public function build(array $ctx): array
    {
        $result = ['seen' => null, 'skin' => null, 'concern' => null, 'concern_keys' => self::CONCERN_NAMES];
        if ($this->client === null) {
            return $result;
        }
        try {
            $sections = array_values(array_filter((array)($ctx['sections'] ?? []), static fn($s) => is_string($s) && isset(self::LIMITS[$s])));
            if (!$sections) {
                return $result;
            }
            $payload = [
                'sections' => $sections,
                'seen_ids' => self::cleanIds($ctx['seen_ids'] ?? [], 5),
                'skin_profile' => self::cleanProfile($ctx['skin_profile'] ?? null),
                'cart_ids' => self::cleanIds($ctx['cart_ids'] ?? [], 50),
                'exclude_ids' => self::cleanIds($ctx['exclude_ids'] ?? [], 100),
                'limits' => self::LIMITS,
            ];
            $concern = $ctx['concern'] ?? null;
            if (is_string($concern) && $concern !== '') {
                $payload['concern'] = $concern;
            }
            $response = $this->client->recommend($payload);
            if ($response === null) {
                return $result;
            }
            foreach ($response['sections'] as $section) {
                $key = $section['key'];
                if (!in_array($key, $sections, true)) {
                    continue;
                }
                $result[$key] = $this->hydrate($section);
            }
        } catch (Throwable $e) {
            error_log('[HOME_GRAPH] ' . get_class($e));
            $result['seen'] = $result['skin'] = $result['concern'] = null;
        }
        return $result;
    }

    private function hydrate(array $section): ?array
    {
        $ids = array_map(static fn($i) => $i['product_id'], $section['items']);
        if (!$ids) {
            return null;
        }
        $byId = [];
        foreach (($this->findVisibleByIds)($ids) as $p) {
            if (is_array($p) && isset($p['ma_san_pham'])) {
                $byId[(string)$p['ma_san_pham']] = $p;
            }
        }
        $products = [];
        foreach ($section['items'] as $item) {
            $p = $byId[$item['product_id']] ?? null;
            if ($p === null) {
                continue;
            }
            $p['reason_text'] = HomeReasonText::fromReason($item['reason']);
            $p['reason_code'] = $item['reason']['code'];
            $products[] = $p;
        }
        if (count($products) < self::MIN_ITEMS) {
            return null;
        }
        $titleData = [];
        foreach (['seed', 'skin_type', 'concern'] as $meta) {
            if (isset($section[$meta])) {
                $titleData[$meta] = $section[$meta];
            }
        }
        return ['title_data' => $titleData, 'products' => $products];
    }

    private static function cleanIds($ids, int $max): array
    {
        $out = [];
        foreach ((array)$ids as $id) {
            if (is_bool($id) || !(is_string($id) || is_int($id))) {
                continue;
            }
            $id = trim((string)$id);
            if (preg_match(self::ID_PATTERN, $id) && !in_array($id, $out, true)) {
                $out[] = $id;
                if (count($out) >= $max) {
                    break;
                }
            }
        }
        return $out;
    }

    private static function cleanProfile($profile): ?array
    {
        if (!is_array($profile)) {
            return null;
        }
        $skin = $profile['skin_type'] ?? null;
        $skin = (is_string($skin) && in_array($skin, SearchSkinProfile::SKIN_TYPES, true)) ? $skin : null;
        $concerns = [];
        foreach ((array)($profile['concerns'] ?? []) as $c) {
            if (is_string($c) && in_array($c, SearchSkinProfile::CONCERNS, true) && !in_array($c, $concerns, true)) {
                $concerns[] = $c;
            }
        }
        if ($skin === null && !$concerns) {
            return null;
        }
        return ['skin_type' => $skin, 'concerns' => $concerns];
    }
}

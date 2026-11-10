<?php
// Gom input trang chủ -> gọi Flask /api/home/recommend -> hydrate SP từ Mongo -> ghép câu lý do.
// Mọi lỗi -> khối null (trang chủ giữ nguyên các khối cũ). Không log hồ sơ da/giỏ hàng.
require_once __DIR__ . '/HomeRecommendClient.php';
require_once __DIR__ . '/HomeReasonText.php';
require_once __DIR__ . '/HomeReasonDetail.php';
require_once __DIR__ . '/HomeWhyFitCopy.php';
require_once __DIR__ . '/HomeFitDetail.php';
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
    public const LIMITS = ['seen' => 12, 'skin' => 4, 'concern' => 8];
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
     * @return array{seen:?array,skin:?array,concern:?array,concern_keys:array,failed:bool} failed=true khi lỗi thật (client tắt, Flask lỗi, exception)
     */
    public function build(array $ctx): array
    {
        $result = ['seen' => null, 'skin' => null, 'concern' => null, 'concern_keys' => self::CONCERN_NAMES, 'failed' => false];
        if ($this->client === null) {
            $result['failed'] = true;
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
                $result['failed'] = true;
                return $result;
            }
            $wanted = [];
            $allIds = [];
            foreach ($response['sections'] as $section) {
                if (!in_array($section['key'], $sections, true)) {
                    continue;
                }
                $wanted[] = $section;
                foreach ($section['items'] as $item) {
                    $allIds[$item['product_id']] = true;
                    // SP "đã xem" làm gốc so sánh: tải chung một lần truy vấn, không đưa vào danh sách hiển thị.
                    if ($section['key'] === 'seen') {
                        $sid = $item['reason']['seed_id'] ?? null;
                        if ((is_string($sid) || is_int($sid)) && preg_match(self::ID_PATTERN, (string)$sid)) {
                            $allIds[(string)$sid] = true;
                        }
                    }
                }
            }
            // Một lần truy vấn Mongo cho mọi khối (giảm round trip tới Atlas).
            $byId = $this->loadProducts(array_map('strval', array_keys($allIds)));
            foreach ($wanted as $section) {
                $result[$section['key']] = $this->hydrate($section, $byId);
            }
        } catch (Throwable $e) {
            error_log('[HOME_GRAPH] ' . get_class($e));
            $result['failed'] = true;
            $result['seen'] = $result['skin'] = $result['concern'] = null;
        }
        return $result;
    }

    /** @return array<string,array> sản phẩm Mongo theo ma_san_pham */
    private function loadProducts(array $ids): array
    {
        $byId = [];
        if (!$ids) {
            return $byId;
        }
        foreach (($this->findVisibleByIds)($ids) as $p) {
            if (is_array($p) && isset($p['ma_san_pham'])) {
                // Mongo trả mảng dạng BSONArray (đối tượng): đổi về mảng PHP để các hàm dựng câu/chi tiết đọc được.
                foreach (['cong_dung_keys', 'van_de_da_keys', 'loai_da_keys'] as $f) {
                    if (isset($p[$f]) && is_object($p[$f])) {
                        $p[$f] = $p[$f] instanceof Traversable ? iterator_to_array($p[$f], false) : array_values((array)$p[$f]);
                    }
                }
                $byId[(string)$p['ma_san_pham']] = $p;
            }
        }
        return $byId;
    }

    private function hydrate(array $section, array $byId): ?array
    {
        if (!$section['items']) {
            return null;
        }
        $products = [];
        $titleSeedId = isset($section['seed']['id']) ? (string)$section['seed']['id'] : '';
        $isSeen = $section['key'] === 'seen';
        foreach ($section['items'] as $item) {
            $p = $byId[$item['product_id']] ?? null;
            if ($p === null) {
                continue;
            }
            if ($isSeen) {
                $cat = '';
                if (is_string($p['danh_muc_day_du'] ?? null) && trim($p['danh_muc_day_du']) !== '') {
                    $segs = explode('->', $p['danh_muc_day_du']);
                    $cat = trim((string)end($segs));
                }
                $textOpts = ['omit_seed_id' => $titleSeedId, 'category' => $cat];
                $p['reason_text'] = HomeReasonText::fromReason($item['reason'], $textOpts);
                // Bản đầy đủ (không cắt tên SP gốc) cho popover trên thẻ.
                $p['reason_text_full'] = HomeReasonText::fromReason($item['reason'], $textOpts + ['full_seed' => true]);
                $sid = $item['reason']['seed_id'] ?? null;
                $seedProduct = (is_string($sid) || is_int($sid)) ? ($byId[(string)$sid] ?? null) : null;
                $p['reason_detail'] = HomeReasonDetail::build($item['reason'], $p, $seedProduct);
                $p['why_fit'] = HomeWhyFitCopy::build($item['reason'], $p['reason_detail'], $cat);
            } else {
                $p['reason_text'] = HomeReasonText::fromReason($item['reason']);
                $p['why_fit'] = HomeWhyFitCopy::forProduct($p, $item['reason'], (string)$section['key']);
                $p['fit_detail'] = HomeFitDetail::build($p, $item['reason'], (string)$section['key']);
            }
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

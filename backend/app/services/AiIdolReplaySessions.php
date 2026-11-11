<?php
declare(strict_types=1);

/**
 * Pure mapper: finished Syna AI Idol campaign -> a $liveSessions entry for live.php.
 * No DB or view-helper dependency (the controller resolves the fallback image).
 */
final class AiIdolReplaySessions
{
    private static function httpUrl($value): string
    {
        $url = is_string($value) ? trim($value) : '';
        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    public static function toSession(array $campaign, ?array $firstProduct, string $fallbackImage = ''): ?array
    {
        $id = trim((string)($campaign['_id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $replay = is_array($campaign['replay'] ?? null) ? $campaign['replay'] : [];
        $video = self::httpUrl($replay['video_url'] ?? null);
        if ($video === '') {
            return null;
        }
        $poster = self::httpUrl($replay['poster_url'] ?? null);

        return [
            'id' => 'ai-' . $id,
            'title' => (string)($campaign['name'] ?? ''),
            'streamer' => 'Syna · AI Idol',
            'viewers' => 0,
            'status' => 'replay_ready',
            'thumbnail' => $poster !== '' ? $poster : $fallbackImage,
            'pinned_product' => $firstProduct,
            'gia_uu_dai_live' => (float)($firstProduct['gia_ban'] ?? 0),
            'server_livekit_url' => '',
            'url_ban_ghi' => $video,
            'tom_tat_phien_live' => '',
            'luot_xem' => 0,
            'mat_do_nguoi_xem_dinh' => 0,
            'tong_doanh_thu' => 0,
            'tong_don_hang' => 0,
            'tong_san_pham_ban' => 0,
            'ty_le_chot_don' => 0,
            'danh_sach_deal' => [],
            'top_san_pham' => [],
            'description' => '',
        ];
    }
}

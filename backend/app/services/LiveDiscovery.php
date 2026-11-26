<?php

require_once __DIR__ . '/SearchSkinProfile.php';

final class LiveDiscovery
{
    private const TOPICS = [
        'da_dau' => ['Da dầu', 'da dau|hon hop thien dau|kiem dau|dau thua'],
        'da_kho' => ['Da khô', 'da kho|hon hop thien kho|thieu am|bong troc'],
        'da_nhay_cam' => ['Da nhạy cảm', 'da nhay cam|kich ung'],
        'da_mun' => ['Da mụn', 'da mun|mun viem|mun an|giam mun'],
        'hon_hop' => ['Da hỗn hợp', 'da hon hop|hon hop thien dau|hon hop thien kho'],
        'thuong' => ['Da thường', 'da thuong'],
    ];

    public static function skinKey(?array $profile): ?string
    {
        $key = SearchSkinProfile::skinTypeFromField($profile['tinh_trang_dac_biet'] ?? null);
        if (isset(self::TOPICS[$key])) return $key;

        $name = SearchSkinProfile::fold((string)($profile['loai_da'] ?? $profile['skin_type'] ?? ''));
        foreach (self::TOPICS as $key => [, $terms]) {
            if (preg_match('/\b(?:' . $terms . ')\b/', $name)) return $key;
        }
        return null;
    }

    public static function rank(array $sessions, ?string $skinKey): array
    {
        $priority = ['live' => 0, 'replay_ready' => 1, 'ready' => 2, 'interrupted' => 2, 'scheduled' => 3];
        $topic = self::TOPICS[$skinKey] ?? null;
        foreach ($sessions as &$session) {
            $text = SearchSkinProfile::fold(($session['title'] ?? '') . ' ' . ($session['tom_tat_phien_live'] ?? ''));
            $session['discovery_topic'] = $topic && preg_match('/\b(?:' . $topic[1] . ')\b/', $text) ? $topic[0] : '';
        }
        unset($session);
        usort($sessions, static function (array $a, array $b) use ($priority): int {
            $status = ($priority[$a['status']] ?? 4) <=> ($priority[$b['status']] ?? 4);
            if ($status !== 0) return $status;
            $topic = (int)($b['discovery_topic'] !== '') <=> (int)($a['discovery_topic'] !== '');
            return $topic ?: ($b['discovery_time'] ?? 0) <=> ($a['discovery_time'] ?? 0);
        });
        return $sessions;
    }

    public static function timestamp($value): int
    {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) return $value->toDateTime()->getTimestamp();
        if (is_int($value)) return max(0, $value);
        return is_string($value) && $value !== '' ? (strtotime($value) ?: 0) : 0;
    }
}

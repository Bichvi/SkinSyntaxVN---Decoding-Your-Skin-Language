<?php

/**
 * Session-only state for a skincare consultation.
 *
 * This deliberately does not write to khach_hang/skin_profiles. A quick check-in
 * is temporary until the customer explicitly confirms a permanent update.
 */
final class SkinConsultationSession
{
    private const SESSION_KEY = 'skinsyntax_consultation_v1';
    private const TTL_SECONDS = 86400;

    private static function ownerKey(array $user): string
    {
        $email = mb_strtolower(trim((string)($user['email'] ?? '')), 'UTF-8');
        return $email === '' ? '' : hash('sha256', $email);
    }

    private static function normalizeList($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }
        $items = array_map(static fn($item): string => trim((string)$item), $value);
        return array_values(array_unique(array_filter($items, static fn(string $item): bool => $item !== '')));
    }

    public static function start(array $user, string $profileId, array $checkIn, string $mode = 'quick'): ?array
    {
        $ownerKey = self::ownerKey($user);
        if ($ownerKey === '' || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $sessionId = 'cs_' . bin2hex(random_bytes(12));
        $now = time();
        $record = [
            'session_id' => $sessionId,
            'owner_key' => $ownerKey,
            'profile_id' => trim($profileId),
            'mode' => in_array($mode, ['full', 'quick', 'other'], true) ? $mode : 'quick',
            'skin_checkin' => [
                'muc_do_nhay_cam' => trim((string)($checkIn['muc_do_nhay_cam'] ?? '')),
                'van_de_da' => self::normalizeList($checkIn['van_de_da'] ?? []),
                'muc_tieu_cham_soc' => trim((string)($checkIn['muc_tieu_cham_soc'] ?? '')),
                'ngan_sach' => max(0, (int)($checkIn['ngan_sach'] ?? 0)),
            ],
            'product_preferences' => [
                'owned_product_ids' => [],
                'skipped_product_ids' => [],
                'liked_product_ids' => [],
            ],
            'generated_routine' => [],
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
        ];
        $_SESSION[self::SESSION_KEY] = $record;
        return $record;
    }

    public static function get(array $user): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $record = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($record) || !hash_equals((string)($record['owner_key'] ?? ''), self::ownerKey($user))) {
            return null;
        }
        if ((int)($record['expires_at'] ?? 0) < time()) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        return $record;
    }

    public static function updatePreference(array $user, string $productId, string $preference): ?array
    {
        $record = self::get($user);
        $productId = trim($productId);
        if (!$record || $productId === '' || !in_array($preference, ['owned', 'skipped', 'liked'], true)) {
            return null;
        }

        $prefs = is_array($record['product_preferences'] ?? null) ? $record['product_preferences'] : [];
        foreach (['owned_product_ids', 'skipped_product_ids', 'liked_product_ids'] as $key) {
            $prefs[$key] = self::normalizeList($prefs[$key] ?? []);
        }
        $map = ['owned' => 'owned_product_ids', 'skipped' => 'skipped_product_ids', 'liked' => 'liked_product_ids'];
        foreach ($map as $key => $listKey) {
            $prefs[$listKey] = array_values(array_diff($prefs[$listKey], [$productId]));
        }
        $prefs[$map[$preference]][] = $productId;
        $prefs[$map[$preference]] = array_values(array_unique($prefs[$map[$preference]]));
        $record['product_preferences'] = $prefs;
        $record['updated_at'] = time();
        $_SESSION[self::SESSION_KEY] = $record;
        return $record;
    }

    public static function setGeneratedRoutine(array $user, array $routine): ?array
    {
        $record = self::get($user);
        if (!$record) {
            return null;
        }
        $record['generated_routine'] = $routine;
        $record['updated_at'] = time();
        $_SESSION[self::SESSION_KEY] = $record;
        return $record;
    }

    public static function clear(array $user): void
    {
        if (self::get($user) !== null) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }
}

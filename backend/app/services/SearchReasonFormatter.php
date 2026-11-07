<?php
// Hàm thuần: reason_code + reason_params (từ Flask, không tin cậy) -> câu lý do tiếng Việt.
// Không escape HTML ở đây; view phải escape.
final class SearchReasonFormatter
{
    public const MAX_PARAMS = 3;
    public const MAX_PARAM_LEN = 40;

    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config === null) {
            $cfg = require __DIR__ . '/../config/search_reasons.php';
            self::$config = is_array($cfg) ? $cfg : ['sections' => [], 'reasons' => [], 'labels' => []];
        }
        return self::$config;
    }

    public static function sectionTitle(string $key): string
    {
        return (string)(self::config()['sections'][$key] ?? '');
    }

    public static function format(string $code, array $params): string
    {
        $cfg = self::config();
        $template = $cfg['reasons'][$code] ?? null;
        if (!is_string($template)) {
            return '';
        }
        $labels = $cfg['labels'] ?? [];
        $clean = [];
        foreach ($params as $p) {
            if (count($clean) >= self::MAX_PARAMS) {
                break;
            }
            if (!is_string($p) && !is_int($p)) {
                continue;
            }
            $p = trim((string)$p);
            if ($p === '') {
                continue;
            }
            $p = mb_substr($p, 0, self::MAX_PARAM_LEN, 'UTF-8');
            $clean[] = (string)($labels[$p] ?? $p);
        }
        if (strpos($template, '%s') === false) {
            return $template;
        }
        if (!$clean) {
            return trim(str_replace('%s', '', $template));
        }
        return str_replace('%s', implode(', ', $clean), $template);
    }
}

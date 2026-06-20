<?php
// backend/app/config/config.php
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!function_exists('ss_load_env')) {
	if (!function_exists('ss_starts_with')) {
		function ss_starts_with(string $haystack, string $needle): bool {
			if ($needle === '') {
				return true;
			}
			return strncmp($haystack, $needle, strlen($needle)) === 0;
		}
	}

	if (!function_exists('ss_ends_with')) {
		function ss_ends_with(string $haystack, string $needle): bool {
			if ($needle === '') {
				return true;
			}
			$needleLen = strlen($needle);
			if ($needleLen > strlen($haystack)) {
				return false;
			}
			return substr($haystack, -$needleLen) === $needle;
		}
	}

	function ss_load_env(string $envPath): void {
		if (!is_file($envPath) || !is_readable($envPath)) {
			return;
		}

		$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			return;
		}

		foreach ($lines as $line) {
			$trimmed = trim($line);
			if ($trimmed === '' || ss_starts_with($trimmed, '#')) {
				continue;
			}

			$parts = explode('=', $trimmed, 2);
			if (count($parts) !== 2) {
				continue;
			}

			$key = trim($parts[0]);
			$value = trim($parts[1]);
			if ($key === '') {
				continue;
			}

			if ((ss_starts_with($value, '"') && ss_ends_with($value, '"')) || (ss_starts_with($value, "'") && ss_ends_with($value, "'"))) {
				$value = substr($value, 1, -1);
			}

			if (getenv($key) === false) {
				putenv($key . '=' . $value);
				$_ENV[$key] = $value;
				$_SERVER[$key] = $value;
			}
		}
	}
}

$projectEnvPath = dirname(__DIR__, 3) . '/.env';
ss_load_env($projectEnvPath);

if (!function_exists('ss_env')) {
	function ss_env(string $key, string $default = ''): string {
		$value = getenv($key);
		if ($value === false || $value === null) {
			return $default;
		}
		return (string)$value;
	}
}

if (!function_exists('ss_env_int')) {
	function ss_env_int(string $key, int $default = 0): int {
		$value = ss_env($key, (string)$default);
		return ctype_digit($value) ? (int)$value : $default;
	}
}

if (!function_exists('ss_env_bool')) {
	function ss_env_bool(string $key, bool $default = false): bool {
		$value = strtolower(trim(ss_env($key, $default ? 'true' : 'false')));
		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}
}

$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base = rtrim($base, '/');
if (!defined('BASE_URL')) {
	define('BASE_URL', $base);
}

defined('APP_URL') || define('APP_URL', ss_env('APP_URL', 'http://localhost'));
defined('SOCIAL_LOCAL_FALLBACK') || define('SOCIAL_LOCAL_FALLBACK', ss_env('SOCIAL_LOCAL_FALLBACK', '0'));

defined('BACKEND_ROOT') || define('BACKEND_ROOT', ss_env('BACKEND_ROOT', dirname(__DIR__, 2)) ?: dirname(__DIR__, 2));
defined('FRONTEND_ROOT') || define('FRONTEND_ROOT', ss_env('FRONTEND_ROOT', dirname(BACKEND_ROOT) . '/frontend'));

defined('MONGO_URI') || define('MONGO_URI', ss_env('MONGO_URI', 'mongodb://127.0.0.1:27017'));
defined('MONGO_DB') || define('MONGO_DB', ss_env('MONGO_DB', 'skinsyntax'));
defined('CSV_FILE_PATH') || define('CSV_FILE_PATH', ss_env('CSV_FILE_PATH', dirname(BACKEND_ROOT) . '/database/data_clean_final.csv'));

$defaultOAuthRedirect = rtrim(APP_URL, '/') . '/index.php?r=auth_social_callback';

defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', ss_env('MAIL_FROM_ADDRESS', 'no-reply@skinsyntax.local'));
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', ss_env('MAIL_FROM_NAME', 'SkinSyntax'));
defined('MAIL_DEMO_MODE') || define('MAIL_DEMO_MODE', ss_env('MAIL_DEMO_MODE', '1'));
defined('SMTP_HOST') || define('SMTP_HOST', ss_env('SMTP_HOST', ''));
defined('SMTP_PORT') || define('SMTP_PORT', (int)ss_env('SMTP_PORT', '587'));
defined('SMTP_USER') || define('SMTP_USER', ss_env('SMTP_USER', ''));
defined('SMTP_PASS') || define('SMTP_PASS', ss_env('SMTP_PASS', ''));
defined('SMTP_ENCRYPTION') || define('SMTP_ENCRYPTION', ss_env('SMTP_ENCRYPTION', 'tls'));

defined('GOOGLE_OAUTH_CLIENT_ID') || define('GOOGLE_OAUTH_CLIENT_ID', ss_env('GOOGLE_OAUTH_CLIENT_ID', ss_env('GOOGLE_CLIENT_ID', '')));
defined('GOOGLE_OAUTH_CLIENT_SECRET') || define('GOOGLE_OAUTH_CLIENT_SECRET', ss_env('GOOGLE_OAUTH_CLIENT_SECRET', ss_env('GOOGLE_CLIENT_SECRET', '')));
defined('GOOGLE_OAUTH_REDIRECT_URI') || define('GOOGLE_OAUTH_REDIRECT_URI', ss_env('GOOGLE_OAUTH_REDIRECT_URI', $defaultOAuthRedirect));
defined('FACEBOOK_OAUTH_CLIENT_ID') || define('FACEBOOK_OAUTH_CLIENT_ID', ss_env('FACEBOOK_OAUTH_CLIENT_ID', ss_env('FACEBOOK_APP_ID', '')));
defined('FACEBOOK_OAUTH_CLIENT_SECRET') || define('FACEBOOK_OAUTH_CLIENT_SECRET', ss_env('FACEBOOK_OAUTH_CLIENT_SECRET', ss_env('FACEBOOK_APP_SECRET', '')));
defined('FACEBOOK_OAUTH_REDIRECT_URI') || define('FACEBOOK_OAUTH_REDIRECT_URI', ss_env('FACEBOOK_OAUTH_REDIRECT_URI', $defaultOAuthRedirect));

defined('BANK_TRANSFER_BANK_ID') || define('BANK_TRANSFER_BANK_ID', ss_env('BANK_TRANSFER_BANK_ID', '970422'));
defined('BANK_TRANSFER_BANK_NAME') || define('BANK_TRANSFER_BANK_NAME', ss_env('BANK_TRANSFER_BANK_NAME', 'MB Bank'));
defined('BANK_TRANSFER_ACCOUNT_NO') || define('BANK_TRANSFER_ACCOUNT_NO', ss_env('BANK_TRANSFER_ACCOUNT_NO', ''));
defined('BANK_TRANSFER_ACCOUNT_NAME') || define('BANK_TRANSFER_ACCOUNT_NAME', ss_env('BANK_TRANSFER_ACCOUNT_NAME', ''));
defined('BANK_TRANSFER_QR_TEMPLATE') || define('BANK_TRANSFER_QR_TEMPLATE', ss_env('BANK_TRANSFER_QR_TEMPLATE', 'compact2'));
defined('BANK_TRANSFER_WEBHOOK_SECRET') || define('BANK_TRANSFER_WEBHOOK_SECRET', ss_env('BANK_TRANSFER_WEBHOOK_SECRET', ''));

defined('SEPAY_API_TOKEN') || define('SEPAY_API_TOKEN', ss_env('SEPAY_API_TOKEN', ''));
defined('SEPAY_ACCOUNT_NUMBER') || define('SEPAY_ACCOUNT_NUMBER', ss_env('SEPAY_ACCOUNT_NUMBER', ''));
defined('SEPAY_POLLING_ENABLED') || define('SEPAY_POLLING_ENABLED', ss_env_bool('SEPAY_POLLING_ENABLED', false));

defined('AI_RECOMMENDATION_ENDPOINT') || define('AI_RECOMMENDATION_ENDPOINT', ss_env('AI_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5001/api/recommend/explain'));
defined('AI_RECOMMENDATION_TIMEOUT') || define('AI_RECOMMENDATION_TIMEOUT', ss_env_int('AI_RECOMMENDATION_TIMEOUT', 20));
defined('AI_HYBRID_RECOMMENDATION_ENDPOINT') || define('AI_HYBRID_RECOMMENDATION_ENDPOINT', ss_env('AI_HYBRID_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5001/api/recommend/langchain-rag'));
defined('AI_HYBRID_RECOMMENDATION_TIMEOUT') || define('AI_HYBRID_RECOMMENDATION_TIMEOUT', ss_env_int('AI_HYBRID_RECOMMENDATION_TIMEOUT', 30));
defined('AI_CHAT_ENDPOINT') || define('AI_CHAT_ENDPOINT', ss_env('AI_CHAT_ENDPOINT', 'http://127.0.0.1:5001/api/chat/auto'));
defined('AI_CHAT_STREAM_ENDPOINT') || define('AI_CHAT_STREAM_ENDPOINT', ss_env('AI_CHAT_STREAM_ENDPOINT', 'http://127.0.0.1:5001/api/chat/stream'));
defined('AI_CHAT_TIMEOUT') || define('AI_CHAT_TIMEOUT', ss_env_int('AI_CHAT_TIMEOUT', 60));
defined('REDIS_URL') || define('REDIS_URL', ss_env('REDIS_URL', ''));
defined('REDIS_CACHE_TTL') || define('REDIS_CACHE_TTL', ss_env_int('REDIS_CACHE_TTL', 604800));
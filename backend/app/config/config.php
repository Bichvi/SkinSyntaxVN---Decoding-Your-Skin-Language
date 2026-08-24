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

			putenv($key . '=' . $value);
			$_ENV[$key] = $value;
			$_SERVER[$key] = $value;
		}
	}
}

$projectEnvPath = dirname(__DIR__, 3) . '/.env';
ss_load_env($projectEnvPath);

if (!function_exists('ss_env')) {
	function ss_env(string $key, string $default = ''): string {
		$value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
		if ($value === false || $value === null || trim((string)$value) === '') {
			return $default;
		}
		return trim((string)$value);
	}
}


$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base = rtrim($base, '/');
if (!defined('BASE_URL')) {
	define('BASE_URL', $base);
}

defined('APP_URL') || define('APP_URL', ss_env('APP_URL', ''));
defined('SOCIAL_LOCAL_FALLBACK') || define('SOCIAL_LOCAL_FALLBACK', ss_env('SOCIAL_LOCAL_FALLBACK', '0'));

defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', ss_env('MAIL_FROM_ADDRESS', 'lamngoc562004@gmail.com'));
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', ss_env('MAIL_FROM_NAME', 'SkinSyntax'));

defined('GOOGLE_OAUTH_CLIENT_ID') || define('GOOGLE_OAUTH_CLIENT_ID', ss_env('GOOGLE_OAUTH_CLIENT_ID', ss_env('GOOGLE_CLIENT_ID', '')));
defined('GOOGLE_OAUTH_CLIENT_SECRET') || define('GOOGLE_OAUTH_CLIENT_SECRET', ss_env('GOOGLE_OAUTH_CLIENT_SECRET', ss_env('GOOGLE_CLIENT_SECRET', '')));
defined('GOOGLE_OAUTH_REDIRECT_URI') || define('GOOGLE_OAUTH_REDIRECT_URI', ss_env('GOOGLE_OAUTH_REDIRECT_URI', ''));
defined('FACEBOOK_OAUTH_CLIENT_ID') || define('FACEBOOK_OAUTH_CLIENT_ID', ss_env('FACEBOOK_OAUTH_CLIENT_ID', ss_env('FACEBOOK_APP_ID', '1298635468783222')));
defined('FACEBOOK_OAUTH_CLIENT_SECRET') || define('FACEBOOK_OAUTH_CLIENT_SECRET', ss_env('FACEBOOK_OAUTH_CLIENT_SECRET', ss_env('FACEBOOK_APP_SECRET', 'd2690f5c1dc061e3de5073bdd4d180f2')));
defined('FACEBOOK_OAUTH_REDIRECT_URI') || define('FACEBOOK_OAUTH_REDIRECT_URI', ss_env('FACEBOOK_OAUTH_REDIRECT_URI', ''));

defined('BANK_TRANSFER_BANK_ID') || define('BANK_TRANSFER_BANK_ID', '970422');
defined('BANK_TRANSFER_BANK_NAME') || define('BANK_TRANSFER_BANK_NAME', 'MB Bank');
defined('BANK_TRANSFER_ACCOUNT_NO') || define('BANK_TRANSFER_ACCOUNT_NO', '0824719703');
defined('BANK_TRANSFER_ACCOUNT_NAME') || define('BANK_TRANSFER_ACCOUNT_NAME', 'LAM NGUYEN MY NGOC');
defined('BANK_TRANSFER_QR_TEMPLATE') || define('BANK_TRANSFER_QR_TEMPLATE', 'compact2');
defined('BANK_TRANSFER_WEBHOOK_SECRET') || define('BANK_TRANSFER_WEBHOOK_SECRET', ss_env('BANK_TRANSFER_WEBHOOK_SECRET', 'skinsyntax-mb-auto-pay-2026'));


defined('SEPAY_API_TOKEN') || define('SEPAY_API_TOKEN', ss_env('SEPAY_API_TOKEN', 'J61YPTHCHGPKEBZGDMDT2CUKFNSJWRTOTWBMCW7IERNYXRO68GXXBFZB59AZGEIL'));
defined('SEPAY_ACCOUNT_NUMBER') || define('SEPAY_ACCOUNT_NUMBER', '0824719703');
defined('SEPAY_POLLING_ENABLED') || define('SEPAY_POLLING_ENABLED', true);

defined('VNPAY_TMN_CODE') || define('VNPAY_TMN_CODE', ss_env('VNPAY_TMN_CODE', ''));
defined('VNPAY_HASH_SECRET') || define('VNPAY_HASH_SECRET', ss_env('VNPAY_HASH_SECRET', ''));
defined('VNPAY_URL') || define('VNPAY_URL', ss_env('VNPAY_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'));
defined('VNPAY_RETURN_URL') || define('VNPAY_RETURN_URL', ss_env('VNPAY_RETURN_URL', ''));


defined('AI_RECOMMENDATION_ENDPOINT') || define('AI_RECOMMENDATION_ENDPOINT', ss_env('AI_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5001/api/recommend/explain'));
defined('AI_RECOMMENDATION_TIMEOUT') || define('AI_RECOMMENDATION_TIMEOUT', 20);
defined('AI_HYBRID_RECOMMENDATION_ENDPOINT') || define('AI_HYBRID_RECOMMENDATION_ENDPOINT', ss_env('AI_HYBRID_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5001/api/recommend/langchain-rag'));
defined('AI_HYBRID_RECOMMENDATION_TIMEOUT') || define('AI_HYBRID_RECOMMENDATION_TIMEOUT', 30);
defined('AI_LLAMA_INDEX_RECOMMENDATION_ENDPOINT') || define('AI_LLAMA_INDEX_RECOMMENDATION_ENDPOINT', ss_env('AI_LLAMA_INDEX_RECOMMENDATION_ENDPOINT', 'http://127.0.0.1:5001/api/recommend/profile'));
defined('AI_LLAMA_INDEX_RECOMMENDATION_TIMEOUT') || define('AI_LLAMA_INDEX_RECOMMENDATION_TIMEOUT', 35);
defined('AI_PROFILE_RECOMMENDATION_ENDPOINT_BASE') || define('AI_PROFILE_RECOMMENDATION_ENDPOINT_BASE', ss_env('AI_PROFILE_RECOMMENDATION_ENDPOINT_BASE', 'http://127.0.0.1:5001/api/recommend/profile'));
defined('AI_PROFILE_RECOMMENDATION_TIMEOUT') || define('AI_PROFILE_RECOMMENDATION_TIMEOUT', 20);
defined('AI_CHAT_ENDPOINT') || define('AI_CHAT_ENDPOINT', ss_env('AI_CHAT_ENDPOINT', 'http://127.0.0.1:5001/api/chat/auto'));
defined('AI_CHAT_TIMEOUT') || define('AI_CHAT_TIMEOUT', 60);

defined('LIVEKIT_URL') || define('LIVEKIT_URL', ss_env('LIVEKIT_URL', 'wss://skinsyntax-live.livekit.cloud'));
defined('LIVEKIT_API_KEY') || define('LIVEKIT_API_KEY', ss_env('LIVEKIT_API_KEY', 'devkey'));
defined('LIVEKIT_API_SECRET') || define('LIVEKIT_API_SECRET', ss_env('LIVEKIT_API_SECRET', 'secret'));


if (!defined('VIEW_DIR')) {
	$frontendRootEnv = ss_env('FRONTEND_ROOT', '');
	if ($frontendRootEnv !== '' && is_dir(rtrim($frontendRootEnv, '/\\') . '/views')) {
		define('VIEW_DIR', rtrim($frontendRootEnv, '/\\') . '/views');
	} else {
		$localFrontendViews = dirname(__DIR__, 3) . '/frontend/views';
		if (is_dir($localFrontendViews)) {
			define('VIEW_DIR', $localFrontendViews);
		} else {
			define('VIEW_DIR', dirname(__DIR__, 2) . '/app/views');
		}
	}
}

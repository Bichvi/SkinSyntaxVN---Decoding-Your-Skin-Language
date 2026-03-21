<?php
// backend/app/config/config.php
date_default_timezone_set('Asia/Ho_Chi_Minh');

$base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$base = rtrim($base, '/');
if (!defined('BASE_URL')) {
	define('BASE_URL', $base);
}

defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', 'lamngoc562004@gmail.com');
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', 'SkinSyntax');

defined('GOOGLE_OAUTH_CLIENT_ID') || define('GOOGLE_OAUTH_CLIENT_ID', getenv('GOOGLE_OAUTH_CLIENT_ID') ?: '');
defined('GOOGLE_OAUTH_CLIENT_SECRET') || define('GOOGLE_OAUTH_CLIENT_SECRET', getenv('GOOGLE_OAUTH_CLIENT_SECRET') ?: '');
defined('FACEBOOK_OAUTH_CLIENT_ID') || define('FACEBOOK_OAUTH_CLIENT_ID', '');
defined('FACEBOOK_OAUTH_CLIENT_SECRET') || define('FACEBOOK_OAUTH_CLIENT_SECRET', '');

defined('BANK_TRANSFER_BANK_ID') || define('BANK_TRANSFER_BANK_ID', '970422');
defined('BANK_TRANSFER_BANK_NAME') || define('BANK_TRANSFER_BANK_NAME', 'MB Bank');
defined('BANK_TRANSFER_ACCOUNT_NO') || define('BANK_TRANSFER_ACCOUNT_NO', '0824719703');
defined('BANK_TRANSFER_ACCOUNT_NAME') || define('BANK_TRANSFER_ACCOUNT_NAME', 'LAM NGUYEN MY NGOC');
defined('BANK_TRANSFER_QR_TEMPLATE') || define('BANK_TRANSFER_QR_TEMPLATE', 'compact2');
defined('BANK_TRANSFER_WEBHOOK_SECRET') || define('BANK_TRANSFER_WEBHOOK_SECRET', getenv('BANK_TRANSFER_WEBHOOK_SECRET') ?: '');


defined('SEPAY_API_TOKEN') || define('SEPAY_API_TOKEN', getenv('SEPAY_API_TOKEN') ?: '');
defined('SEPAY_ACCOUNT_NUMBER') || define('SEPAY_ACCOUNT_NUMBER', '0824719703');
defined('SEPAY_POLLING_ENABLED') || define('SEPAY_POLLING_ENABLED', true);

defined('AI_RECOMMENDATION_ENDPOINT') || define('AI_RECOMMENDATION_ENDPOINT', getenv('AI_RECOMMENDATION_ENDPOINT') ?: 'http://127.0.0.1:5000/api/recommend/explain');
defined('AI_RECOMMENDATION_TIMEOUT') || define('AI_RECOMMENDATION_TIMEOUT', 20);
defined('AI_CHAT_ENDPOINT') || define('AI_CHAT_ENDPOINT', getenv('AI_CHAT_ENDPOINT') ?: 'http://127.0.0.1:5000/api/chat');
defined('AI_CHAT_TIMEOUT') || define('AI_CHAT_TIMEOUT', 25);
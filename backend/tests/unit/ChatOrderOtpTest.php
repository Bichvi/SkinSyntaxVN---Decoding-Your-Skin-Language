<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatOrderOtp.php';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
$errorOf = static function (callable $fn): ?ChatOrderOtpException {
    try { $fn(); } catch (ChatOrderOtpException $e) { return $e; }
    return null;
};

$session = [];
$otp = new ChatOrderOtp($session, $clock);
$code = $otp->issue('sub-1', 'draft-1');
$entry = $session['chat_order_otp']['sub-1'] ?? [];
$check('code_is_six_digits', preg_match('/^\d{6}$/', $code) === 1);
$check('only_hash_is_stored', ($entry['hash'] ?? '') === hash('sha256', $code) && !in_array($code, array_map('strval', $entry), true));
$check('entry_shape', $entry['draft_id'] === 'draft-1' && $entry['expires_at'] === $now + 300 && $entry['attempts'] === 0 && $entry['sent_at'] === $now);

$check('verify_correct_ok', $errorOf(fn() => $otp->verify('sub-1', 'draft-1', $code)) === null);

$e = $errorOf(fn() => $otp->verify('sub-1', 'draft-1', ''));
$check('empty_code_428', $e?->getCode() === 428 && $e->getErrorCode() === 'otp_required');
$e = $errorOf(fn() => $otp->verify('sub-2', 'draft-1', $code));
$check('missing_entry_428', $e?->getCode() === 428 && $e->getErrorCode() === 'otp_required');
$e = $errorOf(fn() => $otp->verify('sub-1', 'draft-other', $code));
$check('different_draft_428', $e?->getCode() === 428 && $e->getErrorCode() === 'otp_required');

$wrong = $code === '000000' ? '111111' : '000000';
$e = $errorOf(fn() => $otp->verify('sub-1', 'draft-1', $wrong));
$check('wrong_code_422_with_remaining', $e?->getCode() === 422 && $e->getErrorCode() === 'otp_invalid'
    && str_contains($e->getMessage(), '4') && $session['chat_order_otp']['sub-1']['attempts'] === 1);

for ($i = 0; $i < 3; $i++) $errorOf(fn() => $otp->verify('sub-1', 'draft-1', $wrong));
$e = $errorOf(fn() => $otp->verify('sub-1', 'draft-1', $wrong));
$check('fifth_wrong_locks_429', $e?->getCode() === 429 && $e->getErrorCode() === 'otp_locked');
$e = $errorOf(fn() => $otp->verify('sub-1', 'draft-1', $code));
$check('locked_even_with_right_code', $e?->getCode() === 429 && $e->getErrorCode() === 'otp_locked');

// cooldown
$e = $errorOf(fn() => $otp->issue('sub-1', 'draft-1'));
$check('reissue_in_cooldown_429', $e?->getCode() === 429 && $e->getErrorCode() === 'otp_cooldown' && str_contains($e->getMessage(), '60'));
$now += 59;
$check('still_cooldown_at_59s', $errorOf(fn() => $otp->issue('sub-1', 'draft-1')) !== null);
$now += 1;
$code2 = $otp->issue('sub-1', 'draft-1');
$check('reissue_after_cooldown_resets_attempts', $session['chat_order_otp']['sub-1']['attempts'] === 0
    && $errorOf(fn() => $otp->verify('sub-1', 'draft-1', $code2)) === null);

// expiry
$now += 301;
$e = $errorOf(fn() => $otp->verify('sub-1', 'draft-1', $code2));
$check('expired_410', $e?->getCode() === 410 && $e->getErrorCode() === 'otp_expired');

// consume
$code3 = $otp->issue('sub-1', 'draft-1');
$otp->consume('sub-1');
$e = $errorOf(fn() => $otp->verify('sub-1', 'draft-1', $code3));
$check('consumed_code_requires_new_428', $e?->getCode() === 428 && !$otp->has('sub-1'));

// rebind
$now += 100;
$code4 = $otp->issue('sub-1', 'draft-A');
$otp->rebind('sub-1', 'draft-B');
$check('rebind_moves_code_to_new_draft', $errorOf(fn() => $otp->verify('sub-1', 'draft-B', $code4)) === null
    && $errorOf(fn() => $otp->verify('sub-1', 'draft-A', $code4))?->getErrorCode() === 'otp_required');
$otp->rebind('nobody', 'x');
$check('rebind_without_entry_is_noop', !$otp->has('nobody'));

// isolation between users
$codeU = $otp->issue('sub-9', 'draft-9');
$check('codes_are_per_user', $errorOf(fn() => $otp->verify('sub-1', 'draft-9', $codeU))?->getErrorCode() === 'otp_required');

// a new draft inside the cooldown gets its own code (cooldown is per draft)
$codeOld = $otp->issue('sub-5', 'draft-old');
$codeNew = null;
$check('new_draft_skips_cooldown', $errorOf(function () use ($otp, &$codeNew) { $codeNew = $otp->issue('sub-5', 'draft-new'); }) === null);
$check('new_draft_code_replaces_old', $errorOf(fn() => $otp->verify('sub-5', 'draft-new', (string)$codeNew)) === null
    && $errorOf(fn() => $otp->verify('sub-5', 'draft-old', $codeOld))?->getErrorCode() === 'otp_required');

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatCartEffect.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$secret = 'cart-effect-secret';
$effect = ChatCartEffect::sign([
    'op' => 'add',
    'product_id' => 'p1',
    'qty' => 2,
    'sub' => 'user-7',
    'exp' => 1_800_000_120,
    'nonce' => 'abcdef0123456789',
], $secret);

$check('verify_ok', ChatCartEffect::verify($effect, $secret, 'user-7', 1_800_000_000));

$tampered = $effect;
$tampered['qty'] = 3;
$check('tampered_qty_rejected', !ChatCartEffect::verify($tampered, $secret, 'user-7', 1_800_000_000));

$check('other_user_rejected', !ChatCartEffect::verify($effect, $secret, 'other-user', 1_800_000_000));
$check('expired_rejected', !ChatCartEffect::verify($effect, $secret, 'user-7', 1_800_000_120));
$check('empty_subject_rejected', !ChatCartEffect::verify($effect, $secret, '', 1_800_000_000));

$session = ['gio_hang' => ['p1' => 19]];
$check('apply_returns_true', ChatCartEffect::apply($effect, $session));
$check('apply_add_caps_at_20', $session['gio_hang']['p1'] === 20);
$check('replay_skipped', !ChatCartEffect::apply($effect, $session) && $session['gio_hang']['p1'] === 20);
$session['chat_cart_nonces']['old-nonce'] = 1;
$check('expired_nonces_pruned', ChatCartEffect::apply($effect, $session) === false
    && !isset($session['chat_cart_nonces']['old-nonce']));

$remove = ChatCartEffect::sign([
    'op' => 'remove',
    'product_id' => 'p1',
    'qty' => 1,
    'sub' => 'user-7',
    'exp' => 1_800_000_120,
    'nonce' => 'fedcba9876543210',
], $secret);
$check('apply_remove_returns_true', ChatCartEffect::apply($remove, $session));
$check('apply_remove', !array_key_exists('p1', $session['gio_hang']));

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);

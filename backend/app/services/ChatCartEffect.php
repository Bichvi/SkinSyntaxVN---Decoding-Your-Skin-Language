<?php
declare(strict_types=1);

final class ChatCartEffect
{
    private const PAYLOAD_KEYS = ['op', 'product_id', 'qty', 'sub', 'exp', 'nonce'];
    private const EFFECT_KEYS = ['op', 'product_id', 'qty', 'sub', 'exp', 'nonce', 'sig'];

    public static function sign(array $effect, string $secret): array
    {
        $payload = self::payload($effect);
        $payload['sig'] = self::base64UrlEncode(hash_hmac(
            'sha256',
            self::canonicalJson($payload),
            $secret,
            true
        ));
        return $payload;
    }

    /**
     * Verify an effect using the exact key order op, product_id, qty, sub, exp, nonce, sig.
     * Relays must pass the decoded array through unchanged; do not re-serialise it with sorted keys.
     */
    public static function verify(array $effect, string $secret, string $sub, ?int $now = null): bool
    {
        if ($secret === '' || $sub === '' || array_keys($effect) !== self::EFFECT_KEYS) {
            return false;
        }

        try {
            $payload = self::payload($effect);
        } catch (Throwable $e) {
            return false;
        }

        if (!is_string($effect['sig']) || !hash_equals(
            self::base64UrlEncode(hash_hmac('sha256', self::canonicalJson($payload), $secret, true)),
            $effect['sig']
        )) {
            return false;
        }

        return hash_equals((string)$payload['sub'], $sub)
            && ($now ?? time()) < $payload['exp'];
    }

    public static function apply(array $effect, array &$session): bool
    {
        $now = time();
        $nonces = isset($session['chat_cart_nonces']) && is_array($session['chat_cart_nonces'])
            ? $session['chat_cart_nonces']
            : [];
        foreach ($nonces as $nonce => $exp) {
            if (!is_int($exp) || $exp < $now) {
                unset($nonces[$nonce]);
            }
        }
        $nonce = (string)$effect['nonce'];
        if (array_key_exists($nonce, $nonces)) {
            $session['chat_cart_nonces'] = $nonces;
            return false;
        }

        $cart = isset($session['gio_hang']) && is_array($session['gio_hang'])
            ? $session['gio_hang']
            : [];
        $id = $effect['product_id'];

        if ($effect['op'] === 'remove') {
            unset($cart[$id]);
        } elseif ($effect['op'] === 'add') {
            $cart[$id] = min(20, (int)($cart[$id] ?? 0) + $effect['qty']);
        }

        $session['gio_hang'] = $cart;
        $nonces[$nonce] = $effect['exp'];
        while (count($nonces) > 200) {
            unset($nonces[array_key_first($nonces)]);
        }
        $session['chat_cart_nonces'] = $nonces;
        return true;
    }

    private static function payload(array $effect): array
    {
        if (array_keys($effect) !== self::PAYLOAD_KEYS && array_keys($effect) !== self::EFFECT_KEYS) {
            throw new InvalidArgumentException('Invalid cart effect keys');
        }

        $payload = [];
        foreach (self::PAYLOAD_KEYS as $key) {
            if (!array_key_exists($key, $effect)) {
                throw new InvalidArgumentException('Invalid cart effect payload');
            }
            $payload[$key] = $effect[$key];
        }

        if (!in_array($payload['op'], ['add', 'remove'], true)
            || !is_string($payload['product_id'])
            || $payload['product_id'] === ''
            || strlen($payload['product_id']) > 64
            || !is_int($payload['qty'])
            || $payload['qty'] < 1
            || $payload['qty'] > 20
            || !is_string($payload['sub'])
            || !is_int($payload['exp'])
            || !is_string($payload['nonce'])
            || $payload['nonce'] === '') {
            throw new InvalidArgumentException('Invalid cart effect payload');
        }

        return $payload;
    }

    private static function canonicalJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

<?php
declare(strict_types=1);

final class ChatAgentToken
{
    public static function issue(array $claims, string $secret, int $ttl = 300, ?int $now = null): string
    {
        if ($secret === '') {
            throw new DomainException('Invalid token secret', 401);
        }

        $now ??= time();
        if (!array_key_exists('exp', $claims)) {
            $claims['exp'] = $now + $ttl;
        }
        if (!array_key_exists('jti', $claims)) {
            $claims['jti'] = bin2hex(random_bytes(16));
        }

        try {
            $json = json_encode(
                $claims,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new DomainException('Invalid token claims', 401, $e);
        }

        $payload = self::base64UrlEncode($json);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payload, $secret, true));
        return $payload . '.' . $signature;
    }

    public static function verify(string $token, string $secret, ?int $now = null): array
    {
        if ($secret === '') {
            throw new DomainException('Invalid token', 401);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new DomainException('Invalid token', 401);
        }

        [$payload, $encodedSignature] = $parts;
        try {
            $signature = self::base64UrlDecode($encodedSignature);
            $claimsJson = self::base64UrlDecode($payload);
            $claims = json_decode($claimsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new DomainException('Invalid token', 401, $e);
        }

        if (!is_array($claims) || !hash_equals(hash_hmac('sha256', $payload, $secret, true), $signature)) {
            throw new DomainException('Invalid token', 401);
        }
        if (!array_key_exists('exp', $claims) || !is_int($claims['exp']) || ($now ?? time()) >= $claims['exp']) {
            throw new DomainException('Invalid token', 401);
        }

        return $claims;
    }

    public static function hasScope(array $claims, string $scope): bool
    {
        return isset($claims['scope'])
            && is_array($claims['scope'])
            && in_array($scope, $claims['scope'], true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1 || strlen($value) % 4 === 1) {
            throw new DomainException('Invalid token', 401);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new DomainException('Invalid token', 401);
        }

        return $decoded;
    }
}

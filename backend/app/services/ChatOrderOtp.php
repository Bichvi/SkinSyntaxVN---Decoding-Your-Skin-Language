<?php
declare(strict_types=1);

final class ChatOrderOtpException extends DomainException
{
    public function __construct(string $message, int $httpCode, private readonly string $errorCode)
    {
        parent::__construct($message, $httpCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

/**
 * Email OTP for chat order confirmation. State lives in the injected session array
 * ($session['chat_order_otp'][$sub]); only the SHA-256 of the code is stored.
 */
final class ChatOrderOtp
{
    public const TTL = 300;
    public const COOLDOWN = 60;
    public const MAX_ATTEMPTS = 5;
    private const KEY = 'chat_order_otp';

    /** @var callable(): int */
    private $clock;

    public function __construct(private array &$session, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Issues a fresh 6-digit code for the draft and returns it (plain, only for the caller to send). */
    public function issue(string $sub, string $draftId): string
    {
        $now = (int)($this->clock)();
        $existing = $this->session[self::KEY][$sub] ?? null;
        // Cooldown only for the same draft: a new draft (e.g. the customer changed the address) needs its own code.
        // The controller's rate limit (3 sends / 10 min) still caps sends across drafts.
        if (is_array($existing) && (string)($existing['draft_id'] ?? '') === $draftId) {
            $wait = (int)($existing['sent_at'] ?? 0) + self::COOLDOWN - $now;
            if ($wait > 0) {
                throw new ChatOrderOtpException('Vui lòng đợi ' . $wait . ' giây trước khi gửi lại mã.', 429, 'otp_cooldown');
            }
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->session[self::KEY][$sub] = [
            'draft_id' => $draftId,
            'hash' => hash('sha256', $code),
            'expires_at' => $now + self::TTL,
            'attempts' => 0,
            'sent_at' => $now,
        ];
        return $code;
    }

    public function verify(string $sub, string $draftId, string $code): void
    {
        $now = (int)($this->clock)();
        $entry = $this->session[self::KEY][$sub] ?? null;
        $code = trim($code);
        if (!is_array($entry) || $code === '' || (string)($entry['draft_id'] ?? '') !== $draftId) {
            throw new ChatOrderOtpException('Vui lòng nhập mã xác nhận được gửi qua email.', 428, 'otp_required');
        }
        if ((int)($entry['expires_at'] ?? 0) <= $now) {
            throw new ChatOrderOtpException('Mã xác nhận đã hết hạn. Vui lòng gửi lại mã.', 410, 'otp_expired');
        }
        if ((int)($entry['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            throw new ChatOrderOtpException('Bạn đã nhập sai quá nhiều lần. Vui lòng gửi lại mã mới.', 429, 'otp_locked');
        }
        if (!hash_equals((string)($entry['hash'] ?? ''), hash('sha256', $code))) {
            $attempts = (int)($entry['attempts'] ?? 0) + 1;
            $this->session[self::KEY][$sub]['attempts'] = $attempts;
            $left = self::MAX_ATTEMPTS - $attempts;
            if ($left <= 0) {
                throw new ChatOrderOtpException('Bạn đã nhập sai quá nhiều lần. Vui lòng gửi lại mã mới.', 429, 'otp_locked');
            }
            throw new ChatOrderOtpException('Mã xác nhận không đúng. Bạn còn ' . $left . ' lần thử.', 422, 'otp_invalid');
        }
    }

    public function consume(string $sub): void
    {
        unset($this->session[self::KEY][$sub]);
    }

    /** Moves an existing code to a replacement draft (price changed) without extending its life. */
    public function rebind(string $sub, string $newDraftId): void
    {
        if (isset($this->session[self::KEY][$sub]) && is_array($this->session[self::KEY][$sub])) {
            $this->session[self::KEY][$sub]['draft_id'] = $newDraftId;
        }
    }

    public function has(string $sub): bool
    {
        return isset($this->session[self::KEY][$sub]);
    }

    public function remainingCooldown(string $sub): int
    {
        $entry = $this->session[self::KEY][$sub] ?? null;
        if (!is_array($entry)) return 0;
        return max(0, (int)($entry['sent_at'] ?? 0) + self::COOLDOWN - (int)($this->clock)());
    }
}

<?php
declare(strict_types=1);

/**
 * Minimal outbound mailer: demo mode (log only), a tiny SMTP client, or mail() as a fallback.
 * A transport callable can be injected so tests never touch a socket.
 */
final class Mailer
{
    /** @var callable(string,string,string):bool|null */
    private $transport;

    /**
     * @param array{demo?:bool,host?:string,port?:int,user?:string,pass?:string,encryption?:string,from_address?:string,from_name?:string} $config
     */
    public function __construct(private readonly array $config = [], ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public static function fromEnv(): self
    {
        $env = static function (string $key, string $default = ''): string {
            if (function_exists('ss_env')) return ss_env($key, $default);
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            return ($value === false || $value === null || trim((string)$value) === '') ? $default : trim((string)$value);
        };
        $encryption = strtolower($env('SMTP_ENCRYPTION', 'tls'));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) $encryption = 'tls';
        $port = (int)$env('SMTP_PORT', $encryption === 'ssl' ? '465' : '587');
        return new self([
            'demo' => in_array(strtolower($env('MAIL_DEMO_MODE', '0')), ['1', 'true', 'yes', 'on'], true),
            'host' => $env('SMTP_HOST'),
            'port' => $port > 0 ? $port : 587,
            'user' => $env('SMTP_USER'),
            'pass' => $env('SMTP_PASS'),
            'encryption' => $encryption,
            'from_address' => defined('MAIL_FROM_ADDRESS') ? (string)MAIL_FROM_ADDRESS : $env('MAIL_FROM_ADDRESS', 'no-reply@localhost'),
            'from_name' => defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : $env('MAIL_FROM_NAME', 'SkinSyntax'),
        ]);
    }

    public function isDemo(): bool
    {
        return !empty($this->config['demo']);
    }

    public function send(string $to, string $subject, string $html): bool
    {
        $to = trim($to);
        if (!self::validAddress($to) || preg_match('/[\r\n]/', $subject) === 1) {
            error_log('[mail] rejected: invalid recipient or subject');
            return false;
        }
        if ($this->isDemo()) {
            error_log('[mail demo] to=' . self::maskEmail($to) . ' subject=' . $subject);
            return true;
        }
        if ($this->transport !== null) {
            return (bool)($this->transport)($to, $subject, $html);
        }
        $host = trim((string)($this->config['host'] ?? ''));
        if ($host !== '') {
            return $this->sendSmtp($host, $to, $subject, $html);
        }
        return $this->sendNative($to, $subject, $html);
    }

    public static function validAddress(string $email): bool
    {
        return preg_match('/[\r\n<>,;]/', $email) !== 1 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) return '***';
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    private function fromAddress(): string
    {
        $from = trim((string)($this->config['from_address'] ?? ''));
        return self::validAddress($from) ? $from : 'no-reply@localhost';
    }

    private function fromName(): string
    {
        $name = trim((string)($this->config['from_name'] ?? ''));
        return preg_replace('/[\r\n"]/', '', $name) ?? '';
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** @return list<string> */
    private function headers(string $to, string $subject): array
    {
        $from = $this->fromAddress();
        $name = $this->fromName();
        $fromHeader = $name === '' ? '<' . $from . '>' : self::encodeHeader($name) . ' <' . $from . '>';
        $domain = substr($from, (int)strrpos($from, '@') + 1) ?: 'localhost';
        return [
            'From: ' . $fromHeader,
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
    }

    private function sendNative(string $to, string $subject, string $html): bool
    {
        $headers = $this->headers($to, $subject);
        // mail() adds To and Subject itself.
        $headers = array_values(array_filter($headers, static fn(string $h): bool => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:')));
        $ok = @mail($to, self::encodeHeader($subject), chunk_split(base64_encode($html)), implode("\r\n", $headers));
        if (!$ok) error_log('[mail] mail() returned false');
        return (bool)$ok;
    }

    private function sendSmtp(string $host, string $to, string $subject, string $html): bool
    {
        $encryption = (string)($this->config['encryption'] ?? 'tls');
        $port = (int)($this->config['port'] ?? 587);
        $target = ($encryption === 'ssl' || $port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($target, $errno, $errstr, 10);
        if (!is_resource($socket)) {
            error_log('[mail] smtp connect failed errno=' . $errno);
            return false;
        }
        stream_set_timeout($socket, 10);
        try {
            if (!$this->expect($socket, [220], 'greeting')) return false;
            $ehlo = 'EHLO ' . ($this->ehloName());
            if (!$this->command($socket, $ehlo, [250], 'ehlo')) return false;
            if ($encryption === 'tls' && $port !== 465) {
                if (!$this->command($socket, 'STARTTLS', [220], 'starttls')) return false;
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    error_log('[mail] smtp step=tls_handshake failed');
                    return false;
                }
                if (!$this->command($socket, $ehlo, [250], 'ehlo_tls')) return false;
            }
            $user = (string)($this->config['user'] ?? '');
            if ($user !== '') {
                if (!$this->command($socket, 'AUTH LOGIN', [334], 'auth')) return false;
                if (!$this->command($socket, base64_encode($user), [334], 'auth_user')) return false;
                if (!$this->command($socket, base64_encode((string)($this->config['pass'] ?? '')), [235], 'auth_pass')) return false;
            }
            if (!$this->command($socket, 'MAIL FROM:<' . $this->fromAddress() . '>', [250], 'mail_from')) return false;
            if (!$this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'rcpt_to')) return false;
            if (!$this->command($socket, 'DATA', [354], 'data')) return false;
            $message = implode("\r\n", $this->headers($to, $subject)) . "\r\n\r\n" . chunk_split(base64_encode($html), 76, "\r\n");
            if (!$this->command($socket, $message . '.', [250], 'data_body')) return false;
            $this->command($socket, 'QUIT', [221], 'quit');
            return true;
        } finally {
            @fclose($socket);
        }
    }

    private function ehloName(): string
    {
        $from = $this->fromAddress();
        $domain = substr($from, (int)strrpos($from, '@') + 1);
        return preg_match('/^[A-Za-z0-9.-]+$/', $domain) === 1 ? $domain : 'localhost';
    }

    /** @param resource $socket */
    private function command($socket, string $line, array $codes, string $step): bool
    {
        if (@fwrite($socket, $line . "\r\n") === false) {
            error_log('[mail] smtp step=' . $step . ' write failed');
            return false;
        }
        return $this->expect($socket, $codes, $step);
    }

    /** @param resource $socket */
    private function expect($socket, array $codes, string $step): bool
    {
        $code = 0;
        while (($line = fgets($socket, 1024)) !== false) {
            if (strlen($line) < 4) continue;
            $code = (int)substr($line, 0, 3);
            if ($line[3] !== '-') break;
        }
        if (!in_array($code, $codes, true)) {
            error_log('[mail] smtp step=' . $step . ' code=' . $code);
            return false;
        }
        return true;
    }
}

<?php

class MailService {
    public static function isDemoMode(): bool {
        if (!defined('MAIL_DEMO_MODE')) {
            return true;
        }
        $value = strtolower(trim((string)MAIL_DEMO_MODE));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function smtpConfigured(): bool {
        $host = defined('SMTP_HOST') ? trim((string)SMTP_HOST) : '';
        $user = defined('SMTP_USER') ? trim((string)SMTP_USER) : '';
        $pass = defined('SMTP_PASS') ? trim((string)SMTP_PASS) : '';
        return $host !== '' && $user !== '' && $pass !== '';
    }

    public static function sendHtml(string $to, string $subject, string $html): bool {
        if (self::smtpConfigured()) {
            return self::sendViaSmtp($to, $subject, $html);
        }

        return self::sendViaPhpMail($to, $subject, $html);
    }

    private static function fromAddress(): string {
        return defined('MAIL_FROM_ADDRESS') ? (string)MAIL_FROM_ADDRESS : 'no-reply@skinsyntax.local';
    }

    private static function fromName(): string {
        return defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : 'SkinSyntax';
    }

    private static function sendViaPhpMail(string $to, string $subject, string $html): bool {
        $fromName = self::fromName();
        $fromAddress = self::fromAddress();
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
            'X-Mailer: PHP/' . phpversion(),
        ];

        if (DIRECTORY_SEPARATOR === '\\') {
            $sendmailPath = 'D:\\xampp\\sendmail\\sendmail.exe';
            if (is_file($sendmailPath)) {
                $message = implode("\r\n", [
                    'To: ' . $to,
                    'From: ' . $fromName . ' <' . $fromAddress . '>',
                    'Subject: ' . $encodedSubject,
                    ...$headers,
                    '',
                    $html,
                    '',
                ]);

                $tmpFile = tempnam(sys_get_temp_dir(), 'skinsyntax-mail-');
                if ($tmpFile !== false) {
                    file_put_contents($tmpFile, $message);
                    $command = 'cmd /C ""' . $sendmailPath . '" -t < "' . $tmpFile . '""';
                    $output = [];
                    $exitCode = 1;
                    @exec($command, $output, $exitCode);
                    @unlink($tmpFile);

                    if ($exitCode === 0) {
                        return true;
                    }

                    error_log('SkinSyntax sendmail.exe failed. Exit code: ' . $exitCode);
                }
            }
        }

        return @mail($to, $encodedSubject, $html, implode("\r\n", $headers));
    }

    private static function sendViaSmtp(string $to, string $subject, string $html): bool {
        $host = trim((string)SMTP_HOST);
        $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $user = trim((string)SMTP_USER);
        $pass = (string)SMTP_PASS;
        $encryption = defined('SMTP_ENCRYPTION') ? strtolower(trim((string)SMTP_ENCRYPTION)) : 'tls';
        $fromAddress = self::fromAddress();
        $fromName = self::fromName();
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            error_log('SkinSyntax SMTP connect failed: ' . $errstr);
            return false;
        }

        stream_set_timeout($socket, 20);

        if (!self::smtpExpect($socket, [220])) {
            fclose($socket);
            return false;
        }

        $ehloHost = 'localhost';
        if (!self::smtpCommand($socket, 'EHLO ' . $ehloHost, [250])) {
            fclose($socket);
            return false;
        }

        if ($encryption === 'tls') {
            if (!self::smtpCommand($socket, 'STARTTLS', [220])) {
                fclose($socket);
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return false;
            }
            if (!self::smtpCommand($socket, 'EHLO ' . $ehloHost, [250])) {
                fclose($socket);
                return false;
            }
        }

        if (!self::smtpCommand($socket, 'AUTH LOGIN', [334])
            || !self::smtpCommand($socket, base64_encode($user), [334])
            || !self::smtpCommand($socket, base64_encode($pass), [235])) {
            fclose($socket);
            return false;
        }

        if (!self::smtpCommand($socket, 'MAIL FROM:<' . $fromAddress . '>', [250])
            || !self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251])
            || !self::smtpCommand($socket, 'DATA', [354])) {
            fclose($socket);
            return false;
        }

        $body = implode("\r\n", [
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $html,
            '',
        ]);

        fwrite($socket, $body . "\r\n.\r\n");
        if (!self::smtpExpect($socket, [250])) {
            fclose($socket);
            return false;
        }

        self::smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    }

    private static function smtpCommand($socket, string $command, array $expectedCodes): bool {
        fwrite($socket, $command . "\r\n");
        return self::smtpExpect($socket, $expectedCodes);
    }

    private static function smtpExpect($socket, array $expectedCodes): bool {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $code = (int)substr(trim($response), 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            error_log('SkinSyntax SMTP unexpected response: ' . trim($response));
            return false;
        }

        return true;
    }
}

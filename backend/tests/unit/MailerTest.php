<?php
declare(strict_types=1);

// Pure unit test: the transport is injected, no socket is ever opened.
require_once __DIR__ . '/../../app/services/Mailer.php';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$calls = [];
$transport = static function (string $to, string $subject, string $html) use (&$calls): bool {
    $calls[] = [$to, $subject, $html];
    return true;
};

$logFile = tempnam(sys_get_temp_dir(), 'mailerlog');
$previousLog = ini_set('error_log', $logFile);

$mailer = new Mailer(['demo' => true], $transport);
$check('demo_mode_reports_demo', $mailer->isDemo() === true);
$check('demo_mode_returns_true', $mailer->send('user@example.test', 'Subject', '<p>x</p>') === true);
$check('demo_mode_does_not_call_transport', $calls === []);
$log = (string)file_get_contents($logFile);
$check('demo_log_masks_email', str_contains($log, '[mail demo] to=u***@example.test') && !str_contains($log, 'user@example.test'));

$calls = [];
$live = new Mailer(['demo' => false], $transport);
$check('transport_is_used_when_not_demo', $live->send('user@example.test', 'Hello', '<b>hi</b>') === true
    && $calls === [['user@example.test', 'Hello', '<b>hi</b>']]);

$failing = new Mailer([], static fn(string $to, string $subject, string $html): bool => false);
$check('transport_failure_returns_false', $failing->send('user@example.test', 'Hello', 'x') === false);

$calls = [];
$check('crlf_in_recipient_rejected', $live->send("a@example.test\r\nBcc: b@example.test", 'S', 'x') === false);
$check('crlf_in_subject_rejected', $live->send('user@example.test', "Hi\r\nBcc: b@example.test", 'x') === false);
$check('invalid_email_rejected', $live->send('not-an-email', 'S', 'x') === false);
$check('empty_email_rejected', $live->send('', 'S', 'x') === false);
$check('rejected_mail_never_reaches_transport', $calls === []);

$check('mask_email_shape', Mailer::maskEmail('bichvi@gmail.com') === 'b***@gmail.com' && Mailer::maskEmail('oops') === '***');
$check('valid_address_accepts_normal', Mailer::validAddress('a.b+c@example.test'));

$env = Mailer::fromEnv();
$check('from_env_builds_mailer', $env instanceof Mailer);

if ($previousLog !== false) ini_set('error_log', (string)$previousLog);
@unlink($logFile);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);

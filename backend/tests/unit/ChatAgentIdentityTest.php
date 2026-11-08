<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatAgentIdentity.php';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$em = static fn(string $e): string => 'em:' . substr(hash('sha256', strtolower(trim($e))), 0, 32);
$objectId = '507f1f77bcf86cd799439011';

$check('int_id', ChatAgentIdentity::subFor(['id' => 42, 'email' => 'a@x.test']) === '42');
$check('numeric_string_id', ChatAgentIdentity::subFor(['id' => '17', 'email' => 'a@x.test']) === '17');
$check('numeric_string_normalised', ChatAgentIdentity::subFor(['id' => '007']) === '7');
$check('objectid_string_id', ChatAgentIdentity::subFor(['id' => $objectId, 'email' => 'a@x.test']) === $objectId);
$check('null_id_with_email_uses_em', ChatAgentIdentity::subFor(['id' => null, 'email' => 'a@x.test']) === $em('a@x.test'));
$check('missing_id_with_email_uses_em', ChatAgentIdentity::subFor(['email' => 'a@x.test']) === $em('a@x.test'));
$check('int_zero_with_email_uses_em', ChatAgentIdentity::subFor(['id' => 0, 'email' => 'a@x.test']) === $em('a@x.test'));
$check('string_zero_with_email_uses_em', ChatAgentIdentity::subFor(['id' => '0', 'email' => 'a@x.test']) === $em('a@x.test'));
$check('email_is_case_and_space_insensitive', ChatAgentIdentity::subFor(['id' => 0, 'email' => '  A@X.Test ']) === $em('a@x.test'));
$check('em_form_shape', preg_match('/^em:[0-9a-f]{32}$/', ChatAgentIdentity::subFor(['id' => 0, 'email' => 'a@x.test'])) === 1);
$check('different_emails_different_subs',
    ChatAgentIdentity::subFor(['id' => 0, 'email' => 'a@x.test']) !== ChatAgentIdentity::subFor(['id' => 0, 'email' => 'b@x.test']));
$check('empty_user_is_empty', ChatAgentIdentity::subFor([]) === '');
$check('id_zero_no_email_is_empty', ChatAgentIdentity::subFor(['id' => 0]) === '');
$check('blank_id_blank_email_is_empty', ChatAgentIdentity::subFor(['id' => '  ', 'email' => ' ']) === '');
$check('negative_id_falls_back_to_email', ChatAgentIdentity::subFor(['id' => -3, 'email' => 'a@x.test']) === $em('a@x.test'));

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);

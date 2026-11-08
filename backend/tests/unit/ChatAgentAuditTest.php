<?php
require_once __DIR__ . '/../../app/services/ChatAgentAudit.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$params = [
    'phone' => '0912345678',
    'address' => '12 Nguyễn Văn A, Q.1, TP.HCM',
    'authorization' => 'Bearer secret',
    'nested' => [['sdt_nguoi_nhan' => '+84 912 345 678', 'csrf' => 'secret']],
];
$masked = ChatAgentAudit::mask($params);
$check('mask_phone', $masked['phone'] === '09xx xxx 678');
$check('mask_address', $masked['address'] === '…, TP.HCM');
$check('token_removed', $masked['authorization'] === '***' && $masked['nested'][0]['csrf'] === '***');
$check('nested_masked', $masked['nested'][0]['sdt_nguoi_nhan'] === '84xx xxx 678');
$check('input_not_mutated', $params['phone'] === '0912345678' && $params['nested'][0]['csrf'] === 'secret');
$check('sensitive_containers_fully_masked', ChatAgentAudit::mask([
    'token' => ['a' => 'b'],
    'phone' => ['0912345678'],
]) === ['token' => '***', 'phone' => '***']);

$extra = ChatAgentAudit::mask([
    'phone_number' => '0912345678',
    'so_dien_thoai' => '0912345678',
    'mobile' => '0912345678',
    'full_address' => '12 Nguyễn Văn A, Q.1, TP.HCM',
    'street' => '12 Lê Lợi',
    'duong' => '12 Lê Lợi',
    'dia_chi_giao_hang' => '12 Nguyễn Văn A, Q.1, TP.HCM',
    'ten_nguoi_nhan' => 'Nguyễn Văn A',
    'receiver_name' => 'Bich Vi',
    'full_name' => 'Bich Vi',
    'recipient_name' => 'Bich Vi',
    'ho_ten' => 'Ốc',
    'nested' => ['new_address' => ['phone_number' => '0987654321', 'ten_nguoi_nhan' => 'Lan']],
]);
$check('phone_like_keys_masked', $extra['phone_number'] === '09xx xxx 678'
    && $extra['so_dien_thoai'] === '09xx xxx 678' && $extra['mobile'] === '09xx xxx 678');
$check('address_like_keys_masked', $extra['full_address'] === '…, TP.HCM'
    && $extra['street'] === '…' && $extra['duong'] === '…'
    && $extra['dia_chi_giao_hang'] === '…, TP.HCM');
$check('name_like_keys_masked', $extra['ten_nguoi_nhan'] === 'N***' && $extra['receiver_name'] === 'B***'
    && $extra['full_name'] === 'B***' && $extra['recipient_name'] === 'B***' && $extra['ho_ten'] === 'Ố***');
$check('nested_new_address_masked', $extra['nested']['new_address']['phone_number'] === '09xx xxx 321'
    && $extra['nested']['new_address']['ten_nguoi_nhan'] === 'L***');
$check('name_container_fully_masked', ChatAgentAudit::mask(['full_name' => ['a']]) === ['full_name' => '***']);

$documents = [];
$audit = new ChatAgentAudit(function (array $document) use (&$documents): void {
    $documents[] = $document;
});
$audit->record('user-1', 'order.create', ['phone' => '0912345678'], 'ok');
$check('record_document_shape', count($documents) === 1
    && $documents[0]['actor'] === 'user-1'
    && $documents[0]['action'] === 'order.create'
    && $documents[0]['params']['phone'] === '09xx xxx 678'
    && $documents[0]['outcome'] === 'ok'
    && $documents[0]['source'] === 'php'
    && array_key_exists('ts', $documents[0]));

$auditFailure = new ChatAgentAudit(function (array $document): void {
    throw new RuntimeException('insert failed');
});
$auditFailure->record('user-1', 'order.create', [], 'failed');
$check('insert_failure_is_swallowed', true);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);

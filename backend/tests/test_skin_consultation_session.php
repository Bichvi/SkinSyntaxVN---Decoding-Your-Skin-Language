<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/services/SkinConsultationSession.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION = [];
$userA = ['email' => 'a@example.test'];
$userB = ['email' => 'b@example.test'];
$started = SkinConsultationSession::start($userA, 'self-1', [
    'muc_do_nhay_cam' => 'Rất dễ',
    'van_de_da' => ['Da khô căng, bong tróc'],
    'muc_tieu_cham_soc' => 'Phục hồi màng bảo vệ da, cấp ẩm',
    'ngan_sach' => 500000,
]);
if (!$started || SkinConsultationSession::get($userA) === null) {
    throw new RuntimeException('Session should be readable by its owner.');
}
if (SkinConsultationSession::get($userB) !== null) {
    throw new RuntimeException('A different account must not read the consultation session.');
}
$updated = SkinConsultationSession::updatePreference($userA, '6365', 'owned');
if (!$updated || !in_array('6365', $updated['product_preferences']['owned_product_ids'], true)) {
    throw new RuntimeException('Owned product preference was not stored.');
}
SkinConsultationSession::clear($userA);
if (SkinConsultationSession::get($userA) !== null) {
    throw new RuntimeException('Session should be clearable.');
}

echo "SkinConsultationSession: PASS\n";

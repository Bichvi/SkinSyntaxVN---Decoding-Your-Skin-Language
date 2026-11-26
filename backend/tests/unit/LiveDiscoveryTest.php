<?php

require_once __DIR__ . '/../../app/services/LiveDiscovery.php';

$sessions = [
    ['id' => 'replay', 'status' => 'replay_ready', 'title' => 'Routine cho da dầu', 'discovery_time' => 500],
    ['id' => 'generic', 'status' => 'live', 'title' => 'Hỏi đáp skincare', 'discovery_time' => 400],
    ['id' => 'matched', 'status' => 'live', 'title' => 'Cùng chuyên gia', 'tom_tat_phien_live' => 'Kiểm dầu trong mùa nóng', 'discovery_time' => 300],
    ['id' => 'planned', 'status' => 'scheduled', 'title' => 'Chăm sóc da khô', 'discovery_time' => 600],
];
$skin = LiveDiscovery::skinKey(['tinh_trang_dac_biet' => 'loaida:8']);
$personal = LiveDiscovery::rank($sessions, $skin);
$general = LiveDiscovery::rank($sessions, null);
$cases = [
    'live first, with an explicitly matching topic before other live sessions' => array_column($personal, 'id') === ['matched', 'generic', 'replay', 'planned'],
    'guests see latest active session and receive no profile label' => array_column($general, 'id') === ['generic', 'matched', 'replay', 'planned']
        && array_column($general, 'discovery_topic') === ['', '', '', ''],
    'profile label appears only on matched topics' => array_column($personal, 'discovery_topic') === ['Da dầu', '', 'Da dầu', ''],
    'explicit combination skin profile is recognized' => LiveDiscovery::skinKey(['loai_da' => 'Da hỗn hợp']) === 'hon_hop',
    'missing skin data yields no profile signal' => LiveDiscovery::skinKey(null) === null,
    'recording epoch remains sortable' => LiveDiscovery::timestamp(1700000000) === 1700000000,
];
foreach ($cases as $label => $pass) {
    echo ($pass ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
}
exit(in_array(false, $cases, true) ? 1 : 0);

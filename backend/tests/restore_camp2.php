<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/VideoRewardCampaign.php';
$m = new VideoRewardCampaign($pdo);
$m->toggleActive('rew_7e6ada3dc3', true);
echo "Toggled rew_7e6ada3dc3 to ACTIVE" . PHP_EOL;

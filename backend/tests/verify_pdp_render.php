<?php
$html = file_get_contents('http://skinsyntax-nginx/index.php?r=chitiet&id=6376');
echo 'Has synaRewardCard: ' . (strpos($html, 'id="synaRewardCard"') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'Has synaRewardProgressBar: ' . (strpos($html, 'id="synaRewardProgressBar"') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'Has campaign name: ' . (strpos($html, 'Ưu đãi Syna 3D - Giảm 15%') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'Has video_watch_start: ' . (strpos($html, 'video_watch_start') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'Has video_watch_heartbeat: ' . (strpos($html, 'video_watch_heartbeat') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'Has video_watch_complete: ' . (strpos($html, 'video_watch_complete') !== false ? 'YES' : 'NO') . PHP_EOL;

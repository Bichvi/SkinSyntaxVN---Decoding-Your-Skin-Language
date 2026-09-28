<?php
$routes = ['?r=home', '?r=chitiet&id=111', '?r=goiy'];

foreach ($routes as $route) {
    echo "=== PROFILING $route ===\n";
    $cmd = "php -r '
        require \"/var/www/html/tests/profile_request_breakdown.php\";
        // runProfiledRoute was defined in file
    '";
    // Or simpler: pass route via CLI argument to profile_single_breakdown.php
}

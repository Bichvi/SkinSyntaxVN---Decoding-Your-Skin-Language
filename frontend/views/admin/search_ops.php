<?php
// Vận hành tìm kiếm (U11) - chỉ đọc. Biến từ AdminSearchOpsController::index(): $health (mảng hoặc null).
$health = is_array($health ?? null) ? $health : null;
$fmtTime = static function ($epoch): string {
    if (!is_numeric($epoch)) {
        return '—';
    }
    return (new DateTime('@' . (int)$epoch))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m H:i');
};
$num = static function ($v, int $dec = 0): string {
    return is_numeric($v) ? number_format((float)$v, $dec, ',', '.') : '—';
};
$statusBadge = static function (?array $job): string {
    if ($job === null) {
        return '<span class="badge bg-secondary">chưa chạy</span>';
    }
    $status = (string)($job['status'] ?? '');
    $map = ['ok' => ['bg-success', 'OK'], 'fail' => ['bg-danger', 'LỖI'], 'skipped' => ['bg-warning text-dark', 'BỎ QUA']];
    [$class, $label] = $map[$status] ?? ['bg-secondary', $status !== '' ? $status : '?'];
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
};
$jobLabels = [
    'catalog_sync' => 'Đồng bộ sản phẩm',
    'graph_sync_incremental' => 'Cập nhật liên kết sản phẩm',
    'graph_sync_full' => 'Cập nhật toàn bộ liên kết',
    'graph_eval' => 'Kiểm tra liên kết sản phẩm',
    'co_purchase' => 'Cập nhật gợi ý mua cùng',
    'chroma_reindex' => 'Cập nhật tìm kiếm',
    'dictionary_export' => 'Cập nhật từ khóa',
    'popular_maintain' => 'Cập nhật từ khóa phổ biến',
    'zero_result_report' => 'Báo cáo tìm kiếm chưa có kết quả',
    'config_watch' => 'Theo dõi cấu hình',
    'health_check' => 'Kiểm tra dịch vụ',
];
?>
<div class="container-fluid px-4 py-4" data-testid="search-ops">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Vận hành tìm kiếm</h1>
            <p class="text-muted mb-0 small">Theo dõi trạng thái tìm kiếm và đồng bộ dữ liệu. Số liệu được cập nhật định kỳ.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="index.php?r=admin_search_ops">Tải lại</a>
    </div>

<?php if ($health === null): ?>
    <div class="alert alert-danger" role="alert" data-testid="ops-unavailable">Không thể tải trạng thái dịch vụ. Vui lòng thử lại sau.</div>
<?php else: ?>
    <?php $alerts = is_array($health['alerts'] ?? null) ? $health['alerts'] : []; ?>
    <div class="alert <?= empty($alerts) ? 'alert-success' : (($health['ok'] ?? true) ? 'alert-warning' : 'alert-danger') ?>" role="status" data-testid="ops-status">
        <?php if (empty($alerts)): ?>
            <strong>Không có cảnh báo.</strong>
        <?php else: ?>
            <strong><?= h((string)count($alerts)) ?> cảnh báo</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($alerts as $alert): ?>
                    <li data-alert="<?= h((string)($alert['code'] ?? '')) ?>"><span class="badge bg-<?= ($alert['level'] ?? '') === 'critical' ? 'danger' : 'warning text-dark' ?>"><?= h((string)($alert['level'] ?? '')) ?></span> <?= h((string)($alert['message'] ?? '')) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <div class="small text-muted mt-1">Tạo lúc <?= h((string)($health['generated_at'] ?? '')) ?></div>
    </div>

    <?php
    $neo = is_array($health['neo4j'] ?? null) ? $health['neo4j'] : [];
    $chroma = is_array($health['chroma'] ?? null) ? $health['chroma'] : [];
    $mongo = is_array($health['mongo'] ?? null) ? $health['mongo'] : [];
    $redis = is_array($health['redis'] ?? null) ? $health['redis'] : [];
    $stream = is_array($redis['catalog_stream'] ?? null) ? $redis['catalog_stream'] : [];
    $drift = is_array($health['drift'] ?? null) ? $health['drift'] : [];
    $okBadge = static fn($block): string => !empty($block['ok']) ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">LỖI</span>';
    ?>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><strong>Neo4j</strong><?= $okBadge($neo) ?></div>
            <div class="small mt-2">Sản phẩm active: <strong><?= h($num($neo['count'] ?? null)) ?></strong> (Mongo: <?= h($num($mongo['neo4j_expected'] ?? null)) ?>)</div>
            <div class="small">Lệch: <?= h($num($drift['neo4j_pct'] ?? null, 2)) ?>%</div>
            <div class="small">Liên kết sản phẩm: <?= h($num($neo['similar_to'] ?? null)) ?></div>
            <div class="small">Bộ nhớ: <strong><?= h($num($neo['mem_pct'] ?? null, 1)) ?>%</strong> (<?= h($num($neo['mem_used_mb'] ?? null)) ?> / <?= h($num($neo['mem_limit_mb'] ?? null)) ?> MiB)</div>
            <div class="small">Bộ nhớ ứng dụng: <?= h($num($neo['heap_used_mb'] ?? null)) ?> / <?= h($num($neo['heap_max_mb'] ?? null)) ?> MiB</div>
        </div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><strong>Chroma</strong><?= $okBadge($chroma) ?></div>
            <div class="small mt-2">Tài liệu: <strong><?= h($num($chroma['count'] ?? null)) ?></strong> (Mongo: <?= h($num($mongo['chroma_expected'] ?? null)) ?>)</div>
            <div class="small">Lệch: <?= h($num($drift['chroma_pct'] ?? null, 2)) ?>%</div>
        </div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><strong>Redis</strong><?= $okBadge($redis) ?></div>
            <div class="small mt-2">Bộ nhớ: <?= h($num($redis['used_memory_mb'] ?? null, 1)) ?> MiB</div>
            <div class="small">Mục chờ đồng bộ: <?= h($num($stream['length'] ?? null)) ?></div>
            <div class="small">Đang chờ xử lý: <strong><?= h($num($stream['pending'] ?? null)) ?></strong>, lâu nhất <?= h($num($stream['oldest_pending_idle_s'] ?? null)) ?> giây</div>
            <div class="small">Lỗi cần xử lý: <?= h($num($stream['dead'] ?? null)) ?></div>
        </div></div></div>
        <div class="col-12 col-md-6 col-xl-3"><div class="card h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><strong>Mongo Atlas</strong><?= $okBadge($mongo) ?></div>
            <div class="small mt-2">Tổng sản phẩm: <?= h($num($mongo['total_products'] ?? null)) ?></div>
        </div></div></div>
    </div>

    <h2 class="h6 fw-bold">Tác vụ định kỳ</h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle" data-testid="ops-jobs">
            <thead><tr><th>Tác vụ</th><th>Trạng thái</th><th>Lần cuối</th><th>Thời lượng</th><th>Lỗi liên tiếp</th><th>Chi tiết</th></tr></thead>
            <tbody>
            <?php foreach ($jobLabels as $name => $label): $job = is_array($health['jobs'][$name] ?? null) ? $health['jobs'][$name] : null; ?>
                <tr data-job="<?= h($name) ?>">
                    <td><?= h($label) ?></td>
                    <td><?= $statusBadge($job) ?></td>
                    <td><?= h($job ? $fmtTime($job['finished_at'] ?? null) : '—') ?></td>
                    <td><?= h($job ? $num($job['duration_s'] ?? null, 1) . 's' : '—') ?></td>
                    <td><?= h($job ? (string)(int)($job['consecutive_failures'] ?? 0) : '—') ?></td>
                    <td class="small text-muted"><?php
                        if ($job && !empty($job['error'])) {
                            echo h((string)$job['error']);
                        } elseif ($job && is_array($job['detail'] ?? null)) {
                            $parts = [];
                            foreach (array_slice($job['detail'], 0, 6, true) as $k => $v) {
                                if (is_scalar($v)) {
                                    $parts[] = $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : $v);
                                }
                            }
                            echo h(implode(', ', $parts));
                        }
                    ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>

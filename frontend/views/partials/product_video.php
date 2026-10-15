<?php
/**
 * AI Idol videos for the product detail page.
 * Expects $productVideos = ['live' => ?array, 'videos' => array, 'current_id' => string]
 * prepared by SanPhamController::chitiet(). Rendered only when there is a video or a live show.
 */
$pvData = is_array($productVideos ?? null) ? $productVideos : [];
$pvVideos = array_values(is_array($pvData['videos'] ?? null) ? $pvData['videos'] : []);
$pvLive = is_array($pvData['live'] ?? null) ? $pvData['live'] : null;

$pvDayMonth = static function (string $iso): string {
    if ($iso === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m');
    } catch (Throwable $e) {
        return '';
    }
};
$pvDuration = static function ($seconds): string {
    $total = max(0, (int)round((float)$seconds));
    return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
};

$pvClientVideos = [];
foreach ($pvVideos as $video) {
    $day = $pvDayMonth((string)($video['date'] ?? ''));
    $pvClientVideos[] = [
        'id' => (string)$video['video_id'],
        'src' => (string)$video['video_url'],
        'poster' => (string)$video['poster_url'],
        'landscape' => ($video['format'] ?? '16:9') !== '9:16',
        'title' => (string)($video['campaign_name'] ?? '') !== '' ? (string)$video['campaign_name'] : 'Syna giới thiệu sản phẩm',
        'duration' => $pvDuration($video['duration'] ?? 0),
        'cardDate' => $day === '' ? '' : (($video['date_kind'] ?? '') === 'aired' ? 'Phát sóng ' : 'Tạo ') . $day,
        'madeDate' => $day === '' ? '' : 'Video tạo ngày ' . $day,
        'products' => array_values($video['products'] ?? []),
    ];
}
$pvAssetBase = BASE_URL . '/assets';
$pvCssVersion = @filemtime(VIEW_DIR . '/../public/assets/css/product-video.css') ?: '1';
$pvJsVersion = @filemtime(VIEW_DIR . '/../public/assets/js/product-video.js') ?: '1';
?>
<link rel="stylesheet" href="<?= h($pvAssetBase . '/css/product-video.css?v=' . $pvCssVersion) ?>">
<?php if ($pvClientVideos): ?>
<div class="col-12 col-lg-7">
<section class="pv-row" aria-labelledby="pvRowTitle" data-pv-row>
  <div class="pv-row-head">
    <h2 id="pvRowTitle">Video về sản phẩm</h2>
    <span class="pv-row-count"><?= count($pvClientVideos) ?> video</span>
    <div class="pv-arrows" data-pv-arrows hidden>
      <button type="button" class="pv-arrow" data-pv-prev aria-label="Lướt về các video trước"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
      <button type="button" class="pv-arrow" data-pv-next aria-label="Lướt tới các video sau"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
    </div>
  </div>
  <div class="pv-list" data-pv-list>
    <?php foreach ($pvClientVideos as $index => $video): ?>
      <button type="button" class="pv-card" data-pv-index="<?= $index ?>"
              aria-label="<?= h('Xem video: ' . $video['title']) ?>">
        <span class="pv-poster">
          <img src="<?= h($video['poster']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
          <?php if ($index === 0): ?><span class="pv-new">Mới nhất</span><?php endif; ?>
          <span class="pv-play" aria-hidden="true">▶</span>
          <span class="pv-dur"><?= h($video['duration']) ?></span>
        </span>
        <span class="pv-title"><?= h($video['title']) ?></span>
        <?php if ($video['cardDate'] !== ''): ?><span class="pv-date"><?= h($video['cardDate']) ?></span><?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>
</section>
</div>
<?php endif; ?>
<script type="application/json" id="productVideoData"><?= json_encode([
    'currentId' => (string)($pvData['current_id'] ?? ''),
    'liveUrl' => BASE_URL . '/index.php?r=live',
    'live' => $pvLive,
    'videos' => $pvClientVideos,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= h($pvAssetBase . '/js/product-video.js?v=' . $pvJsVersion) ?>" defer></script>

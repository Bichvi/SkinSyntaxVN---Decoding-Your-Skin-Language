<?php
$title = $title ?? 'Hệ thống cửa hàng';
$eyebrow = $eyebrow ?? 'SkinSyntax';
$summary = $summary ?? '';
$stats = isset($stats) && is_array($stats) ? $stats : [];
$channels = isset($channels) && is_array($channels) ? $channels : [];
$serviceSteps = isset($serviceSteps) && is_array($serviceSteps) ? $serviceSteps : [];
$helpLinks = isset($helpLinks) && is_array($helpLinks) ? $helpLinks : [];
?>

<style>
  .store-network {
    max-width: 1120px;
    margin: 0 auto;
  }

  .store-network__hero {
    padding: 36px;
    border-radius: 32px;
    background: linear-gradient(135deg, #0e2238 0%, #184b67 55%, #1f7d78 100%);
    color: #f8fbff;
    box-shadow: 0 26px 60px rgba(15, 23, 42, 0.18);
  }

  .store-network__eyebrow {
    display: inline-block;
    margin-bottom: 12px;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: rgba(248, 251, 255, .76);
  }

  .store-network__hero h1 {
    margin: 0 0 12px;
    font-size: clamp(30px, 4vw, 46px);
    font-weight: 900;
    line-height: 1.1;
  }

  .store-network__hero p {
    max-width: 780px;
    margin: 0;
    color: rgba(248, 251, 255, .88);
    line-height: 1.75;
  }

  .store-network__stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-top: 24px;
  }

  .store-network__stat {
    padding: 18px 20px;
    border-radius: 22px;
    background: rgba(255, 255, 255, .1);
    border: 1px solid rgba(255, 255, 255, .16);
    backdrop-filter: blur(8px);
  }

  .store-network__stat strong {
    display: block;
    font-size: 1.24rem;
    font-weight: 900;
    margin-bottom: 6px;
  }

  .store-network__stat span {
    color: rgba(248, 251, 255, .8);
    line-height: 1.6;
  }

  .store-network__layout {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(300px, .8fr);
    gap: 24px;
    margin-top: 24px;
  }

  .store-network__main,
  .store-network__aside {
    display: grid;
    gap: 18px;
  }

  .store-network__card {
    background: #fff;
    border: 1px solid #e4ecf2;
    border-radius: 26px;
    padding: 24px;
    box-shadow: 0 16px 36px rgba(15, 23, 42, 0.07);
  }

  .store-network__card h2,
  .store-network__card h3 {
    margin: 0 0 16px;
    color: #12263f;
    font-weight: 800;
  }

  .store-network__channel-grid {
    display: grid;
    gap: 14px;
  }

  .store-network__channel {
    display: grid;
    grid-template-columns: 48px minmax(0, 1fr);
    gap: 14px;
    align-items: start;
    padding: 16px 18px;
    border-radius: 20px;
    background: #f8fbff;
  }

  .store-network__channel-icon {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #d9eef4 0%, #e9f6f0 100%);
    color: #185067;
    font-size: 18px;
  }

  .store-network__channel-title {
    font-weight: 800;
    color: #16324d;
    margin-bottom: 4px;
  }

  .store-network__channel-text {
    color: #4d5f72;
    line-height: 1.72;
  }

  .store-network__steps {
    display: grid;
    gap: 12px;
    margin: 0;
    padding-left: 20px;
    color: #334155;
  }

  .store-network__note {
    color: #4d5f72;
    line-height: 1.75;
    margin-bottom: 0;
  }

  .store-network__links {
    display: grid;
    gap: 12px;
  }

  .store-network__links a {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 18px;
    background: #f6fafc;
    border: 1px solid #e3edf3;
    color: #16324d;
    text-decoration: none;
    font-weight: 700;
  }

  .store-network__links a:hover {
    background: #eef6fb;
  }

  @media (max-width: 991px) {
    .store-network__stats,
    .store-network__layout {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="container py-4 py-lg-5">
  <div class="store-network">
    <section class="store-network__hero">
      <span class="store-network__eyebrow"><?= h($eyebrow) ?></span>
      <h1><?= h($title) ?></h1>
      <p><?= h($summary) ?></p>

      <?php if (!empty($stats)): ?>
        <div class="store-network__stats">
          <?php foreach ($stats as $stat): ?>
            <div class="store-network__stat">
              <strong><?= h((string)($stat['value'] ?? '')) ?></strong>
              <span><?= h((string)($stat['label'] ?? '')) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <div class="store-network__layout">
      <div class="store-network__main">
        <section class="store-network__card">
          <h2>Các kênh phục vụ hiện tại</h2>
          <div class="store-network__channel-grid">
            <?php foreach ($channels as $channel): ?>
              <div class="store-network__channel">
                <div class="store-network__channel-icon"><i class="<?= h((string)($channel['icon'] ?? 'fa-solid fa-location-dot')) ?>"></i></div>
                <div>
                  <div class="store-network__channel-title"><?= h((string)($channel['title'] ?? 'Kênh phục vụ')) ?></div>
                  <div class="store-network__channel-text"><?= h((string)($channel['text'] ?? '')) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="store-network__card">
          <h2>Cách nhận hỗ trợ nhanh</h2>
          <ol class="store-network__steps">
            <?php foreach ($serviceSteps as $step): ?>
              <li><?= h((string)$step) ?></li>
            <?php endforeach; ?>
          </ol>
        </section>
      </div>

      <aside class="store-network__aside">
        <section class="store-network__card">
          <h3>Lưu ý về hệ thống cửa hàng</h3>
          <p class="store-network__note">SkinSyntax hiện công bố trước các kênh phục vụ số, hotline và hỗ trợ trên website. Khi có cửa hàng hoặc điểm hỗ trợ trực tiếp được mở mới, thông tin khu vực, thời gian hoạt động và hình thức phục vụ sẽ được cập nhật tại chính trang này.</p>
        </section>

        <section class="store-network__card">
          <h3>Đi nhanh tới dịch vụ cần dùng</h3>
          <div class="store-network__links">
            <?php foreach ($helpLinks as $link): ?>
              <a href="<?= h((string)($link['url'] ?? '#')) ?>">
                <span><?= h((string)($link['label'] ?? 'Xem thêm')) ?></span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      </aside>
    </div>
  </div>
</div>
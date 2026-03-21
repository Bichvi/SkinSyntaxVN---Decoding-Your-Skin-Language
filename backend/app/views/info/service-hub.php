<?php
$title = $title ?? 'Trung tâm dịch vụ';
$eyebrow = $eyebrow ?? 'SkinSyntax';
$summary = $summary ?? '';
$sections = isset($sections) && is_array($sections) ? $sections : [];
$supportCard = isset($supportCard) && is_array($supportCard) ? $supportCard : [];
$actions = isset($actions) && is_array($actions) ? $actions : [];
?>

<style>
  .service-hub {
    max-width: 1080px;
    margin: 0 auto;
  }

  .service-hub__hero {
    padding: 34px;
    border-radius: 30px;
    background: linear-gradient(135deg, #16324d 0%, #1f6a7d 100%);
    color: #f8fbff;
    box-shadow: 0 24px 52px rgba(15, 23, 42, 0.16);
  }

  .service-hub__eyebrow {
    display: inline-block;
    margin-bottom: 12px;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: rgba(248, 251, 255, .76);
  }

  .service-hub__hero h1 {
    margin: 0 0 12px;
    font-size: clamp(30px, 4vw, 44px);
    font-weight: 900;
    line-height: 1.12;
  }

  .service-hub__hero p {
    max-width: 760px;
    margin: 0;
    color: rgba(248, 251, 255, .88);
    line-height: 1.75;
  }

  .service-hub__layout {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(280px, .65fr);
    gap: 24px;
    margin-top: 24px;
  }

  .service-hub__main,
  .service-hub__aside {
    display: grid;
    gap: 18px;
  }

  .service-hub__card {
    background: #fff;
    border: 1px solid #e4ebf2;
    border-radius: 24px;
    padding: 24px;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.07);
  }

  .service-hub__card h2,
  .service-hub__card h3 {
    margin: 0 0 14px;
    color: #10233a;
    font-weight: 800;
  }

  .service-hub__items {
    display: grid;
    gap: 12px;
  }

  .service-hub__item {
    padding: 14px 16px;
    border-radius: 18px;
    background: #f7fbfe;
    color: #415164;
    line-height: 1.72;
  }

  .service-hub__support-text {
    color: #4a5a6d;
    line-height: 1.72;
    margin-bottom: 14px;
  }

  .service-hub__support-list {
    display: grid;
    gap: 10px;
    margin: 0;
    padding-left: 18px;
    color: #334155;
  }

  .service-hub__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 18px;
  }

  .service-hub__actions .btn {
    border-radius: 999px;
    padding-inline: 18px;
  }

  @media (max-width: 991px) {
    .service-hub__layout {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="container py-4 py-lg-5">
  <div class="service-hub">
    <section class="service-hub__hero">
      <span class="service-hub__eyebrow"><?= h($eyebrow) ?></span>
      <h1><?= h($title) ?></h1>
      <p><?= h($summary) ?></p>
    </section>

    <div class="service-hub__layout">
      <div class="service-hub__main">
        <?php foreach ($sections as $section): ?>
          <section class="service-hub__card">
            <h2><?= h((string)($section['title'] ?? 'Thông tin')) ?></h2>
            <div class="service-hub__items">
              <?php foreach (($section['items'] ?? []) as $item): ?>
                <div class="service-hub__item"><?= h((string)$item) ?></div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>

      <aside class="service-hub__aside">
        <section class="service-hub__card">
          <h3><?= h((string)($supportCard['title'] ?? 'Hỗ trợ tại SkinSyntax')) ?></h3>
          <p class="service-hub__support-text"><?= h((string)($supportCard['text'] ?? '')) ?></p>
          <ul class="service-hub__support-list">
            <?php foreach (($supportCard['bullets'] ?? []) as $bullet): ?>
              <li><?= h((string)$bullet) ?></li>
            <?php endforeach; ?>
          </ul>

          <?php if (!empty($actions)): ?>
            <div class="service-hub__actions">
              <?php foreach ($actions as $action): ?>
                <a class="btn btn-outline-brand" href="<?= h((string)($action['url'] ?? '#')) ?>"><?= h((string)($action['label'] ?? 'Xem thêm')) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </aside>
    </div>
  </div>
</div>
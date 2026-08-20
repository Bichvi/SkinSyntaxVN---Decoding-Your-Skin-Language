<?php
$title = $title ?? 'Trang tham chiếu';
$eyebrow = $eyebrow ?? 'Tham chiếu';
$summary = $summary ?? '';
$highlightItems = $highlights ?? [];
$highlights = is_array($highlightItems) ? $highlightItems : [];
?>

<style>
  .policy-shell {
    max-width: 980px;
    margin: 0 auto;
  }

  .policy-hero {
    padding: 34px;
    border-radius: 28px;
    background: linear-gradient(135deg, #0f2238 0%, #184f74 100%);
    color: #fff;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.14);
  }

  .policy-eyebrow {
    display: inline-block;
    margin-bottom: 12px;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: rgba(255,255,255,.78);
  }

  .policy-hero h1 {
    font-size: clamp(30px, 4vw, 42px);
    font-weight: 900;
    line-height: 1.18;
    margin-bottom: 12px;
  }

  .policy-hero p {
    max-width: 760px;
    color: rgba(255,255,255,.86);
    line-height: 1.75;
    margin-bottom: 0;
  }

  .policy-layout {
    display: grid;
    grid-template-columns: 1.2fr .8fr;
    gap: 22px;
    margin-top: 24px;
  }

  .policy-card {
    background: #fff;
    border: 1px solid #e6edf5;
    border-radius: 24px;
    padding: 24px;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.07);
  }

  .policy-card h2 {
    font-size: 1.28rem;
    font-weight: 800;
    margin-bottom: 14px;
    color: #0f172a;
  }

  .policy-points {
    display: grid;
    gap: 12px;
  }

  .policy-point {
    padding: 14px 16px;
    border-radius: 18px;
    background: #f8fbff;
    color: #334155;
    line-height: 1.7;
  }

  .policy-note {
    color: #475569;
    line-height: 1.75;
    margin-bottom: 0;
  }

  .policy-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 18px;
  }

  @media (max-width: 991px) {
    .policy-layout {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="container py-4 py-lg-5">
  <div class="policy-shell">
    <section class="policy-hero">
      <span class="policy-eyebrow"><?= h($eyebrow) ?></span>
      <h1><?= h($title) ?></h1>
      <p><?= h($summary) ?></p>
    </section>

    <div class="policy-layout">
      <section class="policy-card">
        <h2>Nội dung chính</h2>
        <div class="policy-points">
          <?php foreach ($highlights as $item): ?>
            <div class="policy-point"><?= h((string)$item) ?></div>
          <?php endforeach; ?>
        </div>
      </section>

      <aside class="policy-card">
        <h2>Áp dụng tại SkinSyntax</h2>
        <p class="policy-note">Nội dung trên trang này là chính sách do SkinSyntax xây dựng và áp dụng trực tiếp cho website SkinSyntax. Khi tiếp tục đăng ký tài khoản hoặc sử dụng dịch vụ, bạn xác nhận đã đọc và hiểu các nguyên tắc được công bố tại đây.</p>
        <div class="policy-actions">
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?auth=register">Quay lại popup đăng ký</a>
        </div>
      </aside>
    </div>
  </div>
</div>
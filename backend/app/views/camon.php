<?php
$maHoaDon = trim((string)($maHoaDon ?? ''));
?>

<div class="container py-5">
  <div class="thanks-card text-center">
    <div class="icon-wrap"><i class="fa-solid fa-circle-check"></i></div>
    <h2>Đặt hàng thành công</h2>
    <p class="text-muted mb-2">Cảm ơn bạn đã mua sắm tại SkinSyntax.</p>
    <?php if ($maHoaDon !== ''): ?>
      <p class="order-code">Mã đơn hàng: <strong>#<?= h($maHoaDon) ?></strong></p>
    <?php endif; ?>
    <div class="d-flex gap-2 justify-content-center mt-3 flex-wrap">
      <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=tatca">Tiếp tục mua sắm</a>
      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/index.php?r=hoso">Xem lịch sử đơn hàng</a>
    </div>
  </div>
</div>

<style>
  .thanks-card {
    max-width: 620px;
    margin: 0 auto;
    border: 1px solid #e8edf4;
    border-radius: 18px;
    background: #fff;
    padding: 30px 18px;
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.06);
  }

  .icon-wrap {
    width: 74px;
    height: 74px;
    margin: 0 auto 12px;
    border-radius: 50%;
    background: #ecfdf3;
    color: #16a34a;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 34px;
  }

  .order-code {
    font-size: 16px;
    color: #1f2937;
  }
</style>

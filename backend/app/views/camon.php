<?php
$maHoaDon = trim((string)($maHoaDon ?? ''));
$order = $order ?? null;
$paymentMethodLabel = trim((string)($paymentMethodLabel ?? 'Thanh toán khi nhận hàng (COD)'));
$transferData = $transferData ?? null;
$autoCheckEnabled = !empty($autoCheckEnabled);
$autoCheckUrl = trim((string)($autoCheckUrl ?? ''));

// Normalize status to check if already paid
$statusRaw = strtolower(trim((string)($order['status_thanh_toan'] ?? $transferData['payment_status'] ?? '')));
$statusNorm = preg_replace('/[\x{0300}-\x{036f}]/u', '', str_replace(['đ', 'Đ'], ['d', 'D'], mb_strtolower($statusRaw, 'UTF-8')));
$isPaid = in_array($statusNorm, ['da thanh toan', 'paid', 'thanh cong'], true);
?>

<div class="container py-5">
  <div class="thanks-card text-center">
    <div class="icon-wrap<?= $isPaid ? ' icon-wrap--success' : '' ?>"><i class="fa-solid fa-circle-check"></i></div>
    <h2><?= $isPaid ? 'Thanh toán &amp; Đặt hàng thành công!' : 'Đặt hàng thành công' ?></h2>
    <p class="text-muted mb-2">Cảm ơn bạn đã mua sắm tại SkinSyntax.</p>
    <?php if ($maHoaDon !== ''): ?>
      <p class="order-code">Mã đơn hàng: <strong>#<?= h($maHoaDon) ?></strong></p>
    <?php endif; ?>
    <?php if ($order): ?>
      <p class="order-meta mb-1">Phương thức thanh toán: <strong><?= h($paymentMethodLabel) ?></strong></p>
      <p class="order-meta text-muted">Tổng thanh toán: <strong><?= vnd($order['tong_tien'] ?? 0) ?></strong></p>
    <?php endif; ?>

    <?php if ($isPaid): ?>
      <!-- SUCCESS PAID STATE BOX -->
      <div class="transfer-card text-center mt-4 p-4" style="background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border: 1.5px solid #6EE7B7; border-radius: 18px;">
        <div class="mb-3 text-success" style="font-size: 48px;">
          <i class="fa-solid fa-shield-check"></i>
        </div>
        <h4 class="fw-bold text-success mb-2">Đã nhận tiền chuyển khoản thành công!</h4>
        <p class="text-muted small mb-4">Trạng thái thanh toán: <span class="badge bg-success px-3 py-2 rounded-pill fs-6">Đã thanh toán</span></p>
        <div class="d-flex gap-2 justify-content-center flex-wrap">
          <a class="btn btn-success fw-bold px-4 py-2 rounded-3" href="<?= BASE_URL ?>/index.php?r=hoso" style="background: #059669; border: none;">
            <i class="fa-solid fa-receipt me-2"></i>Xem chi tiết đơn hàng
          </a>
          <a class="btn btn-outline-success fw-bold px-4 py-2 rounded-3" href="<?= BASE_URL ?>/index.php">
            <i class="fa-solid fa-house me-2"></i>Về trang chủ
          </a>
        </div>
      </div>
    <?php elseif ($transferData): ?>
      <!-- PENDING QR CODE BOX -->
      <div class="transfer-card text-start mt-4" id="transferCardContainer">
        <div class="transfer-card__head">
          <div>
            <h5 class="mb-1">Quét QR để chuyển khoản</h5>
            <div class="text-muted small">Chuyển đúng số tiền và nội dung bên dưới để hệ thống xác nhận tự động.</div>
          </div>
          <span class="transfer-status" id="transferStatusBadge"><?= h($transferData['payment_status'] ?? 'Chờ chuyển khoản') ?></span>
        </div>

        <div class="transfer-card__grid">
          <div class="transfer-card__qr">
            <img src="<?= h($transferData['qr_url'] ?? '') ?>" alt="QR chuyển khoản đơn hàng <?= h($maHoaDon) ?>">
          </div>
          <div class="transfer-card__info">
            <div><strong>Ngân hàng:</strong> <?= h($transferData['bank_name'] ?? '') ?></div>
            <div><strong>Số tài khoản:</strong> <?= h($transferData['account_no'] ?? '') ?></div>
            <div><strong>Chủ tài khoản:</strong> <?= h($transferData['account_name'] ?? '') ?></div>
            <div><strong>Số tiền:</strong> <?= vnd($transferData['amount'] ?? 0) ?></div>
            <div><strong>Nội dung:</strong> <span class="badge bg-light text-dark border px-2 py-1 user-select-all"><?= h($transferData['content'] ?? '') ?></span></div>
          </div>
        </div>

        <?php if ($autoCheckEnabled && $autoCheckUrl !== ''): ?>
          <div class="transfer-autocheck" id="transferAutoCheck" data-endpoint="<?= h($autoCheckUrl) ?>" data-interval="8000">
            <i class="fa-solid fa-arrows-rotate fa-spin"></i>
            <span id="transferAutoCheckMessage">Hệ thống đang tự động kiểm tra giao dịch chuyển khoản từ SePay...</span>
          </div>
          <div class="transfer-actions d-flex gap-2 align-items-center mt-3">
            <button type="button" class="btn btn-check-transfer" id="transferManualCheckButton">
              Kiểm tra ngay
            </button>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="d-flex gap-2 justify-content-center mt-4 flex-wrap">
      <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=tatca"><i class="fa-solid fa-cart-shopping me-2"></i>Tiếp tục mua sắm</a>
      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/index.php?r=hoso"><i class="fa-solid fa-clock-rotate-left me-2"></i>Xem lịch sử đơn hàng</a>
      <a class="btn btn-light border" href="<?= BASE_URL ?>/index.php"><i class="fa-solid fa-house me-2"></i>Về trang chủ</a>
    </div>
  </div>
</div>

<style>
  .thanks-card {
    max-width: 650px;
    margin: 0 auto;
    border: 1px solid #e8edf4;
    border-radius: 20px;
    background: #fff;
    padding: 36px 24px;
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.06);
  }

  .icon-wrap {
    width: 76px;
    height: 76px;
    margin: 0 auto 16px;
    border-radius: 50%;
    background: #ecfdf3;
    color: #16a34a;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
  }

  .icon-wrap--success {
    background: #dcfce7;
    color: #059669;
    box-shadow: 0 0 0 8px rgba(16, 185, 129, 0.15);
  }

  .order-code {
    font-size: 16px;
    color: #1f2937;
  }

  .order-meta {
    font-size: 15px;
    color: #1f2937;
  }

  .transfer-card {
    border: 1px solid #d7ebde;
    border-radius: 18px;
    padding: 20px;
    background: linear-gradient(180deg, #f5fff9 0%, #ffffff 100%);
  }

  .transfer-card__head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 14px;
  }

  .transfer-status {
    border-radius: 999px;
    background: #fff0c2;
    color: #8a6100;
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 800;
  }

  .transfer-status--paid {
    background: #dcfce7 !important;
    color: #166534 !important;
  }

  .transfer-card__grid {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 18px;
    align-items: center;
  }

  .transfer-card__qr {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 12px;
    text-align: center;
  }

  .transfer-card__qr img {
    width: 100%;
    max-width: 190px;
    aspect-ratio: 1;
    object-fit: contain;
  }

  .transfer-card__info {
    display: grid;
    gap: 8px;
    color: #334155;
  }

  .transfer-autocheck {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-top: 16px;
    padding: 10px 14px;
    border-radius: 12px;
    background: #f8fafc;
    color: #475569;
    font-size: 14px;
  }

  .transfer-autocheck i {
    color: #0f6b3e;
  }

  .transfer-actions {
    margin-top: 14px;
  }

  .btn-check-transfer {
    border: 1px solid #0f6b3e;
    border-radius: 12px;
    background: #fff;
    color: #0f6b3e;
    font-weight: 700;
    padding: 10px 18px;
    transition: all .2s ease;
  }

  .btn-check-transfer:hover {
    background: #0f6b3e;
    color: #fff;
  }

  .btn-check-transfer:disabled {
    opacity: .7;
    cursor: wait;
  }

  @media (max-width: 767.98px) {
    .transfer-card__grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<?php if ($autoCheckEnabled && $autoCheckUrl !== '' && !$isPaid): ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var autoCheckElement = document.getElementById('transferAutoCheck');
      if (!autoCheckElement) {
        return;
      }

      var endpoint = autoCheckElement.getAttribute('data-endpoint') || '';
      var interval = parseInt(autoCheckElement.getAttribute('data-interval') || '8000', 10);
      var messageElement = document.getElementById('transferAutoCheckMessage');
      var statusBadge = document.getElementById('transferStatusBadge');
      var manualCheckButton = document.getElementById('transferManualCheckButton');
      var container = document.getElementById('transferCardContainer');
      var stopped = false;
      var timerId = null;
      var isChecking = false;
      var reloadScheduled = false;

      var stopPolling = function () {
        stopped = true;
        if (timerId !== null) {
          clearInterval(timerId);
        }
      };

      var setManualButtonState = function (checking) {
        if (!manualCheckButton) {
          return;
        }

        manualCheckButton.disabled = checking;
        manualCheckButton.textContent = checking ? 'Đang kiểm tra...' : 'Kiểm tra ngay';
      };

      var setMessage = function (message) {
        if (messageElement) {
          messageElement.textContent = message;
        }
      };

      var normalizeStr = function(str) {
        if (!str) return '';
        return String(str)
          .toLowerCase()
          .replace(/đ/g, 'd')
          .replace(/Đ/g, 'd')
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .trim();
      };

      var isPaidStatus = function (status) {
        var norm = normalizeStr(status);
        return norm === 'da thanh toan' || norm === 'paid' || norm === 'thanh cong';
      };

      var finalizePaidState = function (message) {
        stopPolling();

        if (statusBadge) {
          statusBadge.classList.add('transfer-status--paid');
          statusBadge.textContent = 'Đã thanh toán';
        }

        if (container) {
          container.innerHTML = 
            '<div class="text-center p-4" style="background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border: 1.5px solid #6EE7B7; border-radius: 18px;">' +
              '<div class="mb-3 text-success" style="font-size: 42px;"><i class="fa-solid fa-shield-check"></i></div>' +
              '<h4 class="fw-bold text-success mb-2">Đã nhận tiền chuyển khoản thành công!</h4>' +
              '<p class="text-muted small mb-3">' + (message || 'Hệ thống đã tự động nhận diện khoản thanh toán của bạn.') + '</p>' +
              '<div class="d-flex gap-2 justify-content-center flex-wrap mt-3">' +
                '<a class="btn btn-success fw-bold px-4 py-2 rounded-3" href="<?= BASE_URL ?>/index.php?r=hoso" style="background: #059669; border: none;">' +
                  '<i class="fa-solid fa-receipt me-2"></i>Xem chi tiết đơn hàng' +
                '</a>' +
                '<a class="btn btn-outline-success fw-bold px-4 py-2 rounded-3" href="<?= BASE_URL ?>/index.php">' +
                  '<i class="fa-solid fa-house me-2"></i>Về trang chủ' +
                '</a>' +
              '</div>' +
            '</div>';
        }

        if (reloadScheduled) {
          return;
        }

        reloadScheduled = true;
        window.setTimeout(function () {
          window.location.reload();
        }, 1500);
      };

      var runCheck = function () {
        if (stopped || endpoint === '' || isChecking) {
          return;
        }

        isChecking = true;
        setManualButtonState(true);

        fetch(endpoint, {
          method: 'GET',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
          },
          credentials: 'same-origin'
        })
          .then(function (response) {
            return response.json();
          })
          .then(function (data) {
            if (!data || typeof data !== 'object') {
              setMessage('Phản hồi kiểm tra thanh toán không hợp lệ.');
              return;
            }

            if (data.payment_status && statusBadge) {
              statusBadge.textContent = data.payment_status;
            }

            if (data.paid || isPaidStatus(data.payment_status)) {
              finalizePaidState(data.message);
              return;
            }

            setMessage(data.message || 'Hệ thống đang tự động kiểm tra giao dịch chuyển khoản từ SePay...');
          })
          .catch(function () {
            setMessage('Tạm thời chưa kết nối được SePay. Hệ thống sẽ tự động thử lại.');
          })
          .finally(function () {
            isChecking = false;
            if (!stopped) {
              setManualButtonState(false);
            }
          });
      };

      if (manualCheckButton) {
        manualCheckButton.addEventListener('click', function () {
          setMessage('Đang kiểm tra giao dịch mới nhất từ SePay...');
          runCheck();
        });
      }

      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
          runCheck();
        }
      });

      window.addEventListener('focus', function () {
        runCheck();
      });

      window.addEventListener('pageshow', function () {
        runCheck();
      });

      runCheck();
      timerId = window.setInterval(runCheck, Math.max(interval, 5000));
    });
  </script>
<?php endif; ?>

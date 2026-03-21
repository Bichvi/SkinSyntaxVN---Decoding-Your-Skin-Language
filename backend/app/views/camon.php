<?php
$maHoaDon = trim((string)($maHoaDon ?? ''));
$order = $order ?? null;
$paymentMethodLabel = trim((string)($paymentMethodLabel ?? 'Thanh toán khi nhận hàng (COD)'));
$transferData = $transferData ?? null;
$autoCheckEnabled = !empty($autoCheckEnabled);
$autoCheckUrl = trim((string)($autoCheckUrl ?? ''));
?>

<div class="container py-5">
  <div class="thanks-card text-center">
    <div class="icon-wrap"><i class="fa-solid fa-circle-check"></i></div>
    <h2>Đặt hàng thành công</h2>
    <p class="text-muted mb-2">Cảm ơn bạn đã mua sắm tại SkinSyntax.</p>
    <?php if ($maHoaDon !== ''): ?>
      <p class="order-code">Mã đơn hàng: <strong>#<?= h($maHoaDon) ?></strong></p>
    <?php endif; ?>
    <?php if ($order): ?>
      <p class="order-meta mb-1">Phương thức thanh toán: <strong><?= h($paymentMethodLabel) ?></strong></p>
      <p class="order-meta text-muted">Tổng thanh toán: <strong><?= vnd($order['tong_tien'] ?? 0) ?></strong></p>
    <?php endif; ?>

    <?php if ($transferData): ?>
      <div class="transfer-card text-start mt-4">
        <div class="transfer-card__head">
          <div>
            <h5 class="mb-1">Quét QR để chuyển khoản</h5>
            <div class="text-muted small">Chuyển đúng số tiền và nội dung bên dưới để nhân viên đối soát nhanh hơn.</div>
          </div>
          <span class="transfer-status<?= strtolower(trim((string)($transferData['payment_status'] ?? ''))) === 'da thanh toan' ? ' transfer-status--paid' : '' ?>" id="transferStatusBadge"><?= h($transferData['payment_status'] ?? 'Cho chuyen khoan') ?></span>
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
            <div><strong>Nội dung:</strong> <?= h($transferData['content'] ?? '') ?></div>
          </div>
        </div>

        <?php if ($autoCheckEnabled && $autoCheckUrl !== ''): ?>
          <div class="transfer-autocheck" id="transferAutoCheck" data-endpoint="<?= h($autoCheckUrl) ?>" data-interval="10000">
            <i class="fa-solid fa-arrows-rotate"></i>
            <span id="transferAutoCheckMessage">Hệ thống đang tự động kiểm tra giao dịch chuyển khoản từ SePay mỗi 10 giây.</span>
          </div>
          <div class="transfer-actions">
            <button type="button" class="btn btn-check-transfer" id="transferManualCheckButton">
              Kiểm tra ngay
            </button>
          </div>
        <?php endif; ?>
      </div>
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

  .order-meta {
    font-size: 15px;
    color: #1f2937;
  }

  .transfer-card {
    border: 1px solid #d7ebde;
    border-radius: 18px;
    padding: 18px;
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
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 800;
  }

  .transfer-status--paid {
    background: #dcfce7;
    color: #166534;
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
    padding: 10px 16px;
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

<?php if ($autoCheckEnabled && $autoCheckUrl !== ''): ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var autoCheckElement = document.getElementById('transferAutoCheck');
      if (!autoCheckElement) {
        return;
      }

      var endpoint = autoCheckElement.getAttribute('data-endpoint') || '';
      var interval = parseInt(autoCheckElement.getAttribute('data-interval') || '10000', 10);
      var messageElement = document.getElementById('transferAutoCheckMessage');
      var statusBadge = document.getElementById('transferStatusBadge');
      var manualCheckButton = document.getElementById('transferManualCheckButton');
      var stopped = false;
      var timerId = null;
      var isChecking = false;

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

            if (data.paid) {
              if (statusBadge) {
                statusBadge.classList.add('transfer-status--paid');
              }
              setMessage(data.message || 'Đã xác nhận thanh toán. Đang cập nhật giao diện...');
              stopPolling();
              window.setTimeout(function () {
                window.location.reload();
              }, 1200);
              return;
            }

            setMessage(data.message || 'Hệ thống đang tiếp tục kiểm tra giao dịch.');
          })
          .catch(function () {
            setMessage('Tạm thời chưa kiểm tra được SePay. Hệ thống sẽ thử lại sau.');
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

      runCheck();
      timerId = window.setInterval(runCheck, Math.max(interval, 5000));
    });
  </script>
<?php endif; ?>

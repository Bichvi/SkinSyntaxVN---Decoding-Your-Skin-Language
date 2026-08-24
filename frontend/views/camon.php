<?php
$maHoaDon = trim((string)($maHoaDon ?? ''));
$order = $order ?? null;
$paymentMethodLabel = trim((string)($paymentMethodLabel ?? 'Thanh toán khi nhận hàng (COD)'));
$transferData = $transferData ?? null;
$autoCheckEnabled = !empty($autoCheckEnabled);
$autoCheckUrl = trim((string)($autoCheckUrl ?? ''));

// Normalize status to check if already paid or failed
$paymentStatusRaw = strtolower(trim((string)($order['payment_status'] ?? '')));
$statusRaw = strtolower(trim((string)($order['status_thanh_toan'] ?? $transferData['payment_status'] ?? '')));
$statusNorm = preg_replace('/[\x{0300}-\x{036f}]/u', '', str_replace(['đ', 'Đ'], ['d', 'D'], mb_strtolower($statusRaw, 'UTF-8')));

$isPaid = in_array($statusNorm, ['da thanh toan', 'paid', 'thanh cong'], true);
$isFailed = in_array($paymentStatusRaw, ['failed', 'cancelled'], true) || in_array($statusNorm, ['thanh toan that bai', 'thanh toan da huy', 'quá hạn thanh toán', 'qua han thanh toan'], true);
$hinhThucRaw = strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? $order['payment_method'] ?? '')));
$isVnpay = ($hinhThucRaw === 'vnpay');
?>

<div class="container py-5">
  <div class="thanks-card text-center">
    <?php if ($isPaid): ?>
      <div class="icon-wrap icon-wrap--success"><i class="fa-solid fa-circle-check"></i></div>
      <h2>Thanh toán &amp; Đặt hàng thành công!</h2>
    <?php elseif ($isVnpay && $isFailed): ?>
      <div class="icon-wrap" style="background: #FEF2F2; color: #DC2626; border-color: #FCA5A5;"><i class="fa-solid fa-circle-xmark"></i></div>
      <h2 class="text-danger">Thanh toán VNPAY chưa hoàn tất</h2>
    <?php else: ?>
      <div class="icon-wrap"><i class="fa-solid fa-circle-check"></i></div>
      <h2>Đặt hàng thành công</h2>
    <?php endif; ?>
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
        <h4 class="fw-bold text-success mb-2">Đã nhận tiền thanh toán thành công!</h4>
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
    <?php elseif ($isVnpay && $isFailed): ?>
      <!-- FAILED VNPAY PAYMENT BOX -->
      <div class="transfer-card text-center mt-4 p-4" style="background: #FEF2F2; border: 1.5px solid #FCA5A5; border-radius: 18px;">
        <div class="mb-3 text-danger" style="font-size: 48px;">
          <i class="fa-solid fa-credit-card"></i>
        </div>
        <h4 class="fw-bold text-danger mb-2">Thanh toán qua VNPAY không thành công</h4>
        <p class="text-muted small mb-2">Đơn hàng <strong>#<?= h($maHoaDon) ?></strong> đã được tạo nhưng chưa hoàn tất thanh toán.</p>
        <p class="mb-4"><span class="badge bg-danger px-3 py-2 rounded-pill fs-6"><?= h($order['status_thanh_toan'] ?? 'Thanh toán thất bại') ?></span></p>
        <div class="d-flex gap-2 justify-content-center flex-wrap">
          <a class="btn btn-danger fw-bold px-4 py-2 rounded-3" href="<?= BASE_URL ?>/index.php?r=vnpay_repay&order_id=<?= urlencode((string)$maHoaDon) ?>" style="background: #DC2626; border: none;">
            <i class="fa-solid fa-rotate-right me-2"></i>Thử thanh toán lại qua VNPAY
          </a>
          <a class="btn btn-outline-secondary fw-bold px-4 py-2 rounded-3" href="<?= BASE_URL ?>/index.php?r=hoso">
            <i class="fa-solid fa-receipt me-2"></i>Xem lịch sử đơn hàng
          </a>
          <a class="btn btn-outline-dark fw-bold px-4 py-2 rounded-3" href="<?= BASE_URL ?>/index.php">
            <i class="fa-solid fa-house me-2"></i>Về trang chủ
          </a>
        </div>
      </div>

    <?php elseif ($transferData): ?>
      <?php
        $supportedBanks = !empty($transferData['supported_banks']) ? $transferData['supported_banks'] : [
          [
            'id' => 'MB',
            'bin' => '970422',
            'name' => ($transferData['bank_name'] ?? 'MB Bank'),
            'short_name' => ($transferData['bank_name'] ?? 'MB Bank'),
            'account_no' => ($transferData['account_no'] ?? ''),
            'account_name' => ($transferData['account_name'] ?? ''),
            'badge' => 'MB',
            'color' => '#001A9E',
            'app_scheme' => 'mbmobile://',
            'qr_url' => ($transferData['qr_url'] ?? ''),
            'is_momo' => false,
          ]
        ];
      ?>
      <!-- PENDING QR CODE BOX -->
      <div class="transfer-card text-start mt-4" id="transferCardContainer">
        <div class="transfer-card__head mb-3">
          <div>
            <h5 class="mb-1 fw-bold text-dark"><i class="fa-solid fa-qrcode me-2 text-success"></i>Quét QR để thanh toán</h5>
            <div class="text-muted small">Mở ứng dụng Ngân hàng bất kỳ hoặc Ví điện tử quét mã VietQR bên dưới để chuyển khoản.</div>
          </div>
          <span class="transfer-status" id="transferStatusBadge"><?= h($transferData['payment_status'] ?? 'Chờ chuyển khoản') ?></span>
        </div>

        <!-- ACTIVE BANK QR DISPLAY & DETAILS -->
        <div class="transfer-card__grid">
          <div class="transfer-card__qr">
            <div class="qr-bank-header text-center mb-2" id="activeBankHeader">
              <span class="badge rounded-pill px-3 py-1 fw-bold fs-6" id="activeBankBadge" style="background-color: <?= h($supportedBanks[0]['color'] ?? '#001A9E') ?>; color: #fff;">
                <i class="fa-solid fa-building-columns me-1"></i><span id="activeBankTitle"><?= h($supportedBanks[0]['short_name'] ?? 'MB Bank') ?></span>
              </span>
            </div>

            <div class="qr-img-wrapper position-relative">
              <img id="qrImageDisplay" src="<?= h($supportedBanks[0]['qr_url'] ?? $transferData['qr_url'] ?? '') ?>" alt="QR chuyển khoản đơn hàng <?= h($maHoaDon) ?>">
            </div>
            <div class="mt-2 text-center">
              <a id="downloadQrBtn" href="<?= h($supportedBanks[0]['qr_url'] ?? $transferData['qr_url'] ?? '') ?>" download="SkinSyntax_QR_<?= h($maHoaDon) ?>.png" target="_blank" class="btn btn-sm btn-outline-secondary w-100 rounded-3">
                <i class="fa-solid fa-download me-1"></i>Tải ảnh QR
              </a>
            </div>
          </div>

          <div class="transfer-card__info">
            <div class="info-row">
              <span class="info-label"><i class="fa-solid fa-university me-1 text-muted"></i>Ngân hàng / Ví:</span>
              <strong class="info-value text-dark" id="selectedBankName"><?= h($supportedBanks[0]['name'] ?? $transferData['bank_name'] ?? '') ?></strong>
            </div>

            <div class="info-row">
              <span class="info-label"><i class="fa-solid fa-credit-card me-1 text-muted"></i>Số tài khoản / SĐT:</span>
              <div class="d-flex align-items-center gap-2">
                <strong class="info-value text-primary fs-6" id="selectedAccountNo"><?= h($supportedBanks[0]['account_no'] ?? $transferData['account_no'] ?? '') ?></strong>
                <button type="button" class="btn btn-sm btn-light border py-0 px-2 rounded-2 btn-copy" data-copy="<?= h($supportedBanks[0]['account_no'] ?? $transferData['account_no'] ?? '') ?>" title="Sao chép số tài khoản">
                  <i class="fa-regular fa-copy me-1"></i>Copy
                </button>
              </div>
            </div>

            <div class="info-row">
              <span class="info-label"><i class="fa-solid fa-user me-1 text-muted"></i>Chủ tài khoản:</span>
              <strong class="info-value text-dark" id="selectedAccountName"><?= h($supportedBanks[0]['account_name'] ?? $transferData['account_name'] ?? '') ?></strong>
            </div>

            <div class="info-row">
              <span class="info-label"><i class="fa-solid fa-money-bill-wave me-1 text-muted"></i>Số tiền chuyển:</span>
              <div class="d-flex align-items-center gap-2">
                <strong class="info-value text-danger fs-5"><?= vnd($transferData['amount'] ?? 0) ?></strong>
                <button type="button" class="btn btn-sm btn-light border py-0 px-2 rounded-2 btn-copy" data-copy="<?= (int)($transferData['amount'] ?? 0) ?>" title="Sao chép số tiền">
                  <i class="fa-regular fa-copy me-1"></i>Copy
                </button>
              </div>
            </div>

            <div class="info-row">
              <span class="info-label"><i class="fa-solid fa-comment-dots me-1 text-muted"></i>Nội dung chuyển khoản:</span>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-warning text-dark border border-warning px-3 py-2 fs-6 user-select-all fw-bold" id="selectedContent"><?= h($transferData['content'] ?? '') ?></span>
                <button type="button" class="btn btn-sm btn-warning fw-bold py-1 px-2 rounded-2 btn-copy" data-copy="<?= h($transferData['content'] ?? '') ?>" title="Sao chép nội dung">
                  <i class="fa-solid fa-copy me-1"></i>Sao chép
                </button>
              </div>
            </div>

          </div>
        </div>

        <?php if ($autoCheckEnabled && $autoCheckUrl !== ''): ?>
          <div class="transfer-autocheck mt-3" id="transferAutoCheck" data-endpoint="<?= h($autoCheckUrl) ?>" data-interval="8000">
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

  .bank-selector-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    max-height: 150px;
    overflow-y: auto;
    padding: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
  }

  .bank-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
  }

  .bank-pill:hover {
    border-color: var(--bank-color, #0f6b3e);
    background: #f1f5f9;
  }

  .bank-pill.active {
    border-color: var(--bank-color, #0f6b3e);
    background: var(--bank-color, #0f6b3e);
    color: #ffffff;
    box-shadow: 0 4px 10px rgba(0,0,0,0.12);
  }

  .bank-pill__badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 6px;
    background: rgba(0,0,0,0.06);
    font-size: 11px;
    font-weight: 800;
  }

  .bank-pill.active .bank-pill__badge {
    background: rgba(255,255,255,0.25);
    color: #ffffff;
  }

  .bank-pill--momo.active {
    background: #A50064 !important;
    border-color: #A50064 !important;
  }

  .style-momo-tag {
    font-size: 9px;
    padding: 2px 4px;
    border-radius: 4px;
  }

  .info-row {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 14px;
    padding-bottom: 8px;
    border-bottom: 1px dashed #e2e8f0;
  }

  .info-row:last-child {
    border-bottom: none;
  }

  .info-label {
    color: #64748b;
    font-size: 13px;
  }

  .copy-toast-notification {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #0f172a;
    color: #ffffff;
    padding: 12px 20px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    z-index: 99999;
    opacity: 0;
    transform: translateY(20px);
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .copy-toast-notification.show {
    opacity: 1;
    transform: translateY(0);
  }

  #qrImageDisplay {
    transition: opacity 0.2s ease-in-out;
  }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    // Copy buttons logic
    var copyButtons = document.querySelectorAll('.btn-copy');
    copyButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy') || '';
        if (!text) return;

        var showToast = function(msg) {
          var toast = document.createElement('div');
          toast.className = 'copy-toast-notification';
          toast.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>' + msg;
          document.body.appendChild(toast);
          setTimeout(function () { toast.classList.add('show'); }, 10);
          setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () {
              if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 300);
          }, 2500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () {
            showToast('Đã sao chép: ' + text);
          }).catch(function () {
            fallbackCopy(text, showToast);
          });
        } else {
          fallbackCopy(text, showToast);
        }
      });
    });

    function fallbackCopy(text, cb) {
      var dummy = document.createElement('textarea');
      document.body.appendChild(dummy);
      dummy.value = text;
      dummy.select();
      document.execCommand('copy');
      document.body.removeChild(dummy);
      if (cb) cb('Đã sao chép: ' + text);
    }

    // Bank Selection logic
    var bankPills = document.querySelectorAll('.bank-pill');
    var qrImage = document.getElementById('qrImageDisplay');
    var downloadQrBtn = document.getElementById('downloadQrBtn');
    var activeBankTitle = document.getElementById('activeBankTitle');
    var activeBankBadge = document.getElementById('activeBankBadge');
    var selectedBankName = document.getElementById('selectedBankName');
    var selectedAccountNo = document.getElementById('selectedAccountNo');
    var selectedAccountName = document.getElementById('selectedAccountName');
    var openBankAppBtn = document.getElementById('openBankAppBtn');
    var openBankAppName = document.getElementById('openBankAppName');

    bankPills.forEach(function (pill) {
      pill.addEventListener('click', function () {
        bankPills.forEach(function (p) { p.classList.remove('active'); });
        pill.classList.add('active');

        var qrUrl = pill.getAttribute('data-qr-url') || '';
        var bankName = pill.getAttribute('data-bank-name') || '';
        var bankShort = pill.getAttribute('data-bank-short') || '';
        var accountNo = pill.getAttribute('data-account-no') || '';
        var accountName = pill.getAttribute('data-account-name') || '';
        var color = pill.getAttribute('data-color') || '#001A9E';
        var appScheme = pill.getAttribute('data-app-scheme') || '#';

        if (qrImage) {
          qrImage.style.opacity = '0.3';
          qrImage.src = qrUrl;
          setTimeout(function() { qrImage.style.opacity = '1'; }, 180);
        }

        if (downloadQrBtn) downloadQrBtn.href = qrUrl;
        if (activeBankTitle) activeBankTitle.textContent = bankShort;
        if (activeBankBadge) activeBankBadge.style.backgroundColor = color;
        if (selectedBankName) selectedBankName.textContent = bankName;
        if (selectedAccountNo) selectedAccountNo.textContent = accountNo;
        if (selectedAccountName) selectedAccountName.textContent = accountName;
        if (openBankAppName) openBankAppName.textContent = bankShort;
        if (openBankAppBtn) openBankAppBtn.href = appScheme;

        // Update copy button data-copy for account number
        if (selectedAccountNo) {
          var copyAccBtn = selectedAccountNo.nextElementSibling;
          if (copyAccBtn && copyAccBtn.classList.contains('btn-copy')) {
            copyAccBtn.setAttribute('data-copy', accountNo);
          }
        }
      });
    });
  });
</script>

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
            setMessage('Tạm thời chưa kết nối được. Hệ thống sẽ tự động thử lại.');
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

<?php
$order = is_array($order ?? null) ? $order : [];
$documentType = (string)($documentType ?? 'invoice');
$isInvoice = $documentType === 'invoice';
$items = is_array($order['items'] ?? null) ? $order['items'] : [];
$formatDate = static function ($value): string {
    if ($value instanceof \MongoDB\BSON\UTCDateTime) {
        return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
    }
    $text = trim((string)($value ?? ''));
    if ($text === '' || $text === '0') return 'Chưa có ngày đặt';
    $timestamp = strtotime($text);
    return ($timestamp && $timestamp > 0) ? date('d/m/Y H:i', $timestamp) : 'Chưa có ngày đặt';
};
$paymentMethod = strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod')));
$paymentText = $paymentMethod === 'bank_transfer_qr' ? 'Chuyển khoản qua QR' : 'COD';
$paymentStatus = (string)($order['status_thanh_toan'] ?? 'Chưa thanh toán');
$paid = in_array(strtolower(trim($paymentStatus)), ['da thanh toan', 'đã thanh toán', 'paid', 'thanh cong'], true);
$collectAmount = ($paymentMethod === 'cod' && !$paid) ? (int)($order['tong_tien'] ?? 0) : 0;
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($isInvoice ? 'Hóa đơn bán hàng' : 'Phiếu giao hàng') ?> #<?= h($order['ma_hoa_don'] ?? '') ?></title>
    <style>
        body { margin: 0; background: #edf4f2; color: #17272b; font-family: Arial, sans-serif; }
        .toolbar { max-width: 210mm; margin: 18px auto; display: flex; justify-content: flex-end; gap: 10px; }
        .toolbar button { border: 0; border-radius: 10px; padding: 10px 18px; background: #108764; color: #fff; font-weight: 700; cursor: pointer; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto 24px; background: #fff; box-sizing: border-box; padding: 18mm; box-shadow: 0 18px 48px rgba(15, 23, 42, .12); }
        .header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 3px solid #108764; padding-bottom: 16px; margin-bottom: 22px; }
        .brand { font-size: 24px; font-weight: 800; color: #0f3d56; }
        .subtitle { color: #64748b; margin-top: 4px; }
        .doc-title { text-align: right; font-size: 24px; font-weight: 800; color: #108764; text-transform: uppercase; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .box { border: 1px solid #dce8e3; border-radius: 12px; padding: 14px; }
        .box h3 { margin: 0 0 10px; font-size: 14px; color: #0f3d56; text-transform: uppercase; letter-spacing: .02em; }
        .line { display: flex; justify-content: space-between; gap: 16px; margin: 6px 0; }
        .line span:first-child { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #e8f5f0; color: #0f3d56; text-align: left; }
        th, td { border: 1px solid #dce8e3; padding: 10px; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-left: auto; width: 48%; margin-top: 18px; }
        .totals .grand { font-size: 18px; color: #d33b30; font-weight: 800; }
        .note { margin-top: 28px; padding: 14px; background: #f8fafc; border-radius: 12px; color: #475569; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-top: 54px; text-align: center; }
        .signature-box { min-height: 110px; border-top: 1px dashed #94a3b8; padding-top: 12px; font-weight: 700; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">In</button>
    </div>
    <main class="sheet">
        <section class="header">
            <div>
                <div class="brand">SkinSyntaxVN</div>
                <div class="subtitle">SkinSyntax - Decoding Your Skin Language</div>
            </div>
            <div class="doc-title"><?= $isInvoice ? 'Hóa đơn bán hàng' : 'Phiếu giao hàng' ?></div>
        </section>

        <section class="meta-grid">
            <div class="box">
                <h3>Thông tin đơn hàng</h3>
                <div class="line"><span>Mã đơn hàng</span><strong>#<?= h($order['ma_hoa_don'] ?? '') ?></strong></div>
                <div class="line"><span>Ngày đặt</span><strong><?= h($formatDate($order['ngay_dat'] ?? $order['ngay_dat_hien_thi'] ?? null)) ?></strong></div>
                <div class="line"><span>Phương thức thanh toán</span><strong><?= h($paymentText) ?></strong></div>
                <div class="line"><span>Trạng thái thanh toán</span><strong><?= h($paymentStatus) ?></strong></div>
            </div>
            <div class="box">
                <h3><?= $isInvoice ? 'Khách hàng' : 'Người nhận' ?></h3>
                <div class="line"><span>Họ tên</span><strong><?= h($order['ten_nguoi_nhan'] ?? $order['ho_ten'] ?? '') ?></strong></div>
                <?php if ($isInvoice): ?>
                    <div class="line"><span>Email</span><strong><?= h($order['email'] ?? '') ?></strong></div>
                <?php endif; ?>
                <div class="line"><span>Số điện thoại</span><strong><?= h($order['sdt_nguoi_nhan'] ?? $order['so_dien_thoai'] ?? '') ?></strong></div>
                <div><span style="color:#64748b">Địa chỉ giao hàng</span><br><strong><?= h($order['dia_chi_giao_hang'] ?? '') ?></strong></div>
            </div>
        </section>

        <?php if ($isInvoice): ?>
            <table>
                <thead>
                    <tr><th style="width:48px">STT</th><th>Tên sản phẩm</th><th class="num">Số lượng</th><th class="num">Đơn giá</th><th class="num">Thành tiền</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $index => $item): ?>
                        <?php $qty = max(1, (int)($item['so_luong'] ?? 1)); $unit = (int)($item['don_gia'] ?? 0); ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= h($item['ten_san_pham'] ?? ('SP #' . ($item['ma_san_pham'] ?? ''))) ?></td>
                            <td class="num"><?= number_format($qty, 0, ',', '.') ?></td>
                            <td class="num"><?= vnd($unit) ?></td>
                            <td class="num"><?= vnd($item['thanh_tien'] ?? ($qty * $unit)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <section class="totals">
                <div class="line"><span>Tạm tính</span><strong><?= vnd($order['tam_tinh'] ?? 0) ?></strong></div>
                <div class="line"><span>Giảm voucher</span><strong>-<?= vnd($order['so_tien_giam'] ?? 0) ?></strong></div>
                <div class="line"><span>Giảm bằng điểm</span><strong>-<?= vnd($order['tien_giam_diem'] ?? 0) ?></strong></div>
                <div class="line"><span>Phí vận chuyển</span><strong><?= vnd($order['phi_van_chuyen'] ?? 0) ?></strong></div>
                <div class="line grand"><span>Tổng thanh toán</span><strong><?= vnd($order['tong_tien'] ?? 0) ?></strong></div>
            </section>
            <div class="note">Cảm ơn quý khách đã mua sắm tại SkinSyntax.</div>
        <?php else: ?>
            <div class="box">
                <div class="line"><span>Tổng tiền cần thu</span><strong class="grand"><?= vnd($collectAmount) ?></strong></div>
            </div>
            <table>
                <thead><tr><th>Tên sản phẩm</th><th class="num">Số lượng</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= h($item['ten_san_pham'] ?? ('SP #' . ($item['ma_san_pham'] ?? ''))) ?></td>
                            <td class="num"><?= number_format(max(1, (int)($item['so_luong'] ?? 1)), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <section class="signatures">
                <div class="signature-box">Người giao hàng</div>
                <div class="signature-box">Người nhận hàng</div>
            </section>
        <?php endif; ?>
    </main>
    <script>
        setTimeout(function () { window.print(); }, 350);
    </script>
</body>
</html>

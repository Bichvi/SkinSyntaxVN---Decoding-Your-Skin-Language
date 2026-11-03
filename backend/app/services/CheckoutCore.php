<?php

/**
 * Copy of HomeController::buildCheckoutPreview / getDefaultCheckoutReceiver (owner decision 2026-11-03, HomeController untouched). Keep in sync.
 */
class CheckoutCore
{
    private $db;
    private $productModel;
    private $voucherModel;
    private $customerVoucherModel;
    private $effectivePrice;

    public function __construct(
        $db,
        $productModel,
        $voucherModel,
        $customerVoucherModel,
        callable $effectivePrice
    ) {
        $this->db = $db;
        $this->productModel = $productModel;
        $this->voucherModel = $voucherModel;
        $this->customerVoucherModel = $customerVoucherModel;
        $this->effectivePrice = $effectivePrice;
    }

    public function preview(array $checkoutItems): array
    {
        $items = [];
        $subtotal = 0;
        $stockErrors = [];

        foreach ($checkoutItems as $productId => $qty) {
            $product = $this->productModel->findById((string)$productId);
            if (!$product) {
                continue;
            }

            $qty = max(1, (int)$qty);
            $stock = method_exists($this->productModel, 'getProductStock')
                ? $this->productModel->getProductStock($product)
                : null;
            $available = method_exists($this->productModel, 'isProductAvailable')
                ? $this->productModel->isProductAvailable($product)
                : true;
            if (!$available || ($stock !== null && $qty > $stock)) {
                $stockErrors[] = 'Sản phẩm ' . (string)($product['ten_san_pham'] ?? ('#' . $productId))
                    . ' chỉ còn ' . (int)($stock ?? 0) . ' sản phẩm trong kho.';
                continue;
            }

            $pricing = ($this->effectivePrice)((string)$productId, $product);
            $unitPrice = (int)$pricing;
            if (is_array($pricing)) {
                $unitPrice = (int)($pricing['effective_price'] ?? 0);
            }

            $lineTotal = $unitPrice * $qty;
            $subtotal += $lineTotal;
            $items[] = [
                'id' => (string)$productId,
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'pricing' => $pricing,
            ];
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'stock_errors' => $stockErrors,
        ];
    }

    public function defaultReceiver(?array $customer, array $user): array
    {
        $customer = $customer ?? [];

        return [
            'ten_nguoi_nhan' => trim((string)($customer['ho_ten'] ?? ($user['ho_ten'] ?? ''))),
            'sdt_nguoi_nhan' => trim((string)($customer['so_dien_thoai'] ?? '')),
            'dia_chi_giao_hang' => trim((string)($customer['dia_chi'] ?? '')),
        ];
    }

    public function bestVoucher(string $customerId, int $subtotal): ?array
    {
        $best = null;
        $bestDiscount = -1;
        $bestExpiry = PHP_INT_MAX;
        $entitlements = $this->customerVoucherModel->getCustomerVouchers($customerId, 'AVAILABLE');

        foreach ($entitlements as $entitlement) {
            if (!is_array($entitlement)) {
                continue;
            }
            $code = trim((string)($entitlement['voucher_code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $result = $this->voucherModel->validateForCheckout($code, $subtotal);
            if (empty($result['ok'])) {
                continue;
            }

            $discount = (int)($result['discount'] ?? 0);
            $expiry = $this->voucherExpiry($result, $entitlement);
            if ($discount > $bestDiscount || ($discount === $bestDiscount && $expiry < $bestExpiry)) {
                $best = $result;
                $bestDiscount = $discount;
                $bestExpiry = $expiry;
            }
        }

        return $best;
    }

    private function voucherExpiry(array $result, array $entitlement): int
    {
        $voucher = is_array($result['voucher'] ?? null) ? $result['voucher'] : [];
        $raw = $voucher['ngay_ket_thuc'] ?? ($entitlement['end_date'] ?? null);
        if ($raw === null || trim((string)$raw) === '') {
            return PHP_INT_MAX;
        }

        $timestamp = strtotime((string)$raw);
        return $timestamp === false ? PHP_INT_MAX : $timestamp;
    }
}

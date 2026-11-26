<?php
declare(strict_types=1);

require_once __DIR__ . '/CustomerCartStore.php';
require_once __DIR__ . '/../models/SanPham.php';

/** Bridges the legacy PHP session cart to the account cart for website requests. */
final class AccountCartBridge
{
    private const OWNER_SESSION_KEY = '_ss_account_cart_owner';
    private const BASELINE_SESSION_KEY = '_ss_account_cart_baseline';

    public static function begin($db): ?array
    {
        if (!function_exists('is_logged_in') || !is_logged_in()
            || !function_exists('current_role') || current_role() !== 'khach_hang') {
            return null;
        }

        $user = (array)(function_exists('current_user') ? current_user() : []);
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') return null;

        try {
            $customer = $db->khach_hang->findOne([
                'email' => new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i'),
            ]);
            $customerId = (int)(((array)$customer)['ma_kh'] ?? 0);
            if ($customerId <= 0) return null;

            $store = new CustomerCartStore($db);
            $ownerId = (int)($_SESSION[self::OWNER_SESSION_KEY] ?? 0);
            $sessionCart = self::normalizeQuantities($_SESSION['gio_hang'] ?? []);

            if ($ownerId === 0) {
                $store->merge($customerId, $sessionCart, fn(string $id): int => self::stockForProduct($db, $id));
            }

            $accountCart = self::normalizeQuantities($store->get($customerId)['items']);
            if ($ownerId !== $customerId || $sessionCart !== $accountCart) {
                unset($_SESSION['live_cart_offers']);
            }
            $_SESSION['gio_hang'] = $accountCart;
            $_SESSION[self::OWNER_SESSION_KEY] = $customerId;
            $_SESSION[self::BASELINE_SESSION_KEY] = $accountCart;

            $context = ['customer_id' => $customerId, 'before' => $accountCart];
            register_shutdown_function(static function () use ($db, $context): void {
                self::commit($db, $context);
            });
            return $context;
        } catch (Throwable $error) {
            error_log('Account cart bridge unavailable: ' . get_class($error));
            return null;
        }
    }

    /** Capture a guest cart before the existing website login handler redirects and exits. */
    public static function attachLoginMerge($db): void
    {
        $guestCart = self::normalizeQuantities($_SESSION['gio_hang'] ?? []);
        $previousOwner = (int)($_SESSION[self::OWNER_SESSION_KEY] ?? 0);
        $hasPreviousBaseline = isset($_SESSION[self::BASELINE_SESSION_KEY]) && is_array($_SESSION[self::BASELINE_SESSION_KEY]);
        $previousBaseline = self::normalizeQuantities($_SESSION[self::BASELINE_SESSION_KEY] ?? []);

        register_shutdown_function(static function () use ($db, $guestCart, $previousOwner, $previousBaseline, $hasPreviousBaseline): void {
            try {
                if (!function_exists('is_logged_in') || !is_logged_in()
                    || !function_exists('current_role') || current_role() !== 'khach_hang') {
                    return;
                }
                $user = (array)(function_exists('current_user') ? current_user() : []);
                $email = trim((string)($user['email'] ?? ''));
                if ($email === '') return;

                $customer = $db->khach_hang->findOne([
                    'email' => new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i'),
                ]);
                $customerId = (int)(((array)$customer)['ma_kh'] ?? 0);
                if ($customerId <= 0) return;

                $store = new CustomerCartStore($db);
                if ($previousOwner === 0) {
                    $store->merge($customerId, $guestCart, fn(string $id): int => self::stockForProduct($db, $id));
                } elseif ($previousOwner === $customerId && $hasPreviousBaseline) {
                    self::mergeSessionDelta($db, $store, $customerId, $previousBaseline, $guestCart);
                }
                $accountCart = self::normalizeQuantities($store->get($customerId)['items']);
                $_SESSION['gio_hang'] = $accountCart;
                $_SESSION[self::OWNER_SESSION_KEY] = $customerId;
                $_SESSION[self::BASELINE_SESSION_KEY] = $accountCart;
            } catch (Throwable $error) {
                error_log('Account cart login merge unavailable: ' . get_class($error));
            }
        });
    }

    private static function commit($db, array $context): void
    {
        try {
            if (!function_exists('is_logged_in') || !is_logged_in()
                || !function_exists('current_role') || current_role() !== 'khach_hang') {
                return;
            }
            $user = (array)(function_exists('current_user') ? current_user() : []);
            $email = trim((string)($user['email'] ?? ''));
            $customer = $email === '' ? null : $db->khach_hang->findOne([
                'email' => new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i'),
            ]);
            $customerId = (int)(((array)$customer)['ma_kh'] ?? 0);
            if ($customerId <= 0 || $customerId !== (int)$context['customer_id']) return;

            $before = self::normalizeQuantities($context['before'] ?? []);
            $after = self::normalizeQuantities($_SESSION['gio_hang'] ?? []);
            $store = new CustomerCartStore($db);
            $allIds = array_unique(array_merge(array_keys($before), array_keys($after)));

            foreach ($allIds as $productId) {
                $oldQuantity = (int)($before[$productId] ?? 0);
                $newQuantity = (int)($after[$productId] ?? 0);
                if ($oldQuantity === $newQuantity) continue;
                if ($newQuantity <= 0) {
                    $store->remove($customerId, (string)$productId);
                    continue;
                }

                $stock = self::stockForProduct($db, (string)$productId);
                if ($stock <= 0) {
                    $store->remove($customerId, (string)$productId);
                    continue;
                }
                $currentQuantity = (int)($store->get($customerId)['items'][$productId] ?? 0);
                $mergedQuantity = max(0, min($stock, $currentQuantity + ($newQuantity - $oldQuantity)));
                if ($mergedQuantity <= 0) $store->remove($customerId, (string)$productId);
                else $store->setQuantity($customerId, (string)$productId, $mergedQuantity, $stock);
            }

            $_SESSION['gio_hang'] = $store->get($customerId)['items'];
            $_SESSION[self::BASELINE_SESSION_KEY] = self::normalizeQuantities($_SESSION['gio_hang']);
        } catch (Throwable $error) {
            error_log('Account cart bridge save unavailable: ' . get_class($error));
        }
    }

    private static function stockForProduct($db, string $productId): int
    {
        $model = new SanPham($db);
        $product = $model->findById($productId, true);
        if (!$product || !$model->isProductAvailable($product)) return 0;
        return max(0, (int)($model->getProductStock((array)$product) ?? 300));
    }

    /** Apply only changes made while logged out, so an account cart isn't merged into itself twice. */
    private static function mergeSessionDelta($db, CustomerCartStore $store, int $customerId, array $baseline, array $guestCart): void
    {
        $allIds = array_unique(array_merge(array_keys($baseline), array_keys($guestCart)));
        foreach ($allIds as $productId) {
            $delta = (int)($guestCart[$productId] ?? 0) - (int)($baseline[$productId] ?? 0);
            if ($delta === 0) continue;

            $stock = self::stockForProduct($db, (string)$productId);
            $current = (int)($store->get($customerId)['items'][$productId] ?? 0);
            if ($stock <= 0) {
                $store->remove($customerId, (string)$productId);
                continue;
            }
            $next = max(0, min($stock, $current + $delta));
            if ($next === 0) $store->remove($customerId, (string)$productId);
            else $store->setQuantity($customerId, (string)$productId, $next, $stock);
        }
    }

    private static function normalizeQuantities($cart): array
    {
        if (!is_array($cart)) return [];
        $normalized = [];
        foreach ($cart as $productId => $quantity) {
            $productId = trim((string)$productId);
            $quantity = max(0, (int)$quantity);
            if ($productId !== '' && $quantity > 0) {
                $normalized[$productId] = $quantity;
            }
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }
}

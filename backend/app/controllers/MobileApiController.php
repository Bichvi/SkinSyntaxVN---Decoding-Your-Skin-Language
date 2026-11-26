<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/NguoiDung.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/ProductFavorite.php';
require_once __DIR__ . '/../services/MobileSessionStore.php';
require_once __DIR__ . '/../services/CustomerCartStore.php';
require_once __DIR__ . '/../services/AuthThrottle.php';

/** Bearer-authenticated JSON API for customer-facing native app flows. */
final class MobileApiController
{
    private const METHODS = [
        'auth.login' => ['POST'],
        'auth.me' => ['GET'],
        'auth.logout' => ['POST'],
        'catalog.product' => ['GET'],
        'favorites.list' => ['GET'],
        'favorites.set' => ['PUT'],
        'cart.get' => ['GET'],
        'cart.add' => ['POST'],
        'cart.set' => ['PUT'],
        'cart.remove' => ['DELETE'],
        'cart.clear' => ['DELETE'],
        'account.profile' => ['GET', 'PATCH'],
        'account.addresses' => ['GET', 'POST', 'PATCH', 'DELETE'],
        'account.skin-profile' => ['GET', 'PATCH', 'POST'],
        'account.orders' => ['GET'],
        'account.loyalty' => ['GET'],
        'account.vouchers' => ['GET'],
    ];

    private $db;
    private NguoiDung $users;
    private TaiKhoan $accounts;
    private SanPham $products;
    private MobileSessionStore $sessions;
    private CustomerCartStore $carts;
    private ?array $identity = null;

    public function __construct($db)
    {
        $this->db = $db;
        $this->users = new NguoiDung($db);
        $this->accounts = new TaiKhoan($db);
        $this->products = new SanPham($db);
        $this->sessions = new MobileSessionStore($db);
        $this->carts = new CustomerCartStore($db);
    }

    public function dispatch(): void
    {
        $action = trim((string)($_GET['action'] ?? ''));
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!isset(self::METHODS[$action])) {
            $this->json(['ok' => false, 'message' => 'API route không tồn tại.'], 404);
        }
        if (!in_array($method, self::METHODS[$action], true)) {
            $this->json(['ok' => false, 'message' => 'Phương thức không được hỗ trợ.'], 405, [
                'Allow' => implode(', ', self::METHODS[$action]),
            ]);
        }

        $input = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? $this->readInput() : [];
        try {
            switch ($action) {
                case 'auth.login': $this->login($input);
                case 'auth.me': $this->json(['ok' => true, 'user' => $this->publicUser($this->requireCustomer())]);
                case 'auth.logout': $this->logout();
                case 'catalog.product': $this->productDetail();
                case 'favorites.list': $this->favoriteList();
                case 'favorites.set': $this->favoriteSet($input);
                case 'cart.get': $this->json(['ok' => true, 'cart' => $this->cartPayload($this->requireCustomer())]);
                case 'cart.add': $this->cartAdd($input);
                case 'cart.set': $this->cartSet($input);
                case 'cart.remove': $this->cartRemove($input);
                case 'cart.clear': $this->cartClear();
                case 'account.profile': $this->profile($method, $input);
                case 'account.addresses': $this->addresses($method, $input);
                case 'account.skin-profile': $this->skinProfile($method, $input);
                case 'account.orders': $this->orders();
                case 'account.loyalty': $this->loyalty();
                case 'account.vouchers': $this->vouchers();
            }
        } catch (Throwable $error) {
            error_log('Mobile API request failed: ' . get_class($error));
            $this->json(['ok' => false, 'message' => 'Máy chủ chưa thể xử lý yêu cầu. Vui lòng thử lại.'], 503);
        }
    }

    private function login(array $input): void
    {
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $password === '' || strlen($password) > 128) {
            $this->json(['ok' => false, 'message' => 'Email hoặc mật khẩu không hợp lệ.'], 422);
        }

        $throttle = AuthThrottle::check('login', $email);
        if (empty($throttle['allowed'])) {
            $this->json([
                'ok' => false,
                'message' => AuthThrottle::message((int)($throttle['retry_after'] ?? 0)),
                'retry_after' => (int)($throttle['retry_after'] ?? 0),
            ], 429);
        }

        // Match website account precedence. Staff identities never receive customer API tokens.
        $staff = $this->users->timNhanVienTheoEmail($email);
        $account = $staff ? null : $this->users->timTheoEmail($email);
        if (!$account || empty($account['mat_khau']) || !password_verify($password, (string)$account['mat_khau'])) {
            $this->json(['ok' => false, 'message' => 'Email hoặc mật khẩu không đúng.'], 401);
        }

        $customer = $this->accounts->getKhachHangByEmail($email);
        $customerId = (int)($customer['ma_kh'] ?? 0);
        if (!$customer || $customerId <= 0) {
            $this->json(['ok' => false, 'message' => 'Tài khoản khách hàng chưa sẵn sàng để đăng nhập ứng dụng.'], 403);
        }

        $issued = $this->sessions->issue($customerId);
        $this->json([
            'ok' => true,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'user' => $this->publicUser($customer),
        ]);
    }

    private function logout(): void
    {
        $token = $this->bearerToken();
        if ($token === '') {
            $this->json(['ok' => false, 'message' => 'Phiên đăng nhập không hợp lệ.'], 401);
        }
        if (!$this->sessions->revoke($token)) {
            $this->json(['ok' => false, 'message' => 'Không thể thu hồi phiên đăng nhập.'], 401);
        }
        $this->json(['ok' => true]);
    }

    private function requireCustomer(): array
    {
        if ($this->identity !== null) return $this->identity;
        $token = $this->bearerToken();
        $customerId = $token === '' ? null : $this->sessions->customerIdForToken($token);
        if (!$customerId) {
            $this->json(['ok' => false, 'code' => 'authentication_required', 'message' => 'Vui lòng đăng nhập lại.'], 401);
        }

        $row = $this->db->khach_hang->findOne(['ma_kh' => $customerId]);
        if (!$row) {
            $this->json(['ok' => false, 'code' => 'authentication_required', 'message' => 'Vui lòng đăng nhập lại.'], 401);
        }
        $this->identity = (array)$row;
        return $this->identity;
    }

    private function bearerToken(): string
    {
        $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if ($authorization === '' && function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $name => $value) {
                if (strtolower((string)$name) === 'authorization') {
                    $authorization = trim((string)$value);
                    break;
                }
            }
        }
        return preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,64})$/iD', $authorization, $match) ? $match[1] : '';
    }

    private function publicUser(array $customer): array
    {
        return [
            'id' => (string)($customer['ma_kh'] ?? ''),
            'ma_kh' => (int)($customer['ma_kh'] ?? 0),
            'ho_ten' => (string)($customer['ho_ten'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'role' => 'khach_hang',
        ];
    }

    private function productDetail(): void
    {
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '' || strlen($id) > 80) {
            $this->json(['ok' => false, 'message' => 'Mã sản phẩm không hợp lệ.'], 422);
        }
        $product = $this->products->findById($id, true);
        if (!$product) $this->json(['ok' => false, 'message' => 'Không tìm thấy sản phẩm.'], 404);
        $this->json(['ok' => true, 'product' => $this->serializeProduct((array)$product, true)]);
    }

    private function favoriteList(): void
    {
        $user = $this->requireCustomer();
        $accountKey = $this->favoriteAccountKey($user);
        if ($accountKey === '') $this->json(['ok' => false, 'message' => 'Không xác định được tài khoản.'], 401);

        $favorites = new ProductFavorite($this->db);
        $ids = $favorites->idsForAccount($accountKey);
        $products = $ids ? $this->products->findVisibleByIds($ids) : [];
        $items = [];
        foreach ($products as $product) $items[] = $this->serializeProduct((array)$product);
        $this->json(['ok' => true, 'product_ids' => $ids, 'items' => $items]);
    }

    private function favoriteSet(array $input): void
    {
        $user = $this->requireCustomer();
        $productId = trim((string)($input['product_id'] ?? ''));
        $enabled = filter_var($input['favorite'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($productId === '' || strlen($productId) > 80 || $enabled === null) {
            $this->json(['ok' => false, 'message' => 'Thông tin yêu thích không hợp lệ.'], 422);
        }
        $product = $this->products->findById($productId, true);
        if (!$product) $this->json(['ok' => false, 'message' => 'Sản phẩm không còn hiển thị.'], 404);

        $accountKey = $this->favoriteAccountKey($user);
        if ($accountKey === '') $this->json(['ok' => false, 'message' => 'Không xác định được tài khoản.'], 401);
        $favorites = new ProductFavorite($this->db);
        // This read preserves the website model's index setup and account-key contract.
        $favorites->idsForAccount($accountKey);
        $collection = $this->db->product_favorites;
        $filter = ['account_key' => $accountKey, 'product_id' => $productId];
        if ($enabled) {
            try {
                $collection->updateOne($filter, ['$setOnInsert' => [
                    'account_key' => $accountKey,
                    'product_id' => $productId,
                    'saved_at' => new \MongoDB\BSON\UTCDateTime(),
                ]], ['upsert' => true]);
            } catch (Throwable $error) {
                if ($collection->findOne($filter) === null) throw $error;
            }
        } else {
            $collection->deleteOne($filter);
        }

        $this->json(['ok' => true, 'favorite' => $enabled, 'product_id' => $productId]);
    }

    private function favoriteAccountKey(array $user): string
    {
        $email = strtolower(trim((string)($user['email'] ?? '')));
        return $email === '' ? '' : 'email:' . $email;
    }

    private function cartAdd(array $input): void
    {
        $user = $this->requireCustomer();
        $productId = trim((string)($input['product_id'] ?? ''));
        $quantity = (int)($input['quantity'] ?? 1);
        if ($productId === '' || strlen($productId) > 80 || $quantity < 1 || $quantity > 300) {
            $this->json(['ok' => false, 'message' => 'Thông tin sản phẩm không hợp lệ.'], 422);
        }
        $product = $this->products->findById($productId, true);
        if (!$product || !$this->products->isProductAvailable($product)) {
            $this->json(['ok' => false, 'message' => 'Sản phẩm hiện không thể mua.'], 409);
        }
        $product = (array)$product;
        $cartProductId = trim((string)($product['ma_san_pham'] ?? $product['id'] ?? $productId));
        $stock = max(0, (int)($this->products->getProductStock($product) ?? 300));
        $key = $this->requestHeader('Idempotency-Key') ?: (string)($input['idempotency_key'] ?? '');
        $outcome = $this->carts->addOnce((int)$user['ma_kh'], $cartProductId, $quantity, $stock, $key);
        if (empty($outcome['ok'])) {
            $status = ($outcome['code'] ?? '') === 'request_in_progress' ? 409
                : (($outcome['code'] ?? '') === 'cart_conflict' ? 409 : 422);
            $this->json($outcome, $status);
        }
        $this->json(['ok' => true, 'duplicate' => !empty($outcome['duplicate']), 'cart' => $this->cartPayload($user)]);
    }

    private function cartSet(array $input): void
    {
        $user = $this->requireCustomer();
        $productId = trim((string)($input['product_id'] ?? ''));
        $quantity = (int)($input['quantity'] ?? 0);
        $product = $productId !== '' ? $this->products->findById($productId, true) : null;
        if (!$product || !$this->products->isProductAvailable($product)) {
            $this->json(['ok' => false, 'message' => 'Sản phẩm không còn khả dụng.'], 404);
        }
        $product = (array)$product;
        $productId = (string)($product['ma_san_pham'] ?? $product['id'] ?? $productId);
        $stock = max(0, (int)($this->products->getProductStock($product) ?? 300));
        $result = $this->carts->setQuantity((int)$user['ma_kh'], $productId, $quantity, $stock);
        if (empty($result['ok'])) $this->json($result, 422);
        $this->json(['ok' => true, 'cart' => $this->cartPayload($user)]);
    }

    private function cartRemove(array $input): void
    {
        $user = $this->requireCustomer();
        $productId = trim((string)($input['product_id'] ?? $_GET['product_id'] ?? ''));
        if ($productId === '' || strlen($productId) > 80) {
            $this->json(['ok' => false, 'message' => 'Mã sản phẩm không hợp lệ.'], 422);
        }
        $this->carts->remove((int)$user['ma_kh'], $productId);
        $this->json(['ok' => true, 'cart' => $this->cartPayload($user)]);
    }

    private function cartClear(): void
    {
        $user = $this->requireCustomer();
        $this->carts->clear((int)$user['ma_kh']);
        $this->json(['ok' => true, 'cart' => $this->cartPayload($user)]);
    }

    private function cartPayload(array $user): array
    {
        $customerId = (int)($user['ma_kh'] ?? 0);
        $cart = $this->carts->get($customerId);
        $items = [];
        $warnings = [];
        $subtotal = 0;

        foreach ($cart['items'] as $productId => $quantity) {
            $product = $this->products->findById((string)$productId, true);
            if (!$product || !$this->products->isProductAvailable($product)) {
                $this->carts->remove($customerId, (string)$productId);
                $warnings[] = ['product_id' => (string)$productId, 'code' => 'unavailable'];
                continue;
            }
            $product = (array)$product;
            $stock = max(0, (int)($this->products->getProductStock($product) ?? 300));
            if ($stock <= 0) {
                $this->carts->remove($customerId, (string)$productId);
                $warnings[] = ['product_id' => (string)$productId, 'code' => 'out_of_stock'];
                continue;
            }
            $quantity = min((int)$quantity, $stock);
            if ($quantity <= 0) {
                $this->carts->remove($customerId, (string)$productId);
                continue;
            }
            if ($quantity !== (int)$cart['items'][$productId]) {
                $this->carts->setQuantity($customerId, (string)$productId, $quantity, $stock);
            }

            $serialized = $this->serializeProduct($product);
            $unitPrice = (int)($product['gia_ban'] ?? 0);
            try {
                require_once __DIR__ . '/../models/FlashSaleCampaign.php';
                $pricing = (new FlashSaleCampaign($this->db))->resolveEffectivePrice((string)$productId, null, $product);
                $unitPrice = max(0, (int)($pricing['effective_price'] ?? $unitPrice));
            } catch (Throwable $error) {
                error_log('Mobile cart price fallback: ' . get_class($error));
            }
            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;
            $items[] = [
                'product_id' => (string)$productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'stock' => $stock,
                'product' => $serialized,
            ];
        }

        $current = $this->carts->get($customerId);
        return [
            'revision' => $current['revision'],
            'items' => $items,
            'item_count' => array_sum(array_column($items, 'quantity')),
            'subtotal' => $subtotal,
            'warnings' => $warnings,
        ];
    }

    private function profile(string $method, array $input): void
    {
        $user = $this->requireCustomer();
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') $this->json(['ok' => false, 'message' => 'Không xác định được tài khoản.'], 401);

        if ($method === 'GET') {
            $this->json(['ok' => true, 'profile' => $this->profilePayload($user)]);
        }

        $name = trim((string)($input['ho_ten'] ?? ''));
        $phone = trim((string)($input['so_dien_thoai'] ?? ''));
        $gender = trim((string)($input['gioi_tinh'] ?? ''));
        $address = trim((string)($input['dia_chi'] ?? ''));
        $birthYear = $input['nam_sinh'] ?? null;
        if ($name === '' || mb_strlen($name) > 120 || mb_strlen($phone) > 24 || mb_strlen($address) > 500) {
            $this->json(['ok' => false, 'message' => 'Vui lòng kiểm tra lại thông tin cá nhân.'], 422);
        }
        if ($phone !== '' && !preg_match('/^(?:0\d{9}|\+84\d{9})$/D', preg_replace('/\s+/u', '', $phone) ?? $phone)) {
            $this->json(['ok' => false, 'message' => 'Số điện thoại không hợp lệ.'], 422);
        }
        if ($birthYear !== null && $birthYear !== '') {
            if (!is_numeric($birthYear) || (int)$birthYear < 1900 || (int)$birthYear > (int)date('Y')) {
                $this->json(['ok' => false, 'message' => 'Năm sinh không hợp lệ.'], 422);
            }
            $birthYear = (int)$birthYear;
        } else {
            $birthYear = null;
        }

        if (!$this->accounts->saveThongTinKhachHang($email, [
            'ho_ten' => $name,
            'so_dien_thoai' => preg_replace('/\s+/u', '', $phone) ?? $phone,
            'gioi_tinh' => $gender,
            'dia_chi' => $address,
            'nam_sinh' => $birthYear,
        ])) {
            $this->json(['ok' => false, 'message' => 'Không thể cập nhật thông tin tài khoản.'], 503);
        }
        $updated = $this->accounts->getKhachHangByEmail($email) ?? $user;
        $this->json(['ok' => true, 'profile' => $this->profilePayload($updated)]);
    }

    private function profilePayload(array $user): array
    {
        $addresses = [];
        foreach ((array)($user['shipping_addresses'] ?? []) as $address) {
            $addresses[] = $this->sanitizeAddress((array)$address);
        }
        return [
            'id' => (string)($user['ma_kh'] ?? ''),
            'ma_kh' => (int)($user['ma_kh'] ?? 0),
            'ho_ten' => (string)($user['ho_ten'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'so_dien_thoai' => (string)($user['so_dien_thoai'] ?? ''),
            'gioi_tinh' => (string)($user['gioi_tinh'] ?? ''),
            'nam_sinh' => $user['nam_sinh'] ?? null,
            'dia_chi' => (string)($user['dia_chi'] ?? ''),
            'shipping_addresses' => $addresses,
        ];
    }

    private function addresses(string $method, array $input): void
    {
        $user = $this->requireCustomer();
        $customerId = (int)($user['ma_kh'] ?? 0);
        if ($method === 'GET') {
            $this->json(['ok' => true, 'addresses' => $this->profilePayload($user)['shipping_addresses']]);
        }

        $addresses = [];
        foreach ((array)($user['shipping_addresses'] ?? []) as $row) $addresses[] = (array)$row;
        if ($method === 'POST') {
            if (count($addresses) >= 10) $this->json(['ok' => false, 'message' => 'Bạn có thể lưu tối đa 10 địa chỉ.'], 422);
            $name = trim((string)($input['receiver_name'] ?? ''));
            $phone = preg_replace('/\s+/u', '', trim((string)($input['phone'] ?? ''))) ?? '';
            $province = trim((string)($input['province'] ?? ''));
            $ward = trim((string)($input['ward'] ?? ''));
            $street = trim((string)($input['street'] ?? ''));
            $type = (string)($input['address_type'] ?? 'home');
            if ($name === '' || $phone === '' || $province === '' || $ward === '' || $street === '') {
                $this->json(['ok' => false, 'message' => 'Vui lòng nhập người nhận, điện thoại và địa chỉ đầy đủ.'], 422);
            }
            if (mb_strlen($name) > 120 || mb_strlen($province) > 120 || mb_strlen($ward) > 120
                || mb_strlen($street) > 300 || !in_array($type, ['home', 'company'], true)) {
                $this->json(['ok' => false, 'message' => 'Một số trường địa chỉ quá dài hoặc không hợp lệ.'], 422);
            }
            if (!preg_match('/^(?:0\d{9}|\+84\d{9})$/D', $phone)) {
                $this->json(['ok' => false, 'message' => 'Số điện thoại phải gồm 10 số hoặc dùng đầu số +84.'], 422);
            }
            $makeDefault = !empty($input['is_default']) || !$addresses;
            if ($makeDefault) {
                foreach ($addresses as &$row) $row['is_default'] = false;
                unset($row);
            }
            $address = [
                'id' => bin2hex(random_bytes(8)),
                'receiver_name' => $name,
                'phone' => $phone,
                'province' => $province,
                'ward' => $ward,
                'street' => $street,
                'address_type' => $type,
                'is_default' => $makeDefault,
                'created_at' => new \MongoDB\BSON\UTCDateTime(),
            ];
            $addresses[] = $address;
            if ($makeDefault) {
                $this->db->khach_hang->updateOne(['ma_kh' => $customerId], ['$set' => [
                    'so_dien_thoai' => $phone,
                    'dia_chi' => implode(', ', [$street, $ward, $province]),
                ]]);
            }
        } elseif ($method === 'PATCH') {
            $addressId = trim((string)($input['address_id'] ?? ''));
            if ($addressId === '' || strlen($addressId) > 40) {
                $this->json(['ok' => false, 'message' => 'Mã địa chỉ không hợp lệ.'], 422);
            }
            $makeDefault = null;
            if (array_key_exists('is_default', $input)) {
                $makeDefault = filter_var($input['is_default'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($makeDefault === null) $this->json(['ok' => false, 'message' => 'Trạng thái địa chỉ mặc định không hợp lệ.'], 422);
            }
            $found = false;
            foreach ($addresses as &$row) {
                if ((string)($row['id'] ?? '') === $addressId) {
                    $row['receiver_name'] = trim((string)($input['receiver_name'] ?? $row['receiver_name'] ?? ''));
                    $row['phone'] = preg_replace('/\s+/u', '', trim((string)($input['phone'] ?? $row['phone'] ?? ''))) ?? '';
                    $row['province'] = trim((string)($input['province'] ?? $row['province'] ?? ''));
                    $row['ward'] = trim((string)($input['ward'] ?? $row['ward'] ?? ''));
                    $row['street'] = trim((string)($input['street'] ?? $row['street'] ?? ''));
                    if (array_key_exists('address_type', $input)) {
                        if (!in_array($input['address_type'], ['home', 'company'], true)) {
                            $this->json(['ok' => false, 'message' => 'Loại địa chỉ không hợp lệ.'], 422);
                        }
                        $row['address_type'] = $input['address_type'];
                    }
                    if ($row['receiver_name'] === '' || mb_strlen($row['receiver_name']) > 120
                        || !preg_match('/^(?:0\d{9}|\+84\d{9})$/D', $row['phone'])
                        || $row['province'] === '' || mb_strlen($row['province']) > 120
                        || $row['ward'] === '' || mb_strlen($row['ward']) > 120
                        || $row['street'] === '' || mb_strlen($row['street']) > 300) {
                        $this->json(['ok' => false, 'message' => 'Vui lòng kiểm tra lại người nhận, số điện thoại và địa chỉ.'], 422);
                    }
                    $found = true;
                } elseif ($makeDefault === true) {
                    $row['is_default'] = false;
                }
            }
            unset($row);
            if (!$found) $this->json(['ok' => false, 'message' => 'Không tìm thấy địa chỉ.'], 404);
            foreach ($addresses as &$row) {
                if ((string)($row['id'] ?? '') === $addressId && $makeDefault !== null) {
                    $row['is_default'] = $makeDefault;
                }
            }
            unset($row);
            if ($addresses && !array_filter($addresses, static fn(array $row): bool => !empty($row['is_default']))) {
                $addresses[0]['is_default'] = true;
            }
        } else {
            $addressId = trim((string)($input['address_id'] ?? ''));
            $addresses = array_values(array_filter($addresses, static fn(array $row): bool => (string)($row['id'] ?? '') !== $addressId));
            if ($addressId === '' || count($addresses) === count((array)($user['shipping_addresses'] ?? []))) {
                $this->json(['ok' => false, 'message' => 'Không tìm thấy địa chỉ.'], 404);
            }
            if ($addresses && !array_filter($addresses, static fn(array $row): bool => !empty($row['is_default']))) {
                $addresses[0]['is_default'] = true;
            }
        }

        $this->db->khach_hang->updateOne(
            ['ma_kh' => $customerId],
            ['$set' => ['shipping_addresses' => $addresses, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
        );
        TaiKhoan::forgetRequestCache();
        $updated = $this->accounts->getKhachHangByEmail((string)$user['email']) ?? $user;
        $this->json(['ok' => true, 'addresses' => $this->profilePayload($updated)['shipping_addresses']]);
    }

    private function skinProfile(string $method, array $input): void
    {
        $user = $this->requireCustomer();
        $email = trim((string)($user['email'] ?? ''));
        if ($method === 'GET') {
            $profiles = $this->accounts->getSkinProfilesByEmail($email);
            $selectedId = trim((string)($_GET['profile_id'] ?? ''));
            if ($selectedId !== '' && !array_filter($profiles, static fn(array $p): bool => (string)($p['profile_id'] ?? '') === $selectedId)) {
                $this->json(['ok' => false, 'message' => 'Không tìm thấy hồ sơ da.'], 404);
            }
            $history = $this->accounts->getQuickSkinProfileHistory($email, $selectedId !== '' ? $selectedId : null);
            $this->json(['ok' => true, 'profiles' => $profiles, 'history' => $history]);
        }

        if ($method === 'PATCH') {
            $skinType = trim((string)($input['loai_da'] ?? ''));
            $issues = $input['van_de_da'] ?? [];
            if (!is_array($issues)) $issues = [$issues];
            $issues = array_values(array_filter(array_map(static fn($v): string => mb_substr(trim((string)$v), 0, 120), $issues)));
            $budget = isset($input['ngan_sach']) && $input['ngan_sach'] !== '' ? (int)$input['ngan_sach'] : null;
            if ($skinType === '' || mb_strlen($skinType) > 80 || count($issues) > 20 || ($budget !== null && ($budget < 0 || $budget > 1000000000))) {
                $this->json(['ok' => false, 'message' => 'Thông tin hồ sơ da không hợp lệ.'], 422);
            }
            $ok = $this->accounts->saveSkinProfileByEmail((string)($user['ho_ten'] ?? ''), $email, $skinType, $issues, $budget);
            if (!$ok) $this->json(['ok' => false, 'message' => 'Không thể lưu hồ sơ da.'], 503);
            $this->json(['ok' => true, 'profiles' => $this->accounts->getSkinProfilesByEmail($email)]);
        }

        $profileId = trim((string)($input['profile_id'] ?? ''));
        if ($profileId !== '' && !array_filter(
            $this->accounts->getSkinProfilesByEmail($email),
            static fn(array $p): bool => (string)($p['profile_id'] ?? '') === $profileId
        )) {
            $this->json(['ok' => false, 'message' => 'Không tìm thấy hồ sơ da.'], 404);
        }
        $payload = [
            'muc_do_nhay_cam' => trim((string)($input['muc_do_nhay_cam'] ?? '')),
            'van_de_da' => $input['van_de_da'] ?? [],
            'muc_tieu_cham_soc' => trim((string)($input['muc_tieu_cham_soc'] ?? '')),
            'ngan_sach' => isset($input['ngan_sach']) ? (int)$input['ngan_sach'] : 0,
            'request_id' => trim((string)($input['request_id'] ?? '')),
        ];
        if (!is_array($payload['van_de_da'])) $payload['van_de_da'] = [$payload['van_de_da']];
        if (count($payload['van_de_da']) > 20 || strlen($payload['request_id']) > 120) {
            $this->json(['ok' => false, 'message' => 'Câu trả lời khảo sát không hợp lệ.'], 422);
        }
        $saved = $this->accounts->saveQuickSkinProfileResultByEmail(
            (string)($user['ho_ten'] ?? ''),
            $email,
            $payload,
            $profileId !== '' ? $profileId : null
        );
        if (empty($saved['ok'])) $this->json(['ok' => false, 'message' => 'Không thể lưu kết quả khảo sát.'], 503);
        $this->json(['ok' => true, 'result' => $saved, 'profiles' => $this->accounts->getSkinProfilesByEmail($email)]);
    }

    private function orders(): void
    {
        $user = $this->requireCustomer();
        $customerId = (int)($user['ma_kh'] ?? 0);
        $orders = $customerId > 0 ? $this->accounts->getOrderHistory($customerId) : [];
        $result = [];
        foreach ($orders as $order) {
            $items = [];
            foreach ((array)($order['items'] ?? []) as $item) {
                $items[] = [
                    'product_id' => (string)($item['ma_san_pham'] ?? ''),
                    'name' => (string)($item['ten_san_pham'] ?? ''),
                    'image_url' => (string)($item['link_hinh_anh'] ?? ''),
                    'quantity' => max(0, (int)($item['so_luong'] ?? 0)),
                    'unit_price' => max(0, (int)($item['gia_ban'] ?? $item['don_gia'] ?? 0)),
                ];
            }
            $result[] = [
                'id' => (string)($order['ma_hoa_don'] ?? ''),
                'placed_at' => $this->dateValue($order['ngay_dat'] ?? null),
                'status' => (string)($order['trang_thai'] ?? ''),
                'total' => max(0, (int)($order['tong_tien'] ?? 0)),
                'payment_method' => (string)($order['phuong_thuc_thanh_toan'] ?? ''),
                'items' => $items,
            ];
        }
        $this->json(['ok' => true, 'orders' => $result]);
    }

    private function loyalty(): void
    {
        $user = $this->requireCustomer();
        $this->json(['ok' => true, 'loyalty' => [
            'points' => max(0, (int)($user['diemtl'] ?? 0)),
            'level' => (string)($user['loaikh'] ?? 'Thuong'),
        ]]);
    }

    private function vouchers(): void
    {
        $user = $this->requireCustomer();
        $customerId = (string)(int)($user['ma_kh'] ?? 0);
        if ($customerId === '0') $this->json(['ok' => false, 'message' => 'Không xác định được tài khoản.'], 401);

        $items = [];
        foreach ($this->db->customer_vouchers->find(
            ['customer_id' => $customerId],
            ['sort' => ['created_at' => -1], 'limit' => 100]
        ) as $doc) {
            $row = (array)$doc;
            $status = strtoupper(trim((string)($row['status'] ?? 'AVAILABLE')));
            $endDate = $this->dateValue($row['end_date'] ?? null);
            if ($status === 'AVAILABLE' && $endDate !== null && strtotime($endDate) < time()) $status = 'EXPIRED';
            $items[] = [
                'voucher_code' => (string)($row['voucher_code'] ?? ''),
                'campaign_name' => (string)($row['campaign_name'] ?? ''),
                'discount_type' => (string)($row['discount_type'] ?? 'percentage'),
                'discount_value' => max(0, (float)($row['discount_value'] ?? 0)),
                'max_discount_amount' => isset($row['max_discount_amount']) ? max(0, (int)$row['max_discount_amount']) : null,
                'min_order_amount' => max(0, (int)($row['min_order_amount'] ?? 0)),
                'status' => $status,
                'start_date' => $this->dateValue($row['start_date'] ?? null),
                'end_date' => $endDate,
                'created_at' => $this->dateValue($row['created_at'] ?? null),
            ];
        }
        $this->json(['ok' => true, 'vouchers' => $items]);
    }

    private function serializeProduct(array $product, bool $detail = false): array
    {
        $id = (string)($product['ma_san_pham'] ?? $product['id'] ?? '');
        $result = [
            'id' => $id,
            'name' => (string)($product['ten_san_pham'] ?? $product['name'] ?? ''),
            'brand' => (string)($product['thuong_hieu'] ?? $product['ten_thuong_hieu'] ?? ''),
            'category' => (string)($product['loai_san_pham'] ?? $product['danh_muc_day_du'] ?? ''),
            'price' => max(0, (int)($product['gia_ban'] ?? 0)),
            'image_url' => (string)($product['link_hinh_anh'] ?? $product['hinh_anh'] ?? ''),
            'available' => $this->products->isProductAvailable($product),
            'stock' => $this->products->getProductStock($product),
        ];
        if ($detail) {
            $result += [
                'description' => (string)($product['mo_ta'] ?? $product['mo_ta_san_pham'] ?? ''),
                'ingredients' => $product['thanh_phan'] ?? $product['ingredients'] ?? [],
                'usage' => (string)($product['huong_dan_su_dung'] ?? ''),
                'volume' => (string)($product['dung_tich'] ?? ''),
            ];
        }
        return $result;
    }

    private function sanitizeAddress(array $address): array
    {
        return [
            'id' => (string)($address['id'] ?? ''),
            'receiver_name' => (string)($address['receiver_name'] ?? ''),
            'phone' => (string)($address['phone'] ?? ''),
            'province' => (string)($address['province'] ?? ''),
            'ward' => (string)($address['ward'] ?? ''),
            'street' => (string)($address['street'] ?? ''),
            'address_type' => (string)($address['address_type'] ?? 'home'),
            'is_default' => !empty($address['is_default']),
        ];
    }

    private function dateValue($value): ?string
    {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }
        if ($value instanceof DateTimeInterface) return $value->format(DATE_ATOM);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function readInput(): array
    {
        $raw = file_get_contents('php://input', false, null, 0, 32769);
        if (!is_string($raw) || strlen($raw) > 32768) {
            $this->json(['ok' => false, 'message' => 'Nội dung gửi lên quá lớn.'], 413);
        }
        if (trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->json(['ok' => false, 'message' => 'Nội dung JSON không hợp lệ.'], 400);
        }
        return $decoded;
    }

    private function requestHeader(string $name): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey])) return trim((string)$_SERVER[$serverKey]);
        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $header => $value) {
                if (strcasecmp((string)$header, $name) === 0) return trim((string)$value);
            }
        }
        return '';
    }

    private function json(array $payload, int $status = 200, array $extraHeaders = []): void
    {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            header('Vary: Authorization');
            foreach ($extraHeaders as $name => $value) header($name . ': ' . $value);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

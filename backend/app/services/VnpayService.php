<?php
// backend/app/services/VnpayService.php

class VnpayService {
    private string $tmnCode;
    private string $hashSecret;
    private string $vnpUrl;
    private string $returnUrl;

    public function __construct(?string $tmnCode = null, ?string $hashSecret = null, ?string $vnpUrl = null, ?string $returnUrl = null) {
        $tmn = trim((string)($tmnCode ?? (defined('VNPAY_TMN_CODE') && VNPAY_TMN_CODE !== '' ? VNPAY_TMN_CODE : '')));
        if ($tmn === '' && function_exists('ss_env')) {
            $tmn = ss_env('VNPAY_TMN_CODE', '');
        }
        if ($tmn === '') {
            $tmn = trim((string)($_ENV['VNPAY_TMN_CODE'] ?? getenv('VNPAY_TMN_CODE') ?? ''));
        }
        if ($tmn === '') {
            $tmn = '6S051F2X';
        }

        $secret = trim((string)($hashSecret ?? (defined('VNPAY_HASH_SECRET') && VNPAY_HASH_SECRET !== '' ? VNPAY_HASH_SECRET : '')));
        if ($secret === '' && function_exists('ss_env')) {
            $secret = ss_env('VNPAY_HASH_SECRET', '');
        }
        if ($secret === '') {
            $secret = trim((string)($_ENV['VNPAY_HASH_SECRET'] ?? getenv('VNPAY_HASH_SECRET') ?? ''));
        }
        if ($secret === '') {
            $secret = 'JWBMZUNCSLRESUCKLMRWWPAORIQCTTSC';
        }

        $url = trim((string)($vnpUrl ?? (defined('VNPAY_URL') && VNPAY_URL !== '' ? VNPAY_URL : '')));
        if ($url === '' && function_exists('ss_env')) {
            $url = ss_env('VNPAY_URL', '');
        }
        if ($url === '') {
            $url = trim((string)($_ENV['VNPAY_URL'] ?? getenv('VNPAY_URL') ?? 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'));
        }

        $this->tmnCode = $tmn !== '' ? $tmn : '6S051F2X';
        $this->hashSecret = $secret !== '' ? $secret : 'JWBMZUNCSLRESUCKLMRWWPAORIQCTTSC';
        $this->vnpUrl = $url !== '' ? $url : 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html';

        $configuredReturnUrl = trim((string)($returnUrl ?? (defined('VNPAY_RETURN_URL') && VNPAY_RETURN_URL !== '' ? VNPAY_RETURN_URL : '')));
        if ($configuredReturnUrl === '' && function_exists('ss_env')) {
            $configuredReturnUrl = ss_env('VNPAY_RETURN_URL', '');
        }

        if ($configuredReturnUrl !== '') {
            $this->returnUrl = $configuredReturnUrl;
        } else {
            $baseUrl = defined('BASE_URL') ? BASE_URL : '.';
            $this->returnUrl = $this->buildFullUrl($baseUrl . '/index.php?r=vnpay_return');
        }
    }



    private function buildFullUrl(string $path): string {
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $cleanPath = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($cleanPath, './')) {
            $cleanPath = substr($cleanPath, 2);
        }

        return $scheme . '://' . $host . '/' . $cleanPath;
    }



    public function isConfigured(): bool {
        return $this->tmnCode !== '' && $this->hashSecret !== '';
    }

    public function createPaymentUrl(string $orderId, int $amount, string $orderInfo = '', ?string $ipAddr = null): string {
        if (!$this->isConfigured()) {
            throw new RuntimeException('VNPAY Gateway chưa được cấu hình VNPAY_TMN_CODE hoặc VNPAY_HASH_SECRET.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Số tiền thanh toán VNPAY phải lớn hơn 0.');
        }

        $vnpIpAddr = trim((string)($ipAddr ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')));
        if ($vnpIpAddr === '' || $vnpIpAddr === '::1') {
            $vnpIpAddr = '127.0.0.1';
        }

        $vnpOrderInfo = trim($orderInfo);
        if ($vnpOrderInfo === '') {
            $vnpOrderInfo = 'Thanh toan don hang #' . $orderId;
        }

        $vnpData = [
            'vnp_Version' => '2.1.0',
            'vnp_TmnCode' => $this->tmnCode,
            'vnp_Amount' => $amount * 100, // VNPAY nhân 100
            'vnp_Command' => 'pay',
            'vnp_CreateDate' => date('YmdHis'),
            'vnp_CurrCode' => 'VND',
            'vnp_IpAddr' => $vnpIpAddr,
            'vnp_Locale' => 'vn',
            'vnp_OrderInfo' => $vnpOrderInfo,
            'vnp_OrderType' => 'other',
            'vnp_ReturnUrl' => $this->returnUrl,
            'vnp_TxnRef' => (string)$orderId,
            'vnp_ExpireDate' => date('YmdHis', time() + 15 * 60), // Hết hạn sau 15 phút
        ];

        ksort($vnpData);

        $query = '';
        $hashData = '';
        $i = 0;

        foreach ($vnpData as $key => $value) {
            if ($i === 1) {
                $hashData .= '&' . urlencode((string)$key) . "=" . urlencode((string)$value);
            } else {
                $hashData .= urlencode((string)$key) . "=" . urlencode((string)$value);
                $i = 1;
            }
            $query .= urlencode((string)$key) . "=" . urlencode((string)$value) . '&';
        }

        $vnpUrl = $this->vnpUrl . "?" . $query;
        $vnpSecureHash = hash_hmac('sha512', $hashData, $this->hashSecret);
        $vnpUrl .= 'vnp_SecureHash=' . $vnpSecureHash;

        return $vnpUrl;
    }

    public function validateResponse(array $params): bool {
        $vnpSecureHash = $params['vnp_SecureHash'] ?? '';
        if (trim((string)$vnpSecureHash) === '') {
            return false;
        }

        $inputData = [];
        foreach ($params as $key => $value) {
            if (substr((string)$key, 0, 4) === 'vnp_') {
                $inputData[$key] = $value;
            }
        }

        unset($inputData['vnp_SecureHash'], $inputData['vnp_SecureHashType']);
        ksort($inputData);

        $hashData = '';
        $i = 0;
        foreach ($inputData as $key => $value) {
            if ($i === 1) {
                $hashData .= '&' . urlencode((string)$key) . "=" . urlencode((string)$value);
            } else {
                $hashData .= urlencode((string)$key) . "=" . urlencode((string)$value);
                $i = 1;
            }
        }

        $secureHash = hash_hmac('sha512', $hashData, $this->hashSecret);
        return hash_equals(strtolower($secureHash), strtolower((string)$vnpSecureHash));
    }

    public function getResponseCodeMessage(string $code): string {
        $code = trim($code);
        $messages = [
            '00' => 'Giao dịch thành công.',
            '07' => 'Trừ tiền thành công. Giao dịch bị nghi ngờ (liên quan tới lừa đảo, bất thường).',
            '09' => 'Thẻ/Tài khoản chưa đăng ký dịch vụ InternetBanking.',
            '10' => 'Xác thực thông tin thẻ/tài khoản không thành công quá 3 lần.',
            '11' => 'Đã hết hạn chờ thanh toán. Vui lòng thực hiện lại giao dịch.',
            '12' => 'Thẻ/Tài khoản của quý khách bị khóa.',
            '13' => 'Quý khách nhập sai mật khẩu xác thực giao dịch (OTP).',
            '24' => 'Khách hàng hủy giao dịch.',
            '51' => 'Tài khoản của quý khách không đủ số dư để thực hiện giao dịch.',
            '65' => 'Tài khoản của quý khách đã vượt quá hạn mức giao dịch trong ngày.',
            '75' => 'Ngân hàng thanh toán đang bảo trì.',
            '79' => 'Quý khách nhập sai mật khẩu thanh toán quá số lần quy định.',
            '99' => 'Lỗi không xác định từ cổng VNPAY.',
        ];

        return $messages[$code] ?? ('Lỗi không xác định (mã ' . $code . ').');
    }
}

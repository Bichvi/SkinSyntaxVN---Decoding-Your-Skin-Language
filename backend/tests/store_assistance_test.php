<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/services/SupportKnowledge.php';
require_once __DIR__ . '/../app/services/PurchaseIntent.php';
require_once __DIR__ . '/../app/services/ChatProductCatalog.php';
require_once __DIR__ . '/../app/services/ChatPurchaseService.php';

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
function rejects(callable $action, int $code, string $label): void {
    try { $action(); } catch (DomainException $error) { check($error->getCode() === $code, $label); return; }
    throw new RuntimeException('Not rejected: ' . $label);
}
$knowledge = new SupportKnowledge();
foreach ($knowledge->data()['articles'] as $article) {
    check(count($article['sections']) > 0, 'Article has body: ' . $article['slug']);
    foreach ($article['questions'] as $question) check($knowledge->match($question)['slug'] === $article['slug'], $question);
    check(!str_contains(json_encode($article, JSON_UNESCAPED_UNICODE), '[ĐIỀN'), 'No draft placeholders in published body');
}
foreach (['Tôi có nên mua retinol không?', 'Tư vấn mua serum cho da nhạy cảm', 'Tôi chưa mua, chỉ hỏi cách dùng', 'Hướng dẫn đặt hàng', 'So sánh CeraVe và SVR', 'Tôi không muốn mua nữa'] as $message) check(PurchaseIntent::parse($message) === null, 'Not a purchase: ' . $message);
$intent = PurchaseIntent::parse('tối mua 1 chai Serum CeraVe Retinol Hỗ Trợ Mờ Thâm Mụn');
check($intent === ['query'=>'serum cerave retinol mo tham mun', 'quantity'=>1], 'Original user example');
check(PurchaseIntent::parse('đặt cho tôi 2 chai CeraVe Retinol')['quantity'] === 2, 'Quantity extraction');
check(PurchaseIntent::parse('Mua chai CeraVe retinol, thanh toán COD nhé')['query'] === 'cerave retinol', 'Mixed purchase/payment');
check(PurchaseIntent::parse('Mua chai CeraVe retinol và xuất hóa đơn VAT')['query'] === 'cerave retinol', 'Mixed purchase/invoice');
check($knowledge->match('OTP đăng ký bao lâu hết hạn?')['slug'] === 'otp', 'OTP timing over generic registration');
check($knowledge->match('Nhân viên đòi đọc OTP đăng ký')['slug'] === 'an-toan-tai-khoan', 'Security over generic OTP');
check($knowledge->match('Shop giao hàng thế nào?')['slug'] === 'van-chuyen', 'Delivery paraphrase');
$records = [
    ['ma_san_pham'=>'1', 'ten_san_pham'=>'Serum CeraVe Retinol Hỗ Trợ Mờ Thâm Mụn 30ml'],
    ['ma_san_pham'=>'2', 'ten_san_pham'=>'Serum CeraVe Cấp Ẩm 30ml'],
    ['ma_san_pham'=>'3', 'ten_san_pham'=>'Serum SVR Retinol 30ml'],
];
check(array_column(ChatProductCatalog::rank($intent['query'], $records), 'ma_san_pham') === ['1'], 'Brand and retinol must both match');
check(array_column(ChatProductCatalog::rank('serum cerave retinlo', $records), 'ma_san_pham') === [], 'Two-edit miss not overmatched');
check(array_column(ChatProductCatalog::rank('serum cerave retino', $records), 'ma_san_pham') === ['1'], 'One-letter typo');
check(ChatProductCatalog::rank('cerave 50ml', $records) === [], 'No fabricated volume');
$catalog = new class {
    public array $product = ['id'=>'1', 'name'=>'Test retinol', 'price'=>350000, 'stock'=>4, 'available'=>true];
    public function search(string $query): array { return $query === 'missing' ? [] : [$this->product]; }
    public function find(string $id): ?array { return $id === '1' ? $this->product : null; }
};
$session = ['gio_hang'=>['99'=>3]];
$now = 1000;
$service = new ChatPurchaseService($catalog, $session, static function () use (&$now) { return $now; });
$offer = $service->offer($intent)['commerce'];
check(!isset($session['checkout_items']) && $session['gio_hang'] === ['99'=>3], 'Offer cannot mutate cart or checkout');
$quote = $service->quote($offer['offer'], '1', 2);
check($quote['subtotal'] === 700000 && !isset($session['checkout_items']), 'Quote without checkout');
rejects(fn() => $service->quote($offer['offer'], '2', 1), 422, 'Unoffered ID');
foreach ([0, -1, 21, 1.5, '2', null] as $quantity) rejects(fn() => $service->quote($offer['offer'], '1', $quantity), 422, 'Invalid quantity');
rejects(fn() => $service->quote($offer['offer'], '1', 5), 409, 'Insufficient stock');
$otherSession = [];
$otherService = new ChatPurchaseService($catalog, $otherSession, static fn() => 1000);
rejects(fn() => $otherService->quote($offer['offer'], '1', 1), 410, 'Offer cannot cross sessions');
rejects(fn() => $service->checkout($offer['offer'], '1', 2, 1), 409, 'Client price tampering');
check(!isset($session['checkout_items']), 'Rejected price must not write checkout');
$catalog->product['price'] = 360000;
rejects(fn() => $service->checkout($offer['offer'], '1', 2, 350000), 409, 'Price changed');
$service->checkout($offer['offer'], '1', 2, 360000);
check($session['checkout_items'] === ['1'=>2] && $session['gio_hang'] === ['99'=>3], 'Only requested selection enters checkout');
$catalog->product['available'] = false;
rejects(fn() => $service->quote($offer['offer'], '1', 1), 409, 'Product removed');
$now += 601;
rejects(fn() => $service->quote($offer['offer'], '1', 1), 410, 'Expired offer');
check(!isset($service->offer(['query'=>'missing','quantity'=>1])['commerce']), 'No match cannot fabricate offer');
echo "PASS: {$checks} assertions\n";

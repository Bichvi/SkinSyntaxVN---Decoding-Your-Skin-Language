<?php
declare(strict_types=1);

/** Session-bound offers/quotes. Inject catalog and clock for independent contract tests. */
final class ChatPurchaseService
{
    private $catalog;
    private $clock;
    private array $session;
    public function __construct($catalog, array &$session, ?callable $clock = null)
    {
        $this->catalog = $catalog;
        $this->session =& $session;
        $this->clock = $clock ?? static fn() => time();
    }

    public function offer(array $intent): array
    {
        $quantity = $this->quantity($intent['quantity']);
        if ($intent['query'] === '') return $this->response('Bạn muốn mua sản phẩm nào? Gửi tên hoặc thương hiệu, kèm dung tích nếu bạn đã chọn được nhé.');
        $products = $this->catalog->search($intent['query']);
        if (!$products) return $this->response('Mình chưa tìm được sản phẩm đang bán khớp tên bạn vừa gửi. Bạn kiểm tra lại tên, thương hiệu hoặc gửi tên trên nhãn nhé. Điều này chưa có nghĩa cả cửa hàng không có sản phẩm đó.');
        $now = ($this->clock)();
        $offers = array_filter($this->session['chat_purchase_offers'] ?? [], static fn($offer) => $offer['expires'] >= $now);
        $token = bin2hex(random_bytes(24));
        $offers[$token] = ['ids'=>array_column($products, 'id'), 'expires'=>$now + 600, 'quantity'=>$quantity];
        $this->session['chat_purchase_offers'] = array_slice($offers, -6, null, true);
        $result = $this->response('Mình tìm được các lựa chọn dưới đây. Bạn chọn đúng chai đang nói tới, kiểm tra số lượng rồi bấm Thanh toán nhé.');
        $result['commerce'] = ['stage'=>'choose', 'offer'=>$token, 'quantity'=>$quantity, 'products'=>$products];
        return $result;
    }

    public function quote(string $offer, string $id, $quantity): array
    {
        $state = $this->session['chat_purchase_offers'][$offer] ?? null;
        if (!$state || $state['expires'] < ($this->clock)()) throw new DomainException('Lựa chọn đã hết hạn. Bạn nhắn lại tên sản phẩm để lấy giá hiện tại nhé.', 410);
        if (!in_array($id, $state['ids'], true)) throw new DomainException('Sản phẩm không thuộc danh sách vừa gợi ý. Vui lòng chọn lại.', 422);
        $quantity = $this->quantity($quantity);
        $product = $this->catalog->find($id);
        if (!$product || !$product['available'] || $product['price'] <= 0) throw new DomainException('Sản phẩm hiện không thể mua. Bạn chọn sản phẩm khác nhé.', 409);
        if ($product['stock'] !== null && $quantity > $product['stock']) throw new DomainException('Số lượng vượt tồn kho hiện tại. Vui lòng giảm số lượng.', 409);
        return ['stage'=>'selected', 'offer'=>$offer, 'product'=>$product, 'quantity'=>$quantity,
            'subtotal'=>$product['price'] * $quantity, 'expires_at'=>$state['expires']];
    }

    public function checkout(string $offer, string $id, $quantity, $expectedPrice): array
    {
        $quote = $this->quote($offer, $id, $quantity);
        if (!is_int($expectedPrice) || $expectedPrice !== $quote['product']['price']) throw new DomainException('Giá sản phẩm vừa thay đổi. Bấm kiểm tra lại giá và xác nhận trước khi thanh toán.', 409);
        // Only the explicit checkout action writes the existing checkout session contract.
        $this->session['checkout_items'] = [$id => $quote['quantity']];
        unset($this->session['checkout_voucher'], $this->session['checkout_points'], $this->session['checkout_address_choice'], $this->session['checkout_new_receiver']);
        $this->session['checkout_payment_method'] = 'cod';
        return $quote;
    }

    private function quantity($value): int
    {
        if (!is_int($value) || $value < 1 || $value > 20) throw new DomainException('Mỗi lần chọn từ 1 đến 20 sản phẩm. Vui lòng nhập số lượng nguyên hợp lệ.', 422);
        return $value;
    }

    private function response(string $answer): array
    {
        return ['ok'=>true, 'answer'=>$answer, 'products'=>[], 'conflicts'=>[], 'intent_mode'=>'PURCHASE', 'pipeline_mode'=>'catalog_purchase'];
    }
}

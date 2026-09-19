<?php
declare(strict_types=1);
require_once __DIR__ . '/SupportKnowledge.php';

/** A purchase request is a proposal to show choices, never authorization to order. */
final class PurchaseIntent
{
    public static function parse(string $message): ?array
    {
        $text = SupportKnowledge::fold($message);
        if (preg_match('/\b(khong|ko|chua|dung)\s+(muon\s+|can\s+|dinh\s+)?(mua|dat|lay|chot)\b/', $text)) return null;
        if (preg_match('/\b(mua|dat hang)\s+(duoc|nhu the nao|can gi|bang cach nao)\b/', $text)) return null;
        if (preg_match('/\b(co nen|co can|nen mua|nen dung|co hop|phu hop|tu van|so sanh|khac nhau|la gi|tac dung|cach dung|dung the nao|khong mua|chua mua|dung dat|huy|huong dan|cach dat|lam sao|dat hang nhu|mua hang nhu)\b/', $text)) return null;
        if (!preg_match('/\b(mua|dat|lay|chot)\b/u', $text, $verb, PREG_OFFSET_CAPTURE)) return null;
        $query = trim(substr($text, $verb[0][1] + strlen($verb[0][0])));
        // Delivery/payment questions belong to support, not the product name.
        $query = preg_split('/[,;?]|\b(?:va\s+)?(?:thanh toan|tra bang|giao cod|ship|phi ship|xuat hoa don|xuat vat|giao hang|doi tra)\b/', $query, 2)[0];
        $quantity = 1;
        if (preg_match('/\b(\d+)\s*(chai|lo|tuyp|hop|san pham|cai|hu)\b/', $query, $match)) {
            $quantity = (int)$match[1];
            $query = str_replace($match[0], ' ', $query);
        }
        $query = preg_replace('/\b(cho|giup|minh|toi|tui|em|anh|chi|ban|voi|nhe|nha|di|mot|chai|lo|tuyp|hop|hu|cai|san pham|hang|ho tro|lam|giam)\b/', ' ', $query);
        $query = trim(preg_replace('/\s+/', ' ', $query));
        return ['query' => $query, 'quantity' => $quantity];
    }
}

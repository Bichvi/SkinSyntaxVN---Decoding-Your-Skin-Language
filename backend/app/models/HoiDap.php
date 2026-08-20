<?php

use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;

class HoiDap {
    private $db;

    public function __construct($db = null) {
        if ($db === null) {
            global $db;
            $this->db = $db;
        } else if (is_object($db) && method_exists($db, 'raw')) {
            $this->db = $db->raw();
        } else {
            $this->db = $db;
        }
        if (!is_object($this->db)) {
            global $db;
            $this->db = $db ?? $GLOBALS['db'] ?? null;
        }
    }

    private function getNextNumericId(string $collection, string $column): int {
        $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
        return $lastDoc ? (int)$lastDoc[$column] + 1 : 1;
    }

    private function productIdCandidates(string $productId): array {
        $productId = trim((string)$productId);
        $ids = [$productId];
        if ($productId !== '' && is_numeric($productId)) {
            $ids[] = (int)$productId;
        }
        return array_values(array_unique($ids, SORT_REGULAR));
    }

    private function productFilter(string $productId): array {
        $ids = $this->productIdCandidates($productId);
        return ['$or' => [
            ['ma_san_pham' => ['$in' => $ids]],
            ['id' => ['$in' => $ids]],
        ]];
    }

    private function getProductBriefById(string $productId): array {
        $productId = trim((string)$productId);
        if ($productId === '') return [];

        $dbInstance = is_object($this->db) ? (method_exists($this->db, 'raw') ? $this->db->raw() : $this->db) : ($GLOBALS['db'] ?? null);

        if (class_exists('SanPham') && is_object($dbInstance)) {
            try {
                $brief = (new SanPham($dbInstance))->getProductBriefById($productId);
                if (!empty($brief) && !empty($brief['ten_san_pham'])) return $brief;
            } catch (Throwable $e) {
                error_log('question product brief lookup error: ' . $e->getMessage());
            }
        }

        if (!is_object($dbInstance)) return [];

        $product = $dbInstance->san_pham->findOne($this->productFilter($productId));
        if (!$product) return [];
        $p = (array)$product;
        return [
            'id' => (string)($p['id'] ?? $p['ma_san_pham'] ?? $productId),
            'ma_san_pham' => (string)($p['ma_san_pham'] ?? $p['id'] ?? $productId),
            'ten_san_pham' => (string)($p['ten_san_pham'] ?? ''),
            'thuong_hieu' => (string)($p['thuong_hieu'] ?? ''),
            'link_hinh_anh' => (string)($p['link_hinh_anh'] ?? ''),
        ];
    }

    public function resolveCustomerByEmail(string $email, string $defaultName = 'Khách hàng'): ?array {
        $email = trim($email);
        if ($email === '') return null;
        $regex = new Regex('^' . preg_quote($email) . '$', 'i');
        $customer = $this->db->khach_hang->findOne(['email' => $regex]);
        if ($customer) return (array)$customer;

        $id = $this->getNextNumericId('khach_hang', 'ma_kh');
        $payload = [
            'ma_kh' => $id,
            'ho_ten' => trim($defaultName) !== '' ? $defaultName : 'Khách hàng',
            'email' => $email,
            'created_at' => new UTCDateTime((int)(microtime(true) * 1000)),
            'updated_at' => new UTCDateTime((int)(microtime(true) * 1000)),
        ];
        $this->db->khach_hang->insertOne($payload);
        return $payload;
    }

    private function normalizeQuestionDate(array &$item, string $field): void {
        if (!empty($item[$field]) && $item[$field] instanceof UTCDateTime) {
            $item[$field] = $item[$field]->toDateTime()
                ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))
                ->format('Y-m-d H:i:s');
        }
    }

    private function normalizeQuestion(array $item): array {
        $this->normalizeQuestionDate($item, 'ngay_hoi');
        if (!empty($item['tra_loi']) && is_object($item['tra_loi'])) {
            $item['tra_loi'] = (array)$item['tra_loi'];
        }
        if (!empty($item['tra_loi']['ngay_tra_loi']) && $item['tra_loi']['ngay_tra_loi'] instanceof UTCDateTime) {
            $item['tra_loi']['ngay_tra_loi'] = $item['tra_loi']['ngay_tra_loi']->toDateTime()
                ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))
                ->format('Y-m-d H:i:s');
        }
        return $item;
    }

    public function getQuestionsByProduct(string $productId, int $limit = 20): array {
        $productId = trim((string)$productId);
        if ($productId === '') return [];

        $filter = ['$and' => [
            $this->productFilter($productId),
            ['trang_thai' => 'hien_thi'],
        ]];
        $cursor = $this->db->hoi_dap_san_pham->find(
            $filter,
            ['sort' => ['ngay_hoi' => -1, 'ma_hoi_dap' => -1], 'limit' => max(1, min(50, $limit))]
        );

        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeQuestion((array)$doc);
        }
        return $items;
    }

    public function countQuestionsByProduct(string $productId): int {
        $productId = trim((string)$productId);
        if ($productId === '') return 0;
        return $this->db->hoi_dap_san_pham->countDocuments(['$and' => [
            $this->productFilter($productId),
            ['trang_thai' => 'hien_thi'],
        ]]);
    }

    public function addQuestion(string $productId, int $customerId, array $data): array {
        $productId = trim((string)$productId);
        $question = trim((string)($data['cau_hoi'] ?? ''));
        if ($productId === '' || $customerId <= 0 || $question === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập câu hỏi cho sản phẩm.'];
        }

        $customer = $this->db->khach_hang->findOne(['ma_kh' => $customerId]);
        $questionId = $this->getNextNumericId('hoi_dap_san_pham', 'ma_hoi_dap');
        $customerName = (string)($customer['ho_ten'] ?? 'Khách hàng');
        $now = new UTCDateTime((int)(microtime(true) * 1000));
        $this->db->hoi_dap_san_pham->insertOne([
            'ma_hoi_dap' => $questionId,
            'ma_san_pham' => (string)$productId,
            'ma_khach_hang' => $customerId,
            'ten_khach_hang' => $customerName,
            'cau_hoi' => $question,
            'ngay_hoi' => $now,
            'tra_loi' => null,
            'so_luot_thich' => 0,
            'trang_thai' => 'hien_thi',
            'updated_at' => $now,
        ]);
        $this->createQuestionNotification($questionId, $productId, $customerId, $customerName);
        return ['ok' => true, 'message' => 'Đã gửi câu hỏi. SkinSyntax sẽ phản hồi sớm.'];
    }

    private function createQuestionNotification(int $questionId, string $productId, int $customerId, string $customerName): void {
        try {
            $product = $this->getProductBriefById($productId);
            $productName = trim((string)($product['ten_san_pham'] ?? ''));
            $contentProduct = $productName !== '' ? $productName : ('#' . $productId);
            $now = new UTCDateTime((int)(microtime(true) * 1000));
            $this->db->thong_bao->insertOne([
                'ma_thong_bao' => $this->getNextNumericId('thong_bao', 'ma_thong_bao'),
                'loai' => 'hoi_dap_moi',
                'tieu_de' => 'Hỏi đáp mới',
                'noi_dung' => 'Khách ' . $customerName . ' vừa đặt câu hỏi cho sản phẩm ' . $contentProduct . '.',
                'ma_san_pham' => (string)$productId,
                'ma_hoi_dap' => $questionId,
                'ma_khach_hang' => $customerId,
                'da_doc' => false,
                'link' => 'index.php?r=admin_questions',
                'created_at' => $now,
                'ngay_tao' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            error_log('question notification error: ' . $e->getMessage());
        }
    }

    public function listAdminQuestions(string $status = '', string $keyword = '', int $limit = 100): array {
        $filter = [];
        if ($status === 'answered') {
            $filter['tra_loi.noi_dung'] = ['$exists' => true, '$ne' => ''];
            $filter['trang_thai'] = ['$ne' => 'an'];
        } elseif ($status === 'pending') {
            $filter['$or'] = [['tra_loi' => null], ['tra_loi.noi_dung' => ['$exists' => false]], ['tra_loi.noi_dung' => '']];
            $filter['trang_thai'] = ['$ne' => 'an'];
        } elseif ($status === 'hidden') {
            $filter['trang_thai'] = 'an';
        } else {
            $filter['trang_thai'] = ['$ne' => 'an'];
        }

        $keyword = trim($keyword);

        $items = [];
        $cursor = $this->db->hoi_dap_san_pham->find($filter, ['sort' => ['ngay_hoi' => -1], 'limit' => max(10, min(300, $limit))]);
        foreach ($cursor as $doc) {
            $item = $this->normalizeQuestion((array)$doc);
            $product = $this->getProductBriefById((string)($item['ma_san_pham'] ?? ''));
            $item['ten_san_pham'] = $product['ten_san_pham'] ?? '';
            $item['thuong_hieu'] = $product['thuong_hieu'] ?? '';
            $item['link_hinh_anh'] = $product['link_hinh_anh'] ?? '';
            $item['product_missing'] = empty($product);
            if ($keyword !== '') {
                $haystack = implode(' ', [
                    $item['ma_hoi_dap'] ?? '',
                    $item['ma_san_pham'] ?? '',
                    $item['ten_san_pham'] ?? '',
                    $item['thuong_hieu'] ?? '',
                    $item['ten_khach_hang'] ?? '',
                    $item['cau_hoi'] ?? '',
                    !empty($item['tra_loi']['noi_dung']) ? 'answered đã trả lời' : 'pending chưa trả lời',
                ]);
                if (stripos($haystack, $keyword) === false) {
                    continue;
                }
            }
            $items[] = $item;
        }
        return $items;
    }

    public function answerQuestion(int $questionId, int $staffId, string $answer, string $staffName = ''): bool {
        $answer = trim($answer);
        if ($questionId <= 0 || $answer === '') return false;
        $result = $this->db->hoi_dap_san_pham->updateOne(
            ['ma_hoi_dap' => $questionId],
            ['$set' => [
                'tra_loi' => [
                    'noi_dung' => $answer,
                    'ngay_tra_loi' => new UTCDateTime((int)(microtime(true) * 1000)),
                    'ma_nhan_vien' => $staffId > 0 ? $staffId : null,
                    'ten_nhan_vien' => $staffName !== '' ? $staffName : 'SkinSyntax',
                ],
                'trang_thai' => 'hien_thi',
                'updated_at' => new UTCDateTime((int)(microtime(true) * 1000)),
            ]]
        );
        return $result->getMatchedCount() > 0;
    }

    public function hideQuestion(int $questionId): bool {
        if ($questionId <= 0) return false;
        $result = $this->db->hoi_dap_san_pham->updateOne(
            ['ma_hoi_dap' => $questionId],
            ['$set' => ['trang_thai' => 'an', 'updated_at' => new UTCDateTime((int)(microtime(true) * 1000))]]
        );
        return $result->getMatchedCount() > 0;
    }
}

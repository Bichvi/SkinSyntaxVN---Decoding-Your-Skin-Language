<?php
// Mongo cho nhãn công dụng (U07). Quyền ghi CHỈ gồm:
//   - san_pham: 6 trường nhãn (cong_dung_keys, van_de_da_keys, loai_da_keys, tag_source, tag_updated_at, tag_updated_by)
//   - tag_suggestions: status/reviewed_by/reviewed_at/approved của gợi ý LLM
// Mọi thao tác đi qua $db->collection (MongoDatabaseCompat | MongoDB\Database | đối tượng giả khi test).

require_once __DIR__ . '/../services/TagCatalog.php';
require_once __DIR__ . '/../services/TagCuration.php';

class TagSuggestion
{
    public const COLLECTION = 'tag_suggestions';
    public const STATUSES = ['pending', 'approved', 'rejected'];

    private $db;
    private ?string $lastError = null;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    // ---- helpers ------------------------------------------------------------------------------------------

    /** BSONDocument/BSONArray/ArrayObject -> mảng thuần (đệ quy). */
    public static function toArray($value)
    {
        if (is_object($value) && method_exists($value, 'toDateTime')) {
            return $value;      // UTCDateTime giữ nguyên
        }
        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::toArray($v);
            }
        }
        return $value;
    }

    /** Bộ lọc nhận diện sản phẩm (ma_san_pham dạng chuỗi hoặc số). */
    public static function productFilters($productId): array
    {
        $id = trim((string)$productId);
        $filters = [['ma_san_pham' => $id]];
        if ($id !== '' && ctype_digit($id)) {
            array_unshift($filters, ['ma_san_pham' => (int)$id]);
        }
        return $filters;
    }

    public function findProduct($productId): ?array
    {
        foreach (self::productFilters($productId) as $filter) {
            $doc = $this->db->san_pham->findOne($filter);
            if ($doc) {
                return self::toArray($doc);
            }
        }
        return null;
    }

    private function objectId(string $id)
    {
        if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
            return null;
        }
        try {
            return new \MongoDB\BSON\ObjectId($id);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function now()
    {
        return new \MongoDB\BSON\UTCDateTime();
    }

    // ---- ghi nhãn lên san_pham ----------------------------------------------------------------------------

    /**
     * Admin nhập tay. Trả mảng ['ok'=>bool,'message'=>string]. Chỉ `$set` 6 trường nhãn.
     *
     * @param array<string,string[]> $selection
     */
    public function saveManual($productId, array $selection, string $actorHash): array
    {
        return $this->writeTags($productId, $selection, TagCuration::SOURCE_MANUAL, $actorHash);
    }

    private function writeTags($productId, array $selection, string $source, string $actorHash): array
    {
        $set = TagCuration::productTagSet($selection, $source, $actorHash, $this->now());
        try {
            foreach (self::productFilters($productId) as $filter) {
                $res = $this->db->san_pham->updateOne($filter, ['$set' => $set]);
                if ($res->getMatchedCount() > 0) {
                    return ['ok' => true, 'message' => 'Đã lưu nhãn sản phẩm.'];
                }
            }
        } catch (Throwable $e) {
            $this->lastError = 'Lỗi lưu nhãn: ' . get_class($e);
            return ['ok' => false, 'message' => $this->lastError];
        }
        return ['ok' => false, 'message' => 'Không tìm thấy sản phẩm.'];
    }

    // ---- danh sách gợi ý ----------------------------------------------------------------------------------

    public function countByStatus(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        try {
            foreach (self::STATUSES as $status) {
                $out[$status] = (int)$this->db->{self::COLLECTION}->countDocuments(['status' => $status]);
            }
        } catch (Throwable $e) {
            $this->lastError = 'Không đọc được tag_suggestions';
        }
        return $out;
    }

    /**
     * Gợi ý theo trạng thái, kèm tên sản phẩm và nhãn hiện tại.
     *
     * @return array<int,array>
     */
    public function listByStatus(string $status, int $limit = 50, int $skip = 0, bool $onlyDiff = false): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'pending';
        }
        $filter = ['status' => $status];
        if ($onlyDiff) {
            $filter['diff.differs'] = true;
        }
        $rows = [];
        try {
            $cursor = $this->db->{self::COLLECTION}->find($filter, [
                'sort' => ['created_at' => -1, '_id' => -1],
                'limit' => max(1, min(200, $limit)),
                'skip' => max(0, $skip),
            ]);
            foreach ($cursor as $doc) {
                $row = self::toArray($doc);
                $row['id'] = (string)($row['_id'] ?? '');
                $row['product'] = $this->productBrief($row['ma_san_pham'] ?? '');
                $rows[] = $row;
            }
        } catch (Throwable $e) {
            $this->lastError = 'Không đọc được tag_suggestions';
        }
        return $rows;
    }

    private function productBrief($productId): array
    {
        $doc = $this->findProduct($productId) ?? [];
        $current = [];
        foreach (TagCatalog::FIELDS as $group => $field) {
            $current[$group] = TagCatalog::validate($group, is_array($doc[$field] ?? null) ? $doc[$field] : [])['valid'];
        }
        return [
            'name' => (string)($doc['ten_san_pham'] ?? ('Sản phẩm #' . $productId)),
            'tag_source' => (string)($doc['tag_source'] ?? ''),
            'current' => $current,
            'image' => (string)($doc['link_hinh_anh'] ?? ''),
        ];
    }

    public function find(string $id): ?array
    {
        $oid = $this->objectId($id);
        if ($oid === null) {
            return null;
        }
        $doc = $this->db->{self::COLLECTION}->findOne(['_id' => $oid]);
        return $doc ? self::toArray($doc) : null;
    }

    // ---- duyệt / từ chối ----------------------------------------------------------------------------------

    /**
     * Duyệt một gợi ý. `$override` (đã qua selectionFromInput) = admin sửa trước khi duyệt; null = giữ đề xuất LLM.
     * Sản phẩm đã có nhãn nhập tay (manual) không bị ghi đè: trả ok=false, reason='manual_locked'.
     *
     * @param array<string,string[]>|null $override
     * @return array{ok:bool,reason:string,message:string,product_id:string,edited:bool}
     */
    public function approve(string $suggestionId, ?array $override, string $actorHash): array
    {
        $fail = static fn(string $reason, string $message, string $pid = ''): array =>
            ['ok' => false, 'reason' => $reason, 'message' => $message, 'product_id' => $pid, 'edited' => false];
        $suggestion = $this->find($suggestionId);
        if ($suggestion === null) {
            return $fail('not_found', 'Không tìm thấy gợi ý.');
        }
        $pid = (string)($suggestion['ma_san_pham'] ?? '');
        if (($suggestion['status'] ?? '') !== 'pending') {
            return $fail('not_pending', 'Gợi ý này đã được xử lý.', $pid);
        }
        $product = $this->findProduct($pid);
        if ($product === null) {
            return $fail('no_product', 'Sản phẩm không còn tồn tại.', $pid);
        }
        if (($product['tag_source'] ?? '') === TagCuration::SOURCE_MANUAL) {
            return $fail('manual_locked', 'Sản phẩm đã có nhãn nhập tay (ưu tiên cao hơn), bỏ qua.', $pid);
        }

        $suggested = [];
        foreach (TagCatalog::FIELDS as $group => $_field) {
            $suggested[$group] = TagCatalog::validate($group, $suggestion['suggested'][$group] ?? [])['valid'];
        }
        $final = $override ?? $suggested;
        $edited = $override !== null && $override != $suggested;

        $written = $this->writeTags($pid, $final, TagCuration::SOURCE_LLM, $actorHash);
        if (!$written['ok']) {
            return $fail('write_failed', $written['message'], $pid);
        }
        $oid = $this->objectId($suggestionId);
        $update = [
            'status' => 'approved',
            'reviewed_by' => $actorHash,
            'reviewed_at' => $this->now(),
            'approved' => $final,
            'edited' => $edited,
        ];
        try {
            $this->db->{self::COLLECTION}->updateOne(['_id' => $oid, 'status' => 'pending'], ['$set' => $update]);
        } catch (Throwable $e) {
            $this->lastError = 'Đã ghi nhãn nhưng không cập nhật trạng thái gợi ý';
        }
        return ['ok' => true, 'reason' => '', 'message' => 'Đã duyệt.', 'product_id' => $pid, 'edited' => $edited];
    }

    public function reject(string $suggestionId, string $actorHash): array
    {
        $oid = $this->objectId($suggestionId);
        if ($oid === null) {
            return ['ok' => false, 'message' => 'Mã gợi ý không hợp lệ.'];
        }
        try {
            $res = $this->db->{self::COLLECTION}->updateOne(
                ['_id' => $oid, 'status' => 'pending'],
                ['$set' => ['status' => 'rejected', 'reviewed_by' => $actorHash, 'reviewed_at' => $this->now()]]
            );
            return $res->getModifiedCount() > 0
                ? ['ok' => true, 'message' => 'Đã từ chối.']
                : ['ok' => false, 'message' => 'Gợi ý không còn ở trạng thái chờ duyệt.'];
        } catch (Throwable $e) {
            $this->lastError = 'Lỗi từ chối gợi ý';
            return ['ok' => false, 'message' => $this->lastError];
        }
    }
}

<?php

/**
 * Persistence boundary for live commerce sessions.
 *
 * Live sessions are intentionally data-driven. This model must not seed demo
 * rooms or manufacture replay URLs when the database is empty or unavailable.
 */
class PhienLive {
    private $db;

    private const ALLOWED_STATUSES = [
        'scheduled', 'ready', 'live', 'interrupted', 'ended',
        'processing', 'replay_ready', 'recording_failed'
    ];

    public function __construct($db) {
        $this->db = $db;
    }

    private function idFilter($id): array {
        $value = trim((string)$id);
        $or = [
            ['ma_phong' => $value],
            ['ma_phong' => is_numeric($value) ? (int)$value : $value],
            ['id' => $value],
        ];
        if (class_exists('MongoDB\\BSON\\ObjectId') && preg_match('/^[a-f0-9]{24}$/i', $value)) {
            $or[] = ['_id' => new MongoDB\BSON\ObjectId($value)];
        }
        return ['$or' => $or];
    }

    private function nextNumericId(): int {
        try {
            $last = $this->db->phien_live->findOne([], ['sort' => ['ma_phong' => -1], 'projection' => ['ma_phong' => 1]]);
            return $last && is_numeric($last['ma_phong'] ?? null) ? ((int)$last['ma_phong'] + 1) : 1;
        } catch (Throwable $e) {
            return 1;
        }
    }

    public function getAllLives(): array {
        try {
            $cursor = $this->db->phien_live->find([], ['sort' => ['created_at' => -1, 'khung_gio_bat_dau' => -1]]);
            return array_map([$this, 'normalizeDoc'], iterator_to_array($cursor));
        } catch (Throwable $e) {
            // Fail closed with an empty state; never replace a DB failure with
            // fabricated rooms or video URLs.
            return [];
        }
    }

    public function getActiveLives(): array {
        return array_values(array_filter($this->getAllLives(), fn(array $live): bool => ($live['status'] ?? '') === 'live'));
    }

    public function getUpcomingLives(): array {
        return array_values(array_filter($this->getAllLives(), fn(array $live): bool => in_array(($live['status'] ?? ''), ['scheduled', 'ready'], true)));
    }

    public function findById($id): ?array {
        try {
            $doc = $this->db->phien_live->findOne($this->idFilter($id));
            return $doc ? $this->normalizeDoc($doc) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function getLiveById($id): ?array {
        return $this->findById($id);
    }

    public function taoPhienLive(array $data): bool {
        $title = trim((string)($data['tieu_de'] ?? ''));
        $streamer = trim((string)($data['streamer'] ?? ''));
        if ($title === '' || $streamer === '') return false;

        try {
            $now = date('Y-m-d H:i:s');
            $status = $this->normalizeInputStatus((string)($data['trang_thai'] ?? 'scheduled'));
            if ($status === 'live') $status = 'ready';
            $payload = [
                'ma_phong' => $this->nextNumericId(),
                'tieu_de' => mb_substr($title, 0, 180),
                'streamer' => mb_substr($streamer, 0, 120),
                'khung_gio_bat_dau' => trim((string)($data['khung_gio_bat_dau'] ?? '')),
                'khung_gio_ket_thuc' => trim((string)($data['khung_gio_ket_thuc'] ?? '')),
                'ma_san_pham_ghim' => trim((string)($data['ma_san_pham_ghim'] ?? '')),
                'gia_uu_dai_live' => max(0, (float)($data['gia_uu_dai_live'] ?? 0)),
                'so_luong_kho_deal' => max(0, (int)($data['so_luong_kho_deal'] ?? 0)),
                'bat_ai_cohost' => !empty($data['bat_ai_cohost']),
                'server_livekit_url' => trim((string)($data['server_livekit_url'] ?? (defined('LIVEKIT_URL') ? LIVEKIT_URL : ''))),
                'trang_thai' => $status,
                'stream_state' => 'offline',
                'stream_last_seen' => null,
                'recording_status' => 'not_started',
                'url_ban_ghi' => '',
                'recording_mime' => '',
                'recording_size' => 0,
                'recording_error' => '',
                'luot_xem' => 0,
                'reaction_count' => 0,
                'created_at' => $now,
                'updated_at' => $now
            ];
            $res = $this->db->phien_live->insertOne($payload);
            return $res->getInsertedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function capNhatPhienLive($id, array $data): bool {
        $allowed = [
            'tieu_de', 'streamer', 'khung_gio_bat_dau', 'khung_gio_ket_thuc',
            'ma_san_pham_ghim', 'gia_uu_dai_live', 'so_luong_kho_deal',
            'bat_ai_cohost', 'server_livekit_url', 'url_ban_ghi',
            'tom_tat_phien_live', 'trang_thai', 'recording_status',
            'recording_mime', 'recording_size', 'recording_error'
        ];
        $set = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) continue;
            if ($key === 'trang_thai') $set[$key] = $this->normalizeInputStatus((string)$data[$key]);
            elseif ($key === 'gia_uu_dai_live') $set[$key] = max(0, (float)$data[$key]);
            elseif ($key === 'so_luong_kho_deal' || $key === 'recording_size') $set[$key] = max(0, (int)$data[$key]);
            elseif ($key === 'bat_ai_cohost') $set[$key] = !empty($data[$key]);
            else $set[$key] = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
        }
        if (!$set) return false;
        $set['updated_at'] = date('Y-m-d H:i:s');
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$set' => $set]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function xoaPhienLive($id): bool {
        try {
            $res = $this->db->phien_live->deleteOne($this->idFilter($id));
            return $res->getDeletedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function doiTrangThai($id, string $status): bool {
        $status = $this->normalizeInputStatus($status);
        if (!in_array($status, self::ALLOWED_STATUSES, true)) return false;
        $set = ['trang_thai' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if ($status === 'ended') {
            $set['stream_state'] = 'offline';
            $set['stream_last_seen'] = null;
            $set['recording_status'] = 'processing';
        }
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$set' => $set]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function updateStreamState($id, string $state): bool {
        $state = strtolower(trim($state));
        if (!in_array($state, ['offline', 'live', 'interrupted', 'ended'], true)) return false;
        $set = ['stream_state' => $state, 'stream_last_seen' => $state === 'live' ? time() : null, 'updated_at' => date('Y-m-d H:i:s')];
        if ($state === 'live') {
            $set['trang_thai'] = 'live';
            $set['stream_started_at'] = time();
        } elseif ($state === 'interrupted') {
            $set['trang_thai'] = 'interrupted';
        } elseif ($state === 'ended') {
            $set['trang_thai'] = 'ended';
            $set['recording_status'] = 'processing';
        } else {
            $set['trang_thai'] = 'ready';
        }
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$set' => $set]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function touchStream($id): bool {
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$set' => [
                'stream_state' => 'live', 'stream_last_seen' => time(), 'trang_thai' => 'live', 'updated_at' => date('Y-m-d H:i:s')
            ]]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function tangLuotXem($id): bool {
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$inc' => ['luot_xem' => 1], '$set' => ['updated_at' => date('Y-m-d H:i:s')]]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function ghimSanPham($id, string $productId, ?float $livePrice = null, int $durationMinutes = 15, int $dealStock = 0): bool {
        $productId = trim($productId);
        if ($productId === '' || $dealStock < 1) return false;
        try {
            $now = date('Y-m-d H:i:s');
            $set = [
                'ma_san_pham_ghim' => $productId,
                'deal_bat_dau' => $now,
                'deal_ket_thuc' => date('Y-m-d H:i:s', strtotime('+' . max(1, $durationMinutes) . ' minutes')),
                'so_luong_kho_deal' => $dealStock,
                'updated_at' => $now
            ];
            if ($livePrice !== null && $livePrice > 0) $set['gia_uu_dai_live'] = $livePrice;
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$set' => $set]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function themDealSanPham($id, array $dealData): bool {
        $productId = trim((string)($dealData['ma_san_pham'] ?? ''));
        if ($productId === '') return false;
        $deal = [
            'deal_id' => 'deal_' . bin2hex(random_bytes(8)),
            'ma_san_pham' => $productId,
            'gia_uu_dai_live' => max(0, (float)($dealData['gia_uu_dai_live'] ?? 0)),
            'so_luong_kho_deal' => max(0, (int)($dealData['so_luong_kho_deal'] ?? 0)),
            'khung_gio_bat_dau' => trim((string)($dealData['khung_gio_bat_dau'] ?? '')),
            'khung_gio_ket_thuc' => trim((string)($dealData['khung_gio_ket_thuc'] ?? '')),
            'trang_thai' => trim((string)($dealData['trang_thai'] ?? 'scheduled')),
        ];
        if ($deal['so_luong_kho_deal'] < 1) return false;
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$push' => ['danh_sach_deal' => $deal], '$set' => ['updated_at' => date('Y-m-d H:i:s')]]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function xoaDealSanPham($id, string $dealId): bool {
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$pull' => ['danh_sach_deal' => ['deal_id' => $dealId]], '$set' => ['updated_at' => date('Y-m-d H:i:s')]]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function saveRecording($id, string $url, string $mime, int $size): bool {
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$set' => [
                'url_ban_ghi' => trim($url), 'recording_status' => 'replay_ready', 'recording_mime' => trim($mime),
                'recording_size' => max(0, $size), 'recording_error' => '', 'trang_thai' => 'replay_ready',
                'stream_state' => 'offline', 'updated_at' => date('Y-m-d H:i:s')
            ]]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function failRecording($id, string $message): bool {
        try {
            $res = $this->db->phien_live->updateOne($this->idFilter($id), ['$set' => [
                'recording_status' => 'recording_failed', 'recording_error' => mb_substr(trim($message), 0, 500),
                'trang_thai' => 'recording_failed', 'updated_at' => date('Y-m-d H:i:s')
            ]]);
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function luuTinNhanChat(string $roomId, string $senderName, string $message, bool $isAi = false, bool $isOrder = false, string $senderType = 'customer', string $senderId = '', string $clientMessageId = '', bool $isQuestion = false): bool {
        try {
            $this->db->phien_live_chats->insertOne([
                'room_id' => (string)$roomId, 'sender_id' => trim($senderId), 'sender_type' => trim($senderType) ?: ($isAi ? 'ai' : 'customer'),
                'sender_name' => mb_substr(trim($senderName), 0, 80), 'message' => mb_substr(trim($message), 0, 1000),
                'is_ai' => $isAi, 'is_order' => $isOrder, 'is_question' => $isQuestion, 'client_message_id' => trim($clientMessageId),
                'created_at' => date('Y-m-d H:i:s'), 'timestamp_ms' => (int)round(microtime(true) * 1000)
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getLichSuChat(string $roomId, int $limit = 100, string $mode = 'all'): array {
        try {
            $filter = ['room_id' => (string)$roomId];
            if ($mode === 'featured') $filter['$or'] = [['is_pinned' => true], ['is_order' => true]];
            if ($mode === 'qa') $filter['is_question'] = true;
            $cursor = $this->db->phien_live_chats->find($filter, ['sort' => ['timestamp_ms' => 1], 'limit' => min(200, max(1, $limit))]);
            $results = [];
            foreach ($cursor as $item) {
                $results[] = [
                    'id' => isset($item['_id']) ? (string)$item['_id'] : '', 'client_message_id' => (string)($item['client_message_id'] ?? ''),
                    'sender_id' => (string)($item['sender_id'] ?? ''), 'sender_type' => (string)($item['sender_type'] ?? (!empty($item['is_ai']) ? 'ai' : 'customer')),
                    'sender_name' => (string)($item['sender_name'] ?? 'Khách hàng'), 'message' => (string)($item['message'] ?? ''),
                    'is_ai' => !empty($item['is_ai']), 'is_order' => !empty($item['is_order']), 'is_question' => !empty($item['is_question']),
                    'is_pinned' => !empty($item['is_pinned']), 'created_at' => (string)($item['created_at'] ?? '')
                ];
            }
            return $results;
        } catch (Throwable $e) {
            return [];
        }
    }

    public function recordReaction(string $roomId, string $identity): int {
        try {
            $now = time();
            $this->db->phien_live_reactions->insertOne(['room_id' => $roomId, 'identity' => $identity, 'created_at' => $now]);
            $this->db->phien_live->updateOne($this->idFilter($roomId), ['$inc' => ['reaction_count' => 1]]);
            return (int)$this->db->phien_live_reactions->countDocuments(['room_id' => $roomId, 'created_at' => ['$gte' => $now - 3600]]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function normalizeInputStatus(string $status): string {
        $status = strtolower(trim($status));
        return match ($status) {
            'chuamoi', 'upcoming' => 'scheduled',
            'danglive' => 'ready',
            'ketthuc', 'cancelled' => 'ended',
            'tamdung', 'paused' => 'interrupted',
            default => in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'scheduled'
        };
    }

    private function resolveStatus(array $arr): string {
        $raw = strtolower(trim((string)($arr['trang_thai'] ?? 'scheduled')));
        $streamState = strtolower(trim((string)($arr['stream_state'] ?? '')));
        $lastSeen = (int)($arr['stream_last_seen'] ?? 0);
        $recording = strtolower(trim((string)($arr['recording_status'] ?? '')));
        if (in_array($raw, ['danglive', 'live'], true)) return $streamState === 'live' && $lastSeen >= (time() - 30) ? 'live' : 'ready';
        if (in_array($raw, ['chuamoi', 'upcoming'], true)) return 'scheduled';
        if (in_array($raw, ['ketthuc', 'cancelled'], true)) return $recording === 'replay_ready' || !empty($arr['url_ban_ghi']) ? 'replay_ready' : 'ended';
        if (in_array($raw, self::ALLOWED_STATUSES, true)) return $raw === 'live' && !($streamState === 'live' && $lastSeen >= (time() - 30)) ? 'ready' : $raw;
        return 'scheduled';
    }

    private function normalizeDoc($doc): array {
        $arr = (array)$doc;
        $arr['id'] = (string)($arr['ma_phong'] ?? $arr['id'] ?? '');
        $arr['tieu_de'] = (string)($arr['tieu_de'] ?? '');
        $arr['streamer'] = (string)($arr['streamer'] ?? '');
        $arr['khung_gio_bat_dau'] = (string)($arr['khung_gio_bat_dau'] ?? '');
        $arr['khung_gio_ket_thuc'] = (string)($arr['khung_gio_ket_thuc'] ?? '');
        $arr['ma_san_pham_ghim'] = (string)($arr['ma_san_pham_ghim'] ?? '');
        $arr['gia_uu_dai_live'] = (float)($arr['gia_uu_dai_live'] ?? 0);
        $arr['danh_sach_deal'] = is_array($arr['danh_sach_deal'] ?? null) ? json_decode(json_encode($arr['danh_sach_deal']), true) : [];
        $arr['bat_ai_cohost'] = !empty($arr['bat_ai_cohost']);
        $arr['server_livekit_url'] = (string)($arr['server_livekit_url'] ?? (defined('LIVEKIT_URL') ? LIVEKIT_URL : ''));
        $arr['url_ban_ghi'] = (string)($arr['url_ban_ghi'] ?? '');
        // Older development records used Google's public sample videos. They
        // are not SkinSyntax recordings and must never be presented as replay.
        if (str_contains($arr['url_ban_ghi'], 'commondatastorage.googleapis.com/gtv-videos-bucket/sample/')) {
            $arr['url_ban_ghi'] = '';
            $arr['recording_status'] = 'recording_failed';
        }
        $arr['stream_state'] = (string)($arr['stream_state'] ?? 'offline');
        $arr['stream_last_seen'] = (int)($arr['stream_last_seen'] ?? 0);
        $arr['recording_status'] = (string)($arr['recording_status'] ?? ($arr['url_ban_ghi'] !== '' ? 'replay_ready' : 'not_started'));
        $arr['recording_error'] = (string)($arr['recording_error'] ?? '');
        $arr['luot_xem'] = (int)($arr['luot_xem'] ?? 0);
        $arr['reaction_count'] = (int)($arr['reaction_count'] ?? 0);
        $arr['status'] = $this->resolveStatus($arr);
        return $arr;
    }
}

<?php

class PhienLive {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    private function getNextNumericId(): int {
        try {
            $lastDoc = $this->db->phien_live->findOne([], ['sort' => ['ma_phong' => -1]]);
            if ($lastDoc && isset($lastDoc['ma_phong']) && is_numeric($lastDoc['ma_phong'])) {
                return (int)$lastDoc['ma_phong'] + 1;
            }
        } catch (Throwable $e) {
            // Silence exception fallback
        }
        return 1;
    }

    public function getAllLives(): array {
        try {
            $cursor = $this->db->phien_live->find([], ['sort' => ['created_at' => -1]]);
            $results = iterator_to_array($cursor);
            if (empty($results)) {
                $this->seedDefaultLives();
                $cursor = $this->db->phien_live->find([], ['sort' => ['created_at' => -1]]);
                $results = iterator_to_array($cursor);
            }
            return array_map([$this, 'normalizeDoc'], $results);
        } catch (Throwable $e) {
            return $this->getDefaultMockLives();
        }
    }

    public function getActiveLives(): array {
        try {
            $cursor = $this->db->phien_live->find(
                ['$or' => [['trang_thai' => 'danglive'], ['trang_thai' => 'live']]],
                ['sort' => ['luot_xem' => -1]]
            );
            $results = array_map([$this, 'normalizeDoc'], iterator_to_array($cursor));
            if (empty($results)) {
                $all = $this->getAllLives();
                $results = array_values(array_filter($all, fn($item) => $item['trang_thai'] === 'danglive' || $item['trang_thai'] === 'live'));
            }
            return $results;
        } catch (Throwable $e) {
            return array_values(array_filter($this->getDefaultMockLives(), fn($item) => $item['trang_thai'] === 'danglive' || $item['trang_thai'] === 'live'));
        }
    }

    public function getUpcomingLives(): array {
        try {
            $cursor = $this->db->phien_live->find(
                ['$or' => [['trang_thai' => 'chuamoi'], ['trang_thai' => 'upcoming']]],
                ['sort' => ['khung_gio_bat_dau' => 1]]
            );
            return array_map([$this, 'normalizeDoc'], iterator_to_array($cursor));
        } catch (Throwable $e) {
            return array_values(array_filter($this->getDefaultMockLives(), fn($item) => $item['trang_thai'] === 'chuamoi' || $item['trang_thai'] === 'upcoming'));
        }
    }

    public function findById($id): ?array {
        try {
            $doc = $this->db->phien_live->findOne([
                '$or' => [
                    ['ma_phong' => (int)$id],
                    ['ma_phong' => (string)$id],
                    ['_id' => is_string($id) && strlen($id) === 24 ? new MongoDB\BSON\ObjectId($id) : $id]
                ]
            ]);
            return $doc ? $this->normalizeDoc($doc) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function taoPhienLive(array $data): bool {
        try {
            $maPhong = $this->getNextNumericId();
            $payload = [
                'ma_phong' => $maPhong,
                'tieu_de' => trim((string)($data['tieu_de'] ?? 'Phiên LiveStream AI Mới')),
                'streamer' => trim((string)($data['streamer'] ?? 'SkinSyntax Streamer & AI Co-Host')),
                'khung_gio_bat_dau' => trim((string)($data['khung_gio_bat_dau'] ?? date('Y-m-d H:i'))),
                'khung_gio_ket_thuc' => trim((string)($data['khung_gio_ket_thuc'] ?? date('Y-m-d H:i', strtotime('+2 hours')))),
                'ma_san_pham_ghim' => trim((string)($data['ma_san_pham_ghim'] ?? '5876')),
                'gia_uu_dai_live' => (float)($data['gia_uu_dai_live'] ?? 78000),
                'bat_ai_cohost' => !empty($data['bat_ai_cohost']),
                'server_livekit_url' => trim((string)($data['server_livekit_url'] ?? 'wss://skinsyntax-live.livekit.cloud')),
                'trang_thai' => trim((string)($data['trang_thai'] ?? 'danglive')),
                'luot_xem' => (int)($data['luot_xem'] ?? rand(500, 1500)),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $res = $this->db->phien_live->insertOne($payload);
            return $res->getInsertedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function capNhatPhienLive($id, array $data): bool {
        try {
            $payload = [
                'tieu_de' => trim((string)($data['tieu_de'] ?? '')),
                'streamer' => trim((string)($data['streamer'] ?? '')),
                'khung_gio_bat_dau' => trim((string)($data['khung_gio_bat_dau'] ?? '')),
                'khung_gio_ket_thuc' => trim((string)($data['khung_gio_ket_thuc'] ?? '')),
                'ma_san_pham_ghim' => trim((string)($data['ma_san_pham_ghim'] ?? '')),
                'gia_uu_dai_live' => (float)($data['gia_uu_dai_live'] ?? 0),
                'bat_ai_cohost' => !empty($data['bat_ai_cohost']),
                'server_livekit_url' => trim((string)($data['server_livekit_url'] ?? '')),
                'trang_thai' => trim((string)($data['trang_thai'] ?? 'danglive')),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $res = $this->db->phien_live->updateOne(
                ['$or' => [['ma_phong' => (int)$id], ['ma_phong' => (string)$id]]],
                ['$set' => array_filter($payload, fn($v) => $v !== '')]
            );
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function xoaPhienLive($id): bool {
        try {
            $res = $this->db->phien_live->deleteOne([
                '$or' => [['ma_phong' => (int)$id], ['ma_phong' => (string)$id]]
            ]);
            return $res->getDeletedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function doiTrangThai($id, string $status): bool {
        try {
            $res = $this->db->phien_live->updateOne(
                ['$or' => [['ma_phong' => (int)$id], ['ma_phong' => (string)$id]]],
                ['$set' => ['trang_thai' => $status, 'updated_at' => date('Y-m-d H:i:s')]]
            );
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function normalizeDoc($doc): array {
        if (!$doc) return [];
        $arr = (array)$doc;
        $arr['id'] = (string)($arr['ma_phong'] ?? $arr['_id'] ?? '');
        $arr['tieu_de'] = (string)($arr['tieu_de'] ?? '');
        $arr['streamer'] = (string)($arr['streamer'] ?? '');
        $arr['khung_gio_bat_dau'] = (string)($arr['khung_gio_bat_dau'] ?? '');
        $arr['khung_gio_ket_thuc'] = (string)($arr['khung_gio_ket_thuc'] ?? '');
        $arr['ma_san_pham_ghim'] = (string)($arr['ma_san_pham_ghim'] ?? '');
        $arr['gia_uu_dai_live'] = (float)($arr['gia_uu_dai_live'] ?? 0);
        $arr['bat_ai_cohost'] = !empty($arr['bat_ai_cohost']);
        $arr['server_livekit_url'] = (string)($arr['server_livekit_url'] ?? 'wss://skinsyntax-live.livekit.cloud');
        $arr['trang_thai'] = (string)($arr['trang_thai'] ?? 'danglive');
        $arr['luot_xem'] = (int)($arr['luot_xem'] ?? 0);
        return $arr;
    }

    private function seedDefaultLives(): void {
        try {
            $defaults = $this->getDefaultMockLives();
            foreach ($defaults as $item) {
                $this->db->phien_live->insertOne($item);
            }
        } catch (Throwable $e) {
            // Ignore duplicate insert
        }
    }

    public function getDefaultMockLives(): array {
        return [
            [
                'ma_phong' => 1,
                'tieu_de' => '🔴 LIVE: Gỡ Rối Routine Phục Hồi Da Dầu Mụn Với AI Skin Agent & Dược Sĩ',
                'streamer' => 'DS. Minh Trang & AI Co-Host',
                'khung_gio_bat_dau' => date('Y-m-d 19:00'),
                'khung_gio_ket_thuc' => date('Y-m-d 21:00'),
                'ma_san_pham_ghim' => '5876',
                'gia_uu_dai_live' => 78000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'danglive',
                'luot_xem' => 1420,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 2,
                'tieu_de' => '⚡ FLASH SALE LIVESTREAM: Chốt Đơn Tự Động 24/7 Với LiveKit & LLM AI',
                'streamer' => 'SkinSyntax Official Stream',
                'khung_gio_bat_dau' => date('Y-m-d 14:00'),
                'khung_gio_ket_thuc' => date('Y-m-d 16:00'),
                'ma_san_pham_ghim' => '5689',
                'gia_uu_dai_live' => 55000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'danglive',
                'luot_xem' => 980,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 3,
                'tieu_de' => '⏰ SẮP DIỄN RA (20:00): Hướng Dẫn Kết Hợp Niacinamide & BHA Cho Da Nhạy Cảm',
                'streamer' => 'Beauty Editor Thu Thảo',
                'khung_gio_bat_dau' => date('Y-m-d 20:00'),
                'khung_gio_ket_thuc' => date('Y-m-d 22:00'),
                'ma_san_pham_ghim' => '5933',
                'gia_uu_dai_live' => 99000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'chuamoi',
                'luot_xem' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];
    }
}

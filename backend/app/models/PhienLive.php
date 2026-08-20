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
                'url_ban_ghi' => trim((string)($data['url_ban_ghi'] ?? '')),
                'tom_tat_phien_live' => trim((string)($data['tom_tat_phien_live'] ?? '')),
                'trang_thai' => trim((string)($data['trang_thai'] ?? 'danglive')),
                'luot_xem' => (int)($data['luot_xem'] ?? rand(15, 45)),
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
                'url_ban_ghi' => trim((string)($data['url_ban_ghi'] ?? '')),
                'tom_tat_phien_live' => trim((string)($data['tom_tat_phien_live'] ?? '')),
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

    public function tangLuotXem($id): bool {
        try {
            $res = $this->db->phien_live->updateOne(
                ['$or' => [['ma_phong' => (int)$id], ['ma_phong' => (string)$id], ['id' => (string)$id]]],
                ['$inc' => ['luot_xem' => 1], '$set' => ['updated_at' => date('Y-m-d H:i:s')]]
            );
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function ghimSanPham($id, string $productId, ?float $livePrice = null, int $durationMinutes = 15): bool {
        try {
            $nowStr = date('Y-m-d H:i:s');
            $endStr = date('Y-m-d H:i:s', strtotime("+{$durationMinutes} minutes"));
            $set = [
                'ma_san_pham_ghim' => $productId,
                'deal_bat_dau' => $nowStr,
                'deal_ket_thuc' => $endStr,
                'updated_at' => $nowStr
            ];
            if ($livePrice !== null && $livePrice > 0) {
                $set['gia_uu_dai_live'] = $livePrice;
            }
            $res = $this->db->phien_live->updateOne(
                ['$or' => [['ma_phong' => (int)$id], ['ma_phong' => (string)$id]]],
                ['$set' => $set]
            );
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function themDealSanPham($id, array $dealData): bool {
        try {
            $dealItem = [
                'deal_id' => 'deal_' . uniqid(),
                'ma_san_pham' => trim((string)($dealData['ma_san_pham'] ?? '')),
                'gia_uu_dai_live' => (float)($dealData['gia_uu_dai_live'] ?? 0),
                'so_luong_kho_deal' => max(1, (int)($dealData['so_luong_kho_deal'] ?? 20)),
                'khung_gio_bat_dau' => trim((string)($dealData['khung_gio_bat_dau'] ?? date('H:i'))),
                'khung_gio_ket_thuc' => trim((string)($dealData['khung_gio_ket_thuc'] ?? date('H:i', strtotime('+30 minutes')))),
                'trang_thai' => trim((string)($dealData['trang_thai'] ?? 'sap_dien_ra'))
            ];
            if (empty($dealItem['ma_san_pham'])) return false;

            $res = $this->db->phien_live->updateOne(
                ['$or' => [['ma_phong' => (int)$id], ['ma_phong' => (string)$id]]],
                ['$push' => ['danh_sach_deal' => $dealItem]]
            );
            return $res->getModifiedCount() > 0 || $res->getMatchedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function xoaDealSanPham($id, string $dealId): bool {
        try {
            $res = $this->db->phien_live->updateOne(
                ['$or' => [['ma_phong' => (int)$id], ['ma_phong' => (string)$id]]],
                ['$pull' => ['danh_sach_deal' => ['deal_id' => $dealId]]]
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
        $arr['deal_bat_dau'] = (string)($arr['deal_bat_dau'] ?? '');
        $arr['deal_ket_thuc'] = (string)($arr['deal_ket_thuc'] ?? '');
        $arr['danh_sach_deal'] = is_array($arr['danh_sach_deal'] ?? null) ? json_decode(json_encode($arr['danh_sach_deal']), true) : [];
        $arr['bat_ai_cohost'] = !empty($arr['bat_ai_cohost']);
        $arr['server_livekit_url'] = (string)($arr['server_livekit_url'] ?? 'wss://skinsyntax-live.livekit.cloud');
        $arr['url_ban_ghi'] = (string)($arr['url_ban_ghi'] ?? '');
        $st = (string)($arr['trang_thai'] ?? 'danglive');
        if ($st === 'tamdung' || $st === 'paused') {
            $st = 'ketthuc';
        }
        $arr['trang_thai'] = $st;
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

    public function getThemedPresets(): array {
        return [
            'lancome' => [
                'tieu_de' => '🔴 LANCÔME BRAND DAY: Săn Nước Thần Clarifique & Kem Nền Che Phủ Giảm 84%',
                'streamer' => 'SkinSyntax Official & Lancôme Expert',
                'ma_san_pham_ghim' => '5689',
                'gia_uu_dai_live' => 55000,
                'bat_ai_cohost' => true,
                'chu_de_label' => 'Thương hiệu Lancôme'
            ],
            'serum' => [
                'tieu_de' => '💧 ĐẠI CHIẾN SERUM PHỤC HỒI: Top 5 Hyaluronic Acid & Peptide Đáng Mua Nhất',
                'streamer' => 'Chuyên Gia Da Liễu Khánh Linh',
                'ma_san_pham_ghim' => '5933',
                'gia_uu_dai_live' => 99000,
                'bat_ai_cohost' => true,
                'chu_de_label' => 'Dòng Serum & Tinh chất'
            ],
            'paulas_choice' => [
                'tieu_de' => ' PAULA\'S CHOICE SPECIAL: BHA 2% & Niacinamide Thu Nhỏ Lỗ Chân Lông',
                'streamer' => 'Beauty Editor Thu Thảo',
                'ma_san_pham_ghim' => '5876',
                'gia_uu_dai_live' => 120000,
                'bat_ai_cohost' => true,
                'chu_de_label' => 'Thương hiệu Paula\'s Choice'
            ],
            'la_roche_posay' => [
                'tieu_de' => '🔴 LA ROCHE-POSAY WORKSHOP: Phục Hồi Màng Lipid B5 Chuẩn Y Khoa Cho Da Nhạy Cảm',
                'streamer' => 'Bác Sĩ Hoàng Nam (SkinLab)',
                'ma_san_pham_ghim' => '5689',
                'gia_uu_dai_live' => 145000,
                'bat_ai_cohost' => true,
                'chu_de_label' => 'Thương hiệu La Roche-Posay'
            ],
            'sunscreen' => [
                'tieu_de' => '☀️ SĂN DEAL KEM CHỐNG NẮNG: Bảo Vệ Da Toàn Diện Khỏi UV & Ánh Sáng Xanh',
                'streamer' => 'KOL Thanh Hà & AI Assistant',
                'ma_san_pham_ghim' => '5933',
                'gia_uu_dai_live' => 89000,
                'bat_ai_cohost' => true,
                'chu_de_label' => 'Chuyên đề Kem Chống Nắng'
            ],
            'cleanser' => [
                'tieu_de' => '🌿 LÀM SẠCH SÂU CHUẨN Y KHOA: Nước Tẩy Trang Micellar & Gel Rửa Mặt Cho Da Dầu Mụn',
                'streamer' => 'Dược Sĩ Phương Anh',
                'ma_san_pham_ghim' => '5876',
                'gia_uu_dai_live' => 68000,
                'bat_ai_cohost' => true,
                'chu_de_label' => 'Chuyên đề Tẩy Trang & Sữa Rửa Mặt'
            ],
            'whitening' => [
                'tieu_de' => '✨ RETINOL & VITAMIN C: Bí Quyết Dưỡng Trắng, Mờ Thâm & Trẻ Hóa Đón Tết',
                'streamer' => 'Skincare Host Quỳnh Chi',
                'ma_san_pham_ghim' => '5689',
                'gia_uu_dai_live' => 159000,
                'bat_ai_cohost' => true,
                'chu_de_label' => 'Chuyên đề Dưỡng Trắng & Trẻ Hóa'
            ],
            'acne_care' => [
                'tieu_de' => '🔴 GỠ RỐI ROUTINE DA DẦU MỤN: Tự Động Tư Vấn 24/7 Với AI Co-Host & Dược Sĩ',
                'streamer' => 'DS. Minh Trang & AI Co-Host',
                'ma_san_pham_ghim' => '5876',
                'gia_uu_dai_live' => 78000,
                'bat_ai_cohost' => true,
                'chu_de_label' => 'Routine Da Dầu Mụn'
            ]
        ];
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
                'luot_xem' => 42,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 2,
                'tieu_de' => '🔴 LANCÔME BRAND DAY: Săn Nước Thần Clarifique & Kem Nền Che Phủ Giảm 84%',
                'streamer' => 'SkinSyntax Official & Lancôme Expert',
                'khung_gio_bat_dau' => date('Y-m-d 14:00'),
                'khung_gio_ket_thuc' => date('Y-m-d 16:00'),
                'ma_san_pham_ghim' => '5689',
                'gia_uu_dai_live' => 55000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'danglive',
                'luot_xem' => 28,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 3,
                'tieu_de' => '💧 ĐẠI CHIẾN SERUM PHỤC HỒI: Top 5 Hyaluronic Acid & Peptide Đáng Mua Nhất',
                'streamer' => 'Chuyên Gia Da Liễu Khánh Linh',
                'khung_gio_bat_dau' => date('Y-m-d 18:30'),
                'khung_gio_ket_thuc' => date('Y-m-d 20:30'),
                'ma_san_pham_ghim' => '5933',
                'gia_uu_dai_live' => 99000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'danglive',
                'luot_xem' => 35,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 4,
                'tieu_de' => ' SẮP DIỄN RA (20:00): Paula\'s Choice Special - BHA 2% & Niacinamide Thu Nhỏ Lỗ Chân Lông',
                'streamer' => 'Beauty Editor Thu Thảo',
                'khung_gio_bat_dau' => date('Y-m-d 20:00'),
                'khung_gio_ket_thuc' => date('Y-m-d 22:00'),
                'ma_san_pham_ghim' => '5876',
                'gia_uu_dai_live' => 120000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'chuamoi',
                'luot_xem' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 5,
                'tieu_de' => ' SẮP DIỄN RA (21:30): La Roche-Posay Workshop - Phục Hồi B5 Chuẩn Y Khoa Cho Da Nhạy Cảm',
                'streamer' => 'Bác Sĩ Hoàng Nam (SkinLab)',
                'khung_gio_bat_dau' => date('Y-m-d 21:30'),
                'khung_gio_ket_thuc' => date('Y-m-d 23:30'),
                'ma_san_pham_ghim' => '5689',
                'gia_uu_dai_live' => 145000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'chuamoi',
                'luot_xem' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 6,
                'tieu_de' => '☀️ SĂN DEAL KEM CHỐNG NẮNG: Bảo Vệ Da Toàn Diện Khỏi UV & Ánh Sáng Xanh',
                'streamer' => 'KOL Thanh Hà & AI Assistant',
                'khung_gio_bat_dau' => date('Y-m-d 22:00'),
                'khung_gio_ket_thuc' => date('Y-m-d 23:59'),
                'ma_san_pham_ghim' => '5933',
                'gia_uu_dai_live' => 89000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'chuamoi',
                'luot_xem' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 7,
                'tieu_de' => '🌿 LÀM SẠCH SÂU CHUẨN Y KHOA: Nước Tẩy Trang Micellar & Gel Rửa Mặt Cho Da Dầu Mụn',
                'streamer' => 'Dược Sĩ Phương Anh',
                'khung_gio_bat_dau' => date('Y-m-d 10:00', strtotime('+1 day')),
                'khung_gio_ket_thuc' => date('Y-m-d 12:00', strtotime('+1 day')),
                'ma_san_pham_ghim' => '5876',
                'gia_uu_dai_live' => 68000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'chuamoi',
                'luot_xem' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 8,
                'tieu_de' => '✨ RETINOL & VITAMIN C: Bí Quyết Dưỡng Trắng, Mờ Thâm & Trẻ Hóa Đón Tết',
                'streamer' => 'Skincare Host Quỳnh Chi',
                'khung_gio_bat_dau' => date('Y-m-d 15:00', strtotime('+1 day')),
                'khung_gio_ket_thuc' => date('Y-m-d 17:00', strtotime('+1 day')),
                'ma_san_pham_ghim' => '5689',
                'gia_uu_dai_live' => 159000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'trang_thai' => 'chuamoi',
                'luot_xem' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 9,
                'tieu_de' => '🎬 [XEM LẠI] BẢN GHI LIVE: Routine Phục Hồi Màng Lipid Da Nhạy Cảm & Dầu Mụn',
                'streamer' => 'DS. Minh Trang & AI Co-Host',
                'khung_gio_bat_dau' => date('Y-m-d 19:00', strtotime('-2 days')),
                'khung_gio_ket_thuc' => date('Y-m-d 21:00', strtotime('-2 days')),
                'ma_san_pham_ghim' => '5876',
                'gia_uu_dai_live' => 78000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'url_ban_ghi' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
                'tom_tat_phien_live' => "• Phân tích nguyên nhân suy giảm màng lipid da do sử dụng AHA/BHA quá liều.\n• Hướng dẫn kết hợp B5 Hyaluronic Acid giúp làm dịu mẩn đỏ trong 48h.\n• Khung giờ vàng áp dụng deal giảm 84% cho sản phẩm Lancôme Clarifique Essence.",
                'trang_thai' => 'ketthuc',
                'luot_xem' => 18,
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'ma_phong' => 10,
                'tieu_de' => '🎬 [XEM LẠI] BẢN GHI LIVE: Paula\'s Choice Workshop BHA 2% & Niacinamide',
                'streamer' => 'Beauty Editor Thu Thảo',
                'khung_gio_bat_dau' => date('Y-m-d 14:00', strtotime('-3 days')),
                'khung_gio_ket_thuc' => date('Y-m-d 16:00', strtotime('-3 days')),
                'ma_san_pham_ghim' => '5689',
                'gia_uu_dai_live' => 120000,
                'bat_ai_cohost' => true,
                'server_livekit_url' => 'wss://skinsyntax-live.livekit.cloud',
                'url_ban_ghi' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ElephantsDream.mp4',
                'tom_tat_phien_live' => "• Giải đáp thắc mắc hiện tượng đẩy mụn (purging) khi dùng Salicylic Acid 2%.\n• Routine tối giản 3 bước hỗ trợ se khít lỗ chân lông và làm đều màu da.\n• Ưu đãi đặc quyền tặng kèm sample Niacinamide 10% khi đặt hàng trong phiên.",
                'trang_thai' => 'ketthuc',
                'luot_xem' => 25,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    public function luuTinNhanChat(string $roomId, string $senderName, string $message, bool $isAi = false, bool $isOrder = false): bool {
        try {
            $doc = [
                'room_id' => (string)$roomId,
                'sender_name' => (string)$senderName,
                'message' => (string)$message,
                'is_ai' => (bool)$isAi,
                'is_order' => (bool)$isOrder,
                'created_at' => date('Y-m-d H:i:s'),
                'timestamp_ms' => (int)round(microtime(true) * 1000)
            ];
            $this->db->phien_live_chats->insertOne($doc);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getLichSuChat(string $roomId, int $limit = 100): array {
        try {
            $cursor = $this->db->phien_live_chats->find(
                ['room_id' => (string)$roomId],
                [
                    'sort' => ['created_at' => 1],
                    'limit' => max(1, $limit)
                ]
            );
            $items = iterator_to_array($cursor);
            $results = [];
            foreach ($items as $item) {
                $results[] = [
                    'id' => isset($item['_id']) ? (string)$item['_id'] : '',
                    'sender_name' => (string)($item['sender_name'] ?? 'Khách hàng'),
                    'message' => (string)($item['message'] ?? ''),
                    'is_ai' => !empty($item['is_ai']),
                    'is_order' => !empty($item['is_order']),
                    'created_at' => (string)($item['created_at'] ?? date('H:i:s'))
                ];
            }
            return $results;
        } catch (Throwable $e) {
            return [];
        }
    }
}

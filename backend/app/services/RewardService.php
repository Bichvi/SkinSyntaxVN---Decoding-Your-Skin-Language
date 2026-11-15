<?php
// backend/app/services/RewardService.php

require_once __DIR__ . '/RewardRules.php';
require_once __DIR__ . '/../models/RewardLedger.php';
require_once __DIR__ . '/../models/RewardCheckin.php';
require_once __DIR__ . '/../models/VideoWatchDaily.php';

/**
 * Điểm danh, mốc xem, xu theo video, tóm tắt và lịch sử xu.
 * Server là nguồn sự thật: mọi số xu/giây đều đọc từ sổ và bảng giây xem.
 */
class RewardService
{
    private $db;
    /** @var callable */
    private $resolveMaKh;
    /** @var callable */
    private $syncLoyalty;
    /** @var callable */
    private $clock;
    /** @var callable */
    private $pointsOf;

    public function __construct($db, ?callable $resolveMaKh = null, ?callable $syncLoyalty = null, ?callable $clock = null, ?callable $pointsOf = null)
    {
        $this->db = $db;
        $this->resolveMaKh = $resolveMaKh ?? function (string $email): ?int {
            require_once __DIR__ . '/../models/TaiKhoan.php';
            $kh = (new TaiKhoan($this->db))->getKhachHangByEmail($email);
            $maKh = (int) ($kh['ma_kh'] ?? 0);
            return $maKh > 0 ? $maKh : null;
        };
        $this->syncLoyalty = $syncLoyalty ?? function (int $maKh): void {
            require_once __DIR__ . '/../models/HoaDon.php';
            (new HoaDon($this->db))->syncLoyaltyForCustomer($maKh);
        };
        $this->clock = $clock ?? 'time';
        // Điểm tích lũy khả dụng (khach_hang.diemtl) của ma_kh; 0 nếu không có.
        $this->pointsOf = $pointsOf ?? function (int $maKh): int {
            $kh = $this->db->khach_hang->findOne(['ma_kh' => $maKh]);
            return $kh === null ? 0 : max(0, (int) ($kh['diemtl'] ?? 0));
        };
    }

    /** @return array{customer_id:string,ma_kh:int}|null */
    public function customerFromIdentity(array $identity): ?array
    {
        $customerId = trim((string) ($identity['customer_id'] ?? ''));
        $email = trim((string) ($identity['customer_email'] ?? ''));
        if ($customerId === '' || $email === '') {
            return null;
        }
        try {
            $maKh = (int) (($this->resolveMaKh)($email) ?? 0);
        } catch (\Throwable $e) {
            error_log('RewardService resolveMaKh failed: ' . get_class($e));
            return null;
        }
        return $maKh > 0 ? ['customer_id' => $customerId, 'ma_kh' => $maKh] : null;
    }

    public function awardVideoXu(array $identity, array $campaign, string $videoId): array
    {
        // Đường nóng (heartbeat): kiểm tra rẻ trước, không tra hồ sơ/tính số dư khi kết quả không phải OK.
        // Kết quả không OK trả xu_balance = null (không tính).
        $customerId = trim((string) ($identity['customer_id'] ?? ''));
        if ($customerId === '') {
            return $this->result(false, 'LOGIN_REQUIRED', 'Vui lòng đăng nhập để nhận xu.', 0, null);
        }
        $xu = RewardRules::normalizeVideoXu($campaign['reward_xu'] ?? 0);
        $campaignId = trim((string) ($campaign['campaign_id'] ?? ''));
        if ($xu <= 0 || $campaignId === '') {
            return $this->result(false, 'NO_XU', 'Chiến dịch này không có xu thưởng.', 0, null, true);
        }
        try {
            if ((new RewardLedger($this->db, false))->hasKey('video:' . $customerId . ':' . $campaignId)) {
                return $this->result(false, 'ALREADY_CLAIMED', 'Bạn đã nhận xu của video này rồi.', 0, null, true);
            }
        } catch (\Throwable $e) {
            error_log('RewardService hasKey failed: ' . get_class($e));
        }
        $who = $this->requireCustomer($identity);
        if (isset($who['code'])) {
            return $who;
        }
        $res = $this->ledger()->award([
            'customer_id' => $who['customer_id'],
            'ma_kh' => $who['ma_kh'],
            'source' => 'video',
            'source_key' => 'video:' . $who['customer_id'] . ':' . $campaignId,
            'amount_xu' => $xu,
            'date_vn' => $this->today(),
            'meta' => ['campaign_id' => $campaignId, 'video_id' => $videoId],
        ]);
        return $this->fromAward($res, $who['ma_kh'], 'ALREADY_CLAIMED', 'Bạn đã nhận xu của video này rồi.', 'Đã cộng xu xem video.', false);
    }

    public function checkin(array $identity): array
    {
        $who = $this->requireCustomer($identity);
        if (isset($who['code'])) {
            return $who;
        }
        $today = $this->today();
        $seconds = (new VideoWatchDaily($this->db))->secondsToday($who['customer_id'], $today);
        if ($seconds < RewardRules::CHECKIN_MIN_SECONDS) {
            return $this->result(false, 'NOT_ENOUGH_WATCH', 'Hãy xem video ít nhất 1 phút hôm nay để điểm danh.', 0, $who['ma_kh']);
        }
        $last = $this->lastCheckinRow($who['ma_kh']);
        if ($last !== null && ($last['date_vn'] ?? null) === $today) {
            return $this->result(false, 'ALREADY_CHECKED_IN', 'Hôm nay bạn đã điểm danh rồi.', 0, $who['ma_kh']);
        }
        $day = RewardRules::nextStreakDay(
            $last['date_vn'] ?? null,
            self::streakOf($last),
            $today
        );
        // Chuỗi điểm danh ghi trước và độc lập với sổ xu: chạm trần 300 xu vẫn giữ chuỗi.
        $checkins = new RewardCheckin($this->db);
        if (!$checkins->insert($who['customer_id'], $who['ma_kh'], $today, $day, 0)) {
            return $this->result(false, 'ALREADY_CHECKED_IN', 'Hôm nay bạn đã điểm danh rồi.', 0, $who['ma_kh'], true);
        }
        $intended = RewardRules::checkinXu($day);
        try {
            $res = $this->ledger()->award([
                'customer_id' => $who['customer_id'],
                'ma_kh' => $who['ma_kh'],
                'source' => 'checkin',
                'source_key' => 'checkin:' . $who['customer_id'] . ':' . $today,
                'amount_xu' => $intended,
                'date_vn' => $today,
                'meta' => ['streak_day' => $day],
            ]);
        } catch (\Throwable $e) {
            // Lỗi tạm thời: gỡ dòng điểm danh vừa ghi để lần thử lại vẫn nhận được xu.
            error_log('RewardService checkin award failed: ' . get_class($e));
            try {
                $checkins->delete($who['ma_kh'], $today);
            } catch (\Throwable $e2) {
                error_log('RewardService checkin rollback failed: ' . get_class($e2));
            }
            return $this->result(false, 'TEMPORARY_ERROR', 'Có lỗi tạm thời, vui lòng thử lại.', 0, $who['ma_kh']);
        }
        $awarded = ($res['code'] ?? '') === 'OK' ? (int) ($res['xu_awarded'] ?? 0) : 0;
        if ($awarded > 0) {
            try {
                $checkins->setXuAwarded($who['ma_kh'], $today, $awarded);
            } catch (\Throwable $e) {
                error_log('RewardService checkin setXuAwarded failed: ' . get_class($e));
            }
            try {
                ($this->syncLoyalty)($who['ma_kh']);
            } catch (\Throwable $e) {
                error_log('RewardService syncLoyalty failed: ' . get_class($e));
            }
        }
        $ledgerCode = (string) ($res['code'] ?? '');
        $capped = $ledgerCode === 'DAILY_CAP_REACHED' || ($ledgerCode === 'OK' && $awarded < $intended);
        $message = 'Đã điểm danh ngày ' . $day . ($capped ? ' (hôm nay đã đủ ' . RewardRules::DAILY_CAP_XU . ' xu)' : '');
        return $this->result(true, 'OK', $message, $awarded, $who['ma_kh']);
    }

    public function claimMilestone(array $identity, int $minutes): array
    {
        $who = $this->requireCustomer($identity);
        if (isset($who['code'])) {
            return $who;
        }
        $xu = RewardRules::milestoneXu($minutes);
        if ($xu === null) {
            return $this->result(false, 'INVALID_MILESTONE', 'Mốc xem không hợp lệ.', 0, $who['ma_kh']);
        }
        $today = $this->today();
        $seconds = (new VideoWatchDaily($this->db))->secondsToday($who['customer_id'], $today);
        if ($seconds < $minutes * 60) {
            return $this->result(false, 'NOT_ENOUGH_WATCH', 'Bạn chưa xem đủ thời gian cho mốc này.', 0, $who['ma_kh']);
        }
        $res = $this->ledger()->award([
            'customer_id' => $who['customer_id'],
            'ma_kh' => $who['ma_kh'],
            'source' => 'watch_milestone',
            'source_key' => $this->milestoneKey($who['customer_id'], $today, $minutes),
            'amount_xu' => $xu,
            'date_vn' => $today,
            'meta' => ['milestone_min' => $minutes],
        ]);
        return $this->fromAward($res, $who['ma_kh'], 'ALREADY_CLAIMED', 'Bạn đã nhận mốc này hôm nay rồi.', 'Đã cộng xu mốc xem.');
    }

    public function summary(array $identity): array
    {
        $who = $this->customerFromIdentity($identity);
        $code = 'OK';
        if ($who === null) {
            $code = trim((string) ($identity['customer_id'] ?? '')) === '' ? 'LOGIN_REQUIRED' : 'NO_CUSTOMER_PROFILE';
        }
        $today = $this->today();
        $balance = 0;
        $xuToday = 0;
        $watched = 0;
        $pointsAvailable = 0;
        $last = null;
        $claimed = [];
        if ($who !== null) {
            try {
                $ledger = new RewardLedger($this->db, false);
                $balance = $ledger->sumXu($who['ma_kh']);
                $xuToday = $ledger->sumXu($who['ma_kh'], $today);
                $last = $this->lastCheckinRow($who['ma_kh']);
                foreach (array_keys(RewardRules::MILESTONE_XU) as $min) {
                    $claimed[$min] = $ledger->hasKey($this->milestoneKey($who['customer_id'], $today, $min));
                }
                $watched = (new VideoWatchDaily($this->db, false))->secondsToday($who['customer_id'], $today);
                $pointsAvailable = max(0, (int) ($this->pointsOf)($who['ma_kh']));
            } catch (\Throwable $e) {
                error_log('RewardService summary failed: ' . get_class($e));
            }
        }

        $lastStreak = self::streakOf($last);
        $checkedInToday = $last !== null && ($last['date_vn'] ?? null) === $today;
        if ($checkedInToday) {
            $doneUpTo = $lastStreak;
            $nextDay = $lastStreak;
            $todayDay = 0;
        } else {
            $nextDay = RewardRules::nextStreakDay($last['date_vn'] ?? null, $lastStreak, $today);
            $doneUpTo = $nextDay - 1;
            $todayDay = $nextDay;
        }
        $days = [];
        foreach (RewardRules::CHECKIN_XU as $i => $xu) {
            $d = $i + 1;
            $state = $d <= $doneUpTo ? 'done' : ($d === $todayDay ? 'today' : 'upcoming');
            $days[] = ['day' => $d, 'xu' => $xu, 'state' => $state];
        }
        $secondsNeeded = max(0, RewardRules::CHECKIN_MIN_SECONDS - $watched);

        $milestones = [];
        foreach (RewardRules::MILESTONE_XU as $min => $xu) {
            $state = !empty($claimed[$min]) ? 'claimed' : ($watched >= $min * 60 ? 'ready' : 'locked');
            $milestones[] = ['minutes' => $min, 'xu' => $xu, 'state' => $state];
        }

        return [
            'code' => $code,
            'xu_balance' => $balance,
            'points_from_xu' => RewardRules::pointsFromXu($balance),
            'xu_pending' => $balance % RewardRules::XU_PER_POINT,
            'points_available' => $pointsAvailable,
            'xu_today' => $xuToday,
            'daily_cap' => RewardRules::DAILY_CAP_XU,
            'watched_seconds_today' => $watched,
            'checkin' => [
                'checked_in_today' => $checkedInToday,
                'next_day' => $nextDay,
                'next_xu' => RewardRules::checkinXu($checkedInToday ? $nextDay : $todayDay),
                'seconds_needed' => $secondsNeeded,
                'can_checkin' => !$checkedInToday && $secondsNeeded === 0 && $who !== null,
                'days' => $days,
            ],
            'milestones' => $milestones,
        ];
    }

    public function history(array $identity): array
    {
        $who = $this->customerFromIdentity($identity);
        if ($who === null) {
            return [];
        }
        $items = [];
        try {
            foreach ((new RewardLedger($this->db, false))->history($who['ma_kh'], 30) as $row) {
                $source = (string) ($row['source'] ?? '');
                $meta = (array) ($row['meta'] ?? []);
                if ($source === 'checkin') {
                    $label = 'Điểm danh ngày ' . (int) ($meta['streak_day'] ?? 1);
                } elseif ($source === 'watch_milestone') {
                    $label = 'Xem đủ ' . (int) ($meta['milestone_min'] ?? 0) . ' phút';
                } else {
                    $label = 'Xem video';
                }
                $items[] = [
                    'amount_xu' => (int) ($row['amount_xu'] ?? 0),
                    'source' => $source,
                    'label' => $label,
                    'date_vn' => (string) ($row['date_vn'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            error_log('RewardService history failed: ' . get_class($e));
        }
        return $items;
    }

    // ---- nội bộ ----

    private function today(): string
    {
        return RewardRules::dateVn((int) ($this->clock)());
    }

    /** Dòng điểm danh mới nhất: reward_checkins, nếu trống thì sổ xu cũ (tương thích ngược). */
    private function lastCheckinRow(int $maKh): ?array
    {
        $row = (new RewardCheckin($this->db, false))->last($maKh);
        if ($row !== null) {
            return $row;
        }
        return (new RewardLedger($this->db, false))->lastCheckin($maKh);
    }

    private static function streakOf(?array $row): int
    {
        if ($row === null) {
            return 0;
        }
        return (int) ($row['streak_day'] ?? ($row['meta']['streak_day'] ?? 0));
    }

    private function ledger(): RewardLedger
    {
        return new RewardLedger($this->db);
    }

    private function milestoneKey(string $customerId, string $dateVn, int $minutes): string
    {
        return 'milestone:' . $customerId . ':' . $dateVn . ':' . $minutes;
    }

    /** @return array customer ({customer_id, ma_kh}) hoặc mảng lỗi có khóa 'code'. */
    private function requireCustomer(array $identity): array
    {
        if (trim((string) ($identity['customer_id'] ?? '')) === '') {
            return $this->result(false, 'LOGIN_REQUIRED', 'Vui lòng đăng nhập để nhận xu.', 0, null);
        }
        $who = $this->customerFromIdentity($identity);
        if ($who === null) {
            return $this->result(false, 'NO_CUSTOMER_PROFILE', 'Tài khoản chưa có hồ sơ khách hàng để nhận xu.', 0, null);
        }
        return $who;
    }

    private function fromAward(array $res, int $maKh, string $dupCode, string $dupMessage, string $okMessage, bool $balanceOnFail = true): array
    {
        $code = (string) ($res['code'] ?? 'INVALID');
        if ($code === 'OK') {
            try {
                ($this->syncLoyalty)($maKh);
            } catch (\Throwable $e) {
                error_log('RewardService syncLoyalty failed: ' . get_class($e));
            }
            return $this->result(true, 'OK', $okMessage, (int) ($res['xu_awarded'] ?? 0), $maKh);
        }
        if ($code === 'DUPLICATE') {
            return $this->result(false, $dupCode, $dupMessage, 0, $balanceOnFail ? $maKh : null, true);
        }
        if ($code === 'DAILY_CAP_REACHED') {
            return $this->result(false, 'DAILY_CAP_REACHED', 'Hôm nay bạn đã đạt trần 300 xu.', 0, $balanceOnFail ? $maKh : null, true);
        }
        return $this->result(false, $code, 'Không thể cộng xu lúc này.', 0, $balanceOnFail ? $maKh : null, true);
    }

    private function result(bool $ok, string $code, string $message, int $awarded, ?int $maKh, bool $nullBalanceIfNoUser = false): array
    {
        $balance = ($maKh === null && $nullBalanceIfNoUser) ? null : 0;
        if ($maKh !== null) {
            try {
                $balance = (new RewardLedger($this->db, false))->sumXu($maKh);
            } catch (\Throwable $e) {
                error_log('RewardService balance failed: ' . get_class($e));
            }
        }
        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'xu_awarded' => $awarded, 'xu_balance' => $balance];
    }
}

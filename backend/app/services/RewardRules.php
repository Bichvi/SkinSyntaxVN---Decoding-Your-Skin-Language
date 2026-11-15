<?php
/**
 * Luật thưởng xu thuần (không DB, không framework).
 */
final class RewardRules
{
    public const TZ = 'Asia/Ho_Chi_Minh';
    public const XU_PER_POINT = 100;
    public const DAILY_CAP_XU = 300;
    public const CHECKIN_XU = [10, 10, 20, 10, 10, 20, 50];
    public const CHECKIN_MIN_SECONDS = 60;
    public const MILESTONE_XU = [5 => 10, 15 => 20, 30 => 40];
    public const VIDEO_XU_MAX = 100;
    public const VIDEO_XU_FORM_DEFAULT = 20;
    public const MAX_FIRST_CREDIT_SECONDS = 10.0;
    public const CREDIT_JITTER_SECONDS = 1.0;

    public static function dateVn(int $ts): string
    {
        return (new DateTimeImmutable('@' . $ts))
            ->setTimezone(new DateTimeZone(self::TZ))
            ->format('Y-m-d');
    }

    public static function previousDateVn(string $dateVn): string
    {
        return (new DateTimeImmutable($dateVn . ' 00:00:00', new DateTimeZone(self::TZ)))
            ->modify('-1 day')
            ->format('Y-m-d');
    }

    public static function nextStreakDay(?string $lastCheckinDate, int $lastStreakDay, string $todayVn): int
    {
        if ($lastCheckinDate !== null
            && $lastCheckinDate === self::previousDateVn($todayVn)
            && $lastStreakDay >= 1
            && $lastStreakDay < 7) {
            return $lastStreakDay + 1;
        }
        return 1;
    }

    public static function checkinXu(int $streakDay): int
    {
        return self::CHECKIN_XU[$streakDay - 1] ?? 0;
    }

    public static function trimToCap(int $amountXu, int $todayXu): int
    {
        return max(0, min($amountXu, self::DAILY_CAP_XU - $todayXu));
    }

    public static function pointsFromXu(int $totalXu): int
    {
        return intdiv(max(0, $totalXu), self::XU_PER_POINT);
    }

    public static function clampCredit(float $delta, float $now, ?float $lastCreditAt): float
    {
        $allowance = $lastCreditAt === null
            ? self::MAX_FIRST_CREDIT_SECONDS
            : max(0.0, $now - $lastCreditAt) + self::CREDIT_JITTER_SECONDS;
        return max(0.0, min($delta, $allowance));
    }

    public static function milestoneXu(int $minutes): ?int
    {
        return self::MILESTONE_XU[$minutes] ?? null;
    }

    public static function normalizeVideoXu($value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }
        return max(0, min(self::VIDEO_XU_MAX, (int) $value));
    }
}

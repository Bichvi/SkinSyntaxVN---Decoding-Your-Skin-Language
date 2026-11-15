<?php
// Test thuần PHP (không DB thật) cho RewardController + RewardCsrf.
// Chạy: php backend/tests/unit/RewardControllerTest.php
$root = realpath(__DIR__ . '/../../..');
require_once $root . '/backend/app/services/RewardRules.php';
require_once $root . '/backend/app/services/RewardService.php';
require_once $root . '/backend/app/services/RewardCsrf.php';
require_once $root . '/backend/app/controllers/RewardController.php';

$_SESSION = [];
$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

class FakeRewardService extends RewardService
{
    public $code;
    public function __construct(string $code) { $this->code = $code; }
    private function res(): array
    {
        return ['ok' => $this->code === 'OK', 'code' => $this->code, 'message' => 'm', 'xu_awarded' => 0, 'xu_balance' => 0];
    }
    public function checkin(array $identity): array { return $this->res(); }
    public function claimMilestone(array $identity, int $minutes): array { return $this->res(); }
    public function summary(array $identity): array { return ['xu_balance' => 0, 'checkin' => ['checked_in_today' => false]]; }
    public function history(array $identity): array { return [['amount_xu' => 20, 'source' => 'video', 'label' => 'Xem video', 'date_vn' => '2026-11-12']]; }
}

$user = fn() => ['customer_id' => 'u1', 'customer_email' => 'a@x.vn'];
$guest = fn() => ['customer_id' => null, 'customer_email' => null];
$ctrl = new RewardController(null, new FakeRewardService('OK'), $user);
$guestCtrl = new RewardController(null, new FakeRewardService('LOGIN_REQUIRED'), $guest);
$dupCtrl = new RewardController(null, new FakeRewardService('ALREADY_CHECKED_IN'), $user);

$check('summary khi chưa đăng nhập -> 401 LOGIN_REQUIRED', $guestCtrl->summaryResult()['status'] === 401);
$check('checkin GET -> 405', $ctrl->checkinResult('GET', RewardCsrf::token())['status'] === 405);
$check('checkin thiếu/sai CSRF -> 403 CSRF_INVALID', $ctrl->checkinResult('POST', 'sai')['body']['code'] === 'CSRF_INVALID');
$check('checkin OK -> 200 và body ok', $ctrl->checkinResult('POST', RewardCsrf::token())['status'] === 200);
$check('ALREADY_CHECKED_IN -> 409', $dupCtrl->checkinResult('POST', RewardCsrf::token())['status'] === 409);
$tmpCtrl = new RewardController(null, new FakeRewardService('TEMPORARY_ERROR'), $user);
$check('TEMPORARY_ERROR -> 503', $tmpCtrl->checkinResult('POST', RewardCsrf::token())['status'] === 503);
$check('milestone minutes không phải số -> 400 INVALID_MILESTONE', $ctrl->milestoneResult('POST', RewardCsrf::token(), ['minutes' => 'x'])['body']['code'] === 'INVALID_MILESTONE');
$check('token ổn định trong phiên, 64 hex', RewardCsrf::token() === RewardCsrf::token() && preg_match('/^[a-f0-9]{64}$/', RewardCsrf::token()) === 1);
$check('checkin OK ghi $_SESSION[reward_checked_in_date] = ngày VN hôm nay', ($_SESSION['reward_checked_in_date'] ?? '') === RewardRules::dateVn(time()));
$hist = $ctrl->historyResult();
$check('historyResult -> 200, body[items] là list đúng dòng', $hist['status'] === 200 && isset($hist['body']['items']) && array_values($hist['body']['items']) === $hist['body']['items'] && count($hist['body']['items']) === 1 && $hist['body']['items'][0]['amount_xu'] === 20);
$idx = file_get_contents($root . '/backend/public/index.php');
$check('5 route đã nối', count(array_filter(['phan_thuong','reward_summary','reward_history','reward_checkin','reward_milestone_claim'], fn($r) => strpos($idx, "case '$r':") !== false)) === 5);

echo "$pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);

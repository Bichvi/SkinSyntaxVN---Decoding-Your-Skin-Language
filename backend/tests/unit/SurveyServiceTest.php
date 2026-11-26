<?php

require_once __DIR__ . '/../../app/services/SurveyService.php';

function surveyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function surveyThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        return;
    }
    throw new RuntimeException($message);
}

$template = SurveyService::templateFor('recommendation', 'khach_hang');
surveyAssert(is_array($template), 'Customer recommendation template must exist.');
surveyAssert(count($template['questions']) === 5, 'Recommendation template must contain five single-criterion questions.');
surveyAssert(SurveyService::templateFor('recommendation', 'admin') === null, 'Admin must not receive the customer recommendation template.');

$rawAnswers = [];
foreach ($template['questions'] as $index => $question) {
    $rawAnswers[$question['id']] = (string)(($index % 5) + 1);
}
$answers = SurveyService::validateAnswers($template, $rawAnswers);
surveyAssert(count($answers) === 5, 'All valid answers must be retained.');

$missing = $rawAnswers;
array_pop($missing);
surveyThrows(fn() => SurveyService::validateAnswers($template, $missing), 'Missing answers must be rejected.');
$outsideRange = $rawAnswers;
$outsideRange[$template['questions'][0]['id']] = '6';
surveyThrows(fn() => SurveyService::validateAnswers($template, $outsideRange), 'Ratings outside 1..5 must be rejected.');
$unknownQuestion = $rawAnswers;
$unknownQuestion['FORGED_QUESTION'] = '5';
surveyThrows(fn() => SurveyService::validateAnswers($template, $unknownQuestion), 'Unknown question IDs must be rejected.');

$session = [];
$interactionId = SurveyService::issueInteraction($session, $template, 'khach_hang', 'account-42', 1_700_000_000);
$resolved = SurveyService::resolveInteraction($session, $interactionId, 'khach_hang', 'account-42', 1_700_000_001);
surveyAssert($resolved['template']['id'] === $template['id'], 'Server-issued interaction must resolve its exact template.');
surveyThrows(fn() => SurveyService::resolveInteraction($session, $interactionId, 'admin', 'account-42', 1_700_000_001), 'A forged role must not resolve another role interaction.');
surveyThrows(fn() => SurveyService::resolveInteraction($session, $interactionId, 'khach_hang', 'account-43', 1_700_000_001), 'Another account must not reuse the interaction.');
surveyThrows(fn() => SurveyService::resolveInteraction($session, $interactionId, 'khach_hang', 'account-42', 1_700_100_000), 'Expired interactions must be rejected.');

$participant = SurveyService::participantHash('khach_hang', 'account-42', 'test-secret-not-for-production');
$submittedAt = new DateTimeImmutable('2026-11-25 12:00:00', new DateTimeZone('Asia/Ho_Chi_Minh'));
$first = SurveyService::buildResponseDocument($template, 'khach_hang', $participant, $interactionId, $answers, 'Hữu ích', $submittedAt);
$retry = SurveyService::buildResponseDocument($template, 'khach_hang', $participant, $interactionId, $answers, 'Hữu ích', $submittedAt);
$another = SurveyService::buildResponseDocument($template, 'khach_hang', $participant, str_repeat('a', 32), $answers, '', $submittedAt);
surveyAssert($first['_id'] === $retry['_id'], 'A retry of the same interaction must have the same idempotency key.');
surveyAssert($first['_id'] !== $another['_id'], 'A different experience interaction must keep separate history.');
surveyAssert(!array_key_exists('user_id', $first), 'Stored responses must not contain raw user IDs.');

$legacy = [
    '_id' => 'legacy-1',
    'participant' => hash('sha256', 'legacy'),
    'feature' => 'recommendation',
    'role' => 'khach_hang',
    'rating' => 4,
    'comment' => 'Dữ liệu cũ',
    'question_version' => 1,
    'submitted_at' => $submittedAt,
];
$summary = SurveyService::summarize([$first, $retry, $legacy]);
surveyAssert($summary['participants'] === 2, 'Unique participants must be counted independently from survey attempts.');
surveyAssert($summary['surveys'] === 3, 'Each valid document must count as one survey response.');
surveyAssert($summary['answers'] === 11, 'Two v2 responses and one legacy response must produce 5 + 5 + 1 answers.');
surveyAssert($summary['average'] === 3.09 && $summary['positive_count'] === 5, 'Mean and top-two counts use answer denominators.');
surveyAssert($summary['distribution'] === [1 => 2, 2 => 2, 3 => 2, 4 => 3, 5 => 2], 'All five bins are counted.');
$ordered = $first;
$ordered['answers'][0]['rating'] = 4;
$medians = array_column(SurveyService::summarize([$first, $ordered])['criteria'], 'median', 'question_id');
surveyAssert($medians['REC_EASE'] === 2.5, 'Median averages the two center positions for even samples.');
surveyAssert(SurveyService::templateFor('syna_chat', 'admin') === null, 'SYNA survey is limited to customer accounts.');
surveyAssert($summary['legacy_surveys'] === 1, 'Legacy responses must remain explicitly identified.');
$legacyCriteria = array_values(array_filter($summary['criteria'], static fn(array $row): bool => $row['question_id'] === 'LEGACY_OVERALL'));
surveyAssert(count($legacyCriteria) === 1 && $legacyCriteria[0]['count'] === 1, 'A legacy rating must never be expanded into new questions.');
surveyAssert(count(SurveyService::exportRows([$first])) === 5, 'De-identified response export must contain one row per answer.');

echo "SurveyServiceTest: PASS\n";

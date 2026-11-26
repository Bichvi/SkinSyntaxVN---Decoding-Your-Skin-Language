<?php

final class SurveyService
{
    public const SCALE = [
        1 => 'Hoàn toàn không đồng ý',
        2 => 'Không đồng ý',
        3 => 'Trung lập',
        4 => 'Đồng ý',
        5 => 'Hoàn toàn đồng ý',
    ];

    private const INTERACTION_TTL_SECONDS = 86400;

    public static function templates(): array
    {
        return [
            'recommendation-v2' => [
                'id' => 'recommendation-v2',
                'version' => 2,
                'feature' => 'recommendation',
                'feature_label' => 'Gợi ý sản phẩm',
                'roles' => ['khach_hang'],
                'questions' => [
                    ['id' => 'REC_EASE', 'criterion' => 'ease_of_use', 'criterion_label' => 'Tính dễ sử dụng', 'text' => 'Tôi dễ dàng thực hiện các bước để nhận gợi ý sản phẩm.'],
                    ['id' => 'REC_RELEVANCE', 'criterion' => 'relevance', 'criterion_label' => 'Mức độ phù hợp', 'text' => 'Các sản phẩm được gợi ý phù hợp với nhu cầu chăm sóc da của tôi.'],
                    ['id' => 'REC_EXPLANATION', 'criterion' => 'information_quality', 'criterion_label' => 'Chất lượng giải thích', 'text' => 'Phần giải thích giúp tôi hiểu vì sao sản phẩm được gợi ý.'],
                    ['id' => 'REC_SPEED', 'criterion' => 'response_time', 'criterion_label' => 'Thời gian phản hồi', 'text' => 'Kết quả gợi ý được hiển thị trong thời gian hợp lý.'],
                    ['id' => 'REC_SATISFACTION', 'criterion' => 'overall_satisfaction', 'criterion_label' => 'Hài lòng tổng thể', 'text' => 'Nhìn chung, tôi hài lòng với trải nghiệm gợi ý sản phẩm.'],
                ],
            ],
            'syna-chat-v2' => [
                'id' => 'syna-chat-v2',
                'version' => 2,
                'feature' => 'syna_chat',
                'feature_label' => 'Tư vấn SYNA',
                'roles' => ['khach_hang'],
                'questions' => [
                    ['id' => 'SYNA_EASE', 'criterion' => 'ease_of_use', 'criterion_label' => 'Tính dễ sử dụng', 'text' => 'Tôi dễ dàng gửi câu hỏi cho SYNA.'],
                    ['id' => 'SYNA_USEFUL', 'criterion' => 'usefulness', 'criterion_label' => 'Mức độ hữu ích', 'text' => 'Câu trả lời của SYNA hữu ích với tôi.'],
                    ['id' => 'SYNA_RELEVANCE', 'criterion' => 'relevance', 'criterion_label' => 'Mức độ phù hợp', 'text' => 'Câu trả lời của SYNA phù hợp với nhu cầu tư vấn của tôi.'],
                    ['id' => 'SYNA_SPEED', 'criterion' => 'response_time', 'criterion_label' => 'Thời gian phản hồi', 'text' => 'SYNA phản hồi trong thời gian hợp lý.'],
                    ['id' => 'SYNA_SATISFACTION', 'criterion' => 'overall_satisfaction', 'criterion_label' => 'Hài lòng tổng thể', 'text' => 'Nhìn chung, tôi hài lòng với trải nghiệm tư vấn của SYNA.'],
                ],
            ],
            'customer-account-v2' => [
                'id' => 'customer-account-v2',
                'version' => 2,
                'feature' => 'customer_account',
                'feature_label' => 'Tài khoản khách hàng',
                'roles' => ['khach_hang'],
                'questions' => [
                    ['id' => 'CUS_EASE', 'criterion' => 'ease_of_use', 'criterion_label' => 'Tính dễ sử dụng', 'text' => 'Tôi dễ dàng tìm thấy thông tin cần thiết trong khu vực tài khoản.'],
                    ['id' => 'CUS_INFO', 'criterion' => 'information_quality', 'criterion_label' => 'Chất lượng thông tin', 'text' => 'Thông tin đơn hàng, hồ sơ và quyền lợi được trình bày rõ ràng.'],
                    ['id' => 'CUS_USEFUL', 'criterion' => 'usefulness', 'criterion_label' => 'Mức độ hữu ích', 'text' => 'Khu vực tài khoản hỗ trợ tốt việc quản lý hoạt động mua sắm của tôi.'],
                    ['id' => 'CUS_SATISFACTION', 'criterion' => 'overall_satisfaction', 'criterion_label' => 'Hài lòng tổng thể', 'text' => 'Nhìn chung, tôi hài lòng với khu vực tài khoản.'],
                    ['id' => 'CUS_CONTINUE', 'criterion' => 'continued_use', 'criterion_label' => 'Ý định tiếp tục sử dụng', 'text' => 'Tôi có ý định tiếp tục sử dụng khu vực tài khoản khi mua sắm trên SkinSyntaxVN.'],
                ],
            ],
            'staff-workspace-v2' => [
                'id' => 'staff-workspace-v2',
                'version' => 2,
                'feature' => 'staff_workspace',
                'feature_label' => 'Công cụ nhân viên',
                'roles' => ['nhanvien'],
                'questions' => [
                    ['id' => 'STF_EASE', 'criterion' => 'ease_of_use', 'criterion_label' => 'Tính dễ sử dụng', 'text' => 'Tôi dễ dàng thực hiện các tác vụ được giao trên khu vực làm việc.'],
                    ['id' => 'STF_INFO', 'criterion' => 'information_quality', 'criterion_label' => 'Chất lượng thông tin', 'text' => 'Thông tin cần xử lý được trình bày rõ ràng.'],
                    ['id' => 'STF_EFFICIENCY', 'criterion' => 'workflow_efficiency', 'criterion_label' => 'Hiệu quả nghiệp vụ', 'text' => 'Công cụ giúp tôi hoàn thành công việc hiệu quả hơn.'],
                    ['id' => 'STF_SPEED', 'criterion' => 'response_time', 'criterion_label' => 'Thời gian phản hồi', 'text' => 'Các thao tác nghiệp vụ phản hồi trong thời gian hợp lý.'],
                    ['id' => 'STF_SATISFACTION', 'criterion' => 'overall_satisfaction', 'criterion_label' => 'Hài lòng tổng thể', 'text' => 'Nhìn chung, tôi hài lòng với công cụ làm việc dành cho nhân viên.'],
                ],
            ],
            'admin-workspace-v2' => [
                'id' => 'admin-workspace-v2',
                'version' => 2,
                'feature' => 'admin_workspace',
                'feature_label' => 'Không gian quản trị',
                'roles' => ['admin'],
                'questions' => [
                    ['id' => 'ADM_EASE', 'criterion' => 'ease_of_use', 'criterion_label' => 'Tính dễ sử dụng', 'text' => 'Tôi dễ dàng tìm và sử dụng các chức năng quản trị cần thiết.'],
                    ['id' => 'ADM_INFO', 'criterion' => 'information_quality', 'criterion_label' => 'Chất lượng thông tin', 'text' => 'Thông tin vận hành trên trang quản trị được trình bày rõ ràng.'],
                    ['id' => 'ADM_USEFUL', 'criterion' => 'usefulness', 'criterion_label' => 'Mức độ hữu ích', 'text' => 'Các công cụ quản trị đáp ứng nhu cầu theo dõi và điều hành hệ thống.'],
                    ['id' => 'ADM_EFFICIENCY', 'criterion' => 'workflow_efficiency', 'criterion_label' => 'Hiệu quả nghiệp vụ', 'text' => 'Trang quản trị giúp tôi hoàn thành tác vụ vận hành hiệu quả hơn.'],
                    ['id' => 'ADM_SATISFACTION', 'criterion' => 'overall_satisfaction', 'criterion_label' => 'Hài lòng tổng thể', 'text' => 'Nhìn chung, tôi hài lòng với trải nghiệm quản trị SkinSyntaxVN.'],
                ],
            ],
        ];
    }

    public static function templateFor(string $feature, string $role, ?int $version = null): ?array
    {
        foreach (self::templates() as $template) {
            if ($template['feature'] !== $feature || !in_array($role, $template['roles'], true)) {
                continue;
            }
            if ($version !== null && (int)$template['version'] !== $version) {
                continue;
            }
            return $template;
        }
        return null;
    }

    public static function accountId(array $user): string
    {
        return trim((string)($user['ma_nv'] ?? $user['id'] ?? ''));
    }

    public static function issueInteraction(array &$session, array $template, string $role, string $accountId, ?int $now = null): string
    {
        $now ??= time();
        $items = is_array($session['survey_interactions'] ?? null) ? $session['survey_interactions'] : [];
        foreach ($items as $id => $entry) {
            if (!is_array($entry) || $now - (int)($entry['issued_at'] ?? 0) > self::INTERACTION_TTL_SECONDS) {
                unset($items[$id]);
            }
        }
        $items = array_slice($items, -63, null, true);
        $interactionId = bin2hex(random_bytes(16));
        $items[$interactionId] = [
            'template_id' => $template['id'],
            'template_version' => (int)$template['version'],
            'feature' => $template['feature'],
            'role' => $role,
            'account_id' => $accountId,
            'issued_at' => $now,
        ];
        $session['survey_interactions'] = $items;
        return $interactionId;
    }

    public static function resolveInteraction(array $session, string $interactionId, string $role, string $accountId, ?int $now = null): array
    {
        $now ??= time();
        if (!preg_match('/^[a-f0-9]{32}$/D', $interactionId)) {
            throw new InvalidArgumentException('Lượt khảo sát không hợp lệ. Hãy tải lại trang.');
        }
        $entry = $session['survey_interactions'][$interactionId] ?? null;
        if (!is_array($entry) || ($entry['role'] ?? '') !== $role ||
            $accountId === '' || ($entry['account_id'] ?? '') !== $accountId ||
            $now - (int)($entry['issued_at'] ?? 0) > self::INTERACTION_TTL_SECONDS) {
            throw new InvalidArgumentException('Lượt khảo sát đã hết hạn. Hãy tải lại trang để tiếp tục.');
        }
        $template = self::templateFor((string)($entry['feature'] ?? ''), $role, (int)($entry['template_version'] ?? 0));
        if ($template === null || $template['id'] !== ($entry['template_id'] ?? '')) {
            throw new InvalidArgumentException('Phiên bản khảo sát không được hỗ trợ.');
        }
        return ['interaction' => $entry, 'template' => $template];
    }

    public static function validateAnswers(array $template, $rawAnswers): array
    {
        if (!is_array($rawAnswers)) {
            throw new InvalidArgumentException('Vui lòng trả lời đầy đủ các câu hỏi.');
        }
        $questionMap = [];
        foreach ($template['questions'] as $question) {
            $questionMap[$question['id']] = $question;
        }
        if (count($rawAnswers) !== count($questionMap) || array_diff(array_keys($rawAnswers), array_keys($questionMap))) {
            throw new InvalidArgumentException('Danh sách câu hỏi không khớp với phiên bản khảo sát.');
        }
        $answers = [];
        foreach ($questionMap as $questionId => $question) {
            $rawRating = $rawAnswers[$questionId] ?? null;
            if ((!is_string($rawRating) && !is_int($rawRating)) || !preg_match('/^[1-5]$/D', (string)$rawRating)) {
                throw new InvalidArgumentException('Vui lòng chọn một mức từ 1 đến 5 cho mỗi câu hỏi.');
            }
            $answers[] = [
                'question_id' => $questionId,
                'criterion' => $question['criterion'],
                'criterion_label' => $question['criterion_label'],
                'question_text' => $question['text'],
                'rating' => (int)$rawRating,
            ];
        }
        return $answers;
    }

    public static function participantHash(string $role, string $accountId, string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            throw new RuntimeException('SURVEY_PARTICIPANT_SECRET chưa được cấu hình.');
        }
        return hash_hmac('sha256', $role . ':' . $accountId, $secret);
    }

    public static function buildResponseDocument(
        array $template,
        string $role,
        string $participant,
        string $interactionId,
        array $answers,
        string $comment,
        $submittedAt = null
    ): array {
        if (mb_strlen($comment) > 1000) {
            throw new InvalidArgumentException('Góp ý không được dài quá 1.000 ký tự.');
        }
        $responseId = hash('sha256', implode(':', [
            $participant,
            $interactionId,
            $template['id'],
            (string)$template['version'],
        ]));
        return [
            '_id' => $responseId,
            'schema_version' => 2,
            'template_id' => $template['id'],
            'template_version' => (int)$template['version'],
            'feature' => $template['feature'],
            'role' => $role,
            'participant' => $participant,
            'interaction_id' => $interactionId,
            'answers' => $answers,
            'comment' => $comment,
            'submitted_at' => $submittedAt ?? new \MongoDB\BSON\UTCDateTime(),
        ];
    }

    public static function summarize(iterable $documents): array
    {
        $participants = [];
        $distribution = array_fill_keys(range(1, 5), 0);
        $criteria = [];
        $groups = ['feature' => [], 'role' => [], 'version' => []];
        $comments = [];
        $surveys = 0;
        $answerCount = 0;
        $satisfied = 0;
        $legacySurveys = 0;

        foreach ($documents as $rawDocument) {
            $document = self::normalizeDocument(self::arrayValue($rawDocument));
            if ($document === null) {
                continue;
            }
            $surveys++;
            if ($document['legacy']) {
                $legacySurveys++;
            }
            $participant = (string)$document['participant'];
            if ($participant !== '') {
                $participants[$participant] = true;
            }
            self::addGroupSurvey($groups['feature'], (string)$document['feature'], $participant);
            self::addGroupSurvey($groups['role'], (string)$document['role'], $participant);
            $versionKey = 'v' . $document['template_version'];
            self::addGroupSurvey($groups['version'], $versionKey, $participant);
            foreach ($document['answers'] as $answer) {
                $rating = (int)$answer['rating'];
                $answerCount++;
                $distribution[$rating]++;
                if ($rating >= 4) {
                    $satisfied++;
                }
                $criterionKey = implode('|', [
                    $document['template_id'],
                    (string)$document['template_version'],
                    $document['role'],
                    $answer['question_id'],
                ]);
                if (!isset($criteria[$criterionKey])) {
                    $criteria[$criterionKey] = [
                        'template_id' => $document['template_id'],
                        'version' => $document['template_version'],
                        'feature' => $document['feature'],
                        'role' => $document['role'],
                        'question_id' => $answer['question_id'],
                        'criterion' => $answer['criterion'],
                        'criterion_label' => $answer['criterion_label'],
                        'question_text' => $answer['question_text'],
                        'count' => 0,
                        'sum' => 0,
                        'satisfied' => 0,
                        'distribution' => array_fill_keys(range(1, 5), 0),
                    ];
                }
                $criteria[$criterionKey]['count']++;
                $criteria[$criterionKey]['sum'] += $rating;
                $criteria[$criterionKey]['distribution'][$rating]++;
                if ($rating >= 4) {
                    $criteria[$criterionKey]['satisfied']++;
                }
                foreach (['feature', 'role'] as $dimension) {
                    self::addGroupAnswer($groups[$dimension], (string)$document[$dimension], $participant, $rating);
                }
                self::addGroupAnswer($groups['version'], $versionKey, $participant, $rating);
            }
            $comment = trim((string)$document['comment']);
            if ($comment !== '') {
                $comments[] = [
                    'feature' => $document['feature'],
                    'role' => $document['role'],
                    'template_id' => $document['template_id'],
                    'version' => $document['template_version'],
                    'participant_code' => substr($participant, 0, 12),
                    'comment' => $comment,
                    'submitted_at' => self::dateText($document['submitted_at']),
                ];
            }
        }

        foreach ($criteria as &$criterion) {
            $criterion['average'] = $criterion['count'] > 0 ? round($criterion['sum'] / $criterion['count'], 2) : null;
            $criterion['median'] = self::median($criterion['distribution']);
            $criterion['positive_count'] = $criterion['satisfied'];
            $criterion['satisfaction_rate'] = $criterion['count'] > 0 ? round(100 * $criterion['satisfied'] / $criterion['count'], 1) : 0.0;
            unset($criterion['sum'], $criterion['satisfied']);
        }
        unset($criterion);
        foreach ($groups as &$dimensionGroups) {
            foreach ($dimensionGroups as &$group) {
                $group['participants'] = count($group['_participants']);
                $group['satisfaction_rate'] = $group['answers'] > 0 ? round(100 * $group['satisfied'] / $group['answers'], 1) : 0.0;
                unset($group['_participants'], $group['satisfied']);
            }
            unset($group);
            ksort($dimensionGroups);
        }
        unset($dimensionGroups);
        usort($comments, static fn(array $a, array $b): int => strcmp($b['submitted_at'], $a['submitted_at']));
        usort($criteria, static fn(array $a, array $b): int => [$a['feature'], $a['version'], $a['question_id']] <=> [$b['feature'], $b['version'], $b['question_id']]);

        return [
            'participants' => count($participants),
            'surveys' => $surveys,
            'answers' => $answerCount,
            'average' => $answerCount ? round(array_sum(array_map(static fn($score, $count) => $score * $count, array_keys($distribution), $distribution)) / $answerCount, 2) : null,
            'positive_count' => $satisfied,
            'satisfaction_rate' => $answerCount > 0 ? round(100 * $satisfied / $answerCount, 1) : 0.0,
            'distribution' => $distribution,
            'criteria' => array_values($criteria),
            'groups' => $groups,
            'comments' => $comments,
            'legacy_surveys' => $legacySurveys,
        ];
    }

    private static function median(array $distribution): ?float
    {
        $n = array_sum($distribution);
        if ($n === 0) return null;
        $remaining = [intdiv($n + 1, 2), intdiv($n, 2) + 1];
        $middle = 0;
        foreach ($distribution as $score => $count) {
            foreach ($remaining as $index => $position) {
                if ($position <= $count) {
                    $middle += $score;
                    unset($remaining[$index]);
                } else {
                    $remaining[$index] -= $count;
                }
            }
        }
        return $middle / 2;
    }

    public static function exportRows(iterable $documents): array
    {
        $rows = [];
        foreach ($documents as $rawDocument) {
            $document = self::normalizeDocument(self::arrayValue($rawDocument));
            if ($document === null) {
                continue;
            }
            foreach ($document['answers'] as $answer) {
                $rows[] = [
                    'participant_code' => substr((string)$document['participant'], 0, 12),
                    'feature' => $document['feature'],
                    'role' => $document['role'],
                    'template_id' => $document['template_id'],
                    'template_version' => $document['template_version'],
                    'question_id' => $answer['question_id'],
                    'criterion' => $answer['criterion'],
                    'question_text' => $answer['question_text'],
                    'rating' => $answer['rating'],
                    'submitted_at' => self::dateText($document['submitted_at']),
                    'legacy' => $document['legacy'] ? 1 : 0,
                ];
            }
        }
        return $rows;
    }

    private static function normalizeDocument(array $document): ?array
    {
        $ratingAnswers = [];
        $answers = self::arrayValue($document['answers'] ?? []);
        $isV2 = ($document['schema_version'] ?? null) === 2;
        $questionIds = [];
        if ($answers !== []) {
            foreach ($answers as $rawAnswer) {
                $answer = self::arrayValue($rawAnswer);
                $rating = $answer['rating'] ?? null;
                $questionId = trim((string)($answer['question_id'] ?? ''));
                if (!is_int($rating) || $rating < 1 || $rating > 5 || $questionId === '' || isset($questionIds[$questionId])) {
                    if ($isV2) return null;
                    continue;
                }
                $questionIds[$questionId] = true;
                $ratingAnswers[] = [
                    'question_id' => $questionId,
                    'criterion' => (string)($answer['criterion'] ?? 'unknown'),
                    'criterion_label' => (string)($answer['criterion_label'] ?? $questionId),
                    'question_text' => (string)($answer['question_text'] ?? $questionId),
                    'rating' => $rating,
                ];
            }
        }
        if ($isV2) {
            $template = self::templates()[(string)($document['template_id'] ?? '')] ?? null;
            if ($template === null || $template['version'] !== ($document['template_version'] ?? null) ||
                $template['feature'] !== ($document['feature'] ?? null) ||
                !in_array($document['role'] ?? null, $template['roles'], true) ||
                count($ratingAnswers) !== count($template['questions']) ||
                array_diff(array_column($template['questions'], 'id'), array_keys($questionIds))) {
                return null;
            }
        }
        $legacy = false;
        if ($ratingAnswers === []) {
            $rating = (int)($document['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) {
                return null;
            }
            $legacy = true;
            $ratingAnswers[] = [
                'question_id' => 'LEGACY_OVERALL',
                'criterion' => 'legacy_overall',
                'criterion_label' => 'Câu hỏi tổng hợp cũ',
                'question_text' => 'Câu hỏi tổng hợp của module khảo sát phiên bản 1.',
                'rating' => $rating,
            ];
        }
        $version = (int)($document['template_version'] ?? $document['question_version'] ?? 1);
        $feature = (string)($document['feature'] ?? 'unknown');
        return [
            'template_id' => (string)($document['template_id'] ?? ('legacy-' . $feature)),
            'template_version' => max(1, $version),
            'feature' => $feature,
            'role' => (string)($document['role'] ?? 'unknown'),
            'participant' => (string)($document['participant'] ?? ''),
            'answers' => $ratingAnswers,
            'comment' => (string)($document['comment'] ?? ''),
            'submitted_at' => $document['submitted_at'] ?? null,
            'legacy' => $legacy,
        ];
    }

    private static function addGroupAnswer(array &$groups, string $key, string $participant, int $rating): void
    {
        $key = $key !== '' ? $key : 'unknown';
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'surveys' => 0,
                'answers' => 0,
                'satisfied' => 0,
                'distribution' => array_fill_keys(range(1, 5), 0),
                '_participants' => [],
            ];
        }
        $groups[$key]['answers']++;
        $groups[$key]['distribution'][$rating]++;
        if ($rating >= 4) {
            $groups[$key]['satisfied']++;
        }
        if ($participant !== '') {
            $groups[$key]['_participants'][$participant] = true;
        }
    }

    private static function addGroupSurvey(array &$groups, string $key, string $participant): void
    {
        $key = $key !== '' ? $key : 'unknown';
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'surveys' => 0,
                'answers' => 0,
                'satisfied' => 0,
                'distribution' => array_fill_keys(range(1, 5), 0),
                '_participants' => [],
            ];
        }
        $groups[$key]['surveys']++;
        if ($participant !== '') {
            $groups[$key]['_participants'][$participant] = true;
        }
    }

    private static function arrayValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }
        return [];
    }

    private static function dateText($value): string
    {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        }
        if ($value instanceof DateTimeInterface) {
            return $value->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        }
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return '';
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? $text : date('Y-m-d H:i:s', $timestamp);
    }
}

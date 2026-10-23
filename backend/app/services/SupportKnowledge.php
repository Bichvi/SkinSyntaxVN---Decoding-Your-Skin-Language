<?php
declare(strict_types=1);

/** Authored store facts, shared by the website and its chat BFF. No model promises. */
final class SupportKnowledge
{
    /** rank(): bài có điểm dưới ngưỡng này bị bỏ (câu sản phẩm/da không được hút vào chính sách). */
    public const LOW_THRESHOLD = 3.0;
    /** Flask lỗi mà bài đầu đạt ngưỡng này thì PHP tự trả bản soạn sẵn. */
    public const HIGH_THRESHOLD = 8.0;
    /** Câu khách trùng nguyên văn một câu trong `questions`. */
    public const EXACT_SCORE = 1000.0;
    /** Giới hạn ký tự nội dung một bài gửi sang Flask. */
    public const CONTEXT_CHAR_LIMIT = 2500;

    /** Từ đệm đã fold; không tính điểm token. */
    public const STOPWORDS = ['cho', 'minh', 'toi', 'ban', 'thi', 'a', 'nhe', 'voi', 'la', 'cua', 'co', 'khong', 'sao', 'nao', 'the', 'lam', 'duoc', 'gi', 'o', 'dau', 'oi', 'shop', 'em', 'anh', 'chi', 'vay', 'ha'];

    /** Lời chào/cảm ơn: câu chỉ gồm các từ này (và stopword) không có bài hỗ trợ. */
    private const GREETING_TOKENS = ['xin', 'chao', 'hello', 'hi', 'hey', 'alo', 'cam', 'on', 'thanks', 'thank', 'ok', 'oke', 'okay', 'bye', 'tam', 'biet'];

    /** Viết tắt/tiếng lóng phổ biến, chỉ dùng khi xếp hạng (match() không đổi). */
    private const ABBREVIATIONS = ['ko' => 'khong', 'hok' => 'khong','dc' => 'duoc', 'dk' => 'duoc', 'sdt' => 'so dien thoai', 'mk' => 'mat khau', 'pass' => 'mat khau', 'password' => 'mat khau', 'acc' => 'tai khoan', 'tk' => 'tai khoan', 'ki' => 'ky'];

    private const FIELD_WEIGHTS = ['title' => 3.0, 'questions' => 2.0, 'triggers' => 2.0, 'summary' => 1.0];

    private array $data;

    /** Chỉ mục token theo path: [slug => [field => [token => true]]]. */
    private static array $tokenIndex = [];
    private string $path;

    /** Parsed JSON per file path: one read/parse per request however many callers ask. */
    private static array $cache = [];

    public function __construct(?string $path = null)
    {
        $path = $path ?? __DIR__ . '/../content/support.json';
        $this->path = $path;
        if (!isset(self::$cache[$path])) {
            self::$cache[$path] = json_decode((string)@file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
        $this->data = self::$cache[$path];
    }

    /** Text of a section item: a plain string or {"text": string, "details": string[]}. */
    public static function itemText(mixed $item): string
    {
        if (is_string($item)) return $item;
        if (is_array($item) && is_string($item['text'] ?? null)) return $item['text'];
        return '';
    }

    /** Level-4 details of a section item; plain strings and malformed items have none. */
    public static function itemDetails(mixed $item): array
    {
        if (!is_array($item) || !is_array($item['details'] ?? null)) return [];
        return array_values(array_filter($item['details'], static fn($detail) => is_string($detail) && $detail !== ''));
    }

    /** Single source for hotline/email/hours. Never throws: unreadable file -> empty values. */
    public static function contact(): array
    {
        $empty = ['phone' => '', 'email' => '', 'hours' => ''];
        try {
            $contact = (new self())->data['contact'] ?? [];
        } catch (Throwable $error) {
            error_log('Support contact unavailable');
            return $empty;
        }
        return [
            'phone' => is_string($contact['phone'] ?? null) ? $contact['phone'] : '',
            'email' => is_string($contact['email'] ?? null) ? $contact['email'] : '',
            'hours' => is_string($contact['hours'] ?? null) ? $contact['hours'] : '',
        ];
    }

    public static function fold(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $groups = ['a'=>'àáạảãâầấậẩẫăằắặẳẵ', 'e'=>'èéẹẻẽêềếệểễ', 'i'=>'ìíịỉĩ', 'o'=>'òóọỏõôồốộổỗơờớợởỡ', 'u'=>'ùúụủũưừứựửữ', 'y'=>'ỳýỵỷỹ', 'd'=>'đ'];
        foreach ($groups as $ascii => $letters) {
            $text = str_replace(preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY), $ascii, $text);
        }
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^a-z0-9%.,\s]/u', ' ', $text)));
    }

    public function data(): array { return $this->data; }

    public function article(string $slug): ?array
    {
        foreach ($this->data['articles'] as $article) {
            if ($article['slug'] === $slug) return $article;
        }
        return null;
    }

    public function match(string $message): ?array
    {
        $query = self::fold($message);
        $best = null;
        $bestScore = 0;
        foreach ($this->data['articles'] as $article) {
            $score = $this->phraseScore($article, $query);
            if ($score > $bestScore) { $best = $article; $bestScore = $score; }
            foreach ($article['questions'] as $question) {
                if ($query === self::fold($question)) return $article;
            }
        }
        return $best;
    }

    /** Best phrase score of one article for an already-folded query; 0 when no trigger/alias matches. */
    private function phraseScore(array $article, string $query): int
    {
        $best = 0;
        $phrases = array_merge($article['triggers'], $this->data['routing']['aliases'][$article['slug']] ?? []);
        foreach ($phrases as $phrase) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($phrase, '/') . '(?![a-z0-9])/', $query)) {
                // An explicit requested operation outranks incidental order/payment context.
                $score = strlen($phrase) + (preg_match('/^(huy|sua|doi|xoa|khoi phuc|quen|reset|forgot)\b/', $phrase) ? 30 : 0)
                    + ($this->data['routing']['priority'][$article['slug']] ?? 0);
                if ($score > $best) $best = $score;
            }
        }
        return $best;
    }

    public function answer(string $message, string $baseUrl): ?array
    {
        $article = $this->match($message);
        if ($article === null) return null;
        $url = $baseUrl . '/index.php?r=tro_giup&bai=' . rawurlencode($article['slug']);
        return [
            'ok' => true, 'answer' => $article['answer'] . "\n\n[" . $article['title'] . '](' . $url . ')',
            'products' => [], 'conflicts' => [], 'intent_mode' => 'STORE_SUPPORT',
            'pipeline_mode' => 'verified_store_content', 'support_topic' => $article['slug'],
            'sources' => [['title' => $article['title'], 'url' => $url, 'version' => $this->data['version']]],
        ];
    }

    // ---------------------------------------------------------------- xếp hạng cho chatbot

    /**
     * Xếp hạng bài hỗ trợ theo câu hỏi: điểm cụm của match() + điểm trùng token.
     * Trả [['article'=>array,'score'=>float,'url'=>string], ...] giảm dần, chỉ điểm >= LOW_THRESHOLD.
     */
    public function rank(string $message, int $limit = 3, string $baseUrl = ''): array
    {
        $raw = self::fold(mb_substr($message, 0, 300, 'UTF-8'));
        $tokens = $this->queryTokens($raw);
        if ($raw === '' || $tokens === [] || $limit < 1) return [];
        if (array_diff($tokens, self::GREETING_TOKENS) === []) return [];

        $phraseQuery = implode(' ', $this->expandedWords($raw));
        $index = $this->tokenIndex();
        $baseUrl = $baseUrl !== '' ? $baseUrl : (defined('BASE_URL') ? (string)BASE_URL : '');
        $scored = [];
        foreach ($this->data['articles'] as $position => $article) {
            $exact = false;
            foreach ($article['questions'] as $question) {
                if ($raw === self::fold($question)) { $exact = true; break; }
            }
            $score = $exact
                ? self::EXACT_SCORE
                : (float)$this->phraseScore($article, $phraseQuery) + $this->tokenScore($tokens, $index[$article['slug']] ?? []);
            if ($score < self::LOW_THRESHOLD) continue;
            $scored[] = [$score, $position, [
                'article' => $article,
                'score' => round($score, 3),
                'url' => $baseUrl . '/index.php?r=tro_giup&bai=' . rawurlencode($article['slug']),
            ]];
        }
        usort($scored, static fn($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
        return array_column(array_slice($scored, 0, $limit), 2);
    }

    /** Nội dung gọn gửi sang Flask: mỗi bài <= CONTEXT_CHAR_LIMIT ký tự, cắt item cuối trước. */
    public function toChatContext(array $ranked): array
    {
        $out = [];
        foreach ($ranked as $entry) {
            $article = $entry['article'] ?? null;
            if (!is_array($article)) continue;
            $title = (string)($article['title'] ?? '');
            $summary = (string)($article['summary'] ?? '');
            $answer = (string)($article['answer'] ?? '');
            $questions = array_values(array_filter($article['questions'] ?? [], 'is_string'));
            $fixed = mb_strlen($title) + mb_strlen($summary) + array_sum(array_map('mb_strlen', $questions));
            $budget = self::CONTEXT_CHAR_LIMIT - $fixed - mb_strlen($answer);
            $sections = [];
            if ($budget < 0) {
                $answer = self::cutAtWord($answer, max(0, mb_strlen($answer) + $budget));
            } else {
                foreach ($article['sections'] ?? [] as $section) {
                    $items = [];
                    $sectionTitle = (string)($section['title'] ?? '');
                    $sectionCost = mb_strlen($sectionTitle);
                    foreach ($section['items'] ?? [] as $item) {
                        $details = self::itemDetails($item);
                        $cost = mb_strlen(self::itemText($item)) + array_sum(array_map('mb_strlen', $details)) + ($items === [] ? $sectionCost : 0);
                        if ($cost > $budget) { $budget = -1; break; }
                        $budget -= $cost;
                        $items[] = ['text' => self::itemText($item), 'details' => $details];
                    }
                    if ($items !== []) $sections[] = ['title' => $sectionTitle, 'items' => $items];
                    if ($budget < 0) break;
                }
            }
            $out[] = [
                'slug' => (string)($article['slug'] ?? ''), 'title' => $title, 'summary' => $summary, 'answer' => $answer,
                'sections' => $sections, 'url' => (string)($entry['url'] ?? ''), 'version' => (string)($this->data['version'] ?? ''),
                'score' => (float)($entry['score'] ?? 0), 'questions' => $questions,
            ];
        }
        return $out;
    }

    /** Câu hỏi gợi ý (<= 3) từ `questions` của các bài đã xếp hạng, luân phiên giữa các bài, bỏ câu trùng câu khách. */
    public function suggestedQuestions(array $ranked, string $message): array
    {
        $asked = self::fold($message);
        $lists = [];
        foreach ($ranked as $entry) $lists[] = array_values(array_filter($entry['article']['questions'] ?? [], 'is_string'));
        $out = [];
        for ($round = 0; $round < 5 && count($out) < 3; $round++) {
            foreach ($lists as $questions) {
                $question = $questions[$round] ?? null;
                if ($question === null || self::fold($question) === $asked || in_array($question, $out, true)) continue;
                $out[] = $question;
                if (count($out) === 3) break;
            }
        }
        return $out;
    }

    /** Bản soạn sẵn từ bài đầu: answer, các bước "1. …" của section đầu, dòng Nguồn. */
    public function formatFallbackAnswer(array $ranked): string
    {
        $article = $ranked[0]['article'] ?? null;
        if (!is_array($article)) return '';
        $parts = [trim((string)$article['answer'])];
        $steps = [];
        foreach (array_slice($article['sections'][0]['items'] ?? [], 0, 10) as $item) {
            $text = trim(self::itemText($item));
            if ($text !== '') $steps[] = (count($steps) + 1) . '. ' . $text;
        }
        if ($steps !== []) $parts[] = implode("\n", $steps);
        $parts[] = 'Nguồn: [' . $article['title'] . '](' . ($ranked[0]['url'] ?? '') . ')';
        return implode("\n\n", $parts);
    }

    /** Phản hồi khi Flask lỗi: chỉ khi bài đầu >= HIGH_THRESHOLD, ngược lại null (giữ câu lỗi cũ). */
    public function fallbackResponse(array $ranked, string $message): ?array
    {
        if ($ranked === [] || (float)($ranked[0]['score'] ?? 0) < self::HIGH_THRESHOLD) return null;
        $article = $ranked[0]['article'];
        return [
            'answer' => $this->formatFallbackAnswer($ranked),
            'pipeline_mode' => 'verified_store_content',
            'intent_mode' => 'STORE_SUPPORT',
            'fallback' => true,
            'support_topic' => (string)$article['slug'],
            'sources' => [['title' => (string)$article['title'], 'url' => (string)$ranked[0]['url'], 'version' => (string)($this->data['version'] ?? '')]],
            'suggested_questions' => $this->suggestedQuestions($ranked, $message),
        ];
    }

    /** Thêm khóa store_support_articles vào payload prompt; danh sách rỗng -> payload giữ nguyên. */
    public static function withSupportArticles(array $payload, array $articles): array
    {
        if ($articles !== []) $payload['store_support_articles'] = $articles;
        return $payload;
    }

    /**
     * Câu hỏi khớp bài hỗ trợ (>= LOW_THRESHOLD) -> KHÔNG đọc/ghi cache phản hồi AI (cache 7 ngày theo câu hỏi
     * từng giữ đáp án cũ không theo chính sách hiện hành). Nhận kết quả rank() đã có, không rank lại.
     */
    public static function shouldBypassChatCache(array $ranked): bool
    {
        foreach ($ranked as $entry) {
            if (is_array($entry) && is_numeric($entry['score'] ?? null) && (float)$entry['score'] >= self::LOW_THRESHOLD) return true;
        }
        return false;
    }

    /** Flask coi là lỗi: không 2xx (gồm timeout, status 0) hoặc 2xx nhưng thân không phải JSON. */
    public static function flaskFailed(array $response, ?array $decoded): bool
    {
        $status = (int)($response['status'] ?? 0);
        return $status < 200 || $status >= 300 || $decoded === null;
    }

    /** Các khóa STORE_SUPPORT từ phản hồi Flask để chuyển tiếp cho widget; intent khác -> []. */
    public static function flaskSupportFields(?array $decoded): array
    {
        if (!is_array($decoded) || ($decoded['intent_mode'] ?? '') !== 'STORE_SUPPORT') return [];
        $sources = [];
        foreach (is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [] as $source) {
            if (!is_array($source) || !is_string($source['url'] ?? null)) continue;
            $sources[] = [
                'title' => is_string($source['title'] ?? null) ? $source['title'] : '',
                'url' => $source['url'],
                'version' => is_string($source['version'] ?? null) ? $source['version'] : '',
            ];
        }
        $questions = array_values(array_filter(is_array($decoded['suggested_questions'] ?? null) ? $decoded['suggested_questions'] : [], static fn($q) => is_string($q) && $q !== ''));
        return [
            'support_topic' => is_string($decoded['support_topic'] ?? null) ? $decoded['support_topic'] : '',
            'sources' => array_slice($sources, 0, 3),
            'suggested_questions' => array_slice($questions, 0, 3),
        ];
    }

    // ---------------------------------------------------------------- hàm phụ của rank()

    /** Từ của câu đã fold, viết tắt được mở rộng; chưa bỏ stopword. */
    private function expandedWords(string $folded): array
    {
        $words = [];
        foreach (preg_split('/[^a-z0-9%]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach (explode(' ', self::ABBREVIATIONS[$word] ?? $word) as $piece) $words[] = $piece;
        }
        return $words;
    }

    /** Token có nghĩa của câu hỏi: bỏ stopword, độ dài >= 2. */
    private function queryTokens(string $folded): array
    {
        return array_values(array_unique(array_filter(
            $this->expandedWords($folded),
            static fn(string $word) => mb_strlen($word) >= 2 && !in_array($word, self::STOPWORDS, true)
        )));
    }

    private function tokenSet(string $text): array
    {
        $set = [];
        foreach ($this->queryTokens(self::fold($text)) as $token) $set[$token] = true;
        return $set;
    }

    /** Chỉ mục token của từng bài, dựng một lần cho mỗi file support.json. */
    private function tokenIndex(): array
    {
        if (isset(self::$tokenIndex[$this->path])) return self::$tokenIndex[$this->path];
        $index = [];
        foreach ($this->data['articles'] as $article) {
            $triggers = array_merge($article['triggers'], $this->data['routing']['aliases'][$article['slug']] ?? []);
            $index[$article['slug']] = [
                'title' => $this->tokenSet((string)$article['title']),
                'questions' => $this->tokenSet(implode(' ', $article['questions'])),
                'triggers' => $this->tokenSet(implode(' ', $triggers)),
                'summary' => $this->tokenSet((string)$article['summary']),
            ];
        }
        return self::$tokenIndex[$this->path] = $index;
    }

    /** Tổng trọng số token trùng (title x3, questions x2, triggers x2, summary x1) chia số token câu hỏi. */
    private function tokenScore(array $tokens, array $fields): float
    {
        $sum = 0.0;
        foreach ($tokens as $token) {
            foreach (self::FIELD_WEIGHTS as $field => $weight) {
                $set = $fields[$field] ?? [];
                if (isset($set[$token])) { $sum += $weight; continue; }
                // Lỗi chính tả nhẹ: sai một ký tự ở token đủ dài, nửa trọng số.
                if (strlen($token) >= 5) {
                    foreach ($set as $candidate => $_) {
                        $candidate = (string)$candidate; // khóa số như "52" bị PHP đổi thành int
                        if (strlen($candidate) >= 5 && abs(strlen($candidate) - strlen($token)) <= 1 && levenshtein($candidate, $token) === 1) { $sum += $weight / 2; break; }
                    }
                }
            }
        }
        return $sum / max(1, count($tokens));
    }

    /** Cắt tại ranh giới từ (không cắt giữa từ), thêm "…" nếu có cắt. */
    private static function cutAtWord(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        $cut = mb_substr($text, 0, max(0, $max - 1), 'UTF-8');
        if (!preg_match('/\s/u', mb_substr($text, mb_strlen($cut), 1, 'UTF-8') ?: ' ')) {
            $cut = preg_replace('/\s*\S*$/u', '', $cut) ?? $cut;
        }
        return rtrim($cut) . '…';
    }
}

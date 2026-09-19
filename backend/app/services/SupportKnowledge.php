<?php
declare(strict_types=1);

/** Authored store facts, shared by the website and its chat BFF. No model promises. */
final class SupportKnowledge
{
    private array $data;

    public function __construct(?string $path = null)
    {
        $this->data = json_decode(file_get_contents($path ?? __DIR__ . '/../content/support.json'), true, 512, JSON_THROW_ON_ERROR);
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
            $phrases = array_merge($article['triggers'], $this->data['routing']['aliases'][$article['slug']] ?? []);
            foreach ($phrases as $phrase) {
                if (preg_match('/(?<![a-z0-9])' . preg_quote($phrase, '/') . '(?![a-z0-9])/', $query)) {
                    // An explicit requested operation outranks incidental order/payment context.
                    $score = strlen($phrase) + (preg_match('/^(huy|sua|doi|xoa|khoi phuc|quen|reset|forgot)\b/', $phrase) ? 30 : 0)
                        + ($this->data['routing']['priority'][$article['slug']] ?? 0);
                    if ($score > $bestScore) { $best = $article; $bestScore = $score; }
                }
            }
            foreach ($article['questions'] as $question) {
                if ($query === self::fold($question)) return $article;
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
}

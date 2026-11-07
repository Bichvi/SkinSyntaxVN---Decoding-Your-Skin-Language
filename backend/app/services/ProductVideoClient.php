<?php

/**
 * Storefront boundary for AI Idol product videos.
 * Any failure degrades to "no videos" so the product page always renders.
 */
final class ProductVideoClient
{
    private const LIMIT = 30;
    private const CONNECT_TIMEOUT_MS = 500;
    private const TIMEOUT_MS = 1500;

    private string $endpoint;
    private string $publicPrefix;
    /** @var callable(string): ?string */
    private $transport;

    public function __construct(string $endpoint, string $publicBase, ?callable $transport = null)
    {
        $this->endpoint = rtrim($endpoint, '/');
        $publicBase = rtrim(trim($publicBase), '/');
        $this->publicPrefix = $publicBase === '' ? '' : $publicBase . '/';
        $this->transport = $transport ?? [$this, 'httpGet'];
    }

    public static function fromEnv(): self
    {
        return new self(
            getenv('AI_IDOL_ENDPOINT') ?: 'http://ai-idol-service:5003',
            (string)(getenv('R2_PUBLIC_BASE_URL') ?: '')
        );
    }

    /** @return array{live: ?array, videos: array} */
    public function forProduct(string $productId): array
    {
        $empty = ['live' => null, 'videos' => []];
        $url = $this->endpoint . '/api/ai-idol/products/' . rawurlencode($productId) . '/videos?limit=' . self::LIMIT;
        $body = ($this->transport)($url);
        if (!is_string($body) || $body === '') {
            return $empty;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true || !is_array($decoded['data'] ?? null)) {
            error_log('ProductVideoClient: invalid response for product ' . $productId);
            return $empty;
        }
        $videos = [];
        foreach (($decoded['data']['videos'] ?? []) as $item) {
            $video = is_array($item) ? $this->video($item) : null;
            if ($video !== null) {
                $videos[] = $video;
            }
        }
        return ['live' => $this->live($decoded['data']['live'] ?? null), 'videos' => $videos];
    }

    private function video(array $item): ?array
    {
        $videoUrl = (string)($item['video_url'] ?? '');
        $posterUrl = (string)($item['poster_url'] ?? '');
        $videoId = (string)($item['video_id'] ?? '');
        if ($videoId === '' || !$this->isPublic($videoUrl) || !$this->isPublic($posterUrl)) {
            return null;
        }
        $productIds = [];
        foreach ((array)($item['product_ids'] ?? []) as $id) {
            $id = (string)$id;
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                $productIds[] = $id;
            }
        }
        return [
            'video_id' => $videoId,
            'video_url' => $videoUrl,
            'poster_url' => $posterUrl,
            'duration' => (float)($item['duration'] ?? 0),
            'format' => ($item['format'] ?? '') === '9:16' ? '9:16' : '16:9',
            'date' => (string)($item['date'] ?? ''),
            'date_kind' => ($item['date_kind'] ?? '') === 'aired' ? 'aired' : 'created',
            'campaign_name' => (string)($item['campaign_name'] ?? ''),
            'product_ids' => $productIds,
        ];
    }

    private function live($live): ?array
    {
        if (!is_array($live) || empty($live['campaign_id'])) {
            return null;
        }
        return ['campaign_id' => (string)$live['campaign_id'], 'name' => (string)($live['name'] ?? '')];
    }

    private function isPublic(string $url): bool
    {
        return $this->publicPrefix !== '' && strncmp($url, $this->publicPrefix, strlen($this->publicPrefix)) === 0;
    }

    private function httpGet(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS => self::TIMEOUT_MS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            error_log('ProductVideoClient: AI Idol unavailable (HTTP ' . $status . ($error !== '' ? ', ' . $error : '') . ')');
            return null;
        }
        return (string)$body;
    }
}

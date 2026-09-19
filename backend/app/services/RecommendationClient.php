<?php

/**
 * Typed boundary for the Recommendation & Routine Solver service.
 * Uses cURL when available and a stream fallback for minimal PHP installs.
 */
final class RecommendationProductDto
{
    public function __construct(
        public readonly string $productId,
        public readonly string $name,
        public readonly int $price,
        public readonly string $image,
        public readonly float $matchScore,
        public readonly array $keyIngredients,
        public readonly array $avoidFlags,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['product_id'] ?? ''),
            trim((string)($data['name'] ?? '')),
            (int)($data['price'] ?? 0),
            trim((string)($data['image'] ?? '')),
            (float)($data['match_score'] ?? 0),
            self::strings($data['key_ingredients'] ?? []),
            self::strings($data['avoid_flags'] ?? []),
        );
    }

    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            $value
        ), static fn(string $item): bool => $item !== ''));
    }
}

final class RecommendationStepDto
{
    public function __construct(
        public readonly string $stepName,
        public readonly int $stepOrder,
        public readonly ?RecommendationProductDto $recommendedProduct,
        public readonly array $alternatives,
        public readonly string $whyThisProduct,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $recommended = $data['recommended_product'] ?? null;
        $alternatives = [];
        foreach (($data['alternatives'] ?? []) as $alternative) {
            if (is_array($alternative)) {
                $alternatives[] = RecommendationProductDto::fromArray($alternative);
            }
        }
        return new self(
            trim((string)($data['step_name'] ?? '')),
            (int)($data['step_order'] ?? 0),
            is_array($recommended) ? RecommendationProductDto::fromArray($recommended) : null,
            $alternatives,
            trim((string)($data['why_this_product'] ?? '')),
        );
    }
}

final class RecommendationResponseDto
{
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $routineType,
        public readonly array $profileSummary,
        public readonly array $amRoutine,
        public readonly array $pmRoutine,
        public readonly array $combo,
        public readonly array $conflictWarnings,
        public readonly string $safetyNotes,
        public readonly ?float $latencyMs,
        public readonly bool $ok,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $am = [];
        foreach (($data['am_routine'] ?? []) as $step) {
            if (is_array($step)) {
                $am[] = RecommendationStepDto::fromArray($step);
            }
        }
        $pm = [];
        foreach (($data['pm_routine'] ?? []) as $step) {
            if (is_array($step)) {
                $pm[] = RecommendationStepDto::fromArray($step);
            }
        }
        $warnings = [];
        foreach (($data['conflict_warnings'] ?? []) as $warning) {
            if (!is_array($warning)) {
                continue;
            }
            $warnings[] = [
                'pair' => is_array($warning['pair'] ?? null) ? array_values($warning['pair']) : [],
                'resolution' => trim((string)($warning['resolution'] ?? '')),
            ];
        }
        $latency = isset($data['latency_ms']) && is_numeric($data['latency_ms'])
            ? (float)$data['latency_ms']
            : null;

        return new self(
            (string)($data['schema_version'] ?? ''),
            (string)($data['routine_type'] ?? 'personalized'),
            is_array($data['profile_summary'] ?? null) ? $data['profile_summary'] : [],
            $am,
            $pm,
            is_array($data['combo'] ?? null) ? $data['combo'] : [],
            $warnings,
            trim((string)($data['safety_notes'] ?? '')),
            $latency,
            (bool)($data['ok'] ?? true),
        );
    }
}

final class RecommendationClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxRetries = 2,
        private readonly int $retryDelayMs = 120,
    ) {
    }

    public function recommend(array $payload): RecommendationResponseDto
    {
        $body = $this->postJson($payload);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Recommendation service returned invalid JSON.');
        }
        if ((string)($decoded['schema_version'] ?? '') !== '1.0') {
            throw new RuntimeException('Unsupported recommendation schema version.');
        }
        return RecommendationResponseDto::fromArray($decoded);
    }

    private function postJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Recommendation payload cannot be encoded.');
        }
        $attempts = max(1, $this->maxRetries + 1);
        $lastError = 'unknown transport error';
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $result = function_exists('curl_init')
                ? $this->postWithCurl($json)
                : $this->postWithStream($json);
            $status = (int)($result['status'] ?? 0);
            $error = trim((string)($result['error'] ?? ''));
            if ($status >= 200 && $status < 300 && (string)($result['body'] ?? '') !== '') {
                return (string)$result['body'];
            }
            $lastError = $error !== '' ? $error : 'HTTP status ' . $status;
            if (!$this->isRetryable($status, $error) || $attempt === $attempts - 1) {
                break;
            }
            usleep(max(0, $this->retryDelayMs) * 1000 * ($attempt + 1));
        }
        error_log('[RecommendationClient] request failed: ' . $lastError);
        throw new RuntimeException('Recommendation service unavailable.');
    }

    private function postWithCurl(string $json): array
    {
        $handle = curl_init($this->endpoint);
        if ($handle === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => min(5, max(1, $this->timeoutSeconds)),
            CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
    }

    private function postWithStream(string $json): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\nContent-Type: application/json\r\n",
                'content' => $json,
                'ignore_errors' => true,
                'timeout' => max(1, $this->timeoutSeconds),
            ],
        ]);
        $body = @file_get_contents($this->endpoint, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $header, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => ''];
    }

    private function isRetryable(int $status, string $error): bool
    {
        return $status === 0 || $status === 408 || $status === 429 || $status >= 500 || $error !== '';
    }
}

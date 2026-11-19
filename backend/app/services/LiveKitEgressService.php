<?php

/**
 * LiveKit Egress + S3-compatible storage client.
 *
 * A playback URL is deliberately not created while an egress is starting.
 * Replay becomes publishable only after a HEAD request proves that the output
 * exists and is a non-empty video/mp4 object.
 */
class LiveKitEgressService {
    private string $host = '';
    private string $apiKey = '';
    private string $apiSecret = '';
    private ?array $s3Config = null;
    private string $webhookApiKey = '';
    private string $webhookApiSecret = '';

    public function __construct() {
        $env = static fn(string $name): string => function_exists('ss_env') ? (string)ss_env($name, '') : (string)(getenv($name) ?: '');
        $url = trim($env('LIVEKIT_URL'));
        $this->host = preg_replace('/^ws(s?):/i', 'http$1:', $url) ?: '';
        $this->apiKey = trim($env('LIVEKIT_API_KEY'));
        $this->apiSecret = trim($env('LIVEKIT_API_SECRET'));
        $this->webhookApiKey = trim($env('LIVEKIT_EGRESS_WEBHOOK_SIGNING_KEY')) ?: (trim($env('LIVEKIT_WEBHOOK_API_KEY')) ?: $this->apiKey);
        $this->webhookApiSecret = trim($env('LIVEKIT_EGRESS_WEBHOOK_SIGNING_SECRET')) ?: (trim($env('LIVEKIT_WEBHOOK_API_SECRET')) ?: $this->apiSecret);

        $r2Account = trim($env('R2_ACCOUNT_ID'));
        $r2Key = trim($env('R2_ACCESS_KEY_ID'));
        $r2Secret = trim($env('R2_SECRET_ACCESS_KEY'));
        $r2Bucket = trim($env('R2_BUCKET')) ?: 'skinsyntax-videos';
        $r2Endpoint = rtrim(trim($env('R2_ENDPOINT')), '/');
        if ($r2Endpoint === '' && $r2Account !== '') $r2Endpoint = "https://{$r2Account}.r2.cloudflarestorage.com";

        // R2_PUBLIC_BASE_URL is optional. Playback uses the authenticated
        // server proxy below, so a public bucket is never required.
        if ($r2Key !== '' && $r2Secret !== '' && $r2Bucket !== '' && preg_match('~^https://~i', $r2Endpoint)) {
            $this->s3Config = [
                'access_key' => $r2Key,
                'secret' => $r2Secret,
                'region' => trim($env('R2_REGION')) ?: 'auto',
                'endpoint' => $r2Endpoint,
                'bucket' => $r2Bucket,
                'force_path_style' => true,
            ];
        }
    }

    public function isStorageConfigured(): bool {
        return $this->s3Config !== null && $this->host !== '' && $this->apiKey !== '' && $this->apiSecret !== '';
    }

    public function getStorageConfig(): ?array { return $this->s3Config; }

    private static function base64UrlDecode(string $value): ?string {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }

    private static function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function generateEgressToken(int $ttlSeconds = 120): string {
        $header = self::base64UrlEncode((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $now = time();
        $payload = self::base64UrlEncode((string)json_encode([
            'iss' => $this->apiKey,
            'sub' => 'egress-service-' . bin2hex(random_bytes(6)),
            'nbf' => $now - 5,
            'exp' => $now + $ttlSeconds,
            'video' => ['roomRecord' => true, 'roomAdmin' => true, 'roomList' => true],
        ]));
        return $header . '.' . $payload . '.' . self::base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $this->apiSecret, true));
    }

    private function postTwirp(string $endpoint, array $payload): array {
        if ($this->host === '' || $this->apiKey === '' || $this->apiSecret === '' || !function_exists('curl_init')) {
            return ['status' => 0, 'data' => null, 'error' => 'LiveKit Egress chưa được cấu hình.', 'raw' => ''];
        }
        $ch = curl_init(rtrim($this->host, '/') . '/twirp/' . ltrim($endpoint, '/'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->generateEgressToken()],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        return ['status' => $status, 'data' => is_array($json) ? $json : null, 'error' => $err, 'raw' => is_string($body) ? $body : ''];
    }

    private static function safeLiveId(string $liveId): string {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($liveId));
        return trim((string)$safe, '_') ?: 'unknown';
    }

    public function startRoomRecording(string $roomName, string $liveId): array {
        if (!$this->isStorageConfigured()) return ['ok' => false, 'code' => 'egress_not_configured', 'message' => 'Cấu hình LiveKit/R2 chưa đầy đủ.'];

        $filePath = 'live-recordings/live_' . self::safeLiveId($liveId) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.mp4';
        $payload = [
            'room_name' => $roomName,
            'template' => ['layout' => 'speaker'],
            'outputs' => [['file' => ['file_type' => 'MP4', 'filepath' => $filePath]]],
            'storage' => ['s3' => $this->s3Config],
        ];

        $webhookUrl = function_exists('ss_env') ? trim((string)ss_env('LIVEKIT_EGRESS_WEBHOOK_URL', '')) : trim((string)(getenv('LIVEKIT_EGRESS_WEBHOOK_URL') ?: ''));
        $webhookKey = function_exists('ss_env') ? trim((string)ss_env('LIVEKIT_EGRESS_WEBHOOK_SIGNING_KEY', '')) : trim((string)(getenv('LIVEKIT_EGRESS_WEBHOOK_SIGNING_KEY') ?: ''));
        if ($webhookUrl !== '' && preg_match('~^https://~i', $webhookUrl)) {
            $payload['webhooks'] = [['url' => $webhookUrl] + ($webhookKey !== '' ? ['signing_key' => $webhookKey] : [])];
        }

        // StartEgress is the current API. The old source-specific composite
        // call is deliberately not used because it is deprecated upstream.
        $resp = $this->postTwirp('livekit.Egress/StartEgress', $payload);
        if ($resp['status'] === 200 && !empty($resp['data']['egress_id'])) {
            return [
                'ok' => true,
                'egress_id' => (string)$resp['data']['egress_id'],
                'file_path' => $filePath,
                'status' => $resp['data']['status'] ?? 'EGRESS_STARTING',
                'started_at' => time(),
            ];
        }
        $data = is_array($resp['data']) ? $resp['data'] : [];
        return [
            'ok' => false,
            'code' => $data['code'] ?? 'egress_failed',
            'message' => $data['msg'] ?? $data['message'] ?? $resp['error'] ?? 'Lỗi không xác định khi gọi LiveKit Egress.',
            'status_code' => $resp['status'],
        ];
    }

    public function stopRecording(string $egressId): array {
        if (trim($egressId) === '') return ['ok' => false, 'message' => 'Thiếu mã Egress.'];
        $resp = $this->postTwirp('livekit.Egress/StopEgress', ['egress_id' => trim($egressId)]);
        if ($resp['status'] === 200) return ['ok' => true, 'data' => $resp['data']];
        $data = is_array($resp['data']) ? $resp['data'] : [];
        return ['ok' => false, 'message' => $data['msg'] ?? $data['message'] ?? $resp['error'] ?? 'Không thể dừng LiveKit Egress.'];
    }

    /** ListEgress is diagnostics only: LiveKit documents it as active-only. */
    public function listEgress(string $roomName = ''): array {
        $resp = $this->postTwirp('livekit.Egress/ListEgress', ['room_name' => $roomName]);
        if ($resp['status'] === 200) return ['ok' => true, 'items' => $resp['data']['items'] ?? []];
        return ['ok' => false, 'items' => [], 'error' => is_array($resp['data']) ? ($resp['data']['msg'] ?? '') : ($resp['error'] ?? '')];
    }

    public static function verifyWebhookAuthorization(string $rawBody, string $authorization, string $apiKey, string $apiSecret): ?array {
        if ($apiKey === '' || $apiSecret === '' || !preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) return null;
        $parts = explode('.', trim($m[1]));
        if (count($parts) !== 3) return null;
        $headerRaw = self::base64UrlDecode($parts[0]);
        $payloadRaw = self::base64UrlDecode($parts[1]);
        $header = $headerRaw === null ? null : json_decode($headerRaw, true);
        $payload = $payloadRaw === null ? null : json_decode($payloadRaw, true);
        if (!is_array($header) || !is_array($payload) || ($header['alg'] ?? '') !== 'HS256') return null;
        $expected = self::base64UrlEncode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $apiSecret, true));
        if (($payload['iss'] ?? '') !== $apiKey || !hash_equals($expected, $parts[2])) return null;
        if (isset($payload['exp']) && (int)$payload['exp'] < time()) return null;
        if (isset($payload['nbf']) && (int)$payload['nbf'] > time() + 30) return null;
        if (($payload['sha256'] ?? '') !== hash('sha256', $rawBody)) return null;
        return $payload;
    }

    public function verifyWebhook(string $rawBody, string $authorization): ?array {
        return self::verifyWebhookAuthorization($rawBody, $authorization, $this->webhookApiKey, $this->webhookApiSecret);
    }

    public function extractWebhookEgressInfo(array $event): array {
        $info = $event['egressInfo'] ?? $event['egress_info'] ?? $event['egress'] ?? [];
        return is_array($info) ? $info : [];
    }

    public function normalizeEgressStatus(array $info): string {
        $status = $info['status'] ?? '';
        if (is_int($status) || ctype_digit((string)$status)) {
            return match ((int)$status) {
                3 => 'EGRESS_COMPLETE', 4 => 'EGRESS_FAILED', 5 => 'EGRESS_ABORTED', 6 => 'EGRESS_LIMIT_REACHED',
                2 => 'EGRESS_ENDING', 1 => 'EGRESS_ACTIVE', default => 'EGRESS_STARTING',
            };
        }
        return strtoupper(trim((string)$status));
    }

    public function egressFileMetadata(array $info): array {
        $files = $info['file_results'] ?? $info['fileResults'] ?? [];
        if (!is_array($files)) $files = [];
        $file = $files[0] ?? [];
        if (!is_array($file)) $file = [];
        $duration = (int)($file['duration'] ?? $info['duration'] ?? 0);
        if ($duration > 1000000) $duration = (int)round($duration / 1000000000);
        return [
            'file_path' => trim((string)($file['filename'] ?? $file['location'] ?? $info['file_location'] ?? '')),
            'location' => trim((string)($file['location'] ?? '')),
            'duration' => max(0, $duration),
            'size' => max(0, (int)($file['size'] ?? $info['size'] ?? 0)),
            'mime' => trim((string)($file['mime_type'] ?? $file['mimeType'] ?? '')),
            'started_at' => (int)($file['started_at'] ?? $info['started_at'] ?? 0),
            'ended_at' => (int)($file['ended_at'] ?? $info['ended_at'] ?? 0),
            'error' => trim((string)($info['error'] ?? $file['error'] ?? '')),
        ];
    }

    private static function encodePath(string $path): string {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private function s3Request(string $method, string $key, ?string $range = null): array {
        if ($this->s3Config === null || !function_exists('curl_init')) return ['ok' => false, 'status' => 0, 'headers' => [], 'error' => 'R2 chưa được cấu hình.'];
        $endpoint = rtrim((string)$this->s3Config['endpoint'], '/');
        $bucket = (string)$this->s3Config['bucket'];
        $uri = '/' . rawurlencode($bucket) . '/' . self::encodePath($key);
        $url = $endpoint . $uri;
        $host = (string)parse_url($endpoint, PHP_URL_HOST);
        $now = gmdate('Ymd\THis\Z');
        $date = gmdate('Ymd');
        $payloadHash = hash('sha256', '');
        $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $now];
        if ($range !== null && $range !== '') $headers['range'] = $range;
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) $canonicalHeaders .= $name . ':' . trim((string)$value) . "\n";
        $signedHeaders = implode(';', array_keys($headers));
        $canonicalRequest = $method . "\n" . $uri . "\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
        $scope = $date . '/' . $this->s3Config['region'] . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->s3Config['secret'], true);
        $kRegion = hash_hmac('sha256', (string)$this->s3Config['region'], $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        $requestHeaders = ['Host: ' . $host, 'x-amz-content-sha256: ' . $payloadHash, 'x-amz-date: ' . $now, 'Authorization: AWS4-HMAC-SHA256 Credential=' . $this->s3Config['access_key'] . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature];
        if ($range !== null && $range !== '') $requestHeaders[] = 'Range: ' . $range;
        return ['ok' => true, 'url' => $url, 'headers' => $requestHeaders];
    }

    public function headObject(string $key): array {
        $request = $this->s3Request('HEAD', $key);
        if (empty($request['ok'])) return $request;
        $responseHeaders = [];
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (strpos($line, ':') !== false) { [$name, $value] = explode(':', $line, 2); $responseHeaders[strtolower(trim($name))] = trim($value); }
                return strlen($line);
            },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => $status >= 200 && $status < 300, 'status' => $status, 'headers' => $responseHeaders,
            'size' => max(0, (int)($responseHeaders['content-length'] ?? 0)),
            'mime' => strtolower(trim((string)($responseHeaders['content-type'] ?? ''))),
            'etag' => trim((string)($responseHeaders['etag'] ?? '')),
            'last_modified' => trim((string)($responseHeaders['last-modified'] ?? '')),
            'error' => $error,
        ];
    }

    public function verifyObject(string $key): array {
        $head = $this->headObject($key);
        if (empty($head['ok'])) return ['ok' => false, 'status' => 'pending', 'message' => 'Bản ghi chưa xuất hiện trong R2.', 'head' => $head];
        $mime = strtolower((string)($head['mime'] ?? ''));
        $size = (int)($head['size'] ?? 0);
        if ($size < 1) return ['ok' => false, 'status' => 'invalid', 'message' => 'Bản ghi trong R2 có kích thước không hợp lệ.', 'head' => $head];
        if ($mime !== 'video/mp4') return ['ok' => false, 'status' => 'invalid', 'message' => 'Bản ghi trong R2 không có MIME video/mp4.', 'head' => $head];
        return ['ok' => true, 'status' => 'complete', 'size' => $size, 'mime' => $mime, 'etag' => $head['etag'] ?? '', 'last_modified' => $head['last_modified'] ?? '', 'head' => $head];
    }

    /** Stream a private R2 object while preserving Range/206 response headers. */
    public function streamObject(string $key, string $method, ?string $range, callable $onHeaders, callable $onChunk): array {
        $request = $this->s3Request($method, $key, $range);
        if (empty($request['ok'])) return ['ok' => false, 'status' => 503, 'error' => $request['error'] ?? 'R2 unavailable'];
        $status = 0; $headers = []; $allowBody = false; $headersDispatched = false;
        $dispatchHeaders = static function () use (&$headersDispatched, &$status, &$headers, $onHeaders): void {
            if (!$headersDispatched && $status >= 200 && $status < 300) {
                $headersDispatched = true;
                $onHeaders($status, $headers);
            }
        };
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 0, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$headers, &$allowBody, $dispatchHeaders): int {
                $trimmed = trim($line);
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $trimmed, $m)) {
                    $status = (int)$m[1]; $allowBody = $status >= 200 && $status < 300;
                } elseif (strpos($line, ':') !== false && $status >= 200 && $status < 300) {
                    [$name, $value] = explode(':', $line, 2); $name = strtolower(trim($name));
                    if (in_array($name, ['content-type', 'content-length', 'content-range', 'accept-ranges', 'etag', 'last-modified', 'content-disposition'], true)) $headers[$name] = trim($value);
                } elseif ($trimmed === '') {
                    $dispatchHeaders();
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$allowBody, $dispatchHeaders, $onChunk): int {
                $dispatchHeaders();
                return $allowBody ? (int)$onChunk($chunk) : strlen($chunk);
            },
        ]);
        $result = curl_exec($ch); $error = curl_error($ch); $dispatchHeaders(); curl_close($ch);
        return ['ok' => $result !== false && $status >= 200 && $status < 300, 'status' => $status, 'headers' => $headers, 'error' => $error];
    }
}

<?php

final class HttpJsonClient {
    public static function post(string $url, array $payload, int $timeout = 20): array {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'JSON encoding failed.',
            ];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, max(3, $timeout));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $responseBody = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            return [
                'status' => $status,
                'body' => (string)$responseBody,
                'error' => $error,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => max(3, $timeout),
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }

        return [
            'status' => $status,
            'body' => (string)$responseBody,
            'error' => '',
        ];
    }
}

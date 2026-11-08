<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatSseRelay.php';
require_once __DIR__ . '/../../app/controllers/ChatAgentController.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$forwarded = [];
$applied = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect) use (&$applied): ?int { $applied[] = $effect; return 3; }
);
$relay->feed("event: token\ndata: {\"text\":\"xin\"}\n");
$relay->feed("\nevent: token\ndata: {\"text\":\"chào\"}\n\n");
$check('splits_events_across_chunks', count($forwarded) === 2 && str_contains($forwarded[0], 'xin') && str_contains($forwarded[1], 'chào'));
$check('forwards_token_events_verbatim', $forwarded[0] === "event: token\ndata: {\"text\":\"xin\"}\n\n");

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return 4; }
);
$effectJson = '{"op":"add","product_id":"p1","qty":1,"sub":"u1","exp":2000000000,"nonce":"n1","sig":"sig"}';
$relay->feed("event: cart_effect\ndata: {$effectJson}\n\n");
$check('cart_effect_not_forwarded_and_emits_cart_updated', count($forwarded) === 1
    && str_starts_with($forwarded[0], "event: cart_updated\n")
    && str_contains($forwarded[0], '"count":4')
    && !str_contains($forwarded[0], 'cart_effect'));

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return null; }
);
$relay->feed("event: cart_effect\ndata: {$effectJson}\n\n");
$check('bad_effect_dropped', $forwarded === []);

foreach (["\n\r\n", "\n\r", "\r\n\r"] as $delimiter) {
    $forwarded = [];
    $relay = new ChatSseRelay(
        static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
        static function (array $effect): ?int { return 9; }
    );
    $relay->feed("event: cart_effect\ndata: {$effectJson}" . $delimiter);
    if (str_ends_with($delimiter, "\r")) {
        $relay->feed("\n");
    }
    $check('cart_effect_delimiter_' . bin2hex($delimiter), count($forwarded) === 1
        && str_starts_with($forwarded[0], "event: cart_updated\n")
        && !str_contains(implode('', $forwarded), 'cart_effect'));
}

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return 9; }
);
$relay->feed("event: cart_effect\ndata: {$effectJson}\n\r");
$relay->feed("\nevent: token\ndata: late\n\n");
$check('split_carriage_return_delimiter', count($forwarded) === 2
    && str_starts_with($forwarded[0], "event: cart_updated\n")
    && $forwarded[1] === "event: token\ndata: late\n\n");

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return 9; }
);
$relay->feed("event: cart_effect\ndata: {$effectJson}\nevent: token\ndata: late\n\n");
$check('merged_cart_effect_frame_is_dropped', $forwarded === []);

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return 8; }
);
$relay->feed("event: token\rdata: {\"text\":\"a\"}\r\revent: cart_effect\r\ndata: {$effectJson}\r\n\r\nevent: token\ndata: {\"text\":\"b\"}\n\n");
$check('mixed_delimiters_use_earliest_and_normalise_frames', count($forwarded) === 3
    && $forwarded[0] === "event: token\ndata: {\"text\":\"a\"}\n\n"
    && str_starts_with($forwarded[1], "event: cart_updated\n")
    && $forwarded[2] === "event: token\ndata: {\"text\":\"b\"}\n\n");

$applyCalls = 0;
$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect) use (&$applyCalls): ?int { $applyCalls++; return $applyCalls === 1 ? 2 : null; }
);
$relay->feed("event: cart_effect\ndata: {$effectJson}\n\n");
$relay->feed("event: cart_effect\ndata: {$effectJson}\n\n");
$check('relay_applies_effect_after_reopening_session', $applyCalls === 2);
$check('replayed_effect_emits_nothing', count($forwarded) === 1 && str_contains($forwarded[0], '"count":2'));

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return 1; }
);
$relay->feed("event: token\ndata: unfinished");
$relay->finish();
$check('partial_chunk_on_finish_emits_error', count($forwarded) === 2
    && str_starts_with($forwarded[0], "event: error\n")
    && str_starts_with($forwarded[1], "event: done\n"));

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return 1; }
);
$feedResult = $relay->feed(str_repeat('x', 262145));
$check('buffer_overflow_emits_vn_error_done_and_aborts', $feedResult === 0
    && count($forwarded) === 2
    && str_contains($forwarded[0], 'Trợ lý đang bận, bạn thử lại sau nhé.')
    && str_starts_with($forwarded[1], "event: done\n"));
$check('buffer_overflow_drops_following_data', $relay->feed("event: token\ndata: late\n\n") === 0 && count($forwarded) === 2);

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return 1; }
);
$check('finish_uses_vn_error_and_done', $relay->finish() === null && count($forwarded) === 2
    && str_contains($forwarded[0], 'Trợ lý đang bận, bạn thử lại sau nhé.')
    && str_starts_with($forwarded[1], "event: done\n"));
$relay->feed("event: token\ndata: late\n\n");
$check('finish_terminal_stops_forwarding', count($forwarded) === 2);

$forwarded = [];
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect): ?int { return 1; }
);
$check('terminal_event_stops_curl_write_callback', $relay->feed("event: done\ndata: {}\n\nevent: token\ndata: late\n\n") === 0
    && count($forwarded) === 1
    && str_starts_with($forwarded[0], "event: done\n"));

// Cross-layer contract: Flask emits cart_effect as data {"effect": <signed effect>}.
require_once __DIR__ . '/../../app/services/ChatCartEffect.php';
$contractSecret = 'contract-secret';
$contractEffect = ChatCartEffect::sign([
    'op' => 'add', 'product_id' => 'p1', 'qty' => 2, 'sub' => 'user-7',
    'exp' => time() + 600, 'nonce' => 'contract-nonce',
], $contractSecret);
$flaskFrame = "event: cart_effect
data: " . json_encode(['effect' => $contractEffect], JSON_UNESCAPED_UNICODE) . "

";
$forwarded = [];
$received = null;
$relay = new ChatSseRelay(
    static function (string $raw) use (&$forwarded): void { $forwarded[] = $raw; },
    static function (array $effect) use (&$received): ?int { $received = $effect; return 5; }
);
$relay->feed($flaskFrame);
$check('flask_wrapped_effect_is_unwrapped_unchanged', $received === $contractEffect
    && array_keys($received) === ['op', 'product_id', 'qty', 'sub', 'exp', 'nonce', 'sig']);
$check('flask_wrapped_effect_verifies', is_array($received) && ChatCartEffect::verify($received, $contractSecret, 'user-7'));
$check('flask_wrapped_effect_emits_cart_updated', count($forwarded) === 1 && str_contains($forwarded[0], '"count":5'));

$received = null;
$relay = new ChatSseRelay(static function (string $raw): void {}, static function (array $effect) use (&$received): ?int { $received = $effect; return 1; });
$relay->feed("event: cart_effect
data: " . json_encode(['effect' => 'nope']) . "

");
$check('wrapper_with_non_array_effect_is_not_unwrapped', $received === ['effect' => 'nope']);

$check('upstream_gate_requires_200_event_stream', ChatAgentController::acceptsEventStreamResponse(200, 'text/event-stream; charset=utf-8')
    && ChatAgentController::acceptsEventStreamResponse(200, 'TEXT/EVENT-STREAM')
    && !ChatAgentController::acceptsEventStreamResponse(201, 'text/event-stream')
    && !ChatAgentController::acceptsEventStreamResponse(200, 'application/json'));

$request = ChatAgentController::buildOutboundRequest(
    ['message' => 'xin chào', 'cart_snapshot' => []],
    null,
    'internal-secret'
);
$check('guest_path_builds_no_agent_token_header', !array_filter($request['headers'], static fn(string $header): bool => str_starts_with($header, 'X-Agent-Token:'))
    && in_array('X-Internal-Token: internal-secret', $request['headers'], true)
    && $request['body']['message'] === 'xin chào');

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);

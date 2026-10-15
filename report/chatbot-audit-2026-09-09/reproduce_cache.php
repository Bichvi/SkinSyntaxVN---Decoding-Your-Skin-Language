<?php
// Read the original methods using PHP tokens. Only cache storage is replaced by
// memory; no application bootstrap, session, database, or real cache is opened.
$sourcePath = dirname(__DIR__, 2) . '/backend/app/controllers/HomeController.php';
$tokens = token_get_all(file_get_contents($sourcePath));
$wanted = ['normalizeAiChatCacheKey', 'shouldUseAiResponseCache', 'getCachedAiResponsePayload', 'storeAiResponsePayload', 'detectAiIntent'];
$methods = [];
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
    $j = $i + 1;
    while ($j < count($tokens) && (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING)) $j++;
    $name = $tokens[$j][1];
    if (!in_array($name, $wanted, true)) continue;
    $text = '';
    $depth = 0;
    $opened = false;
    for ($k = $i; $k < count($tokens); $k++) {
        $t = $tokens[$k];
        $text .= is_array($t) ? $t[1] : $t;
        if ($t === '{') { $depth++; $opened = true; }
        if ($t === '}') $depth--;
        if ($opened && $depth === 0) break;
    }
    $methods[] = 'public ' . $text;
}
if (count($methods) !== count($wanted)) throw new RuntimeException('Source method extraction failed');
$fixture = 'class AuditCacheFixture {
    private const AI_CHAT_CACHE_TTL = 604800;
    private const AI_CHAT_CACHE_MAX_ITEMS = 300;
    private array $cache = [];
    private function loadAiChatCache(): array { return $this->cache; }
    private function saveAiChatCache(array $cache): void { $this->cache = $cache; }
    private function shouldAttachAiProducts(string $message): bool { return false; }
' . implode("\n", $methods) . '}';
eval($fixture);
$cache = new AuditCacheFixture();
$message = 'Retinol có hợp với mình không?';
$cache->storeAiResponsePayload($message, ['ok' => true, 'answer' => 'FIXTURE_ANSWER_FOR_PROFILE_A', 'conversation_id' => 'FIXTURE_CONVERSATION_A']);
$returned = $cache->getCachedAiResponsePayload($message);
$result = [
    'id' => 'F01',
    'method' => 'Original PHP cache methods, memory storage fixture; same message after user/profile/conversation changes, which are not cache inputs.',
    'reproduced' => ($returned['conversation_id'] ?? '') === 'FIXTURE_CONVERSATION_A',
    'observed' => $returned,
];
file_put_contents(__DIR__ . '/cache-reproduction.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;

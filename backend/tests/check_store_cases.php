<?php
require_once __DIR__ . '/../app/services/SupportKnowledge.php';
$cases = json_decode(file_get_contents(__DIR__ . '/store_chat_cases.json'), true, 512, JSON_THROW_ON_ERROR);
$knowledge = new SupportKnowledge();
$failed = [];
foreach ($cases['support'] as $topic => $questions) {
    foreach ($questions as $question) {
        $actual = $knowledge->match($question)['slug'] ?? 'unhandled';
        if ($actual !== $topic) $failed[] = [$question, $topic, $actual];
    }
}
echo json_encode(['failed'=>$failed], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
exit($failed ? 1 : 0);

<?php

require_once __DIR__ . '/../services/AiChatService.php';
require_once __DIR__ . '/../services/AiChatCommerce.php';

class AiChatController {
    private AiChatService $chat;
    private AiChatCommerce $commerce;

    public function __construct($pdo) {
        $this->chat = new AiChatService($pdo);
        $this->commerce = new AiChatCommerce($pdo);
    }

    public function aiChatAssistant(): void {
        $this->chat->handleAssistant();
    }

    public function aiChatStream(): void {
        $this->chat->handleStream();
    }

    public function aiChatCommerce(): void {
        $this->commerce->handleRequest();
    }
}

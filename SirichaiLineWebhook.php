<?php

use ChatbotCore\LineWebhookHandler;
use ChatbotCore\ConversationManager;
use ChatbotCore\DatabaseManager;

/**
 * Sirichai Electric LINE webhook handler.
 * Wires SirichaiElectricChatbot + ConversationManager into ChatbotCore\LineWebhookHandler.
 */
class SirichaiLineWebhook extends LineWebhookHandler {

    /** @var SirichaiElectricChatbot */
    private $chatbot;

    protected function initialize(): void {
        $config             = AppConfig::getInstance();
        $geminiConfig       = $config->get('gemini');
        $productAPIConfig   = $config->get('productAPI');
        $dbConfig           = $config->get('database');
        $conversationConfig = $config->get('conversation');

        $maxMessages = isset($conversationConfig['maxMessages']) ? $conversationConfig['maxMessages'] : 20;

        $embeddingConfig  = $config->get('embedding');
        $embeddingService = null;
        if (!empty($embeddingConfig['enabled'])) {
            $pdo = DatabaseManager::getInstance($dbConfig)->getConnection();
            $embeddingService = new CatalogEmbeddingService(
                $pdo,
                isset($geminiConfig['apiKey']) ? $geminiConfig['apiKey'] : '',
                isset($embeddingConfig['topK']) ? $embeddingConfig['topK'] : 5
            );
        }

        $productAPI    = new ProductAPIService($productAPIConfig);
        $this->chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI, $embeddingService);

        $this->conversationManager = new ConversationManager($maxMessages, 'line', $dbConfig);
    }

    protected function getChannelSecret(): string {
        return (string) (AppConfig::getInstance()->get('line', 'channelSecret') ?: '');
    }

    protected function getChannelAccessToken(): string {
        return (string) (AppConfig::getInstance()->get('line', 'channelAccessToken') ?: '');
    }

    protected function getAIResponse(string $text, string $conversationId, string $userId): string {
        $isAuthorized = $this->conversationManager->isUserAuthorized($userId);
        $this->chatbot->setAuthorized($isAuthorized);

        $history  = $this->conversationManager->getConversationHistory($conversationId);
        $response = $this->chatbot->chat($text, $history);

        // Store user message with search criteria captured from AI response
        $searchCriteria = isset($response['searchCriteria']) ? $response['searchCriteria'] : null;
        $this->conversationManager->addMessage($conversationId, 'user', $text, 0, $searchCriteria);

        if ($response['success']) {
            $tokensUsed = isset($response['tokensUsed']) ? $response['tokensUsed'] : 0;
            $this->conversationManager->addMessage($conversationId, 'assistant', $response['response'], $tokensUsed, null);
            return $response['response'];
        }

        error_log('[SirichaiLineWebhook] AI error: ' . (isset($response['error']) ? $response['error'] : 'unknown'));

        if (isset($response['error']) &&
            (strpos($response['error'], 'RATE_LIMIT_EXCEEDED') !== false || strpos($response['error'], '429') !== false)) {
            return $this->getRateLimitErrorMessage();
        }

        return $this->getGenericErrorMessage();
    }

    protected function getAIResponseWithImage(string $imageData, string $mimeType, string $text, string $conversationId, string $userId): string {
        $isAuthorized = $this->conversationManager->isUserAuthorized($userId);
        $this->chatbot->setAuthorized($isAuthorized);

        $history  = $this->conversationManager->getConversationHistory($conversationId);
        $response = $this->chatbot->chatWithImage($imageData, $mimeType, $text, $history);

        // Store placeholder with search criteria captured from AI response
        $searchCriteria = isset($response['searchCriteria']) ? $response['searchCriteria'] : null;
        $this->conversationManager->addMessage($conversationId, 'user', '[ผู้ใช้ส่งรูปภาพ]', 0, $searchCriteria);

        if ($response['success']) {
            $tokensUsed = isset($response['tokensUsed']) ? $response['tokensUsed'] : 0;
            $this->conversationManager->addMessage($conversationId, 'assistant', $response['response'], $tokensUsed, null);
            return $response['response'];
        }

        error_log('[SirichaiLineWebhook] Image AI error: ' . (isset($response['error']) ? $response['error'] : 'unknown'));
        return $this->getGenericErrorMessage();
    }
}

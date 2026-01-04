<?php
/**
 * Conversation Manager for Sirichai Electric Chatbot
 * Manages conversation history using PHP sessions
 * PHP 5.6 compatible
 */

class ConversationManager {
    private $maxMessagesPerConversation;
    private $sessionKey;

    public function __construct($maxMessages = 10) {
        $this->maxMessagesPerConversation = $maxMessages;
        $this->sessionKey = 'sirichai_chatbot_conversations';

        // Start session if not already started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        // Initialize session storage
        if (!isset($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = array();
        }
    }

    public function getConversationHistory($conversationId) {
        if (!isset($_SESSION[$this->sessionKey][$conversationId])) {
            return array();
        }

        $conversation = $_SESSION[$this->sessionKey][$conversationId];
        return isset($conversation['messages']) ? $conversation['messages'] : array();
    }

    public function addMessage($conversationId, $role, $content) {
        // Initialize conversation if not exists
        if (!isset($_SESSION[$this->sessionKey][$conversationId])) {
            $_SESSION[$this->sessionKey][$conversationId] = array(
                'conversationId' => $conversationId,
                'messages' => array(),
                'createdAt' => time(),
                'lastActivity' => time(),
            );
        }

        // Add message
        $message = array(
            'role' => $role,
            'content' => $content,
            'timestamp' => time(),
        );

        $_SESSION[$this->sessionKey][$conversationId]['messages'][] = $message;
        $_SESSION[$this->sessionKey][$conversationId]['lastActivity'] = time();

        // Keep only recent messages
        $this->trimConversation($conversationId);
    }

    private function trimConversation($conversationId) {
        if (!isset($_SESSION[$this->sessionKey][$conversationId])) {
            return;
        }

        $messages = $_SESSION[$this->sessionKey][$conversationId]['messages'];
        $count = count($messages);

        if ($count > $this->maxMessagesPerConversation) {
            $messages = array_slice($messages, $count - $this->maxMessagesPerConversation);
            $_SESSION[$this->sessionKey][$conversationId]['messages'] = $messages;
        }
    }

    public function clearConversation($conversationId) {
        if (isset($_SESSION[$this->sessionKey][$conversationId])) {
            unset($_SESSION[$this->sessionKey][$conversationId]);
            return true;
        }
        return false;
    }

    public function clearAllConversations() {
        $_SESSION[$this->sessionKey] = array();
    }

    public function getConversation($conversationId) {
        if (!isset($_SESSION[$this->sessionKey][$conversationId])) {
            return null;
        }
        return $_SESSION[$this->sessionKey][$conversationId];
    }

    public function cleanupOldConversations($maxAgeHours = 24) {
        $now = time();
        $maxAge = $maxAgeHours * 60 * 60;
        $cleaned = 0;

        foreach ($_SESSION[$this->sessionKey] as $id => $conversation) {
            if (!isset($conversation['lastActivity'])) {
                continue;
            }

            $age = $now - $conversation['lastActivity'];
            if ($age > $maxAge) {
                unset($_SESSION[$this->sessionKey][$id]);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    public function generateConversationId() {
        return 'conv_' . time() . '_' . substr(md5(uniqid(rand(), true)), 0, 9);
    }
}

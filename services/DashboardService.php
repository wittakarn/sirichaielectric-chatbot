<?php
/**
 * DashboardService - Business logic for dashboard monitoring
 */

use ChatbotCore\Repository\ConversationRepository;
use ChatbotCore\Repository\MessageRepository;

class DashboardService {
    private $conversationRepo;
    private $messageRepo;

    public function __construct($pdo) {
        $this->conversationRepo = new ConversationRepository($pdo);
        $this->messageRepo      = new MessageRepository($pdo);
    }

    public function getRecentConversationsForGrid($conversationLimit = 6, $messageLimit = 6) {
        $conversations = $this->conversationRepo->findRecentForMonitoring($conversationLimit);

        foreach ($conversations as &$conversation) {
            $conversationId = $conversation['conversation_id'];
            $conversation['message_count']   = $this->messageRepo->countByConversationId($conversationId);
            $conversation['recent_messages'] = $this->messageRepo->getLastNMessages($conversationId, $messageLimit);
        }

        return $conversations;
    }

    public function getConversationsByDate($date) {
        $conversations = $this->conversationRepo->findByDate($date);

        foreach ($conversations as &$conversation) {
            $conversationId = $conversation['conversation_id'];
            $conversation['message_count'] = $this->messageRepo->countByConversationId($conversationId);

            $firstMessage = $this->messageRepo->getFirstUserMessage($conversationId);
            $conversation['first_message'] = $firstMessage ? $firstMessage['content'] : null;
        }

        return $conversations;
    }

    public function getConversationWithMessages($conversationId) {
        $conversation = $this->conversationRepo->findById($conversationId);
        if (!$conversation) {
            return null;
        }

        $conversation['messages'] = $this->messageRepo->findByConversationId($conversationId);
        return $conversation;
    }
}

<?php
/**
 * DashboardService - Business logic for dashboard monitoring
 */

require_once __DIR__ . '/../repository/ConversationRepository.php';
require_once __DIR__ . '/../repository/MessageRepository.php';

class DashboardService {
    private $conversationRepo;
    private $messageRepo;

    public function __construct($dbManager) {
        $this->conversationRepo = new ConversationRepository($dbManager);
        $this->messageRepo = new MessageRepository($dbManager);
    }

    /**
     * Get recent conversations for monitoring grid (6 cards)
     * Each card shows: conversation info, message count, last 6 messages preview
     *
     * @param int $conversationLimit Number of conversations to return (default 6)
     * @param int $messageLimit Number of recent messages per conversation (default 6)
     * @return array List of conversations with stats
     */
    public function getRecentConversationsForGrid($conversationLimit = 6, $messageLimit = 6) {
        // Get recent conversations
        $conversations = $this->conversationRepo->findRecentForMonitoring($conversationLimit);

        // Enrich each conversation with message stats
        foreach ($conversations as &$conversation) {
            $conversationId = $conversation['conversation_id'];

            // Get message count
            $conversation['message_count'] = $this->messageRepo->countByConversationId($conversationId);

            // Get last N messages for preview
            $conversation['recent_messages'] = $this->messageRepo->getLastNMessages($conversationId, $messageLimit);
        }

        return $conversations;
    }

    /**
     * Get full conversation with all messages
     *
     * @param string $conversationId Conversation ID
     * @return array|null Conversation with messages
     */
    public function getConversationWithMessages($conversationId) {
        $conversation = $this->conversationRepo->findById($conversationId);
        if (!$conversation) {
            return null;
        }

        $messages = $this->messageRepo->findByConversationId($conversationId);
        $conversation['messages'] = $messages;

        return $conversation;
    }
}

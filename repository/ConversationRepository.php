<?php
/**
 * Conversation Repository - Handles all conversation-related database operations
 * PHP 5.6 compatible
 */

require_once __DIR__ . '/BaseRepository.php';

class ConversationRepository extends BaseRepository {

    /**
     * Find conversation by ID
     *
     * @param string $conversationId Conversation ID
     * @return array|null Conversation data or null if not found
     */
    public function findById($conversationId) {
        $sql = "
            SELECT conversation_id, platform, user_id, max_messages_limit,
                   is_chatbot_active, UNIX_TIMESTAMP(paused_at) as paused_at,
                   UNIX_TIMESTAMP(created_at) as created_at,
                   UNIX_TIMESTAMP(last_activity) as last_activity
            FROM conversations
            WHERE conversation_id = ?
        ";

        $conversation = $this->fetchOne($sql, array($conversationId));

        if ($conversation) {
            $conversation['created_at'] = intval($conversation['created_at']);
            $conversation['last_activity'] = intval($conversation['last_activity']);
            $conversation['max_messages_limit'] = intval($conversation['max_messages_limit']);
            $conversation['is_chatbot_active'] = intval($conversation['is_chatbot_active']);
            $conversation['paused_at'] = $conversation['paused_at'] ? intval($conversation['paused_at']) : null;
        }

        return $conversation;
    }

    /**
     * Create or update conversation (UPSERT)
     *
     * @param string $conversationId Conversation ID
     * @param string $platform Platform identifier ('api' or 'line')
     * @param string|null $userId User ID (for LINE platform)
     * @param int $maxMessagesLimit Maximum messages to keep
     * @return bool True on success
     */
    public function upsert($conversationId, $platform, $userId, $maxMessagesLimit) {
        $sql = "
            INSERT INTO conversations (conversation_id, platform, user_id, max_messages_limit, last_activity)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE last_activity = NOW()
        ";

        $this->execute($sql, array($conversationId, $platform, $userId, $maxMessagesLimit));
        return true;
    }

    /**
     * Update last activity timestamp
     *
     * @param string $conversationId Conversation ID
     * @return bool True on success
     */
    public function updateLastActivity($conversationId) {
        $sql = "UPDATE conversations SET last_activity = NOW() WHERE conversation_id = ?";
        return $this->execute($sql, array($conversationId)) > 0;
    }

    /**
     * Delete conversation by ID
     *
     * @param string $conversationId Conversation ID
     * @return bool True if deleted
     */
    public function delete($conversationId) {
        $sql = "DELETE FROM conversations WHERE conversation_id = ?";
        return $this->execute($sql, array($conversationId)) > 0;
    }

    /**
     * Delete all conversations
     *
     * @return int Number of deleted conversations
     */
    public function deleteAll() {
        $sql = "DELETE FROM conversations";
        return $this->execute($sql);
    }

    /**
     * Delete old conversations
     *
     * @param int $maxAgeHours Maximum age in hours
     * @return int Number of deleted conversations
     */
    public function deleteOlderThan($maxAgeHours) {
        $sql = "
            DELETE FROM conversations
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL ? HOUR)
        ";
        return $this->execute($sql, array($maxAgeHours));
    }

    /**
     * Find conversations by platform
     *
     * @param string $platform Platform identifier
     * @param int $limit Maximum number of results
     * @return array List of conversations
     */
    public function findByPlatform($platform, $limit = 100) {
        $sql = "
            SELECT conversation_id, platform, user_id, max_messages_limit,
                   UNIX_TIMESTAMP(created_at) as created_at,
                   UNIX_TIMESTAMP(last_activity) as last_activity
            FROM conversations
            WHERE platform = ?
            ORDER BY last_activity DESC
            LIMIT ?
        ";

        $conversations = $this->fetchAll($sql, array($platform, $limit));

        foreach ($conversations as &$conversation) {
            $conversation['created_at'] = intval($conversation['created_at']);
            $conversation['last_activity'] = intval($conversation['last_activity']);
            $conversation['max_messages_limit'] = intval($conversation['max_messages_limit']);
        }

        return $conversations;
    }

    /**
     * Find conversations by user ID
     *
     * @param string $userId User ID
     * @return array List of conversations
     */
    public function findByUserId($userId) {
        $sql = "
            SELECT conversation_id, platform, user_id, max_messages_limit,
                   UNIX_TIMESTAMP(created_at) as created_at,
                   UNIX_TIMESTAMP(last_activity) as last_activity
            FROM conversations
            WHERE user_id = ?
            ORDER BY last_activity DESC
        ";

        $conversations = $this->fetchAll($sql, array($userId));

        foreach ($conversations as &$conversation) {
            $conversation['created_at'] = intval($conversation['created_at']);
            $conversation['last_activity'] = intval($conversation['last_activity']);
            $conversation['max_messages_limit'] = intval($conversation['max_messages_limit']);
        }

        return $conversations;
    }

    /**
     * Check if conversation exists
     *
     * @param string $conversationId Conversation ID
     * @return bool True if exists
     */
    public function exists($conversationId) {
        $sql = "SELECT 1 FROM conversations WHERE conversation_id = ? LIMIT 1";
        return $this->fetchColumn($sql, array($conversationId)) !== false;
    }

    /**
     * Count total conversations
     *
     * @return int Total count
     */
    public function countAll() {
        $sql = "SELECT COUNT(*) FROM conversations";
        return intval($this->fetchColumn($sql));
    }

    /**
     * Count conversations by platform
     *
     * @param string $platform Platform identifier
     * @return int Count
     */
    public function countByPlatform($platform) {
        $sql = "SELECT COUNT(*) FROM conversations WHERE platform = ?";
        return intval($this->fetchColumn($sql, array($platform)));
    }

    /**
     * Pause chatbot for a conversation (human agent takeover)
     *
     * @param string $conversationId Conversation ID
     * @return bool True if updated
     */
    public function pauseChatbot($conversationId) {
        $sql = "
            UPDATE conversations
            SET is_chatbot_active = 0, paused_at = NOW(), last_activity = NOW()
            WHERE conversation_id = ?
        ";
        return $this->execute($sql, array($conversationId)) > 0;
    }

    /**
     * Resume chatbot for a conversation
     *
     * @param string $conversationId Conversation ID
     * @return bool True if updated
     */
    public function resumeChatbot($conversationId) {
        $sql = "
            UPDATE conversations
            SET is_chatbot_active = 1, paused_at = NULL, last_activity = NOW()
            WHERE conversation_id = ?
        ";
        return $this->execute($sql, array($conversationId)) > 0;
    }

    /**
     * Check if chatbot is active for a conversation
     *
     * @param string $conversationId Conversation ID
     * @return bool True if chatbot is active (or conversation doesn't exist yet)
     */
    public function isChatbotActive($conversationId) {
        $sql = "SELECT is_chatbot_active FROM conversations WHERE conversation_id = ? LIMIT 1";
        $result = $this->fetchColumn($sql, array($conversationId));

        // If conversation doesn't exist, chatbot is active by default
        if ($result === false) {
            return true;
        }

        return intval($result) === 1;
    }

    /**
     * Get all paused conversations
     *
     * @param int $limit Maximum number of results
     * @return array List of paused conversations
     */
    public function findPausedConversations($limit = 100) {
        $sql = "
            SELECT conversation_id, platform, user_id,
                   UNIX_TIMESTAMP(paused_at) as paused_at,
                   UNIX_TIMESTAMP(last_activity) as last_activity
            FROM conversations
            WHERE is_chatbot_active = 0
            ORDER BY paused_at DESC
            LIMIT ?
        ";

        $conversations = $this->fetchAll($sql, array($limit));

        foreach ($conversations as &$conversation) {
            $conversation['paused_at'] = $conversation['paused_at'] ? intval($conversation['paused_at']) : null;
            $conversation['last_activity'] = intval($conversation['last_activity']);
        }

        return $conversations;
    }

    /**
     * Auto-resume chatbot for conversations paused longer than specified time
     *
     * @param int $maxPausedMinutes Maximum pause duration in minutes before auto-resume
     * @return int Number of conversations resumed
     */
    public function autoResumeChatbot($maxPausedMinutes = 30) {
        $sql = "
            UPDATE conversations
            SET is_chatbot_active = 1, paused_at = NULL
            WHERE is_chatbot_active = 0
              AND paused_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ";
        return $this->execute($sql, array($maxPausedMinutes));
    }
}

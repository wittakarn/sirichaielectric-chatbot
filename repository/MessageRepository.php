<?php
/**
 * Message Repository - Handles all message-related database operations
 * PHP 5.6 compatible
 */

require_once __DIR__ . '/BaseRepository.php';

class MessageRepository extends BaseRepository {

    /**
     * Find messages by conversation ID
     *
     * @param string $conversationId Conversation ID
     * @return array List of messages ordered by sequence number
     */
    public function findByConversationId($conversationId) {
        $sql = "
            SELECT id, conversation_id, role, content,
                   UNIX_TIMESTAMP(timestamp) as timestamp,
                   tokens_used, sequence_number
            FROM messages
            WHERE conversation_id = ?
            ORDER BY sequence_number ASC
        ";

        $messages = $this->fetchAll($sql, array($conversationId));

        foreach ($messages as &$message) {
            $message['id'] = intval($message['id']);
            $message['timestamp'] = intval($message['timestamp']);
            $message['tokens_used'] = intval($message['tokens_used']);
            $message['sequence_number'] = intval($message['sequence_number']);
        }

        return $messages;
    }

    /**
     * Get conversation history (simplified format for AI context)
     *
     * @param string $conversationId Conversation ID
     * @return array List of messages with role, content, timestamp, tokens_used
     */
    public function getHistory($conversationId, $limit = 20) {
        $sql = "
            SELECT role, content, UNIX_TIMESTAMP(timestamp) as timestamp, tokens_used
            FROM (
                SELECT role, content, timestamp, tokens_used, sequence_number
                FROM messages
                WHERE conversation_id = ?
                ORDER BY sequence_number DESC
                LIMIT ?
            ) AS recent
            ORDER BY sequence_number ASC
        ";

        $messages = $this->fetchAll($sql, array($conversationId, intval($limit)));

        foreach ($messages as &$message) {
            $message['timestamp'] = intval($message['timestamp']);
            $message['tokens_used'] = intval($message['tokens_used']);
        }

        return $messages;
    }

    /**
     * Add new message
     *
     * @param string $conversationId Conversation ID
     * @param string $role Message role ('user' or 'assistant')
     * @param string $content Message content
     * @param int $tokensUsed Number of tokens used
     * @param int $sequenceNumber Sequence number in conversation
     * @param string|null $searchCriteria JSON array of search categories (optional)
     * @return int Inserted message ID
     */
    public function create($conversationId, $role, $content, $tokensUsed, $sequenceNumber, $searchCriteria = null) {
        $sql = "
            INSERT INTO messages (conversation_id, role, content, tokens_used, sequence_number, search_criteria)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $this->execute($sql, array(
            $conversationId,
            $role,
            $content,
            intval($tokensUsed),
            intval($sequenceNumber),
            $searchCriteria
        ));

        return intval($this->lastInsertId());
    }

    /**
     * Get next sequence number for conversation
     *
     * @param string $conversationId Conversation ID
     * @return int Next sequence number
     */
    public function getNextSequenceNumber($conversationId) {
        $sql = "
            SELECT COALESCE(MAX(sequence_number), 0) + 1 as next_seq
            FROM messages
            WHERE conversation_id = ?
        ";

        return intval($this->fetchColumn($sql, array($conversationId)));
    }

    /**
     * Count messages in conversation
     *
     * @param string $conversationId Conversation ID
     * @return int Message count
     */
    public function countByConversationId($conversationId) {
        $sql = "SELECT COUNT(*) FROM messages WHERE conversation_id = ?";
        return intval($this->fetchColumn($sql, array($conversationId)));
    }

    /**
     * Delete oldest messages keeping only recent N messages and/or messages within date range
     *
     * @param string $conversationId Conversation ID
     * @param int $keepCount Number of recent messages to keep
     * @param int $olderThanDays Delete messages older than N days (default 3)
     * @return int Number of deleted messages
     */
    public function deleteOldest($conversationId, $keepCount, $olderThanDays = 3) {
        // Delete messages that are both outside the keep count OR older than specified days
        $sql = "
            DELETE FROM messages
            WHERE conversation_id = ?
            AND (
                id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM messages
                        WHERE conversation_id = ?
                        ORDER BY sequence_number DESC
                        LIMIT ?
                    ) as recent_messages
                )
                OR timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)
            )
        ";

        return $this->execute($sql, array($conversationId, $conversationId, $keepCount, $olderThanDays));
    }

    /**
     * Delete all messages for a conversation
     *
     * @param string $conversationId Conversation ID
     * @return int Number of deleted messages
     */
    public function deleteByConversationId($conversationId) {
        $sql = "DELETE FROM messages WHERE conversation_id = ?";
        return $this->execute($sql, array($conversationId));
    }

    /**
     * Get total tokens used in conversation
     *
     * @param string $conversationId Conversation ID
     * @return int Total tokens used
     */
    public function getTotalTokens($conversationId) {
        $sql = "SELECT COALESCE(SUM(tokens_used), 0) FROM messages WHERE conversation_id = ?";
        return intval($this->fetchColumn($sql, array($conversationId)));
    }

    /**
     * Get last message in conversation
     *
     * @param string $conversationId Conversation ID
     * @return array|null Last message or null
     */
    public function getLastMessage($conversationId) {
        $sql = "
            SELECT id, conversation_id, role, content,
                   UNIX_TIMESTAMP(timestamp) as timestamp,
                   tokens_used, sequence_number
            FROM messages
            WHERE conversation_id = ?
            ORDER BY sequence_number DESC
            LIMIT 1
        ";

        $message = $this->fetchOne($sql, array($conversationId));

        if ($message) {
            $message['id'] = intval($message['id']);
            $message['timestamp'] = intval($message['timestamp']);
            $message['tokens_used'] = intval($message['tokens_used']);
            $message['sequence_number'] = intval($message['sequence_number']);
        }

        return $message;
    }

    /**
     * Get last N messages in conversation (for monitoring preview)
     *
     * @param string $conversationId Conversation ID
     * @param int $limit Number of recent messages to get (default 6)
     * @return array List of recent messages
     */
    public function getLastNMessages($conversationId, $limit = 6) {
        $sql = "
            SELECT id, conversation_id, role, content,
                   UNIX_TIMESTAMP(timestamp) as timestamp,
                   tokens_used, sequence_number
            FROM messages
            WHERE conversation_id = ?
            ORDER BY sequence_number DESC
            LIMIT ?
        ";

        $messages = $this->fetchAll($sql, array($conversationId, $limit));

        // Reverse to get chronological order (oldest to newest)
        $messages = array_reverse($messages);

        foreach ($messages as &$message) {
            $message['id'] = intval($message['id']);
            $message['timestamp'] = intval($message['timestamp']);
            $message['tokens_used'] = intval($message['tokens_used']);
            $message['sequence_number'] = intval($message['sequence_number']);
        }

        return $messages;
    }

    /**
     * Find messages by role in conversation
     *
     * @param string $conversationId Conversation ID
     * @param string $role Message role ('user' or 'assistant')
     * @return array List of messages
     */
    public function findByRole($conversationId, $role) {
        $sql = "
            SELECT id, conversation_id, role, content,
                   UNIX_TIMESTAMP(timestamp) as timestamp,
                   tokens_used, sequence_number
            FROM messages
            WHERE conversation_id = ? AND role = ?
            ORDER BY sequence_number ASC
        ";

        $messages = $this->fetchAll($sql, array($conversationId, $role));

        foreach ($messages as &$message) {
            $message['id'] = intval($message['id']);
            $message['timestamp'] = intval($message['timestamp']);
            $message['tokens_used'] = intval($message['tokens_used']);
            $message['sequence_number'] = intval($message['sequence_number']);
        }

        return $messages;
    }
}

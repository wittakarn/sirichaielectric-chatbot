<?php
/**
 * Conversation Manager for SirichaiElectric Chatbot
 * Manages conversation history using MySQL database via Repository pattern
 * PHP 5.6 compatible
 */

require_once __DIR__ . '/DatabaseManager.php';
require_once __DIR__ . '/repository/ConversationRepository.php';
require_once __DIR__ . '/repository/MessageRepository.php';

class ConversationManager {
    private $maxMessagesPerConversation;
    private $platform;
    private $database;

    /** @var ConversationRepository */
    private $conversationRepository;

    /** @var MessageRepository */
    private $messageRepository;

    /**
     * Constructor
     *
     * @param int $maxMessages Maximum messages to keep per conversation (default 50)
     * @param string $platform Platform identifier ('api' or 'line')
     * @param array $dbConfig Database configuration array
     * @throws Exception if database connection fails
     */
    public function __construct($maxMessages = 50, $platform = 'api', $dbConfig = null) {
        $this->maxMessagesPerConversation = $maxMessages;
        $this->platform = $platform;

        if ($dbConfig === null) {
            throw new Exception('Database configuration required');
        }

        $this->database = DatabaseManager::getInstance($dbConfig);
        $pdo = $this->database->getConnection();

        $this->conversationRepository = new ConversationRepository($pdo);
        $this->messageRepository = new MessageRepository($pdo);
    }

    /**
     * Get conversation history (messages only)
     *
     * @param string $conversationId Conversation ID
     * @return array Array of messages with role, content, timestamp, tokens_used
     */
    public function getConversationHistory($conversationId) {
        try {
            return $this->messageRepository->getHistory($conversationId);
        } catch (PDOException $e) {
            error_log('[ConversationManager] getConversationHistory failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Add message to conversation with token tracking
     *
     * @param string $conversationId Conversation ID
     * @param string $role Message role ('user' or 'assistant')
     * @param string $content Message content
     * @param int $tokensUsed Number of tokens used (default 0)
     * @return bool True on success
     * @throws Exception if database operation fails
     */
    public function addMessage($conversationId, $role, $content, $tokensUsed = 0) {
        try {
            $this->conversationRepository->beginTransaction();

            // Extract user_id from conversation_id if it's a LINE conversation
            $userId = null;
            if (strpos($conversationId, 'line_') === 0) {
                $userId = substr($conversationId, 5);
            }

            // Upsert conversation record
            $this->conversationRepository->upsert(
                $conversationId,
                $this->platform,
                $userId,
                $this->maxMessagesPerConversation
            );

            // Get next sequence number and create message
            $nextSeq = $this->messageRepository->getNextSequenceNumber($conversationId);
            $this->messageRepository->create($conversationId, $role, $content, $tokensUsed, $nextSeq);

            // Trim old messages
            $this->trimConversation($conversationId);

            $this->conversationRepository->commit();
            return true;

        } catch (PDOException $e) {
            $this->conversationRepository->rollback();
            error_log('[ConversationManager] addMessage failed: ' . $e->getMessage());
            throw new Exception('Failed to add message: ' . $e->getMessage());
        }
    }

    /**
     * Trim conversation to keep only recent messages
     *
     * @param string $conversationId Conversation ID
     */
    private function trimConversation($conversationId) {
        try {
            $count = $this->messageRepository->countByConversationId($conversationId);

            if ($count > $this->maxMessagesPerConversation) {
                $deleted = $this->messageRepository->deleteOldest(
                    $conversationId,
                    $this->maxMessagesPerConversation
                );
                error_log("[ConversationManager] Trimmed $deleted messages from $conversationId");
            }
        } catch (PDOException $e) {
            error_log('[ConversationManager] trimConversation failed: ' . $e->getMessage());
        }
    }

    /**
     * Clear a specific conversation
     *
     * @param string $conversationId Conversation ID
     * @return bool True if conversation was deleted
     */
    public function clearConversation($conversationId) {
        try {
            return $this->conversationRepository->delete($conversationId);
        } catch (PDOException $e) {
            error_log('[ConversationManager] clearConversation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all conversations (use with caution)
     *
     * @return int Number of conversations deleted
     */
    public function clearAllConversations() {
        try {
            return $this->conversationRepository->deleteAll();
        } catch (PDOException $e) {
            error_log('[ConversationManager] clearAllConversations failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get full conversation object
     *
     * @param string $conversationId Conversation ID
     * @return array|null Conversation object or null if not found
     */
    public function getConversation($conversationId) {
        try {
            $conversation = $this->conversationRepository->findById($conversationId);

            if (!$conversation) {
                return null;
            }

            // Get messages
            $conversation['messages'] = $this->messageRepository->getHistory($conversationId);

            // Maintain backward compatibility with old structure
            $conversation['conversationId'] = $conversation['conversation_id'];
            $conversation['createdAt'] = $conversation['created_at'];
            $conversation['lastActivity'] = $conversation['last_activity'];

            return $conversation;

        } catch (PDOException $e) {
            error_log('[ConversationManager] getConversation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cleanup old conversations
     *
     * @param int $maxAgeHours Maximum age in hours (default 24)
     * @return int Number of conversations cleaned up
     */
    public function cleanupOldConversations($maxAgeHours = 24) {
        try {
            $cleaned = $this->conversationRepository->deleteOlderThan($maxAgeHours);

            if ($cleaned > 0) {
                error_log("[ConversationManager] Cleaned up $cleaned old conversations");
            }

            return $cleaned;

        } catch (PDOException $e) {
            error_log('[ConversationManager] cleanupOldConversations failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Generate unique conversation ID
     *
     * @return string Generated conversation ID
     */
    public function generateConversationId() {
        return 'conv_' . time() . '_' . substr(md5(uniqid(rand(), true)), 0, 9);
    }

    /**
     * Get total tokens used in conversation
     *
     * @param string $conversationId Conversation ID
     * @return int Total tokens used
     */
    public function getTotalTokens($conversationId) {
        try {
            return $this->messageRepository->getTotalTokens($conversationId);
        } catch (PDOException $e) {
            error_log('[ConversationManager] getTotalTokens failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get conversations by platform
     *
     * @param string $platform Platform identifier
     * @param int $limit Maximum results
     * @return array List of conversations
     */
    public function getConversationsByPlatform($platform, $limit = 100) {
        try {
            return $this->conversationRepository->findByPlatform($platform, $limit);
        } catch (PDOException $e) {
            error_log('[ConversationManager] getConversationsByPlatform failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Get conversations by user ID
     *
     * @param string $userId User ID
     * @return array List of conversations
     */
    public function getConversationsByUserId($userId) {
        try {
            return $this->conversationRepository->findByUserId($userId);
        } catch (PDOException $e) {
            error_log('[ConversationManager] getConversationsByUserId failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Pause chatbot for a conversation (human agent takeover)
     *
     * @param string $conversationId Conversation ID
     * @return bool True if paused successfully
     */
    public function pauseChatbot($conversationId) {
        try {
            $result = $this->conversationRepository->pauseChatbot($conversationId);
            if ($result) {
                error_log("[ConversationManager] Chatbot paused for: $conversationId");
            }
            return $result;
        } catch (PDOException $e) {
            error_log('[ConversationManager] pauseChatbot failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resume chatbot for a conversation
     *
     * @param string $conversationId Conversation ID
     * @return bool True if resumed successfully
     */
    public function resumeChatbot($conversationId) {
        try {
            $result = $this->conversationRepository->resumeChatbot($conversationId);
            if ($result) {
                error_log("[ConversationManager] Chatbot resumed for: $conversationId");
            }
            return $result;
        } catch (PDOException $e) {
            error_log('[ConversationManager] resumeChatbot failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if chatbot is active for a conversation
     *
     * @param string $conversationId Conversation ID
     * @return bool True if chatbot is active
     */
    public function isChatbotActive($conversationId) {
        try {
            return $this->conversationRepository->isChatbotActive($conversationId);
        } catch (PDOException $e) {
            error_log('[ConversationManager] isChatbotActive failed: ' . $e->getMessage());
            return true; // Default to active on error
        }
    }

    /**
     * Get all paused conversations (for admin/agent dashboard)
     *
     * @param int $limit Maximum number of results
     * @return array List of paused conversations
     */
    public function getPausedConversations($limit = 100) {
        try {
            return $this->conversationRepository->findPausedConversations($limit);
        } catch (PDOException $e) {
            error_log('[ConversationManager] getPausedConversations failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Auto-resume chatbot for conversations paused too long
     *
     * @param int $maxPausedMinutes Maximum pause duration in minutes (default 30)
     * @return int Number of conversations auto-resumed
     */
    public function autoResumeChatbot($maxPausedMinutes = 30) {
        try {
            $resumed = $this->conversationRepository->autoResumeChatbot($maxPausedMinutes);
            if ($resumed > 0) {
                error_log("[ConversationManager] Auto-resumed $resumed conversations after $maxPausedMinutes minutes");
            }
            return $resumed;
        } catch (PDOException $e) {
            error_log('[ConversationManager] autoResumeChatbot failed: ' . $e->getMessage());
            return 0;
        }
    }
}

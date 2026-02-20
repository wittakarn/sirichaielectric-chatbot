<?php
/**
 * Conversation Manager for SirichaiElectric Chatbot
 * Manages conversation history using MySQL database via Repository pattern
 * PHP 5.6 compatible
 */

require_once __DIR__ . '/DatabaseManager.php';
require_once __DIR__ . '/../repository/ConversationRepository.php';
require_once __DIR__ . '/../repository/MessageRepository.php';
require_once __DIR__ . '/../repository/AuthorizedUserRepository.php';

class ConversationManager {
    private $maxMessagesPerConversation;
    private $platform;
    private $database;

    /** @var ConversationRepository */
    private $conversationRepository;

    /** @var MessageRepository */
    private $messageRepository;

    /** @var AuthorizedUserRepository */
    private $authorizedUserRepository;

    /**
     * Constructor
     *
     * @param int $maxMessages Maximum messages to keep per conversation (default 20)
     * @param string $platform Platform identifier ('api' or 'line')
     * @param array $dbConfig Database configuration array
     * @throws Exception if database connection fails
     */
    public function __construct($maxMessages = 20, $platform = 'api', $dbConfig = null) {
        $this->maxMessagesPerConversation = $maxMessages;
        $this->platform = $platform;

        if ($dbConfig === null) {
            throw new Exception('Database configuration required');
        }

        $this->database = DatabaseManager::getInstance($dbConfig);
        $pdo = $this->database->getConnection();

        $this->conversationRepository = new ConversationRepository($pdo);
        $this->messageRepository = new MessageRepository($pdo);
        $this->authorizedUserRepository = new AuthorizedUserRepository($pdo);
    }

    /**
     * Get conversation history (messages only)
     *
     * @param string $conversationId Conversation ID
     * @return array Array of messages with role, content, timestamp, tokens_used
     */
    public function getConversationHistory($conversationId) {
        try {
            return $this->messageRepository->getHistory($conversationId, $this->maxMessagesPerConversation);
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
     * @param string|null $searchCriteria JSON array of search categories (optional)
     * @return bool True on success
     * @throws Exception if database operation fails
     */
    public function addMessage($conversationId, $role, $content, $tokensUsed = 0, $searchCriteria = null) {
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
            $this->messageRepository->create($conversationId, $role, $content, $tokensUsed, $nextSeq, $searchCriteria);

            $this->conversationRepository->commit();
            return true;

        } catch (PDOException $e) {
            $this->conversationRepository->rollback();
            error_log('[ConversationManager] addMessage failed: ' . $e->getMessage());
            throw new Exception('Failed to add message: ' . $e->getMessage());
        }
    }

    /**
     * Reset conversation history by deactivating all messages (soft reset)
     * Messages are preserved in the database but excluded from chat context.
     *
     * @param string $conversationId Conversation ID
     * @return int Number of messages deactivated
     */
    public function resetConversationHistory($conversationId) {
        try {
            $count = $this->messageRepository->deactivateByConversationId($conversationId);
            error_log("[ConversationManager] Reset history for $conversationId ($count messages deactivated)");
            return $count;
        } catch (PDOException $e) {
            error_log('[ConversationManager] resetConversationHistory failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Reset all conversations for a LINE group (soft reset)
     * Deactivates messages for all users in the group.
     * Conversation IDs follow pattern: line_group_{groupId}_{userId}
     *
     * @param string $groupId LINE Group/Room ID
     * @return int Number of conversations reset
     */
    public function resetGroupConversations($groupId) {
        try {
            $count = $this->messageRepository->deactivateByGroupId($groupId);
            error_log("[ConversationManager] Reset group conversations for group $groupId ($count conversations reset)");
            return $count;
        } catch (PDOException $e) {
            error_log('[ConversationManager] resetGroupConversations failed: ' . $e->getMessage());
            return 0;
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

    /**
     * Get active conversations from recent days
     *
     * @param int $days Number of days to look back (default 2)
     * @param int $limit Maximum number of results
     * @return array List of active conversations
     */
    public function getActiveConversations($days = 2, $limit = 100) {
        try {
            return $this->conversationRepository->findActiveRecent($days, $limit);
        } catch (PDOException $e) {
            error_log('[ConversationManager] getActiveConversations failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Check if a user is authorized to generate quotations
     *
     * @param string $userId LINE user ID or internal user identifier
     * @return bool True if authorized, false otherwise
     */
    public function isUserAuthorized($userId) {
        try {
            return $this->authorizedUserRepository->isAuthorized($userId);
        } catch (PDOException $e) {
            error_log('[ConversationManager] isUserAuthorized failed: ' . $e->getMessage());
            return false;
        }
    }
}

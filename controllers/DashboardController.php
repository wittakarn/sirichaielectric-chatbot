<?php
/**
 * DashboardController - API endpoints for dashboard monitoring
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../DatabaseManager.php';
require_once __DIR__ . '/../services/DashboardService.php';

class DashboardController {
    private $dashboardService;

    public function __construct() {
        $config = Config::getInstance();
        $dbConfig = $config->get('database');
        $dbManager = DatabaseManager::getInstance($dbConfig);
        $pdo = $dbManager->getConnection();
        $this->dashboardService = new DashboardService($pdo);
    }

    /**
     * Set JSON response headers
     */
    private function setJsonHeaders() {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }

    /**
     * Send JSON response
     */
    private function sendJsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Send error response
     */
    private function sendError($message, $statusCode = 500) {
        $this->sendJsonResponse(array(
            'success' => false,
            'error' => $message
        ), $statusCode);
    }

    /**
     * GET /api/monitoring/conversations
     * Get recent conversations for monitoring grid
     */
    public function getMonitoringConversations() {
        $this->setJsonHeaders();

        try {
            $conversationLimit = isset($_GET['conversation_limit']) ? (int)$_GET['conversation_limit'] : 6;
            $messageLimit = isset($_GET['message_limit']) ? (int)$_GET['message_limit'] : 6;

            $conversations = $this->dashboardService->getRecentConversationsForGrid(
                $conversationLimit,
                $messageLimit
            );

            $this->sendJsonResponse(array(
                'success' => true,
                'data' => $conversations,
                'timestamp' => time()
            ));

        } catch (Exception $e) {
            error_log('[DashboardController] Error: ' . $e->getMessage());
            $this->sendError($e->getMessage());
        }
    }

    /**
     * GET /api/conversation/{id}
     * Get full conversation with all messages
     */
    public function getConversation($conversationId) {
        $this->setJsonHeaders();

        try {
            if (empty($conversationId)) {
                $this->sendError('Conversation ID is required', 400);
            }

            $conversation = $this->dashboardService->getConversationWithMessages($conversationId);

            if ($conversation === null) {
                $this->sendError('Conversation not found', 404);
            }

            $this->sendJsonResponse(array(
                'success' => true,
                'data' => $conversation
            ));

        } catch (Exception $e) {
            error_log('[DashboardController] Error: ' . $e->getMessage());
            $this->sendError($e->getMessage());
        }
    }
}

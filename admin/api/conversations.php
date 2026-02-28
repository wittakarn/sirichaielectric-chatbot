<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('error_log', dirname(__FILE__) . '/../../logs.log');
/**
 * API Endpoint: /admin/api/conversations.php
 * GET ?date=YYYY-MM-DD - Fetch conversations for a specific date
 * GET ?conversation_id=xxx - Fetch full conversation with all messages
 */

require_once __DIR__ . '/../../controllers/DashboardController.php';

$controller = new DashboardController();

if (isset($_GET['conversation_id'])) {
    $controller->getConversation($_GET['conversation_id']);
} else {
    $controller->getConversationsByDate();
}

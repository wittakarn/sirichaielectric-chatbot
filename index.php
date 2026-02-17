<?php
/**
 * Sirichai Electric Chatbot API
 * Main entry point for the PHP chatbot
 * PHP 5.6 compatible
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in production
ini_set('error_log', dirname(__FILE__) . '/logs.log');

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/ProductAPIService.php';
require_once __DIR__ . '/chatbot/GeminiFileManager.php';
require_once __DIR__ . '/chatbot/SirichaiElectricChatbot.php';
require_once __DIR__ . '/chatbot/ConversationManager.php';

// Initialize configuration
try {
    $config = Config::getInstance();
    $config->validate();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error' => 'Configuration error: ' . $e->getMessage(),
    ));
    exit;
}

// Initialize Gemini configuration
$geminiConfig = $config->get('gemini');

// Initialize Product API Service (replaces context cache)
$productAPIConfig = $config->get('productAPI');
$productAPI = new ProductAPIService($productAPIConfig);

// Initialize chatbot with Product API Service (zero context cache)
$chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);

// Initialize conversation manager with database
$dbConfig = $config->get('database');
$conversationConfig = $config->get('conversation');
$maxMessages = isset($conversationConfig['maxMessages']) ? $conversationConfig['maxMessages'] : 20;
$conversationManager = new ConversationManager($maxMessages, 'api', $dbConfig);

// Route the request
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// Get the path - use REDIRECT_URL if available (from .htaccess rewrite), otherwise parse REQUEST_URI
if (isset($_SERVER['REDIRECT_URL']) && !empty($_SERVER['REDIRECT_URL'])) {
    // Apache mod_rewrite provides the clean path in REDIRECT_URL
    $path = $_SERVER['REDIRECT_URL'];
} else {
    // Fallback to parsing REQUEST_URI
    $requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
    $path = str_replace($scriptName, '', $requestUri);
    $path = strtok($path, '?');
}

// Remove base path(s) from config
$serverConfig = $config->get('server');
$basePathConfig = isset($serverConfig['basePath']) ? $serverConfig['basePath'] : '';

if (!empty($basePathConfig)) {
    // Support comma-separated multiple base paths
    $basePaths = array_map('trim', explode(',', $basePathConfig));

    foreach ($basePaths as $basePath) {
        if (empty($basePath)) {
            continue;
        }

        // Ensure basePath starts with /
        if (strpos($basePath, '/') !== 0) {
            $basePath = '/' . $basePath;
        }

        // Remove basePath if it matches the start of the path
        if (strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
            break; // Only remove one base path
        }
    }
}

$path = trim($path, '/');

// Route handling
if ($requestMethod === 'GET' && ($path === '' || $path === 'health')) {
    handleHealthCheck();
} elseif ($requestMethod === 'POST' && $path === 'chat') {
    handleChat($chatbot, $conversationManager);
} elseif ($requestMethod === 'GET' && strpos($path, 'conversation/') === 0) {
    handleGetConversation($conversationManager, $path);
} elseif ($requestMethod === 'DELETE' && strpos($path, 'conversation/') === 0) {
    handleClearConversation($conversationManager, $path);
} else {
    http_response_code(404);
    echo json_encode(array(
        'success' => false,
        'error' => 'Endpoint not found',
    ));
}

// Handler functions

function handleHealthCheck() {
    echo json_encode(array(
        'status' => 'ok',
        'service' => 'Sirichai Electric Chatbot (PHP)',
        'version' => '3.0.0',
        'mode' => 'Optimized (catalog in prompt, 3x faster)',
        'timestamp' => date('c'),
    ));
}

function handleChat($chatbot, $conversationManager) {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(array(
            'success' => false,
            'error' => 'Invalid JSON',
        ));
        return;
    }

    // Validate input
    if (!isset($data['message']) || empty(trim($data['message']))) {
        http_response_code(400);
        echo json_encode(array(
            'success' => false,
            'error' => 'Message is required',
        ));
        return;
    }

    $message = trim($data['message']);
    $conversationId = isset($data['conversationId']) && !empty($data['conversationId'])
        ? $data['conversationId']
        : $conversationManager->generateConversationId();

    // Get conversation history
    $history = $conversationManager->getConversationHistory($conversationId);

    // Get chatbot response first (to capture search criteria)
    $response = $chatbot->chat($message, $history);

    // Add user message to history with search criteria if available
    $searchCriteria = isset($response['searchCriteria']) ? $response['searchCriteria'] : null;
    $conversationManager->addMessage($conversationId, 'user', $message, 0, $searchCriteria);

    if ($response['success']) {
        // Add assistant response to history with token tracking (no search criteria for assistant)
        $tokensUsed = isset($response['tokensUsed']) ? $response['tokensUsed'] : 0;
        $conversationManager->addMessage($conversationId, 'assistant', $response['response'], $tokensUsed, null);

        echo json_encode(array(
            'success' => true,
            'response' => $response['response'],
            'conversationId' => $conversationId,
            'language' => $response['language'],
        ));
    } else {
        http_response_code(500);
        echo json_encode(array(
            'success' => false,
            'response' => '',
            'conversationId' => $conversationId,
            'error' => $response['error'],
        ));
    }
}

function handleGetConversation($conversationManager, $path) {
    $conversationId = str_replace('conversation/', '', $path);

    $conversation = $conversationManager->getConversation($conversationId);

    if ($conversation === null) {
        http_response_code(404);
        echo json_encode(array(
            'success' => false,
            'error' => 'Conversation not found',
        ));
        return;
    }

    echo json_encode(array(
        'success' => true,
        'conversation' => $conversation,
    ));
}

function handleClearConversation($conversationManager, $path) {
    $conversationId = str_replace('conversation/', '', $path);

    $deleted = $conversationManager->clearConversation($conversationId);

    echo json_encode(array(
        'success' => $deleted,
        'message' => $deleted ? 'Conversation cleared' : 'Conversation not found',
    ));
}

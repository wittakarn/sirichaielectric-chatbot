<?php
/**
 * Sirichai Electric Chatbot API
 * Main entry point for the PHP chatbot
 * PHP 5.6 compatible
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in production
ini_set('log_errors', 1);

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
require_once __DIR__ . '/ProductFetcher.php';
require_once __DIR__ . '/SirichaiChatbot.php';
require_once __DIR__ . '/ConversationManager.php';

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

// Initialize product fetcher
$productConfig = $config->get('productData');
$productFetcher = new ProductFetcher($productConfig);

// Check if product data needs updating
if ($productFetcher->shouldUpdate()) {
    $productFetcher->fetchProductData();
}

// Initialize chatbot
$geminiConfig = $config->get('gemini');
$chatbot = new SirichaiChatbot($geminiConfig, $productFetcher);

// Initialize conversation manager
$conversationManager = new ConversationManager(10);

// Route the request
$requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// Remove query string and script name from URI
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
$path = str_replace($scriptName, '', $requestUri);
$path = strtok($path, '?');
$path = trim($path, '/');

// Route handling
if ($requestMethod === 'GET' && ($path === '' || $path === 'health')) {
    handleHealthCheck($productFetcher);
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

function handleHealthCheck($productFetcher) {
    $lastUpdated = $productFetcher->getLastUpdated();
    echo json_encode(array(
        'status' => 'ok',
        'service' => 'Sirichai Electric Chatbot (PHP)',
        'version' => '1.0.0',
        'timestamp' => date('c'),
        'productDataLastUpdated' => $lastUpdated ? date('c', $lastUpdated) : null,
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

    // Add user message to history
    $conversationManager->addMessage($conversationId, 'user', $message);

    // Get chatbot response
    $response = $chatbot->chat($message, $history);

    if ($response['success']) {
        // Add assistant response to history
        $conversationManager->addMessage($conversationId, 'assistant', $response['response']);

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

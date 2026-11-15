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
// Same-origin by default (chat.html calls its own host, also when iframed).
// ALLOWED_ORIGINS="https://a.com,https://b.com" opens it to other sites.
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$allowedOrigins = array_filter(array_map('trim', explode(',', (string)getenv('ALLOWED_ORIGINS'))));
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Chat-Token');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/AppConfig.php';
require_once __DIR__ . '/services/ProductAPIService.php';
require_once __DIR__ . '/services/SearchService.php';
require_once __DIR__ . '/services/ChatAccess.php';
require_once __DIR__ . '/chatbot/SirichaiElectricChatbot.php';

use ChatbotCore\ConversationManager;

const CHAT_COOKIE = 'sirichai_cid';
const CHAT_COOKIE_TTL = 604800; // 7 days

// Initialize configuration
try {
    $config = AppConfig::getInstance();
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

// Initialize Product API Service with Supabase-backed search
$supabaseCfg   = $config->get('supabase');
$searchService = new SearchService($config->get('gemini', 'apiKey'), $supabaseCfg['restUrl'], $supabaseCfg['key']);
$productAPIConfig = $config->get('productAPI');
$productAPI = new ProductAPIService($productAPIConfig, $searchService);

// Initialize chatbot with Product API Service (zero context cache)
$chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);

// Initialize conversation manager with database
$dbConfig = $config->get('database');
$conversationConfig = $config->get('conversation');
$maxMessages = isset($conversationConfig['maxMessages']) ? $conversationConfig['maxMessages'] : 20;
$conversationManager = new ConversationManager($maxMessages, 'api', $dbConfig);

// Route the request
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// Get the path from REQUEST_URI (reliable across all Apache versions/setups)
// REDIRECT_URL is intentionally avoided: on MAMP/some Apache configs it holds the
// rewritten destination (index.php) instead of the original URL.
$requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
$path = str_replace($scriptName, '', $requestUri);
$path = strtok($path, '?');

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
} elseif ($requestMethod === 'POST' && $path === 'verify') {
    handleVerify($config);
} elseif ($requestMethod === 'POST' && $path === 'chat') {
    requireChatAccess($config, $dbConfig);
    handleChat($chatbot, $conversationManager);
} elseif ($requestMethod === 'GET' && $path === 'history') {
    handleGetHistory($conversationManager);
} elseif ($requestMethod === 'POST' && $path === 'new-chat') {
    handleNewChat();
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
        'mode' => 'RAG-based catalog search',
        'timestamp' => date('c'),
    ));
}

function handleVerify($config) {
    $cfg  = $config->get('turnstile');
    $data = json_decode(file_get_contents('php://input'), true);
    $resp = is_array($data) && isset($data['turnstileToken']) ? (string)$data['turnstileToken'] : '';
    $ip   = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

    if ($cfg['tokenSecret'] === '' || !ChatAccess::verifyTurnstile($cfg['secret'], $resp, $ip)) {
        http_response_code(403);
        echo json_encode(array('success' => false, 'error' => 'Verification failed'));
        exit;
    }
    echo json_encode(array('success' => true, 'token' => ChatAccess::issueToken($cfg['tokenSecret'], time())));
}

/** Rejects /chat without a valid session token (401) or over the per-token limit (429). */
function requireChatAccess($config, $dbConfig) {
    $token = isset($_SERVER['HTTP_X_CHAT_TOKEN']) ? $_SERVER['HTTP_X_CHAT_TOKEN'] : '';
    $now   = time();
    $id    = ChatAccess::verifyToken($token, $config->get('turnstile', 'tokenSecret'), $now);
    if ($id === null) {
        http_response_code(401);
        echo json_encode(array('success' => false, 'error' => 'Verification required', 'verify' => true));
        exit;
    }

    try {
        $pdo = \ChatbotCore\DatabaseManager::getInstance($dbConfig)->getConnection();
        $hit = function ($tokenId, $window) use ($pdo) {
            $pdo->prepare('INSERT INTO chat_rate_limits (token_id, window_start, hits) VALUES (?, ?, 1)
                           ON DUPLICATE KEY UPDATE hits = hits + 1')->execute(array($tokenId, $window));
            $q = $pdo->prepare('SELECT hits FROM chat_rate_limits WHERE token_id = ? AND window_start = ?');
            $q->execute(array($tokenId, $window));
            $hits = (int)$q->fetchColumn();
            if (mt_rand(1, 100) === 1) { // housekeeping
                $pdo->prepare('DELETE FROM chat_rate_limits WHERE window_start < ?')->execute(array($window - 60));
            }
            return $hits;
        };
        $allowed = ChatAccess::withinLimit($hit, $id, $now, $config->get('turnstile', 'maxPerMinute'));
    } catch (\Throwable $e) {
        // Fail closed, but say why in the log instead of a blank 500.
        error_log('[ChatAccess] rate limiter failed: ' . get_class($e) . ': ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(array('success' => false, 'error' => 'Service temporarily unavailable. Please try again shortly.'));
        exit;
    }

    if (!$allowed) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(array('success' => false, 'error' => 'Too many requests. Please wait a minute and try again.'));
        exit;
    }
}

function currentConversationId() {
    $id = isset($_COOKIE[CHAT_COOKIE]) ? $_COOKIE[CHAT_COOKIE] : '';
    return preg_match('/^conv_[0-9]+_[a-f0-9]{9}$/', $id) ? $id : '';
}

function setConversationCookie($id, $expires) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie(CHAT_COOKIE, $id, array(
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax', // iframe on shop.* is same-site as assistant.*
    ));
}

/** Messages of this browser's conversation, for redrawing the chat after a refresh. */
function handleGetHistory($conversationManager) {
    $id = currentConversationId();
    $messages = $id === '' ? array() : $conversationManager->getConversationHistory($id);
    echo json_encode(array(
        'success'  => true,
        'messages' => array_map(function ($m) {
            return array('role' => $m['role'], 'content' => $m['content']);
        }, $messages),
    ));
}

/** "New chat": forget the cookie only. The old conversation stays in the database; the next message starts a new one. */
function handleNewChat() {
    setConversationCookie('', time() - 3600);
    echo json_encode(array('success' => true));
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
    // The conversation is identified by an HttpOnly cookie only (never by a client-supplied id),
    // so one visitor cannot read or continue another's conversation by guessing an id.
    $conversationId = currentConversationId();
    if ($conversationId === '') {
        $conversationId = $conversationManager->generateConversationId();
    }
    setConversationCookie($conversationId, time() + CHAT_COOKIE_TTL);

    // Handle /reset command
    if ($message === '/reset') {
        $conversationManager->resetConversationHistory($conversationId);
        echo json_encode(array(
            'success' => true,
            'reset' => true,
            'response' => "Chat history has been cleared. You can start a fresh conversation now.",
            'conversationId' => $conversationId,
        ));
        return;
    }

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


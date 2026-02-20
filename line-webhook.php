<?php
/**
 * LINE Webhook Handler for Sirichai Electric Chatbot
 * Receives messages from LINE Official Account and responds using the chatbot
 * PHP 5.6 compatible
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('error_log', dirname(__FILE__) . '/logs.log');

// Allow unlimited execution time for background processing
set_time_limit(0);
ini_set('max_execution_time', 0);

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/ProductAPIService.php';
require_once __DIR__ . '/chatbot/GeminiFileManager.php';
require_once __DIR__ . '/chatbot/SirichaiElectricChatbot.php';
require_once __DIR__ . '/chatbot/ConversationManager.php';
require_once __DIR__ . '/utils/LineWebhookUtils.php';

// Initialize configuration
try {
    $config = Config::getInstance();
    $config->validate();
} catch (Exception $e) {
    error_log('[LINE Webhook] Configuration error: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

// Get LINE configuration
$lineConfig = $config->get('line');
$channelSecret = $lineConfig['channelSecret'];
$channelAccessToken = $lineConfig['channelAccessToken'];

if (empty($channelSecret) || empty($channelAccessToken)) {
    error_log('[LINE Webhook] LINE credentials not configured');
    http_response_code(500);
    exit;
}

// Verify LINE signature
$body = file_get_contents('php://input');
$signature = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? $_SERVER['HTTP_X_LINE_SIGNATURE'] : '';

// SECURITY WARNING: Signature verification is disabled for testing
// Enable this in production by setting VERIFY_LINE_SIGNATURE=true in .env
$verifySignature = getenv('VERIFY_LINE_SIGNATURE') !== 'false';

if ($verifySignature) {
    if (!LineWebhookUtils::verifySignature($body, $signature, $channelSecret)) {
        error_log('[LINE Webhook] Invalid signature');
        http_response_code(403);
        exit;
    }
}

// Parse events
$events = json_decode($body, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log('[LINE Webhook] Invalid JSON: ' . json_last_error_msg());
    http_response_code(400);
    exit;
}

// CRITICAL: Respond to LINE within 2 seconds to prevent webhook timeout
// See: https://developers.line.biz/en/docs/messaging-api/receiving-messages/
// "If HTTP 2xx isn't sent within 2 seconds, a request_timeout error occurs"
http_response_code(200);

// Allow script to continue running after client disconnect
ignore_user_abort(true);

// Close the connection immediately so LINE receives the 200 response
// LiteSpeed LSAPI v8.1 supports both litespeed_finish_request() and fastcgi_finish_request()
if (function_exists('litespeed_finish_request')) {
    error_log('[LINE Webhook] Using litespeed_finish_request() to close connection');
    litespeed_finish_request(); // Native LiteSpeed function
} elseif (function_exists('fastcgi_finish_request')) {
    error_log('[LINE Webhook] Using fastcgi_finish_request() to close connection');
    fastcgi_finish_request(); // Fallback for PHP-FPM
} else {
    error_log('[LINE Webhook] Using header-based connection close (fallback)');
    // Fallback for other SAPIs
    header('Content-Length: 0');
    header('Connection: close');
    flush();
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

// Now process events asynchronously (after LINE has received the 200 response)
if (isset($events['events']) && is_array($events['events']) && count($events['events']) > 0) {
    $geminiConfig = $config->get('gemini');

    // Initialize Product API Service (replaces context cache)
    $productAPIConfig = $config->get('productAPI');

    try {
        $productAPI = new ProductAPIService($productAPIConfig);
        $chatbot = new SirichaiElectricChatbot($geminiConfig, $productAPI);
    } catch (Exception $e) {
        error_log('[LINE Webhook] FATAL: Failed to initialize chatbot - ' . $e->getMessage());
        exit;
    }

    // Use custom conversation manager for LINE (stores by LINE User ID)
    $dbConfig = $config->get('database');
    $conversationConfig = $config->get('conversation');
    $maxMessages = isset($conversationConfig['maxMessages']) ? $conversationConfig['maxMessages'] : 20;
    $conversationManager = new ConversationManager($maxMessages, 'line', $dbConfig);

    // Check if the sender is authorized to generate quotations
    $senderId = null;
    foreach ($events['events'] as $evt) {
        if (isset($evt['source']['userId'])) {
            $senderId = $evt['source']['userId'];
            break;
        }
    }

    if ($senderId !== null) {
        $isAuthorized = $conversationManager->isUserAuthorized($senderId);
        $chatbot->setAuthorized($isAuthorized);
        error_log('[LINE Webhook] User ' . $senderId . ' authorized: ' . ($isAuthorized ? 'yes' : 'no'));
    }

    // Get bot user ID from webhook data for mention detection
    $botUserId = LineWebhookUtils::getBotUserId($events);

    foreach ($events['events'] as $event) {
        try {
            handleEvent($event, $chatbot, $conversationManager, $channelAccessToken, $botUserId);
        } catch (Exception $e) {
            error_log('[LINE Webhook] ERROR: ' . $e->getMessage());
            error_log('[LINE Webhook] Stack trace: ' . $e->getTraceAsString());

            // Try to notify user of error via Push API
            if (isset($event['source']['userId'])) {
                $userId = $event['source']['userId'];
                $errorMsg = "ขออภัยครับ ขณะนี้ระบบมีปัญหา กรุณาลองใหม่อีกครั้งหรือติดต่อทีมงานโดยตรงครับ\n\nSorry, the system is experiencing issues. Please try again or contact our team directly.";
                LineWebhookUtils::sendPushMessage($userId, $errorMsg, $channelAccessToken);
            }
        }
    }
}

exit;

// ===== Helper Functions =====

function handleEvent($event, $chatbot, $conversationManager, $accessToken, $botUserId = '') {
    // Check if we should respond to this event
    $responseInfo = LineWebhookUtils::shouldRespondToEvent($event, $botUserId);

    if ($responseInfo === false) {
        return; // Don't respond
    }

    // Extract response info
    $userId = $responseInfo['userId'];
    $conversationId = $responseInfo['conversationId'];
    $sourceType = $responseInfo['sourceType'];
    $messageType = $responseInfo['messageType'];
    $groupId = isset($responseInfo['groupId']) ? $responseInfo['groupId'] : null;

    // Determine reply target: for groups/rooms, reply to group; for direct chat, reply to user
    $replyTo = ($sourceType === 'group' || $sourceType === 'room') && !empty($groupId) ? $groupId : $userId;

    // Check for pause/resume commands (text messages only)
    if ($messageType === 'text') {
        $messageText = isset($event['message']['text']) ? trim($event['message']['text']) : '';
        $commandResult = handleChatbotCommand($messageText, $conversationId, $userId, $conversationManager, $accessToken);
        if ($commandResult !== false) {
            return; // Command was handled, don't process as regular message
        }
    }

    // Check if chatbot is active for this conversation
    if (!$conversationManager->isChatbotActive($conversationId)) {
        // Chatbot is paused - don't respond, let human agent handle it
        error_log("[LINE] Chatbot paused for $conversationId - skipping AI response");
        return;
    }

    // Get conversation history
    $history = $conversationManager->getConversationHistory($conversationId);

    // Show loading animation for 1:1 direct chats only
    // LINE API does not support loading animation in group/room chats
    if ($sourceType === 'user') {
        LineWebhookUtils::showLoadingAnimation($replyTo, 60, $accessToken);
    }

    $startTime = microtime(true);

    if ($messageType === 'image') {
        // Handle image message
        $messageId = isset($event['message']['id']) ? $event['message']['id'] : '';
        if (empty($messageId)) {
            return;
        }

        // Download image from LINE Content API
        $imageData = LineWebhookUtils::downloadLineContent($messageId, $accessToken);
        if ($imageData === false) {
            error_log('[LINE] ERROR: Failed to download image ' . $messageId);
            LineWebhookUtils::sendPushMessage($replyTo, "ขออภัยครับ ไม่สามารถรับรูปภาพได้ กรุณาลองส่งใหม่อีกครั้งครับ\n\nSorry, we couldn't receive the image. Please try sending it again.", $accessToken);
            return;
        }

        // Get chatbot response with image first (to capture search criteria)
        $response = $chatbot->chatWithImage($imageData, 'image/jpeg', '', $history);

        // Store placeholder in conversation history with search criteria if available
        $searchCriteria = isset($response['searchCriteria']) ? $response['searchCriteria'] : null;
        $conversationManager->addMessage($conversationId, 'user', '[ผู้ใช้ส่งรูปภาพ]', 0, $searchCriteria);
    } else {
        // Handle text message
        $messageText = isset($event['message']['text']) ? $event['message']['text'] : '';
        if (empty($messageText)) {
            return;
        }

        // Remove "zx" prefix if present (used for mentioning bot on LINE Desktop)
        $cleanedMessage = LineWebhookUtils::removeZxPrefix($messageText);

        // Get chatbot response first (to capture search criteria)
        $response = $chatbot->chat($cleanedMessage, $history);

        // Add user message to history with search criteria if available (store original message)
        $searchCriteria = isset($response['searchCriteria']) ? $response['searchCriteria'] : null;
        $conversationManager->addMessage($conversationId, 'user', $messageText, 0, $searchCriteria);
    }

    $duration = round(microtime(true) - $startTime, 2);

    // Log response status with duration
    if (!$response['success']) {
        error_log('[LINE] ERROR: Chatbot failed (' . $duration . 's) - ' . $response['error']);
    }

    if ($response['success']) {
        // Add assistant response to history with token tracking (no search criteria for assistant)
        $tokensUsed = isset($response['tokensUsed']) ? $response['tokensUsed'] : 0;
        $conversationManager->addMessage($conversationId, 'assistant', $response['response'], $tokensUsed, null);

        // Send the actual AI response
        // Use Push API for ALL messages because:
        // 1. Reply token may expire after long Gemini processing (10+ seconds)
        // 2. We're already using Push API for loading animation
        // 3. Push API is more reliable for async processing
        $replyMessage = $response['response'];
        $messages = LineWebhookUtils::splitMessage($replyMessage, 4900);

        // Send all messages via Push API (to group if group mention, to user if direct chat)
        for ($i = 0; $i < count($messages); $i++) {
            $result = LineWebhookUtils::sendPushMessage($replyTo, $messages[$i], $accessToken);
            if (!$result) {
                error_log('[LINE] ERROR: Push message ' . $i . ' failed');
            }
        }
    } else {
        // Check if it's a rate limit error
        if (isset($response['error']) &&
            (strpos($response['error'], 'RATE_LIMIT_EXCEEDED') !== false ||
             strpos($response['error'], '429') !== false)) {
            $errorMsg = "ขออภัยครับ ขณะนี้มีผู้ใช้งานเยอะมาก กรุณารอสักครู่แล้วลองใหม่อีกครั้งครับ\n\nSorry, we're experiencing high traffic. Please wait a moment and try again.";
        } else {
            $errorMsg = "ขออภัยครับ ขณะนี้ระบบมีปัญหา กรุณาลองใหม่อีกครั้งหรือติดต่อทีมงานโดยตรงครับ\n\nSorry, the system is experiencing issues. Please try again or contact our team directly.";
        }

        LineWebhookUtils::sendPushMessage($replyTo, $errorMsg, $accessToken);
    }
}

/**
 * Handle chatbot pause/resume commands (user-initiated only)
 *
 * Note: Agent commands are handled via Admin API (admin-api.php) since
 * LINE webhook only receives messages from users, not from agents.
 *
 * Commands:
 * - User pause: "ติดต่อพนักงาน", "/human", "คุยกับพนักงาน"
 * - User resume: "/bot", "/resume", "เปิดแชทบอท"
 *
 * @param string $messageText The message text
 * @param string $conversationId The conversation ID
 * @param string $userId The LINE user ID
 * @param ConversationManager $conversationManager
 * @param string $accessToken LINE access token
 * @return bool|string False if not a command, otherwise the command type handled
 */
function handleChatbotCommand($messageText, $conversationId, $userId, $conversationManager, $accessToken) {
    $lowerMessage = mb_strtolower(trim($messageText), 'UTF-8');

    // User pause commands (user wants to talk to human agent)
    $pauseCommands = array(
        'ติดต่อพนักงาน',
        'คุยกับพนักงาน',
        'ขอคุยกับพนักงาน',
        'ต้องการคุยกับพนักงาน',
        '/human',
        '/agent'
    );

    // Resume commands (re-enable chatbot)
    $resumeCommands = array(
        'เปิดแชทบอท',
        'เปิดบอท',
        '/bot',
        '/resume',
        '/on',
        '/chatbot'
    );

    // Check for pause command
    foreach ($pauseCommands as $cmd) {
        if ($lowerMessage === mb_strtolower($cmd, 'UTF-8')) {
            return handlePauseCommand($conversationId, $userId, $conversationManager, $accessToken);
        }
    }

    // Check for resume command
    foreach ($resumeCommands as $cmd) {
        if ($lowerMessage === mb_strtolower($cmd, 'UTF-8')) {
            return handleResumeCommand($conversationId, $userId, $conversationManager, $accessToken);
        }
    }

    // Check for reset command
    if ($lowerMessage === '/reset') {
        return handleResetCommand($conversationId, $userId, $conversationManager, $accessToken);
    }

    return false; // Not a command
}

/**
 * Handle pause command - user wants to talk to human agent
 */
function handlePauseCommand($conversationId, $userId, $conversationManager, $accessToken) {
    // Check if already paused
    if (!$conversationManager->isChatbotActive($conversationId)) {
        $message = "ขณะนี้ท่านกำลังรอพนักงานอยู่แล้วค่ะ กรุณารอสักครู่นะคะ\n\n"
                 . "You are already waiting for a human agent. Please wait a moment.";
        LineWebhookUtils::sendPushMessage($userId, $message, $accessToken);
        return 'already_paused';
    }

    // Pause the chatbot
    $conversationManager->pauseChatbot($conversationId);

    // Store the pause request in conversation history (no search criteria for system messages)
    $conversationManager->addMessage($conversationId, 'user', '[ผู้ใช้ขอติดต่อพนักงาน]', 0, null);

    $message = "ได้รับคำขอแล้วค่ะ พนักงานจะติดต่อกลับโดยเร็วที่สุด\n"
             . "ระหว่างนี้แชทบอทจะหยุดตอบชั่วคราวค่ะ\n\n"
             . "Your request has been received. An agent will contact you soon.\n"
             . "The chatbot will be paused in the meantime.\n\n"
             . "💡 พิมพ์ \"/bot\" เพื่อกลับมาใช้แชทบอท";

    LineWebhookUtils::sendPushMessage($userId, $message, $accessToken);

    error_log("[LINE] Chatbot paused by user request: $conversationId");

    return 'paused';
}

/**
 * Handle resume command - re-enable chatbot
 */
function handleResumeCommand($conversationId, $userId, $conversationManager, $accessToken) {
    // Check if already active
    if ($conversationManager->isChatbotActive($conversationId)) {
        $message = "แชทบอทพร้อมให้บริการอยู่แล้วค่ะ มีอะไรให้ช่วยไหมคะ?\n\n"
                 . "The chatbot is already active. How can I help you?";
        LineWebhookUtils::sendPushMessage($userId, $message, $accessToken);
        return 'already_active';
    }

    // Resume the chatbot
    $conversationManager->resumeChatbot($conversationId);

    // Store the resume in conversation history (no search criteria for system messages)
    $conversationManager->addMessage($conversationId, 'assistant', '[แชทบอทกลับมาให้บริการ]', 0, null);

    $message = "แชทบอทกลับมาให้บริการแล้วค่ะ 🤖\n"
             . "มีอะไรให้ช่วยไหมคะ?\n\n"
             . "The chatbot is now active again.\n"
             . "How can I help you?";

    LineWebhookUtils::sendPushMessage($userId, $message, $accessToken);

    error_log("[LINE] Chatbot resumed: $conversationId");

    return 'resumed';
}

/**
 * Handle reset command - deactivate all messages so conversation starts fresh
 */
function handleResetCommand($conversationId, $userId, $conversationManager, $accessToken) {
    $conversationManager->resetConversationHistory($conversationId);

    $message = "ล้างประวัติการสนทนาเรียบร้อยแล้วค่ะ\n"
             . "เริ่มต้นบทสนทนาใหม่ได้เลยค่ะ\n\n"
             . "Chat history has been cleared.\n"
             . "You can start a fresh conversation now.";

    LineWebhookUtils::sendPushMessage($userId, $message, $accessToken);

    error_log("[LINE] Conversation history reset by user: $conversationId");

    return 'reset';
}

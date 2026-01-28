<?php
/**
 * LINE Webhook Handler for Sirichai Electric Chatbot
 * Receives messages from LINE Official Account and responds using the chatbot
 * PHP 5.6 compatible
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Allow unlimited execution time for background processing
set_time_limit(0);
ini_set('max_execution_time', 0);

// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ProductAPIService.php';
require_once __DIR__ . '/GeminiFileManager.php';
require_once __DIR__ . '/SirichaiElectricChatbot.php';
require_once __DIR__ . '/ConversationManager.php';

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
    if (!verifySignature($body, $signature, $channelSecret)) {
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
    $maxMessages = isset($conversationConfig['maxMessages']) ? $conversationConfig['maxMessages'] : 50;
    $conversationManager = new ConversationManager($maxMessages, 'line', $dbConfig);

    foreach ($events['events'] as $event) {
        try {
            handleEvent($event, $chatbot, $conversationManager, $channelAccessToken);
        } catch (Exception $e) {
            error_log('[LINE Webhook] ERROR: ' . $e->getMessage());
            error_log('[LINE Webhook] Stack trace: ' . $e->getTraceAsString());

            // Try to notify user of error via Push API
            if (isset($event['source']['userId'])) {
                $userId = $event['source']['userId'];
                $errorMsg = "ขออภัยครับ ขณะนี้ระบบมีปัญหา กรุณาลองใหม่อีกครั้งหรือติดต่อทีมงานโดยตรงครับ\n\nSorry, the system is experiencing issues. Please try again or contact our team directly.";
                sendPushMessage($userId, $errorMsg, $channelAccessToken);
            }
        }
    }
}

exit;

// ===== Helper Functions =====

function handleEvent($event, $chatbot, $conversationManager, $accessToken) {
    $eventType = isset($event['type']) ? $event['type'] : '';

    // Only handle message events
    if ($eventType !== 'message') {
        return;
    }

    $messageType = isset($event['message']['type']) ? $event['message']['type'] : '';

    // Only handle text and image messages
    if ($messageType !== 'text' && $messageType !== 'image') {
        return;
    }

    $replyToken = isset($event['replyToken']) ? $event['replyToken'] : '';
    $userId = isset($event['source']['userId']) ? $event['source']['userId'] : '';

    if (empty($replyToken) || empty($userId)) {
        return;
    }

    // Use LINE User ID as conversation ID
    $conversationId = 'line_' . $userId;

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

    // Show loading animation (instead of sending a message)
    // This gives us up to 60 seconds to process the request
    showLoadingAnimation($userId, 60, $accessToken);

    $startTime = microtime(true);

    if ($messageType === 'image') {
        // Handle image message
        $messageId = isset($event['message']['id']) ? $event['message']['id'] : '';
        if (empty($messageId)) {
            return;
        }

        // Download image from LINE Content API
        $imageData = downloadLineContent($messageId, $accessToken);
        if ($imageData === false) {
            error_log('[LINE] ERROR: Failed to download image ' . $messageId);
            sendPushMessage($userId, "ขออภัยครับ ไม่สามารถรับรูปภาพได้ กรุณาลองส่งใหม่อีกครั้งครับ\n\nSorry, we couldn't receive the image. Please try sending it again.", $accessToken);
            return;
        }

        // Store placeholder in conversation history (images can't be stored in DB)
        $conversationManager->addMessage($conversationId, 'user', '[ผู้ใช้ส่งรูปภาพ]', 0);

        // Get chatbot response with image
        $response = $chatbot->chatWithImage($imageData, 'image/jpeg', '', $history);
    } else {
        // Handle text message
        $messageText = isset($event['message']['text']) ? $event['message']['text'] : '';
        if (empty($messageText)) {
            return;
        }

        // Add user message to history (0 tokens for user messages)
        $conversationManager->addMessage($conversationId, 'user', $messageText, 0);

        // Get chatbot response
        $response = $chatbot->chat($messageText, $history);
    }

    $duration = round(microtime(true) - $startTime, 2);

    // Log response status with duration
    if (!$response['success']) {
        error_log('[LINE] ERROR: Chatbot failed (' . $duration . 's) - ' . $response['error']);
    }

    if ($response['success']) {
        // Add assistant response to history with token tracking
        $tokensUsed = isset($response['tokensUsed']) ? $response['tokensUsed'] : 0;
        $conversationManager->addMessage($conversationId, 'assistant', $response['response'], $tokensUsed);

        // Send the actual AI response
        // Use Push API for ALL messages because:
        // 1. Reply token may expire after long Gemini processing (10+ seconds)
        // 2. We're already using Push API for loading animation
        // 3. Push API is more reliable for async processing
        $replyMessage = $response['response'];
        $messages = splitMessage($replyMessage, 4900);

        // Send all messages via Push API
        for ($i = 0; $i < count($messages); $i++) {
            $result = sendPushMessage($userId, $messages[$i], $accessToken);
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

        sendPushMessage($userId, $errorMsg, $accessToken);
    }
}

function splitMessage($text, $maxLength = 4900) {
    $messages = array();

    if (strlen($text) <= $maxLength) {
        $messages[] = $text;
        return $messages;
    }

    // Split by paragraphs
    $paragraphs = explode("\n\n", $text);
    $currentMessage = '';

    foreach ($paragraphs as $paragraph) {
        if (strlen($currentMessage . $paragraph) > $maxLength) {
            if (!empty($currentMessage)) {
                $messages[] = trim($currentMessage);
                $currentMessage = '';
            }

            // If single paragraph is too long, split by sentences
            if (strlen($paragraph) > $maxLength) {
                $sentences = preg_split('/([.!?]\s+)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($sentences as $sentence) {
                    if (strlen($currentMessage . $sentence) > $maxLength && !empty($currentMessage)) {
                        $messages[] = trim($currentMessage);
                        $currentMessage = $sentence;
                    } else {
                        $currentMessage .= $sentence;
                    }
                }
            } else {
                $currentMessage = $paragraph . "\n\n";
            }
        } else {
            $currentMessage .= $paragraph . "\n\n";
        }
    }

    if (!empty($currentMessage)) {
        $messages[] = trim($currentMessage);
    }

    return $messages;
}

function verifySignature($body, $signature, $secret) {
    if (empty($signature) || empty($secret)) {
        return false;
    }

    $hash = base64_encode(hash_hmac('sha256', $body, $secret, true));
    return hash_equals($signature, $hash);
}

function sendLineRequest($url, $data, $accessToken, $logPrefix) {
    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Accept 2xx status codes (200, 202, etc.)
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[LINE] ' . $logPrefix . ' failed: HTTP ' . $httpCode . ', Response: ' . $result);
        return false;
    }

    return true;
}

function sendPushMessage($userId, $message, $accessToken) {
    $url = 'https://api.line.me/v2/bot/message/push';

    $data = array(
        'to' => $userId,
        'messages' => array(
            array(
                'type' => 'text',
                'text' => $message
            )
        )
    );

    return sendLineRequest($url, $data, $accessToken, 'Push');
}

function downloadLineContent($messageId, $accessToken) {
    $url = 'https://api-data.line.me/v2/bot/message/' . $messageId . '/content';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $accessToken
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        error_log('[LINE] Content download cURL error: ' . $error);
        return false;
    }

    if ($httpCode !== 200) {
        error_log('[LINE] Content download failed: HTTP ' . $httpCode);
        return false;
    }

    error_log('[LINE] Downloaded content: ' . strlen($result) . ' bytes');
    return $result;
}

function showLoadingAnimation($userId, $seconds, $accessToken) {
    $url = 'https://api.line.me/v2/bot/chat/loading/start';

    // Ensure seconds is between 5 and 60
    $seconds = max(5, min(60, $seconds));

    $data = array(
        'chatId' => $userId,
        'loadingSeconds' => $seconds
    );

    return sendLineRequest($url, $data, $accessToken, 'Loading Animation');
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
        sendPushMessage($userId, $message, $accessToken);
        return 'already_paused';
    }

    // Pause the chatbot
    $conversationManager->pauseChatbot($conversationId);

    // Store the pause request in conversation history
    $conversationManager->addMessage($conversationId, 'user', '[ผู้ใช้ขอติดต่อพนักงาน]', 0);

    $message = "ได้รับคำขอแล้วค่ะ พนักงานจะติดต่อกลับโดยเร็วที่สุด\n"
             . "ระหว่างนี้แชทบอทจะหยุดตอบชั่วคราวค่ะ\n\n"
             . "Your request has been received. An agent will contact you soon.\n"
             . "The chatbot will be paused in the meantime.\n\n"
             . "💡 พิมพ์ \"/bot\" เพื่อกลับมาใช้แชทบอท";

    sendPushMessage($userId, $message, $accessToken);

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
        sendPushMessage($userId, $message, $accessToken);
        return 'already_active';
    }

    // Resume the chatbot
    $conversationManager->resumeChatbot($conversationId);

    // Store the resume in conversation history
    $conversationManager->addMessage($conversationId, 'assistant', '[แชทบอทกลับมาให้บริการ]', 0);

    $message = "แชทบอทกลับมาให้บริการแล้วค่ะ 🤖\n"
             . "มีอะไรให้ช่วยไหมคะ?\n\n"
             . "The chatbot is now active again.\n"
             . "How can I help you?";

    sendPushMessage($userId, $message, $accessToken);

    error_log("[LINE] Chatbot resumed: $conversationId");

    return 'resumed';
}

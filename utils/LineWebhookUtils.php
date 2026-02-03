<?php
/**
 * LINE Webhook Utility Functions
 * Helper class for LINE webhook event processing
 * PHP 5.6 compatible
 */

class LineWebhookUtils {

    /**
     * Check if bot was mentioned in the message
     *
     * @param array $message The message object from LINE webhook
     * @param string $botUserId The bot's user ID (optional, uses 'isSelf' if not provided)
     * @return bool True if bot was mentioned, false otherwise
     */
    public static function isBotMentioned($message, $botUserId = '') {
        // No mention object = not mentioned
        if (!isset($message['mention']['mentionees'])) {
            return false;
        }

        foreach ($message['mention']['mentionees'] as $mentionee) {
            // Method 1: Check isSelf flag (easiest and most reliable)
            if (isset($mentionee['isSelf']) && $mentionee['isSelf'] === true) {
                return true;
            }

            // Method 2: Check userId (backup method)
            if (!empty($botUserId) && isset($mentionee['userId']) && $mentionee['userId'] === $botUserId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if bot should respond to the event
     *
     * Rules:
     * - Direct chat (1-on-1): Always respond, track with line_{userId}
     * - Group/Room chat: Only respond if bot is mentioned, track with line_group_{groupId}_{userId}
     * - Other cases: Don't respond
     *
     * @param array $event The LINE webhook event
     * @param string $botUserId Optional bot user ID for mention verification
     * @return array|false Returns array with response info or false if shouldn't respond
     *
     * Return array structure:
     * array(
     *   'userId' => string,           // LINE user ID who sent the message
     *   'conversationId' => string,   // Conversation ID for tracking (line_{userId} or line_group_{groupId}_{userId})
     *   'sourceType' => string,       // 'user', 'group', or 'room'
     *   'messageType' => string,      // 'text' or 'image'
     *   'groupId' => string|null      // Group/Room ID (only for group/room sources)
     * )
     */
    public static function shouldRespondToEvent($event, $botUserId = '') {
        $eventType = isset($event['type']) ? $event['type'] : '';

        // Only handle message events
        if ($eventType !== 'message') {
            return false;
        }

        $messageType = isset($event['message']['type']) ? $event['message']['type'] : '';

        // Only handle text and image messages
        if ($messageType !== 'text' && $messageType !== 'image') {
            return false;
        }

        $replyToken = isset($event['replyToken']) ? $event['replyToken'] : '';
        $sourceType = isset($event['source']['type']) ? $event['source']['type'] : '';

        if (empty($replyToken) || empty($sourceType)) {
            return false;
        }

        // Case 1: Direct chat (1-on-1) - respond and track with line_{userId}
        if ($sourceType === 'user') {
            $userId = isset($event['source']['userId']) ? $event['source']['userId'] : '';
            if (empty($userId)) {
                return false;
            }

            error_log('[LINE] Direct chat from user: ' . $userId);
            return array(
                'userId' => $userId,
                'conversationId' => 'line_' . $userId,
                'sourceType' => 'user',
                'messageType' => $messageType,
                'groupId' => null
            );
        }

        // Case 2: Group or Room chat - only respond if bot is mentioned
        elseif ($sourceType === 'group' || $sourceType === 'room') {
            // Only process text messages in groups (mentions only work with text)
            if ($messageType !== 'text') {
                error_log('[LINE] Ignoring non-text message in group/room');
                return false;
            }

            $message = isset($event['message']) ? $event['message'] : array();

            if (self::isBotMentioned($message, $botUserId)) {
                $userId = isset($event['source']['userId']) ? $event['source']['userId'] : '';
                $groupId = isset($event['source']['groupId']) ? $event['source']['groupId'] : '';
                $roomId = isset($event['source']['roomId']) ? $event['source']['roomId'] : '';
                $sourceId = !empty($groupId) ? $groupId : $roomId;

                if (empty($userId) || empty($sourceId)) {
                    error_log('[LINE] Missing userId or groupId/roomId in group mention');
                    return false;
                }

                // Track per-user-per-group: line_group_{groupId}_{userId}
                $conversationId = 'line_group_' . $sourceId . '_' . $userId;
                error_log('[LINE] Bot mentioned in ' . $sourceType . ': ' . $sourceId . ' by user: ' . $userId);

                return array(
                    'userId' => $userId,
                    'conversationId' => $conversationId,
                    'sourceType' => $sourceType,
                    'messageType' => $messageType,
                    'groupId' => $sourceId
                );
            } else {
                // Not mentioned - stay silent
                error_log('[LINE] Bot not mentioned in ' . $sourceType . ' - ignoring');
                return false;
            }
        }

        // Unknown source type - ignore
        else {
            error_log('[LINE] Unknown source type: ' . $sourceType);
            return false;
        }
    }

    /**
     * Get bot user ID from webhook events data
     *
     * @param array $eventsData The full webhook data (contains 'destination' field)
     * @return string Bot user ID or empty string if not found
     */
    public static function getBotUserId($eventsData) {
        return isset($eventsData['destination']) ? $eventsData['destination'] : '';
    }

    /**
     * Split long message into multiple parts to comply with LINE's character limit
     *
     * @param string $text The message text to split
     * @param int $maxLength Maximum length per message (default 4900, LINE limit is 5000)
     * @return array Array of message parts
     */
    public static function splitMessage($text, $maxLength = 4900) {
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

    /**
     * Verify LINE webhook signature
     *
     * @param string $body Request body
     * @param string $signature X-Line-Signature header value
     * @param string $secret Channel secret
     * @return bool True if signature is valid
     */
    public static function verifySignature($body, $signature, $secret) {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        $hash = base64_encode(hash_hmac('sha256', $body, $secret, true));
        return hash_equals($signature, $hash);
    }

    /**
     * Send request to LINE Messaging API
     *
     * @param string $url API endpoint URL
     * @param array $data Request data
     * @param string $accessToken Channel access token
     * @param string $logPrefix Prefix for error logging
     * @return bool True if successful (2xx response)
     */
    public static function sendLineRequest($url, $data, $accessToken, $logPrefix) {
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

    /**
     * Send push message to LINE user
     *
     * @param string $userId LINE user ID
     * @param string $message Message text
     * @param string $accessToken Channel access token
     * @return bool True if successful
     */
    public static function sendPushMessage($userId, $message, $accessToken) {
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

        return self::sendLineRequest($url, $data, $accessToken, 'Push');
    }

    /**
     * Download content (image, video, audio) from LINE
     *
     * @param string $messageId LINE message ID
     * @param string $accessToken Channel access token
     * @return string|false Binary content data or false on failure
     */
    public static function downloadLineContent($messageId, $accessToken) {
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

    /**
     * Show loading animation (typing indicator) in LINE chat
     *
     * @param string $userId LINE user ID
     * @param int $seconds Duration in seconds (5-60)
     * @param string $accessToken Channel access token
     * @return bool True if successful
     */
    public static function showLoadingAnimation($userId, $seconds, $accessToken) {
        $url = 'https://api.line.me/v2/bot/chat/loading/start';

        // Ensure seconds is between 5 and 60
        $seconds = max(5, min(60, $seconds));

        $data = array(
            'chatId' => $userId,
            'loadingSeconds' => $seconds
        );

        return self::sendLineRequest($url, $data, $accessToken, 'Loading Animation');
    }
}

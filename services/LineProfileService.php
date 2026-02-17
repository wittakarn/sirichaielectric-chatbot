<?php
/**
 * LINE Profile Service
 *
 * Handles fetching LINE user profile information
 * Uses LINE Messaging API Get Profile endpoint
 *
 * @see https://developers.line.biz/en/reference/messaging-api/#get-profile
 */

class LineProfileService {
    /**
     * @var string LINE Channel Access Token
     */
    private $accessToken;

    /**
     * Constructor
     *
     * @param string $accessToken LINE Channel Access Token
     */
    public function __construct($accessToken) {
        $this->accessToken = $accessToken;
    }

    /**
     * Get user profile from LINE API
     *
     * @param string $userId LINE user ID
     * @return array|null Profile data or null on failure
     *                    [
     *                      'userId' => 'U1234567890abcdef',
     *                      'displayName' => 'John Doe',
     *                      'pictureUrl' => 'https://...',
     *                      'statusMessage' => 'Hello!'
     *                    ]
     */
    public function getProfile($userId) {
        $url = "https://api.line.me/v2/bot/profile/{$userId}";

        $headers = array(
            'Authorization: Bearer ' . $this->accessToken
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $profile = json_decode($response, true);
            if ($profile) {
                return $profile;
            }
        }

        error_log("[LINE Profile] Failed to fetch profile for user: {$userId}, HTTP Code: {$httpCode}");
        return null;
    }

    /**
     * Get only display name from LINE API
     *
     * @param string $userId LINE user ID
     * @return string|null Display name or null on failure
     */
    public function getDisplayName($userId) {
        $profile = $this->getProfile($userId);
        return $profile ? $profile['displayName'] : null;
    }

    /**
     * Extract user ID from conversation ID
     * Handles LINE conversation IDs in format: line_{userId}
     *
     * @param string $conversationId Conversation ID
     * @return string|null User ID or null if not a LINE conversation
     */
    public static function extractUserIdFromConversationId($conversationId) {
        if (strpos($conversationId, 'line_') === 0) {
            return substr($conversationId, 5); // Remove 'line_' prefix
        }
        return null;
    }
}

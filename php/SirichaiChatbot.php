<?php
/**
 * Sirichai Electric Chatbot - Main chatbot class
 * Uses Google Gemini API for AI responses
 * PHP 5.6 compatible
 */

class SirichaiChatbot {
    private $config;
    private $productFetcher;
    private $systemPrompt;

    public function __construct($config, $productFetcher = null) {
        $this->config = $config;
        $this->productFetcher = $productFetcher;
        $this->systemPrompt = $this->buildSystemPrompt();
    }

    private function buildSystemPrompt() {
        return "You are a helpful and knowledgeable customer service assistant for Sirichai Electric (ศิริชัยอิเล็คทริค), a leading electrical equipment supplier in Thailand.

COMPANY INFORMATION:
- Website: https://shop.sirichaielectric.com/
- Business: Electrical equipment and industrial products supplier
- Specialties: Electrical wiring, circuit protection, LED lighting, solar/EV equipment, industrial supplies

PRODUCT CATEGORIES:
- Electrical Wires and Cables (Yazaki, Helukabel)
- Circuit Breakers and Contactors (Mitsubishi, Schneider, ABB)
- LED Lights and Fixtures (Philips, Panasonic)
- Cable Management Systems
- Solar and EV Charging Equipment
- Control Equipment and Switches

YOUR ROLE:
1. Answer questions about electrical products, specifications, and applications
2. Help customers find the right products for their needs
3. Provide technical guidance on electrical equipment
4. Support both Thai and English languages fluently
5. Be professional, friendly, and informative

LANGUAGE HANDLING:
- Detect the customer's language (Thai or English) from their message
- Respond in the same language they use
- If they use Thai, respond in Thai (ตอบเป็นภาษาไทย)
- If they use English, respond in English
- Use proper technical terms in both languages

GUIDELINES:
- Provide accurate information about electrical products and applications
- For specific product availability, specifications, or pricing, suggest visiting the website or contacting sales
- Recommend appropriate products based on customer needs and applications
- Explain technical specifications in a clear, understandable way
- Be honest if you don't have specific information - offer to connect them with a specialist
- Focus on helping customers make informed decisions

TONE:
- Professional yet approachable
- Patient and helpful
- Clear and concise
- Technical when needed, but always understandable

Remember: You represent a trusted electrical supplier. Build confidence and provide value in every interaction.";
    }

    private function detectLanguage($message) {
        // Simple Thai character detection using Unicode range
        return preg_match('/[\x{0E00}-\x{0E7F}]/u', $message) ? 'th' : 'en';
    }

    private function buildContextMessage($history) {
        if (empty($history)) {
            return '';
        }

        $context = "\n\nPREVIOUS CONVERSATION:\n";
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? 'Customer' : 'Assistant';
            $context .= $role . ': ' . $msg['content'] . "\n";
        }

        return $context;
    }

    private function buildFullPrompt($message, $history) {
        $prompt = $this->systemPrompt;

        // Add product context if available
        if ($this->productFetcher !== null) {
            $productContext = $this->productFetcher->getProductContext();
            if ($productContext !== null) {
                $prompt .= "\n\n" . $productContext;
            }
        }

        // Add conversation history
        $contextMessage = $this->buildContextMessage($history);
        if (!empty($contextMessage)) {
            $prompt .= $contextMessage;
        }

        // Add current message
        $prompt .= "\n\nCUSTOMER MESSAGE:\n" . $message;

        return $prompt;
    }

    public function chat($message, $conversationHistory = array()) {
        try {
            // Build the full prompt
            $fullPrompt = $this->buildFullPrompt($message, $conversationHistory);

            // Call Gemini API
            $response = $this->callGeminiAPI($fullPrompt);

            if (!$response['success']) {
                return array(
                    'success' => false,
                    'response' => '',
                    'error' => $response['error'],
                    'language' => $this->detectLanguage($message),
                );
            }

            return array(
                'success' => true,
                'response' => $response['text'],
                'language' => $this->detectLanguage($message),
            );

        } catch (Exception $e) {
            error_log('[Chatbot] Error: ' . $e->getMessage());
            return array(
                'success' => false,
                'response' => '',
                'error' => $e->getMessage(),
            );
        }
    }

    private function callGeminiAPI($prompt) {
        $apiKey = $this->config['apiKey'];
        $model = $this->config['model'];
        $temperature = $this->config['temperature'];
        $maxTokens = $this->config['maxTokens'];

        // Gemini API endpoint
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        // Build request body
        $requestBody = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens,
                'topP' => 0.95,
                'topK' => 40,
            ),
        );

        $jsonBody = json_encode($requestBody);

        // Make cURL request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonBody)
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return array(
                'success' => false,
                'error' => 'cURL error: ' . $error,
            );
        }

        if ($httpCode !== 200) {
            return array(
                'success' => false,
                'error' => 'HTTP error: ' . $httpCode . ' - ' . $response,
            );
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'JSON decode error: ' . json_last_error_msg(),
            );
        }

        // Extract text from Gemini response
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            return array(
                'success' => true,
                'text' => $data['candidates'][0]['content']['parts'][0]['text'],
            );
        }

        // Check for error in response
        if (isset($data['error'])) {
            $errorMsg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown API error';
            return array(
                'success' => false,
                'error' => $errorMsg,
            );
        }

        return array(
            'success' => false,
            'error' => 'Unexpected API response format',
        );
    }
}

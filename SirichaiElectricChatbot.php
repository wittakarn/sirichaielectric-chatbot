<?php
/**
 * Sirichai Electric Chatbot - Optimized with catalog summary in system prompt
 * Fetches catalog summary once at initialization and includes it in system prompt
 * Then uses Gemini function calling to search products on-demand
 * This reduces API calls from 3 to 1 per user query (3x faster)
 * PHP 5.6 compatible
 */

class SirichaiElectricChatbot {
    private $config;
    private $productAPI;
    private $catalogSummary;
    private $fileManager;
    private $systemPromptFileUri;
    private $catalogFileUri;
    private $systemPromptText;

    public function __construct($config, $productAPI = null) {
        $this->config = $config;
        $this->productAPI = $productAPI;

        // Initialize file manager
        $this->fileManager = new GeminiFileManager($config['apiKey']);

        // Fetch catalog summary once at initialization
        $this->catalogSummary = $this->fetchCatalogSummary();

        // Upload files to Gemini File API
        $this->uploadContextFiles();
    }

    private function fetchCatalogSummary() {
        if ($this->productAPI === null) {
            return '';
        }

        try {
            $catalogText = $this->productAPI->getCatalogSummary();
            if ($catalogText === null) {
                error_log('[Chatbot] Catalog returned null');
                return '';
            }
            error_log('[Chatbot] Catalog loaded: ' . strlen($catalogText) . ' chars');
            return $catalogText;
        } catch (Exception $e) {
            error_log('[Chatbot] ERROR: Failed to fetch catalog - ' . $e->getMessage());
            return '';
        }
    }


    private function uploadContextFiles() {
        error_log('[Chatbot] === Initializing File API Context ===');

        /**
         * HYBRID FILE API APPROACH (Fixed Empty Response Bug - Feb 15, 2026)
         *
         * Why this approach:
         * - Uploading BOTH system prompt and catalog as File API files caused empty responses
         * - Sending 106KB directly in systemInstruction caused 60s+ timeouts
         *
         * Solution:
         * 1. System Prompt (~5KB) → Direct text in systemInstruction (fast, no caching needed)
         * 2. Product Catalog (~101KB) → File API upload (enables caching, reduces tokens)
         *
         * Benefits:
         * - Fast response times (~5 seconds)
         * - Proper caching for large catalog
         * - Avoids empty response bug from dual File API upload
         */

        // Load system prompt text for direct use in systemInstruction
        $promptFile = __DIR__ . '/system-prompt.txt';
        if (file_exists($promptFile)) {
            $this->systemPromptText = file_get_contents($promptFile);
        } else {
            $this->systemPromptText = "You are a helpful customer service assistant for Sirichai Electric.";
        }
        error_log('[Chatbot] ✓ System Prompt loaded (' . strlen($this->systemPromptText) . ' bytes)');

        // Upload ONLY catalog file to File API (system prompt goes in systemInstruction)
        $this->systemPromptFileUri = null;

        if (!empty($this->catalogSummary)) {
            $catalogResult = $this->fileManager->getOrUploadFile(
                'catalog-summary',
                $this->catalogSummary,
                'Product Catalog'
            );

            if ($catalogResult['success']) {
                $this->catalogFileUri = $catalogResult['fileUri'];
                $cacheStatus = isset($catalogResult['cached']) && $catalogResult['cached'] ? 'CACHED' : 'UPLOADED';
                error_log('[Chatbot] ✓ Product Catalog: ' . $cacheStatus . ' - ' . $catalogResult['name']);
            } else {
                error_log('[Chatbot] ✗ Product Catalog: FAILED - ' . $catalogResult['error']);
                $this->catalogFileUri = null;
            }
        } else {
            $this->catalogFileUri = null;
            error_log('[Chatbot] ⊘ Product Catalog: SKIPPED (no data)');
        }

        error_log('[Chatbot] === File API Context Ready (hybrid mode) ===');
    }

    /**
     * Force refresh of uploaded files (useful if catalog or system prompt changed)
     */
    public function refreshFiles() {
        error_log('[Chatbot] Force refreshing uploaded files...');

        // Clear cache to force re-upload
        $this->fileManager->clearCache();

        // Re-fetch catalog
        $this->catalogSummary = $this->fetchCatalogSummary();

        // Re-upload files
        $this->uploadContextFiles();

        error_log('[Chatbot] Files refreshed successfully');
    }

    private function detectLanguage($message) {
        // Simple Thai character detection using Unicode range
        return preg_match('/[\x{0E00}-\x{0E7F}]/u', $message) ? 'th' : 'en';
    }

    private function buildConversationHistory($history) {
        $contents = array();

        foreach ($history as $msg) {
            $contents[] = array(
                'role' => $msg['role'] === 'user' ? 'user' : 'model',
                'parts' => array(
                    array('text' => $msg['content'])
                )
            );
        }

        return $contents;
    }

    /**
     * Chat with an image (and optional text message)
     * Downloads are handled by the caller - this receives raw image bytes
     *
     * @param string $imageData Raw binary image data
     * @param string $mimeType Image MIME type (e.g., 'image/jpeg')
     * @param string $textMessage Optional text message from user
     * @param array $conversationHistory Previous conversation messages
     * @return array Same format as chat() response
     */
    public function chatWithImage($imageData, $mimeType, $textMessage = '', $conversationHistory = array()) {
        try {
            $totalTokens = 0;
            $searchCriteria = null;

            // Build conversation contents from history
            $contents = $this->buildConversationHistory($conversationHistory);

            // Build user message parts with image
            $userParts = array();

            // Add inline image data (base64 encoded)
            $userParts[] = array(
                'inline_data' => array(
                    'mime_type' => $mimeType,
                    'data' => base64_encode($imageData)
                )
            );

            // Add text part - use default prompt if no text provided
            if (!empty($textMessage)) {
                $userParts[] = array('text' => $textMessage);
            } else {
                $userParts[] = array('text' => 'The customer sent this image. Analyze it. If it shows electrical products, identify the type and search the catalog. If not product-related, describe what you see and ask how you can help.');
            }

            $contents[] = array(
                'role' => 'user',
                'parts' => $userParts
            );

            // Make API call with function declarations (same as text chat)
            $response = $this->callGeminiWithFunctions($contents);

            if (isset($response['tokensUsed'])) {
                $totalTokens += $response['tokensUsed'];
            }

            if (!$response['success']) {
                return array(
                    'success' => false,
                    'response' => '',
                    'error' => $response['error'],
                    'language' => 'th',
                    'tokensUsed' => $totalTokens,
                    'searchCriteria' => $searchCriteria,
                );
            }

            // Handle function calls if present
            if (isset($response['functionCalls']) && !empty($response['functionCalls'])) {
                foreach ($response['functionCalls'] as $call) {
                    $functionName = isset($call['name']) ? $call['name'] : 'unknown';
                    $args = isset($call['args']) ? $call['args'] : array();
                    $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    error_log('[Chatbot] Image: Calling: ' . $functionName . '(' . $argsJson . ')');

                    // Capture search criteria for logging to database
                    $criteria = $this->extractSearchCriteria($functionName, $args);
                    if ($criteria !== null) {
                        $searchCriteria = $criteria;
                    }
                }

                $response = $this->handleFunctionCalls($response, $contents);

                if (isset($response['tokensUsed'])) {
                    $totalTokens += $response['tokensUsed'];
                }
            }

            if (!$response['success']) {
                return array(
                    'success' => false,
                    'response' => '',
                    'error' => $response['error'],
                    'language' => 'th',
                    'tokensUsed' => $totalTokens,
                    'searchCriteria' => $searchCriteria,
                );
            }

            if (!isset($response['text'])) {
                return array(
                    'success' => false,
                    'response' => '',
                    'error' => 'Response missing text field',
                    'language' => 'th',
                    'tokensUsed' => $totalTokens,
                    'searchCriteria' => $searchCriteria,
                );
            }

            return array(
                'success' => true,
                'response' => $response['text'],
                'language' => 'th',
                'tokensUsed' => $totalTokens,
                'searchCriteria' => $searchCriteria,
            );

        } catch (Exception $e) {
            error_log('[Chatbot] Image ERROR: ' . $e->getMessage());
            return array(
                'success' => false,
                'response' => '',
                'error' => $e->getMessage(),
                'tokensUsed' => 0,
                'searchCriteria' => null,
            );
        }
    }

    public function chat($message, $conversationHistory = array()) {
        try {
            // Track total tokens used across all API calls
            $totalTokens = 0;
            $searchCriteria = null;

            // Build conversation contents
            $contents = $this->buildConversationHistory($conversationHistory);

            // Add current message - just the text, no files!
            // Files are now passed via systemInstruction for better caching
            $contents[] = array(
                'role' => 'user',
                'parts' => array(
                    array('text' => $message)
                )
            );

            // Make initial API call with function declarations
            $response = $this->callGeminiWithFunctions($contents);

            // Track tokens from initial call
            if (isset($response['tokensUsed'])) {
                $totalTokens += $response['tokensUsed'];
            }

            if (!$response['success']) {
                return array(
                    'success' => false,
                    'response' => '',
                    'error' => $response['error'],
                    'language' => $this->detectLanguage($message),
                    'tokensUsed' => $totalTokens,
                    'searchCriteria' => $searchCriteria,
                );
            }

            // Handle function calls if present
            if (isset($response['functionCalls']) && !empty($response['functionCalls'])) {
                // Log each function call with readable Thai text
                // Also capture search_products criteria for logging
                foreach ($response['functionCalls'] as $call) {
                    $functionName = isset($call['name']) ? $call['name'] : 'unknown';
                    $args = isset($call['args']) ? $call['args'] : array();
                    $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    error_log('[Chatbot] Calling: ' . $functionName . '(' . $argsJson . ')');

                    // Capture search criteria for logging to database
                    $criteria = $this->extractSearchCriteria($functionName, $args);
                    if ($criteria !== null) {
                        $searchCriteria = $criteria;
                    }
                }

                $response = $this->handleFunctionCalls($response, $contents);

                // Track tokens from function call follow-up
                if (isset($response['tokensUsed'])) {
                    $totalTokens += $response['tokensUsed'];
                }

                // Log what came back after handling functions
                if (isset($response['functionCalls'])) {
                    error_log('[Chatbot] ERROR: Got another function call after execution - possible infinite loop!');
                }
            }

            if (!$response['success']) {
                return array(
                    'success' => false,
                    'response' => '',
                    'error' => $response['error'],
                    'language' => $this->detectLanguage($message),
                    'tokensUsed' => $totalTokens,
                    'searchCriteria' => $searchCriteria,
                );
            }

            // Check if text field exists in response
            if (!isset($response['text'])) {
                error_log('[Chatbot] ERROR: Response missing text field - ' . json_encode(array_keys($response), JSON_UNESCAPED_UNICODE));
                return array(
                    'success' => false,
                    'response' => '',
                    'error' => 'Response missing text field',
                    'language' => $this->detectLanguage($message),
                    'tokensUsed' => $totalTokens,
                    'searchCriteria' => $searchCriteria,
                );
            }

            return array(
                'success' => true,
                'response' => $response['text'],
                'language' => $this->detectLanguage($message),
                'tokensUsed' => $totalTokens,
                'searchCriteria' => $searchCriteria,
            );

        } catch (Exception $e) {
            error_log('[Chatbot] ERROR: ' . $e->getMessage());
            return array(
                'success' => false,
                'response' => '',
                'error' => $e->getMessage(),
                'tokensUsed' => 0,
                'searchCriteria' => null,
            );
        }
    }

    private function handleFunctionCalls($response, $originalContents) {
        error_log('[Chatbot] Handling function calls - count: ' . count($response['functionCalls']));

        // Add the function call request to conversation
        $contents = $originalContents;

        // Clean up function call parts - remove 'thoughtSignature' and fix 'args'
        $cleanedParts = array();
        foreach ($response['functionCallParts'] as $part) {
            $cleanedPart = array('functionCall' => array());

            if (isset($part['functionCall']['name'])) {
                $cleanedPart['functionCall']['name'] = $part['functionCall']['name'];
            }

            // Convert args array to object if needed
            if (isset($part['functionCall']['args'])) {
                $args = $part['functionCall']['args'];
                // If args is an empty array, convert to empty object
                if (is_array($args) && empty($args)) {
                    $cleanedPart['functionCall']['args'] = (object)array();
                } else {
                    $cleanedPart['functionCall']['args'] = $args;
                }
            }

            $cleanedParts[] = $cleanedPart;
        }

        $contents[] = array(
            'role' => 'model',
            'parts' => $cleanedParts
        );

        // Build function response parts
        $functionResponseParts = array();

        // Execute each function call
        foreach ($response['functionCalls'] as $call) {
            $functionName = $call['name'];
            $args = isset($call['args']) ? $call['args'] : array();

            // Execute the function
            $result = $this->executeFunction($functionName, $args);

            // Add to function response parts
            $functionResponseParts[] = array(
                'functionResponse' => array(
                    'name' => $functionName,
                    'response' => array(
                        'content' => $result
                    )
                )
            );
        }

        // Add all function responses as a single user message
        $contents[] = array(
            'role' => 'user',
            'parts' => $functionResponseParts
        );

        // Call Gemini again with function results
        // Keep function declarations available in case Gemini wants to call more functions
        error_log('[Chatbot] Calling Gemini again with function results...');
        $finalResponse = $this->callGeminiWithFunctions($contents, true);

        // Log what type of response we got
        if (isset($finalResponse['functionCalls'])) {
            error_log('[Chatbot] WARNING: Gemini called another function after getting results!');
        } elseif (isset($finalResponse['text'])) {
            error_log('[Chatbot] Got final text response (' . strlen($finalResponse['text']) . ' chars)');
        } else {
            error_log('[Chatbot] ERROR: Unexpected response type - keys: ' . json_encode(array_keys($finalResponse), JSON_UNESCAPED_UNICODE));
        }

        return $finalResponse;
    }

    /**
     * Extract search criteria from function call for logging purposes
     * @param string $functionName Name of the function being called
     * @param array $args Function arguments
     * @return string|null JSON-encoded search criteria, or null if not a search function
     */
    private function extractSearchCriteria($functionName, $args) {
        if ($functionName === 'search_products' && isset($args['criterias'])) {
            return json_encode($args['criterias'], JSON_UNESCAPED_UNICODE);
        }

        if ($functionName === 'search_product_detail' && isset($args['productName'])) {
            return json_encode(array('productName' => $args['productName']), JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    private function executeFunction($functionName, $args) {
        if ($this->productAPI === null) {
            return "Product API service not available.";
        }

        if ($functionName === 'search_products') {
            $criterias = isset($args['criterias']) ? $args['criterias'] : array();
            if (empty($criterias)) {
                return "No search criteria provided.";
            }
            $result = $this->productAPI->searchProducts($criterias);

            if ($result !== null) {
                return $result;
            }

            return "No products found.";
        }

        if ($functionName === 'search_product_detail') {
            $productName = isset($args['productName']) ? $args['productName'] : '';
            if (empty($productName)) {
                return "No product name provided.";
            }
            $result = $this->productAPI->getProductDetail($productName);

            if ($result !== null) {
                return $result;
            }

            return "Product details not found.";
        }

        return "Unknown function: " . $functionName;
    }

    private function logTokenUsage($data, $context = '') {
        if (!isset($data['usageMetadata'])) {
            return 0;
        }

        $usage = $data['usageMetadata'];
        $promptTokens = isset($usage['promptTokenCount']) ? $usage['promptTokenCount'] : 0;
        $completionTokens = isset($usage['candidatesTokenCount']) ? $usage['candidatesTokenCount'] : 0;
        $totalTokens = isset($usage['totalTokenCount']) ? $usage['totalTokenCount'] : 0;
        $cachedTokens = isset($usage['cachedContentTokenCount']) ? $usage['cachedContentTokenCount'] : 0;

        $contextPrefix = $context ? "[{$context}] " : '';
        error_log(sprintf(
            '[Chatbot] %sToken Usage - Prompt: %d, Completion: %d, Total: %d, Cached: %d',
            $contextPrefix,
            $promptTokens,
            $completionTokens,
            $totalTokens,
            $cachedTokens
        ));

        return $totalTokens;
    }

    private function callGeminiWithFunctions($contents, $includeFunctions = true) {
        $apiKey = $this->config['apiKey'];
        $model = $this->config['model'];
        $temperature = $this->config['temperature'];
        $maxTokens = $this->config['maxTokens'];

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        // Build systemInstruction from system-prompt.txt (catalog file will be added to user message)
        $systemInstructionText = !empty($this->systemPromptText) ? $this->systemPromptText : 'You are a helpful customer service assistant for Sirichai Electric. Follow the instructions and use the product catalog information provided in the context. IMPORTANT: Always respond in the same language the customer uses. If they write in English, respond in English. If they write in Thai, respond in Thai.';

        // Add catalog file reference to the FIRST user message (if available)
        if ($this->catalogFileUri && !empty($contents) && $contents[0]['role'] === 'user') {
            $fileParts = array(
                array(
                    'file_data' => array(
                        'file_uri' => $this->catalogFileUri,
                        'mime_type' => 'text/plain'
                    )
                )
            );

            // Prepend catalog file to the first user message parts
            $contents[0]['parts'] = array_merge($fileParts, $contents[0]['parts']);
        }

        // Build request body
        $requestBody = array(
            'contents' => $contents,
            'systemInstruction' => array(
                'parts' => array(
                    array('text' => $systemInstructionText)
                )
            ),
            'generationConfig' => array(
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens,
            ),
        );

        // Add function declarations
        if ($includeFunctions) {
            $requestBody['tools'] = array(
                array(
                    'functionDeclarations' => array(
                        array(
                            'name' => 'search_products',
                            'description' => 'Search for products by exact category names from the catalog file. Returns product name, price, and unit grouped by category. CRITICAL: Copy complete category names including all text inside {}, [], () - these contain brand/model codes. Never exceed 3 categories.',
                            'parameters' => array(
                                'type' => 'object',
                                'properties' => array(
                                    'criterias' => array(
                                        'type' => 'array',
                                        'items' => array('type' => 'string'),
                                        'description' => 'Array of EXACT category names from catalog (the part before " | "). Must include ALL special characters: {}, [], () and their contents. Maximum 3 categories.'
                                    )
                                ),
                                'required' => array('criterias')
                            )
                        ),
                        array(
                            'name' => 'search_product_detail',
                            'description' => 'Get detailed product specifications (weight, size, thickness, quantity per pack). CRITICAL: (1) MUST use EXACT product name from search_products() results - NEVER use customer\'s informal name directly, (2) If you don\'t have exact product name from previous search_products(), call search_products() FIRST to get it, (3) ALWAYS call this function for spec questions - NEVER say "information not available" without trying. Trigger keywords: น้ำหนัก/weight, หนา/thickness, ขนาด/size/dimensions, กี่ชิ้นต่อแพ็ค/quantity per pack.',
                            'parameters' => array(
                                'type' => 'object',
                                'properties' => array(
                                    'productName' => array(
                                        'type' => 'string',
                                        'description' => 'EXACT complete product name from search_products() results. Must include ALL characters: brackets [], braces {}, parentheses (), numbers, Thai/English text. NEVER use customer\'s informal product name. Example correct: "รางวายเวย์ 2\"x3\" (50x75) ยาว 2.4เมตร สีขาว KWSS2038-10 KJL". Example WRONG: "KWSS2038-10" or "LC1D12M7".'
                                    )
                                ),
                                'required' => array('productName')
                            )
                        )
                    )
                )
            );
        }

        $jsonBody = json_encode($requestBody);

        // Make cURL request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
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
            error_log('[Chatbot] ERROR: HTTP ' . $httpCode . ' - ' . $response);

            // Try to extract error message from response
            $errorData = json_decode($response, true);
            $errorMessage = 'HTTP error: ' . $httpCode;
            if (isset($errorData['error']['message'])) {
                $errorMessage .= ' - ' . $errorData['error']['message'];
            }

            // Add helpful message for quota errors
            if ($httpCode === 429) {
                $errorMessage .= '. Please wait a moment or upgrade to paid tier for higher limits.';
            }

            return array(
                'success' => false,
                'error' => $errorMessage,
            );
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'JSON decode error: ' . json_last_error_msg(),
            );
        }

        // Log token usage for this API call and get token count
        $tokensUsed = $this->logTokenUsage($data, $includeFunctions ? 'Initial Call' : 'Follow-up Call');

        // Check if response has content
        if (!isset($data['candidates'][0]['content']['parts']) || empty($data['candidates'][0]['content']['parts'])) {
            // Log the actual response structure for debugging
            error_log('[Chatbot] ERROR: Empty model response');
            error_log('[Chatbot] Full response: ' . json_encode($data, JSON_UNESCAPED_UNICODE));

            // Try to get actual error reason from API response
            $errorMessage = 'AI returned empty response';

            // Check for finish reason which often contains the actual error
            if (isset($data['candidates'][0]['finishReason'])) {
                $finishReason = $data['candidates'][0]['finishReason'];
                $errorMessage .= ': ' . $finishReason;

                // Add helpful context based on finish reason
                if ($finishReason === 'MAX_TOKENS') {
                    $errorMessage .= '. The conversation is too long. Please start a new conversation.';
                } elseif ($finishReason === 'SAFETY') {
                    $errorMessage .= '. Content was blocked by safety filters.';
                } elseif ($finishReason === 'RECITATION') {
                    $errorMessage .= '. Content was blocked due to recitation concerns.';
                }
            }

            // Check if there's a prompt feedback error
            if (isset($data['promptFeedback']['blockReason'])) {
                $errorMessage .= '. Block reason: ' . $data['promptFeedback']['blockReason'];
            }

            return array(
                'success' => false,
                'error' => $errorMessage,
            );
        }

        // Check for function calls
        if (isset($data['candidates'][0]['content']['parts'])) {
            $parts = $data['candidates'][0]['content']['parts'];

            // Check if there are function calls
            $functionCalls = array();
            $functionCallParts = array();

            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    $functionCalls[] = $part['functionCall'];
                    $functionCallParts[] = $part;
                }
            }

            if (!empty($functionCalls)) {
                return array(
                    'success' => true,
                    'functionCalls' => $functionCalls,
                    'functionCallParts' => $functionCallParts,
                    'tokensUsed' => $tokensUsed,
                );
            }

            // Regular text response - check all parts for text
            $textParts = array();
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $textParts[] = $part['text'];
                }
            }

            if (!empty($textParts)) {
                return array(
                    'success' => true,
                    'text' => implode('', $textParts),
                    'tokensUsed' => $tokensUsed,
                );
            }

            // Log unexpected parts structure (debugging edge cases)
            error_log('[Chatbot] ERROR: Unexpected parts structure - ' . json_encode($parts, JSON_UNESCAPED_UNICODE));
        }

        // Check for error in response
        if (isset($data['error'])) {
            $errorMsg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown API error';
            return array(
                'success' => false,
                'error' => $errorMsg,
            );
        }

        // Log full response for debugging edge cases
        error_log('[Chatbot] ERROR: Unexpected API response - ' . substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 500));

        return array(
            'success' => false,
            'error' => 'Unexpected API response format',
        );
    }
}

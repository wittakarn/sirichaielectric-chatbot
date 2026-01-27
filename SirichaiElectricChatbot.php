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

        // Upload system prompt file
        $promptFile = __DIR__ . '/system-prompt.txt';
        $promptContent = '';
        if (file_exists($promptFile)) {
            $promptContent = file_get_contents($promptFile);
        } else {
            $promptContent = "You are a helpful customer service assistant for ONE Electric.";
        }

        $promptResult = $this->fileManager->getOrUploadFile(
            'system-prompt',
            $promptContent,
            'System Prompt'
        );

        if ($promptResult['success']) {
            $this->systemPromptFileUri = $promptResult['fileUri'];
            $cacheStatus = isset($promptResult['cached']) && $promptResult['cached'] ? 'CACHED' : 'UPLOADED';
            error_log('[Chatbot] ✓ System Prompt: ' . $cacheStatus . ' - ' . $promptResult['name']);
        } else {
            error_log('[Chatbot] ✗ System Prompt: FAILED - ' . $promptResult['error']);
            $this->systemPromptFileUri = null;
        }

        // Upload catalog file if available
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

        error_log('[Chatbot] === File API Context Ready ===');
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

    public function chat($message, $conversationHistory = array()) {
        try {
            // Track total tokens used across all API calls
            $totalTokens = 0;

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
                );
            }

            // Handle function calls if present
            if (isset($response['functionCalls']) && !empty($response['functionCalls'])) {
                // Log each function call with readable Thai text
                foreach ($response['functionCalls'] as $call) {
                    $functionName = isset($call['name']) ? $call['name'] : 'unknown';
                    $args = isset($call['args']) ? $call['args'] : array();
                    $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    error_log('[Chatbot] Calling: ' . $functionName . '(' . $argsJson . ')');
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
                );
            }

            return array(
                'success' => true,
                'response' => $response['text'],
                'language' => $this->detectLanguage($message),
                'tokensUsed' => $totalTokens,
            );

        } catch (Exception $e) {
            error_log('[Chatbot] ERROR: ' . $e->getMessage());
            return array(
                'success' => false,
                'response' => '',
                'error' => $e->getMessage(),
                'tokensUsed' => 0,
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
            return $result !== null ? $result : "No products found.";
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

        // Build systemInstruction - ONLY TEXT ALLOWED (no files!)
        // Files must be added to user messages instead
        $systemInstructionText = 'You are a helpful customer service assistant for Sirichai Electric. Follow the instructions and use the product catalog information provided in the context. IMPORTANT: Always respond in the same language the customer uses. If they write in English, respond in English. If they write in Thai, respond in Thai.';

        // Add file references to the FIRST user message (not systemInstruction!)
        // This allows Gemini to cache files while following API requirements
        if (($this->systemPromptFileUri || $this->catalogFileUri) && !empty($contents) && $contents[0]['role'] === 'user') {
            // Build file parts to prepend
            $fileParts = array();

            if ($this->systemPromptFileUri) {
                $fileParts[] = array(
                    'file_data' => array(
                        'file_uri' => $this->systemPromptFileUri,
                        'mime_type' => 'text/plain'
                    )
                );
            }

            if ($this->catalogFileUri) {
                $fileParts[] = array(
                    'file_data' => array(
                        'file_uri' => $this->catalogFileUri,
                        'mime_type' => 'text/plain'
                    )
                );
            }

            // Prepend files to the first user message parts
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
                            'description' => 'Search for products by exact category names from the catalog file. Returns product name, price, and unit grouped by category.',
                            'parameters' => array(
                                'type' => 'object',
                                'properties' => array(
                                    'criterias' => array(
                                        'type' => 'array',
                                        'items' => array('type' => 'string'),
                                        'description' => 'Array of exact category names from the catalog file. Limit to 3 most relevant categories.'
                                    )
                                ),
                                'required' => array('criterias')
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

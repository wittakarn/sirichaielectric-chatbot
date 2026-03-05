<?php

use ChatbotCore\GeminiChatbot;

/**
 * Sirichai Electric chatbot.
 * Extends ChatbotCore\GeminiChatbot with product-search, product-detail, and quotation functions.
 */
class SirichaiElectricChatbot extends GeminiChatbot {

    /** @var ProductAPIService|null */
    private $productAPI;

    public function __construct(array $config, $productAPI = null) {
        $this->productAPI = $productAPI;
        parent::__construct($config, __DIR__ . '/../file-cache.json');
    }

    protected function fetchCatalogSummary(): string {
        if ($this->productAPI === null) {
            return '';
        }
        try {
            $text = $this->productAPI->getCatalogSummary();
            if ($text === null) {
                error_log('[Chatbot] Catalog returned null');
                return '';
            }
            error_log('[Chatbot] Catalog loaded: ' . strlen($text) . ' chars');
            return $text;
        } catch (Exception $e) {
            error_log('[Chatbot] ERROR: Failed to fetch catalog - ' . $e->getMessage());
            return '';
        }
    }

    protected function loadSystemPromptText(): string {
        $promptFile = __DIR__ . '/../system-prompt.txt';
        if (file_exists($promptFile)) {
            return file_get_contents($promptFile);
        }
        return 'You are a helpful customer service assistant for Sirichai Electric.';
    }

    protected function getFunctionDeclarations(): array {
        return array(array('functionDeclarations' => array(
            array(
                'name'        => 'search_products',
                'description' => 'Search for products by exact category names from the catalog file. Returns product name, price, and unit grouped by category. CRITICAL: Copy complete category names including all text inside {}, [], () - these contain brand/model codes. Never exceed 3 categories.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'criterias' => array(
                            'type'        => 'array',
                            'items'       => array('type' => 'string'),
                            'description' => 'Array of EXACT category names from catalog (the part before " | "). Must include ALL special characters: {}, [], () and their contents. Maximum 3 categories.'
                        )
                    ),
                    'required' => array('criterias')
                )
            ),
            array(
                'name'        => 'search_product_detail',
                'description' => 'Get detailed product specifications (weight, size, thickness, quantity per pack). CRITICAL: (1) MUST use EXACT product name from search_products() results - NEVER use customer\'s informal name directly, (2) If you don\'t have exact product name from previous search_products(), call search_products() FIRST to get it, (3) ALWAYS call this function for spec questions - NEVER say "information not available" without trying. Trigger keywords: น้ำหนัก/weight, หนา/thickness, ขนาด/size/dimensions, กี่ชิ้นต่อแพ็ค/quantity per pack.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'productName' => array(
                            'type'        => 'string',
                            'description' => 'EXACT complete product name from search_products() results. Must include ALL characters: brackets [], braces {}, parentheses (), numbers, Thai/English text. NEVER use customer\'s informal product name.'
                        )
                    ),
                    'required' => array('productName')
                )
            ),
            array(
                'name'        => 'generate_quotation',
                'description' => 'Generate a fast quotation PDF from products discussed in the conversation.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'quotaDetail' => array(
                            'type'        => 'array',
                            'items'       => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'productName' => array(
                                        'type'        => 'string',
                                        'description' => 'EXACT product name from search_products() results discussed in the conversation'
                                    ),
                                    'amount' => array(
                                        'type'        => 'number',
                                        'description' => 'Quantity of the product. Use the amount discussed in conversation, or ask the user if not specified.'
                                    )
                                ),
                                'required' => array('productName', 'amount')
                            ),
                            'description' => 'Array of products with their names and quantities from the conversation history'
                        ),
                        'priceType' => array(
                            'type'        => 'string',
                            'enum'        => array('ss', 's', 'a', 'b', 'c', 'vb', 'vc', 'd', 'e', 'f'),
                            'description' => 'Price type extracted from user message'
                        )
                    ),
                    'required' => array('quotaDetail', 'priceType')
                )
            ),
        )));
    }

    protected function executeFunction(string $functionName, array $args): string {
        if ($this->productAPI === null) {
            return 'Product API service not available.';
        }

        if ($functionName === 'search_products') {
            $criterias = isset($args['criterias']) ? $args['criterias'] : array();
            if (empty($criterias)) {
                return 'No search criteria provided.';
            }
            $result = $this->productAPI->searchProducts($criterias);
            return $result !== null ? $result : 'No products found.';
        }

        if ($functionName === 'search_product_detail') {
            $productName = isset($args['productName']) ? $args['productName'] : '';
            if (empty($productName)) {
                return 'No product name provided.';
            }
            $result = $this->productAPI->getProductDetail($productName);
            return $result !== null ? $result : 'Product details not found.';
        }

        if ($functionName === 'generate_quotation') {
            $quotaDetail = isset($args['quotaDetail']) ? $args['quotaDetail'] : array();
            $priceType   = isset($args['priceType']) ? $args['priceType'] : 'c';

            $validTypes = array('ss', 's', 'a', 'b', 'c', 'vb', 'vc', 'd', 'e', 'f');
            if (!in_array($priceType, $validTypes) || !$this->isAuthorized) {
                $priceType = 'c';
            }

            if (empty($quotaDetail)) {
                return 'No products provided for quotation.';
            }

            $result = $this->productAPI->generateFastQuotation($quotaDetail, $priceType);
            return $result !== null ? $result : 'Failed to generate quotation.';
        }

        return 'Unknown function: ' . $functionName;
    }

    protected function extractSearchCriteria(string $functionName, array $args): ?string {
        if ($functionName === 'search_products' && isset($args['criterias'])) {
            return json_encode($args['criterias'], JSON_UNESCAPED_UNICODE);
        }

        if ($functionName === 'search_product_detail' && isset($args['productName'])) {
            return json_encode(array('productName' => $args['productName']), JSON_UNESCAPED_UNICODE);
        }

        if ($functionName === 'generate_quotation') {
            return json_encode(array(
                'quotaDetail' => isset($args['quotaDetail']) ? $args['quotaDetail'] : array(),
                'priceType'   => isset($args['priceType']) ? $args['priceType'] : '',
            ), JSON_UNESCAPED_UNICODE);
        }

        return null;
    }
}

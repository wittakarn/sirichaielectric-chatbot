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
        parent::__construct($config);
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
                'name'        => 'search_catalog',
                'description' => 'Look up catalog category names relevant to the customer\'s product question. Used in WORKFLOW 1 Path B — when the customer\'s message has NO specific model/part number, only a product type, brand, or spec. If the message DOES contain a model/part number (e.g. "LRD05", "WEG5001K"), skip this function and call search_products directly with loose terms (Path A). Returns catalog lines (categories) that match or closely match the query — exact and loose matches may both appear. Pass the customer\'s product question as-is — no keyword extraction or rewriting needed. Full path/selection rules live in the system prompt (WORKFLOW 1 Path A & Path B).',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'query' => array(
                            'type'        => 'string',
                            'description' => 'The customer\'s product question, passed through as-is. Use only when the question has NO model/part number. Example: "ขอราคาเบรกเกอร์ 32A" or "สายไฟ THW สีดำ ยาซากิ" or "เบรกเกอร์ ABB 3P". (Queries containing a code like "LRD05" should go to search_products directly — do NOT call search_catalog for those.)'
                        )
                    ),
                    'required' => array('query')
                )
            ),
            array(
                'name'        => 'search_products',
                'description' => 'Search for products. The criterias array accepts either: (a) loose terms — a model/part number and optionally a brand name (e.g. ["WEG5001K","PANASONIC"]) — used in WORKFLOW 1 Path A when the customer\'s message contains a specific model/part code; or (b) exact catalog category names returned from search_catalog() — used in WORKFLOW 1 Path B. Returns results as lines formatted: "Name | Price | Unit | Id". Use the numeric Id (4th field) to render every product as a markdown link: "[Name](https://shop.sirichaielectric.com/product/Id) ราคา: Price บาท/Unit". Maximum 3 criteria items.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'criterias' => array(
                            'type'        => 'array',
                            'items'       => array('type' => 'string'),
                            'description' => 'Array of search criteria — either loose terms (model/part number, brand) for Path A, or EXACT category names returned from search_catalog() for Path B. Maximum 3 items. Never mix terms from different products in one call.'
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

        if ($functionName === 'search_catalog') {
            $query = isset($args['query']) ? trim($args['query']) : '';
            if ($query === '') {
                return 'No search query provided.';
            }
            $result = $this->productAPI->searchCatalog($query);
            return $result !== null && $result !== '' ? $result : 'No matching catalog entries found.';
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
        if ($functionName === 'search_catalog' && isset($args['query'])) {
            return json_encode(array('query' => $args['query']), JSON_UNESCAPED_UNICODE);
        }

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

<?php

use ChatbotCore\GeminiChatbot;

/**
 * Sirichai Electric chatbot.
 * Extends ChatbotCore\GeminiChatbot with product-search, product-detail, and quotation functions.
 *
 * When a CatalogEmbeddingService is provided, RAG is active:
 *   - fetchCatalogSummary() returns '' so the base class skips the 94 KB File API upload.
 *   - chat() embeds the user message and retrieves the top-K relevant catalog categories.
 *   - loadSystemPromptText() appends those categories so Gemini receives a focused context.
 *
 * When no CatalogEmbeddingService is provided, the chatbot falls back to the original
 * full-catalog approach (backwards compatible).
 */
class SirichaiElectricChatbot extends GeminiChatbot {

    /** @var ProductAPIService|null */
    private $productAPI;

    /** @var CatalogEmbeddingService|null */
    private $embeddingService;

    /** @var string[] Categories retrieved by RAG for the current request */
    private $ragCategories = array();

    public function __construct(array $config, $productAPI = null, $embeddingService = null) {
        $this->productAPI       = $productAPI;
        $this->embeddingService = $embeddingService;
        parent::__construct($config, __DIR__ . '/../file-cache.json');
    }

    /**
     * Before handing off to the parent, run RAG retrieval so that
     * loadSystemPromptText() can inject the result inline.
     */
    public function chat($message, $history) {
        $this->ragCategories = array();

        if ($this->embeddingService !== null) {
            try {
                $this->ragCategories = $this->embeddingService->getRelevantCategories($message);
                error_log('[RAG] Retrieved ' . count($this->ragCategories) . ' categories for query: ' . mb_substr($message, 0, 60, 'UTF-8'));
            } catch (Exception $e) {
                error_log('[RAG] Retrieval failed, falling back to no catalog context: ' . $e->getMessage());
            }
        }

        return parent::chat($message, $history);
    }

    /**
     * Return '' when RAG is active so the base class skips uploading the
     * full catalog to Gemini File API.  Falls back to full catalog otherwise.
     */
    protected function fetchCatalogSummary(): string {
        if ($this->embeddingService !== null) {
            return '';
        }

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

    /**
     * System prompt + RAG catalog snippet (when available).
     * The snippet lists only the top-K pre-filtered categories so Gemini
     * never needs to scan the full 1 000+ category list.
     */
    protected function loadSystemPromptText(): string {
        $promptFile = __DIR__ . '/../system-prompt.txt';
        $base = file_exists($promptFile)
            ? file_get_contents($promptFile)
            : 'You are a helpful customer service assistant for Sirichai Electric.';

        if (!empty($this->ragCategories)) {
            $base .= "\n\nRELEVANT CATALOG CATEGORIES (pre-filtered for this query — pick from these only):\n";
            $base .= implode("\n", $this->ragCategories);
        }

        return $base;
    }

    protected function getFunctionDeclarations(): array {
        return array(array('functionDeclarations' => array(
            array(
                'name'        => 'search_products',
                'description' => 'Search for products by exact category names from the catalog. Returns results as lines formatted: "Name | Price | Unit | Id". Use the numeric Id (4th field) to render every product as a markdown link: "[Name](https://shop.sirichaielectric.com/product/Id) ราคา: Price บาท/Unit". Never exceed 3 categories.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'criterias' => array(
                            'type'        => 'array',
                            'items'       => array('type' => 'string'),
                            'description' => 'Array of EXACT category names from the list provided above. Maximum 3 categories.'
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

<?php

use ChatbotCore\GeminiChatbot;

require_once __DIR__ . '/../services/InputGuard.php';
require_once __DIR__ . '/../services/PriceTypePolicy.php';

/**
 * Sirichai Electric chatbot.
 * Extends ChatbotCore\GeminiChatbot with product-search, product-detail, and quotation functions.
 */
class SirichaiElectricChatbot extends GeminiChatbot {

    /** @var ProductAPIService|null */
    private $productAPI;

    /** @var InputGuard */
    private $inputGuard;

    /** @var PriceTypePolicy */
    private $priceTypePolicy;

    public function __construct(array $config, $productAPI = null, ?InputGuard $inputGuard = null, ?PriceTypePolicy $priceTypePolicy = null) {
        $this->productAPI = $productAPI;
        $this->inputGuard = $inputGuard !== null ? $inputGuard : new InputGuard();
        $this->priceTypePolicy = $priceTypePolicy !== null ? $priceTypePolicy : new PriceTypePolicy();
        parent::__construct($config);
    }

    protected function loadSystemPromptText(): string {
        $promptFile = __DIR__ . '/../system-prompt.txt';
        if (file_exists($promptFile)) {
            return file_get_contents($promptFile);
        }
        return 'You are a helpful customer service assistant for Sirichai Electric.';
    }

    public function chat(string $message, array $conversationHistory = array()): array {
        $reason = $this->inputGuard->refusalReason($message);
        if ($reason === InputGuard::REASON_TOO_LONG) {
            error_log('[SirichaiElectricChatbot] Message too long blocked: ' . mb_strlen($message, 'UTF-8') . ' chars');
            return $this->refusal('ขออภัยค่ะ ข้อความยาวเกินไป กรุณาสอบถามสั้น ๆ เช่น ชื่อสินค้า ยี่ห้อ หรือรุ่นที่ต้องการค่ะ');
        }
        if ($reason === InputGuard::REASON_INJECTION) {
            error_log('[SirichaiElectricChatbot] Prompt injection attempt blocked: ' . substr($message, 0, 200));
            return $this->refusal('ขออภัยค่ะ ระบบนี้ให้บริการด้านสินค้าไฟฟ้าของศิริชัยอิเล็คทริคเท่านั้น กรุณาสอบถามเกี่ยวกับสินค้าที่ต้องการค่ะ');
        }
        return parent::chat($message, $conversationHistory);
    }

    // Canned reply for refused input: no model call, so no tokens spent.
    private function refusal(string $text): array {
        return array(
            'success'        => true,
            'response'       => $text,
            'language'       => 'th',
            'tokensUsed'     => 0,
            'searchCriteria' => null,
        );
    }

    protected function getFunctionDeclarations(): array {
        return array(array('functionDeclarations' => array(
            array(
                'name'        => 'search_products',
                'description' => 'Search for products. Each criterias item is the search query for ONE product — the words the customer used for that product (type, brand, model/part number, size, colour). For a list of several products, pass every product in ONE call, one item each. Results come back grouped under a "## <query>" heading per item, as lines formatted: "Name | Price | Unit | Id", best match first. Use the numeric Id (4th field) to render every product as a markdown link: "[Name](https://shop.sirichaielectric.com/product/Id) ราคา: Price บาท/Unit". Maximum 10 items.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'criterias' => array(
                            'type'        => 'array',
                            'items'       => array('type' => 'string'),
                            'description' => 'One search query per product, e.g. ["เบรกเกอร์ ABB 3P 32A"] or, for a list, ["สายไฟ THW 1x2.5 ยาซากิ สีดำ", "LRD05", "รางวายเวย์ KWSS2038"]. Keep the words for each product together in its own item; never merge two products into one item. Maximum 10 items.'
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
                        ),
                        'remark' => array(
                            'type'        => 'string',
                            'description' => 'Customer contact the customer gave for this quotation (name, phone, LINE ID), as they wrote it. Omit if none.'
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
            $criterias = isset($args['criterias']) ? array_slice(array_filter(array_map('trim', (array) $args['criterias']), 'strlen'), 0, 10) : array();
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
            // Model-supplied priceType is untrusted; the policy forces tier 'c' for unauthorized users.
            $priceType   = $this->priceTypePolicy->resolve(isset($args['priceType']) ? $args['priceType'] : null, $this->isAuthorized);

            if (empty($quotaDetail)) {
                return 'No products provided for quotation.';
            }

            $remark = isset($args['remark']) && is_string($args['remark']) ? trim($args['remark']) : '';
            $result = $this->productAPI->generateFastQuotation($quotaDetail, $priceType, $remark);
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
                'remark'      => isset($args['remark']) ? $args['remark'] : '',
            ), JSON_UNESCAPED_UNICODE);
        }

        return null;
    }
}

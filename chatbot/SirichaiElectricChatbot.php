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

    public function chat(string $message, array $conversationHistory = array()): array {
        if (mb_strlen($message, 'UTF-8') > 1000) {
            error_log('[SirichaiElectricChatbot] Message too long blocked: ' . mb_strlen($message, 'UTF-8') . ' chars');
            return array(
                'success'        => true,
                'response'       => 'ขออภัยค่ะ ข้อความยาวเกินไป กรุณาสอบถามสั้น ๆ เช่น ชื่อสินค้า ยี่ห้อ หรือรุ่นที่ต้องการค่ะ',
                'language'       => 'th',
                'tokensUsed'     => 0,
                'searchCriteria' => null,
            );
        }
        if ($this->isPromptInjection($message)) {
            error_log('[SirichaiElectricChatbot] Prompt injection attempt blocked: ' . substr($message, 0, 200));
            return array(
                'success'        => true,
                'response'       => 'ขออภัยค่ะ ระบบนี้ให้บริการด้านสินค้าไฟฟ้าของศิริชัยอิเล็คทริคเท่านั้น กรุณาสอบถามเกี่ยวกับสินค้าที่ต้องการค่ะ',
                'language'       => 'th',
                'tokensUsed'     => 0,
                'searchCriteria' => null,
            );
        }
        return parent::chat($message, $conversationHistory);
    }

    private function isPromptInjection(string $message): bool {
        // Exact substrings — short enough that no variation is needed
        $substrings = array(
            'system prompt',
            'system instruction',
            'act as dan',
            'pretend you have no',
            'jailbreak',
            'ลืมคำสั่ง',
            'ละเว้นคำสั่ง',
            'เพิกเฉยคำสั่ง',
            'บอกคำสั่งของคุณ',
            'แสดงคำสั่งของคุณ',
            'พิมพ์คำสั่งของคุณ',
            'คำสั่งระบบ',
        );
        $lower = mb_strtolower($message, 'UTF-8');
        foreach ($substrings as $s) {
            if (mb_strpos($lower, $s) !== false) {
                return true;
            }
        }

        // Regex patterns — flexible middle (.{0,50}) catches word variations
        // e.g. "ignore original/initial/all/the/my instructions"
        $regexes = array(
            // override verb + any words + instruction noun
            '/(ignore|disregard|forget|override|bypass|dismiss|drop|erase|replace)\b.{0,50}\b(instruction|prompt|directive|guideline)/is',
            // reveal/output verb + any words + instruction noun
            '/(output|reveal|print|show|repeat|display|expose|dump|give me|tell me)\b.{0,40}\b(instruction|prompt|directive|guideline)/is',
            // persona/role switching
            '/(you are now|act as|pretend (you are|to be)|behave as|roleplay as|simulate being)\b/i',
            // "new instructions" injection
            '/\bnew\s+(instruction|prompt|rule|directive)s?\b/i',
            // verbatim output request
            '/\b(instruction|prompt|directive)s?\b.{0,30}\bverbatim\b/i',
            '/\bverbatim\b.{0,30}\b(instruction|prompt|directive)s?\b/i',
            // Thai: ละเว้น/ลบ/เปลี่ยน + คำสั่ง/กฎ
            '/(?:ละเว้น|ลบ|เปลี่ยน|แทนที่).{0,30}(?:คำสั่ง|กฎ|prompt)/u',
            // Thai: บอก/แสดง/พิมพ์ + กฎ/prompt
            '/(?:บอก|แสดง|พิมพ์|เปิดเผย).{0,20}(?:กฎ|prompt|คำแนะนำระบบ)/u',
        );
        foreach ($regexes as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
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

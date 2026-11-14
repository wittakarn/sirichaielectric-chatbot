<?php
/**
 * Product API Service - Handles catalog and product API calls
 * PHP 5.6 compatible
 */

class ProductAPIService {
    private $config;
    private $searchService;
    private $productSearchUrl;
    private $productKeywordsUrl;
    private $productByIdsUrl;
    private $productDetailUrl;
    private $quotationUrl;

    public function __construct($config, $searchService) {
        $this->config             = $config;
        $this->searchService      = $searchService;
        $this->productSearchUrl   = $config['productSearchUrl'];
        $this->productKeywordsUrl = isset($config['productKeywordsUrl']) ? $config['productKeywordsUrl'] : '';
        $this->productByIdsUrl    = isset($config['productByIdsUrl']) ? $config['productByIdsUrl'] : '';
        $this->productDetailUrl   = $config['productDetailUrl'];
        $this->quotationUrl       = $config['quotationUrl'];
    }

    /**
     * Search products, one query per product. The OneElectric Supabase index finds the
     * product IDs (all queries in parallel), Sirichai's by-IDs endpoint prices them.
     * If that finds nothing, falls back to the keywords endpoint, then the category endpoint.
     * @param array $criterias One search query per product
     * @return string|null Returns markdown formatted product list, or null on error
     */
    public function searchProducts($criterias) {
        error_log('[ProductAPI] Searching products with criteria: ' . json_encode($criterias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $indexed = $this->searchIndex($criterias);
        if ($indexed !== null) {
            error_log('[ProductAPI] Index search returned (' . strlen($indexed) . ' chars):' . PHP_EOL . $indexed);
            return $indexed;
        }
        error_log('[ProductAPI] Index search empty — falling back to keywords endpoint');

        if ($this->productKeywordsUrl !== '') {
            $keywordsResult = $this->postCriterias($this->productKeywordsUrl, $criterias, 'keywords');
            if ($this->hasProductLines($keywordsResult)) {
                error_log('[ProductAPI] Keywords endpoint returned results — using those');
                return $keywordsResult;
            }
            error_log('[ProductAPI] Keywords endpoint empty — falling back to categories endpoint');
        }

        return $this->postCriterias($this->productSearchUrl, $criterias, 'categories');
    }

    /** @return string|null product lines grouped per query, or null when nothing was found */
    private function searchIndex($queries) {
        if ($this->productByIdsUrl === '') {
            return null;
        }
        $found = $this->searchService->searchMany($queries);
        if ($found === null) {
            return null;
        }
        $ids = array();
        foreach ($found as $rows) {
            foreach ($rows as $row) {
                $ids[(int) $row['product_id']] = true;
            }
        }
        if (empty($ids)) {
            return null;
        }
        $priced = $this->pricesById(array_keys($ids));

        $output = "# PRODUCTS\n\nFormat: Name | Price | Unit | Id\n";
        $lines  = 0;
        foreach ($found as $query => $rows) {
            $output .= "\n## " . $query . "\n";
            foreach ($rows as $row) {
                $id = (int) $row['product_id'];
                if (isset($priced[$id])) { // missing = not sold at Sirichai, or discontinued
                    $output .= $priced[$id] . "\n";
                    $lines++;
                }
            }
        }
        return $lines > 0 ? $output : null;
    }

    /**
     * Sirichai name, price and unit for product IDs (shop endpoint services/products-by-ids.php).
     * @return array id => "- Name | Price | Unit | Id"
     */
    private function pricesById($ids) {
        $response = $this->postJson($this->productByIdsUrl, array('product_ids' => $ids), 'by-ids');
        $priced = array();
        foreach (explode("\n", (string) $response) as $line) {
            if (preg_match('/^- .* \| (\d+)\s*$/u', $line, $m)) {
                $priced[(int) $m[1]] = rtrim($line);
            }
        }
        return $priced;
    }

    private function postCriterias($url, $criterias, $label) {
        return $this->postJson($url, array('criterias' => $criterias), $label);
    }

    private function postJson($url, $payload, $label) {
        $requestBody = json_encode($payload);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($requestBody)
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[ProductAPI] cURL error (' . $label . '): ' . $error);
            return null;
        }
        if ($httpCode !== 200) {
            error_log('[ProductAPI] HTTP error (' . $label . '): ' . $httpCode);
            return null;
        }
        error_log('[ProductAPI] ' . $label . ' search completed (' . strlen($response) . ' chars)');
        return $response;
    }

    private function hasProductLines($response) {
        if ($response === null || $response === '') return false;
        // Product lines start with "- " (markdown bullets). Empty payloads only have the format header.
        return strpos($response, "\n- ") !== false || strpos($response, "- ") === 0;
    }

    /**
     * Get detailed product information by product name
     * @param string $productName Exact product name from search results
     * @return string|null Returns product details (weight, size, quantity per pack, etc.), or null on error
     */
    public function getProductDetail($productName) {
        error_log('[ProductAPI] Getting product detail for: ' . $productName);

        $requestBody = json_encode(array('productName' => $productName));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->productDetailUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($requestBody)
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[ProductAPI] cURL error: ' . $error);
            return null;
        }

        if ($httpCode !== 200) {
            error_log('[ProductAPI] HTTP error: ' . $httpCode);
            return null;
        }

        error_log('[ProductAPI] Product detail fetched (' . strlen($response) . ' chars)');
        return $response;
    }

    /**
     * Generate a fast quotation PDF from product list
     * @param array $quotaDetail Array of objects with productName and amount
     * @param string $priceType Price type: 'vc', 'a', or 'b'
     * @return string|null Returns PDF download URL, or null on error
     */
    public function generateFastQuotation($quotaDetail, $priceType) {
        error_log('[ProductAPI] Generating fast quotation with ' . count($quotaDetail) . ' products, priceType: ' . $priceType);

        $requestBody = json_encode(array(
            'quotaDetail' => $quotaDetail,
            'priceType' => $priceType
        ), JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->quotationUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($requestBody)
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[ProductAPI] cURL error: ' . $error);
            return null;
        }

        if ($httpCode !== 200) {
            error_log('[ProductAPI] HTTP error: ' . $httpCode);
            return null;
        }

        error_log('[ProductAPI] Quotation generated successfully');
        return $response;
    }

}

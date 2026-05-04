<?php
/**
 * Product API Service - Handles catalog and product API calls
 * PHP 5.6 compatible
 */

class ProductAPIService {
    private $config;
    private $searchService;
    private $productSearchUrl;
    private $productDetailUrl;
    private $quotationUrl;

    public function __construct($config, $searchService) {
        $this->config          = $config;
        $this->searchService   = $searchService;
        $this->productSearchUrl = $config['productSearchUrl'];
        $this->productDetailUrl = $config['productDetailUrl'];
        $this->quotationUrl     = $config['quotationUrl'];
    }

    /**
     * Search the product catalog via Supabase vector similarity search.
     * Returns relevant catalog category lines, used by Gemini to pick exact category names.
     * @param string $query Free-form search query (brand, model, product type, etc.)
     * @return string|null Returns newline-joined catalog lines, or null on error
     */
    public function searchCatalog($query) {
        error_log('[ProductAPI] Search catalog query: ' . $query);

        $result = $this->searchService->search($query);

        if (!$result['success']) {
            error_log('[ProductAPI] Search failed: ' . (isset($result['error']) ? $result['error'] : 'unknown'));
            return null;
        }

        $resultText = implode("\n", $result['results']);
        error_log('[ProductAPI] Search catalog returned ' . count($result['results']) . ' results (' . strlen($resultText) . ' chars):' . PHP_EOL . $resultText);
        return $resultText;
    }

    /**
     * Search products by category names
     * @param array $criterias Array of exact category names from the catalog
     * @return string|null Returns markdown formatted product details, or null on error
     */
    public function searchProducts($criterias) {
        error_log('[ProductAPI] Searching products with criteria: ' . json_encode($criterias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $requestBody = json_encode(array('criterias' => $criterias));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->productSearchUrl);
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

        error_log('[ProductAPI] Product search completed (' . strlen($response) . ' chars)');
        return $response;
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

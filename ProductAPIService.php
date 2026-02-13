<?php
/**
 * Product API Service - Handles catalog-summary and product-search API calls
 * Replaces context caching with on-demand API queries
 * PHP 5.6 compatible
 */

class ProductAPIService {
    private $config;
    private $catalogSummaryUrl;
    private $productSearchUrl;
    private $cacheDir;
    private $cacheDuration; // in seconds

    public function __construct($config) {
        $this->config = $config;
        $this->catalogSummaryUrl = $config['catalogSummaryUrl'];
        $this->productSearchUrl = $config['productSearchUrl'];
        $this->cacheDir = __DIR__ . '/cache';
        $this->cacheDuration = 86400; // 24 hours

        // Create cache directory if it doesn't exist
        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get catalog summary (categories with product IDs)
     * Uses local cache to avoid repeated API calls
     * @return string|null Returns markdown formatted catalog, or null on error
     */
    public function getCatalogSummary() {
        $cacheFile = $this->cacheDir . '/catalog-summary-cache.md';

        // Check if cache exists and is valid
        if (file_exists($cacheFile)) {
            $cacheAge = time() - filemtime($cacheFile);
            if ($cacheAge < $this->cacheDuration) {
                error_log('[ProductAPI] Using cached catalog summary (age: ' . $cacheAge . 's)');
                $cachedData = file_get_contents($cacheFile);
                if ($cachedData !== false && strlen($cachedData) > 0) {
                    return $cachedData;
                }
                error_log('[ProductAPI] Cache file empty, fetching fresh data');
            } else {
                error_log('[ProductAPI] Cache expired (age: ' . $cacheAge . 's), fetching fresh data');
            }
        }

        // Cache miss or expired - fetch from API
        error_log('[ProductAPI] Fetching catalog summary from: ' . $this->catalogSummaryUrl);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->catalogSummaryUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[ProductAPI] cURL error: ' . $error);
            // Try to return stale cache if available
            if (file_exists($cacheFile)) {
                error_log('[ProductAPI] Using stale cache due to API error');
                return file_get_contents($cacheFile);
            }
            return null;
        }

        if ($httpCode !== 200) {
            error_log('[ProductAPI] HTTP error: ' . $httpCode);
            // Try to return stale cache if available
            if (file_exists($cacheFile)) {
                error_log('[ProductAPI] Using stale cache due to HTTP error');
                return file_get_contents($cacheFile);
            }
            return null;
        }

        // Save to cache
        file_put_contents($cacheFile, $response);
        error_log('[ProductAPI] Catalog summary fetched and cached successfully');
        return $response;
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

        $productDetailUrl = 'https://shop.sirichaielectric.com/services/get-product-by-name.php';

        $requestBody = json_encode(array('productName' => $productName));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $productDetailUrl);
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

}

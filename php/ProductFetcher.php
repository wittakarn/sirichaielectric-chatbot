<?php
/**
 * Product Fetcher for Sirichai Electric Chatbot
 * Fetches and caches product data from the API
 * PHP 5.6 compatible
 */

class ProductFetcher {
    private $config;
    private $productContext;
    private $lastUpdated;
    private $cacheFile;

    public function __construct($config) {
        $this->config = $config;
        $this->cacheFile = __DIR__ . '/cache/product-data.json';
        $this->productContext = null;
        $this->lastUpdated = null;
    }

    public function fetchProductData() {
        try {
            $endpoint = $this->config['apiEndpoint'];

            if (empty($endpoint)) {
                error_log('[Product Fetcher] No API endpoint configured');
                return false;
            }

            error_log('[Product Fetcher] Fetching product data from: ' . $endpoint);

            // Use cURL for PHP 5.6 compatibility
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new Exception('cURL error: ' . $error);
            }

            if ($httpCode !== 200) {
                throw new Exception('HTTP error: ' . $httpCode);
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON decode error: ' . json_last_error_msg());
            }

            if (isset($data['error']) && $data['error']) {
                $errorMsg = isset($data['message']) ? $data['message'] : 'API returned an error';
                throw new Exception($errorMsg);
            }

            // Build product context
            $this->productContext = $this->buildProductContext($data);
            $this->lastUpdated = time();

            // Cache the data
            $this->saveCache($data);

            error_log('[Product Fetcher] Product data updated successfully');
            return true;

        } catch (Exception $e) {
            error_log('[Product Fetcher] Error: ' . $e->getMessage());

            // Try to load from cache
            if ($this->loadCache()) {
                error_log('[Product Fetcher] Using cached product data');
                return true;
            }

            return false;
        }
    }

    private function buildProductContext($data) {
        $context = "CURRENT PRODUCT INVENTORY";

        if (isset($data['lastUpdated'])) {
            $context .= " (Updated: " . $data['lastUpdated'] . ")";
        }

        $context .= ":\n\n";

        // Categories
        if (isset($data['categories']) && is_array($data['categories'])) {
            $context .= "หมวดหมู่สินค้า (Product Categories):\n";
            foreach ($data['categories'] as $category) {
                $name = isset($category['name']) ? $category['name'] : 'Unknown';
                $brands = isset($category['brands']) ? implode(', ', $category['brands']) : '';
                $count = isset($category['productCount']) ? $category['productCount'] : 0;
                $priceRange = isset($category['priceRange']) ? $category['priceRange'] : '';

                $context .= "- {$name}";
                if (!empty($brands)) {
                    $context .= " [{$brands}]";
                }
                $context .= " ({$count} สินค้า)";
                if (!empty($priceRange)) {
                    $context .= " - ราคา {$priceRange}";
                }
                $context .= "\n";
            }
            $context .= "\n";
        }

        // Available brands
        if (isset($data['brands']) && is_array($data['brands'])) {
            $context .= "แบรนด์ที่มีจำหน่าย (Available Brands):\n";
            $context .= implode(', ', $data['brands']) . "\n\n";
        }

        // Featured products
        if (isset($data['featuredProducts']) && is_array($data['featuredProducts'])) {
            $context .= "สินค้าแนะนำ (Featured Products):\n";
            foreach ($data['featuredProducts'] as $product) {
                $name = isset($product['name']) ? $product['name'] : 'Unknown';
                $brand = isset($product['brand']) ? $product['brand'] : '';
                $category = isset($product['category']) ? $product['category'] : '';
                $price = isset($product['price']) ? number_format($product['price'], 2) : '';
                $inStock = isset($product['inStock']) ? $product['inStock'] : false;

                $context .= "- {$name}";
                if (!empty($brand)) {
                    $context .= " [{$brand}]";
                }
                if (!empty($category)) {
                    $context .= " ({$category})";
                }
                if (!empty($price)) {
                    $context .= " - ฿{$price}";
                }
                $context .= $inStock ? " ✓" : " (สินค้าหมด)";
                $context .= "\n";
            }
            $context .= "\n";
        }

        $context .= "Website: " . $this->config['websiteUrl'];

        return $context;
    }

    private function saveCache($data) {
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheData = array(
            'data' => $data,
            'timestamp' => time(),
        );

        file_put_contents($this->cacheFile, json_encode($cacheData));
    }

    private function loadCache() {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $cacheContent = file_get_contents($this->cacheFile);
        $cacheData = json_decode($cacheContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (!isset($cacheData['data']) || !isset($cacheData['timestamp'])) {
            return false;
        }

        // Use cache if it's less than 24 hours old
        $age = time() - $cacheData['timestamp'];
        if ($age > 86400) { // 24 hours
            return false;
        }

        $this->productContext = $this->buildProductContext($cacheData['data']);
        $this->lastUpdated = $cacheData['timestamp'];

        return true;
    }

    public function getProductContext() {
        // If no context yet, try to load from cache
        if ($this->productContext === null) {
            $this->loadCache();
        }
        return $this->productContext;
    }

    public function shouldUpdate() {
        if ($this->lastUpdated === null) {
            return true;
        }

        $intervalSeconds = $this->config['updateIntervalMinutes'] * 60;
        $age = time() - $this->lastUpdated;

        return $age >= $intervalSeconds;
    }

    public function getLastUpdated() {
        return $this->lastUpdated;
    }
}

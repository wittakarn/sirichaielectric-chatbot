<?php
/**
 * Configuration loader for Sirichai Electric Chatbot
 * PHP 5.6 compatible - loads environment variables from .env file
 */

class Config {
    private static $instance = null;
    private $config = array();

    private function __construct() {
        $this->loadEnv();
        $this->buildConfig();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Config();
        }
        return self::$instance;
    }

    private function loadEnv() {
        $envFile = __DIR__ . '/.env';

        if (!file_exists($envFile)) {
            throw new Exception('.env file not found');
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                if (preg_match('/^["\'](.*)["\']\s*$/', $value, $matches)) {
                    $value = $matches[1];
                }

                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    private function buildConfig() {
        $this->config = array(
            'gemini' => array(
                'apiKey' => $this->getEnv('GEMINI_API_KEY', ''),
                'model' => $this->getEnv('GEMINI_MODEL', 'gemini-2.5-flash'),
                'temperature' => floatval($this->getEnv('GEMINI_TEMPERATURE', '0.7')),
                'maxTokens' => intval($this->getEnv('GEMINI_MAX_TOKENS', '2048')),
            ),
            'productData' => array(
                'websiteUrl' => $this->getEnv('WEBSITE_URL', 'https://shop.sirichaielectric.com/'),
                'apiEndpoint' => $this->getEnv('PRODUCT_API_ENDPOINT', ''),
                'updateIntervalMinutes' => intval($this->getEnv('PRODUCT_UPDATE_INTERVAL_MINUTES', '60')),
            ),
            'rateLimit' => array(
                'maxRequestsPerMinute' => intval($this->getEnv('MAX_REQUESTS_PER_MINUTE', '15')),
            ),
        );
    }

    private function getEnv($key, $default = '') {
        // Check $_ENV first (more reliable), then fall back to getenv()
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        $value = getenv($key);
        return $value !== false && $value !== '' ? $value : $default;
    }

    public function get($section = null) {
        if ($section === null) {
            return $this->config;
        }
        return isset($this->config[$section]) ? $this->config[$section] : null;
    }

    public function validate() {
        if (empty($this->config['gemini']['apiKey'])) {
            throw new Exception('GEMINI_API_KEY is required in .env file');
        }

        if (empty($this->config['productData']['websiteUrl'])) {
            throw new Exception('WEBSITE_URL is required in .env file');
        }
    }
}

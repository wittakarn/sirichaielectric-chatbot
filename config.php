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
                'maxTokens' => intval($this->getEnv('GEMINI_MAX_OUTPUT_TOKENS', '1024')),
            ),
            'productAPI' => array(
                'catalogSummaryUrl' => $this->getEnv('CATALOG_SUMMARY_URL', ''),
                'productSearchUrl' => $this->getEnv('PRODUCT_SEARCH_URL', ''),
            ),
            'website' => array(
                'url' => $this->getEnv('WEBSITE_URL', 'https://assistant.sirichaielectric.com/'),
            ),
            'server' => array(
                'basePath' => $this->getEnv('API_BASE_PATH', ''),
            ),
            'rateLimit' => array(
                'maxRequestsPerMinute' => intval($this->getEnv('MAX_REQUESTS_PER_MINUTE', '15')),
            ),
            'line' => array(
                'channelSecret' => $this->getEnv('LINE_CHANNEL_SECRET', ''),
                'channelAccessToken' => $this->getEnv('LINE_CHANNEL_ACCESS_TOKEN', ''),
            ),
            'database' => array(
                'host' => $this->getEnv('DB_HOST', 'localhost'),
                'port' => intval($this->getEnv('DB_PORT', '3306')),
                'name' => $this->getEnv('DB_NAME', 'chatbotdb'),
                'user' => $this->getEnv('DB_USER', ''),
                'password' => $this->getEnv('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
            ),
            'conversation' => array(
                'maxMessages' => intval($this->getEnv('MAX_MESSAGES_PER_CONVERSATION', '50')),
                'autoResumeTimeoutMinutes' => intval($this->getEnv('AUTO_RESUME_TIMEOUT_MINUTES', '30')),
            ),
            'admin' => array(
                'username' => $this->getEnv('ADMIN_USERNAME', ''),
                'password_hash' => $this->getEnv('ADMIN_PASSWORD_HASH', ''),
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

    public function get($section = null, $key = null, $default = null) {
        if ($section === null) {
            return $this->config;
        }

        if ($key === null) {
            return isset($this->config[$section]) ? $this->config[$section] : null;
        }

        if (isset($this->config[$section][$key])) {
            return $this->config[$section][$key];
        }

        return $default;
    }

    public function validate() {
        if (empty($this->config['gemini']['apiKey'])) {
            throw new Exception('GEMINI_API_KEY is required in .env file');
        }

        if (empty($this->config['website']['url'])) {
            throw new Exception('WEBSITE_URL is required in .env file');
        }

        if (empty($this->config['productAPI']['catalogSummaryUrl'])) {
            throw new Exception('CATALOG_SUMMARY_URL is required in .env file');
        }

        if (empty($this->config['productAPI']['productSearchUrl'])) {
            throw new Exception('PRODUCT_SEARCH_URL is required in .env file');
        }

        if (empty($this->config['database']['user'])) {
            throw new Exception('DB_USER is required in .env file');
        }

        if (empty($this->config['database']['name'])) {
            throw new Exception('DB_NAME is required in .env file');
        }
    }
}

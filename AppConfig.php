<?php

use ChatbotCore\Config;

/**
 * App-level configuration for Sirichai Electric chatbot.
 * Extends ChatbotCore\Config with product API, website, rate-limit, and admin sections.
 */
class AppConfig extends Config {

    protected function buildConfig(): void {
        parent::buildConfig();

        $this->config['productAPI'] = array(
            'catalogSummaryUrl' => $this->getEnv('CATALOG_SUMMARY_URL', ''),
            'productSearchUrl'  => $this->getEnv('PRODUCT_SEARCH_URL', ''),
            'productDetailUrl'  => $this->getEnv('PRODUCT_DETAIL_URL', ''),
            'quotationUrl'      => $this->getEnv('QUOTATION_URL', ''),
        );

        $this->config['website'] = array(
            'url' => $this->getEnv('WEBSITE_URL', 'https://assistant.sirichaielectric.com/'),
        );

        $this->config['rateLimit'] = array(
            'maxRequestsPerMinute' => intval($this->getEnv('MAX_REQUESTS_PER_MINUTE', '15')),
        );

        $this->config['admin'] = array(
            'username'      => $this->getEnv('ADMIN_USERNAME', ''),
            'password_hash' => $this->getEnv('ADMIN_PASSWORD_HASH', ''),
        );

        $this->config['embedding'] = array(
            'enabled' => $this->getEnv('CATALOG_RAG_ENABLED', 'true') === 'true',
            'topK'    => intval($this->getEnv('CATALOG_RAG_TOP_K', '5')),
        );
    }

    public function validate(): void {
        parent::validate();

        if (empty($this->config['website']['url'])) {
            throw new \Exception('WEBSITE_URL is required in .env file');
        }

        if (empty($this->config['productAPI']['catalogSummaryUrl'])) {
            throw new \Exception('CATALOG_SUMMARY_URL is required in .env file');
        }

        if (empty($this->config['productAPI']['productSearchUrl'])) {
            throw new \Exception('PRODUCT_SEARCH_URL is required in .env file');
        }

        if (empty($this->config['productAPI']['productDetailUrl'])) {
            throw new \Exception('PRODUCT_DETAIL_URL is required in .env file');
        }

        if (empty($this->config['productAPI']['quotationUrl'])) {
            throw new \Exception('QUOTATION_URL is required in .env file');
        }
    }
}

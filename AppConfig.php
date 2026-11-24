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
            'productSearchUrl'   => $this->getEnv('PRODUCT_SEARCH_URL', ''),
            'productKeywordsUrl' => $this->getEnv('PRODUCT_KEYWORDS_URL', ''),
            'productByIdsUrl'    => $this->getEnv('PRODUCT_BY_IDS_URL', ''),
            'productDetailUrl'   =>$this->getEnv('PRODUCT_DETAIL_URL', ''),
            'quotationUrl'       => $this->getEnv('QUOTATION_URL', ''),
            'quotationToken'     => $this->getEnv('QUOTATION_TOKEN', ''),
            // Staff LINE chat (group/user ID) told about quotations that carry a customer contact
            'quotationNotifyTo'    => $this->getEnv('LINE_QUOTATION_NOTIFY_TO', ''),
            // No fallback for 'broadcast': the chatbot's own account has customers as friends
            'quotationNotifyToken' => $this->getEnv('LINE_QUOTATION_NOTIFY_TOKEN',
                $this->getEnv('LINE_QUOTATION_NOTIFY_TO', '') === 'broadcast' ? '' : $this->getEnv('LINE_CHANNEL_ACCESS_TOKEN', '')),
        );

        $this->config['website'] = array(
            'url' => $this->getEnv('WEBSITE_URL', 'https://assistant.sirichaielectric.com/'),
        );

        $this->config['rateLimit'] = array(
            'maxRequestsPerMinute' => intval($this->getEnv('MAX_REQUESTS_PER_MINUTE', '15')),
        );

        $this->config['turnstile'] = array(
            'secret'      => $this->getEnv('TURNSTILE_SECRET', ''),
            'tokenSecret' => $this->getEnv('CHAT_TOKEN_SECRET', ''),
            // Per session token, per minute. Separate from MAX_REQUESTS_PER_MINUTE (Gemini quota sizing).
            'maxPerMinute' => intval($this->getEnv('CHAT_MAX_REQUESTS_PER_MINUTE', '15')),
        );

        $this->config['admin'] = array(
            'username'      => $this->getEnv('ADMIN_USERNAME', ''),
            'password_hash' => $this->getEnv('ADMIN_PASSWORD_HASH', ''),
        );

        $this->config['supabase'] = array(
            'restUrl' => $this->getEnv('SUPABASE_REST_URL', ''),
            'key'     => $this->getEnv('SUPABASE_KEY', ''),
        );

        $this->config['ingest'] = array(
            'catalogUrl' => $this->getEnv('CATALOG_INGEST_URL', ''),
        );
    }

    public function validate(): void {
        parent::validate();

        if (empty($this->config['website']['url'])) {
            throw new \Exception('WEBSITE_URL is required in .env file');
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

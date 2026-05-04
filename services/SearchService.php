<?php

class SearchService {

    const EMBED_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent';
    const EMBED_MODEL   = 'models/gemini-embedding-001';

    private $geminiApiKey;
    private $supabaseRestUrl;
    private $supabaseKey;

    public function __construct($geminiApiKey, $supabaseRestUrl, $supabaseKey) {
        $this->geminiApiKey    = $geminiApiKey;
        $this->supabaseRestUrl = rtrim($supabaseRestUrl, '/');
        $this->supabaseKey     = $supabaseKey;
    }

    /**
     * Embed query and return most similar catalog entries.
     * @return array { success, results?, error? }
     */
    public function search($query, $nResults = 5) {
        $embedding = $this->embedQuery($query);
        if ($embedding === null) {
            return array('success' => false, 'error' => 'Failed to generate embedding');
        }

        $results = $this->searchSupabase($embedding, $nResults);
        if ($results === null) {
            return array('success' => false, 'error' => 'Failed to search Supabase');
        }

        return array('success' => true, 'results' => $results);
    }

    private function embedQuery($query) {
        $body = json_encode(array(
            'model'   => self::EMBED_MODEL,
            'content' => array('parts' => array(array('text' => $query))),
        ));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::EMBED_API_URL . '?key=' . urlencode($this->geminiApiKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log('[SearchService] Embed error HTTP ' . $httpCode . ': ' . $curlError);
            return null;
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['embedding']['values'])) {
            error_log('[SearchService] Unexpected embed response: ' . substr($response, 0, 300));
            return null;
        }

        return $decoded['embedding']['values'];
    }

    private function searchSupabase($embedding, $nResults) {
        $body = json_encode(array(
            'query_embedding' => '[' . implode(',', $embedding) . ']',
            'match_count'     => $nResults,
        ));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->supabaseRestUrl . '/rest/v1/rpc/search_product_catalog');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'apikey: ' . $this->supabaseKey,
            'Authorization: Bearer ' . $this->supabaseKey,
            'Content-Type: application/json',
        ));
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log('[SearchService] Supabase error HTTP ' . $httpCode . ' cURL: ' . $curlError . ' body: ' . $response);
            return null;
        }

        $rows = json_decode($response, true);
        if (!is_array($rows)) {
            error_log('[SearchService] Unexpected Supabase response: ' . substr($response, 0, 300));
            return null;
        }

        return array_map(function($row) { return $row['content']; }, $rows);
    }
}

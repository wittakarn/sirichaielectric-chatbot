<?php

/**
 * Product search against the OneElectric Supabase index (product IDs are shared
 * between the two shops): Gemini query embedding + RPC `search_products_all`, which
 * merges a pgvector leg and a keyword leg. Returns product IDs and names only —
 * prices come from Sirichai's own services (see ProductAPIService).
 */
class SearchService {

    const EMBED_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:batchEmbedContents';
    const EMBED_MODEL   = 'models/gemini-embedding-001';
    const DIMENSIONS    = 1536; // must match the index (oneelectric-chatbot/supabase/schema.sql)
    const SEARCH_RPC    = 'search_products_all'; // supabase/search_products_all.sql: no sales filter

    private $geminiApiKey;
    private $supabaseRestUrl;
    private $supabaseKey;

    public function __construct($geminiApiKey, $supabaseRestUrl, $supabaseKey) {
        $this->geminiApiKey    = $geminiApiKey;
        $this->supabaseRestUrl = rtrim($supabaseRestUrl, '/');
        $this->supabaseKey     = $supabaseKey;
    }

    /**
     * Single-query search (REST /search endpoint).
     * @return array { success, results?: [{product_id, name}], error? }
     */
    public function search($query) {
        $found = $this->searchMany(array($query));
        if ($found === null) {
            return array('success' => false, 'error' => 'Search failed');
        }
        return array('success' => true, 'results' => reset($found));
    }

    /**
     * One embed call for all queries, then every query's RPC in parallel.
     * @param string[] $queries
     * @return array|null query => ranked rows [{product_id, name}], or null when embedding failed
     */
    public function searchMany($queries) {
        $queries = array_values(array_unique($queries));
        $vectors = $this->embed($queries);
        if ($vectors === null) {
            return null;
        }

        $multi   = curl_multi_init();
        $handles = array();
        foreach ($queries as $i => $query) {
            $ch = curl_init($this->supabaseRestUrl . '/rest/v1/rpc/' . self::SEARCH_RPC);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('query_embedding' => $vectors[$i], 'query_text' => $query)));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'apikey: ' . $this->supabaseKey,
                'Authorization: Bearer ' . $this->supabaseKey,
                'Content-Type: application/json',
            ));
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi);
            }
        } while ($running && $status === CURLM_OK);

        $found = array();
        foreach ($handles as $i => $ch) {
            $rows = json_decode((string) curl_multi_getcontent($ch), true);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200 || !is_array($rows)) {
                error_log('[SearchService] Supabase error HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' for "' . $queries[$i] . '": ' . curl_error($ch) . ' ' . substr((string) curl_multi_getcontent($ch), 0, 300));
                $rows = array();
            }
            $found[$queries[$i]] = $rows;
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $found;
    }

    /** @return float[][]|null one vector per query, in order */
    private function embed($queries) {
        $requests = array();
        foreach ($queries as $query) {
            $requests[] = array(
                'model'                => self::EMBED_MODEL,
                'content'              => array('parts' => array(array('text' => $query))),
                'taskType'             => 'RETRIEVAL_QUERY',
                'outputDimensionality' => self::DIMENSIONS,
            );
        }

        $ch = curl_init(self::EMBED_API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('requests' => $requests)));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'x-goog-api-key: ' . $this->geminiApiKey));
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);
        if ($httpCode !== 200 || !isset($decoded['embeddings']) || count($decoded['embeddings']) !== count($queries)) {
            error_log('[SearchService] Embed error HTTP ' . $httpCode . ': ' . $curlError . ' ' . substr((string) $response, 0, 300));
            return null;
        }

        return array_map(function ($embedding) { return $embedding['values']; }, $decoded['embeddings']);
    }
}

<?php

/**
 * IngestService - Fetches catalog, generates Gemini embeddings, stores in Supabase pgvector.
 *
 * Flow (mirrors ingest.py logic):
 *   1. Fetch plain-text catalog from CATALOG_INGEST_URL
 *   2. SHA-256 hash check — skip if unchanged (unless $force=true)
 *   3. Chunk: one non-blank line per document (>10 chars)
 *   4. For each embed batch (100): call Gemini → immediately insert into Supabase via REST API
 *   6. Persist hash to .supabase_hash
 */
class IngestService {

    const EMBEDDING_MODEL   = 'models/gemini-embedding-001';
    const EMBEDDING_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:batchEmbedContents';
    const EMBEDDING_DIM     = 3072;
    const EMBED_BATCH_SIZE  = 100;
    const INSERT_BATCH_SIZE = 50;
    const MIN_LINE_LENGTH   = 10;
    const TABLE_NAME        = 'product_catalog';

    private $geminiApiKey;
    private $catalogUrl;
    private $supabaseRestUrl;
    private $supabaseKey;
    private $hashFile;

    public function __construct($geminiApiKey, $catalogUrl, $supabaseRestUrl, $supabaseKey) {
        $this->geminiApiKey    = $geminiApiKey;
        $this->catalogUrl      = $catalogUrl;
        $this->supabaseRestUrl = rtrim($supabaseRestUrl, '/');
        $this->supabaseKey     = $supabaseKey;
        $this->hashFile        = dirname(__DIR__) . '/.supabase_hash';
    }

    /**
     * Run the full ingest pipeline.
     * @param bool $force Skip hash check and always re-ingest
     * @return array { success, status, documents?, message, error? }
     */
    public function run($force = false) {
        error_log('[IngestService] Starting ingest (force=' . ($force ? 'true' : 'false') . ')');

        $content = $this->fetchContent();
        if ($content === null) {
            return array('success' => false, 'error' => 'Failed to fetch catalog from ' . $this->catalogUrl);
        }

        $currentHash = hash('sha256', $content);
        $storedHash  = $this->loadStoredHash();

        if (!$force && $currentHash === $storedHash) {
            error_log('[IngestService] Content unchanged — skipping.');
            return array(
                'success' => true,
                'status'  => 'unchanged',
                'message' => 'Content unchanged — skipping ingestion.',
            );
        }

        $chunks = $this->chunkContent($content);
        error_log('[IngestService] Ingesting ' . count($chunks) . ' documents...');

        $error = $this->embedAndStore($chunks);
        if ($error !== null) {
            return array('success' => false, 'error' => $error);
        }

        $this->saveHash($currentHash);
        error_log('[IngestService] Done — ' . count($chunks) . ' documents ingested.');

        return array(
            'success'   => true,
            'status'    => 'ingested',
            'documents' => count($chunks),
            'message'   => 'Ingested ' . count($chunks) . ' documents.',
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function fetchContent() {
        error_log('[IngestService] Fetching catalog from ' . $this->catalogUrl);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->catalogUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[IngestService] cURL error: ' . $curlError);
            return null;
        }
        if ($httpCode !== 200) {
            error_log('[IngestService] HTTP ' . $httpCode . ' from catalog URL');
            return null;
        }
        error_log('[IngestService] Fetched ' . strlen($response) . ' bytes');
        return $response;
    }

    private function chunkContent($content) {
        $chunks = array();
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (strlen($line) > self::MIN_LINE_LENGTH) {
                $chunks[] = $line;
            }
        }
        return $chunks;
    }

    /**
     * Embed each batch and immediately insert into Supabase.
     * Keeps only one batch in memory at a time.
     * @return string|null Error message, or null on success
     */
    private function embedAndStore($chunks) {
        // Clear existing data before inserting
        $error = $this->supabaseDeleteAll();
        if ($error !== null) {
            return $error;
        }

        $batches = array_chunk($chunks, self::EMBED_BATCH_SIZE, true);
        $total   = count($batches);

        foreach ($batches as $batchIndex => $batch) {
            $batchNum = $batchIndex + 1;
            error_log('[IngestService] Batch ' . $batchNum . '/' . $total . ' — embedding ' . count($batch) . ' items');

            // 1. Build Gemini embedding request
            $requests = array();
            foreach ($batch as $text) {
                $requests[] = array(
                    'model'   => self::EMBEDDING_MODEL,
                    'content' => array('parts' => array(array('text' => $text))),
                );
            }

            $body = json_encode(array('requests' => $requests));
            $url  = self::EMBEDDING_API_URL . '?key=' . urlencode($this->geminiApiKey);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                return 'Embedding cURL error: ' . $curlError;
            }
            if ($httpCode !== 200) {
                return 'Embedding HTTP ' . $httpCode . ': ' . substr($response, 0, 300);
            }

            $decoded = json_decode($response, true);
            if (!isset($decoded['embeddings'])) {
                return 'Unexpected embedding response: ' . substr($response, 0, 300);
            }

            // 2. Build rows and insert into Supabase
            $texts = array_values($batch);
            $rows  = array();
            foreach ($decoded['embeddings'] as $j => $emb) {
                $globalIndex = $batchIndex * self::EMBED_BATCH_SIZE + $j;
                $rows[] = array(
                    'id'        => 'doc_' . $globalIndex,
                    'content'   => $texts[$j],
                    'embedding' => '[' . implode(',', $emb['values']) . ']',
                );
            }

            // Insert in sub-batches to keep request size manageable
            foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $insertBatch) {
                $error = $this->supabaseInsert($insertBatch);
                if ($error !== null) {
                    return $error;
                }
            }

            unset($decoded, $response, $body, $requests, $rows);
        }

        error_log('[IngestService] Stored ' . count($chunks) . ' rows in Supabase');
        return null;
    }

    private function supabaseDeleteAll() {
        $url = $this->supabaseRestUrl . '/rest/v1/' . self::TABLE_NAME . '?id=not.is.null';
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->supabaseHeaders());
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return 'Supabase DELETE cURL error: ' . $curlError;
        }
        if ($httpCode >= 300) {
            return 'Supabase DELETE HTTP ' . $httpCode . ': ' . substr($response, 0, 300);
        }
        error_log('[IngestService] Cleared existing rows');
        return null;
    }

    private function supabaseInsert($rows) {
        $url  = $this->supabaseRestUrl . '/rest/v1/' . self::TABLE_NAME;
        $body = json_encode($rows);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->supabaseHeaders());
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return 'Supabase INSERT cURL error: ' . $curlError;
        }
        if ($httpCode >= 300) {
            return 'Supabase INSERT HTTP ' . $httpCode . ': ' . substr($response, 0, 300);
        }
        return null;
    }

    private function supabaseHeaders() {
        return array(
            'apikey: ' . $this->supabaseKey,
            'Authorization: Bearer ' . $this->supabaseKey,
            'Content-Type: application/json',
            'Prefer: return=minimal',
        );
    }

    private function loadStoredHash() {
        if (!file_exists($this->hashFile)) {
            return null;
        }
        return trim(file_get_contents($this->hashFile));
    }

    private function saveHash($hash) {
        file_put_contents($this->hashFile, $hash);
    }
}

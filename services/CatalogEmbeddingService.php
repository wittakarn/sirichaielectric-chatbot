<?php
/**
 * RAG service: embeds catalog categories into MySQL VECTOR column,
 * then retrieves the top-K most relevant categories for a user query.
 *
 * Requires MySQL 9.0+ (VECTOR type, DISTANCE() function, STRING_TO_VECTOR()).
 * text-embedding-004 outputs L2-normalised 768-dim vectors, so L2 distance
 * and cosine similarity produce identical ranking — no metric flag needed.
 */
class CatalogEmbeddingService {

    private $pdo;
    private $apiKey;
    private $topK;

    const EMBED_URL = 'https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key=';
    const EMBED_DIM = 768;

    public function __construct($pdo, $apiKey, $topK = 5) {
        $this->pdo    = $pdo;
        $this->apiKey = $apiKey;
        $this->topK   = (int) $topK;
    }

    /**
     * Return the top-K catalog category names closest to the user query.
     * Falls back to an empty array if the embedding table is empty or
     * the API call fails — the chatbot will operate without RAG context.
     *
     * @param  string $query  Raw user message
     * @return string[]       Exact category name strings
     */
    public function getRelevantCategories($query) {
        try {
            $vector = $this->embedText($query, 'RETRIEVAL_QUERY');
            return $this->vectorSearch($vector, $this->topK);
        } catch (Exception $e) {
            error_log('[RAG] getRelevantCategories failed: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Embed every category in $catalogText and upsert into catalog_embeddings.
     * Skips rows whose content_hash has not changed to avoid redundant API calls.
     *
     * @param  string $catalogText  Raw catalog text (one category name per line)
     * @return int                  Number of rows inserted / updated
     */
    public function syncCatalog($catalogText) {
        $categories = $this->parseCategories($catalogText);
        if (empty($categories)) {
            error_log('[RAG] syncCatalog: no categories parsed from catalog text');
            return 0;
        }

        $synced = 0;
        foreach ($categories as $name) {
            $hash = md5($name);

            $stmt = $this->pdo->prepare(
                'SELECT content_hash FROM catalog_embeddings WHERE category_name = ?'
            );
            $stmt->execute(array($name));
            $existing = $stmt->fetchColumn();

            if ($existing === $hash) {
                continue;
            }

            $vector    = $this->embedText($name, 'RETRIEVAL_DOCUMENT');
            $vectorStr = $this->vectorToString($vector);

            $stmt = $this->pdo->prepare(
                'INSERT INTO catalog_embeddings (category_name, content_hash, embedding)
                 VALUES (?, ?, STRING_TO_VECTOR(?))
                 ON DUPLICATE KEY UPDATE
                   content_hash = VALUES(content_hash),
                   embedding    = VALUES(embedding),
                   updated_at   = CURRENT_TIMESTAMP'
            );
            $stmt->execute(array($name, $hash, $vectorStr));
            $synced++;
        }

        error_log('[RAG] syncCatalog: synced ' . $synced . ' of ' . count($categories) . ' categories');
        return $synced;
    }

    /**
     * Call Gemini text-embedding-004 and return the 768-dim float array.
     *
     * @param  string $text
     * @param  string $taskType  RETRIEVAL_DOCUMENT | RETRIEVAL_QUERY
     * @return float[]
     */
    public function embedText($text, $taskType = 'RETRIEVAL_QUERY') {
        $body = json_encode(array(
            'model'    => 'models/text-embedding-004',
            'content'  => array('parts' => array(array('text' => $text))),
            'taskType' => $taskType,
        ), JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::EMBED_URL . $this->apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('Embedding API cURL error: ' . $curlErr);
        }
        if ($httpCode !== 200) {
            throw new Exception('Embedding API HTTP ' . $httpCode . ': ' . substr($response, 0, 200));
        }

        $data   = json_decode($response, true);
        $values = isset($data['embedding']['values']) ? $data['embedding']['values'] : null;

        if (!is_array($values) || count($values) !== self::EMBED_DIM) {
            throw new Exception('Unexpected embedding response (dim=' . (is_array($values) ? count($values) : 'null') . ')');
        }

        return $values;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Run an exact KNN query against catalog_embeddings.
     * MySQL 9.2 HNSW ANN index is used automatically when present.
     */
    private function vectorSearch(array $vector, $topK) {
        $vectorStr = $this->vectorToString($vector);

        $stmt = $this->pdo->prepare(
            'SELECT category_name
             FROM catalog_embeddings
             ORDER BY DISTANCE(embedding, STRING_TO_VECTOR(?))
             LIMIT ' . (int) $topK
        );
        $stmt->execute(array($vectorStr));
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Encode a float array as a JSON-style vector string accepted by
     * MySQL STRING_TO_VECTOR(): "[f1,f2,...,f768]"
     */
    private function vectorToString(array $vector) {
        return '[' . implode(',', array_map(function ($v) {
            return rtrim(rtrim(sprintf('%.8f', $v), '0'), '.');
        }, $vector)) . ']';
    }

    /**
     * Parse the raw catalog text into an array of non-empty category name strings.
     * Skips markdown headers (lines starting with #) and blank lines.
     */
    private function parseCategories($catalogText) {
        $lines = explode("\n", $catalogText);
        $out   = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }
}

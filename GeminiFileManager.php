<?php
/**
 * Gemini File Manager
 * Handles file uploads to Gemini File API and manages file lifecycle
 * Files expire after 48 hours and need to be re-uploaded
 * PHP 5.6 compatible
 */

class GeminiFileManager {
    private $apiKey;
    private $cacheFile;
    private $cache;

    /**
     * @param string $apiKey Gemini API key
     * @param string $cacheFile Path to JSON file for storing file metadata
     */
    public function __construct($apiKey, $cacheFile = null) {
        $this->apiKey = $apiKey;
        $this->cacheFile = $cacheFile ?: __DIR__ . '/file-cache.json';
        $this->loadCache();
    }

    /**
     * Load file cache from disk
     */
    private function loadCache() {
        if (file_exists($this->cacheFile)) {
            $json = file_get_contents($this->cacheFile);
            $this->cache = json_decode($json, true);
            if (!is_array($this->cache)) {
                $this->cache = array();
            }
        } else {
            $this->cache = array();
        }
    }

    /**
     * Save file cache to disk
     */
    private function saveCache() {
        $json = json_encode($this->cache, JSON_PRETTY_PRINT);
        file_put_contents($this->cacheFile, $json);
    }

    /**
     * Upload a text file to Gemini File API
     * @param string $content Text content to upload
     * @param string $displayName Display name for the file
     * @return array ['success' => bool, 'fileUri' => string, 'name' => string, 'error' => string]
     */
    public function uploadTextFile($content, $displayName) {
        $url = "https://generativelanguage.googleapis.com/upload/v1beta/files?key={$this->apiKey}";

        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'gemini_');
        file_put_contents($tempFile, $content);

        // Prepare metadata
        $metadata = array(
            'file' => array(
                'display_name' => $displayName
            )
        );

        $boundary = '----GeminiFileBoundary' . uniqid();

        // Build multipart body
        $body = '';

        // Add metadata part
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n";

        // Add file part
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--{$boundary}--\r\n";

        // Make upload request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: multipart/related; boundary=' . $boundary,
            'X-Goog-Upload-Protocol: multipart'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Clean up temp file
        unlink($tempFile);

        if ($response === false) {
            error_log('[FileManager] Upload failed: ' . $error);
            return array(
                'success' => false,
                'error' => 'cURL error: ' . $error
            );
        }

        if ($httpCode !== 200) {
            error_log('[FileManager] Upload HTTP error ' . $httpCode . ': ' . $response);
            return array(
                'success' => false,
                'error' => 'HTTP ' . $httpCode . ': ' . substr($response, 0, 200)
            );
        }

        $data = json_decode($response, true);

        if (!isset($data['file']['uri']) || !isset($data['file']['name'])) {
            error_log('[FileManager] Invalid response: ' . $response);
            return array(
                'success' => false,
                'error' => 'Invalid API response'
            );
        }

        error_log('[FileManager] Uploaded: ' . $data['file']['name'] . ' (' . strlen($content) . ' bytes)');

        return array(
            'success' => true,
            'fileUri' => $data['file']['uri'],
            'name' => $data['file']['name'],
            'displayName' => $displayName,
            'uploadedAt' => time()
        );
    }

    /**
     * Get or upload a file with caching
     * @param string $cacheKey Unique key for this file in cache
     * @param string $content Content to upload if not cached
     * @param string $displayName Display name for the file
     * @param int $maxAge Maximum age in seconds (default 46 hours to refresh before 48h expiry)
     * @return array ['success' => bool, 'fileUri' => string, 'name' => string, 'error' => string]
     */
    public function getOrUploadFile($cacheKey, $content, $displayName, $maxAge = 165600) {
        $contentHash = substr(md5($content), 0, 8);
        $contentSize = strlen($content);

        // Check cache
        if (isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            $age = time() - $cached['uploadedAt'];
            $ageHours = round($age / 3600, 1);
            $ageMinutes = round($age / 60, 1);

            if ($age < $maxAge) {
                // Check if content changed
                $cachedHash = isset($cached['contentHash']) ? $cached['contentHash'] : 'unknown';
                if ($cachedHash !== 'unknown' && $cachedHash !== $contentHash) {
                    error_log('[FileManager] ⚠️  Cache MISS: Content changed for "' . $displayName . '" (hash: ' . $cachedHash . ' -> ' . $contentHash . ')');
                } else {
                    $timeDisplay = $ageHours >= 1 ? $ageHours . 'h' : $ageMinutes . 'm';
                    error_log('[FileManager] ✓ Cache HIT: "' . $displayName . '" (age: ' . $timeDisplay . ', size: ' . number_format($contentSize) . ' bytes, hash: ' . $contentHash . ')');
                    return array(
                        'success' => true,
                        'fileUri' => $cached['fileUri'],
                        'name' => $cached['name'],
                        'displayName' => $cached['displayName'],
                        'cached' => true
                    );
                }
            } else {
                error_log('[FileManager] ⚠️  Cache MISS: Expired for "' . $displayName . '" (age: ' . $ageHours . 'h, max: ' . round($maxAge / 3600, 1) . 'h)');
            }
        } else {
            error_log('[FileManager] ⚠️  Cache MISS: No cache entry for "' . $displayName . '"');
        }

        // Upload new file
        error_log('[FileManager] ⬆️  Uploading: "' . $displayName . '" (' . number_format($contentSize) . ' bytes, hash: ' . $contentHash . ')');
        $result = $this->uploadTextFile($content, $displayName);

        if ($result['success']) {
            // Cache the result with content hash
            $this->cache[$cacheKey] = array(
                'fileUri' => $result['fileUri'],
                'name' => $result['name'],
                'displayName' => $result['displayName'],
                'uploadedAt' => $result['uploadedAt'],
                'contentHash' => $contentHash,
                'contentSize' => $contentSize
            );
            $this->saveCache();
            error_log('[FileManager] ✓ Cached: "' . $displayName . '" as ' . $cacheKey);
        }

        return $result;
    }

    /**
     * Delete a file from Gemini
     * @param string $fileName The file name (not URI) returned from upload
     * @return array ['success' => bool, 'error' => string]
     */
    public function deleteFile($fileName) {
        $url = "https://generativelanguage.googleapis.com/v1beta/files/{$fileName}?key={$this->apiKey}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return array('success' => false, 'error' => 'cURL error: ' . $error);
        }

        if ($httpCode !== 200 && $httpCode !== 204) {
            return array('success' => false, 'error' => 'HTTP ' . $httpCode);
        }

        error_log('[FileManager] Deleted: ' . $fileName);
        return array('success' => true);
    }

    /**
     * Get file metadata
     * @param string $fileName The file name (not URI)
     * @return array ['success' => bool, 'data' => array, 'error' => string]
     */
    public function getFileInfo($fileName) {
        $url = "https://generativelanguage.googleapis.com/v1beta/files/{$fileName}?key={$this->apiKey}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return array('success' => false, 'error' => 'HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);
        return array('success' => true, 'data' => $data);
    }

    /**
     * Clear all cached file references
     */
    public function clearCache() {
        $this->cache = array();
        $this->saveCache();
        error_log('[FileManager] Cache cleared');
    }

    /**
     * Get all cached files
     * @return array
     */
    public function getCachedFiles() {
        return $this->cache;
    }

    /**
     * List all files from Gemini API
     * @return array ['success' => bool, 'files' => array, 'error' => string]
     */
    public function listAllFiles() {
        $url = "https://generativelanguage.googleapis.com/v1beta/files?key={$this->apiKey}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return array('success' => false, 'error' => 'HTTP ' . $httpCode);
        }

        $data = json_decode($response, true);

        if (!isset($data['files'])) {
            return array('success' => true, 'files' => array());
        }

        return array('success' => true, 'files' => $data['files']);
    }

    /**
     * Delete all files from Gemini API
     * @return array ['success' => bool, 'deleted' => int, 'failed' => int, 'errors' => array]
     */
    public function deleteAllFiles() {
        $listResult = $this->listAllFiles();

        if (!$listResult['success']) {
            return array(
                'success' => false,
                'deleted' => 0,
                'failed' => 0,
                'errors' => array('Failed to list files: ' . $listResult['error'])
            );
        }

        $files = $listResult['files'];
        $deleted = 0;
        $failed = 0;
        $errors = array();

        foreach ($files as $file) {
            $result = $this->deleteFile($file['name']);
            if ($result['success']) {
                $deleted++;
            } else {
                $failed++;
                $errors[] = $file['name'] . ': ' . $result['error'];
            }
        }

        // Clear cache after deleting
        if ($deleted > 0) {
            $this->clearCache();
        }

        return array(
            'success' => ($failed === 0),
            'deleted' => $deleted,
            'failed' => $failed,
            'errors' => $errors
        );
    }
}

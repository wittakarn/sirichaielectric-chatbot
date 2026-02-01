<?php
/**
 * Cache Clear Service
 * Deletes all files in the cache folder to force regeneration
 *
 * Usage: GET /services/CacheClearService.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

/**
 * Send JSON response and exit
 */
function sendResponse($success, $message, $data = null) {
    $response = array(
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    );

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Log message to file
 */
function logMessage($message, $level = 'INFO') {
    $logFile = __DIR__ . '/../logs.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] [CacheClearService] {$message}\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

try {
    // Only accept GET or POST requests
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'GET' && $method !== 'POST') {
        sendResponse(false, 'Only GET or POST methods are allowed');
    }

    // Define cache folder path
    $cacheFolder = __DIR__ . '/../cache';

    // Check if cache folder exists
    if (!is_dir($cacheFolder)) {
        logMessage("Cache folder does not exist: {$cacheFolder}", 'ERROR');
        sendResponse(false, 'Cache folder does not exist');
    }

    // Get all files in the cache folder
    $files = scandir($cacheFolder);
    $deletedFiles = array();
    $failedFiles = array();
    $totalSize = 0;

    foreach ($files as $file) {
        // Skip . and ..
        if ($file === '.' || $file === '..') {
            continue;
        }

        $filePath = $cacheFolder . '/' . $file;

        // Only delete files, not directories
        if (is_file($filePath)) {
            $fileSize = filesize($filePath);
            $totalSize = $totalSize + $fileSize;

            if (unlink($filePath)) {
                $deletedFiles[] = array(
                    'name' => $file,
                    'size_kb' => round($fileSize / 1024, 2)
                );
                logMessage("Deleted: {$file} (Size: {$fileSize} bytes)", 'SUCCESS');
            } else {
                $failedFiles[] = $file;
                logMessage("Failed to delete: {$file}", 'ERROR');
            }
        }
    }

    // Prepare response
    if (count($failedFiles) > 0) {
        sendResponse(false, 'Some files could not be deleted', array(
            'deleted_count' => count($deletedFiles),
            'failed_count' => count($failedFiles),
            'deleted_files' => $deletedFiles,
            'failed_files' => $failedFiles,
            'total_size_kb' => round($totalSize / 1024, 2)
        ));
    } else if (count($deletedFiles) === 0) {
        logMessage("No cache files found to delete", 'INFO');
        sendResponse(true, 'No cache files found (cache already empty)', array(
            'deleted_count' => 0
        ));
    } else {
        logMessage("Successfully deleted " . count($deletedFiles) . " cache file(s)", 'SUCCESS');
        sendResponse(true, 'Cache cleared successfully', array(
            'deleted_count' => count($deletedFiles),
            'deleted_files' => $deletedFiles,
            'total_size_kb' => round($totalSize / 1024, 2)
        ));
    }

} catch (Exception $e) {
    logMessage("Error: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    sendResponse(false, 'Error: ' . $e->getMessage());
}
?>

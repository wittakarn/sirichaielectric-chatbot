<?php
/**
 * Cleanup utility for Gemini File API
 * Lists and optionally deletes all uploaded files
 *
 * Usage:
 *   php cleanup-files.php list          - List all files
 *   php cleanup-files.php delete-all    - Delete all files (requires confirmation)
 *   php cleanup-files.php delete <name> - Delete specific file
 */

require_once __DIR__ . '/GeminiFileManager.php';

// Configuration
$apiKey = getenv('GEMINI_API_KEY') ?: 'YOUR_API_KEY_HERE';

if ($apiKey === 'YOUR_API_KEY_HERE') {
    echo "ERROR: Please set GEMINI_API_KEY environment variable\n";
    echo "Example: export GEMINI_API_KEY='your-key-here'\n";
    exit(1);
}

$fileManager = new GeminiFileManager($apiKey);

// Parse command
$command = isset($argv[1]) ? $argv[1] : 'list';
$arg = isset($argv[2]) ? $argv[2] : null;

/**
 * List all files from Gemini API
 */
function listAllFiles($apiKey) {
    $url = "https://generativelanguage.googleapis.com/v1beta/files?key={$apiKey}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "ERROR: Failed to list files (HTTP {$httpCode})\n";
        echo "Response: {$response}\n";
        return array();
    }

    $data = json_decode($response, true);

    if (!isset($data['files'])) {
        return array();
    }

    return $data['files'];
}

/**
 * Format file size
 */
function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 2) . ' KB';
    return round($bytes / (1024 * 1024), 2) . ' MB';
}

/**
 * Format timestamp
 */
function formatTime($timestamp) {
    $time = strtotime($timestamp);
    $age = time() - $time;

    if ($age < 3600) {
        return round($age / 60) . ' minutes ago';
    }
    if ($age < 86400) {
        return round($age / 3600, 1) . ' hours ago';
    }
    return round($age / 86400, 1) . ' days ago';
}

switch ($command) {
    case 'list':
        echo "=== Listing All Uploaded Files ===\n\n";

        $files = listAllFiles($apiKey);

        if (empty($files)) {
            echo "No files found.\n";
            break;
        }

        echo "Found " . count($files) . " file(s):\n\n";

        foreach ($files as $i => $file) {
            $num = $i + 1;
            $name = $file['name'];
            $displayName = isset($file['displayName']) ? $file['displayName'] : 'N/A';
            $size = isset($file['sizeBytes']) ? formatSize($file['sizeBytes']) : 'N/A';
            $created = isset($file['createTime']) ? formatTime($file['createTime']) : 'N/A';
            $expires = isset($file['expirationTime']) ? formatTime($file['expirationTime']) : 'N/A';
            $mimeType = isset($file['mimeType']) ? $file['mimeType'] : 'N/A';

            echo "{$num}. {$displayName}\n";
            echo "   Name: {$name}\n";
            echo "   Size: {$size}\n";
            echo "   Type: {$mimeType}\n";
            echo "   Created: {$created}\n";
            echo "   Expires: {$expires}\n";
            echo "   URI: {$file['uri']}\n";
            echo "\n";
        }

        echo "To delete all files: php cleanup-files.php delete-all\n";
        echo "To delete specific file: php cleanup-files.php delete <file-name>\n";
        break;

    case 'delete':
        if (!$arg) {
            echo "ERROR: Please provide file name to delete\n";
            echo "Usage: php cleanup-files.php delete <file-name>\n";
            echo "Example: php cleanup-files.php delete files/abc123\n";
            exit(1);
        }

        echo "Deleting file: {$arg}\n";
        $result = $fileManager->deleteFile($arg);

        if ($result['success']) {
            echo "✓ File deleted successfully\n";
        } else {
            echo "✗ Failed to delete file: " . $result['error'] . "\n";
            exit(1);
        }
        break;

    case 'delete-all':
        echo "=== Delete All Files ===\n\n";

        $files = listAllFiles($apiKey);

        if (empty($files)) {
            echo "No files to delete.\n";
            break;
        }

        echo "Found " . count($files) . " file(s):\n";
        foreach ($files as $file) {
            echo "  - " . (isset($file['displayName']) ? $file['displayName'] : $file['name']) . "\n";
        }

        echo "\nWARNING: This will delete ALL uploaded files!\n";
        echo "Are you sure? Type 'yes' to confirm: ";

        $handle = fopen("php://stdin", "r");
        $confirmation = trim(fgets($handle));
        fclose($handle);

        if (strtolower($confirmation) !== 'yes') {
            echo "Cancelled.\n";
            exit(0);
        }

        echo "\nDeleting files...\n";
        $deleted = 0;
        $failed = 0;

        foreach ($files as $file) {
            $name = $file['name'];
            $displayName = isset($file['displayName']) ? $file['displayName'] : $name;

            echo "  Deleting: {$displayName}... ";
            $result = $fileManager->deleteFile($name);

            if ($result['success']) {
                echo "✓\n";
                $deleted++;
            } else {
                echo "✗ ({$result['error']})\n";
                $failed++;
            }
        }

        echo "\nSummary:\n";
        echo "  Deleted: {$deleted}\n";
        echo "  Failed: {$failed}\n";

        // Also clear local cache
        echo "\nClearing local cache...\n";
        $fileManager->clearCache();
        echo "✓ Cache cleared\n";
        break;

    case 'clear-cache':
        echo "=== Clearing Local Cache ===\n\n";
        $fileManager->clearCache();
        echo "✓ Cache cleared\n";
        echo "\nNote: This only clears the local cache file.\n";
        echo "Files on Gemini servers remain until you delete them or they expire.\n";
        break;

    case 'help':
    case '--help':
    case '-h':
        echo "Gemini File API Cleanup Utility\n\n";
        echo "Usage:\n";
        echo "  php cleanup-files.php list              - List all uploaded files\n";
        echo "  php cleanup-files.php delete-all        - Delete all files (with confirmation)\n";
        echo "  php cleanup-files.php delete <name>     - Delete specific file\n";
        echo "  php cleanup-files.php clear-cache       - Clear local cache only\n";
        echo "  php cleanup-files.php help              - Show this help\n\n";
        echo "Examples:\n";
        echo "  php cleanup-files.php list\n";
        echo "  php cleanup-files.php delete files/abc123xyz\n";
        echo "  php cleanup-files.php delete-all\n\n";
        echo "Important Notes:\n";
        echo "  - Files auto-expire after 48 hours\n";
        echo "  - Gemini File API is FREE (no storage charges)\n";
        echo "  - You have 20GB total storage quota per project\n";
        echo "  - Deleting files frees up quota immediately\n";
        break;

    default:
        echo "Unknown command: {$command}\n";
        echo "Run 'php cleanup-files.php help' for usage information\n";
        exit(1);
}

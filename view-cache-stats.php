<?php
/**
 * View File API Cache Statistics
 * Shows what files are cached and their status
 */

$cacheFile = __DIR__ . '/file-cache.json';

echo "=== File API Cache Statistics ===\n\n";

if (!file_exists($cacheFile)) {
    echo "No cache file found.\n";
    exit;
}

$cache = json_decode(file_get_contents($cacheFile), true);

if (empty($cache)) {
    echo "Cache is empty.\n";
    exit;
}

echo "Total cached files: " . count($cache) . "\n\n";

foreach ($cache as $key => $file) {
    $age = time() - $file['uploadedAt'];
    $ageHours = round($age / 3600, 1);
    $ageMinutes = round($age / 60, 1);
    $timeDisplay = $ageHours >= 1 ? $ageHours . ' hours' : $ageMinutes . ' minutes';

    $expiresIn = 172800 - $age; // 48 hours in seconds
    $expiresHours = round($expiresIn / 3600, 1);

    echo "File: " . $file['displayName'] . "\n";
    echo "  Key: " . $key . "\n";
    echo "  URI: " . $file['fileUri'] . "\n";
    echo "  Name: " . $file['name'] . "\n";
    echo "  Uploaded: " . date('Y-m-d H:i:s', $file['uploadedAt']) . "\n";
    echo "  Age: " . $timeDisplay . "\n";
    echo "  Expires in: " . $expiresHours . " hours\n";

    if (isset($file['contentHash'])) {
        echo "  Content Hash: " . $file['contentHash'] . "\n";
    }
    if (isset($file['contentSize'])) {
        echo "  Content Size: " . number_format($file['contentSize']) . " bytes\n";
    }

    echo "\n";
}

echo "=== End of Cache Stats ===\n";

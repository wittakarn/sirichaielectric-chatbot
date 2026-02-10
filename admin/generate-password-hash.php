<?php
/**
 * Password Hash Generator
 *
 * Utility script to generate password hashes for admin users
 * Usage: php generate-password-hash.php
 */

echo "=== Admin Password Hash Generator ===\n\n";

// Get password from command line or prompt
if (isset($argv[1]) && !empty($argv[1])) {
    $password = $argv[1];
} else {
    echo "Enter password: ";
    $password = trim(fgets(STDIN));
}

if (empty($password)) {
    echo "Error: Password cannot be empty\n";
    exit(1);
}

// Generate password hash
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\n";
echo "Password: " . str_repeat('*', strlen($password)) . "\n";
echo "Hash: {$hash}\n";
echo "\n";
echo "Add this to your .env or .env.local file:\n";
echo "ADMIN_PASSWORD_HASH={$hash}\n";
echo "\n";

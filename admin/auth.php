<?php
/**
 * Admin Authentication Utilities
 *
 * Provides session-based authentication for admin dashboard
 * - Login/logout functionality
 * - Session management
 * - Access control
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if admin is logged in
 *
 * @return bool True if logged in
 */
function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * Verify admin credentials
 *
 * @param string $username Username
 * @param string $password Password
 * @return bool True if credentials are valid
 */
function verifyAdminCredentials($username, $password) {
    require_once __DIR__ . '/../config.php';
    $config = Config::getInstance();

    // Get configured admin credentials
    $validUsername = $config->get('admin', 'username');
    $validPasswordHash = $config->get('admin', 'password_hash');

    // Verify username and password
    if ($username === $validUsername && password_verify($password, $validPasswordHash)) {
        return true;
    }

    return false;
}

/**
 * Login admin user
 *
 * @param string $username Username
 * @param string $password Password
 * @return bool True if login successful
 */
function adminLogin($username, $password) {
    if (verifyAdminCredentials($username, $password)) {
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_login_time'] = time();

        error_log("[Admin Auth] User '{$username}' logged in successfully");
        return true;
    }

    error_log("[Admin Auth] Failed login attempt for username: {$username}");
    return false;
}

/**
 * Logout admin user
 */
function adminLogout() {
    $username = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'unknown';

    // Clear all session variables
    $_SESSION = array();

    // Destroy session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }

    // Destroy session
    session_destroy();

    error_log("[Admin Auth] User '{$username}' logged out");
}

/**
 * Require admin authentication
 * Redirects to login page if not authenticated
 *
 * @param string $loginUrl Login page URL
 */
function requireAdminAuth($loginUrl = 'login.php') {
    if (!isAdminLoggedIn()) {
        header('Location: ' . $loginUrl);
        exit;
    }
}

/**
 * Get logged in admin username
 *
 * @return string|null Username or null if not logged in
 */
function getAdminUsername() {
    return isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : null;
}

/**
 * Generate password hash for admin user
 * Use this to generate hashed password for .env configuration
 *
 * @param string $password Plain text password
 * @return string Password hash
 */
function generatePasswordHash($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

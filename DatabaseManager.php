<?php
/**
 * DatabaseManager - Singleton PDO wrapper for MySQL connection management
 *
 * Handles database connections with:
 * - Connection pooling via singleton pattern
 * - UTF-8mb4 charset for Thai language and emoji support
 * - Proper error handling and logging
 * - Automatic reconnection on connection loss
 */
class DatabaseManager {
    private static $instance = null;
    private $pdo = null;
    private $config = array();

    /**
     * Private constructor to enforce singleton pattern
     *
     * @param array $config Database configuration (host, port, name, user, password, charset)
     * @throws Exception if connection fails
     */
    private function __construct($config) {
        $this->config = $config;

        try {
            $this->pdo = $this->createConnection();
            error_log('[DatabaseManager] Connected successfully to ' . $config['name']);
        } catch (PDOException $e) {
            error_log('[DatabaseManager] Connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get singleton instance of DatabaseManager
     *
     * @param array $config Database configuration (required on first call)
     * @return DatabaseManager
     * @throws Exception if config not provided on first call
     */
    public static function getInstance($config = null) {
        if (self::$instance === null) {
            if ($config === null) {
                throw new Exception('Database configuration required for first initialization');
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Get PDO connection instance
     *
     * @return PDO
     */
    public function getConnection() {
        // Check if connection is still alive
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            error_log('[DatabaseManager] Connection lost, reconnecting...');
            $this->reconnect();
        }

        return $this->pdo;
    }

    /**
     * Create new PDO connection
     *
     * @return PDO
     * @throws PDOException if connection fails
     */
    private function createConnection() {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            isset($this->config['port']) ? $this->config['port'] : 3306,
            $this->config['name'],
            isset($this->config['charset']) ? $this->config['charset'] : 'utf8mb4'
        );

        $options = array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        return new PDO(
            $dsn,
            $this->config['user'],
            $this->config['password'],
            $options
        );
    }

    /**
     * Test database connection
     *
     * @return bool True if connection is working
     */
    public function testConnection() {
        try {
            $stmt = $this->pdo->query('SELECT 1');
            return $stmt !== false;
        } catch (PDOException $e) {
            error_log('[DatabaseManager] Connection test failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reconnect to database
     *
     * @throws Exception if reconnection fails
     */
    private function reconnect() {
        try {
            $this->pdo = $this->createConnection();
            error_log('[DatabaseManager] Reconnected successfully');
        } catch (PDOException $e) {
            error_log('[DatabaseManager] Reconnection failed: ' . $e->getMessage());
            throw new Exception('Database reconnection failed: ' . $e->getMessage());
        }
    }
}

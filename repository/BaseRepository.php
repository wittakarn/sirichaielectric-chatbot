<?php
/**
 * Base Repository - Abstract base class for all repositories
 * Provides common database operations using PDO
 * PHP 5.6 compatible
 */

abstract class BaseRepository {
    /** @var PDO */
    protected $db;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection instance
     */
    public function __construct(PDO $pdo) {
        $this->db = $pdo;
    }

    /**
     * Execute a query and return statement
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return PDOStatement
     * @throws PDOException
     */
    protected function query($sql, $params = array()) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch single row
     *
     * @param string $sql SQL query
     * @param array $params Parameters to bind
     * @return array|null
     */
    protected function fetchOne($sql, $params = array()) {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /**
     * Fetch all rows
     *
     * @param string $sql SQL query
     * @param array $params Parameters to bind
     * @return array
     */
    protected function fetchAll($sql, $params = array()) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch single column value
     *
     * @param string $sql SQL query
     * @param array $params Parameters to bind
     * @return mixed
     */
    protected function fetchColumn($sql, $params = array()) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }

    /**
     * Execute query and return affected row count
     *
     * @param string $sql SQL query
     * @param array $params Parameters to bind
     * @return int Number of affected rows
     */
    protected function execute($sql, $params = array()) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Get last inserted ID
     *
     * @return string
     */
    protected function lastInsertId() {
        return $this->db->lastInsertId();
    }

    /**
     * Begin transaction
     *
     * @return bool
     */
    public function beginTransaction() {
        return $this->db->beginTransaction();
    }

    /**
     * Commit transaction
     *
     * @return bool
     */
    public function commit() {
        return $this->db->commit();
    }

    /**
     * Rollback transaction
     *
     * @return bool
     */
    public function rollback() {
        return $this->db->rollBack();
    }
}

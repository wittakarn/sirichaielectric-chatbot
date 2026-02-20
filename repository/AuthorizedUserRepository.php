<?php
/**
 * Authorized User Repository - Manages users permitted to generate quotations
 * PHP 5.6 compatible
 */

require_once __DIR__ . '/BaseRepository.php';

class AuthorizedUserRepository extends BaseRepository {

    /**
     * Check if a user ID is authorized to generate quotations
     *
     * @param string $userId LINE user ID or internal user identifier
     * @return bool True if authorized, false otherwise
     */
    public function isAuthorized($userId) {
        $sql = "SELECT COUNT(*) FROM authorized_users WHERE INSTR(?, user_id) > 0";
        return (int) $this->fetchColumn($sql, array($userId)) > 0;
    }
}

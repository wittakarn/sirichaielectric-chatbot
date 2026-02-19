-- Migration: Create authorized_users table
-- Purpose: Store LINE user IDs authorized to generate quotations
-- Date: 2026-02-19

CREATE TABLE IF NOT EXISTS `authorized_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'LINE user ID or internal user identifier',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Display name for reference',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Users authorized to generate quotation PDFs';

-- Insert fixed test user for integration tests
-- This user is used by test-chatbot-with-history.php
INSERT IGNORE INTO `authorized_users` (`user_id`, `name`) VALUES ('Ucaba7f422b7205234c96c2e52531653e', 'Pou');

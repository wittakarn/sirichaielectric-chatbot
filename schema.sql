-- SirichaiElectric Chatbot Database Schema
-- This schema supports persistent conversation storage with token tracking
-- Run this file: mysql -u your_user -p sirichaielectric_chatbot < schema.sql

-- Create conversations table
CREATE TABLE IF NOT EXISTS conversations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(100) NOT NULL UNIQUE,
    platform VARCHAR(20) NOT NULL DEFAULT 'api' COMMENT 'api or line',
    user_id VARCHAR(100) NULL COMMENT 'LINE user ID for line platform',
    max_messages_limit INT NOT NULL DEFAULT 50,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_platform (platform),
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create messages table
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id VARCHAR(100) NOT NULL,
    role ENUM('user', 'assistant') NOT NULL,
    content TEXT NOT NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tokens_used INT NOT NULL DEFAULT 0 COMMENT 'Gemini API tokens for this message',
    sequence_number INT NOT NULL COMMENT 'Message order within conversation',
    CONSTRAINT fk_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations(conversation_id)
        ON DELETE CASCADE,
    INDEX idx_conversation_id (conversation_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_sequence (conversation_id, sequence_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verification queries (run these to verify schema)
-- SHOW TABLES;
-- DESCRIBE conversations;
-- DESCRIBE messages;
-- SHOW INDEX FROM conversations;
-- SHOW INDEX FROM messages;

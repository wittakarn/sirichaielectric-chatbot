-- Migration: Add user_display_name column to conversations table
-- Purpose: Store LINE user's display name for better admin dashboard UX
-- Date: 2026-01-29

-- Add user_display_name column
ALTER TABLE conversations
ADD COLUMN user_display_name VARCHAR(255) NULL AFTER user_id;

-- Add index for faster lookups
CREATE INDEX idx_user_display_name ON conversations(user_display_name);

-- Update existing records with placeholder (optional)
-- UPDATE conversations SET user_display_name = 'Unknown' WHERE user_id IS NOT NULL AND user_display_name IS NULL;

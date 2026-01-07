-- Add subscription columns to users table
-- Run this SQL to add subscription functionality

-- MySQL/MariaDB
ALTER TABLE `users` 
ADD COLUMN `has_subscribed` BOOLEAN DEFAULT FALSE AFTER `telegram_connected`,
ADD COLUMN `subscribed_at` TIMESTAMP NULL DEFAULT NULL AFTER `has_subscribed`;

-- Verify columns were added
DESCRIBE `users`;

-- Or check specific columns
SHOW COLUMNS FROM `users` LIKE 'has_subscribed';
SHOW COLUMNS FROM `users` LIKE 'subscribed_at';

-- ============================================
-- PostgreSQL version (if using PostgreSQL)
-- ============================================
-- ALTER TABLE users 
-- ADD COLUMN has_subscribed BOOLEAN DEFAULT FALSE,
-- ADD COLUMN subscribed_at TIMESTAMP NULL;

-- ============================================
-- To remove columns (rollback) - MySQL/MariaDB
-- ============================================
-- ALTER TABLE `users` 
-- DROP COLUMN `has_subscribed`,
-- DROP COLUMN `subscribed_at`;


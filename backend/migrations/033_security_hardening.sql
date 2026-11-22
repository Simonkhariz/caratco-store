-- -----------------------------------------------------------------------------
-- Migration 033: security hardening (22 Nov 2026 penetration review)
--
--   login_devices          browsers that have signed in to an account before. Only the SHA-256
--                          of a random device key is stored. When someone floods an account with
--                          wrong passwords (the per-account limit), a browser listed here can
--                          still sign in, so the owner cannot be locked out of their own shop.
--   orders.client_network  SHA-256 of the network an order was placed from (an IPv4 address, or
--                          the /64 of an IPv6 address). Used only to cap how many unpaid orders one
--                          connection can hold at once, so one visitor cannot keep stock off sale.
--
-- Adds only; no data is changed. Safe to run again (MySQL 8.4, MariaDB 10.x).
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `login_devices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` DATETIME NULL DEFAULT NULL,
    UNIQUE KEY `uniq_login_device_token` (`token_hash`),
    INDEX `idx_login_device_user` (`user_id`),
    CONSTRAINT `fk_login_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'client_network') = 0,
    'ALTER TABLE `orders` ADD COLUMN `client_network` CHAR(64) NULL DEFAULT NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_client_network') = 0,
    'ALTER TABLE `orders` ADD INDEX `idx_orders_client_network` (`client_network`, `order_status`, `expires_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Migration 029: A cap on unpaid orders per customer
--
-- An order holds its items from the moment it is placed until it is paid for or its payment
-- window closes. Without a cap, one person could place order after order they never pay for
-- and keep stock away from real buyers. The shop now refuses a new order when the same customer
-- already has the allowed number of unpaid orders (store setting `max_unpaid_orders`, 2 unless
-- changed, 0 = no cap).
--
-- "The same customer" is the account, the email, the phone number or the device. Email and
-- account are already on the order; these two columns add the other two:
--   orders.contact_phone  the delivery phone in +234 form (a copy of the one in the address)
--   orders.device_hash    SHA-256 of the browser's device id (never the id itself)
--
-- Safe to run again: each column and index is only added when missing (MySQL 8.4, MariaDB 10.x).
-- -----------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'contact_phone') = 0,
    'ALTER TABLE `orders` ADD COLUMN `contact_phone` VARCHAR(32) NULL DEFAULT NULL AFTER `guest_email`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'device_hash') = 0,
    'ALTER TABLE `orders` ADD COLUMN `device_hash` CHAR(64) NULL DEFAULT NULL AFTER `contact_phone`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The cap only ever looks at orders still awaiting payment, a short list at any moment.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_unpaid_holds') = 0,
    'ALTER TABLE `orders` ADD INDEX `idx_orders_unpaid_holds` (`order_status`, `expires_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `store_settings` (`key_name`, `value`) VALUES ('max_unpaid_orders', '2');

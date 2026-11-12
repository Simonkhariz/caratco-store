-- -----------------------------------------------------------------------------
-- Migration 030: Specifications, warranty and heavy items on products
--
-- The same shop code now serves different kinds of shop (config/shop.json), and some of them
-- need to say more about a product than a description can:
--   products.specifications  rows of name and value ("Top notes: Bergamot", "Power: 1500W"),
--                            kept as a JSON list of {"name", "value"}; NULL when there are none
--   products.warranty        one line such as "12 months manufacturer warranty"; NULL when none
--   products.is_heavy        1 for bulky items that cost more to deliver (see migration 031)
--
-- Safe to run again: each column is only added when missing (MySQL 8.4, MariaDB 10.x).
-- -----------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'specifications') = 0,
    'ALTER TABLE `products` ADD COLUMN `specifications` JSON NULL DEFAULT NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'warranty') = 0,
    'ALTER TABLE `products` ADD COLUMN `warranty` VARCHAR(160) NULL DEFAULT NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_heavy') = 0,
    'ALTER TABLE `products` ADD COLUMN `is_heavy` TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

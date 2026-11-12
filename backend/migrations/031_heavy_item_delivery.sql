-- -----------------------------------------------------------------------------
-- Migration 031: A delivery fee for heavy items
--
-- A fridge does not travel for the price of a phone charger. Each delivery zone can now add an
-- amount for every heavy item in an order (products.is_heavy, migration 030):
--   shipping_zones.heavy_item_fee  added once per heavy item, on top of the zone's usual fee
--
-- It only applies in shops that turn the feature on (config/shop.json, features.heavyDelivery);
-- everywhere else, and for every zone until an owner sets it, the fee is 0 and nothing changes.
--
-- Safe to run again: the column is only added when missing (MySQL 8.4, MariaDB 10.x).
-- -----------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shipping_zones' AND COLUMN_NAME = 'heavy_item_fee') = 0,
    'ALTER TABLE `shipping_zones` ADD COLUMN `heavy_item_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `extra_item_fee`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

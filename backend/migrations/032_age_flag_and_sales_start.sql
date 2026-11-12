-- -----------------------------------------------------------------------------
-- Migration 032: An age flag on products, a record of the confirmation, a starting sales count
--
--   products.is_age_restricted  1 for a product only adults may buy, whatever its category
--                               (a wine hamper among the hampers). A shop with the age check on
--                               (config/shop.json, features.ageCheck) asks for the confirmation
--                               when the product's category is listed there OR this is set.
--   orders.age_confirmed_at     when the buyer confirmed their age, on orders that needed it;
--                               NULL on every other order. The owner's record of the confirmation.
--   products.units_sold_start   the sales count a demo catalogue starts a product with.
--                               products.units_sold is this plus the units on paid orders
--                               (App\Services\ProductSales), so the first real sale no longer
--                               wipes the seeded figure. 0 for every product an owner creates.
--
-- Safe to run again: each column is only added when missing (MySQL 8.4, MariaDB 10.x).
-- -----------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_age_restricted') = 0,
    'ALTER TABLE `products` ADD COLUMN `is_age_restricted` TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'units_sold_start') = 0,
    'ALTER TABLE `products` ADD COLUMN `units_sold_start` INT NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'age_confirmed_at') = 0,
    'ALTER TABLE `orders` ADD COLUMN `age_confirmed_at` DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

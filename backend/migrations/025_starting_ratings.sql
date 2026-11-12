-- -----------------------------------------------------------------------------
-- Migration 025: Starting ratings
--
-- The owner can give each product a starting star rating (optional). A product without one uses
-- the store's default starting rating (Settings -> "Starting rating for new products", 4.5 unless
-- changed; empty = no starting rating, stars only from reviews).
--
--  products.rating_start  the product's own starting rating, NULL = use the store default
--  products.review_avg    the customers' own average (approved reviews only), for the admin
--  products.rating_avg    what the shop shows: the starting rating counted as 5 reviews, blended
--                         with the approved reviews (ProductRatings::refresh); rating_count stays
--                         the real number of approved reviews
--
-- Safe to run again: columns are only added when missing (MySQL 8.4 and MariaDB 10.x).
-- -----------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'rating_start') = 0,
    'ALTER TABLE `products` ADD COLUMN `rating_start` DECIMAL(2,1) NULL DEFAULT NULL AFTER `rating_count`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'review_avg') = 0,
    'ALTER TABLE `products` ADD COLUMN `review_avg` DECIMAL(3,2) NOT NULL DEFAULT 0.00 AFTER `rating_start`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `store_settings` (`key_name`, `value`) VALUES ('default_product_rating', '4.5');

-- Recompute every product once (the same sum as ProductRatings::refresh, weight 5).
SET @default_rating := (SELECT CASE WHEN `value` REGEXP '^[1-5](\\.[0-9])?$' THEN CAST(`value` AS DECIMAL(2,1)) END
                        FROM `store_settings` WHERE `key_name` = 'default_product_rating' LIMIT 1);

UPDATE `products` p
LEFT JOIN (
    SELECT `product_id`, AVG(`rating`) AS avg_rating, COUNT(*) AS review_count
    FROM `reviews`
    WHERE `status` = 'approved'
    GROUP BY `product_id`
) r ON r.product_id = p.id
SET p.rating_count = COALESCE(r.review_count, 0),
    p.review_avg = COALESCE(ROUND(r.avg_rating, 2), 0),
    p.rating_avg = CASE
        WHEN COALESCE(p.rating_start, @default_rating) IS NULL THEN COALESCE(ROUND(r.avg_rating, 2), 0)
        ELSE ROUND((COALESCE(p.rating_start, @default_rating) * 5 + COALESCE(r.avg_rating, 0) * COALESCE(r.review_count, 0))
                   / (5 + COALESCE(r.review_count, 0)), 2)
    END;

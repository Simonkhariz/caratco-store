-- -----------------------------------------------------------------------------
-- Migration 015: Catalogue speed at scale
--  - products.units_sold: units on paid, not cancelled/refunded orders, kept up to date by
--    App\Services\ProductSales. "Best selling" sorts on it instead of summing every order
--    line per product on each request (125 s at 20k products / 150k order lines).
--  - Composite indexes for the storefront's filters and sorts.
--  - Full-text index for product search (word search instead of scanning descriptions).
--  - Duplicate single-column indexes removed (each one slows every insert and update).
-- -----------------------------------------------------------------------------

ALTER TABLE `products`
    ADD COLUMN `units_sold` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `rating_count`;

UPDATE `products` p
LEFT JOIN (
    SELECT oi.`product_id`, SUM(oi.`quantity`) AS qty
    FROM `order_items` oi
    JOIN `orders` o ON o.`id` = oi.`order_id`
    WHERE oi.`product_id` IS NOT NULL
      AND o.`payment_status` IN ('successful', 'partially_refunded')
      AND o.`order_status` NOT IN ('cancelled', 'refunded')
    GROUP BY oi.`product_id`
) s ON s.`product_id` = p.`id`
SET p.`units_sold` = COALESCE(s.qty, 0);

ALTER TABLE `products`
    ADD INDEX `idx_products_listing` (`status`, `product_type`, `created_at`),
    ADD INDEX `idx_products_best_selling` (`status`, `units_sold`),
    ADD INDEX `idx_products_price` (`status`, `base_price`),
    ADD INDEX `idx_products_category_listing` (`category_id`, `status`, `created_at`),
    ADD INDEX `idx_products_flash` (`is_flash_deal`, `status`),
    ADD INDEX `idx_products_style_listing` (`status`, `product_type`, `dress_style`, `created_at`),
    ADD FULLTEXT INDEX `ft_products_search` (`name`, `description`, `dress_style`),
    DROP INDEX `idx_products_slug`,
    DROP INDEX `idx_products_style`;

-- Covers the listing's colour/size lookup and the filter options without reading rows.
ALTER TABLE `product_variants`
    ADD INDEX `idx_variants_facets` (`product_id`, `is_active`, `color_name`, `size`, `color_hex`);

ALTER TABLE `reviews`
    ADD INDEX `idx_reviews_product_status` (`product_id`, `status`, `created_at`);

ALTER TABLE `payments`
    ADD INDEX `idx_payments_order_status` (`order_id`, `status`),
    DROP INDEX `idx_payments_reference`;

ALTER TABLE `orders`
    DROP INDEX `idx_orders_number`;

ALTER TABLE `users`
    DROP INDEX `idx_users_email`;

ALTER TABLE `categories`
    DROP INDEX `idx_categories_slug`;

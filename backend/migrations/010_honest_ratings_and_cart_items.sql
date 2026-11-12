-- -----------------------------------------------------------------------------
-- Migration 010: Honest product ratings & persistent cart integrity
--  - products.rating_avg no longer defaults to a made-up 4.50
--  - ratings are recomputed from approved reviews only (0 / 0 when there are none)
--  - one cart line per variant
-- -----------------------------------------------------------------------------

ALTER TABLE `products`
    MODIFY `rating_avg` DECIMAL(3,2) NOT NULL DEFAULT 0.00;

UPDATE `products` p
LEFT JOIN (
    SELECT `product_id`, ROUND(AVG(`rating`), 2) AS avg_rating, COUNT(*) AS review_count
    FROM `reviews`
    WHERE `status` = 'approved'
    GROUP BY `product_id`
) r ON r.product_id = p.id
SET p.rating_avg = COALESCE(r.avg_rating, 0),
    p.rating_count = COALESCE(r.review_count, 0);

DELETE ci1 FROM `cart_items` ci1
JOIN `cart_items` ci2
  ON ci1.cart_id = ci2.cart_id AND ci1.variant_id = ci2.variant_id AND ci1.id > ci2.id;

ALTER TABLE `cart_items`
    ADD UNIQUE KEY `uq_cart_items_variant` (`cart_id`, `variant_id`);

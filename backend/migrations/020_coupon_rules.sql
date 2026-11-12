-- 020: coupon rules beyond a plain percentage.
--
--   discount_type        'percent' (discount_percent of the items subtotal) or 'fixed' (discount_amount off)
--   discount_amount      the fixed amount off, for 'fixed' codes
--   min_order_amount     the items subtotal must be at least this for the code to work (NULL: no minimum)
--   max_discount_amount  a percentage discount never takes off more than this (NULL: no cap)
--
-- Existing codes keep working unchanged: they are 'percent' with no minimum and no cap.

ALTER TABLE `promotions`
    ADD COLUMN `discount_type` ENUM('percent', 'fixed') NOT NULL DEFAULT 'percent' AFTER `description`,
    ADD COLUMN `discount_amount` DECIMAL(12,2) NULL AFTER `discount_percent`,
    ADD COLUMN `min_order_amount` DECIMAL(12,2) NULL AFTER `discount_amount`,
    ADD COLUMN `max_discount_amount` DECIMAL(12,2) NULL AFTER `min_order_amount`;

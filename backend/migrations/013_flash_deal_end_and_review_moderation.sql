-- -----------------------------------------------------------------------------
-- Migration 013: Real flash-deal end times and moderated customer reviews
--  - products.flash_deal_ends_at drives the storefront countdown; an expired deal is
--    no longer shown as a flash deal (NULL = no end date, no countdown shown)
--  - new reviews wait for moderation and are only "verified purchase" when the server
--    finds a paid order for that product (no more approved/verified by default)
-- -----------------------------------------------------------------------------

ALTER TABLE `products`
    ADD COLUMN `flash_deal_ends_at` DATETIME NULL AFTER `is_flash_deal`;

ALTER TABLE `reviews`
    MODIFY `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    MODIFY `is_verified_purchase` TINYINT(1) NOT NULL DEFAULT 0;

-- -----------------------------------------------------------------------------
-- Migration 009: Order, Payment & Inventory Integrity
--  - order statuses: adds `returned`; payment statuses: adds `partially_refunded`
--  - stock reservation expiry (orders.expires_at) and idempotent order creation
--  - admin attention flag for late/duplicate/underpaid payments
--  - webhook de-duplication scoped per provider
--  - variants: archive flag, one variant per size+colour, stock can never go negative
-- Engine: InnoDB | Collation: utf8mb4_unicode_ci
-- -----------------------------------------------------------------------------

ALTER TABLE `orders`
    MODIFY `order_status` ENUM('pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'returned', 'refund_pending', 'refunded') NOT NULL DEFAULT 'pending_payment',
    MODIFY `payment_status` ENUM('pending', 'successful', 'failed', 'cancelled', 'partially_refunded', 'refunded') NOT NULL DEFAULT 'pending',
    ADD COLUMN `expires_at` DATETIME NULL AFTER `notes`,
    ADD COLUMN `idempotency_key` VARCHAR(64) NULL AFTER `expires_at`,
    ADD COLUMN `requires_attention` TINYINT(1) NOT NULL DEFAULT 0 AFTER `idempotency_key`,
    ADD COLUMN `attention_reason` VARCHAR(500) NULL AFTER `requires_attention`,
    ADD UNIQUE INDEX `uq_orders_idempotency_key` (`idempotency_key`),
    ADD INDEX `idx_orders_expiry` (`order_status`, `expires_at`),
    ADD INDEX `idx_orders_attention` (`requires_attention`);

-- Existing unpaid orders get the standard 60-minute payment window from when they were placed.
UPDATE `orders`
SET `expires_at` = DATE_ADD(`created_at`, INTERVAL 60 MINUTE)
WHERE `order_status` = 'pending_payment' AND `expires_at` IS NULL;

ALTER TABLE `payments`
    MODIFY `status` ENUM('pending', 'successful', 'failed', 'cancelled', 'partially_refunded', 'refunded') NOT NULL DEFAULT 'pending',
    ADD COLUMN `amount_received` DECIMAL(12,2) NULL AFTER `amount`;

ALTER TABLE `payment_webhook_events`
    DROP INDEX `event_reference`,
    ADD UNIQUE INDEX `uq_webhook_provider_event` (`provider`, `event_reference`);

ALTER TABLE `product_variants`
    ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `low_stock_threshold`,
    ADD UNIQUE INDEX `uq_variant_options` (`product_id`, `size`, `color_name`),
    ADD CONSTRAINT `chk_variant_stock_non_negative` CHECK (`stock_quantity` >= 0);

ALTER TABLE `refunds`
    ADD COLUMN `restocked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

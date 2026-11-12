-- -----------------------------------------------------------------------------
-- Migration 017: Data retention and storage health
--
-- * users.last_seen_at: when a customer last signed in or used their account, so accounts
--   nobody has used for the admin's chosen period can be removed.
-- * orders.personal_data_removed_at: set when an old order's name, phone, email and address
--   are removed under the retention setting. Totals, items and payments are kept.
-- * api_slow_requests: one row per hour and route for API requests slower than
--   SLOW_REQUEST_MS, used for the "site is getting slow" alert. Fast requests write nothing.
-- * Retention settings start at the safe defaults (customer records kept until the admin
--   chooses a period).
-- -----------------------------------------------------------------------------

ALTER TABLE `users`
    ADD COLUMN `last_seen_at` DATETIME NULL AFTER `email_verified_at`,
    ADD INDEX `idx_users_role_seen` (`role`, `last_seen_at`);

-- Start from the latest thing we know each customer did: signing up or placing an order.
UPDATE `users` u
LEFT JOIN (SELECT `user_id`, MAX(`created_at`) AS `last_order` FROM `orders` WHERE `user_id` IS NOT NULL GROUP BY `user_id`) o
       ON o.`user_id` = u.`id`
SET u.`last_seen_at` = GREATEST(u.`created_at`, COALESCE(o.`last_order`, u.`created_at`))
WHERE u.`last_seen_at` IS NULL;

ALTER TABLE `orders`
    ADD COLUMN `personal_data_removed_at` DATETIME NULL AFTER `customer_marked_paid_at`,
    ADD INDEX `idx_orders_retention` (`personal_data_removed_at`, `order_status`, `updated_at`);

CREATE TABLE IF NOT EXISTS `api_slow_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `hour_start` DATETIME NOT NULL,
    `method` VARCHAR(8) NOT NULL,
    `route` VARCHAR(191) NOT NULL,
    `hits` INT NOT NULL DEFAULT 0,
    `total_ms` INT NOT NULL DEFAULT 0,
    `max_ms` INT NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_slow_hour_route` (`hour_start`, `method`, `route`),
    INDEX `idx_slow_hour` (`hour_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `store_settings` (`key_name`, `value`) VALUES
    ('retention_customer_days', '0'),
    ('retention_unpaid_days', '30'),
    ('retention_message_days', '180'),
    ('retention_activity_days', '0'),
    ('storage_alert_mb', '500');

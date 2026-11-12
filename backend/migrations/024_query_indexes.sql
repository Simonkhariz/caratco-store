-- -----------------------------------------------------------------------------
-- Migration 024: Indexes for the admin lists and reports
--
-- Each index below serves queries that had no usable index (a full scan or a sort of the whole
-- table on every load). Indexes already given by a PRIMARY/UNIQUE key, a foreign key or an earlier
-- migration are not repeated. Adding an index changes no data.
--
-- Safe to run again: each index is only created when information_schema says it is missing
-- (works on MySQL 8.4 and MariaDB 10.x; no "ADD INDEX IF NOT EXISTS", which MySQL lacks).
--
--  payments (status, verified_at, order_id)  dashboard + reports revenue: WHERE status IN (paid)
--                                            AND verified_at in a period, GROUP BY DATE(verified_at),
--                                            COUNT(DISTINCT order_id) (AdminDashboardController)
--  payments (created_at)                     Admin -> Payments list ORDER BY created_at DESC, and the
--                                            reports' payments-by-gateway for a period
--  users (role, created_at)                  Admin -> Customers list (role = 'customer' ORDER BY
--                                            created_at DESC) and new customers per period
--  email_outbox (status, created_at)         Admin -> Emails filtered by status, newest first; the
--                                            data clean-up of old sent/failed emails
--  reviews (status, created_at)              homepage "happy customers" (approved, newest first)
--                                            and Admin -> Reviews filtered by status
--  admin_audit_logs (action, created_at)     Admin -> Activity log filtered by action, newest first
--  newsletter_subscribers (status, subscribed_at)
--                                            Admin -> Marketing subscriber list, newest first
--  inventory_logs (variant_id, created_at)   stock history of one product option, newest first
-- -----------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_status_verified') = 0,
    'ALTER TABLE `payments` ADD INDEX `idx_payments_status_verified` (`status`, `verified_at`, `order_id`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND INDEX_NAME = 'idx_payments_created') = 0,
    'ALTER TABLE `payments` ADD INDEX `idx_payments_created` (`created_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role_created') = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_role_created` (`role`, `created_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_outbox' AND INDEX_NAME = 'idx_outbox_status_created') = 0,
    'ALTER TABLE `email_outbox` ADD INDEX `idx_outbox_status_created` (`status`, `created_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND INDEX_NAME = 'idx_reviews_status_created') = 0,
    'ALTER TABLE `reviews` ADD INDEX `idx_reviews_status_created` (`status`, `created_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_logs' AND INDEX_NAME = 'idx_audit_action_created') = 0,
    'ALTER TABLE `admin_audit_logs` ADD INDEX `idx_audit_action_created` (`action`, `created_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' AND INDEX_NAME = 'idx_newsletter_status_subscribed') = 0,
    'ALTER TABLE `newsletter_subscribers` ADD INDEX `idx_newsletter_status_subscribed` (`status`, `subscribed_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_logs' AND INDEX_NAME = 'idx_inventory_variant_created') = 0,
    'ALTER TABLE `inventory_logs` ADD INDEX `idx_inventory_variant_created` (`variant_id`, `created_at`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- Migration 008: Rate Limiting
-- Replaces the unlocked storage/framework/rate_limits.json file with an atomic,
-- shared MySQL counter table (fixed window per bucket + key hash).
-- Engine: InnoDB | Collation: utf8mb4_unicode_ci
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` CHAR(64) NOT NULL PRIMARY KEY,
    `hits` INT UNSIGNED NOT NULL DEFAULT 0,
    `window_start` INT UNSIGNED NOT NULL,
    INDEX `idx_rate_limits_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

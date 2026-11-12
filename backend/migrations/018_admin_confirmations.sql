-- -----------------------------------------------------------------------------
-- Migration 018: One-time confirmations for dangerous admin actions
--
-- A data clean-up only runs after the admin re-enters their password and types a 6-digit
-- code emailed to them. Each row is one such confirmation: tied to the admin and the
-- sign-in session that asked for it, to the exact records the admin reviewed (fingerprint,
-- reviewed_at), valid for 10 minutes, at most 5 code attempts, usable once. Only a hash of
-- the code is stored.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `admin_confirmations` (
    `id` CHAR(32) NOT NULL PRIMARY KEY,
    `admin_id` INT NOT NULL,
    `session_id` VARCHAR(128) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `code_hash` CHAR(64) NOT NULL,
    `reviewed_at` DATETIME NOT NULL,
    `fingerprint` CHAR(64) NOT NULL,
    `summary` JSON NULL,
    `attempts` INT NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_confirmations_admin` (`admin_id`, `created_at`),
    CONSTRAINT `fk_confirmations_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Migration 006: Promotions, Banners, Admin Audit Logs & Email Outbox
-- Engine: InnoDB | Collation: utf8mb4_unicode_ci
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `promotions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255) NOT NULL,
    `discount_percent` INT NOT NULL,
    `max_uses` INT NOT NULL DEFAULT 1000,
    `used_count` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `starts_at` DATETIME NULL,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_promotions_code` (`code`),
    INDEX `idx_promotions_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `banners` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `subtitle` VARCHAR(255) NULL,
    `cta_text` VARCHAR(50) NULL,
    `cta_url` VARCHAR(255) NULL,
    `image_url` VARCHAR(500) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `display_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_banners_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(100) NOT NULL,
    `entity_id` VARCHAR(100) NULL,
    `before_state` JSON NULL,
    `after_state` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_audit_admin` (`admin_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_outbox` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `recipient_email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `template` VARCHAR(100) NOT NULL,
    `payload` JSON NOT NULL,
    `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `attempt_count` INT NOT NULL DEFAULT 0,
    `last_error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME NULL,
    INDEX `idx_email_status` (`status`),
    INDEX `idx_email_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

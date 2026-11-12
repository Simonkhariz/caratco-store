-- -----------------------------------------------------------------------------
-- Migration 005: Payments, Webhooks, Digital Grants & Refunds
-- Engine: InnoDB | Collation: utf8mb4_unicode_ci
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'kora',
    `provider_reference` VARCHAR(100) NOT NULL UNIQUE,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
    `payment_method` VARCHAR(50) NULL,
    `status` ENUM('pending', 'successful', 'failed', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
    `provider_payload_redacted` JSON NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_payments_order` (`order_id`),
    INDEX `idx_payments_reference` (`provider_reference`),
    INDEX `idx_payments_status` (`status`),
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_webhook_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `provider` VARCHAR(50) NOT NULL DEFAULT 'kora',
    `event_reference` VARCHAR(100) NOT NULL UNIQUE,
    `event_type` VARCHAR(100) NOT NULL,
    `signature_valid` TINYINT(1) NOT NULL DEFAULT 1,
    `processing_result` ENUM('processed', 'ignored_duplicate', 'failed', 'unmatched') NOT NULL DEFAULT 'processed',
    `payload_metadata` JSON NULL,
    `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_webhook_reference` (`event_reference`),
    INDEX `idx_webhook_type` (`event_type`),
    INDEX `idx_webhook_received` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `digital_download_grants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `deliverable_id` INT NOT NULL,
    `user_id` INT NULL,
    `customer_email` VARCHAR(255) NOT NULL,
    `download_token_hash` VARCHAR(64) NOT NULL UNIQUE,
    `max_downloads` INT NOT NULL DEFAULT 5,
    `download_count` INT NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `is_revoked` TINYINT(1) NOT NULL DEFAULT 0,
    `revocation_reason` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_grants_order` (`order_id`),
    INDEX `idx_grants_deliverable` (`deliverable_id`),
    INDEX `idx_grants_token` (`download_token_hash`),
    INDEX `idx_grants_email` (`customer_email`),
    INDEX `idx_grants_expires` (`expires_at`),
    CONSTRAINT `fk_grants_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_grants_deliverable` FOREIGN KEY (`deliverable_id`) REFERENCES `digital_deliverables` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_grants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `digital_download_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `grant_id` INT NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` TEXT NULL,
    `downloaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_download_logs_grant` (`grant_id`),
    CONSTRAINT `fk_download_logs_grant` FOREIGN KEY (`grant_id`) REFERENCES `digital_download_grants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refunds` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payment_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `provider_refund_reference` VARCHAR(100) NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
    `reason` TEXT NOT NULL,
    `status` ENUM('pending', 'completed', 'rejected') NOT NULL DEFAULT 'pending',
    `processed_by_user_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_refunds_payment` (`payment_id`),
    INDEX `idx_refunds_order` (`order_id`),
    INDEX `idx_refunds_status` (`status`),
    CONSTRAINT `fk_refunds_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_refunds_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_refunds_user` FOREIGN KEY (`processed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

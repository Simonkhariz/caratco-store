-- -----------------------------------------------------------------------------
-- Migration 004: Shipping Zones, Orders, Order Items, Status History & Shipments
-- Engine: InnoDB | Collation: utf8mb4_unicode_ci
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `shipping_zones` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `states_covered` JSON NOT NULL,
    `fee` DECIMAL(12,2) NOT NULL,
    `estimated_days` VARCHAR(50) NOT NULL DEFAULT '2-4 business days',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_zones_code` (`code`),
    INDEX `idx_zones_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(32) NOT NULL UNIQUE,
    `user_id` INT NULL,
    `guest_email` VARCHAR(255) NOT NULL,
    `guest_access_token_hash` VARCHAR(64) NOT NULL,
    `shipping_zone_id` INT NULL,
    `shipping_zone_snapshot` JSON NULL,
    `shipping_address_snapshot` JSON NULL,
    `order_status` ENUM('pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refund_pending', 'refunded') NOT NULL DEFAULT 'pending_payment',
    `payment_status` ENUM('pending', 'successful', 'failed', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
    `subtotal` DECIMAL(12,2) NOT NULL,
    `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `shipping_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `grand_total` DECIMAL(12,2) NOT NULL,
    `applied_promo_code` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_orders_number` (`order_number`),
    INDEX `idx_orders_user` (`user_id`),
    INDEX `idx_orders_email` (`guest_email`),
    INDEX `idx_orders_guest_token` (`guest_access_token_hash`),
    INDEX `idx_orders_status` (`order_status`),
    INDEX `idx_orders_created` (`created_at`),
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_orders_zone` FOREIGN KEY (`shipping_zone_id`) REFERENCES `shipping_zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `product_id` INT NULL,
    `variant_id` INT NULL,
    `product_type` ENUM('physical', 'digital') NOT NULL DEFAULT 'physical',
    `item_name` VARCHAR(255) NOT NULL,
    `sku` VARCHAR(100) NOT NULL,
    `variant_options` JSON NOT NULL,
    `unit_price` DECIMAL(12,2) NOT NULL,
    `quantity` INT NOT NULL,
    `line_total` DECIMAL(12,2) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_order_items_order` (`order_id`),
    INDEX `idx_order_items_product` (`product_id`),
    INDEX `idx_order_items_variant` (`variant_id`),
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_order_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_order_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_status_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `previous_status` VARCHAR(50) NOT NULL,
    `new_status` VARCHAR(50) NOT NULL,
    `comment` TEXT NULL,
    `changed_by_user_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_status_history_order` (`order_id`),
    CONSTRAINT `fk_status_history_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_status_history_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shipments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `carrier` VARCHAR(100) NOT NULL,
    `tracking_number` VARCHAR(100) NULL,
    `tracking_url` VARCHAR(500) NULL,
    `status` ENUM('label_created', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'returned') NOT NULL DEFAULT 'label_created',
    `shipped_at` DATETIME NULL,
    `delivered_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_shipments_order` (`order_id`),
    INDEX `idx_shipments_tracking` (`tracking_number`),
    CONSTRAINT `fk_shipments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

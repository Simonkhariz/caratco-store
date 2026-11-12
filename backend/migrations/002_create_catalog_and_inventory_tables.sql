-- -----------------------------------------------------------------------------
-- Migration 002: Categories, Products, Variants, Images & Digital Deliverables
-- Engine: InnoDB | Collation: utf8mb4_unicode_ci
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `image_url` VARCHAR(500) NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_categories_slug` (`slug`),
    INDEX `idx_categories_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `uuid` VARCHAR(36) NOT NULL UNIQUE,
    `category_id` INT NULL,
    `name` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `description` LONGTEXT NULL,
    `product_type` ENUM('physical', 'digital') NOT NULL DEFAULT 'physical',
    `status` ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
    `base_price` DECIMAL(12,2) NOT NULL,
    `original_price` DECIMAL(12,2) NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'NGN',
    `sku` VARCHAR(100) NOT NULL UNIQUE,
    `rating_avg` DECIMAL(3,2) NOT NULL DEFAULT 4.50,
    `rating_count` INT NOT NULL DEFAULT 0,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
    `is_flash_deal` TINYINT(1) NOT NULL DEFAULT 0,
    `dress_style` VARCHAR(50) NOT NULL DEFAULT 'Casual',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_products_slug` (`slug`),
    INDEX `idx_products_category` (`category_id`),
    INDEX `idx_products_status` (`status`),
    INDEX `idx_products_featured` (`is_featured`),
    INDEX `idx_products_type` (`product_type`),
    INDEX `idx_products_style` (`dress_style`),
    CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_variants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `sku` VARCHAR(100) NOT NULL UNIQUE,
    `size` VARCHAR(50) NOT NULL,
    `color_name` VARCHAR(50) NOT NULL,
    `color_hex` VARCHAR(10) NOT NULL,
    `price_override` DECIMAL(12,2) NULL,
    `stock_quantity` INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_variants_product` (`product_id`),
    INDEX `idx_variants_sku` (`sku`),
    INDEX `idx_variants_stock` (`stock_quantity`),
    CONSTRAINT `fk_variants_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_images` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `image_url` VARCHAR(500) NOT NULL,
    `alt_text` VARCHAR(255) NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `display_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_images_product` (`product_id`),
    CONSTRAINT `fk_images_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `digital_deliverables` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `storage_path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size_bytes` BIGINT NOT NULL,
    `version` VARCHAR(50) NOT NULL DEFAULT '1.0',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_digital_product` (`product_id`),
    CONSTRAINT `fk_digital_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

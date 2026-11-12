-- -----------------------------------------------------------------------------
-- Step 13 Migration: Inventory Movement Logs, Newsletter Subscribers & Product Reviews
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `inventory_logs` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `variant_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `previous_stock` INT NOT NULL,
    `new_stock` INT NOT NULL,
    `change_amount` INT NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `adjusted_by_user_id` INT(11) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_inventory_variant` (`variant_id`),
    INDEX `idx_inventory_product` (`product_id`),
    INDEX `idx_inventory_created` (`created_at`),
    CONSTRAINT `fk_inventory_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_inventory_user` FOREIGN KEY (`adjusted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `status` ENUM('active', 'unsubscribed') NOT NULL DEFAULT 'active',
    `source` VARCHAR(100) NOT NULL DEFAULT 'storefront_footer',
    `subscribed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_newsletter_email` (`email`),
    INDEX `idx_newsletter_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reviews` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT(11) NOT NULL,
    `user_id` INT(11) NULL,
    `author_name` VARCHAR(150) NOT NULL,
    `author_email` VARCHAR(255) NULL,
    `rating` TINYINT UNSIGNED NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
    `title` VARCHAR(255) NULL,
    `comment` TEXT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',
    `is_verified_purchase` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_reviews_product` (`product_id`),
    INDEX `idx_reviews_status` (`status`),
    INDEX `idx_reviews_created` (`created_at`),
    CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

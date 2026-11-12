-- NOTE: rating_avg / rating_count values below are ignored: seed.php recomputes them from approved
-- reviews (none are seeded), so a fresh store shows no ratings until real reviews are approved.
-- -----------------------------------------------------------------------------
-- Seed 001: Initial Store Settings, Categories, Products, Variants & Shipping Zones
-- Currency: NGN (₦) | Origin Hub: Enugu, Nigeria
-- Note: No default admin credentials seeded. Admin is created via one-time onboarding.
-- Setup state is never reset here: seeding an onboarded store must not reopen public setup.
-- Physical products only.
-- -----------------------------------------------------------------------------

-- 1. Store Settings (Origin & Operational Configuration)
INSERT INTO `store_settings` (`key_name`, `value`) VALUES
('store_name', 'SHOP.CO'),
('store_currency', 'NGN'),
('store_origin_city', 'Enugu'),
('store_origin_state', 'Enugu'),
('store_origin_address', '12 Okpara Avenue, Independence Layout, Enugu, Nigeria'),
('store_contact_email', 'support@shop.co'),
('store_contact_phone', '+234 803 000 0000')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- 2. Destination Shipping Zones (Origin: Enugu)
INSERT INTO `shipping_zones` (`name`, `code`, `description`, `states_covered`, `fee`, `estimated_days`, `is_active`) VALUES
(
    'Enugu Urban & Metro',
    'ZONE-ENUGU-METRO',
    'Fast intra-state courier delivery within Enugu municipal and surrounding metro areas.',
    JSON_ARRAY('Enugu'),
    1500.00,
    '1-2 business days',
    1
),
(
    'South-East Regional Hub',
    'ZONE-SE-REGIONAL',
    'Regional road express across South-Eastern states bordering Enugu.',
    JSON_ARRAY('Anambra', 'Imo', 'Abia', 'Ebonyi'),
    3000.00,
    '2-3 business days',
    1
),
(
    'Major Commercial Hubs',
    'ZONE-MAJOR-HUBS',
    'Air & inter-state express to major commercial capitals.',
    JSON_ARRAY('Lagos', 'Abuja', 'Federal Capital Territory', 'Rivers'),
    4500.00,
    '3-4 business days',
    1
),
(
    'Rest of Nigeria',
    'ZONE-NATIONAL',
    'Nationwide delivery to all other Nigerian states.',
    JSON_ARRAY('*'),
    6000.00,
    '4-6 business days',
    1
)
ON DUPLICATE KEY UPDATE `fee` = VALUES(`fee`);

-- 3. Promotions & Coupon Codes
INSERT INTO `promotions` (`code`, `description`, `discount_percent`, `max_uses`, `is_active`, `show_on_homepage`) VALUES
('SHOP20', '20% off for verified new customers', 20, 1000, 1, 0),
('PANTO10', '10% promotional storewide discount', 10, 500, 1, 0),
('ENUGUFIRST', '15% inaugural launch coupon for Enugu shoppers', 15, 250, 1, 1)
ON DUPLICATE KEY UPDATE `discount_percent` = VALUES(`discount_percent`);

-- 4. Categories
INSERT INTO `categories` (`name`, `slug`, `description`, `image_url`, `display_order`, `is_active`) VALUES
('T-Shirts', 't-shirts', 'Casual streetwear and premium graphic tees.', '/images/categories/t-shirts.webp', 1, 1),
('Shorts', 'shorts', 'Everyday shorts, tailored bermudas, and athletic wear.', '/images/categories/shorts.webp', 2, 1),
('Shirts', 'shirts', 'Smart casual button-downs and formal shirts.', '/images/categories/shirts.webp', 3, 1),
('Hoodies', 'hoodies', 'Heavyweight fleece hoodies and outerwear.', '/images/categories/hoodies.webp', 4, 1),
('Jeans', 'jeans', 'Slim-fit, relaxed, and classic denim.', '/images/categories/jeans.webp', 5, 1),
('Accessories', 'accessories', 'Caps, bags, belts and everyday accessories.', '/images/categories/accessories.webp', 6, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 5. Products & Product Variants (Physical)
-- Physical Product 1: Gradient Graphic T-shirt
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-001', 1, 'Gradient Graphic T-shirt', 'gradient-graphic-tshirt', 'This graphic t-shirt is perfect for any casual occasion. Crafted from 100% breathable heavyweight cotton.', 'physical', 'published', 14500.00, 24200.00, 'NGN', 'SKU-TSHIRT-GRAD', 4.50, 42, 1, 1, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `price_override`, `stock_quantity`, `low_stock_threshold`) VALUES
(LAST_INSERT_ID(), 'SKU-TSHIRT-GRAD-M-WHT', 'Medium', 'White', '#FFFFFF', NULL, 25, 5),
(LAST_INSERT_ID(), 'SKU-TSHIRT-GRAD-L-WHT', 'Large', 'White', '#FFFFFF', NULL, 30, 5),
(LAST_INSERT_ID(), 'SKU-TSHIRT-GRAD-XL-WHT', 'X-Large', 'White', '#FFFFFF', NULL, 15, 5)
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`) VALUES
(1, 'https://images.unsplash.com/photo-1583743814966-8936f5b7be1a?auto=format&fit=crop&w=600&q=80', 'Gradient Graphic T-shirt Front', 1, 1)
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);

-- Physical Product 2: Skinny Fit Jeans
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-002', 5, 'Skinny Fit Jeans', 'skinny-fit-jeans', 'Premium stretch denim tailored with a modern tapered silhouette.', 'physical', 'published', 24000.00, 26000.00, 'NGN', 'SKU-JEANS-SKINNY', 4.80, 28, 1, 0, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `price_override`, `stock_quantity`, `low_stock_threshold`) VALUES
(2, 'SKU-JEANS-SKINNY-32-BLU', '32', 'Ocean Blue', '#2563EB', NULL, 20, 5),
(2, 'SKU-JEANS-SKINNY-34-BLU', '34', 'Ocean Blue', '#2563EB', NULL, 18, 5)
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`) VALUES
(2, 'https://images.unsplash.com/photo-1542272604-780c96856592?auto=format&fit=crop&w=600&q=80', 'Skinny Fit Jeans Front', 1, 1)
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);

-- Physical Product 3: Checkered Shirt
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-003', 3, 'Checkered Shirt', 'checkered-shirt', 'Classic yarn-dyed cotton checkered long-sleeve shirt.', 'physical', 'published', 18000.00, NULL, 'NGN', 'SKU-SHIRT-CHK', 4.60, 19, 0, 0, 'Formal')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `price_override`, `stock_quantity`, `low_stock_threshold`) VALUES
(3, 'SKU-SHIRT-CHK-M-RED', 'Medium', 'Crimson Red', '#B91C1C', NULL, 12, 5),
(3, 'SKU-SHIRT-CHK-L-RED', 'Large', 'Crimson Red', '#B91C1C', NULL, 14, 5)
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`) VALUES
(3, 'https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?auto=format&fit=crop&w=600&q=80', 'Checkered Shirt Front', 1, 1)
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);



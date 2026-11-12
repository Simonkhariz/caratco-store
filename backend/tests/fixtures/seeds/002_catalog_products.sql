-- NOTE: rating_avg / rating_count values below are ignored: seed.php recomputes them from approved
-- reviews (none are seeded), so a fresh store shows no ratings until real reviews are approved.
-- -----------------------------------------------------------------------------
-- Seed 002: Comprehensive Catalogue Products Across All Categories
-- Populates 3-4 distinct products for T-Shirts, Shorts, Shirts, Hoodies and Jeans (physical products only).
-- All prices in NGN (₦).
-- -----------------------------------------------------------------------------

-- =============================================================================
-- 1. T-SHIRTS (category_id = 1)
-- =============================================================================

-- Product 5: One Life Graphic T-shirt
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-005', 1, 'One Life Graphic T-shirt', 'one-life-graphic-tshirt', 'Crafted from 100% breathable organic cotton with high-definition typography on chest.', 'physical', 'published', 16500.00, 22000.00, 'NGN', 'SKU-TEE-ONELIFE', 4.70, 74, 1, 1, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-TEE-ONELIFE-M-BLK', 'Medium', 'Pitch Black', '#111827', 35, 5 FROM `products` WHERE `slug` = 'one-life-graphic-tshirt'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-TEE-ONELIFE-L-BLK', 'Large', 'Pitch Black', '#111827', 40, 5 FROM `products` WHERE `slug` = 'one-life-graphic-tshirt'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-TEE-ONELIFE-M-WHT', 'Medium', 'Clean White', '#FFFFFF', 25, 5 FROM `products` WHERE `slug` = 'one-life-graphic-tshirt'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=600&q=80', 'One Life Graphic T-shirt', 1, 1 FROM `products` WHERE `slug` = 'one-life-graphic-tshirt'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- Product 6: T-shirt with Tape Details
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-006', 1, 'T-shirt with Tape Details', 'tshirt-tape-details', 'Minimalist black t-shirt accented with branded sleeve tape details.', 'physical', 'published', 12000.00, 15000.00, 'NGN', 'SKU-TEE-TAPED', 4.50, 56, 0, 0, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-TEE-TAPED-M', 'Medium', 'Black', '#000000', 30, 5 FROM `products` WHERE `slug` = 'tshirt-tape-details'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-TEE-TAPED-L', 'Large', 'Black', '#000000', 25, 5 FROM `products` WHERE `slug` = 'tshirt-tape-details'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1503342217505-b0a15ec3261c?auto=format&fit=crop&w=600&q=80', 'T-shirt with Tape Details', 1, 1 FROM `products` WHERE `slug` = 'tshirt-tape-details'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- Product 7: Sleeve Striped Heavyweight T-shirt
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-007', 1, 'Sleeve Striped Heavyweight T-shirt', 'sleeve-striped-tshirt', 'Heavyweight 240 GSM combed cotton with contrasting athletic sleeve stripes.', 'physical', 'published', 13500.00, NULL, 'NGN', 'SKU-TEE-STRIPED', 4.60, 31, 0, 0, 'Gym')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-TEE-STRIPED-M', 'Medium', 'Navy Blue', '#1E3A8A', 20, 5 FROM `products` WHERE `slug` = 'sleeve-striped-tshirt'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1576566588028-4147f3842f27?auto=format&fit=crop&w=600&q=80', 'Sleeve Striped T-shirt', 1, 1 FROM `products` WHERE `slug` = 'sleeve-striped-tshirt'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- =============================================================================
-- 2. SHORTS (category_id = 2)
-- =============================================================================

-- Product 8: Loose Fit Bermuda Shorts
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-008', 2, 'Loose Fit Bermuda Shorts', 'loose-fit-bermuda-shorts', 'Relaxed silhouette twill Bermuda shorts with deep slash pockets and drawstring waistband.', 'physical', 'published', 15000.00, 18500.00, 'NGN', 'SKU-SHORTS-BERM', 4.40, 38, 1, 1, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHORTS-BERM-32-KHK', '32', 'Khaki Tan', '#D4B996', 25, 5 FROM `products` WHERE `slug` = 'loose-fit-bermuda-shorts'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHORTS-BERM-34-KHK', '34', 'Khaki Tan', '#D4B996', 30, 5 FROM `products` WHERE `slug` = 'loose-fit-bermuda-shorts'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHORTS-BERM-32-BLK', '32', 'Midnight Black', '#000000', 20, 5 FROM `products` WHERE `slug` = 'loose-fit-bermuda-shorts'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1591195853828-11db59a44f6b?auto=format&fit=crop&w=600&q=80', 'Loose Fit Bermuda Shorts', 1, 1 FROM `products` WHERE `slug` = 'loose-fit-bermuda-shorts'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- Product 9: Tailored Chino Walking Shorts
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-009', 2, 'Tailored Chino Walking Shorts', 'tailored-chino-shorts', 'Smart casual stretch chino shorts designed for warm climate versatility.', 'physical', 'published', 17500.00, NULL, 'NGN', 'SKU-SHORTS-CHINO', 4.80, 22, 0, 0, 'Formal')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHORTS-CHINO-32-NVY', '32', 'Navy', '#1E3A8A', 15, 5 FROM `products` WHERE `slug` = 'tailored-chino-shorts'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHORTS-CHINO-34-NVY', '34', 'Navy', '#1E3A8A', 20, 5 FROM `products` WHERE `slug` = 'tailored-chino-shorts'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1562157873-818bc0726f68?auto=format&fit=crop&w=600&q=80', 'Tailored Chino Shorts', 1, 1 FROM `products` WHERE `slug` = 'tailored-chino-shorts'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- Product 10: Athletic Breathable Mesh Shorts
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-010', 2, 'Athletic Breathable Mesh Shorts', 'athletic-mesh-shorts', 'Double-layer moisture-wicking mesh shorts engineered for intense workouts and agility.', 'physical', 'published', 11000.00, 14000.00, 'NGN', 'SKU-SHORTS-MESH', 4.60, 49, 1, 0, 'Gym')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHORTS-MESH-M-GRY', 'Medium', 'Heather Gray', '#9CA3AF', 30, 5 FROM `products` WHERE `slug` = 'athletic-mesh-shorts'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHORTS-MESH-L-GRY', 'Large', 'Heather Gray', '#9CA3AF', 35, 5 FROM `products` WHERE `slug` = 'athletic-mesh-shorts'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1539571696357-5a69c17a67c6?auto=format&fit=crop&w=600&q=80', 'Athletic Mesh Shorts', 1, 1 FROM `products` WHERE `slug` = 'athletic-mesh-shorts'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- =============================================================================
-- 3. SHIRTS (category_id = 3)
-- =============================================================================

-- Product 11: Vertical Striped Casual Shirt
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-011', 3, 'Vertical Striped Casual Shirt', 'vertical-striped-shirt', 'Cuban-collar retro vertical striped shirt made from lightweight breathable rayon blend.', 'physical', 'published', 19500.00, 23000.00, 'NGN', 'SKU-SHIRT-VSTRIPE', 4.70, 63, 1, 1, 'Party')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHIRT-VSTRIPE-M', 'Medium', 'Vintage Olive', '#4B5563', 25, 5 FROM `products` WHERE `slug` = 'vertical-striped-shirt'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHIRT-VSTRIPE-L', 'Large', 'Vintage Olive', '#4B5563', 30, 5 FROM `products` WHERE `slug` = 'vertical-striped-shirt'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1596755094514-f87e34085b2c?auto=format&fit=crop&w=600&q=80', 'Vertical Striped Shirt', 1, 1 FROM `products` WHERE `slug` = 'vertical-striped-shirt'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- Product 12: Relaxed Fit Linen Resort Shirt
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-012', 3, 'Relaxed Fit Linen Resort Shirt', 'relaxed-linen-shirt', '100% natural European flax linen shirt with mother-of-pearl buttons. Ultimate tropical luxury.', 'physical', 'published', 22500.00, 26000.00, 'NGN', 'SKU-SHIRT-LINEN', 4.90, 44, 1, 0, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHIRT-LINEN-M-WHT', 'Medium', 'Sand White', '#FAF8F5', 20, 5 FROM `products` WHERE `slug` = 'relaxed-linen-shirt'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-SHIRT-LINEN-L-WHT', 'Large', 'Sand White', '#FAF8F5', 25, 5 FROM `products` WHERE `slug` = 'relaxed-linen-shirt'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1620799140408-edc6dcb6d633?auto=format&fit=crop&w=600&q=80', 'Relaxed Linen Shirt', 1, 1 FROM `products` WHERE `slug` = 'relaxed-linen-shirt'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- =============================================================================
-- 4. HOODIES (category_id = 4)
-- =============================================================================

-- Product 13: Heavyweight Fleece Pullover Hoodie
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-013', 4, 'Heavyweight Fleece Pullover Hoodie', 'heavyweight-fleece-hoodie', '450 GSM ultra-heavyweight brushed French terry cotton with kangaroo pouch pocket.', 'physical', 'published', 28000.00, 34000.00, 'NGN', 'SKU-HOOD-HVY', 4.90, 89, 1, 1, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-HOOD-HVY-M-BLK', 'Medium', 'Onyx Black', '#0F172A', 40, 5 FROM `products` WHERE `slug` = 'heavyweight-fleece-hoodie'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-HOOD-HVY-L-BLK', 'Large', 'Onyx Black', '#0F172A', 45, 5 FROM `products` WHERE `slug` = 'heavyweight-fleece-hoodie'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-HOOD-HVY-XL-BLK', 'X-Large', 'Onyx Black', '#0F172A', 30, 5 FROM `products` WHERE `slug` = 'heavyweight-fleece-hoodie'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1556905055-8f358a7a47b2?auto=format&fit=crop&w=600&q=80', 'Heavyweight Fleece Hoodie', 1, 1 FROM `products` WHERE `slug` = 'heavyweight-fleece-hoodie'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- Product 14: Minimalist Oversized Streetwear Hoodie
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-014', 4, 'Minimalist Oversized Streetwear Hoodie', 'oversized-streetwear-hoodie', 'Dropped shoulders with boxy relaxed drape. Clean aesthetic with subtle tonal cuff embroidery.', 'physical', 'published', 26500.00, NULL, 'NGN', 'SKU-HOOD-OVR', 4.80, 52, 1, 0, 'Party')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-HOOD-OVR-M-SAGE', 'Medium', 'Sage Green', '#6B7280', 25, 5 FROM `products` WHERE `slug` = 'oversized-streetwear-hoodie'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-HOOD-OVR-L-SAGE', 'Large', 'Sage Green', '#6B7280', 30, 5 FROM `products` WHERE `slug` = 'oversized-streetwear-hoodie'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1543163521-1bf539c55dd2?auto=format&fit=crop&w=600&q=80', 'Oversized Streetwear Hoodie', 1, 1 FROM `products` WHERE `slug` = 'oversized-streetwear-hoodie'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- Product 15: Full-Zip Thermal Athletic Hoodie
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-015', 4, 'Full-Zip Thermal Athletic Hoodie', 'full-zip-thermal-hoodie', 'Water-repellent zip-up hoodie lined with thermal microfleece for morning track runs.', 'physical', 'published', 29000.00, 32000.00, 'NGN', 'SKU-HOOD-ZIP', 4.50, 37, 0, 0, 'Gym')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-HOOD-ZIP-M-GRY', 'Medium', 'Charcoal Gray', '#374151', 20, 5 FROM `products` WHERE `slug` = 'full-zip-thermal-hoodie'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1578587018452-892bacefd3f2?auto=format&fit=crop&w=600&q=80', 'Full-Zip Thermal Hoodie', 1, 1 FROM `products` WHERE `slug` = 'full-zip-thermal-hoodie'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- =============================================================================
-- 5. JEANS (category_id = 5)
-- =============================================================================

-- Product 16: Loose Fit Tactical Cargo Jeans
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-016', 5, 'Loose Fit Tactical Cargo Jeans', 'tactical-cargo-jeans', 'Heavy-duty 14oz raw selvedge denim built with multi-pocket utility styling.', 'physical', 'published', 27000.00, 31000.00, 'NGN', 'SKU-JEANS-CARGO', 4.80, 58, 1, 0, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-JEANS-CARGO-32', '32', 'Washed Indigo', '#1E40AF', 25, 5 FROM `products` WHERE `slug` = 'tactical-cargo-jeans'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-JEANS-CARGO-34', '34', 'Washed Indigo', '#1E40AF', 30, 5 FROM `products` WHERE `slug` = 'tactical-cargo-jeans'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1541099649105-f69ad21f3246?auto=format&fit=crop&w=600&q=80', 'Tactical Cargo Jeans', 1, 1 FROM `products` WHERE `slug` = 'tactical-cargo-jeans'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);


-- Product 17: Faded Vintage Denim Jeans
INSERT INTO `products` (`uuid`, `category_id`, `name`, `slug`, `description`, `product_type`, `status`, `base_price`, `original_price`, `currency`, `sku`, `rating_avg`, `rating_count`, `is_featured`, `is_flash_deal`, `dress_style`) VALUES
('prod-uuid-017', 5, 'Faded Vintage Denim Jeans', 'faded-vintage-jeans', 'Hand-distressed vintage wash denim with authentic whiskering and copper rivet reinforcements.', 'physical', 'published', 25500.00, 29000.00, 'NGN', 'SKU-JEANS-VNTG', 4.60, 41, 0, 1, 'Casual')
ON DUPLICATE KEY UPDATE `base_price` = VALUES(`base_price`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-JEANS-VNTG-32', '32', 'Light Vintage Blue', '#60A5FA', 20, 5 FROM `products` WHERE `slug` = 'faded-vintage-jeans'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_variants` (`product_id`, `sku`, `size`, `color_name`, `color_hex`, `stock_quantity`, `low_stock_threshold`)
SELECT id, 'SKU-JEANS-VNTG-34', '34', 'Light Vintage Blue', '#60A5FA', 25, 5 FROM `products` WHERE `slug` = 'faded-vintage-jeans'
ON DUPLICATE KEY UPDATE `stock_quantity` = VALUES(`stock_quantity`);

INSERT INTO `product_images` (`product_id`, `image_url`, `alt_text`, `is_primary`, `display_order`)
SELECT id, 'https://images.unsplash.com/photo-1582552938357-32b906df40cb?auto=format&fit=crop&w=600&q=80', 'Faded Vintage Denim Jeans', 1, 1 FROM `products` WHERE `slug` = 'faded-vintage-jeans'
ON DUPLICATE KEY UPDATE `image_url` = VALUES(`image_url`);




-- -----------------------------------------------------------------------------
-- Rollback All Migrations (Reverse Dependency Order)
-- -----------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `schema_migrations`;
DROP TABLE IF EXISTS `admin_confirmations`;
DROP TABLE IF EXISTS `api_slow_requests`;
DROP TABLE IF EXISTS `rate_limits`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `newsletter_subscribers`;
DROP TABLE IF EXISTS `inventory_logs`;

DROP TABLE IF EXISTS `email_outbox`;
DROP TABLE IF EXISTS `admin_audit_logs`;
DROP TABLE IF EXISTS `banners`;
DROP TABLE IF EXISTS `promotions`;
DROP TABLE IF EXISTS `refunds`;
DROP TABLE IF EXISTS `digital_download_logs`;
DROP TABLE IF EXISTS `digital_download_grants`;
DROP TABLE IF EXISTS `payment_webhook_events`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `shipments`;
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `shipping_zones`;
DROP TABLE IF EXISTS `wishlist_items`;
DROP TABLE IF EXISTS `wishlists`;
DROP TABLE IF EXISTS `cart_items`;
DROP TABLE IF EXISTS `carts`;
DROP TABLE IF EXISTS `digital_deliverables`;
DROP TABLE IF EXISTS `product_images`;
DROP TABLE IF EXISTS `product_variants`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `store_settings`;
DROP TABLE IF EXISTS `addresses`;
DROP TABLE IF EXISTS `email_verification_tokens`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- Migration 016: Customer "Mark as paid" for WhatsApp transfers
--
-- When a customer who pays by WhatsApp transfer taps "Mark as paid", the time is stored on
-- the order. The admin is alerted (header bell, orders list, email) until the transfer is
-- confirmed or the order closes. The claim itself never marks anything paid.
-- -----------------------------------------------------------------------------

ALTER TABLE `orders`
  ADD COLUMN `customer_marked_paid_at` DATETIME NULL DEFAULT NULL AFTER `expires_at`;

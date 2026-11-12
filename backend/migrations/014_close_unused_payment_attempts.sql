-- -----------------------------------------------------------------------------
-- Migration 014: Close payment attempts that can no longer be paid
--
-- Before the payment fixes, every order got a pending Kora row up front, so an order paid
-- with Monnify (or confirmed manually) also showed a never-used "pending" Kora payment.
-- New code closes the other attempts when an order is paid; this tidies the older rows.
-- Only rows still 'pending' on orders that are no longer awaiting payment are touched.
-- -----------------------------------------------------------------------------

UPDATE `payments` p
JOIN `orders` o ON o.`id` = p.`order_id`
SET p.`status` = 'cancelled', p.`updated_at` = NOW()
WHERE p.`status` = 'pending'
  AND o.`order_status` <> 'pending_payment';

-- -----------------------------------------------------------------------------
-- Migration 022: Flag payment claims that expired before this fix
--
-- A customer who taps "I've sent the transfer" may really have paid. New expiries flag the order
-- (FulfillmentService::expireOrder); this does the same for orders that already expired, so the
-- clean-up does not list them as "never paid" before an admin has checked the bank. Only raises a
-- flag; nothing is removed. updated_at keeps its value: reports and keep periods date orders by it.
-- -----------------------------------------------------------------------------

UPDATE `orders` o
SET o.`requires_attention` = 1,
    o.`attention_reason` = LEFT(CONCAT_WS(' | ', NULLIF(o.`attention_reason`, ''),
        'The customer said they sent the transfer, but the payment window ended and the order was cancelled. Check your bank: if the money arrived, confirm the payment (it is kept as a late payment) and refund it.'), 500),
    o.`updated_at` = o.`updated_at`
WHERE o.`order_status` = 'cancelled'
  AND o.`customer_marked_paid_at` IS NOT NULL
  AND o.`requires_attention` = 0
  AND NOT EXISTS (SELECT 1 FROM `payments` p WHERE p.`order_id` = o.`id`
                  AND p.`status` IN ('successful', 'partially_refunded', 'refunded'));

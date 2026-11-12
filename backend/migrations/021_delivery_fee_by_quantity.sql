-- 021: delivery fee that grows with the number of items.
--
--   fee             covers the first `items_included` items (as before)
--   items_included  how many items the fee covers (at least 1)
--   extra_item_fee  added for each item after that
--
-- Defaults (1 item included, 0 per extra item) keep every zone's fee exactly as it was.

ALTER TABLE `shipping_zones`
    ADD COLUMN `items_included` INT NOT NULL DEFAULT 1 AFTER `fee`,
    ADD COLUMN `extra_item_fee` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `items_included`;

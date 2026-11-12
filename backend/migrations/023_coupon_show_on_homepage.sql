-- 023: the owner chooses which coupon the homepage advertises.
--
--   show_on_homepage  1: advertised to every visitor while the code is live. 0: private (the default).
--
-- Until now the newest live code was advertised automatically. That code gets the tick here, so the
-- homepage looks the same after this update and the owner can untick it in admin.

ALTER TABLE `promotions`
    ADD COLUMN `show_on_homepage` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;

UPDATE `promotions` p
JOIN (
    SELECT `id` FROM `promotions`
    WHERE `is_active` = 1
      AND (`starts_at` IS NULL OR `starts_at` <= NOW())
      AND (`expires_at` IS NULL OR `expires_at` >= NOW())
      AND (`max_uses` <= 0 OR `used_count` < `max_uses`)
    ORDER BY `id` DESC LIMIT 1
) advertised ON advertised.`id` = p.`id`
SET p.`show_on_homepage` = 1;

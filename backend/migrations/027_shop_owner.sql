-- -----------------------------------------------------------------------------
-- Migration 027: The shop owner
--
-- Admins can now switch off and delete other admins. Until now every admin had the same role, so
-- a staff admin could remove the owner. The account made at first-run setup is now the owner
-- (role super_admin: only an owner can switch off or delete an owner).
--
-- For shops set up before this: when no owner exists yet, one admin becomes the owner. An admin
-- who can sign in (active) always comes before one who is switched off; among those, the admin
-- with the first-run setup email, else the oldest. Nothing else changes; a shop that already has
-- an owner is left alone, so this is safe to run again.
-- -----------------------------------------------------------------------------

SET @has_owner := (SELECT COUNT(*) FROM `users` WHERE `role` = 'super_admin');

SET @owner := (
    SELECT u.`id` FROM `users` u
    LEFT JOIN `store_settings` s ON s.`key_name` = 'admin_onboarded_email' AND LOWER(s.`value`) = LOWER(u.`email`)
    WHERE u.`role` = 'admin'
    ORDER BY (u.`status` = 'active') DESC, (s.`key_name` IS NOT NULL) DESC, u.`created_at` ASC, u.`id` ASC
    LIMIT 1
);

UPDATE `users` SET `role` = 'super_admin' WHERE `id` = @owner AND @has_owner = 0;

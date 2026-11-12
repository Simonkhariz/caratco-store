-- -----------------------------------------------------------------------------
-- Migration 011: Newsletter unsubscribe
-- Marketing email goes only to people who subscribed; every message carries a
-- one-click unsubscribe link backed by a per-subscriber token.
-- -----------------------------------------------------------------------------

ALTER TABLE `newsletter_subscribers`
    ADD COLUMN `unsubscribe_token` CHAR(32) NULL AFTER `source`,
    ADD COLUMN `unsubscribed_at` DATETIME NULL AFTER `subscribed_at`,
    ADD UNIQUE INDEX `uq_newsletter_unsubscribe_token` (`unsubscribe_token`);

UPDATE `newsletter_subscribers`
SET `unsubscribe_token` = SUBSTRING(SHA2(CONCAT(`id`, `email`, RAND(), NOW(6)), 256), 1, 32)
WHERE `unsubscribe_token` IS NULL;

-- 019: one outbox row per dedup key.
--
-- MailService checked for an existing row and then inserted, so two requests at the same moment
-- could both queue the same email. A unique key makes the second insert fail, and MailService
-- treats that as "already queued". Rows are never removed: if older duplicates exist, only the
-- extra copies lose their dedup key (the first row keeps it), so the unique key can be added.

UPDATE `email_outbox` o
JOIN (
    SELECT `dedup_key`, MIN(`id`) AS `keep_id`
    FROM `email_outbox`
    WHERE `dedup_key` IS NOT NULL
    GROUP BY `dedup_key`
    HAVING COUNT(*) > 1
) d ON d.`dedup_key` = o.`dedup_key` AND o.`id` <> d.`keep_id`
SET o.`dedup_key` = NULL;

ALTER TABLE `email_outbox`
    DROP INDEX `idx_outbox_dedup`,
    ADD UNIQUE KEY `uq_outbox_dedup` (`dedup_key`);

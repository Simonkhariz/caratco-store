-- -----------------------------------------------------------------------------
-- Migration 012: Email outbox delivery, refund completion time, audit action names
--
-- * email_outbox gains a dedup key, the transport actually used (smtp / log) and a
--   next_attempt_at used both as the retry schedule and as a short lease for the worker.
-- * refunds gain completed_at so analytics can count a refund on the day it was sent.
-- * Older audit rows are renamed to the entity.verb convention used everywhere now.
-- -----------------------------------------------------------------------------

ALTER TABLE `email_outbox`
    ADD COLUMN `dedup_key` VARCHAR(191) NULL AFTER `template`,
    ADD COLUMN `transport` VARCHAR(10) NULL AFTER `status`,
    ADD COLUMN `next_attempt_at` DATETIME NULL AFTER `attempt_count`,
    ADD INDEX `idx_outbox_due` (`status`, `next_attempt_at`),
    ADD INDEX `idx_outbox_dedup` (`dedup_key`);

-- Rows written by the old code in log mode said "sent" with a note; record the transport instead.
UPDATE `email_outbox`
SET `transport` = 'log', `last_error` = NULL
WHERE `last_error` LIKE '[DEV_LOG]%';

-- Broadcasts queued by the old code went to every customer, not only subscribers, and carry no
-- unsubscribe link. They must never be sent.
UPDATE `email_outbox`
SET `status` = 'failed',
    `last_error` = 'Not sent: queued before newsletter opt-in and unsubscribe links were required.'
WHERE `template` = 'marketing_broadcast'
  AND `status` = 'pending'
  AND JSON_EXTRACT(`payload`, '$.unsubscribe_url') IS NULL;

-- Opt-in broadcasts already carry everything the renderer needs.
UPDATE `email_outbox`
SET `payload` = JSON_SET(`payload`, '$.v', 2)
WHERE `template` = 'marketing_broadcast'
  AND JSON_EXTRACT(`payload`, '$.unsubscribe_url') IS NOT NULL;

ALTER TABLE `refunds`
    ADD COLUMN `completed_at` DATETIME NULL AFTER `processed_by_user_id`;

UPDATE `refunds` SET `completed_at` = `updated_at` WHERE `status` = 'completed' AND `completed_at` IS NULL;

-- entity_type first: MySQL applies SET assignments left to right, so it must see the old action.
UPDATE `admin_audit_logs`
SET `entity_type` = CASE
        WHEN `action` = 'admin_onboarding_completed' THEN 'store'
        WHEN `action` = 'customer.status_update' THEN 'customer'
        WHEN `action` IN ('admin.profile_update', 'admin.password_change') THEN 'admin'
        ELSE `entity_type`
    END
WHERE `action` IN ('admin_onboarding_completed', 'customer.status_update', 'admin.profile_update', 'admin.password_change');

UPDATE `admin_audit_logs`
SET `action` = CASE `action`
        WHEN 'admin_onboarding_completed' THEN 'store.setup'
        WHEN 'product_created' THEN 'product.create'
        WHEN 'PRODUCT_CREATED' THEN 'product.create'
        WHEN 'product_updated' THEN 'product.update'
        WHEN 'PRODUCT_UPDATED' THEN 'product.update'
        WHEN 'product_status_changed' THEN 'product.status_change'
        WHEN 'PRODUCT_STATUS_CHANGED' THEN 'product.status_change'
        WHEN 'product_archived' THEN 'product.archive'
        WHEN 'PRODUCT_DELETED' THEN 'product.archive'
        WHEN 'inventory_adjusted' THEN 'inventory.adjust'
        WHEN 'REFUND_PROCESSED' THEN 'refund.create'
        WHEN 'MARKETING_BROADCAST_QUEUED' THEN 'marketing.broadcast'
        WHEN 'marketing.broadcast_queued' THEN 'marketing.broadcast'
        WHEN 'customer.status_update' THEN 'customer.status_update'
        ELSE `action`
    END
WHERE `action` IN (
    'admin_onboarding_completed', 'product_created', 'PRODUCT_CREATED', 'product_updated', 'PRODUCT_UPDATED',
    'product_status_changed', 'PRODUCT_STATUS_CHANGED', 'product_archived', 'PRODUCT_DELETED',
    'inventory_adjusted', 'REFUND_PROCESSED', 'MARKETING_BROADCAST_QUEUED', 'marketing.broadcast_queued',
    'customer.status_update'
);

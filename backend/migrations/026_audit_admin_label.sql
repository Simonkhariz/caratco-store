-- -----------------------------------------------------------------------------
-- Migration 026: Keep the name on the activity log when an admin account is deleted
--
-- admin_audit_logs.admin_id is set to NULL when its admin account is deleted, which used to leave
-- that admin's past actions with no name. admin_label holds "Name (deleted admin, email)" and is
-- written by AdminTeamController::delete just before the account goes; readers show it when the
-- account no longer exists.
--
-- Safe to run again: the column is only added when missing (MySQL 8.4 and MariaDB 10.x).
-- -----------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_logs' AND COLUMN_NAME = 'admin_label') = 0,
    'ALTER TABLE `admin_audit_logs` ADD COLUMN `admin_label` VARCHAR(600) NULL DEFAULT NULL AFTER `admin_id`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

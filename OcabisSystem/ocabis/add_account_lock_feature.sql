-- Add account lock feature for department head accounts
-- This script adds columns to track failed login attempts and account locks

ALTER TABLE `users` 
ADD COLUMN `failed_login_attempts` INT(11) DEFAULT 0 AFTER `role`,
ADD COLUMN `account_locked` TINYINT(1) DEFAULT 0 AFTER `failed_login_attempts`,
ADD COLUMN `locked_at` DATETIME DEFAULT NULL AFTER `account_locked`,
ADD COLUMN `lock_reason` VARCHAR(255) DEFAULT NULL AFTER `locked_at`,
ADD COLUMN `temporary_lock_count` INT(11) DEFAULT 0 AFTER `lock_reason`;

-- Add index for faster lookups
CREATE INDEX `idx_account_locked` ON `users` (`account_locked`);
CREATE INDEX `idx_department_admin` ON `users` (`department`, `is_admin`);
CREATE INDEX `idx_temporary_lock_count` ON `users` (`temporary_lock_count`);


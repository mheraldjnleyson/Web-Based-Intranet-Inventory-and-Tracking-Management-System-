-- ================================================
-- Add QR Code Field to item_tables
-- ================================================
-- This script adds a qr_code field to item_tables table
-- so each item table can have its own QR code for scanning

ALTER TABLE `item_tables` 
ADD COLUMN IF NOT EXISTS `qr_code` varchar(255) DEFAULT NULL AFTER `table_image_path`;

-- Add index for faster QR code lookups
ALTER TABLE `item_tables`
ADD INDEX IF NOT EXISTS `idx_qr_code` (`qr_code`);


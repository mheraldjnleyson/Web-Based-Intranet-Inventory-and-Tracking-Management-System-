-- Migration script to change return_date column from DATE to DATETIME
-- This allows storing timestamps (date and time) for when items are returned
-- Run this script in your database to enable timestamp support for return_date

-- Change return_date column from DATE to DATETIME
ALTER TABLE `borrow_history` 
MODIFY COLUMN `return_date` DATETIME DEFAULT NULL;

-- Verify the change
-- SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
-- FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_SCHEMA = DATABASE() 
-- AND TABLE_NAME = 'borrow_history' 
-- AND COLUMN_NAME = 'return_date';


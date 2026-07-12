-- Update borrow_history table to support pending and declined statuses
-- Run this SQL script to update the existing table

ALTER TABLE `borrow_history` 
MODIFY COLUMN `status` ENUM('pending', 'active', 'returned', 'overdue', 'declined') NOT NULL DEFAULT 'pending';


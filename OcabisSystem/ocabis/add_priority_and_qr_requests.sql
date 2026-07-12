-- Add priority field to item_tables
ALTER TABLE `item_tables` 
ADD COLUMN `priority` ENUM('low', 'medium', 'high') DEFAULT 'low' AFTER `description`;

-- Create qr_requests table
CREATE TABLE IF NOT EXISTS `qr_requests` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `item_table_id` int(11) NOT NULL,
    `requested_by` varchar(100) NOT NULL,
    `priority` ENUM('low', 'medium', 'high') NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `qr_code` varchar(255) DEFAULT NULL,
    `qr_code_path` varchar(500) DEFAULT NULL,
    `download_count` int(11) DEFAULT 0,
    `downloaded_at` datetime DEFAULT NULL,
    `approved_by` varchar(100) DEFAULT NULL,
    `rejected_by` varchar(100) DEFAULT NULL,
    `rejection_reason` text DEFAULT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `item_table_id` (`item_table_id`),
    KEY `requested_by` (`requested_by`),
    KEY `status` (`status`),
    FOREIGN KEY (`item_table_id`) REFERENCES `item_tables`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


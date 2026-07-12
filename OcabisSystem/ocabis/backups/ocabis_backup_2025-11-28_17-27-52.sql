-- OCABIS Database Backup
-- Export Date: 2025-11-28 17:27:52
-- Backup Type: Automatic Monthly
-- Total Tables: 14

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

-- ================================================
-- Table: archived_categories
-- ================================================

DROP TABLE IF EXISTS `archived_categories`;
CREATE TABLE `archived_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `account` varchar(255) DEFAULT NULL,
  `archived_by` varchar(255) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- Table archived_categories is empty (0 rows)

-- ================================================
-- Table: archived_items
-- ================================================

DROP TABLE IF EXISTS `archived_items`;
CREATE TABLE `archived_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `item_code` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `department_name` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `archived_by` varchar(255) DEFAULT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `item_table_id` int(11) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_id` (`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- Table archived_items is empty (0 rows)

-- ================================================
-- Table: borrow_history
-- ================================================

DROP TABLE IF EXISTS `borrow_history`;
CREATE TABLE `borrow_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `borrow_id` varchar(50) NOT NULL,
  `borrower_name` varchar(255) NOT NULL,
  `borrower_email` varchar(255) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `department_name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `quantity_borrowed` int(11) NOT NULL DEFAULT 1,
  `borrow_date` date NOT NULL,
  `due_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `status` enum('active','returned','overdue') DEFAULT 'active',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `purpose` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `borrow_id` (`borrow_id`),
  KEY `idx_borrow_id` (`borrow_id`),
  KEY `idx_borrower_name` (`borrower_name`),
  KEY `idx_item_id` (`item_id`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_department` (`department_name`),
  CONSTRAINT `borrow_history_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table borrow_history is empty (0 rows)

-- ================================================
-- Table: buildings
-- ================================================

DROP TABLE IF EXISTS `buildings`;
CREATE TABLE `buildings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `date_built` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table buildings (1 rows)
INSERT INTO `buildings` (`id`, `name`, `description`, `image_path`, `date_built`, `created_at`, `updated_at`) VALUES ('6', 'Building 1', '', 'uploads/buildings/building_68fe08b3cf78b4.12649113.jpg', '2025-10-26', '2025-10-26 19:40:35', '2025-10-26 19:40:35');

-- ================================================
-- Table: categories
-- ================================================

DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `account` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table categories (1 rows)
INSERT INTO `categories` (`id`, `name`, `account`, `created_at`) VALUES ('14', 'Computer Peripherals', 'superadmin', '2025-10-26 19:41:26');

-- ================================================
-- Table: departments
-- ================================================

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table departments (4 rows)
INSERT INTO `departments` (`id`, `name`, `created_at`, `updated_at`) VALUES ('8', 'Student Learning Resource Center (SLRC)', '2025-10-26 18:51:32', '2025-10-26 18:51:32');
INSERT INTO `departments` (`id`, `name`, `created_at`, `updated_at`) VALUES ('9', 'ICT Equipment', '2025-10-26 19:28:52', '2025-10-26 19:28:52');
INSERT INTO `departments` (`id`, `name`, `created_at`, `updated_at`) VALUES ('10', 'Science Equipment', '2025-10-26 19:28:52', '2025-10-26 19:28:52');
INSERT INTO `departments` (`id`, `name`, `created_at`, `updated_at`) VALUES ('11', 'SPS Equipment', '2025-10-26 19:39:42', '2025-10-26 19:39:42');

-- ================================================
-- Table: floors
-- ================================================

DROP TABLE IF EXISTS `floors`;
CREATE TABLE `floors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `building_id` int(11) NOT NULL,
  `floor_number` int(11) NOT NULL,
  `floor_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_floors_building` (`building_id`),
  CONSTRAINT `fk_floors_building` FOREIGN KEY (`building_id`) REFERENCES `buildings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table floors (1 rows)
INSERT INTO `floors` (`id`, `building_id`, `floor_number`, `floor_name`, `description`, `created_at`, `updated_at`) VALUES ('6', '6', '3', 'Floor2', '', '2025-10-26 19:40:45', '2025-10-26 19:40:45');

-- ================================================
-- Table: item_requests
-- ================================================

DROP TABLE IF EXISTS `item_requests`;
CREATE TABLE `item_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requested_by` varchar(255) NOT NULL,
  `department_name` varchar(255) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- Table item_requests is empty (0 rows)

-- ================================================
-- Table: item_tables
-- ================================================

DROP TABLE IF EXISTS `item_tables`;
CREATE TABLE `item_tables` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `department_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `table_image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `item_tables_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table item_tables (2 rows)
INSERT INTO `item_tables` (`id`, `table_name`, `category`, `department_id`, `description`, `table_image_path`, `created_at`, `updated_at`) VALUES ('12', 'Monitor', 'Computer Peripherals', '9', '', 'uploads/item_tables/table_1761478904_68fe08f83adc5.png', '2025-10-26 19:41:44', '2025-10-26 19:41:44');
INSERT INTO `item_tables` (`id`, `table_name`, `category`, `department_id`, `description`, `table_image_path`, `created_at`, `updated_at`) VALUES ('13', 'asd', 'Computer Peripherals', '9', '', NULL, '2025-10-27 21:53:12', '2025-10-27 21:53:12');

-- ================================================
-- Table: items
-- ================================================

DROP TABLE IF EXISTS `items`;
CREATE TABLE `items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_code` varchar(50) DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `department_id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `location` varchar(100) NOT NULL,
  `status` enum('Working','Under Maintenance','Broken','Lost') DEFAULT 'Working',
  `description` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `item_table_id` int(11) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_code` (`item_code`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `items_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=149 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table items (6 rows)
INSERT INTO `items` (`id`, `item_code`, `name`, `department_id`, `category`, `quantity`, `location`, `status`, `description`, `image_path`, `item_table_id`, `qr_code`, `created_at`, `updated_at`) VALUES ('143', 'MON-CO-20251026-CEF2', 'Monitor', '9', 'Computer Peripherals', '1', 'Building 1 - Floor 3 - Comlab', 'Working', '', NULL, '12', 'qr_codes/qr_item_143_1761478912.png', '2025-10-26 19:41:52', '2025-10-26 19:41:54');
INSERT INTO `items` (`id`, `item_code`, `name`, `department_id`, `category`, `quantity`, `location`, `status`, `description`, `image_path`, `item_table_id`, `qr_code`, `created_at`, `updated_at`) VALUES ('144', 'MON-CO-20251026-8612', 'Monitor', '9', 'Computer Peripherals', '1', 'Building 1 - Floor 3 - Comlab', 'Working', '', NULL, '12', 'qr_codes/qr_item_144_1761478914.png', '2025-10-26 19:41:54', '2025-10-26 19:41:57');
INSERT INTO `items` (`id`, `item_code`, `name`, `department_id`, `category`, `quantity`, `location`, `status`, `description`, `image_path`, `item_table_id`, `qr_code`, `created_at`, `updated_at`) VALUES ('145', 'MON-CO-20251026-8033', 'Monitor', '9', 'Computer Peripherals', '1', 'Building 1 - Floor 3 - Comlab', 'Working', '', NULL, '12', 'qr_codes/qr_item_145_1761478917.png', '2025-10-26 19:41:57', '2025-10-26 19:41:58');
INSERT INTO `items` (`id`, `item_code`, `name`, `department_id`, `category`, `quantity`, `location`, `status`, `description`, `image_path`, `item_table_id`, `qr_code`, `created_at`, `updated_at`) VALUES ('146', 'MON-CO-20251026-0CC0', 'Monitor', '9', 'Computer Peripherals', '1', 'Building 1 - Floor 3 - Comlab', 'Working', '', NULL, '12', 'qr_codes/qr_item_146_1761478918.png', '2025-10-26 19:41:58', '2025-10-26 19:42:01');
INSERT INTO `items` (`id`, `item_code`, `name`, `department_id`, `category`, `quantity`, `location`, `status`, `description`, `image_path`, `item_table_id`, `qr_code`, `created_at`, `updated_at`) VALUES ('147', 'MON-CO-20251026-1F3F', 'Monitor', '9', 'Computer Peripherals', '1', 'Building 1 - Floor 3 - Comlab', 'Working', '', NULL, '12', 'qr_codes/qr_item_147_1761478921.png', '2025-10-26 19:42:01', '2025-10-27 22:30:17');
INSERT INTO `items` (`id`, `item_code`, `name`, `department_id`, `category`, `quantity`, `location`, `status`, `description`, `image_path`, `item_table_id`, `qr_code`, `created_at`, `updated_at`) VALUES ('148', 'MON-CO-20251027-88C0', 'Monitor', '9', 'Computer Peripherals', '1', 'Building 1 - Floor 3 - Comlab', 'Working', '', NULL, '12', 'qr_codes/qr_item_148_1761578353.png', '2025-10-27 23:19:13', '2025-10-27 23:19:15');

-- ================================================
-- Table: rooms
-- ================================================

DROP TABLE IF EXISTS `rooms`;
CREATE TABLE `rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `floor_id` int(11) NOT NULL,
  `room_number` varchar(50) DEFAULT NULL,
  `room_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_rooms_floor` (`floor_id`),
  CONSTRAINT `fk_rooms_floor` FOREIGN KEY (`floor_id`) REFERENCES `floors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table rooms (1 rows)
INSERT INTO `rooms` (`id`, `floor_id`, `room_number`, `room_name`, `description`, `capacity`, `created_at`, `updated_at`) VALUES ('7', '6', '401', 'Comlab', '', '20', '2025-10-26 19:40:53', '2025-10-26 19:40:53');

-- ================================================
-- Table: super_admin
-- ================================================

DROP TABLE IF EXISTS `super_admin`;
CREATE TABLE `super_admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT 'IT',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_permanent` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table super_admin (1 rows)
INSERT INTO `super_admin` (`id`, `username`, `password`, `email`, `department`, `status`, `created_at`, `updated_at`, `is_permanent`) VALUES ('43', 'superadmin', '$2y$10$ieaI73Hqf0OwOSBjU84CduIyJyC./i0Miu4JABciLwQirVbqTn9OG', 'superadmin@ocabis.com', 'IT Department', 'active', '2024-01-01 00:00:00', '2025-10-27 23:05:01', '1');

-- ================================================
-- Table: user_sessions
-- ================================================

DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`),
  KEY `user_id` (`user_id`),
  KEY `is_active` (`is_active`),
  KEY `last_activity` (`last_activity`),
  KEY `idx_user_active_sessions` (`user_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- Data for table user_sessions (4 rows)
INSERT INTO `user_sessions` (`id`, `user_id`, `session_id`, `ip_address`, `user_agent`, `login_time`, `last_activity`, `is_active`) VALUES ('98', '43', '4k3a7vrbq7t7nmseuahjbvd9eg', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-26 19:35:40', '2025-10-26 19:51:46', '1');
INSERT INTO `user_sessions` (`id`, `user_id`, `session_id`, `ip_address`, `user_agent`, `login_time`, `last_activity`, `is_active`) VALUES ('99', '13', 'mjkrs3bjh3bgnfemsor1plnuve', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 03:04:57', '2025-10-27 03:05:04', '1');
INSERT INTO `user_sessions` (`id`, `user_id`, `session_id`, `ip_address`, `user_agent`, `login_time`, `last_activity`, `is_active`) VALUES ('100', '43', '0qg8eu7erh8fir7j8cqt55meaj', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 21:12:29', '2025-10-27 22:45:08', '1');
INSERT INTO `user_sessions` (`id`, `user_id`, `session_id`, `ip_address`, `user_agent`, `login_time`, `last_activity`, `is_active`) VALUES ('101', '43', '1a14lmf2eps3ovm89r74o17er3', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 23:05:01', '2025-10-28 00:26:17', '1');

-- ================================================
-- Table: users
-- ================================================

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `role` enum('user','admin') DEFAULT 'user',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table users (9 rows)
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('12', 'icampos', '$2y$10$T7o4jTn7sariJOj70QBuau1jHJndm2Y7VQwa0oaHf3gdtf.9gP8Pe', 'Science Laboratory', 'active', 'approved', 'asd@gmail.com', '2025-09-05 15:31:11', NULL, NULL, '0', 'user');
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('13', 'admin', '$2y$10$o2iEUQz/vXlb3XFm3qyLIuTnWzEYBgfWurudwf2jAwh9r9G1RPLyG', 'Information Technology', 'active', 'approved', 'admin@gmail.com', '2025-10-27 03:04:57', NULL, NULL, '1', 'admin');
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('14', 'capstone', '$2y$10$wzzupK4ZaP/.TlhItKle0uhkY7lYgrq0ViOJdiJoHC1H8YH0cXBKK', 'Information Technology', 'active', 'approved', 'capstone12025@gmail.com', '2025-09-15 12:46:05', NULL, NULL, '0', 'user');
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('15', 'wizz', '$2y$10$YjQ9lc96A7GvYLE69ykDJejsHSiR9JILXgAUllmnvaSMos2lx4k8O', 'Information Technology', 'active', 'approved', 'johnwisdomdeguit@gmail.com', '2025-10-08 11:31:02', NULL, NULL, '0', 'user');
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('26', 'anne', '$2y$10$JC3G/wZK//lH6IMxd/TAt.IQs6JqwbucoPQ5g2.aLVY4.Zpy6sYwe', 'ICT Equipment', 'active', 'approved', 'joyannemiaco@themanilatimescollege.com', '2025-10-24 01:29:04', NULL, NULL, '0', 'user');
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('27', 'mike', '$2y$10$wn8u8rYOTIMk/4/qyI8W1.RWQHPLdTuM5flra.JVQYOtVSIRqt7M6', 'ICT Equipment', 'active', 'approved', 'ronaldguiyab4@gmail.com', '2025-10-26 18:44:50', NULL, NULL, '0', 'user');
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('28', 'sasa', '$2y$10$nVZeKgwzWldyAt1H5lYu5OCTYpNdO70cz3boeDO/sR3RTLPsxMNfK', 'ICT Equipment', 'inactive', 'pending', 'johnroyce@ms365air.pro', '2025-10-23 23:04:15', NULL, NULL, '0', 'user');
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('29', 'arthur', '$2y$10$47jbWiEdB//EsUaS1JxSBu8kKEKym4lsDsGfp.Ul60zBTip3u1ZvS', 'ICT Equipment', 'inactive', 'pending', 'arthur@gmail.com', '2025-10-25 11:45:34', NULL, NULL, '0', 'user');
INSERT INTO `users` (`id`, `username`, `password`, `department`, `status`, `approval_status`, `email`, `created_at`, `reset_token`, `reset_expires`, `is_admin`, `role`) VALUES ('30', 'Arthurr', '$2y$10$m2ev9TV/NwNm4jWFn28kteOipcHeXMKanU3.lYEFXdTrIDst8SfdS', 'ICT Equipment', 'active', 'approved', 'avecilla.art@gmail.com', '2025-10-25 13:43:13', '8a9891192d23b81dc97f32e70952bed9b92c39e33fd7e8e4b383992e6f3495dfc731eda954111bc9dcf22f15bc5fab86d136', '2025-10-25 08:02:54', '0', 'user');

SET FOREIGN_KEY_CHECKS = 1;
-- Ensure super admin account exists
-- This section guarantees the super admin account is available after import
-- Add is_permanent column to super_admin table
-- Note: If column already exists, this will show an error but can be ignored
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'ocabis' 
    AND TABLE_NAME = 'super_admin' 
    AND COLUMN_NAME = 'is_permanent'
);
SET @query = IF(@col_exists = 0, 'ALTER TABLE super_admin ADD COLUMN is_permanent tinyint(1) DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP TRIGGER IF EXISTS prevent_super_admin_deletion;
CREATE TRIGGER prevent_super_admin_deletion
    BEFORE DELETE ON super_admin
    FOR EACH ROW
    BEGIN
        IF OLD.is_permanent = 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete permanent super admin account';
        END IF;
    END;

INSERT INTO super_admin (username, email, password, department, status, is_permanent, created_at) 
VALUES ('superadmin', 'superadmin@ocabis.com', '$2y$10$D/WGPRTVPHsDOZavJuGjwOaM1AbCcg5X.AgCUc6k5If/3mfUtarfi', 'IT Department', 'active', 1, '2024-01-01 00:00:00')
ON DUPLICATE KEY UPDATE 
    email = 'superadmin@ocabis.com',
    password = '$2y$10$D/WGPRTVPHsDOZavJuGjwOaM1AbCcg5X.AgCUc6k5If/3mfUtarfi',
    department = 'IT Department',
    status = 'active',
    is_permanent = 1,
    updated_at = CURRENT_TIMESTAMP;

COMMIT;

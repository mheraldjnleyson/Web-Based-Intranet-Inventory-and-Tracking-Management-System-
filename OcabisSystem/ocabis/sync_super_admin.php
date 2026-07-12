<?php
// Script to sync permanent super admin with database
// This ensures the permanent account exists in the database after import

function syncPermanentSuperAdmin() {
    try {
        require_once '../db_connect.php';
        
        // Check if $conn is defined and connected
        if (!isset($conn) || $conn->connect_error) {
            return false; // Database not connected
        }
        
        // Load permanent super admin credentials
        $super_admin_config = include 'super_admin_config.php';
        $permanent_admin = $super_admin_config['super_admin'];
        
        // Check if super_admin table exists
        $check_table = $conn->query("SHOW TABLES LIKE 'super_admin'");
        if ($check_table->num_rows === 0) {
            // Create super_admin table if it doesn't exist
            $create_table = "CREATE TABLE `super_admin` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL,
                `email` varchar(100) NOT NULL,
                `password` varchar(255) NOT NULL,
                `department` varchar(100) DEFAULT NULL,
                `status` enum('active','inactive') DEFAULT 'active',
                `is_permanent` tinyint(1) DEFAULT 0,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `username` (`username`),
                UNIQUE KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            $conn->query($create_table);
        }
        
        // Add is_permanent column if it doesn't exist
        $add_column = "ALTER TABLE super_admin ADD COLUMN IF NOT EXISTS is_permanent tinyint(1) DEFAULT 0";
        $conn->query($add_column);
        
        // Create protection trigger
        $conn->query("DROP TRIGGER IF EXISTS prevent_super_admin_deletion");
        $create_trigger = "CREATE TRIGGER prevent_super_admin_deletion
            BEFORE DELETE ON super_admin
            FOR EACH ROW
            BEGIN
                IF OLD.is_permanent = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete permanent super admin account';
                END IF;
            END";
        $conn->query($create_trigger);
        
        // Always ensure permanent super admin exists in database
        $password_hash = password_hash($permanent_admin['password'], PASSWORD_DEFAULT);
        
        // Use INSERT ... ON DUPLICATE KEY UPDATE to ensure the account exists
        $insert_sql = "INSERT INTO super_admin (username, email, password, department, status, is_permanent, created_at) 
                       VALUES (?, ?, ?, ?, ?, 1, ?)
                       ON DUPLICATE KEY UPDATE 
                       email = VALUES(email),
                       password = VALUES(password),
                       department = VALUES(department),
                       status = VALUES(status),
                       is_permanent = 1,
                       updated_at = CURRENT_TIMESTAMP";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("ssssss", 
            $permanent_admin['username'],
            $permanent_admin['email'],
            $password_hash,
            $permanent_admin['department'],
            $permanent_admin['status'],
            $permanent_admin['created_at']
        );
        
        $stmt->execute();
        $stmt->close();
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Auto-sync when this file is included
syncPermanentSuperAdmin();
?>
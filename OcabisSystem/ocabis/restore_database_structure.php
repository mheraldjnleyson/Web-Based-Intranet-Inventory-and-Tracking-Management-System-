<?php
// Database Structure Restoration Script
// This will recreate the basic database structure if it's missing

echo "<h1>Database Structure Restoration</h1>";

try {
    // Connect to MySQL without specifying database
    $conn = @new mysqli('localhost', 'root', '');
    
    if ($conn->connect_error) {
        echo "<p style='color: red;'>❌ MySQL connection failed: " . $conn->connect_error . "</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Connected to MySQL</p>";
    
    // Create database if it doesn't exist
    $conn->query("CREATE DATABASE IF NOT EXISTS ocabis");
    $conn->query("USE ocabis");
    
    echo "<p style='color: green;'>✅ Using 'ocabis' database</p>";
    
    // Create basic tables
    $tables = [
        'users' => "
            CREATE TABLE IF NOT EXISTS `users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL,
                `email` varchar(100) NOT NULL,
                `password` varchar(255) NOT NULL,
                `first_name` varchar(50) NOT NULL,
                `last_name` varchar(50) NOT NULL,
                `department` varchar(100) DEFAULT NULL,
                `role` enum('user','admin','super_admin') DEFAULT 'user',
                `status` enum('active','inactive','pending') DEFAULT 'pending',
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `username` (`username`),
                UNIQUE KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        
        'departments' => "
            CREATE TABLE IF NOT EXISTS `departments` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `description` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        
        'locations' => "
            CREATE TABLE IF NOT EXISTS `locations` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `description` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        
        'categories' => "
            CREATE TABLE IF NOT EXISTS `categories` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `description` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        
        'item_tables' => "
            CREATE TABLE IF NOT EXISTS `item_tables` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `description` text,
                `category_id` int(11) DEFAULT NULL,
                `location_id` int(11) DEFAULT NULL,
                `department_id` int(11) DEFAULT NULL,
                `image` varchar(255) DEFAULT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `category_id` (`category_id`),
                KEY `location_id` (`location_id`),
                KEY `department_id` (`department_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        
        'items' => "
            CREATE TABLE IF NOT EXISTS `items` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `item_table_id` int(11) NOT NULL,
                `status` enum('Available','Borrowed','Broken','Under Maintenance') DEFAULT 'Available',
                `notes` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `item_table_id` (`item_table_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        
        'borrow_history' => "
            CREATE TABLE IF NOT EXISTS `borrow_history` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `item_id` int(11) NOT NULL,
                `borrow_date` timestamp DEFAULT CURRENT_TIMESTAMP,
                `return_date` timestamp NULL DEFAULT NULL,
                `status` enum('active','returned','overdue') DEFAULT 'active',
                `notes` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `user_id` (`user_id`),
                KEY `item_id` (`item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        
        'super_admin' => "
            CREATE TABLE IF NOT EXISTS `super_admin` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ",
        
        'user_sessions' => "
            CREATE TABLE IF NOT EXISTS `user_sessions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `session_id` varchar(255) NOT NULL,
                `ip_address` varchar(45) DEFAULT NULL,
                `user_agent` text,
                `is_active` tinyint(1) DEFAULT 1,
                `last_activity` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `session_id` (`session_id`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        "
    ];
    
    // Create tables
    foreach ($tables as $table_name => $sql) {
        if ($conn->query($sql)) {
            echo "<p style='color: green;'>✅ Table '$table_name' created/verified</p>";
        } else {
            echo "<p style='color: red;'>❌ Failed to create table '$table_name': " . $conn->error . "</p>";
        }
    }
    
    // Insert default super admin
    $super_admin_config = include 'super_admin_config.php';
    $permanent_admin = $super_admin_config['super_admin'];
    
    $password_hash = password_hash($permanent_admin['password'], PASSWORD_DEFAULT);
    
    $insert_super_admin = "INSERT IGNORE INTO super_admin (username, email, password, department, status, is_permanent, created_at) 
                           VALUES (?, ?, ?, ?, ?, 1, ?)";
    $stmt = $conn->prepare($insert_super_admin);
    $stmt->bind_param("ssssss", 
        $permanent_admin['username'],
        $permanent_admin['email'],
        $password_hash,
        $permanent_admin['department'],
        $permanent_admin['status'],
        $permanent_admin['created_at']
    );
    
    if ($stmt->execute()) {
        echo "<p style='color: green;'>✅ Super admin account created/verified</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Super admin account already exists or error occurred</p>";
    }
    $stmt->close();
    
    // Insert some sample data
    $sample_data = [
        "INSERT IGNORE INTO departments (name, description) VALUES ('IT Department', 'Information Technology Department')",
        "INSERT IGNORE INTO departments (name, description) VALUES ('HR Department', 'Human Resources Department')",
        "INSERT IGNORE INTO departments (name, description) VALUES ('Finance Department', 'Finance and Accounting Department')",
        "INSERT IGNORE INTO locations (name, description) VALUES ('Main Office', 'Main office building')",
        "INSERT IGNORE INTO locations (name, description) VALUES ('Warehouse', 'Storage warehouse')",
        "INSERT IGNORE INTO categories (name, description) VALUES ('Electronics', 'Electronic devices and equipment')",
        "INSERT IGNORE INTO categories (name, description) VALUES ('Furniture', 'Office furniture and fixtures')",
        "INSERT IGNORE INTO categories (name, description) VALUES ('Tools', 'Tools and equipment')"
    ];
    
    foreach ($sample_data as $sql) {
        if ($conn->query($sql)) {
            echo "<p style='color: green;'>✅ Sample data inserted</p>";
        }
    }
    
    $conn->close();
    
    echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46;'>✅ Database Structure Restored Successfully!</h3>";
    echo "<p>Your database now has:</p>";
    echo "<ul>";
    echo "<li>All required tables</li>";
    echo "<li>Super admin account (superadmin / admin123)</li>";
    echo "<li>Sample departments, locations, and categories</li>";
    echo "<li>Proper database structure for full functionality</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<p><a href='dashboard.php' style='background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>

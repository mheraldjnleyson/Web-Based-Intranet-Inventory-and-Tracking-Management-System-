<?php
// Script to fix empty super_admin table
session_start();

echo "<h1>Fix Super Admin Table</h1>";

try {
    require_once '../db_connect.php';
    
    if ($conn->connect_error) {
        echo "<p style='color: red;'>Database connection failed: " . $conn->connect_error . "</p>";
        exit;
    }
    
    echo "<h2>Step 1: Check Current State</h2>";
    
    // Check if super_admin table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'super_admin'");
    if ($check_table->num_rows === 0) {
        echo "<p style='color: orange;'>⚠️ super_admin table does not exist. Creating it...</p>";
        
        // Create super_admin table
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
        
        if ($conn->query($create_table)) {
            echo "<p style='color: green;'>✓ super_admin table created successfully</p>";
        } else {
            echo "<p style='color: red;'>✗ Failed to create super_admin table: " . $conn->error . "</p>";
            exit;
        }
    } else {
        echo "<p style='color: green;'>✓ super_admin table exists</p>";
    }
    
    // Check current records
    $count_query = "SELECT COUNT(*) as count FROM super_admin";
    $count_result = $conn->query($count_query);
    $count_row = $count_result->fetch_assoc();
    
    echo "<p>Current records in super_admin table: <strong>" . $count_row['count'] . "</strong></p>";
    
    if ($count_row['count'] > 0) {
        echo "<p>Existing records:</p>";
        $select_query = "SELECT id, username, email, department, status, is_permanent, created_at FROM super_admin";
        $select_result = $conn->query($select_query);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Department</th><th>Status</th><th>Is Permanent</th><th>Created</th></tr>";
        while ($row = $select_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['department']) . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . ($row['is_permanent'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<h2>Step 2: Add is_permanent Column</h2>";
    
    // Add is_permanent column if it doesn't exist
    $add_column = "ALTER TABLE super_admin ADD COLUMN IF NOT EXISTS is_permanent tinyint(1) DEFAULT 0";
    if ($conn->query($add_column)) {
        echo "<p style='color: green;'>✓ is_permanent column added/verified</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Column might already exist: " . $conn->error . "</p>";
    }
    
    echo "<h2>Step 3: Create Protection Trigger</h2>";
    
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
    
    if ($conn->query($create_trigger)) {
        echo "<p style='color: green;'>✓ Protection trigger created successfully</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to create trigger: " . $conn->error . "</p>";
    }
    
    echo "<h2>Step 4: Insert Super Admin Account</h2>";
    
    // Load permanent super admin credentials
    $super_admin_config = include 'super_admin_config.php';
    $permanent_admin = $super_admin_config['super_admin'];
    
    echo "<p>Using credentials from super_admin_config.php:</p>";
    echo "<ul>";
    echo "<li>Username: " . htmlspecialchars($permanent_admin['username']) . "</li>";
    echo "<li>Email: " . htmlspecialchars($permanent_admin['email']) . "</li>";
    echo "<li>Department: " . htmlspecialchars($permanent_admin['department']) . "</li>";
    echo "<li>Status: " . htmlspecialchars($permanent_admin['status']) . "</li>";
    echo "</ul>";
    
    // Insert super admin account
    $password_hash = password_hash($permanent_admin['password'], PASSWORD_DEFAULT);
    
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
    
    if ($stmt->execute()) {
        echo "<p style='color: green;'>✓ Super admin account inserted/updated successfully</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to insert super admin account: " . $stmt->error . "</p>";
    }
    
    $stmt->close();
    
    echo "<h2>Step 5: Verify Final State</h2>";
    
    // Verify the account was created
    $verify_query = "SELECT id, username, email, department, status, is_permanent, created_at FROM super_admin WHERE username = ?";
    $verify_stmt = $conn->prepare($verify_query);
    $verify_stmt->bind_param("s", $permanent_admin['username']);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0) {
        $verify_row = $verify_result->fetch_assoc();
        echo "<p style='color: green;'>✓ Super admin account verified successfully!</p>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        echo "<tr><td>ID</td><td>" . htmlspecialchars($verify_row['id']) . "</td></tr>";
        echo "<tr><td>Username</td><td>" . htmlspecialchars($verify_row['username']) . "</td></tr>";
        echo "<tr><td>Email</td><td>" . htmlspecialchars($verify_row['email']) . "</td></tr>";
        echo "<tr><td>Department</td><td>" . htmlspecialchars($verify_row['department']) . "</td></tr>";
        echo "<tr><td>Status</td><td>" . htmlspecialchars($verify_row['status']) . "</td></tr>";
        echo "<tr><td>Is Permanent</td><td>" . ($verify_row['is_permanent'] ? 'Yes' : 'No') . "</td></tr>";
        echo "<tr><td>Created At</td><td>" . htmlspecialchars($verify_row['created_at']) . "</td></tr>";
        echo "</table>";
    } else {
        echo "<p style='color: red;'>✗ Super admin account not found after insertion</p>";
    }
    
    $verify_stmt->close();
    
    echo "<h2>Step 6: Test Login</h2>";
    
    // Test password verification
    $test_query = "SELECT password FROM super_admin WHERE username = ?";
    $test_stmt = $conn->prepare($test_query);
    $test_stmt->bind_param("s", $permanent_admin['username']);
    $test_stmt->execute();
    $test_result = $test_stmt->get_result();
    
    if ($test_result->num_rows > 0) {
        $test_row = $test_result->fetch_assoc();
        if (password_verify($permanent_admin['password'], $test_row['password'])) {
            echo "<p style='color: green;'>✓ Password verification successful - login should work</p>";
        } else {
            echo "<p style='color: red;'>✗ Password verification failed</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Could not find account for password test</p>";
    }
    
    $test_stmt->close();
    
    echo "<h2>✅ Fix Complete!</h2>";
    echo "<p>Your super admin account has been successfully created/updated.</p>";
    echo "<p><strong>Login Credentials:</strong></p>";
    echo "<ul>";
    echo "<li><strong>Username:</strong> " . htmlspecialchars($permanent_admin['username']) . "</li>";
    echo "<li><strong>Password:</strong> " . htmlspecialchars($permanent_admin['password']) . "</li>";
    echo "</ul>";
    
    echo "<p><a href='login.php'>Go to Login Page</a> | <a href='database_export.php'>Go to Database Export</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>

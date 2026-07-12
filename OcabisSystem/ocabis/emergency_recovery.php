<?php
// Emergency Recovery System
// This provides access to database export/import even when main database is deleted

// Suppress all PHP warnings and errors to hide database connection issues
error_reporting(0);
ini_set('display_errors', 0);

session_start();

// Emergency credentials (hardcoded for maximum security)
$EMERGENCY_USERNAME = 'emergency';
$EMERGENCY_PASSWORD = 'recovery2024';
$EMERGENCY_ACCESS = false;

// Handle emergency login
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emergency_login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username === $EMERGENCY_USERNAME && $password === $EMERGENCY_PASSWORD) {
        $_SESSION['emergency_access'] = true;
        $_SESSION['emergency_user'] = $username;
        $EMERGENCY_ACCESS = true;
    } else {
        $login_error = "Invalid emergency credentials.";
    }
}

// Check if already logged in
if (isset($_SESSION['emergency_access']) && $_SESSION['emergency_access'] === true) {
    $EMERGENCY_ACCESS = true;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: emergency_recovery.php");
    exit();
}

// Database connection status
$db_connected = false;
$db_error = '';
$conn = null;

if ($EMERGENCY_ACCESS) {
    try {
        // Suppress database connection errors to hide warnings
        error_reporting(0);
        ini_set('display_errors', 0);
        
        require_once '../db_connect.php';
        if (!$conn->connect_error) {
            $result = $conn->query("SELECT 1");
            if ($result !== false) {
                $db_connected = true;
            } else {
                $db_connected = false;
            }
        } else {
            $db_connected = false;
        }
        
        // Re-enable error reporting
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    } catch (Exception $e) {
        $db_connected = false;
    }
}

// Handle database operations
$operation_message = '';
$operation_error = '';

// Handle database export
if ($EMERGENCY_ACCESS && isset($_GET['export']) && $db_connected) {
    $export_type = $_GET['export'];
    
    if ($export_type === 'sql') {
        $db_name = "ocabis";
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "ocabis_emergency_backup_{$timestamp}.sql";
        
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        
        // Get all tables
        $tables_query = "SHOW TABLES";
        $tables_result = $conn->query($tables_query);
        
        echo "-- OCABIS Emergency Database Backup\n";
        echo "-- Export Date: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Exported by: Emergency Recovery System\n";
        echo "-- This backup includes all data and super admin account\n\n";
        echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        echo "START TRANSACTION;\n\n";
        
        while ($row = $tables_result->fetch_array(MYSQLI_NUM)) {
            $table = $row[0];
            
            // Get table structure
            $create_table = $conn->query("SHOW CREATE TABLE `$table`");
            $create_row = $create_table->fetch_row();
            echo "\n-- Structure for table `$table`\n";
            echo "DROP TABLE IF EXISTS `$table`;\n";
            echo $create_row[1] . ";\n\n";
            
            // Get table data
            $data_query = "SELECT * FROM `$table`";
            $data_result = $conn->query($data_query);
            
            if ($data_result->num_rows > 0) {
                echo "-- Data for table `$table`\n";
                
                while ($data_row = $data_result->fetch_assoc()) {
                    $fields = array_keys($data_row);
                    $values = array_map(function($value) use ($conn) {
                        return is_null($value) ? 'NULL' : "'" . $conn->real_escape_string($value) . "'";
                    }, array_values($data_row));
                    
                    echo "INSERT INTO `$table` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $values) . ");\n";
                }
                echo "\n";
            }
        }
        
        // Ensure super admin account is always included
        echo "-- Ensure super admin account exists\n";
        echo "-- This section guarantees the super admin account is available after import\n";
        
        // Load permanent super admin credentials
        $super_admin_config = include 'super_admin_config.php';
        $permanent_admin = $super_admin_config['super_admin'];
        
        // Add is_permanent column if it doesn't exist
        echo "ALTER TABLE super_admin ADD COLUMN IF NOT EXISTS is_permanent tinyint(1) DEFAULT 0;\n";
        
        // Create protection trigger
        echo "DROP TRIGGER IF EXISTS prevent_super_admin_deletion;\n";
        echo "CREATE TRIGGER prevent_super_admin_deletion\n";
        echo "    BEFORE DELETE ON super_admin\n";
        echo "    FOR EACH ROW\n";
        echo "    BEGIN\n";
        echo "        IF OLD.is_permanent = 1 THEN\n";
        echo "            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete permanent super admin account';\n";
        echo "        END IF;\n";
        echo "    END;\n\n";
        
        // Insert or update super admin account
        $password_hash = password_hash($permanent_admin['password'], PASSWORD_DEFAULT);
        echo "INSERT INTO super_admin (username, email, password, department, status, is_permanent, created_at) \n";
        echo "VALUES ('{$permanent_admin['username']}', '{$permanent_admin['email']}', '{$password_hash}', '{$permanent_admin['department']}', '{$permanent_admin['status']}', 1, '{$permanent_admin['created_at']}')\n";
        echo "ON DUPLICATE KEY UPDATE \n";
        echo "    email = '{$permanent_admin['email']}',\n";
        echo "    password = '{$password_hash}',\n";
        echo "    department = '{$permanent_admin['department']}',\n";
        echo "    status = '{$permanent_admin['status']}',\n";
        echo "    is_permanent = 1,\n";
        echo "    updated_at = CURRENT_TIMESTAMP;\n\n";
        
        echo "COMMIT;\n";
        exit();
    }
}

// Handle database import
if ($EMERGENCY_ACCESS && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_db'])) {
    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['sql_file'];
        $tmp_file = $file['tmp_name'];
        
        // Read SQL file
        $sql = file_get_contents($tmp_file);
        
        if ($sql) {
            try {
                // Create a new connection without specifying database
                $import_conn = @new mysqli('localhost', 'root', '');
                
                if ($import_conn->connect_error) {
                    throw new Exception("Connection failed: " . $import_conn->connect_error);
                }
                
                // Create database if it doesn't exist
                $import_conn->query("CREATE DATABASE IF NOT EXISTS ocabis");
                $import_conn->query("USE ocabis");
                
                // Disable foreign key checks temporarily
                $import_conn->query("SET FOREIGN_KEY_CHECKS = 0");
                $import_conn->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
                $import_conn->query("SET AUTOCOMMIT = 0");
                
                // Remove any stored procedures/functions temporarily to avoid syntax errors
                $sql_clean = preg_replace('/DELIMITER\s+.*/i', '', $sql);
                
                // Use multi_query for better SQL execution
                if ($import_conn->multi_query($sql_clean)) {
                    do {
                        // Consume the result set
                        if ($result = $import_conn->store_result()) {
                            $result->free();
                        }
                        
                        // Check for errors
                        if ($import_conn->errno) {
                            error_log("MySQL error: " . $import_conn->error);
                        }
                    } while ($import_conn->next_result());
                }
                
                // Re-enable foreign key checks
                $import_conn->query("SET FOREIGN_KEY_CHECKS = 1");
                $import_conn->query("SET AUTOCOMMIT = 1");
                
                // Ensure super admin account exists after import
                try {
                    $super_admin_config = include 'super_admin_config.php';
                    $permanent_admin = $super_admin_config['super_admin'];
                    
                    // Check if super_admin table exists
                    $check_table = $import_conn->query("SHOW TABLES LIKE 'super_admin'");
                    if ($check_table->num_rows === 0) {
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
                        
                        $import_conn->query($create_table);
                    }
                    
                    // Add is_permanent column if it doesn't exist
                    // First check if column exists
                    $check_column = $import_conn->query("SHOW COLUMNS FROM super_admin LIKE 'is_permanent'");
                    if ($check_column->num_rows === 0) {
                        $add_column = "ALTER TABLE super_admin ADD COLUMN is_permanent tinyint(1) DEFAULT 0";
                        $import_conn->query($add_column);
                    }
                    
                    // Create protection trigger
                    $import_conn->query("DROP TRIGGER IF EXISTS prevent_super_admin_deletion");
                    $create_trigger = "CREATE TRIGGER prevent_super_admin_deletion
                        BEFORE DELETE ON super_admin
                        FOR EACH ROW
                        BEGIN
                            IF OLD.is_permanent = 1 THEN
                                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete permanent super admin account';
                            END IF;
                        END";
                    $import_conn->query($create_trigger);
                    
                    // Ensure permanent super admin exists
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
                    $stmt = $import_conn->prepare($insert_sql);
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
                    
                } catch (Exception $e) {
                    error_log("Super admin sync error: " . $e->getMessage());
                }
                
                $import_conn->close();
                
                $operation_message = "Database imported successfully! All tables and data have been restored. Please refresh the page to see your data.";
            } catch (Exception $e) {
                $operation_error = "Import failed: " . $e->getMessage();
            }
        } else {
            $operation_error = "Failed to read SQL file.";
        }
    } else {
        $operation_error = "Please select a valid SQL file.";
    }
}

// Handle super admin sync
if ($EMERGENCY_ACCESS && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_super_admin'])) {
    try {
        // Only sync if database is connected
        if ($db_connected) {
            include 'sync_super_admin.php';
            $operation_message = "Super admin account synchronized successfully!";
        } else {
            $operation_error = "Cannot sync super admin account. Database is not connected. Please import a database backup first.";
        }
    } catch (Exception $e) {
        $operation_error = "Sync failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/logo.png">
    <title>Emergency Recovery System - OCABIS</title>
    <link rel="stylesheet" href="Css/login.css" />
    <link rel="stylesheet" href="Css/dashboard.css" />
    <link rel="stylesheet" href="Css/profile_dropdown.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .emergency-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            box-sizing: border-box;
        }
        
        .emergency-login-box {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        .emergency-login-box .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .emergency-login-box .logo-section img {
            height: 60px;
            width: auto;
            margin-bottom: 15px;
        }
        
        .emergency-login-box h2 {
            color: #1f2937;
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        
        .emergency-login-box p {
            color: #6b7280;
            margin: 0 0 20px 0;
        }
        
        .emergency-credentials {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .emergency-credentials h3 {
            margin: 0 0 10px 0;
            color: #0369a1;
            font-size: 16px;
        }
        
        .emergency-credentials p {
            margin: 5px 0;
            color: #0369a1;
            font-size: 14px;
        }
        
        /* Emergency Dashboard Styles - Matching Login Page Design */
        .export-options, .import-options {
            margin: 20px 0;
        }
        
        .export-info-box, .warning-info-box {
            background: #f8fafc;
            border-left: 4px solid #10b981;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .warning-info-box {
            border-left-color: #f59e0b;
            background: #fef3c7;
        }
        
        .export-info-box h3, .warning-info-box h3 {
            margin: 0 0 10px 0;
            color: #1f2937;
            font-size: 16px;
        }
        
        .export-info-box p, .warning-info-box p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }
        
        .export-button {
            display: block;
            width: 100%;
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            padding: 15px 20px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: transform 0.2s;
            margin-bottom: 20px;
        }
        
        .export-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }
        
        .export-button i {
            margin-right: 10px;
        }
        
        .export-button span {
            display: block;
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .export-button small {
            display: block;
            font-size: 12px;
            opacity: 0.9;
        }
        
        .sync-section {
            margin: 30px 0;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .sync-section h3 {
            margin: 0 0 10px 0;
            color: #1f2937;
            font-size: 16px;
        }
        
        .sync-section p {
            margin: 0 0 15px 0;
            color: #6b7280;
            font-size: 14px;
        }
        
        .sync-button {
            background: #3b82f6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .sync-button:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }
        
        .sync-button i {
            margin-right: 8px;
        }
        
        .emergency-info {
            margin: 30px 0;
            padding: 20px;
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
        }
        
        .emergency-info h3 {
            margin: 0 0 15px 0;
            color: #0369a1;
            font-size: 16px;
        }
        
        .emergency-info ul {
            margin: 0;
            padding-left: 20px;
            color: #0369a1;
        }
        
        .emergency-info ul li {
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .signup-text {
            text-align: center;
            margin-top: 20px;
            color: #6b7280;
        }
        
        .signup-text a {
            color: #e53e3e;
            text-decoration: none;
            font-weight: 500;
        }
        
        .signup-text a:hover {
            text-decoration: underline;
        }
        
        /* Hide all PHP warnings and errors */
        .emergency-container * {
            box-sizing: border-box;
        }
        
        /* Ensure the login box is centered */
        .login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
            box-sizing: border-box;
        }
        
        .login-box {
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        /* Hide any PHP error messages */
        body {
            margin: 0;
            padding: 0;
        }
        
        /* Suppress any error output */
        .emergency-container {
            position: relative;
        }
        
        .emergency-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            z-index: -1;
        }

        /* Confirmation modal for critical actions */
        .confirm-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .confirm-modal-overlay.show {
            display: flex;
        }

        .confirm-modal {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35);
            max-width: 420px;
            width: 100%;
            padding: 24px 24px 20px;
        }

        .confirm-modal-header {
            display: flex;
            align-items: center;
            margin-bottom: 16px;
        }

        .confirm-modal-icon {
            height: 40px;
            width: 40px;
            border-radius: 999px;
            background: #fef3c7;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: #d97706;
        }

        .confirm-modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .confirm-modal-body {
            font-size: 14px;
            color: #4b5563;
            margin-bottom: 20px;
        }

        .confirm-modal-body strong {
            color: #b91c1c;
            font-weight: 600;
        }

        .confirm-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .confirm-btn-secondary,
        .confirm-btn-danger {
            border-radius: 999px;
            padding: 8px 18px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: background 0.15s, transform 0.1s, box-shadow 0.15s;
        }

        .confirm-btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }

        .confirm-btn-secondary:hover {
            background: #d1d5db;
        }

        .confirm-btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
        }

        .confirm-btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.5);
        }
    </style>
    <script>
        // Toggle password visibility for emergency login
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggle-password');

            if (passwordField && toggleIcon) {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    toggleIcon.classList.remove('fa-eye');
                    toggleIcon.classList.add('fa-eye-slash');
                } else {
                    passwordField.type = 'password';
                    toggleIcon.classList.remove('fa-eye-slash');
                    toggleIcon.classList.add('fa-eye');
                }
            }
        }

        // Simple client-side validation for emergency login form
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('emergencyLoginForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const username = document.querySelector('input[name="username"]')?.value.trim();
                    const password = document.querySelector('input[name="password"]')?.value.trim();

                    if (!username || !password) {
                        e.preventDefault();
                        alert('Please enter both emergency username and password');
                    }
                });
            }
        });
    </script>
</head>
<body>
    <?php if (!$EMERGENCY_ACCESS): ?>
    <!-- Emergency Login Form -->
    <div class="emergency-container">
        <div class="emergency-login-box">
            <div class="logo-section">
                <img src="image/image-removebg-preview.png" alt="Logo">
                <h2>EMERGENCY RECOVERY</h2>
                <p>Database Recovery & System Restoration</p>
            </div>
            
            <?php if ($login_error): ?>
            <div class="error-list">
                <ul>
                    <li><?php echo htmlspecialchars($login_error); ?></li>
                </ul>
            </div>
            <?php endif; ?>
            
            <form method="POST" id="emergencyLoginForm">
                <div class="input-group">
                    <input type="text" name="username" placeholder="Emergency Username" required 
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" />
                    <i class="fas fa-user"></i>
                </div>
                
                <div class="input-group">
                    <input type="password" name="password" id="password" placeholder="Emergency Password" required autocomplete="off" />
                    <i class="fas fa-eye" id="toggle-password" onclick="togglePassword()" style="cursor: pointer;"></i>
                </div>
                
                <button type="submit" name="emergency_login" class="login-btn">ACCESS EMERGENCY RECOVERY</button>
            </form>
            
            <div class="emergency-credentials">
                <h3><i class="fas fa-key"></i> Emergency Credentials</h3>
                <p><strong>Username:</strong> emergency</p>
                <p><strong>Password:</strong> recovery2024</p>
                <p style="font-size: 12px; margin-top: 10px; color: #6b7280;">
                    These credentials are hardcoded for maximum security and recovery capability.
                </p>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Emergency Dashboard - Using Login Page Design -->
    <div class="login-container">
        <div class="login-box">
            <div class="left-panel">
                <h3>EMERGENCY RECOVERY</h3>
                <div class="logo-title">
                    <img src="image/image-removebg-preview.png" alt="Logo">
                    <h1>CABIS</h1>
                </div>
                <p>DATABASE RECOVERY SYSTEM</p>
            </div>
            <div class="right-panel">
                <h2>DATABASE EXPORT</h2>
                <p>Emergency database backup and recovery</p>

                <?php if ($operation_message): ?>
                <div class="success-message">
                    <p><?php echo htmlspecialchars($operation_message); ?></p>
                </div>
                <?php endif; ?>

                <?php if ($operation_error): ?>
                <div class="error-list">
                    <ul>
                        <li><?php echo htmlspecialchars($operation_error); ?></li>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($db_connected): ?>
                <!-- Database Connected - Show Export Options -->
                <div class="export-options">
                    <div class="export-info-box">
                        <h3><i class="fas fa-check-circle"></i> Database Connected</h3>
                        <p>Database is accessible and ready for operations.</p>
                    </div>
                    
                    <a href="?export=sql" class="export-button">
                        <i class="fas fa-download"></i>
                        <span>Export Full Database</span>
                        <small>Download complete database as SQL file</small>
                    </a>
                </div>
                <?php else: ?>
                <!-- Database Not Connected - Show Import Options -->
                <div class="import-options">
                    <form method="POST" enctype="multipart/form-data" id="importForm">
                        <input type="hidden" name="import_db" value="1">
                        <div class="input-group">
                            <input type="file" name="sql_file" accept=".sql" required 
                                   style="padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; width: 100%; box-sizing: border-box;">
                            <i class="fas fa-file-upload"></i>
                        </div>
                        
                        <small class="help-text">
                            Only .sql files are allowed. Maximum file size: 50MB
                        </small>
                        
                        <button type="button" name="import_db" class="login-btn" onclick="openImportConfirm()">
                            <i class="fas fa-upload"></i> Import Database
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Super Admin Sync Section - Only show when database is connected -->
                <?php if ($db_connected): ?>
                <div class="sync-section">
                    <h3>Super Admin Account Sync</h3>
                    <p>Recreate super admin account if missing after import</p>
                    
                    <form method="POST" id="syncForm">
                        <input type="hidden" name="sync_super_admin" value="1">
                        <button type="button" name="sync_super_admin" class="sync-button" onclick="openSyncConfirm()">
                            <i class="fas fa-sync"></i> Sync Super Admin Account
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="emergency-info">
                    <h3>Emergency Recovery Information</h3>
                    <ul>
                        <li><strong>Super Admin:</strong> superadmin / admin123</li>
                        <li><strong>Emergency Access:</strong> emergency / recovery2024</li>
                        <li><strong>After Import:</strong> All navigation will be restored</li>
                    </ul>
                </div>

                <p class="signup-text">
                    <a href="?logout=1">Sign out</a> | 
                    <a href="login.php">Go to Main Login</a>
                </p>
            </div>
    </div>
    </div>

    <!-- Reusable confirmation modal for critical actions -->
    <div id="confirmModal" class="confirm-modal-overlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
            <div class="confirm-modal-header">
                <div class="confirm-modal-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="confirm-modal-title" id="confirmModalTitle">Confirm Action</div>
            </div>
            <div class="confirm-modal-body">
                <p id="confirmModalPrimary">Are you sure you want to import this database?</p>
                <p id="confirmModalSecondary"><strong>This will replace all existing data.</strong><br>Make sure you have a backup before continuing.</p>
            </div>
            <div class="confirm-modal-footer">
                <button type="button" class="confirm-btn-secondary" id="confirmCancelBtn">Cancel</button>
                <button type="button" class="confirm-btn-danger" id="confirmProceedBtn">Yes, proceed</button>
            </div>
        </div>
    </div>
    
    <script>
        let confirmActionCallback = null;

        function openConfirmModal(options) {
            const modal = document.getElementById('confirmModal');
            const primary = document.getElementById('confirmModalPrimary');
            const secondary = document.getElementById('confirmModalSecondary');
            const title = document.getElementById('confirmModalTitle');
            const cancelBtn = document.getElementById('confirmCancelBtn');
            const proceedBtn = document.getElementById('confirmProceedBtn');

            if (!modal || !primary || !secondary || !title || !proceedBtn) return;

            const mode = options.mode || 'confirm'; // 'confirm' | 'info'

            title.textContent = options.title || 'Confirm Action';
            primary.textContent = options.primary || 'Are you sure you want to continue?';
            secondary.innerHTML = options.secondary || '<strong>This action cannot be undone.</strong>';

            // Reset button classes first for consistency
            proceedBtn.classList.remove('confirm-btn-danger', 'confirm-btn-secondary');

            // Configure buttons based on mode
            if (mode === 'info') {
                if (cancelBtn) {
                    cancelBtn.style.display = 'none';
                }
                proceedBtn.textContent = options.confirmText || 'OK';
                // Use the same pill/neutral style as other secondary buttons
                proceedBtn.classList.add('confirm-btn-secondary');
            } else {
                if (cancelBtn) {
                    cancelBtn.style.display = '';
                }
                proceedBtn.textContent = options.confirmText || 'Yes, proceed';
                proceedBtn.classList.add('confirm-btn-danger');
            }

            confirmActionCallback = typeof options.onConfirm === 'function' ? options.onConfirm : null;

            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeConfirmModal() {
            const modal = document.getElementById('confirmModal');
            if (!modal) return;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            confirmActionCallback = null;
        }

        document.addEventListener('DOMContentLoaded', function () {
            const cancelBtn = document.getElementById('confirmCancelBtn');
            const proceedBtn = document.getElementById('confirmProceedBtn');
            const modal = document.getElementById('confirmModal');

            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    closeConfirmModal();
                });
            }

            if (proceedBtn) {
                proceedBtn.addEventListener('click', function () {
                    if (confirmActionCallback) {
                        confirmActionCallback();
                    }
                    closeConfirmModal();
                });
            }

            if (modal) {
                modal.addEventListener('click', function (e) {
                    if (e.target === modal) {
                        closeConfirmModal();
                    }
                });
            }
        });

        function openImportConfirm() {
            const form = document.getElementById('importForm');
            if (!form) return;

            // Ensure a file is selected before showing the confirmation modal
            const fileInput = form.querySelector('input[name="sql_file"]');
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                openConfirmModal({
                    title: 'Attach SQL File',
                    primary: 'No SQL file selected.',
                    secondary: 'Please attach a <strong>.sql</strong> backup file first before importing.',
                    mode: 'info',
                    confirmText: 'OK'
                });
                return;
            }

            openConfirmModal({
                title: 'Confirm Action',
                primary: 'Are you sure you want to import this database?',
                secondary: '<strong>This will replace all existing data.</strong><br>Make sure you have a backup before continuing.',
                mode: 'confirm',
                confirmText: 'Yes, proceed',
                onConfirm: function () {
                    form.submit();
                }
            });
        }
        
        function openSyncConfirm() {
            const form = document.getElementById('syncForm');
            if (!form) return;

            openConfirmModal({
                title: 'Confirm Action',
                primary: 'Are you sure you want to sync the super admin account?',
                secondary: '<strong>This will recreate the super admin account in the database.</strong>',
                mode: 'confirm',
                confirmText: 'Yes, proceed',
                onConfirm: function () {
                    form.submit();
                }
            });
        }
        
        // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggle-password');
            
            if (passwordField && toggleIcon) {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    toggleIcon.classList.remove('fa-eye');
                    toggleIcon.classList.add('fa-eye-slash');
                } else {
                    passwordField.type = 'password';
                    toggleIcon.classList.remove('fa-eye-slash');
                    toggleIcon.classList.add('fa-eye');
                }
            }
        }
        
        // Form validation
        function validateForm() {
            const username = document.querySelector('input[name="username"]').value.trim();
            const password = document.querySelector('input[name="password"]').value.trim();
            
            if (!username) {
                alert('Please enter emergency username');
                return false;
            }
            
            if (!password) {
                alert('Please enter emergency password');
                return false;
            }
            
            return true;
        }
        
        // Add form validation on submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('emergencyLoginForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!validateForm()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>

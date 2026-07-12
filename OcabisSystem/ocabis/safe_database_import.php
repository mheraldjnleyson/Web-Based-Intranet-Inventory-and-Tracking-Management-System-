<?php
/**
 * Safe Database Import Script
 * This script safely imports SQL files by handling foreign key constraint errors
 * 
 * Usage: 
 * 1. Place your .sql file in the ocabis/backups/ directory
 * 2. Access this file via browser: http://localhost/ocabisFrontend/ocabis/safe_database_import.php
 * 3. Select your SQL file and click Import
 */

session_start();
require_once '../db_connect.php';

$error = '';
$success = '';
$import_log = [];

// Check if user is super admin
$is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;

if (!$is_super_admin) {
    $error = "Access denied. Super admin privileges required.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sql_file']) && $is_super_admin) {
    $file = $_FILES['sql_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $file['tmp_name'];
        $sql_content = file_get_contents($tmp_name);
        
        if ($sql_content === false) {
            $error = "Failed to read SQL file.";
        } else {
            // Disable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            $conn->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
            
            // Split SQL into individual statements
            $statements = array_filter(
                array_map('trim', explode(';', $sql_content)),
                function($stmt) {
                    return !empty($stmt) && 
                           !preg_match('/^\s*--/', $stmt) && 
                           !preg_match('/^\s*\/\*/', $stmt);
                }
            );
            
            $executed = 0;
            $skipped = 0;
            $errors = 0;
            
            foreach ($statements as $statement) {
                // Skip comments and empty statements
                if (empty(trim($statement)) || 
                    preg_match('/^\s*--/', $statement) || 
                    preg_match('/^\s*\/\*/', $statement)) {
                    continue;
                }
                
                // Handle ALTER TABLE statements with constraint checks
                if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+CONSTRAINT\s+`?(\w+)`?/i', $statement, $matches)) {
                    $table_name = $matches[1];
                    $constraint_name = $matches[2];
                    
                    // Check if constraint already exists
                    $check_sql = "SELECT COUNT(*) as cnt 
                                 FROM information_schema.TABLE_CONSTRAINTS 
                                 WHERE CONSTRAINT_SCHEMA = DATABASE() 
                                 AND TABLE_NAME = ? 
                                 AND CONSTRAINT_NAME = ?";
                    $stmt_check = $conn->prepare($check_sql);
                    $stmt_check->bind_param("ss", $table_name, $constraint_name);
                    $stmt_check->execute();
                    $result = $stmt_check->get_result();
                    $row = $result->fetch_assoc();
                    $stmt_check->close();
                    
                    if ($row['cnt'] > 0) {
                        $import_log[] = "Skipped: Constraint '{$constraint_name}' already exists on table '{$table_name}'";
                        $skipped++;
                        continue;
                    }
                }
                
                // Execute statement
                try {
                    if ($conn->query($statement)) {
                        $executed++;
                    } else {
                        $error_msg = $conn->error;
                        
                        // Check if it's a constraint error we can safely ignore
                        if (preg_match('/Duplicate key name|already exists|Duplicate entry/i', $error_msg)) {
                            $import_log[] = "Skipped: " . substr($error_msg, 0, 100);
                            $skipped++;
                        } else {
                            $import_log[] = "Error: " . substr($error_msg, 0, 100);
                            $errors++;
                        }
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                    if (preg_match('/Duplicate key name|already exists|Duplicate entry/i', $error_msg)) {
                        $import_log[] = "Skipped: " . substr($error_msg, 0, 100);
                        $skipped++;
                    } else {
                        $import_log[] = "Exception: " . substr($error_msg, 0, 100);
                        $errors++;
                    }
                }
            }
            
            // Re-enable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
            // Now add missing constraints safely
            add_missing_constraints($conn, $import_log);
            
            $success = "Import completed! Executed: {$executed}, Skipped: {$skipped}, Errors: {$errors}";
        }
    } else {
        $error = "File upload error: " . $file['error'];
    }
}

/**
 * Add missing foreign key constraints safely
 */
function add_missing_constraints($conn, &$import_log) {
    // Add fk_items_category constraint if category_id column exists
    $check_column = "SELECT COUNT(*) as cnt 
                     FROM information_schema.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                     AND TABLE_NAME = 'items' 
                     AND COLUMN_NAME = 'category_id'";
    $result = $conn->query($check_column);
    $row = $result->fetch_assoc();
    
    if ($row['cnt'] > 0) {
        // Check if constraint exists
        $check_constraint = "SELECT COUNT(*) as cnt 
                           FROM information_schema.TABLE_CONSTRAINTS 
                           WHERE CONSTRAINT_SCHEMA = DATABASE() 
                           AND TABLE_NAME = 'items' 
                           AND CONSTRAINT_NAME = 'fk_items_category'";
        $result = $conn->query($check_constraint);
        $row = $result->fetch_assoc();
        
        if ($row['cnt'] == 0) {
            // Update category_id from category name if needed
            $conn->query("UPDATE items i 
                         JOIN categories c ON c.name = i.category 
                         SET i.category_id = c.id 
                         WHERE i.category_id IS NULL AND i.category IS NOT NULL");

            // Null-out orphaned category references before adding FK
            $orphan_result = $conn->query("SELECT COUNT(*) AS cnt 
                                            FROM items i 
                                            LEFT JOIN categories c ON c.id = i.category_id 
                                            WHERE i.category_id IS NOT NULL AND c.id IS NULL");
            if ($orphan_result) {
                $orphan_row = $orphan_result->fetch_assoc();
                if ((int)$orphan_row['cnt'] > 0) {
                    $conn->query("UPDATE items 
                                  SET category_id = NULL 
                                  WHERE category_id IS NOT NULL 
                                  AND category_id NOT IN (SELECT id FROM categories)");
                    $import_log[] = "Cleaned " . (int)$orphan_row['cnt'] . " orphaned category_id references";
                }
            }
            
            // Add constraint
            $sql = "ALTER TABLE items 
                   ADD CONSTRAINT fk_items_category 
                   FOREIGN KEY (category_id) REFERENCES categories(id) 
                   ON DELETE SET NULL ON UPDATE CASCADE";
            
            if ($conn->query($sql)) {
                $import_log[] = "Added: fk_items_category constraint";
            } else {
                $import_log[] = "Error adding fk_items_category: " . $conn->error;
            }
        }
    }
    
    // Check items_ibfk_1 constraint
    $check_constraint = "SELECT COUNT(*) as cnt 
                        FROM information_schema.TABLE_CONSTRAINTS 
                        WHERE CONSTRAINT_SCHEMA = DATABASE() 
                        AND TABLE_NAME = 'items' 
                        AND CONSTRAINT_NAME = 'items_ibfk_1'";
    $result = $conn->query($check_constraint);
    $row = $result->fetch_assoc();
    
    if ($row['cnt'] == 0) {
        $sql = "ALTER TABLE items 
               ADD CONSTRAINT items_ibfk_1 
               FOREIGN KEY (department_id) REFERENCES departments(id)";
        
        if ($conn->query($sql)) {
            $import_log[] = "Added: items_ibfk_1 constraint";
        } else {
            $import_log[] = "Error adding items_ibfk_1: " . $conn->error;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safe Database Import</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 2px dashed #ddd;
            border-radius: 4px;
            background: #f9f9f9;
        }
        button {
            background: #007bff;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #0056b3;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .log {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 12px;
        }
        .log-item {
            padding: 2px 0;
            border-bottom: 1px solid #eee;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Safe Database Import</h1>
        
        <?php if (!$is_super_admin): ?>
            <div class="error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="sql_file">Select SQL File to Import:</label>
                    <input type="file" name="sql_file" id="sql_file" accept=".sql" required>
                </div>
                <button type="submit">Import Database</button>
            </form>
            
            <?php if (!empty($import_log)): ?>
                <div class="log">
                    <strong>Import Log:</strong>
                    <?php foreach ($import_log as $log_item): ?>
                        <div class="log-item"><?php echo htmlspecialchars($log_item); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>


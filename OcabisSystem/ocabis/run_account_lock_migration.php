<?php
/**
 * Migration script to add account lock feature for department head accounts
 * Run this script once to add the necessary columns to the users table
 */

require_once '../db_connect.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Account Lock Migration</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
    .container { max-width: 800px; margin: auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h1, h2 { color: #0056b3; }
    p { margin-bottom: 10px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: #007bff; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
</style>";
echo "</head><body><div class='container'>";
echo "<h1>Account Lock Feature Migration</h1>";

try {
    // Check database connection
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    
    // Check if columns already exist
    echo "<h2>Step 1: Checking existing columns...</h2>";
    $checkQuery = "SHOW COLUMNS FROM `users` LIKE 'failed_login_attempts'";
    $result = $conn->query($checkQuery);
    
    if ($result && $result->num_rows > 0) {
        echo "<p class='info'>Some columns already exist. Checking all required columns...</p>";
        
        // Check all required columns
        $required_columns = ['failed_login_attempts', 'account_locked', 'locked_at', 'lock_reason', 'temporary_lock_count'];
        $missing_columns = [];
        
        foreach ($required_columns as $column_name) {
            $check_column = $conn->query("SHOW COLUMNS FROM `users` LIKE '$column_name'");
            if (!$check_column || $check_column->num_rows === 0) {
                $missing_columns[] = $column_name;
            }
        }
        
        if (empty($missing_columns)) {
            echo "<p class='success'>✓ All required columns already exist. Migration already completed!</p>";
        } else {
            echo "<p class='info'>Missing columns: " . implode(', ', $missing_columns) . ". Adding missing columns...</p>";
            
            // Add missing columns
            $columns_to_add = [
                'failed_login_attempts' => "INT(11) DEFAULT 0 AFTER `role`",
                'account_locked' => "TINYINT(1) DEFAULT 0 AFTER `failed_login_attempts`",
                'locked_at' => "DATETIME DEFAULT NULL AFTER `account_locked`",
                'lock_reason' => "VARCHAR(255) DEFAULT NULL AFTER `locked_at`",
                'temporary_lock_count' => "INT(11) DEFAULT 0 AFTER `lock_reason`"
            ];
            
            foreach ($missing_columns as $column_name) {
                if (isset($columns_to_add[$column_name])) {
                    $check_column_sql = "SHOW COLUMNS FROM `users` LIKE '$column_name'";
                    $result_check = $conn->query($check_column_sql);
                    
                    if (!$result_check || $result_check->num_rows === 0) {
                        // Try to find where to place the column (find the previous column)
                        $prev_column = '';
                        if ($column_name === 'failed_login_attempts') {
                            $prev_column = 'role';
                        } elseif ($column_name === 'account_locked') {
                            $prev_column = 'failed_login_attempts';
                        } elseif ($column_name === 'locked_at') {
                            $prev_column = 'account_locked';
                        } elseif ($column_name === 'lock_reason') {
                            $prev_column = 'locked_at';
                        } elseif ($column_name === 'temporary_lock_count') {
                            $prev_column = 'lock_reason';
                        }
                        
                        // Check if previous column exists
                        $check_prev = $conn->query("SHOW COLUMNS FROM `users` LIKE '$prev_column'");
                        if ($check_prev && $check_prev->num_rows > 0) {
                            $add_column_sql = "ALTER TABLE `users` ADD COLUMN `$column_name` " . $columns_to_add[$column_name];
                        } else {
                            // If previous column doesn't exist, add without AFTER clause
                            $col_def = $columns_to_add[$column_name];
                            $col_def = preg_replace('/\s+AFTER\s+`[^`]+`/', '', $col_def);
                            $add_column_sql = "ALTER TABLE `users` ADD COLUMN `$column_name` " . $col_def;
                        }
                        
                        if ($conn->query($add_column_sql)) {
                            echo "<p class='success'>✓ Column `$column_name` added successfully.</p>";
                        } else {
                            echo "<p class='error'>✗ Failed to add column `$column_name`: " . $conn->error . "</p>";
                        }
                    }
                }
            }
            
            echo "<p class='success'>✓ Missing columns added successfully!</p>";
        }
    } else {
        echo "<p class='info'>Columns do not exist. Proceeding with migration...</p>";
        
        // Step 2: Add columns
        echo "<h2>Step 2: Adding columns to users table...</h2>";
        $columns_to_add = [
            'failed_login_attempts' => "INT(11) DEFAULT 0",
            'account_locked' => "TINYINT(1) DEFAULT 0",
            'locked_at' => "DATETIME DEFAULT NULL",
            'lock_reason' => "VARCHAR(255) DEFAULT NULL",
            'temporary_lock_count' => "INT(11) DEFAULT 0"
        ];
        
        // Get current column order to place new columns appropriately
        $columns_result = $conn->query("SHOW COLUMNS FROM `users`");
        $existing_columns = [];
        if ($columns_result) {
            while ($row = $columns_result->fetch_assoc()) {
                $existing_columns[] = $row['Field'];
            }
        }
        
        // Try to place columns after 'role' if it exists, otherwise just add at end
        $position_after = null;
        if (in_array('role', $existing_columns)) {
            $position_after = 'role';
        }
        
        foreach ($columns_to_add as $column_name => $column_definition) {
            $check_column_sql = "SHOW COLUMNS FROM `users` LIKE '$column_name'";
            $result = $conn->query($check_column_sql);
            
            if ($result && $result->num_rows === 0) {
                // Build ALTER TABLE statement
                if ($position_after && $column_name === 'failed_login_attempts') {
                    $add_column_sql = "ALTER TABLE `users` ADD COLUMN `$column_name` $column_definition AFTER `$position_after`";
                } elseif ($column_name === 'account_locked' && in_array('failed_login_attempts', $existing_columns)) {
                    $add_column_sql = "ALTER TABLE `users` ADD COLUMN `$column_name` $column_definition AFTER `failed_login_attempts`";
                } elseif ($column_name === 'locked_at' && in_array('account_locked', $existing_columns)) {
                    $add_column_sql = "ALTER TABLE `users` ADD COLUMN `$column_name` $column_definition AFTER `account_locked`";
                } elseif ($column_name === 'lock_reason' && in_array('locked_at', $existing_columns)) {
                    $add_column_sql = "ALTER TABLE `users` ADD COLUMN `$column_name` $column_definition AFTER `locked_at`";
                } elseif ($column_name === 'temporary_lock_count' && in_array('lock_reason', $existing_columns)) {
                    $add_column_sql = "ALTER TABLE `users` ADD COLUMN `$column_name` $column_definition AFTER `lock_reason`";
                } else {
                    // Add without AFTER clause if previous column doesn't exist
                    $add_column_sql = "ALTER TABLE `users` ADD COLUMN `$column_name` $column_definition";
                }
                
                if ($conn->query($add_column_sql)) {
                    echo "<p class='success'>✓ Column `$column_name` added successfully.</p>";
                    $existing_columns[] = $column_name; // Update existing columns list
                } else {
                    echo "<p class='error'>✗ Failed to add column `$column_name`: " . $conn->error . "</p>";
                }
            } else {
                echo "<p class='info'>Column `$column_name` already exists. Skipping.</p>";
            }
        }
        
        // Step 3: Add indexes
        echo "<h2>Step 3: Adding indexes...</h2>";
        $indexQueries = [
            "CREATE INDEX `idx_account_locked` ON `users` (`account_locked`)",
            "CREATE INDEX `idx_department_admin` ON `users` (`department`, `is_admin`)",
            "CREATE INDEX `idx_temporary_lock_count` ON `users` (`temporary_lock_count`)"
        ];
        
        foreach ($indexQueries as $indexQuery) {
            if ($conn->query($indexQuery)) {
                echo "<p class='success'>✓ Index created successfully!</p>";
            } else {
                // Index might already exist, which is okay
                if (strpos($conn->error, 'Duplicate key name') === false) {
                    echo "<p class='info'>Note: " . $conn->error . "</p>";
                }
            }
        }
        
        echo "<p class='success'>Migration completed successfully!</p>";
    }
    
    // Display current structure
    echo "<h2>Current users table structure:</h2>";
    $columnsQuery = "SHOW COLUMNS FROM `users`";
    $columnsResult = $conn->query($columnsQuery);
    
    if ($columnsResult) {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $columnsResult->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>Migration failed: " . $e->getMessage() . "</p>";
    error_log("Account lock migration failed: " . $e->getMessage());
} finally {
    $conn->close();
}

echo "</div></body></html>";
?>


<?php
session_start();

// Handle login for database export page
$login_error = '';
$is_logged_in = false;
$is_super_admin = false;

// Check if user is already logged in
if (isset($_SESSION['username'])) {
    $is_logged_in = true;
    // Initially check session variable
    $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $login_error = "Please enter both username and password.";
    } else {
        $login_successful = false;
        
        // Load permanent super admin credentials from config file
        $super_admin_config = include 'super_admin_config.php';
        $permanent_admin = $super_admin_config['super_admin'];
        
        if ($username === $permanent_admin['username'] && $password === $permanent_admin['password']) {
            // Permanent super admin login successful
            $_SESSION['user_id'] = 999999; // Permanent ID
            $_SESSION['username'] = $permanent_admin['username'];
            $_SESSION['email'] = $permanent_admin['email'];
            $_SESSION['department'] = $permanent_admin['department'];
            $_SESSION['role'] = 'super_admin';
            $_SESSION['is_admin'] = 1;
            $_SESSION['is_super_admin'] = 1;
            $_SESSION['created_at'] = $permanent_admin['created_at'];
            
            $is_logged_in = true;
            $is_super_admin = true;
            $login_successful = true;
            
            // Refresh page to show logged in state
            header("Location: database_export.php");
            exit();
        }
        
        // Try database login if emergency login failed
        if (!$login_successful) {
            try {
                // Include database connection for login
                require_once '../db_connect.php';
                
                if (!$conn->connect_error) {
                    // Check super_admin table
                    $super_admin_sql = "SELECT id, username, email, password, status FROM super_admin WHERE username = ?";
                    $super_admin_stmt = $conn->prepare($super_admin_sql);
                    $super_admin_stmt->bind_param("s", $username);
                    $super_admin_stmt->execute();
                    $super_admin_result = $super_admin_stmt->get_result();
                    
                    if ($super_admin_result->num_rows === 1) {
                        $userData = $super_admin_result->fetch_assoc();
                        
                        if ($userData['status'] === 'inactive') {
                            $login_error = "Your super admin account is inactive.";
                        } else {
                            if (password_verify($password, $userData['password'])) {
                                // Super admin login successful
                                $_SESSION['user_id'] = $userData['id'];
                                $_SESSION['username'] = $userData['username'];
                                $_SESSION['email'] = $userData['email'];
                                $_SESSION['role'] = 'super_admin';
                                $_SESSION['is_admin'] = 1;
                                $_SESSION['is_super_admin'] = 1;
                                $_SESSION['created_at'] = date('Y-m-d H:i:s');
                                
                                $is_logged_in = true;
                                $is_super_admin = true;
                                $login_successful = true;
                                
                                // Refresh page to show logged in state
                                header("Location: database_export.php");
                                exit();
                            } else {
                                $login_error = "Invalid username or password.";
                            }
                        }
                    } else {
                        $login_error = "Only super admin accounts can access this page.";
                    }
                    
                    $super_admin_stmt->close();
                } else {
                    $login_error = "Database connection failed. Use permanent credentials: " . $permanent_admin['username'] . " / " . $permanent_admin['password'];
                }
            } catch (Exception $e) {
                $login_error = "Database error. Use permanent credentials: " . $permanent_admin['username'] . " / " . $permanent_admin['password'];
            }
        }
    }
}

// Include database connection
require_once '../db_connect.php';

// Check if database connection is working
$db_connected = false;
$db_error = '';

if ($conn->connect_error) {
    $db_error = $conn->connect_error;
    $db_connected = false;
} else {
    // Try to query something to verify database exists
    try {
        $result = $conn->query("SELECT 1");
        if ($result !== false) {
            $db_connected = true;
        } else {
            $db_error = "Database query failed";
            $db_connected = false;
        }
    } catch (Exception $e) {
        $db_error = $e->getMessage();
        $db_connected = false;
    }
}

// CRITICAL SECURITY CHECK: Verify logged-in user is actually a super admin in database
// This prevents regular admins from accessing even if session variables are manipulated
if ($is_logged_in && $db_connected) {
    try {
        $verify_sql = "SELECT id, username, status FROM super_admin WHERE username = ? AND status = 'active'";
        $verify_stmt = $conn->prepare($verify_sql);
        if ($verify_stmt) {
            $verify_stmt->bind_param("s", $_SESSION['username']);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            
            // If user is not found in super_admin table, they are NOT a super admin
            // Deny access regardless of session variables
            if ($verify_result->num_rows === 0) {
                $is_super_admin = false;
                // Clear session variables to prevent further access attempts
                unset($_SESSION['is_super_admin']);
            } else {
                // User exists in super_admin table, confirm they are super admin
                $is_super_admin = true;
            }
            $verify_stmt->close();
        }
    } catch (Exception $e) {
        // If database check fails, deny access to be safe
        $is_super_admin = false;
        unset($_SESSION['is_super_admin']);
    }
} elseif ($is_logged_in && !$db_connected) {
    // If database is not connected but user is logged in, 
    // only allow if they're using permanent super admin credentials
    $super_admin_config = include 'super_admin_config.php';
    $permanent_admin = $super_admin_config['super_admin'];
    if (!isset($_SESSION['username']) || $_SESSION['username'] !== $permanent_admin['username']) {
        $is_super_admin = false;
    }
}


// Ensure core foreign keys exist (idempotent, safe). Runs only when DB is connected.
// This preserves existing text columns while introducing ID columns + FKs where missing.
$fk_migration_ran = false;
$fk_migration_error = '';
if ($db_connected) {
    try {
        $sql_migration = "
        USE ocabis;

        -- archived_categories.category_id → categories.id
        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name='archived_categories' AND index_name='category_id'
          ),
          'CREATE INDEX category_id ON archived_categories(category_id);',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        -- Ensure archived_categories.category_id allows NULL
        SET @sql := NULL;
        SELECT IF(
          EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='archived_categories' AND column_name='category_id' AND is_nullable='NO'),
          'ALTER TABLE archived_categories MODIFY category_id INT NULL',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        -- Drop existing FK if using RESTRICT, then recreate with ON DELETE SET NULL
        SET @sql := NULL;
        SELECT IF(
          EXISTS (SELECT 1 FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() AND constraint_name='fk_archived_categories_category'),
          'ALTER TABLE archived_categories DROP FOREIGN KEY fk_archived_categories_category',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (
            SELECT 1 FROM information_schema.referential_constraints
            WHERE constraint_schema = DATABASE() AND constraint_name='fk_archived_categories_category'
          )
          AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='archived_categories' AND column_name='category_id')
          AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='categories' AND column_name='id'),
          'ALTER TABLE archived_categories
             ADD CONSTRAINT fk_archived_categories_category
             FOREIGN KEY (category_id) REFERENCES categories(id)
             ON UPDATE CASCADE ON DELETE SET NULL;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        -- archived_items.department_id → departments.id (nullable)
        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name='archived_items' AND index_name='idx_arch_items_dept'
          ),
          'CREATE INDEX idx_arch_items_dept ON archived_items(department_id);',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (
            SELECT 1 FROM information_schema.referential_constraints
            WHERE constraint_schema = DATABASE() AND constraint_name='fk_archived_items_department'
          ),
          'ALTER TABLE archived_items
             ADD CONSTRAINT fk_archived_items_department
             FOREIGN KEY (department_id) REFERENCES departments(id)
             ON UPDATE CASCADE ON DELETE SET NULL;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        -- archived_items.item_table_id → item_tables.id (nullable)
        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name='archived_items' AND index_name='idx_arch_items_table'
          ),
          'CREATE INDEX idx_arch_items_table ON archived_items(item_table_id);',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (
            SELECT 1 FROM information_schema.referential_constraints
            WHERE constraint_schema = DATABASE() AND constraint_name='fk_archived_items_item_table'
          ),
          'ALTER TABLE archived_items
             ADD CONSTRAINT fk_archived_items_item_table
             FOREIGN KEY (item_table_id) REFERENCES item_tables(id)
             ON UPDATE CASCADE ON DELETE SET NULL;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        -- items.category_id add + FK (keep text column)
        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (SELECT 1 FROM information_schema.columns
                      WHERE table_schema=DATABASE() AND table_name='items' AND column_name='category_id'),
          'ALTER TABLE items ADD COLUMN category_id INT NULL AFTER department_id;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (SELECT 1 FROM information_schema.statistics
                      WHERE table_schema=DATABASE() AND table_name='items' AND index_name='idx_items_category_id'),
          'CREATE INDEX idx_items_category_id ON items(category_id);',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        UPDATE items i
        JOIN categories c ON c.name = i.category
        SET i.category_id = c.id
        WHERE i.category_id IS NULL AND i.category IS NOT NULL;

        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
                      WHERE constraint_schema=DATABASE() AND constraint_name='fk_items_category'),
          'ALTER TABLE items
             ADD CONSTRAINT fk_items_category
             FOREIGN KEY (category_id) REFERENCES categories(id)
             ON UPDATE CASCADE ON DELETE SET NULL;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        -- item_requests: add department_id & category_id + FKs
        SET @sql := NULL;
        SELECT IF(NOT EXISTS (SELECT 1 FROM information_schema.columns
                              WHERE table_schema=DATABASE() AND table_name='item_requests' AND column_name='department_id'),
          'ALTER TABLE item_requests ADD COLUMN department_id INT NULL AFTER requested_by;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(NOT EXISTS (SELECT 1 FROM information_schema.columns
                              WHERE table_schema=DATABASE() AND table_name='item_requests' AND column_name='category_id'),
          'ALTER TABLE item_requests ADD COLUMN category_id INT NULL AFTER category;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics
                              WHERE table_schema=DATABASE() AND table_name='item_requests' AND index_name='idx_item_requests_dept'),
          'CREATE INDEX idx_item_requests_dept ON item_requests(department_id);',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(NOT EXISTS (SELECT 1 FROM information_schema.statistics
                              WHERE table_schema=DATABASE() AND table_name='item_requests' AND index_name='idx_item_requests_category'),
          'CREATE INDEX idx_item_requests_category ON item_requests(category_id);',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        UPDATE item_requests r
        LEFT JOIN departments d ON d.name = r.department_name
        LEFT JOIN categories  c ON c.name = r.category
        SET r.department_id = COALESCE(r.department_id, d.id),
            r.category_id   = COALESCE(r.category_id, c.id)
        WHERE (r.department_id IS NULL AND r.department_name IS NOT NULL)
           OR (r.category_id   IS NULL AND r.category IS NOT NULL);

        SET @sql := NULL;
        SELECT IF(NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
                              WHERE constraint_schema=DATABASE() AND constraint_name='fk_item_requests_department'),
          'ALTER TABLE item_requests
             ADD CONSTRAINT fk_item_requests_department
             FOREIGN KEY (department_id) REFERENCES departments(id)
             ON UPDATE CASCADE ON DELETE SET NULL;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
                              WHERE constraint_schema=DATABASE() AND constraint_name='fk_item_requests_category'),
          'ALTER TABLE item_requests
             ADD CONSTRAINT fk_item_requests_category
             FOREIGN KEY (category_id) REFERENCES categories(id)
             ON UPDATE CASCADE ON DELETE SET NULL;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        -- users.department_id add + FK (keep text)
        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (SELECT 1 FROM information_schema.columns
                      WHERE table_schema=DATABASE() AND table_name='users' AND column_name='department_id'),
          'ALTER TABLE users ADD COLUMN department_id INT NULL AFTER department;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (SELECT 1 FROM information_schema.statistics
                      WHERE table_schema=DATABASE() AND table_name='users' AND index_name='idx_users_department_id'),
          'CREATE INDEX idx_users_department_id ON users(department_id);',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        UPDATE users u
        LEFT JOIN departments d ON d.name = u.department
        SET u.department_id = COALESCE(u.department_id, d.id)
        WHERE u.department_id IS NULL AND u.department IS NOT NULL;

        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
                      WHERE constraint_schema=DATABASE() AND constraint_name='fk_users_department'),
          'ALTER TABLE users
             ADD CONSTRAINT fk_users_department
             FOREIGN KEY (department_id) REFERENCES departments(id)
             ON UPDATE CASCADE ON DELETE SET NULL;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

        -- user_sessions.user_id FK (cascade on delete)
        SET @sql := NULL;
        SELECT IF(
          NOT EXISTS (SELECT 1 FROM information_schema.referential_constraints
                      WHERE constraint_schema=DATABASE() AND constraint_name='fk_user_sessions_user'),
          'ALTER TABLE user_sessions
             ADD CONSTRAINT fk_user_sessions_user
             FOREIGN KEY (user_id) REFERENCES users(id)
             ON UPDATE CASCADE ON DELETE CASCADE;',
          'SELECT 1;'
        ) INTO @sql; PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
        ";

        if (!$conn->multi_query($sql_migration)) {
            $fk_migration_error = $conn->error;
        } else {
            // Flush and free all result sets from multi_query to avoid "commands out of sync"
            while ($conn->more_results()) {
                $conn->next_result();
                if ($tmpResult = $conn->store_result()) {
                    $tmpResult->free();
                }
            }
            $fk_migration_ran = true;
        }
    } catch (Exception $e) {
        $fk_migration_error = $e->getMessage();
    }
}

function do_database_export($save_to_file = false) {
    global $conn, $db_connected;

    // Check if database is connected
    if (!$db_connected) {
        if ($save_to_file) {
            return false;
        } else {
            die("Database not connected. Cannot export.");
        }
    }

    // Export database as SQL file
    $db_name = "ocabis";
    $timestamp = date('Y-m-d_H-i-s');

    if ($save_to_file) {
        $backup_dir = __DIR__ . '/backups/';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        $filename = "ocabis_backup_{$timestamp}.sql";
        $filepath = $backup_dir . $filename;
        $output = fopen($filepath, 'w');
    } else {
        $filename = "ocabis_backup_{$timestamp}.sql";
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        $output = fopen('php://output', 'w');
    }

    // Ensure there are no pending results from earlier operations
    while ($conn->more_results()) {
        $conn->next_result();
        if ($tmpResult = $conn->store_result()) {
            $tmpResult->free();
        }
    }

    // Get all tables from the ocabis database
    $tables_query = "SHOW TABLES FROM ocabis";
    $tables_result = $conn->query($tables_query);

    if (!$tables_result) {
        if ($save_to_file) {
            fclose($output);
            return false;
        } else {
            die("Error getting tables: " . $conn->error);
        }
    }

    // Collect all table names
    $all_tables = [];
    while ($row = $tables_result->fetch_array(MYSQLI_NUM)) {
        $all_tables[] = $row[0];
    }

    fwrite($output, "-- OCABIS Database Backup\n");
    fwrite($output, "-- Export Date: " . date('Y-m-d H:i:s') . "\n");
    if ($save_to_file) {
        fwrite($output, "-- Backup Type: Automatic Monthly\n");
    } else {
        fwrite($output, "-- Exported by: " . $_SESSION['username'] . "\n");
    }
    fwrite($output, "-- Total Tables: " . count($all_tables) . "\n\n");

    // Disable foreign key checks during import
    fwrite($output, "SET FOREIGN_KEY_CHECKS = 0;\n");
    fwrite($output, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
    fwrite($output, "START TRANSACTION;\n\n");

    // Export each table
    foreach ($all_tables as $table) {
        fwrite($output, "-- ================================================\n");
        fwrite($output, "-- Table: " . $table . "\n");
        fwrite($output, "-- ================================================\n\n");

        // Get table structure
        $create_table = $conn->query("SHOW CREATE TABLE `$table`");
        if (!$create_table) {
            fwrite($output, "-- Error getting structure for table " . $table . ": " . $conn->error . "\n\n");
            continue;
        }

        $create_row = $create_table->fetch_row();
        fwrite($output, "DROP TABLE IF EXISTS `" . $table . "`;\n");
        fwrite($output, $create_row[1] . ";\n\n");

        // Get table data
        $data_query = "SELECT * FROM `" . $table . "`";
        $data_result = $conn->query($data_query);

        if (!$data_result) {
            fwrite($output, "-- Error getting data from table " . $table . ": " . $conn->error . "\n\n");
            continue;
        }

        $row_count = $data_result->num_rows;

        if ($row_count > 0) {
            fwrite($output, "-- Data for table " . $table . " (" . $row_count . " rows)\n");

            while ($data_row = $data_result->fetch_assoc()) {
                $fields = array_keys($data_row);
                $values = array_map(function($value) use ($conn) {
                    if (is_null($value)) {
                        return 'NULL';
                    } else {
                        return "'" . $conn->real_escape_string($value) . "'";
                    }
                }, array_values($data_row));

                fwrite($output, "INSERT INTO `" . $table . "` (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $values) . ");\n");
            }
            fwrite($output, "\n");
        } else {
            fwrite($output, "-- Table " . $table . " is empty (0 rows)\n\n");
        }
    }

    // Re-enable foreign key checks
    fwrite($output, "SET FOREIGN_KEY_CHECKS = 1;\n");

    // Ensure super admin account is always included in export
    fwrite($output, "-- Ensure super admin account exists\n");
    fwrite($output, "-- This section guarantees the super admin account is available after import\n");

    // Load permanent super admin credentials
    $super_admin_config = include 'super_admin_config.php';
    $permanent_admin = $super_admin_config['super_admin'];

    // Add is_permanent column if it doesn't exist
    fwrite($output, "-- Add is_permanent column to super_admin table\n");
    fwrite($output, "-- Note: If column already exists, this will show an error but can be ignored\n");
    fwrite($output, "SET @col_exists = (\n");
    fwrite($output, "    SELECT COUNT(*) FROM information_schema.COLUMNS \n");
    fwrite($output, "    WHERE TABLE_SCHEMA = 'ocabis' \n");
    fwrite($output, "    AND TABLE_NAME = 'super_admin' \n");
    fwrite($output, "    AND COLUMN_NAME = 'is_permanent'\n");
    fwrite($output, ");\n");
    fwrite($output, "SET @query = IF(@col_exists = 0, 'ALTER TABLE super_admin ADD COLUMN is_permanent tinyint(1) DEFAULT 0', 'SELECT 1');\n");
    fwrite($output, "PREPARE stmt FROM @query;\n");
    fwrite($output, "EXECUTE stmt;\n");
    fwrite($output, "DEALLOCATE PREPARE stmt;\n\n");

    // Create protection trigger
    fwrite($output, "DROP TRIGGER IF EXISTS prevent_super_admin_deletion;\n");
    fwrite($output, "CREATE TRIGGER prevent_super_admin_deletion\n");
    fwrite($output, "    BEFORE DELETE ON super_admin\n");
    fwrite($output, "    FOR EACH ROW\n");
    fwrite($output, "    BEGIN\n");
    fwrite($output, "        IF OLD.is_permanent = 1 THEN\n");
    fwrite($output, "            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot delete permanent super admin account';\n");
    fwrite($output, "        END IF;\n");
    fwrite($output, "    END;\n\n");

    // Insert or update super admin account
    $password_hash = password_hash($permanent_admin['password'], PASSWORD_DEFAULT);
    fwrite($output, "INSERT INTO super_admin (username, email, password, department, status, is_permanent, created_at) \n");
    fwrite($output, "VALUES ('{$permanent_admin['username']}', '{$permanent_admin['email']}', '{$password_hash}', '{$permanent_admin['department']}', '{$permanent_admin['status']}', 1, '{$permanent_admin['created_at']}')\n");
    fwrite($output, "ON DUPLICATE KEY UPDATE \n");
    fwrite($output, "    email = '{$permanent_admin['email']}',\n");
    fwrite($output, "    password = '{$password_hash}',\n");
    fwrite($output, "    department = '{$permanent_admin['department']}',\n");
    fwrite($output, "    status = '{$permanent_admin['status']}',\n");
    fwrite($output, "    is_permanent = 1,\n");
    fwrite($output, "    updated_at = CURRENT_TIMESTAMP;\n\n");

    fwrite($output, "COMMIT;\n");
    fclose($output);

    if ($save_to_file) {
        return $filename;
    } else {
        exit();
    }
}


$backup_dir = __DIR__ . '/backups/';
$timestamp_file = $backup_dir . 'last_backup_timestamp.txt';
$current_time = time();
$last_backup_time = 0;
if (file_exists($timestamp_file)) {
    $last_backup_time = (int)file_get_contents($timestamp_file);
}
$days_since_last_backup = ($current_time - $last_backup_time) / (60*60*24);
$monthly_backup_created = false;
$monthly_backup_filename = '';
$backup_email_sent = false;

// Load super admin config for use throughout the page
$super_admin_config = include 'super_admin_config.php';

// Determine effective super admin email (DB > session > config)
$effective_super_admin_email = $super_admin_config['super_admin']['email'];
if ($db_connected) {
	// Prefer the logged-in super admin's email if available
	if ($is_super_admin && !empty($_SESSION['email']) && filter_var($_SESSION['email'], FILTER_VALIDATE_EMAIL)) {
		$effective_super_admin_email = $_SESSION['email'];
	} else {
		// Otherwise, try to fetch an active super admin email from the database
		$stmt = $conn->prepare("SELECT email FROM super_admin WHERE status = 'active' ORDER BY updated_at DESC, id ASC LIMIT 1");
		if ($stmt) {
			if ($stmt->execute()) {
				$result = $stmt->get_result();
				if ($result && $row = $result->fetch_assoc()) {
					if (!empty($row['email']) && filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
						$effective_super_admin_email = $row['email'];
					}
				}
			}
			$stmt->close();
		}
	}
}

// Automatic monthly backup (runs every 30 days)
if ((!file_exists($timestamp_file) || $days_since_last_backup >= 30) && $db_connected) {
    $monthly_backup_filename = do_database_export(true);
    if ($monthly_backup_filename) {
        file_put_contents($timestamp_file, $current_time);
        $monthly_backup_created = true;
        
        // Send backup via email to super admin
        if (file_exists(__DIR__ . '/email_notifications.php')) {
            require_once __DIR__ . '/email_notifications.php';
            
            // Get super admin email (DB/session preferred, fallback to config)
            $super_admin_email = $effective_super_admin_email;
            
            $backup_file_path = $backup_dir . $monthly_backup_filename;
            
            // Send email with backup attachment
            $backup_email_sent = sendDatabaseBackupEmail($super_admin_email, $backup_file_path, $monthly_backup_filename);
            
            if ($backup_email_sent) {
                error_log("Monthly backup sent via email to: " . $super_admin_email);
            } else {
                error_log("Failed to send monthly backup via email");
            }
        }
    }
}


// Handle database export
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    if ($export_type === 'sql') {
        do_database_export(false);
    } elseif ($export_type === 'csv') {
        // Export specific table as CSV
        $table = $_GET['table'] ?? '';
        if (empty($table)) {
            die("No table specified");
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "ocabis_{$table}_{$timestamp}.csv";
        
        header('Content-Type: text/csv');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        
        $result = $conn->query("SELECT * FROM `$table`");
        
        // Output CSV header
        $output = fopen('php://output', 'w');
        
        // Get column names
        $fields = $result->fetch_fields();
        $column_names = array();
        foreach ($fields as $field) {
            $column_names[] = $field->name;
        }
        fputcsv($output, $column_names);
        
        // Output data
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>Database Backup - OCABIS</title>
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .export-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .export-header {
            background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }
        
        .export-header h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        
        .export-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 16px;
        }
        
        .export-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 20px;
        }
        
        .export-section h2 {
            color: #1f2937;
            margin: 0 0 20px 0;
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .export-section h2 i {
            color: #e53e3e;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 0;
        }
        
        .input-group input {
            width: 100%;
            padding: 12px 40px 12px 15px;
            border: 1px solid #000000;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .input-group i {
            position: absolute;
            right: 15px;
            top: 12px;
            color: #aaa;
            cursor: pointer;
        }
        
        /* Hide browser password manager icons */
        .input-group input[type="password"]::-webkit-credentials-auto-fill-button,
        .input-group input[type="password"]::-webkit-strong-password-auto-fill-button {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            position: absolute !important;
            right: -9999px !important;
        }
        
        .input-group input[type="password"]::-ms-reveal,
        .input-group input[type="password"]::-ms-clear {
            display: none !important;
        }
        
        .export-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .export-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            text-decoration: none;
            color: #1f2937;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .export-btn:hover {
            border-color: #e53e3e;
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(229, 62, 62, 0.2);
        }
        
        .export-btn i {
            font-size: 48px;
            color: #e53e3e;
            margin-bottom: 15px;
        }
        
        .export-btn strong {
            font-size: 18px;
            margin-bottom: 8px;
            color: #1f2937;
        }
        
        .export-btn span {
            font-size: 14px;
            color: #6b7280;
            text-align: center;
        }
        
        .export-info {
            background: #f8fafc;
            border-left: 4px solid #e53e3e;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .export-info h3 {
            margin: 0 0 10px 0;
            color: #1f2937;
            font-size: 16px;
        }
        
        .export-info ul {
            margin: 0;
            padding-left: 20px;
            color: #6b7280;
        }
        
        .export-info ul li {
            margin-bottom: 8px;
        }
        
        .tables-list {
            margin-top: 20px;
        }
        
        .table-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.2s ease;
        }
        
        .table-item:hover {
            background: #f3f4f6;
        }
        
        .table-item-info {
            flex: 1;
        }
        
        .table-item-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .table-item-desc {
            font-size: 14px;
            color: #6b7280;
        }
        
        .table-item-action {
            margin-left: 20px;
        }
        
        .btn-download {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-download:hover {
            background: #c53030;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }
        
        .warning-box {
            background: #fef2f2;
            border: 2px solid #fecaca;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .warning-box h3 {
            color: #991b1b;
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .warning-box p {
            color: #991b1b;
            margin: 0;
            line-height: 1.6;
        }
        
        /* Sidebar Toggle Fixed - Hidden on Desktop by Default */
        .sidebar-toggle-fixed {
            display: none;
        }

        /* Mobile Inline Sidebar Toggle - Hidden on desktop */
        .sidebar-toggle-mobile-inline {
            display: none !important;
        }

        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1199;
        }

        .sidebar-overlay.show {
            display: block;
        }

        @media (max-width: 768px) {
            .export-buttons {
                grid-template-columns: 1fr;
            }
            
            .export-btn {
                padding: 20px;
            }
            
            .export-btn i {
                font-size: 36px;
            }

            /* Sidebar mobile styles */
            #sidebarToggle, .sidebar-toggle-inline { /* hide inline in mobile */
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }
            .sidebar {
                position: fixed;
                top: 0; left: 0;
                width: 250px !important;
                height: 100vh;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                z-index: 1200;
                box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            }
            .sidebar.open { transform: translateX(0); }

            /* Sidebar Toggle Fixed Button - Show on mobile with high specificity */
            body #sidebarToggleFixed,
            body .sidebar-toggle-fixed,
            #sidebarToggleFixed,
            .sidebar-toggle-fixed {
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                align-items: center !important;
                justify-content: center !important;
                z-index: 1300 !important;
                position: fixed !important;
                top: 15px !important;
                left: 15px !important;
                background: rgba(229, 62, 62, 0.95) !important;
                color: white !important;
                border: 0 !important;
                width: 42px !important;
                height: 42px !important;
                border-radius: 12px !important;
                cursor: pointer !important;
                font-size: 18px !important;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
                transition: all 0.3s ease !important;
                pointer-events: auto !important;
            }

            body #sidebarToggleFixed:hover,
            body .sidebar-toggle-fixed:hover,
            #sidebarToggleFixed:hover,
            .sidebar-toggle-fixed:hover {
                background: rgba(229, 62, 62, 1) !important;
                transform: scale(1.05) !important;
            }

            /* Ensure sidebar has proper padding and width on mobile */
            .sidebar {
                width: 250px !important;
                position: fixed !important;
                height: 100vh !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                padding: 20px 0 !important;
                padding-bottom: 80px !important;
            }
            
            /* Ensure sidebar content is properly styled on mobile */
            .sidebar .logo {
                padding: 0 20px !important;
                margin-bottom: 30px !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            .sidebar .nav-menu {
                list-style: none !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }
            
            .sidebar .nav-item {
                margin-bottom: 8px !important;
                width: 100% !important;
            }
            
            /* Nav link styling - match desktop layout - ensure text doesn't change */
            .sidebar .nav-link {
                display: flex !important;
                align-items: center !important;
                padding: 12px 20px !important;
                color: white !important;
                text-decoration: none !important;
                font-size: 14px !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
                white-space: nowrap !important;
                overflow: visible !important;
            }
            
            /* Nav icon styling - consistent size */
            .sidebar .nav-icon {
                width: 16px !important;
                height: 16px !important;
                margin-right: 12px !important;
                opacity: 0.8 !important;
                flex-shrink: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            
            .sidebar .nav-icon img {
                width: 16px !important;
                height: 16px !important;
                object-fit: contain !important;
                margin-right: 0 !important;
            }
            
            /* Nav label styling - ensure text is always visible and doesn't change */
            .sidebar .nav-label {
                display: inline-block !important;
                visibility: visible !important;
                opacity: 1 !important;
                white-space: nowrap !important;
                flex: 1 !important;
                overflow: visible !important;
                text-overflow: clip !important;
                max-width: none !important;
            }
            
            /* Ensure all text in nav-link is visible */
            .sidebar .nav-link span:not(.nav-icon) {
                white-space: nowrap !important;
                overflow: visible !important;
                text-overflow: clip !important;
            }

            .main-content { margin-left: 0 !important; padding: 10px !important; width: 100% !important; }
        }
    </style>
</head>
<body>
    <?php if (!$is_logged_in): ?>
    <!-- Login Form for Database Export Access -->
    <div style="min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div style="background: white; padding: 40px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.1); width: 100%; max-width: 400px;">
            <div style="text-align: center; margin-bottom: 30px;">
                <img src="image/image-removebg-preview.png" alt="Logo" style="height: 60px; width: auto; margin-bottom: 15px;">
                <h2 style="color: #1f2937; margin: 0 0 10px 0;">Database Export Access</h2>
                <p style="color: #6b7280; margin: 0;">Super Admin Login Required</p>
            </div>
            
            <?php if ($login_error): ?>
            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($login_error); ?>
            </div>
            <?php endif; ?>
            
            <form method="POST">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; color: #374151; font-weight: 500;">Username</label>
                    <input type="text" name="username" required 
                           style="width: 100%; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 16px; box-sizing: border-box;"
                           placeholder="Enter super admin username">
                </div>
                
                <div style="margin-bottom: 30px;">
                    <label style="display: block; margin-bottom: 8px; color: #374151; font-weight: 500;">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="dbExportPassword" required 
                               placeholder="Enter password" autocomplete="current-password">
                        <i class="fas fa-eye" id="toggle-db-export-password" onclick="togglePassword('dbExportPassword', 'toggle-db-export-password')" style="cursor: pointer;"></i>
                    </div>
                </div>
                
                <button type="submit" name="login" 
                        style="width: 100%; background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%); color: white; padding: 14px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s;">
                    <i class="fas fa-sign-in-alt"></i> Login to Database Export
                </button>
            </form>
            
            <div style="text-align: center; margin-top: 20px; color: #6b7280; font-size: 14px;">
                <p>This page allows super admin to export and import the database</p>
                <?php 
                // Load permanent credentials for display
                $super_admin_config = include 'super_admin_config.php';
                $permanent_admin = $super_admin_config['super_admin'];
                ?>
                <div style="background: #f0f9ff; border: 1px solid #0ea5e9; padding: 12px; border-radius: 8px; margin-top: 15px;">
                    <p style="margin: 0; color: #0369a1; font-weight: 500;">
                        <i class="fas fa-key"></i> Permanent Access (Survives Database Deletion):<br>
                        <strong>Username:</strong> <?php echo htmlspecialchars($permanent_admin['username']); ?><br>
                        <strong>Password:</strong> <?php echo htmlspecialchars($permanent_admin['password']); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php elseif (!$is_super_admin): ?>
    <!-- Access Denied for Non-Super Admin -->
    <div style="min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div style="background: white; padding: 40px; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.1); width: 100%; max-width: 500px; text-align: center;">
            <div style="margin-bottom: 30px;">
                <i class="fas fa-ban" style="font-size: 64px; color: #e53e3e; margin-bottom: 20px;"></i>
                <h2 style="color: #1f2937; margin: 0 0 10px 0;">Access Denied</h2>
                <p style="color: #6b7280; margin: 0;">This page is only accessible to Super Administrators.</p>
            </div>
            <div style="background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <i class="fas fa-exclamation-triangle"></i> You do not have permission to access this page.
            </div>
            <a href="dashboard.php" style="display: inline-block; background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%); color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600;">
                <i class="fas fa-arrow-left"></i> Return to Dashboard
            </a>
        </div>
    </div>
    <?php else: ?>
    <!-- Main Database Export Content -->
    <div class="sidebar">
        <div class="logo">
            <div class="logo-top" style="display: flex !important; align-items: center; gap: 10px; width: 100%; position: relative;">
                <div class="logo-icon">
                    <img src="image/image-removebg-preview.png" alt="Logo" style="height: 50px; width: auto;">
                </div>
                <h1 style="margin: 0; flex: 1; min-width: 0;">CABIS</h1>
                <button id="sidebarToggle" class="sidebar-toggle-inline" aria-label="Toggle sidebar" style="display: flex !important; visibility: visible !important; opacity: 1 !important; margin-left: auto !important; flex-shrink: 0 !important;">☰</button>
            </div>
            <div class="logo-text">
                <p>INVENTORY MANAGEMENT SYSTEM</p>
            </div>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link" title="Dashboard">
                    <span class="nav-icon">
                        <img src="image/admin.png" alt="Dashboard">
                    </span>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="department.php" class="nav-link" title="Department">
                    <span class="nav-icon">
                        <img src="image/department.png" alt="Department">
                    </span>
                    <span class="nav-label">Department</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="location.php" class="nav-link" title="Location">
                    <span class="nav-icon">
                        <img src="image/icons8-building-64.png" alt="Location">
                    </span>
                    <span class="nav-label">Location</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="categories.php" class="nav-link" title="Categories">
                    <span class="nav-icon">
                        <img src="image/icons8-categorize-50.png" alt="Categories">
                    </span>
                    <span class="nav-label">Categories</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="BorrowHistory.php" class="nav-link" title="Borrow History">
                    <span class="nav-icon">
                        <img src="image/book.png" alt="Borrow History">
                    </span>
                    <span class="nav-label">Borrow History</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="archive.php" class="nav-link" title="Archive">
                    <span class="nav-icon">
                        <img src="image/icons8-archive-50.png" alt="Archive">
                    </span>
                    <span class="nav-label">Archive</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="qrscanner.php" class="nav-link" title="QR Code Scanner">
                    <span class="nav-icon">
                        <img src="image/qr.png" alt="QR Code">
                    </span>
                    <span class="nav-label">QR Code Scanner</span>
                </a>
            </li>
            <li class="nav-item">
        <a href="barcode_scanner.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/barcode-scan.png" alt="QR Code">
            </span>
            <span class="nav-label">Barcode Scanner</span>
        </a>
    </li>
            <li class="nav-item">
                <a href="item_requests.php" class="nav-link" title="Item Requests">
                    <span class="nav-icon">
                        <img src="image/application.png" alt="Requests">
                    </span>
                    <span class="nav-label">Item Requests</span>
                </a>
            </li>
            <?php if (isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1): ?>
            <li class="nav-item">
                <a href="user_management.php" class="nav-link" title="User Management">
                    <span class="nav-icon">
                        <img src="image/profile.png" alt="User Management">
                    </span>
                    <span class="nav-label">User Management</span>
                </a>
            </li>
            <?php endif; ?>
            <?php 
            // Database Export/Import/Backup - ONLY for native super admin (not elevated via role)
            $is_native_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 && !isset($_SESSION['super_admin_via_role']);
            if ($is_native_super_admin): 
            ?>
            <li class="nav-item active">
                <a href="database_export.php" class="nav-link active" title="Backup">
                    <span class="nav-icon">
                        <img src="image/sqlbackup.png" alt="Backup">
                    </span>
                    <span class="nav-label">Backup</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sign-out">
            <a href="logout.php" class="nav-link" title="Sign out">
                <span class="nav-icon">
                    <img src="image/icons8-sign-out-48.png" alt="Sign Out">
                </span>
                <span class="nav-label">Sign out</span>
            </a>
        </div>
    </div>
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>
    
    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        
        <div class="export-container">
            <div class="export-header">
                <h1><i class="fas fa-database"></i> Database Backup</h1>
                <p>Backup and download your OCABIS database</p>
            </div>
            <?php if ($fk_migration_ran): ?>
            <div class="export-info" style="margin-top:-10px;">
                <h3><i class="fas fa-link"></i> Schema Check</h3>
                <p style="margin:0;">Foreign keys verified and applied where missing.</p>
            </div>
            <?php elseif (!empty($fk_migration_error)): ?>
            <div class="warning-box">
                <h3><i class="fas fa-exclamation-triangle"></i> Schema Check Warning</h3>
                <p style="margin:0;">Could not auto-apply FK migration: <?php echo htmlspecialchars($fk_migration_error); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="warning-box">
                <h3><i class="fas fa-exclamation-triangle"></i> Important Notice</h3>
                <p>This feature allows you to export the complete database. Use this for backups and data migration purposes only. The exported files contain sensitive data and should be handled securely.</p>
            </div>
            
            <div class="export-section">
                <h2><i class="fas fa-clock"></i> Automatic Monthly Backup</h2>
                <div class="export-info">
                    <h3>How it works:</h3>
                    <ul>
                        <li>System automatically creates a backup every 30 days</li>
                        <li>Backup is created when you visit this page after 30 days</li>
                        <li>Backup is automatically sent to your email address (<?php 
                            echo htmlspecialchars($effective_super_admin_email);
                        ?>)</li>
                        <li>No external task scheduler required - it's built into the system</li>
                        <li>Last backup: 
                            <?php 
                            $backup_dir = __DIR__ . '/backups/';
                            $timestamp_file = $backup_dir . 'last_backup_timestamp.txt';
                            if (file_exists($timestamp_file)) {
                                $last_backup_time = (int)file_get_contents($timestamp_file);
                                echo date('F j, Y \a\t g:i A', $last_backup_time);
                            } else {
                                echo "No backup created yet";
                            }
                            ?>
                        </li>
                        <li>Next backup: 
                            <?php 
                            if (file_exists($timestamp_file)) {
                                $last_backup_time = (int)file_get_contents($timestamp_file);
                                $next_backup_time = $last_backup_time + (30 * 24 * 60 * 60);
                                $days_until = round((($next_backup_time - time()) / (60*60*24)));
                                if ($days_until <= 0) {
                                    echo "Due now (will be created on next page visit)";
                                } else {
                                    echo date('F j, Y', $next_backup_time) . " (in " . $days_until . " days)";
                                }
                            } else {
                                echo "Will be created on next page visit";
                            }
                            ?>
                        </li>
                    </ul>
                </div>
            </div>
            
            <?php if ($monthly_backup_created): ?>
            <div class="export-section">
                <h2><i class="fas fa-check-circle" style="color: #22c55e;"></i> Automatic Monthly Backup Created</h2>
                <div class="export-info">
                    <p style="margin-bottom: 10px;"><strong>✅ Backup Created:</strong> <?php echo htmlspecialchars($monthly_backup_filename); ?></p>
                    <p style="margin: 0;">
                        <?php if ($backup_email_sent): ?>
                            <span style="color: #22c55e;"><i class="fas fa-envelope"></i> Backup sent via email to: <?php 
                                echo htmlspecialchars($effective_super_admin_email);
                            ?></span>
                        <?php else: ?>
                            <span style="color: #f59e0b;"><i class="fas fa-exclamation-triangle"></i> Email notification could not be sent</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <div id="auto-download" style="display:none;">
                <a href="backups/<?php echo htmlspecialchars($monthly_backup_filename); ?>" download></a>
            </div>
            <script>
                // Automatically trigger download when backup is created
                setTimeout(function() {
                    var downloadLink = document.querySelector('#auto-download a');
                    if (downloadLink) {
                        downloadLink.click();
                    }
                }, 1000); // Small delay to ensure page loads
            </script>
            <?php endif; ?>
            
            <?php if (!$db_connected): ?>
            <div class="warning-box" style="background: #fef3c7; border-color: #fbbf24;">
                <h3><i class="fas fa-exclamation-triangle"></i> Database Not Connected</h3>
                <p style="color: #92400e;">The database is currently unavailable. You can still import a backup to restore the database. If you have a backup SQL file, use the import feature below.</p>
            </div>
            <?php else: ?>
            <div class="export-section">
                <h2><i class="fas fa-download"></i> Full Database Backup</h2>
                
                <div class="export-info">
                    <h3>What is exported:</h3>
                    <ul>
                        <li>Complete database structure (all tables)</li>
                        <li>All data from all tables</li>
                        <li>System configuration and user data</li>
                        <li>Export format: SQL file</li>
                    </ul>
                </div>
                
                <div class="export-buttons">
                    <a href="?export=sql" class="export-btn">
                        <i class="fas fa-database"></i>
                        <strong>Backup Full Database</strong>
                        <span>Download complete database as SQL file</span>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            
        </div>
    </div>
    
    <script>
        // Sidebar toggle functionality with mobile support
        (function() {
            const BODY_CLASS = 'sidebar-collapsed';
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            function isMobile() {
                return window.innerWidth <= 768;
            }

            function applyInitialState() {
                const saved = localStorage.getItem('ocabis:sidebar-collapsed');
                const isCollapsed = saved === '1';
                const fixedBtn = document.getElementById('sidebarToggleFixed');
                
                if (isMobile()) {
                    // On mobile, don't apply collapsed state initially
                    sidebar.classList.remove('open');
                    if (overlay) overlay.classList.remove('show');
                    document.body.style.overflow = '';
                    // Ensure hamburger button is visible on mobile
                    if (fixedBtn) {
                        fixedBtn.style.display = 'flex';
                        fixedBtn.style.visibility = 'visible';
                        fixedBtn.style.opacity = '1';
                    }
                } else {
                    // On desktop, apply saved state
                    document.body.classList.toggle(BODY_CLASS, isCollapsed);
                    // Hide hamburger button on desktop
                    if (fixedBtn) {
                        fixedBtn.style.display = 'none';
                    }
                }
            }

            function toggleSidebar() {
                const fixedBtn = document.getElementById('sidebarToggleFixed');
                
                if (isMobile()) {
                    // Mobile behavior: slide sidebar in/out with overlay
                    const isOpen = sidebar.classList.contains('open');
                    
                    if (isOpen) {
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                        // Show hamburger button when sidebar closes
                        if (fixedBtn) {
                            fixedBtn.style.display = 'flex';
                            fixedBtn.style.visibility = 'visible';
                            fixedBtn.style.opacity = '1';
                        }
                    } else {
                        sidebar.classList.add('open');
                        if (overlay) overlay.classList.add('show');
                        document.body.style.overflow = 'hidden';
                        // Hide hamburger button when sidebar opens
                        if (fixedBtn) {
                            fixedBtn.style.display = 'none';
                        }
                    }
                } else {
                    // Desktop behavior: collapse/expand
                    const isCollapsed = document.body.classList.toggle(BODY_CLASS);
                    localStorage.setItem('ocabis:sidebar-collapsed', isCollapsed ? '1' : '0');
                }
            }

            // Close sidebar when clicking overlay (mobile only)
            if (overlay) {
                overlay.addEventListener('click', function() {
                    if (isMobile()) {
                        const fixedBtn = document.getElementById('sidebarToggleFixed');
                        sidebar.classList.remove('open');
                        overlay.classList.remove('show');
                        document.body.style.overflow = '';
                        // Show hamburger button when sidebar closes via overlay
                        if (fixedBtn) {
                            fixedBtn.style.display = 'flex';
                            fixedBtn.style.visibility = 'visible';
                            fixedBtn.style.opacity = '1';
                        }
                    }
                });
            }

            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    const fixedBtn = document.getElementById('sidebarToggleFixed');
                    
                    if (isMobile()) {
                        // On mobile, ensure sidebar is closed and reset desktop state
                        document.body.classList.remove(BODY_CLASS);
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                        // Ensure hamburger button is visible on mobile
                        if (fixedBtn) {
                            fixedBtn.style.display = 'flex';
                            fixedBtn.style.visibility = 'visible';
                            fixedBtn.style.opacity = '1';
                        }
                    } else {
                        // On desktop, close mobile sidebar and apply desktop state
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                        // Hide hamburger button on desktop
                        if (fixedBtn) {
                            fixedBtn.style.display = 'none';
                        }
                        applyInitialState();
                    }
                }, 250);
            });

            const inlineBtn = document.getElementById('sidebarToggle');
            const fixedBtn = document.getElementById('sidebarToggleFixed');
            const mobileInlineBtn = document.getElementById('sidebarToggleMobile');
            if (inlineBtn) inlineBtn.addEventListener('click', toggleSidebar);
            if (fixedBtn) fixedBtn.addEventListener('click', toggleSidebar);
            if (mobileInlineBtn) mobileInlineBtn.addEventListener('click', toggleSidebar);
            
            applyInitialState();
        })();
        
        // Toggle password visibility
        function togglePassword(inputId, iconId) {
            const passwordField = document.getElementById(inputId);
            const toggleIcon = document.getElementById(iconId);
            
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
    </script>
    <?php endif; ?>
</body>
</html>


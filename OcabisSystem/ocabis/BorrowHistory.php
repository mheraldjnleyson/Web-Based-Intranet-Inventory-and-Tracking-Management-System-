<?php
session_start();

// Set timezone to Philippines (Asia/Manila) for accurate date/time display
date_default_timezone_set('Asia/Manila');

// redirect if not logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$conn = @new mysqli('localhost', 'root', '', 'ocabis');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Skip migration checks on every page load - these should only run once via a migration script
// Migration checks are now cached - only run if migration flag doesn't exist
$migration_flag_file = __DIR__ . '/.borrow_history_migrated';
if (!file_exists($migration_flag_file)) {
    // Run migrations only once
    try {
        // Check and migrate return_date column to DATETIME if needed
        $check_col = $conn->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'borrow_history' 
                                    AND COLUMN_NAME = 'return_date'");
        if ($check_col && $check_col->num_rows > 0) {
            $col_info = $check_col->fetch_assoc();
            if (strtoupper($col_info['DATA_TYPE']) === 'DATE') {
                $migrate_query = "ALTER TABLE `borrow_history` MODIFY COLUMN `return_date` DATETIME DEFAULT NULL";
                if ($conn->query($migrate_query)) {
                    error_log("Successfully migrated return_date column from DATE to DATETIME");
                }
            }
        }
        
        // Check and migrate borrow_date column to DATETIME if needed
        $check_col = $conn->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'borrow_history' 
                                    AND COLUMN_NAME = 'borrow_date'");
        if ($check_col && $check_col->num_rows > 0) {
            $col_info = $check_col->fetch_assoc();
            $data_type = strtoupper($col_info['DATA_TYPE']);
            if ($data_type === 'DATE') {
                $update_query = "UPDATE `borrow_history` SET `borrow_date` = `created_at` WHERE `borrow_date` IS NOT NULL";
                $conn->query($update_query);
                $migrate_query = "ALTER TABLE `borrow_history` MODIFY COLUMN `borrow_date` DATETIME NOT NULL";
                $conn->query($migrate_query);
            }
        }
        
        // Check and migrate due_date column to DATETIME if needed
        $check_col = $conn->query("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'borrow_history' 
                                    AND COLUMN_NAME = 'due_date'");
        if ($check_col && $check_col->num_rows > 0) {
            $col_info = $check_col->fetch_assoc();
            $data_type = strtoupper($col_info['DATA_TYPE']);
            if ($data_type === 'DATE') {
                $update_query = "UPDATE `borrow_history` SET `due_date` = CONCAT(`due_date`, ' 23:59:59') WHERE `due_date` IS NOT NULL";
                $conn->query($update_query);
                $migrate_query = "ALTER TABLE `borrow_history` MODIFY COLUMN `due_date` DATETIME NOT NULL";
                $conn->query($migrate_query);
            }
        }
        
        // Check and add received_date column if it doesn't exist
        $check_col = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'borrow_history' 
                                    AND COLUMN_NAME = 'received_date'");
        if ($check_col && $check_col->num_rows === 0) {
            $add_col_query = "ALTER TABLE `borrow_history` ADD COLUMN `received_date` DATETIME DEFAULT NULL AFTER `due_date`";
            $conn->query($add_col_query);
        }
        
        // Check and update status ENUM to include 'approved' and 'received' if they don't exist
        $check_enum = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
                                    WHERE TABLE_SCHEMA = DATABASE() 
                                    AND TABLE_NAME = 'borrow_history' 
                                    AND COLUMN_NAME = 'status'");
        if ($check_enum && $check_enum->num_rows > 0) {
            $enum_row = $check_enum->fetch_assoc();
            $current_enum = $enum_row['COLUMN_TYPE'] ?? '';
            $has_approved = stripos($current_enum, "'approved'") !== false;
            $has_received = stripos($current_enum, "'received'") !== false;
            
            if (!$has_approved || !$has_received) {
                $update_enum_query = "ALTER TABLE `borrow_history` 
                                      MODIFY COLUMN `status` ENUM('pending', 'approved', 'active', 'returned', 'overdue', 'declined', 'received') NOT NULL DEFAULT 'pending'";
                $conn->query($update_enum_query);
            }
        }
        
        // Create migration flag file to skip checks on future loads
        file_put_contents($migration_flag_file, date('Y-m-d H:i:s'));
    } catch (Exception $e) {
        error_log("Error during migration: " . $e->getMessage());
    }
}

// Sanitize input function
function sanitizeInput($input) {
    global $conn;
    return $conn->real_escape_string(trim($input));
}

// Get current logged in info
$username = $_SESSION['username'];
$department = $_SESSION['department'];
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
// Determine if user is a viewer (borrower) - no department and not admin
$isViewer = empty($department) && !$isAdmin && !$isSuperAdmin;
// Department head: admin but not super admin
$isDepartmentHead = $isAdmin && !$isSuperAdmin;

if (isset($_GET['action']) && $_GET['action'] === 'get_borrow_history') {
    try {
        // Format all dates to include timestamp
        $query = "SELECT bh.*, 
                         i.name as item_name,
                         i.item_code,
                         bh.item_placement,
                         CASE 
                             WHEN bh.borrow_date IS NOT NULL THEN 
                                 DATE_FORMAT(bh.borrow_date, '%Y-%m-%d %H:%i:%s')
                             ELSE NULL 
                         END as borrow_date_formatted,
                         CASE 
                             WHEN bh.due_date IS NOT NULL THEN 
                                 DATE_FORMAT(bh.due_date, '%Y-%m-%d %H:%i:%s')
                             ELSE NULL 
                         END as due_date_formatted,
                         CASE 
                             WHEN bh.return_date IS NOT NULL THEN 
                                 DATE_FORMAT(bh.return_date, '%Y-%m-%d %H:%i:%s')
                             ELSE NULL 
                         END as return_date_formatted
                  FROM borrow_history bh 
                  LEFT JOIN items i ON bh.item_id = i.id 
                  ORDER BY bh.created_at DESC";
        
        // Add filtering if parameters are provided
        $where_conditions = [];
        $params = [];
        $param_types = "";
        
        if (!empty($_GET['status'])) {
            $where_conditions[] = "bh.status = ?";
            $params[] = $_GET['status'];
            $param_types .= "s";
        }
        
        if (!empty($_GET['department'])) {
            $where_conditions[] = "bh.department_name = ?";
            $params[] = $_GET['department'];
            $param_types .= "s";
        }

        // For viewers (borrowers), only show their own borrow history
        // ALWAYS filter by viewer's username - even when loading all for counting
        // BUT: Skip this restriction if "borrowed_items_only" filter is active
        if ($isViewer && empty($_GET['borrowed_items_only'])) {
            $where_conditions[] = "bh.borrower_name = ?";
            $params[] = $username;
            $param_types .= "s";
        }
        // Enforce department scoping for non-super-admins when no department filter provided
        // Only super admins can see all departments; others see only their own department
        // ALWAYS filter by department for department heads - even when loading all for counting
        // BUT: Skip this if "borrowed_items_only" filter is active
        elseif (!$isSuperAdmin && empty($_GET['department']) && !empty($department) && empty($_GET['borrowed_items_only'])) {
            $where_conditions[] = "bh.department_name = ?";
            $params[] = $department;
            $param_types .= "s";
        }
        // If department filter is provided but user is not super admin, validate it's their department
        // ALWAYS enforce department restriction for department heads - even when loading all for counting
        // BUT: Skip this if "borrowed_items_only" filter is active
        elseif (!$isSuperAdmin && !empty($_GET['department']) && !empty($department) && empty($_GET['borrowed_items_only'])) {
            // Only allow filtering by their own department
            if ($_GET['department'] !== $department) {
                // Reset to their own department
                $where_conditions[] = "bh.department_name = ?";
                $params[] = $department;
                $param_types .= "s";
            }
        }
        
        if (!empty($_GET['search'])) {
            $where_conditions[] = "(bh.borrower_name LIKE ? OR i.name LIKE ? OR bh.borrow_id LIKE ?)";
            $search_term = "%" . $_GET['search'] . "%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $param_types .= "sss";
        }
        
        // Filter by borrower name if provided (for "Borrowed Items" card)
        if (!empty($_GET['borrower_name'])) {
            $where_conditions[] = "bh.borrower_name = ?";
            $params[] = $_GET['borrower_name'];
            $param_types .= "s";
        }
        
        // Special filter for "Borrowed Items" - only show items that are currently borrowed
        // (approved, received, active, or overdue - not pending, returned, or declined)
        if (!empty($_GET['borrowed_items_only']) && $_GET['borrowed_items_only'] === 'true') {
            $where_conditions[] = "bh.status IN ('approved', 'received', 'active', 'overdue')";
            // No parameters needed for this condition
        }
        
        if (!empty($where_conditions)) {
            $query = str_replace("ORDER BY", "WHERE " . implode(" AND ", $where_conditions) . " ORDER BY", $query);
        }
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        
        // Batch update overdue items BEFORE fetching to avoid per-row updates
        $today = date('Y-m-d H:i:s');
        $batch_update_query = "UPDATE borrow_history 
                              SET status = 'overdue', priority = 'high' 
                              WHERE status = 'active' 
                              AND due_date IS NOT NULL 
                              AND due_date < ?";
        $batch_update_stmt = $conn->prepare($batch_update_query);
        $batch_update_stmt->bind_param("s", $today);
        $batch_update_stmt->execute();
        $batch_update_stmt->close();
        
        // Now execute the main query
        $stmt->execute();
        $result = $stmt->get_result();
        
        $borrow_records = [];
        while ($row = $result->fetch_assoc()) {
            // Mark as overdue in memory if needed (already updated in DB via batch update)
            if ($row['status'] === 'active' && !empty($row['due_date']) && strtotime($row['due_date']) < time()) {
                $row['status'] = 'overdue';
                $row['priority'] = 'high';
            }
            
            // Use formatted dates if available (includes timestamp), otherwise use original
            if (isset($row['borrow_date_formatted']) && $row['borrow_date_formatted']) {
                $row['borrow_date'] = $row['borrow_date_formatted'];
            }
            if (isset($row['due_date_formatted']) && $row['due_date_formatted']) {
                $row['due_date'] = $row['due_date_formatted'];
            }
            if (isset($row['received_date_formatted']) && $row['received_date_formatted']) {
                $row['received_date'] = $row['received_date_formatted'];
            }
            if (isset($row['return_date_formatted']) && $row['return_date_formatted']) {
                $row['return_date'] = $row['return_date_formatted'];
            }
            // Remove the temporary formatted fields
            unset($row['borrow_date_formatted']);
            unset($row['due_date_formatted']);
            unset($row['received_date_formatted']);
            unset($row['return_date_formatted']);
            
            $borrow_records[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'borrow_records' => $borrow_records
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Get single borrow record details
if (isset($_GET['action']) && $_GET['action'] === 'get_borrow_details') {
    try {
        $borrow_id = sanitizeInput($_GET['borrow_id']);
        
        $query = "SELECT bh.*, 
                         i.name as item_name, 
                         i.item_code,
                         i.description as item_description, 
                         i.category, 
                         i.location, 
                         i.qr_code,
                         bh.item_placement,
                         CASE 
                             WHEN bh.borrow_date IS NOT NULL THEN 
                                 DATE_FORMAT(bh.borrow_date, '%Y-%m-%d %H:%i:%s')
                             ELSE NULL 
                         END as borrow_date_formatted,
                         CASE 
                             WHEN bh.due_date IS NOT NULL THEN 
                                 DATE_FORMAT(bh.due_date, '%Y-%m-%d %H:%i:%s')
                             ELSE NULL 
                         END as due_date_formatted,
                         CASE 
                             WHEN bh.received_date IS NOT NULL THEN 
                                 DATE_FORMAT(bh.received_date, '%Y-%m-%d %H:%i:%s')
                             ELSE NULL 
                         END as received_date_formatted,
                         CASE 
                             WHEN bh.return_date IS NOT NULL THEN 
                                 DATE_FORMAT(bh.return_date, '%Y-%m-%d %H:%i:%s')
                             ELSE NULL 
                         END as return_date_formatted
                  FROM borrow_history bh 
                  LEFT JOIN items i ON bh.item_id = i.id 
                  WHERE bh.borrow_id = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $borrow_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $record = $result->fetch_assoc();
            
            // Permission check: 
            // - Super admins can view all records
            // - Users can view records where they are the borrower (even from other departments)
            // - Non-super-admins can only view records from their own department (unless they are the borrower)
            if (!$isSuperAdmin && !$isViewer && !empty($department)) {
                $record_dept = $record['department_name'] ?? '';
                $record_borrower = $record['borrower_name'] ?? '';
                $isCurrentUserBorrower = strtolower(trim($record_borrower)) === strtolower(trim($username));
                
                // Allow if user is the borrower OR if record is from their department
                if (!$isCurrentUserBorrower && $record_dept !== $department) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'You can only view borrow records from your own department or records where you are the borrower'
                    ]);
                    exit;
                }
            }
            
            // Use formatted dates if available (includes timestamp), otherwise use original
            if (isset($record['borrow_date_formatted']) && $record['borrow_date_formatted']) {
                $record['borrow_date'] = $record['borrow_date_formatted'];
            }
            if (isset($record['due_date_formatted']) && $record['due_date_formatted']) {
                $record['due_date'] = $record['due_date_formatted'];
            }
            if (isset($record['received_date_formatted']) && $record['received_date_formatted']) {
                $record['received_date'] = $record['received_date_formatted'];
            }
            if (isset($record['return_date_formatted']) && $record['return_date_formatted']) {
                $record['return_date'] = $record['return_date_formatted'];
            }
            // Remove the temporary formatted fields
            unset($record['borrow_date_formatted']);
            unset($record['due_date_formatted']);
            unset($record['received_date_formatted']);
            unset($record['return_date_formatted']);
            
            // Format location: Use item_placement if available, otherwise use item location
            // Format: "Building, Floor X, Room Name" or "Building, Floor X, Room Number"
            $locationToDisplay = '';
            if (!empty($record['item_placement'])) {
                // Check if it's new format (comma-separated) or old format (dash-separated)
                if (strpos($record['item_placement'], ',') !== false) {
                    // New format: "Building, Floor X, Room Name" or "Building, Floor X, Room Number"
                    $parts = explode(',', $record['item_placement']);
                    if (count($parts) >= 3) {
                        $building = trim($parts[0]);
                        $floor = trim($parts[1]); // Already has "Floor X" format
                        $room = trim($parts[2]);
                        $locationToDisplay = $building . ', ' . $floor . ', ' . $room;
                    } else {
                        $locationToDisplay = $record['item_placement'];
                    }
                } else {
                    // Old format: "Building X - Floor Y - Room Name" or "Building X - Floor Y - Room Number"
                    $parts = explode(' - ', $record['item_placement']);
                    if (count($parts) >= 3) {
                        $building = trim($parts[0]);
                        $floor = trim($parts[1]);
                        // Keep "Floor" if present, otherwise add it
                        if (!preg_match('/^Floor\s+/i', $floor)) {
                            $floor = 'Floor ' . preg_replace('/^Floor\s+/i', '', $floor);
                        }
                        $room = trim($parts[2]);
                        
                        // Check if room starts with "Room " and extract number, otherwise use as room name
                        if (preg_match('/^Room\s+(.+)$/i', $room, $matches)) {
                            $room = $matches[1]; // Use room number
                        }
                        
                        // Format as "Building, Floor X, Room Name" or "Building, Floor X, Room Number"
                        $locationToDisplay = $building . ', ' . $floor . ', ' . $room;
                    } else {
                        $locationToDisplay = $record['item_placement'];
                    }
                }
            } else if (!empty($record['location'])) {
                // Check if it's new format (comma-separated) or old format (dash-separated)
                if (strpos($record['location'], ',') !== false) {
                    // New format: "Building, Floor X, Room Name" or "Building, Floor X, Room Number"
                    $parts = explode(',', $record['location']);
                    if (count($parts) >= 3) {
                        $building = trim($parts[0]);
                        $floor = trim($parts[1]); // Already has "Floor X" format
                        $room = trim($parts[2]);
                        $locationToDisplay = $building . ', ' . $floor . ', ' . $room;
                    } else {
                        $locationToDisplay = $record['location'];
                    }
                } else {
                    // Old format: "Building X - Floor Y - Room Name" or "Building X - Floor Y - Room Number"
                    $parts = explode(' - ', $record['location']);
                    if (count($parts) >= 3) {
                        $building = trim($parts[0]);
                        $floor = trim($parts[1]);
                        // Keep "Floor" if present, otherwise add it
                        if (!preg_match('/^Floor\s+/i', $floor)) {
                            $floor = 'Floor ' . preg_replace('/^Floor\s+/i', '', $floor);
                        }
                        $room = trim($parts[2]);
                        
                        // Check if room starts with "Room " and extract number, otherwise use as room name
                        if (preg_match('/^Room\s+(.+)$/i', $room, $matches)) {
                            $room = $matches[1]; // Use room number
                        }
                        
                        // Format as "Building, Floor X, Room Name" or "Building, Floor X, Room Number"
                        $locationToDisplay = $building . ', ' . $floor . ', ' . $room;
                    } else {
                        $locationToDisplay = $record['location'];
                    }
                }
            } else {
                $locationToDisplay = 'N/A';
            }
            
            // Set formatted location
            $record['formatted_location'] = $locationToDisplay;
            
            echo json_encode([
                'success' => true,
                'record' => $record
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Record not found'
            ]);
        }
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Add function to update borrow record (mark as returned, etc.)
if (isset($_POST['action']) && $_POST['action'] === 'update_borrow') {
    try {
        $borrow_id = sanitizeInput($_POST['borrow_id']);
        $update_type = sanitizeInput($_POST['update_type']);
        
        if ($update_type === 'mark_returned') {
            // Start transaction first to prevent race conditions
            $conn->begin_transaction();
            
            try {
                // Get borrow record details with row lock to prevent concurrent updates
                $get_record = "SELECT * FROM borrow_history WHERE borrow_id = ? AND status != 'returned' FOR UPDATE";
                $get_stmt = $conn->prepare($get_record);
                $get_stmt->bind_param("s", $borrow_id);
                $get_stmt->execute();
                $record_result = $get_stmt->get_result();
                
                if ($record_result->num_rows === 0) {
                    $get_stmt->close();
                    $conn->rollback();
                    throw new Exception("Active borrow record not found or already returned");
                }
                
                $record = $record_result->fetch_assoc();
                $get_stmt->close();
                
                // Permission check: only super admins can mark items as returned from other departments
                if (!$isSuperAdmin && !$isViewer && !empty($department)) {
                    $record_dept = $record['department_name'] ?? '';
                    if ($record_dept !== $department) {
                        $conn->rollback();
                        throw new Exception("You can only mark items as returned from your own department");
                    }
                }
                
                // Double-check status hasn't changed (race condition protection)
                if ($record['status'] === 'returned') {
                    $conn->rollback();
                    throw new Exception("This item has already been returned");
                }
                
                // Update borrow record with datetime (NOW() includes timestamp)
                // Note: If return_date column is 'date' type, only date will be stored
                // If you want full timestamp, change column type to 'datetime' in database
                // Use WHERE clause to ensure we only update if status is not already 'returned'
                $update_query = "UPDATE borrow_history SET status = 'returned', return_date = NOW(), updated_at = NOW() WHERE borrow_id = ? AND status != 'returned'";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("s", $borrow_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update borrow record");
                }
                
                // Check if update actually affected a row (prevents double processing)
                if ($update_stmt->affected_rows === 0) {
                    $update_stmt->close();
                    $conn->rollback();
                    throw new Exception("Item has already been returned");
                }
                $update_stmt->close();
                
                // Return quantity to item (only if we successfully updated the status)
                $return_quantity = "UPDATE items SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?";
                $return_stmt = $conn->prepare($return_quantity);
                $return_stmt->bind_param("ii", $record['quantity_borrowed'], $record['item_id']);
                
                if (!$return_stmt->execute()) {
                    throw new Exception("Failed to return item quantity");
                }
                $return_stmt->close();
                
                // Get updated item quantity to determine status
                $get_item = "SELECT quantity FROM items WHERE id = ?";
                $get_item_stmt = $conn->prepare($get_item);
                $get_item_stmt->bind_param("i", $record['item_id']);
                $get_item_stmt->execute();
                $item_result = $get_item_stmt->get_result();
                $item_data = $item_result->fetch_assoc();
                $new_quantity = $item_data['quantity'];
                $get_item_stmt->close();
                
                // Update item status back to "Working" if quantity > 0
                // Check if display_status column exists
                $check_col = $conn->query("SHOW COLUMNS FROM items LIKE 'display_status'");
                if ($check_col && $check_col->num_rows > 0) {
                    // Column exists, set display_status to NULL (will fallback to status) and ensure status is "Working"
                    if ($new_quantity > 0) {
                        $update_item = $conn->prepare("UPDATE items SET display_status = NULL, status = 'Working', updated_at = NOW() WHERE id = ?");
                        $update_item->bind_param("i", $record['item_id']);
                        $update_item->execute();
                        $update_item->close();
                    }
                } else {
                    // Column doesn't exist, update status field directly
                    if ($new_quantity > 0) {
                        $update_item = $conn->prepare("UPDATE items SET status = 'Working', updated_at = NOW() WHERE id = ?");
                        $update_item->bind_param("i", $record['item_id']);
                        $update_item->execute();
                        $update_item->close();
                    }
                }
                
                $conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Item verified via QR code and marked as returned successfully'
                ]);
                
            } catch (Exception $inner_e) {
                $conn->rollback();
                throw $inner_e;
            }
            
        } elseif ($update_type === 'mark_received') {
            // Mark as received (for viewers/teachers)
            // Start transaction first to prevent race conditions
            $conn->begin_transaction();
            
            try {
                // Get borrow record details with row lock to prevent concurrent updates
                // Handle NULL and empty string status
                $get_record = "SELECT * FROM borrow_history WHERE borrow_id = ? AND (status IS NULL OR status = '' OR status NOT IN ('returned', 'received')) FOR UPDATE";
                $get_stmt = $conn->prepare($get_record);
                $get_stmt->bind_param("s", $borrow_id);
                $get_stmt->execute();
                $record_result = $get_stmt->get_result();
                
                if ($record_result->num_rows === 0) {
                    $get_stmt->close();
                    $conn->rollback();
                    throw new Exception("Active borrow record not found or already received/returned");
                }
                
                $record = $record_result->fetch_assoc();
                $get_stmt->close();
                
                // Double-check status hasn't changed (race condition protection)
                // Handle NULL and empty string status
                $current_status = trim($record['status'] ?? '');
                if ($current_status && in_array(strtolower($current_status), ['returned', 'received'])) {
                    $conn->rollback();
                    throw new Exception("This item has already been received/returned");
                }
                
                // Update borrow record with status 'received' and set received_date (not return_date)
                // Handle NULL and empty string status
                $update_query = "UPDATE borrow_history SET status = 'received', received_date = NOW(), updated_at = NOW() WHERE borrow_id = ? AND (status IS NULL OR status = '' OR status NOT IN ('returned', 'received'))";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("s", $borrow_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update borrow record");
                }
                
                // Check if update actually affected a row (prevents double processing)
                if ($update_stmt->affected_rows === 0) {
                    $update_stmt->close();
                    $conn->rollback();
                    throw new Exception("Item has already been received/returned");
                }
                $update_stmt->close();
                
                // Return quantity to item (only if we successfully updated the status)
                $return_quantity = "UPDATE items SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?";
                $return_stmt = $conn->prepare($return_quantity);
                $return_stmt->bind_param("ii", $record['quantity_borrowed'], $record['item_id']);
                
                if (!$return_stmt->execute()) {
                    throw new Exception("Failed to return item quantity");
                }
                $return_stmt->close();
                
                // Get updated item quantity to determine status
                $get_item = "SELECT quantity FROM items WHERE id = ?";
                $get_item_stmt = $conn->prepare($get_item);
                $get_item_stmt->bind_param("i", $record['item_id']);
                $get_item_stmt->execute();
                $item_result = $get_item_stmt->get_result();
                $item_data = $item_result->fetch_assoc();
                $new_quantity = $item_data['quantity'];
                $get_item_stmt->close();
                
                // Update item status back to "Working" if quantity > 0
                // Check if display_status column exists
                $check_col = $conn->query("SHOW COLUMNS FROM items LIKE 'display_status'");
                if ($check_col && $check_col->num_rows > 0) {
                    // Column exists, set display_status to NULL (will fallback to status) and ensure status is "Working"
                    if ($new_quantity > 0) {
                        $update_item = $conn->prepare("UPDATE items SET display_status = NULL, status = 'Working', updated_at = NOW() WHERE id = ?");
                        $update_item->bind_param("i", $record['item_id']);
                        $update_item->execute();
                        $update_item->close();
                    }
                } else {
                    // Column doesn't exist, update status field directly
                    if ($new_quantity > 0) {
                        $update_item = $conn->prepare("UPDATE items SET status = 'Working', updated_at = NOW() WHERE id = ?");
                        $update_item->bind_param("i", $record['item_id']);
                        $update_item->execute();
                        $update_item->close();
                    }
                }
                
                $conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Item received successfully'
                ]);
                
            } catch (Exception $inner_e) {
                $conn->rollback();
                throw $inner_e;
            }
            
        } else {
            throw new Exception("Invalid update type");
        }
        
    } catch (Exception $e) {
        if ($conn && $conn->in_transaction) {
            $conn->rollback();
        }
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Delete borrow record - disabled for safety

// Send reminder email
if (isset($_POST['action']) && $_POST['action'] === 'send_reminder') {
    try {
        require_once __DIR__ . '/email_notifications.php';
        
        $borrower_email = sanitizeInput($_POST['borrower_email']);
        $borrower_name = sanitizeInput($_POST['borrower_name']);
        $item_name = sanitizeInput($_POST['item_name']);
        $due_date = sanitizeInput($_POST['due_date']);
        $is_overdue = isset($_POST['is_overdue']) && $_POST['is_overdue'] === '1';
        $days = (int)$_POST['days'];
        
        // Validate email format
        if (!filter_var($borrower_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }
        
        if ($is_overdue) {
            // Calculate days overdue
            $dueDate = new DateTime($due_date);
            $today = new DateTime();
            $daysOverdue = $today->diff($dueDate)->days;
            
            // Send overdue email
            $success = sendOverdueItemEmail(
                $borrower_email,
                $borrower_name,
                $item_name,
                $due_date,
                $daysOverdue
            );
            
            if (!$success) {
                throw new Exception("Failed to send overdue email");
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Overdue reminder email sent successfully',
                'type' => 'overdue'
            ]);
            
        } else {
            // Send due date reminder email
            $success = sendDueDateReminderEmail(
                $borrower_email,
                $borrower_name,
                $item_name,
                $due_date,
                $days
            );
            
            if (!$success) {
                throw new Exception("Failed to send reminder email");
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Due date reminder email sent successfully',
                'type' => 'reminder'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// Get department list for filter dropdown (only for non-viewers)
function getDepartments() {
    global $conn, $isSuperAdmin, $department;
    if ($isSuperAdmin) {
        // Super admins see all departments from departments table
        $query = "SELECT DISTINCT name FROM departments WHERE name IS NOT NULL ORDER BY name";
        $result = $conn->query($query);
    } else if (!empty($department)) {
        // Regular users only see their own department
        $query = "SELECT DISTINCT name FROM departments WHERE name = ? ORDER BY name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $department);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        // Users without department see no departments
        $query = "SELECT DISTINCT name FROM departments WHERE 1=0";
        $result = $conn->query($query);
    }
    $departments = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row['name'];
        }
    }
    return $departments;
}

$departments = getDepartments();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>OCABIS - Borrow History</title>
    <link rel="stylesheet" href="css/borrow.css">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <script src="js/session_monitor.js"></script>
    <link rel="stylesheet" href="Css/department.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script src="modal.js"></script>
    <style>
        /* Match sidebar spacing with dashboard defaults */
        .sidebar .nav-item {
            margin-bottom: 4px !important;
        }
        .sidebar .nav-link {
            gap: 10px !important;
            letter-spacing: normal !important;
        }
        .sidebar .nav-link span,
        .sidebar .nav-label {
            letter-spacing: normal !important;
        }
        
        /* Status Cards Styles */
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 16px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1);
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .stat-card.active {
            background: #f3f4f6;
            border: 2px solid #3b82f6;
        }
        
        @media (max-width: 768px) {
            .summary-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .stat-card {
                padding: 12px;
            }
            
            .stat-number {
                font-size: 24px !important;
            }
            
            .stat-label {
                font-size: 12px !important;
            }
        }
    </style>
    <style>
        /* Item Detail Modal Scrolling Fix */
        .item-detail-modal {
            overflow: hidden !important;
        }
        
        .item-detail-content {
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
            max-height: 95vh !important;
        }
        
        .item-detail-body {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            flex: 1 !important;
            min-height: 0 !important;
            -webkit-overflow-scrolling: touch !important;
        }
        
        /* QR Scanner Modal Styles */
        #qrReturnScannerModal {
            z-index: 10000 !important;
        }
        
        #qrReturnScannerModal.show {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            opacity: 1 !important;
        }
        
        #qrReturnScannerModal .modal {
            max-width: 600px;
            width: 95%;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            z-index: 10001;
        }
        
        #qrReturnScannerModal #qrReturnReader {
            width: 100%;
            min-height: 300px;
            background: #f3f4f6;
            border-radius: 8px;
            position: relative;
        }
        
        #qrReturnScannerModal #qrReturnReader video {
            width: 100%;
            height: auto;
            border-radius: 8px;
        }
        
        #qrReturnScannerModal #qrReturnResult {
            margin-top: 20px;
            padding: 0 10px;
        }
        
        #qrReturnScannerModal .close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
        }
        
        /* Viewer QR Scanner Modal Styles */
        #viewerQRScannerModal {
            z-index: 10000 !important;
        }
        
        #viewerQRScannerModal.show {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            opacity: 1 !important;
        }
        
        #viewerQRScannerModal .modal {
            max-width: 700px;
            width: 95%;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            z-index: 10001;
        }
        
        #viewerQRScannerModal #viewerQRReader {
            width: 100%;
            min-height: 300px;
            background: #f3f4f6;
            border-radius: 8px;
            position: relative;
        }
        
        #viewerQRScannerModal #viewerQRReader video {
            width: 100%;
            height: auto;
            border-radius: 8px;
        }
        
        #viewerQRScannerModal #viewerQRResult {
            margin-top: 20px;
            padding: 0 10px;
        }
        
        #viewerQRScannerModal .close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
        }
        
        #viewerItemDetailsContent {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        #viewerItemDetailsContent .item-detail-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #2563eb;
        }
        
        #viewerItemDetailsContent .item-detail-label {
            font-weight: 600;
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        #viewerItemDetailsContent .item-detail-value {
            color: #1f2937;
            font-size: 16px;
        }
        
        /* Desktop Table Styles - Ensure visibility */
        .table-container {
            width: 100%;
            overflow-x: auto;
            display: block;
        }

        .table {
            width: 100%;
            display: table;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            display: table-cell;
            visibility: visible;
            pointer-events: auto;
        }

        .table tbody tr {
            display: table-row;
            visibility: visible;
        }

        /* Ensure action button is clickable on desktop */
        .action-btn {
            pointer-events: auto !important;
            position: relative;
            z-index: 10;
            cursor: pointer !important;
        }

        .table td:last-child {
            pointer-events: auto;
            position: relative;
            z-index: 10;
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

        /* Sidebar Mobile Responsive Styles */
        @media (max-width: 768px) {
            /* Show fixed hamburger on mobile - always visible */
            #sidebarToggleFixed { 
                display: flex !important; 
                visibility: visible !important;
                opacity: 1 !important;
                z-index: 1300;
                position: fixed !important;
                top: 15px !important;
                left: 15px !important;
            }

            /* Hide inline toggle on mobile */
            #sidebarToggle {
                display: none;
            }

            /* Slide sidebar in/out on mobile */
            .sidebar { 
                transform: translateX(-100%); 
                transition: transform 0.3s ease;
                z-index: 1200;
                width: 250px !important;
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                height: 100vh !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
            }
            
            .sidebar.open { 
                transform: translateX(0); 
            }

            /* Content should be full width */
            .main-content { 
                margin-left: 0 !important; 
            }

            /* Ensure toggle button is always on top */
            .sidebar-toggle-fixed {
                background: rgba(229, 62, 62, 0.95) !important;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
            }

            /* Hide mobile inline toggle - we only use fixed toggle on left */
            #sidebarToggleMobile,
            .sidebar-toggle-mobile-inline {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }

            /* Main Content Mobile */
            .main-content {
                margin-left: 0 !important;
                padding: 10px !important;
                width: 100% !important;
            }

            /* Page Header */
            .page-header {
                margin-bottom: 20px;
            }

            .page-title {
                font-size: 24px !important;
            }

            .page-subtitle {
                font-size: 14px !important;
            }

            /* Dashboard Header - Charts */
            .dashboard-header {
                flex-direction: column !important;
                gap: 12px !important;
            }

            .chart-card {
                width: 100% !important;
                flex: none !important;
                min-width: 100% !important;
                max-width: 100% !important;
                height: 220px !important;
                max-height: 220px !important;
                min-height: 220px !important;
            }

            .chart-container {
                height: 160px !important;
                max-height: 160px !important;
                min-height: 160px !important;
            }

            .chart-title {
                font-size: 11px !important;
                min-height: 40px;
                height: auto;
                max-height: none;
                overflow: visible;
                white-space: normal;
                line-height: 1.4;
                word-wrap: break-word;
            }

            /* Filters Section */
            .filters-section {
                flex-direction: column !important;
                gap: 10px !important;
                padding: 15px !important;
            }

            .search-input,
            .filter-select,
            .download-btn {
                width: 100% !important;
                font-size: 14px;
            }

            /* Table Container */
            .table-container {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch;
                margin: 0 -10px;
                padding: 0 10px;
                width: 100%;
                display: block;
            }

            .table {
                min-width: 800px;
                width: 100%;
                display: table;
            }

            .table th,
            .table td {
                padding: 8px !important;
                font-size: 12px !important;
                display: table-cell;
                visibility: visible;
            }

            .table tbody tr {
                display: table-row;
                visibility: visible;
            }

            /* Ensure action button is clickable on mobile */
            .action-btn {
                pointer-events: auto !important;
                position: relative;
                z-index: 10;
                cursor: pointer !important;
                touch-action: manipulation;
            }

            .table td:last-child {
                pointer-events: auto;
                position: relative;
                z-index: 10;
            }

            /* Pagination */
            .pagination-container {
                flex-direction: column !important;
                gap: 15px !important;
                align-items: flex-start !important;
            }

            .pagination-buttons {
                width: 100%;
                justify-content: center;
            }

            /* Modals */
            .modal {
                width: 95% !important;
                max-width: 95% !important;
                padding: 16px !important;
                margin: 10px !important;
            }

            .details-modal {
                max-width: 95% !important;
            }

            .details-grid {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
            }

            /* Item Detail Modal Mobile */
            .item-detail-modal {
                padding: 10px 0 !important;
            }

            .item-detail-content {
                width: 95% !important;
                max-width: 95% !important;
                margin: 10px auto !important;
            }

            .item-detail-header {
                padding: 20px !important;
                flex-direction: column !important;
                gap: 15px !important;
            }

            .item-header-info {
                flex-direction: column !important;
                text-align: center !important;
                gap: 10px !important;
            }

            .item-icon-large {
                font-size: 36px !important;
                padding: 12px !important;
            }

            .item-title-section h2 {
                font-size: 20px !important;
            }

            .item-subtitle {
                font-size: 13px !important;
            }

            .item-detail-body {
                padding: 16px !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                max-height: calc(95vh - 200px) !important;
            }

            .detail-section {
                margin-bottom: 16px !important;
            }

            .section-title {
                font-size: 16px !important;
            }

            .detail-line {
                flex-direction: column !important;
                gap: 4px !important;
            }

            .line-label {
                font-size: 12px !important;
            }

            .line-value {
                font-size: 14px !important;
            }

            .modal-header {
                margin-bottom: 15px !important;
            }

            .modal-title {
                font-size: 20px !important;
            }

            .modal-buttons {
                flex-direction: column !important;
            }

            .modal-btn {
                width: 100% !important;
            }

            .form-actions {
                flex-direction: column !important;
            }

            .btn-cancel,
            .btn-save {
                width: 100% !important;
            }

            /* Ensure sidebar has proper padding on mobile */
            .sidebar {
                padding: 20px 0 !important;
                padding-bottom: 80px !important;
            }
            
            /* Ensure sidebar content is properly styled on mobile */
            .sidebar .logo {
                padding: 0 20px !important;
                margin-bottom: 30px !important;
            }
            
            .sidebar .nav-menu {
                list-style: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .sidebar .nav-item {
                margin-bottom: 8px !important;
            }
            
            /* Nav link styling - match desktop layout */
            .sidebar .nav-link {
                display: flex !important;
                align-items: center !important;
                padding: 12px 20px !important;
                color: white !important;
                text-decoration: none !important;
                font-size: 14px !important;
                width: 100% !important;
                box-sizing: border-box !important;
                white-space: nowrap !important;
                overflow: visible !important;
            }
            
            /* Nav icon styling */
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
            }
            
            /* Nav label styling - ensure text is visible */
            .sidebar .nav-label {
                display: inline-block !important;
                visibility: visible !important;
                opacity: 1 !important;
                white-space: nowrap !important;
                flex: 1 !important;
            }

            /* Action Menu */
            .action-menu {
                min-width: 160px !important;
                font-size: 14px !important;
                z-index: 10000 !important;
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                pointer-events: auto !important;
            }

            .action-item {
                padding: 10px 14px !important;
                font-size: 13px !important;
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                pointer-events: auto !important;
            }

            .action-item-disabled {
                opacity: 0.5 !important;
                cursor: not-allowed !important;
                pointer-events: none !important;
                background-color: #f8f9fa !important;
                color: #6c757d !important;
            }

            .action-item-disabled:hover {
                background-color: #f8f9fa !important;
                color: #6c757d !important;
            }
        }

        @media (max-width: 480px) {
            .page-title {
                font-size: 20px !important;
            }

            .page-subtitle {
                font-size: 12px !important;
            }

            .chart-card {
                height: 200px !important;
                max-height: 200px !important;
                min-height: 200px !important;
            }

            .chart-container {
                height: 150px !important;
                max-height: 150px !important;
                min-height: 150px !important;
            }

            .table th,
            .table td {
                padding: 6px !important;
                font-size: 11px !important;
            }

            .modal {
                width: 98% !important;
                max-width: 98% !important;
                padding: 12px !important;
            }
        }
    </style>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>" data-user-is-viewer="<?= $isViewer ? 'true' : 'false' ?>" data-user-is-department-head="<?= $isDepartmentHead ? 'true' : 'false' ?>" data-username="<?= htmlspecialchars($username) ?>">
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
            <?php if (!$isViewer): ?>
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link" title="Dashboard">
                    <span class="nav-icon">
                        <img src="image/admin.png" alt="Dashboard">
                    </span>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a href="department.php" class="nav-link" title="<?= ($isViewer || $isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
                    <span class="nav-icon">
                        <img src="image/department.png" alt="<?= ($isViewer || $isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
                    </span>
                    <span class="nav-label"><?= ($isViewer || $isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?></span>
                </a>
            </li>
            <?php if ($isDepartmentHead): ?>
            <li class="nav-item">
                <a href="head_borrow_items.php" class="nav-link" title="Borrow Items">
                    <span class="nav-icon">
                        <img src="image/book.png" alt="Borrow Items">
                    </span>
                    <span class="nav-label">Borrow Items</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($isViewer): ?>
            <li class="nav-item">
                <a href="viewer_qr_scanner.php" class="nav-link" title="Scan QR">
                    <span class="nav-icon">
                        <img src="image/qr.png" alt="Scan QR">
                    </span>
                    <span class="nav-label">Scan Item QR</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="BorrowHistory.php" class="nav-link active" title="Borrow History">
                    <span class="nav-icon">
                        <img src="image/book.png" alt="Borrow History">
                    </span>
                    <span class="nav-label">Borrow History</span>
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (!$isViewer): ?>
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
                <a href="BorrowHistory.php" class="nav-link active" title="Borrow History">
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
                        <img src="image/qr.png" alt="QR Scanner">
                    </span>
                    <span class="nav-label">QR Code Scanner</span>
                </a>
            </li>
            <li class="nav-item">
        <a href="barcode_scanner.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/barcode-scan.png" alt="Barcode Scanner">
            </span>
            <span class="nav-label">Barcode Scanner</span>
        </a>
    </li>
            <?php endif; ?>
        <?php 
        // Admin role: is_admin = 1 AND role = 'admin'
        $is_admin_role = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
        $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
        $is_admin_or_super = $is_super_admin || $is_admin_role;
        ?>
        <?php if ($is_admin_or_super): ?>
        <li class="nav-item">
            <a href="item_requests.php" class="nav-link" title="Item Requests">
                <span class="nav-icon"><img src="image/application.png" alt="Requests"></span>
                <span class="nav-label">Item Requests</span>
            </a>
        </li>
        <?php endif; ?>
        <?php if ($is_admin_or_super): ?>
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
        $is_native_super_admin = $is_super_admin && !isset($_SESSION['super_admin_via_role']);
        if ($is_native_super_admin): 
        ?>
        <li class="nav-item">
                <a href="database_export.php" class="nav-link" title="Backup">
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
    </div>

    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>

    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        <div class="page-header">
            <h1 class="page-title"><?php echo $isViewer ? 'My Borrow History' : 'Borrow History'; ?></h1>
            <p class="page-subtitle"><?php echo $isViewer ? 'View your borrowing history and track your borrowed items' : 'Track and manage all borrowing activities and records'; ?></p>
        </div>

        <!-- Status Cards -->
        <div class="summary-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px;">
            <div class="stat-card" id="pendingCard" onclick="filterByStatusCard('pending')" style="cursor: pointer; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); transition: all 0.2s;">
                <div class="stat-number" id="pendingCount" style="font-size: 28px; font-weight: 700; color: #f59e0b; margin-bottom: 4px;">0</div>
                <div class="stat-label" style="color: #6b7280; font-size: 14px; font-weight: 600;">Pending</div>
            </div>
            <div class="stat-card" id="approvedCard" onclick="filterByStatusCard('approved')" style="cursor: pointer; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); transition: all 0.2s;">
                <div class="stat-number" id="approvedCount" style="font-size: 28px; font-weight: 700; color: #10b981; margin-bottom: 4px;">0</div>
                <div class="stat-label" style="color: #6b7280; font-size: 14px; font-weight: 600;">Approved</div>
            </div>
            <?php if (!$isViewer): ?>
            <div class="stat-card" id="receivedCard" onclick="filterByStatusCard('received')" style="cursor: pointer; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); transition: all 0.2s;">
                <div class="stat-number" id="receivedCount" style="font-size: 28px; font-weight: 700; color: #3b82f6; margin-bottom: 4px;">0</div>
                <div class="stat-label" style="color: #6b7280; font-size: 14px; font-weight: 600;">Received</div>
            </div>
            <?php endif; ?>
            <div class="stat-card" id="returnedCard" onclick="filterByStatusCard('returned')" style="cursor: pointer; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); transition: all 0.2s;">
                <div class="stat-number" id="returnedCount" style="font-size: 28px; font-weight: 700; color: #28a745; margin-bottom: 4px;">0</div>
                <div class="stat-label" style="color: #6b7280; font-size: 14px; font-weight: 600;">Returned</div>
            </div>
            <div class="stat-card" id="overdueCard" onclick="filterByStatusCard('overdue')" style="cursor: pointer; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); transition: all 0.2s;">
                <div class="stat-number" id="overdueCount" style="font-size: 28px; font-weight: 700; color: #ef4444; margin-bottom: 4px;">0</div>
                <div class="stat-label" style="color: #6b7280; font-size: 14px; font-weight: 600;">Overdue</div>
            </div>
            <div class="stat-card" id="borrowedItemsCard" onclick="filterByBorrowedItemsCard()" style="cursor: pointer; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); transition: all 0.2s;">
                <div class="stat-number" id="borrowedItemsCount" style="font-size: 28px; font-weight: 700; color: #8b5cf6; margin-bottom: 4px;">0</div>
                <div class="stat-label" style="color: #6b7280; font-size: 14px; font-weight: 600;">Borrowed Items</div>
            </div>
        </div>

        <div class="filters-section">
            <input type="text" class="search-input" id="searchInput" placeholder="<?php echo $isViewer ? 'Search by item name...' : 'Search by name, item, or borrower...'; ?>">
            <select class="filter-select" id="statusFilter">
                <option value="">All Status</option>
                <option value="pending" selected>Pending</option>
                <option value="approved">Approved</option>
                <option value="active">Active</option>
                <?php if (!$isViewer): ?>
                <option value="received">Received</option>
                <?php endif; ?>
                <option value="returned">Returned</option>
                <option value="overdue">Overdue</option>
            </select>
            <?php if (!$isViewer): ?>
            <select class="filter-select" id="departmentFilter">
                <?php if ($isSuperAdmin): ?>
                <option value="">All Departments</option>
                <?php endif; ?>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>" <?= (!$isSuperAdmin && $dept === $department) ? 'selected' : '' ?>>
                        <?php echo htmlspecialchars($dept); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="download-btn" onclick="downloadData()">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <table class="table" id="borrowTable">
                <thead>
                    <tr>
                        <th onclick="sortTable(0)">Borrow ID <span class="sort-icon" id="sort-0">↕</span></th>
                        <?php if (!$isViewer): ?>
                        <th onclick="sortTable(1)">Borrower Name <span class="sort-icon" id="sort-1">↕</span></th>
                        <?php endif; ?>
                        <th onclick="sortTable(<?php echo $isViewer ? '1' : '2'; ?>)">Item Name <span class="sort-icon" id="sort-<?php echo $isViewer ? '1' : '2'; ?>">↕</span></th>
                        <?php if (!$isViewer): ?>
                        <th>Item Code</th>
                        <th onclick="sortTable(4)">Department <span class="sort-icon" id="sort-4">↕</span></th>
                        <?php endif; ?>
                        <th>Item Placement</th>
                        <th onclick="sortTable(<?php echo $isViewer ? '3' : '6'; ?>)">Borrow Date <span class="sort-icon" id="sort-<?php echo $isViewer ? '3' : '6'; ?>">↕</span></th>
                        <th onclick="sortTable(<?php echo $isViewer ? '4' : '7'; ?>)">Due Date <span class="sort-icon" id="sort-<?php echo $isViewer ? '4' : '7'; ?>">↕</span></th>
                        <th onclick="sortTable(<?php echo $isViewer ? '5' : '8'; ?>)">Received Date <span class="sort-icon" id="sort-<?php echo $isViewer ? '5' : '8'; ?>">↕</span></th>
                        <th onclick="sortTable(<?php echo $isViewer ? '6' : '9'; ?>)">Returned Date <span class="sort-icon" id="sort-<?php echo $isViewer ? '6' : '9'; ?>">↕</span></th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                </tbody>
            </table>
        </div>

        <div class="pagination-container" id="paginationContainer">
            <div class="pagination-info">
                Showing <span id="startRecord">0</span> to <span id="endRecord">0</span> of <span id="totalRecords">0</span> entries
            </div>
            <div class="pagination-buttons">
                <button class="pagination-btn" onclick="changePage('prev')" id="prevBtn" disabled>Previous</button>
                <div class="page-numbers" id="pageNumbers"></div>
                <button class="pagination-btn" onclick="changePage('next')" id="nextBtn" disabled>Next</button>
            </div>
        </div>
    </div>

    <!-- QR Scanner Modal for Return Verification -->
    <div class="modal-overlay" id="qrReturnScannerModal" style="display: none; z-index: 10000;">
        <div class="modal" style="max-width: 600px; width: 95%;">
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981, #059669); color: white; display: flex; justify-content: space-between; align-items: center; padding: 20px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-qrcode" style="font-size: 24px;"></i>
                    <h3 style="margin: 0;">Scan QR Code to Return Item</h3>
                </div>
                <button class="close-btn" onclick="closeQRReturnScanner()" style="background: transparent; border: none; color: white; font-size: 28px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">×</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <p style="color: #666; margin: 0 0 10px 0;">Scan the QR code of the item being returned to verify and process the return.</p>
                    <p style="color: #10b981; font-weight: bold; margin: 0;" id="qrReturnItemInfo">Expected Item: <span id="qrReturnItemName">-</span></p>
                    <p style="color: #6b7280; font-size: 14px; margin: 5px 0 0 0;">The QR code must match the item above to complete the return.</p>
                </div>
                <div id="qrReturnScannerContainer" style="width: 100%; max-width: 500px; margin: 0 auto; position: relative;">
                    <div id="qrReturnReader" style="width: 100%;"></div>
                </div>
                <div id="qrReturnResult" style="margin-top: 20px; text-align: center; display: none;"></div>
                <div style="text-align: center; margin-top: 20px;">
                    <button class="modal-btn modal-btn-cancel" onclick="closeQRReturnScanner()" style="background: #6b7280; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Viewer QR Scanner Modal for Receiving Items -->
    <div class="modal-overlay" id="viewerQRScannerModal" style="display: none; z-index: 10000;">
        <div class="modal" style="max-width: 700px; width: 95%;">
            <div class="modal-header" style="background: linear-gradient(135deg, #2563eb, #1e40af); color: white; display: flex; justify-content: space-between; align-items: center; padding: 20px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-qrcode" style="font-size: 24px;"></i>
                    <h3 style="margin: 0;">Scan QR Code to Receive Item</h3>
                </div>
                <button class="close-btn" onclick="closeViewerQRScanner()" style="background: transparent; border: none; color: white; font-size: 28px; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">×</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <!-- Scanner Section -->
                <div id="viewerScannerSection">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <p style="color: #666; margin: 0 0 10px 0;">Scan the QR code of the item you borrowed to view details and receive it.</p>
                        <p style="color: #2563eb; font-weight: bold; margin: 0;" id="viewerExpectedItemInfo">Expected Item: <span id="viewerExpectedItemName">-</span> <span id="viewerExpectedItemCode" style="color: #6b7280; font-weight: normal;">(<span id="viewerExpectedItemCodeValue">-</span>)</span></p>
                    </div>
                    <div id="viewerQRScannerContainer" style="width: 100%; max-width: 500px; margin: 0 auto; position: relative;">
                        <div id="viewerQRReader" style="width: 100%;"></div>
                    </div>
                    <div id="viewerQRResult" style="margin-top: 20px; text-align: center; display: none;"></div>
                    <div style="text-align: center; margin-top: 20px;">
                        <button class="modal-btn modal-btn-cancel" onclick="closeViewerQRScanner()" style="background: #6b7280; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer;">Cancel</button>
                    </div>
                </div>
                
                <!-- Item Details Section (shown after successful scan) -->
                <div id="viewerItemDetailsSection" style="display: none;">
                    <div id="viewerItemDetailsContent"></div>
                    <div style="text-align: center; margin-top: 20px;">
                        <button class="modal-btn modal-btn-cancel" onclick="closeViewerQRScanner()" style="background: #6b7280; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-right: 10px;">Close</button>
                        <button class="modal-btn" id="viewerReceiveBtn" onclick="receiveItem()" style="background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold;">Receive</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sign Out Modal -->
    <div class="modal-overlay" id="signOutModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon">🚪</div>
                <h3 class="modal-title">Sign Out</h3>
                <p class="modal-message">Are you sure you want to sign out of your account?</p>
            </div>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-cancel" onclick="closeSignOutModal()">Cancel</button>
                <button class="modal-btn modal-btn-confirm" id="confirmSignOut" onclick="confirmSignOut()">Sign Out</button>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="item-detail-modal" id="detailsModal">
        <div class="item-detail-content">
            <div class="item-detail-header">
                <div class="item-header-info">
                    <div class="item-icon-large">📋</div>
                    <div class="item-title-section">
                        <h2 id="borrowDetailTitle">Borrow Record Details</h2>
                        <p class="item-subtitle" id="borrowDetailSubtitle">Complete borrow record information</p>
                    </div>
                </div>
                <button class="close-detail-btn" onclick="closeDetailsModal()">×</button>
            </div>
            
            <div class="item-detail-body">
                <div class="detail-grid">
                    <div class="detail-left-column">
                        <!-- Record Header -->
                        <div class="detail-section full-width" style="box-shadow:none;border:none;padding:0;background:transparent;">
                            <h2 class="item-title-display" id="borrowItemNameDisplay">-</h2>
                        </div>

                        <!-- Borrow Information Card -->
                        <div class="detail-section full-width">
                            <h3 class="section-title">📋 Borrow Information</h3>
                            <div class="item-fields">
                                <div class="detail-line">
                                    <span class="line-label">Borrow ID:</span> 
                                    <span class="line-value" id="detailBorrowId">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Borrower Name:</span> 
                                    <span class="line-value" id="detailBorrowerName">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Department:</span> 
                                    <span class="line-value" id="detailDepartment">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Quantity Borrowed:</span> 
                                    <span class="line-value" id="detailQuantity">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Borrow Date:</span> 
                                    <span class="line-value" id="detailBorrowDate">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Due Date:</span> 
                                    <span class="line-value" id="detailDueDate">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Received Date:</span> 
                                    <span class="line-value" id="detailReceivedDate">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Returned Date:</span> 
                                    <span class="line-value" id="detailReturnDate">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Status:</span> 
                                    <span class="line-value" id="detailStatus">-</span>
                                </div>
                            </div>
                        </div>

                        <!-- Item Information Card -->
                        <div class="detail-section full-width">
                            <h3 class="section-title">📦 Item Information</h3>
                            <div class="item-fields">
                                <div class="detail-line">
                                    <span class="line-label">Item Name:</span> 
                                    <span class="line-value" id="detailItemName">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Item Code:</span> 
                                    <span class="line-value" id="detailItemCode">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Category:</span> 
                                    <span class="line-value" id="detailCategory">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Location:</span> 
                                    <span class="line-value" id="detailLocation">-</span>
                                </div>
                                <div class="detail-line">
                                    <span class="line-label">Item Description:</span> 
                                    <span class="line-value" id="detailItemDescription">-</span>
                                </div>
                            </div>
                        </div>

                        <!-- Purpose & Notes Section -->
                        <div class="detail-section full-width">
                            <h3 class="section-title">📝 Purpose & Notes</h3>
                            <div class="description-card">
                                <div class="detail-line">
                                    <span class="line-label">Purpose:</span> 
                                    <span class="line-value" id="detailPurpose">-</span>
                                </div>
                                <div class="detail-line" style="margin-top: 12px;">
                                    <span class="line-label">Notes:</span> 
                                    <div class="detail-value" id="detailNotes" style="margin-top: 8px;">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal edit-modal">
            <div class="modal-header">
                <div class="modal-icon">✏️</div>
                <h3 class="modal-title">Edit Borrow Record</h3>
                <button class="close-btn" onclick="closeEditModal()">×</button>
            </div>
            <div class="edit-content" id="editContent">
                <form id="editForm">
                    <div class="form-group">
                        <label for="editBorrowerId">Borrow ID:</label>
                        <input type="text" id="editBorrowerId" name="borrow_id" readonly>
                    </div>
                    <div class="form-group">
                        <label for="editBorrowerName">Borrower Name:</label>
                        <input type="text" id="editBorrowerName" name="borrower_name" required>
                    </div>
                    <div class="form-group">
                        <label for="editDepartment">Department:</label>
                        <input type="text" id="editDepartment" name="department" required>
                    </div>
                    <div class="form-group">
                        <label for="editDueDate">Due Date:</label>
                        <input type="date" id="editDueDate" name="due_date" required>
                    </div>
                    <div class="form-group">
                        <label for="editPriority">Priority:</label>
                        <select id="editPriority" name="priority" required>
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn-save">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon" id="confirmIcon">⚠️</div>
                <h3 class="modal-title" id="confirmTitle">Confirm Action</h3>
                <p class="modal-message" id="confirmMessage">Are you sure you want to perform this action?</p>
            </div>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-cancel" onclick="closeConfirmModal()">Cancel</button>
                <button class="modal-btn modal-btn-confirm" id="confirmActionBtn">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        // Sidebar collapse/expand with mobile support
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
                
                if (isMobile()) {
                    // On mobile, don't apply collapsed state initially
                    sidebar.classList.remove('open');
                    if (overlay) overlay.classList.remove('show');
                    document.body.style.overflow = '';
                } else {
                    // On desktop, apply saved state
                    document.body.classList.toggle(BODY_CLASS, isCollapsed);
                }
            }

            function toggleSidebar() {
                if (isMobile()) {
                    // Mobile behavior: slide sidebar in/out with overlay
                    const isOpen = sidebar.classList.contains('open');
                    
                    if (isOpen) {
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                    } else {
                        sidebar.classList.add('open');
                        if (overlay) overlay.classList.add('show');
                        document.body.style.overflow = 'hidden';
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
                        sidebar.classList.remove('open');
                        overlay.classList.remove('show');
                        document.body.style.overflow = '';
                    }
                });
            }

            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (isMobile()) {
                        // On mobile, ensure sidebar is closed and reset desktop state
                        document.body.classList.remove(BODY_CLASS);
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                    } else {
                        // On desktop, close mobile sidebar and apply desktop state
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
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

// Global variables
let currentSortColumn = -1;
let currentSortOrder = 'asc';
let currentBorrowId = null;
let allRows = [];
let borrowRecords = []; // Filtered records for display
let allBorrowRecords = []; // All records for counting (never filtered)
let showBorrowedItemsOnly = false; // Track if "Borrowed Items" filter is active
let currentUsername = document.body.getAttribute('data-username') || ''; // Get current username

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    loadBorrowHistory();
    initializeEventListeners();
    // Highlight active card if status filter is set
    highlightActiveCard();
});

// Highlight active card based on current filter
function highlightActiveCard() {
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter && statusFilter.value) {
        const status = statusFilter.value;
        resetCardActiveState();
        const card = document.getElementById(status + 'Card');
        if (card) {
            card.classList.add('active');
            card.style.background = '#f3f4f6';
            card.style.border = '2px solid #3b82f6';
        }
    }
}

// Initialize event listeners
function initializeEventListeners() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const departmentFilter = document.getElementById('departmentFilter');
    
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(filterTable, 300); // Debounce search
        });
    }
    
    if (statusFilter) {
        statusFilter.addEventListener('change', function() {
            // Reset borrowed items filter when status filter changes
            showBorrowedItemsOnly = false;
            const borrowedItemsCard = document.getElementById('borrowedItemsCard');
            if (borrowedItemsCard) {
                borrowedItemsCard.classList.remove('active');
                borrowedItemsCard.style.background = 'white';
                borrowedItemsCard.style.border = 'none';
            }
            resetCardActiveState();
            filterTable();
        });
    }
    if (departmentFilter) {
        departmentFilter.addEventListener('change', function() {
            filterTable();
            updateStatusCards(); // Update cards when department filter changes
        });
    }
    
    // Handle Borrow History link click - reload if already on the page
    const borrowHistoryLink = document.querySelector('a[href="BorrowHistory.php"]');
    if (borrowHistoryLink) {
        borrowHistoryLink.addEventListener('click', function(e) {
            const currentPage = window.location.pathname;
            if (currentPage.includes('BorrowHistory.php')) {
                e.preventDefault();
                window.location.reload();
            }
        });
    }
}


// Load borrow history from database
async function loadBorrowHistory() {
    try {
        // Load all records in one request for faster loading
        // For department heads, we need to load ALL records (not just their department) to count borrowed items correctly
        // We'll add a special parameter to bypass department restrictions for counting
        const params = new URLSearchParams({
            action: 'get_borrow_history',
            load_all_for_counting: 'true' // Special flag to load all records for accurate counting
        });
        
        const response = await fetch('?' + params.toString());
        const data = await response.json();
        
        if (data.success) {
            allBorrowRecords = data.borrow_records; // Store all records
            
            // Filter pending records for initial display
            borrowRecords = data.borrow_records.filter(record => record.status === 'pending');
            
            populateTable(borrowRecords);
            updateStatusCards();
            updatePagination();
            
            // Highlight pending card by default
            const pendingCard = document.getElementById('pendingCard');
            if (pendingCard) {
                pendingCard.classList.add('active');
                pendingCard.style.background = '#f3f4f6';
                pendingCard.style.border = '2px solid #3b82f6';
            }
        } else {
            console.error('Failed to load borrow history:', data.message);
            showNotification('Failed to load borrow history', 'error');
        }
    } catch (error) {
        console.error('Error loading borrow history:', error);
        showNotification('Error loading data', 'error');
    }
}

// Populate table with database data
function populateTable(records) {
    const tableBody = document.getElementById('tableBody');
    tableBody.innerHTML = ''; // Clear existing data
    
    // Check if user is a viewer
    const isViewer = document.body.getAttribute('data-user-is-viewer') === 'true';
    const colspan = isViewer ? '9' : '12';
    
    if (records.length === 0) {
        tableBody.innerHTML = `<tr><td colspan="${colspan}" style="text-align: center; padding: 20px; color: #666;">No borrow records found</td></tr>`;
        return;
    }
    
    records.forEach(record => {
        const row = createTableRow(record);
        tableBody.appendChild(row);
    });
    
    allRows = Array.from(tableBody.querySelectorAll('tr'));
}

// Create table row from database record
function createTableRow(record) {
    const row = document.createElement('tr');
    
    // Check if user is a viewer
    const isViewer = document.body.getAttribute('data-user-is-viewer') === 'true';
    
    // Set data attributes for filtering
    // Normalize status to lowercase for filtering
    // If status is empty/null but received_date exists, treat as "received"
    let normalizedStatus = (record.status || '').toLowerCase().trim();
    if (!normalizedStatus && record.received_date) {
        normalizedStatus = 'received';
    }
    row.setAttribute('data-status', normalizedStatus || 'unknown');
    row.setAttribute('data-department', record.department_name);
    row.setAttribute('data-borrow-id', record.borrow_id);
    row.setAttribute('data-borrower-name', record.borrower_name || '');
    
    // Format dates with timestamps
    const borrowDate = record.borrow_date ? formatDateTime(record.borrow_date) : '-';
    const dueDate = record.due_date ? formatDateTime(record.due_date) : '-';
    // Use formatDateTime for received_date and return_date to show timestamp
    const receivedDate = record.received_date ? formatDateTime(record.received_date) : '-';
    const returnDate = record.return_date ? formatDateTime(record.return_date) : '-';
    
    // Create status badge - show "REJECTED" for declined status, "RECEIVED" for received status
    let statusText;
    let statusClass;
    const status = (record.status || '').toLowerCase().trim();
    
    // If status is empty/null but received_date exists, treat as "received"
    if (!status && record.received_date) {
        statusText = 'RECEIVED';
        statusClass = 'received';
    } else if (status === 'declined') {
        statusText = 'REJECTED';
        statusClass = 'declined';
    } else if (status === 'received') {
        statusText = 'RECEIVED';
        statusClass = 'received';
    } else if (status === 'returned') {
        statusText = 'Returned';
        statusClass = 'returned';
    } else if (status === 'approved') {
        statusText = 'Approved';
        statusClass = 'approved';
    } else if (status === 'active') {
        statusText = 'Active';
        statusClass = 'active';
    } else if (status === 'overdue') {
        statusText = 'Overdue';
        statusClass = 'overdue';
    } else if (status) {
        statusText = capitalizeFirst(status);
        statusClass = status;
    } else {
        statusText = 'Unknown';
        statusClass = 'unknown';
    }
    const statusBadge = `<span class="status-badge status-${statusClass}">${statusText}</span>`;
    
    // Build row HTML based on user type
    let rowHTML = `<td>${escapeHtml(record.borrow_id)}</td>`;
    
    // Only show Borrower Name and Department for non-viewers
    if (!isViewer) {
        rowHTML += `<td>${escapeHtml(record.borrower_name)}</td>`;
    }
    
    rowHTML += `<td>${escapeHtml(record.item_name || 'N/A')}</td>`;
    
    if (!isViewer) {
        rowHTML += `<td><span class="item-code-text" style="font-family: monospace; font-weight: bold; color: #2563eb;">${escapeHtml(record.item_code || 'N/A')}</span></td>`;
        rowHTML += `<td>${escapeHtml(record.department_name)}</td>`;
    }
    
    // Format Item Placement
    let itemPlacementDisplay = 'N/A';
    if (record.item_placement) {
        const placement = record.item_placement.trim();
        // Check if it's new format (comma-separated) or old format (dash-separated)
        if (placement.includes(',')) {
            // New format: "Building, Floor X, Room Name" or "Building, Floor X, Room Number"
            const parts = placement.split(',');
            if (parts.length >= 3) {
                const building = parts[0].trim();
                const floor = parts[1].trim(); // Already has "Floor X" format
                const room = parts[2].trim();
                itemPlacementDisplay = `${building}, ${floor}, ${room}`;
            } else {
                itemPlacementDisplay = placement;
            }
        } else if (placement.includes(' - ')) {
            // Old format: "Building X - Floor Y - Room Name" or "Building X - Floor Y - Room Number"
            const parts = placement.split(' - ');
            if (parts.length >= 3) {
                const building = parts[0].trim();
                let floor = parts[1].trim();
                // Keep "Floor" if present, otherwise add it
                if (!/^Floor\s+/i.test(floor)) {
                    floor = 'Floor ' + floor.replace(/^Floor\s+/i, '');
                }
                let room = parts[2].trim();
                // Check if room starts with "Room " and extract number, otherwise use as room name
                if (/^Room\s+(.+)$/i.test(room)) {
                    room = room.match(/^Room\s+(.+)$/i)[1]; // Use room number
                }
                // Format as "Building, Floor X, Room Name" or "Building, Floor X, Room Number"
                itemPlacementDisplay = `${building}, ${floor}, ${room}`;
            } else {
                itemPlacementDisplay = placement;
            }
        } else {
            itemPlacementDisplay = placement;
        }
    }
    
    rowHTML += `<td>${escapeHtml(itemPlacementDisplay)}</td>`;
    
    rowHTML += `
        <td>${borrowDate}</td>
        <td>${dueDate}</td>
        <td>${receivedDate}</td>
        <td>${returnDate}</td>
        <td>${statusBadge}</td>
    `;
    
    rowHTML += `<td><button class="action-btn" onclick="showActionMenu(this, '${record.borrow_id}')">⋮</button></td>`;
    
    row.innerHTML = rowHTML;
    
    return row;
}

// Format date for display
function formatDate(dateString) {
    if (!dateString) return '-';
    
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
}

// Format date with time for display (used for return_date with timestamp)
function formatDateTime(dateString) {
    if (!dateString) return '-';
    
    // Parse MySQL DATETIME format (YYYY-MM-DD HH:MM:SS) as local time
    // Replace space with T to create ISO-like format, but don't add Z (to avoid UTC conversion)
    let date;
    if (dateString.match(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)) {
        // MySQL DATETIME format - treat as local time (Asia/Manila)
        const isoString = dateString.replace(' ', 'T');
        date = new Date(isoString);
    } else if (dateString.includes('T')) {
        // ISO format
        date = new Date(dateString);
    } else {
        date = new Date(dateString);
    }
    
    // Check if date is valid
    if (isNaN(date.getTime())) return dateString;
    
    // Format date in Asia/Manila timezone to match server timezone
    const dateStr = date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        timeZone: 'Asia/Manila'
    });
    
    // Format time in Asia/Manila timezone to match server timezone
    // Format: "January 15, 2024 at 02:30:45 PM"
    const timeStr = date.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
        timeZone: 'Asia/Manila'
    });
    
    return `${dateStr} at ${timeStr}`;
}

// Capitalize first letter
function capitalizeFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
}

// Filter functionality with database integration
async function filterTable() {
    const searchTerm = document.getElementById('searchInput').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const departmentFilterElement = document.getElementById('departmentFilter');
    const departmentFilter = departmentFilterElement ? departmentFilterElement.value : '';
    
    try {
        // Build query parameters
        const params = new URLSearchParams({
            action: 'get_borrow_history'
        });
        
        if (searchTerm) params.append('search', searchTerm);
        
        // Don't add status filter if "Borrowed Items" is active (we filter by status in borrowed_items_only)
        if (statusFilter && !showBorrowedItemsOnly) {
            params.append('status', statusFilter);
        }
        
        // Don't add department filter if "Borrowed Items" is active (we want items from all departments)
        if (departmentFilter && !showBorrowedItemsOnly) {
            params.append('department', departmentFilter);
        }
        
        // Add borrower name filter if "Borrowed Items" card is active
        // Filter by borrower name AND status (approved, received, active, overdue)
        if (showBorrowedItemsOnly && currentUsername) {
            params.append('borrower_name', currentUsername);
            params.append('borrowed_items_only', 'true'); // Special flag for borrowed items filter
        }
        
        const response = await fetch('?' + params.toString());
        const data = await response.json();
        
        if (data.success) {
            borrowRecords = data.borrow_records; // Filtered records for display
            // Don't update allBorrowRecords here - keep original counts
            populateTable(borrowRecords);
            updateStatusCards(); // This will use allBorrowRecords for counts
            updatePagination();
        }
    } catch (error) {
        console.error('Error filtering data:', error);
        // Fallback to client-side filtering
        clientSideFilter();
    }
}

// Client-side filtering fallback
function clientSideFilter() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;
    const departmentFilterElement = document.getElementById('departmentFilter');
    const departmentFilter = departmentFilterElement ? departmentFilterElement.value : '';

    const rows = document.querySelectorAll('#tableBody tr');
    
    rows.forEach(row => {
        if (row.cells.length === 1) return; // Skip "no records" row
        
        const text = row.textContent.toLowerCase();
        const status = row.getAttribute('data-status');
        const department = row.getAttribute('data-department');
        const borrowerName = row.getAttribute('data-borrower-name') || '';

        const matchesSearch = !searchTerm || text.includes(searchTerm);
        const matchesStatus = !statusFilter || status === statusFilter;
        const matchesDepartment = !departmentFilter || department === departmentFilter;
        
        // For borrowed items filter: must match borrower name AND be in borrowed statuses
        const borrowedStatuses = ['approved', 'received', 'active', 'overdue'];
        const matchesBorrower = !showBorrowedItemsOnly || 
            (borrowerName.toLowerCase() === currentUsername.toLowerCase() && borrowedStatuses.includes(status));

        row.style.display = (matchesSearch && matchesStatus && matchesDepartment && matchesBorrower) ? '' : 'none';
    });

    updateStatusCards();
    updatePagination();
}

// Chart bar hover effects and interactions
function initializeChartInteractions() {
    document.querySelectorAll('.chart-bar').forEach((bar, index) => {
        bar.addEventListener('mouseenter', function() {
            this.style.opacity = '0.8';
            this.style.transform = 'scaleY(1.1)';
            this.style.transition = 'all 0.3s ease';
        });
        
        bar.addEventListener('mouseleave', function() {
            this.style.opacity = '1';
            this.style.transform = 'scaleY(1)';
        });
    });
}

// Sort table functionality
function sortTable(columnIndex) {
    const table = document.getElementById('borrowTable');
    const tbody = table.getElementsByTagName('tbody')[0];
    const rows = Array.from(tbody.getElementsByTagName('tr')).filter(row => row.style.display !== 'none' && row.cells.length > 1);

    // Update sort order
    if (currentSortColumn === columnIndex) {
        currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
    } else {
        currentSortColumn = columnIndex;
        currentSortOrder = 'asc';
    }

    // Sort rows
    rows.sort((a, b) => {
        const aValue = a.getElementsByTagName('td')[columnIndex].textContent.trim();
        const bValue = b.getElementsByTagName('td')[columnIndex].textContent.trim();

        // Handle dates - check if user is viewer to determine date column indices
        const isViewer = document.body.getAttribute('data-user-is-viewer') === 'true';
        const dateColumnStart = isViewer ? 3 : 6; // Borrow Date column index
        const dateColumnEnd = isViewer ? 6 : 9; // Returned Date column index
        if (columnIndex >= dateColumnStart && columnIndex <= dateColumnEnd) {
            const aDate = aValue === '-' ? new Date(0) : new Date(aValue);
            const bDate = bValue === '-' ? new Date(0) : new Date(bValue);
            return currentSortOrder === 'asc' ? aDate - bDate : bDate - aDate;
        }

        // Handle numbers in ID
        if (columnIndex === 0) {
            const aNum = parseInt(aValue.split('-')[1]);
            const bNum = parseInt(bValue.split('-')[1]);
            return currentSortOrder === 'asc' ? aNum - bNum : bNum - aNum;
        }

        // Handle text
        return currentSortOrder === 'asc' 
            ? aValue.localeCompare(bValue) 
            : bValue.localeCompare(aValue);
    });

    // Update sort icons
    document.querySelectorAll('.sort-icon').forEach((icon, index) => {
        if (index === columnIndex) {
            icon.textContent = currentSortOrder === 'asc' ? '↑' : '↓';
        } else {
            icon.textContent = '↕';
        }
    });

    // Re-append sorted rows
    rows.forEach(row => tbody.appendChild(row));
}

// Action menu functionality
function showActionMenu(button, borrowId) {
    // Store current borrow ID
    currentBorrowId = borrowId;
    // Find the record by borrowId - check both borrowRecords and allBorrowRecords
    let record = borrowRecords.find(r => r.borrow_id === borrowId);
    if (!record) {
        // If not found in filtered records, check all records (for borrowed items filter)
        record = allBorrowRecords.find(r => r.borrow_id === borrowId);
    }
    
    // Check if user is a viewer (borrower)
    const isViewer = document.body.getAttribute('data-user-is-viewer') === 'true';

    // Create and show action menu
    const existingMenu = document.querySelector('.action-menu');
    if (existingMenu) existingMenu.remove();

    const menu = document.createElement('div');
    menu.className = 'action-menu';
    let html = `
        <div class="action-item" onclick="viewDetails('${borrowId}')">
            <i class="fa-solid fa-eye"></i> View Details
        </div>
    `;
    
    // For viewers, show "View Details" and "Scan QR" (if status is approved/active/overdue)
    if (isViewer) {
        // Viewers can view details and scan QR for approved/active/overdue items
        if (record && (record.status === 'approved' || record.status === 'active' || record.status === 'overdue')) {
            html += `
            <div class="action-item" onclick="openViewerQRScanner('${borrowId}')" style="color: #2563eb;">
                <i class="fa-solid fa-qrcode"></i> Scan QR
            </div>`;
        }
        menu.innerHTML = html;
    } else {
        // For non-viewers (admins), show all actions
        // Check if status is declined/rejected - only show View Details
        if (record && (record.status === 'declined' || record.status === 'rejected')) {
            // Rejected/declined records can only view details, no other actions
            menu.innerHTML = html;
        } else if (record && record.status === 'pending') {
            // Check if this is a pending request - show approve/reject options
            html += `
            <div class="action-item" onclick="approveBorrowRequest('${borrowId}')" style="color: #10b981;">
                <i class="fa-solid fa-check-circle"></i> Approve Request
            </div>
            <div class="action-item" onclick="rejectBorrowRequest('${borrowId}')" style="color: #ef4444;">
                <i class="fa-solid fa-times-circle"></i> Reject Request
            </div>`;
            menu.innerHTML = html;
        } else if (record && ((record.status || '').toLowerCase() === 'returned' || (record.status || '').toLowerCase() === 'received')) {
            // Disabled Mark as Returned (for returned or received items)
            html += `
            <div class="action-item action-item-disabled" style="opacity: 0.5; cursor: not-allowed; pointer-events: none;" title="Already returned/received">
                <i class="fa-solid fa-check"></i> Mark as Returned
            </div>`;
            // Disabled Send Reminder
            html += `
            <div class="action-item action-item-disabled" style="opacity: 0.5; cursor: not-allowed; pointer-events: none;" title="Already returned">
                <i class="fa-solid fa-envelope"></i> Send Reminder
            </div>`;
            menu.innerHTML = html;
        } else {
            // Check if current user is the borrower (items they borrowed from other departments)
            const isCurrentUserBorrower = record && currentUsername && record.borrower_name && 
                record.borrower_name.toLowerCase().trim() === currentUsername.toLowerCase().trim();
            
            if (isCurrentUserBorrower) {
                // For borrowed items (user is the borrower), show "Scan to receive" if status is approved
                // (not yet received)
                if (record && record.status === 'approved') {
                    html += `
                    <div class="action-item" onclick="openViewerQRScanner('${borrowId}')" style="color: #2563eb;">
                        <i class="fa-solid fa-qrcode"></i> Scan to Receive
                    </div>`;
                }
            } else {
                // Only show "Mark as Returned" and "Send Reminder" if user is NOT the borrower
                // (these actions are for items from their department that others borrowed)
                html += `
                <div class="action-item" onclick="markAsReturned('${borrowId}')">
                    <i class="fa-solid fa-check"></i> Mark as Returned
                </div>
                <div class="action-item" onclick="sendReminder('${borrowId}')">
                    <i class="fa-solid fa-envelope"></i> Send Reminder
                </div>`;
            }
            menu.innerHTML = html;
        }
    }

    // Set base styles first
    menu.style.position = 'fixed';
    menu.style.zIndex = '10000';
    menu.style.background = 'white';
    menu.style.border = '1px solid #ddd';
    menu.style.borderRadius = '8px';
    menu.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
    menu.style.minWidth = '180px';
    menu.style.overflow = 'hidden';
    menu.style.opacity = '0';
    menu.style.pointerEvents = 'none';

    // Append to body to get dimensions
    document.body.appendChild(menu);

    // Get button and menu dimensions
    const rect = button.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const viewportHeight = window.innerHeight;
    const viewportWidth = window.innerWidth;

    // Calculate available space below and above
    const spaceBelow = viewportHeight - rect.bottom;
    const spaceAbove = rect.top;

    // Determine if menu should open upward or downward
    let topPosition;
    if (spaceBelow >= menuRect.height || spaceBelow >= spaceAbove) {
        // Open downward
        topPosition = rect.bottom + 5;
    } else {
        // Open upward
        topPosition = rect.top - menuRect.height - 5;
    }

    // Ensure menu doesn't go below viewport
    if (topPosition + menuRect.height > viewportHeight) {
        topPosition = viewportHeight - menuRect.height - 10;
    }

    // Ensure menu doesn't go above viewport
    if (topPosition < 10) {
        topPosition = 10;
        // If constrained, make menu scrollable
        menu.style.maxHeight = (viewportHeight - 20) + 'px';
        menu.style.overflowY = 'auto';
    }

    // Calculate horizontal position
    let leftPosition = rect.left - 120;
    
    // Ensure menu doesn't overflow horizontally
    if (leftPosition + menuRect.width > viewportWidth) {
        leftPosition = viewportWidth - menuRect.width - 10;
    }
    if (leftPosition < 10) {
        leftPosition = 10;
    }

    // Apply calculated positions
    menu.style.top = topPosition + 'px';
    menu.style.left = leftPosition + 'px';
    menu.style.opacity = '1';
    menu.style.visibility = 'visible';
    menu.style.pointerEvents = 'auto';
    menu.style.display = 'block';

    // Close menu on outside click
    setTimeout(() => {
        document.addEventListener('click', function closeMenu(e) {
            if (!menu.contains(e.target) && e.target !== button) {
                menu.remove();
                document.removeEventListener('click', closeMenu);
            }
        });
    }, 100);
}

// View details function - enhanced with database fetch
async function viewDetails(borrowId) {
    try {
        const response = await fetch(`?action=get_borrow_details&borrow_id=${encodeURIComponent(borrowId)}`);
        const data = await response.json();
        
        if (data.success) {
            const record = data.record;
            const modal = document.getElementById('detailsModal');
            
            // Populate header
            document.getElementById('borrowDetailTitle').textContent = 'Borrow Record Details';
            document.getElementById('borrowDetailSubtitle').textContent = `Borrow ID: ${escapeHtml(record.borrow_id)}`;
            document.getElementById('borrowItemNameDisplay').textContent = escapeHtml(record.item_name || 'N/A');
            
            // Populate borrow information
            document.getElementById('detailBorrowId').textContent = escapeHtml(record.borrow_id);
            document.getElementById('detailBorrowerName').textContent = escapeHtml(record.borrower_name);
            document.getElementById('detailDepartment').textContent = escapeHtml(record.department_name);
            document.getElementById('detailQuantity').textContent = record.quantity_borrowed;
            document.getElementById('detailBorrowDate').textContent = record.borrow_date ? formatDateTime(record.borrow_date) : '-';
            document.getElementById('detailDueDate').textContent = record.due_date ? formatDateTime(record.due_date) : '-';
            document.getElementById('detailReceivedDate').textContent = record.received_date ? formatDateTime(record.received_date) : 'Not received yet';
            document.getElementById('detailReturnDate').textContent = record.return_date ? formatDateTime(record.return_date) : 'Not returned yet';
            // Handle status display - show "REJECTED" for declined, "RECEIVED" for received
            let detailStatusText;
            let detailStatusClass;
            const detailStatus = (record.status || '').toLowerCase().trim();
            
            // If status is empty/null but received_date exists, treat as "received"
            if (!detailStatus && record.received_date) {
                detailStatusText = 'RECEIVED';
                detailStatusClass = 'received';
            } else if (detailStatus === 'declined') {
                detailStatusText = 'REJECTED';
                detailStatusClass = 'declined';
            } else if (detailStatus === 'received') {
                detailStatusText = 'RECEIVED';
                detailStatusClass = 'received';
            } else if (detailStatus === 'returned') {
                detailStatusText = 'Returned';
                detailStatusClass = 'returned';
            } else if (detailStatus === 'approved') {
                detailStatusText = 'Approved';
                detailStatusClass = 'approved';
            } else if (detailStatus === 'active') {
                detailStatusText = 'Active';
                detailStatusClass = 'active';
            } else if (detailStatus === 'overdue') {
                detailStatusText = 'Overdue';
                detailStatusClass = 'overdue';
            } else if (detailStatus) {
                detailStatusText = capitalizeFirst(detailStatus);
                detailStatusClass = detailStatus;
            } else {
                detailStatusText = 'Unknown';
                detailStatusClass = 'unknown';
            }
            document.getElementById('detailStatus').innerHTML = `<span class="status-badge status-${detailStatusClass}">${detailStatusText}</span>`;
            
            // Populate item information
            document.getElementById('detailItemName').textContent = escapeHtml(record.item_name || 'N/A');
            document.getElementById('detailItemCode').textContent = escapeHtml(record.item_code || 'N/A');
            document.getElementById('detailCategory').textContent = escapeHtml(record.category || 'N/A');
            // Use formatted_location (Building, Floor, Room Name/Number) instead of location
            document.getElementById('detailLocation').textContent = escapeHtml(record.formatted_location || record.location || 'N/A');
            document.getElementById('detailItemDescription').textContent = escapeHtml(record.item_description || 'N/A');
            
            // Populate purpose & notes
            document.getElementById('detailPurpose').textContent = escapeHtml(record.purpose || 'N/A');
            document.getElementById('detailNotes').textContent = escapeHtml(record.notes || 'N/A');
            
            // Show modal - ensure it displays properly
            if (modal) {
                modal.style.display = 'flex';
                modal.style.alignItems = 'flex-start';
                modal.style.justifyContent = 'center';
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
                
                // Scroll to top of modal
                setTimeout(() => {
                    modal.scrollTop = 0;
                }, 10);
            }
        } else {
            showNotification(data.message || 'Failed to load record details', 'error');
        }
    } catch (error) {
        console.error('Error loading record details:', error);
        showNotification('Error loading record details', 'error');
    }
    
    // Close action menu
    const actionMenu = document.querySelector('.action-menu');
    if (actionMenu) actionMenu.remove();
}

// Edit record function
function editRecord(borrowId) {
    // Editing disabled globally
    showNotification('Editing is disabled for borrow records', 'warning');
    // Close action menu if open
    const actionMenu = document.querySelector('.action-menu');
    if (actionMenu) actionMenu.remove();
    return;
}

// QR Scanner variables for return verification
let qrReturnScanner = null;
let currentReturnBorrowId = null;
let currentReturnItemId = null;

// Viewer QR Scanner variables
let viewerQRScanner = null;
let currentViewerBorrowId = null;
let currentViewerItemId = null;
let currentViewerBorrowRecord = null;

// Enhanced mark as returned with QR code verification
async function markAsReturned(borrowId) {
    // Find record to check if it's already returned or received
    const record = borrowRecords.find(r => r.borrow_id === borrowId);
    const status = (record?.status || '').toLowerCase();
    if (record && (status === 'returned' || status === 'received')) {
        showNotification('This item has already been returned/received', 'info');
        const actionMenu = document.querySelector('.action-menu');
        if (actionMenu) actionMenu.remove();
        return;
    }
    
    // Get borrow record details to get item_id
    try {
        const response = await fetch(`?action=get_borrow_details&borrow_id=${encodeURIComponent(borrowId)}`);
        const data = await response.json();
        
        if (!data.success || !data.record) {
            showNotification('Failed to get borrow record details', 'error');
            const actionMenu = document.querySelector('.action-menu');
            if (actionMenu) actionMenu.remove();
            return;
        }
        
        // Store borrow ID and item ID for validation
        currentReturnBorrowId = borrowId;
        currentReturnItemId = data.record.item_id;
        
        // Open QR scanner modal
        openQRReturnScanner(data.record.item_name || 'Unknown Item');
        
    } catch (error) {
        console.error('Error fetching borrow details:', error);
        showNotification('Error fetching borrow record details', 'error');
        const actionMenu = document.querySelector('.action-menu');
        if (actionMenu) actionMenu.remove();
    }
}

// Open QR scanner modal for return verification
function openQRReturnScanner(itemName) {
    console.log('Opening QR scanner modal for item:', itemName);
    const modal = document.getElementById('qrReturnScannerModal');
    if (!modal) {
        console.error('QR scanner modal element not found');
        showNotification('QR scanner modal not found', 'error');
        return;
    }
    
    // Update item name display
    const itemNameElement = document.getElementById('qrReturnItemName');
    if (itemNameElement) {
        itemNameElement.textContent = itemName;
    }
    
    // Show modal - add show class and set display
    modal.classList.add('show');
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    modal.style.zIndex = '10000';
    modal.style.opacity = '1';
    document.body.style.overflow = 'hidden';
    
    console.log('Modal display set, starting scanner...');
    
    // Clear previous result
    const resultDiv = document.getElementById('qrReturnResult');
    if (resultDiv) {
        resultDiv.style.display = 'none';
        resultDiv.innerHTML = '';
    }
    
    // Small delay to ensure modal is visible before starting scanner
    setTimeout(() => {
        console.log('Starting QR scanner...');
        // Start QR scanner
        startQRReturnScanner();
    }, 100);
}

// Start QR scanner
function startQRReturnScanner() {
    const scannerContainer = document.getElementById('qrReturnReader');
    if (!scannerContainer) {
        showNotification('QR scanner container not found', 'error');
        return;
    }
    
    // Clear any existing scanner and container content
    if (qrReturnScanner) {
        qrReturnScanner.stop().then(() => {
            qrReturnScanner.clear();
            qrReturnScanner = null;
        }).catch(err => {
            console.error('Error stopping previous scanner:', err);
            qrReturnScanner = null;
        });
    }
    
    // Clear container
    scannerContainer.innerHTML = '';
    
    // Check if Html5Qrcode is available
    if (typeof Html5Qrcode === 'undefined') {
        const resultDiv = document.getElementById('qrReturnResult');
        if (resultDiv) {
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Library Error</strong><br>QR scanner library not loaded. Please refresh the page.</div>';
        }
        return;
    }
    
    // Initialize scanner
    qrReturnScanner = new Html5Qrcode("qrReturnReader");
    
    qrReturnScanner.start(
        { facingMode: "environment" },
        {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0
        },
        (decodedText, decodedResult) => {
            // QR code scanned successfully
            handleQRReturnScan(decodedText);
        },
        (errorMessage) => {
            // Ignore scanning errors (they're frequent during scanning)
        }
    ).catch(err => {
        console.error('Error starting QR scanner:', err);
        const resultDiv = document.getElementById('qrReturnResult');
        if (resultDiv) {
            resultDiv.style.display = 'block';
            let errorMsg = 'Failed to start camera. ';
            if (err.message && err.message.includes('Permission')) {
                errorMsg += 'Please allow camera access and refresh the page.';
            } else if (err.message && err.message.includes('NotFoundError')) {
                errorMsg += 'No camera found. Please connect a camera device.';
            } else {
                errorMsg += 'Please check camera permissions and try again.';
            }
            resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Camera Error</strong><br>' + errorMsg + '</div>';
        } else {
            showNotification('Failed to start camera. Please check permissions.', 'error');
        }
    });
}

// Handle QR code scan for return verification
function handleQRReturnScan(decodedText) {
    console.log('QR Code scanned for return:', decodedText);
    
    // Stop scanner immediately
    if (qrReturnScanner) {
        qrReturnScanner.stop().then(() => {
            qrReturnScanner.clear();
            qrReturnScanner = null;
        }).catch(err => {
            console.error('Error stopping scanner:', err);
        });
    }
    
    // Extract item ID from QR code
    let scannedItemId = null;
    
    // Check if it's a URL
    if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
        if (decodedText.includes('view_item.php?id=')) {
            const urlMatch = decodedText.match(/view_item\.php[?&]id=(\d+)/);
            if (urlMatch) {
                scannedItemId = parseInt(urlMatch[1]);
            }
        }
    } else {
        // Try to parse as JSON (legacy format)
        try {
            const data = JSON.parse(decodedText);
            if (data.id) {
                scannedItemId = parseInt(data.id);
            }
        } catch (e) {
            // Plain text QR code - check if it's just a number (item ID)
            if (/^\d+$/.test(decodedText.trim())) {
                scannedItemId = parseInt(decodedText.trim());
            }
        }
    }
    
    // Validate scanned item ID
    if (!scannedItemId) {
        const resultDiv = document.getElementById('qrReturnResult');
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Invalid QR Code</strong><br>Could not identify the item from this QR code. Please scan the QR code of the item being returned.</div>';
        
        // Restart scanner after 2 seconds
        setTimeout(() => {
            resultDiv.style.display = 'none';
            startQRReturnScanner();
        }, 2000);
        return;
    }
    
    // Check if scanned item ID matches the borrow record's item ID
    if (scannedItemId !== currentReturnItemId) {
        const resultDiv = document.getElementById('qrReturnResult');
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Verification Failed</strong><br>The scanned QR code does not match the item being returned. Please scan the correct item\'s QR code to complete the return.</div>';
        
        // Restart scanner after 2 seconds
        setTimeout(() => {
            resultDiv.style.display = 'none';
            startQRReturnScanner();
        }, 2000);
        return;
    }
    
    // Item ID matches - proceed to mark as returned
    const resultDiv = document.getElementById('qrReturnResult');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '<div style="color: #10b981; padding: 15px; background: #d1fae5; border-radius: 8px; border: 1px solid #10b981;"><strong>✅ QR Code Verified</strong><br>Item QR code matches! Processing return...</div>';
    
    // Mark as returned
    markAsReturnedConfirmed(currentReturnBorrowId);
}

// Close QR scanner modal
function closeQRReturnScanner() {
    // Stop scanner
    if (qrReturnScanner) {
        qrReturnScanner.stop().then(() => {
            qrReturnScanner.clear();
            qrReturnScanner = null;
        }).catch(err => {
            console.error('Error stopping scanner:', err);
            qrReturnScanner = null;
        });
    }
    
    // Hide modal - remove show class and hide
    const modal = document.getElementById('qrReturnScannerModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    // Clear variables
    currentReturnBorrowId = null;
    currentReturnItemId = null;
    
    // Clear result
    const resultDiv = document.getElementById('qrReturnResult');
    if (resultDiv) {
        resultDiv.style.display = 'none';
        resultDiv.innerHTML = '';
    }
    
    // Clear scanner container
    const scannerContainer = document.getElementById('qrReturnReader');
    if (scannerContainer) {
        scannerContainer.innerHTML = '';
    }
    
    // Close action menu
    const actionMenu = document.querySelector('.action-menu');
    if (actionMenu) actionMenu.remove();
}

// Mark as returned after QR verification
async function markAsReturnedConfirmed(borrowId) {
    try {
        const formData = new FormData();
        formData.append('action', 'update_borrow');
        formData.append('borrow_id', borrowId);
        formData.append('update_type', 'mark_returned');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Close QR scanner modal
            closeQRReturnScanner();
            
            // Show success notification
            showNotification('Item verified via QR code and returned successfully!', 'success');
            
            // Reload data
            loadBorrowHistory();
        } else {
            const resultDiv = document.getElementById('qrReturnResult');
            resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Error</strong><br>' + (data.message || 'Failed to process return. Please try again.') + '</div>';
            
            // Restart scanner after 3 seconds
            setTimeout(() => {
                resultDiv.style.display = 'none';
                startQRReturnScanner();
            }, 3000);
        }
    } catch (error) {
        console.error('Error updating record:', error);
        const resultDiv = document.getElementById('qrReturnResult');
        resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Processing Error</strong><br>An error occurred while processing the return. Please try scanning again.</div>';
        
        // Restart scanner after 3 seconds
        setTimeout(() => {
            resultDiv.style.display = 'none';
            startQRReturnScanner();
        }, 3000);
    }
}

// Viewer QR Scanner Functions
async function openViewerQRScanner(borrowId) {
    // Close action menu
    const actionMenu = document.querySelector('.action-menu');
    if (actionMenu) actionMenu.remove();
    
    // Find the borrow record
    const record = borrowRecords.find(r => r.borrow_id === borrowId);
    if (!record) {
        showNotification('Borrow record not found', 'error');
        return;
    }
    
    // Store borrow ID and record
    currentViewerBorrowId = borrowId;
    currentViewerBorrowRecord = record;
    
    // Get borrow record details to get item_id
    try {
        const response = await fetch(`?action=get_borrow_details&borrow_id=${encodeURIComponent(borrowId)}`);
        const data = await response.json();
        
        if (!data.success || !data.record) {
            showNotification('Failed to get borrow record details', 'error');
            return;
        }
        
        currentViewerItemId = data.record.item_id;
        
        // Open modal
        const modal = document.getElementById('viewerQRScannerModal');
        if (!modal) {
            showNotification('QR scanner modal not found', 'error');
            return;
        }
        
        // Update expected item name and code
        const itemNameElement = document.getElementById('viewerExpectedItemName');
        if (itemNameElement) {
            itemNameElement.textContent = record.item_name || 'Unknown Item';
        }
        
        // Update expected item code
        const itemCodeElement = document.getElementById('viewerExpectedItemCodeValue');
        if (itemCodeElement) {
            // Get item code from the detailed record if available
            const itemCode = data.record.item_code || record.item_code || 'N/A';
            itemCodeElement.textContent = itemCode;
        }
        
        // Show scanner section, hide details section
        document.getElementById('viewerScannerSection').style.display = 'block';
        document.getElementById('viewerItemDetailsSection').style.display = 'none';
        
        // Show modal
        modal.classList.add('show');
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        modal.style.zIndex = '10000';
        modal.style.opacity = '1';
        document.body.style.overflow = 'hidden';
        
        // Clear previous result
        const resultDiv = document.getElementById('viewerQRResult');
        if (resultDiv) {
            resultDiv.style.display = 'none';
            resultDiv.innerHTML = '';
        }
        
        // Start scanner
        setTimeout(() => {
            startViewerQRScanner();
        }, 100);
        
    } catch (error) {
        console.error('Error fetching borrow details:', error);
        showNotification('Error fetching borrow record details', 'error');
    }
}

// Start viewer QR scanner
function startViewerQRScanner() {
    const scannerContainer = document.getElementById('viewerQRReader');
    if (!scannerContainer) {
        showNotification('QR scanner container not found', 'error');
        return;
    }
    
    // Clear any existing scanner
    if (viewerQRScanner) {
        viewerQRScanner.stop().then(() => {
            viewerQRScanner.clear();
            viewerQRScanner = null;
        }).catch(err => {
            console.error('Error stopping previous scanner:', err);
            viewerQRScanner = null;
        });
    }
    
    // Clear container
    scannerContainer.innerHTML = '';
    
    // Check if Html5Qrcode is available
    if (typeof Html5Qrcode === 'undefined') {
        const resultDiv = document.getElementById('viewerQRResult');
        if (resultDiv) {
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Library Error</strong><br>QR scanner library not loaded. Please refresh the page.</div>';
        }
        return;
    }
    
    // Initialize scanner
    viewerQRScanner = new Html5Qrcode("viewerQRReader");
    
    viewerQRScanner.start(
        { facingMode: "environment" },
        {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0
        },
        (decodedText, decodedResult) => {
            // QR code scanned successfully
            handleViewerQRScan(decodedText);
        },
        (errorMessage) => {
            // Ignore scanning errors
        }
    ).catch(err => {
        console.error('Error starting QR scanner:', err);
        const resultDiv = document.getElementById('viewerQRResult');
        if (resultDiv) {
            resultDiv.style.display = 'block';
            let errorMsg = 'Failed to start camera. ';
            if (err.message && err.message.includes('Permission')) {
                errorMsg += 'Please allow camera access and refresh the page.';
            } else if (err.message && err.message.includes('NotFoundError')) {
                errorMsg += 'No camera found. Please connect a camera device.';
            } else {
                errorMsg += 'Please check camera permissions and try again.';
            }
            resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Camera Error</strong><br>' + errorMsg + '</div>';
        } else {
            showNotification('Failed to start camera. Please check permissions.', 'error');
        }
    });
}

// Handle viewer QR code scan
async function handleViewerQRScan(decodedText) {
    console.log('QR Code scanned for viewer:', decodedText);
    
    // Stop scanner immediately
    if (viewerQRScanner) {
        viewerQRScanner.stop().then(() => {
            viewerQRScanner.clear();
            viewerQRScanner = null;
        }).catch(err => {
            console.error('Error stopping scanner:', err);
        });
    }
    
    // Extract item ID from QR code
    let scannedItemId = null;
    
    // Check if it's a URL
    if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
        if (decodedText.includes('view_item.php?id=')) {
            const urlMatch = decodedText.match(/view_item\.php[?&]id=(\d+)/);
            if (urlMatch) {
                scannedItemId = parseInt(urlMatch[1]);
            }
        }
    } else {
        // Try to parse as JSON
        try {
            const data = JSON.parse(decodedText);
            if (data.id) {
                scannedItemId = parseInt(data.id);
            }
        } catch (e) {
            // Plain text QR code - check if it's just a number (item ID)
            if (/^\d+$/.test(decodedText.trim())) {
                scannedItemId = parseInt(decodedText.trim());
            }
        }
    }
    
    // Validate scanned item ID
    if (!scannedItemId) {
        const resultDiv = document.getElementById('viewerQRResult');
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Invalid QR Code</strong><br>Could not identify the item from this QR code. Please scan the QR code of the item you borrowed.</div>';
        
        // Restart scanner after 2 seconds
        setTimeout(() => {
            resultDiv.style.display = 'none';
            startViewerQRScanner();
        }, 2000);
        return;
    }
    
    // Check if scanned item ID matches the borrow record's item ID
    if (scannedItemId !== currentViewerItemId) {
        // Wrong item - show "Wrong Item" message prominently
        const resultDiv = document.getElementById('viewerQRResult');
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="color: #ef4444; padding: 20px; background: #fef2f2; border-radius: 8px; border: 2px solid #fecaca; font-size: 20px; font-weight: bold; text-align: center; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);"><strong>❌ Wrong Item</strong><br><span style="font-size: 14px; font-weight: normal; color: #991b1b; margin-top: 8px; display: block;">Please scan the correct item QR code.</span></div>';
        
        // Restart scanner after 3 seconds
        setTimeout(() => {
            resultDiv.style.display = 'none';
            startViewerQRScanner();
        }, 3000);
        return;
    }
    
    // Item ID matches - fetch and display item details
    try {
        const response = await fetch(`view_item_api.php?id=${scannedItemId}`);
        const data = await response.json();
        
        if (data.success && data.item) {
            displayViewerItemDetails(data.item);
        } else {
            const resultDiv = document.getElementById('viewerQRResult');
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Error</strong><br>Failed to load item details. Please try again.</div>';
            
            setTimeout(() => {
                resultDiv.style.display = 'none';
                startViewerQRScanner();
            }, 2000);
        }
    } catch (error) {
        console.error('Error fetching item details:', error);
        const resultDiv = document.getElementById('viewerQRResult');
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div style="color: #ef4444; padding: 15px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;"><strong>❌ Error</strong><br>Error loading item details. Please try again.</div>';
        
        setTimeout(() => {
            resultDiv.style.display = 'none';
            startViewerQRScanner();
        }, 2000);
    }
}

// Display item details for viewer
function displayViewerItemDetails(item) {
    const detailsContent = document.getElementById('viewerItemDetailsContent');
    const scannerSection = document.getElementById('viewerScannerSection');
    const detailsSection = document.getElementById('viewerItemDetailsSection');
    
    if (!detailsContent || !scannerSection || !detailsSection) {
        return;
    }
    
    // Hide scanner section, show details section
    scannerSection.style.display = 'none';
    detailsSection.style.display = 'block';
    
    // Build item details HTML
    const detailsHTML = `
        <div style="text-align: center; margin-bottom: 20px;">
            <div style="color: #10b981; padding: 15px; background: #d1fae5; border-radius: 8px; border: 1px solid #10b981; margin-bottom: 20px;">
                <strong>✅ Item Verified</strong><br>This is the correct item you borrowed.
            </div>
        </div>
        <div class="item-detail-card">
            <div class="item-detail-label">Item Name</div>
            <div class="item-detail-value">${escapeHtml(item.name || 'N/A')}</div>
        </div>
        <div class="item-detail-card">
            <div class="item-detail-label">Item Code</div>
            <div class="item-detail-value">${escapeHtml(item.item_code || 'N/A')}</div>
        </div>
        <div class="item-detail-card">
            <div class="item-detail-label">Department</div>
            <div class="item-detail-value">${escapeHtml(item.department_name || 'N/A')}</div>
        </div>
        <div class="item-detail-card">
            <div class="item-detail-label">Category</div>
            <div class="item-detail-value">${escapeHtml(item.category || 'N/A')}</div>
        </div>
        <div class="item-detail-card">
            <div class="item-detail-label">Location</div>
            <div class="item-detail-value">${escapeHtml(item.location || 'N/A')}</div>
        </div>
        ${item.description ? `
        <div class="item-detail-card">
            <div class="item-detail-label">Description</div>
            <div class="item-detail-value">${escapeHtml(item.description)}</div>
        </div>
        ` : ''}
    `;
    
    detailsContent.innerHTML = detailsHTML;
}

// Receive item (mark as returned)
async function receiveItem() {
    if (!currentViewerBorrowId) {
        showNotification('Borrow ID not found', 'error');
        return;
    }
    
    const receiveBtn = document.getElementById('viewerReceiveBtn');
    if (receiveBtn) {
        receiveBtn.disabled = true;
        receiveBtn.textContent = 'Processing...';
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'update_borrow');
        formData.append('borrow_id', currentViewerBorrowId);
        formData.append('update_type', 'mark_received');
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Close modal
            closeViewerQRScanner();
            
            // Show success notification
            showNotification('Item received successfully!', 'success');
            
            // Reload data
            loadBorrowHistory();
        } else {
            if (receiveBtn) {
                receiveBtn.disabled = false;
                receiveBtn.textContent = 'Receive';
            }
            showNotification(data.message || 'Failed to receive item. Please try again.', 'error');
        }
    } catch (error) {
        console.error('Error receiving item:', error);
        if (receiveBtn) {
            receiveBtn.disabled = false;
            receiveBtn.textContent = 'Receive';
        }
        showNotification('Error processing receive. Please try again.', 'error');
    }
}

// Close viewer QR scanner modal
function closeViewerQRScanner() {
    // Stop scanner
    if (viewerQRScanner) {
        viewerQRScanner.stop().then(() => {
            viewerQRScanner.clear();
            viewerQRScanner = null;
        }).catch(err => {
            console.error('Error stopping scanner:', err);
            viewerQRScanner = null;
        });
    }
    
    // Hide modal
    const modal = document.getElementById('viewerQRScannerModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    
    // Clear variables
    currentViewerBorrowId = null;
    currentViewerItemId = null;
    currentViewerBorrowRecord = null;
    
    // Clear result
    const resultDiv = document.getElementById('viewerQRResult');
    if (resultDiv) {
        resultDiv.style.display = 'none';
        resultDiv.innerHTML = '';
    }
    
    // Clear scanner container
    const scannerContainer = document.getElementById('viewerQRReader');
    if (scannerContainer) {
        scannerContainer.innerHTML = '';
    }
    
    // Reset sections
    const scannerSection = document.getElementById('viewerScannerSection');
    const detailsSection = document.getElementById('viewerItemDetailsSection');
    if (scannerSection) scannerSection.style.display = 'block';
    if (detailsSection) detailsSection.style.display = 'none';
    
    // Reset receive button
    const receiveBtn = document.getElementById('viewerReceiveBtn');
    if (receiveBtn) {
        receiveBtn.disabled = false;
        receiveBtn.textContent = 'Receive';
    }
}

// Send reminder function
async function sendReminder(borrowId) {
    const record = borrowRecords.find(r => r.borrow_id === borrowId);
    if (!record) {
        showNotification('Record not found', 'error');
        return;
    }
    // Do not allow reminders for returned or received records
    const status = (record.status || '').toLowerCase();
    if (status === 'returned' || status === 'received') {
        showNotification('Cannot send reminder. Item already returned/received.', 'warning');
        const actionMenu = document.querySelector('.action-menu');
        if (actionMenu) actionMenu.remove();
        return;
    }
    
    // Check if borrower_email exists
    if (!record.borrower_email || record.borrower_email === '') {
        showNotification('Borrower email not found. Cannot send reminder.', 'error');
        const actionMenu = document.querySelector('.action-menu');
        if (actionMenu) actionMenu.remove();
        return;
    }
    
    // Calculate days until due or days overdue
    const dueDate = new Date(record.due_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    dueDate.setHours(0, 0, 0, 0);
    
    const daysDiff = Math.ceil((dueDate - today) / (1000 * 60 * 60 * 24));
    const isOverdue = daysDiff < 0;
    const daysUntilDue = isOverdue ? 0 : daysDiff;
    
    // Show confirmation modal
    showConfirmModal(
        'Send Reminder',
        `Send a reminder email to ${record.borrower_name} for ${record.item_name || 'this item'}?${isOverdue ? '<br><span style="color: #dc2626; font-weight: bold;">This item is ' + Math.abs(daysDiff) + ' day(s) overdue.</span>' : ''}`,
        '📧',
        async function() {
            try {
                const formData = new FormData();
                formData.append('action', 'send_reminder');
                formData.append('borrow_id', borrowId);
                formData.append('borrower_email', record.borrower_email);
                formData.append('borrower_name', record.borrower_name);
                formData.append('item_name', record.item_name || 'Borrowed Item');
                formData.append('due_date', record.due_date);
                formData.append('is_overdue', isOverdue ? '1' : '0');
                formData.append('days', daysUntilDue.toString());
                
                const response = await fetch('BorrowHistory.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification(`Reminder email sent successfully to ${record.borrower_name}`, 'success');
                } else {
                    showNotification(data.message || 'Failed to send reminder email', 'error');
                }
            } catch (error) {
                console.error('Error sending reminder:', error);
                showNotification('Error sending reminder email', 'error');
            }
        }
    );
    
    const actionMenu = document.querySelector('.action-menu');
    if (actionMenu) actionMenu.remove();
}

// Approve borrow request
async function approveBorrowRequest(borrowId) {
    // Close action menu
    const actionMenu = document.querySelector('.action-menu');
    if (actionMenu) actionMenu.remove();
    
    // Find the record
    const record = borrowRecords.find(r => r.borrow_id === borrowId);
    if (!record) {
        showNotification('Borrow request not found', 'error');
        return;
    }
    
    if (record.status !== 'pending') {
        showNotification('This request is not pending', 'warning');
        return;
    }
    
    // Show confirmation modal
    showConfirmModal(
        'Approve Borrow Request',
        `Approve borrow request from ${record.borrower_name} for ${record.item_name || 'this item'}?`,
        '✅',
        async function() {
            try {
                const formData = new FormData();
                formData.append('action', 'update_borrow_status');
                formData.append('borrow_id', borrowId);
                formData.append('status', 'approved');
                
                const response = await fetch('crud.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Borrow request approved successfully!', 'success');
                    // Reload data
                    loadBorrowHistory();
                } else {
                    showNotification(data.message || 'Failed to approve request', 'error');
                }
            } catch (error) {
                console.error('Error approving request:', error);
                showNotification('Error approving request', 'error');
            }
        }
    );
}

// Reject borrow request
async function rejectBorrowRequest(borrowId) {
    // Close action menu
    const actionMenu = document.querySelector('.action-menu');
    if (actionMenu) actionMenu.remove();
    
    // Find the record
    const record = borrowRecords.find(r => r.borrow_id === borrowId);
    if (!record) {
        showNotification('Borrow request not found', 'error');
        return;
    }
    
    if (record.status !== 'pending') {
        showNotification('This request is not pending', 'warning');
        return;
    }
    
    // Show confirmation modal
    showConfirmModal(
        'Reject Borrow Request',
        `Reject borrow request from ${record.borrower_name} for ${record.item_name || 'this item'}?<br><span style="color: #dc2626;">This action cannot be undone.</span>`,
        '❌',
        async function() {
            try {
                const formData = new FormData();
                formData.append('action', 'update_borrow_status');
                formData.append('borrow_id', borrowId);
                formData.append('status', 'declined');
                
                const response = await fetch('crud.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Borrow request rejected', 'success');
                    // Reload data
                    loadBorrowHistory();
                } else {
                    showNotification(data.message || 'Failed to reject request', 'error');
                }
            } catch (error) {
                console.error('Error rejecting request:', error);
                showNotification('Error rejecting request', 'error');
            }
        }
    );
}

// Delete record function removed (disabled for safety)

// Update status cards with counts
function updateStatusCards() {
    const counts = {
        pending: 0,
        approved: 0,
        received: 0,
        returned: 0,
        overdue: 0,
        borrowedItems: 0
    };
    
    // Get current user's department for filtering
    const currentUserDepartment = '<?php echo htmlspecialchars($department ?? '', ENT_QUOTES); ?>';
    const isDepartmentHead = document.body.getAttribute('data-user-is-department-head') === 'true';
    const isSuperAdmin = document.body.getAttribute('data-user-super-admin') === 'true';
    const isViewer = document.body.getAttribute('data-user-is-viewer') === 'true';
    
    // Get selected department filter (for Admin/Super Admin)
    const departmentFilterElement = document.getElementById('departmentFilter');
    const selectedDepartment = departmentFilterElement ? departmentFilterElement.value : '';
    
    // Count records by status from ALL records (not filtered)
    // This ensures card counts always show total counts regardless of current filter
    allBorrowRecords.forEach(record => {
        const status = (record.status || '').toLowerCase().trim();
        const isCurrentUserBorrower = currentUsername && record.borrower_name && 
            record.borrower_name.toLowerCase().trim() === currentUsername.toLowerCase().trim();
        const isItemFromUserDepartment = currentUserDepartment && record.department_name && 
            record.department_name.toLowerCase().trim() === currentUserDepartment.toLowerCase().trim();
        
        // For Admin/Super Admin: filter by selected department if one is selected
        if ((isSuperAdmin || isDepartmentHead) && selectedDepartment) {
            const isItemFromSelectedDepartment = record.department_name && 
                record.department_name.toLowerCase().trim() === selectedDepartment.toLowerCase().trim();
            if (!isItemFromSelectedDepartment) {
                return; // Skip records that don't match selected department
            }
        }
        
        // For viewers, only count their own records for all status cards
        if (isViewer && !isCurrentUserBorrower) {
            return; // Skip records that don't belong to the viewer
        }
        
        if (status === 'pending') {
            // For department heads: only count pending requests for items from their department
            // For super admins: count all pending requests (or filtered by selected department)
            // For viewers: only count their own pending requests (already filtered above)
            if (isDepartmentHead && !isSuperAdmin) {
                if (isItemFromUserDepartment) {
                    counts.pending++;
                }
            } else {
                counts.pending++;
            }
        } else if (status === 'approved') {
            // Approved card: items that are approved
            // For viewers: only count their own approved requests
            if (isViewer) {
                counts.approved++;
            } else if (isDepartmentHead && !isSuperAdmin) {
                // For department heads: count approved items from their department (not borrowed by them)
                if (isItemFromUserDepartment && !isCurrentUserBorrower) {
                    counts.approved++;
                }
            } else {
                // For super admins: count all approved items (or filtered by selected department)
                counts.approved++;
            }
        } else if (status === 'received') {
            // For department heads: only count received items from their department
            // For super admins: count all received items (or filtered by selected department)
            // For viewers: only count their own received items (already filtered above)
            if (isDepartmentHead && !isSuperAdmin) {
                if (isItemFromUserDepartment) {
                    counts.received++;
                }
            } else {
                counts.received++;
            }
        } else if (status === 'returned') {
            // For department heads: only count returned items from their department
            // For super admins: count all returned items (or filtered by selected department)
            // For viewers: only count their own returned items (already filtered above)
            if (isDepartmentHead && !isSuperAdmin) {
                if (isItemFromUserDepartment) {
                    counts.returned++;
                }
            } else if (!isViewer) {
                counts.returned++;
            } else {
                // Viewer case - already filtered above
                counts.returned++;
            }
        } else if (status === 'overdue') {
            // For department heads: only count overdue items from their department
            // For super admins: count all overdue items (or filtered by selected department)
            // For viewers: only count their own overdue items (already filtered above)
            if (isDepartmentHead && !isSuperAdmin) {
                if (isItemFromUserDepartment) {
                    counts.overdue++;
                }
            } else if (!isViewer) {
                counts.overdue++;
            } else {
                // Viewer case - already filtered above
                counts.overdue++;
            }
        }
        
        // Count borrowed items for current user (ALL users who borrowed items)
        // Include items with status: approved, received, active, or overdue (currently borrowed items)
        // This should work for all users - if they borrowed items, they should see them
        if (isCurrentUserBorrower) {
            const borrowedStatuses = ['approved', 'received', 'active', 'overdue'];
            if (borrowedStatuses.includes(status)) {
                counts.borrowedItems++;
            }
        }
    });
    
    // Update card counts
    document.getElementById('pendingCount').textContent = counts.pending;
    document.getElementById('approvedCount').textContent = counts.approved;
    const receivedCountEl = document.getElementById('receivedCount');
    if (receivedCountEl) {
        receivedCountEl.textContent = counts.received;
    }
    document.getElementById('returnedCount').textContent = counts.returned;
    document.getElementById('overdueCount').textContent = counts.overdue;
    
    // Update borrowed items count (only if card exists)
    const borrowedItemsCountEl = document.getElementById('borrowedItemsCount');
    if (borrowedItemsCountEl) {
        borrowedItemsCountEl.textContent = counts.borrowedItems;
    }
}

// Filter by status card click
function filterByStatusCard(status) {
    // Reset borrowed items filter
    showBorrowedItemsOnly = false;
    
    // Update status filter dropdown
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.value = status;
    }
    
    // Remove active class from all cards
    document.querySelectorAll('.stat-card').forEach(card => {
        card.classList.remove('active');
        card.style.background = 'white';
        card.style.border = 'none';
    });
    
    // Add active class to clicked card
    const clickedCard = document.getElementById(status + 'Card');
    if (clickedCard) {
        clickedCard.classList.add('active');
        clickedCard.style.background = '#f3f4f6';
        clickedCard.style.border = '2px solid #3b82f6';
    }
    
    // Trigger filter
    filterTable();
}

// Filter by borrowed items card click
function filterByBorrowedItemsCard() {
    // Toggle borrowed items filter
    showBorrowedItemsOnly = !showBorrowedItemsOnly;
    
    // Clear status filter when using borrowed items filter
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.value = '';
    }
    
    // Clear department filter when using borrowed items filter (to show items from all departments)
    const departmentFilter = document.getElementById('departmentFilter');
    if (departmentFilter) {
        departmentFilter.value = '';
    }
    
    // Remove active class from all status cards
    document.querySelectorAll('.stat-card').forEach(card => {
        if (card.id !== 'borrowedItemsCard') {
            card.classList.remove('active');
            card.style.background = 'white';
            card.style.border = 'none';
        }
    });
    
    // Toggle active state for borrowed items card
    const borrowedItemsCard = document.getElementById('borrowedItemsCard');
    if (borrowedItemsCard) {
        if (showBorrowedItemsOnly) {
            borrowedItemsCard.classList.add('active');
            borrowedItemsCard.style.background = '#f3f4f6';
            borrowedItemsCard.style.border = '2px solid #8b5cf6';
        } else {
            borrowedItemsCard.classList.remove('active');
            borrowedItemsCard.style.background = 'white';
            borrowedItemsCard.style.border = 'none';
        }
    }
    
    // Trigger filter
    filterTable();
}

// Reset card active state when filter changes
function resetCardActiveState() {
    document.querySelectorAll('.stat-card').forEach(card => {
        card.classList.remove('active');
        card.style.background = 'white';
        card.style.border = 'none';
    });
    // Also reset borrowed items filter
    showBorrowedItemsOnly = false;
}

// Animate counter updates
function animateCounter(elementId, targetValue) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const currentValue = parseInt(element.textContent) || 0;
    const increment = targetValue > currentValue ? 1 : -1;
    const steps = Math.abs(targetValue - currentValue);
    let current = currentValue;
    
    if (steps === 0) return;
    
    const stepTime = Math.min(50, 300 / steps);
    
    const timer = setInterval(() => {
        current += increment;
        element.textContent = current;
        
        if (current === targetValue) {
            clearInterval(timer);
        }
    }, stepTime);
}

// Modal functions
function closeDetailsModal() {
    const modal = document.getElementById('detailsModal');
    modal.style.display = 'none';
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

function showConfirmModal(title, message, icon, confirmCallback) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    document.getElementById('confirmIcon').textContent = icon;
    
    const confirmBtn = document.getElementById('confirmActionBtn');
    confirmBtn.onclick = function() {
        closeConfirmModal();
        if (confirmCallback) confirmCallback();
    };
    
    document.getElementById('confirmModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Show notification
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#cce7ff'};
        color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#004085'};
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 1000;
        max-width: 300px;
        word-wrap: break-word;
        opacity: 0;
        transform: translateX(100%);
        transition: all 0.3s ease;
    `;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateX(0)';
    }, 100);
    
    // Remove after 5 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 5000);
}

// Update pagination info
function updatePagination() {
    const visibleRows = Array.from(document.querySelectorAll('#tableBody tr')).filter(row => 
        row.style.display !== 'none' && row.cells.length > 1
    );
    
    const totalRecords = visibleRows.length;
    
    document.getElementById('startRecord').textContent = totalRecords > 0 ? 1 : 0;
    document.getElementById('endRecord').textContent = totalRecords;
    document.getElementById('totalRecords').textContent = totalRecords;
}

// Download functionality - PDF Export
function downloadData() {
    const statusFilter = document.getElementById('statusFilter').value;
    const departmentFilter = document.getElementById('departmentFilter').value;
    
    const params = new URLSearchParams({
        type: 'borrow_history',
        status: statusFilter,
        department: departmentFilter
    });
    
    window.open('pdf_export.php?' + params.toString(), '_blank');
    showNotification('PDF report generated successfully!', 'success');
}


// Pagination functionality (basic implementation)
function changePage(direction) {
    // This is a placeholder for pagination functionality
    // In a full implementation, you'd handle page navigation here
    console.log('Page change:', direction);
}

// Sign out modal functionality
function signOut() {
    showSignOutModal();
}

function showSignOutModal() {
    const modal = document.getElementById('signOutModal');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeSignOutModal() {
    const modal = document.getElementById('signOutModal');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

function confirmSignOut() {
    const confirmBtn = document.getElementById('confirmSignOut');
    
    if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = 'Signing out...';
        
        setTimeout(() => {
            closeSignOutModal();
            window.location.href = 'logout.php';
        }, 1500);
    }
}

// Close modals when clicking outside or pressing Escape
document.addEventListener('click', function(e) {
    const signOutModal = document.getElementById('signOutModal');
    if (signOutModal && e.target === signOutModal) {
        closeSignOutModal();
    }
    
    const detailsModal = document.getElementById('detailsModal');
    if (detailsModal && e.target === detailsModal) {
        closeDetailsModal();
    }
    
    const editModal = document.getElementById('editModal');
    if (editModal && e.target === editModal) {
        closeEditModal();
    }
    
    const confirmModal = document.getElementById('confirmModal');
    if (confirmModal && e.target === confirmModal) {
        closeConfirmModal();
    }
});

// Close details modal when clicking outside (for item-detail-modal structure)
document.addEventListener('click', function(e) {
    const detailsModal = document.getElementById('detailsModal');
    if (detailsModal && e.target === detailsModal && e.target.classList.contains('item-detail-modal')) {
        closeDetailsModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeSignOutModal();
        closeDetailsModal();
        closeEditModal();
        closeConfirmModal();
        const actionMenu = document.querySelector('.action-menu');
        if (actionMenu) actionMenu.remove();
    }
});

// Edit form submission
document.getElementById('editForm').addEventListener('submit', function(e) {
    e.preventDefault();
    // Here you would typically send the form data to the server
    // For now, just show a success message
    showNotification('Record updated successfully!', 'success');
    closeEditModal();
    loadBorrowHistory(); // Reload data
});

// Add dynamic styles for all modals and components
const dynamicStyles = `
<style>
.loading-spinner {
    display: inline-block;
    position: relative;
}

.loading-spinner::after {
    content: '';
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #007bff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-left: 10px;
    vertical-align: middle;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.action-menu {
    background: white !important;
    border: 1px solid #ddd !important;
    border-radius: 8px !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
    z-index: 10000 !important;
    min-width: 180px !important;
    overflow: hidden !important;
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    pointer-events: auto !important;
    position: fixed !important;
}

.action-item {
    padding: 12px 16px !important;
    cursor: pointer !important;
    border-bottom: 1px solid #f0f0f0 !important;
    transition: background-color 0.2s !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    color: #333 !important;
    background: white !important;
    pointer-events: auto !important;
}

.action-item:hover {
    background-color: #f8f9fa !important;
}

.action-item:last-child {
    border-bottom: none !important;
}

.action-item.delete-item {
    color: #dc3545 !important;
}

.action-item.delete-item:hover {
    background-color: #f8d7da !important;
}

.action-item i {
    width: 16px !important;
    text-align: center !important;
    pointer-events: none !important;
}

.action-item-disabled {
    opacity: 0.5 !important;
    cursor: not-allowed !important;
    pointer-events: none !important;
    background-color: #f8f9fa !important;
    color: #6c757d !important;
}

.action-item-disabled:hover {
    background-color: #f8f9fa !important;
    color: #6c757d !important;
}

/* Modal styles */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.modal-overlay.show {
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 1;
}

.modal {
    background: white;
    border-radius: 12px;
    padding: 24px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    transform: scale(0.7);
    transition: transform 0.3s ease;
}

.modal-overlay.show .modal {
    transform: scale(1);
}

.details-modal {
    max-width: 700px;
}

.edit-modal {
    max-width: 600px;
}

.modal-header {
    text-align: center;
    margin-bottom: 20px;
}

.modal-icon {
    font-size: 48px;
    margin-bottom: 16px;
}

.modal-title {
    font-size: 24px;
    font-weight: bold;
    margin-bottom: 8px;
    color: #333;
}

.modal-message {
    color: #666;
    line-height: 1.5;
}

.modal-buttons {
    display: flex;
    gap: 12px;
    justify-content: center;
    margin-top: 24px;
}

.modal-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.modal-btn-cancel {
    background-color: #f8f9fa;
    color: #6c757d;
}

.modal-btn-cancel:hover {
    background-color: #e9ecef;
}

.modal-btn-confirm {
    background-color: #007bff;
    color: white;
}

.modal-btn-confirm:hover {
    background-color: #0056b3;
}

.close-btn {
    position: absolute;
    top: 16px;
    right: 16px;
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #999;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.close-btn:hover {
    background-color: #f5f5f5;
    color: #333;
}

/* Details modal specific styles */
.details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 20px;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.detail-item label {
    font-weight: bold;
    color: #333;
    font-size: 14px;
}

.detail-item span {
    color: #666;
    font-size: 14px;
    padding: 8px;
    background-color: #f8f9fa;
    border-radius: 4px;
    border: 1px solid #e9ecef;
}

/* Edit modal specific styles */
.edit-content {
    margin-top: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
    color: #333;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s ease;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}

.form-group input[readonly] {
    background-color: #f8f9fa;
    color: #6c757d;
}

.form-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
}

.btn-cancel {
    padding: 12px 24px;
    background-color: #f8f9fa;
    color: #6c757d;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.btn-cancel:hover {
    background-color: #e9ecef;
}

.btn-save {
    padding: 12px 24px;
    background-color: #28a745;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.btn-save:hover {
    background-color: #218838;
}

/* Status and priority badges */
.status-badge, .priority-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    text-transform: capitalize;
}

.status-active {
    background-color: #d1ecf1;
    color: #0c5460;
}

.status-returned {
    background-color: #d4edda;
    color: #155724;
}

.status-overdue {
    background-color: #f8d7da;
    color: #721c24;
}

.status-declined {
    background-color: #fee2e2;
    color: #991b1b;
    font-weight: 600;
    border: 1px solid #fca5a5;
}

.status-received {
    background-color: #fff3cd;
    color: #856404;
    font-weight: 600;
    border: 1px solid #ffc107;
}

.priority-low {
    background-color: #e2e3e5;
    color: #495057;
}

.priority-medium {
    background-color: #fff3cd;
    color: #856404;
}

.priority-high {
    background-color: #f8d7da;
    color: #721c24;
}

/* Responsive design */
@media (max-width: 1200px) {
    .chart-card {
        flex: 1;
        min-width: 0;
        max-width: none;
    }
}

@media (max-width: 768px) {
    .dashboard-header {
        flex-direction: column;
        gap: 12px;
        justify-content: flex-start;
    }
    
    .chart-card {
        flex: none;
        width: 100%;
        min-width: 100%;
        max-width: 100%;
        height: 220px;
        max-height: 220px;
        min-height: 220px;
    }
    
    .chart-container {
        height: 160px;
        max-height: 160px;
        min-height: 160px;
    }
    
    .details-grid {
        grid-template-columns: 1fr;
    }
    
    .modal {
        width: 95%;
        padding: 16px;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .modal-buttons {
        flex-direction: column;
    }
    
    .action-menu {
        min-width: 160px;
    }
}

/* Animation keyframes */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notification {
    animation: fadeIn 0.3s ease-out;
}

/* Table enhancements */
.table th {
    position: relative;
    cursor: pointer;
    user-select: none;
}

.table th:hover {
    background-color: #f8f9fa;
}

.sort-icon {
    margin-left: 4px;
    font-size: 12px;
    opacity: 0.5;
    transition: opacity 0.2s ease;
}

.table th:hover .sort-icon {
    opacity: 1;
}

/* Action button styles */
.action-btn {
    background: none;
    border: none;
    font-size: 18px;
    cursor: pointer;
    padding: 8px;
    border-radius: 4px;
    transition: background-color 0.2s ease;
    color: #6c757d;
    position: relative;
    z-index: 10;
    pointer-events: auto;
}

.action-btn:hover {
    background-color: #f8f9fa;
    color: #333;
}

.action-btn:active {
    background-color: #e9ecef;
}

/* Ensure action button column is clickable */
.table td:last-child {
    position: relative;
    z-index: 10;
    pointer-events: auto;
}

.table td:last-child .action-btn {
    pointer-events: auto;
    position: relative;
    z-index: 11;
}

/* Loading states */
.btn-loading {
    opacity: 0.6;
    cursor: not-allowed;
    position: relative;
}

.btn-loading::after {
    content: '';
    position: absolute;
    left: 50%;
    top: 50%;
    width: 16px;
    height: 16px;
    margin: -8px 0 0 -8px;
    border: 2px solid transparent;
    border-top: 2px solid currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

/* Chart enhancements */
.chart-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.chart-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.chart-bar {
    transition: all 0.3s ease;
    transform-origin: bottom;
}

/* Filter section improvements */
.filters-section {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 24px;
    padding: 20px;
    background-color: #f8f9fa;
    border-radius: 8px;
}

.search-input, .filter-select {
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s ease;
}

.search-input:focus, .filter-select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}

.download-btn {
    padding: 10px 16px;
    background-color: #28a745;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: background-color 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.download-btn:hover {
    background-color: #218838;
}

/* Pagination improvements */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 24px;
    padding: 16px 0;
    border-top: 1px solid #e9ecef;
}

.pagination-info {
    color: #6c757d;
    font-size: 14px;
}

.pagination-buttons {
    display: flex;
    align-items: center;
    gap: 8px;
}

.pagination-btn {
    padding: 8px 16px;
    border: 1px solid #ddd;
    background-color: white;
    color: #6c757d;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s ease;
}

.pagination-btn:hover:not(:disabled) {
    background-color: #f8f9fa;
    border-color: #999;
}

.pagination-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Table container improvements */
.table-container {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #333;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

/* Page header improvements */
.page-header {
    margin-bottom: 32px;
}

.page-title {
    font-size: 32px;
    font-weight: bold;
    color: #333;
    margin-bottom: 8px;
}

.page-subtitle {
    color: #6c757d;
    font-size: 16px;
    margin: 0;
}

/* Dashboard header improvements */
.dashboard-header {
    display: flex;
    gap: 12px;
    margin-bottom: 30px;
    align-items: flex-start;
    flex-wrap: wrap;
    width: 100%;
    max-width: 100%;
    justify-content: space-between;
}

.chart-card {
    background: white;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    flex: 1;
    min-width: 0;
    max-width: none;
    transition: all 0.3s ease;
    overflow: hidden;
    margin-bottom: 20px;
    cursor: pointer;
    height: 280px;
    max-height: 280px;
    min-height: 280px;
}

.chart-title {
    font-size: 12px;
    font-weight: 600;
    color: #666;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    height: 30px;
    max-height: 30px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.chart-container {
    position: relative;
    height: 200px;
    padding: 12px 8px 8px 8px;
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
    border-radius: 8px;
    margin: 8px 0;
    overflow: hidden;
    width: 100%;
    max-height: 200px;
    min-height: 200px;
}

.chart-bar {
    flex: 1;
    background-color: #007bff;
    border-radius: 2px 2px 0 0;
    min-height: 4px;
}

.chart-labels {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #6c757d;
}

.chart-labels span {
    font-weight: 500;
}

/* Color variations for chart cards */
.total-borrows .chart-bar {
    background-color: #007bff;
}

.active-borrows .chart-bar {
    background-color: #17a2b8;
}

.overdue-items .chart-bar {
    background-color: #dc3545;
}

.returned-items .chart-bar {
    background-color: #28a745;
}

.total-borrows .chart-number {
    color: #007bff;
}

.active-borrows .chart-number {
    color: #17a2b8;
}

.overdue-items .chart-number {
    color: #dc3545;
}

.returned-items .chart-number {
    color: #28a745;
}
</style>
`;

// Add styles to document head
if (!document.getElementById('dynamic-styles')) {
    const styleElement = document.createElement('div');
    styleElement.id = 'dynamic-styles';
    styleElement.innerHTML = dynamicStyles;
    document.head.appendChild(styleElement);
}
    </script>
</body>
</html>
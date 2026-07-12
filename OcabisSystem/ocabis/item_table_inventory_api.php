<?php
include '../db_connect.php';
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Check user permissions
$isViewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$hasDepartment = isset($_SESSION['department']) && !empty(trim($_SESSION['department']));

$userId = $_SESSION['user_id'];

// Get action from GET, POST, or JSON body
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$jsonInput = null;

// Read JSON input if it's a POST request (can only read php://input once)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonInput = json_decode($rawInput, true);
        // Get action from JSON if not already set
        if (!$action && $jsonInput && isset($jsonInput['action'])) {
            $action = $jsonInput['action'];
        }
    }
}

if (!$action) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// Define read-only actions that viewers/teachers can access
$readOnlyActions = ['get_item_table', 'get_items', 'get_qr_path'];
$isReadOnlyAction = in_array($action, $readOnlyActions);

// Block viewers/teachers from write actions
if ($isViewer && !$isReadOnlyAction) {
    echo json_encode(['success' => false, 'message' => 'Access denied. Viewers can only scan/view items, not modify them.']);
    exit();
}

try {
    switch ($action) {
        case 'get_item_tables_list':
            // Get all item tables with department info
            $sql = "SELECT it.*, d.name as department_name 
                    FROM item_tables it
                    LEFT JOIN departments d ON it.department_id = d.id
                    ORDER BY it.id DESC";
            
            $result = $conn->query($sql);
            
            $itemTables = [];
            while ($row = $result->fetch_assoc()) {
                $itemTables[] = $row;
            }
            
            echo json_encode(['success' => true, 'item_tables' => $itemTables]);
            break;

        case 'generate_qr_code':
            // Generate QR code for item table
            // Use already parsed JSON input if available, otherwise parse it
            if ($jsonInput === null) {
                $jsonInput = json_decode(file_get_contents('php://input'), true);
            }
            $itemTableId = $jsonInput['item_table_id'] ?? null;
            
            if (!$itemTableId) {
                echo json_encode(['success' => false, 'message' => 'Item table ID required']);
                exit();
            }
            
            // Check if qr_code column exists, if not add it
            $checkColumn = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'qr_code'");
            if ($checkColumn->num_rows == 0) {
                // Add qr_code column if it doesn't exist
                $conn->query("ALTER TABLE item_tables ADD COLUMN qr_code varchar(255) DEFAULT NULL AFTER table_image_path");
                $conn->query("ALTER TABLE item_tables ADD INDEX idx_qr_code (qr_code)");
            }
            
            // Get item table info with department
            $sql = "SELECT it.*, d.id as department_id, d.name as department_name 
                    FROM item_tables it 
                    LEFT JOIN departments d ON it.department_id = d.id 
                    WHERE it.id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $itemTableId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Item table not found']);
                exit();
            }
            
            $itemTable = $result->fetch_assoc();
            $stmt->close();
            
            // Permission check: super admin or same department can generate QR codes
            $currentDepartment = isset($_SESSION['department']) ? trim($_SESSION['department']) : '';
            $isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
            $tableDepartmentName = isset($itemTable['department_name']) ? trim($itemTable['department_name']) : '';
            
            // Normalize comparison: trim and case-insensitive
            if (!$isSuperAdmin && strcasecmp($tableDepartmentName, $currentDepartment) !== 0) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only generate QR codes for item tables from your own department']);
                exit();
            }
            
            // Get department color
            if (!function_exists('getDepartmentColorHex')) {
                function getDepartmentColorHex($departmentId, $departmentName = null) {
                    // Normalize department name: lowercase, trim, remove extra spaces
                    $name = is_string($departmentName) ? preg_replace('/\s+/', ' ', strtolower(trim($departmentName))) : '';
                    
                    if ($name !== '') {
                        // ICT Equipment - red
                        if ($name === 'ict equipment' || (strpos($name, 'ict') !== false && strpos($name, 'equipment') !== false)) {
                            error_log("getDepartmentColorHex (API): Matched 'ICT Equipment' -> C62828 (red)");
                            return 'C62828';
                        }
                        // SLRC - blue
                        if ($name === 'slrc' || strpos($name, 'student learning resource center') !== false || strpos($name, 'slrc') !== false) {
                            error_log("getDepartmentColorHex (API): Matched 'SLRC' -> 1565C0 (blue)");
                            return '1565C0';
                        }
                        // Science Equipment - yellow
                        if ($name === 'science equipment' || (strpos($name, 'science') !== false && strpos($name, 'equipment') !== false)) {
                            error_log("getDepartmentColorHex (API): Matched 'Science Equipment' -> F59E0B (yellow)");
                            return 'F59E0B';
                        }
                        // SPS Equipment - green
                        if ($name === 'sps equipment' || (strpos($name, 'sps') !== false && strpos($name, 'equipment') !== false)) {
                            error_log("getDepartmentColorHex (API): Matched 'SPS Equipment' -> 2E7D32 (green)");
                            return '2E7D32';
                        }
                        
                        error_log("getDepartmentColorHex (API): No exact match for name: '$name', using fallback");
                    }
                    
                    // Fallback deterministic palette by ID
                    $palette = [
                        '000000', '1F497D', '2E7D32', '7B1FA2', 'C62828', '1565C0', '00695C', '4E342E', '37474F', 'AD1457', '283593', '00838F'
                    ];
                    if (!is_numeric($departmentId) || $departmentId <= 0) {
                        error_log("getDepartmentColorHex (API): Invalid department ID, using black");
                        return $palette[0];
                    }
                    $index = ((int)$departmentId) % count($palette);
                    $fallbackColor = $palette[$index];
                    error_log("getDepartmentColorHex (API): Using fallback color from palette index $index: $fallbackColor for department ID: $departmentId");
                    return $fallbackColor;
                }
            }
            
            $deptId = $itemTable['department_id'] ?? null;
            $deptName = $itemTable['department_name'] ?? null;
            
            // Log for debugging
            error_log("QR Generation (API) - Department ID: $deptId, Name: " . ($deptName ?? 'NULL'));
            
            $fgColor = getDepartmentColorHex($deptId, $deptName);
            
            // Ensure color is properly formatted (hex without #)
            $fgColor = strtoupper(ltrim($fgColor, '#'));
            
            // Log the color being used
            error_log("QR Generation (API) - Using color: $fgColor for department: $deptName");
            
            // Generate QR code value (use table ID or create unique code)
            $qrCodeValue = 'TABLE-' . $itemTableId . '-' . time();
            
            // Generate QR code image
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
            $qrData = $baseUrl . 'item_table_inventory.php?table_id=' . $itemTableId;
            
            $qrCodeFilename = 'qr_table_' . $itemTableId . '_' . time() . '.png';
            $qrCodePath = 'qr_codes/' . $qrCodeFilename;
            
            // Ensure qr_codes folder exists
            if (!file_exists('qr_codes')) {
                if (!mkdir('qr_codes', 0777, true)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to create qr_codes folder']);
                    exit();
                }
            }
            
            // Generate QR code using API with department color
            // Log the final API URL for debugging (without the data parameter for security)
            error_log("QR Generation (API) - API URL (partial): https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=" . $fgColor . "&bgcolor=FFFFFF");
            
            $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=' . $fgColor . '&bgcolor=FFFFFF&data=' . urlencode($qrData);
            $qrImage = @file_get_contents($qrApiUrl);
            
            // Log if QR generation failed
            if ($qrImage === false) {
                error_log("QR Generation (API) - Failed to generate QR code from API. URL: " . substr($qrApiUrl, 0, 200));
                echo json_encode(['success' => false, 'message' => 'Failed to generate QR code image']);
                exit();
            } else {
                error_log("QR Generation (API) - Successfully generated QR code with color: $fgColor");
            }
            
            // Save QR code image
            if (file_put_contents($qrCodePath, $qrImage) === false) {
                echo json_encode(['success' => false, 'message' => 'Failed to save QR code file']);
                exit();
            }
            
            // Update item_tables with QR code
            $updateSql = "UPDATE item_tables SET qr_code = ? WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("si", $qrCodeValue, $itemTableId);
            if (!$updateStmt->execute()) {
                error_log("item_table_inventory_api - Failed to update item_tables.qr_code for table {$itemTableId}: " . $updateStmt->error);
            }
            $updateStmt->close();
            
            // ALSO update items.qr_code so the new QR propagates to each item record
            // This ensures download buttons and QR printing use the latest code
            $updateItemsSql = "UPDATE items SET qr_code = ? WHERE item_table_id = ?";
            $updateItemsStmt = $conn->prepare($updateItemsSql);
            if ($updateItemsStmt) {
                $updateItemsStmt->bind_param("si", $qrCodeValue, $itemTableId);
                if (!$updateItemsStmt->execute()) {
                    error_log("item_table_inventory_api - Failed to update items.qr_code for table {$itemTableId}: " . $updateItemsStmt->error);
                }
                $updateItemsStmt->close();
            } else {
                error_log("item_table_inventory_api - Failed to prepare items.qr_code update statement for table {$itemTableId}: " . $conn->error);
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'QR code generated successfully',
                'qr_code' => $qrCodeValue,
                'qr_path' => $qrCodePath,
                'qr_url' => $qrApiUrl,
                'qr_image_url' => $baseUrl . $qrCodePath  // Full URL for download
            ]);
            break;
            
        case 'get_item_table':
            // Get item table by QR code or table ID
            $qrCode = $_GET['qr_code'] ?? null;
            $tableId = $_GET['table_id'] ?? null;
            
            if (!$qrCode && !$tableId) {
                echo json_encode(['success' => false, 'message' => 'QR code or table ID required']);
                exit();
            }
            
            // Join with departments to get department name
            $sql = "SELECT it.*, d.id as department_id, d.name as department_name 
                    FROM item_tables it 
                    LEFT JOIN departments d ON it.department_id = d.id 
                    WHERE ";
            if ($qrCode) {
                $sql .= "it.qr_code = ? OR it.id = ?";
                $stmt = $conn->prepare($sql);
                // Try to parse QR code as ID if it's numeric
                $qrAsId = is_numeric($qrCode) ? intval($qrCode) : 0;
                $stmt->bind_param("si", $qrCode, $qrAsId);
            } else {
                $sql .= "it.id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $tableId);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Item table not found']);
                exit();
            }
            
            $itemTable = $result->fetch_assoc();
            echo json_encode(['success' => true, 'item_table' => $itemTable]);
            break;

        case 'get_qr_path':
            // Get QR code file path for an item table
            $tableId = $_GET['table_id'] ?? null;
            
            if (!$tableId) {
                echo json_encode(['success' => false, 'message' => 'Table ID required']);
                exit();
            }
            
            // Get item table info
            $sql = "SELECT qr_code FROM item_tables WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $tableId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Item table not found']);
                exit();
            }
            
            $itemTable = $result->fetch_assoc();
            $stmt->close();
            
            // Find the QR code file in qr_codes folder
            $qrCodePath = null;
            if (!empty($itemTable['qr_code'])) {
                // Search for QR code files matching the pattern
                $qrFiles = glob("qr_codes/qr_table_{$tableId}_*.png");
                if (!empty($qrFiles)) {
                    // Get the most recent one
                    usort($qrFiles, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    $qrCodePath = $qrFiles[0];
                }
            }
            
            if ($qrCodePath && file_exists($qrCodePath)) {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
                
                echo json_encode([
                    'success' => true,
                    'qr_path' => $qrCodePath,
                    'qr_url' => $baseUrl . $qrCodePath
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'QR code file not found']);
            }
            break;
            
        case 'get_items':
            // Get all items in an item table with correct status (Borrowed/Consumable)
            $itemTableId = $_GET['item_table_id'] ?? null;
            
            if (!$itemTableId) {
                echo json_encode(['success' => false, 'message' => 'Item table ID required']);
                exit();
            }
            
            // Check if item_tables has is_consumable column
            $checkConsumableColumn = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'is_consumable'");
            $hasConsumableColumn = $checkConsumableColumn && $checkConsumableColumn->num_rows > 0;
            
            // Build SQL with proper status logic - check if item is borrowed or consumable
            // Priority: Borrowed > Consumable > Original Status
            $sql = "SELECT i.*, i.name, i.item_code, i.quantity, i.location, i.category,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM borrow_history bh 
                            WHERE bh.item_id = i.id 
                            AND bh.status IN ('approved', 'active', 'overdue', 'received')
                        ) THEN 'Borrowed'
                        WHEN EXISTS (
                            SELECT 1 FROM item_tables it 
                            WHERE it.id = i.item_table_id";
            
            if ($hasConsumableColumn) {
                $sql .= " AND COALESCE(it.is_consumable, 0) = 1";
            } else {
                $sql .= " AND 0 = 1";
            }
            
            $sql .= "                        ) THEN 'Consumable'
                        ELSE COALESCE(i.status, 'Working')
                    END as status,
                    i.status as original_status
                    FROM items i
                    WHERE i.item_table_id = ?
                    ORDER BY i.name ASC, i.item_code ASC";
            
            error_log("Item Table Inventory API - get_items: Query for table_id=$itemTableId, hasConsumableColumn=" . ($hasConsumableColumn ? 'yes' : 'no'));
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("Item Table Inventory API - get_items: Prepare failed: " . $conn->error);
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                exit();
            }
            
            $stmt->bind_param("i", $itemTableId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $items = [];
            $borrowedCount = 0;
            $consumableCount = 0;
            while ($row = $result->fetch_assoc()) {
                if ($row['status'] === 'Borrowed') {
                    $borrowedCount++;
                } else if ($row['status'] === 'Consumable') {
                    $consumableCount++;
                }
                $items[] = $row;
            }
            $stmt->close();
            
            error_log("Item Table Inventory API - get_items: Found " . count($items) . " items, Borrowed: $borrowedCount, Consumable: $consumableCount");
            
            echo json_encode(['success' => true, 'items' => $items]);
            break;
            
        case 'save_inventory':
            // Save inventory updates
            // Use already parsed JSON input if available, otherwise parse it
            if ($jsonInput === null) {
                $jsonInput = json_decode(file_get_contents('php://input'), true);
            }
            $itemTableId = $jsonInput['item_table_id'] ?? null;
            $updates = $jsonInput['updates'] ?? [];
            
            if (!$itemTableId || empty($updates)) {
                echo json_encode(['success' => false, 'message' => 'Invalid data']);
                exit();
            }
            
            $conn->begin_transaction();
            
            try {
                foreach ($updates as $update) {
                    $itemId = $update['item_id'];
                    $newQuantity = intval($update['quantity']);
                    $newStatus = $update['status'];
                    $prevQuantity = intval($update['previous_quantity']);
                    $prevStatus = $update['previous_status'];
                    
                    // Update items table
                    $updateSql = "UPDATE items 
                                  SET quantity = ?, status = ?, updated_at = NOW()
                                  WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("isi", $newQuantity, $newStatus, $itemId);
                    $updateStmt->execute();
                    
                    if ($updateStmt->affected_rows === 0) {
                        throw new Exception("Failed to update item ID: $itemId");
                    }
                    
                    $updateStmt->close();
                    
                    // Log quantity change
                    if ($newQuantity != $prevQuantity) {
                        // Get item name for logging
                        $itemNameSql = "SELECT name FROM items WHERE id = ?";
                        $itemNameStmt = $conn->prepare($itemNameSql);
                        $itemNameStmt->bind_param("i", $itemId);
                        $itemNameStmt->execute();
                        $itemNameResult = $itemNameStmt->get_result();
                        $itemName = $itemNameResult->fetch_assoc()['name'];
                        $itemNameStmt->close();
                        
                        // Check if inventory_logs table exists, if not create it
                        $checkTableSql = "CREATE TABLE IF NOT EXISTS `inventory_logs` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `item_table_id` int(11) NOT NULL,
                            `item_id` int(11) NOT NULL,
                            `item_name` varchar(200) NOT NULL,
                            `field_changed` varchar(50) NOT NULL,
                            `old_value` varchar(255) DEFAULT NULL,
                            `new_value` varchar(255) DEFAULT NULL,
                            `changed_by` int(11) DEFAULT NULL,
                            `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
                            `notes` text DEFAULT NULL,
                            PRIMARY KEY (`id`),
                            KEY `item_table_id` (`item_table_id`),
                            KEY `item_id` (`item_id`),
                            KEY `changed_by` (`changed_by`),
                            KEY `changed_at` (`changed_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                        $conn->query($checkTableSql);
                        
                        $logSql = "INSERT INTO inventory_logs 
                                   (item_table_id, item_id, item_name, field_changed, old_value, new_value, changed_by, changed_at)
                                   VALUES (?, ?, ?, 'quantity', ?, ?, ?, NOW())";
                        $logStmt = $conn->prepare($logSql);
                        $logStmt->bind_param("iisii", $itemTableId, $itemId, $itemName, $prevQuantity, $newQuantity, $userId);
                        $logStmt->execute();
                        $logStmt->close();
                    }
                    
                    // Log status change
                    if ($newStatus != $prevStatus) {
                        // Get item name for logging
                        $itemNameSql = "SELECT name FROM items WHERE id = ?";
                        $itemNameStmt = $conn->prepare($itemNameSql);
                        $itemNameStmt->bind_param("i", $itemId);
                        $itemNameStmt->execute();
                        $itemNameResult = $itemNameStmt->get_result();
                        $itemName = $itemNameResult->fetch_assoc()['name'];
                        $itemNameStmt->close();
                        
                        // Check if inventory_logs table exists
                        $checkTableSql = "CREATE TABLE IF NOT EXISTS `inventory_logs` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `item_table_id` int(11) NOT NULL,
                            `item_id` int(11) NOT NULL,
                            `item_name` varchar(200) NOT NULL,
                            `field_changed` varchar(50) NOT NULL,
                            `old_value` varchar(255) DEFAULT NULL,
                            `new_value` varchar(255) DEFAULT NULL,
                            `changed_by` int(11) DEFAULT NULL,
                            `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
                            `notes` text DEFAULT NULL,
                            PRIMARY KEY (`id`),
                            KEY `item_table_id` (`item_table_id`),
                            KEY `item_id` (`item_id`),
                            KEY `changed_by` (`changed_by`),
                            KEY `changed_at` (`changed_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                        $conn->query($checkTableSql);
                        
                        $logSql = "INSERT INTO inventory_logs 
                                   (item_table_id, item_id, item_name, field_changed, old_value, new_value, changed_by, changed_at)
                                   VALUES (?, ?, ?, 'status', ?, ?, ?, NOW())";
                        $logStmt = $conn->prepare($logSql);
                        $logStmt->bind_param("iisssi", $itemTableId, $itemId, $itemName, $prevStatus, $newStatus, $userId);
                        $logStmt->execute();
                        $logStmt->close();
                    }
                }
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Inventory updated successfully']);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?>


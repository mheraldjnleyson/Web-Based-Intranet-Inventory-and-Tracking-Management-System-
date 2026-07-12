<?php
session_start();
    require_once __DIR__ . '/../db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Initialize success and error messages
$success_message = '';
$error_message = '';

$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$isViewer = strcasecmp($userRole, 'viewer') === 0;
$userDepartment = isset($_SESSION['department']) ? $_SESSION['department'] : '';
// Department head: admin but not super admin
$isDepartmentHead = $isAdmin && !$isSuperAdmin;

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['ajax']) {
        case 'get_floors':
            if (!isset($_GET['building_id']) || empty($_GET['building_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Building ID is required']);
                exit;
            }
            
            $building_id = (int)$_GET['building_id'];
            
            if ($building_id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid building ID']);
                exit;
            }
            
            try {
                $sql = "SELECT f.*, COUNT(r.id) as room_count 
                       FROM floors f 
                       LEFT JOIN rooms r ON f.id = r.floor_id 
                       WHERE f.building_id = ? 
                       GROUP BY f.id 
                       ORDER BY f.floor_number ASC";
                $stmt = mysqli_prepare($conn, $sql);
                
                if (!$stmt) {
                    throw new Exception("Database prepare error: " . mysqli_error($conn));
                }
                
                mysqli_stmt_bind_param($stmt, "i", $building_id);
                
                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Database execute error: " . mysqli_stmt_error($stmt));
                }
                
                $result = mysqli_stmt_get_result($stmt);
                
                if (!$result) {
                    throw new Exception("Database result error: " . mysqli_stmt_error($stmt));
                }
                
                $floors = [];
                while ($row = mysqli_fetch_assoc($result)) {
                    $floors[] = [
                        'id' => (int)$row['id'],
                        'floor_number' => (int)$row['floor_number'],
                        'floor_name' => htmlspecialchars($row['floor_name'], ENT_QUOTES, 'UTF-8'),
                        'description' => htmlspecialchars($row['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'room_count' => (int)$row['room_count'],
                        'created_at' => $row['created_at']
                    ];
                }
                
                mysqli_stmt_close($stmt);
                echo json_encode($floors);
                exit;
                
            } catch (Exception $e) {
                error_log("Error in get_floors AJAX: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Internal server error']);
                exit;
            }
            break;
            
        case 'get_rooms':
    if (!isset($_GET['floor_id']) || empty($_GET['floor_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Floor ID is required']);
        exit;
    }
    
    $floor_id = (int)$_GET['floor_id'];
    
    try {
        $sql = "SELECT r.*, 
                   b.name as building_name,
                   f.floor_number,
                   (
                       SELECT COUNT(*) FROM items i
                       WHERE i.location = CONCAT(b.name, ', Floor ', f.floor_number, ', ', COALESCE(NULLIF(TRIM(r.room_name), ''), r.room_number))
                   ) AS item_count
               FROM rooms r 
               LEFT JOIN floors f ON r.floor_id = f.id
               LEFT JOIN buildings b ON f.building_id = b.id
               WHERE r.floor_id = ? 
               ORDER BY r.room_number";
        
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $floor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $rooms = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $rooms[] = [
                'id' => (int)$row['id'],
                'room_number' => htmlspecialchars($row['room_number'], ENT_QUOTES, 'UTF-8'),
                'room_name' => htmlspecialchars($row['room_name'], ENT_QUOTES, 'UTF-8'),
                'description' => htmlspecialchars($row['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                'capacity' => $row['capacity'] ? (int)$row['capacity'] : null,
                'item_count' => isset($row['item_count']) ? (int)$row['item_count'] : 0,
                'created_at' => $row['created_at']
            ];
        }
        
        echo json_encode($rooms);
        exit;
        
    } catch (Exception $e) {
        error_log("Error in get_rooms AJAX: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
        exit;
    }
    break;
            
        case 'get_items':
    if (!isset($_GET['room_id']) || empty($_GET['room_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Room ID is required']);
        exit;
    }
    
    $room_id = (int)$_GET['room_id'];
    
    try {
        // Get full location details (building, floor, room) to match with items location
        $room_sql = "SELECT 
                        r.room_name,
                        r.room_number,
                        f.floor_number,
                        b.name as building_name
                     FROM rooms r
                     LEFT JOIN floors f ON r.floor_id = f.id
                     LEFT JOIN buildings b ON f.building_id = b.id
                     WHERE r.id = ?";
        $room_stmt = mysqli_prepare($conn, $room_sql);
        mysqli_stmt_bind_param($room_stmt, "i", $room_id);
        mysqli_stmt_execute($room_stmt);
        $room_result = mysqli_stmt_get_result($room_stmt);
        $room_data = mysqli_fetch_assoc($room_result);
        
        if (!$room_data) {
            echo json_encode([]);
            exit;
        }
        
        // Construct full_location string exactly as stored in items.location
        // Format: "Building Name, Floor X, Room Name" or "Building Name, Floor X, Room Number"
        $room_display = !empty($room_data['room_name']) && trim($room_data['room_name']) !== '' ? $room_data['room_name'] : $room_data['room_number'];
        $full_location = $room_data['building_name'] . ', Floor ' . $room_data['floor_number'] . ', ' . $room_display;
        
        // Use exact match for location (items.location should exactly match the full_location)
        $location_search = mysqli_real_escape_string($conn, $full_location);
        
        if ($isSuperAdmin) {
            // Super admins see all items
            $sql = "SELECT i.*, d.name as department_name,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM borrow_history bh 
                            WHERE bh.item_id = i.id 
                            AND bh.status IN ('approved', 'active', 'overdue', 'received')
                        ) THEN 'Borrowed'
                        WHEN EXISTS (
                            SELECT 1 FROM item_tables it 
                            WHERE it.id = i.item_table_id 
                            AND COALESCE(it.is_consumable, 0) = 1
                        ) THEN 'Consumable'
                        ELSE COALESCE(i.status, 'Working')
                    END as display_status
                   FROM items i 
                   LEFT JOIN departments d ON i.department_id = d.id 
                   LEFT JOIN item_tables it ON i.item_table_id = it.id
                   WHERE i.location = ? 
                   ORDER BY i.name";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "s", $location_search);
        } else if (!empty($userDepartment)) {
            // Department heads/regular users only see items from their own department
            $sql = "SELECT i.*, d.name as department_name,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM borrow_history bh 
                            WHERE bh.item_id = i.id 
                            AND bh.status IN ('approved', 'active', 'overdue', 'received')
                        ) THEN 'Borrowed'
                        WHEN EXISTS (
                            SELECT 1 FROM item_tables it 
                            WHERE it.id = i.item_table_id 
                            AND COALESCE(it.is_consumable, 0) = 1
                        ) THEN 'Consumable'
                        ELSE COALESCE(i.status, 'Working')
                    END as display_status
                   FROM items i 
                   LEFT JOIN departments d ON i.department_id = d.id 
                   LEFT JOIN item_tables it ON i.item_table_id = it.id
                   WHERE i.location = ? 
                   AND (d.name = ? OR d.name IS NULL)
                   ORDER BY i.name";
            $stmt = mysqli_prepare($conn, $sql);
            $deptName = trim($userDepartment);
            mysqli_stmt_bind_param($stmt, "ss", $location_search, $deptName);
        } else {
            // Users without department see no items
            echo json_encode([]);
            exit;
        }
        
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $items = [];
        while ($row = mysqli_fetch_assoc($result)) {
            // Additional check: normalize department comparison
            $itemDept = isset($row['department_name']) ? trim($row['department_name']) : '';
            if (!$isSuperAdmin && !empty($userDepartment) && !empty($itemDept)) {
                if (strcasecmp($itemDept, trim($userDepartment)) !== 0) {
                    continue; // Skip items from other departments
                }
            }
            
                    $items[] = [
                        'id' => (int)$row['id'],
                        'name' => htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'),
                        'department_name' => htmlspecialchars($row['department_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'category_name' => htmlspecialchars($row['category'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'quantity' => (int)$row['quantity'],
                        'status' => htmlspecialchars($row['display_status'] ?? $row['status'] ?? 'Working', ENT_QUOTES, 'UTF-8'),
                        'description' => htmlspecialchars($row['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'created_at' => $row['created_at']
                    ];
        }
        
        echo json_encode($items);
        exit;
        
    } catch (Exception $e) {
        error_log("Error in get_items AJAX: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
        exit;
    }
    break;
            
        case 'get_item_details':
            if (!isset($_GET['item_id']) || empty($_GET['item_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Item ID is required']);
                exit;
            }
            
            $item_id = (int)$_GET['item_id'];
            
            try {
                // Simple query - just get item data with department name and display status
                $sql = "SELECT i.*, d.name as department_name,
                        CASE 
                            WHEN EXISTS (
                                SELECT 1 FROM borrow_history bh 
                                WHERE bh.item_id = i.id 
                                AND bh.status IN ('approved', 'active', 'overdue', 'received')
                            ) THEN 'Borrowed'
                            WHEN EXISTS (
                                SELECT 1 FROM item_tables it 
                                WHERE it.id = i.item_table_id 
                                AND COALESCE(it.is_consumable, 0) = 1
                            ) THEN 'Consumable'
                            ELSE COALESCE(i.status, 'Working')
                        END as display_status
                       FROM items i 
                       LEFT JOIN departments d ON i.department_id = d.id 
                       LEFT JOIN item_tables it ON i.item_table_id = it.id
                       WHERE i.id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $item_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($item = mysqli_fetch_assoc($result)) {
                    // Parse location string to extract building, floor, room
                    $location = $item['location'] ?? '';
                    $building_name = '';
                    $floor_name = '';
                    $room_name = '';
                    
                    // Try to parse location format: "Building, Floor X, Room Name" or "Building, Floor X, Room Number"
                    if (!empty($location)) {
                        $parts = explode(', ', $location);
                        if (count($parts) >= 3) {
                            $building_name = trim($parts[0]);
                            $floor_name = trim($parts[1]); // "Floor X"
                            $room_name = trim($parts[2]);
                        } else {
                            $room_name = $location; // Fallback to full location
                        }
                    }
                    
                    echo json_encode([
                        'id' => (int)$item['id'],
                        'name' => htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'),
                        'department_name' => htmlspecialchars($item['department_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'category_name' => htmlspecialchars($item['category'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'building_name' => htmlspecialchars($building_name, ENT_QUOTES, 'UTF-8'),
                        'floor_name' => htmlspecialchars($floor_name, ENT_QUOTES, 'UTF-8'),
                        'room_name' => htmlspecialchars($room_name, ENT_QUOTES, 'UTF-8'),
                        'location' => htmlspecialchars($location, ENT_QUOTES, 'UTF-8'),
                        'quantity' => (int)$item['quantity'],
                        'status' => htmlspecialchars($item['display_status'] ?? $item['status'] ?? 'Working', ENT_QUOTES, 'UTF-8'),
                        'description' => htmlspecialchars($item['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'created_at' => $item['created_at'],
                        'updated_at' => $item['updated_at']
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Item not found']);
                }
                exit;
                
            } catch (Exception $e) {
                error_log("Error in get_item_details AJAX: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
                exit;
            }
            break;
            
        case 'get_building_info':
            if (!isset($_GET['building_id']) || empty($_GET['building_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Building ID is required']);
                exit;
            }
            
            $building_id = (int)$_GET['building_id'];
            
            try {
                $sql = "SELECT * FROM buildings WHERE id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $building_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($building = mysqli_fetch_assoc($result)) {
                    echo json_encode([
                        'id' => (int)$building['id'],
                        'name' => htmlspecialchars($building['name'], ENT_QUOTES, 'UTF-8'),
                        'description' => htmlspecialchars($building['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'image_path' => $building['image_path'],
                        'date_built' => $building['date_built'],
                        'created_at' => $building['created_at']
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Building not found']);
                }
                exit;
                
            } catch (Exception $e) {
                error_log("Error in get_building_info AJAX: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Internal server error']);
                exit;
            }
            break;

        case 'get_building_details':
            if (!isset($_GET['building_id']) || empty($_GET['building_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Building ID is required']);
                exit;
            }
            
            $building_id = (int)$_GET['building_id'];
            
            try {
                $sql = "SELECT b.*, 
                               COUNT(DISTINCT f.id) as floor_count,
                               COUNT(DISTINCT r.id) as room_count
                        FROM buildings b
                        LEFT JOIN floors f ON b.id = f.building_id
                        LEFT JOIN rooms r ON f.id = r.floor_id
                        WHERE b.id = ?
                        GROUP BY b.id";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $building_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($building = mysqli_fetch_assoc($result)) {
                    // Get floors with rooms
                    $floors_sql = "SELECT f.*, COUNT(r.id) as room_count
                                  FROM floors f
                                  LEFT JOIN rooms r ON f.id = r.floor_id
                                  WHERE f.building_id = ?
                                  GROUP BY f.id
                                  ORDER BY f.floor_number";
                    $floors_stmt = mysqli_prepare($conn, $floors_sql);
                    mysqli_stmt_bind_param($floors_stmt, "i", $building_id);
                    mysqli_stmt_execute($floors_stmt);
                    $floors_result = mysqli_stmt_get_result($floors_stmt);
                    
                    $floors = [];
                    while ($floor = mysqli_fetch_assoc($floors_result)) {
                        // Get rooms for each floor
                        $rooms_sql = "SELECT * FROM rooms WHERE floor_id = ? ORDER BY room_number";
                        $rooms_stmt = mysqli_prepare($conn, $rooms_sql);
                        mysqli_stmt_bind_param($rooms_stmt, "i", $floor['id']);
                        mysqli_stmt_execute($rooms_stmt);
                        $rooms_result = mysqli_stmt_get_result($rooms_stmt);
                        
                        $rooms = [];
                        while ($room = mysqli_fetch_assoc($rooms_result)) {
                            $rooms[] = $room;
                        }
                        
                        $floor['rooms'] = $rooms;
                        $floors[] = $floor;
                    }
                    
                    $building['floors'] = $floors;
                    echo json_encode($building);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Building not found']);
                }
                
            } catch (Exception $e) {
                error_log("Error in get_building_details AJAX: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Internal server error']);
            }
            exit;
            break;

        case 'get_floor_details':
            try {
                if (!isset($_GET['floor_id']) || empty($_GET['floor_id'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Floor ID is required']);
                    exit;
                }
                
                $floor_id = (int)$_GET['floor_id'];
                
                $sql = "SELECT f.*, b.name as building_name FROM floors f 
                        JOIN buildings b ON f.building_id = b.id 
                        WHERE f.id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $floor_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($floor = mysqli_fetch_assoc($result)) {
                    echo json_encode($floor);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Floor not found']);
                }
                
            } catch (Exception $e) {
                error_log("Error in get_floor_details AJAX: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Internal server error']);
            }
            exit;
            break;

        case 'get_room_details':
            try {
                if (!isset($_GET['room_id']) || empty($_GET['room_id'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Room ID is required']);
                    exit;
                }
                
                $room_id = (int)$_GET['room_id'];
                
                $sql = "SELECT r.*, f.floor_name, b.name as building_name 
                        FROM rooms r 
                        JOIN floors f ON r.floor_id = f.id 
                        JOIN buildings b ON f.building_id = b.id 
                        WHERE r.id = ?";
                $stmt = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($stmt, "i", $room_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($room = mysqli_fetch_assoc($result)) {
                    echo json_encode($room);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Room not found']);
                }
                
            } catch (Exception $e) {
                error_log("Error in get_room_details AJAX: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Internal server error']);
            }
            exit;
            break;

        case 'get_room_items_for_scan':
            if (!isset($_GET['room_id']) || empty($_GET['room_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Room ID is required']);
                exit;
            }
            
            $room_id = (int)$_GET['room_id'];
            
            try {
                // Get full location details (building, floor, room) to match with items location
                $room_sql = "SELECT 
                                r.room_name,
                                r.room_number,
                                f.floor_number,
                                b.name as building_name
                             FROM rooms r
                             LEFT JOIN floors f ON r.floor_id = f.id
                             LEFT JOIN buildings b ON f.building_id = b.id
                             WHERE r.id = ?";
                $room_stmt = mysqli_prepare($conn, $room_sql);
                mysqli_stmt_bind_param($room_stmt, "i", $room_id);
                mysqli_stmt_execute($room_stmt);
                $room_result = mysqli_stmt_get_result($room_stmt);
                $room_data = mysqli_fetch_assoc($room_result);
                
                if (!$room_data) {
                    echo json_encode(['error' => 'Room not found']);
                    exit;
                }
                
                // Construct full_location string exactly as stored in items.location
                // Format: "Building Name, Floor X, Room Name" or "Building Name, Floor X, Room Number"
                $room_display = !empty($room_data['room_name']) && trim($room_data['room_name']) !== '' ? $room_data['room_name'] : $room_data['room_number'];
                $full_location = $room_data['building_name'] . ', Floor ' . $room_data['floor_number'] . ', ' . $room_display;
                
                // Use exact match for location (items.location should exactly match the full_location)
                $location_search = mysqli_real_escape_string($conn, $full_location);
                
                if ($isSuperAdmin) {
                    // Super admins see all items
                    $sql = "SELECT i.id, i.item_code, i.name, i.status, d.name as department_name,
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM borrow_history bh 
                                    WHERE bh.item_id = i.id 
                                    AND bh.status IN ('approved', 'active', 'overdue', 'received')
                                ) THEN 'Borrowed'
                                WHEN EXISTS (
                                    SELECT 1 FROM item_tables it 
                                    WHERE it.id = i.item_table_id 
                                    AND COALESCE(it.is_consumable, 0) = 1
                                ) THEN 'Consumable'
                                ELSE COALESCE(i.status, 'Working')
                            END as display_status
                           FROM items i 
                           LEFT JOIN departments d ON i.department_id = d.id 
                           LEFT JOIN item_tables it ON i.item_table_id = it.id
                           WHERE i.location = ? 
                           AND i.id NOT IN (
                               SELECT item_id 
                               FROM borrow_history 
                               WHERE item_id = i.id 
                               AND status IN ('active', 'overdue')
                           )
                           ORDER BY i.name";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "s", $location_search);
                } else if (!empty($userDepartment)) {
                    // Department heads/regular users only see items from their own department
                    $sql = "SELECT i.id, i.item_code, i.name, i.status, d.name as department_name,
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM borrow_history bh 
                                    WHERE bh.item_id = i.id 
                                    AND bh.status IN ('approved', 'active', 'overdue', 'received')
                                ) THEN 'Borrowed'
                                WHEN EXISTS (
                                    SELECT 1 FROM item_tables it 
                                    WHERE it.id = i.item_table_id 
                                    AND COALESCE(it.is_consumable, 0) = 1
                                ) THEN 'Consumable'
                                ELSE COALESCE(i.status, 'Working')
                            END as display_status
                           FROM items i 
                           LEFT JOIN departments d ON i.department_id = d.id 
                           LEFT JOIN item_tables it ON i.item_table_id = it.id
                           WHERE i.location = ? 
                           AND (d.name = ? OR d.name IS NULL)
                           AND i.id NOT IN (
                               SELECT item_id 
                               FROM borrow_history 
                               WHERE item_id = i.id 
                               AND status IN ('active', 'overdue')
                           )
                           ORDER BY i.name";
                    $stmt = mysqli_prepare($conn, $sql);
                    $deptName = trim($userDepartment);
                    mysqli_stmt_bind_param($stmt, "ss", $location_search, $deptName);
                } else {
                    // Users without department see no items
                    echo json_encode(['success' => true, 'items' => []]);
                    exit;
                }
                
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                $items = [];
                while ($row = mysqli_fetch_assoc($result)) {
                    // Additional check: normalize department comparison
                    $itemDept = isset($row['department_name']) ? trim($row['department_name']) : '';
                    if (!$isSuperAdmin && !empty($userDepartment) && !empty($itemDept)) {
                        if (strcasecmp($itemDept, trim($userDepartment)) !== 0) {
                            continue; // Skip items from other departments
                        }
                    }
                    
                    $items[] = [
                        'id' => (int)$row['id'],
                        'item_code' => htmlspecialchars($row['item_code'] ?? '', ENT_QUOTES, 'UTF-8'),
                        'name' => htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'),
                        'status' => htmlspecialchars($row['display_status'] ?? $row['status'] ?? 'Working', ENT_QUOTES, 'UTF-8')
                    ];
                }
                
                echo json_encode(['success' => true, 'items' => $items]);
                exit;
                
            } catch (Exception $e) {
                error_log("Error in get_room_items_for_scan AJAX: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Internal server error']);
                exit;
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid AJAX request']);
            exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_building':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage buildings.";
                    break;
                }
                try {
                    // Validate required fields
                    if (empty(trim($_POST['building_name']))) {
                        throw new Exception("Building name is required.");
                    }
                    
                    $name = trim($_POST['building_name']);
                    $description = trim($_POST['building_description'] ?? '');
                    $date_built = !empty($_POST['date_built']) ? $_POST['date_built'] : null;
                    
                    // Handle image upload
                    $image_path = '';
                    if (isset($_FILES['building_image']) && $_FILES['building_image']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = 'uploads/buildings/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        // Validate file type
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $file_type = $_FILES['building_image']['type'];
                        
                        if (!in_array($file_type, $allowed_types)) {
                            throw new Exception("Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.");
                        }
                        
                        // Check file size (5MB limit)
                        if ($_FILES['building_image']['size'] > 5 * 1024 * 1024) {
                            throw new Exception("File size must be less than 5MB.");
                        }
                        
                        $file_extension = strtolower(pathinfo($_FILES['building_image']['name'], PATHINFO_EXTENSION));
                        $new_filename = uniqid('building_', true) . '.' . $file_extension;
                        $target_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES['building_image']['tmp_name'], $target_path)) {
                            $image_path = $target_path;
                        } else {
                            throw new Exception("Failed to upload image.");
                        }
                    }
                    
                    // Check if building name already exists
                    $check_sql = "SELECT id FROM buildings WHERE name = ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    if (!$check_stmt) {
                        throw new Exception("Database error: " . mysqli_error($conn));
                    }
                    
                    mysqli_stmt_bind_param($check_stmt, "s", $name);
                    mysqli_stmt_execute($check_stmt);
                    $result = mysqli_stmt_get_result($check_stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("A building with this name already exists.");
                    }
                    
                    // Insert new building
                    $sql = "INSERT INTO buildings (name, description, image_path, date_built, created_at) VALUES (?, ?, ?, ?, NOW())";
                    $stmt = mysqli_prepare($conn, $sql);
                    if (!$stmt) {
                        throw new Exception("Database error: " . mysqli_error($conn));
                    }
                    
                    mysqli_stmt_bind_param($stmt, "ssss", $name, $description, $image_path, $date_built);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_message = "Building added successfully!";
                    } else {
                        throw new Exception("Failed to add building: " . mysqli_stmt_error($stmt));
                    }
                    
                    mysqli_stmt_close($stmt);
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;
                
            case 'add_floor':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage floors.";
                    break;
                }
                try {
                    // Validate required fields
                    if (empty($_POST['building_id']) || empty($_POST['floor_number']) || empty(trim($_POST['floor_name']))) {
                        throw new Exception("Building, floor number, and floor name are required.");
                    }
                    
                    $building_id = (int)$_POST['building_id'];
                    $floor_number = (int)$_POST['floor_number'];
                    $floor_name = trim($_POST['floor_name']);
                    $description = trim($_POST['floor_description'] ?? '');
                    
                    // Check if building exists
                    $building_check = "SELECT id FROM buildings WHERE id = ?";
                    $building_stmt = mysqli_prepare($conn, $building_check);
                    mysqli_stmt_bind_param($building_stmt, "i", $building_id);
                    mysqli_stmt_execute($building_stmt);
                    $building_result = mysqli_stmt_get_result($building_stmt);
                    
                    if (mysqli_num_rows($building_result) === 0) {
                        throw new Exception("Selected building does not exist.");
                    }
                    
                    // Check if floor number already exists in this building
                    $check_sql = "SELECT id FROM floors WHERE building_id = ? AND floor_number = ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    mysqli_stmt_bind_param($check_stmt, "ii", $building_id, $floor_number);
                    mysqli_stmt_execute($check_stmt);
                    $result = mysqli_stmt_get_result($check_stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Floor number {$floor_number} already exists in this building.");
                    }
                    
                    // Insert new floor
                    $sql = "INSERT INTO floors (building_id, floor_number, floor_name, description, created_at) VALUES (?, ?, ?, ?, NOW())";
                    $stmt = mysqli_prepare($conn, $sql);
                    if (!$stmt) {
                        throw new Exception("Database error: " . mysqli_error($conn));
                    }
                    
                    mysqli_stmt_bind_param($stmt, "iiss", $building_id, $floor_number, $floor_name, $description);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_message = "Floor added successfully!";
                    } else {
                        throw new Exception("Failed to add floor: " . mysqli_stmt_error($stmt));
                    }
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;
                
            case 'add_room':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage rooms.";
                    break;
                }
                try {
                    // Validate required fields
                    if (empty($_POST['floor_id']) || empty(trim($_POST['room_number']))) {
                        throw new Exception("Floor and room number are required.");
                    }
                    
                    $floor_id = (int)$_POST['floor_id'];
                    $room_number = trim($_POST['room_number']);
                    $room_name = !empty($_POST['room_name']) ? trim($_POST['room_name']) : null;
                    $description = trim($_POST['room_description'] ?? '');
                    
                    // Check if floor exists
                    $floor_check = "SELECT id FROM floors WHERE id = ?";
                    $floor_stmt = mysqli_prepare($conn, $floor_check);
                    mysqli_stmt_bind_param($floor_stmt, "i", $floor_id);
                    mysqli_stmt_execute($floor_stmt);
                    $floor_result = mysqli_stmt_get_result($floor_stmt);
                    
                    if (mysqli_num_rows($floor_result) === 0) {
                        throw new Exception("Selected floor does not exist.");
                    }
                    
                    // Check if room number already exists on this floor
                    $check_sql = "SELECT id FROM rooms WHERE floor_id = ? AND room_number = ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    mysqli_stmt_bind_param($check_stmt, "is", $floor_id, $room_number);
                    mysqli_stmt_execute($check_stmt);
                    $result = mysqli_stmt_get_result($check_stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Room number {$room_number} already exists on this floor.");
                    }
                    
                    // Insert new room
                    $sql = "INSERT INTO rooms (floor_id, room_number, room_name, description, created_at) VALUES (?, ?, ?, ?, NOW())";
                    $stmt = mysqli_prepare($conn, $sql);
                    if (!$stmt) {
                        throw new Exception("Database error: " . mysqli_error($conn));
                    }
                    
                    mysqli_stmt_bind_param($stmt, "isss", $floor_id, $room_number, $room_name, $description);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_message = "Room added successfully!";
                    } else {
                        throw new Exception("Failed to add room: " . mysqli_stmt_error($stmt));
                    }
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;

            case 'edit_building':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage buildings.";
                    break;
                }
                try {
                    if (empty($_POST['building_id']) || empty(trim($_POST['building_name']))) {
                        throw new Exception("Building ID and name are required.");
                    }
                    
                    $building_id = (int)$_POST['building_id'];
                    $name = trim($_POST['building_name']);
                    $description = trim($_POST['building_description'] ?? '');
                    $date_built = !empty($_POST['date_built']) ? $_POST['date_built'] : null;
                    
                    // Handle image upload if new image is provided
                    $image_path = '';
                    $update_image = false;
                    if (isset($_FILES['building_image']) && $_FILES['building_image']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = 'uploads/buildings/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        // Validate file type and size (same as add building)
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $file_type = $_FILES['building_image']['type'];
                        
                        if (!in_array($file_type, $allowed_types)) {
                            throw new Exception("Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.");
                        }
                        
                        if ($_FILES['building_image']['size'] > 5 * 1024 * 1024) {
                            throw new Exception("File size must be less than 5MB.");
                        }
                        
                        $file_extension = strtolower(pathinfo($_FILES['building_image']['name'], PATHINFO_EXTENSION));
                        $new_filename = uniqid('building_', true) . '.' . $file_extension;
                        $target_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES['building_image']['tmp_name'], $target_path)) {
                            $image_path = $target_path;
                            $update_image = true;
                        } else {
                            throw new Exception("Failed to upload image.");
                        }
                    }
                    
                    // Get old building name
                    $old_building_sql = "SELECT name FROM buildings WHERE id = ?";
                    $old_building_stmt = mysqli_prepare($conn, $old_building_sql);
                    mysqli_stmt_bind_param($old_building_stmt, "i", $building_id);
                    mysqli_stmt_execute($old_building_stmt);
                    $old_building_result = mysqli_stmt_get_result($old_building_stmt);
                    $old_building = mysqli_fetch_assoc($old_building_result);
                    
                    if (!$old_building) {
                        throw new Exception("Building not found.");
                    }
                    
                    $old_building_name = $old_building['name'];
                    
                    // Check if building name already exists (excluding current building)
                    $check_sql = "SELECT id FROM buildings WHERE name = ? AND id != ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    mysqli_stmt_bind_param($check_stmt, "si", $name, $building_id);
                    mysqli_stmt_execute($check_stmt);
                    $result = mysqli_stmt_get_result($check_stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("A building with this name already exists.");
                    }
                    
                    // Update building
                    if ($update_image) {
                        $sql = "UPDATE buildings SET name = ?, description = ?, image_path = ?, date_built = ? WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $sql);
                        mysqli_stmt_bind_param($stmt, "ssssi", $name, $description, $image_path, $date_built, $building_id);
                    } else {
                        $sql = "UPDATE buildings SET name = ?, description = ?, date_built = ? WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $sql);
                        mysqli_stmt_bind_param($stmt, "sssi", $name, $description, $date_built, $building_id);
                    }
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Update all items that have the old building name in their location
                        $update_items_sql = "UPDATE items SET location = REPLACE(location, ?, ?) WHERE location LIKE ?";
                        $update_items_stmt = mysqli_prepare($conn, $update_items_sql);
                        $old_pattern = $old_building_name . ', %';
                        $old_prefix = $old_building_name . ', ';
                        $new_prefix = $name . ', ';
                        mysqli_stmt_bind_param($update_items_stmt, "sss", $old_prefix, $new_prefix, $old_pattern);
                        mysqli_stmt_execute($update_items_stmt);
                        
                        $success_message = "Building updated successfully!";
                    } else {
                        throw new Exception("Failed to update building: " . mysqli_stmt_error($stmt));
                    }
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;

            case 'delete_building':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage buildings.";
                    break;
                }
                try {
                    if (empty($_POST['building_id'])) {
                        throw new Exception("Building ID is required.");
                    }
                    
                    $building_id = (int)$_POST['building_id'];
                    
                    // Check if building has floors/rooms
                    $check_sql = "SELECT COUNT(*) as count FROM floors WHERE building_id = ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    mysqli_stmt_bind_param($check_stmt, "i", $building_id);
                    mysqli_stmt_execute($check_stmt);
                    $result = mysqli_stmt_get_result($check_stmt);
                    $floor_count = mysqli_fetch_assoc($result)['count'];
                    
                    if ($floor_count > 0) {
                        throw new Exception("Cannot delete building with existing floors. Please delete all floors first.");
                    }
                    
                    // Get image path before deletion
                    $image_sql = "SELECT image_path FROM buildings WHERE id = ?";
                    $image_stmt = mysqli_prepare($conn, $image_sql);
                    mysqli_stmt_bind_param($image_stmt, "i", $building_id);
                    mysqli_stmt_execute($image_stmt);
                    $image_result = mysqli_stmt_get_result($image_stmt);
                    $image_data = mysqli_fetch_assoc($image_result);
                    
                    // Delete building
                    $sql = "DELETE FROM buildings WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "i", $building_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Delete image file if exists
                        if ($image_data['image_path'] && file_exists($image_data['image_path'])) {
                            unlink($image_data['image_path']);
                        }
                        $success_message = "Building deleted successfully!";
                    } else {
                        throw new Exception("Failed to delete building: " . mysqli_stmt_error($stmt));
                    }
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;

            case 'edit_floor':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage floors.";
                    break;
                }
                try {
                    if (empty($_POST['floor_id']) || empty(trim($_POST['floor_name']))) {
                        throw new Exception("Floor ID and name are required.");
                    }
                    
                    $floor_id = (int)$_POST['floor_id'];
                    $floor_name = trim($_POST['floor_name']);
                    $floor_number = !empty($_POST['floor_number']) ? (int)$_POST['floor_number'] : null;
                    $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
                    
                    // Get old floor data and building info
                    $old_floor_sql = "SELECT f.floor_number, f.floor_name, b.name as building_name, b.id as building_id
                                     FROM floors f
                                     JOIN buildings b ON f.building_id = b.id
                                     WHERE f.id = ?";
                    $old_floor_stmt = mysqli_prepare($conn, $old_floor_sql);
                    mysqli_stmt_bind_param($old_floor_stmt, "i", $floor_id);
                    mysqli_stmt_execute($old_floor_stmt);
                    $old_floor_result = mysqli_stmt_get_result($old_floor_stmt);
                    $old_floor = mysqli_fetch_assoc($old_floor_result);
                    
                    if (!$old_floor) {
                        throw new Exception("Floor not found.");
                    }
                    
                    $old_floor_number = $old_floor['floor_number'];
                    $old_floor_pattern = 'Floor ' . $old_floor_number;
                    $new_floor_pattern = 'Floor ' . ($floor_number ?: $old_floor_number);
                    
                    // Update floor
                    $sql = "UPDATE floors SET floor_name = ?, floor_number = ?, description = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "sisi", $floor_name, $floor_number, $description, $floor_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Update all items that have this floor in their location
                        $update_items_sql = "UPDATE items SET location = REPLACE(location, ?, ?) WHERE location LIKE ?";
                        $update_items_stmt = mysqli_prepare($conn, $update_items_sql);
                        $location_pattern = $old_floor['building_name'] . ', ' . $old_floor_pattern . ', %';
                        mysqli_stmt_bind_param($update_items_stmt, "sss", $old_floor_pattern, $new_floor_pattern, $location_pattern);
                        mysqli_stmt_execute($update_items_stmt);
                        
                        $success_message = "Floor updated successfully!";
                    } else {
                        throw new Exception("Failed to update floor: " . mysqli_stmt_error($stmt));
                    }
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;

            case 'edit_room':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage rooms.";
                    break;
                }
                try {
                    if (empty($_POST['room_id'])) {
                        throw new Exception("Room ID is required.");
                    }
                    
                    $room_id = (int)$_POST['room_id'];
                    $room_name = !empty($_POST['room_name']) ? trim($_POST['room_name']) : null;
                    $room_number = !empty($_POST['room_number']) ? trim($_POST['room_number']) : null;
                    $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
                    
                    // Get old room data and related building/floor info
                    $old_room_sql = "SELECT r.room_name, r.room_number, f.floor_number, f.id as floor_id, b.name as building_name, b.id as building_id
                                    FROM rooms r
                                    JOIN floors f ON r.floor_id = f.id
                                    JOIN buildings b ON f.building_id = b.id
                                    WHERE r.id = ?";
                    $old_stmt = mysqli_prepare($conn, $old_room_sql);
                    mysqli_stmt_bind_param($old_stmt, "i", $room_id);
                    mysqli_stmt_execute($old_stmt);
                    $old_result = mysqli_stmt_get_result($old_stmt);
                    $old_room = mysqli_fetch_assoc($old_result);
                    
                    if (!$old_room) {
                        throw new Exception("Room not found.");
                    }
                    
                    $old_room_name = $old_room['room_name'];
                    $old_full_location = $old_room['building_name'] . ', Floor ' . $old_room['floor_number'] . ', ' . ($old_room['room_name'] ?: $old_room['room_number']);
                    
                    // Update room
                    $sql = "UPDATE rooms SET room_name = ?, room_number = ?, description = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "sssi", $room_name, $room_number, $description, $room_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Build new full location
                        $new_full_location = $old_room['building_name'] . ', Floor ' . $old_room['floor_number'] . ', ' . ($room_name ?: $room_number);
                        
                        // Update all items that have the old location
                        $update_items_sql = "UPDATE items SET location = REPLACE(location, ?, ?) WHERE location LIKE ?";
                        $update_items_stmt = mysqli_prepare($conn, $update_items_sql);
                        $old_location_pattern = '%' . mysqli_real_escape_string($conn, $old_full_location) . '%';
                        mysqli_stmt_bind_param($update_items_stmt, "sss", $old_full_location, $new_full_location, $old_location_pattern);
                        mysqli_stmt_execute($update_items_stmt);
                        
                        $success_message = "Room updated successfully!";
                    } else {
                        throw new Exception("Failed to update room: " . mysqli_stmt_error($stmt));
                    }
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;

            case 'delete_floor':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage floors.";
                    break;
                }
                try {
                    if (empty($_POST['floor_id'])) {
                        throw new Exception("Floor ID is required.");
                    }
                    
                    $floor_id = (int)$_POST['floor_id'];
                    
                    // Check if floor has rooms
                    $check_sql = "SELECT COUNT(*) as count FROM rooms WHERE floor_id = ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    mysqli_stmt_bind_param($check_stmt, "i", $floor_id);
                    mysqli_stmt_execute($check_stmt);
                    $result = mysqli_stmt_get_result($check_stmt);
                    $room_count = mysqli_fetch_assoc($result)['count'];
                    
                    if ($room_count > 0) {
                        throw new Exception("Cannot delete floor with existing rooms. Please delete all rooms first.");
                    }
                    
                    $sql = "DELETE FROM floors WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "i", $floor_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_message = "Floor deleted successfully!";
                    } else {
                        throw new Exception("Failed to delete floor: " . mysqli_stmt_error($stmt));
                    }
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;

            case 'delete_room':
                if (!$isSuperAdmin) {
                    $error_message = "You do not have permission to manage rooms.";
                    break;
                }
                try {
                    if (empty($_POST['room_id'])) {
                        throw new Exception("Room ID is required.");
                    }
                    
                    $room_id = (int)$_POST['room_id'];
                    
                    // Check if room has items by checking location field
                    $room_sql = "SELECT room_name FROM rooms WHERE id = ?";
                    $room_stmt = mysqli_prepare($conn, $room_sql);
                    
                    if (!$room_stmt) {
                        throw new Exception("Database error: " . mysqli_error($conn));
                    }
                    
                    mysqli_stmt_bind_param($room_stmt, "i", $room_id);
                    mysqli_stmt_execute($room_stmt);
                    $room_result = mysqli_stmt_get_result($room_stmt);
                    $room_data = mysqli_fetch_assoc($room_result);
                    
                    if (!$room_data) {
                        throw new Exception("Room not found.");
                    }
                    
                    $room_name = $room_data['room_name'];
                    
                    // Check if any items are in this room by location
                    $check_sql = "SELECT COUNT(*) as count FROM items WHERE location LIKE ?";
                    $check_stmt = mysqli_prepare($conn, $check_sql);
                    
                    if (!$check_stmt) {
                        throw new Exception("Database error: " . mysqli_error($conn));
                    }
                    
                    $location_pattern = "%{$room_name}%";
                    mysqli_stmt_bind_param($check_stmt, "s", $location_pattern);
                    mysqli_stmt_execute($check_stmt);
                    $result = mysqli_stmt_get_result($check_stmt);
                    $item_count = mysqli_fetch_assoc($result)['count'];
                    
                    if ($item_count > 0) {
                        throw new Exception("Cannot delete room with existing items. Please move or delete all items first.");
                    }
                    
                    $sql = "DELETE FROM rooms WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $sql);
                    
                    if (!$stmt) {
                        throw new Exception("Database error: " . mysqli_error($conn));
                    }
                    
                    mysqli_stmt_bind_param($stmt, "i", $room_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_message = "Room deleted successfully!";
                    } else {
                        throw new Exception("Failed to delete room: " . mysqli_stmt_error($stmt));
                    }
                    
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;
                
            case 'complete_room_scan':
                header('Content-Type: application/json');
                try {
                    if (!isset($_POST['room_id']) || empty($_POST['room_id']) || !isset($_POST['scanned_item_ids'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Missing required parameters']);
                        exit;
                    }
                    
                    $room_id = (int)$_POST['room_id'];
                    $scanned_ids = json_decode($_POST['scanned_item_ids'], true);
                    
                    if (!is_array($scanned_ids)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid scanned items data']);
                        exit;
                    }
                    
                    // Get full location details (building, floor, room) to match with items location
                    $room_sql = "SELECT 
                                    r.room_name,
                                    r.room_number,
                                    f.floor_number,
                                    b.name as building_name
                                 FROM rooms r
                                 LEFT JOIN floors f ON r.floor_id = f.id
                                 LEFT JOIN buildings b ON f.building_id = b.id
                                 WHERE r.id = ?";
                    $room_stmt = mysqli_prepare($conn, $room_sql);
                    mysqli_stmt_bind_param($room_stmt, "i", $room_id);
                    mysqli_stmt_execute($room_stmt);
                    $room_result = mysqli_stmt_get_result($room_stmt);
                    $room_data = mysqli_fetch_assoc($room_result);
                    
                    if (!$room_data) {
                        echo json_encode(['error' => 'Room not found']);
                        exit;
                    }
                    
                    // Construct full_location string exactly as stored in items.location
                    // Format: "Building Name, Floor X, Room Name" or "Building Name, Floor X, Room Number"
                    $room_display = !empty($room_data['room_name']) && trim($room_data['room_name']) !== '' ? $room_data['room_name'] : $room_data['room_number'];
                    $full_location = $room_data['building_name'] . ', Floor ' . $room_data['floor_number'] . ', ' . $room_display;
                    
                    // Convert scanned IDs to integers and sanitize
                    $scanned_ids = array_map('intval', $scanned_ids);
                    $scanned_ids_str = implode(',', $scanned_ids);
                    // Use exact match for location (items.location should exactly match the full_location)
                    $location_search = mysqli_real_escape_string($conn, $full_location);
                    
                    // Check permissions: verify all scanned items belong to user's department (if not super admin)
                    if (!$isSuperAdmin && !empty($userDepartment)) {
                        if (!empty($scanned_ids)) {
                            $check_sql = "SELECT i.id, d.name as department_name
                                         FROM items i 
                                         LEFT JOIN departments d ON i.department_id = d.id 
                                         WHERE i.id IN ($scanned_ids_str)";
                            $check_result = $conn->query($check_sql);
                            while ($row = mysqli_fetch_assoc($check_result)) {
                                $itemDept = isset($row['department_name']) ? trim($row['department_name']) : '';
                                if (!empty($itemDept) && strcasecmp($itemDept, trim($userDepartment)) !== 0) {
                                    echo json_encode(['error' => 'You can only scan items from your own department']);
                                    exit;
                                }
                            }
                        }
                    }
                    
                    // Get all item IDs in this room excluding borrowed items
                    // Filter by department if not super admin
                    if ($isSuperAdmin) {
                        $all_items_sql = "SELECT i.id FROM items i 
                                          WHERE i.location = '$location_search'
                                          AND i.id NOT IN (
                                              SELECT item_id 
                                              FROM borrow_history 
                                              WHERE status IN ('active', 'overdue')
                                          )";
                    } else if (!empty($userDepartment)) {
                        $deptName = mysqli_real_escape_string($conn, trim($userDepartment));
                        $all_items_sql = "SELECT i.id FROM items i 
                                          LEFT JOIN departments d ON i.department_id = d.id 
                                          WHERE i.location = '$location_search'
                                          AND (d.name = '$deptName' OR d.name IS NULL)
                                          AND i.id NOT IN (
                                              SELECT item_id 
                                              FROM borrow_history 
                                              WHERE status IN ('active', 'overdue')
                                          )";
                    } else {
                        $all_items_sql = "SELECT id FROM items WHERE 1=0"; // No items for users without department
                    }
                    
                    $all_items_result = $conn->query($all_items_sql);
                    $all_item_ids = [];
                    while ($row = mysqli_fetch_assoc($all_items_result)) {
                        $all_item_ids[] = (int)$row['id'];
                    }
                    
                    // Determine which items are missing
                    $missing_ids = array_diff($all_item_ids, $scanned_ids);
                    
                    // Update missing items to "Missing" (only items from user's department)
                    if (count($missing_ids) > 0) {
                        $missing_ids_str = implode(',', array_map('intval', $missing_ids));
                        $update_missing_sql = "UPDATE items SET status = 'Missing', updated_at = NOW() 
                                           WHERE id IN ($missing_ids_str)";
                        $conn->query($update_missing_sql);
                    }
                    
                    // Reset all scanned items to "Working" status
                    if (count($scanned_ids) > 0) {
                        $update_scanned_sql = "UPDATE items SET status = 'Working', updated_at = NOW() 
                                             WHERE id IN ($scanned_ids_str)";
                        $conn->query($update_scanned_sql);
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Scan completed successfully']);
                    exit;
                    
                } catch (Exception $e) {
                    error_log("Error in complete_room_scan: " . $e->getMessage());
                    http_response_code(500);
                    echo json_encode(['error' => 'Internal server error']);
                    exit;
                }
                break;
                
            default:
                $error_message = "Invalid action.";
        }
        
        // Redirect to prevent form resubmission
        if (!empty($success_message) || !empty($error_message)) {
            $_SESSION['success_message'] = $success_message;
            $_SESSION['error_message'] = $error_message;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Get messages from session and clear them
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Fetch buildings with floor and room counts for the main grid view
$buildings_query = "
    SELECT b.*, 
           COUNT(DISTINCT f.id) as floor_count,
           COUNT(DISTINCT r.id) as room_count
    FROM buildings b
    LEFT JOIN floors f ON b.id = f.building_id
    LEFT JOIN rooms r ON f.id = r.floor_id
    GROUP BY b.id, b.name, b.description, b.image_path, b.date_built, b.created_at
    ORDER BY b.name
";
$buildings_result = mysqli_query($conn, $buildings_query);

if (!$buildings_result) {
    die("Database error: " . mysqli_error($conn));
}

// Fetch all buildings for dropdowns
$all_buildings_query = "SELECT id, name FROM buildings ORDER BY name";
$all_buildings = mysqli_query($conn, $all_buildings_query);

if (!$all_buildings) {
    die("Database error: " . mysqli_error($conn));
}

// Get total counts
$total_buildings_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM buildings");
$total_buildings = $total_buildings_result ? mysqli_fetch_assoc($total_buildings_result)['count'] : 0;

$total_floors_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM floors");
$total_floors = $total_floors_result ? mysqli_fetch_assoc($total_floors_result)['count'] : 0;

$total_rooms_result = mysqli_query($conn, "SELECT COUNT(*) as count FROM rooms");
$total_rooms = $total_rooms_result ? mysqli_fetch_assoc($total_rooms_result)['count'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>OCABIS Location</title>
    <link rel="stylesheet" href="Css/location.css">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <script src="js/session_monitor.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="modal.js"></script>
    <style>
        /* Mobile responsive adjustments (375px - 768px) */
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

            /* Hide mobile inline toggle - we only use fixed toggle on left */
            #sidebarToggleMobile,
            .sidebar-toggle-mobile-inline {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }

            /* Slide sidebar in/out on mobile */
            .sidebar { 
                transform: translateX(-100%); 
                transition: transform 0.3s ease;
                z-index: 1200;
                width: 250px !important;
            }
            
            .sidebar.open { 
                transform: translateX(0); 
            }

            /* Content should be full width */
            .main-content { 
                margin-left: 0 !important; 
                padding: 10px !important;
            }

            /* Header mobile adjustments */
            .header {
                flex-direction: column;
                gap: 10px;
            }

            .add-button {
                width: 100%;
                justify-content: center;
            }

            /* Content area mobile */
            .content-area {
                padding: 10px !important;
            }

            /* Buildings grid mobile */
            .buildings-grid {
                grid-template-columns: 1fr !important;
                gap: 15px !important;
            }

            /* Building card mobile */
            .building-card {
                width: 100% !important;
                padding: 15px !important;
            }

            /* Floors grid mobile */
            .floors-grid {
                grid-template-columns: 1fr !important;
                gap: 15px !important;
            }

            /* Rooms grid mobile */
            .rooms-grid {
                grid-template-columns: 1fr !important;
                gap: 15px !important;
            }

            /* Table container mobile */
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                width: 100%;
            }

            /* All table containers mobile */
            #floorsTableContainer,
            #roomsTableContainer,
            #itemsTableContainer {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                width: 100%;
            }

            table,
            .data-table {
                min-width: 700px;
                font-size: 12px;
                width: 100%;
            }

            table th,
            table td,
            .data-table th,
            .data-table td {
                padding: 10px 8px;
                white-space: nowrap;
            }

            /* Table header mobile */
            .table-header {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start !important;
            }

            .table-header > div:last-child {
                width: 100%;
                flex-direction: column;
                gap: 10px;
            }

            .table-header button {
                width: 100%;
            }

            /* Breadcrumb nav mobile */
            .breadcrumb-nav {
                flex-wrap: wrap;
                font-size: 12px;
                gap: 5px;
            }

            /* Table view mobile */
            .table-view {
                padding: 10px !important;
            }

            /* Pagination mobile */
            .pagination {
                flex-wrap: wrap;
                justify-content: center;
                gap: 10px;
                padding: 10px 0;
            }

            .pagination button {
                padding: 8px 16px;
                font-size: 14px;
                min-width: 80px;
            }

            /* Selection bar mobile */
            .item-selection-actions {
                flex-direction: column !important;
                gap: 10px !important;
                align-items: stretch !important;
            }

            .item-selection-actions > div:last-child {
                width: 100%;
                flex-direction: column;
                gap: 10px;
            }

            .item-selection-btn {
                width: 100% !important;
                justify-content: center;
            }

            /* Modal mobile */
            .modal {
                width: 95% !important;
                max-width: 95% !important;
                margin: 10px auto !important;
                padding: 15px !important;
                max-height: 90vh !important;
                overflow-y: auto !important;
            }

            /* Scan Items Modal mobile */
            #scanItemsModal .modal {
                width: 100% !important;
                max-width: 100% !important;
                height: 100vh !important;
                max-height: 100vh !important;
                margin: 0 !important;
                padding: 15px !important;
                border-radius: 0 !important;
                display: flex !important;
                flex-direction: column !important;
            }

            #scanItemsModal .modal-header {
                flex-shrink: 0;
                padding: 15px !important;
            }

            #scanItemsModal .modal-body {
                flex: 1;
                overflow-y: auto;
                padding: 15px !important;
            }

            /* QR Scanner mobile */
            #qr-reader {
                width: 100% !important;
                max-width: 100% !important;
            }

            /* Checklist mobile */
            #scanChecklist {
                max-height: 300px;
                overflow-y: auto;
            }

            /* Item Details Modal mobile */
            #itemDetailsModal .modal {
                width: 100% !important;
                max-width: 100% !important;
                max-height: 95vh !important;
                margin: 10px auto !important;
                padding: 15px !important;
            }

            #itemDetailsModal .modal-body {
                max-height: calc(95vh - 100px);
                overflow-y: auto;
            }

            /* Item Details 2-column layout mobile - make it single column */
            #itemDetailsModal .modal-body > div[style*="grid-template-columns"] {
                grid-template-columns: 1fr !important;
                gap: 15px !important;
            }

            /* Item Details cards mobile - all nested grids single column */
            #itemDetailsModal .modal-body div[style*="grid-template-columns: repeat(auto-fit"] {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }

            /* Item Details left and right columns mobile */
            #itemDetailsModal .modal-body > div > div:first-child,
            #itemDetailsModal .modal-body > div > div:last-child {
                width: 100% !important;
            }

            /* Item Details header badges mobile */
            #itemDetailsModal .modal-body div[style*="display: flex; gap: 8px"] {
                flex-wrap: wrap !important;
            }

            /* Item Details description section mobile */
            #itemDetailsModal .detail-section {
                width: 100% !important;
                grid-column: 1 / -1 !important;
            }

            /* Scanner buttons mobile */
            #scanItemsModal .modal-body button {
                width: 100% !important;
                margin: 5px 0 !important;
            }

            /* Scanner controls mobile */
            #scanItemsModal .modal-body > div:last-child {
                flex-direction: column;
                gap: 10px;
            }

            /* Scan Items Modal - 2 column layout to single column on mobile */
            #scanItemsModal .modal-body > div[style*="grid-template-columns"] {
                grid-template-columns: 1fr !important;
                gap: 15px !important;
            }

            /* QR Scanner section mobile */
            #scanItemsModal .modal-body > div > div:first-child {
                width: 100% !important;
            }

            /* Checklist section mobile */
            #scanItemsModal .modal-body > div > div:last-child {
                width: 100% !important;
            }

            /* Items checklist container mobile */
            #itemsChecklist {
                max-height: 250px !important;
                overflow-y: auto !important;
            }

            /* QR reader height mobile */
            #qr-reader {
                height: 250px !important;
                min-height: 250px !important;
            }

            /* Scan Items Modal grid layout mobile */
            #scanItemsModal .modal-body > div[style*="display: grid"] {
                grid-template-columns: 1fr !important;
                gap: 15px !important;
            }

            /* Scan Items Modal header mobile */
            #scanItemsModal .modal-header {
                font-size: 16px !important;
            }

            #scanItemsModal .modal-header h3 {
                font-size: 18px !important;
            }

            /* Scan Items Modal footer buttons mobile */
            #scanItemsModal .modal-footer {
                padding: 15px !important;
            }

            #scanItemsModal .modal-footer button {
                padding: 12px 20px !important;
                font-size: 14px !important;
            }

            /* Modal footer mobile */
            .modal-footer {
                flex-direction: column;
                gap: 10px;
                flex-shrink: 0;
            }

            .modal-footer button {
                width: 100%;
                margin: 0 !important;
            }

            /* Breadcrumb mobile */
            .breadcrumb {
                font-size: 12px;
                flex-wrap: wrap;
            }

            /* Stats cards mobile */
            .stats-container {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }

            .stat-card {
                padding: 12px !important;
            }
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

        /* Mobile Inline Sidebar Toggle - Hidden on desktop */
        .sidebar-toggle-mobile-inline {
            display: none !important;
        }

        @media (max-width: 768px) {
            .sidebar-overlay.show {
                display: block;
            }
            
            /* Ensure toggle button is always on top */
            .sidebar-toggle-fixed {
                background: rgba(229, 62, 62, 0.95) !important;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
            }
        }
    </style>
</head>
<body data-user-logged-in="true"
      data-user-super-admin="<?= $isSuperAdmin ? 'true' : 'false' ?>"
      data-user-admin="<?= $isAdmin ? 'true' : 'false' ?>"
      data-user-role="<?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>"
      data-user-department="<?= htmlspecialchars($userDepartment, ENT_QUOTES, 'UTF-8') ?>">
    
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>
    
    <!-- Sidebar -->
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
                    <a href="department.php" class="nav-link" title="<?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
                        <span class="nav-icon">
                            <img src="image/department.png" alt="<?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
                        </span>
                        <span class="nav-label"><?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?></span>
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
                <li class="nav-item">
                    <a href="location.php" class="nav-link active" title="Location">
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

    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        <!-- Mobile Inline Sidebar Toggle (visible on mobile only) -->
        <button id="sidebarToggleMobile" class="sidebar-toggle-mobile-inline" aria-label="Toggle sidebar">☰</button>
        <!-- Buildings Grid View (Default) -->
        <div id="gridView">
            <div class="header">
                <div class="breadcrumb">
                    <div class="breadcrumb-icon"><img src="image/building-1062.png" alt="Buildings" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;" /></div>
                    <span>Buildings</span>
                </div>
                <?php if ($isSuperAdmin): ?>
                <button class="add-button" onclick="showAddBuildingModal()">
                    <img src="image/icons8-add-48.png" alt="Add" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;filter:brightness(0) invert(1);" />
                    ADD BUILDING
                </button>
                <?php endif; ?>
            </div>

            <div class="content-area">
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success" id="successAlert">
                        <?php echo htmlspecialchars($success_message); ?>
                        <button class="close" onclick="closeAlert('successAlert')">&times;</button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error" id="errorAlert">
                        <?php echo htmlspecialchars($error_message); ?>
                        <button class="close" onclick="closeAlert('errorAlert')">&times;</button>
                    </div>
                <?php endif; ?>

                <div class="search-section" style="position: relative; display: flex; align-items: center; gap: 8px;">
                    <input type="text" class="search-bar" placeholder="Search buildings...">
                </div>

                <div class="stats-bar">
                    <div class="stat-item">
                        <strong>Buildings: <?php echo $total_buildings; ?></strong>
                    </div>
                    <div class="stat-item">
                        <strong>Floors: <?php echo $total_floors; ?></strong>
                    </div>
                    <div class="stat-item">
                        <strong>Rooms: <?php echo $total_rooms; ?></strong>
                    </div>
                </div>

                <div class="locations-grid">
                    <?php while ($building = mysqli_fetch_assoc($buildings_result)): ?>
                    <div class="location-card" onclick="showBuildingFloors(<?php echo $building['id']; ?>, '<?php echo htmlspecialchars($building['name'], ENT_QUOTES); ?>')">
                        <?php if (!empty($building['image_path']) && file_exists($building['image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($building['image_path']); ?>" alt="<?php echo htmlspecialchars($building['name']); ?>" class="location-image">
                        <?php else: ?>
                            <div class="building-image">No Image Available</div>
                        <?php endif; ?>
                        <div class="location-content">
                            <div class="location-info">
                                <h3><?php echo htmlspecialchars($building['name']); ?></h3>
                                <div class="location-floors">Floors: <?php echo $building['floor_count']; ?></div>
                            </div>
                            <div class="location-stats">
                                <div class="room-count">Rooms: <?php echo $building['room_count']; ?></div>
                                <div>Built: <?php echo $building['date_built'] ? date('m/d/Y', strtotime($building['date_built'])) : 'N/A'; ?></div>
                            </div>
                            <?php if ($isSuperAdmin): ?>
                            <button class="more-menu" onclick="event.stopPropagation(); showBuildingOptions(<?php echo $building['id']; ?>, this)">⋮</button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>

        <!-- Floors Table View -->
        <div id="floorsView" class="table-view">
            <div class="table-header">
                <div>
                    <div class="breadcrumb-nav">
                        <span class="breadcrumb-item" onclick="showGridView()">Buildings</span>
                        <span class="breadcrumb-separator">›</span>
                        <span id="currentBuildingName">Building Name</span>
                    </div>
                    <div class="table-title">
                        <img src="image/layers.png" alt="Floors" style="width:30px;height:30px;vertical-align:middle;margin-right:2px;" /> Floors
                    </div>
                </div>
                <button class="back-button" onclick="showGridView()"><img src="image/back-button.png" alt="Back" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;" /> Back to Buildings</button>
            </div>
            <div id="floorsTableContainer">
                <div class="loading">
                    <div class="loading-spinner"></div>
                </div>
            </div>
        </div>

        <!-- Rooms Table View -->
        <div id="roomsView" class="table-view">
            <div class="table-header">
                <div>
                    <div class="breadcrumb-nav">
                        <span class="breadcrumb-item" onclick="showGridView()">Buildings</span>
                        <span class="breadcrumb-separator">›</span>
                        <span class="breadcrumb-item" onclick="showBuildingFloors(currentBuildingId, currentBuildingName)" id="breadcrumbBuilding">Building</span>
                        <span class="breadcrumb-separator">›</span>
                        <span id="currentFloorName">Floor Name</span>
                    </div>
                    <div class="table-title">
                    <img src="image/classroom.png" alt="Room" style="width:30px;height:30px;vertical-align:middle;margin-right:2px;" /> Rooms
                    </div>
                </div>
                <button class="back-button" onclick="showBuildingFloors(currentBuildingId, currentBuildingName)"><img src="image/layers.png" alt="Back" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;" /> Back to Floors</button>
            </div>
            <div id="roomsTableContainer">
                <div class="loading">
                    <div class="loading-spinner"></div>
                </div>
            </div>
        </div>

        <!-- Items Table View -->
        <div id="itemsView" class="table-view">
            <div class="table-header">
                <div>
                    <div class="breadcrumb-nav">
                        <span class="breadcrumb-item" onclick="showGridView()">Buildings</span>
                        <span class="breadcrumb-separator">›</span>
                        <span class="breadcrumb-item" onclick="showBuildingFloors(currentBuildingId, currentBuildingName)" id="breadcrumbBuilding2">Building</span>
                        <span class="breadcrumb-separator">›</span>
                        <span class="breadcrumb-item" onclick="showFloorRooms(currentFloorId, currentFloorName)" id="breadcrumbFloor">Floor</span>
                        <span class="breadcrumb-separator">›</span>
                        <span id="currentRoomName">Room Name</span>
                    </div>
                    <div class="table-title">
                        <img src="image/table.png" alt="Items" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;" /> Items
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button class="btn-submit" onclick="openScanItemsModal()" style="background: rgba(255, 255, 255, 0.2); linear-gradient(135deg, #e53e3e, #c53030); border: none; padding: 10px 20px; border-radius: 8px; color: white; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; font-size: 14px; box-shadow: 0 2px 8px rgba(229, 62, 62, 0.3);">
                        <img src="image/barcode-scan.png" alt="Scan" style="width:18px;height:18px;filter: brightness(0) invert(1);" /> Scan Items
                    </button>
                    <button class="back-button" onclick="showFloorRooms(currentFloorId, currentFloorName)"><img src="image/classroom.png" alt="Back" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;" /> Back to Rooms</button>
                </div>
            </div>
            <div id="itemsTableContainer">
                <div class="loading">
                    <div class="loading-spinner"></div>
                </div>
            </div>
            <div id="itemsPagination"></div>
        </div>
    </div>

    <!-- Item Details Modal -->
    <div class="modal-overlay" id="itemDetailsModal">
        <div class="modal details-modal" style="max-width: 900px; border-radius: 16px; overflow: hidden; max-height: 90vh;">
            <div class="modal-header details-header" style="background: linear-gradient(135deg, #e53e3e, #c53030); color: white; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center;">
                <div class="details-header-content" style="display: flex; align-items: center; gap: 15px;">
                    <div class="modal-icon-box" style="background: rgba(255, 255, 255, 0.2); border-radius: 12px; padding: 12px; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                        <img src="image/table.png" alt="Item" style="width:32px;height:32px;filter:brightness(0) invert(1);" />
                    </div>
                    <div>
                        <h3 class="modal-title" style="margin: 0; font-size: 20px; font-weight: 700; color: white;">ITEM DETAILS</h3>
                        <p class="modal-subtitle" style="margin: 2px 0 0 0; font-size: 13px; opacity: 0.9; color: white;">Complete information</p>
                    </div>
                </div>
                <button class="close-btn" onclick="closeModal('itemDetailsModal')" style="background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3); color: white; font-size: 24px; cursor: pointer; padding: 0; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 10px; backdrop-filter: blur(10px); transition: all 0.2s;">×</button>
            </div>
            <div class="modal-body details-body" id="itemDetailsContent" style="padding: 24px 30px;">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>
    </div>

    <!-- Add Building Modal -->
    <div class="modal-overlay" id="addBuildingModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Add New Building</h3>
                <button class="modal-close" onclick="closeModal('addBuildingModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addBuildingForm" class="modal-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_building">
                    
                    <div class="form-group">
                        <label for="building_name">Building Name <span class="required">*</span></label>
                        <input type="text" id="building_name" name="building_name" required maxlength="100" placeholder="Enter building name">
                    </div>
                    
                    <div class="form-group">
                        <label for="building_description">Description</label>
                        <textarea id="building_description" name="building_description" maxlength="500" placeholder="Enter building description"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="building_image">Building Image</label>
                        <input type="file" id="building_image" name="building_image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="validateFileUpload(this)">
                        <small style="color: #666; font-size: 12px;">Max size: 5MB. Allowed formats: JPEG, PNG, GIF, WebP</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="date_built">Date Built</label>
                        <input type="date" id="date_built" name="date_built">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addBuildingModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" form="addBuildingForm">Add Building</button>
            </div>
        </div>
    </div>

    <!-- Add Floor Modal -->
    <div class="modal-overlay" id="addFloorModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add Floor</h3>
                <button class="close-btn" onclick="closeModal('addFloorModal')">×</button>
            </div>
            <div class="modal-body">
                <form id="addFloorForm" method="POST">
                    <input type="hidden" name="action" value="add_floor">
                    <input type="hidden" id="floor_building_id" name="building_id">
                    
                    <div class="form-group">
                        <label>Floor Number: <span class="required">*</span></label>
                        <input type="number" id="floor_number" name="floor_number" min="1" max="100" required placeholder="Enter floor number">
                        <div class="error-text"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Floor Name: <span class="required">*</span></label>
                        <input type="text" id="floor_name" name="floor_name" required maxlength="100" placeholder="Enter floor name">
                        <div class="error-text"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description:</label>
                        <textarea id="floor_description" name="floor_description" maxlength="500" placeholder="Enter floor description"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addFloorModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" form="addFloorForm">Add Floor</button>
            </div>
        </div>
    </div>

    <!-- Add Room Modal -->
    <div class="modal-overlay" id="addRoomModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Add Room</h3>
                <button class="modal-close" onclick="closeModal('addRoomModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addRoomForm" class="modal-form" method="POST">
                    <input type="hidden" name="action" value="add_room">
                    
                    <div class="form-group">
                        <label for="room_floor_id">Select Floor <span class="required">*</span></label>
                        <select id="room_floor_id" name="floor_id" required>
                            <option value="">Choose a floor...</option>
                            <!-- Will be populated by JavaScript -->
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="room_number">Room Number <span class="required">*</span></label>
                        <input type="text" id="room_number" name="room_number" required maxlength="50" placeholder="Enter room number">
                    </div>
                    
                    <div class="form-group">
                        <label for="room_name">Room Name (Optional)</label>
                        <input type="text" id="room_name" name="room_name" maxlength="100" placeholder="Enter room name">
                    </div>
                    
                    <div class="form-group">
                        <label for="room_description">Description</label>
                        <textarea id="room_description" name="room_description" maxlength="500" placeholder="Enter room description"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addRoomModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" form="addRoomForm">Add Room</button>
            </div>
        </div>
    </div>

    <!-- View/Edit Building Details Modal -->
    <div class="modal-overlay" id="viewBuildingModal">
    <div class="modal" style="max-width: 900px; max-height: 90vh; overflow-y: auto; padding: 0;">
        <div style="position: sticky; top: 0; background: linear-gradient(135deg, #e53e3e, #c53030); color: white; padding: 20px 30px; z-index: 10; display: flex; justify-content: space-between; align-items: center; border-radius: 10px 10px 0 0;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background: rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 10px; backdrop-filter: blur(10px);">
                    <img src="image/building-1062.png" alt="Building" style="width: 28px; height: 28px; filter: brightness(0) invert(1);">
                </div>
                <div>
                    <h3 style="margin: 0; font-size: 22px; font-weight: 700;">Building Details</h3>
                    <p style="margin: 2px 0 0 0; font-size: 13px; opacity: 0.9;">Complete information and management</p>
                </div>
            </div>
            <button onclick="closeModal('viewBuildingModal')" style="background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3); color: white; font-size: 24px; cursor: pointer; padding: 0; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; backdrop-filter: blur(10px); transition: all 0.2s;" onmouseover="this.style.background='rgba(255, 255, 255, 0.3)'" onmouseout="this.style.background='rgba(255, 255, 255, 0.2)'">×</button>
        </div>
        <div id="buildingDetailsContent">
            <!-- Content will be loaded dynamically -->
        </div>
    </div>
</div>

    <!-- Edit Building Modal -->
    <div class="modal-overlay" id="editBuildingModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Edit Building</h3>
                <button class="modal-close" onclick="closeModal('editBuildingModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editBuildingForm" class="modal-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_building">
                    <input type="hidden" id="edit_building_id" name="building_id">
                    
                    <div class="form-group">
                        <label for="edit_building_name">Building Name <span class="required">*</span></label>
                        <input type="text" id="edit_building_name" name="building_name" required maxlength="100" placeholder="Enter building name">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_building_description">Description</label>
                        <textarea id="edit_building_description" name="building_description" maxlength="500" placeholder="Enter building description"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_building_image">Building Image</label>
                        <input type="file" id="edit_building_image" name="building_image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="validateFileUpload(this)">
                        <small style="color: #666; font-size: 12px;">Leave empty to keep current image. Max size: 5MB.</small>
                        <div id="currentImagePreview" style="margin-top: 10px;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_date_built">Date Built</label>
                        <input type="date" id="edit_date_built" name="date_built">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editBuildingModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" form="editBuildingForm">Update Building</button>
            </div>
        </div>
    </div>

    <!-- Edit Floor Modal -->
    <div class="modal-overlay" id="editFloorModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Edit Floor</h3>
                <button class="modal-close" onclick="closeModal('editFloorModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editFloorForm" class="modal-form" method="POST">
                    <input type="hidden" name="action" value="edit_floor">
                    <input type="hidden" name="floor_id" id="edit_floor_id">
                    
                    <div class="form-group">
                        <label for="edit_floor_name">Floor Name <span class="required">*</span></label>
                        <input type="text" id="edit_floor_name" name="floor_name" required placeholder="Enter floor name">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_floor_number">Floor Number</label>
                        <input type="number" id="edit_floor_number" name="floor_number" placeholder="Enter floor number">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_floor_description">Description</label>
                        <textarea id="edit_floor_description" name="description" rows="3" placeholder="Enter floor description"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editFloorModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" form="editFloorForm">Update Floor</button>
            </div>
        </div>
    </div>

    <!-- Edit Room Modal -->
    <div class="modal-overlay" id="editRoomModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon">
                    <img src="image/edit.png" alt="Edit" style="width: 24px; height: 24px;">
                </div>
                <h3 class="modal-title">Edit Room</h3>
                <button class="modal-close" onclick="closeModal('editRoomModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editRoomForm" class="modal-form" method="POST">
                    <input type="hidden" name="action" value="edit_room">
                    <input type="hidden" name="room_id" id="edit_room_id">
                    
                    <div class="form-group">
                        <label for="edit_room_name">Room Name (Optional)</label>
                        <input type="text" id="edit_room_name" name="room_name" placeholder="Enter room name">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_room_number">Room Number</label>
                        <input type="text" id="edit_room_number" name="room_number" placeholder="Enter room number">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_room_description">Description</label>
                        <textarea id="edit_room_description" name="description" rows="3" placeholder="Enter room description"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editRoomModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" form="editRoomForm">Update Room</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteConfirmModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon">
                    <img src="image/delete.png" alt="Delete" style="width: 24px; height: 24px;">
                </div>
                <h3 class="modal-title">Confirm Delete</h3>
                <button class="modal-close" onclick="closeModal('deleteConfirmModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p class="modal-message" id="deleteMessage">Are you sure you want to delete this item?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('deleteConfirmModal')">Cancel</button>
                <button class="btn btn-danger" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <!-- Scan Items Modal -->
    <div class="modal-overlay" id="scanItemsModal">
        <div class="modal" style="max-width: 1000px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #e53e3e, #c53030); color:#fff;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="background: rgba(255, 255, 255, 0.2); border-radius: 10px; padding: 10px; backdrop-filter: blur(10px);">
                        <img src="image/barcode-scan.png" alt="Scan" style="width: 28px; height: 28px; filter: brightness(0) invert(1);">
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 22px; font-weight: 700; color: white;">Scan Items - <span id="scanRoomName">Room Name</span></h3>
                        <p style="margin: 2px 0 0 0; font-size: 13px; opacity: 0.9; color: white;">Check all items in this room</p>
                    </div>
                </div>
                <button onclick="closeModal('scanItemsModal')" style="background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3); color: white; font-size: 24px; cursor: pointer; padding: 0; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; backdrop-filter: blur(10px); transition: all 0.2s;">×</button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <!-- QR Scanner Section -->
                    <div style="background: white; border: 2px solid #e2e8f0; border-radius: 12px; padding: 20px;">
                        <h4 style="margin: 0 0 15px 0; font-size: 16px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 8px;">
                            <img src="image/barcode-scan.png" alt="Scan" style="width: 20px; height: 20px;"> QR Scanner
                        </h4>
                        <div id="qr-reader" style="width: 100%; height: 300px; border: 2px dashed #d1d5db; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f9fafb;">
                            <div style="text-align: center; color: #9ca3af;">
                                <div style="font-size: 40px; margin-bottom: 10px; font-weight: 300;">QR</div>
                                <div>Click Start to begin scanning</div>
                            </div>
                        </div>
                        <div style="margin-top: 15px; display: flex; gap: 10px;">
                            <button id="startScannerBtn" onclick="startScanner()" style="flex: 1; background: linear-gradient(135deg, #e53e3e, #c53030); color: white; border: none; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                Start Scanner
                            </button>
                            <button id="stopScannerBtn" onclick="stopScanner()" style="display: none; flex: 1; background: #ef4444; color: white; border: none; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer;">
                                Stop Scanner
                            </button>
                        </div>
                        <div id="scanStatus" style="margin-top: 10px; padding: 10px; border-radius: 8px; background: #f9fafb; display: none;">
                            <div id="scanStatusText" style="font-size: 14px; color: #6b7280;"></div>
                        </div>
                    </div>

                    <!-- Checklist Section -->
                    <div style="background: white; border: 2px solid #e2e8f0; border-radius: 12px; padding: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h4 style="margin: 0; font-size: 16px; font-weight: 600; color: #1f2937; display: flex; align-items: center; gap: 8px;">
                                <img src="image/table.png" alt="Checklist" style="width: 20px; height: 20px;"> Checklist
                            </h4>
                            <div style="font-size: 14px; color: #6b7280;">
                                <span id="scannedCount">0</span> / <span id="totalCount">0</span> scanned
                            </div>
                        </div>
                        <div id="itemsChecklist" style="max-height: 300px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; background: #f9fafb;">
                            <!-- Items will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px; border-top: 1px solid #e2e8f0; background: #f9fafb;">
                <button onclick="closeScanItemsModal()" style="background: white; border: 1px solid #d1d5db; color: #374151; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; margin-right: 10px;">
                    Cancel
                </button>
                <button onclick="completeScan()" id="completeScanBtn" style="background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 10px 30px; border-radius: 8px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);">
                    Done
                </button>
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

    <!-- Building Options Dropdown -->
    <div class="dropdown-menu" id="buildingOptions">
        <div class="dropdown-item" onclick="addFloorToBuilding()">Add Floor</div>
        <div class="dropdown-item" onclick="addRoomToBuilding()">Add Room</div>
        <div class="dropdown-item" onclick="viewBuildingDetails()">View Details</div>
    </div>

    <script>
        const IS_ADMIN = document.body.dataset.userAdmin === 'true';
        const IS_SUPER_ADMIN = document.body.dataset.userSuperAdmin === 'true';
        const USER_ROLE = (document.body.dataset.userRole || '').toLowerCase();
        const IS_VIEWER = USER_ROLE === 'viewer';
        // Sidebar collapse/expand
        (function() {
            const BODY_CLASS = 'sidebar-collapsed';
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const isMobile = window.innerWidth <= 768;

            function applyInitialState() {
                if (!isMobile) {
                    const saved = localStorage.getItem('ocabis:sidebar-collapsed');
                    const isCollapsed = saved === '1';
                    document.body.classList.toggle(BODY_CLASS, isCollapsed);
                }
            }

            function toggleSidebar(e) {
                if (e) e.stopPropagation();
                
                if (isMobile) {
                    // Mobile behavior: slide sidebar in/out
                    const isOpen = sidebar.classList.toggle('open');
                    if (overlay) {
                        overlay.classList.toggle('show', isOpen);
                    }
                    // Prevent body scroll when sidebar is open
                    document.body.style.overflow = isOpen ? 'hidden' : '';
                } else {
                    // Desktop behavior: collapse/expand
                    const isCollapsed = document.body.classList.toggle(BODY_CLASS);
                    localStorage.setItem('ocabis:sidebar-collapsed', isCollapsed ? '1' : '0');
                }
            }

            function closeSidebar() {
                if (sidebar) sidebar.classList.remove('open');
                if (overlay) overlay.classList.remove('show');
                document.body.style.overflow = '';
            }

            // Close sidebar when clicking overlay
            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }

            // Close sidebar when clicking outside on mobile
            if (isMobile) {
                document.addEventListener('click', function(e) {
                    if (sidebar && sidebar.classList.contains('open')) {
                        if (!sidebar.contains(e.target) && !e.target.closest('#sidebarToggleFixed') && !e.target.closest('#sidebarToggleMobile')) {
                            closeSidebar();
                        }
                    }
                });
            }

            const inlineBtn = document.getElementById('sidebarToggle');
            const fixedBtn = document.getElementById('sidebarToggleFixed');
            const mobileInlineBtn = document.getElementById('sidebarToggleMobile');
            if (inlineBtn) inlineBtn.addEventListener('click', toggleSidebar);
            if (fixedBtn) fixedBtn.addEventListener('click', toggleSidebar);
            if (mobileInlineBtn) mobileInlineBtn.addEventListener('click', toggleSidebar);

            // Handle window resize
            let resizeTimeout;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(function() {
                    const nowMobile = window.innerWidth <= 768;
                    // Close sidebar when switching to mobile or desktop
                    if (sidebar && sidebar.classList.contains('open')) {
                        closeSidebar();
                    }
                }, 250);
            });

            applyInitialState();
        })();

        // Global variables
        let currentView = 'grid';
        let currentBuildingId = null;
        let currentBuildingName = '';
        let currentFloorId = null;
        let currentFloorName = '';
        let currentRoomId = null;
        let currentRoomName = '';
        let deleteTargetId = null;
        let deleteTargetType = null;

        // View management functions
        function showGridView() {
            hideAllViews();
            document.getElementById('gridView').style.display = 'block';
            currentView = 'grid';
        }

        function showBuildingFloors(buildingId, buildingName) {
            currentBuildingId = buildingId;
            currentBuildingName = buildingName;
            
            hideAllViews();
            document.getElementById('floorsView').classList.add('active');
            document.getElementById('currentBuildingName').textContent = buildingName;
            document.getElementById('breadcrumbBuilding').textContent = buildingName;
            document.getElementById('breadcrumbBuilding2').textContent = buildingName;
            
            loadFloors(buildingId);
            currentView = 'floors';
        }

        function showFloorRooms(floorId, floorName) {
            currentFloorId = floorId;
            currentFloorName = floorName;
            
            hideAllViews();
            document.getElementById('roomsView').classList.add('active');
            document.getElementById('currentFloorName').textContent = floorName;
            document.getElementById('breadcrumbFloor').textContent = floorName;
            
            loadRooms(floorId);
            currentView = 'rooms';
        }

        function showRoomItems(roomId, roomName) {
            currentRoomId = roomId;
            currentRoomName = roomName;
            
            hideAllViews();
            document.getElementById('itemsView').classList.add('active');
            document.getElementById('currentRoomName').textContent = roomName;
            
            loadItems(roomId);
            currentView = 'items';
        }

        function hideAllViews() {
            document.getElementById('gridView').style.display = 'none';
            document.querySelectorAll('.table-view').forEach(view => {
                view.classList.remove('active');
            });
        }

        // Data loading functions
        function loadFloors(buildingId) {
            const container = document.getElementById('floorsTableContainer');
            container.innerHTML = '<div class="loading"><div class="loading-spinner"></div></div>';

            fetch(`?ajax=get_floors&building_id=${buildingId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        container.innerHTML = `<div class="no-data">Error: ${data.error}</div>`;
                        return;
                    }

                    if (data.length === 0) {
                        container.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state-icon">🏢</div>
                                <div>No floors found in this building</div>
                            </div>
                        `;
                        return;
                    }

                    let tableHTML = `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Floor Number</th>
                                    <th>Floor Name</th>
                                    <th>Description</th>
                                    <th>Rooms</th>
                                    <th>Date Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.forEach(floor => {
                        tableHTML += `
                            <tr onclick="showFloorRooms(${floor.id}, '${floor.floor_name.replace(/'/g, '\\\'')}')" style="cursor: pointer;">
                                <td>${floor.id}</td>
                                <td>${floor.floor_number}</td>
                                <td>${floor.floor_name}</td>
                                <td>${floor.description || 'N/A'}</td>
                                <td>${floor.room_count}</td>
                                <td>${new Date(floor.created_at).toLocaleDateString()}</td>
                                <td onclick="event.stopPropagation();">
    <div class="action-dropdown">
        <button class="action-btn" onclick="toggleActionMenu(${floor.id}, event)">⋮</button>
        <div class="action-menu" id="menu-floor-${floor.id}">
            <button onclick="showFloorRooms(${floor.id}, '${floor.floor_name.replace(/'/g, '\\\'')}')">
                <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> 
                View Rooms
            </button>
        </div>
    </div>
</td>
                            </tr>
                        `;
                    });

                    tableHTML += '</tbody></table>';
                    container.innerHTML = tableHTML;
                })
                .catch(error => {
                    console.error('Error loading floors:', error);
                    container.innerHTML = '<div class="no-data">Error loading floors. Please try again.</div>';
                });
        }

        function loadRooms(floorId) {
            const container = document.getElementById('roomsTableContainer');
            container.innerHTML = '<div class="loading"><div class="loading-spinner"></div></div>';

            fetch(`?ajax=get_rooms&floor_id=${floorId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        container.innerHTML = `<div class="no-data">Error: ${data.error}</div>`;
                        return;
                    }

                    if (data.length === 0) {
                        container.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state-icon">🏠</div>
                                <div>No rooms found on this floor</div>
                            </div>
                        `;
                        return;
                    }

                    let tableHTML = `
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Room Number</th>
                                    <th>Room Name</th>
                                    <th>Description</th>
                                    <th>Items</th>
                                    <th>Date Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    data.forEach(room => {
                        tableHTML += `
                            <tr onclick="showRoomItems(${room.id}, '${room.room_name.replace(/'/g, '\\\'')}')" style="cursor: pointer;">
                                <td>${room.id}</td>
                                <td>${room.room_number}</td>
                                <td>${room.room_name}</td>
                                <td>${room.description || 'N/A'}</td>
                                <td>${room.item_count}</td>
                                <td>${new Date(room.created_at).toLocaleDateString()}</td>
                                 <td onclick="event.stopPropagation();">
    <div class="action-dropdown">
        <button class="action-btn" onclick="toggleActionMenu(${room.id}, event)">⋮</button>
        <div class="action-menu" id="menu-room-${room.id}">
            <button onclick="showRoomItems(${room.id}, '${room.room_name.replace(/'/g, '\\\'')}')">
                <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> 
                View Items
            </button>
        </div>
    </div>
</td>
                            </tr>
                        `;
                    });

                    tableHTML += '</tbody></table>';
                    container.innerHTML = tableHTML;
                })
                .catch(error => {
                    console.error('Error loading rooms:', error);
                    container.innerHTML = '<div class="no-data">Error loading rooms. Please try again.</div>';
                });
        }

        // Pagination state for items
        let itemsData = [];
        let itemsCurrentPage = 1;
        const itemsPerPage = 10;

        // Selection state and helpers for bulk actions in Location page
        const selectedItemsLoc = new Set();

        function toggleItemSelectionLoc(itemId, e) {
            if (e) e.stopPropagation();
            if (selectedItemsLoc.has(itemId)) selectedItemsLoc.delete(itemId); else selectedItemsLoc.add(itemId);
            updateItemsSelectionBarLoc();
        }

        function toggleSelectAllItemsLoc(e) {
            const checked = e.target.checked;
            document.querySelectorAll('.loc-item-checkbox').forEach(cb => {
                const id = parseInt(cb.getAttribute('data-id'));
                cb.checked = checked;
                if (checked) selectedItemsLoc.add(id); else selectedItemsLoc.delete(id);
            });
            updateItemsSelectionBarLoc();
        }

        function updateItemsSelectionBarLoc() {
            const bar = document.getElementById('itemsSelectionBarLoc');
            if (!bar) return;
            const countEl = document.getElementById('itemsSelectionCountLoc');
            if (countEl) countEl.textContent = selectedItemsLoc.size;
            const requires = bar.querySelectorAll('button[data-requires-selection="1"]');
            requires.forEach(btn => { btn.disabled = selectedItemsLoc.size === 0; });
        }

        function archiveSelectedItemsLoc() {
            if (selectedItemsLoc.size === 0) { alert('Please select items to archive.'); return; }
            if (!confirm(`Are you sure you want to archive ${selectedItemsLoc.size} selected items?`)) return;
            const ids = Array.from(selectedItemsLoc);
            let completed = 0, failed = 0;
            const next = (i) => {
                if (i >= ids.length) {
                    selectedItemsLoc.clear();
                    updateItemsSelectionBarLoc();
                    renderItemsTable();
                    if (typeof modal !== 'undefined' && modal.success) {
                        failed === 0 ? modal.success(`${completed} items archived successfully.`) : modal.warning(`${completed} archived, ${failed} failed.`);
                    } else {
                        alert(failed === 0 ? `${completed} items archived successfully.` : `${completed} archived, ${failed} failed.`);
                        // Notify archive page to reload immediately
                        localStorage.setItem('archiveUpdated', Date.now().toString());
                        window.dispatchEvent(new StorageEvent('storage', {
                            key: 'archiveUpdated',
                            newValue: Date.now().toString()
                        }));
                    }
                    if (typeof loadAllItems === 'function') loadAllItems(); // Remove delay, reload immediately
                    return;
                }
                const id = ids[i];
                fetch('crud.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=archive&id=${encodeURIComponent(id)}` })
                    .then(r => r.json()).then(d => { if (d && d.success) completed++; else failed++; next(i+1); })
                    .catch(() => { failed++; next(i+1); });
            };
            next(0);
        }

        function openMoveLocationModalLoc() {
            if (selectedItemsLoc.size === 0) { alert('Please select items to move.'); return; }
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'moveLocationModalLoc';
            overlay.innerHTML = `
                <div class="modal">
                    <div class="modal-header" style="background: linear-gradient(135deg, #3182ce, #2c5aa0); color: white; position:relative;">
                        <h3 style="color: white;">Move Items to New Location</h3>
                        <button class="close-btn"
                            onclick="closeMoveLocationModalLoc()"
                            aria-label="Close"
                            style="background: rgba(255,255,255,0.8); border: 2px solid #e2e8f0; color: #1e293b; font-size: 26px; box-shadow: 0 2px 8px rgba(56,97,251,0.07); width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 50%; position: absolute; right: 28px; top: 18px; transition: all 0.18s cubic-bezier(.4,0,.2,1); ">
                            ×
                        </button>
                    </div>
                    <div class="modal-body">
                        <div style="text-align:center; padding: 20px 0;">
                            <div style="width: 100px; height: 100px; margin: 0 auto 28px; background: linear-gradient(135deg, #3182ce, #2c5aa0); border-radius: 50%; display: flex; align-items: center; justify-content: center;box-shadow:0 4px 20px rgba(20,80,200,0.08);">
                                <img src=\"image/building-1062.png\" alt=\"Move Location\" style=\"width: 56px; height: 56px; filter: brightness(0) invert(1);\"/>
                            </div>
                            <h4 style="margin: 0 0 12px 0; color: #273046; font-size: 26px; font-weight: 700; letter-spacing:-.5px;">Move ${selectedItemsLoc.size} selected items to new location</h4>
                            <p style="color: #6b7280; margin: 4px 0 22px 0; font-size: 17px;">Select the new location for the selected items.</p>
                            <div class="form-group" style="max-width:410px; margin: 0 auto 18px;">
                                <label style="font-size:16px;">New Location: <span class="required">*</span></label>
                                <select id="newLocationSelectLoc" required style="width: 100%; padding: 14px; border: 1.5px solid #d2daea; border-radius: 8px; font-size: 15.5px; margin-top: 4px;">
                                    <option value="">Select New Location</option>
                                </select>
                            </div>
                            <div class="location-details" id="selectedLocationDetailsLoc" style="margin-top: 18px; padding: 20px; background: #f8f9fa; border-radius: 12px; display:none; box-shadow:0 1px 3px 0 rgba(40,80,179,0.06);">
                                <div style="display: flex; gap: 18px; flex-wrap: wrap; justify-content:center;">
                                    <div style="flex: 1; min-width: 108px;">
                                        <strong style="color: #8088ab; font-size: 14px; text-transform: uppercase; letter-spacing: 1.1px;">Building</strong>
                                        <div id="selBuildingLoc" style="color: #374151; font-size: 16px; margin-top: 2px; font-weight: 500;">-</div>
                                    </div>
                                    <div style="flex: 1; min-width: 82px;">
                                        <strong style="color: #8088ab; font-size: 14px; text-transform: uppercase; letter-spacing: 1.1px;">Floor</strong>
                                        <div id="selFloorLoc" style="color: #374151; font-size: 16px; margin-top: 2px;font-weight: 500;">-</div>
                                    </div>
                                    <div style="flex: 1; min-width: 92px;">
                                        <strong style="color: #8088ab; font-size: 14px; text-transform: uppercase; letter-spacing: 1.1px;">Room</strong>
                                        <div id="selRoomLoc" style="color: #374151; font-size: 16px; margin-top: 2px; font-weight: 500;">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="padding: 34px 40px 34px 14px; gap: 15px; background: #f8f9fa; border-top: 1px solid #e6e8ee; display: flex; flex-direction: row; justify-content: flex-end; align-items:center;">
                        <button class="btn-cancel" type="button" style="background: #fff; color: #334155; border: 1.5px solid #e1e7f1; font-size: 17px; border-radius: 8px; font-weight: 600; min-width:124px; box-shadow:0 2px 10px 0 rgba(40,80,179,0.04); padding: 12px 22px; " onclick="closeMoveLocationModalLoc()">Cancel</button>
                        <button class="btn-submit" id="confirmMoveBtnLoc" type="button" style="background: linear-gradient(90deg,#2563eb 2%, #3191fa 99%); color: #fff; border:none; border-radius: 8px; font-size: 17px; font-weight: 600; min-width:148px;display: flex; align-items: center; gap: 10px; box-shadow: 0 6px 18px 0 rgba(49,145,250,0.11),0 1.5px 5.5px 0 rgba(40,80,179,0.07); padding: 12px 24px; transition: box-shadow .15s; " onclick="confirmMoveLocationLoc()">
                            <img src=\"image/building-1062.png\" alt=\"Move Location\" style=\"width: 22px; height: 22px; filter: brightness(0) invert(1); margin-right:5px;\">
                            Move Items
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            overlay.style.display = 'flex';
            overlay.classList.add('show');
            document.body.style.overflow = 'hidden';
            loadLocationsForMoveLoc();
        }

        function closeMoveLocationModalLoc() {
            const m = document.getElementById('moveLocationModalLoc');
            if (!m) return;
            m.classList.remove('show');
            document.body.style.overflow = 'auto';
            setTimeout(() => m.remove(), 300);
        }

        function loadLocationsForMoveLoc() {
            fetch('crud.php?action=get_locations')
                .then(r => r.json())
                .then(data => {
                    const sel = document.getElementById('newLocationSelectLoc');
                    if (!sel) return;
                    sel.innerHTML = '<option value="">Select New Location</option>';
                    if (data && data.success && Array.isArray(data.locations)) {
                        data.locations.forEach(loc => {
                            const o = document.createElement('option');
                            o.value = loc.full_location;
                            o.textContent = loc.full_location;
                            o.dataset.building = loc.building_name;
                            o.dataset.floor = loc.floor_number;
                            o.dataset.room = loc.room_name;
                            sel.appendChild(o);
                        });
                        sel.addEventListener('change', function() {
                            const opt = this.options[this.selectedIndex];
                            const box = document.getElementById('selectedLocationDetailsLoc');
                            if (this.value) {
                                box.style.display = 'block';
                                document.getElementById('selBuildingLoc').textContent = opt.dataset.building || '-';
                                document.getElementById('selFloorLoc').textContent = opt.dataset.floor || '-';
                                document.getElementById('selRoomLoc').textContent = opt.dataset.room || '-';
                            } else {
                                box.style.display = 'none';
                            }
                        });
                    }
                })
                .catch(err => console.error('Error loading locations', err));
        }

        function confirmMoveLocationLoc() {
            const sel = document.getElementById('newLocationSelectLoc');
            if (!sel || !sel.value) { alert('Please select a new location.'); return; }
            const newLocation = sel.value;
            const ids = Array.from(selectedItemsLoc);
            const btn = document.getElementById('confirmMoveBtnLoc');
            let completed = 0, failed = 0;
            if (btn) { btn.disabled = true; btn.textContent = 'Moving...'; }
            const next = (i) => {
                if (i >= ids.length) {
                    if (btn) { btn.disabled = false; btn.textContent = 'Move Items'; }
                    selectedItemsLoc.clear();
                    updateItemsSelectionBarLoc();
                    closeMoveLocationModalLoc();
                    renderItemsTable();
                    if (typeof modal !== 'undefined' && modal.success) {
                        failed === 0 ? modal.success(`${completed} items moved to "${newLocation}".`) : modal.warning(`${completed} moved, ${failed} failed.`);
                    } else {
                        alert(failed === 0 ? `${completed} items moved to "${newLocation}".` : `${completed} moved, ${failed} failed.`);
                    }
                    if (typeof loadAllItems === 'function') setTimeout(() => loadAllItems(), 600);
                    return;
                }
                const id = ids[i];
                fetch('crud.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: `action=move_location&id=${encodeURIComponent(id)}&location=${encodeURIComponent(newLocation)}` })
                    .then(r => r.json()).then(d => { if (d && d.success) completed++; else failed++; next(i+1); })
                    .catch(() => { failed++; next(i+1); });
            };
            next(0);
        }

        function loadItems(roomId) {
            const container = document.getElementById('itemsTableContainer');
            container.innerHTML = '<div class="loading"><div class="loading-spinner"></div></div>';

            fetch(`?ajax=get_items&room_id=${roomId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        container.innerHTML = `<div class=\"no-data\">Error: ${data.error}</div>`;
                        return;
                    }

                    if (data.length === 0) {
                        container.innerHTML = `
                            <div class=\"empty-state\">
                                <div class=\"empty-state-icon\">📦</div>
                                <div>No items found in this room</div>
                            </div>
                        `;
                        return;
                    }

                    itemsData = data;
                    itemsCurrentPage = 1;
                    renderItemsTable();
                })
                .catch(error => {
                    console.error('Error loading items:', error);
                    container.innerHTML = '<div class=\"no-data\">Error loading items. Please try again.</div>';
                });
        }

        function renderItemsTable() {
            const container = document.getElementById('itemsTableContainer');
            const startIndex = (itemsCurrentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const pageItems = itemsData.slice(startIndex, endIndex);
            const totalPages = Math.max(1, Math.ceil(itemsData.length / itemsPerPage));

            let tableHTML = `
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="checkbox-column"><input type="checkbox" id="selectAllItemsLoc" onchange="toggleSelectAllItemsLoc(event)" /></th>
                            <th>ID</th>
                            <th>Item Name</th>
                            <th>Department</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Date Added</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            pageItems.forEach(item => {
                const statusClass = `status-${item.status.toLowerCase().replace(' ', '-')}`;
                tableHTML += `
                    <tr onclick="showItemDetails(${item.id})" style="cursor: pointer;">
                        <td class="checkbox-column" onclick="event.stopPropagation();"><input type="checkbox" class="loc-item-checkbox" data-id="${item.id}" ${selectedItemsLoc.has(item.id) ? 'checked' : ''} onchange="toggleItemSelectionLoc(${item.id}, event)" /></td>
                        <td>${item.id}</td>
                        <td>${item.name}</td>
                        <td>${item.department_name || 'N/A'}</td>
                        <td>${item.category_name || 'N/A'}</td>
                        <td>${item.quantity}</td>
                        <td><span class="status-badge ${statusClass}">${item.status}</span></td>
                        <td>${new Date(item.created_at).toLocaleDateString()}</td>
                        <td onclick="event.stopPropagation();">
                            <div class="action-dropdown">
                                <button class="action-btn" onclick="toggleActionMenu(${item.id}, event)">⋮</button>
                                <div class="action-menu" id="menu-item-${item.id}">
                                    <button onclick="showItemDetails(${item.id})">
                                        <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> 
                                        View Details
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                `;
            });

            tableHTML += `</tbody></table>`;
            const selectionBarHTML = `
                <div id="itemsSelectionBarLoc" class="item-selection-actions" style="display:flex;gap:8px;align-items:center;justify-content:space-between;margin:8px 0;">
                    <div><span id="itemsSelectionCountLoc">${selectedItemsLoc.size}</span> selected</div>
                    <div style="display:flex;gap:8px;">
                        <button class="item-selection-btn" data-requires-selection="1" id="moveSelectedBtnLoc" onclick="openMoveLocationModalLoc()" ${selectedItemsLoc.size===0?'disabled':''} style="background: white; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px; display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s;">
                            <img src="image/building-1062.png" alt="Move Location" style="width:16px;height:16px;filter: brightness(0) saturate(100%) invert(20%) sepia(0%) saturate(0%) hue-rotate(0deg) brightness(0%) contrast(100%);" />
                            Move Location
                        </button>
                        <button class="item-selection-btn primary" data-requires-selection="1" id="archiveSelectedBtnLoc" onclick="archiveSelectedItemsLoc()" ${selectedItemsLoc.size===0?'disabled':''} style="background: #dc2626; border: 1px solid #dc2626; color: white; padding: 8px 16px; border-radius: 6px; display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s;">
                            <img src="image/icons8-archive-50.png" alt="Archive" style="width:16px;height:16px;filter: brightness(0) invert(1);" />
                            Archive
                        </button>
                    </div>
                </div>`;
            container.innerHTML = selectionBarHTML + tableHTML;
            updateItemsSelectionBarLoc();

            // Render pagination
            const pagEl = document.getElementById('itemsPagination');
            if (pagEl) {
                pagEl.innerHTML = `
                    <div class="pagination" style="position:sticky;bottom:0;background:#fff;z-index:2;display:flex;flex-wrap:wrap;align-items:center;gap:10px;justify-content:flex-end;margin-top:10px;padding:8px 0;border-top:1px solid #eee;">
                        <button class="btn" ${itemsCurrentPage === 1 ? 'disabled' : ''} onclick="changeItemsPage('prev')">Previous</button>
                        <span>Page ${itemsCurrentPage} of ${Math.max(1, Math.ceil(itemsData.length / itemsPerPage))}</span>
                        <button class="btn" ${itemsCurrentPage === Math.max(1, Math.ceil(itemsData.length / itemsPerPage)) ? 'disabled' : ''} onclick="changeItemsPage('next')">Next</button>
                    </div>`;
            }
        }

        function changeItemsPage(direction) {
            const totalPages = Math.max(1, Math.ceil(itemsData.length / itemsPerPage));
            if (direction === 'prev' && itemsCurrentPage > 1) {
                itemsCurrentPage--;
            } else if (direction === 'next' && itemsCurrentPage < totalPages) {
                itemsCurrentPage++;
            }
            renderItemsTable();
        }

        function showItemDetails(itemId) {
            fetch(`?ajax=get_item_details&item_id=${itemId}`)
                .then(response => response.json())
                .then(item => {
                    if (item.error) {
                        modal.error('Error loading item details: ' + item.error);
                        return;
                    }

                    const statusClass = `status-${item.status.toLowerCase().replace(' ', '-')}`;
                    const content = `
                        <!-- Compact 2-Column Layout -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <!-- Left Column -->
                            <div style="display: flex; flex-direction: column; gap: 16px;">
                                <!-- Item Header Card -->
                                <div style="background: linear-gradient(135deg, #f8f9fa, #e9ecef); padding: 18px; border-radius: 10px; border-left: 4px solid #e53e3e;">
                                    <h2 style="margin: 0 0 10px 0; font-size: 22px; font-weight: 700; color: #2d3748; text-align: left;">${item.name}</h2>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <span style="background: #e53e3e; color: white; padding: 3px 10px; border-radius: 5px; font-size: 12px; font-weight: 600;">ID: ${item.id}</span>
                                        <span class="status-badge ${statusClass}" style="padding: 3px 10px; border-radius: 5px; font-size: 12px; font-weight: 600;">${item.status}</span>
                                        <span style="background: #4299e1; color: white; padding: 3px 10px; border-radius: 5px; font-size: 12px; font-weight: 600;">Qty: ${item.quantity}</span>
                                    </div>
                                </div>

                                <!-- Basic Info Card -->
                                <div style="background: white; padding: 16px; border-radius: 10px; border: 1px solid #e2e8f0;">
                                    <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #2d3748; display: flex; align-items: center; gap: 6px;">
                                        <span style="color: #e53e3e;">📋</span> Basic Information
                                    </h3>
                                    <div style="display: grid; gap: 10px;">
                                        <div style="padding: 6px 0; border-bottom: 1px solid #f7fafc;">
                                            <div style="font-size: 12px; color: #718096; font-weight: 600; margin-bottom: 4px;">Item Name:</div>
                                            <div style="font-size: 13px; color: #2d3748; font-weight: 500;">${item.name}</div>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f7fafc;">
                                            <span style="font-size: 12px; color: #718096; font-weight: 600;">Quantity:</span>
                                            <span style="font-size: 13px; color: #2d3748; font-weight: 500;">${item.quantity} units</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 6px 0;">
                                            <span style="font-size: 12px; color: #718096; font-weight: 600;">Status:</span>
                                            <span style="font-size: 13px; color: #2d3748; font-weight: 500;">${item.status}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Classification Card -->
                                <div style="background: white; padding: 16px; border-radius: 10px; border: 1px solid #e2e8f0;">
                                    <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #2d3748; display: flex; align-items: center; gap: 6px;">
                                        <span style="color: #e53e3e;">🏷️</span> Classification
                                    </h3>
                                    <div style="display: grid; gap: 10px;">
                                        <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f7fafc;">
                                            <span style="font-size: 12px; color: #718096; font-weight: 600;">Department:</span>
                                            <span style="font-size: 13px; color: #2d3748; font-weight: 500;">${item.department_name || 'N/A'}</span>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; padding: 6px 0;">
                                            <span style="font-size: 12px; color: #718096; font-weight: 600;">Category:</span>
                                            <span style="font-size: 13px; color: #2d3748; font-weight: 500;">${item.category_name || 'N/A'}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column -->
                            <div style="display: flex; flex-direction: column; gap: 16px;">
                                <!-- Location Card -->
                                <div style="background: white; padding: 16px; border-radius: 10px; border: 1px solid #e2e8f0;">
                                    <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #2d3748; display: flex; align-items: center; gap: 6px;">
                                    <span style="color: #e53e3e;">📍</span> Location
                                </h3>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                                    <div>
                                        <div style="font-size: 12px; color: #718096; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Building</div>
                                        <div style="font-size: 15px; color: #2d3748; font-weight: 500;">${item.building_name || 'N/A'}</div>
                                    </div>
                                    <div>
                                        <div style="font-size: 12px; color: #718096; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Floor</div>
                                        <div style="font-size: 15px; color: #2d3748; font-weight: 500;">${item.floor_name || 'N/A'}</div>
                                    </div>
                                    <div>
                                        <div style="font-size: 12px; color: #718096; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Room</div>
                                        <div style="font-size: 15px; color: #2d3748; font-weight: 500;">${item.room_name || 'N/A'}</div>
                                    </div>
                                </div>
                            </div>

                            ${item.description ? `
                            <!-- Description -->
                            <div class="detail-section" style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0;">
                                <h3 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 600; color: #2d3748; display: flex; align-items: center; gap: 8px;">
                                    <span style="color: #e53e3e;">📝</span> Description
                                </h3>
                                <div style="font-size: 15px; color: #4a5568; line-height: 1.6;">${item.description}</div>
                            </div>
                            ` : ''}

                            <!-- Timestamps -->
                            <div class="detail-section" style="background: white; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0;">
                                <h3 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 600; color: #2d3748; display: flex; align-items: center; gap: 8px;">
                                    <span style="color: #e53e3e;">🕒</span> Timestamps
                                </h3>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                                    <div>
                                        <div style="font-size: 12px; color: #718096; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Created</div>
                                        <div style="font-size: 15px; color: #2d3748; font-weight: 500;">${new Date(item.created_at).toLocaleString()}</div>
                                    </div>
                                    <div>
                                        <div style="font-size: 12px; color: #718096; font-weight: 600; text-transform: uppercase; margin-bottom: 4px;">Last Updated</div>
                                        <div style="font-size: 15px; color: #2d3748; font-weight: 500;">${new Date(item.updated_at).toLocaleString()}</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div style="display: flex; gap: 12px; justify-content: flex-end; padding-top: 8px;">
                                <button onclick="closeModal('itemDetailsModal')" class="btn btn-secondary" style="padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; border: 2px solid #e2e8f0; background: white; color: #4a5568; transition: all 0.2s;">
                                    Close
                                </button>
                                <button onclick="window.location.href='department.php?item_id=${item.id}'" class="btn btn-primary" style="padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; background: linear-gradient(135deg, #e53e3e, #c53030); color: white; transition: all 0.2s;">
                                    View in Department
                                </button>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('itemDetailsContent').innerHTML = content;
                    document.getElementById('itemDetailsModal').classList.add('show');
                })
                .catch(error => {
                    console.error('Error loading item details:', error);
                    modal.error('Error loading item details. Please try again.');
                });
        }

        // Scan Items Modal Functions
        let scannedItems = new Set();
        let html5QrCodeInstance = null;
        let scanItemsData = [];

        function openScanItemsModal() {
            if (!currentRoomId) {
                alert('Please select a room first');
                return;
            }

            document.getElementById('scanRoomName').textContent = currentRoomName;
            document.getElementById('scanItemsModal').classList.add('show');
            
            // Reset scanned items
            scannedItems.clear();
            
            // Load items for this room
            loadRoomItemsForScan(currentRoomId);
        }

        function closeScanItemsModal() {
            // Stop scanner if running
            if (html5QrCodeInstance) {
                stopScanner();
            }
            
            // Clear state
            scannedItems.clear();
            
            // Close modal
            document.getElementById('scanItemsModal').classList.remove('show');
        }

        function loadRoomItemsForScan(roomId) {
            fetch(`?ajax=get_room_items_for_scan&room_id=${roomId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error loading items: ' + data.error);
                        return;
                    }

                    scanItemsData = data.items || [];
                    renderChecklist();
                })
                .catch(error => {
                    console.error('Error loading items:', error);
                    alert('Error loading items for scanning');
                });
        }

        function renderChecklist() {
            const container = document.getElementById('itemsChecklist');
            const totalCount = scanItemsData.length;
            const scannedCount = scannedItems.size;

            document.getElementById('totalCount').textContent = totalCount;
            document.getElementById('scannedCount').textContent = scannedCount;

            if (totalCount === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 40px; color: #9ca3af;">No items in this room</div>';
                return;
            }

            let html = '';
            scanItemsData.forEach(item => {
                const isScanned = scannedItems.has(item.id);
                const statusClass = item.status === 'Lost' ? 'status-lost' : 'status-working';
                
                html += `
                    <div style="display: flex; align-items: center; gap: 10px; padding: 12px; background: ${isScanned ? '#ecfdf5' : 'white'}; border: 2px solid ${isScanned ? '#10b981' : '#e2e8f0'}; border-radius: 8px; margin-bottom: 8px; transition: all 0.2s;">
                        <input type="checkbox" ${isScanned ? 'checked' : ''} 
                               onchange="toggleItemScan(${item.id})" 
                               style="width: 20px; height: 20px; cursor: pointer;" />
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">${item.name}</div>
                            <div style="font-size: 12px; color: #6b7280;">${item.item_code || ''}</div>
                        </div>
                        <span class="status-badge ${statusClass}" style="padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;">${item.status}</span>
                    </div>
                `;
            });

            container.innerHTML = html;
        }

        function toggleItemScan(itemId) {
            if (scannedItems.has(itemId)) {
                scannedItems.delete(itemId);
            } else {
                scannedItems.add(itemId);
            }
            renderChecklist();
        }

        function startScanner() {
            if (!window.Html5Qrcode) {
                alert('QR scanner library not loaded. Please refresh the page.');
                return;
            }

            const qrCodeId = "qr-reader";
            html5QrCodeInstance = new Html5Qrcode(qrCodeId);
            
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            };

            // Start scanning
            html5QrCodeInstance.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanError
            )
            .catch(err => {
                console.error('Failed to start scanner:', err);
                alert('Failed to start camera. Please check permissions.');
            });

            document.getElementById('startScannerBtn').style.display = 'none';
            document.getElementById('stopScannerBtn').style.display = 'inline-flex';
            
            document.getElementById('scanStatus').style.display = 'block';
            document.getElementById('scanStatusText').textContent = 'Scanner started. Point camera at QR code.';
        }

        function stopScanner() {
            if (html5QrCodeInstance) {
                html5QrCodeInstance.stop().then(() => {
                    html5QrCodeInstance.clear();
                    html5QrCodeInstance = null;
                    
                    document.getElementById('startScannerBtn').style.display = 'inline-flex';
                    document.getElementById('stopScannerBtn').style.display = 'none';
                    document.getElementById('scanStatus').style.display = 'none';
                    
                    document.getElementById('qr-reader').innerHTML = `
                        <div style="text-align: center; color: #9ca3af;">
                            <div style="font-size: 40px; margin-bottom: 10px; font-weight: 300;">QR</div>
                            <div>Click Start to begin scanning</div>
                        </div>
                    `;
                }).catch(err => {
                    console.error('Error stopping scanner:', err);
                });
            }
        }

        function onScanSuccess(decodedText) {
            // Log the decoded text for debugging
            console.log('QR Code scanned:', decodedText);
            
            // FIRST: Check if it's an item table QR code
            if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                // Check if it's item table inventory URL
                if (decodedText.includes('item_table_inventory.php')) {
                    // Extract table ID from URL
                    const urlMatch = decodedText.match(/item_table_inventory\.php[?&]table_id=(\d+)/);
                    if (urlMatch) {
                        const tableId = urlMatch[1];
                        // Redirect to item table inventory page
                        window.location.href = `item_table_inventory.php?table_id=${tableId}`;
                        return;
                    }
                }
            }
            
            // Check if it's an item table QR code (starts with TABLE-)
            if (decodedText.startsWith('TABLE-') || decodedText.includes('TABLE-')) {
                // Extract table ID from QR code
                const tableIdMatch = decodedText.match(/TABLE-(\d+)/);
                if (tableIdMatch) {
                    const tableId = tableIdMatch[1];
                    // Redirect to item table inventory page
                    window.location.href = `item_table_inventory.php?table_id=${tableId}`;
                    return;
                }
            }
            
            // SECOND: Extract item ID from QR code - using same logic as qrscanner.php
            let itemId = null;
            
            // Try multiple parsing methods - matching qrscanner.php logic
            if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                // Full URL - check if it's our item URL
                if (decodedText.includes('view_item.php?id=')) {
                    // Extract ID from URL - try multiple methods
                    try {
                        const url = new URL(decodedText);
                        itemId = parseInt(url.searchParams.get('id'));
                    } catch (e) {
                        // Fallback: try to parse manually
                        const match = decodedText.match(/view_item\.php[?&]id=(\d+)/);
                        if (match) {
                            itemId = parseInt(match[1]);
                        } else {
                            // Try simpler pattern
                            const match2 = decodedText.match(/[?&]id=(\d+)/);
                            if (match2) {
                                itemId = parseInt(match2[1]);
                            }
                        }
                    }
                } else {
                    // External URL - try to extract ID if it has id parameter
                    const match = decodedText.match(/[?&]id=(\d+)/);
                    if (match) {
                        itemId = parseInt(match[1]);
                    }
                }
            } else if (decodedText.includes('view_item.php')) {
                // Relative URL with view_item.php - this is the most common format
                const match = decodedText.match(/view_item\.php[?&]id=(\d+)/);
                if (match) {
                    itemId = parseInt(match[1]);
                } else {
                    // Try simpler pattern
                    const match2 = decodedText.match(/id=(\d+)/);
                    if (match2) {
                        itemId = parseInt(match2[1]);
                    }
                }
            } else if (decodedText.includes('id=')) {
                // Has id parameter somewhere
                const match = decodedText.match(/id=(\d+)/);
                if (match) {
                    itemId = parseInt(match[1]);
                }
            } else if (/^\d+$/.test(decodedText.trim())) {
                // Plain number (item ID directly)
                itemId = parseInt(decodedText.trim());
            } else {
                // Try to parse as JSON
                try {
                    const data = JSON.parse(decodedText);
                    if (data.id) {
                        itemId = parseInt(data.id);
                    }
                } catch (e) {
                    // Not JSON, try to find any number in the text
                    const match = decodedText.match(/\d+/);
                    if (match) {
                        itemId = parseInt(match[0]);
                    }
                }
            }
            
            console.log('Extracted Item ID:', itemId);
            
            if (itemId) {
                // Check if item exists in the checklist
                const itemExists = scanItemsData.some(item => item.id === itemId);
                
                if (!itemExists) {
                    document.getElementById('scanStatusText').innerHTML = 
                        `<span style="color: #f59e0b;">⚠ Item ${itemId} not found in this room</span>`;
                    setTimeout(() => {
                        if (document.getElementById('scanStatusText')) {
                            document.getElementById('scanStatusText').textContent = 'Scanner active. Point camera at next QR code.';
                        }
                    }, 2000);
                    return;
                }
                
                // Add item to scanned items if not already scanned
                const wasAlreadyScanned = scannedItems.has(itemId);
                
                if (!wasAlreadyScanned) {
                    scannedItems.add(itemId);
                    renderChecklist();
                    
                    // Show success message
                    const itemName = scanItemsData.find(item => item.id === itemId)?.name || itemId;
                    document.getElementById('scanStatusText').innerHTML = 
                        `<span style="color: #10b981;">✓ Item scanned: ${itemName}</span>`;
                } else {
                    // Show already scanned message
                    const itemName = scanItemsData.find(item => item.id === itemId)?.name || itemId;
                    document.getElementById('scanStatusText').innerHTML = 
                        `<span style="color: #f59e0b;">⚠ Item already scanned: ${itemName}</span>`;
                }
                
                setTimeout(() => {
                    if (document.getElementById('scanStatusText')) {
                        document.getElementById('scanStatusText').textContent = 'Scanner active. Point camera at next QR code.';
                    }
                }, 2000);
            } else {
                console.error('Could not extract item ID from QR code:', decodedText);
                document.getElementById('scanStatusText').innerHTML = 
                    `<span style="color: #ef4444;">Invalid QR code format. Scanned: ${decodedText.substring(0, 50)}</span>`;
                setTimeout(() => {
                    if (document.getElementById('scanStatusText')) {
                        document.getElementById('scanStatusText').textContent = 'Scanner started. Point camera at QR code.';
                    }
                }, 3000);
            }
        }

        function onScanError(error) {
            // Error handling - can be ignored for continuous scanning
            console.log('Scan error:', error);
        }

        function completeScan() {
            if (scannedItems.size === 0) {
                simpleNotify('Notice', 'Please scan at least one item before completing.');
                return;
            }

            const scanEl = document.getElementById('scanItemsModal');
            const wasOpen = scanEl && scanEl.classList.contains('show');
            if (wasOpen) scanEl.classList.remove('show');

            simpleConfirm(`Complete scan with ${scannedItems.size} items scanned?`, 'Unscanned items will be marked as Missing.')
                .then(ok => {
                    if (!ok) { if (wasOpen) scanEl.classList.add('show'); return; }

                    const formData = new FormData();
                    formData.append('action', 'complete_room_scan');
                    formData.append('room_id', currentRoomId);
                    formData.append('scanned_item_ids', JSON.stringify(Array.from(scannedItems)));

                    fetch('location.php', { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                closeScanItemsModal();
                                simpleNotify('Success', 'Scan completed successfully! Missing items have been marked as Missing.');
                                stopScanner();
                                loadItems(currentRoomId);
                            } else {
                                simpleNotify('Error', 'Error completing scan: ' + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(err => {
                            console.error('Error completing scan:', err);
                            simpleNotify('Error', 'Error completing scan. Please try again.');
                        });
                });
        }

        function simpleConfirm(title, message) {
            return new Promise(resolve => {
                const overlay = document.createElement('div');
                overlay.style.position = 'fixed';
                overlay.style.inset = '0';
                overlay.style.background = 'rgba(0,0,0,0.45)';
                overlay.style.zIndex = '5000';
                overlay.style.display = 'flex';
                overlay.style.alignItems = 'center';
                overlay.style.justifyContent = 'center';

                const box = document.createElement('div');
                box.style.width = '92%';
                box.style.maxWidth = '460px';
                box.style.background = '#fff';
                box.style.border = '1px solid #e5e7eb';
                box.style.borderRadius = '12px';
                box.style.boxShadow = '0 12px 34px rgba(0,0,0,0.24)';

                const header = document.createElement('div');
                header.style.padding = '16px 18px';
                header.style.borderBottom = '1px solid #f1f5f9';
                header.style.fontWeight = '700';
                header.style.fontSize = '16px';
                header.textContent = title || 'Confirm';

                const body = document.createElement('div');
                body.style.padding = '16px 18px 6px 18px';
                body.style.color = '#374151';
                body.style.fontSize = '14px';
                body.textContent = message || '';

                const footer = document.createElement('div');
                footer.style.display = 'flex';
                footer.style.justifyContent = 'flex-end';
                footer.style.gap = '10px';
                footer.style.padding = '12px 18px 16px 18px';
                const cancelBtn = document.createElement('button');
                cancelBtn.textContent = 'Cancel';
                cancelBtn.style.padding = '10px 16px';
                cancelBtn.style.borderRadius = '8px';
                cancelBtn.style.border = '1px solid #e5e7eb';
                cancelBtn.style.background = '#f9fafb';
                cancelBtn.style.cursor = 'pointer';
                const okBtn = document.createElement('button');
                okBtn.textContent = 'Confirm';
                okBtn.style.padding = '10px 16px';
                okBtn.style.borderRadius = '8px';
                okBtn.style.border = 'none';
                okBtn.style.background = 'linear-gradient(135deg, #e53e3e, #c53030)';
                okBtn.style.color = '#fff';
                okBtn.style.cursor = 'pointer';
                footer.appendChild(cancelBtn);
                footer.appendChild(okBtn);

                box.appendChild(header);
                box.appendChild(body);
                box.appendChild(footer);
                overlay.appendChild(box);
                document.body.appendChild(overlay);

                function cleanup(result){ document.body.removeChild(overlay); resolve(result); }
                cancelBtn.addEventListener('click', () => cleanup(false));
                okBtn.addEventListener('click', () => cleanup(true));
                overlay.addEventListener('click', (e)=>{ if (e.target === overlay) cleanup(false); });
                setTimeout(() => okBtn.focus(), 50);
            });
        }

        function simpleNotify(title, message) {
            const overlay = document.createElement('div');
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.background = 'rgba(0,0,0,0.45)';
            overlay.style.zIndex = '5000';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            const box = document.createElement('div');
            box.style.width = '92%';
            box.style.maxWidth = '460px';
            box.style.background = '#fff';
            box.style.border = '1px solid #e5e7eb';
            box.style.borderRadius = '12px';
            box.style.boxShadow = '0 12px 34px rgba(0,0,0,0.24)';
            const header = document.createElement('div');
            header.style.padding = '16px 18px';
            header.style.borderBottom = '1px solid #f1f5f9';
            header.style.fontWeight = '700';
            header.style.fontSize = '16px';
            header.textContent = title || 'Notification';
            const body = document.createElement('div');
            body.style.padding = '16px 18px 6px 18px';
            body.style.color = '#374151';
            body.style.fontSize = '14px';
            body.textContent = message || '';
            const footer = document.createElement('div');
            footer.style.display = 'flex';
            footer.style.justifyContent = 'flex-end';
            footer.style.gap = '10px';
            footer.style.padding = '12px 18px 16px 18px';
            const okBtn = document.createElement('button');
            okBtn.textContent = 'OK';
            okBtn.style.padding = '10px 16px';
            okBtn.style.borderRadius = '8px';
            okBtn.style.border = 'none';
            okBtn.style.background = 'linear-gradient(135deg, #e53e3e, #c53030)';
            okBtn.style.color = '#fff';
            okBtn.style.cursor = 'pointer';
            footer.appendChild(okBtn);
            box.appendChild(header); box.appendChild(body); box.appendChild(footer);
            overlay.appendChild(box); document.body.appendChild(overlay);
            function close(){ document.body.removeChild(overlay); }
            okBtn.addEventListener('click', close);
            overlay.addEventListener('click', (e)=>{ if (e.target === overlay) close(); });
            setTimeout(() => okBtn.focus(), 50);
        }

        // Building management functions
        function loadBuildingDetails(buildingId) {
            fetch(`?ajax=get_building_details&building_id=${buildingId}`)
                .then(response => response.json())
                .then(building => {
                    if (building.error) {
                        modal.error('Error loading building details: ' + building.error);
                        return;
                    }
                    displayBuildingDetails(building);
                    document.getElementById('viewBuildingModal').classList.add('show');
                })
                .catch(error => {
                    console.error('Error loading building details:', error);
                    modal.error('Error loading building details. Please try again.');
                });
        }

       // Replace your displayBuildingDetails function with this updated version:

function displayBuildingDetails(building) {
    const content = document.getElementById('buildingDetailsContent');
    const canManageStructure = IS_SUPER_ADMIN;
    const imageHtml = building.image_path && building.image_path !== '' ? 
        `<div style="position: relative; width: 100%; height: 280px; border-radius: 12px; overflow: hidden; margin-bottom: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
            <img src="${building.image_path}" alt="${building.name}" style="width: 100%; height: 100%; object-fit: cover;">
            <div style="position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(0,0,0,0.7), transparent); padding: 20px; color: white;">
                <h2 style="margin: 0; font-size: 28px; font-weight: 700;">${building.name}</h2>
            </div>
        </div>` : 
        `<div style="background: linear-gradient(135deg, #f8f9fa, #e9ecef); height: 280px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #666; margin-bottom: 24px; border-radius: 12px; border: 2px dashed #dee2e6;">
            <div style="font-size: 64px; opacity: 0.3; margin-bottom: 12px;">🏢</div>
            <h2 style="margin: 0 0 8px 0; font-size: 28px; font-weight: 700; color: #495057;">${building.name}</h2>
            <p style="margin: 0; color: #6c757d;">No Image Available</p>
        </div>`;
    
    let floorsHtml = '';
    if (building.floors && building.floors.length > 0) {
        building.floors.forEach(floor => {
            let roomsHtml = '';
            if (floor.rooms && floor.rooms.length > 0) {
                floor.rooms.forEach(room => {
                    const roomActions = canManageStructure ? `
                        <div style="display: flex; gap: 6px;">
                            <button onclick="editRoom(${room.id})" style="background: #3b82f6; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 6px;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                                <img src="image/edit.png" alt="Edit" style="width: 14px; height: 14px; filter: brightness(0) invert(1);">
                            </button>
                            <button onclick="deleteRoom(${room.id}, '${room.room_name.replace(/'/g, "\\'")}');" style="background: #ef4444; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 6px;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                                <img src="image/delete.png" alt="Delete" style="width: 14px; height: 14px; filter: brightness(0) invert(1);">
                            </button>
                        </div>
                    ` : '';
                    roomsHtml += `
                        <div style="background: white; padding: 14px 16px; margin: 8px 0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #e9ecef; transition: all 0.2s ease;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #2d3748; font-size: 15px; margin-bottom: 4px;">
                                    <span style="background: #e53e3e; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-right: 8px;">Room ${room.room_number}</span>
                                    ${room.room_name}
                                </div>
                            </div>
                            ${roomActions}
                        </div>
                    `;
                });
            } else {
                roomsHtml = '<div style="color: #718096; font-style: italic; padding: 16px; text-align: center; background: #f8f9fa; border-radius: 8px; margin: 8px 0;">No rooms on this floor</div>';
            }
            
            const floorActions = canManageStructure ? `
                        <div style="display: flex; gap: 6px;">
                            <button onclick="editFloor(${floor.id})" style="background: #3b82f6; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 6px;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='#3b82f6'">
                                <img src="image/edit.png" alt="Edit" style="width: 14px; height: 14px; filter: brightness(0) invert(1);">
                            </button>
                            <button onclick="deleteFloor(${floor.id}, '${floor.floor_name.replace(/'/g, "\\'")}');" style="background: #ef4444; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 6px;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                                <img src="image/delete.png" alt="Delete" style="width: 14px; height: 14px; filter: brightness(0) invert(1);">
                            </button>
                        </div>` : '';

            floorsHtml += `
                <div style="background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 10px; margin: 12px 0; padding: 18px; transition: all 0.2s;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 2px solid #e2e8f0;">
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 4px 0; font-size: 18px; font-weight: 600; color: #1f2937;">${floor.floor_name}</h4>
                            <div style="font-size: 13px; color: #6b7280;">
                                <span style="background: white; padding: 3px 10px; border-radius: 4px; display: inline-block;">Floor ${floor.floor_number}</span>
                                <span style="margin-left: 8px; color: #9ca3af;">•</span>
                                <span style="margin-left: 8px;">${floor.room_count} ${floor.room_count === 1 ? 'Room' : 'Rooms'}</span>
                            </div>
                        </div>
                        ${floorActions}
                    </div>
                    ${floor.description ? `<p style="color: #6b7280; margin: 0 0 12px 0; font-size: 14px; padding: 8px; background: white; border-radius: 6px;">${floor.description}</p>` : ''}
                    <div style="margin-top: 12px;">
                        ${roomsHtml}
                    </div>
                </div>
            `;
        });
    } else {
        floorsHtml = '<div style="text-align: center; padding: 40px; color: #9ca3af; background: #f8f9fa; border-radius: 10px; border: 2px dashed #e2e8f0;"><div style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;">🏢</div><div style="font-size: 16px;">No floors added yet</div></div>';
    }
    
    const buildingActions = canManageStructure ? `
                <div style="display: flex; gap: 10px; flex-shrink: 0;">
                    <button onclick="editBuilding(${building.id})" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(59, 130, 246, 0.3)'">
                        <img src="image/edit.png" alt="Edit" style="width: 16px; height: 16px; filter: brightness(0) invert(1);">
                        Edit Building
                    </button>
                    <button onclick="deleteBuilding(${building.id}, '${building.name.replace(/'/g, "\\'")}');" style="background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(239, 68, 68, 0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(239, 68, 68, 0.3)'">
                        <img src="image/delete.png" alt="Delete" style="width: 16px; height: 16px; filter: brightness(0) invert(1);">
                        Delete
                    </button>
                </div>
    ` : '';

    content.innerHTML = `
        <div style="padding: 24px;">
            ${imageHtml}
            
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; gap: 16px; flex-wrap: wrap;">
                ${!building.image_path ? '' : `<h2 style="margin: 0; font-size: 28px; font-weight: 700; color: #1f2937; flex: 1;">${building.name}</h2>`}
                ${buildingActions}
            </div>
            
            ${building.description ? `<div style="background: linear-gradient(135deg, #f8f9fa, #e9ecef); padding: 16px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #e53e3e;">
                <div style="font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px;">Description</div>
                <p style="color: #374151; margin: 0; font-size: 15px; line-height: 1.6;">${building.description}</p>
            </div>` : ''}
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 24px 0;">
                <div style="background: white; padding: 18px; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Total Floors</div>
                    <div style="font-size: 28px; font-weight: 700; color: #e53e3e;">${building.floor_count}</div>
                </div>
                <div style="background: white; padding: 18px; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Total Rooms</div>
                    <div style="font-size: 28px; font-weight: 700; color: #e53e3e;">${building.room_count}</div>
                </div>
                <div style="background: white; padding: 18px; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Date Built</div>
                    <div style="font-size: 16px; font-weight: 600; color: #374151;">${building.date_built ? new Date(building.date_built).toLocaleDateString() : 'N/A'}</div>
                </div>
                <div style="background: white; padding: 18px; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="font-size: 12px; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 8px;">Created</div>
                    <div style="font-size: 16px; font-weight: 600; color: #374151;">${new Date(building.created_at).toLocaleDateString()}</div>
                </div>
            </div>
            
            <div style="margin-top: 32px;">
                <h3 style="font-size: 20px; font-weight: 600; color: #1f2937; margin-bottom: 16px; display: flex; align-items: center; gap: 10px;">
                    <img src="image/layers.png" alt="Floors" style="width: 24px; height: 24px;">
                    Floors & Rooms
                </h3>
                ${floorsHtml}
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 32px; padding-top: 24px; border-top: 2px solid #e2e8f0;">
                <button onclick="closeModal('viewBuildingModal')" style="background: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6; padding: 10px 24px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#f8f9fa'">
                    Close
                </button>
            </div>
        </div>
    `;
}

        function editBuilding(buildingId) {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to edit buildings.');
                } else {
                    alert('You do not have permission to edit buildings.');
                }
                return;
            }
            fetch(`?ajax=get_building_details&building_id=${buildingId}`)
                .then(response => response.json())
                .then(building => {
                    if (building.error) {
                        modal.error('Error loading building data: ' + building.error);
                        return;
                    }
                    
                    document.getElementById('edit_building_id').value = building.id;
                    document.getElementById('edit_building_name').value = building.name;
                    document.getElementById('edit_building_description').value = building.description || '';
                    document.getElementById('edit_date_built').value = building.date_built || '';
                    
                    // Show current image preview
                    const preview = document.getElementById('currentImagePreview');
                    if (building.image_path) {
                        preview.innerHTML = `<img src="${building.image_path}" alt="Current image" style="max-width: 200px; max-height: 100px; object-fit: cover; border-radius: 4px;">`;
                    } else {
                        preview.innerHTML = '<div style="color: #666;">No current image</div>';
                    }
                    
                    closeModal('viewBuildingModal');
                    document.getElementById('editBuildingModal').classList.add('show');
                })
                .catch(error => {
                    console.error('Error loading building data:', error);
                    modal.error('Error loading building data for editing.');
                });
        }

        // Delete functions
        function deleteBuilding(buildingId, buildingName) {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to delete buildings.');
                } else {
                    alert('You do not have permission to delete buildings.');
                }
                return;
            }
            deleteTargetId = buildingId;
            deleteTargetType = 'building';
            document.getElementById('deleteMessage').textContent = `Are you sure you want to delete the building "${buildingName}"? This action cannot be undone.`;
            closeModal('viewBuildingModal');
            document.getElementById('deleteConfirmModal').classList.add('show');
        }

        function deleteFloor(floorId, floorName) {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to delete floors.');
                } else {
                    alert('You do not have permission to delete floors.');
                }
                return;
            }
            deleteTargetId = floorId;
            deleteTargetType = 'floor';
            document.getElementById('deleteMessage').textContent = `Are you sure you want to delete the floor "${floorName}"? This will also delete all rooms on this floor.`;
            closeModal('viewBuildingModal');
            document.getElementById('deleteConfirmModal').classList.add('show');
        }

        function deleteRoom(roomId, roomName) {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to delete rooms.');
                } else {
                    alert('You do not have permission to delete rooms.');
                }
                return;
            }
            deleteTargetId = roomId;
            deleteTargetType = 'room';
            document.getElementById('deleteMessage').textContent = `Are you sure you want to delete the room "${roomName}"?`;
            closeModal('viewBuildingModal');
            document.getElementById('deleteConfirmModal').classList.add('show');
        }

        function deleteItem(itemId, itemName) {
            deleteTargetId = itemId;
            deleteTargetType = 'item';
            document.getElementById('deleteMessage').textContent = `Are you sure you want to delete the item "${itemName}"?`;
            document.getElementById('deleteConfirmModal').classList.add('show');
        }

        function confirmDelete() {
            if (!IS_SUPER_ADMIN && deleteTargetType && deleteTargetType !== 'item') {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to perform this deletion.');
                } else {
                    alert('You do not have permission to perform this deletion.');
                }
                closeModal('deleteConfirmModal');
                return;
            }
            if (deleteTargetId && deleteTargetType) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = `delete_${deleteTargetType}`;
                
                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = `${deleteTargetType}_id`;
                idInput.value = deleteTargetId;
                
                form.appendChild(actionInput);
                form.appendChild(idInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Edit floor function
        function editFloor(floorId) {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to edit floors.');
                } else {
                    alert('You do not have permission to edit floors.');
                }
                return;
            }
            fetch(`?ajax=get_floor_details&floor_id=${floorId}`)
                .then(response => response.json())
                .then(floor => {
                    if (floor.error) {
                        modal.error('Error loading floor details: ' + floor.error);
                        return;
                    }
                    
                    document.getElementById('edit_floor_id').value = floor.id;
                    document.getElementById('edit_floor_name').value = floor.floor_name;
                    document.getElementById('edit_floor_number').value = floor.floor_number || '';
                    document.getElementById('edit_floor_description').value = floor.description || '';
                    
                    closeModal('viewBuildingModal');
                    document.getElementById('editFloorModal').classList.add('show');
                })
                .catch(error => {
                    console.error('Error loading floor data:', error);
                    modal.error('Error loading floor data for editing.');
                });
        }

        // Edit room function
        function editRoom(roomId) {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to edit rooms.');
                } else {
                    alert('You do not have permission to edit rooms.');
                }
                return;
            }
            fetch(`?ajax=get_room_details&room_id=${roomId}`)
                .then(response => response.json())
                .then(room => {
                    if (room.error) {
                        modal.error('Error loading room details: ' + room.error);
                        return;
                    }
                    
                    document.getElementById('edit_room_id').value = room.id;
                    document.getElementById('edit_room_name').value = room.room_name;
                    document.getElementById('edit_room_number').value = room.room_number || '';
                    document.getElementById('edit_room_description').value = room.description || '';
                    
                    closeModal('viewBuildingModal');
                    document.getElementById('editRoomModal').classList.add('show');
                })
                .catch(error => {
                    console.error('Error loading room data:', error);
                    modal.error('Error loading room data for editing.');
                });
        }

        function editItem(itemId) {
            modal.info('Item editing functionality would be implemented here for Item ID: ' + itemId);
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            const form = document.querySelector(`#${modalId} form`);
            if (form) {
                form.reset();
            }
        }

        function showAddBuildingModal() {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to add buildings.');
                } else {
                    alert('You do not have permission to add buildings.');
                }
                return;
            }
            document.getElementById('addBuildingModal').classList.add('show');
        }

        // Alert functions
        function closeAlert(alertId) {
            const alert = document.getElementById(alertId);
            if (alert) {
                alert.style.display = 'none';
            }
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        // Dropdown functions
        function showBuildingOptions(buildingId, element) {
            if (!IS_SUPER_ADMIN) {
                return;
            }
            currentBuildingId = buildingId;
            const dropdown = document.getElementById('buildingOptions');
            const rect = element.getBoundingClientRect();
            
            dropdown.style.top = (rect.bottom + window.scrollY) + 'px';
            dropdown.style.left = (rect.left + window.scrollX - 120) + 'px';
            dropdown.classList.add('show');
            
            setTimeout(() => {
                document.addEventListener('click', closeDropdown);
            }, 0);
        }

        function closeDropdown() {
            document.getElementById('buildingOptions').classList.remove('show');
            document.removeEventListener('click', closeDropdown);
        }

        function addFloorToBuilding() {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to add floors.');
                } else {
                    alert('You do not have permission to add floors.');
                }
                closeDropdown();
                return;
            }
            if (currentBuildingId) {
                document.getElementById('floor_building_id').value = currentBuildingId;
                document.getElementById('addFloorModal').classList.add('show');
            }
            closeDropdown();
        }

        function addRoomToBuilding() {
            if (!IS_SUPER_ADMIN) {
                if (typeof modal !== 'undefined' && typeof modal.warning === 'function') {
                    modal.warning('You do not have permission to add rooms.');
                } else {
                    alert('You do not have permission to add rooms.');
                }
                closeDropdown();
                return;
            }
            if (currentBuildingId) {
                loadFloorsForRoom(currentBuildingId);
                document.getElementById('addRoomModal').classList.add('show');
            }
            closeDropdown();
        }

        function viewBuildingDetails() {
            if (currentBuildingId) {
                loadBuildingDetails(currentBuildingId);
            }
            closeDropdown();
        }

        // Load floors for room selection
        function loadFloorsForRoom(buildingId) {
            fetch(`?ajax=get_floors&building_id=${buildingId}`)
                .then(response => response.json())
                .then(floors => {
                    const select = document.getElementById('room_floor_id');
                    select.innerHTML = '<option value="">Choose a floor...</option>';
                    floors.forEach(floor => {
                        select.innerHTML += `<option value="${floor.id}">${floor.floor_name} (Floor ${floor.floor_number})</option>`;
                    });
                })
                .catch(error => {
                    console.error('Error loading floors:', error);
                    modal.error('Error loading floors. Please try again.');
                });
        }

        // Sign out functionality
        function signOut() {
            showSignOutModal();
        }

        function showSignOutModal() {
            document.getElementById('signOutModal').classList.add('show');
        }

        function closeSignOutModal() {
            document.getElementById('signOutModal').classList.remove('show');
        }

        function confirmSignOut() {
            const confirmBtn = document.getElementById('confirmSignOut');
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = 'Signing out...';
            
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 1000);
        }

        // Search functionality
        const searchBar = document.querySelector('.search-bar');
        if (searchBar) {
            let searchTimeout;
            searchBar.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const searchTerm = this.value.toLowerCase();
                    const locationCards = document.querySelectorAll('.location-card');
                    let visibleCount = 0;
                    
                    locationCards.forEach(card => {
                        const buildingName = card.querySelector('h3').textContent.toLowerCase();
                        if (buildingName.includes(searchTerm)) {
                            card.style.display = 'block';
                            visibleCount++;
                        } else {
                            card.style.display = 'none';
                        }
                    });
                    
                    // Show "No results found" message if needed
                    if (visibleCount === 0 && searchTerm.length > 0) {
                        showNoResultsMessage();
                    } else {
                        hideNoResultsMessage();
                    }
                }, 300);
            });
        }

        function showNoResultsMessage() {
            let existingMessage = document.getElementById('noResultsMessage');
            if (!existingMessage) {
                const message = document.createElement('div');
                message.id = 'noResultsMessage';
                message.style.cssText = 'text-align: center; padding: 40px; color: #666; font-size: 16px;';
                message.innerHTML = `
                    <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                    <div>No buildings found matching your search.</div>
                `;
                document.querySelector('.locations-grid').appendChild(message);
            }
        }

        function hideNoResultsMessage() {
            const message = document.getElementById('noResultsMessage');
            if (message) {
                message.remove();
            }
        }

        // Form validation
        function validateForm(formElement) {
            const requiredFields = formElement.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = '#dc3545';
                    isValid = false;
                } else {
                    field.style.borderColor = '#ddd';
                }
            });
            
            return isValid;
        }

        // File upload validation
        function validateFileUpload(fileInput) {
            const file = fileInput.files[0];
            if (file) {
                // Check file size (5MB limit)
                if (file.size > 5 * 1024 * 1024) {
                    modal.warning('File size must be less than 5MB.');
                    fileInput.value = '';
                    return false;
                }
                
                // Check file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    modal.warning('Only JPEG, PNG, GIF, and WebP images are allowed.');
                    fileInput.value = '';
                    return false;
                }
            }
            return true;
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize the page with grid view
            showGridView();

            // Add form validation to all forms
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!validateForm(this)) {
                        e.preventDefault();
                        modal.warning('Please fill in all required fields.');
                        return;
                    }
                    
                    // Add loading state to submit button
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        const originalText = submitBtn.textContent;
                        submitBtn.innerHTML = '<div class="loading-spinner" style="width: 16px; height: 16px; border: 2px solid #f3f3f3; border-top: 2px solid #333; border-radius: 50%; animation: spin 1s linear infinite; display: inline-block; margin-right: 8px;"></div>' + originalText;
                        
                        // Re-enable button after 5 seconds as fallback
                        setTimeout(() => {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }, 5000);
                    }
                });
            });

            // File upload validation
            const fileInputs = document.querySelectorAll('input[type="file"]');
            fileInputs.forEach(input => {
                input.addEventListener('change', function() {
                    validateFileUpload(this);
                });
            });

            // Navigation handling
            document.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    if (!this.getAttribute('onclick')) {
                        document.querySelectorAll('.nav-link').forEach(nav => nav.classList.remove('active'));
                        this.classList.add('active');
                    }
                });
            });
        });

        // Close modals when clicking outside or pressing Escape
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                e.target.classList.remove('show');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.show').forEach(modal => {
                    modal.classList.remove('show');
                });
                closeDropdown();
            }
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Add smooth animations to location cards
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.location-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                    this.style.transition = 'all 0.2s ease';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
                });
            });

            // Initialize tooltips for truncated text
            document.querySelectorAll('.location-card h3, .location-card .location-description').forEach(element => {
                if (element.scrollWidth > element.clientWidth) {
                    element.title = element.textContent;
                }
            });
        });

        // Enhanced dropdown positioning
        function positionDropdown(dropdown, trigger) {
            const rect = trigger.getBoundingClientRect();
            const dropdownRect = dropdown.getBoundingClientRect();
            
            let top = rect.bottom + window.scrollY;
            let left = rect.left + window.scrollX - 120;
            
            // Adjust if dropdown would go off-screen
            if (left + dropdownRect.width > window.innerWidth) {
                left = window.innerWidth - dropdownRect.width - 10;
            }
            
            if (left < 10) {
                left = 10;
            }
            
            dropdown.style.top = top + 'px';
            dropdown.style.left = left + 'px';
        }

        // Error handling for AJAX requests
        function handleAjaxError(error, context) {
            console.error(`Error in ${context}:`, error);
            return `<div class="no-data">Error loading ${context}. Please try again.</div>`;
        }

        // Utility function to escape HTML
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Debounce function for search
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Add loading states to action buttons
        function addLoadingState(button, originalText) {
            button.disabled = true;
            button.innerHTML = `<div class="loading-spinner" style="width: 16px; height: 16px; border: 2px solid #f3f3f3; border-top: 2px solid #333; border-radius: 50%; animation: spin 1s linear infinite; display: inline-block; margin-right: 8px;"></div>${originalText}`;
            
            setTimeout(() => {
                button.disabled = false;
                button.innerHTML = originalText;
            }, 5000);
        }

        // Keyboard navigation support
        document.addEventListener('keydown', function(e) {
            // Handle Enter key on clickable elements
            if (e.key === 'Enter' && e.target.classList.contains('location-card')) {
                e.target.click();
            }
            
            // Handle arrow keys for navigation in tables
            if (currentView !== 'grid' && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                const rows = document.querySelectorAll('.data-table tbody tr');
                const currentRow = document.querySelector('.data-table tbody tr:focus');
                
                if (rows.length > 0) {
                    let index = Array.from(rows).indexOf(currentRow);
                    
                    if (e.key === 'ArrowDown') {
                        index = (index + 1) % rows.length;
                    } else if (e.key === 'ArrowUp') {
                        index = (index - 1 + rows.length) % rows.length;
                    }
                    
                    rows[index].focus();
                    e.preventDefault();
                }
            }
        });

        // Add accessibility attributes
        document.addEventListener('DOMContentLoaded', function() {
            // Make location cards keyboard accessible
            document.querySelectorAll('.location-card').forEach(card => {
                card.setAttribute('tabindex', '0');
                card.setAttribute('role', 'button');
                card.setAttribute('aria-label', 'View building details');
            });
            
            // Make table rows keyboard accessible
            document.addEventListener('click', function() {
                document.querySelectorAll('.data-table tbody tr').forEach(row => {
                    row.setAttribute('tabindex', '0');
                    row.setAttribute('role', 'button');
                });
            });
        });

        // Performance optimization: Virtual scrolling for large datasets
        function createVirtualTable(data, containerSelector, rowHeight = 50) {
            const container = document.querySelector(containerSelector);
            const viewportHeight = container.clientHeight;
            const visibleRows = Math.ceil(viewportHeight / rowHeight);
            let startIndex = 0;

            function renderVisibleRows() {
                const endIndex = Math.min(startIndex + visibleRows, data.length);
                const visibleData = data.slice(startIndex, endIndex);
                
                // Render only visible rows
                // This would be implemented based on specific table structure
            }

            container.addEventListener('scroll', debounce(() => {
                startIndex = Math.floor(container.scrollTop / rowHeight);
                renderVisibleRows();
            }, 16)); // ~60fps
        }

        // Export functionality (future enhancement)
        function exportToCSV(data, filename) {
            const csvContent = "data:text/csv;charset=utf-8," 
                + data.map(row => Object.values(row).join(",")).join("\n");
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", filename);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Print functionality
        function printTable(tableSelector) {
            const table = document.querySelector(tableSelector);
            if (table) {
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                        <head>
                            <title>Print Table</title>
                            <style>
                                table { border-collapse: collapse; width: 100%; }
                                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                                th { background-color: #f2f2f2; }
                            </style>
                        </head>
                        <body>
                            ${table.outerHTML}
                        </body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.print();
            }
        }

        // Initialize all functionality when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            console.log('CABIS Location Management System initialized');
            
            // Check for any initialization errors
            try {
                showGridView();
            } catch (error) {
                console.error('Error initializing application:', error);
                modal.error('There was an error initializing the application. Please refresh the page.');
            }           
        });
        // Toggle action menu function
function toggleActionMenu(id, event) {
    event.stopPropagation();
    
    // Close all other open menus
    document.querySelectorAll('.action-menu').forEach(menu => {
        if (menu.id !== `menu-floor-${id}` && 
            menu.id !== `menu-room-${id}` && 
            menu.id !== `menu-item-${id}`) {
            menu.classList.remove('show');
        }
    });
    
    // Toggle current menu
    const floorMenu = document.getElementById(`menu-floor-${id}`);
    const roomMenu = document.getElementById(`menu-room-${id}`);
    const itemMenu = document.getElementById(`menu-item-${id}`);
    
    if (floorMenu) floorMenu.classList.toggle('show');
    if (roomMenu) roomMenu.classList.toggle('show');
    if (itemMenu) itemMenu.classList.toggle('show');
    
    // Close menu when clicking outside
    setTimeout(() => {
        document.addEventListener('click', function closeMenu(e) {
            if (!e.target.closest('.action-dropdown')) {
                document.querySelectorAll('.action-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
                document.removeEventListener('click', closeMenu);
            }
        });
    }, 0);
}
    </script>
</body>
</html>
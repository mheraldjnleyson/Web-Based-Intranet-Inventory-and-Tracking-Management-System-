<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include database connection
require_once '../db_connect.php';

// Set content type
header('Content-Type: application/json');

// Get the action
$action = $_GET['action'] ?? '';

// Test endpoint
if ($action === 'test') {
    echo json_encode(['success' => true, 'message' => 'API is working']);
    exit();
}

try {
    switch ($action) {
        case 'stats':
            // Get dashboard statistics - ONLY if department is provided
            $department_filter = $_GET['department'] ?? '';
            
            if (empty($department_filter)) {
                // Return empty stats if no department
                echo json_encode([
                    'success' => true, 
                    'stats' => [
                        'total_items' => 0,
                        'low_stock' => 0,
                        'not_working' => 0,
                        'active_borrows' => 0,
                        'recent_activities' => 0
                    ]
                ]);
                break;
            }
            
            // Get dashboard statistics for selected department
            $stats = [];
            
            // Get total items count
            $total_items_query = "SELECT COUNT(*) as count FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE d.name = ?";
            $total_items_stmt = $conn->prepare($total_items_query);
            $total_items_stmt->bind_param("s", $department_filter);
            $total_items_stmt->execute();
            $total_items_result = $total_items_stmt->get_result();
            $stats['total_items'] = $total_items_result ? $total_items_result->fetch_assoc()['count'] : 0;
            $total_items_stmt->close();
            
            // Get low stock items (only consumable items with quantity <= 5)
            $low_stock_query = "
                SELECT COUNT(*) as count 
                FROM items i 
                LEFT JOIN departments d ON i.department_id = d.id 
                LEFT JOIN item_tables it ON i.item_table_id = it.id
                WHERE d.name = ? 
                AND i.quantity <= 5 
                AND i.quantity > 0
                AND i.status = 'Working'
                AND COALESCE(it.is_consumable, 0) = 1
            ";
            $low_stock_stmt = $conn->prepare($low_stock_query);
            $low_stock_stmt->bind_param("s", $department_filter);
            $low_stock_stmt->execute();
            $low_stock_result = $low_stock_stmt->get_result();
            $stats['low_stock'] = $low_stock_result ? $low_stock_result->fetch_assoc()['count'] : 0;
            $low_stock_stmt->close();
            
            // Get not working items (Broken and Under Maintenance status)
            $not_working_query = "SELECT COUNT(*) as count FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE (i.status = 'Broken' OR i.status = 'Under Maintenance') AND d.name = ?";
            $not_working_stmt = $conn->prepare($not_working_query);
            $not_working_stmt->bind_param("s", $department_filter);
            $not_working_stmt->execute();
            $not_working_result = $not_working_stmt->get_result();
            $stats['not_working'] = $not_working_result ? $not_working_result->fetch_assoc()['count'] : 0;
            $not_working_stmt->close();
            
            // Get active borrows
            $active_borrows_query = "SELECT COUNT(*) as count FROM borrow_history bh LEFT JOIN items i ON bh.item_id = i.id LEFT JOIN departments d ON i.department_id = d.id WHERE bh.status = 'active' AND d.name = ?";
            $active_borrows_stmt = $conn->prepare($active_borrows_query);
            $active_borrows_stmt->bind_param("s", $department_filter);
            $active_borrows_stmt->execute();
            $active_borrows_result = $active_borrows_stmt->get_result();
            $stats['active_borrows'] = $active_borrows_result ? $active_borrows_result->fetch_assoc()['count'] : 0;
            $active_borrows_stmt->close();
            
            // Get recent activities (last 7 days)
            $recent_activities_query = "SELECT COUNT(*) as count FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE i.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND d.name = ?";
            $recent_activities_stmt = $conn->prepare($recent_activities_query);
            $recent_activities_stmt->bind_param("s", $department_filter);
            $recent_activities_stmt->execute();
            $recent_activities_result = $recent_activities_stmt->get_result();
            $stats['recent_activities'] = $recent_activities_result ? $recent_activities_result->fetch_assoc()['count'] : 0;
            $recent_activities_stmt->close();
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        case 'chart_data':
            // Get chart data for the specified number of days
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
            $chart_data = [];
            
            // Low stocks by day - show actual daily low stock activity for working items
            $low_stock_weekly = $conn->query("
                SELECT DAYNAME(updated_at) as day_name, COUNT(*) as count 
                FROM items 
                WHERE quantity <= 5 AND status = 'Working' AND updated_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
                GROUP BY DAYNAME(updated_at)
                ORDER BY updated_at
            ");
            
            $low_stock_data = [];
            if ($low_stock_weekly) {
                while ($row = $low_stock_weekly->fetch_assoc()) {
                    $low_stock_data[$row['day_name']] = (int)$row['count'];
                }
            }
            
            // Activities by day - show actual daily activity
            $activities_weekly = $conn->query("
                SELECT DAYNAME(updated_at) as day_name, COUNT(*) as count 
                FROM items 
                WHERE updated_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
                GROUP BY DAYNAME(updated_at)
                ORDER BY updated_at
            ");
            
            $activities_data = [];
            if ($activities_weekly) {
                while ($row = $activities_weekly->fetch_assoc()) {
                    $activities_data[$row['day_name']] = (int)$row['count'];
                }
            }
            
            // Borrow items by day - show actual daily borrow activity
            $borrow_weekly = $conn->query("
                SELECT DAYNAME(borrow_date) as day_name, COUNT(*) as count 
                FROM borrow_history 
                WHERE borrow_date >= DATE_SUB(NOW(), INTERVAL $days DAY)
                GROUP BY DAYNAME(borrow_date)
                ORDER BY borrow_date
            ");
            
            $borrow_data = [];
            if ($borrow_weekly) {
                while ($row = $borrow_weekly->fetch_assoc()) {
                    $borrow_data[$row['day_name']] = (int)$row['count'];
                }
            }
            
            
            // Not working items by day - show current broken and under maintenance items distributed across week
            $not_working_weekly = $conn->query("
                SELECT DAYNAME(updated_at) as day_name, COUNT(*) as count 
                FROM items 
                WHERE (status = 'Broken' OR status = 'Under Maintenance') AND updated_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
                GROUP BY DAYNAME(updated_at)
                ORDER BY updated_at
            ");
            
            $not_working_data = [];
            if ($not_working_weekly) {
                while ($row = $not_working_weekly->fetch_assoc()) {
                    $not_working_data[$row['day_name']] = (int)$row['count'];
                }
            }
            
            // If no recent data but we have not working items, distribute them across the week
            if (empty($not_working_data)) {
                // Get current broken and under maintenance items count
                $not_working_count_result = $conn->query("SELECT COUNT(*) as count FROM items WHERE status = 'Broken' OR status = 'Under Maintenance'");
                $total_not_working = $not_working_count_result ? $not_working_count_result->fetch_assoc()['count'] : 0;
                
                if ($total_not_working > 0) {
                    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    
                    // Distribute items across days (more on weekdays, less on weekends)
                    $distribution = [0.1, 0.2, 0.2, 0.2, 0.2, 0.1, 0.0]; // Sunday to Saturday
                    foreach ($days as $index => $day) {
                        $not_working_data[$day] = round($total_not_working * $distribution[$index]);
                    }
                    
                    // Ensure at least one item appears if we have broken items
                    if ($total_not_working > 0 && array_sum($not_working_data) == 0) {
                        $not_working_data['Monday'] = 1; // Put at least one on Monday
                    }
                }
            }
            
            $chart_data = [
                'low_stock' => $low_stock_data,
                'activities' => $activities_data,
                'borrow' => $borrow_data,
                'not_working' => $not_working_data
            ];
            
            echo json_encode(['success' => true, 'chart_data' => $chart_data]);
            break;
            
        case 'items':
            // Get filtered items with pagination - ONLY if department is provided
            $search = $_GET['search'] ?? '';
            $department_filter = $_GET['department'] ?? '';
            $category_filter = $_GET['category'] ?? '';
            $status_filter = $_GET['status'] ?? '';
            
            // Return empty if no department
            if (empty($department_filter)) {
                echo json_encode([
                    'success' => true, 
                    'items' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'total_pages' => 0,
                        'total_items' => 0,
                        'items_per_page' => 10
                    ]
                ]);
                break;
            }
            
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $items_per_page = 10;
            $offset = ($page - 1) * $items_per_page;
            
            $where_conditions = [];
            $params = [];
            $param_types = '';
            
            // Department is required
            $where_conditions[] = "d.name = ?";
            $params[] = $department_filter;
            $param_types .= 's';
            
            if (!empty($search)) {
                // Handle special filter cases
                if ($search === 'low_stock_filter') {
                    $where_conditions[] = "i.quantity <= 5 AND i.status = 'Working'";
                } elseif ($search === 'recent_activities_filter') {
                    $where_conditions[] = "i.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                } elseif ($search === 'borrow_items_filter') {
                    // Show items that have active borrows
                    $where_conditions[] = "i.id IN (SELECT DISTINCT item_id FROM borrow_history WHERE status = 'active')";
                } else {
                    // Regular search
                    $where_conditions[] = "(i.name LIKE ? OR i.description LIKE ? OR i.location LIKE ?)";
                    $search_param = "%$search%";
                    $params[] = $search_param;
                    $params[] = $search_param;
                    $params[] = $search_param;
                    $param_types .= 'sss';
                }
            }
            
            if (!empty($category_filter)) {
                $where_conditions[] = "i.category = ?";
                $params[] = $category_filter;
                $param_types .= 's';
            }
            
            if (!empty($status_filter)) {
                if ($status_filter === 'Broken') {
                    // For "Not Working" filter, show both Broken and Under Maintenance
                    $where_conditions[] = "(i.status = 'Broken' OR i.status = 'Under Maintenance')";
                } else {
                    $where_conditions[] = "i.status = ?";
                    $params[] = $status_filter;
                    $param_types .= 's';
                }
            }
            
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
            
            // Get total count
            $count_query = "
                SELECT COUNT(*) as total
                FROM items i 
                LEFT JOIN departments d ON i.department_id = d.id 
                $where_clause
            ";
            
            $count_stmt = $conn->prepare($count_query);
            if (!empty($params)) {
                $count_stmt->bind_param($param_types, ...$params);
            }
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $total_items = $count_result->fetch_assoc()['total'];
            $total_pages = ceil($total_items / $items_per_page);
            
            $items_query = "
                SELECT 
                    i.id,
                    i.name,
                    i.item_code,
                    i.department_id,
                    d.name as department_name,
                    i.category,
                    i.quantity,
                    i.location,
                    i.status,
                    i.description,
                    i.created_at,
                    i.updated_at,
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
                $where_clause
                ORDER BY i.updated_at DESC
                LIMIT $items_per_page OFFSET $offset
            ";
            
            $items_stmt = $conn->prepare($items_query);
            if (!empty($params)) {
                $items_stmt->bind_param($param_types, ...$params);
            }
            $items_stmt->execute();
            $items_result = $items_stmt->get_result();
            $items = $items_result->fetch_all(MYSQLI_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'items' => $items,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'total_items' => $total_items,
                    'items_per_page' => $items_per_page
                ]
            ]);
            break;
            
        case 'low_stock_items':
            $day = $_GET['day'] ?? '';
            
            // If specific day is requested, show items that went low stock on that day
            if ($day && $day !== 'All Days') {
                $low_stock_query = "
                    SELECT i.id, i.name, i.quantity, i.status, i.location, i.category, d.name as department_name
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE i.quantity <= 5 
                    AND i.status = 'Working'
                    AND DAYNAME(i.updated_at) = ?
                    ORDER BY i.quantity ASC
                ";
                $stmt = $conn->prepare($low_stock_query);
                $stmt->bind_param("s", $day);
                $stmt->execute();
                $result = $stmt->get_result();
                $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            } else {
                // Show all low stock items - only working items
                $low_stock_query = "
                    SELECT i.id, i.name, i.quantity, i.status, i.location, i.category, d.name as department_name
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE i.quantity <= 5 
                    AND i.status = 'Working'
                    ORDER BY i.quantity ASC
                ";
                $result = $conn->query($low_stock_query);
                $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            }
            
            echo json_encode(['success' => true, 'items' => $items]);
            break;
            
        case 'activity_items':
            $day = $_GET['day'] ?? '';
            
            // If specific day is requested, show items updated on that day
            if ($day && $day !== 'All Days') {
                $activity_query = "
                    SELECT i.id, i.name, i.quantity, i.status, i.location, i.category, d.name as department_name, i.updated_at
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE DAYNAME(i.updated_at) = ?
                    ORDER BY i.updated_at DESC
                ";
                $stmt = $conn->prepare($activity_query);
                $stmt->bind_param("s", $day);
                $stmt->execute();
                $result = $stmt->get_result();
                $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            } else {
                // Show recent activities (last 7 days)
                $activity_query = "
                    SELECT i.id, i.name, i.quantity, i.status, i.location, i.category, d.name as department_name, i.updated_at
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE DATE(i.updated_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    ORDER BY i.updated_at DESC
                    LIMIT 20
                ";
                $result = $conn->query($activity_query);
                $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            }
            
            echo json_encode(['success' => true, 'items' => $items]);
            break;
            
        case 'borrow_items':
            $day = $_GET['day'] ?? '';
            
            // If specific day is requested, show items borrowed on that day
            if ($day && $day !== 'All Days') {
                $borrow_query = "
                    SELECT i.id, i.name, i.quantity, i.status, i.location, i.category, d.name as department_name, bh.borrow_date
                    FROM borrow_history bh
                    LEFT JOIN items i ON bh.item_id = i.id
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE DAYNAME(bh.borrow_date) = ?
                    ORDER BY bh.borrow_date DESC
                ";
                $stmt = $conn->prepare($borrow_query);
                $stmt->bind_param("s", $day);
                $stmt->execute();
                $result = $stmt->get_result();
                $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            } else {
                // Show recent borrows (last 7 days)
                $borrow_query = "
                    SELECT i.id, i.name, i.quantity, i.status, i.location, i.category, d.name as department_name, bh.borrow_date
                    FROM borrow_history bh
                    LEFT JOIN items i ON bh.item_id = i.id
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE DATE(bh.borrow_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    ORDER BY bh.borrow_date DESC
                    LIMIT 20
                ";
                $result = $conn->query($borrow_query);
                $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            }
            
            echo json_encode(['success' => true, 'items' => $items]);
            break;
            
        case 'status_items':
            $status = $_GET['status'] ?? '';
            
            if ($status === 'Not Working') {
                // For "Not Working", get both Broken and Under Maintenance items
                $status_query = "
                    SELECT i.id, i.name, i.quantity, i.status, i.location, i.category, d.name as department_name
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE i.status = 'Broken' OR i.status = 'Under Maintenance'
                    ORDER BY i.name ASC
                ";
                $result = $conn->query($status_query);
                $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            } else {
                // For "Working", get items that are not broken or under maintenance
                $status_query = "
                    SELECT i.id, i.name, i.quantity, i.status, i.location, i.category, d.name as department_name
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE i.status = 'Working'
                    ORDER BY i.name ASC
                ";
                $result = $conn->query($status_query);
                $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            }
            
            echo json_encode(['success' => true, 'items' => $items]);
            break;
            
        case 'card_items':
            // Get items for dashboard cards - requires department filter
            $department_filter = $_GET['department'] ?? '';
            $card_type = $_GET['card_type'] ?? ''; // 'total_items', 'low_stock', 'not_working'
            $search = $_GET['search'] ?? '';
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $items_per_page = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 50; // Default 50 items per page
            
            if (empty($department_filter)) {
                echo json_encode(['success' => false, 'error' => 'Department is required']);
                break;
            }
            
            $where_conditions = [];
            $params = [];
            $param_types = '';
            
            // Department is required - use TRIM for exact matching
            $where_conditions[] = "TRIM(d.name) = TRIM(?)";
            $params[] = $department_filter;
            $param_types .= 's';
            
            // Apply card type filter
            switch($card_type) {
                case 'total_items':
                    // All items - no additional filter
                    break;
                case 'low_stock':
                    // Low stock items: consumable items with quantity <= 5 and status = 'Working'
                    // Use COALESCE to handle NULL/Empty status as 'Working'
                    // Use EXACT same logic as display_status to check if consumable (for consistency)
                    // Handle Empty/NULL status as 'Working' (Empty status should be treated as Working)
                    $where_conditions[] = "i.quantity <= 5 AND i.quantity > 0 AND (COALESCE(i.status, 'Working') = 'Working' OR i.status = '' OR i.status IS NULL) AND i.item_table_id IS NOT NULL AND EXISTS (
                        SELECT 1 FROM item_tables it 
                        WHERE it.id = i.item_table_id 
                        AND COALESCE(it.is_consumable, 0) = 1
                    )";
                    break;
                case 'not_working':
                    // Not working items: Filter item tables that have defective items (Broken or Under Maintenance)
                    // This will be used in the item_tables query, so we filter by item_table_id
                    $where_conditions[] = "it.id IN (
                        SELECT DISTINCT i2.item_table_id 
                        FROM items i2 
                        INNER JOIN item_tables it2 ON i2.item_table_id = it2.id
                        LEFT JOIN departments d2 ON i2.department_id = d2.id
                        WHERE (i2.status = 'Broken' OR i2.status = 'Under Maintenance')
                        AND i2.item_table_id IS NOT NULL
                        AND TRIM(d2.name) = TRIM(d.name)
                    )";
                    break;
                case 'pending_requests':
                    // Pending requests - handled separately below
                    break;
                default:
                    echo json_encode(['success' => false, 'error' => 'Invalid card type']);
                    exit();
            }
            
            // Add search filter if provided
            if (!empty($search)) {
                $where_conditions[] = "(i.name LIKE ? OR i.description LIKE ? OR i.location LIKE ? OR i.category LIKE ?)";
                $search_param = "%$search%";
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
                $param_types .= 'ssss';
            }
            
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            
            // For pending_requests, return borrow requests with item information
            if ($card_type === 'pending_requests') {
                try {
                    // Get total count for pagination
                    $count_query = "
                        SELECT COUNT(*) as total
                        FROM borrow_history bh
                        LEFT JOIN items i ON bh.item_id = i.id
                        LEFT JOIN departments d ON i.department_id = d.id
                        WHERE bh.status = 'pending' AND TRIM(d.name) = TRIM(?)
                    ";
                    
                    $count_stmt = $conn->prepare($count_query);
                    if (!$count_stmt) {
                        throw new Exception("Failed to prepare count query: " . $conn->error);
                    }
                    
                    $count_stmt->bind_param("s", $department_filter);
                    if (!$count_stmt->execute()) {
                        throw new Exception("Failed to execute count query: " . $count_stmt->error);
                    }
                    
                    $count_result = $count_stmt->get_result();
                    $total_items = $count_result ? $count_result->fetch_assoc()['total'] : 0;
                    $total_pages = ceil($total_items / $items_per_page);
                    $offset = ($page - 1) * $items_per_page;
                    $count_stmt->close();
                    
                    // Build search condition for pending requests
                    $search_condition = '';
                    $search_params = [];
                    $search_param_types = '';
                    if (!empty($search)) {
                        $search_condition = " AND (i.name LIKE ? OR i.description LIKE ? OR i.location LIKE ? OR i.category LIKE ? OR bh.borrower_name LIKE ?)";
                        $search_param = "%$search%";
                        $search_params = [$search_param, $search_param, $search_param, $search_param, $search_param];
                        $search_param_types = 'sssss';
                    }
                    
                    // Get pending requests with item information
                    $items_query = "
                        SELECT 
                            bh.id as request_id,
                            bh.item_id,
                            bh.borrower_name as requested_by,
                            bh.borrow_date as request_date,
                            bh.due_date as expected_return_date,
                            bh.purpose,
                            bh.status as request_status,
                            i.id,
                            i.name,
                            i.item_code,
                            i.department_id,
                            d.name as department_name,
                            i.category,
                            i.quantity,
                            i.location,
                            i.status,
                            i.description,
                            i.item_table_id,
                            it.table_name,
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM borrow_history bh2 
                                    WHERE bh2.item_id = i.id 
                                    AND bh2.status IN ('approved', 'active', 'overdue', 'received')
                                ) THEN 'Borrowed'
                                WHEN EXISTS (
                                    SELECT 1 FROM item_tables it2 
                                    WHERE it2.id = i.item_table_id 
                                    AND COALESCE(it2.is_consumable, 0) = 1
                                ) THEN 'Consumable'
                                ELSE COALESCE(i.status, 'Working')
                            END as display_status
                        FROM borrow_history bh
                        LEFT JOIN items i ON bh.item_id = i.id
                        LEFT JOIN departments d ON i.department_id = d.id
                        LEFT JOIN item_tables it ON i.item_table_id = it.id
                        WHERE bh.status = 'pending' AND TRIM(d.name) = TRIM(?)
                        $search_condition
                        ORDER BY bh.borrow_date DESC
                        LIMIT $items_per_page OFFSET $offset
                    ";
                    
                    $items_stmt = $conn->prepare($items_query);
                    if (!$items_stmt) {
                        throw new Exception("Failed to prepare items query: " . $conn->error);
                    }
                    
                    $bind_params = [$department_filter];
                    $bind_types = 's';
                    
                    if (!empty($search_params)) {
                        $bind_params = array_merge($bind_params, $search_params);
                        $bind_types .= $search_param_types;
                    }
                    
                    $items_stmt->bind_param($bind_types, ...$bind_params);
                    if (!$items_stmt->execute()) {
                        throw new Exception("Failed to execute items query: " . $items_stmt->error);
                    }
                    
                    $items_result = $items_stmt->get_result();
                    $items = $items_result ? $items_result->fetch_all(MYSQLI_ASSOC) : [];
                    $items_stmt->close();
                    
                    // Return response for pending_requests immediately
                    echo json_encode([
                        'success' => true, 
                        'items' => $items,
                        'pagination' => [
                            'current_page' => $page,
                            'total_pages' => $total_pages,
                            'total_items' => $total_items,
                            'items_per_page' => $items_per_page
                        ]
                    ]);
                    break;
                } catch (Exception $e) {
                    error_log("Pending requests API error: " . $e->getMessage());
                    echo json_encode([
                        'success' => false, 
                        'error' => 'Failed to load pending requests: ' . $e->getMessage()
                    ]);
                    break;
                }
            }
            // For low_stock, total_items, and not_working, return item tables (not individual items)
            else if ($card_type === 'low_stock' || $card_type === 'total_items' || $card_type === 'not_working') {
                // Get total count for pagination - count distinct item tables
                $count_query = "
                    SELECT COUNT(*) as total FROM (
                        SELECT DISTINCT it.id
                        FROM item_tables it
                        LEFT JOIN departments d ON it.department_id = d.id 
                        LEFT JOIN items i ON it.id = i.item_table_id
                        $where_clause
                        GROUP BY it.id
                        HAVING COUNT(i.id) > 0
                    ) as filtered_tables
                ";
                
                $count_stmt = $conn->prepare($count_query);
                if (!empty($params)) {
                    $count_stmt->bind_param($param_types, ...$params);
                }
                $count_stmt->execute();
                $count_result = $count_stmt->get_result();
                $total_items = $count_result ? $count_result->fetch_assoc()['total'] : 0;
                $total_pages = ceil($total_items / $items_per_page);
                $offset = ($page - 1) * $items_per_page;
                
                // Get item tables with item counts grouped
                // For low_stock: only tables that have low stock consumable items
                // For total_items: all item tables that have items
                // For not_working: only tables that have defective items, count only defective items
                $item_count_expression = "COUNT(i.id)";
                $items_where_addition = "";
                if ($card_type === 'not_working') {
                    $item_count_expression = "SUM(CASE WHEN (i.status = 'Broken' OR i.status = 'Under Maintenance') THEN 1 ELSE 0 END)";
                    // Also filter items in WHERE clause to only count defective items
                    $items_where_addition = " AND (i.status = 'Broken' OR i.status = 'Under Maintenance')";
                }
                
                // Add items filter to where clause if needed
                $final_where_clause = $where_clause;
                if (!empty($items_where_addition)) {
                    if (empty($where_clause)) {
                        $final_where_clause = "WHERE 1=1" . $items_where_addition;
                    } else {
                        $final_where_clause = $where_clause . $items_where_addition;
                    }
                }
                
                $items_query = "
                    SELECT 
                        it.id,
                        it.table_name,
                        it.category,
                        it.department_id,
                        d.name as department_name,
                        it.description,
                        it.table_image_path,
                        it.priority,
                        it.is_consumable,
                        $item_count_expression as item_count,
                        MAX(i.updated_at) as last_updated
                    FROM item_tables it
                    LEFT JOIN departments d ON it.department_id = d.id 
                    LEFT JOIN items i ON it.id = i.item_table_id
                    $final_where_clause
                    GROUP BY it.id, it.table_name, it.category, it.department_id, d.name, it.description, it.table_image_path, it.priority, it.is_consumable
                    HAVING $item_count_expression > 0
                    ORDER BY it.table_name ASC
                    LIMIT $items_per_page OFFSET $offset
                ";
                
                $items_stmt = $conn->prepare($items_query);
                if (!empty($params)) {
                    $items_stmt->bind_param($param_types, ...$params);
                }
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result ? $items_result->fetch_all(MYSQLI_ASSOC) : [];
            } else {
                // For other card types, return individual items
                // No special JOIN needed since we use EXISTS in WHERE clause
                $join_clause = '';
                
                // Get total count for pagination
                $count_query = "
                    SELECT COUNT(*) as total
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    $join_clause
                    $where_clause
                ";
                
                $count_stmt = $conn->prepare($count_query);
                if (!empty($params)) {
                    $count_stmt->bind_param($param_types, ...$params);
                }
                $count_stmt->execute();
                $count_result = $count_stmt->get_result();
                $total_items = $count_result ? $count_result->fetch_assoc()['total'] : 0;
                $total_pages = ceil($total_items / $items_per_page);
                $offset = ($page - 1) * $items_per_page;
                
                // Get items with pagination
                // Use regular LEFT JOIN for item_tables (EXISTS in WHERE handles consumable check)
                $items_query = "
                    SELECT 
                        i.id,
                        i.name,
                        i.item_code,
                        i.department_id,
                        d.name as department_name,
                        i.category,
                        i.quantity,
                        i.location,
                        i.status,
                        i.description,
                        i.item_table_id,
                        it.table_name,
                        CASE 
                            WHEN EXISTS (
                                SELECT 1 FROM borrow_history bh 
                                WHERE bh.item_id = i.id 
                                AND bh.status IN ('approved', 'active', 'overdue', 'received')
                            ) THEN 'Borrowed'
                            WHEN EXISTS (
                                SELECT 1 FROM item_tables it2 
                                WHERE it2.id = i.item_table_id 
                                AND COALESCE(it2.is_consumable, 0) = 1
                            ) THEN 'Consumable'
                            ELSE COALESCE(i.status, 'Working')
                        END as display_status
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    LEFT JOIN item_tables it ON i.item_table_id = it.id
                    $where_clause
                    ORDER BY it.table_name ASC, i.name ASC
                    LIMIT $items_per_page OFFSET $offset
                ";
                
                $items_stmt = $conn->prepare($items_query);
                if (!empty($params)) {
                    $items_stmt->bind_param($param_types, ...$params);
                }
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                $items = $items_result ? $items_result->fetch_all(MYSQLI_ASSOC) : [];
            }
            
            echo json_encode([
                'success' => true, 
                'items' => $items,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'total_items' => $total_items,
                    'items_per_page' => $items_per_page
                ]
            ]);
            break;
            
        case 'item_tables':
            // Get item tables with item counts - only tables that have items
            $item_tables_query = "
                SELECT 
                    it.id,
                    it.table_name,
                    it.category,
                    it.department_id,
                    d.name as department_name,
                    it.description,
                    it.table_image_path,
                    COUNT(i.id) as item_count,
                    SUM(i.quantity) as total_quantity
                FROM item_tables it 
                LEFT JOIN departments d ON it.department_id = d.id 
                LEFT JOIN items i ON it.id = i.item_table_id
                GROUP BY it.id, it.table_name, it.category, it.department_id, d.name, it.description, it.table_image_path
                HAVING COUNT(i.id) > 0
                ORDER BY it.table_name ASC
            ";
            $result = $conn->query($item_tables_query);
            
            if ($result) {
                $item_tables = [];
                while ($row = $result->fetch_assoc()) {
                    $item_tables[] = $row;
                }
                
                echo json_encode(['success' => true, 'item_tables' => $item_tables]);
            } else {
                throw new Exception("Failed to fetch item tables: " . $conn->error);
            }
            break;
            
        case 'items_by_table':
            $table_id = $_GET['table_id'] ?? '';
            $search = $_GET['search'] ?? '';
            $special_filter = $_GET['special_filter'] ?? '';
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $items_per_page = isset($_GET['items_per_page']) ? (int)$_GET['items_per_page'] : 50;
            
            if (empty($table_id)) {
                echo json_encode(['error' => 'Table ID is required']);
                break;
            }
            
            $where_conditions = ["i.item_table_id = ?"];
            $params = [$table_id];
            $param_types = 'i';
            
            // Add special filter for defective items
            if ($special_filter === 'not_working') {
                $where_conditions[] = "(i.status = 'Broken' OR i.status = 'Under Maintenance')";
            }
            
            // Add search filter if provided
            if (!empty($search)) {
                $where_conditions[] = "(i.name LIKE ? OR i.description LIKE ? OR i.location LIKE ? OR i.category LIKE ?)";
                $search_param = "%$search%";
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
                $params[] = $search_param;
                $param_types .= 'ssss';
            }
            
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            
            // Get total count
            $count_query = "
                SELECT COUNT(*) as total
                FROM items i 
                LEFT JOIN departments d ON i.department_id = d.id 
                LEFT JOIN item_tables it ON i.item_table_id = it.id
                $where_clause
            ";
            
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->bind_param($param_types, ...$params);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $total_items = $count_result ? $count_result->fetch_assoc()['total'] : 0;
            $total_pages = ceil($total_items / $items_per_page);
            $offset = ($page - 1) * $items_per_page;
            
            // Get items in a specific table with pagination
            $items_query = "
                SELECT 
                    i.id,
                    i.name,
                    i.item_code,
                    i.quantity,
                    i.status,
                    i.location,
                    i.category,
                    i.description,
                    d.name as department_name,
                    it.table_name,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM borrow_history bh 
                            WHERE bh.item_id = i.id 
                            AND bh.status IN ('approved', 'active', 'overdue', 'received')
                        ) THEN 'Borrowed'
                        WHEN EXISTS (
                            SELECT 1 FROM item_tables it2 
                            WHERE it2.id = i.item_table_id 
                            AND COALESCE(it2.is_consumable, 0) = 1
                        ) THEN 'Consumable'
                        ELSE COALESCE(i.status, 'Working')
                    END as display_status
                FROM items i 
                LEFT JOIN departments d ON i.department_id = d.id 
                LEFT JOIN item_tables it ON i.item_table_id = it.id
                $where_clause
                ORDER BY i.name ASC
                LIMIT $items_per_page OFFSET $offset
            ";
            
            $stmt = $conn->prepare($items_query);
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                $items = [];
                while ($row = $result->fetch_assoc()) {
                    $items[] = $row;
                }
                
                echo json_encode([
                    'success' => true, 
                    'items' => $items,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $total_pages,
                        'total_items' => $total_items,
                        'items_per_page' => $items_per_page
                    ]
                ]);
            } else {
                throw new Exception("Failed to fetch items: " . $stmt->error);
            }
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    error_log("Dashboard API error: " . $e->getMessage());
    echo json_encode(['error' => 'Server error']);
}
?>
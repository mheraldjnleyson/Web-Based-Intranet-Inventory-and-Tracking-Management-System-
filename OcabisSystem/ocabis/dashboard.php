<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    // Not logged in → redirect to login
    header("Location: login.php");
    exit();
}

// Include database connection
require_once '../db_connect.php';

// Check if user is a viewer (teacher) - no department and not admin
// Viewers should not access dashboard, redirect to department.php
$isViewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);
if ($isViewer) {
    header("Location: department.php");
    exit();
}

// Get user's department and admin status
$currentUserDepartment = isset($_SESSION['department']) ? trim($_SESSION['department']) : '';
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
// Admin role: is_admin = 1 AND role = 'admin' (different from department head)
$isAdminRole = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
// Check if user is admin or super admin (for general admin access, but not database export/import)
$isAdminOrSuperAdmin = $isSuperAdmin || $isAdminRole;
// Check if user is admin (for department head detection)
$isAdmin = (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) || $isSuperAdmin;
// Department head: admin but not super admin
$isDepartmentHead = $isAdmin && !$isSuperAdmin;

// Build department filter for queries (only if not super admin)
$dept_filter_sql = '';
$dept_filter_params = [];
if (!$isSuperAdmin && !empty($currentUserDepartment)) {
    $dept_filter_sql = " AND d.name = ?";
    $dept_filter_params[] = $currentUserDepartment;
}

// Get department filter from GET parameter
$department_filter = isset($_GET['department']) ? trim($_GET['department']) : '';

// IMPORTANT: Department filter logic
// - For department heads (non-super-admins): Auto-use their department, show data immediately
// - For super-admins: Require explicit department selection before showing data
if (!$isSuperAdmin && !empty($currentUserDepartment)) {
    // Department heads: Auto-use their department if not explicitly set
    if (empty($department_filter)) {
        // Auto-redirect to include department filter so data loads immediately (only if no GET params)
        if (empty($_GET) || (count($_GET) === 1 && isset($_GET['page']))) {
            $redirect_url = "dashboard.php?department=" . urlencode($currentUserDepartment);
            if (isset($_GET['page'])) {
                $redirect_url .= "&page=" . $_GET['page'];
            }
            header("Location: " . $redirect_url);
            exit();
        }
        $department_filter = trim($currentUserDepartment);
    } else {
        // Ensure department filter is trimmed
        $department_filter = trim($department_filter);
    }
    $hasDepartmentFilter = !empty($department_filter);
} else {
    // Super-admins: Require explicit department selection
    $department_filter = trim($department_filter);
    $hasDepartmentFilter = !empty($department_filter);
}

// Get dashboard statistics - ONLY if department is selected
$stats = [
    'total_items' => 0,
    'low_stock' => 0,
    'not_working' => 0,
    'active_borrows' => 0,
    'recent_activities' => 0
];

// Only load stats if department filter is set
if ($hasDepartmentFilter) {
    try {
        // Get total items count
        $total_items_query = "SELECT COUNT(*) as count FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE d.name = ?";
        $total_items_stmt = $conn->prepare($total_items_query);
        $total_items_stmt->bind_param("s", $department_filter);
        $total_items_stmt->execute();
        $total_items_result = $total_items_stmt->get_result();
        $stats['total_items'] = $total_items_result ? $total_items_result->fetch_assoc()['count'] : 0;
        $total_items_stmt->close();

        // Get low stock item tables (count distinct item tables that have low stock consumable items)
        // Only consumable item tables with items that have quantity <= 5
        // Use TRIM and case-insensitive comparison for department name
        // Handle Empty/NULL status as 'Working' (Empty status should be treated as Working)
        $low_stock_query = "
            SELECT COUNT(DISTINCT i.item_table_id) as count 
            FROM items i 
            INNER JOIN departments d ON i.department_id = d.id 
            INNER JOIN item_tables it ON i.item_table_id = it.id
            WHERE TRIM(d.name) = TRIM(?) 
            AND i.quantity <= 5 
            AND i.quantity > 0
            AND (COALESCE(i.status, 'Working') = 'Working' OR i.status = '' OR i.status IS NULL)
            AND i.item_table_id IS NOT NULL
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

        // Get pending borrow requests count (for admin/super admin)
        $is_admin_role = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
        $is_admin_or_super = $isSuperAdmin || $is_admin_role || $isDepartmentHead;
        if ($is_admin_or_super) {
            // Filter by selected department if one is selected (even for super admin)
            if (!empty($department_filter)) {
                // Filter by selected department
                $pending_requests_query = "SELECT COUNT(*) as count FROM borrow_history bh 
                    LEFT JOIN items i ON bh.item_id = i.id 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE bh.status = 'pending' AND d.name = ?";
                $pending_requests_stmt = $conn->prepare($pending_requests_query);
                if ($pending_requests_stmt) {
                    $pending_requests_stmt->bind_param("s", $department_filter);
                    $pending_requests_stmt->execute();
                    $pending_requests_result = $pending_requests_stmt->get_result();
                    $stats['pending_requests'] = $pending_requests_result ? $pending_requests_result->fetch_assoc()['count'] : 0;
                    $pending_requests_stmt->close();
                } else {
                    $stats['pending_requests'] = 0;
                }
            } elseif ($isSuperAdmin) {
                // Super admin with no department selected: count all pending requests
                $pending_requests_query = "SELECT COUNT(*) as count FROM borrow_history bh WHERE bh.status = 'pending'";
                $pending_requests_stmt = $conn->prepare($pending_requests_query);
                if ($pending_requests_stmt) {
                    $pending_requests_stmt->execute();
                    $pending_requests_result = $pending_requests_stmt->get_result();
                    $stats['pending_requests'] = $pending_requests_result ? $pending_requests_result->fetch_assoc()['count'] : 0;
                    $pending_requests_stmt->close();
                } else {
                    $stats['pending_requests'] = 0;
                }
            } else {
                // Department head: count only their department's pending requests
                $pending_requests_query = "SELECT COUNT(*) as count FROM borrow_history bh 
                    LEFT JOIN items i ON bh.item_id = i.id 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE bh.status = 'pending' AND d.name = ?";
                $pending_requests_stmt = $conn->prepare($pending_requests_query);
                if ($pending_requests_stmt) {
                    $pending_requests_stmt->bind_param("s", $currentUserDepartment);
                    $pending_requests_stmt->execute();
                    $pending_requests_result = $pending_requests_stmt->get_result();
                    $stats['pending_requests'] = $pending_requests_result ? $pending_requests_result->fetch_assoc()['count'] : 0;
                    $pending_requests_stmt->close();
                } else {
                    $stats['pending_requests'] = 0;
                }
            }
        } else {
            $stats['pending_requests'] = 0;
        }
    } catch (Exception $e) {
        // Set default values if there's an error
        $stats = [
            'total_items' => 0,
            'low_stock' => 0,
            'not_working' => 0,
            'active_borrows' => 0,
            'recent_activities' => 0,
            'pending_requests' => 0
        ];
    }
}

// Charts removed - no longer needed

// Get item tables grouped data for the table
$search = isset($_GET['search']) ? $_GET['search'] : '';
$special_filter = isset($_GET['special_filter']) ? $_GET['special_filter'] : '';
// $department_filter and $hasDepartmentFilter already defined above
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Check if filters have been explicitly applied
// Item tables should only show when user has applied filters (not just auto-selected department)
$filtersApplied = false;
if ($hasDepartmentFilter) {
    // Check if there are any filter parameters in the URL (beyond just department and page)
    // This means the user has explicitly interacted with the filters
    $hasFilterParams = !empty($search) || !empty($category_filter) || !empty($status_filter) || !empty($special_filter);
    
    // For super admins: department selection in URL counts as applying a filter (they explicitly select it)
    // For department heads: need explicit filter parameters (search, category, status, or special_filter)
    if ($isSuperAdmin) {
        // Super admin explicitly selected department = filter applied
        $filtersApplied = !empty($department_filter);
    } else {
        // Department heads: need explicit filter parameters (not just auto-selected department)
        $filtersApplied = $hasFilterParams;
    }
}

// Pagination parameters
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;

$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(it.table_name LIKE ? OR i.name LIKE ? OR i.description LIKE ? OR i.location LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'ssss';
}

// Handle special filters from card clicks
if (!empty($special_filter)) {
    switch($special_filter) {
        case 'all_items':
            // Show all items - no additional filter needed
            // This just ensures filtersApplied is true
            break;
        case 'low_stock':
            // Filter item tables that have consumable items with quantity <= 5
            // This will be used in the item_tables query, so we filter by item_table_id
            $where_conditions[] = "it.id IN (
                SELECT DISTINCT i2.item_table_id 
                FROM items i2 
                INNER JOIN item_tables it2 ON i2.item_table_id = it2.id
                WHERE i2.quantity <= 5 
                AND i2.quantity > 0
                AND (COALESCE(i2.status, '') = 'Working' OR COALESCE(i2.status, '') = '')
                AND CAST(COALESCE(it2.is_consumable, 0) AS UNSIGNED) = 1
                AND i2.item_table_id IS NOT NULL
            )";
            break;
        case 'recent_activities':
            $where_conditions[] = "i.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'borrow_items':
            $where_conditions[] = "i.id IN (SELECT DISTINCT item_id FROM borrow_history WHERE status = 'active')";
            break;
        case 'not_working':
            // Filter item tables that have defective items (Broken or Under Maintenance)
            // This will be used in the item_tables query, so we filter by item_table_id
            // The department filter will be applied separately in the main query
            $where_conditions[] = "it.id IN (
                SELECT DISTINCT i2.item_table_id 
                FROM items i2 
                INNER JOIN item_tables it2 ON i2.item_table_id = it2.id
                LEFT JOIN departments d2 ON i2.department_id = d2.id
                WHERE (i2.status = 'Broken' OR i2.status = 'Under Maintenance')
                AND i2.item_table_id IS NOT NULL
                AND d2.name = d.name
            )";
            break;
    }
}

// Enforce department scoping for non-super-admins
if (!$isSuperAdmin && !empty($currentUserDepartment)) {
    // For non-super-admins: always filter by their department
    $where_conditions[] = "d.name = ?";
    $params[] = $currentUserDepartment;
    $param_types .= 's';
} else {
    // For super admins: filter by selected department or show all if empty
    if (!empty($department_filter)) {
        $where_conditions[] = "d.name = ?";
        $params[] = $department_filter;
        $param_types .= 's';
    }
    // If empty (All Departments selected), don't add any filter - show all
}

if (!empty($category_filter)) {
    $where_conditions[] = "i.category = ?";
    $params[] = $category_filter;
    $param_types .= 's';
}

if (!empty($status_filter)) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

// Only load items if department filter is set
$items = [];
$total_items = 0;
$total_pages = 0;
$show_individual_items = false; // Flag to determine if we should show individual items or item tables

// Check if special filter is applied - if so, show individual items instead of item tables
// Exception: low_stock and not_working should show item tables, not individual items
if (!empty($special_filter) && in_array($special_filter, ['borrow_items', 'recent_activities'])) {
    $show_individual_items = true;
}

if ($hasDepartmentFilter) {
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    if ($show_individual_items) {
        // Query individual items when special filters are applied
        // Get total count for pagination - count individual items
        $count_query = "
            SELECT COUNT(*) as total
            FROM items i
            LEFT JOIN departments d ON i.department_id = d.id 
            LEFT JOIN item_tables it ON i.item_table_id = it.id
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

        // Get individual items
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
                it.table_image_path,
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

        $items_stmt = $conn->prepare($items_query);
        if (!empty($params)) {
            $items_stmt->bind_param($param_types, ...$params);
        }
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $items = $items_result->fetch_all(MYSQLI_ASSOC);
    } else {
        // Get total count for pagination - count distinct item tables that have items
        $count_query = "
            SELECT COUNT(*) as total FROM (
                SELECT it.id
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
        $total_items = $count_result->fetch_assoc()['total'];
        $total_pages = ceil($total_items / $items_per_page);

        // Get item tables with item counts grouped - only tables that have items
        // For not_working filter, count only defective items and filter items in WHERE clause
        $item_count_expression = "COUNT(i.id)";
        $items_where_addition = "";
        if ($special_filter === 'not_working') {
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
        $items = $items_result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get departments for filter dropdown
$departments_result = $conn->query("SELECT DISTINCT name FROM departments ORDER BY name");
$departments = [];
if ($departments_result) {
    while ($row = $departments_result->fetch_assoc()) {
        $departments[] = $row['name'];
    }
}

// Get categories for filter dropdown (only from user's department if not super admin)
if (!$isSuperAdmin && !empty($currentUserDepartment)) {
    $categories_query = "SELECT DISTINCT i.category FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE i.category IS NOT NULL AND d.name = ? ORDER BY i.category";
    $categories_stmt = $conn->prepare($categories_query);
    $categories_stmt->bind_param("s", $currentUserDepartment);
    $categories_stmt->execute();
    $categories_result = $categories_stmt->get_result();
} else {
    $categories_result = $conn->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL ORDER BY category");
}
$categories = [];
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}
if (isset($categories_stmt)) {
    $categories_stmt->close();
}

// Get statuses for filter dropdown
$statuses = ['Working', 'Under Maintenance', 'Broken', 'Lost'];
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <title>OCABIS Dashboard</title>
    <style>
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 15px 0;
            border-top: 1px solid #e5e7eb;
        }
        
        .pagination-info {
            color: #6b7280;
            font-size: 14px;
        }
        
        .pagination {
            display: flex;
            gap: 5px;
        }
        
        .page-btn {
            display: inline-block;
            padding: 8px 12px;
            margin: 0 2px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            color: #374151;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            background: white;
        }
        
        .page-btn:hover {
            background: #f3f4f6;
            border-color: #9ca3af;
            color: #111827;
        }
        
        .page-btn.active {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
        }
        
        .page-btn.active:hover {
            background: #2563eb;
            border-color: #2563eb;
        }
        
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .page-btn:disabled:hover {
            background: white;
            border-color: #d1d5db;
            color: #374151;
        }
        
        @media (max-width: 768px) {
            .pagination-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .pagination {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .page-btn {
                padding: 6px 10px;
                font-size: 13px;
            }
        }

        .stat-card {
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card:active {
            transform: translateY(0);
        }
        
        /* Low Stock Visual Indicators */
        .low-stock-row {
            background-color: #fef2f2 !important;
            border-left: 4px solid #ef4444 !important;
        }
        
        .low-stock-row:hover {
            background-color: #fee2e2 !important;
        }
        
        .low-stock-badge {
            background-color: #ef4444 !important;
            color: white !important;
            font-weight: 700 !important;
            animation: pulse-red 2s infinite;
        }
        
        @keyframes pulse-red {
            0% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
            }
        }
        
        
        /* Empty State Animation */
        .empty-state {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Stat Card Hover Effects */
        .stat-card:hover {
            transform: translateY(-4px) !important;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important;
        }

        /* Card items list scrollbar styling */
        .card-items-list {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f8f9fa;
        }
        
        .card-items-list::-webkit-scrollbar {
            width: 6px;
        }
        
        .card-items-list::-webkit-scrollbar-track {
            background: #f8f9fa;
            border-radius: 3px;
        }
        
        .card-items-list::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 3px;
        }
        
        .card-items-list::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }

        /* View Toggle Button Styles */
        .view-toggle {
            display: inline-flex;
            background: #f3f4f6;
            border-radius: 8px;
            padding: 2px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .view-btn {
            padding: 8px 12px;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
        }

        .view-btn:hover {
            background: #e5e7eb;
            color: #374151;
        }

        .view-btn.active {
            background: #e53e3e;
            color: white;
            box-shadow: 0 1px 3px rgba(229, 62, 62, 0.3);
        }

        .view-btn.active:hover {
            background: #c53030;
            color: white;
        }

        .view-btn svg {
            width: 16px;
            height: 16px;
        }

        /* Grid and List Layout Styles for Dashboard Item Cards */
        .items-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .items-cards-container.grid-layout {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .items-cards-container.list-layout {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .items-cards-container.list-layout .item-card {
            padding: 12px 16px;
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 16px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 0 !important;
        }

        .items-cards-container.list-layout .item-image-container {
            width: 60px;
            height: 60px;
            margin: 0;
            flex-shrink: 0;
            border-radius: 8px;
            background: #f3f4f6;
        }

        .items-cards-container.list-layout .item-card-content {
            flex: 1;
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 16px;
            margin: 0;
        }

        .items-cards-container.list-layout .item-card-title {
            font-size: 12px;
            font-weight: 500;
            margin: 0;
            min-width: 80px;
        }

        .items-cards-container.list-layout .quantity-badge {
            font-size: 10px;
            margin: 0;
            min-width: 60px;
        }

    </style>
    <style>
        /* Sidebar toggle fixed - hidden on desktop by default */
        .sidebar-toggle-fixed {
            display: none;
        }

        /* Mobile Inline Sidebar Toggle - Hidden on desktop */
        .sidebar-toggle-mobile-inline {
            display: none !important;
        }

        /* Mobile responsive adjustments (375px - 768px) */
        @media (max-width: 768px) {
            /* Show fixed hamburger on mobile - always visible */
            #sidebarToggleFixed,
            .sidebar-toggle-fixed { 
                display: flex !important; 
                visibility: visible !important;
                opacity: 1 !important;
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
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 18px !important;
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

            /* Make wide tables scrollable horizontally */
            .table-container { 
                overflow-x: auto; 
                -webkit-overflow-scrolling: touch; 
                width: 100%;
            }
            
            .table { 
                min-width: 700px; 
                font-size: 12px;
            }
            
            .table th,
            .table td {
                padding: 10px 8px;
            }


            /* Fix filters section */
            .filters-section {
                flex-direction: column;
                gap: 10px;
            }

            .filters-section form {
                flex-direction: column;
                width: 100%;
            }

            .filter-input,
            .filter-select {
                width: 100%;
                min-width: 100%;
            }

            /* Fix export button */
            .filters-section form div {
                margin-left: 0 !important;
                text-align: center;
            }

            .filters-section form div button {
                margin-left: 0 !important;
                width: 100%;
            }

            /* Summary stats - 1 column on mobile for better readability */
            .summary-stats {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .stat-card {
                padding: 12px;
            }
            
            .card-items-list {
                max-height: 100px !important;
            }

            /* Pagination mobile adjustments */
            .pagination-container {
                flex-direction: column;
                gap: 10px;
                padding: 10px 0;
            }

            .pagination {
                flex-wrap: wrap;
                justify-content: center;
                gap: 5px;
            }

            .page-btn {
                padding: 8px 10px;
                font-size: 12px;
                min-width: 36px;
            }

            .pagination-info {
                font-size: 12px;
                text-align: center;
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

        @media (max-width: 768px) {
            .sidebar-overlay.show {
                display: block;
            }
            
            /* Ensure toggle button is always on top */
            .sidebar-toggle-fixed {
                background: rgba(229, 62, 62, 0.95) !important;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
            }

            /* Main content padding for mobile */
            .main-content {
                padding: 10px !important;
                padding-top: 70px !important;
            }

            /* Filters section mobile improvements */
            .filters-section {
                padding: 15px !important;
                margin-bottom: 20px !important;
            }

            .filters-section form > div {
                width: 100% !important;
                min-width: 100% !important;
                margin-bottom: 10px;
            }

            .filters-section form > div:last-child {
                width: 100% !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 10px !important;
            }

            .filters-section form > div:last-child > div {
                width: 100% !important;
            }

            .filters-section form > div:last-child button {
                width: 100% !important;
                margin: 0 !important;
                padding: 12px 20px !important;
                font-size: 14px !important;
            }

            /* View toggle mobile positioning */
            .view-toggle {
                width: 100% !important;
                justify-content: center !important;
                margin-bottom: 10px !important;
            }

            /* Summary stats mobile - single column */
            .summary-stats {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
                margin-bottom: 20px !important;
            }

            .stat-card {
                padding: 14px !important;
                margin: 0 !important;
            }

            .stat-card .stat-content {
                font-size: 11px !important;
            }

            .stat-card .stat-number {
                font-size: 20px !important;
            }

            .stat-card .stat-label {
                font-size: 11px !important;
            }

            .card-items-list {
                max-height: 80px !important;
                font-size: 10px !important;
            }

            /* Item cards container mobile */
            .items-cards-container {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
            }

            .items-cards-container.grid-layout {
                grid-template-columns: 1fr !important;
            }

            .item-card {
                margin-bottom: 0 !important;
            }

            .item-image-container {
                width: 80px !important;
                height: 80px !important;
            }

            .item-card-title {
                font-size: 14px !important;
            }

            .quantity-text {
                font-size: 12px !important;
            }

            /* Individual items display mobile */
            .table-container {
                padding: 15px !important;
            }

            .table-container > div[style*="display: flex"] {
                flex-direction: column !important;
                gap: 10px !important;
            }

            .table-container > div[style*="display: flex"] > div[style*="background: white"] {
                padding: 12px !important;
                flex-direction: column !important;
                gap: 12px !important;
            }

            .table-container > div[style*="display: flex"] > div[style*="background: white"] > div[style*="flex-shrink: 0"] {
                width: 100% !important;
                height: 150px !important;
            }

            .table-container > div[style*="display: flex"] > div[style*="background: white"] > div[style*="flex: 1"] {
                width: 100% !important;
            }

            .table-container > div[style*="display: flex"] > div[style*="background: white"] > div[style*="flex-shrink: 0"][style*="display: flex"][style*="flex-direction: column"] {
                width: 100% !important;
                align-items: flex-start !important;
            }

            /* Empty state mobile */
            .empty-state {
                padding: 40px 15px !important;
            }

            .empty-state > div[style*="font-size: 64px"] {
                font-size: 48px !important;
            }

            .empty-state h2 {
                font-size: 18px !important;
            }

            .empty-state p {
                font-size: 14px !important;
            }

            /* Pending requests section mobile */
            #pendingRequestsSection {
                padding: 15px !important;
            }

            #pendingRequestsSection h2 {
                font-size: 18px !important;
            }

            #pendingRequestsList > div[style*="display: flex"] > div[style*="background: #f9fafb"] {
                padding: 15px !important;
            }

            #pendingRequestsList > div[style*="display: flex"] > div[style*="background: #f9fafb"] > div[style*="display: flex"] {
                flex-direction: column !important;
                gap: 12px !important;
            }

            #pendingRequestsList > div[style*="display: flex"] > div[style*="background: #f9fafb"] > div[style*="display: grid"] {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }

            #pendingRequestsList > div[style*="display: flex"] > div[style*="background: #f9fafb"] > div[style*="display: flex"][style*="gap: 8px"] {
                flex-direction: column !important;
            }

            #pendingRequestsList > div[style*="display: flex"] > div[style*="background: #f9fafb"] > div[style*="display: flex"][style*="gap: 8px"] button {
                width: 100% !important;
            }

            /* Item tables header mobile */
            .table-container > div[style*="display: flex"][style*="justify-content: space-between"] {
                flex-direction: column !important;
                gap: 15px !important;
                align-items: flex-start !important;
            }

            .table-container > div[style*="display: flex"][style*="justify-content: space-between"] h2 {
                font-size: 18px !important;
                margin: 0 !important;
            }

            /* Message divs mobile */
            div[style*="background: white"][style*="border-radius: 12px"] > div[style*="font-size: 48px"] {
                font-size: 36px !important;
            }

            div[style*="background: white"][style*="border-radius: 12px"] h3 {
                font-size: 16px !important;
            }

            div[style*="background: white"][style*="border-radius: 12px"] p {
                font-size: 13px !important;
            }

            /* Profile dropdown mobile adjustments - ensure it's within user-profile-section */
            .user-profile-section {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 10px !important;
            }

            .user-profile-section .profile-dropdown {
                position: relative !important;
                right: auto !important;
                top: auto !important;
                flex-shrink: 0 !important;
            }

            .user-profile-section .profile-btn {
                padding: 6px 10px !important;
                gap: 6px !important;
                font-size: 11px !important;
            }

            .user-profile-section .profile-avatar {
                width: 18px !important;
                height: 18px !important;
            }

            .user-profile-section .profile-name {
                display: none !important; /* Hide name on mobile to save space */
            }

            .user-profile-section .profile-dropdown-menu {
                right: 0 !important;
                left: auto !important;
                min-width: 180px !important;
                z-index: 1400 !important;
            }

            /* Modal mobile improvements */
            .items-modal > div[style*="background: white"] {
                width: 95% !important;
                max-width: 95% !important;
                max-height: 90vh !important;
                padding: 15px !important;
            }

            .items-modal > div[style*="background: white"] > div[style*="flex: 1"] {
                padding: 10px 0 !important;
            }

            /* Manage Borrow Requests Modal mobile */
            #manageBorrowRequestsModal .modal {
                width: 95% !important;
                max-width: 95% !important;
                max-height: 90vh !important;
                padding: 0 !important;
            }

            #manageBorrowRequestsModal .modal-body {
                padding: 15px !important;
            }

            #manageBorrowRequestsModal .modal-body > div > div {
                padding: 12px !important;
            }

            #manageBorrowRequestsModal .modal-body > div > div > div[style*="display: grid"] {
                grid-template-columns: 1fr !important;
            }

            #manageBorrowRequestsModal .modal-body > div > div > div[style*="display: flex"][style*="gap: 8px"] {
                flex-direction: column !important;
            }

            #manageBorrowRequestsModal .modal-body > div > div > div[style*="display: flex"][style*="gap: 8px"] button {
                width: 100% !important;
            }

            /* Pagination mobile - already has styles but ensure they work */
            .pagination-container {
                padding: 10px 0 !important;
            }

            .pagination-info {
                text-align: center !important;
                margin-bottom: 10px !important;
            }

            /* Card items in stat cards mobile */
            .card-items-list > div[style*="padding: 6px 8px"] {
                padding: 8px !important;
            }

            .card-items-list > div[style*="padding: 6px 8px"] > div {
                font-size: 10px !important;
            }

            /* View more items link mobile */
            .view-more-items {
                padding: 8px !important;
                font-size: 11px !important;
            }

            /* Badge mobile adjustments */
            .stock-badge,
            .consumable-badge {
                font-size: 10px !important;
                padding: 4px 8px !important;
            }

            /* Meta row mobile */
            .meta-row {
                flex-direction: column !important;
                gap: 4px !important;
            }

            .meta {
                font-size: 11px !important;
            }

            /* Override inline styles for mobile - Individual items */
            div[onclick*="view_item.php"] {
                flex-direction: column !important;
                padding: 12px !important;
            }

            div[onclick*="view_item.php"] > div[style*="flex-shrink: 0"][style*="width: 80px"] {
                width: 100% !important;
                height: 150px !important;
                margin-bottom: 10px !important;
            }

            div[onclick*="view_item.php"] > div[style*="flex: 1"] {
                width: 100% !important;
            }

            div[onclick*="view_item.php"] > div[style*="flex-shrink: 0"][style*="display: flex"][style*="flex-direction: column"] {
                width: 100% !important;
                align-items: flex-start !important;
                margin-top: 10px !important;
            }

            /* Stat card content mobile */
            .stat-card > div[style*="display: flex"] {
                flex-direction: column !important;
                gap: 8px !important;
            }

            .stat-card > div[style*="display: flex"] > .stat-icon {
                align-self: flex-start !important;
            }

            .stat-card > div[style*="display: flex"] > .stat-content {
                width: 100% !important;
            }

            /* Item card action dropdown mobile */
            .card-action-dropdown {
                position: relative !important;
            }

            /* Export PDF button mobile */
            button[onclick*="exportToPDF"] {
                width: 100% !important;
                margin-top: 10px !important;
            }

            /* Clear filters button mobile */
            button[onclick*="clearAllFilters"] {
                width: 100% !important;
                margin-top: 10px !important;
            }

            /* Search input mobile */
            .search-input {
                font-size: 16px !important; /* Prevents zoom on iOS */
            }

            /* Select dropdowns mobile */
            .filter-select {
                font-size: 16px !important; /* Prevents zoom on iOS */
            }

            /* Modal content mobile scrolling */
            .items-modal > div[style*="background: white"] > div[style*="flex: 1"] {
                -webkit-overflow-scrolling: touch !important;
            }

            /* Item card clickable area mobile */
            .clickable-card {
                min-height: auto !important;
            }

            /* Table container header mobile */
            .table-container > div[style*="display: flex"][style*="justify-content: space-between"][style*="align-items: center"] {
                flex-wrap: wrap !important;
            }

            /* Ensure text doesn't overflow on mobile */
            .item-card-title,
            .quantity-text,
            .stat-label,
            .stat-number {
                word-wrap: break-word !important;
                overflow-wrap: break-word !important;
            }

            /* Pending requests card mobile */
            #pendingRequestsList > div > div[style*="background: #f9fafb"] {
                margin-bottom: 12px !important;
            }

            /* Empty state message mobile */
            div[style*="background: white"][style*="border-radius: 12px"][style*="box-shadow"] {
                padding: 30px 15px !important;
            }

            /* Item code badge mobile */
            span[style*="font-family: monospace"][style*="background: #e0e7ff"] {
                font-size: 10px !important;
                padding: 4px 8px !important;
                max-width: 100% !important;
                overflow: hidden !important;
                text-overflow: ellipsis !important;
            }

            /* Status badge mobile */
            span[style*="background:"][style*="padding: 4px 12px"][style*="border-radius: 12px"] {
                font-size: 10px !important;
                padding: 4px 10px !important;
            }

            /* Card items list item mobile */
            .card-items-list > div[style*="padding: 6px 8px"] > div[style*="display: flex"] {
                flex-direction: column !important;
                gap: 6px !important;
            }

            .card-items-list > div[style*="padding: 6px 8px"] > div[style*="display: flex"] > div[style*="flex: 1"] {
                width: 100% !important;
            }

            .card-items-list > div[style*="padding: 6px 8px"] > div[style*="display: flex"] > div[style*="margin-left: 8px"] {
                width: 100% !important;
                margin-left: 0 !important;
                margin-top: 6px !important;
                text-align: left !important;
            }
        }

        /* Additional mobile optimizations for very small screens */
        @media (max-width: 480px) {
            .main-content {
                padding: 8px !important;
                padding-top: 65px !important;
            }

            .filters-section {
                padding: 12px !important;
            }

            .stat-card {
                padding: 12px !important;
            }

            .stat-card .stat-number {
                font-size: 18px !important;
            }

            .item-card {
                padding: 10px !important;
            }

            .table-container {
                padding: 12px !important;
            }

            .pagination {
                gap: 3px !important;
            }

            .page-btn {
                padding: 6px 8px !important;
                font-size: 11px !important;
                min-width: 32px !important;
            }
        }
    </style>
    <script src="js/session_monitor.js"></script>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>">
    
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>
    
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
        <a href="#" class="nav-link active" title="Dashboard">
            <span class="nav-icon">
                <img src="image/admin.png" alt="Dashboard">
            </span>
            <span class="nav-label">Dashboard</span>
        </a>
    </li>
    <li class="nav-item">
        <a href="department.php" class="nav-link" title="<?= ($isDepartmentHead || $isAdminRole || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
            <span class="nav-icon">
                <img src="image/department.png" alt="<?= ($isDepartmentHead || $isAdminRole || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
            </span>
            <span class="nav-label"><?= ($isDepartmentHead || $isAdminRole || $isSuperAdmin) ? 'Item List' : 'Department' ?></span>
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
    
    <div class="main-content" data-user-department="<?php echo htmlspecialchars(!empty($department_filter) ? $department_filter : $currentUserDepartment, ENT_QUOTES, 'UTF-8'); ?>">
        <?php include 'profile_dropdown.php'; ?>
        
        <!-- Filter Bar - Always Visible -->
        <div class="filters-section" style="background: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <form id="filterForm" method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <?php if ($isSuperAdmin): ?>
                <!-- Super Admin: Must select department -->
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2d3748; font-size: 14px;">Department <span style="color: #e53e3e;">*</span></label>
                    <select name="department" class="filter-select" required style="width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 16px;">
                        <option value="">-- Select Department --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <!-- Department Head: Hide department filter (keep hidden input for requests) -->
                <input type="hidden" name="department" id="departmentInput" value="<?php echo htmlspecialchars(!empty($department_filter) ? $department_filter : $currentUserDepartment); ?>">
                <?php endif; ?>
                
                <div style="flex: 1; min-width: 180px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2d3748; font-size: 14px;">Status</label>
                    <select name="status" class="filter-select" style="width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 16px;">
                        <option value="">All Status</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2d3748; font-size: 14px;">Search</label>
                    <input type="text" name="search" class="search-input filter-input" placeholder="Search items..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 16px;">
                </div>
                
                <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                    <!-- View Toggle Button -->
                    <div class="view-toggle" id="dashboardViewToggle" style="display: none; margin-right: 10px;">
                        <button class="view-btn active" id="dashboardGridViewBtn" onclick="switchDashboardToGridView()" title="Grid View">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <rect x="3" y="3" width="7" height="7"/>
                                <rect x="14" y="3" width="7" height="7"/>
                                <rect x="3" y="14" width="7" height="7"/>
                                <rect x="14" y="14" width="7" height="7"/>
                            </svg>
                        </button>
                        <button class="view-btn" id="dashboardListViewBtn" onclick="switchDashboardToListView()" title="List View">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <rect x="3" y="6" width="18" height="2"/>
                                <rect x="3" y="11" width="18" height="2"/>
                                <rect x="3" y="16" width="18" height="2"/>
                            </svg>
                        </button>
                    </div>
                    <?php if ($hasDepartmentFilter): ?>
                    <button type="button" onclick="exportToPDF()" style="background: #28a745; color: white; border: none; padding: 14px 24px; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <?php if ($filtersApplied): ?>
                    <button type="button" onclick="clearAllFilters()" style="background: #6b7280; color: white; border: none; padding: 14px 24px; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Hidden fields for special filters -->
                <input type="hidden" name="special_filter" id="specialFilter" value="">
            </form>
        </div>
        
        <?php if (!$hasDepartmentFilter && $isSuperAdmin): ?>
        <!-- Empty State - Only for Super Admins -->
        <div class="empty-state" style="text-align: center; padding: 80px 20px; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <div style="font-size: 64px; margin-bottom: 20px;">📊</div>
            <h2 style="color: #2d3748; margin-bottom: 12px; font-size: 24px; font-weight: 600;">Please select a department to view inventory data</h2>
            <p style="color: #718096; font-size: 16px; max-width: 500px; margin: 0 auto;">Choose a department from the filter above to see inventory statistics and items.</p>
        </div>
        <?php elseif ($hasDepartmentFilter && empty($search)): ?>
        
        <!-- Summary Statistics - Only shown when department is selected and no search is performed -->
        <div class="summary-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 12px; margin-bottom: 20px;">
            <div class="stat-card" data-filter="all_items" data-card-type="total_items" style="background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                    <div class="stat-icon"><img src="image/list-items.png" alt="Total Items Inventory" style="width:28px;height:28px;vertical-align:middle;" /></div>
                    <div class="stat-content" style="flex: 1;">
                        <div class="stat-number" style="font-size: 24px; font-weight: 700; color: #2d3748; margin-bottom: 2px;"><?php echo $stats['total_items']; ?></div>
                        <div class="stat-label" style="color: #2d3748; font-size: 12px; font-weight: 600; margin-bottom: 0;">Total Items Inventory</div>
                        <div style="color: #3b82f6; font-size: 11px; font-weight: 600; margin-top: 4px;">
                            <?php echo htmlspecialchars($department_filter); ?>
                        </div>
                    </div>
                </div>
                <div class="card-items-list" style="max-height: 120px; overflow-y: auto; border-top: 1px solid #e2e8f0; padding-top: 8px; margin-top: 8px;">
                    <div class="items-loading" style="text-align: center; color: #718096; padding: 6px; font-size: 11px;">Loading items...</div>
                    <div class="items-container" style="display: none;"></div>
                </div>
            </div>
            <div class="stat-card" data-filter="low_stock" data-card-type="low_stock" style="background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                    <div class="stat-icon"><img src="image/low-stock.png" alt="Low Stock Consumables" style="width:28px;height:28px;vertical-align:middle;" /></div>
                    <div class="stat-content" style="flex: 1;">
                        <div class="stat-number" style="font-size: 24px; font-weight: 700; color: #e53e3e; margin-bottom: 2px;"><?php echo $stats['low_stock']; ?></div>
                        <div class="stat-label" style="color: #2d3748; font-size: 12px; font-weight: 600; margin-bottom: 0;">Low Stock Consumables</div>
                        <div style="color: #3b82f6; font-size: 11px; font-weight: 600; margin-top: 4px;">
                            <?php echo htmlspecialchars($department_filter); ?>
                        </div>
                    </div>
                </div>
                <div class="card-items-list" style="max-height: 120px; overflow-y: auto; border-top: 1px solid #e2e8f0; padding-top: 8px; margin-top: 8px;">
                    <div class="items-loading" style="text-align: center; color: #718096; padding: 6px; font-size: 11px;">Loading items...</div>
                    <div class="items-container" style="display: none;"></div>
                </div>
            </div>
            <div class="stat-card" data-filter="not_working" data-card-type="not_working" style="background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                    <div class="stat-icon"><img src="image/—Pngtree—troubleshooting silhouette customer service icon_9008472.png" alt="Defective" style="width:28px;height:28px;vertical-align:middle;" /></div>
                    <div class="stat-content" style="flex: 1;">
                        <div class="stat-number" style="font-size: 24px; font-weight: 700; color: #805ad5; margin-bottom: 2px;"><?php echo $stats['not_working']; ?></div>
                        <div class="stat-label" style="color: #2d3748; font-size: 12px; font-weight: 600; margin-bottom: 0;">Defective Items</div>
                        <div style="color: #3b82f6; font-size: 11px; font-weight: 600; margin-top: 4px;">
                            <?php echo htmlspecialchars($department_filter); ?>
                        </div>
                    </div>
                </div>
                <div class="card-items-list" style="max-height: 120px; overflow-y: auto; border-top: 1px solid #e2e8f0; padding-top: 8px; margin-top: 8px;">
                    <div class="items-loading" style="text-align: center; color: #718096; padding: 6px; font-size: 11px;">Loading items...</div>
                    <div class="items-container" style="display: none;"></div>
                </div>
            </div>
            <?php 
            // Show Manage Borrow Requests card for admin and superadmin
            $is_admin_role = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
            $is_admin_or_super = $isSuperAdmin || $is_admin_role || $isDepartmentHead;
            if ($is_admin_or_super): 
            ?>
            <div class="stat-card" data-filter="pending_requests" data-card-type="pending_requests" style="background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                    <div class="stat-icon"><img src="image/clock.png" alt="Pending Requests" style="width:28px;height:28px;vertical-align:middle;" /></div>
                    <div class="stat-content" style="flex: 1;">
                        <div class="stat-number" style="font-size: 24px; font-weight: 700; color: #10b981; margin-bottom: 2px;"><?php echo isset($stats['pending_requests']) ? $stats['pending_requests'] : 0; ?></div>
                        <div class="stat-label" style="color: #2d3748; font-size: 12px; font-weight: 600; margin-bottom: 0;">Pending Requests</div>
                        <div style="color: #3b82f6; font-size: 11px; font-weight: 600; margin-top: 4px;">
                            <?php echo htmlspecialchars($department_filter); ?>
                        </div>
                    </div>
                </div>
                <div class="card-items-list" style="max-height: 120px; overflow-y: auto; border-top: 1px solid #e2e8f0; padding-top: 8px; margin-top: 8px;">
                    <div class="items-loading" style="text-align: center; color: #718096; padding: 6px; font-size: 11px;">Loading items...</div>
                    <div class="items-container" style="display: none;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <?php endif; ?>

        <!-- Pending Requests Section - Always available in DOM -->
        <div id="pendingRequestsSection" style="display: none; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px;">
            <h2 style="margin-bottom: 20px; color: #2d3748; font-size: 20px; font-weight: 600;">Pending Borrow Requests</h2>
            <div id="pendingRequestsList" style="min-height: 200px;">
                <div style="text-align: center; padding: 40px; color: #666;">
                    <p>Loading pending requests...</p>
                </div>
            </div>
        </div>
        
        <?php if ($hasDepartmentFilter && !$filtersApplied): ?>
        <!-- Message when department is selected but filters not applied -->
        <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 40px; text-align: center; margin-top: 30px;">
            <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
            <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 20px; font-weight: 600;">Apply Filters to View Item Tables</h3>
            <p style="color: #718096; font-size: 14px; max-width: 500px; margin: 0 auto;">
                Use the search bar or status filters above, or click on any stat card to view item tables.
            </p>
        </div>
        <?php elseif ($filtersApplied): ?>
        <?php if ($show_individual_items): ?>
        <!-- Individual Items - Shown when special filters are applied -->
        <div class="table-container" style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px;">
            <?php
            $filterTitle = 'Items';
            if ($special_filter === 'not_working') {
                $filterTitle = 'Defective Items';
            } elseif ($special_filter === 'low_stock') {
                $filterTitle = 'Low Stock Consumables';
            } elseif ($special_filter === 'borrow_items') {
                $filterTitle = 'Borrowed Items';
            } elseif ($special_filter === 'recent_activities') {
                $filterTitle = 'Recent Activities';
            }
            ?>
            <h2 style="margin-bottom: 20px; color: #2d3748; font-size: 20px; font-weight: 600;"><?php echo htmlspecialchars($filterTitle); ?></h2>
            
            <?php if (empty($items)): ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <div style="font-size: 48px; margin-bottom: 16px;">📦</div>
                    <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 18px; font-weight: 600;">No Items Found</h3>
                    <p style="color: #718096; font-size: 14px;">No items found matching the selected filters</p>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($items as $item): ?>
                        <?php
                        // For defective items filter, show original status (Broken/Under Maintenance) even if borrowed/consumable
                        $originalStatus = $item['status'] ?? 'Unknown';
                        $displayStatus = $item['display_status'] ?? $originalStatus;
                        
                        // If filtering for defective items, prioritize showing the original defective status
                        if ($special_filter === 'not_working' && ($originalStatus === 'Broken' || $originalStatus === 'Under Maintenance')) {
                            $status = $originalStatus;
                        } else {
                            $status = $displayStatus;
                        }
                        
                        $itemImage = !empty($item['table_image_path']) ? $item['table_image_path'] : null;
                        
                        // Status badge colors
                        $statusColors = [
                            'Working' => ['bg' => '#c6f6d5', 'color' => '#2f855a'],
                            'Broken' => ['bg' => '#fed7d7', 'color' => '#c53030'],
                            'Borrowed' => ['bg' => '#fef3c7', 'color' => '#d97706'],
                            'Under Maintenance' => ['bg' => '#feebc8', 'color' => '#c05621']
                        ];
                        $statusStyle = $statusColors[$status] ?? ['bg' => '#e2e8f0', 'color' => '#4a5568'];
                        ?>
                        <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 16px; transition: all 0.2s; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.05);" 
                             onclick="window.location.href='view_item.php?id=<?php echo $item['id']; ?>'"
                             onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.1)'; this.style.borderColor='#cbd5e0';"
                             onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.05)'; this.style.borderColor='#e2e8f0';">
                            <!-- Item Image/Icon -->
                            <div style="flex-shrink: 0; width: 80px; height: 80px; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #f3f4f6;">
                                <?php if ($itemImage): ?>
                                    <img src="<?php echo htmlspecialchars($itemImage); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" style="width: 100%; height: 100%; object-fit: cover;" />
                                <?php else: ?>
                                    <div style="font-size: 36px;">📦</div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Item Details -->
                            <div style="flex: 1; min-width: 0;">
                                <h4 style="margin: 0 0 8px 0; color: #2d3748; font-size: 18px; font-weight: 600;"><?php echo htmlspecialchars($item['name'] ?? 'N/A'); ?></h4>
                                <div style="display: flex; flex-direction: column; gap: 4px; color: #718096; font-size: 14px;">
                                    <div><strong>Department:</strong> <?php echo htmlspecialchars($item['department_name'] ?? 'Unknown'); ?></div>
                                    <div><strong>Category:</strong> <?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?></div>
                                    <?php if (!empty($item['location'])): ?>
                                        <div><strong>Location:</strong> <?php echo htmlspecialchars($item['location']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['item_code'])): ?>
                                        <div><strong>Item Code:</strong> <span style="font-family: monospace; background: #e0e7ff; padding: 2px 6px; border-radius: 3px;"><?php echo htmlspecialchars($item['item_code']); ?></span></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Status Badges -->
                            <div style="flex-shrink: 0; display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                                <?php if ($status): ?>
                                    <span style="background: <?php echo $statusStyle['bg']; ?>; color: <?php echo $statusStyle['color']; ?>; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase;">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <!-- Item Tables Cards - Only shown when filters are applied (but not special filters) -->
        <div class="table-container" style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <?php
                $tableTitle = 'Item Tables';
                if ($special_filter === 'not_working') {
                    $tableTitle = 'Item Tables with Defective Items';
                } elseif ($special_filter === 'low_stock') {
                    $tableTitle = 'Item Tables with Low Stock';
                }
                ?>
                <h2 style="margin: 0; color: #2d3748; font-size: 20px; font-weight: 600;"><?php echo htmlspecialchars($tableTitle); ?></h2>
                <!-- View Toggle Button for Item Tables -->
                <div class="view-toggle" style="display: inline-flex;">
                    <button class="view-btn active" id="dashboardGridViewBtn2" onclick="switchDashboardToGridView()" title="Grid View">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <rect x="3" y="3" width="7" height="7"/>
                            <rect x="14" y="3" width="7" height="7"/>
                            <rect x="3" y="14" width="7" height="7"/>
                            <rect x="14" y="14" width="7" height="7"/>
                        </svg>
                    </button>
                    <button class="view-btn" id="dashboardListViewBtn2" onclick="switchDashboardToListView()" title="List View">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <rect x="3" y="6" width="18" height="2"/>
                            <rect x="3" y="11" width="18" height="2"/>
                            <rect x="3" y="16" width="18" height="2"/>
                        </svg>
                    </button>
                </div>
            </div>
            
                    <?php if (empty($items)): ?>
                <div style="text-align: center; padding: 40px; color: #666;">
                    <div style="font-size: 48px; margin-bottom: 16px;">📦</div>
                    <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 18px; font-weight: 600;">No Item Tables Found</h3>
                    <p style="color: #718096; font-size: 14px;">No item tables found for the selected department</p>
                </div>
                    <?php else: ?>
                <div class="items-cards-container grid-layout" id="itemsCardsContainer">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $itemCount = (int)$item['item_count'];
                            $createdDate = $item['last_updated'] ? date('M j, y', strtotime($item['last_updated'])) : 'N/A';
                            $tableImage = !empty($item['table_image_path']) ? $item['table_image_path'] : null;
                            $priority = isset($item['priority']) ? strtolower(trim($item['priority'])) : '';
                            $isConsumable = isset($item['is_consumable']) ? (int)$item['is_consumable'] === 1 : false;
                            ?>
                        <div class="item-card clickable-card" data-table-id="<?php echo $item['id']; ?>" onclick="showTableItems(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['table_name'])); ?>')">
                            <div class="item-image-container">
                                <?php if ($tableImage): ?>
                                    <img src="<?php echo htmlspecialchars($tableImage); ?>" alt="<?php echo htmlspecialchars($item['table_name']); ?>" class="item-image" />
                                <?php else: ?>
                                    <div class="item-image-placeholder">📦</div>
                                <?php endif; ?>
                            </div>
                            <div class="item-card-content">
                                <div class="item-title-row">
                                    <div class="item-card-title"><?php echo htmlspecialchars($item['table_name']); ?></div>
                                    <div class="card-action-dropdown">
                                        <button class="card-action-btn-menu" type="button" onclick="event.stopPropagation(); showTableItems(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['table_name'])); ?>')" title="View Item Table">⋮</button>
                                    </div>
                                </div>
                                <div class="quantity-text">Category: <?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?></div>
                                <div class="quantity-text">Items: <?php echo $itemCount; ?></div>
                                <div style="margin: 8px 0; display: flex; gap: 6px; flex-wrap: wrap;">
                                    <?php if ($isConsumable): ?>
                                        <span class="consumable-badge">
                                            ⚡ Consumable
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($priority): ?>
                                        <span class="stock-badge <?php echo $priority; ?>">
                                            <span class="badge-dot"></span>
                                            <?php echo strtoupper($priority); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="meta-row">
                                    <div class="meta">
                                        <span class="meta-label">Department:</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($item['department_name'] ?? 'Unknown'); ?></span>
                                    </div>
                                    <div class="meta">
                                        <span class="meta-label">Created:</span>
                                        <span class="meta-value"><?php echo $createdDate; ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                </div>
                    <?php endif; ?>
        <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination-container" style="margin-top: 20px;">
                <div class="pagination-info">
                    Showing <?php echo (($current_page - 1) * $items_per_page) + 1; ?> to <?php echo min($current_page * $items_per_page, $total_items); ?> of <?php echo $total_items; ?> <?php echo $show_individual_items ? 'items' : 'item tables'; ?>
                </div>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-btn first">First</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page - 1])); ?>" class="page-btn prev">Previous</a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="page-btn <?php echo $i === $current_page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $current_page + 1])); ?>" class="page-btn next">Next</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-btn last">Last</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>


    <script>
        // Charts removed - no longer needed

        // Chart functions removed - no longer needed

        function showItemsModal(title, cardType, department) {
            // Remove any existing modal first
            const existingModal = document.querySelector('.items-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            const modal = document.createElement('div');
            modal.className = 'items-modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.6);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 2000;
                backdrop-filter: blur(4px);
            `;
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: white;
                border-radius: 12px;
                padding: 20px;
                max-width: 90%;
                width: 900px;
                max-height: 85%;
                display: flex;
                flex-direction: column;
                box-shadow: 0 25px 50px rgba(0,0,0,0.25);
                position: relative;
            `;
            
            // Store modal state
            let currentPage = 1;
            let currentSearch = '';
            let totalItems = 0;
            let totalPages = 0;
            const itemsPerPage = 50;
            
            // Items container
            const itemsContainer = document.createElement('div');
            itemsContainer.style.cssText = `
                flex: 1;
                overflow-y: auto;
                margin-top: 15px;
                min-height: 0;
            `;
            
            // Loading indicator
            const loadingDiv = document.createElement('div');
            loadingDiv.style.cssText = 'text-align: center; padding: 40px; color: #666;';
            loadingDiv.textContent = 'Loading items...';
            itemsContainer.appendChild(loadingDiv);
            
            // Function to load items
            function loadItems(page = 1, search = '') {
                currentPage = page;
                currentSearch = search;
                
                // Show loading
                itemsContainer.innerHTML = '';
                itemsContainer.appendChild(loadingDiv);
                
                const searchParam = search ? `&search=${encodeURIComponent(search)}` : '';
                fetch(`dashboard_api.php?action=card_items&department=${encodeURIComponent(department)}&card_type=${cardType}&page=${page}&items_per_page=${itemsPerPage}${searchParam}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            totalItems = data.pagination.total_items;
                            totalPages = data.pagination.total_pages;
                            
                            // Update title with count
                            let itemLabel;
                            if (cardType === 'low_stock' || cardType === 'total_items') {
                                itemLabel = totalItems === 1 ? 'item table' : 'item tables';
                            } else if (cardType === 'pending_requests') {
                                itemLabel = totalItems === 1 ? 'request' : 'requests';
                            } else {
                                itemLabel = totalItems === 1 ? 'item' : 'items';
                            }
                            const titleWithCount = `${title} (${totalItems} ${itemLabel})`;
                            titleElement.textContent = titleWithCount;
                            
                            // Render items
                            renderItems(data.items);
                            renderPagination();
                        } else {
                            itemsContainer.innerHTML = `<p style="text-align: center; color: #e53e3e; padding: 40px 20px;">Error: ${data.error || 'Failed to load items'}</p>`;
                        }
                    })
                    .catch(error => {
                        console.error('Error loading items:', error);
                        itemsContainer.innerHTML = `<p style="text-align: center; color: #e53e3e; padding: 40px 20px;">Error loading items. Please try again.</p>`;
                    });
            }
            
            // Function to render items grouped by tables
            function renderItems(items) {
                if (items.length === 0) {
                    const noItemsText = cardType === 'pending_requests' ? 'No pending requests found.' : 'No items found for this selection.';
                    itemsContainer.innerHTML = `<p style="text-align: center; color: #666; padding: 40px 20px;">${noItemsText}</p>`;
                    return;
                }
                
                // For pending_requests, render requests with item information
                if (cardType === 'pending_requests') {
                    let itemsHTML = '<div style="display: flex; flex-direction: column; gap: 12px;">';
                    
                    items.forEach(item => {
                        const requestDate = item.request_date ? new Date(item.request_date).toLocaleDateString() : 'N/A';
                        const expectedReturn = item.expected_return_date ? new Date(item.expected_return_date).toLocaleDateString() : 'N/A';
                        const requestedBy = item.requested_by || 'Unknown';
                        const purpose = item.purpose || 'N/A';
                        
                        itemsHTML += `
                            <div style="padding: 16px; border: 2px solid #fef3c7; border-radius: 12px; background: white; box-shadow: 0 2px 8px rgba(217, 119, 6, 0.15); transition: all 0.2s; cursor: pointer;" 
                                 onclick="window.location.href='item_requests.php'"
                                 onmouseover="this.style.background='#fffbeb'; this.style.borderColor='#fcd34d'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(217, 119, 6, 0.2)';"
                                 onmouseout="this.style.background='white'; this.style.borderColor='#fef3c7'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(217, 119, 6, 0.15)';">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <div style="flex: 1;">
                                        <h4 style="margin: 0 0 8px 0; color: #2d3748; font-size: 17px; font-weight: 700;">${item.name || 'N/A'}</h4>
                                        <p style="margin: 0 0 4px 0; color: #718096; font-size: 14px;">
                                            <strong>Requested by:</strong> ${requestedBy}
                                        </p>
                                        <p style="margin: 0 0 4px 0; color: #718096; font-size: 14px;">
                                            <strong>Request Date:</strong> ${requestDate}
                                        </p>
                                        <p style="margin: 0 0 4px 0; color: #718096; font-size: 14px;">
                                            <strong>Expected Return:</strong> ${expectedReturn}
                                        </p>
                                        <p style="margin: 0 0 4px 0; color: #718096; font-size: 14px;">
                                            <strong>Purpose:</strong> ${purpose}
                                        </p>
                                        <p style="margin: 6px 0 0 0; color: #4a5568; font-size: 13px;">
                                            <strong>Department:</strong> ${item.department_name || 'N/A'} • <strong>Category:</strong> ${item.category || 'N/A'}
                                        </p>
                                    </div>
                                    <div style="text-align: right; margin-left: 16px; display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                                        ${item.item_code ? `
                                        <span style="background: #e0e7ff; color: #3730a3; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block; font-family: monospace; white-space: nowrap;" title="${item.item_code}">
                                            ${item.item_code}
                                        </span>
                                        ` : ''}
                                        <span style="background: #fef3c7; color: #d97706; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block;">
                                            Pending
                                        </span>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    itemsHTML += '</div>';
                    itemsContainer.innerHTML = itemsHTML;
                    return;
                }
                
                // For low_stock and total_items, render item tables instead of individual items
                if (cardType === 'low_stock' || cardType === 'total_items') {
                    const borderColor = cardType === 'low_stock' ? '#fed7d7' : '#c6f6d5';
                    const shadowColor = cardType === 'low_stock' ? 'rgba(229, 62, 62, 0.15)' : 'rgba(34, 197, 94, 0.15)';
                    const hoverShadowColor = cardType === 'low_stock' ? 'rgba(229, 62, 62, 0.2)' : 'rgba(34, 197, 94, 0.2)';
                    const hoverBorderColor = cardType === 'low_stock' ? '#fc8181' : '#4ade80';
                    const hoverBg = cardType === 'low_stock' ? '#fef5f5' : '#f0fdf4';
                    
                    let itemsHTML = '<div style="display: flex; flex-direction: column; gap: 12px;">';
                    
                    items.forEach(item => {
                        const itemCount = item.item_count || 0;
                        const isConsumable = item.is_consumable == 1 || item.is_consumable === '1';
                        
                        itemsHTML += `
                            <div style="padding: 16px; border: 2px solid ${borderColor}; border-radius: 12px; background: white; box-shadow: 0 2px 8px ${shadowColor}; transition: all 0.2s; cursor: pointer;" 
                                 onclick="showTableItems(${item.id}, '${(item.table_name || 'N/A').replace(/'/g, "\\'")}')"
                                 onmouseover="this.style.background='${hoverBg}'; this.style.borderColor='${hoverBorderColor}'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px ${hoverShadowColor}';"
                                 onmouseout="this.style.background='white'; this.style.borderColor='${borderColor}'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px ${shadowColor}';">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <div style="flex: 1;">
                                        <h4 style="margin: 0 0 8px 0; color: #2d3748; font-size: 17px; font-weight: 700;">${item.table_name || 'N/A'}</h4>
                                        <p style="margin: 0 0 4px 0; color: #718096; font-size: 14px;">
                                            <strong>Department:</strong> ${item.department_name || 'N/A'}
                                        </p>
                                        <p style="margin: 0 0 4px 0; color: #718096; font-size: 14px;">
                                            <strong>Category:</strong> ${item.category || 'N/A'}
                                        </p>
                                        <p style="margin: 6px 0 0 0; color: #4a5568; font-size: 13px;">
                                            <strong>Items:</strong> ${itemCount} ${itemCount === 1 ? 'item' : 'items'}${cardType === 'low_stock' ? ' with low stock' : ''}
                                        </p>
                                    </div>
                                    <div style="text-align: right; margin-left: 16px; display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                                        ${isConsumable ? `
                                        <span style="background: #fef3c7; color: #d97706; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block;">
                                            Consumable
                                        </span>
                                        ` : ''}
                                        ${cardType === 'low_stock' ? `
                                        <span style="background: #fed7d7; color: #e53e3e; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block;">
                                            Low Stock
                                        </span>
                                        ` : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    itemsHTML += '</div>';
                    itemsContainer.innerHTML = itemsHTML;
                    return;
                }
                
                // For other card types, group items by table_name
                const itemsByTable = {};
                items.forEach(item => {
                    const tableName = item.table_name || 'Uncategorized';
                    if (!itemsByTable[tableName]) {
                        itemsByTable[tableName] = [];
                    }
                    itemsByTable[tableName].push(item);
                });
                
                // Sort table names
                const sortedTableNames = Object.keys(itemsByTable).sort();
                
                // Build HTML with items grouped by table
                let itemsHTML = '<div style="display: flex; flex-direction: column; gap: 20px;">';
                
                sortedTableNames.forEach(tableName => {
                    const tableItems = itemsByTable[tableName];
                    itemsHTML += `
                        <div style="border: 2px solid #3b82f6; border-radius: 12px; overflow: hidden; background: white; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);">
                            <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 14px 18px; border-bottom: 2px solid #2563eb;">
                                <h3 style="margin: 0; color: white; font-size: 17px; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.1);">${tableName}</h3>
                                <p style="margin: 4px 0 0 0; color: rgba(255,255,255,0.9); font-size: 13px; font-weight: 500;">${tableItems.length} ${tableItems.length === 1 ? 'item' : 'items'}</p>
                            </div>
                            <div style="padding: 12px; display: flex; flex-direction: column; gap: 8px;">
                                ${tableItems.map(item => `
                                    <div style="padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8f9fa; transition: all 0.2s; cursor: pointer;" 
                                         onclick="window.location.href='view_item.php?id=${item.id}'"
                                         onmouseover="this.style.background='#f0f4f8'; this.style.borderColor='#cbd5e0';"
                                         onmouseout="this.style.background='#f8f9fa'; this.style.borderColor='#e2e8f0';">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                            <div style="flex: 1;">
                                                <h4 style="margin: 0 0 6px 0; color: #2d3748; font-size: 15px; font-weight: 600;">${item.name || 'N/A'}</h4>
                                                <p style="margin: 0 0 3px 0; color: #718096; font-size: 13px;">
                                                    <strong>Department:</strong> ${item.department_name || 'N/A'}
                                                </p>
                                                <p style="margin: 0 0 3px 0; color: #718096; font-size: 13px;">
                                                    <strong>Category:</strong> ${item.category || 'N/A'}
                                                </p>
                                                <p style="margin: 6px 0 0 0; color: #4a5568; font-size: 12px;">
                                                    <strong>Location:</strong> ${item.location || 'N/A'}
                                                </p>
                                            </div>
                                            <div style="text-align: right; margin-left: 16px;">
                                                ${item.item_code ? `
                                                <span style="background: #e0e7ff; color: #3730a3; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block; margin-bottom: 6px; font-family: monospace; white-space: nowrap;" title="${item.item_code}">
                                                    ${item.item_code}
                                                </span>
                                                ` : ''}
                                                <br>
                                                <span style="background: ${(item.display_status || item.status) === 'Working' ? '#c6f6d5' : ((item.display_status || item.status) === 'Broken' ? '#fed7d7' : (item.display_status || item.status) === 'Borrowed' ? '#fef3c7' : '#feebc8')}; color: ${(item.display_status || item.status) === 'Working' ? '#2f855a' : ((item.display_status || item.status) === 'Broken' ? '#c53030' : (item.display_status || item.status) === 'Borrowed' ? '#d97706' : '#c05621')}; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; display: inline-block;">
                                                    ${item.display_status || item.status || 'N/A'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `;
                });
                
                itemsHTML += '</div>';
                itemsContainer.innerHTML = itemsHTML;
            }
            
            // Function to render pagination
            function renderPagination() {
                if (totalPages <= 1) return;
                
                const paginationDiv = document.createElement('div');
                paginationDiv.style.cssText = `
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-top: 20px;
                    padding-top: 15px;
                    border-top: 1px solid #e2e8f0;
                `;
                
                let itemLabel;
                if (cardType === 'low_stock' || cardType === 'total_items') {
                    itemLabel = 'item tables';
                } else if (cardType === 'pending_requests') {
                    itemLabel = 'requests';
                } else {
                    itemLabel = 'items';
                }
                const infoText = `Showing ${((currentPage - 1) * itemsPerPage) + 1} to ${Math.min(currentPage * itemsPerPage, totalItems)} of ${totalItems} ${itemLabel}`;
                paginationDiv.innerHTML = `
                    <div style="color: #6b7280; font-size: 14px;">${infoText}</div>
                    <div style="display: flex; gap: 5px;">
                        ${currentPage > 1 ? `
                            <button onclick="loadModalPage(${currentPage - 1})" style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; cursor: pointer; font-size: 14px;">Previous</button>
                        ` : ''}
                        ${Array.from({length: Math.min(5, totalPages)}, (_, i) => {
                            let pageNum;
                            if (totalPages <= 5) {
                                pageNum = i + 1;
                            } else if (currentPage <= 3) {
                                pageNum = i + 1;
                            } else if (currentPage >= totalPages - 2) {
                                pageNum = totalPages - 4 + i;
                            } else {
                                pageNum = currentPage - 2 + i;
                            }
                            return `<button onclick="loadModalPage(${pageNum})" style="padding: 8px 12px; border: 1px solid ${pageNum === currentPage ? '#3b82f6' : '#d1d5db'}; border-radius: 6px; background: ${pageNum === currentPage ? '#3b82f6' : 'white'}; color: ${pageNum === currentPage ? 'white' : '#374151'}; cursor: pointer; font-size: 14px;">${pageNum}</button>`;
                        }).join('')}
                        ${currentPage < totalPages ? `
                            <button onclick="loadModalPage(${currentPage + 1})" style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; cursor: pointer; font-size: 14px;">Next</button>
                        ` : ''}
                    </div>
                `;
                
                // Store loadModalPage function on modal for access
                modal.loadModalPage = function(page) {
                    loadItems(page, currentSearch);
                };
                
                itemsContainer.appendChild(paginationDiv);
            }
            
            // Header with title and close button
            const headerDiv = document.createElement('div');
            headerDiv.style.cssText = 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px;';
            
            const titleElement = document.createElement('h3');
            titleElement.style.cssText = 'margin: 0; color: #2d3748; font-size: 20px; font-weight: 600;';
            titleElement.textContent = title;
            
            const closeBtn = document.createElement('button');
            closeBtn.textContent = 'Close';
            closeBtn.style.cssText = 'background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 16px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s;';
            closeBtn.onmouseover = () => closeBtn.style.background = '#e2e8f0';
            closeBtn.onmouseout = () => closeBtn.style.background = '#f8f9fa';
            closeBtn.onclick = () => modal.remove();
            
            headerDiv.appendChild(titleElement);
            headerDiv.appendChild(closeBtn);
            
            // Search input
            const searchDiv = document.createElement('div');
            searchDiv.style.cssText = 'margin-bottom: 15px;';
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.placeholder = 'Search items...';
            searchInput.style.cssText = 'width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;';
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    loadItems(1, this.value);
                }, 500);
            });
            searchDiv.appendChild(searchInput);
            
            modalContent.appendChild(headerDiv);
            modalContent.appendChild(searchDiv);
            modalContent.appendChild(itemsContainer);
            
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
            
            // Global function for pagination buttons
            window.loadModalPage = function(page) {
                if (modal && modal.loadModalPage) {
                    modal.loadModalPage(page);
                }
            };
            
            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                    window.loadModalPage = null;
                }
            });
            
            // Close modal with Escape key
            const escapeHandler = function(e) {
                if (e.key === 'Escape') {
                    modal.remove();
                    window.loadModalPage = null;
                    document.removeEventListener('keydown', escapeHandler);
                }
            };
            document.addEventListener('keydown', escapeHandler);
            
            // Load initial items
            loadItems(1, '');
        }

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            initializeCardClicks();
            loadCardItems();
            
            
            // Auto-submit form when filters change
            const filterForm = document.getElementById('filterForm');
            let searchTimeout;
            
            // Auto-submit on dropdown change (immediate)
            document.querySelectorAll('.filter-select').forEach(select => {
                select.addEventListener('change', function() {
                    // Clear special filter when user manually changes filters
                    document.querySelector('#specialFilter').value = '';
                    filterForm.submit();
                });
            });
            
            // Auto-submit on search input (with debounce - wait 500ms after user stops typing)
            const searchInput = document.querySelector('.search-input');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    // Clear special filter when user manually searches
                    document.querySelector('#specialFilter').value = '';
                    searchTimeout = setTimeout(() => {
                        filterForm.submit();
                    }, 500); // Wait 500ms after user stops typing
                });
            }
        });

        // Load items for each card
        function loadCardItems() {
            // Get department from form
            const form = document.getElementById('filterForm');
            const departmentSelect = form.querySelector('select[name="department"]');
            const departmentInput = form.querySelector('input[name="department"]');
            let department = departmentSelect ? departmentSelect.value : (departmentInput ? departmentInput.value : '');
            
            // If no department found in form, try to get from URL parameter
            if (!department) {
                const urlParams = new URLSearchParams(window.location.search);
                department = urlParams.get('department') || '';
            }
            
            // If still no department, try to get from main-content data attribute (for department heads)
            if (!department) {
                const mainContent = document.querySelector('.main-content');
                department = mainContent ? mainContent.getAttribute('data-user-department') || '' : '';
            }
            
            if (!department) {
                return; // No department selected, don't load items
            }
            
            // Get all stat cards with card-type attribute
            const statCards = document.querySelectorAll('.stat-card[data-card-type]');
            
            statCards.forEach(card => {
                const cardType = card.getAttribute('data-card-type');
                const itemsContainer = card.querySelector('.items-container');
                const loadingDiv = card.querySelector('.items-loading');
                
                // Skip if container or loading div doesn't exist
                if (!itemsContainer || !loadingDiv) {
                    console.warn(`Card ${cardType} is missing items-container or items-loading div`);
                    return;
                }
                
                // Fetch items for this card
                fetch(`dashboard_api.php?action=card_items&department=${encodeURIComponent(department)}&card_type=${cardType}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            if (data.items && data.items.length > 0) {
                                displayCardItems(itemsContainer, data.items, cardType);
                                loadingDiv.style.display = 'none';
                                itemsContainer.style.display = 'block';
                            } else {
                                const noItemsText = cardType === 'pending_requests' ? 'No request found' : 'No items found';
                                loadingDiv.textContent = noItemsText;
                                loadingDiv.style.color = '#cbd5e0';
                                itemsContainer.style.display = 'none';
                            }
                        } else {
                            console.error('API error for', cardType, ':', data.error);
                            const noItemsText = cardType === 'pending_requests' ? 'No request found' : 'No items found';
                            loadingDiv.textContent = data.error || noItemsText;
                            loadingDiv.style.color = '#cbd5e0';
                            itemsContainer.style.display = 'none';
                        }
                    })
                    .catch(error => {
                        console.error('Error loading card items for', cardType, ':', error);
                        const errorText = cardType === 'pending_requests' ? 'Error loading requests' : 'Error loading items';
                        loadingDiv.textContent = errorText;
                        loadingDiv.style.color = '#e53e3e';
                        itemsContainer.style.display = 'none';
                    });
            });
        }

        // Display items in card
        function displayCardItems(container, items, cardType) {
            if (items.length === 0) {
                const noItemsText = cardType === 'pending_requests' ? 'No request found' : 'No items found';
                container.innerHTML = `<div style="text-align: center; color: #cbd5e0; padding: 6px; font-size: 11px;">${noItemsText}</div>`;
                return;
            }
            
            // For low_stock, total_items, and not_working, display item tables instead of individual items
            if (cardType === 'low_stock' || cardType === 'total_items' || cardType === 'not_working') {
                // Show max 5 item tables, with "and X more" if there are more
                const maxDisplay = 5;
                const displayItems = items.slice(0, maxDisplay);
                const remainingCount = items.length - maxDisplay;
                
                // Different border color for total_items vs low_stock vs not_working
                const borderColor = cardType === 'low_stock' ? '#fed7d7' : (cardType === 'not_working' ? '#fed7d7' : '#c6f6d5');
                
                let itemsHTML = '<div style="display: flex; flex-direction: column; gap: 4px;">';
                
                displayItems.forEach(item => {
                    const itemCount = item.item_count || 0;
                    const isConsumable = item.is_consumable == 1 || item.is_consumable === '1';
                    
                    itemsHTML += `
                        <div style="padding: 6px 8px; background: #f8f9fa; border-radius: 4px; border-left: 2px solid ${borderColor}; cursor: pointer; transition: all 0.2s;"
                             onclick="event.stopPropagation(); showTableItems(${item.id}, '${(item.table_name || 'N/A').replace(/'/g, "\\'")}')"
                             onmouseover="this.style.background='#f0f4f8'; this.style.transform='translateX(2px)';"
                             onmouseout="this.style.background='#f8f9fa'; this.style.transform='translateX(0)';">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 600; color: #2d3748; font-size: 11px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${item.table_name || 'N/A'}">
                                        ${item.table_name || 'N/A'}
                                    </div>
                                    <div style="font-size: 10px; color: #718096; margin-bottom: 2px;">
                                        <strong style="color: #3b82f6;">${item.department_name || 'Unknown'}</strong>
                                    </div>
                                    <div style="font-size: 10px; color: #718096;">
                                        ${item.category || 'N/A'} • ${itemCount} ${itemCount === 1 ? 'item' : 'items'}
                                    </div>
                                </div>
                                <div style="margin-left: 8px; text-align: right; display: flex; flex-direction: column; gap: 4px; align-items: flex-end;">
                                    ${isConsumable ? `
                                    <span style="background: #fef3c7; color: #d97706; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; display: inline-block;">
                                        Consumable
                                    </span>
                                    ` : ''}
                                    ${cardType === 'low_stock' ? `
                                    <span style="background: #fed7d7; color: #e53e3e; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; display: inline-block;">
                                        Low Stock
                                    </span>
                                    ` : ''}
                                    ${cardType === 'not_working' ? `
                                    <span style="background: #fed7d7; color: #c53030; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; display: inline-block;">
                                        Defective
                                    </span>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                if (remainingCount > 0) {
                    itemsHTML += `
                        <div class="view-more-items" 
                             data-card-type="${cardType}"
                             style="text-align: center; padding: 6px; color: #3b82f6; font-size: 10px; font-weight: 600; border-top: 1px solid #e2e8f0; margin-top: 4px; padding-top: 8px; cursor: pointer; transition: all 0.2s;"
                             onmouseover="this.style.color='#2563eb'; this.style.textDecoration='underline';"
                             onmouseout="this.style.color='#3b82f6'; this.style.textDecoration='none';"
                             onclick="event.stopPropagation(); viewAllCardItems('${cardType}')">
                            and ${remainingCount} more ${remainingCount === 1 ? 'item table' : 'item tables'}...
                        </div>
                    `;
                }
                
                itemsHTML += '</div>';
                container.innerHTML = itemsHTML;
                return;
            }
            
            // For pending_requests, display request information
            if (cardType === 'pending_requests') {
                // Show max 5 requests, with "and X more" if there are more
                const maxDisplay = 5;
                const displayItems = items.slice(0, maxDisplay);
                const remainingCount = items.length - maxDisplay;
                
                let itemsHTML = '<div style="display: flex; flex-direction: column; gap: 4px;">';
                
                displayItems.forEach(item => {
                    const requestDate = item.request_date ? new Date(item.request_date).toLocaleDateString() : 'N/A';
                    const requestedBy = item.requested_by || 'Unknown';
                    
                    itemsHTML += `
                        <div style="padding: 6px 8px; background: #f8f9fa; border-radius: 4px; border-left: 2px solid #fef3c7; cursor: pointer; transition: all 0.2s;"
                             onclick="event.stopPropagation(); window.location.href='item_requests.php'"
                             onmouseover="this.style.background='#f0f4f8'; this.style.transform='translateX(2px)';"
                             onmouseout="this.style.background='#f8f9fa'; this.style.transform='translateX(0)';">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="flex: 1; min-width: 0;">
                                    <div style="font-weight: 600; color: #2d3748; font-size: 11px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${item.name || 'N/A'}">
                                        ${item.name || 'N/A'}
                                    </div>
                                    <div style="font-size: 10px; color: #718096; margin-bottom: 2px;">
                                        <strong style="color: #3b82f6;">Requested by:</strong> ${requestedBy}
                                    </div>
                                    <div style="font-size: 10px; color: #718096;">
                                        ${item.category || 'N/A'} • ${requestDate}
                                    </div>
                                </div>
                                <div style="margin-left: 8px; text-align: right; display: flex; flex-direction: column; gap: 4px; align-items: flex-end;">
                                    <span style="background: #fef3c7; color: #d97706; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; display: inline-block;">
                                        Pending
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                if (remainingCount > 0) {
                    itemsHTML += `
                        <div class="view-more-items" 
                             data-card-type="${cardType}"
                             style="text-align: center; padding: 6px; color: #3b82f6; font-size: 10px; font-weight: 600; border-top: 1px solid #e2e8f0; margin-top: 4px; padding-top: 8px; cursor: pointer; transition: all 0.2s;"
                             onmouseover="this.style.color='#2563eb'; this.style.textDecoration='underline';"
                             onmouseout="this.style.color='#3b82f6'; this.style.textDecoration='none';"
                             onclick="event.stopPropagation(); viewAllCardItems('${cardType}')">
                            and ${remainingCount} more ${remainingCount === 1 ? 'request' : 'requests'}...
                        </div>
                    `;
                }
                
                itemsHTML += '</div>';
                container.innerHTML = itemsHTML;
                return;
            }
            
            // For other card types, display individual items
            // Show max 5 items, with "and X more" if there are more
            const maxDisplay = 5;
            const displayItems = items.slice(0, maxDisplay);
            const remainingCount = items.length - maxDisplay;
            
            let itemsHTML = '<div style="display: flex; flex-direction: column; gap: 4px;">';
            
            displayItems.forEach(item => {
                const quantityColor = item.quantity <= 5 ? '#e53e3e' : '#2f855a';
                const quantityBg = item.quantity <= 5 ? '#fed7d7' : '#c6f6d5';
                
                itemsHTML += `
                    <div style="padding: 6px 8px; background: #f8f9fa; border-radius: 4px; border-left: 2px solid ${quantityBg}; cursor: pointer; transition: all 0.2s;"
                         onclick="event.stopPropagation(); window.location.href='view_item.php?id=${item.id}'"
                         onmouseover="this.style.background='#f0f4f8'; this.style.transform='translateX(2px)';"
                         onmouseout="this.style.background='#f8f9fa'; this.style.transform='translateX(0)';">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 600; color: #2d3748; font-size: 11px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${item.name || 'N/A'}">
                                    ${item.name || 'N/A'}
                                </div>
                                <div style="font-size: 10px; color: #718096; margin-bottom: 2px;">
                                    <strong style="color: #3b82f6;">${item.department_name || 'Unknown'}</strong>
                                </div>
                                <div style="font-size: 10px; color: #718096;">
                                    ${item.category || 'N/A'} • ${item.location || 'N/A'}
                                </div>
                            </div>
                            <div style="margin-left: 8px; text-align: right; display: flex; flex-direction: column; gap: 4px; align-items: flex-end;">
                                ${item.item_code ? `
                                <span style="background: #e0e7ff; color: #3730a3; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; display: inline-block; font-family: monospace; white-space: nowrap; max-width: 120px; overflow: hidden; text-overflow: ellipsis;" title="${item.item_code}">
                                    ${item.item_code}
                                </span>
                                ` : ''}
                                ${(item.display_status || item.status) ? `
                                    <span style="background: ${(item.display_status || item.status) === 'Working' ? '#c6f6d5' : ((item.display_status || item.status) === 'Broken' ? '#fed7d7' : (item.display_status || item.status) === 'Borrowed' ? '#fef3c7' : '#feebc8')}; color: ${(item.display_status || item.status) === 'Working' ? '#2f855a' : ((item.display_status || item.status) === 'Broken' ? '#c53030' : (item.display_status || item.status) === 'Borrowed' ? '#d97706' : '#c05621')}; padding: 2px 6px; border-radius: 3px; font-size: 9px; font-weight: 600; display: inline-block;">
                                        ${item.display_status || item.status}
                                    </span>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            if (remainingCount > 0) {
                // Store all items in a data attribute for easy access
                const cardTitle = cardType === 'total_items' ? 'Total Items Inventory' : 
                                 cardType === 'low_stock' ? 'Low Stock Consumables' : 
                                 cardType === 'not_working' ? 'Defective Items' : 'Items';
                
                itemsHTML += `
                    <div class="view-more-items" 
                         data-card-type="${cardType}"
                         style="text-align: center; padding: 6px; color: #3b82f6; font-size: 10px; font-weight: 600; border-top: 1px solid #e2e8f0; margin-top: 4px; padding-top: 8px; cursor: pointer; transition: all 0.2s;"
                         onmouseover="this.style.color='#2563eb'; this.style.textDecoration='underline';"
                         onmouseout="this.style.color='#3b82f6'; this.style.textDecoration='none';"
                         onclick="event.stopPropagation(); viewAllCardItems('${cardType}')">
                        and ${remainingCount} more ${remainingCount === 1 ? 'item' : 'items'}...
                    </div>
                `;
            }
            
            itemsHTML += '</div>';
            container.innerHTML = itemsHTML;
        }

        // Initialize click handlers for dashboard cards
        function initializeCardClicks() {
            // Stat cards click handlers - only trigger on card header, not on items list
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                // Add click handler to the card, but check if click is on items list
                card.addEventListener('click', function(e) {
                    // Don't trigger modal if clicking on items list or items container
                    if (e.target.closest('.card-items-list') || e.target.closest('.items-container')) {
                        return; // Let the item's own click handler handle it
                    }
                    
                    const filterType = this.getAttribute('data-filter');
                    if (filterType) {
                        handleStatCardClick(filterType);
                    }
                });
            });
        }

        // Handle stat card clicks
        function handleStatCardClick(filterType) {
            // Get department from form
            const form = document.getElementById('filterForm');
            const departmentSelect = form.querySelector('select[name="department"]');
            const departmentInput = form.querySelector('input[name="department"]');
            const department = departmentSelect ? departmentSelect.value : (departmentInput ? departmentInput.value : '');
            
            if (!department) {
                alert('Please select a department first.');
                return;
            }
            
            // Map filter types to card types
            const cardTypeMap = {
                'all_items': 'total_items',
                'low_stock': 'low_stock',
                'not_working': 'not_working',
                'pending_requests': 'pending_requests'
            };
            
            const cardType = cardTypeMap[filterType];
            if (!cardType) {
                // For other types, use old behavior
                switch(filterType) {
                    case 'active_borrows':
                        filterTableByBorrowItems();
                        break;
                    default:
                        break;
                }
                return;
            }
            
            // Handle pending requests separately
            if (cardType === 'pending_requests') {
                showPendingRequests();
                return;
            }
            
            // Apply filter to item table based on card type
            const specialFilter = form.querySelector('#specialFilter');
            const searchInput = form.querySelector('input[name="search"]');
            
            // Clear other filters first
            searchInput.value = '';
            form.querySelector('select[name="status"]').value = '';
            
            // Set special filter based on card type
            switch(cardType) {
                case 'low_stock':
                    specialFilter.value = 'low_stock';
                    break;
                case 'not_working':
                    specialFilter.value = 'not_working';
                    break;
                case 'total_items':
                    // For total items, use 'all_items' as special filter to show all
                    specialFilter.value = 'all_items';
                    break;
            }
            
            // Submit form to filter the table (will reload page) - NO MODAL
            submitForm();
        }
        
        // Show pending requests in main content area
        async function showPendingRequests() {
            // First, show the pending requests section
            const pendingSection = document.getElementById('pendingRequestsSection');
            if (!pendingSection) {
                console.error('Pending requests section not found in DOM');
                alert('Pending requests section not found. Please refresh the page.');
                return;
            }
            
            // Hide all other sections - be more aggressive
            const sectionsToHide = [
                '.table-container',
                '.items-grid-container',
                '.items-list-container',
                '.items-cards-container',
                '.empty-state'
            ];
            
            sectionsToHide.forEach(selector => {
                document.querySelectorAll(selector).forEach(el => {
                    if (el && el.id !== 'pendingRequestsSection' && !el.closest('#pendingRequestsSection')) {
                        el.style.display = 'none';
                    }
                });
            });
            
            // Hide message divs (but not the pending requests section or its children)
            const messageDivs = document.querySelectorAll('div');
            messageDivs.forEach(div => {
                if (div.id === 'pendingRequestsSection' || div.closest('#pendingRequestsSection')) {
                    return; // Skip pending requests section and its children
                }
                
                const text = div.textContent || '';
                if (text.includes('No Items Found') || text.includes('No Item Tables Found') || text.includes('Apply Filters to View')) {
                    // Only hide if it's not inside pending requests section
                    if (!div.closest('#pendingRequestsSection')) {
                        div.style.display = 'none';
                    }
                }
            });
            
            // Make sure parent containers are visible
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.style.display = 'block';
                mainContent.style.visibility = 'visible';
            }
            
            // Show pending requests section
            pendingSection.style.display = 'block';
            pendingSection.style.visibility = 'visible';
            pendingSection.style.opacity = '1';
            
            // Scroll to section to make sure it's visible
            pendingSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            
            const listContainer = document.getElementById('pendingRequestsList');
            if (listContainer) {
                listContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;"><p>Loading pending requests...</p></div>';
                
                // Get selected department from form
                const form = document.getElementById('filterForm');
                const departmentSelect = form.querySelector('select[name="department"]');
                const departmentInput = form.querySelector('input[name="department"]');
                const department = departmentSelect ? departmentSelect.value : (departmentInput ? departmentInput.value : '');
                
                // Build URL with department parameter if selected
                let url = 'crud.php?action=get_pending_borrow_requests';
                if (department) {
                    url += '&department=' + encodeURIComponent(department);
                }
                
                try {
                    const response = await fetch(url, {
                        method: 'GET',
                        credentials: 'same-origin'
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        const requests = data.requests || [];
                        if (requests.length === 0) {
                            listContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;"><p>No request found</p></div>';
                        } else {
                            displayPendingRequests(requests);
                        }
                    } else {
                        listContainer.innerHTML = `<div style="text-align: center; padding: 40px; color: #dc2626;"><p>Error: ${data.message || 'Failed to load pending requests'}</p></div>`;
                    }
                } catch (error) {
                    listContainer.innerHTML = `<div style="text-align: center; padding: 40px; color: #dc2626;"><p>Error loading pending requests: ${error.message}</p></div>`;
                }
            }
        }
        
        // Display pending requests in list format
        function displayPendingRequests(requests) {
            const listContainer = document.getElementById('pendingRequestsList');
            if (!listContainer) return;
            
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            let requestsHTML = '<div style="display: flex; flex-direction: column; gap: 16px;">';
            
            requests.forEach(request => {
                const borrowDate = new Date(request.borrow_date).toLocaleDateString();
                const neededDate = request.date_needed ? new Date(request.date_needed).toLocaleDateString() : 'Not specified';
                const dueDate = new Date(request.due_date).toLocaleDateString();
                
                // Store request data in data attributes
                const requestData = JSON.stringify(request).replace(/"/g, '&quot;');
                
                requestsHTML += `
                    <div style="background: #f9fafb; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; transition: all 0.2s;" 
                         onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.1)'; this.style.borderColor='#cbd5e0';"
                         onmouseout="this.style.boxShadow='none'; this.style.borderColor='#e2e8f0';">
                        <div style="display: flex; align-items: flex-start; gap: 16px; margin-bottom: 16px;">
                            <div style="flex: 1;">
                                <h4 style="margin: 0 0 8px 0; color: #1f2937; font-size: 18px; font-weight: 600;">${escapeHtml(request.item_name)}</h4>
                                <p style="margin: 4px 0; color: #6b7280; font-size: 14px;"><strong>Borrower:</strong> ${escapeHtml(request.borrower_name)}</p>
                                <p style="margin: 4px 0; color: #6b7280; font-size: 14px;"><strong>Email:</strong> ${escapeHtml(request.borrower_email)}</p>
                                <p style="margin: 4px 0; color: #6b7280; font-size: 14px;"><strong>Department:</strong> ${escapeHtml(request.department_name || 'N/A')}</p>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; padding: 12px; background: white; border-radius: 6px;">
                            <div>
                                <p style="margin: 0; color: #9ca3af; font-size: 12px;">Borrow Date</p>
                                <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600;">${borrowDate}</p>
                            </div>
                            <div>
                                <p style="margin: 0; color: #9ca3af; font-size: 12px;">Date Needed</p>
                                <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600;">${neededDate}</p>
                            </div>
                            <div>
                                <p style="margin: 0; color: #9ca3af; font-size: 12px;">Due Date</p>
                                <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600;">${dueDate}</p>
                            </div>
                        </div>
                        ${request.purpose ? `<div style="margin-bottom: 12px; padding: 12px; background: #f0f9ff; border-left: 3px solid #0ea5e9; border-radius: 4px;"><p style="margin: 0; color: #1e40af; font-size: 14px;"><strong>Purpose/Notes:</strong> ${escapeHtml(request.purpose)}</p></div>` : ''}
                        <div style="display: flex; gap: 8px; justify-content: flex-end;">
                            <button onclick="showApproveRejectModalFromData(this)" data-request='${requestData}' data-action="approve" style="background: #10b981; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s;" onmouseover="this.style.background='#059669';" onmouseout="this.style.background='#10b981';">✓ Approve</button>
                            <button onclick="showApproveRejectModalFromData(this)" data-request='${requestData}' data-action="reject" style="background: #ef4444; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s;" onmouseover="this.style.background='#dc2626';" onmouseout="this.style.background='#ef4444';">✗ Decline</button>
                        </div>
                    </div>
                `;
            });
            
            requestsHTML += '</div>';
            listContainer.innerHTML = requestsHTML;
        }
        
        // Show approve/reject modal from button data
        function showApproveRejectModalFromData(button) {
            const requestData = button.getAttribute('data-request');
            const action = button.getAttribute('data-action') || 'approve';
            
            if (!requestData) {
                console.error('Request data not found');
                return;
            }
            
            try {
                // Decode the request data
                const request = JSON.parse(requestData.replace(/&quot;/g, '"'));
                showApproveRejectModal(request, action);
            } catch (error) {
                console.error('Error parsing request data:', error);
                alert('Error loading request details. Please try again.');
            }
        }
        
        // Show approve/reject modal
        function showApproveRejectModal(request, action = 'approve') {
            // Remove any existing modal first
            const existingModal = document.getElementById('approveRejectModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            const modal = document.createElement('div');
            modal.id = 'approveRejectModal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.6);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 2000;
                backdrop-filter: blur(4px);
            `;
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: white;
                border-radius: 12px;
                padding: 24px;
                max-width: 90%;
                width: 500px;
                box-shadow: 0 25px 50px rgba(0,0,0,0.25);
                position: relative;
            `;
            
            const borrowDate = new Date(request.borrow_date).toLocaleDateString();
            const neededDate = request.date_needed ? new Date(request.date_needed).toLocaleDateString() : 'Not specified';
            const dueDate = new Date(request.due_date).toLocaleDateString();
            
            const actionText = action === 'approve' ? 'Approve' : 'Decline';
            const actionColor = action === 'approve' ? '#10b981' : '#ef4444';
            const actionIcon = action === 'approve' ? '✓' : '✗';
            const confirmText = action === 'approve' 
                ? 'Are you sure you want to approve this borrow request?'
                : 'Are you sure you want to decline this borrow request?';
            
            modalContent.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: #1f2937; font-size: 20px; font-weight: 600;">${actionText} Borrow Request</h3>
                    <button onclick="closeApproveRejectModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 0; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 6px;" onmouseover="this.style.background='#f3f4f6';" onmouseout="this.style.background='none';">&times;</button>
                </div>
                
                <div style="background: #f9fafb; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 12px 0; color: #1f2937; font-size: 18px; font-weight: 600;">${escapeHtml(request.item_name)}</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 12px;">
                        <div>
                            <p style="margin: 0; color: #9ca3af; font-size: 12px;">Borrower</p>
                            <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600; font-size: 14px;">${escapeHtml(request.borrower_name)}</p>
                        </div>
                        <div>
                            <p style="margin: 0; color: #9ca3af; font-size: 12px;">Email</p>
                            <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600; font-size: 14px;">${escapeHtml(request.borrower_email)}</p>
                        </div>
                        <div>
                            <p style="margin: 0; color: #9ca3af; font-size: 12px;">Department</p>
                            <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600; font-size: 14px;">${escapeHtml(request.department_name || 'N/A')}</p>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 12px;">
                        <div>
                            <p style="margin: 0; color: #9ca3af; font-size: 12px;">Borrow Date</p>
                            <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600; font-size: 14px;">${borrowDate}</p>
                        </div>
                        <div>
                            <p style="margin: 0; color: #9ca3af; font-size: 12px;">Date Needed</p>
                            <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600; font-size: 14px;">${neededDate}</p>
                        </div>
                        <div>
                            <p style="margin: 0; color: #9ca3af; font-size: 12px;">Due Date</p>
                            <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600; font-size: 14px;">${dueDate}</p>
                        </div>
                    </div>
                    ${request.purpose ? `<div style="margin-top: 12px; padding: 12px; background: #f0f9ff; border-left: 3px solid #0ea5e9; border-radius: 4px;"><p style="margin: 0; color: #1e40af; font-size: 14px;"><strong>Purpose/Notes:</strong> ${escapeHtml(request.purpose)}</p></div>` : ''}
                </div>
                
                <p style="margin: 0 0 20px 0; color: #4b5563; font-size: 14px; line-height: 1.5;">${confirmText}</p>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button onclick="closeApproveRejectModal()" style="background: #f3f4f6; color: #374151; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s;" onmouseover="this.style.background='#e5e7eb';" onmouseout="this.style.background='#f3f4f6';">Cancel</button>
                    <button onclick="confirmApproveReject('${request.borrow_id}', '${action}')" style="background: ${actionColor}; color: white; border: none; padding: 10px 24px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s;" onmouseover="this.style.background='${action === 'approve' ? '#059669' : '#dc2626'}';" onmouseout="this.style.background='${actionColor}';">
                        ${actionIcon} ${actionText}
                    </button>
                </div>
            `;
            
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
            
            // Close on background click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeApproveRejectModal();
                }
            });
        }
        
        // Close approve/reject modal
        function closeApproveRejectModal() {
            const modal = document.getElementById('approveRejectModal');
            if (modal) {
                modal.remove();
            }
        }
        
        // Confirm approve/reject action
        async function confirmApproveReject(borrowId, action) {
            const actionText = action === 'approve' ? 'approve' : 'decline';
            const actionLabel = action === 'approve' ? 'approved' : 'declined';
            
            try {
                const response = await fetch('crud.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=${actionText}_borrow_request&borrow_id=${borrowId}`,
                    credentials: 'same-origin'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    closeApproveRejectModal();
                    const message = action === 'approve' 
                        ? 'Borrow request approved successfully. The borrower will be notified via email.'
                        : 'Borrow request declined. The borrower will be notified via email.';
                    alert(message);
                    showPendingRequests(); // Reload the list
                } else {
                    alert('Error: ' + (data.message || `Failed to ${actionText} borrow request.`));
                }
            } catch (error) {
                console.error(`Error ${actionText}ing borrow request:`, error);
                alert(`Error ${actionText}ing borrow request. Please try again.`);
            }
        }
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // View all items from card (called when clicking "and X more items...")
        function viewAllCardItems(cardType) {
            // Get department from form
            const form = document.getElementById('filterForm');
            const departmentSelect = form.querySelector('select[name="department"]');
            const departmentInput = form.querySelector('input[name="department"]');
            const department = departmentSelect ? departmentSelect.value : (departmentInput ? departmentInput.value : '');
            
            if (!department) {
                alert('Please select a department first.');
                return;
            }
            
            // Show modal with pagination support
            const titles = {
                'total_items': 'Total Items Inventory',
                'low_stock': 'Low Stock Consumables',
                'not_working': 'Defective Items',
                'pending_requests': 'Pending Requests'
            };
            showItemsModal(titles[cardType] || 'Items', cardType, department);
        }

        // Filter functions for different card types
        function filterTableByLowStock() {
            const form = document.getElementById('filterForm');
            const specialFilter = form.querySelector('#specialFilter');
            const searchInput = form.querySelector('input[name="search"]');
            
            // Clear other filters
            searchInput.value = '';
            form.querySelector('select[name="department"]').value = '';
            form.querySelector('select[name="status"]').value = '';
            
            // Set special filter
            specialFilter.value = 'low_stock';
            submitForm();
        }

        function filterTableByRecentActivities() {
            const form = document.getElementById('filterForm');
            const specialFilter = form.querySelector('#specialFilter');
            const searchInput = form.querySelector('input[name="search"]');
            
            // Clear all filters
            searchInput.value = '';
            form.querySelector('select[name="department"]').value = '';
            form.querySelector('select[name="category"]').value = '';
            form.querySelector('select[name="status"]').value = '';
            
            // Set special filter
            specialFilter.value = 'recent_activities';
            submitForm();
        }

        function filterTableByBorrowItems() {
            const form = document.getElementById('filterForm');
            const specialFilter = form.querySelector('#specialFilter');
            const searchInput = form.querySelector('input[name="search"]');
            
            // Clear all filters
            searchInput.value = '';
            form.querySelector('select[name="department"]').value = '';
            form.querySelector('select[name="category"]').value = '';
            form.querySelector('select[name="status"]').value = '';
            
            // Set special filter
            specialFilter.value = 'borrow_items';
            submitForm();
        }

        function filterTableByNotWorking() {
            const form = document.getElementById('filterForm');
            const specialFilter = form.querySelector('#specialFilter');
            const searchInput = form.querySelector('input[name="search"]');
            
            // Clear other filters
            searchInput.value = '';
            form.querySelector('select[name="department"]').value = '';
            form.querySelector('select[name="category"]').value = '';
            
            // Set special filter
            specialFilter.value = 'not_working';
            submitForm();
        }

        function showAllItems() {
            const form = document.getElementById('filterForm');
            
            // Clear all filters
            form.querySelector('input[name="search"]').value = '';
            form.querySelector('select[name="department"]').value = '';
            form.querySelector('select[name="status"]').value = '';
            form.querySelector('#specialFilter').value = '';
            
            submitForm();
        }

        // Clear all filters and hide item tables
        function clearAllFilters() {
            const form = document.getElementById('filterForm');
            const departmentInput = form.querySelector('select[name="department"]') || form.querySelector('input[name="department"]');
            const departmentValue = departmentInput ? departmentInput.value : '';
            
            // Build clean URL with only department (if exists) and no empty parameters
            let cleanUrl = 'dashboard.php';
            if (departmentValue) {
                cleanUrl += '?department=' + encodeURIComponent(departmentValue);
            }
            
            // Redirect to clean URL without empty parameters
            window.location.href = cleanUrl;
        }

        // Submit form to filter table
        function submitForm() {
            const form = document.getElementById('filterForm');
            form.submit();
        }


        // Form validation - require department before submit (only for super admins)
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            const departmentSelect = this.querySelector('select[name="department"]');
            if (departmentSelect && !departmentSelect.value) {
                e.preventDefault();
                alert('Please select a department first.');
                departmentSelect.focus();
                return false;
            }
        });

        // Switch to grid view
        function switchDashboardToGridView() {
            console.log('Switching to grid view');
            const cardsContainer = document.getElementById('itemsCardsContainer');
            if (cardsContainer) {
                cardsContainer.classList.remove('list-layout');
                cardsContainer.classList.add('grid-layout');
                console.log('Grid layout applied');
            }
            updateDashboardViewToggleButtons('grid');
        }

        // Switch to list view
        function switchDashboardToListView() {
            console.log('Switching to list view');
            const cardsContainer = document.getElementById('itemsCardsContainer');
            if (cardsContainer) {
                cardsContainer.classList.remove('grid-layout');
                cardsContainer.classList.add('list-layout');
                console.log('List layout applied');
            }
            updateDashboardViewToggleButtons('list');
        }

        // Update view toggle button states
        function updateDashboardViewToggleButtons(activeView) {
            // Update first set of buttons
            const gridBtn = document.getElementById('dashboardGridViewBtn');
            const listBtn = document.getElementById('dashboardListViewBtn');
            
            // Update second set of buttons (in the item tables header)
            const gridBtn2 = document.getElementById('dashboardGridViewBtn2');
            const listBtn2 = document.getElementById('dashboardListViewBtn2');
            
            if (activeView === 'grid') {
                if (gridBtn) gridBtn.classList.add('active');
                if (listBtn) listBtn.classList.remove('active');
                if (gridBtn2) gridBtn2.classList.add('active');
                if (listBtn2) listBtn2.classList.remove('active');
            } else {
                if (listBtn) listBtn.classList.add('active');
                if (gridBtn) gridBtn.classList.remove('active');
                if (listBtn2) listBtn2.classList.add('active');
                if (gridBtn2) gridBtn2.classList.remove('active');
            }
        }

        // Export to PDF functionality
        function exportToPDF() {
            <?php 
            // Get user's department for department heads
            $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
            $is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
            $is_department_head = $is_admin && !$is_super_admin;
            $user_department = isset($_SESSION['department']) ? trim($_SESSION['department']) : '';
            
            if ($is_department_head && !empty($user_department)) {
                echo "window.open('pdf_export.php?type=dashboard&department=" . urlencode($user_department) . "', '_blank');";
            } else {
                echo "window.open('pdf_export.php?type=dashboard', '_blank');";
            }
            ?>
        }

        // Show items in a specific table (with pagination)
        function showTableItems(tableId, tableName) {
            // Use the same modal structure but with table-specific API
            showItemsModalForTable(tableName, tableId);
        }
        
        // Modal function for table items (similar to showItemsModal but for tables)
        function showItemsModalForTable(title, tableId) {
            // Remove any existing modal first
            const existingModal = document.querySelector('.items-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            const modal = document.createElement('div');
            modal.className = 'items-modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.6);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 2000;
                backdrop-filter: blur(4px);
            `;
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: white;
                border-radius: 12px;
                padding: 20px;
                max-width: 90%;
                width: 900px;
                max-height: 85%;
                display: flex;
                flex-direction: column;
                box-shadow: 0 25px 50px rgba(0,0,0,0.25);
                position: relative;
            `;
            
            // Store modal state
            let currentPage = 1;
            let currentSearch = '';
            let totalItems = 0;
            let totalPages = 0;
            const itemsPerPage = 50;
            
            // Items container
            const itemsContainer = document.createElement('div');
            itemsContainer.style.cssText = `
                flex: 1;
                overflow-y: auto;
                margin-top: 15px;
                min-height: 0;
            `;
            
            // Loading indicator
            const loadingDiv = document.createElement('div');
            loadingDiv.style.cssText = 'text-align: center; padding: 40px; color: #666;';
            loadingDiv.textContent = 'Loading items...';
            itemsContainer.appendChild(loadingDiv);
            
            // Function to load items
            function loadItems(page = 1, search = '') {
                currentPage = page;
                currentSearch = search;
                
                // Show loading
                itemsContainer.innerHTML = '';
                itemsContainer.appendChild(loadingDiv);
                
                // Get special_filter from URL if present
                const urlParams = new URLSearchParams(window.location.search);
                const specialFilter = urlParams.get('special_filter') || '';
                
                const searchParam = search ? `&search=${encodeURIComponent(search)}` : '';
                const filterParam = specialFilter ? `&special_filter=${encodeURIComponent(specialFilter)}` : '';
                fetch(`dashboard_api.php?action=items_by_table&table_id=${tableId}&page=${page}&items_per_page=${itemsPerPage}${searchParam}${filterParam}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            totalItems = data.pagination.total_items;
                            totalPages = data.pagination.total_pages;
                            
                            // Update title with count
                            const titleWithCount = `${title} - Items (${totalItems} ${totalItems === 1 ? 'item' : 'items'})`;
                            titleElement.textContent = titleWithCount;
                            
                            // Render items
                            renderItems(data.items);
                            renderPagination();
                        } else {
                            itemsContainer.innerHTML = `<p style="text-align: center; color: #e53e3e; padding: 40px 20px;">Error: ${data.error || 'Failed to load items'}</p>`;
                        }
                    })
                    .catch(error => {
                        console.error('Error loading items:', error);
                        itemsContainer.innerHTML = `<p style="text-align: center; color: #e53e3e; padding: 40px 20px;">Error loading items. Please try again.</p>`;
                    });
            }
            
            // Function to render items as horizontal list (pahaba)
            function renderItems(items) {
                if (items.length === 0) {
                    itemsContainer.innerHTML = '<p style="text-align: center; color: #666; padding: 40px 20px;">No items found for this selection.</p>';
                    return;
                }
                
                const itemsHTML = `
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        ${items.map(item => {
                            const quantity = parseInt(item.quantity) || 0;
                            const status = item.display_status || item.status || 'Unknown';
                            
                            // Get item image or placeholder
                            const itemImage = item.table_image_path || item.image_path ? 
                                `<img src="${item.table_image_path || item.image_path}" alt="${item.name}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;" />` : 
                                `<div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 32px; background: #f3f4f6; border-radius: 8px;">📦</div>`;
                            
                            // Status badge colors
                            const statusColors = {
                                'Working': { bg: '#c6f6d5', color: '#2f855a' },
                                'Broken': { bg: '#fed7d7', color: '#c53030' },
                                'Borrowed': { bg: '#fef3c7', color: '#d97706' },
                                'Under Maintenance': { bg: '#feebc8', color: '#c05621' }
                            };
                            const statusStyle = statusColors[status] || { bg: '#e2e8f0', color: '#4a5568' };
                            
                            return `
                                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 16px; transition: all 0.2s; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.05);" 
                                 onclick="window.location.href='view_item.php?id=${item.id}'"
                                     onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.1)'; this.style.borderColor='#cbd5e0';"
                                     onmouseout="this.style.boxShadow='0 2px 4px rgba(0,0,0,0.05)'; this.style.borderColor='#e2e8f0';">
                                    <!-- Item Image/Icon -->
                                    <div style="flex-shrink: 0; width: 80px; height: 80px; border-radius: 8px; overflow: hidden;">
                                        ${itemImage}
                                    </div>
                                    
                                    <!-- Item Details -->
                                    <div style="flex: 1; min-width: 0;">
                                        <h4 style="margin: 0 0 8px 0; color: #2d3748; font-size: 18px; font-weight: 600;">${item.name || 'N/A'}</h4>
                                        <div style="display: flex; flex-direction: column; gap: 4px; color: #718096; font-size: 14px;">
                                            <div><strong>Department:</strong> ${item.department_name || 'Unknown'}</div>
                                            <div><strong>Category:</strong> ${item.category || 'Uncategorized'}</div>
                                            ${item.location ? `<div><strong>Location:</strong> ${item.location}</div>` : ''}
                                        </div>
                                    </div>
                                    
                                    <!-- Status Badges -->
                                    <div style="flex-shrink: 0; display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                                        ${item.item_code ? `
                                        <span style="background: #e0e7ff; color: #3730a3; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-block; font-family: monospace; white-space: nowrap;" title="${item.item_code}">
                                            ${item.item_code}
                                        </span>
                                        ` : ''}
                                        <span style="background: ${statusStyle.bg}; color: ${statusStyle.color}; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-block;">
                                            ${status}
                                        </span>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `;
                itemsContainer.innerHTML = itemsHTML;
            }
            
            // Function to render pagination
            function renderPagination() {
                if (totalPages <= 1) return;
                
                const paginationDiv = document.createElement('div');
                paginationDiv.style.cssText = `
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-top: 20px;
                    padding-top: 15px;
                    border-top: 1px solid #e2e8f0;
                `;
                
                const infoText = `Showing ${((currentPage - 1) * itemsPerPage) + 1} to ${Math.min(currentPage * itemsPerPage, totalItems)} of ${totalItems} items`;
                paginationDiv.innerHTML = `
                    <div style="color: #6b7280; font-size: 14px;">${infoText}</div>
                    <div style="display: flex; gap: 5px;">
                        ${currentPage > 1 ? `
                            <button onclick="loadTableModalPage(${currentPage - 1})" style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; cursor: pointer; font-size: 14px;">Previous</button>
                        ` : ''}
                        ${Array.from({length: Math.min(5, totalPages)}, (_, i) => {
                            let pageNum;
                            if (totalPages <= 5) {
                                pageNum = i + 1;
                            } else if (currentPage <= 3) {
                                pageNum = i + 1;
                            } else if (currentPage >= totalPages - 2) {
                                pageNum = totalPages - 4 + i;
                            } else {
                                pageNum = currentPage - 2 + i;
                            }
                            return `<button onclick="loadTableModalPage(${pageNum})" style="padding: 8px 12px; border: 1px solid ${pageNum === currentPage ? '#3b82f6' : '#d1d5db'}; border-radius: 6px; background: ${pageNum === currentPage ? '#3b82f6' : 'white'}; color: ${pageNum === currentPage ? 'white' : '#374151'}; cursor: pointer; font-size: 14px;">${pageNum}</button>`;
                        }).join('')}
                        ${currentPage < totalPages ? `
                            <button onclick="loadTableModalPage(${currentPage + 1})" style="padding: 8px 16px; border: 1px solid #d1d5db; border-radius: 6px; background: white; cursor: pointer; font-size: 14px;">Next</button>
                        ` : ''}
                    </div>
                `;
                
                // Store loadTableModalPage function on modal for access
                modal.loadTableModalPage = function(page) {
                    loadItems(page, currentSearch);
                };
                
                itemsContainer.appendChild(paginationDiv);
            }
            
            // Header with title and close button
            const headerDiv = document.createElement('div');
            headerDiv.style.cssText = 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px;';
            
            const titleElement = document.createElement('h3');
            titleElement.style.cssText = 'margin: 0; color: #2d3748; font-size: 20px; font-weight: 600;';
            titleElement.textContent = `${title} - Items`;
            
            const closeBtn = document.createElement('button');
            closeBtn.textContent = 'Close';
            closeBtn.style.cssText = 'background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 16px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s;';
            closeBtn.onmouseover = () => closeBtn.style.background = '#e2e8f0';
            closeBtn.onmouseout = () => closeBtn.style.background = '#f8f9fa';
            closeBtn.onclick = () => modal.remove();
            
            headerDiv.appendChild(titleElement);
            headerDiv.appendChild(closeBtn);
            
            // Search input
            const searchDiv = document.createElement('div');
            searchDiv.style.cssText = 'margin-bottom: 15px;';
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.placeholder = 'Search items...';
            searchInput.style.cssText = 'width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px;';
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    loadItems(1, this.value);
                }, 500);
            });
            searchDiv.appendChild(searchInput);
            
            modalContent.appendChild(headerDiv);
            modalContent.appendChild(searchDiv);
            modalContent.appendChild(itemsContainer);
            
            modal.appendChild(modalContent);
            document.body.appendChild(modal);
            
            // Global function for pagination buttons
            window.loadTableModalPage = function(page) {
                if (modal && modal.loadTableModalPage) {
                    modal.loadTableModalPage(page);
                }
            };
            
            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                    window.loadTableModalPage = null;
                }
            });
            
            // Close modal with Escape key
            const escapeHandler = function(e) {
                if (e.key === 'Escape') {
                    modal.remove();
                    window.loadTableModalPage = null;
                    document.removeEventListener('keydown', escapeHandler);
                }
            };
            document.addEventListener('keydown', escapeHandler);
            
            // Load initial items
            loadItems(1, '');
        }



        // Item actions dropdown: only View Details and Borrow Item
        function showItemActions(itemId, ev) {
            ev && ev.stopPropagation();
            // Close any existing action menus
            document.querySelectorAll('.dashboard-action-menu').forEach(m => m.remove());

            const btn = ev ? ev.currentTarget : null;
            const menu = document.createElement('div');
            menu.className = 'dashboard-action-menu';
            menu.style.position = 'absolute';
            menu.style.background = '#fff';
            menu.style.border = '1px solid #e5e7eb';
            menu.style.boxShadow = '0 6px 20px rgba(0,0,0,0.08)';
            menu.style.borderRadius = '8px';
            menu.style.padding = '6px 0';
            menu.style.zIndex = '1000';

            const rect = btn ? btn.getBoundingClientRect() : null;
            const top = rect ? rect.bottom + window.scrollY + 6 : 100;
            const left = rect ? rect.left + window.scrollX - 80 : 100;
            menu.style.top = top + 'px';
            menu.style.left = left + 'px';

            function addAction(label, handler, icon) {
                const a = document.createElement('button');
                a.type = 'button';
                a.style.display = 'flex';
                a.style.alignItems = 'center';
                a.style.gap = '8px';
                a.style.width = '100%';
                a.style.padding = '8px 12px';
                a.style.fontSize = '14px';
                a.style.background = 'transparent';
                a.style.border = 'none';
                a.style.cursor = 'pointer';
                a.onmouseenter = () => a.style.background = '#f9fafb';
                a.onmouseleave = () => a.style.background = 'transparent';
                a.innerHTML = `${icon || ''} ${label}`;
                a.addEventListener('click', () => {
                    handler();
                    menu.remove();
                });
                menu.appendChild(a);
            }

            addAction('View Details', () => {
                window.location.href = 'view_item.php?id=' + itemId;
            }, '<img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;" />');

            addAction('Borrow Item', () => {
                // Redirect to department page with borrow modal intent or implement inline later
                window.location.href = 'department.php?borrow_item_id=' + itemId;
            }, '<img src="image/book.png" alt="Borrow" style="width:14px;height:14px;vertical-align:middle;" />');

            document.body.appendChild(menu);

            function closeOnOutside(e) {
                if (!menu.contains(e.target)) {
                    menu.remove();
                    document.removeEventListener('click', closeOnOutside);
                }
            }
            setTimeout(() => document.addEventListener('click', closeOnOutside), 0);
        }

        // Basic styles for action menu (inline to avoid external CSS edits)
        (function ensureActionMenuStyles(){
            const styleId = 'dashboard-action-menu-style';
            if (document.getElementById(styleId)) return;
            const style = document.createElement('style');
            style.id = styleId;
            style.textContent = `.action-btn{position:relative}`;
            document.head.appendChild(style);
        })();

        // Auto-refresh dashboard data every 30 seconds
        function refreshDashboardData() {
            fetch('dashboard_api.php?action=stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update chart titles with new counts
                        document.querySelector('.low-stocks .chart-title').textContent = `Low Stock Consumables by Department (${data.stats.low_stock})`;
                        document.querySelector('.recently-activities .chart-title').textContent = `Recent Activities by Department (${data.stats.recent_activities})`;
                        document.querySelector('.borrow-item .chart-title').textContent = `Borrow Events by Department (${data.stats.active_borrows})`;
                        document.querySelector('.not-working .chart-title').textContent = `Defective Items by Department (${data.stats.not_working})`;
                    }
                })
                .catch(error => console.log('Error refreshing dashboard:', error));
        }

        // Start auto-refresh
        setInterval(refreshDashboardData, 30000); // 30 seconds


// Sidebar collapse/expand
(function() {
    const BODY_CLASS = 'sidebar-collapsed';
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function isMobile() {
        return window.innerWidth <= 768;
    }

    function applyInitialState() {
        if (!isMobile()) {
            const saved = localStorage.getItem('ocabis:sidebar-collapsed');
            const isCollapsed = saved === '1';
            document.body.classList.toggle(BODY_CLASS, isCollapsed);
        } else {
            // On mobile, ensure sidebar is closed initially
            if (sidebar) sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    function toggleSidebar(e) {
        if (e) e.stopPropagation();
        
        if (isMobile()) {
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
    document.addEventListener('click', function(e) {
        if (isMobile() && sidebar && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && !e.target.closest('#sidebarToggleFixed')) {
                closeSidebar();
            }
        }
    });

    // Add event listeners to both buttons
    const inlineBtn = document.getElementById('sidebarToggle');
    const fixedBtn = document.getElementById('sidebarToggleFixed');
    
    if (inlineBtn) {
        inlineBtn.addEventListener('click', toggleSidebar);
    }
    
    if (fixedBtn) {
        fixedBtn.addEventListener('click', toggleSidebar);
    }

    // Apply initial state
    applyInitialState();

    // Handle window resize
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            const nowMobile = window.innerWidth <= 768;
            // Close sidebar when switching to mobile or desktop
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            }
        }, 250);
    });

    // Apply initial state on load
    applyInitialState();
})();
    </script>

<?php 
// Show Manage Borrow Requests Modal for admin and superadmin
$is_admin_role = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$is_admin_or_super = $isSuperAdmin || $is_admin_role || $isDepartmentHead;
?>
<?php if ($is_admin_or_super): ?>
<div id="manageBorrowRequestsModal" class="modal-overlay" style="display: none; z-index: 2147483000; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center;">
    <div class="modal" style="max-width: 900px; width: 95%; max-height: 90vh; overflow-y: auto; z-index: 2147483001;">
        <div class="modal-header" style="position: relative; z-index: 1; background: linear-gradient(135deg, #818CF8, #A5B4FC); color: white;">
            <h3>Manage Borrow Requests</h3>
            <button class="close-btn" onclick="closeManageBorrowRequestsModal()" style="position: relative; z-index: 11; pointer-events: auto; background: transparent; border: none; color: white; font-size: 24px; cursor: pointer;">×</button>
        </div>
        <div class="modal-body" style="position: relative; z-index: 1; padding: 20px;">
            <div id="borrowRequestsList" style="min-height: 200px;">
                <div style="text-align: center; padding: 40px; color: #666;">
                    <p>Loading borrow requests...</p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Manage Borrow Requests Modal Functions
function openManageBorrowRequestsModal() {
    const modal = document.getElementById('manageBorrowRequestsModal');
    if (!modal) {
        alert('Error: Modal not found. Please refresh the page and try again.');
        return;
    }
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    document.body.classList.add('modal-open');
    
    // Load borrow requests
    loadBorrowRequests();
}

function closeManageBorrowRequestsModal() {
    const modal = document.getElementById('manageBorrowRequestsModal');
    if (!modal) return;
    
    modal.style.display = 'none';
    modal.style.visibility = 'hidden';
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
    document.body.classList.remove('modal-open');
}

async function loadBorrowRequests() {
    const listContainer = document.getElementById('borrowRequestsList');
    if (!listContainer) return;
    
    listContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;"><p>Loading borrow requests...</p></div>';
    
    try {
        const response = await fetch('crud.php?action=get_pending_borrow_requests', {
            method: 'GET',
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        
        const data = await response.json();
        
        if (data && data.success) {
            const requests = data.requests || [];
            if (requests.length === 0) {
                listContainer.innerHTML = '<div style="text-align: center; padding: 40px; color: #666;"><p>No pending borrow requests found for your department.</p></div>';
            } else {
                displayBorrowRequests(requests);
            }
        } else {
            listContainer.innerHTML = `<div style="text-align: center; padding: 40px; color: #dc2626;"><p>Error: ${data.message || 'Failed to load borrow requests'}</p></div>`;
        }
    } catch (error) {
        listContainer.innerHTML = `<div style="text-align: center; padding: 40px; color: #dc2626;"><p>Error loading borrow requests: ${error.message}</p></div>`;
    }
}

function displayBorrowRequests(requests) {
    const listContainer = document.getElementById('borrowRequestsList');
    if (!listContainer) return;
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    const requestsHTML = requests.map(request => {
        const borrowDate = new Date(request.borrow_date).toLocaleDateString();
        const neededDate = request.date_needed ? new Date(request.date_needed).toLocaleDateString() : 'Not specified';
        const dueDate = new Date(request.due_date).toLocaleDateString();
        const createdDate = new Date(request.created_at).toLocaleDateString();
        
        return `
            <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px; background: white;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 8px 0; color: #1f2937; font-size: 16px;">${escapeHtml(request.item_name)}</h4>
                        <p style="margin: 4px 0; color: #6b7280; font-size: 14px;"><strong>Borrower:</strong> ${escapeHtml(request.borrower_name)}</p>
                        <p style="margin: 4px 0; color: #6b7280; font-size: 14px;"><strong>Email:</strong> ${escapeHtml(request.borrower_email)}</p>
                        <p style="margin: 4px 0; color: #6b7280; font-size: 14px;"><strong>Department:</strong> ${escapeHtml(request.department_name || 'N/A')}</p>
                        <p style="margin: 4px 0; color: #6b7280; font-size: 14px;"><strong>Category:</strong> ${escapeHtml(request.category || 'N/A')}</p>
                    </div>
                    <div style="text-align: right;">
                        <span style="background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">PENDING</span>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; padding: 12px; background: #f9fafb; border-radius: 6px;">
                    <div>
                        <p style="margin: 0; color: #9ca3af; font-size: 12px;">Borrow Date (Request Date)</p>
                        <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600;">${borrowDate}</p>
                    </div>
                    <div>
                        <p style="margin: 0; color: #9ca3af; font-size: 12px;">Needed Date</p>
                        <p style="margin: 4px 0 0 0; ${request.date_needed ? 'color: #1f2937; font-weight: 600;' : 'color: #9ca3af; font-style: italic;'}">${neededDate}</p>
                    </div>
                    <div>
                        <p style="margin: 0; color: #9ca3af; font-size: 12px;">Due Date</p>
                        <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600;">${dueDate}</p>
                    </div>
                    <div>
                        <p style="margin: 0; color: #9ca3af; font-size: 12px;">Quantity</p>
                        <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600;">${request.quantity_borrowed}</p>
                    </div>
                    <div>
                        <p style="margin: 0; color: #9ca3af; font-size: 12px;">Requested On</p>
                        <p style="margin: 4px 0 0 0; color: #1f2937; font-weight: 600;">${createdDate}</p>
                    </div>
                </div>
                ${request.purpose ? `<div style="margin-bottom: 12px; padding: 12px; background: #f0f9ff; border-left: 3px solid #0ea5e9; border-radius: 4px;"><p style="margin: 0; color: #1e40af; font-size: 14px;"><strong>Purpose/Notes:</strong> ${escapeHtml(request.purpose)}</p></div>` : ''}
                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                    <button onclick="approveBorrowRequest('${request.borrow_id}')" style="background: #10b981; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">✓ Approve</button>
                    <button onclick="declineBorrowRequest('${request.borrow_id}')" style="background: #ef4444; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">✗ Decline</button>
                </div>
            </div>
        `;
    }).join('');
    
    listContainer.innerHTML = requestsHTML;
}

async function approveBorrowRequest(borrowId) {
    if (!confirm('Are you sure you want to approve this borrow request?')) {
        return;
    }
    
    try {
        const response = await fetch('crud.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=update_borrow_status&borrow_id=${encodeURIComponent(borrowId)}&status=approved`,
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Borrow request approved successfully. The borrower will be notified via email.');
            loadBorrowRequests(); // Reload the list
        } else {
            alert('Error: ' + (data.message || 'Failed to approve borrow request.'));
        }
    } catch (error) {
        console.error('Error approving borrow request:', error);
        alert('Error approving borrow request. Please try again.');
    }
}

async function declineBorrowRequest(borrowId) {
    if (!confirm('Are you sure you want to decline this borrow request?')) {
        return;
    }
    
    try {
        const response = await fetch('crud.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=update_borrow_status&borrow_id=${encodeURIComponent(borrowId)}&status=declined`,
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Borrow request declined. The borrower will be notified via email.');
            loadBorrowRequests(); // Reload the list
        } else {
            alert('Error: ' + (data.message || 'Failed to decline borrow request.'));
        }
    } catch (error) {
        console.error('Error declining borrow request:', error);
        alert('Error declining borrow request. Please try again.');
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const manageBorrowRequestsModal = document.getElementById('manageBorrowRequestsModal');
    if (manageBorrowRequestsModal && e.target === manageBorrowRequestsModal) {
        closeManageBorrowRequestsModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const manageBorrowRequestsModal = document.getElementById('manageBorrowRequestsModal');
        if (manageBorrowRequestsModal && manageBorrowRequestsModal.style.display !== 'none') {
            closeManageBorrowRequestsModal();
        }
    }
});
</script>
</body>
</html>

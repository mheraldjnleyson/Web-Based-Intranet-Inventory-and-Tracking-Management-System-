<?php
include '../db_connect.php';
session_start();
// Determine if user is a viewer (borrower) - no department and not admin
$isViewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    // Not logged in → redirect to login
    header("Location: login.php");
    exit();
}

// Determine user context
// Super admins should have all admin privileges
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$isAdmin = (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) || $isSuperAdmin;
$isDepartmentHead = $isAdmin && !$isSuperAdmin; // Department head (admin but not super admin)
$userDepartmentName = isset($_SESSION['department']) ? $_SESSION['department'] : '';

// Initialize departments, categories, and locations arrays
$departments = [];
$categories = [];
$categories_by_dept = [];
$locations = [];
$error_message = '';

try {
    // Get all departments
    $sql = "SELECT id, name FROM departments ORDER BY name ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $departments[] = [
                'id' => (int)$row['id'],
                'name' => htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
            ];
        }
    } else {
        // If no departments found, add the 4 fixed departments (no more can be added)
        $sample_departments = [
            "ICT Equipment",
            "Science Equipment",
            "SPS Equipment",
            "Student Learning Resource Center (SLRC)"
        ];
        
        $insert_sql = "INSERT IGNORE INTO departments (name) VALUES (?)";
        $stmt = $conn->prepare($insert_sql);
        
        if ($stmt) {
            foreach ($sample_departments as $dept) {
                $stmt->bind_param("s", $dept);
                $stmt->execute();
            }
            $stmt->close();
        }
        
        // Fetch departments again
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $departments[] = [
                    'id' => (int)$row['id'],
                    'name' => htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
                ];
            }
        }
    }

    // Resolve current user's department ID
    $userDepartmentId = null;
    if (!empty($userDepartmentName) && !empty($departments)) {
        foreach ($departments as $dep) {
            if (isset($dep['name']) && html_entity_decode($dep['name'], ENT_QUOTES, 'UTF-8') === $userDepartmentName) {
                $userDepartmentId = isset($dep['id']) ? (int)$dep['id'] : null;
                break;
            }
        }
    }

    // For department heads, filter to show only their department
    if ($isDepartmentHead && !empty($userDepartmentName)) {
        $departments = array_filter($departments, function($dept) use ($userDepartmentName) {
            return isset($dept['name']) && html_entity_decode($dept['name'], ENT_QUOTES, 'UTF-8') === $userDepartmentName;
        });
        $departments = array_values($departments); // Re-index array
    }

    // Show all departments to all users (remove non-admin restriction)
    
    // Get all categories with their department
    $sql = "SHOW COLUMNS FROM categories LIKE 'department_id'";
    $hasDeptColRes = $conn->query($sql);
    $hasDeptCol = $hasDeptColRes && $hasDeptColRes->num_rows > 0;
    
    if ($hasDeptCol) {
        $sql = "SELECT name, department_id FROM categories ORDER BY name ASC";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $nameSan = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
                $categories[] = $nameSan;
                $deptId = (int)$row['department_id'];
                if (!isset($categories_by_dept[$deptId])) {
                    $categories_by_dept[$deptId] = [];
                }
                if (!in_array($nameSan, $categories_by_dept[$deptId], true)) {
                    $categories_by_dept[$deptId][] = $nameSan;
                }
            }
        }
    } else {
        // Fallback if department_id column doesn't exist
        $sql = "SELECT DISTINCT name FROM categories ORDER BY name ASC";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $categories[] = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
            }
        }
    }
    
    // Get all locations (buildings, floors, rooms)
    $sql = "SELECT 
                b.id as building_id,
                b.name as building_name,
                f.id as floor_id,
                f.floor_number,
                f.floor_name,
                r.id as room_id,
                r.room_number,
                r.room_name,
                CONCAT(b.name, ', Floor ', f.floor_number, ', ', COALESCE(NULLIF(r.room_name, ''), r.room_number)) as full_location
            FROM buildings b
            LEFT JOIN floors f ON b.id = f.building_id
            LEFT JOIN rooms r ON f.id = r.floor_id
            ORDER BY b.name, f.floor_number, r.room_number";
    
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if ($row['room_id']) { // Only include locations that have rooms
                $locations[] = [
                    'building_id' => (int)$row['building_id'],
                    'building_name' => htmlspecialchars($row['building_name'], ENT_QUOTES, 'UTF-8'),
                    'floor_id' => (int)$row['floor_id'],
                    'floor_number' => (int)$row['floor_number'],
                    'floor_name' => htmlspecialchars($row['floor_name'], ENT_QUOTES, 'UTF-8'),
                    'room_id' => (int)$row['room_id'],
                    'room_number' => htmlspecialchars($row['room_number'], ENT_QUOTES, 'UTF-8'),
                    'room_name' => htmlspecialchars($row['room_name'], ENT_QUOTES, 'UTF-8'),
                    'full_location' => htmlspecialchars($row['full_location'], ENT_QUOTES, 'UTF-8')
                ];
            }
        }
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("Database query error: " . $error_message);
    
    // Debug: Show error message for troubleshooting
    echo "<div style='background: #fee2e2; border: 1px solid #fecaca; padding: 15px; margin: 20px; border-radius: 8px;'>";
    echo "<h3 style='color: #991b1b; margin: 0 0 10px 0;'>Database Error:</h3>";
    echo "<p style='color: #991b1b; margin: 0;'>" . htmlspecialchars($error_message) . "</p>";
    echo "<p style='margin: 10px 0 0 0;'><a href='debug_database_content.php' style='color: #dc2626;'>Debug Database Content</a></p>";
    echo "</div>";
}

// Get current user email for borrow modal
$current_user_email = '';
$current_username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;

try {
    if ($is_super_admin) {
        $stmt = $conn->prepare("SELECT email FROM super_admin WHERE id = ?");
    } else {
        $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    }
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $current_user_email = $row['email'] ?? '';
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching user email: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OCABIS <?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="Css/department.css">
    <style>
        /* Keep sidebar spacing consistent with dashboard */
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
    </style>
    <style>
        /* Professional Loading Animation Styles */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 99999;
        }
        
        .loading-content {
            background: white;
            padding: 40px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            max-width: 350px;
            width: 90%;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #e53e3e;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 25px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            font-size: 18px;
            color: #333;
            font-weight: 500;
            margin-bottom: 10px;
        }
        
        .loading-progress {
            font-size: 14px;
            color: #666;
            margin-top: 10px;
        }
        
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #f0f0f0;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 15px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #e53e3e, #ff6b6b);
            border-radius: 3px;
            transition: width 0.3s ease;
            width: 0%;
        }
        
        /* Professional QR Code Styles */
        .qr-item-detail {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            max-width: 300px;
            margin: 0 auto;
        }
        
        .qr-header-detail {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            gap: 10px;
        }
        
        .qr-logo-detail {
            width: 24px;
            height: 24px;
            object-fit: contain;
        }
        
        .qr-title-detail {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
            letter-spacing: 1px;
        }
        
        .qr-code-container {
            margin: 15px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .qr-code-detail {
            max-width: 200px;
            max-height: 200px;
            width: 100%;
            height: auto;
            border-radius: 6px;
        }
        
        .item-code-detail {
            font-size: 14px;
            font-weight: 600;
            color: #4a5568;
            margin-top: 10px;
            padding: 8px 12px;
            background: #edf2f7;
            border-radius: 6px;
            border: 1px solid #cbd5e0;
        }
        
        /* Print QR Styles */
        .print-qr-item {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 140px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .print-qr-header {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            gap: 6px;
        }
        
        .print-qr-logo {
            width: 16px;
            height: 16px;
            object-fit: contain;
        }
        
        .print-qr-title {
            font-size: 12px;
            font-weight: bold;
            color: #2d3748;
            letter-spacing: 0.5px;
        }
        
        .print-qr-code {
            max-width: 80px;
            max-height: 80px;
            width: 100%;
            height: auto;
            border-radius: 4px;
            margin-bottom: 6px;
        }
        
        .print-item-code {
            font-size: 8px;
            font-weight: 600;
            color: #4a5568;
            padding: 4px 6px;
            background: #f7fafc;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
        }
    </style>
    <style>
        /* Hide QR Code for Teachers and Viewers (not admin/super admin) */
        <?php 
        // Hide for both teachers (has department) and viewers (no department)
        $shouldHideQr = !$isAdmin && !$isSuperAdmin;
        if ($shouldHideQr): 
        ?>
        .detail-right-column,
        .qr-section,
        .qr-code-container,
        #detailQrImage,
        .qr-info,
        .btn-download[onclick*="downloadQrFromDetail"],
        .qr-item-detail {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
        }
        <?php endif; ?>
        
        /* Viewer role: show only Department, Scan QR, and Borrow History in sidebar, view-only UI */
        body[data-user-is-viewer="true"] a.nav-link:not([href="department.php"]):not([href="viewer_qr_scanner.php"]):not([href="BorrowHistory.php"]):not([href="logout.php"]) { display: none !important; }
        body[data-user-is-viewer="true"] li.nav-item:has(a.nav-link:not([href="department.php"]):not([href="viewer_qr_scanner.php"]):not([href="BorrowHistory.php"]):not([href="logout.php"]))) { display: none !important; }
        /* Hide all nav items except the three allowed ones for viewers */
        body[data-user-is-viewer="true"] .nav-menu > li.nav-item:not(:has(a[href="department.php"])):not(:has(a[href="viewer_qr_scanner.php"])):not(:has(a[href="BorrowHistory.php"])) { display: none !important; }
        /* Additional fallback: hide any nav-link that doesn't match the allowed pages */
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="dashboard.php"],
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="location.php"],
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="categories.php"],
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="archive.php"],
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="qrscanner.php"],
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="barcode_scanner.php"],
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="item_requests.php"],
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="user_management.php"],
        body[data-user-is-viewer="true"] .nav-menu a.nav-link[href="database_export.php"] { display: none !important; }
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="dashboard.php"]),
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="location.php"]),
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="categories.php"]),
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="archive.php"]),
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="qrscanner.php"]),
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="barcode_scanner.php"]),
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="item_requests.php"]),
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="user_management.php"]),
        body[data-user-is-viewer="true"] .nav-menu li.nav-item:has(a[href="database_export.php"]) { display: none !important; }
        body[data-user-is-viewer="true"] .top-buttons { display: none !important; }
        body[data-user-is-viewer="true"] #addItemForm,
        body[data-user-is-viewer="true"] #addItemTableForm { display: none !important; }
        body[data-user-is-viewer="true"] .action-buttons { display: none !important; }
        /* Hide checkbox column for viewers */
        body[data-user-is-viewer="true"] .checkbox-column { display: none !important; }
        body[data-user-is-viewer="true"] th.checkbox-column,
        body[data-user-is-viewer="true"] td.checkbox-column { display: none !important; }
        /* Hide action dropdown for viewers, but show action column with borrow button */
        body[data-user-is-viewer="true"] .action-dropdown,
        body[data-user-is-viewer="true"] .action-dots-btn { display: none !important; }
        /* Viewer borrow button styling */
        body[data-user-is-viewer="true"] .viewer-borrow-btn {
            display: inline-flex !important;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            white-space: nowrap !important;
            flex-shrink: 0;
        }
        body[data-user-is-viewer="true"] .viewer-borrow-btn:hover {
            background: linear-gradient(135deg, #218838, #1ea080);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        body[data-user-is-viewer="true"] .viewer-borrow-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* Show fixed hamburger button on both mobile AND desktop */
        #sidebarToggleFixed,
        .sidebar-toggle-fixed {
            display: flex !important;
            position: fixed !important;
            top: 15px !important;
            left: 15px !important;
            z-index: 9999 !important; /* Much higher than sidebar to stay on top */
            background: rgba(229, 62, 62, 0.95) !important;
            color: white !important;
            border: 0 !important;
            width: 42px !important;
            height: 42px !important;
            border-radius: 12px !important;
            cursor: pointer !important;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
            pointer-events: auto !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 18px !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
        
        /* Desktop: Hide when sidebar is NOT collapsed (sidebar is open/visible) */
        @media (min-width: 769px) {
            .sidebar {
                transform: translateX(0) !important;
            }
            
            /* Desktop: Hide fixed button when sidebar is NOT collapsed */
            body:not(.sidebar-collapsed) #sidebarToggleFixed,
            body:not(.sidebar-collapsed) .sidebar-toggle-fixed {
                display: none !important;
            }
            
            /* Desktop: Show fixed button when sidebar is collapsed */
            body.sidebar-collapsed #sidebarToggleFixed,
            body.sidebar-collapsed .sidebar-toggle-fixed {
                display: flex !important;
            }
        }

        /* Mobile Inline Sidebar Toggle - Visible in main content area on mobile */
        .sidebar-toggle-mobile-inline {
            display: none !important; /* Hidden on desktop */
        }

        /* Sidebar Mobile Responsive Styles */
        @media (max-width: 768px) {
            /* Show fixed hamburger on mobile - always visible (unless sidebar is open) */
            /* Override any desktop rules */
            body #sidebarToggleFixed,
            body .sidebar-toggle-fixed,
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
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
            }

            /* Hide fixed button when sidebar is open on mobile - handled by JavaScript */

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

            /* Content should be full width */
            .main-content { 
                margin-left: 0 !important; 
            }

            /* Hide mobile inline toggle - we only use fixed toggle on left */
            #sidebarToggleMobile,
            .sidebar-toggle-mobile-inline {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }
            
            /* Ensure tree menu appears above sidebar when scrolling horizontally */
            .sidebar-tree {
                position: relative;
                z-index: 1001 !important;
            }

            /* Fix sidebar text alignment on mobile */
            .sidebar .nav-link {
                display: flex !important;
                align-items: center !important;
                justify-content: flex-start !important;
                padding: 12px 20px !important;
                gap: 12px !important;
            }

            .sidebar .nav-icon {
                width: 22px !important;
                height: 22px !important;
                margin-right: 0 !important;
                flex-shrink: 0 !important;
            }

            .sidebar .nav-icon img {
                width: 22px !important;
                height: 22px !important;
                margin-right: 0 !important;
            }

            .sidebar .nav-label,
            .sidebar .nav-link span:not(.nav-icon) {
                display: inline-block !important;
                white-space: nowrap !important;
                text-align: left !important;
            }

            .sidebar .logo {
                padding: 0 20px !important;
            }

            .sidebar .logo-top {
                display: flex !important;
                align-items: center !important;
                gap: 10px !important;
            }

            .sidebar .logo-text p {
                white-space: normal !important;
                word-wrap: break-word !important;
            }

            /* Content should be full width */
            .main-content { 
                margin-left: 0 !important; 
                padding: 10px !important;
            }

            /* Top section mobile adjustments */
            .top-section {
                flex-direction: column;
                gap: 10px;
            }

            .top-buttons {
                flex-direction: column;
                width: 100%;
            }

            .top-buttons .btn {
                width: 100%;
                margin: 5px 0;
            }

            /* Content area mobile */
            .content-area {
                flex-direction: column;
            }

            /* Sidebar tree mobile - make it collapsible or horizontal scroll */
            .sidebar-tree {
                width: 100%;
                margin-bottom: 15px;
                max-height: 200px;
                overflow-y: auto;
            }

            /* Filters section mobile */
            .filters-section {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }

            .filter-input,
            .filter-select {
                width: 100% !important;
                min-width: 100% !important;
            }

            /* View toggle mobile */
            .view-toggle {
                width: 100%;
                justify-content: center;
            }

            /* Summary info mobile */
            .summary-info {
                width: 100%;
                text-align: center;
                font-size: 12px;
            }

            /* Table container mobile */
            .table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                width: 100%;
            }

            .data-table {
                min-width: 700px;
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 8px 6px;
            }

            /* Items cards mobile */
            .items-cards-container {
                grid-template-columns: 1fr !important;
                gap: 15px;
                padding: 10px !important;
            }

            .items-cards-container.grid-layout {
                grid-template-columns: 1fr !important;
            }

            .item-card {
                width: 100% !important;
                min-width: 100% !important;
                max-width: 100% !important;
                padding: 15px !important;
                flex-direction: column !important;
                align-items: stretch !important;
            }

            .item-image-container {
                width: 100% !important;
                height: 200px !important;
                margin-bottom: 12px !important;
            }

            .item-card-content {
                width: 100% !important;
            }

            .item-card-details {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }

            /* Modal mobile */
            .modal {
                width: 95% !important;
                max-width: 95% !important;
                margin: 10px auto !important;
                padding: 15px !important;
            }

            /* Breadcrumb mobile */
            .breadcrumb {
                font-size: 12px;
                flex-wrap: wrap;
            }

            /* Dropdown container mobile */
            .dropdown-container {
                width: 100%;
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
    </style>
    <script src="js/session_monitor.js"></script>
</head>
<body class="department-page" data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>" data-user-is-viewer="<?= $isViewer ? 'true' : 'false' ?>" data-user-is-department-head="<?= $isDepartmentHead ? 'true' : 'false' ?>" data-user-username="<?= htmlspecialchars($current_username, ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($current_user_email, ENT_QUOTES, 'UTF-8') ?>">
    
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
    <?php if (!$isViewer): ?>
    <li class="nav-item">
        <a href="dashboard.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/admin.png" alt="Dashboard">
            </span>
            Dashboard
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a href="department.php" class="nav-link active">
            <span class="nav-icon">
                <img src="image/department.png" alt="<?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
            </span>
            <?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?>
        </a>
    </li>
    <?php if ($isViewer): ?>
    <li class="nav-item">
        <a href="viewer_qr_scanner.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/qr.png" alt="Scan QR">
            </span>
            Scan Item QR
        </a>
    </li>
    <li class="nav-item">
        <a href="BorrowHistory.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/book.png" alt="Borrow History">
            </span>
            Borrow History
        </a>
    </li>
    <?php endif; ?>
    <?php if (!$isViewer): ?>
    <li class="nav-item">
        <a href="location.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/icons8-building-64.png" alt="Location">
            </span>
            Location
        </a>
    </li>
    <li class="nav-item">
        <a href="categories.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/icons8-categorize-50.png" alt="Categories">
            </span>
            Categories
        </a>
    </li>
    <li class="nav-item">
        <a href="BorrowHistory.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/book.png" alt="Borrow History">
            </span>
            Borrow History
        </a>
    </li>
    <li class="nav-item">
        <a href="archive.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/icons8-archive-50.png" alt="Archive">
            </span>
            Archive
        </a>
    </li>
    <li class="nav-item">
        <a href="qrscanner.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/qr.png" alt="QR Scanner">
            </span>
            QR Code Scanner
        </a>
    </li>
    <li class="nav-item">
        <a href="barcode_scanner.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/barcode-scan.png" alt="Barcode Scanner">
            </span>
            Barcode Scanner
        </a>
    </li>
    <?php endif; ?>
    <?php 
    // Admin role: is_admin = 1 AND role = 'admin'
    $is_admin_role = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
    $is_admin_or_super = $is_super_admin || $is_admin_role;
    ?>
    <?php if (!$isViewer && $is_admin_or_super): ?>
        <li class="nav-item">
            <a href="item_requests.php" class="nav-link" title="Item Requests">
                <span class="nav-icon"><img src="image/application.png" alt="Requests"></span>
                <span class="nav-label">Item Requests</span>
            </a>
        </li>
    <?php endif; ?>
    <?php if (!$isViewer && $is_admin_or_super): ?>
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
    if (!$isViewer && $is_native_super_admin): 
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
    <a href="logout.php" class="nav-link">
        <span class="nav-icon">
            <img src="image/icons8-sign-out-48.png" alt="Sign Out">
        </span>
        Sign out
    </a>
</div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <?php include 'profile_dropdown.php'; ?>
            <!-- Mobile Inline Sidebar Toggle (visible on mobile only) -->
            <button id="sidebarToggleMobile" class="sidebar-toggle-mobile-inline" aria-label="Toggle sidebar">☰</button>
            <div class="top-section">
                <div class="breadcrumb" id="breadcrumb">
                    <span class="breadcrumb-item clickable" onclick="goToItemsPage()" style="cursor: pointer; color: #333; text-decoration: underline;"><img src="image/building-1062.png" alt="Building" style="width: 16px; height: 16px; margin-right: 5px; vertical-align: middle;"> <?= $isDepartmentHead ? 'Items' : 'Departments' ?></span>
                    <span class="breadcrumb-separator" id="breadcrumbSeparator" style="display: none;"> > </span>
                    <span class="breadcrumb-department" id="breadcrumbDepartment" style="display: none;"></span>
                    <span class="breadcrumb-separator" id="breadcrumbTableSeparator" style="display: none;"> > </span>
                    <span class="breadcrumb-table" id="breadcrumbTable" style="display: none;"></span>
                </div>
                <div class="top-buttons">
                    <?php if (!$isViewer && !$isDepartmentHead): ?>
                    <button class="btn btn-borrow" id="borrowItemBtn" onclick="openBorrowModal()">
                        <img src="image/book.png" alt="Borrow" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;filter:brightness(0) invert(1);" />
                        BORROW ITEM
                    </button>
                    <?php endif; ?>
                    <?php 
                    // Show button for admins and super admins
                    // Check admin status from session and role
                    $sessionIsAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
                    $sessionIsSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
                    // Show button if user is head department (by is_admin flag or super_admin flag)
                    // Note: Super admins automatically have admin privileges, so $isAdmin already includes $isSuperAdmin
                    $shouldShowButton = $sessionIsAdmin || $sessionIsSuperAdmin || $isAdmin || $isSuperAdmin;
                    
                    // TEMPORARY: Show button for ALL users who have a department (testing purposes)
                    // This allows department heads to manage borrow requests even if not marked as admin
                    $hasDepartment = !empty($_SESSION['department']);
                    $shouldShowButton = $shouldShowButton || $hasDepartment;
                    ?>
                    <?php if ($shouldShowButton): ?>
                    <button class="btn btn-primary" id="manageBorrowRequestsBtn" onclick="openManageBorrowRequestsModal()" style="display: none; background-color: #10b981; color: white; align-items: center; gap: 6px; margin-left: 10px; flex-shrink: 0; white-space: nowrap; position: relative; z-index: 10;">
                        <img src="image/icons8-request-service-50.png" alt="Manage Requests" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;filter:brightness(0) invert(1);" />
                        MANAGE BORROW REQUESTS
                    </button>
                    <?php endif; ?>
                    <?php 
                    // Show REQUEST ITEM button for regular users and department heads (but not super admin or admin role)
                    $is_admin_role = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
                    $showRequestButton = !$isViewer && !$isSuperAdmin && !$is_admin_role;
                    ?>
                    <?php if ($showRequestButton): ?>
                    <button class="btn btn-primary" onclick="openRequestModal()">
                        <img src="image/application.png" alt="Request" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;filter:brightness(0) invert(1);" />
                        REQUEST ITEM
                    </button>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1): ?>
                    <button class="btn btn-primary" onclick="openAddDepartmentModal()" title="Add Department">
                        <img src="image/icons8-add-48.png" alt="Add" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;filter:brightness(0) invert(1);" />
                        ADD DEPARTMENT
                    </button>
                    <?php endif; ?>
                    <div id="addItemTableContainer">
                        <button class="btn btn-add" id="addItemTableBtn" onclick="openAddItemTableModal()" style="display: flex; align-items: center; justify-content: center; gap: 6px; background: #e53e3e; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s;">
                            <img src="image/table.png" alt="Add Table" style="width:18px;height:18px;filter:brightness(0) invert(1);" />
                            ADD ITEM TABLE
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="content-area">
                <div class="sidebar-tree">
                    <?php if (!$isDepartmentHead): ?>
                    <div class="search-container" style="position: relative;">
                        <input type="text" id="treeSearch" class="search-input" placeholder="Search departments" style="padding-right: 35px;" />
                        <img src="image/search.png" alt="Search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; opacity: 0.7; pointer-events: none;" />
                    </div>
                    <?php endif; ?>
                    <div class="tree-menu" id="treeMenu">
                        <!-- All Departments Option - Only show for super admins -->
                        <?php if ($isSuperAdmin): ?>
                        <div class="tree-item" data-dept-id="all">
                            <div class="tree-node active" onclick="selectDepartment('all', 'All Departments')">
                                <img src="image/building-1062.png" alt="Building" class="tree-icon">
                                <span class="tree-text">All Departments</span>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (empty($departments)): ?>
                            <div class="no-departments">
                                <?= $error_message ? 'Unable to load departments' : 'No departments found' ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($departments as $dept): ?>
                                <?php 
                                // For department heads, auto-select their department
                                $isUserDept = $isDepartmentHead && isset($dept['id']) && $dept['id'] == $userDepartmentId;
                                ?>
                                <div class="tree-item" data-dept-id="<?= $dept['id'] ?>">
                                    <div class="tree-node <?= $isUserDept ? 'active' : '' ?>" onclick="selectDepartment(<?= $dept['id'] ?>, '<?= $dept['name'] ?>')">
                                        <img src="image/building-1062.png" alt="Building" class="tree-icon">
                                        <span class="tree-text"><?= $dept['name'] ?></span>
                                        <?php if (isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1): ?>
                                            <button title="Delete Department" aria-label="Delete Department"
                                                    onclick="event.stopPropagation(); deleteDepartment(<?= $dept['id'] ?>, '<?= $dept['name'] ?>')"
                                                    style="margin-left:8px;background:transparent;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;color:#dc2626;">
                                                <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;opacity:0.9;" />
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="data-section">
                    <div class="filters-section">
                        <div style="position: relative; display: inline-block;">
                            <input type="text" id="nameFilter" class="filter-input" placeholder="Search items" style="width: 200px; padding-right: 35px;" />
                            <button type="button" onclick="performSearch()" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 4px;" title="Search">
                                
                                <img src="image/search.png" alt="Search" style="width: 16px; height: 15px; opacity: 0.7;" />
                            </button>
                        </div>

                        <!-- Table Cards View Toggle Button -->
                        <div class="view-toggle" id="tableCardsViewToggle" style="display: inline-flex;">
                            <button class="view-btn active" id="gridViewBtn" onclick="switchToGridView()" title="Grid View">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                    <rect x="3" y="3" width="7" height="7"/>
                                    <rect x="14" y="3" width="7" height="7"/>
                                    <rect x="3" y="14" width="7" height="7"/>
                                    <rect x="14" y="14" width="7" height="7"/>
                                </svg>
                            </button>
                            <button class="view-btn" id="listViewBtn" onclick="switchToListView()" title="List View">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                    <rect x="3" y="6" width="18" height="2"/>
                                    <rect x="3" y="11" width="18" height="2"/>
                                    <rect x="3" y="16" width="18" height="2"/>
                                </svg>
                            </button>
                        </div>

                        
                        <div class="summary-info" id="summaryInfo">
                            Items: <strong id="itemCount">0</strong> &nbsp;&nbsp;
                            Total Quantity: <strong id="totalQuantity">0 units</strong>
                        </div>
                    </div>
                    
                    <!-- Message when no search is performed -->
                    <div id="noFilterMessage" style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 40px; text-align: center; margin-top: 30px; display: block;">
                        <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                        <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 20px; font-weight: 600;">Search for Items to View Them</h3>
                        <p style="color: #718096; font-size: 14px; max-width: 500px; margin: 0 auto;">
                            Please use the search box above to search for items. Items will only be displayed after you perform a search.
                        </p>
                    </div>
                    
                    <!-- Item Cards Section - Add this before your table container -->
                    <div class="items-cards-container" id="itemsCardsContainer" style="display: none;">
                        <!-- Cards will be populated dynamically -->
                    </div>
                    <!-- Context Menu -->
                    <div id="contextMenu" class="context-menu">
                        <div class="context-menu-item" onclick="downloadSelectedItemsQR()">
                            <img src="image/barcode-scan.png" alt="Download QR" class="context-menu-icon" />
                            Download QR
                        </div>
                        <div class="context-menu-item" onclick="moveSelectedItemsLocation()">
                            <img src="image/building-1062.png" alt="Move Location" class="context-menu-icon" />
                            Move Location
                        </div>
                        <div class="context-menu-item danger" onclick="archiveSelectedItems()">
                            <img src="image/icons8-archive-50.png" alt="Archive" class="context-menu-icon" />
                            Archive
                        </div>
                    </div>

                    <div class="table-container" style="display: none;">
                        <table class="data-table">
                        <thead>
    <tr>
        <?php if (!$isViewer): ?>
        <th class="checkbox-column">
            <input type="checkbox" id="selectAllItems" onchange="toggleSelectAllItems()" />
        </th>
        <?php endif; ?>
        <th onclick="setSort('id')" class="sortable">
            ID <span class="sort-indicator" data-col="id"></span>
        </th>
        <th onclick="setSort('name')" class="sortable">
            Name <span class="sort-indicator" data-col="name"></span>
        </th>
        <th onclick="setSort('department_name')" class="sortable">
            Department <span class="sort-indicator" data-col="department_name"></span>
        </th>
        <th onclick="setSort('category')" class="sortable">
            Categories <span class="sort-indicator" data-col="category"></span>
        </th>
        <th onclick="setSort('updated_at')" class="sortable">
            Date Last Updated <span class="sort-indicator" data-col="updated_at"></span>
        </th>
        <th onclick="setSort('status')" class="sortable">
            Status <span class="sort-indicator" data-col="status"></span>
        </th>
        <th onclick="setSort('location')" class="sortable">
            Location <span class="sort-indicator" data-col="location"></span>
        </th>
        <th>Action</th>
    </tr>
</thead>
                            <tbody id="itemsTableBody">
                                <tr>
                                    <td colspan="9" class="no-items" style="text-align: center; padding: 20px;">
                                        <?= $isDepartmentHead ? 'Loading items...' : 'Select a department to view items or click "All Departments"' ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination Controls -->
                    <div class="pagination-container" id="paginationContainer" style="display: none;">
                        <div class="pagination-info">
                            <span id="paginationInfo">Showing 0 to 0 of 0 entries</span>
                        </div>
                        <div class="pagination-controls">
                            <button id="firstPageBtn" onclick="goToPage(1)" disabled>First</button>
                            <button id="prevPageBtn" onclick="goToPreviousPage()" disabled>Previous</button>
                            <div class="page-numbers" id="pageNumbers">
                                <!-- Page numbers will be generated dynamically -->
                            </div>
                            <button id="nextPageBtn" onclick="goToNextPage()" disabled>Next</button>
                            <button id="lastPageBtn" onclick="goToLastPage()" disabled>Last</button>
                        </div>
                        <div class="pagination-settings">
                            <label for="itemsPerPage">Items per page:</label>
                            <select id="itemsPerPage" onchange="changeItemsPerPage()">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Enhanced Item Detail Modal -->
<div class="item-detail-modal" id="itemDetailModal">
    <div class="item-detail-content">
        <div class="item-detail-header">
            <div class="item-header-info">
                <div class="item-icon-large" id="itemDetailIcon">📦</div>
                <div class="item-title-section">
                    <h2 id="itemDetailTitle">Item Details</h2>
                    <p class="item-subtitle" id="itemDetailSubtitle">Complete item information</p>
                </div>
            </div>
            <button id="closeItemDetailBtn" class="close-detail-btn" onclick="closeItemDetailModal()" style="pointer-events: auto; cursor: pointer; z-index: 10001; position: relative;">×</button>
        </div>
        
        <div class="item-detail-body">
            <div class="detail-grid">
                <!-- Left Column: Item Details + Description -->
                <div class="detail-left-column">
                    <!-- Item Header -->
                    <div class="detail-section full-width" style="box-shadow:none;border:none;padding:0;background:transparent;">
                        <h2 class="item-title-display" id="itemTitleDisplay">-</h2>
                    </div>

                    <!-- Item Information Card -->
                    <div class="detail-section full-width">
                        <h3 class="section-title">📋 Item Information</h3>
                        <div class="item-fields">
                            <div class="detail-line">
                                <span class="line-label">Item Table Name:</span> 
                                <span class="line-value" id="detailName">-</span>
                            </div>
                            <div class="detail-line">
                                <span class="line-label">ID:</span> 
                                <span class="line-value" id="detailId">-</span>
                            </div>
                            <div class="detail-line">
                                <span class="line-label">Categories:</span> 
                                <span class="line-value" id="detailCategory">-</span>
                            </div>
                            <div class="detail-line">
                                <span class="line-label">Department:</span> 
                                <span class="line-value" id="detailDepartment">-</span>
                            </div>
                            <div class="detail-line">
                                <span class="line-label">Location:</span> 
                                <span class="line-value" id="detailLocation">-</span>
                            </div>
                            <div class="detail-line">
                                <span class="line-label">Status:</span> 
                                <span class="line-value" id="detailStatus">-</span>
                            </div>
                            <div class="detail-line">
                                <span class="line-label">Last Updated:</span> 
                                <span class="line-value" id="detailUpdated">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Description Section -->
                    <div class="detail-section full-width">
                        <h3 class="section-title">📝 Description</h3>
                        <div class="description-card">
                            <div class="detail-value" id="detailDescription">No description available for this item.</div>
                        </div>
                    </div>

                    <!-- Borrow History Button -->
                    <div class="detail-section full-width" style="display:flex;justify-content:flex-end;padding:12px 16px;">
                        <button id="openBorrowHistoryBtn" class="btn-download" style="background: linear-gradient(135deg, #e53e3e, #c53030);color:#fff;border:none;border-radius:8px;padding:8px 12px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;box-shadow:0 4px 12px rgba(229,62,62,0.25);font-size:12px;">
                            <span>📘</span> View Borrow History
                        </button>
                    </div>
                </div>

                <!-- Right Column: QR Code Section -->
                <?php 
                // Hide QR code section for teachers and viewers (not admin/super admin)
                // Teacher = has department but not admin/super admin
                // Viewer = no department and not admin/super admin
                $shouldHideQr = !$isAdmin && !$isSuperAdmin;
                if (!$shouldHideQr): 
                ?>
                <div class="detail-right-column">
                    <!-- QR Code Section -->
                    <div class="detail-section full-width">
                        <h3 class="section-title">🔗 QR Code</h3>
                        <div class="qr-section">
                            <div class="qr-header">
                                <div class="qr-logo">
                                    <img src="assets/logo.png" alt="OCABIS" style="width: 24px; height: 24px; margin-right: 8px;">
                                    <span style="font-weight: 600; color: #2d3748;">OCABIS</span>
                                </div>
                            </div>
                            <img id="detailQrImage" class="qr-image" src="" alt="QR code" />
                            <div class="qr-info">
                                <div class="qr-item-code" id="qrItemCode" style="font-family: monospace; font-weight: bold; color: #4a5568;">-</div>
                                <p>Scan this QR code to view item details</p>
                            </div>
                            <button class="btn-download" onclick="downloadQrFromDetail()">
                                <span style="margin-right: 6px;">⬇️</span>
                                DOWNLOAD QR CODE
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

                <div class="detail-section full-width" style="display:none;">
                    <h3 class="section-title">📈 Item Statistics</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">📅</div>
                            <div class="stat-info">
                                <div class="stat-label">Created</div>
                                <div class="stat-value" id="detailCreated">-</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">🔄</div>
                            <div class="stat-info">
                                <div class="stat-label">Last Modified</div>
                                <div class="stat-value" id="detailModified">-</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">🏷️</div>
                            <div class="stat-info">
                                <div class="stat-label">Item Type</div>
                                <div class="stat-value" id="detailType">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

    <!-- Delete Department Confirm Modal -->
    <div class="modal-overlay" id="deleteDepartmentConfirmModal" style="display:none;">
        <div class="modal" style="max-width: 520px; border-radius: 16px; overflow:hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #e53e3e, #c53030);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:16px 20px;">
                <div style="font-weight:700;">Confirm Delete</div>
                <button class="close-btn" onclick="closeDeleteDepartmentConfirmModal()" style="background:transparent;border:none;color:#fff;font-size:22px;cursor:pointer;">×</button>
            </div>
            <div class="modal-body" style="padding:18px;background:#fff;">
                <p id="deleteDeptConfirmMessage" style="margin:0;">Are you sure you want to delete this department? This action cannot be undone.</p>
                <p style="margin-top:10px;color:#6b7280;font-size:14px;">Note: You can only delete a department with no items, no item tables, and no categories.</p>
                <div id="deleteDeptError" style="display:none;margin-top:10px;color:#dc2626;font-weight:600;"></div>
            </div>
            <div class="modal-footer" style="display:flex;gap:10px;justify-content:flex-end;padding:12px 18px;background:#f9fafb;">
                <button class="btn-cancel" onclick="closeDeleteDepartmentConfirmModal()">Cancel</button>
                <button id="deleteDepartmentConfirmBtn" class="btn-submit" style="background: linear-gradient(135deg, #e53e3e, #c53030);" onclick="proceedDeleteDepartment()">Delete</button>
            </div>
        </div>
    </div>

    <!-- Borrow History Modal -->
    <div class="modal-overlay" id="borrowHistoryModal" style="display:none;">
        <div class="modal" style="max-width: 900px; border-radius: 16px; overflow:hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #e53e3e, #c53030);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:16px 20px;">
                <div style="font-weight:700;">Borrow History</div>
                <button class="close-btn" onclick="closeBorrowHistoryModal()" style="background:transparent;border:none;color:#fff;font-size:22px;cursor:pointer;">×</button>
            </div>
            <div class="modal-body" id="borrowHistoryBody" style="padding:18px;max-height:70vh;overflow:auto;background:#fff;">
                Loading history...
            </div>
        </div>
    </div>

    <!-- Add Item Modal -->
    <div class="modal-overlay" id="addItemModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add New Item</h3>
                <button class="close-btn" onclick="closeAddItemModal()">×</button>
            </div>
            <div class="modal-body">
                <?php if (!$isViewer): ?>
                <form id="addItemForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Item Table: <span class="required">*</span></label>
                        <select id="itemTable" required onchange="onItemTableChange()">
                            <option value="">Select Item Table</option>
                            <!-- Item tables will be populated by JavaScript -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Item Name: <span class="required">*</span></label>
                        <input type="text" id="itemName" required placeholder="Enter item name">
                        <div class="error-text"></div>
                    </div>
                    <!-- Department field hidden - automatically set based on selected table -->
                    <input type="hidden" id="itemDepartment" readonly>
                    <input type="hidden" id="itemDepartmentId" name="department_id">
                    <!-- Category field hidden - automatically set based on selected table -->
                    <input type="hidden" id="itemCategory" readonly>
                    <div class="form-group">
                        <label>Quantity: <span class="required">*</span></label>
                        <input type="number" id="itemQuantity" min="1" required placeholder="1" onchange="toggleItemImageUpload()">
                    </div>
                    <div class="form-group" id="itemImageGroup" style="display: none;">
                        <label>Item Image (Optional):</label>
                        <input type="file" id="itemImage" accept="image/*" onchange="previewItemImage(this)">
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Upload an image for this item (only available when quantity is 1)</small>
                        <div id="itemImagePreview" class="image-preview" style="display: none; margin-top: 10px;">
                            <img id="previewItemImg" src="" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 5px;">
                            <button type="button" onclick="removeItemImagePreview()" style="margin-top: 5px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer; display: block;">Remove Image</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Location: <span class="required">*</span></label>
                        <select id="itemLocation" required>
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= $location['full_location'] ?>" 
                                        data-building="<?= $location['building_name'] ?>"
                                        data-floor="<?= $location['floor_number'] ?>"
                                        data-room="<?= $location['room_name'] ?>">
                                    <?= $location['full_location'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status: <span class="required">*</span></label>
                        <select id="itemStatus" required>
                            <option value="Working" selected>Working</option>
                            <option value="Under Maintenance">Under Maintenance</option>
                            <option value="Broken">Broken</option>
                            <option value="Lost">Lost</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Description (Optional):</label>
                        <textarea id="itemDescription" rows="3" placeholder="Additional details about the item..."></textarea>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAddItemModal()">Cancel</button>
                <button class="btn-submit" onclick="addNewItem()">Add Item</button>
            </div>
        </div>
    </div>

    <!-- Add Item Table Modal -->
    <div class="modal-overlay" id="addItemTableModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Add New Item Table</h3>
                <button class="close-btn" onclick="closeAddItemTableModal()">×</button>
            </div>
            <div class="modal-body">
                <?php if (!$isViewer): ?>
                <form id="addItemTableForm">
                    <div class="form-group">
                        <label>Item Table Name: <span class="required">*</span></label>
                        <input type="text" id="itemTableName" required placeholder="e.g., Mouse Table, Keyboard Table">
                        <div class="error-text"></div>
                    </div>
                    <?php 
                    $isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
                    if ($isSuperAdmin): 
                    ?>
                    <div class="form-group">
                        <label>Department: <span class="required">*</span></label>
                        <select id="itemTableDepartment" name="department_id" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dep): ?>
                                <option value="<?= $dep['id'] ?>"><?= $dep['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: 
                        // For department heads and regular users, hide the department field and auto-set it
                        $userDeptId = $userDepartmentId;
                    ?>
                    <!-- Department field hidden for department heads - automatically set to their department -->
                    <input type="hidden" id="itemTableDepartment" name="department_id" value="<?= $userDeptId ?>" required>
                    <?php endif; ?>
                    <div class="form-group">
                        <label>Category: <span class="required">*</span></label>
                        <select id="itemTableCategory" required <?= !$isSuperAdmin ? '' : 'disabled' ?>>
                            <option value="">Select Category</option>
                        </select>
                        <div id="itemTableCategoryHint" class="hint-text" style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            <?php if ($isSuperAdmin): ?>
                                Please choose a department first to see its categories.
                            <?php else: ?>
                                Select a category for this department.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Table Image (Optional):</label>
                        <input type="file" id="itemTableImage" accept="image/*" onchange="previewTableImage(this)">
                        <div id="tableImagePreview" class="image-preview" style="display: none;">
                            <img id="previewTableImg" src="" alt="Preview" style="max-width: 200px; max-height: 200px; margin-top: 10px;">
                            <button type="button" onclick="removeTableImagePreview()" style="margin-top: 5px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;">Remove Image</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" id="itemTableConsumable" onchange="togglePriorityVisibility()" style="width: 18px; height: 18px; cursor: pointer;">
                            <span>This is a Consumable Item Table</span>
                        </label>
                        <div class="hint-text" style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            <strong>Consumable:</strong> Items in this table will have "Consumable" status and no QR codes (items get used up)
                        </div>
                    </div>
                    <div class="form-group" id="priorityGroup">
                        <label>Priority: <span class="required">*</span></label>
                        <select id="itemTablePriority" required>
                            <option value="low">Low - Auto-generate QR code</option>
                            <option value="medium">Medium - Request QR code approval</option>
                            <option value="high">High - Request QR code approval</option>
                        </select>
                        <div class="hint-text" style="font-size: 12px; color: #6b7280; margin-top: 4px;">
                            <strong>Low:</strong> QR code will be generated automatically<br>
                            <strong>Medium/High:</strong> QR code requires admin approval
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description (Optional):</label>
                        <textarea id="itemTableDescription" rows="3" placeholder="Description about this item table..."></textarea>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeAddItemTableModal()">Cancel</button>
                <button class="btn-submit" onclick="addNewItemTable()">Add Item Table</button>
            </div>
        </div>
    </div>

    <!-- Edit Item Table Modal -->
    <div class="modal-overlay" id="editItemTableModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Edit Item Table</h3>
                <button class="close-btn" onclick="closeEditItemTableModal()">×</button>
            </div>
            <div class="modal-body">
                <form id="editItemTableForm">
                    <input type="hidden" id="editItemTableId">
                    <div class="form-group">
                        <label>Item Table Name: <span class="required">*</span></label>
                        <input type="text" id="editItemTableName" required placeholder="e.g., Mouse Table, Keyboard Table">
                        <div class="error-text"></div>
                    </div>
                    <div class="form-group">
                        <label>Department: <span class="required">*</span></label>
                        <select id="editItemTableDepartment" name="department_id" required>
                            <option value="">Select Department</option>
                            <?php 
                            // Filter departments: 
                            // - Super admins see all departments
                            // - Department heads (admin but not super admin) only see their own department
                            // - Regular users only see their own department
                            $isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
                            if ($isSuperAdmin) {
                                $deptsToShow = $departments;
                            } else {
                                $deptsToShow = array_filter($departments, function($d) use ($userDepartmentName) {
                                    return $d['name'] === $userDepartmentName;
                                });
                            }
                            foreach ($deptsToShow as $dep): 
                            ?>
                                <option value="<?= $dep['id'] ?>"><?= $dep['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Category field hidden - automatically set based on department -->
                    <input type="hidden" id="editItemTableCategory">
                    <div class="form-group">
                        <label>Table Image (Optional):</label>
                        <input type="file" id="editItemTableImage" accept="image/*" onchange="previewEditTableImage(this)">
                        <div id="editTableImagePreview" class="image-preview" style="display: none;">
                            <img id="previewEditTableImg" src="" alt="Preview" style="max-width: 200px; max-height: 200px; margin-top: 10px;">
                            <button type="button" onclick="removeEditTableImagePreview()" style="margin-top: 5px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer;">Remove Image</button>
                        </div>
                        <div id="currentTableImage" style="margin-top: 10px;"></div>
                    </div>
                    <div class="form-group">
                        <label>Description (Optional):</label>
                        <textarea id="editItemTableDescription" rows="3" placeholder="Description about this item table..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeEditItemTableModal()">Cancel</button>
                <button class="btn-submit" onclick="updateItemTable()">Update Item Table</button>
            </div>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div class="modal-overlay" id="editItemModal">
        <div class="modal">
            <div class="modal-header">
                <h3>Edit Item</h3>
                <button class="close-btn" onclick="closeEditItemModal()">×</button>
            </div>
            <div class="modal-body">
                <form id="editItemForm" enctype="multipart/form-data">
                    <input type="hidden" id="editItemId">
                    <div class="form-group">
                        <label>Item Name: <span class="required">*</span></label>
                        <input type="text" id="editItemName" required>
                    </div>
                    <div class="form-group">
                        <label>Department: <span class="required">*</span></label>
                        <select id="editItemDepartment" name="department_id" required>
                            <option value="">Select Department</option>
                            <?php 
                            // Filter departments: 
                            // - Super admins see all departments
                            // - Department heads (admin but not super admin) only see their own department
                            // - Regular users only see their own department
                            $isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
                            if ($isSuperAdmin) {
                                $deptsToShow = $departments;
                            } else {
                                $deptsToShow = array_filter($departments, function($d) use ($userDepartmentName) {
                                    return $d['name'] === $userDepartmentName;
                                });
                            }
                            foreach ($deptsToShow as $dep): 
                            ?>
                                <option value="<?= $dep['id'] ?>"><?= $dep['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Category: <span class="required">*</span></label>
                        <select id="editItemCategory" required>
                            <option value="">Select Category</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity: <span class="required">*</span></label>
                        <input type="number" id="editItemQuantity" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>Location: <span class="required">*</span></label>
                        <select id="editItemLocation" required>
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= $location['full_location'] ?>" 
                                        data-building="<?= $location['building_name'] ?>"
                                        data-floor="<?= $location['floor_number'] ?>"
                                        data-room="<?= $location['room_name'] ?>">
                                    <?= $location['full_location'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status: <span class="required">*</span></label>
                        <select id="editItemStatus" required>
                            <option value="Working">Working</option>
                            <option value="Under Maintenance">Under Maintenance</option>
                            <option value="Broken">Broken</option>
                            <option value="Lost">Lost</option>
                            <option value="Consumable">Consumable</option>
                        </select>
                        <div id="consumableStatusNote" style="display: none; margin-top: 5px; padding: 8px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; font-size: 12px; color: #92400e;">
                            ⚠️ This item belongs to a consumable table. Status cannot be changed and will remain "Consumable".
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Item Image (Optional):</label>
                        <input type="file" id="editItemImage" accept="image/*" onchange="previewEditItemImage(this)">
                        <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Upload a new picture to replace the current item image</small>
                        <div id="editItemImagePreview" class="image-preview" style="display: none; margin-top: 10px;">
                            <img id="previewEditItemImg" src="" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; border: 1px solid #ddd;">
                            <button type="button" onclick="removeEditItemImagePreview()" style="margin-top: 5px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 3px; cursor: pointer; display: block;">Remove New Image</button>
                        </div>
                        <div id="currentItemImage" style="margin-top: 10px;"></div>
                    </div>
                    <div class="form-group">
                        <label>Description:</label>
                        <textarea id="editItemDescription" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeEditItemModal()">Cancel</button>
                <button class="btn-submit" onclick="updateItem()">Update Item</button>
            </div>
        </div>
    </div>
    <div class="modal-overlay" id="borrowItemModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Borrow Item</h3>
            <button class="close-btn" onclick="closeBorrowModal()">×</button>
        </div>
        <div class="modal-body">
            <form id="borrowItemForm">
                <div class="form-group">
                    <label>Borrow ID: <span class="required">*</span></label>
                    <input type="text" id="borrowId" required placeholder="Auto-generated" readonly style="background-color: #f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Borrower Name: <span class="required">*</span></label>
                    <input type="text" id="borrowerName" required placeholder="Enter borrower's full name">
                </div>
                <div class="form-group">
                    <label>Item Table: <span class="required">*</span></label>
                    <select id="borrowItemTable" required onchange="loadItemsFromTable()">
                        <option value="">Select Item Table</option>
                        <!-- Item tables will be populated dynamically -->
                    </select>
                </div>
                <div class="form-group">
                    <label>Item Name: <span class="required">*</span></label>
                    <select id="borrowItemName" required onchange="updateItemDetails()">
                        <option value="">Select Item Table First</option>
                        <!-- Items will be populated dynamically -->
                    </select>
                </div>
                <div class="form-group">
                    <label>Department:</label>
                    <input type="text" id="borrowDepartment" readonly style="background-color: #f8f9fa;" placeholder="Auto-filled based on item">
                </div>
                <div class="form-group">
                    <label>Borrow Date: <span class="required">*</span></label>
                    <input type="date" id="borrowDate" required>
                </div>
                <div class="form-group">
                    <label>Due Date: <span class="required">*</span></label>
                    <input type="date" id="dueDate" required>
                </div>
                <div class="form-group">
                    <label>Borrower Email: <span class="required">*</span></label>
                    <input type="email" id="borrowerEmail" required placeholder="Enter borrower's email address">
                </div>
                <div class="form-group">
                    <label>Purpose/Notes:</label>
                    <textarea id="borrowPurpose" rows="3" placeholder="Purpose of borrowing or additional notes..."></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeBorrowModal()">Cancel</button>
            <button class="btn-submit" onclick="processBorrow()">Process Borrow</button>
        </div>
    </div>
</div>

<!-- Archive Confirmation Modal -->
<div class="modal-overlay" id="archiveItemModal">
    <div class="modal">
        <div class="modal-header" style="background: linear-gradient(135deg, #e53e3e, #c53030); color: white;">
            <h3 style="color: white;">Archive Item</h3>
            <button class="close-btn" onclick="closeArchiveModal()" style="color: white;">×</button>
        </div>
        <div class="modal-body">
            <div style="text-align: center; padding: 20px 0;">
                <div style="width: 80px; height: 80px; margin: 0 auto 20px; background: linear-gradient(135deg, #e53e3e, #c53030); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <img src="image/icons8-archive-50.png" alt="Archive" style="width: 40px; height: 40px; filter: brightness(0) invert(1);">
                </div>
                <h4 style="margin: 0 0 12px 0; color: #333; font-size: 20px; font-weight: 600;">Are you sure you want to archive this item?</h4>
                <p style="color: #666; margin: 0 0 8px 0; font-size: 15px;" id="archiveItemName">Item will be moved to the archive.</p>
                <p style="color: #999; font-size: 13px; margin: 0;">You can view and restore it later from the Archive page.</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeArchiveModal()">Cancel</button>
            <button class="btn-submit" onclick="confirmArchive()" style="background: linear-gradient(135deg, #e53e3e, #c53030); display: flex; align-items: center; gap: 8px;">
                <img src="image/icons8-archive-50.png" alt="Archive" style="width: 16px; height: 16px; filter: brightness(0) invert(1);">
                Archive Item
            </button>
        </div>
    </div>
</div>
<div class="modal-overlay" id="requestItemModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-content">
                <div class="modal-header-icon">📝</div>
                <div class="modal-header-text">
                    <h3 class="modal-header-title">Request an Item</h3>
                    <p class="modal-header-subtitle">Submit a request for new inventory</p>
                </div>
            </div>
            <button class="close-btn" onclick="closeRequestModal()">×</button>
        </div>
        
        <div class="modal-body">
            <form id="requestItemForm">
                <div class="form-group">
                    <label>Item Name: <span class="required">*</span></label>
                    <input type="text" id="requestItemName" required placeholder="What item do you need?">
                </div>
                <div class="form-group">
                    <label>Category:</label>
                    <select id="requestCategory">
                        <option value="">Select Category</option>
                        <!-- Categories populated by PHP -->
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity: <span class="required">*</span></label>
                    <input type="number" id="requestQuantity" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>Date Needed:</label>
                    <input type="date" id="requestDateNeeded" placeholder="Select date needed">
                </div>
                <div class="form-group">
                    <label>Notes:</label>
                    <textarea id="requestNotes" rows="3" placeholder="Additional details (optional)"></textarea>
                </div>
            </form>
        </div>
        
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeRequestModal()">Cancel</button>
            <button class="btn-submit" onclick="submitItemRequest()">Submit Request</button>
        </div>
    </div>
</div>

<!-- Viewer Borrow Request Modal -->
<div class="modal-overlay" id="viewerBorrowModal" style="display:none;">
    <div class="modal" style="max-width: 550px;">
        <div class="modal-header" style="position: relative; z-index: 10;">
            <h3>Request to Borrow Item</h3>
            <button class="close-btn" onclick="closeViewerBorrowModal()" style="position: relative; z-index: 11; pointer-events: auto;">×</button>
        </div>
        <div class="modal-body" style="position: relative; z-index: 1; pointer-events: auto;">
            <form id="viewerBorrowForm" onsubmit="event.preventDefault(); return false;" style="pointer-events: auto;">
                <div class="form-group">
                    <label>Item Name: <span class="required">*</span></label>
                    <input type="text" id="viewerBorrowItemName" readonly style="background-color: #f8f9fa;" required>
                    <input type="hidden" id="viewerBorrowItemId">
                </div>
                <div class="form-group">
                    <label>Item Code:</label>
                    <input type="text" id="viewerBorrowItemCode" readonly style="background-color: #f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Borrower Name: <span class="required">*</span></label>
                    <input type="text" id="viewerBorrowerName" required readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                </div>
                <div class="form-group">
                    <label>Borrower Email: <span class="required">*</span></label>
                    <input type="email" id="viewerBorrowerEmail" required readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                </div>
                <div class="form-group">
                    <label>Borrow Date (Request Date): <span class="required">*</span></label>
                    <input type="date" id="viewerBorrowDate" required readonly style="position: relative; z-index: 10; pointer-events: none; background-color: #f8f9fa; cursor: not-allowed;">
                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Automatically set to today (when you submit the request)</small>
                </div>
                <div class="form-group">
                    <label>Needed Date: <span class="required">*</span></label>
                    <input type="date" id="viewerNeededDate" required style="position: relative; z-index: 10; pointer-events: auto;">
                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">When do you need this item?</small>
                </div>
                <div class="form-group">
                    <label>Due Date: <span class="required">*</span></label>
                    <input type="date" id="viewerDueDate" required style="position: relative; z-index: 10; pointer-events: auto;">
                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">When will you return this item?</small>
                </div>
                <div class="form-group">
                    <label>Item Placement:</label>
                    <select id="viewerItemPlacement" style="position: relative; z-index: 10; pointer-events: auto; width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="">Select Location</option>
                        <!-- Locations will be populated dynamically -->
                    </select>
                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Where will this item be placed?</small>
                </div>
                <div class="form-group">
                    <label>Purpose/Notes:</label>
                    <textarea id="viewerBorrowPurpose" rows="3" placeholder="Purpose of borrowing or additional notes..." style="position: relative; z-index: 10; pointer-events: auto;"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="position: relative; z-index: 1; pointer-events: auto;">
            <button type="button" class="btn-cancel" onclick="closeViewerBorrowModal()" style="pointer-events: auto; cursor: pointer;">Cancel</button>
            <button type="button" class="btn-submit" id="viewerBorrowSubmitBtn" onclick="submitViewerBorrowRequest()" style="pointer-events: auto; cursor: pointer;">Submit Borrow Request</button>
        </div>
    </div>
</div>

<!-- Manage Borrow Requests Modal (Admin and Department Heads) -->
<?php 
// Show modal for admins, super admins, and users with departments
$modalIsAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$modalIsSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$modalHasDepartment = !empty($_SESSION['department']);
$showModal = $modalIsAdmin || $modalIsSuperAdmin || $modalHasDepartment || $isAdmin || $isSuperAdmin;
?>
<?php if ($showModal): ?>
<div id="manageBorrowRequestsModal" class="modal-overlay" style="display: none; z-index: 2147483000; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center;">
    <div class="modal" style="max-width: 900px; width: 95%; max-height: 90vh; overflow-y: auto; z-index: 2147483001;">
        <div class="modal-header" style="position: relative; z-index: 1; background: linear-gradient(135deg, #10b981, #059669); color: white;">
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

<!-- Add Department Modal (Super Admin only) -->
<?php if (isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1): ?>
<div class="modal-overlay" id="addDepartmentModal" style="display:none;">
    <div class="modal" style="max-width: 520px; border-radius: 16px; overflow:hidden;">
        <div class="modal-header" style="background: linear-gradient(135deg, #e53e3e, #c53030);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:16px 20px;">
            <div style="font-weight:700;">Add Department</div>
            <button class="close-btn" onclick="closeAddDepartmentModal()" style="background:transparent;border:none;color:#fff;font-size:22px;cursor:pointer;">×</button>
        </div>
        <div class="modal-body" style="padding:18px;background:#fff;">
            <div class="form-group">
                <label class="form-label" for="newDepartmentName">Department Name</label>
                <input type="text" id="newDepartmentName" class="form-input" placeholder="e.g. Science Department" />
            </div>
        </div>
        <div class="modal-footer" style="display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;background:#fff;border-top:1px solid #eee;">
            <button class="btn-cancel" onclick="closeAddDepartmentModal()">Cancel</button>
            <button class="btn-submit" onclick="submitAddDepartment()">Add Department</button>
        </div>
    </div>
    </div>
    
    <!-- Add Department Confirmation Modal -->
    <div class="modal-overlay" id="addDepartmentConfirmModal" style="display:none;">
        <div class="modal" style="max-width: 480px; border-radius: 16px; overflow:hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #48bb78, #2f855a);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:16px 20px;">
                <div style="font-weight:700;">Success</div>
                <button class="close-btn" onclick="closeAddDepartmentConfirmModal()" style="background:transparent;border:none;color:#fff;font-size:22px;cursor:pointer;">×</button>
            </div>
            <div class="modal-body" style="padding:18px;background:#fff;">
                <div id="addDeptConfirmMessage">Department added successfully.</div>
            </div>
            <div class="modal-footer" style="display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;background:#fff;border-top:1px solid #eee;">
                <button class="btn-submit" onclick="closeAddDepartmentConfirmModal(true)" style="background: linear-gradient(135deg, #48bb78, #2f855a);">OK</button>
            </div>
        </div>
    </div>
<?php endif; ?>
<script>
    function openAddDepartmentModal() {
        const modal = document.getElementById('addDepartmentModal');
        if (!modal) return;
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        const input = document.getElementById('newDepartmentName');
        if (input) setTimeout(() => input.focus(), 50);
    }
    function closeAddDepartmentModal() {
        const modal = document.getElementById('addDepartmentModal');
        if (!modal) return;
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => { modal.style.display = 'none'; }, 300);
        const input = document.getElementById('newDepartmentName');
        if (input) input.value = '';
    }
    async function submitAddDepartment() {
        const input = document.getElementById('newDepartmentName');
        const name = (input?.value || '').trim();
        if (!name) { alert('Please enter a department name.'); return; }
        try {
            const resp = await fetch('crud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'create_department', name })
            });
            const data = await resp.json();
            if (data.success) {
                closeAddDepartmentModal();
                const msg = document.getElementById('addDeptConfirmMessage');
                if (msg) msg.textContent = (data.message || 'Department added successfully.');
                const confirm = document.getElementById('addDepartmentConfirmModal');
                if (confirm) {
                    confirm.style.display = 'flex';
                    confirm.classList.add('show');
                    document.body.style.overflow = 'hidden';
                } else {
                    location.reload();
                }
            } else {
                const msg = document.getElementById('addDeptConfirmMessage');
                if (msg) msg.textContent = (data.message || 'Failed to add department');
                const confirm = document.getElementById('addDepartmentConfirmModal');
                if (confirm) {
                    // Show as error state by changing header color temporarily
                    const header = confirm.querySelector('.modal-header');
                    const okBtn = confirm.querySelector('.btn-submit');
                    if (header) header.style.background = 'linear-gradient(135deg, #e53e3e, #c53030)';
                    if (okBtn) okBtn.style.background = 'linear-gradient(135deg, #e53e3e, #c53030)';
                    confirm.style.display = 'flex';
                    confirm.classList.add('show');
                    document.body.style.overflow = 'hidden';
                } else {
                    alert(data.message || 'Failed to add department');
                }
            }
        } catch (e) {
            const msg = document.getElementById('addDeptConfirmMessage');
            if (msg) msg.textContent = 'Network error while adding department';
            const confirm = document.getElementById('addDepartmentConfirmModal');
            if (confirm) {
                const header = confirm.querySelector('.modal-header');
                const okBtn = confirm.querySelector('.btn-submit');
                if (header) header.style.background = 'linear-gradient(135deg, #e53e3e, #c53030)';
                if (okBtn) okBtn.style.background = 'linear-gradient(135deg, #e53e3e, #c53030)';
                confirm.style.display = 'flex';
                confirm.classList.add('show');
                document.body.style.overflow = 'hidden';
            } else {
                alert('Network error while adding department');
            }
        }
    }

    // Delete Department with confirmation modal
    let pendingDeleteDept = { id: null, name: '' };
    function deleteDepartment(deptId, deptName) {
        // Check if department has items by querying the data
        let itemCount = 0;
        if (departmentData[deptId]) {
            const dept = departmentData[deptId];
            if (dept.categories) {
                Object.values(dept.categories).forEach(category => {
                    itemCount += category.count || 0;
                });
            }
        }
        if (itemCount > 0) {
            // Show modal anyway but indicate why deletion is blocked
            pendingDeleteDept.id = null;
            pendingDeleteDept.name = deptName;
            openDeleteDepartmentConfirmModal(deptName);
            const err = document.getElementById('deleteDeptError');
            if (err) { err.textContent = `Cannot delete "${deptName}": department has ${itemCount} item(s).`; err.style.display = 'block'; }
            const btn = document.getElementById('deleteDepartmentConfirmBtn');
            if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
            if (typeof showToast === 'function') { showToast('Cannot delete: department has items', 'error'); }
            return;
        }
        pendingDeleteDept.id = deptId;
        pendingDeleteDept.name = deptName;
        openDeleteDepartmentConfirmModal(deptName);
    }

    function openDeleteDepartmentConfirmModal(deptName) {
        const modal = document.getElementById('deleteDepartmentConfirmModal');
        if (!modal) return;
        const msg = document.getElementById('deleteDeptConfirmMessage');
        if (msg) msg.textContent = `Are you sure you want to delete the department "${deptName}"? This action cannot be undone.`;
        const err = document.getElementById('deleteDeptError');
        if (err) { err.textContent = ''; err.style.display = 'none'; }
        const btn = document.getElementById('deleteDepartmentConfirmBtn');
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeDeleteDepartmentConfirmModal() {
        const modal = document.getElementById('deleteDepartmentConfirmModal');
        if (!modal) return;
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => { modal.style.display = 'none'; }, 300);
    }

    async function proceedDeleteDepartment() {
        try {
            const deptId = pendingDeleteDept.id;
            if (!deptId) { closeDeleteDepartmentConfirmModal(); return; }
            const resp = await fetch('crud.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'delete_department', id: String(deptId) })
            });
            const data = await resp.json();
            if (data && data.success) {
                closeDeleteDepartmentConfirmModal();
                if (typeof showToast === 'function') { showToast(data.message || 'Department deleted', 'success'); }
                location.reload();
            } else {
                const err = document.getElementById('deleteDeptError');
                if (err) {
                    err.textContent = (data && data.message) ? data.message : 'Failed to delete department';
                    err.style.display = 'block';
                }
            }
        } catch (e) {
            const err = document.getElementById('deleteDeptError');
            if (err) {
                err.textContent = 'Network error while deleting department';
                err.style.display = 'block';
            }
        }
    }

    function closeAddDepartmentConfirmModal(reload = false) {
        const confirm = document.getElementById('addDepartmentConfirmModal');
        if (!confirm) return;
        confirm.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => { confirm.style.display = 'none'; if (reload) location.reload(); }, 300);
    }
    
    // Global variables
    const isDepartmentHead = (document.body?.dataset?.userIsDepartmentHead || 'false') === 'true';
    
    // Initialize selected department - department heads start with their own department
    // Note: USER_DEPARTMENT_ID and USER_DEPARTMENT_NAME are defined later in the code
    let selectedDepartmentId = 'all';
    let selectedDepartmentName = 'All Departments';
    let selectedCategory = null;
    let allItems = [];
    let departmentData = {}; // Store organized data
let currentSort = 'updated_at';
let currentSortDir = 'desc'; // 'asc' | 'desc'
let currentCategoryPage = {}; // Stores current page for each category
const ITEMS_PER_PAGE = 10; // Number of items per page per category

// Flag to prevent duplicate loading
let isLoadingAllItems = false;


    // Load items when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Ensure page title is "Items" for department heads
        if (isDepartmentHead) {
            document.title = 'OCABIS Items';
        }
        
        // Don't load items automatically - wait for search
        // Items will be loaded when user performs a search
        // loadAllItems(); // Commented out - items should only load after search
        
        // Hide items containers initially
        const cardsContainer = document.getElementById('itemsCardsContainer');
        const tableContainer = document.querySelector('.table-container');
        if (cardsContainer) {
            cardsContainer.style.display = 'none';
        }
        if (tableContainer) {
            tableContainer.style.display = 'none';
        }
        
        // Initialize Manage Borrow Requests button visibility on page load
        // Hide button immediately on page load
        const manageBtn = document.getElementById('manageBorrowRequestsBtn');
        if (manageBtn) {
            manageBtn.style.display = 'none';
            manageBtn.style.visibility = 'hidden';
            manageBtn.style.opacity = '0';
        }
        // Then update visibility based on department selection
        setTimeout(() => {
            updateManageBorrowRequestsButtonVisibility();
        }, 500);
        loadCategories();
        
        // Initialize button visibility based on current department selection
        setTimeout(() => {
            updateButtonVisibility();
            // Initialize button text (should be "ADD ITEM TABLE" by default)
            updateAddItemButtonText(false);
        }, 100);

    // Hook department change to refresh categories in Add Item Table modal
    const addTableDeptSelect = document.getElementById('itemTableDepartment');
    if (addTableDeptSelect && !addTableDeptSelect.dataset.listenerAttached) {
        addTableDeptSelect.addEventListener('change', function() {
            try { updateItemTableCategoryOptions(); } catch (e) {}
        });
        addTableDeptSelect.dataset.listenerAttached = '1';
        if (addTableDeptSelect.value) {
            try { updateItemTableCategoryOptions(); } catch (e) {}
        }
    }

    const editItemDeptSelect = document.getElementById('editItemDepartment');
    if (editItemDeptSelect && !editItemDeptSelect.dataset.listenerAttached) {
        editItemDeptSelect.addEventListener('change', function() {
            const deptId = parseInt(this.value || '0', 10);
            loadEditItemCategories(isNaN(deptId) ? 0 : deptId, '');
        });
        editItemDeptSelect.dataset.listenerAttached = '1';
    }
    });


    // Load all items and organize by department and category
    // Note: This function is replaced by the global loadAllItems() function below
    // Keeping this as a reference but it will be overridden

    // Load categories for the request modal dropdown
    function loadCategories() {
        // Head departments (admin but not super admin) can only request from their department's categories
        // Regular users (non-admin, non-super admin) can only request from their department's categories
        // Super admins can see all categories
        const isDepartmentHead = (document.body?.dataset?.userIsDepartmentHead || 'false') === 'true';
        const shouldFilterByDept = (isDepartmentHead || (!IS_ADMIN && !IS_SUPER_ADMIN)) && USER_DEPARTMENT_ID;
        const q = shouldFilterByDept ? `&department_id=${USER_DEPARTMENT_ID}` : '';
        fetch(`crud.php?action=get_categories${q}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const categorySelect = document.getElementById('requestCategory');
                    if (categorySelect) {
                        // Clear existing options except the first one
                        categorySelect.innerHTML = '<option value="">Select Category</option>';
                        
                        // Add categories to dropdown
                        data.categories.forEach(category => {
                            const option = document.createElement('option');
                            option.value = category;
                            option.textContent = category;
                            categorySelect.appendChild(option);
                        });
                    }
                } else {
                    console.error('Error loading categories:', data.message);
                }
            })
            .catch(error => {
                console.error('Error fetching categories:', error);
            });
    }

    // Permission flags from PHP session
    // Super admins should have all admin privileges
    const IS_SUPER_ADMIN = (document.body?.dataset?.userSuperAdmin || 'false') === 'true';
    const IS_ADMIN = <?= json_encode($isAdmin) ?> || IS_SUPER_ADMIN;
    const USER_DEPARTMENT = <?= json_encode($userDepartmentName) ?>;
    const USER_DEPARTMENT_ID = <?= json_encode($userDepartmentId) ?>;
    const CATEGORIES_BY_DEPT = <?= json_encode($categories_by_dept) ?>;
    
    // Initialize selected department for department heads
    if (isDepartmentHead && USER_DEPARTMENT_ID && USER_DEPARTMENT) {
        selectedDepartmentId = String(USER_DEPARTMENT_ID);
        selectedDepartmentName = USER_DEPARTMENT;
    }
    
    // Organize data by department and category
    function organizeDepartmentData() {
        departmentData = {};
        
        // Get departments from PHP
        const departments = <?= json_encode($departments) ?>;
        
        // Store original order of departments (by ID) for consistent sorting
        const originalDeptOrder = departments.map(dept => dept.id);
        
        // Initialize department structure
        departments.forEach(dept => {
            departmentData[dept.id] = {
                name: dept.name,
                categories: {},
                totalItems: 0,
                originalOrder: originalDeptOrder.indexOf(dept.id) // Store original position
            };
        });
        
        // Pre-populate categories from database (even if no items yet)
        if (typeof CATEGORIES_BY_DEPT !== 'undefined' && CATEGORIES_BY_DEPT) {
            Object.keys(CATEGORIES_BY_DEPT).forEach(rawDeptId => {
                const deptId = rawDeptId;
                const categoryNames = CATEGORIES_BY_DEPT[rawDeptId];
                if (!Array.isArray(categoryNames) || !departmentData[deptId]) {
                    return;
                }
                categoryNames.forEach(categoryName => {
                    if (!departmentData[deptId].categories[categoryName]) {
                        departmentData[deptId].categories[categoryName] = {
                            items: [],
                            count: 0
                        };
                    }
                });
            });
        }
        
        // Organize items by department and category
        allItems.forEach(item => {
            const deptId = item.department_id;
            const category = item.category;
            
            if (departmentData[deptId]) {
                departmentData[deptId].totalItems++;
                
                if (!departmentData[deptId].categories[category]) {
                    departmentData[deptId].categories[category] = {
                        items: [],
                        count: 0
                    };
                }
                
                departmentData[deptId].categories[category].items.push(item);
                departmentData[deptId].categories[category].count++;
            }
        });
    }

    // Build the tree structure HTML
    function buildTreeStructure() {
        const isSuperAdmin = (document.body?.dataset?.userSuperAdmin || 'false') === 'true';
        const isDepartmentHead = (document.body?.dataset?.userIsDepartmentHead || 'false') === 'true';
        const treeMenu = document.getElementById('treeMenu');
        
        // Preserve currently selected department before rebuilding
        const currentSelectedDeptId = selectedDepartmentId || 'all';
        
        let treeHTML = '';
        
        // Only show "All Departments" for super admins
        if (isSuperAdmin) {
            // Check if "all" is the selected department
            const isAllActive = currentSelectedDeptId === 'all';
            treeHTML = `
            <!-- All Departments Option -->
            <div class="tree-item" data-dept-id="all">
                <div class="tree-node ${isAllActive ? 'active' : ''}" onclick="selectDepartment('all', 'All Departments')">
                    <img src="image/building-1062.png" alt="Building" class="tree-icon">
                    <span class="tree-text">All Departments</span>
                </div>
            </div>
        `;
        }
        
        // Build department nodes - sort by original order or name to maintain consistency
        const sortedDeptIds = Object.keys(departmentData).sort((a, b) => {
            const deptA = departmentData[a];
            const deptB = departmentData[b];
            
            // If original order is available, use it
            if (deptA.originalOrder !== undefined && deptB.originalOrder !== undefined) {
                return deptA.originalOrder - deptB.originalOrder;
            }
            
            // Otherwise, sort by name
            return deptA.name.localeCompare(deptB.name);
        });
        
        sortedDeptIds.forEach(deptId => {
            const dept = departmentData[deptId];
            const hasCategories = Object.keys(dept.categories).length > 0;
            
            // Check if this department is the selected one
            const isDeptActive = String(currentSelectedDeptId) === String(deptId);
            
            treeHTML += `
                <div class="tree-item" data-dept-id="${deptId}">
                    <div class="tree-node ${isDeptActive ? 'active' : ''}" onclick="selectDepartment(${deptId}, '${dept.name}')">
                        <img src="image/building-1062.png" alt="Building" class="tree-icon">
                        <span class="tree-text">${dept.name}</span>
                        ${isSuperAdmin ? `<button title="Delete Department" aria-label="Delete Department" onclick="event.stopPropagation(); deleteDepartment(${deptId}, '${dept.name.replace(/'/g, "&#39;")}')" style="margin-left:8px;background:transparent;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;color:#dc2626;"><img src=\"image/delete.png\" alt=\"Delete\" style=\"width:14px;height:14px;opacity:0.9;\" /></button>` : ''}
                    </div>
                    <div class="tree-children" id="children-${deptId}" style="display: none;">
            `;
            
            // Build category nodes
            Object.keys(dept.categories).forEach(categoryName => {
                const category = dept.categories[categoryName];
                treeHTML += `
                    <div class="tree-item category-item" data-dept-id="${deptId}" data-category="${categoryName}">
                        <div class="tree-node category-node" onclick="selectCategory(${deptId}, '${dept.name}', '${categoryName}')">
                            <img src="image/table.png" alt="Category" class="tree-icon">
                            <span class="tree-text">${categoryName}</span>
                        </div>
                    </div>
                `;
            });
            
            treeHTML += `
                    </div>
                </div>
            `;
        });
        
        treeMenu.innerHTML = treeHTML;
    }

// Form Validation Functions

// Validate item name to ensure it starts with the base category name
function validateItemName(inputField, baseName) {
    const value = inputField.value.trim();
    const formGroup = inputField.closest('.form-group');
    let errorText = formGroup.querySelector('.error-text');
    
    // Remove existing error styling
    formGroup.classList.remove('has-error');
    if (errorText) {
        errorText.remove();
    }
    
    // Check if the value starts with the base name (case insensitive)
    if (value && !value.toLowerCase().startsWith(baseName.toLowerCase())) {
        // Add error styling
        formGroup.classList.add('has-error');
        inputField.style.borderColor = '#dc3545';
        inputField.style.backgroundColor = '#fef2f2';
        
        // Add error message
        if (!errorText) {
            errorText = document.createElement('div');
            errorText.className = 'error-text';
            formGroup.appendChild(errorText);
        }
        errorText.textContent = `Item name must start with "${baseName}" (e.g., ${baseName} - Brand Name)`;
        errorText.style.color = '#dc3545';
        errorText.style.fontSize = '12px';
        errorText.style.marginTop = '4px';
        
        return false;
    } else {
        // Remove error styling
        inputField.style.borderColor = '';
        inputField.style.backgroundColor = value ? '#fff3cd' : '';
        return true;
    }
}

function validateRequiredFields(formId) {
    const form = document.getElementById(formId);
    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;
    let firstErrorField = null;
    
    // Clear previous errors
    form.querySelectorAll('.form-group').forEach(group => {
        group.classList.remove('has-error');
    });
    
    requiredFields.forEach(field => {
        const formGroup = field.closest('.form-group');
        const value = field.value.trim();
        
        if (!value) {
            formGroup.classList.add('has-error');
            
            // Add or update error message
            let errorText = formGroup.querySelector('.error-text');
            if (!errorText) {
                errorText = document.createElement('div');
                errorText.className = 'error-text';
                formGroup.appendChild(errorText);
            }
            
            const fieldName = field.previousElementSibling ? 
                field.previousElementSibling.textContent.replace('*', '').replace(':', '').trim() : 
                'This field';
            
            errorText.textContent = `${fieldName} is required`;
            
            if (!firstErrorField) {
                firstErrorField = field;
            }
            
            isValid = false;
        }
    });
    
    // Scroll to first error field
    if (firstErrorField) {
        firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstErrorField.focus();
    }
    
    return isValid;
}
// Fixed setButtonLoading function with null check
function setButtonLoading(buttonId, isLoading, originalText = null) {
    const button = document.getElementById(buttonId);
    
    // Add null check to prevent error
    if (!button) {
        console.warn(`Button with ID '${buttonId}' not found`);
        return;
    }
    
    if (isLoading) {
        if (originalText) {
            button.dataset.originalText = originalText;
        } else {
            button.dataset.originalText = button.textContent;
        }
        button.classList.add('btn-loading');
        button.textContent = 'Processing...';
        button.disabled = true;
    } else {
        button.classList.remove('btn-loading');
        button.textContent = button.dataset.originalText || 'Submit';
        button.disabled = false;
    }
}

    // Toggle department expansion
    function toggleExpand(deptId) {
        const children = document.getElementById(`children-${deptId}`);
        const expandIcon = document.querySelector(`[data-dept-id="${deptId}"] > .tree-node .tree-expand`);
        
        if (children.style.display === 'none') {
            children.style.display = 'block';
            expandIcon.textContent = '▼';
        } else {
            children.style.display = 'none';
            expandIcon.textContent = '▶';
        }
    }

    // Function to update ADD ITEM TABLE button text based on context
    function updateAddItemButtonText(isItemTableSelected) {
        const addItemTableBtn = document.getElementById('addItemTableBtn');
        if (!addItemTableBtn) return;
        
        // Ensure button maintains centered styling
        addItemTableBtn.style.display = 'flex';
        addItemTableBtn.style.alignItems = 'center';
        addItemTableBtn.style.justifyContent = 'center';
        addItemTableBtn.style.gap = '6px';
        
        const buttonImg = addItemTableBtn.querySelector('img');
        
        if (isItemTableSelected) {
            // Change to "ADD ITEM" when viewing an item table
            if (buttonImg) {
                buttonImg.src = 'image/icons8-add-48.png';
                buttonImg.alt = 'Add Item';
                buttonImg.style.width = '18px';
                buttonImg.style.height = '18px';
                buttonImg.style.filter = 'brightness(0) invert(1)';
            }
            // Update button text - replace the text content after the image
            // Get all child nodes
            const childNodes = Array.from(addItemTableBtn.childNodes);
            // Remove all text nodes
            childNodes.forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                    node.remove();
                }
            });
            // Add new text after the image
            addItemTableBtn.appendChild(document.createTextNode(' ADD ITEM'));
            // Change onclick to open Add Item modal directly
            addItemTableBtn.setAttribute('onclick', 'openAddItemModal();');
        } else {
            // Change back to "ADD ITEM TABLE" when viewing departments
            if (buttonImg) {
                buttonImg.src = 'image/table.png';
                buttonImg.alt = 'Add Table';
                buttonImg.style.width = '18px';
                buttonImg.style.height = '18px';
                buttonImg.style.filter = 'brightness(0) invert(1)';
            }
            // Update button text - replace the text content after the image
            // Get all child nodes
            const childNodes = Array.from(addItemTableBtn.childNodes);
            // Remove all text nodes
            childNodes.forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                    node.remove();
                }
            });
            // Add new text after the image
            addItemTableBtn.appendChild(document.createTextNode(' ADD ITEM TABLE'));
            // Change onclick back to open Add Item Table modal
            addItemTableBtn.setAttribute('onclick', 'openAddItemTableModal()');
        }
    }

    // Function to toggle button visibility based on selected department
    function updateButtonVisibility() {
        // Check if user is department head (admin but not super admin)
        const isDepartmentHead = (document.body?.dataset?.userIsDepartmentHead || 'false') === 'true';
        const isSuperAdmin = (document.body?.dataset?.userSuperAdmin || 'false') === 'true';
        
        // Super admins always see all buttons regardless of department
        // Department heads: show buttons only when viewing their own department (not "All Departments" and not other departments)
        // Regular users: only show buttons when viewing their own department
        let shouldShowButtons = true;
        
        if (isSuperAdmin) {
            // Super admins always see all buttons
            shouldShowButtons = true;
        } else if (isDepartmentHead) {
            // Department heads: show buttons only when viewing their own department
            // Hide when viewing "All Departments" or other departments
            shouldShowButtons = selectedDepartmentId !== 'all' && 
                               USER_DEPARTMENT_ID && 
                               String(selectedDepartmentId) === String(USER_DEPARTMENT_ID);
        } else {
            // Regular users: only show buttons when viewing their own department
            shouldShowButtons = USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID);
        }
        
        // Get all action buttons
        const borrowBtn = document.getElementById('borrowItemBtn');
        const addItemTableContainer = document.getElementById('addItemTableContainer');
        
        // Show/hide buttons based on department selection
        if (borrowBtn) {
            borrowBtn.style.display = shouldShowButtons ? '' : 'none';
        }
        if (addItemTableContainer) {
            addItemTableContainer.style.display = shouldShowButtons ? '' : 'none';
        }
    }

    // Select department function
    // Updated selectDepartment function - make it globally accessible
    window.selectDepartment = function(deptId, deptName) {
    selectedDepartmentId = deptId;
    selectedDepartmentName = deptName;
    selectedCategory = null;
    
    // Clear current item table selection when switching departments
    window.currentTableId = null;
    window.currentTableName = null;
    
    // Update button text back to "ADD ITEM TABLE" when viewing departments
    updateAddItemButtonText(false);
    
    // Update active state in sidebar
    document.querySelectorAll('.tree-node').forEach(node => {
        node.classList.remove('active');
    });
    const targetNode = document.querySelector(`[data-dept-id="${deptId}"] > .tree-node`);
    if (targetNode) {
        targetNode.classList.add('active');
    }
    
    // Update breadcrumb (clear table name)
    updateBreadcrumb(deptName, null, null);
    
    // Update button visibility based on department selection
    updateButtonVisibility();
    
    // Update Manage Borrow Requests button visibility
    updateManageBorrowRequestsButtonVisibility();
    
    // Hide items containers - wait for search
    // Items will only be displayed after user performs a search
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.querySelector('.table-container');
    const noFilterMessage = document.getElementById('noFilterMessage');
    
    if (cardsContainer) {
        cardsContainer.style.display = 'none';
    }
    if (tableContainer) {
        tableContainer.style.display = 'none';
    }
    
    // Show message to search for items
    if (noFilterMessage) {
        noFilterMessage.style.display = 'block';
    }
    
    // Clear search input when switching departments
    const nameFilter = document.getElementById('nameFilter');
    if (nameFilter) {
        nameFilter.value = '';
    }
    
    updateSummary();
    };

    // Select category function
    function selectCategory(deptId, deptName, categoryName) {
    selectedDepartmentId = deptId;
    selectedDepartmentName = deptName;
    selectedCategory = categoryName;
    
    // Update button visibility based on selected department
    updateButtonVisibility();
    
    // Update active state
    document.querySelectorAll('.tree-node').forEach(node => {
        node.classList.remove('active');
    });
    const targetNode = document.querySelector(`[data-dept-id="${deptId}"][data-category="${categoryName}"] .tree-node`);
    if (targetNode) {
        targetNode.classList.add('active');
    }
    
    // Update breadcrumb
    updateBreadcrumb(deptName, categoryName);
    
    // Don't show items automatically - wait for search
    // Items will only be displayed after user performs a search
    // showCardView(); // Commented out - items should only show after search
    
    // Hide items containers when category is selected (wait for search)
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.querySelector('.table-container');
    if (cardsContainer) {
        cardsContainer.style.display = 'none';
    }
    if (tableContainer) {
        tableContainer.style.display = 'none';
    }
    
    // Don't load items automatically - wait for search
    // const categoryItems = departmentData[deptId].categories[categoryName].items;
    // displayItems(categoryItems);
    updateSummary();
}

    // Update breadcrumb
    function updateBreadcrumb(deptName, categoryName = null, tableName = null) {
        const separator = document.getElementById('breadcrumbSeparator');
        const deptBreadcrumb = document.getElementById('breadcrumbDepartment');
        const tableSeparator = document.getElementById('breadcrumbTableSeparator');
        const tableBreadcrumb = document.getElementById('breadcrumbTable');
        
        if (deptName === 'All Departments') {
            separator.style.display = 'none';
            deptBreadcrumb.style.display = 'none';
            tableSeparator.style.display = 'none';
            tableBreadcrumb.style.display = 'none';
        } else {
            separator.style.display = 'inline';
            deptBreadcrumb.style.display = 'inline';
            
            // Make department clickable
            deptBreadcrumb.innerHTML = `<span class="clickable" onclick="goToDepartment('${deptName}')" style="cursor: pointer; color: #333; text-decoration: underline;">${deptName}</span>`;
            
            if (tableName) {
                // Show item table in breadcrumb
                tableSeparator.style.display = 'inline';
                tableBreadcrumb.style.display = 'inline';
                tableBreadcrumb.innerHTML = `<span class="clickable" onclick="goToItemTable('${tableName}')" style="cursor: pointer; color: #333; text-decoration: underline;">${tableName}</span>`;
            } else {
                tableSeparator.style.display = 'none';
                tableBreadcrumb.style.display = 'none';
            }
        }
    }
    
    // Navigation functions for breadcrumb
    function goToItemsPage() {
        // Go back to items/departments page
        const cardsContainer = document.getElementById('itemsCardsContainer');
        const tableContainer = document.querySelector('.table-container');
        const noFilterMessage = document.getElementById('noFilterMessage');
        
        // Clear current table selection
        window.currentTableId = null;
        window.currentTableName = null;
        
        // Hide items containers - wait for search
        // Items will only be displayed after user performs a search
        if (cardsContainer) {
            cardsContainer.style.display = 'none';
        }
        if (tableContainer) {
            tableContainer.style.display = 'none';
            tableContainer.style.visibility = 'hidden';
        }
        
        // Show message to search for items
        if (noFilterMessage) {
            noFilterMessage.style.display = 'block';
        }
        
        // Update breadcrumb
        if (selectedDepartmentId && selectedDepartmentId !== 'all') {
            updateBreadcrumb(selectedDepartmentName, null, null);
        } else {
            updateBreadcrumb('All Departments', null, null);
        }
        
        // Clear search input
        const nameFilter = document.getElementById('nameFilter');
        if (nameFilter) {
            nameFilter.value = '';
        }
    }
    
    function goToDepartment(deptName) {
        // Go back to department view (show item tables for that department)
        const deptId = Object.keys(departmentData).find(id => {
            const dept = departmentData[id];
            return dept && dept.name === deptName;
        });
        
        if (deptId) {
            selectDepartment(deptId, deptName);
        } else {
            // Fallback: try to find in tree
            const treeItem = document.querySelector(`[data-dept-id] .tree-text`);
            if (treeItem && treeItem.textContent.trim() === deptName) {
                const treeItemParent = treeItem.closest('[data-dept-id]');
                if (treeItemParent) {
                    const deptId = treeItemParent.getAttribute('data-dept-id');
                    selectDepartment(deptId, deptName);
                }
            }
        }
    }
    
    function goToItemTable(tableName) {
        // Go back to item table view
        // Find the table ID from the current table name
        if (window.currentTableId && window.currentTableName === tableName) {
            showTableForItemTable(window.currentTableId, tableName);
        } else {
            // Try to find table by name
            fetch('crud.php?action=get_item_tables')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.item_tables) {
                        const table = data.item_tables.find(t => t.table_name === tableName);
                        if (table) {
                            showTableForItemTable(table.id, tableName);
                        }
                    }
                });
        }
    }

    // Load items for specific department
    function loadItemsForDepartment(deptId) {
    // Show cards container and hide table
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.querySelector('.table-container');
    
    if (cardsContainer) {
        cardsContainer.style.display = 'grid';
        cardsContainer.style.visibility = 'visible';
    }
    if (tableContainer) {
        tableContainer.style.display = 'none';
        tableContainer.style.visibility = 'hidden';
    }
    
    // Show the view toggle for individual item cards
    const viewToggle = document.getElementById('tableCardsViewToggle');
    if (viewToggle) {
        viewToggle.style.display = 'inline-flex';
    }
    
    if (deptId === 'all') {
        displayItems(allItems);
    } else {
        const filteredItems = allItems.filter(item => item.department_id == deptId);
        displayItems(filteredItems);
    }
    
    // Clean up invalid selections (items from other departments)
    cleanupInvalidSelections();
    
    updateSummary();
}

// Clean up selections that are not from user's department
function cleanupInvalidSelections() {
    const userDept = USER_DEPARTMENT || '';
    // Super admins and regular admins can select all items
    if (!userDept || IS_ADMIN || IS_SUPER_ADMIN) {
        return;
    }
    
    let removedCount = 0;
    const itemsToRemove = [];
    
    selectedItems.forEach(itemId => {
        const item = allItems.find(i => i.id == itemId);
        if (!item || String(item.department_name) !== String(userDept)) {
            itemsToRemove.push(itemId);
            removedCount++;
        }
    });
    
    // Remove invalid items from selection
    itemsToRemove.forEach(itemId => {
        selectedItems.delete(itemId);
    });
    
    if (removedCount > 0) {
        updateSelectionUI();
        updateSelectedCount();
    }
}

    // Old displayItems function removed - now using the new card-based displayItems function below
    // Toggle action menu
function toggleActionMenu(itemId) {
    // Close all other open menus
    document.querySelectorAll('.action-menu').forEach(menu => {
        if (menu.id !== `menu-${itemId}`) {
            menu.classList.remove('show');
        }
    });
    
    // Toggle current menu
    const menu = document.getElementById(`menu-${itemId}`);
    const button = menu.previousElementSibling; // The action button
    
    if (menu.classList.contains('show')) {
        menu.classList.remove('show');
        return;
    }
    
    // Show the menu first to calculate dimensions
    menu.classList.add('show');
    
    // Get button position relative to viewport
    const buttonRect = button.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    const viewportHeight = window.innerHeight;
    const viewportWidth = window.innerWidth;
    
    // Calculate position for fixed positioning
    let topPosition, leftPosition;
    
    // Calculate available space below the button
    const spaceBelow = viewportHeight - buttonRect.bottom;
    const spaceAbove = buttonRect.top;
    
    // If there's not enough space below but enough space above, position menu above
    if (spaceBelow < menuRect.height && spaceAbove > menuRect.height) {
        // Position above the button
        topPosition = buttonRect.top - menuRect.height - 4;
    } else {
        // Position below the button
        topPosition = buttonRect.bottom + 4;
    }
    
    // Calculate horizontal position
    const spaceRight = viewportWidth - buttonRect.right;
    if (spaceRight < menuRect.width) {
        // Position to the left of the button
        leftPosition = buttonRect.right - menuRect.width;
    } else {
        // Position to the right of the button
        leftPosition = buttonRect.right;
    }
    
    // Apply fixed positioning
    menu.style.top = `${topPosition}px`;
    menu.style.left = `${leftPosition}px`;
    menu.style.right = 'auto';
    menu.style.bottom = 'auto';
    
    // Ensure menu stays within viewport bounds
    if (topPosition < 0) {
        menu.style.top = '10px';
    }
    if (leftPosition < 0) {
        menu.style.left = '10px';
    }
    if (leftPosition + menuRect.width > viewportWidth) {
        menu.style.left = `${viewportWidth - menuRect.width - 10}px`;
    }
    
    // Close menu when clicking outside
    document.addEventListener('click', function closeMenu(e) {
        if (!e.target.closest('.action-dropdown')) {
            menu.classList.remove('show');
            document.removeEventListener('click', closeMenu);
        }
    });
}

    // Show no items message
    function showNoItems(message) {
        document.getElementById('itemsTableBody').innerHTML = `
            <tr><td colspan="9" class="no-items" style="text-align: center; padding: 20px;">${message}</td></tr>
        `;
    }

    // Update summary information (supports cards view and multi-table view)
    async function updateSummary() {
    const tableContainer = document.querySelector('.table-container');
    let itemCount = 0;
    let totalQuantity = 0;

    // Calculate based on current view
    if (tableContainer && getComputedStyle(tableContainer).display !== 'none') {
        const rows = tableContainer.querySelectorAll('.category-table tbody tr');
        rows.forEach(row => {
            const cells = row.cells;
            if (cells && cells.length >= 5) {
                itemCount += 1;
                totalQuantity += parseInt(cells[4].textContent) || 0;
            }
        });
    } else {
        // Cards view: compute from current filtered items
        let items = allItems || [];
        if (selectedDepartmentId !== 'all') {
            items = items.filter(item => item.department_id == selectedDepartmentId);
        }
        if (selectedCategory) {
            items = items.filter(item => item.category === selectedCategory);
        }
        
        // Count all item tables (including those with no items)
        // First, get all item tables from the database
        let itemTableCount = 0;
        try {
            const response = await fetch('crud.php?action=get_item_tables');
            const data = await response.json();
            if (data.success && data.item_tables) {
                itemTableCount = data.item_tables.length;
            }
        } catch (error) {
            console.error('Error fetching item tables:', error);
            // Fallback: count unique item tables from items data
            const itemTableGroups = {};
            items.forEach(item => {
                const tableId = item.item_table_id;
                if (tableId && !itemTableGroups[tableId]) {
                    itemTableGroups[tableId] = { 
                        count: 0, 
                        quantity: 0 
                    };
                }
                if (tableId && itemTableGroups[tableId]) {
                    itemTableGroups[tableId].count += 1;
                    itemTableGroups[tableId].quantity += parseInt(item.quantity) || 0;
                }
            });
            itemTableCount = Object.keys(itemTableGroups).length;
        }
        itemCount = items.length;
        totalQuantity = items.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
        
        // Update display to show "Item Table: X" format
        const countEl = document.getElementById('itemCount');
        const qtyEl = document.getElementById('totalQuantity');
        if (countEl) countEl.textContent = `${itemTableCount}`;
        if (qtyEl) qtyEl.textContent = `${totalQuantity} units`;
        
        // Update the summary text format
        const summaryInfo = document.getElementById('summaryInfo');
        if (summaryInfo) {
            summaryInfo.innerHTML = `
                Item Table: <strong>${itemTableCount}</strong> &nbsp;&nbsp;
                Items: <strong>${itemCount}</strong> &nbsp;&nbsp;
                Total Quantity: <strong>${totalQuantity} units</strong>
            `;
        }
        return;
    }

    const countEl = document.getElementById('itemCount');
    const qtyEl = document.getElementById('totalQuantity');
    if (countEl) countEl.textContent = itemCount;
    if (qtyEl) qtyEl.textContent = totalQuantity + ' units';
}
    // Sort items
    function sortItems() {
    const sortBy = document.getElementById('sortFilter').value;
    currentSort = sortBy || 'updated_at';
    // Determine which view is visible and refresh
        const tableContainer = document.querySelector('.table-container');
        if (tableContainer && getComputedStyle(tableContainer).display !== 'none') {
            populateTableFromCards();
        } else {
            updateCardView();
        }
    updateSortIndicators();
    }

    function setSort(columnKey) {
    if (currentSort === columnKey) {
        currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
    } else {
        currentSort = columnKey;
        currentSortDir = 'asc';
    }
    // Refresh current view
    const tableContainer = document.querySelector('.table-container');
    if (tableContainer && getComputedStyle(tableContainer).display !== 'none') {
        // Check if viewing a single category table
        const categoryWraps = tableContainer.querySelectorAll('.category-table-wrap');
        if (categoryWraps.length === 1) {
            // Single category view - refresh only this category
            const categoryName = categoryWraps[0].dataset.category;
            showTableForCategory(categoryName);
        } else {
            // Multiple categories view - refresh all
            populateTableFromCards();
        }
    } else {
        updateCardView();
    }
    updateSortIndicators();
}

function updateSortIndicators() {
    // Reset all indicators first
    document.querySelectorAll('.sort-indicator').forEach(el => {
        const col = el.getAttribute('data-col');
        if (col === currentSort) {
            el.textContent = currentSortDir === 'asc' ? '↑' : '↓';
            el.style.color = '#007bff';
            el.style.fontWeight = 'bold';
        } else {
            el.textContent = ''; // Empty instead of ⇅
            el.style.color = '#999';
            el.style.fontWeight = 'normal';
        }
    });
}

    // Format date
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    }

    // Enhanced Modal functions - Adapted from Categories Page
    function openAddItemModal() {
        const modal = document.getElementById('addItemModal');
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // Load item tables for the dropdown
        loadItemTables().then(() => {
            // Auto-select item table if user is viewing a specific item table
            if (window.currentTableId) {
                const itemTableSelect = document.getElementById('itemTable');
                if (itemTableSelect) {
                    itemTableSelect.value = window.currentTableId;
                    // Trigger the change event to populate department and category fields
                    onItemTableChange();
                }
            }
        });
        
        // Initialize quantity to 1 and show image upload
        setTimeout(() => {
            const quantityInput = document.getElementById('itemQuantity');
            if (quantityInput) {
                quantityInput.value = '1';
                toggleItemImageUpload();
            }
        }, 100);
        
        // Pre-select department if one is selected
        if (selectedDepartmentId !== 'all') {
            const itemDepartmentField = document.getElementById('itemDepartment');
            if (itemDepartmentField) {
                itemDepartmentField.value = selectedDepartmentId;
            }
        }
        
        // Super admins can add items to any department
        // If not admin and selected department is not user's, block add
        if (!IS_ADMIN && !IS_SUPER_ADMIN && USER_DEPARTMENT && selectedDepartmentId !== 'all') {
            const selectedDept = departmentData[selectedDepartmentId]?.name;
            if (selectedDept && selectedDept !== USER_DEPARTMENT) {
                closeAddItemModal();
                modal.warning('Action Not Allowed: You can only add items to your own department.');
                return;
            }
        }

        // Pre-fill category if one is selected
        if (selectedCategory) {
            const itemCategoryField = document.getElementById('itemCategory');
            if (itemCategoryField) {
                itemCategoryField.value = selectedCategory;
            }
        }
        
        // Focus the first input after animation
        setTimeout(() => {
            document.getElementById('itemTable').focus();
        }, 300);
    }

    function closeAddItemModal() {
        const modal = document.getElementById('addItemModal');
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            modal.style.display = 'none';
        }, 400);
        document.getElementById('addItemForm').reset();
        removeImagePreview();
        
        // Reset item image preview and toggle visibility
        const itemImageInput = document.getElementById('itemImage');
        const itemImagePreview = document.getElementById('itemImagePreview');
        if (itemImageInput) {
            itemImageInput.value = '';
        }
        if (itemImagePreview) {
            itemImagePreview.style.display = 'none';
        }
        // Reset quantity to 1 and show image upload
        const quantityInput = document.getElementById('itemQuantity');
        if (quantityInput) {
            quantityInput.value = '1';
            toggleItemImageUpload();
        }
    }

    function previewImage(input) {
        const file = input.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewImg').src = e.target.result;
                document.getElementById('imagePreview').style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    }

    function removeImagePreview() {
        const itemImageField = document.getElementById('itemImage');
        if (itemImageField) {
            itemImageField.value = '';
        }
        
        const imagePreview = document.getElementById('imagePreview');
        if (imagePreview) {
            imagePreview.style.display = 'none';
        }
        
        const previewImg = document.getElementById('previewImg');
        if (previewImg) {
            previewImg.src = '';
        }
    }

    // Toggle item image upload visibility based on quantity
    function toggleItemImageUpload() {
        const quantityInput = document.getElementById('itemQuantity');
        const imageGroup = document.getElementById('itemImageGroup');
        const itemImageInput = document.getElementById('itemImage');
        const itemImagePreview = document.getElementById('itemImagePreview');
        
        if (!quantityInput || !imageGroup) {
            return;
        }
        
        const quantity = parseInt(quantityInput.value) || 1;
        
        if (quantity === 1) {
            // Show image upload when quantity is 1
            imageGroup.style.display = 'block';
        } else {
            // Hide image upload when quantity is 2 or more
            imageGroup.style.display = 'none';
            // Clear the image input and preview
            if (itemImageInput) {
                itemImageInput.value = '';
            }
            if (itemImagePreview) {
                itemImagePreview.style.display = 'none';
            }
        }
    }

    // Preview item image
    function previewItemImage(input) {
        const preview = document.getElementById('itemImagePreview');
        const previewImg = document.getElementById('previewItemImg');
        
        if (!preview || !previewImg) {
            return;
        }
        
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                preview.style.display = 'block';
            };
            
            reader.readAsDataURL(input.files[0]);
        } else {
            preview.style.display = 'none';
        }
    }

    // Remove item image preview
    function removeItemImagePreview() {
        const preview = document.getElementById('itemImagePreview');
        const previewImg = document.getElementById('previewItemImg');
        const itemImageInput = document.getElementById('itemImage');
        
        if (preview) {
            preview.style.display = 'none';
        }
        if (previewImg) {
            previewImg.src = '';
        }
        if (itemImageInput) {
            itemImageInput.value = '';
        }
    }

    // Add Item Table Modal Functions
    function openAddItemTableModal() {
        // Super admins can always add item tables to any department
        // Check if user is viewing their own department
        const isViewingOwnDepartment = IS_SUPER_ADMIN ? true :
            (IS_ADMIN ? 
                (selectedDepartmentId === 'all' || (USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID))) :
                (USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID)));
        
        if (!isViewingOwnDepartment) {
            modal.warning('Action Not Allowed: You can only add item tables to your own department.');
            return;
        }
        
        const addItemTableModal = document.getElementById('addItemTableModal');
        addItemTableModal.style.display = 'flex';
        addItemTableModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        setTimeout(() => {
            addItemTableModal.style.display = 'flex';
        }, 300);
        
        // Ensure event listener is attached and load categories
        setTimeout(() => {
            try {
                const deptSelect = document.getElementById('itemTableDepartment');
                const catSelect = document.getElementById('itemTableCategory');
                const hint = document.getElementById('itemTableCategoryHint');
                
                // For department heads (non-super admins), auto-set department and load categories
                if (!IS_SUPER_ADMIN && USER_DEPARTMENT_ID) {
                    if (deptSelect) {
                        // Set the department if it's a hidden field
                        if (deptSelect.type === 'hidden') {
                            deptSelect.value = USER_DEPARTMENT_ID;
                        } else {
                            // If it's a select dropdown, set the value
                            deptSelect.value = USER_DEPARTMENT_ID;
                        }
                        // Load categories for the department
                        updateItemTableCategoryOptions();
                    }
                } else if (deptSelect) {
                    // For super admins, handle category loading based on selected department
                    if (catSelect) {
                        catSelect.innerHTML = '<option value="">Select Category</option>';
                        catSelect.disabled = true;
                    }
                    if (hint) {
                        hint.textContent = 'Please choose a department first to see its categories.';
                    }
                    // Load categories for currently selected department
                    if (deptSelect.value) {
                        updateItemTableCategoryOptions();
                    }
                }
            } catch (e) {
                console.error('Error in openAddItemTableModal:', e);
            }
        }, 350);
    }

    function closeAddItemTableModal() {
        const modal = document.getElementById('addItemTableModal');
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            modal.style.display = 'none';
        }, 400);
        document.getElementById('addItemTableForm').reset();
        removeTableImagePreview();
        
        // Reset consumable checkbox and show priority group
        const consumableCheckbox = document.getElementById('itemTableConsumable');
        const priorityGroup = document.getElementById('priorityGroup');
        const prioritySelect = document.getElementById('itemTablePriority');
        if (consumableCheckbox) {
            consumableCheckbox.checked = false;
        }
        if (priorityGroup) {
            priorityGroup.style.display = 'block';
        }
        if (prioritySelect) {
            prioritySelect.setAttribute('required', 'required');
        }
        
        const catSelect = document.getElementById('itemTableCategory');
        const hint = document.getElementById('itemTableCategoryHint');
        if (catSelect) {
            catSelect.innerHTML = '<option value="">Select Category</option>';
            catSelect.disabled = true;
        }
        if (hint) {
            hint.textContent = 'Please choose a department first to see its categories.';
        }
    }

    function previewTableImage(input) {
        const file = input.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewTableImg').src = e.target.result;
                document.getElementById('tableImagePreview').style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    }

    function removeTableImagePreview() {
        document.getElementById('itemTableImage').value = '';
        document.getElementById('tableImagePreview').style.display = 'none';
        document.getElementById('previewTableImg').src = '';
    }

    // Edit Item Table Functions
    async function openEditItemTableModal(tableId) {
        // Check if user is super admin or from the same department
        // We'll check department match after loading the table data

        const modalEl = document.getElementById('editItemTableModal');
        modalEl.style.display = 'flex';
        modalEl.classList.add('show');
        document.body.style.overflow = 'hidden';

        try {
            // Fetch item table data
            const response = await fetch(`crud.php?action=get_item_table&id=${tableId}`);
            const data = await response.json();

            if (data.success && data.item_table) {
                const table = data.item_table;
                
                // Check permissions: super admin or same department
                if (!IS_SUPER_ADMIN && table.department_name !== USER_DEPARTMENT) {
                    modal.warning('You can only edit item tables from your own department.');
                    closeEditItemTableModal();
                    return;
                }
                
                // Populate form fields
                document.getElementById('editItemTableId').value = table.id;
                document.getElementById('editItemTableName').value = table.table_name || '';
                document.getElementById('editItemTableDepartment').value = table.department_id || '';
                document.getElementById('editItemTableDescription').value = table.description || '';
                // Set category as hidden field (preserve existing category)
                document.getElementById('editItemTableCategory').value = table.category || '';

                // Show current image if exists
                const currentImageDiv = document.getElementById('currentTableImage');
                if (table.table_image_path) {
                    currentImageDiv.innerHTML = `
                        <p style="font-size: 12px; color: #6b7280; margin-bottom: 5px;">Current Image:</p>
                        <img src="${table.table_image_path}" alt="Current Image" style="max-width: 200px; max-height: 200px; border: 1px solid #e5e7eb; border-radius: 4px;">
                    `;
                } else {
                    currentImageDiv.innerHTML = '';
                }

                // Hide preview initially
                document.getElementById('editTableImagePreview').style.display = 'none';
            } else {
                modal.error('Failed to load item table data.');
                closeEditItemTableModal();
            }
        } catch (error) {
            console.error('Error loading item table:', error);
            modal.error('An error occurred while loading item table data.');
            closeEditItemTableModal();
        }
    }

    function closeEditItemTableModal() {
        const modal = document.getElementById('editItemTableModal');
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            modal.style.display = 'none';
        }, 400);
        document.getElementById('editItemTableForm').reset();
        removeEditTableImagePreview();
        document.getElementById('currentTableImage').innerHTML = '';
    }


    function previewEditTableImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewEditTableImg').src = e.target.result;
                document.getElementById('editTableImagePreview').style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function removeEditTableImagePreview() {
        document.getElementById('editItemTableImage').value = '';
        document.getElementById('editTableImagePreview').style.display = 'none';
        document.getElementById('previewEditTableImg').src = '';
    }

    function updateItemTable() {
        const tableId = document.getElementById('editItemTableId').value;
        const tableName = document.getElementById('editItemTableName').value.trim();
        const departmentId = document.getElementById('editItemTableDepartment').value;
        const category = document.getElementById('editItemTableCategory').value;
        const description = document.getElementById('editItemTableDescription').value.trim();

        // Validation
        if (!tableName) {
            modal.warning('Please enter an item table name.');
            return;
        }
        if (!departmentId) {
            modal.warning('Please select a department.');
            return;
        }

        const submitBtn = document.querySelector('#editItemTableModal .btn-submit');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Updating Item Table...';
        submitBtn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'update_item_table');
        formData.append('id', tableId);
        formData.append('table_name', tableName);
        formData.append('category', category);
        formData.append('department_id', departmentId);
        formData.append('description', description);

        // Add image if selected
        const imageFile = document.getElementById('editItemTableImage').files[0];
        if (imageFile) {
            formData.append('table_image', imageFile);
        }

        fetch('crud.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;

            if (data.success) {
                closeEditItemTableModal();
                modal.success(`Item Table Updated Successfully! ${tableName} has been updated.`);
                loadAllItems(); // Reload items to show updated table
            } else {
                modal.error(`Failed to Update Item Table - ${data.message || 'An error occurred while updating the item table.'}`);
            }
        })
        .catch(error => {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
            console.error('Error:', error);
            modal.error('An error occurred while updating the item table.');
        });
    }

    // Toggle priority visibility based on consumable checkbox
    function togglePriorityVisibility() {
        const consumableCheckbox = document.getElementById('itemTableConsumable');
        const priorityGroup = document.getElementById('priorityGroup');
        const prioritySelect = document.getElementById('itemTablePriority');
        
        if (consumableCheckbox && priorityGroup && prioritySelect) {
            if (consumableCheckbox.checked) {
                // Hide priority dropdown for consumable items
                priorityGroup.style.display = 'none';
                prioritySelect.removeAttribute('required');
            } else {
                // Show priority dropdown for non-consumable items
                priorityGroup.style.display = 'block';
                prioritySelect.setAttribute('required', 'required');
            }
        }
    }

    function addNewItemTable() {
        // Validate required fields (but skip priority if consumable)
        const isConsumable = document.getElementById('itemTableConsumable').checked;
        if (!isConsumable) {
        if (!validateRequiredFields('addItemTableForm')) {
            modal.warning('Missing Required Fields: Please fill in all required fields before adding the item table.');
            return;
            }
        } else {
            // For consumable, validate without priority
            const name = document.getElementById('itemTableName').value.trim();
            const category = document.getElementById('itemTableCategory').value;
            const department = document.getElementById('itemTableDepartment').value;
            
            if (!name || !category || !department) {
                modal.warning('Missing Required Fields: Please fill in Item Table Name, Category, and Department.');
                return;
            }
        }
        
        // Show loading state
        const submitBtn = document.querySelector('#addItemTableModal .btn-submit');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Adding Item Table...';
        submitBtn.disabled = true;
        
        const formData = new FormData();
        formData.append('action', 'add_item_table');
        formData.append('table_name', document.getElementById('itemTableName').value.trim());
        formData.append('category', document.getElementById('itemTableCategory').value);
        formData.append('department_id', document.getElementById('itemTableDepartment').value);
        
        // Check if consumable is checked (already declared above, reuse it)
        formData.append('is_consumable', isConsumable ? '1' : '0');
        
        // Only send priority if not consumable
        if (!isConsumable) {
            formData.append('priority', document.getElementById('itemTablePriority').value);
        } else {
            formData.append('priority', 'low'); // Default for consumable, but won't be used
        }
        
        formData.append('description', document.getElementById('itemTableDescription').value.trim());
        
        // Add image if selected
        const imageFile = document.getElementById('itemTableImage').files[0];
        if (imageFile) {
            formData.append('table_image', imageFile);
        }
        
        fetch('crud.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
            
            if (data.success) {
                const tableName = document.getElementById('itemTableName').value;
                closeAddItemTableModal();
                
                // Show appropriate message based on whether it's consumable, QR was auto-generated, or requires approval
                if (data.is_consumable) {
                    modal.success(`Consumable Item Table Added Successfully! ${tableName} has been created. Items in this table will have "Consumable" status and no QR codes.`);
                } else if (data.requires_approval) {
                    modal.success(`Item Table Added Successfully! ${tableName} has been created. QR code request has been submitted and is pending approval.`);
                } else {
                    modal.success(`Item Table Added Successfully! ${tableName} has been created with QR code.`);
                }
                
                loadAllItems(); // Reload items to show new table
            } else {
                modal.error(`Failed to Add Item Table - ${data.message || 'An error occurred while adding the item table.'}`);
            }
        })
        .catch(error => {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
            console.error('Error:', error);
            modal.error(`An error occurred while adding the item table. ${error.message || 'Please check the console for details.'}`);
        });
    }

    // Populate item table category options based on selected department
    function updateItemTableCategoryOptions() {
        const deptSelect = document.getElementById('itemTableDepartment');
        const catSelect = document.getElementById('itemTableCategory');
        const hint = document.getElementById('itemTableCategoryHint');
        if (!deptSelect || !catSelect) {
            console.warn('updateItemTableCategoryOptions: Missing elements');
            return;
        }
        // Get department ID - works for both select dropdown and hidden input
        const deptId = parseInt(deptSelect.value || '0', 10);
        catSelect.innerHTML = '<option value="">Select Category</option>';
        catSelect.disabled = true;
        if (hint) {
            // Update hint text based on whether department is selected
            if (deptId > 0) {
                hint.textContent = 'Loading categories…';
            } else if (deptSelect.type === 'hidden') {
                // For department heads, the department is already set
                hint.textContent = 'Select a category for this department.';
            } else {
                hint.textContent = 'Please choose a department first to see its categories.';
            }
        }
        if (!deptId || deptId <= 0) {
            console.warn('updateItemTableCategoryOptions: No department selected', deptId);
            // For department heads with hidden department field, they should always have a department
            // So if deptId is 0, it might be an error
            return;
        }
        let hasCategories = false;
        // Pre-populate from cached categories (ensures something shows immediately)
        if (typeof CATEGORIES_BY_DEPT !== 'undefined' && CATEGORIES_BY_DEPT[deptId]) {
            CATEGORIES_BY_DEPT[deptId].forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                catSelect.appendChild(opt);
                hasCategories = true;
            });
        }
        catSelect.disabled = !hasCategories;
        if (hint && hasCategories) {
            hint.textContent = 'Select a category for this department.';
        }
        // Fetch latest categories filtered by department to avoid stale cache
        fetch(`crud.php?action=get_categories&department_id=${deptId}`)
            .then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then(data => {
                if (data && data.success && Array.isArray(data.categories)) {
                    if (data.categories.length === 0) {
                        console.log(`No categories found for department ID: ${deptId}`);
                        if (typeof CATEGORIES_BY_DEPT !== 'undefined' && CATEGORIES_BY_DEPT[deptId]) {
                            catSelect.disabled = CATEGORIES_BY_DEPT[deptId].length === 0;
                            if (hint) {
                                hint.textContent = CATEGORIES_BY_DEPT[deptId].length === 0
                                    ? 'No categories found for this department yet. Add one via the Categories page.'
                                    : 'Select a category for this department.';
                            }
                            return; // already populated from cache above
                        }
                    }
                    // Replace current options (keep placeholder)
                    catSelect.innerHTML = '<option value="">Select Category</option>';
                    data.categories.forEach(name => {
                        const opt = document.createElement('option');
                        opt.value = name;
                        opt.textContent = name;
                        catSelect.appendChild(opt);
                    });
                    catSelect.disabled = data.categories.length === 0;
                    if (hint) {
                        hint.textContent = data.categories.length === 0
                            ? 'No categories found for this department yet. Add one via the Categories page.'
                            : 'Select a category for this department.';
                    }
                } else {
                    console.error('Invalid response from get_categories:', data);
                    if (typeof CATEGORIES_BY_DEPT !== 'undefined' && CATEGORIES_BY_DEPT[deptId]) {
                        catSelect.innerHTML = '<option value="">Select Category</option>';
                        CATEGORIES_BY_DEPT[deptId].forEach(name => {
                            const opt = document.createElement('option');
                            opt.value = name;
                            opt.textContent = name;
                            catSelect.appendChild(opt);
                        });
                        catSelect.disabled = CATEGORIES_BY_DEPT[deptId].length === 0;
                        if (hint) {
                            hint.textContent = CATEGORIES_BY_DEPT[deptId].length === 0
                                ? 'No categories found for this department yet. Add one via the Categories page.'
                                : 'Select a category for this department.';
                        }
                    }
                }
            })
            .catch(err => {
                console.error('Failed to load categories by department:', err);
                // Fallback: try to use CATEGORIES_BY_DEPT if available
                if (typeof CATEGORIES_BY_DEPT !== 'undefined' && CATEGORIES_BY_DEPT[deptId]) {
                    catSelect.innerHTML = '<option value="">Select Category</option>';
                    CATEGORIES_BY_DEPT[deptId].forEach(name => {
                        const opt = document.createElement('option');
                        opt.value = name;
                        opt.textContent = name;
                        catSelect.appendChild(opt);
                    });
                    catSelect.disabled = CATEGORIES_BY_DEPT[deptId].length === 0;
                    if (hint) {
                        hint.textContent = CATEGORIES_BY_DEPT[deptId].length === 0
                            ? 'No categories found for this department yet. Add one via the Categories page.'
                            : 'Select a category for this department.';
                    }
                }
            });
    }

    function loadEditItemCategories(deptId, selectedCategory) {
        const catSelect = document.getElementById('editItemCategory');
        if (!catSelect) {
            console.warn('loadEditItemCategories: category select not found');
            return;
        }

        catSelect.innerHTML = '<option value="">Select Category</option>';
        catSelect.disabled = true;

        if (!deptId || deptId <= 0) {
            return;
        }

        if (typeof CATEGORIES_BY_DEPT !== 'undefined' && CATEGORIES_BY_DEPT[deptId]) {
            CATEGORIES_BY_DEPT[deptId].forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                catSelect.appendChild(opt);
            });
            catSelect.disabled = CATEGORIES_BY_DEPT[deptId].length === 0;
            if (selectedCategory) {
                catSelect.value = selectedCategory;
            }
        }

        fetch(`crud.php?action=get_categories&department_id=${deptId}`)
            .then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then(data => {
                if (data && data.success && Array.isArray(data.categories)) {
                    catSelect.innerHTML = '<option value="">Select Category</option>';
                    data.categories.forEach(name => {
                        const opt = document.createElement('option');
                        opt.value = name;
                        opt.textContent = name;
                        catSelect.appendChild(opt);
                    });
                    catSelect.disabled = data.categories.length === 0;
                    if (selectedCategory) {
                        catSelect.value = selectedCategory;
                        if (catSelect.value !== selectedCategory) {
                            const opt = document.createElement('option');
                            opt.value = selectedCategory;
                            opt.textContent = selectedCategory;
                            catSelect.appendChild(opt);
                            catSelect.value = selectedCategory;
                        }
                    }
                }
            })
            .catch(err => {
                console.error('Failed to load edit categories:', err);
            });
    }

    // Generate QR Code for existing item table
    async function generateQRForItemTable(tableId, tableName) {
        // Block viewers from generating/regenerating QR codes
        try {
            if (document.body && document.body.dataset && document.body.dataset.userRole === 'viewer') {
                if (typeof modal !== 'undefined' && modal && typeof modal.warning === 'function') {
                    modal.warning('Viewers cannot generate QR codes.');
                }
                return;
            }
        } catch (e) { /* no-op */ }
        
        // Check if user has permission (super admin or same department)
        try {
            const tableResponse = await fetch(`item_table_inventory_api.php?action=get_item_table&table_id=${tableId}`);
            const tableData = await tableResponse.json();
            
            if (tableData.success && tableData.item_table) {
                const table = tableData.item_table;
                // Normalize comparison: trim and case-insensitive
                const tableDept = (table.department_name || '').trim();
                const userDept = (USER_DEPARTMENT || '').trim();
                if (!IS_SUPER_ADMIN && tableDept.toLowerCase() !== userDept.toLowerCase()) {
                    modal.warning('You can only generate QR codes for item tables from your own department.');
                    return;
                }
            }
        } catch (error) {
            console.error('Error checking table permissions:', error);
            modal.error('Error checking permissions. Please try again.');
            return;
        }
        
        // Close any open menus
        document.querySelectorAll('.card-action-menu').forEach(menu => menu.classList.remove('show'));
        
        if (!confirm(`Generate QR code for "${tableName}"?`)) {
            return;
        }

        try {
            modal.show('Generating QR code...', 'info');
            
            const response = await fetch('item_table_inventory_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'generate_qr_code',
                    item_table_id: parseInt(tableId)
                })
            });

            const data = await response.json();

            if (data.success) {
                modal.success(`QR code generated successfully for "${tableName}"!`);
                
                // Offer to download QR code
                if (data.qr_image_url || data.qr_path) {
                    if (confirm('QR code generated! Would you like to download it now?')) {
                        downloadQRCodeImage(data.qr_image_url || data.qr_path, tableName, tableId);
                    }
                }
                
                // Refresh the cards view to update QR status
                updateCardView();
            } else {
                modal.error('Error generating QR code: ' + data.message);
            }
        } catch (error) {
            console.error('Error generating QR code:', error);
            modal.error('Error generating QR code. Please try again.');
        }
    }

    // Download QR Code for existing item table
    async function downloadQRCode(tableId, tableName) {
        // Close any open menus
        document.querySelectorAll('.card-action-menu').forEach(menu => menu.classList.remove('show'));
        
        try {
            // Get QR code info from API
            const response = await fetch(`item_table_inventory_api.php?action=get_item_table&table_id=${tableId}`);
            const data = await response.json();
            
            // Check if user has permission (super admin or same department)
            if (data.success && data.item_table) {
                const table = data.item_table;
                // Normalize comparison: trim and case-insensitive
                const tableDept = (table.department_name || '').trim();
                const userDept = (USER_DEPARTMENT || '').trim();
                if (!IS_SUPER_ADMIN && tableDept.toLowerCase() !== userDept.toLowerCase()) {
                    modal.warning('You can only download QR codes for item tables from your own department.');
                    return;
                }
            }
            
            if (data.success && data.item_table.qr_code) {
                // Find the QR code file
                const qrPath = `qr_codes/qr_table_${tableId}_*.png`;
                // Try to find the actual file
                const qrUrl = `qr_codes/qr_table_${tableId}_${Date.now()}.png`; // Approximate path
                
                // Get the actual QR code path from database or generate URL
                const qrImageUrl = `qr_codes/qr_table_${tableId}_${Math.floor(Date.now() / 1000)}.png`;
                
                // Get the actual QR code file path
                fetch(`item_table_inventory_api.php?action=get_qr_path&table_id=${tableId}`)
                    .then(res => res.json())
                    .then(qrData => {
                        if (qrData.success && qrData.qr_url) {
                            downloadQRCodeImage(qrData.qr_url, tableName, tableId);
                        } else if (qrData.success && qrData.qr_path) {
                            downloadQRCodeImage(qrData.qr_path, tableName, tableId);
                        } else {
                            modal.error('QR code file not found. Please regenerate the QR code.');
                        }
                    })
                    .catch((error) => {
                        console.error('Error getting QR path:', error);
                        modal.error('Error downloading QR code. Please try again.');
                    });
            } else {
                modal.error('No QR code found for this item table. Please generate one first.');
            }
        } catch (error) {
            console.error('Error downloading QR code:', error);
            modal.error('Error downloading QR code. Please try again.');
        }
    }

    // Helper function to download QR code image
    function downloadQRCodeImage(qrUrl, tableName, tableId) {
        if (!qrUrl) {
            modal?.error?.('QR code URL not available.');
            return;
        }

        const normalizeUrl = (url) => {
            if (url.startsWith('http://') || url.startsWith('https://')) {
                return url;
            }
            const baseUrl = window.location.origin;
            let basePath = window.location.pathname;
            basePath = basePath.substring(0, basePath.lastIndexOf('/') + 1);
            return baseUrl + basePath + url.replace(/^\/+/, '');
        };

        const sourceUrl = normalizeUrl(qrUrl);
        const image = new Image();
        image.crossOrigin = 'anonymous';

        image.onload = () => {
            try {
                const inchesToPixels = (inches) => Math.round(inches * 96); // 96 DPI
                const qrSize = inchesToPixels(3); // 3 inches
                const padding = inchesToPixels(0.1); // small margin
                const canvasSize = qrSize + padding * 2;

                const canvas = document.createElement('canvas');
                canvas.width = canvasSize;
                canvas.height = canvasSize;
                const ctx = canvas.getContext('2d');
                if (!ctx) throw new Error('Canvas context unavailable');

                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvasSize, canvasSize);

                ctx.drawImage(
                    image,
                    padding,
                    padding,
                    qrSize,
                    qrSize
                );

                const downloadLink = document.createElement('a');
                downloadLink.href = canvas.toDataURL('image/png');
                downloadLink.download = `item-table-${tableName.replace(/\s+/g, '-').toLowerCase()}-${tableId}-qr.png`;
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);
            } catch (err) {
                console.error('Error processing QR for print:', err);
                modal?.error?.('Error preparing QR code for printing.');
            }
        };

        image.onerror = () => {
            console.warn('QR image failed to load, downloading original image.', sourceUrl);
            const fallbackLink = document.createElement('a');
            fallbackLink.href = sourceUrl;
            fallbackLink.download = `item-table-${tableName.replace(/\s+/g, '-').toLowerCase()}-${tableId}-qr.png`;
            document.body.appendChild(fallbackLink);
            fallbackLink.click();
            document.body.removeChild(fallbackLink);
        };

        image.src = sourceUrl;
    }

    function loadItemTables() {
        return fetch('crud.php?action=get_item_tables')
            .then(response => response.json())
            .then(data => {
                const itemTableSelect = document.getElementById('itemTable');
                itemTableSelect.innerHTML = '<option value="">Select Item Table</option>';
                
                if (data.success && data.item_tables) {
                    // Filter item tables:
                    // - Super admins see all item tables
                    // - Department heads (admin but not super admin) only see item tables from their own department
                    // - Regular users only see item tables from their own department
                    const tables = IS_SUPER_ADMIN ? data.item_tables : data.item_tables.filter(t => String(t.department_name) === String(USER_DEPARTMENT));
                    tables.forEach(table => {
                        const option = document.createElement('option');
                        option.value = table.id;
                        option.textContent = table.table_name;
                        option.setAttribute('data-table-name', table.table_name);
                        option.setAttribute('data-category', table.category);
                        option.setAttribute('data-department-id', table.department_id);
                        option.setAttribute('data-department-name', table.department_name);
                        itemTableSelect.appendChild(option);
                    });
                }
            })
            .catch(error => {
                console.error('Error loading item tables:', error);
            });
    }
    function onItemTableChange() {
        try {
            const itemTableSelect = document.getElementById('itemTable');
            if (!itemTableSelect) {
                console.error('itemTable select element not found');
                return;
            }
            
            const selectedOption = itemTableSelect.options[itemTableSelect.selectedIndex];
            if (!selectedOption) {
                console.error('No selected option found');
                return;
            }
        
        if (selectedOption.value === '') {
            // Reset fields if no table selected
            const itemNameField = document.getElementById('itemName');
            if (itemNameField) {
                itemNameField.value = '';
                itemNameField.readOnly = false;
                itemNameField.style.backgroundColor = '';
                itemNameField.style.color = '';
                itemNameField.title = '';
                itemNameField.placeholder = 'Enter item name';
                
                // Clear any validation errors
                const formGroup = itemNameField.closest('.form-group');
                if (formGroup) {
                    formGroup.classList.remove('has-error');
                    const errorText = formGroup.querySelector('.error-text');
                    if (errorText) {
                        errorText.remove();
                    }
                }
            }
            
            const departmentField = document.getElementById('itemDepartment');
            if (departmentField) {
                departmentField.value = '';
                departmentField.style.backgroundColor = '';
                departmentField.style.color = '';
                departmentField.title = '';
            }
            
            const categoryField = document.getElementById('itemCategory');
            if (categoryField) {
                categoryField.value = '';
                categoryField.style.backgroundColor = '';
                categoryField.style.color = '';
                categoryField.title = '';
            }
            
            const departmentIdField = document.getElementById('itemDepartmentId');
            if (departmentIdField) {
                departmentIdField.value = '';
            }
            return;
        }
        
        // Get table data from selected option
        const tableName = selectedOption.getAttribute('data-table-name');
        const category = selectedOption.getAttribute('data-category');
        const departmentId = selectedOption.getAttribute('data-department-id');
        const departmentName = selectedOption.getAttribute('data-department-name');
        
        // Validate that the table belongs to user's department (super admins and admins can select all)
        const userDept = USER_DEPARTMENT || '';
        if (userDept && !IS_ADMIN && !IS_SUPER_ADMIN && String(departmentName) !== String(userDept)) {
            // Reset selection and show warning
            itemTableSelect.value = '';
            modal.warning('Action Not Allowed: You can only select item tables from your own department.');
            return;
        }
        
        // Auto-fill item name based on table name (remove "Table" suffix)
        const baseItemName = tableName.replace(' Table', '').replace(' table', '');
        const itemNameField = document.getElementById('itemName');
        if (itemNameField) {
            // Set the base name as placeholder and initial value
            itemNameField.value = baseItemName;
            itemNameField.readOnly = false; // Allow editing
            itemNameField.placeholder = `e.g., ${baseItemName} - Logitech, ${baseItemName} - Razer, etc.`;
            
            // Add event listener to validate that name starts with base name
            itemNameField.addEventListener('input', function() {
                validateItemName(this, baseItemName);
            });
        }
        
        // Auto-fill department name and ID
        const departmentField = document.getElementById('itemDepartment');
        if (departmentField) {
            departmentField.value = departmentName;
        }
        
        const departmentIdField = document.getElementById('itemDepartmentId');
        if (departmentIdField) {
            departmentIdField.value = departmentId;
        }
        
        // Auto-fill category
        const categoryField = document.getElementById('itemCategory');
        if (categoryField) {
            categoryField.value = category;
        }
        
        // Add visual indication that fields are auto-filled
        if (itemNameField) {
            itemNameField.style.backgroundColor = '#fff3cd'; // Light yellow background
            itemNameField.style.color = '#856404'; // Darker yellow text
            itemNameField.title = `Item name must start with "${baseItemName}" (e.g., ${baseItemName} - Brand Name)`;
        }
        
        if (departmentField) {
            departmentField.style.backgroundColor = '#f8f9fa';
            departmentField.style.color = '#6c757d';
            departmentField.title = 'Department is automatically set based on selected table';
        }
        
        if (categoryField) {
            categoryField.style.backgroundColor = '#f8f9fa';
            categoryField.style.color = '#6c757d';
            categoryField.title = 'Category is automatically set based on selected table';
        }
    } catch (error) {
        console.error('onItemTableChange error:', error);
    }
}

    function closeEditItemModal() {
        const modal = document.getElementById('editItemModal');
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            modal.style.display = 'none';
        }, 400);
        document.getElementById('editItemForm').reset();
        // Reset image preview
        const editItemImagePreview = document.getElementById('editItemImagePreview');
        const editItemImage = document.getElementById('editItemImage');
        const currentItemImage = document.getElementById('currentItemImage');
        if (editItemImagePreview) editItemImagePreview.style.display = 'none';
        if (editItemImage) editItemImage.value = '';
        if (currentItemImage) currentItemImage.innerHTML = '';
        // Reset all fields
        const statusSelect = document.getElementById('editItemStatus');
        const consumableNote = document.getElementById('consumableStatusNote');
        const itemNameInput = document.getElementById('editItemName');
        const departmentSelect = document.getElementById('editItemDepartment');
        const categorySelect = document.getElementById('editItemCategory');
        const locationSelect = document.getElementById('editItemLocation');
        const quantityInput = document.getElementById('editItemQuantity');
        
        if (statusSelect) {
            statusSelect.disabled = false;
            statusSelect.style.backgroundColor = '';
            statusSelect.style.cursor = '';
        }
        if (itemNameInput) {
            itemNameInput.disabled = false;
            itemNameInput.style.backgroundColor = '';
            itemNameInput.style.cursor = '';
        }
        if (departmentSelect) {
            departmentSelect.disabled = false;
            departmentSelect.style.backgroundColor = '';
            departmentSelect.style.cursor = '';
        }
        if (categorySelect) {
            categorySelect.disabled = false;
            categorySelect.style.backgroundColor = '';
            categorySelect.style.cursor = '';
        }
        if (locationSelect) {
            locationSelect.disabled = false;
            locationSelect.style.backgroundColor = '';
            locationSelect.style.cursor = '';
        }
        if (quantityInput) {
            quantityInput.removeAttribute('max');
            quantityInput.removeAttribute('data-original-quantity');
            // Remove quantity validation listener if it exists
            if (window.consumableQuantityValidator) {
                quantityInput.removeEventListener('input', window.consumableQuantityValidator);
            }
        }
        if (consumableNote) {
            consumableNote.style.display = 'none';
        }
    }

   // Updated Add Item Function
// Update the addNewItem function in your department.php

function addNewItem() {
    try {
        // Validate required fields
        if (!validateRequiredFields('addItemForm')) {
            modal.warning('Missing Required Fields: Please fill in all required fields before adding the item.');
            return;
        }
    
    // Show loading state
    const submitBtn = document.querySelector('#addItemModal .btn-submit');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Generating QR Code...';
    submitBtn.disabled = true;
    
    const formData = new FormData();
    
    // Get all form elements with null checks
    const itemTable = document.getElementById('itemTable');
    const itemName = document.getElementById('itemName');
    const itemDepartmentId = document.getElementById('itemDepartmentId');
    const itemCategory = document.getElementById('itemCategory');
    const itemQuantity = document.getElementById('itemQuantity');
    const itemLocation = document.getElementById('itemLocation');
    const itemStatus = document.getElementById('itemStatus');
    const itemDescription = document.getElementById('itemDescription');
    
    if (!itemTable || !itemName || !itemDepartmentId || !itemCategory || !itemQuantity || !itemLocation || !itemStatus || !itemDescription) {
        console.error('Missing form elements:', {
            itemTable: !!itemTable,
            itemName: !!itemName,
            itemDepartmentId: !!itemDepartmentId,
            itemCategory: !!itemCategory,
            itemQuantity: !!itemQuantity,
            itemLocation: !!itemLocation,
            itemStatus: !!itemStatus,
            itemDescription: !!itemDescription
        });
        modal.error('Form elements not found. Please refresh the page and try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        return;
    }
    
    // Validate required values
    if (!itemTable.value || !itemName.value.trim() || !itemDepartmentId.value || !itemCategory.value || !itemQuantity.value || !itemLocation.value || !itemStatus.value) {
        modal.error('Please fill in all required fields.');
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        return;
    }
    
    // Validate item name format
    const selectedOption = itemTable.options[itemTable.selectedIndex];
    const tableName = selectedOption.getAttribute('data-table-name');
    const baseItemName = tableName.replace(' Table', '').replace(' table', '');
    
    if (!validateItemName(itemName, baseItemName)) {
        modal.error(`Item name must start with "${baseItemName}" (e.g., ${baseItemName} - Brand Name)`);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
        return;
    }
    
    formData.append('action', 'add');
    formData.append('item_table_id', itemTable.value);
    formData.append('name', itemName.value.trim());
    formData.append('department_id', itemDepartmentId.value);
    formData.append('category', itemCategory.value);
    formData.append('quantity', itemQuantity.value);
    formData.append('location', itemLocation.value.trim());
    formData.append('status', itemStatus.value);
    formData.append('description', itemDescription.value.trim());
    
    // Add image only if quantity is 1
    const itemQuantityValue = parseInt(itemQuantity.value) || 0;
    if (itemQuantityValue === 1) {
        const itemImageInput = document.getElementById('itemImage');
        if (itemImageInput && itemImageInput.files && itemImageInput.files[0]) {
            formData.append('image', itemImageInput.files[0]);
        }
    }
    
    // Debug: Log form data
    console.log('Form data being sent:', {
        item_table_id: itemTable.value,
        name: itemName.value.trim(),
        department_id: itemDepartmentId.value,
        category: itemCategory.value,
        quantity: itemQuantity.value,
        location: itemLocation.value.trim(),
        status: itemStatus.value,
        description: itemDescription.value.trim(),
        has_image: itemQuantityValue === 1 && document.getElementById('itemImage')?.files?.length > 0
    });
    
    fetch('crud.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        console.log('Response headers:', response.headers);
        
        if (!response.ok) {
            console.error('HTTP Error:', response.status, response.statusText);
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return response.text().then(text => {
            console.log('Raw response:', text);
            try {
                const data = JSON.parse(text);
                console.log('Parsed JSON:', data);
                return data;
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                throw new Error('Invalid JSON response from server: ' + text.substring(0, 200));
            }
        });
    })
    .then(data => {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
        
        if (data.success) {
            // Store item name before closing modal
            const itemName = document.getElementById('itemName').value;
            const quantity = document.getElementById('itemQuantity').value;
            closeAddItemModal();
            
            // Show success with individual items info
            let successMessage = `Items Added Successfully! ${itemName} x${quantity} - Created ${data.success_count} individual items`;
            
            if (data.failed_count > 0) {
                successMessage += ` (${data.failed_count} failed)`;
            }
            
            // Add QR code info
            if (data.qr_success_count) {
                successMessage += ` | QR codes generated for ${data.qr_success_count} items`;
            } else if (data.qr_warning) {
                successMessage += ` | ${data.qr_warning}`;
            }
            
            modal.success(successMessage);
            
            // Show detailed info if multiple items
            if (data.success_count > 1) {
                setTimeout(() => {
                    const itemIds = data.created_items ? data.created_items.join(', ') : 'N/A';
                    modal.info(`Individual Items Created: ${data.success_count} separate entries of "${itemName}" have been added to the database. Each item has quantity = 1 and can be managed independently. Item IDs: ${itemIds}`);
                }, 2000);
            }
            
            loadAllItems(); // Reload items
        } else {
            modal.error(`Failed to Add Items - ${data.message || 'An error occurred while adding the items.'}`);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
        modal.error(`Connection Error - Unable to connect to the server. Please check your internet connection. Error details: ${error.message}`);
    });
    } catch (error) {
        console.error('addNewItem error:', error);
        modal.error(`Unexpected error: ${error.message}`);
    }
}

// Function to view generated QR code
function viewGeneratedQR(itemId, qrPath) {
    const item = allItems.find(i => i.id == itemId);
    if (!item) {
        // Reload items and try again
        loadAllItems();
        setTimeout(() => {
            const reloadedItem = allItems.find(i => i.id == itemId);
            if (reloadedItem) {
                viewItem(itemId);
            }
        }, 500);
    } else {
        viewItem(itemId);
    }
}

// Update the generateQrForDetail function to use database QR code
function generateQrForDetail(item) {
    try {
        const img = document.getElementById('detailQrImage');
        
        // Use the saved QR code if available
        if (item.qr_code) {
            img.src = item.qr_code;
            img.dataset.downloadName = `item-${item.id}-qr.png`;
        } else {
            // Fallback: Generate QR code via API if not in database
            const payload = JSON.stringify({ 
                id: item.id, 
                name: item.name,
                department: item.department_name,
                location: item.location
            });
            const api = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(payload)}`;
            img.src = api;
            img.dataset.downloadName = `item-${item.id}-qr.png`;
        }
    } catch (e) {
        console.error('QR generation failed', e);
    }
}

function openBorrowHistoryModal(itemId) {
    const overlay = document.getElementById('borrowHistoryModal');
    const body = document.getElementById('borrowHistoryBody');
    if (!overlay || !body) return;
    overlay.style.display = 'flex';
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
    body.innerHTML = 'Loading history...';
    fetch(`crud.php?action=get_item_borrow_history&item_id=${encodeURIComponent(itemId)}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                body.innerHTML = '<div style="color:#e53e3e;">Failed to load history.</div>';
                return;
            }
            const history = Array.isArray(data.history) ? data.history : [];
            if (history.length === 0) {
                body.innerHTML = '<div style="color:#718096;">No borrow history for this item.</div>';
                return;
            }
            const rows = history.map((h, i) => `
                <tr style="background:${i % 2 === 0 ? '#fff' : '#fafafa'};">
                    <td style="padding:10px;border-bottom:1px solid #edf2f7;">${escapeHtml(h.borrow_id || '')}</td>
                    <td style="padding:10px;border-bottom:1px solid #edf2f7;">${escapeHtml(h.borrower_name || '')}</td>
                    <td style="padding:10px;border-bottom:1px solid #edf2f7;">${badge(h.status)}</td>
                    <td style="padding:10px;border-bottom:1px solid #edf2f7;">${formatDateOnly(h.borrow_date)}</td>
                    <td style="padding:10px;border-bottom:1px solid #edf2f7;">${formatDateOnly(h.due_date)}</td>
                    <td style="padding:10px;border-bottom:1px solid #edf2f7;">${h.return_date ? formatDateOnly(h.return_date) : '-'}</td>
                </tr>
            `).join('');
            body.innerHTML = `
                <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                    <table style="width:100%;border-collapse:separate;border-spacing:0;font-size:13px;">
                        <thead>
                            <tr style="background:#f8fafc;color:#2d3748;">
                                <th style="text-align:left;padding:12px 10px;border-bottom:1px solid #e2e8f0;">Borrow ID</th>
                                <th style="text-align:left;padding:12px 10px;border-bottom:1px solid #e2e8f0;">Borrower</th>
                                <th style="text-align:left;padding:12px 10px;border-bottom:1px solid #e2e8f0;">Status</th>
                                <th style="text-align:left;padding:12px 10px;border-bottom:1px solid #e2e8f0;">Borrowed</th>
                                <th style="text-align:left;padding:12px 10px;border-bottom:1px solid #e2e8f0;">Due</th>
                                <th style="text-align:left;padding:12px 10px;border-bottom:1px solid #e2e8f0;">Returned</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>`;
        })
        .catch(() => { body.innerHTML = '<div style="color:#e53e3e;">Failed to load history.</div>'; });
}

function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]);});
}

function closeBorrowHistoryModal() {
    const overlay = document.getElementById('borrowHistoryModal');
    if (!overlay) return;
    overlay.classList.remove('show');
    document.body.style.overflow = 'auto';
    setTimeout(() => { overlay.style.display = 'none'; }, 300);
}


// Enhanced download function with text overlay
function formatDateOnly(dateString){
    if (!dateString) return '';
    const d = new Date(dateString);
    if (isNaN(d)) return dateString;
    return d.toLocaleDateString();
}
function badge(status){
    const s = (status || '').toString().toLowerCase();
    let bg = '#e2e8f0', fg = '#2d3748';
    if (s === 'active') { bg = 'rgba(16,185,129,0.15)'; fg = '#065f46'; }
    else if (s === 'returned') { bg = 'rgba(59,130,246,0.15)'; fg = '#1e40af'; }
    else if (s === 'overdue') { bg = 'rgba(239,68,68,0.15)'; fg = '#991b1b'; }
    return `<span style="background:${bg};color:${fg};padding:4px 10px;border-radius:999px;font-weight:600;font-size:12px;">${status || ''}</span>`;
}
// OLD downloadQrFromDetail function - keeping for backward compatibility but new one below is used
function downloadQrFromDetail_OLD() {
    const img = document.getElementById('detailQrImage');
    if (!img || !img.src) {
        modal.error('QR code not available');
        return;
    }
    
    // Get current item data
    const currentItem = window.currentItemData;
    if (!currentItem) {
        console.error('No item data available');
        modal.error('Item data not available');
        return;
    }
    
    // Check if user has permission (super admin or same department)
    if (!IS_SUPER_ADMIN && USER_DEPARTMENT && currentItem.department_name !== USER_DEPARTMENT) {
        modal.warning('You can only download QR codes for items from your own department.');
        return;
    }
    
    // Create canvas to add text below QR code
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    
    // Load the QR code image
    const qrImg = new Image();
    qrImg.crossOrigin = 'anonymous';
    qrImg.onload = function() {
        // Set canvas size (QR code + space for text)
        const qrSize = 500; // QR code size
        const textHeight = 60; // Space for text
        canvas.width = qrSize;
        canvas.height = qrSize + textHeight;
        
        // Fill with white background
        ctx.fillStyle = 'white';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        // Draw QR code
        ctx.drawImage(qrImg, 0, 0, qrSize, qrSize);
        
        // Add item code text below QR code
        const itemCode = currentItem.item_code || 'ITEM-' + currentItem.id;
        ctx.fillStyle = 'black';
        ctx.font = 'bold 24px Arial';
        ctx.textAlign = 'center';
        ctx.fillText(itemCode, canvas.width / 2, qrSize + 35);
        
        // Convert canvas to blob and download
        canvas.toBlob(function(blob) {
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `qr-${itemCode}.png`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            
            modal.success('QR Code Downloaded - QR code with item code has been downloaded to your device.');
        }, 'image/png');
    };
    
    qrImg.onerror = function() {
        console.error('Failed to load QR code image');
        // Fallback to original download
        const link = document.createElement('a');
        link.href = img.src;
        link.download = img.dataset.downloadName || 'qr-code.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        modal.success('QR Code Downloaded - QR code has been downloaded to your device.');
    };
    
    qrImg.src = img.src;
}

    // Enhanced Edit item function
    function editItem(id) {
        // Find item data and populate edit modal
        const item = allItems.find(item => item.id === id);
        // Permission check: only super admins can edit items from other departments
        if (item && !IS_SUPER_ADMIN && USER_DEPARTMENT && item.department_name !== USER_DEPARTMENT) {
            modal.warning(`Action Not Allowed - You can only edit items in your own department. Your department: ${USER_DEPARTMENT}. Item dept: ${item.department_name}.`);
            return;
        }
        if (item) {
            document.getElementById('editItemId').value = item.id;
            document.getElementById('editItemName').value = item.name;
            document.getElementById('editItemDepartment').value = item.department_id;
            loadEditItemCategories(item.department_id, item.category);
            document.getElementById('editItemQuantity').value = item.quantity;
            
            // Set location dropdown - try to match with existing location or set to first option
            const locationSelect = document.getElementById('editItemLocation');
            const currentLocation = item.location;
            
            // Try to find exact match first
            let foundMatch = false;
            for (let option of locationSelect.options) {
                if (option.value === currentLocation) {
                    option.selected = true;
                    foundMatch = true;
                    break;
                }
            }
            
            // If no exact match, try to find partial match
            if (!foundMatch) {
                for (let option of locationSelect.options) {
                    if (option.value.includes(currentLocation) || currentLocation.includes(option.value)) {
                        option.selected = true;
                        foundMatch = true;
                        break;
                    }
                }
            }
            
            // If still no match, set to first option (Select Location)
            if (!foundMatch) {
                locationSelect.selectedIndex = 0;
            }
            
            // Check if item belongs to a consumable table
            const statusSelect = document.getElementById('editItemStatus');
            const consumableNote = document.getElementById('consumableStatusNote');
            const itemNameInput = document.getElementById('editItemName');
            const departmentSelect = document.getElementById('editItemDepartment');
            const categorySelect = document.getElementById('editItemCategory');
            // locationSelect already declared above, reuse it
            const quantityInput = document.getElementById('editItemQuantity');
            
            if (item.item_table_id) {
                // Check if parent table is consumable
                fetch(`crud.php?action=check_item_table_consumable&item_table_id=${item.item_table_id}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.is_consumable) {
                            // Item is consumable - disable status, name, department, category, and location
                            statusSelect.value = 'Consumable';
                            statusSelect.disabled = true;
                            statusSelect.style.backgroundColor = '#f3f4f6';
                            statusSelect.style.cursor = 'not-allowed';
                            
                            itemNameInput.disabled = true;
                            itemNameInput.style.backgroundColor = '#f3f4f6';
                            itemNameInput.style.cursor = 'not-allowed';
                            
                            departmentSelect.disabled = true;
                            departmentSelect.style.backgroundColor = '#f3f4f6';
                            departmentSelect.style.cursor = 'not-allowed';
                            
                            categorySelect.disabled = true;
                            categorySelect.style.backgroundColor = '#f3f4f6';
                            categorySelect.style.cursor = 'not-allowed';
                            
                            locationSelect.disabled = true;
                            locationSelect.style.backgroundColor = '#f3f4f6';
                            locationSelect.style.cursor = 'not-allowed';
                            
                            // Quantity can only be decreased - set max to current quantity
                            const currentQuantity = parseInt(item.quantity) || 1;
                            quantityInput.max = currentQuantity;
                            quantityInput.setAttribute('data-original-quantity', currentQuantity);
                            
                            // Store validation function reference for cleanup
                            if (!window.consumableQuantityValidator) {
                                window.consumableQuantityValidator = function() {
                                    const newQty = parseInt(this.value) || 0;
                                    const origQty = parseInt(this.getAttribute('data-original-quantity')) || 0;
                                    if (origQty > 0 && newQty > origQty) {
                                        this.value = origQty;
                                        modal.warning('Quantity cannot be increased for consumable items. You can only decrease the quantity.');
                                    }
                                };
                            }
                            
                            // Remove existing listener if any, then add new one
                            quantityInput.removeEventListener('input', window.consumableQuantityValidator);
                            quantityInput.addEventListener('input', window.consumableQuantityValidator);
                            
                            consumableNote.style.display = 'block';
                            consumableNote.innerHTML = '⚠️ This item belongs to a consumable table. Only quantity can be edited (decreased only). Name, department, category, location, and status cannot be changed.';
                        } else {
                            // Item is not consumable - enable all fields
                            statusSelect.disabled = false;
                            statusSelect.style.backgroundColor = '';
                            statusSelect.style.cursor = '';
                            
                            itemNameInput.disabled = false;
                            itemNameInput.style.backgroundColor = '';
                            itemNameInput.style.cursor = '';
                            
                            departmentSelect.disabled = false;
                            departmentSelect.style.backgroundColor = '';
                            departmentSelect.style.cursor = '';
                            
                            categorySelect.disabled = false;
                            categorySelect.style.backgroundColor = '';
                            categorySelect.style.cursor = '';
                            
                            locationSelect.disabled = false;
                            locationSelect.style.backgroundColor = '';
                            locationSelect.style.cursor = '';
                            
                            quantityInput.removeAttribute('max');
                            quantityInput.removeAttribute('data-original-quantity');
                            
                            // Remove quantity validation listener if it exists
                            if (window.consumableQuantityValidator) {
                                quantityInput.removeEventListener('input', window.consumableQuantityValidator);
                            }
                            
                            consumableNote.style.display = 'none';
                            // Set status from item data
                            statusSelect.value = item.status || item.display_status || 'Working';
                        }
                    })
                    .catch(error => {
                        console.error('Error checking consumable status:', error);
                        // On error, enable all fields
                        statusSelect.disabled = false;
                        statusSelect.value = item.status || item.display_status || 'Working';
                        itemNameInput.disabled = false;
                        departmentSelect.disabled = false;
                        categorySelect.disabled = false;
                        locationSelect.disabled = false;
                        quantityInput.removeAttribute('max');
                        consumableNote.style.display = 'none';
                    });
            } else {
                // No item_table_id - enable all fields
                statusSelect.disabled = false;
                statusSelect.value = item.status || item.display_status || 'Working';
                itemNameInput.disabled = false;
                departmentSelect.disabled = false;
                categorySelect.disabled = false;
                locationSelect.disabled = false;
                quantityInput.removeAttribute('max');
                consumableNote.style.display = 'none';
            }
            
            document.getElementById('editItemDescription').value = item.description || '';
            
            // Display current item image if it exists
            const currentImageContainer = document.getElementById('currentItemImage');
            const editItemImagePreview = document.getElementById('editItemImagePreview');
            const editItemImageInput = document.getElementById('editItemImage');
            
            // Reset image preview
            editItemImagePreview.style.display = 'none';
            editItemImageInput.value = '';
            
            if (item.image_path) {
                currentImageContainer.innerHTML = `
                    <div style="margin-top: 10px;">
                        <label style="font-size: 12px; color: #666; display: block; margin-bottom: 5px;">Current Image:</label>
                        <img src="${escapeHtml(item.image_path)}" alt="Current Item Image" 
                             style="max-width: 200px; max-height: 200px; border-radius: 8px; border: 1px solid #ddd; display: block;">
                    </div>
                `;
            } else {
                currentImageContainer.innerHTML = '<div style="font-size: 12px; color: #999; margin-top: 5px;">No image currently set for this item.</div>';
            }
            
            // Enhanced modal opening
            const modal = document.getElementById('editItemModal');
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Focus the first input after animation
            setTimeout(() => {
                document.getElementById('editItemName').focus();
            }, 300);
        }
    }

    // Update item
    function updateItem() {
        const formData = new FormData();
        formData.append('action', 'update');
        formData.append('id', document.getElementById('editItemId').value);
        formData.append('name', document.getElementById('editItemName').value);
        formData.append('department_id', document.getElementById('editItemDepartment').value);
        formData.append('category', document.getElementById('editItemCategory').value);
        formData.append('quantity', document.getElementById('editItemQuantity').value);
        formData.append('location', document.getElementById('editItemLocation').value);
        formData.append('status', document.getElementById('editItemStatus').value);
        formData.append('description', document.getElementById('editItemDescription').value);
        
        // Add image file if a new one is selected
        const editItemImageInput = document.getElementById('editItemImage');
        if (editItemImageInput && editItemImageInput.files.length > 0) {
            formData.append('image', editItemImageInput.files[0]);
        }
        
        fetch('crud.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    console.error('Server error response:', text);
                    throw new Error(`Server error: ${response.status} ${response.statusText}`);
                });
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error. Raw response:', text);
                    throw new Error('Invalid JSON response from server: ' + text.substring(0, 100));
                }
            });
        })
        .then(data => {
            if (data.success) {
                modal.success('Item updated successfully!');
                closeEditItemModal();
                loadAllItems(); // Reload all items and rebuild tree
            } else {
                modal.error(data.message || 'Failed to update item');
            }
        })
        .catch(error => {
            console.error('Error updating item:', error);
            modal.error('Error updating item: ' + error.message);
        });
    }
    
    // Preview edit item image
    function previewEditItemImage(input) {
        const preview = document.getElementById('editItemImagePreview');
        const previewImg = document.getElementById('previewEditItemImg');
        
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(input.files[0]);
        } else {
            preview.style.display = 'none';
        }
    }
    
    // Remove edit item image preview
    function removeEditItemImagePreview() {
        const preview = document.getElementById('editItemImagePreview');
        const input = document.getElementById('editItemImage');
        if (preview) preview.style.display = 'none';
        if (input) input.value = '';
    }

    // Delete item
    function deleteItem(id) {
        // Permission check before attempting delete
        const item = allItems.find(i => i.id === id);
        if (item && !IS_SUPER_ADMIN && USER_DEPARTMENT && item.department_name !== USER_DEPARTMENT) {
            modal.warning(`Action Not Allowed - You can only delete items in your own department. Your department: ${USER_DEPARTMENT}. Item dept: ${item.department_name}.`);
            return;
        }
        if (confirm('Are you sure you want to delete this item?')) {
            fetch('crud.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete&id=${id}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    modal.success('Item deleted successfully!');
                    loadAllItems(); // Reload all items and rebuild tree
                } else {
                    modal.error(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                modal.error('Error deleting item');
            });
        }
    }

    // Archive item - Store ID for confirmation
    let itemToArchiveId = null;
    
    function archiveItem(id) {
        // Find the item to show its name
        const item = allItems.find(i => i.id == id);
        if (item && !IS_SUPER_ADMIN && USER_DEPARTMENT && item.department_name !== USER_DEPARTMENT) {
            modal.warning(`Action Not Allowed - You can only archive items in your own department. Your department: ${USER_DEPARTMENT}. Item dept: ${item.department_name}.`);
            return;
        }
        const itemName = item ? item.name : 'this item';
        
        // Store the ID for later use
        itemToArchiveId = id;
        
        // Update modal content
        document.getElementById('archiveItemName').textContent = `"${itemName}" will be moved to the archive.`;
        
        // Enhanced modal opening
        const modal = document.getElementById('archiveItemModal');
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    function closeArchiveModal() {
        const modal = document.getElementById('archiveItemModal');
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            modal.style.display = 'none';
        }, 400);
        itemToArchiveId = null;
    }
    
    function confirmArchive() {
        if (!itemToArchiveId) {
            closeArchiveModal();
            return;
        }
        
        // Show loading state
        const submitBtn = document.querySelector('#archiveItemModal .btn-submit');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span style="display: inline-block; animation: spin 1s linear infinite;">⏳</span> Archiving...';
        submitBtn.disabled = true;
        
        fetch('crud.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=archive&id=${itemToArchiveId}`
        })
        .then(response => response.json())
        .then(data => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            
            if (data.success) {
                closeArchiveModal();
                modal.success('Item Archived Successfully! The item has been moved to the archive. You can view and restore it from the Archive page.');
                
                // Immediately reload items (no delay)
                loadAllItems();
                
                // Notify archive page to reload immediately (for cross-page updates)
                localStorage.setItem('archiveUpdated', Date.now().toString());
                window.dispatchEvent(new StorageEvent('storage', {
                    key: 'archiveUpdated',
                    newValue: Date.now().toString()
                }));
            } else {
                closeArchiveModal();
                modal.error(`Failed to Archive Item - ${data.message || 'An error occurred while archiving the item.'}`);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            closeArchiveModal();
            modal.error(`Connection Error - Unable to connect to the server. Error details: ${error.message}`);
        });
    }


    function openRequestModal() {
        // Super admins can always request items from any department
        // Check if user is viewing their own department
        const isViewingOwnDepartment = IS_SUPER_ADMIN ? true :
            (IS_ADMIN ? 
                (selectedDepartmentId === 'all' || (USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID))) :
                (USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID)));
        
        if (!isViewingOwnDepartment) {
            modal.warning('Action Not Allowed: You can only request items from your own department.');
            return;
        }
        
        const requestModal = document.getElementById('requestItemModal');
        requestModal.style.display = 'flex';
        requestModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        document.body.classList.add('modal-open'); // Make sure this line exists
    
    // Close all open menus first
    document.querySelectorAll('.card-action-menu, .action-menu').forEach(menu => {
        menu.classList.remove('show');
    });
    
    setTimeout(() => document.getElementById('requestItemName').focus(), 300);
}
function closeRequestModal() {
    const modal = document.getElementById('requestItemModal');
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
    document.body.classList.remove('modal-open'); // Add this line
    setTimeout(() => { modal.style.display = 'none'; }, 400);
    document.getElementById('requestItemForm').reset();
}
    function submitItemRequest() {
        // Basic validation
        const name = document.getElementById('requestItemName').value.trim();
        const qty = parseInt(document.getElementById('requestQuantity').value, 10) || 0;
        if (!name || qty < 1) {
            modal.warning('Missing Information - Please enter item name and quantity.');
            return;
        }
        const btn = document.querySelector('#requestItemModal .btn-submit');
        setButtonLoading(btn.id || 'requestSubmitBtn', true, 'Submit Request');
        const formData = new FormData();
        formData.append('action', 'request_item');
        formData.append('item_name', name);
        formData.append('category', document.getElementById('requestCategory').value);
        formData.append('quantity', qty);
        formData.append('date_needed', document.getElementById('requestDateNeeded').value || '');
        formData.append('notes', document.getElementById('requestNotes').value.trim());
        fetch('crud.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    closeRequestModal();
                    modal.success('Request Submitted - Your item request has been sent to the admin.');
                } else {
                    modal.error(data.message || 'Please try again later.');
                }
            })
            .catch(err => modal.error(`Network Error - ${err.message}`))
            .finally(() => setButtonLoading(btn.id || 'requestSubmitBtn', false, 'Submit Request'));
    }

    // Viewer Borrow Modal Functions
    function openViewerBorrowModal(itemId, itemName, itemCode) {
        const modal = document.getElementById('viewerBorrowModal');
        if (!modal) {
            console.error('Viewer borrow modal not found');
            return;
        }
        
        // Check if item can be borrowed - find the item in allItems array
        const item = allItems.find(i => i.id == itemId);
        if (item) {
            const itemStatus = (item.display_status || item.status || 'Unknown');
            const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance', 'Consumable'];
            if (nonBorrowableStatuses.includes(itemStatus)) {
                let errorMessage = 'Cannot borrow this item. ';
                if (itemStatus === 'Broken') {
                    errorMessage += 'Item is broken and cannot be borrowed.';
                } else if (itemStatus === 'Missing') {
                    errorMessage += 'Item is missing and cannot be borrowed.';
                } else if (itemStatus === 'Lost') {
                    errorMessage += 'Item is lost and cannot be borrowed.';
                } else if (itemStatus === 'Under Maintenance') {
                    errorMessage += 'Item is under maintenance and cannot be borrowed.';
                } else if (itemStatus === 'Borrowed') {
                    errorMessage += 'Item is already borrowed.';
                } else if (itemStatus === 'Consumable') {
                    errorMessage += 'Consumable not available to borrow.';
                } else {
                    errorMessage += 'Item status does not allow borrowing.';
                }
                modal.error(errorMessage);
                return;
            }
        }
        
        // Get user info from body data attributes
        const body = document.body;
        const username = body.dataset.userUsername || '';
        const email = body.dataset.userEmail || '';
        
        // Set item details
        document.getElementById('viewerBorrowItemId').value = itemId;
        document.getElementById('viewerBorrowItemName').value = itemName;
        document.getElementById('viewerBorrowItemCode').value = itemCode || 'N/A';
        
        // Auto-populate user info
        document.getElementById('viewerBorrowerName').value = username;
        document.getElementById('viewerBorrowerEmail').value = email;
        
        // Set default dates
        const today = new Date().toISOString().split('T')[0];
        // Borrow date is fixed to today (when they request) - readonly
        document.getElementById('viewerBorrowDate').value = today;
        
        // Set default needed date to tomorrow (they need it soon after request)
        const neededDate = new Date();
        neededDate.setDate(neededDate.getDate() + 1);
        document.getElementById('viewerNeededDate').value = neededDate.toISOString().split('T')[0];
        
        // Set default due date to 7 days from now
        const dueDate = new Date();
        dueDate.setDate(dueDate.getDate() + 7);
        document.getElementById('viewerDueDate').value = dueDate.toISOString().split('T')[0];
        
        // Reset purpose field
        document.getElementById('viewerBorrowPurpose').value = '';
        
        // Reset item placement
        const itemPlacementSelect = document.getElementById('viewerItemPlacement');
        if (itemPlacementSelect) {
            itemPlacementSelect.value = '';
            // Load locations from API
            loadLocationsForBorrowModal();
        }
        
        // Show modal
        modal.style.display = 'flex';
        modal.style.pointerEvents = 'auto';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        document.body.classList.add('modal-open');
        
        // Ensure modal content is clickable
        const modalContent = modal.querySelector('.modal');
        if (modalContent) {
            modalContent.style.pointerEvents = 'auto';
            modalContent.style.position = 'relative';
            modalContent.style.zIndex = '2147483001';
        }
        
        // Ensure close button is clickable
        const closeBtn = modal.querySelector('.close-btn');
        if (closeBtn) {
            closeBtn.style.pointerEvents = 'auto';
            closeBtn.style.zIndex = '2147483002';
            closeBtn.style.position = 'relative';
        }
        
        // Ensure submit button is enabled and clickable
        const submitBtn = document.getElementById('viewerBorrowSubmitBtn');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.pointerEvents = 'auto';
            submitBtn.style.cursor = 'pointer';
            // Remove any data attribute that might prevent submission
            submitBtn.removeAttribute('data-submitting');
        }
        
        // Ensure all form inputs in modal are enabled
        const formInputs = modal.querySelectorAll('input, textarea, button, select');
        formInputs.forEach(input => {
            input.disabled = false;
            input.style.pointerEvents = 'auto';
        });
        
        // Close all open menus
        document.querySelectorAll('.card-action-menu, .action-menu').forEach(menu => {
            menu.classList.remove('show');
        });
        
        // Focus on purpose field (first editable field)
        setTimeout(() => {
            const purposeField = document.getElementById('viewerBorrowPurpose');
            if (purposeField) {
                purposeField.focus();
            }
        }, 300);
    }

    function loadLocationsForBorrowModal() {
        const itemPlacementSelect = document.getElementById('viewerItemPlacement');
        if (!itemPlacementSelect) return;
        
        // Clear existing options except the first one
        itemPlacementSelect.innerHTML = '<option value="">Select Location</option>';
        
        // Fetch locations from API
        fetch('crud.php?action=get_locations', {
            method: 'GET',
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.locations) {
                data.locations.forEach(location => {
                    const option = document.createElement('option');
                    // Format: Building, Floor X, Room Name (if available) or Room Number (if no Room Name)
                    let displayText = location.building_name + ', Floor ' + location.floor_number + ', ';
                    if (location.room_name && location.room_name.trim() !== '') {
                        displayText += location.room_name;
                    } else {
                        displayText += location.room_number;
                    }
                    option.value = location.full_location;
                    option.textContent = displayText;
                    itemPlacementSelect.appendChild(option);
                });
            } else {
                console.error('Failed to load locations:', data.message || 'Unknown error');
            }
        })
        .catch(error => {
            console.error('Error loading locations:', error);
        });
    }

    function closeViewerBorrowModal() {
        const modal = document.getElementById('viewerBorrowModal');
        if (!modal) return;
        
        // Hide modal immediately
        modal.style.display = 'none';
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        document.body.classList.remove('modal-open');
        
        // Reset form
        const form = document.getElementById('viewerBorrowForm');
        if (form) {
            form.reset();
        }
        
        // Clear any input values
        const itemIdInput = document.getElementById('viewerBorrowItemId');
        const itemNameInput = document.getElementById('viewerBorrowItemName');
        const itemCodeInput = document.getElementById('viewerBorrowItemCode');
        const borrowerNameInput = document.getElementById('viewerBorrowerName');
        const borrowerEmailInput = document.getElementById('viewerBorrowerEmail');
        const borrowDateInput = document.getElementById('viewerBorrowDate');
        const neededDateInput = document.getElementById('viewerNeededDate');
        const dueDateInput = document.getElementById('viewerDueDate');
        const purposeInput = document.getElementById('viewerBorrowPurpose');
        
        if (itemIdInput) itemIdInput.value = '';
        if (itemNameInput) itemNameInput.value = '';
        if (itemCodeInput) itemCodeInput.value = '';
        if (borrowerNameInput) borrowerNameInput.value = '';
        if (borrowerEmailInput) borrowerEmailInput.value = '';
        if (borrowDateInput) borrowDateInput.value = '';
        if (neededDateInput) neededDateInput.value = '';
        if (dueDateInput) dueDateInput.value = '';
        if (purposeInput) purposeInput.value = '';
        
        // Reset button state
        const submitBtn = document.getElementById('viewerBorrowSubmitBtn');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Borrow Request';
            submitBtn.classList.remove('btn-loading');
        }
    }

    // Manage Borrow Requests Modal Functions
    function openManageBorrowRequestsModal() {
        // Button should ONLY work when viewing user's own department (not "All Departments")
        let isViewingOwnDepartment = false;
        
        if (selectedDepartmentId === 'all') {
            // Never allow when viewing "All Departments"
            isViewingOwnDepartment = false;
        } else if (IS_SUPER_ADMIN) {
            // Super admins can manage requests for any specific department (but not "all")
            isViewingOwnDepartment = true;
        } else {
            // Regular admins and department heads can only manage their own department
            isViewingOwnDepartment = (USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID));
        }
        
        if (!isViewingOwnDepartment) {
            const modalObj = window.modal || modal;
            if (modalObj && typeof modalObj.warning === 'function') {
                modalObj.warning('Action Not Allowed: You can only manage borrow requests when viewing a specific department.');
            } else {
                alert('Action Not Allowed: You can only manage borrow requests when viewing a specific department.');
            }
            return;
        }
        
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
    
    // Function to update Manage Borrow Requests button visibility based on selected department
    function updateManageBorrowRequestsButtonVisibility() {
        const btn = document.getElementById('manageBorrowRequestsBtn');
        if (!btn) return;
        
        // Check if user is department head (admin but not super admin)
        const isDepartmentHead = (document.body?.dataset?.userIsDepartmentHead || 'false') === 'true';
        const isSuperAdmin = (document.body?.dataset?.userSuperAdmin || 'false') === 'true';
        
        // Button visibility logic:
        // - Super admins: show for any specific department (but not "All Departments")
        // - Department heads: show ONLY when viewing their own department
        // - Regular users: show ONLY when viewing their own department
        let shouldShowButton = false;
        
        if (selectedDepartmentId === 'all') {
            // Never show button when viewing "All Departments"
            shouldShowButton = false;
        } else if (isSuperAdmin) {
            // Super admins can see button for any specific department (but not "all")
            shouldShowButton = true;
        } else if (isDepartmentHead) {
            // Department heads: show ONLY when viewing their own department
            shouldShowButton = USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID);
        } else {
            // Regular users: show ONLY when viewing their own department
            shouldShowButton = USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID);
        }
        
        if (shouldShowButton) {
            btn.classList.add('show');
            btn.style.display = 'inline-flex';
            btn.style.visibility = 'visible';
            btn.style.opacity = '1';
        } else {
            btn.classList.remove('show');
            btn.style.display = 'none';
            btn.style.visibility = 'hidden';
            btn.style.opacity = '0';
        }
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

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
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
                const modalObj = window.modal || modal;
                if (modalObj && modalObj.success) {
                    modalObj.success('Borrow request approved successfully. The borrower will be notified via email.');
                } else {
                    alert('Borrow request approved successfully.');
                }
                loadBorrowRequests(); // Reload the list
            } else {
                const modalObj = window.modal || modal;
                if (modalObj && modalObj.error) {
                    modalObj.error(data.message || 'Failed to approve borrow request.');
                } else {
                    alert('Error: ' + (data.message || 'Failed to approve borrow request.'));
                }
            }
        } catch (error) {
            console.error('Error approving borrow request:', error);
            const modalObj = window.modal || modal;
            if (modalObj && modalObj.error) {
                modalObj.error('Error approving borrow request. Please try again.');
            } else {
                alert('Error approving borrow request. Please try again.');
            }
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
                const modalObj = window.modal || modal;
                if (modalObj && modalObj.success) {
                    modalObj.success('Borrow request declined. The borrower will be notified via email.');
                } else {
                    alert('Borrow request declined.');
                }
                loadBorrowRequests(); // Reload the list
            } else {
                const modalObj = window.modal || modal;
                if (modalObj && modalObj.error) {
                    modalObj.error(data.message || 'Failed to decline borrow request.');
                } else {
                    alert('Error: ' + (data.message || 'Failed to decline borrow request.'));
                }
            }
        } catch (error) {
            console.error('Error declining borrow request:', error);
            const modalObj = window.modal || modal;
            if (modalObj && modalObj.error) {
                modalObj.error('Error declining borrow request. Please try again.');
            } else {
                alert('Error declining borrow request. Please try again.');
            }
        }
    }

    function submitViewerBorrowRequest() {
        console.log('submitViewerBorrowRequest called');
        
        // Prevent double submission
        const btn = document.getElementById('viewerBorrowSubmitBtn');
        if (btn) {
            // Check if already submitting
            if (btn.disabled || btn.hasAttribute('data-submitting')) {
                console.log('Submission already in progress, ignoring duplicate call');
                return;
            }
            // Mark as submitting and disable button immediately
            btn.setAttribute('data-submitting', 'true');
            btn.disabled = true;
            btn.style.pointerEvents = 'none';
        }
        
        const itemId = document.getElementById('viewerBorrowItemId').value;
        const itemName = document.getElementById('viewerBorrowItemName').value;
        const borrowerName = document.getElementById('viewerBorrowerName').value.trim();
        const borrowerEmail = document.getElementById('viewerBorrowerEmail').value.trim();
        const borrowDate = document.getElementById('viewerBorrowDate').value;
        const neededDate = document.getElementById('viewerNeededDate').value;
        const dueDate = document.getElementById('viewerDueDate').value;
        const itemPlacement = document.getElementById('viewerItemPlacement').value.trim();
        const purpose = document.getElementById('viewerBorrowPurpose').value.trim();
        
        console.log('Form values:', { itemId, itemName, borrowerName, borrowerEmail, borrowDate, neededDate, dueDate, purpose });
        console.log('date_needed being sent:', neededDate);
        
        // Check if modal object exists, use window.modal if available
        const modalObj = window.modal || modal;
        if (!modalObj || typeof modalObj.warning !== 'function') {
            console.warn('Modal object not found, using alert as fallback');
        }
        
        // Helper function to show messages
        const showMessage = (message, type = 'warning') => {
            if (modalObj && typeof modalObj[type] === 'function') {
                modalObj[type](message);
            } else {
                alert(message);
            }
        };
        
        // Validation
        if (!borrowerName) {
            // Re-enable button on validation error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
            }
            showMessage('Borrower name is missing. Please refresh the page and try again.', 'warning');
            return;
        }
        
        if (!borrowerEmail) {
            // Re-enable button on validation error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
            }
            showMessage('Borrower email is missing. Please refresh the page and try again.', 'warning');
            return;
        }
        
        // Validate email format
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(borrowerEmail)) {
            // Re-enable button on validation error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
            }
            showMessage('Invalid email format. Please contact administrator.', 'warning');
            return;
        }
        
        // Borrow date is automatically set to today, so it should always be present
        if (!borrowDate) {
            // Re-enable button on validation error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
            }
            showMessage('Borrow date is missing. Please refresh the page and try again.', 'warning');
            return;
        }
        
        if (!neededDate) {
            // Re-enable button on validation error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
            }
            showMessage('Please select a needed date (when you need the item).', 'warning');
            return;
        }
        
        if (!dueDate) {
            // Re-enable button on validation error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
            }
            showMessage('Please select a due date (when you will return the item).', 'warning');
            return;
        }
        
        // Validate date order: needed date should be >= borrow date (today), and due date should be >= needed date
        if (new Date(neededDate) < new Date(borrowDate)) {
            // Re-enable button on validation error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
            }
            showMessage('Needed date cannot be before the request date (today).', 'warning');
            return;
        }
        
        if (new Date(dueDate) < new Date(neededDate)) {
            // Re-enable button on validation error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
            }
            showMessage('Due date must be on or after the needed date.', 'warning');
            return;
        }
        
        // Generate borrow ID
        const borrowId = 'BRW-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5).toUpperCase();
        
        console.log('Submitting borrow request with ID:', borrowId);
        
        // Set loading state
        if (btn) {
            setButtonLoading(btn.id, true, 'Submitting...');
        }
        
        // Submit borrow request
        const formData = new FormData();
        formData.append('action', 'borrow');
        formData.append('borrow_id', borrowId);
        formData.append('borrower_name', borrowerName);
        formData.append('borrower_email', borrowerEmail);
        formData.append('item_id', itemId);
        formData.append('quantity', '1');
        formData.append('borrow_date', borrowDate); // Fixed to today (request date)
        formData.append('date_needed', neededDate); // When they need the item
        formData.append('due_date', dueDate);
        formData.append('item_placement', itemPlacement); // Item placement location
        formData.append('purpose', purpose);
        
        fetch('crud.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => {
            // Check if response is OK
            if (!response.ok) {
                // Re-enable button on error
                if (btn) {
                    btn.removeAttribute('data-submitting');
                    btn.disabled = false;
                    btn.style.pointerEvents = 'auto';
                    setButtonLoading(btn.id, false, 'Submit Borrow Request');
                }
                throw new Error('HTTP error! status: ' + response.status);
            }
            // Check content type
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Non-JSON response:', text);
                    // Re-enable button on error
                    if (btn) {
                        btn.removeAttribute('data-submitting');
                        btn.disabled = false;
                        btn.style.pointerEvents = 'auto';
                        setButtonLoading(btn.id, false, 'Submit Borrow Request');
                    }
                    throw new Error('Server returned non-JSON response. Please check the console for details.');
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('=== BORROW REQUEST SUBMISSION RESPONSE ===');
            console.log('Full response:', JSON.stringify(data, null, 2));
            console.log('Success:', data.success);
            console.log('Message:', data.message);
            if (data.borrow_record) {
                console.log('Borrow Record:', data.borrow_record);
                console.log('Status:', data.borrow_record.status);
                console.log('Department:', data.borrow_record.department_name);
                console.log('Borrow ID:', data.borrow_record.borrow_id);
            }
            console.log('==========================================');
            
            // Get modal object for showing messages
            const modalObj = window.modal || modal;
            const showMessage = (message, type = 'warning') => {
                if (modalObj && typeof modalObj[type] === 'function') {
                    modalObj[type](message);
                } else {
                    alert(message);
                }
            };
            
            // Handle session expired
            if (data.session_expired) {
                // Re-enable button (though user will be redirected)
                if (btn) {
                    btn.removeAttribute('data-submitting');
                    btn.disabled = false;
                    btn.style.pointerEvents = 'auto';
                    setButtonLoading(btn.id, false, 'Submit Borrow Request');
                }
                showMessage('Your session has expired. Please login again.', 'error');
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 2000);
                return;
            }
            
            // Handle database error
            if (data.database_error) {
                // Re-enable button on error
                if (btn) {
                    btn.removeAttribute('data-submitting');
                    btn.disabled = false;
                    btn.style.pointerEvents = 'auto';
                    setButtonLoading(btn.id, false, 'Submit Borrow Request');
                }
                showMessage(data.message || 'Database connection error. Please try again later.', 'error');
                return;
            }
            
            if (data.success) {
                // Close borrow modal immediately
                closeViewerBorrowModal();
                
                // Reset button state (should already be reset in closeViewerBorrowModal, but ensure it)
                if (btn) {
                    btn.removeAttribute('data-submitting');
                    btn.disabled = false;
                    btn.style.pointerEvents = 'auto';
                }
                
                // Show success message - user must click OK to close
                showMessage('Borrow Request Submitted Successfully - Your request to borrow "' + itemName + '" has been submitted. You will receive an email notification when your request is approved or declined.', 'success');
                
                // No auto-refresh - let user manually refresh or click OK first
                // User can refresh the page after closing the modal if they want to see updated status
            } else {
                // Re-enable button on error
                if (btn) {
                    btn.removeAttribute('data-submitting');
                    btn.disabled = false;
                    btn.style.pointerEvents = 'auto';
                    setButtonLoading(btn.id, false, 'Submit Borrow Request');
                }
                const errorMsg = data.message || 'Failed to submit borrow request. Please try again.';
                console.error('Borrow request failed:', errorMsg);
                showMessage(errorMsg, 'error');
            }
        })
        .catch(err => {
            console.error('Borrow request error:', err);
            // Re-enable button on error
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.style.pointerEvents = 'auto';
                setButtonLoading(btn.id, false, 'Submit Borrow Request');
            }
            
            const modalObj = window.modal || modal;
            const errorMsg = 'Error submitting borrow request: ' + (err.message || 'Please check your connection and try again.');
            if (modalObj && typeof modalObj.error === 'function') {
                modalObj.error(errorMsg);
            } else {
                alert(errorMsg);
            }
        });
    }

    // Sign out
    function signOut() {
        if (confirm('Are you sure you want to sign out?')) {
            window.location.href = 'logout.php';
        }
    }
    // Search functionality - works with both table and card views
    const nameFilter = document.getElementById('nameFilter');
    if (nameFilter) {
        nameFilter.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const tableContainer = document.querySelector('.table-container');
        
        // If viewing an item table, show table when searching
        if (window.currentTableId && window.currentTableItems) {
            if (!searchTerm) {
                // Show search prompt if search is cleared
                if (tableContainer && window.currentTableItems.length > 0) {
                    tableContainer.innerHTML = `
                        <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 60px 40px; text-align: center;">
                            <div style="font-size: 64px; margin-bottom: 20px;">
                                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="11" cy="11" r="8" stroke="#3b82f6" stroke-width="2" fill="none"/>
                                    <path d="m21 21-4.35-4.35" stroke="#a855f7" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <h3 style="color: #2d3748; margin-bottom: 12px; font-size: 24px; font-weight: 600;">Search for Items to View Them</h3>
                            <p style="color: #718096; font-size: 16px; max-width: 500px; margin: 0 auto;">
                                Please use the search box above to search for items. Items will only be displayed after you perform a search.
                            </p>
                        </div>
                    `;
                }
                return;
            }
            
            // Filter items and show table
            let filteredItems = window.currentTableItems.filter(item => {
                const itemName = (item.name || '').toLowerCase();
                const itemCode = (item.item_code || '').toLowerCase();
                const category = (item.category || '').toLowerCase();
                const department = (item.department_name || '').toLowerCase();
                return itemName.includes(searchTerm) || 
                       itemCode.includes(searchTerm) || 
                       category.includes(searchTerm) ||
                       department.includes(searchTerm);
            });
            
            // Sort items
            const sortedItems = sortItemsArray(filteredItems);
            const currentPage = initializeCategoryPagination(window.currentTableName, sortedItems);
            const paginatedItems = getPaginatedItems(sortedItems, currentPage);
            
            const tableHTML = generateSelectableCategoryTableHTML(window.currentTableName, sortedItems, paginatedItems, currentPage);
            
            // Add selection container
            const selectionContainer = `
                <div class="item-selection-container" id="itemSelectionContainer" style="display: none;">
                    <div class="item-selection-info">
                        <span id="selectedCount">0</span> items selected
                    </div>
                    <div class="item-selection-actions">
                        <button class="item-selection-btn" onclick="printSelectedItemsQR()">
                            <img src="image/export.png" alt="Print QR" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;" />
                            Print QR
                        </button>
                        <button class="item-selection-btn" onclick="downloadSelectedItemsQR()">
                            <img src="image/barcode-scan.png" alt="Download QR" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;" />
                            Download QR
                        </button>
                        <button class="item-selection-btn" onclick="moveSelectedItemsLocation()">
                            <img src="image/building-1062.png" alt="Move Location" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;" />
                            Move Location
                        </button>
                        <button class="item-selection-btn primary" onclick="archiveSelectedItems()">
                            <img src="image/icons8-archive-50.png" alt="Archive" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;" />
                            Archive
                        </button>
                    </div>
                </div>
            `;
            
            if (tableContainer) {
                tableContainer.innerHTML = selectionContainer + tableHTML;
                tableContainer.style.display = 'block';
                tableContainer.style.visibility = 'visible';
            }
            
            updateSummary();
            updateSortIndicators();
            return;
        }
        
        // Original search functionality for item tables list
        // If no search term, hide items and show message
        if (!searchTerm) {
            const cardsContainer = document.getElementById('itemsCardsContainer');
            const noFilterMessage = document.getElementById('noFilterMessage');
            if (tableContainer) tableContainer.style.display = 'none';
            if (cardsContainer) cardsContainer.style.display = 'none';
            if (noFilterMessage) noFilterMessage.style.display = 'block';
            updateSummary();
            return;
        }
        
        // Hide message when search is performed
        const noFilterMessage = document.getElementById('noFilterMessage');
        if (noFilterMessage) noFilterMessage.style.display = 'none';
        
        // If items not loaded yet, load them first
        if (allItems.length === 0) {
            loadAllItems().then(() => {
                // After loading, show and filter items
                showAndFilterItems(searchTerm);
            });
        } else {
            // Items already loaded, just filter and show
            showAndFilterItems(searchTerm);
        }
        });
    }
    
    // Helper function to show and filter item tables based on search
    async function showAndFilterItems(searchTerm) {
        // Check if search term is still valid (user might have cleared it)
        const currentSearchValue = document.getElementById('nameFilter')?.value.toLowerCase().trim() || '';
        if (!currentSearchValue || currentSearchValue !== searchTerm) {
            // Search was cleared or changed, don't display items
            return;
        }
        
        // Hide message when showing items
        const noFilterMessage = document.getElementById('noFilterMessage');
        if (noFilterMessage) noFilterMessage.style.display = 'none';
        
        // Show items container (default to card view for item tables)
        const cardsContainer = document.getElementById('itemsCardsContainer');
        const tableContainer = document.querySelector('.table-container');
        
        if (tableContainer) {
            tableContainer.style.display = 'none';
        }
        
        if (!cardsContainer) {
            return;
        }
        
        try {
            // Load item tables from database
            const response = await fetch('crud.php?action=get_item_tables');
            const data = await response.json();
            
            if (!data.success || !data.item_tables || data.item_tables.length === 0) {
                cardsContainer.innerHTML = `
                    <div class="no-items-card">
                        <div class="no-items-icon">📦</div>
                        <h3>No Item Tables Found</h3>
                        <p>No item tables match your search</p>
                    </div>
                `;
                cardsContainer.style.display = 'grid';
                return;
            }
            
            // Filter item tables by department if needed
            let tablesToShow = data.item_tables;
            if (selectedDepartmentId !== 'all') {
                tablesToShow = tablesToShow.filter(table => table.department_id == selectedDepartmentId);
            }
            
            // Filter item tables: show only tables that contain items matching the search term
            const filteredTables = [];
            for (const table of tablesToShow) {
                // Check if table name matches search
                const tableNameMatch = (table.table_name || '').toLowerCase().includes(searchTerm);
                
                // Check if any items in this table match the search
                let hasMatchingItems = false;
                try {
                    const itemsResponse = await fetch(`crud.php?action=get_items_by_table&table_id=${table.id}`);
                    const itemsData = await itemsResponse.json();
                    if (itemsData.success && itemsData.items) {
                        hasMatchingItems = itemsData.items.some(item => {
                            const itemName = (item.name || '').toLowerCase();
                            const itemCode = (item.item_code || '').toLowerCase();
                            const searchableText = itemName + ' ' + itemCode;
                            return searchableText.includes(searchTerm);
                        });
                    }
                } catch (error) {
                    console.error(`Error checking items for table ${table.id}:`, error);
                }
                
                // Include table if table name matches OR if it has matching items
                if (tableNameMatch || hasMatchingItems) {
                    filteredTables.push(table);
                }
            }
            
            // Check again if search term is still valid before displaying
            const currentSearchValue2 = document.getElementById('nameFilter')?.value.toLowerCase().trim() || '';
            if (!currentSearchValue2 || currentSearchValue2 !== searchTerm) {
                // Search was cleared or changed, don't display items
                return;
            }
            
            if (filteredTables.length === 0) {
                cardsContainer.innerHTML = `
                    <div class="no-items-card">
                        <div class="no-items-icon">🔍</div>
                        <h3>No Item Tables Found</h3>
                        <p>No item tables match your search: "${searchTerm}"</p>
                    </div>
                `;
                cardsContainer.style.display = 'grid';
                updateSummary();
                return;
            }
            
            // Display filtered item tables using updateCardView logic
            await displayFilteredItemTables(filteredTables);
            
        } catch (error) {
            console.error('Error filtering item tables:', error);
            cardsContainer.innerHTML = `
                <div class="no-items-card">
                    <div class="no-items-icon">❌</div>
                    <h3>Error Loading Item Tables</h3>
                    <p>Please refresh the page and try again</p>
                </div>
            `;
            cardsContainer.style.display = 'grid';
        }
        
        updateSummary();
    }
    
    // Helper function to display filtered item tables
    async function displayFilteredItemTables(tables) {
        // Check if search term is still valid before displaying
        const currentSearchValue = document.getElementById('nameFilter')?.value.toLowerCase().trim() || '';
        if (!currentSearchValue) {
            // Search was cleared, don't display items
            const cardsContainer = document.getElementById('itemsCardsContainer');
            if (cardsContainer) {
                cardsContainer.style.display = 'none';
            }
            const noFilterMessage = document.getElementById('noFilterMessage');
            if (noFilterMessage) {
                noFilterMessage.style.display = 'block';
            }
            return;
        }
        
        const cardsContainer = document.getElementById('itemsCardsContainer');
        if (!cardsContainer) return;
        
        // Create cards for each filtered item table
        const cardsPromises = tables.map(async (table) => {
            const tableImage = table.table_image_path ? 
                `<img src="${table.table_image_path}" alt="${table.table_name}" class="item-image" />` : 
                `<div class="item-image-placeholder">📦</div>`;
            
            // Get item count for this table
            let itemCount = 0;
            try {
                const countResponse = await fetch(`crud.php?action=get_items_by_table&table_id=${table.id}`);
                const countData = await countResponse.json();
                if (countData.success && countData.items) {
                    itemCount = countData.items.length;
                }
            } catch (error) {
                console.error(`Error getting item count for table ${table.id}:`, error);
            }
            
            return `
                <div class="item-card clickable-card" data-table-id="${table.id}" onclick="showTableForItemTable('${table.id}', '${table.table_name}')">
                    <div class="item-image-container">
                        ${tableImage}
                    </div>
                    <div class="item-card-content">
                        <div class="item-title-row">
                            <div class="item-card-title">${table.table_name}</div>
                            <div class="card-action-dropdown">
                                <button class="card-action-btn-menu" onclick="event.stopPropagation(); toggleTableActionMenu('${table.id}')">⋮</button>
                                <div class="card-action-menu" id="card-menu-${table.id}">
                                    <button onclick="showTableForItemTable('${table.id}', '${table.table_name}')">
                                        <img src="image/table.png" alt="Table" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        View Table
                                    </button>
                                    ${(IS_SUPER_ADMIN || table.department_name === USER_DEPARTMENT) ? `
                                    <button onclick="event.stopPropagation(); openEditItemTableModal('${table.id}')">
                                        <img src="image/edit.png" alt="Edit" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Edit Item Table
                                    </button>
                                    ` : ''}
                                    ${table.qr_code && (IS_SUPER_ADMIN || table.department_name === USER_DEPARTMENT) ? `
                                    <button onclick="event.stopPropagation(); downloadQRCode('${table.id}', '${table.table_name}')">
                                        <img src="image/export.png" alt="Download" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Download QR Code
                                    </button>
                                    ` : ''}
                                    ${(document.body.dataset.userIsViewer !== 'true') && itemCount === 0 && (IS_SUPER_ADMIN || table.department_name === USER_DEPARTMENT) ? `
                                    <button onclick="event.stopPropagation(); deleteItemTable('${table.id}', '${table.table_name}')" style="color:#dc3545;">
                                        <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Delete Item Table
                                    </button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="quantity-text">Category: ${table.category}</div>
                        <div class="quantity-text">Items: ${itemCount}</div>
                        <div style="margin: 8px 0; display: flex; gap: 6px; flex-wrap: wrap;">
                            ${table.is_consumable == 1 ? `
                            <span style="
                                padding: 4px 10px;
                                border-radius: 12px;
                                font-size: 11px;
                                font-weight: 600;
                                text-transform: uppercase;
                                display: inline-block;
                                background-color: #fef3c7;
                                color: #92400e;
                            ">⚡ Consumable</span>
                            ` : ''}
                            ${table.priority && table.is_consumable != 1 ? `
                            <span style="
                                padding: 4px 10px;
                                border-radius: 12px;
                                font-size: 11px;
                                font-weight: 600;
                                text-transform: uppercase;
                                display: inline-block;
                                background-color: ${table.priority === 'high' ? '#fee2e2' : table.priority === 'medium' ? '#fef3c7' : '#d1fae5'};
                                color: ${table.priority === 'high' ? '#991b1b' : table.priority === 'medium' ? '#92400e' : '#065f46'};
                            ">${table.priority === 'high' ? '🔴 High' : table.priority === 'medium' ? '🟡 Medium' : '🟢 Low'}</span>
                            ` : ''}
                        </div>
                        <div class="meta-row">
                            <div class="meta">
                                <span class="meta-label">Department:</span>
                                <span class="meta-value">${table.department_name}</span>
                            </div>
                            <div class="meta">
                                <span class="meta-label">Created:</span>
                                <span class="meta-value">${formatDateForCard(table.created_at)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        // Wait for all promises to resolve
        const cardsHTML = await Promise.all(cardsPromises);
        
        // Check one more time if search term is still valid before displaying
        const finalSearchValue = document.getElementById('nameFilter')?.value.toLowerCase().trim() || '';
        if (!finalSearchValue) {
            // Search was cleared, don't display items
            cardsContainer.style.display = 'none';
            const noFilterMessage = document.getElementById('noFilterMessage');
            if (noFilterMessage) {
                noFilterMessage.style.display = 'block';
            }
            return;
        }
        
        // Clear container and show cards
        cardsContainer.innerHTML = '';
        cardsContainer.innerHTML = cardsHTML.join('');
        cardsContainer.style.display = 'grid';
        cardsContainer.style.visibility = 'visible';
        
        // Show the view toggle for table cards
        const viewToggle = document.getElementById('tableCardsViewToggle');
        if (viewToggle) {
            viewToggle.style.display = 'inline-flex';
        }
    }

    // Tree search functionality
    const treeSearch = document.getElementById('treeSearch');
    if (treeSearch) {
        treeSearch.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const treeItems = document.querySelectorAll('.tree-item');
        
        treeItems.forEach(item => {
            const treeText = item.querySelector('.tree-text');
            if (!treeText) return;
            const text = treeText.textContent.toLowerCase();
            item.style.display = text.includes(searchTerm) ? '' : 'none';
        });
        });
    }

    // Global search function that can be called from anywhere
    function performSearch() {
        const searchTerm = document.getElementById('nameFilter').value.toLowerCase().trim();
        
        // If no search term, hide items and show message
        if (!searchTerm) {
            const tableContainer = document.querySelector('.table-container');
            const cardsContainer = document.getElementById('itemsCardsContainer');
            const noFilterMessage = document.getElementById('noFilterMessage');
            
            if (tableContainer) {
                tableContainer.style.display = 'none';
            }
            if (cardsContainer) {
                cardsContainer.style.display = 'none';
            }
            if (noFilterMessage) {
                noFilterMessage.style.display = 'block';
            }
            updateSummary();
            return;
        }
        
        // Hide message when search is performed
        const noFilterMessage = document.getElementById('noFilterMessage');
        if (noFilterMessage) noFilterMessage.style.display = 'none';
        
        // Load items if not loaded yet
        if (allItems.length === 0) {
            loadAllItems().then(() => {
                // After items are loaded, perform the search
                setTimeout(() => {
                    document.getElementById('nameFilter').dispatchEvent(new Event('input'));
                }, 100);
            });
        } else {
            // Items already loaded, just trigger the search
            document.getElementById('nameFilter').dispatchEvent(new Event('input'));
        }
    }
    // When modal opens, ensure sidebar search loses focus
    document.addEventListener('click', function(e){
        if (document.body.classList.contains('modal-open')) {
            const search = document.getElementById('treeSearch');
            if (search && document.activeElement === search) {
                search.blur();
            }
        }
    });
    // Borrow Modal Functions
let availableItems = [];

function openBorrowModal() {
    // Prevent department heads from accessing borrow modal
    const isDepartmentHead = (document.body?.dataset?.userIsDepartmentHead || 'false') === 'true';
    if (isDepartmentHead) {
        return;
    }
    // Super admins can always borrow items from any department
    // Check if user is viewing their own department
    const isViewingOwnDepartment = IS_SUPER_ADMIN ? true :
        (IS_ADMIN ? 
            (selectedDepartmentId === 'all' || (USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID))) :
            (USER_DEPARTMENT_ID && String(selectedDepartmentId) === String(USER_DEPARTMENT_ID)));
    
    if (!isViewingOwnDepartment) {
        modal.warning('Action Not Allowed: You can only borrow items from your own department.');
        return;
    }
    
    // Generate new borrow ID
    generateBorrowId();
    
    // Set default borrow date to today
    document.getElementById('borrowDate').value = new Date().toISOString().split('T')[0];
    
    // Set default due date to 7 days from now
    const dueDate = new Date();
    dueDate.setDate(dueDate.getDate() + 7);
    document.getElementById('dueDate').value = dueDate.toISOString().split('T')[0];
    
    // Load item tables for the dropdown
    loadBorrowItemTables();
    
    // Reset item name select until a table is selected
    const itemNameSelect = document.getElementById('borrowItemName');
    if (itemNameSelect) {
        itemNameSelect.innerHTML = '<option value="">Select Item Table First</option>';
    }
    
    // Enhanced modal opening
    const modal = document.getElementById('borrowItemModal');
    modal.style.display = 'flex';
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Focus the first input after animation
    setTimeout(() => {
        document.getElementById('borrowerName').focus();
    }, 300);
}

function closeBorrowModal() {
    const modal = document.getElementById('borrowItemModal');
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
    setTimeout(() => {
        modal.style.display = 'none';
    }, 400);
    document.getElementById('borrowItemForm').reset();
    clearValidationErrors();
}

function generateBorrowId() {
    // Generate a unique borrow ID (you can customize this format)
    const timestamp = Date.now();
    const borrowId = 'BRW-' + timestamp.toString().slice(-6);
    document.getElementById('borrowId').value = borrowId;
}

function loadBorrowItemTables() {
    fetch('crud.php?action=get_item_tables')
        .then(response => response.json())
        .then(data => {
            const itemTableSelect = document.getElementById('borrowItemTable');
            itemTableSelect.innerHTML = '<option value="">Select Item Table</option>';
            
            if (data.success && data.item_tables) {
                // Only show item tables from the user's own department (super admins and admins see all)
                const userDept = USER_DEPARTMENT || '';
                const tables = (!userDept || IS_ADMIN || IS_SUPER_ADMIN) ? data.item_tables : 
                    data.item_tables.filter(t => String(t.department_name) === String(userDept));
                tables.forEach(table => {
                    const option = document.createElement('option');
                    option.value = table.id;
                    option.textContent = table.table_name;
                    option.setAttribute('data-table-name', table.table_name);
                    option.setAttribute('data-category', table.category);
                    option.setAttribute('data-department-id', table.department_id);
                    option.setAttribute('data-department-name', table.department_name);
                    itemTableSelect.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error('Error loading item tables for borrow:', error);
        });
}

function loadItemsFromTable() {
    const itemTableSelect = document.getElementById('borrowItemTable');
    const selectedOption = itemTableSelect.options[itemTableSelect.selectedIndex];
    const itemNameSelect = document.getElementById('borrowItemName');
    
    // Clear current items
    itemNameSelect.innerHTML = '<option value="">Select Item</option>';
    
    if (selectedOption.value === '') {
        return;
    }
    
    const tableId = selectedOption.value;
    
    // Fetch items from the selected table
    fetch(`crud.php?action=get_items_by_table&table_id=${tableId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.items) {
                // Filter items by selected table, user's department, and availability
                const userDept = USER_DEPARTMENT || '';
                const availableItems = data.items.filter(item =>
                    item.status === 'Working' && parseInt(item.quantity) > 0 &&
                    (!userDept || IS_ADMIN || IS_SUPER_ADMIN || String(item.department_name) === String(userDept))
                );
                
                availableItems.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent = `${item.name} (${item.item_code || 'No Code'})`;
                    option.dataset.itemData = JSON.stringify(item);
                    itemNameSelect.appendChild(option);
                });
                
                if (availableItems.length === 0) {
                    itemNameSelect.innerHTML = '<option value="">No available items in this table</option>';
                }
            } else {
                itemNameSelect.innerHTML = '<option value="">No items found in this table</option>';
            }
        })
        .catch(error => {
            console.error('Error loading items from table:', error);
            itemNameSelect.innerHTML = '<option value="">Error loading items</option>';
        });
}

function loadAvailableItems() {
    // Filter items that are available for borrowing (Working status and quantity > 0)
    // AND only from user's department (unless admin)
    const userDept = USER_DEPARTMENT || '';
    availableItems = allItems.filter(item => {
        const isAvailable = item.status === 'Working' && parseInt(item.quantity) > 0;
        const isUserDepartment = !userDept || IS_ADMIN || IS_SUPER_ADMIN || String(item.department_name) === String(userDept);
        return isAvailable && isUserDepartment;
    });
    
    const select = document.getElementById('borrowItemName');
    select.innerHTML = '<option value="">Select Item to Borrow</option>';
    
    availableItems.forEach(item => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = `${item.name} (${item.item_code || 'No Code'})`;
        option.dataset.itemData = JSON.stringify(item);
        select.appendChild(option);
    });
}

function updateItemDetails() {
    const select = document.getElementById('borrowItemName');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const itemData = JSON.parse(selectedOption.dataset.itemData);
        
        // Validate that the item belongs to user's department (super admins and admins can select all)
        const userDept = USER_DEPARTMENT || '';
        if (userDept && !IS_ADMIN && !IS_SUPER_ADMIN && String(itemData.department_name) !== String(userDept)) {
            // Clear selection and show warning
            select.value = '';
            document.getElementById('borrowDepartment').value = '';
            modal.warning('Action Not Allowed: You can only select items from your own department.');
            return;
        }
        
        // Update department
        document.getElementById('borrowDepartment').value = itemData.department_name;
        
    } else {
        // Clear fields
        document.getElementById('borrowDepartment').value = '';
    }
}

// Updated Borrow Item Function
function processBorrow() {
    // Validate required fields
    if (!validateRequiredFields('borrowItemForm')) {
        modal.warning('Missing Required Information - Please fill in all required fields before processing the borrow request. Borrower name, item selection, and dates are all required.');
        return;
    }
    
    // Validate that selected item belongs to user's department
    const itemSelect = document.getElementById('borrowItemName');
    const selectedOption = itemSelect.options[itemSelect.selectedIndex];
    
    if (selectedOption.value) {
        const itemData = JSON.parse(selectedOption.dataset.itemData || '{}');
        const userDept = USER_DEPARTMENT || '';
        
        // Check if item belongs to user's department (super admins and admins can select all)
        if (userDept && !IS_ADMIN && !IS_SUPER_ADMIN && String(itemData.department_name) !== String(userDept)) {
            modal.warning('Action Not Allowed: You can only borrow items from your own department.');
            return;
        }
    }
    
    // Additional validation for borrow-specific rules
    const borrowQuantity = 1; // Default quantity
    const borrowDate = new Date(document.getElementById('borrowDate').value);
    const dueDate = new Date(document.getElementById('dueDate').value);
    
    if (dueDate <= borrowDate) {
        modal.error('Invalid Date Range - Due date must be after the borrow date. Please select a due date that comes after your borrow date.');
        return;
    }
    
    // Set loading state
    setButtonLoading('borrowSubmitBtn', true);
    
    const formData = new FormData();
    formData.append('action', 'borrow');
    formData.append('borrow_id', document.getElementById('borrowId').value);
    formData.append('borrower_name', document.getElementById('borrowerName').value.trim());
    formData.append('item_id', document.getElementById('borrowItemName').value);
    formData.append('quantity', borrowQuantity);
    formData.append('borrow_date', document.getElementById('borrowDate').value);
    formData.append('due_date', document.getElementById('dueDate').value);
    formData.append('borrower_email', document.getElementById('borrowerEmail').value.trim());
    formData.append('purpose', document.getElementById('borrowPurpose').value.trim());
    
    fetch('crud.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        setButtonLoading('borrowSubmitBtn', false);
        
        if (data.success) {
            closeBorrowModal();
            const itemName = document.getElementById('borrowItemName').selectedOptions[0].text.split(' (')[0];
            modal.success(`Item Borrowed Successfully! ${itemName} has been borrowed by ${document.getElementById('borrowerName').value}. Borrow ID: ${document.getElementById('borrowId').value} | Due: ${document.getElementById('dueDate').value} | Quantity: ${borrowQuantity}`);
            loadAllItems(); // Reload items to update quantities
        } else {
            modal.error(data.message || 'An error occurred while processing the borrow request.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        setButtonLoading('borrowSubmitBtn', false);
        modal.error(`Connection Error - Unable to connect to the server. Please check your internet connection and try again. Error details: ${error.message}`);
    });
}


// Enhanced borrow ID generation to avoid duplicates
function generateBorrowId() {
    const timestamp = Date.now();
    const random = Math.floor(Math.random() * 1000);
    const borrowId = 'BRW-' + timestamp.toString().slice(-8) + random.toString().padStart(3, '0');
    document.getElementById('borrowId').value = borrowId;
}

// Enhanced validation with better error handling
function validateBorrowForm() {
    clearValidationErrors();
    let isValid = true;
    
    // Required field validations
    const requiredFields = [
        { id: 'borrowerName', message: 'Borrower name is required' },
        { id: 'borrowItemName', message: 'Please select an item' },
        { id: 'borrowQuantity', message: 'Quantity is required' },
        { id: 'borrowDate', message: 'Borrow date is required' },
        { id: 'dueDate', message: 'Due date is required' }
    ];
    
    requiredFields.forEach(field => {
        const element = document.getElementById(field.id);
        if (!element.value.trim()) {
            showFieldError(field.id, field.message);
            isValid = false;
        }
    });
    
    // Quantity validation
    const borrowQuantityInput = document.getElementById('borrowQuantity');
    const availableQuantityInput = document.getElementById('availableQuantity');
    
    const borrowQuantity = parseInt(borrowQuantityInput.value) || 0;
    const availableQuantity = parseInt(availableQuantityInput.value) || 0;
    
    if (borrowQuantity > availableQuantity) {
        showFieldError('borrowQuantity', `Cannot borrow more than ${availableQuantity} items`);
        isValid = false;
    }
    
    if (borrowQuantity <= 0) {
        showFieldError('borrowQuantity', 'Quantity must be greater than 0');
        isValid = false;
    }
    
    // Date validation
    const borrowDate = new Date(document.getElementById('borrowDate').value);
    const dueDate = new Date(document.getElementById('dueDate').value);
    
    if (dueDate <= borrowDate) {
        showFieldError('dueDate', 'Due date must be after borrow date');
        isValid = false;
    }
    
    // Borrower name validation
    const borrowerName = document.getElementById('borrowerName').value.trim();
    if (borrowerName.length < 2) {
        showFieldError('borrowerName', 'Borrower name must be at least 2 characters');
        isValid = false;
    }
    
    return isValid;
}

// Optional: Show borrow success details
function showBorrowSuccess(borrowRecord) {
    if (borrowRecord) {
        const message = `
Borrow Processed Successfully!

Borrow ID: ${borrowRecord.borrow_id}
Borrower: ${borrowRecord.borrower_name}
Item: ${borrowRecord.item_name}
Department: ${borrowRecord.department_name}
Quantity: ${borrowRecord.quantity}
Due Date: ${borrowRecord.due_date}
Status: Active

The item quantity has been updated and a record has been added to the borrow history.
        `;
        
        // Create a better success modal or just use alert for now
        setTimeout(() => {
            if (confirm(message + '\n\nWould you like to view the borrow history?')) {
                window.location.href = 'BorrowHistory.php';
            }
        }, 500);
    }
}

function validateBorrowForm() {
    clearValidationErrors();
    let isValid = true;
    
    // Required field validations
    const requiredFields = [
        { id: 'borrowerName', message: 'Borrower name is required' },
        { id: 'borrowItemName', message: 'Please select an item' },
        { id: 'borrowQuantity', message: 'Quantity is required' },
        { id: 'borrowDate', message: 'Borrow date is required' },
        { id: 'dueDate', message: 'Due date is required' }
    ];
    
    requiredFields.forEach(field => {
        const element = document.getElementById(field.id);
        if (!element.value.trim()) {
            showFieldError(field.id, field.message);
            isValid = false;
        }
    });
    
    // Quantity validation
    const borrowQuantity = parseInt(document.getElementById('borrowQuantity').value);
    const availableQuantity = parseInt(document.getElementById('availableQuantity').value);
    
    if (borrowQuantity > availableQuantity) {
        showFieldError('borrowQuantity', `Cannot borrow more than ${availableQuantity} items`);
        isValid = false;
    }
    
    if (borrowQuantity <= 0) {
        showFieldError('borrowQuantity', 'Quantity must be greater than 0');
        isValid = false;
    }
    
    // Date validation
    const borrowDate = new Date(document.getElementById('borrowDate').value);
    const dueDate = new Date(document.getElementById('dueDate').value);
    
    if (dueDate <= borrowDate) {
        showFieldError('dueDate', 'Due date must be after borrow date');
        isValid = false;
    }
    
    return isValid;
}

function showFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    const formGroup = field.closest('.form-group');
    formGroup.classList.add('error');
    
    let errorDiv = formGroup.querySelector('.error-message');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        formGroup.appendChild(errorDiv);
    }
    errorDiv.textContent = message;
}

function clearValidationErrors() {
    document.querySelectorAll('.form-group.error').forEach(group => {
        group.classList.remove('error');
    });
    document.querySelectorAll('.error-message').forEach(error => {
        error.remove();
    });
}

// Add event listeners for real-time validation
document.addEventListener('DOMContentLoaded', function() {
    // Quantity input validation
    document.getElementById('borrowQuantity')?.addEventListener('input', function() {
        const max = parseInt(this.max);
        const value = parseInt(this.value);
        
        if (value > max) {
            this.value = max;
        }
    });
    
    // Date validation
    document.getElementById('borrowDate')?.addEventListener('change', function() {
        const borrowDate = new Date(this.value);
        const dueDateInput = document.getElementById('dueDate');
        
        // Set minimum due date to borrow date + 1 day
        const minDueDate = new Date(borrowDate);
        minDueDate.setDate(minDueDate.getDate() + 1);
        dueDateInput.min = minDueDate.toISOString().split('T')[0];
        
        // If current due date is invalid, update it
        if (new Date(dueDateInput.value) <= borrowDate) {
            const suggestedDue = new Date(borrowDate);
            suggestedDue.setDate(suggestedDue.getDate() + 7);
            dueDateInput.value = suggestedDue.toISOString().split('T')[0];
        }
    });
});

// Item Selection Functions
function toggleItemSelection(itemId, event) {
    if (event) {
        event.stopPropagation();
    }
    
    // Check if checkbox is disabled
    const checkbox = event?.target;
    if (checkbox && checkbox.disabled) {
        return; // Don't allow selection of disabled checkboxes
    }
    
    // Find the item in allItems array to check its department
    const item = allItems.find(i => i.id == itemId);
    if (item) {
        // Validate that the item belongs to user's department (super admins and admins can select all)
        const userDept = USER_DEPARTMENT || '';
        if (userDept && !IS_ADMIN && !IS_SUPER_ADMIN && String(item.department_name) !== String(userDept)) {
            modal.warning('Action Not Allowed: You can only select items from your own department.');
            // Ensure checkbox is unchecked
            if (checkbox) {
                checkbox.checked = false;
            }
            return;
        }
    }
    
    // Check if the item is borrowed by looking at the status in the row
    const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
    if (row) {
        const statusElement = row.querySelector('.status-badge');
        if (statusElement && statusElement.textContent.trim() === 'Borrowed') {
            // Don't allow selection of borrowed items
            return;
        }
    }
    
    if (selectedItems.has(itemId)) {
        selectedItems.delete(itemId);
    } else {
        selectedItems.add(itemId);
    }
    
    updateSelectionUI();
    updateSelectedCount();
}

function toggleSelectAllPageItems() {
    const selectAllCheckbox = document.getElementById('selectAllPageItems');
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const userDept = USER_DEPARTMENT || '';
    
    checkboxes.forEach(checkbox => {
        const itemId = parseInt(checkbox.closest('tr').dataset.itemId);
        // Only process items that have checkboxes (not borrowed items)
        if (checkbox && checkbox.offsetParent !== null) {
            // Find the item to check its department
            const item = allItems.find(i => i.id == itemId);
            const isUserDepartment = !userDept || IS_ADMIN || IS_SUPER_ADMIN || (item && String(item.department_name) === String(userDept));
            
            if (selectAllCheckbox.checked) {
                // Only add items from user's department
                if (isUserDepartment) {
                    selectedItems.add(itemId);
                }
            } else {
                selectedItems.delete(itemId);
            }
        }
    });
    
    updateSelectionUI();
    updateSelectedCount();
}

// Handle selectAllItems checkbox (for table view)
function toggleSelectAllItems() {
    const selectAllCheckbox = document.getElementById('selectAllItems');
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const userDept = USER_DEPARTMENT || '';
    
    checkboxes.forEach(checkbox => {
        const itemId = parseInt(checkbox.closest('tr').dataset.itemId);
        // Only process items that have checkboxes (not borrowed items)
        if (checkbox && checkbox.offsetParent !== null) {
            // Find the item to check its department
            const item = allItems.find(i => i.id == itemId);
            const isUserDepartment = !userDept || IS_ADMIN || IS_SUPER_ADMIN || (item && String(item.department_name) === String(userDept));
            
            if (selectAllCheckbox.checked) {
                // Only add items from user's department
                if (isUserDepartment) {
                    selectedItems.add(itemId);
                    checkbox.checked = true;
                } else {
                    checkbox.checked = false;
                }
            } else {
                selectedItems.delete(itemId);
                checkbox.checked = false;
            }
        }
    });
    
    updateSelectionUI();
    updateSelectedCount();
}

function updateSelectionUI() {
    const rows = document.querySelectorAll('.data-table tbody tr.selectable');
    rows.forEach(row => {
        const itemId = parseInt(row.dataset.itemId);
        const checkbox = row.querySelector('.item-checkbox');
        
        // Skip rows without checkboxes (borrowed items or disabled items)
        if (!checkbox || checkbox.disabled) {
            return;
        }
        
        if (selectedItems.has(itemId)) {
            row.classList.add('selected');
            checkbox.checked = true;
        } else {
            row.classList.remove('selected');
            checkbox.checked = false;
        }
    });
    
    // Update select all checkbox - only count enabled checkboxes
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    if (selectAllCheckbox) {
        const checkboxes = document.querySelectorAll('.item-checkbox:not([disabled])');
        const checkedCheckboxes = document.querySelectorAll('.item-checkbox:not([disabled]):checked');
        selectAllCheckbox.checked = checkboxes.length > 0 && checkboxes.length === checkedCheckboxes.length;
        selectAllCheckbox.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < checkboxes.length;
    }
    
    // Also update selectAllItems checkbox if it exists
    const selectAllItemsCheckbox = document.getElementById('selectAllItems');
    if (selectAllItemsCheckbox) {
        const checkboxes = document.querySelectorAll('.item-checkbox:not([disabled])');
        const checkedCheckboxes = document.querySelectorAll('.item-checkbox:not([disabled]):checked');
        selectAllItemsCheckbox.checked = checkboxes.length > 0 && checkboxes.length === checkedCheckboxes.length;
        selectAllItemsCheckbox.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < checkboxes.length;
    }
}

function updateSelectedCount() {
    const selectedCount = document.getElementById('selectedCount');
    const selectionContainer = document.getElementById('itemSelectionContainer');
    
    if (selectedCount) {
        selectedCount.textContent = selectedItems.size;
    }
    
    if (selectionContainer) {
        if (selectedItems.size > 0) {
            selectionContainer.style.display = 'flex';
        } else {
            selectionContainer.style.display = 'none';
        }
    }
}

// Loading Animation Helper Functions
function showLoadingOverlay(message = 'Processing...', showProgress = false, totalItems = 0) {
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.id = 'loadingOverlay';
    
    const progressHtml = showProgress ? `
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill"></div>
        </div>
        <div class="loading-progress" id="loadingProgress">0 / ${totalItems} items</div>
    ` : '';
    
    overlay.innerHTML = `
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <div class="loading-text">${message}</div>
            ${progressHtml}
        </div>
    `;
    document.body.appendChild(overlay);
}

function hideLoadingOverlay() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.remove();
    }
}

function updateLoadingProgress(current, total, itemName = '') {
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('loadingProgress');
    
    if (progressFill && progressText) {
        const percentage = (current / total) * 100;
        progressFill.style.width = percentage + '%';
        progressText.textContent = `${current} / ${total} items${itemName ? ` - ${itemName}` : ''}`;
    }
}

// Action Functions for Selected Items
// Helper function to filter selected items by user's department
function filterSelectedItemsByDepartment() {
    const userDept = USER_DEPARTMENT || '';
    // Super admins can process all items without restrictions
    if (IS_SUPER_ADMIN) {
        return Array.from(selectedItems);
    }
    // Regular admins can also process all items if they have no department restriction
    if (!userDept || IS_ADMIN) {
        return Array.from(selectedItems);
    }
    
    // Filter to only include items from user's department for non-admin users
    return Array.from(selectedItems).filter(itemId => {
        const item = allItems.find(i => i.id == itemId);
        return item && String(item.department_name) === String(userDept);
    });
}

function downloadSelectedItemsQR() {
    if (selectedItems.size === 0) {
        modal.warning('Please select items to download QR codes.');
        return;
    }
    
    // Filter selected items to only include user's department
    const filteredItems = filterSelectedItemsByDepartment();
    
    if (filteredItems.length === 0) {
        modal.warning('Action Not Allowed: You can only download QR codes for items from your own department.');
        // Clear invalid selections
        selectedItems.clear();
        updateSelectionUI();
        updateSelectedCount();
        return;
    }
    
    // If some items were filtered out, notify user
    if (filteredItems.length < selectedItems.size) {
        const removedCount = selectedItems.size - filteredItems.length;
        modal.warning(`${removedCount} item(s) from other departments were removed. Only items from your department will be processed.`);
        // Update selectedItems to only include valid items
        selectedItems.clear();
        filteredItems.forEach(id => selectedItems.add(id));
        updateSelectionUI();
        updateSelectedCount();
    }
    
    const selectedItemsArray = filteredItems;
    console.log('Downloading QR codes for items:', selectedItemsArray);
    
    // Show professional loading overlay with progress
    showLoadingOverlay('Downloading QR Codes...', true, selectedItemsArray.length);
    
    let completedCount = 0;
    
    // Download QR codes for each selected item
    selectedItemsArray.forEach((itemId, index) => {
        const item = allItems.find(i => i.id == itemId);
        if (!item) {
            console.error('Item not found for ID:', itemId);
            completedCount++;
            updateLoadingProgress(completedCount, selectedItemsArray.length, 'Item not found');
            return;
        }
        
        // Create a delay between downloads to avoid browser blocking
        setTimeout(() => {
            downloadSingleItemQR(item, () => {
                completedCount++;
                updateLoadingProgress(completedCount, selectedItemsArray.length, item.name);
                
                // Hide loading overlay when all downloads are complete
                if (completedCount === selectedItemsArray.length) {
                    setTimeout(() => {
                        hideLoadingOverlay();
                        modal.success(`QR codes download completed! ${selectedItemsArray.length} files downloaded.`);
                    }, 500);
                }
            });
        }, index * 800); // 800ms delay between each download
    });
}

// Helper function to download QR code for a single item
function downloadSingleItemQR(item, callback) {
    try {
        // Always create professional QR code with logo (same as print design)
        createProfessionalQRCode(item, callback);
    } catch (error) {
        console.error('Error downloading QR for item:', item.id, error);
        // Call callback even on error to continue progress
        if (callback) callback();
    }
}

// Map department to hex color (without #) for QR foreground
function getDepartmentColorHexJS(departmentName) {
    if (!departmentName) return '000000';
    const name = String(departmentName).trim().toLowerCase();
    if (name === 'ict equipment') return 'C62828'; // red
    if (name === 'slrc' || name.includes('student learning resource center')) return '1565C0'; // blue
    if (name === 'science equipment') return 'F59E0B'; // yellow/amber
    if (name === 'sps equipment') return '2E7D32'; // green
    return '000000';
}
// Helper function to create professional QR code with logo
function createProfessionalQRCode(item, callback) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');

    const inchesToPixels = (inches) => Math.round(inches * 96); // 96 DPI reference
    const qrSize = inchesToPixels(3); // 3 inch QR
    const margin = inchesToPixels(0.25);
    const labelHeight = inchesToPixels(0.4);
    const canvasSize = qrSize + margin * 2 + labelHeight;

    canvas.width = canvasSize;
    canvas.height = canvasSize;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvasSize, canvasSize);

    const payload = JSON.stringify({
        id: item.id,
        name: item.name,
        department: item.department_name,
        location: item.location
    });
    const fgColor = getDepartmentColorHexJS(item.department_name || '');
    console.log('createProfessionalQRCode - Item:', item.id, 'Department:', item.department_name, 'Color:', fgColor);
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=600x600&ecc=H&color=${fgColor}&bgcolor=FFFFFF&data=${encodeURIComponent(payload)}`;

    const qrImg = new Image();
    qrImg.crossOrigin = 'anonymous';
    qrImg.onload = function() {
        ctx.drawImage(qrImg, margin, margin, qrSize, qrSize);

        ctx.fillStyle = '#000000';
        ctx.font = `${Math.round(inchesToPixels(0.18))}px Arial`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        const itemCode = item.item_code || `ITEM-${item.id}`;
        ctx.fillText(itemCode, canvasSize / 2, qrSize + margin + labelHeight / 2);

        canvas.toBlob(function(blob) {
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `qr-${itemCode}.png`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            if (callback) callback();
        }, 'image/png', 1.0);
    };
    qrImg.onerror = function() {
        console.error('Failed to load QR code from API');
        downloadFallbackQR(item, callback);
    };
    qrImg.src = qrUrl;
}

function printSelectedItemsQR() {
    if (selectedItems.size === 0) {
        modal.warning('Please select items to print QR codes.');
        return;
    }
    
    const selectedItemsArray = Array.from(selectedItems).slice(0, 20); // Limit to maximum 20 items
    
    // Show professional loading overlay with progress
    showLoadingOverlay('Preparing QR Codes for Printing...', true, selectedItemsArray.length);
    
    let processedCount = 0;
    
    // Use setTimeout to allow the loading overlay to show and process items
    setTimeout(() => {
        // Build HTML content with print-friendly layout (16 items per page - 4x4 grid)
        let html = '<!DOCTYPE html><html><head><title>QR Codes Print</title>';
        html += '<style>@page{size:A4;margin:0.25in}*{-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact}body{font-family:Arial,sans-serif;margin:0;padding:0;background:white!important}.qr-container{display:grid;grid-template-columns:repeat(4,1fr);grid-template-rows:repeat(4,1fr);gap:8px;max-width:100%;margin:0 auto;page-break-after:always;height:calc(100vh - 0.5in);width:calc(100% - 0.5in)}.print-qr-item{background:white!important;border:2px solid #000!important;border-radius:8px;padding:12px;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center;min-height:140px;box-shadow:0 2px 4px rgba(0,0,0,0.1);page-break-inside:avoid;break-inside:avoid;position:relative;-webkit-print-color-adjust:exact;print-color-adjust:exact}.print-qr-item::before{content:"";position:absolute;top:-4px;left:-4px;right:-4px;bottom:-4px;border:2px dashed #ff0000!important;border-radius:10px;pointer-events:none;z-index:1;-webkit-print-color-adjust:exact;print-color-adjust:exact}.print-qr-header{display:flex;align-items:center;justify-content:center;margin-bottom:8px;gap:6px;position:relative;z-index:3}.print-qr-logo{width:16px;height:16px;object-fit:contain}.print-qr-title{font-size:12px;font-weight:bold;color:#2d3748!important;letter-spacing:0.5px;-webkit-print-color-adjust:exact;print-color-adjust:exact}.print-qr-code{max-width:80px;max-height:80px;width:100%;height:auto;border-radius:4px;margin-bottom:6px;position:relative;z-index:3;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;image-rendering:-webkit-optimize-contrast;image-rendering:crisp-edges}.print-item-code{font-size:8px;font-weight:600;color:#4a5568!important;padding:4px 6px;background:#f7fafc!important;border-radius:4px;border:1px solid #e2e8f0!important;position:relative;z-index:3;-webkit-print-color-adjust:exact;print-color-adjust:exact}@media print{*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}body{margin:0;padding:0;background:white!important}.qr-container{page-break-after:always;height:calc(100vh - 0.5in);width:calc(100% - 0.5in)}.print-qr-item{page-break-inside:avoid;break-inside:avoid;border:2px solid #000!important;background:white!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}.print-qr-item::before{border:2px dashed #ff0000!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}.print-qr-title{color:#2d3748!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}.print-qr-code{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;image-rendering:-webkit-optimize-contrast!important;image-rendering:crisp-edges!important}.print-item-code{color:#4a5568!important;background:#f7fafc!important;border:1px solid #e2e8f0!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}</style>';
        html += '</head><body>';
        
        // Split items into pages of 16 (4x4 grid)
        const itemsPerPage = 16;
        const totalPages = Math.ceil(selectedItemsArray.length / itemsPerPage);
        
        for (let page = 0; page < totalPages; page++) {
            const startIndex = page * itemsPerPage;
            const endIndex = Math.min(startIndex + itemsPerPage, selectedItemsArray.length);
            const pageItems = selectedItemsArray.slice(startIndex, endIndex);
            
            html += '<div class="qr-container">';
            
            // Add QR codes for this page
            pageItems.forEach(function(itemId) {
                processedCount++;
                updateLoadingProgress(processedCount, selectedItemsArray.length, `Processing item ${processedCount}`);
                
                // Try to find item in current table items first, then fallback to allItems
                let item = currentTableItems.find(function(i) { return i.id == itemId; });
                if (!item) {
                    item = allItems.find(function(i) { return i.id == itemId; });
                }
                if (item) {
                    console.log('Found item for printing:', item);
                    const itemCode = item.item_code || 'ITEM-' + item.id;
                    let qrImageSrc = item.qr_code || '';
                    
                    // Convert relative path to full URL if needed
                    if (qrImageSrc && !qrImageSrc.startsWith('http')) {
                        // Get current domain and path
                        const protocol = window.location.protocol;
                        const host = window.location.host;
                        const pathname = window.location.pathname;
                        const basePath = pathname.substring(0, pathname.lastIndexOf('/'));
                        qrImageSrc = protocol + '//' + host + basePath + '/' + qrImageSrc;
                    }
                    
                    html += '<div class="print-qr-item">';
                    html += '<div class="print-qr-header">';
                    html += '<img src="assets/logo.png" class="print-qr-logo" alt="Logo" />';
                    html += '<div class="print-qr-title">OCABIS</div>';
                    html += '</div>';
                    
                    // Get department color for QR code
                    const deptColor = getDepartmentColorHexJS(item.department_name);
                    const qrData = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')) + '/view_item.php?id=' + itemId;
                    
                    if (qrImageSrc) {
                        // Try to load the saved QR code first (with color preservation)
                        html += '<img src="' + qrImageSrc + '" class="print-qr-code" style="-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;" alt="QR Code" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';" />';
                        // Fallback: Generate QR code on-the-fly with department color if file doesn't exist
                        const fallbackQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=H&color=' + deptColor + '&bgcolor=FFFFFF&data=' + encodeURIComponent(qrData);
                        html += '<img src="' + fallbackQrUrl + '" class="print-qr-code" style="display:none;-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;" alt="QR Code Fallback" onload="this.style.display=\'block\'; this.previousElementSibling.style.display=\'none\';" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';" />';
                        html += '<div class="print-qr-code" style="width:80px;height:80px;background:#f0f0f0;display:none;align-items:center;justify-content:center;font-size:10px;">QR Code</div>';
                    } else {
                        // Generate QR code on-the-fly with department color if no saved QR code
                        const fallbackQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=H&color=' + deptColor + '&bgcolor=FFFFFF&data=' + encodeURIComponent(qrData);
                        html += '<img src="' + fallbackQrUrl + '" class="print-qr-code" style="-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact;" alt="QR Code" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';" />';
                        html += '<div class="print-qr-code" style="width:80px;height:80px;background:#f0f0f0;display:none;align-items:center;justify-content:center;font-size:10px;">QR Code</div>';
                    }
                    html += '<div class="print-item-code">' + itemCode + '</div></div>';
                } else {
                    console.log('Item not found for ID:', itemId);
                    // Add a placeholder for missing items
                    html += '<div class="print-qr-item">';
                    html += '<div class="print-qr-header">';
                    html += '<img src="assets/logo.png" class="print-qr-logo" alt="Logo" />';
                    html += '<div class="print-qr-title">OCABIS</div>';
                    html += '</div>';
                    html += '<div class="print-qr-code" style="width:80px;height:80px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:10px;">Item Not Found</div>';
                    html += '<div class="print-item-code">ID: ' + itemId + '</div></div>';
                }
            });
            
            html += '</div>'; // Close qr-container for this page
        }
        
        html += '</body></html>';
        
        // Hide loading overlay
        hideLoadingOverlay();
        
        // Open print window
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        printWindow.document.write(html);
        printWindow.document.close();
        
        // Wait for images to load before printing
        setTimeout(function() {
            printWindow.print();
        }, 2000);
    }, 100); // Small delay to show loading overlay
}

function moveSelectedItemsLocation() {
    if (selectedItems.size === 0) {
        modal.warning('Please select items to move.');
        return;
    }
    
    // Filter selected items to only include user's department
    const filteredItems = filterSelectedItemsByDepartment();
    
    if (filteredItems.length === 0) {
        modal.warning('Action Not Allowed: You can only move items from your own department.');
        // Clear invalid selections
        selectedItems.clear();
        updateSelectionUI();
        updateSelectedCount();
        return;
    }
    
    // If some items were filtered out, notify user
    if (filteredItems.length < selectedItems.size) {
        const removedCount = selectedItems.size - filteredItems.length;
        modal.warning(`${removedCount} item(s) from other departments were removed. Only items from your department will be moved.`);
        // Update selectedItems to only include valid items
        selectedItems.clear();
        filteredItems.forEach(id => selectedItems.add(id));
        updateSelectionUI();
        updateSelectedCount();
    }
    
    // Create a modal for location selection instead of prompt
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.id = 'moveLocationModal';
    modal.innerHTML = `
        <div class="modal">
            <div class="modal-header" style="background: linear-gradient(135deg, #3182ce, #2c5aa0); color: white;">
                <h3 style="color: white;">Move Items to New Location</h3>
                <button class="close-btn" onclick="closeMoveLocationModal()" style="color: white;">×</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <div style="width: 80px; height: 80px; margin: 0 auto 20px; background: linear-gradient(135deg, #3182ce, #2c5aa0); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <img src="image/building-1062.png" alt="Move Location" style="width: 40px; height: 40px; filter: brightness(0) invert(1);">
                    </div>
                    <h4 style="margin: 0 0 12px 0; color: #333; font-size: 20px; font-weight: 600;">Move ${selectedItems.size} selected items to new location</h4>
                    <p style="color: #666; margin: 0 0 20px 0; font-size: 15px;">Select the new location for the selected items.</p>
                    <div class="form-group">
                        <label>New Location: <span class="required">*</span></label>
                        <select id="newLocationSelect" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                            <option value="">Select New Location</option>
                        </select>
                    </div>
                    <div class="location-details" id="selectedLocationDetails" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px; display: none;">
                        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                            <div style="flex: 1; min-width: 150px;">
                                <strong style="color: #333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Building</strong>
                                <div id="selectedBuilding" style="color: #666; font-size: 14px; margin-top: 2px;">-</div>
                            </div>
                            <div style="flex: 1; min-width: 150px;">
                                <strong style="color: #333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Floor</strong>
                                <div id="selectedFloor" style="color: #666; font-size: 14px; margin-top: 2px;">-</div>
                            </div>
                            <div style="flex: 1; min-width: 150px;">
                                <strong style="color: #333; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Room</strong>
                                <div id="selectedRoom" style="color: #666; font-size: 14px; margin-top: 2px;">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="closeMoveLocationModal()">Cancel</button>
                <button class="btn-submit" onclick="confirmMoveLocation()" style="background: linear-gradient(135deg, #3182ce, #2c5aa0); display: flex; align-items: center; gap: 8px;">
                    <img src="image/building-1062.png" alt="Move Location" style="width: 16px; height: 16px; filter: brightness(0) invert(1);">
                    Move Items
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    modal.style.display = 'flex';
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    
    // Load locations into the select dropdown
    loadLocationsForMove();
}

function closeMoveLocationModal() {
    const modal = document.getElementById('moveLocationModal');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        setTimeout(() => {
            modal.remove();
        }, 400);
    }
}

function loadLocationsForMove() {
    // Fetch locations from the backend
    fetch('crud.php?action=get_locations')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('newLocationSelect');
            if (select && data.success) {
                select.innerHTML = '<option value="">Select New Location</option>';
                data.locations.forEach(location => {
                    const option = document.createElement('option');
                    option.value = location.full_location;
                    option.textContent = location.full_location;
                    // Store location details as data attributes
                    option.dataset.building = location.building_name;
                    option.dataset.floor = location.floor_number;
                    option.dataset.room = location.room_name;
                    select.appendChild(option);
                });
                
                // Add event listener for location selection
                select.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const detailsDiv = document.getElementById('selectedLocationDetails');
                    
                    if (selectedOption.value) {
                        // Show location details
                        document.getElementById('selectedBuilding').textContent = selectedOption.dataset.building || '-';
                        document.getElementById('selectedFloor').textContent = selectedOption.dataset.floor || '-';
                        document.getElementById('selectedRoom').textContent = selectedOption.dataset.room || '-';
                        detailsDiv.style.display = 'block';
                    } else {
                        // Hide location details
                        detailsDiv.style.display = 'none';
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error loading locations:', error);
        });
}

function confirmMoveLocation() {
    const newLocation = document.getElementById('newLocationSelect').value;
    if (!newLocation || !newLocation.trim()) {
        modal.warning('Please select a new location.');
        return;
    }
    
    // Double-check: filter selected items to only include user's department (safety check)
    const filteredItems = filterSelectedItemsByDepartment();
    const selectedItemsArray = filteredItems.length > 0 ? filteredItems : Array.from(selectedItems);
    
    // Show loading state
    const moveBtn = document.querySelector('#moveLocationModal .btn-submit');
    const originalText = moveBtn.innerHTML;
    moveBtn.innerHTML = '<span style="display: inline-block; animation: spin 1s linear infinite;">⏳</span> Moving...';
    moveBtn.disabled = true;
    
    // Move items sequentially
    let completed = 0;
    let failed = 0;
    
    const moveNext = (index) => {
        if (index >= selectedItemsArray.length) {
            // All items processed
            moveBtn.innerHTML = originalText;
            moveBtn.disabled = false;
            
            // Clear selection
            selectedItems.clear();
            updateSelectionUI();
            updateSelectedCount();
            
            // Close modal
            closeMoveLocationModal();
            
            // Reload items
            setTimeout(() => {
                loadAllItems();
            }, 1000);
            
            if (failed === 0) {
                if (typeof modal !== 'undefined' && modal.success) {
                    modal.success(`${completed} items have been moved to "${newLocation}" successfully!`);
                } else {
                    modal.success(`${completed} items have been moved to "${newLocation}" successfully!`);
                }
            } else {
                if (typeof modal !== 'undefined' && modal.warning) {
                    modal.warning(`${completed} items moved successfully, ${failed} items failed to move.`);
                } else {
                    modal.warning(`${completed} items moved successfully, ${failed} items failed to move.`);
                }
            }
            return;
        }
        
        const itemId = selectedItemsArray[index];
        
        fetch('crud.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=move_location&id=${itemId}&location=${encodeURIComponent(newLocation)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                completed++;
            } else {
                failed++;
                console.error(`Failed to move item ${itemId}:`, data.message);
            }
            moveNext(index + 1);
        })
        .catch(error => {
            console.error(`Error moving item ${itemId}:`, error);
            failed++;
            moveNext(index + 1);
        });
    };
    
    moveNext(0);
}

function archiveSelectedItems() {
    if (selectedItems.size === 0) {
        modal.warning('Please select items to archive.');
        return;
    }
    
    // Filter selected items to only include user's department
    const filteredItems = filterSelectedItemsByDepartment();
    
    if (filteredItems.length === 0) {
        modal.warning('Action Not Allowed: You can only archive items from your own department.');
        // Clear invalid selections
        selectedItems.clear();
        updateSelectionUI();
        updateSelectedCount();
        return;
    }
    
    // If some items were filtered out, notify user
    if (filteredItems.length < selectedItems.size) {
        const removedCount = selectedItems.size - filteredItems.length;
        modal.warning(`${removedCount} item(s) from other departments were removed. Only items from your department will be archived.`);
        // Update selectedItems to only include valid items
        selectedItems.clear();
        filteredItems.forEach(id => selectedItems.add(id));
        updateSelectionUI();
        updateSelectedCount();
    }
    
    if (confirm(`Are you sure you want to archive ${selectedItems.size} selected items?`)) {
        const selectedItemsArray = Array.from(selectedItems);
        console.log('Archiving items:', selectedItemsArray);
        
        // Show loading state
        const archiveBtn = document.querySelector('.item-selection-btn.primary');
        if (archiveBtn) {
            const originalText = archiveBtn.innerHTML;
            archiveBtn.innerHTML = '<span style="display: inline-block; animation: spin 1s linear infinite;">⏳</span> Archiving...';
            archiveBtn.disabled = true;
        }
        
        // Archive items sequentially to avoid conflicts
        let completed = 0;
        let failed = 0;
        
        const archiveNext = (index) => {
            if (index >= selectedItemsArray.length) {
                // All items processed
                if (archiveBtn) {
                    archiveBtn.innerHTML = '<img src="image/icons8-archive-50.png" alt="Archive" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;" /> Archive';
                    archiveBtn.disabled = false;
                }
        
        // Clear selection
        selectedItems.clear();
        updateSelectionUI();
        updateSelectedCount();
        
                // Reload items
                setTimeout(() => {
                    loadAllItems();
                }, 1000);
                
                if (failed === 0) {
                    if (typeof modal !== 'undefined' && modal.success) {
                        modal.success(`${completed} items have been archived successfully!`);
                    } else {
                        modal.success(`${completed} items have been archived successfully!`);
                    }
                } else {
                    if (typeof modal !== 'undefined' && modal.warning) {
                        modal.warning(`${completed} items archived successfully, ${failed} items failed to archive.`);
                    } else {
                        modal.warning(`${completed} items archived successfully, ${failed} items failed to archive.`);
                    }
                }
                return;
            }
            
            const itemId = selectedItemsArray[index];
            
            fetch('crud.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=archive&id=${itemId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    completed++;
                } else {
                    failed++;
                    console.error(`Failed to archive item ${itemId}:`, data.message);
                }
                archiveNext(index + 1);
            })
            .catch(error => {
                console.error(`Error archiving item ${itemId}:`, error);
                failed++;
                archiveNext(index + 1);
            });
        };
        
        archiveNext(0);
    }
}
// Add this JavaScript to your existing department.php file
// Add these functions to your existing JavaScript

// Function to switch from cards to table view
function showTableView() {
    // Hide cards container with !important to override CSS
    const cardsContainer = document.getElementById('itemsCardsContainer');
    cardsContainer.style.setProperty('display', 'none', 'important');
    
    // Show table container
    const tableContainer = document.querySelector('.table-container');
    tableContainer.style.setProperty('display', 'block', 'important');
    
    // Hide the view toggle for table cards
    const viewToggle = document.getElementById('tableCardsViewToggle');
    
    // Maintain search state when switching views
    setTimeout(() => {
        performSearch();
    }, 100);
    if (viewToggle) {
        viewToggle.style.display = 'none';
    }
    
    // Populate the table with current items
    populateTableFromCards();
    
    // Close any open menus
    document.querySelectorAll('.card-action-menu').forEach(menu => {
        menu.classList.remove('show');
    });
}

// Show table for a single item (clicked from a card)
function showTableForItem(itemId) {
    // Hide cards and show table container
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.querySelector('.table-container');
    cardsContainer.style.setProperty('display', 'none', 'important');
    tableContainer.style.setProperty('display', 'block', 'important');
    
    // Find the item
    const item = allItems.find(i => i.id == itemId);
    if (!item) {
        // Fallback to normal behavior
        populateTableFromCards();
        return;
    }
    
    // Render only this item's category table with one row (the item)
    const categoryName = item.category || 'Uncategorized';
    const isViewer = document.body && document.body.dataset && document.body.dataset.userIsViewer === 'true';
    
    // For viewers, show borrow button; for others, show action dropdown
    let actionHtml = '';
    if (isViewer) {
        // Viewer: Show borrow button or pending status
        const itemStatus = (item.display_status || item.status || 'Unknown');
        const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance', 'Consumable'];
        const canBorrow = !nonBorrowableStatuses.includes(itemStatus);
        const hasPendingRequest = item.has_pending_request === 1 || item.has_pending_request === true;
        
        if (hasPendingRequest) {
            // Show pending approval status instead of button
            actionHtml = `
                <span class="pending-approval-badge" style="display: inline-block; padding: 6px 12px; background-color: #f59e0b; color: white; border-radius: 6px; font-size: 13px; font-weight: 500;">
                    Pending Approval Request
                </span>
            `;
        } else if (itemStatus === 'Consumable') {
            // Show consumable message instead of button
            actionHtml = `
                <span class="consumable-not-available-badge" style="display: inline-block; padding: 6px 12px; background-color: #fef3c7; color: #92400e; border-radius: 6px; font-size: 13px; font-weight: 500; border: 1px solid #fcd34d;">
                    Consumable not available to borrow
                </span>
            `;
        } else {
            let borrowTitle = 'Request to borrow this item';
            if (!canBorrow) {
                if (itemStatus === 'Borrowed') {
                    borrowTitle = 'Item is already borrowed';
                } else if (itemStatus === 'Broken') {
                    borrowTitle = 'Item is broken and cannot be borrowed';
                } else if (itemStatus === 'Missing') {
                    borrowTitle = 'Item is missing and cannot be borrowed';
                } else if (itemStatus === 'Lost') {
                    borrowTitle = 'Item is lost and cannot be borrowed';
                } else if (itemStatus === 'Under Maintenance') {
                    borrowTitle = 'Item is under maintenance and cannot be borrowed';
                } else {
                    borrowTitle = 'Item cannot be borrowed';
                }
            }
            actionHtml = `
                <button class="viewer-borrow-btn" onclick="openViewerBorrowModal(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.item_code || ''}')" ${!canBorrow ? 'disabled' : ''} title="${borrowTitle}">
                    <img src="image/book.png" alt="Borrow" style="width:14px;height:14px;vertical-align:middle;" />
                    <span style="white-space: nowrap;">Request to Borrow</span>
                </button>
            `;
        }
    } else {
        // Non-viewer: Show action dropdown
        actionHtml = `
            <div class="action-dropdown">
                <button class="action-btn" onclick="toggleActionMenu(${item.id})">⋮</button>
                <div class="action-menu" id="menu-${item.id}">
                    <button onclick="viewItem(${item.id})"><img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> View Details</button>
                    ${(item.display_status || item.status || 'Unknown') !== 'Borrowed' ? `
                    ${(IS_SUPER_ADMIN || item.department_name === USER_DEPARTMENT) ? `
                    <button onclick=\"editItem(${item.id})\"><img src=\"image/edit.png\" alt=\"Edit\" style=\"width:14px;height:14px;vertical-align:middle;margin-right:6px;\" /> Edit</button>
                    <button onclick=\"archiveItem(${item.id})\"><img src=\"image/icons8-archive-50.png\" alt=\"Archive\" style=\"width:14px;height:14px;vertical-align:middle;margin-right:6px;\" /> Archive</button>
                    ` : ''}
                    ` : ''}
                </div>
            </div>
        `;
    }
    
    const tableHTML = `
        <div class="category-table-wrap">
            <div class="category-title">${categoryName}</div>
            <table class="data-table category-table">
                <thead>
                    <tr>
                        <th onclick="setSort('id')">ID <span class="sort-indicator" data-col="id"></span></th>
                        <th onclick="setSort('name')">Name <span class="sort-indicator" data-col="name"></span></th>
                        <th onclick="setSort('department_name')">Department <span class="sort-indicator" data-col="department_name"></span></th>
                        <th onclick="setSort('category')">Categories <span class="sort-indicator" data-col="category"></span></th>
                        <th onclick="setSort('updated_at')">Date Last Updated <span class="sort-indicator" data-col="updated_at"></span></th>
                        <th onclick="setSort('status')">Status <span class="sort-indicator" data-col="status"></span></th>
                        <th onclick="setSort('location')">Location <span class="sort-indicator" data-col="location"></span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr onclick="handleRowClick(event, ${item.id})" style="cursor: pointer;">
                        <td>${item.id}</td>
                        <td><span class="item-name-text">${item.name}</span></td>
                        <td>${item.department_name}</td>
                        <td>${item.category}</td>
                        <td>${formatDate(item.updated_at)}</td>
                        <td><span class="status-badge status-${(item.display_status || item.status || 'Unknown').toLowerCase().replace(' ', '-')}">${item.display_status || item.status || 'Unknown'}</span></td>
                        <td>${item.location}</td>
                        <td>${actionHtml}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    `;
    
    tableContainer.innerHTML = tableHTML;
    
    // Close any open card menus
    document.querySelectorAll('.card-action-menu').forEach(menu => menu.classList.remove('show'));
    
    // Update summary for this single item
    updateSummary();
}



// Add these missing helper functions to your script section

function getItemIcon(category) {
    const iconMap = {
        'Computer Peripherals': '🖱️',
        'Mouse': '🖱️',
        'Keyboard': '⌨️',
        'Monitor': '🖥️',
        'Printer': '🖨️',
        'Scanner': '📠',
        'Laptop': '💻',
        'Desktop': '🖥️',
        'Server': '🗄️',
        'Network': '🌐',
        'Cable': '🔌',
        'Storage': '💾',
        'Audio': '🔊',
        'Camera': '📷',
        'Projector': '📽️',
        'Phone': '📞',
        'Tablet': '📱',
        'Accessories': '🔧',
        'Furniture': '🪑',
        'Cleaning': '🧽',
        'Office Supplies': '📋',
        'Security': '🔒',
        'Technology and Electronics': '💻'
    };
    
    return iconMap[category] || '📦';
}

function formatDateForCard(dateString) {
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric',
            year: '2-digit'
        });
    } catch (e) {
        return dateString;
    }
}

function toggleCardActionMenu(itemId) {
    // Close all other open menus
    document.querySelectorAll('.card-action-menu').forEach(menu => {
        if (menu.id !== `card-menu-${itemId}`) {
            menu.classList.remove('show');
        }
    });
    
    // Toggle current menu
    const menu = document.getElementById(`card-menu-${itemId}`);
    menu.classList.toggle('show');
    
    // Close menu when clicking outside
    document.addEventListener('click', function closeMenu(e) {
        if (!e.target.closest('.card-action-dropdown')) {
            menu.classList.remove('show');
            document.removeEventListener('click', closeMenu);
        }
    });
}
// Flag to prevent duplicate loading
let isUpdatingCardView = false;

async function updateCardView() {
    // Prevent duplicate calls
    if (isUpdatingCardView) {
        console.log('updateCardView already in progress, skipping duplicate call');
        return;
    }
    
    isUpdatingCardView = true;
    const cardsContainer = document.getElementById('itemsCardsContainer');
    
    if (!cardsContainer) {
        console.error('Cards container not found');
        isUpdatingCardView = false;
        return;
    }
    
    try {
        // Load item tables from database
        const response = await fetch('crud.php?action=get_item_tables');
        const data = await response.json();
        
        if (!data.success || !data.item_tables || data.item_tables.length === 0) {
            cardsContainer.innerHTML = `
                <div class="no-items-card">
                    <div class="no-items-icon">📦</div>
                    <h3>No Item Tables Found</h3>
                    <p>Create your first item table by clicking "ADD ITEM TABLE"</p>
                </div>
            `;
            return;
        }
        
        // Filter item tables by department if needed
        let tablesToShow = data.item_tables;
        if (selectedDepartmentId !== 'all') {
            tablesToShow = tablesToShow.filter(table => table.department_id == selectedDepartmentId);
        }
        
        // Create cards for each item table with quantity
        const cardsPromises = tablesToShow.map(async (table) => {
            const tableImage = table.table_image_path ? 
                `<img src="${table.table_image_path}" alt="${table.table_name}" class="item-image" />` : 
                `<div class="item-image-placeholder">📦</div>`;
            
            // Get item count for this table
            let itemCount = 0;
            try {
                const countResponse = await fetch(`crud.php?action=get_items_by_table&table_id=${table.id}`);
                const countData = await countResponse.json();
                if (countData.success && countData.items) {
                    itemCount = countData.items.length;
                }
            } catch (error) {
                console.error(`Error getting item count for table ${table.id}:`, error);
            }
            
            return `
                <div class="item-card clickable-card" data-table-id="${table.id}" onclick="showTableForItemTable('${table.id}', '${table.table_name}')">
                    <div class="item-image-container">
                        ${tableImage}
                    </div>
                    <div class="item-card-content">
                        <div class="item-title-row">
                            <div class="item-card-title">${table.table_name}</div>
                            <div class="card-action-dropdown">
                                <button class="card-action-btn-menu" onclick="event.stopPropagation(); toggleTableActionMenu('${table.id}')">⋮</button>
                                <div class="card-action-menu" id="card-menu-${table.id}">
                                    <button onclick="showTableForItemTable('${table.id}', '${table.table_name}')">
                                        <img src="image/table.png" alt="Table" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        View Table
                                    </button>
                                    ${(IS_SUPER_ADMIN || table.department_name === USER_DEPARTMENT) ? `
                                    <button onclick="event.stopPropagation(); openEditItemTableModal('${table.id}')">
                                        <img src="image/edit.png" alt="Edit" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Edit Item Table
                                    </button>
                                    ` : ''}
                                    ${table.qr_code && (IS_SUPER_ADMIN || table.department_name === USER_DEPARTMENT) ? `
                                    <button onclick="event.stopPropagation(); downloadQRCode('${table.id}', '${table.table_name}')">
                                        <img src="image/export.png" alt="Download" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Download QR Code
                                    </button>
                                    ` : ''}
                                    ${(document.body.dataset.userIsViewer !== 'true') && itemCount === 0 && (IS_SUPER_ADMIN || table.department_name === USER_DEPARTMENT) ? `
                                    <button onclick="event.stopPropagation(); deleteItemTable('${table.id}', '${table.table_name}')" style="color:#dc3545;">
                                        <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Delete Item Table
                                    </button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="quantity-text">Category: ${table.category}</div>
                        <div class="quantity-text">Items: ${itemCount}</div>
                        <div style="margin: 8px 0; display: flex; gap: 6px; flex-wrap: wrap;">
                            ${table.is_consumable == 1 ? `
                            <span style="
                                padding: 4px 10px;
                                border-radius: 12px;
                                font-size: 11px;
                                font-weight: 600;
                                text-transform: uppercase;
                                display: inline-block;
                                background-color: #fef3c7;
                                color: #92400e;
                            ">⚡ Consumable</span>
                            ` : ''}
                            ${table.priority && table.is_consumable != 1 ? `
                            <span style="
                                padding: 4px 10px;
                                border-radius: 12px;
                                font-size: 11px;
                                font-weight: 600;
                                text-transform: uppercase;
                                display: inline-block;
                                background-color: ${table.priority === 'high' ? '#fee2e2' : table.priority === 'medium' ? '#fef3c7' : '#d1fae5'};
                                color: ${table.priority === 'high' ? '#991b1b' : table.priority === 'medium' ? '#92400e' : '#065f46'};
                            ">${table.priority === 'high' ? '🔴 High' : table.priority === 'medium' ? '🟡 Medium' : '🟢 Low'}</span>
                            ` : ''}
                        </div>
                        <div class="meta-row">
                            <div class="meta">
                                <span class="meta-label">Department:</span>
                                <span class="meta-value">${table.department_name}</span>
                            </div>
                            <div class="meta">
                                <span class="meta-label">Created:</span>
                                <span class="meta-value">${formatDateForCard(table.created_at)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        // Wait for all promises to resolve
        const cardsHTML = await Promise.all(cardsPromises);
        
        // Double-check we're still updating (prevent race conditions)
        if (!isUpdatingCardView) {
            console.log('updateCardView flag was reset, skipping update');
            return;
        }
        
        // Clear container first to prevent duplicates
        cardsContainer.innerHTML = '';
        
        // Small delay to ensure DOM is ready, then add the new cards
        await new Promise(resolve => setTimeout(resolve, 0));
        
        // Final check before updating
        if (!isUpdatingCardView) {
            console.log('updateCardView cancelled before final update');
            return;
        }
        
        // Then add the new cards
        cardsContainer.innerHTML = cardsHTML.join('');
        
        // Show the cards container
        cardsContainer.style.display = 'grid';
        cardsContainer.style.visibility = 'visible';
        
    } catch (error) {
        console.error('Error loading item tables:', error);
        cardsContainer.innerHTML = `
            <div class="no-items-card">
                <div class="no-items-icon">❌</div>
                <h3>Error Loading Item Tables</h3>
                <p>Please refresh the page and try again</p>
            </div>
        `;
        // Show the cards container even on error
        cardsContainer.style.display = 'grid';
        cardsContainer.style.visibility = 'visible';
    } finally {
        // Always reset the flag
        isUpdatingCardView = false;
    }
}
function toggleTableActionMenu(tableId) {
    // Close all other open menus
    document.querySelectorAll('.card-action-menu').forEach(menu => {
        if (menu.id !== `card-menu-${tableId}`) {
            menu.classList.remove('show');
        }
    });
    
    // Toggle current menu
    const menu = document.getElementById(`card-menu-${tableId}`);
    menu.classList.toggle('show');
    
    // Close menu when clicking outside
    document.addEventListener('click', function closeMenu(e) {
        if (!e.target.closest('.card-action-dropdown')) {
            menu.classList.remove('show');
            document.removeEventListener('click', closeMenu);
        }
    });
}

async function deleteItemTable(tableId, tableName) {
    try {
        if (!confirm(`Delete item table "${tableName}"? This cannot be undone.`)) {
            return;
        }
        const resp = await fetch('crud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_item_table&id=${encodeURIComponent(tableId)}`
        });
        const data = await resp.json();
        if (data.success) {
            modal.success(data.message || 'Item table deleted');
            await updateCardView();
        } else {
            modal.error(data.message || 'Failed to delete item table');
        }
    } catch (e) {
        console.error('Error deleting item table:', e);
        modal.error('Error deleting item table');
    }
}

// Global variables for item selection
let selectedItems = new Set();
let currentTableItems = [];

// Generate selectable category table HTML
function generateSelectableCategoryTableHTML(categoryName, allItems, paginatedItems, currentPage) {
    const totalPages = Math.ceil(allItems.length / ITEMS_PER_PAGE);
    
    let tableHTML = `
        <div class="category-table-wrap">
            <div class="category-title">${categoryName}</div>
            <table class="data-table category-table">
                <thead>
                    <tr>
                        ${(document.body && document.body.dataset && document.body.dataset.userIsViewer !== 'true') ? `
                        <th class="checkbox-column">
                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()" />
                        </th>
                        ` : ''}
                        <th onclick="setSort('id')" class="sortable">ID <span class="sort-indicator" data-col="id"></span></th>
                        <th onclick="setSort('item_code')" class="sortable">Item Code <span class="sort-indicator" data-col="item_code"></span></th>
                        <th onclick="setSort('name')" class="sortable">Name <span class="sort-indicator" data-col="name"></span></th>
                        <th onclick="setSort('department_name')" class="sortable">Department <span class="sort-indicator" data-col="department_name"></span></th>
                        <th onclick="setSort('category')" class="sortable">Category <span class="sort-indicator" data-col="category"></span></th>
                        <th onclick="setSort('updated_at')" class="sortable">Modified <span class="sort-indicator" data-col="updated_at"></span></th>
                        <th onclick="setSort('status')" class="sortable">Status <span class="sort-indicator" data-col="status"></span></th>
                        <th onclick="setSort('location')" class="sortable">Location <span class="sort-indicator" data-col="location"></span></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    paginatedItems.forEach(item => {
        const isSelected = selectedItems.has(item.id);
        const isBorrowed = (item.display_status || item.status || 'Unknown') === 'Borrowed';
        const isViewer = document.body && document.body.dataset && document.body.dataset.userIsViewer === 'true';
        
        // Check if item belongs to user's department
        const userDept = USER_DEPARTMENT || '';
        // Super admins can select items from all departments
        // Department heads can only select items from their own department
        // Regular users/admins can only select items from their own department
        const itemDept = String(item.department_name || '');
        const userDeptStr = String(userDept || '');
        const isItemInUserDepartment = itemDept === userDeptStr;
        
        // Determine if checkbox should be shown and enabled
        // Super admins: can select all items (checkbox enabled)
        // Department heads: can only select items from their department (checkbox hidden if not in their department)
        // Others: can only select items from their department (checkbox disabled if not in their department)
        const canSelectItem = IS_SUPER_ADMIN || isItemInUserDepartment;
        const shouldShowCheckbox = IS_SUPER_ADMIN || isItemInUserDepartment || !isDepartmentHead;
        
        // For viewers, don't show checkbox
        let checkboxHtml = '';
        if (!isViewer) {
            if (isBorrowed) {
                checkboxHtml = '<td class="checkbox-column"><span style="color: #9ca3af; font-size: 12px;">N/A</span></td>';
            } else if (!canSelectItem && !shouldShowCheckbox) {
                // For department heads: completely hide checkbox for items not in their department
                checkboxHtml = '<td class="checkbox-column"></td>';
            } else if (!canSelectItem && shouldShowCheckbox) {
                // For non-department-head users: show disabled checkbox
                checkboxHtml = `<td class="checkbox-column">
                    <input type="checkbox" class="item-checkbox" value="${item.id}" disabled style="opacity: 0.5; cursor: not-allowed;" title="You can only select items from your own department" />
                </td>`;
            } else {
                // Checkbox enabled - user can select this item
                checkboxHtml = `<td class="checkbox-column">
                    <input type="checkbox" class="item-checkbox" value="${item.id}" ${isSelected ? 'checked' : ''} onchange="toggleItemSelection(${item.id}, event)" />
                </td>`;
            }
        }
        
        // For viewers, show borrow button; for others, show action dropdown
        let actionHtml = '';
        if (isViewer) {
            // Viewer: Show borrow button or pending status
            // Check if item can be borrowed - cannot borrow if status is Borrowed, Broken, Missing, Lost, Under Maintenance, or Consumable
            const itemStatus = (item.display_status || item.status || 'Unknown');
            const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance', 'Consumable'];
            const canBorrow = !nonBorrowableStatuses.includes(itemStatus);
            const hasPendingRequest = item.has_pending_request === 1 || item.has_pending_request === true;
            
            if (hasPendingRequest) {
                // Show pending approval status instead of button
                actionHtml = `
                    <td>
                        <span class="pending-approval-badge" style="display: inline-block; padding: 6px 12px; background-color: #f59e0b; color: white; border-radius: 6px; font-size: 13px; font-weight: 500;">
                            Pending Approval Request
                        </span>
                    </td>
                `;
            } else if (itemStatus === 'Consumable') {
                // Show consumable message instead of button
                actionHtml = `
                    <td>
                        <span class="consumable-not-available-badge" style="display: inline-block; padding: 6px 12px; background-color: #fef3c7; color: #92400e; border-radius: 6px; font-size: 13px; font-weight: 500; border: 1px solid #fcd34d;">
                            Consumable not available to borrow
                        </span>
                    </td>
                `;
            } else {
                let borrowTitle = 'Request to borrow this item';
                if (!canBorrow) {
                    if (itemStatus === 'Borrowed') {
                        borrowTitle = 'Item is already borrowed';
                    } else if (itemStatus === 'Broken') {
                        borrowTitle = 'Item is broken and cannot be borrowed';
                    } else if (itemStatus === 'Missing') {
                        borrowTitle = 'Item is missing and cannot be borrowed';
                    } else if (itemStatus === 'Lost') {
                        borrowTitle = 'Item is lost and cannot be borrowed';
                    } else if (itemStatus === 'Under Maintenance') {
                        borrowTitle = 'Item is under maintenance and cannot be borrowed';
                    } else {
                        borrowTitle = 'Item cannot be borrowed';
                    }
                }
                actionHtml = `
                    <td>
                        <button class="viewer-borrow-btn" onclick="openViewerBorrowModal(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.item_code || ''}')" ${!canBorrow ? 'disabled' : ''} title="${borrowTitle}">
                            <img src="image/book.png" alt="Borrow" style="width:14px;height:14px;vertical-align:middle;flex-shrink:0;" />
                            <span style="white-space: nowrap;">Request to Borrow</span>
                        </button>
                    </td>
                `;
            }
        } else {
            // Non-viewer: Show action dropdown
            actionHtml = `
                <td>
                    <div class="action-dropdown">
                        <button class="action-btn" onclick="toggleActionMenu(${item.id})">⋮</button>
                        <div class="action-menu" id="menu-${item.id}">
                            <button onclick="viewItem(${item.id})"><img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> View Details</button>
                            ${(item.display_status || item.status || 'Unknown') !== 'Borrowed' ? `
                            ${(IS_SUPER_ADMIN || item.department_name === USER_DEPARTMENT) ? `
                            <button onclick="editItem(${item.id})"><img src="image/edit.png" alt="Edit" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> Edit</button>
                            <button onclick="archiveItem(${item.id})"><img src="image/icons8-archive-50.png" alt="Archive" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> Archive</button>
                            ` : ''}
                            ` : ''}
                        </div>
                    </div>
                </td>
            `;
        }
        
        tableHTML += `
            <tr class="selectable ${isSelected ? 'selected' : ''}" data-item-id="${item.id}" onclick="handleRowClick(event, ${item.id})" style="cursor: pointer;">
                ${checkboxHtml}
                <td>${item.id}</td>
                <td><span class="item-code-text" style="font-family: monospace; font-weight: bold; color: #2563eb;">${item.item_code || 'N/A'}</span></td>
                <td><span class="item-name-text">${item.name}</span></td>
                <td>${item.department_name}</td>
                <td>${item.category}</td>
                <td>${formatDate(item.updated_at)}</td>
                <td><span class="status-badge status-${(item.display_status || item.status || 'Unknown').toLowerCase().replace(' ', '-')}">${item.display_status || item.status || 'Unknown'}</span></td>
                <td>${item.location}</td>
                ${actionHtml}
            </tr>
        `;
    });
    
    tableHTML += `
                </tbody>
            </table>
        </div>
    `;
    
    // Add pagination if needed
    if (totalPages > 1) {
        tableHTML += generatePaginationControls(categoryName, allItems.length, currentPage);
    }
    
    return tableHTML;
}

function showTableForItemTable(tableId, tableName) {
    console.log('showTableForItemTable called for:', tableId, tableName);
    
    // Store current table info for pagination
    window.currentTableId = tableId;
    window.currentTableName = tableName;
    
    // Update breadcrumb to show item table name
    updateBreadcrumb(selectedDepartmentName, null, tableName);
    
    // Update button text to "ADD ITEM" when viewing an item table
    updateAddItemButtonText(true);
    
    // Load items for this specific item table
    fetch(`crud.php?action=get_items_by_table&table_id=${tableId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error('Error loading items for table:', data.message);
                return;
            }
            
            let tableItems = data.items || [];
            
            // Note: Viewers can see all items, but borrow button will be disabled for non-borrowable items
            // We don't filter items here - let viewers see all items, but disable borrow for consumable items
            
            console.log('Found items for table:', tableItems.length);
            
            // Set current table items for print function
            currentTableItems = tableItems;
            window.currentTableItems = tableItems;
            
            // Hide cards and show table container
            const cardsContainer = document.getElementById('itemsCardsContainer');
            const tableContainer = document.querySelector('.table-container');
            
            // Force hide cards
            cardsContainer.style.display = 'none';
            cardsContainer.style.visibility = 'hidden';
            
            // Show search prompt instead of table initially
            if (tableItems.length === 0) {
                tableContainer.style.display = 'block';
                tableContainer.style.visibility = 'visible';
                tableContainer.innerHTML = `
                    <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 60px 40px; text-align: center;">
                        <div style="font-size: 64px; margin-bottom: 20px;">📦</div>
                        <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 20px; font-weight: 600;">No Items Found</h3>
                        <p style="color: #718096; font-size: 14px;">
                            This item table is empty.
                        </p>
                    </div>
                `;
            } else {
                // Show search prompt - require search first
                tableContainer.style.display = 'block';
                tableContainer.style.visibility = 'visible';
                tableContainer.innerHTML = `
                    <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 60px 40px; text-align: center;">
                        <div style="font-size: 64px; margin-bottom: 20px;">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="11" cy="11" r="8" stroke="#3b82f6" stroke-width="2" fill="none"/>
                                <path d="m21 21-4.35-4.35" stroke="#a855f7" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3 style="color: #2d3748; margin-bottom: 12px; font-size: 24px; font-weight: 600;">Search for Items to View Them</h3>
                        <p style="color: #718096; font-size: 16px; max-width: 500px; margin: 0 auto;">
                            Please use the search box above to search for items. Items will only be displayed after you perform a search.
                        </p>
                    </div>
                `;
            }
            
            // Close any open card menus
            document.querySelectorAll('.card-action-menu').forEach(menu => menu.classList.remove('show'));
            updateSummary();
        })
        .catch(error => {
            console.error('Error loading items for table:', error);
        });
}

function showTableForItemName(itemName) {
    console.log('showTableForItemName called for:', itemName);
    
    // Filter items by the specific item name
    let itemNameItems = allItems.filter(item => item.name === itemName);
    
    // For viewers, filter to only show items available to borrow
    const isViewer = document.body && document.body.dataset && document.body.dataset.userIsViewer === 'true';
    if (isViewer) {
        const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance'];
        itemNameItems = itemNameItems.filter(item => {
            const itemStatus = (item.display_status || item.status || 'Unknown');
            return !nonBorrowableStatuses.includes(itemStatus);
        });
    }
    
    if (selectedDepartmentId !== 'all') {
        itemNameItems = itemNameItems.filter(item => item.department_id == selectedDepartmentId);
    }
    
    console.log('Found items for item name:', itemNameItems.length);
    
    // ALWAYS hide cards and show table
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.querySelector('.table-container');
    
    // Force hide cards
    cardsContainer.style.display = 'none';
    cardsContainer.style.visibility = 'hidden';
    
    // Force show table
    tableContainer.style.display = 'block';
    tableContainer.style.visibility = 'visible';
    
    console.log('Cards display:', cardsContainer.style.display);
    console.log('Table display:', tableContainer.style.display);
    
    // Create single item name table
    const sortedItems = sortItemsArray(itemNameItems);
    const currentPage = initializeCategoryPagination(itemName, sortedItems);
    const paginatedItems = getPaginatedItems(sortedItems, currentPage);
    
    const tableHTML = generateCategoryTableHTML(itemName, sortedItems, paginatedItems, currentPage);
    tableContainer.innerHTML = tableHTML;
    
    // Close any open card menus
    document.querySelectorAll('.card-action-menu').forEach(menu => menu.classList.remove('show'));
    updateSummary();
    updateSortIndicators();
    
    // ✅ ADD THIS: Scroll to table container
    setTimeout(() => {
        tableContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
}


function updateSingleCategoryTable(categoryName, categoryItems) {
    const sortedItems = sortItemsArray(categoryItems);
    const currentPage = currentCategoryPage[categoryName] || 1;
    const paginatedItems = getPaginatedItems(sortedItems, currentPage);
    
    // Find and replace only this category's table
    const categoryWrap = document.querySelector(`[data-category="${categoryName}"]`);
    if (categoryWrap) {
        const newTableHTML = generateCategoryTableHTML(categoryName, sortedItems, paginatedItems, currentPage);
        const temp = document.createElement('div');
        temp.innerHTML = newTableHTML;
        categoryWrap.replaceWith(temp.firstElementChild);
        updateSortIndicators();
    }
}

// ✅ NEW: Sort items array
function sortItemsArray(items) {
    return [...items].sort((a,b) => {
        const col = currentSort;
        let av = a[col]; 
        let bv = b[col];
        
        if (col === 'name' || col === 'department_name' || col === 'category' || col === 'status' || col === 'location') {
            av = (av || '').toString().toLowerCase();
            bv = (bv || '').toString().toLowerCase();
            if (av < bv) return currentSortDir === 'asc' ? -1 : 1;
            if (av > bv) return currentSortDir === 'asc' ? 1 : -1;
            return 0;
        }
        if (col === 'id') {
            av = Number(av) || 0; 
            bv = Number(bv) || 0;
            return currentSortDir === 'asc' ? av - bv : bv - av;
        }
        if (col === 'updated_at') {
            const ad = new Date(a.updated_at || 0).getTime();
            const bd = new Date(b.updated_at || 0).getTime();
            return currentSortDir === 'asc' ? ad - bd : bd - ad;
        }
        return 0;
    });
}

// ✅ NEW: Generate category table HTML
function generateCategoryTableHTML(categoryName, allItemsInCategory, paginatedItems, currentPage) {
    const isViewer = document.body && document.body.dataset && document.body.dataset.userIsViewer === 'true';
    
    const rows = paginatedItems.map(item => {
        // For viewers, show borrow button; for others, show action dropdown
        let actionHtml = '';
        if (isViewer) {
            // Viewer: Show borrow button or pending status
            const itemStatus = (item.display_status || item.status || 'Unknown');
            const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance'];
            const canBorrow = !nonBorrowableStatuses.includes(itemStatus);
            const hasPendingRequest = item.has_pending_request === 1 || item.has_pending_request === true;
            
            if (hasPendingRequest) {
                // Show pending approval status instead of button
                actionHtml = `
                    <span class="pending-approval-badge" style="display: inline-block; padding: 6px 12px; background-color: #f59e0b; color: white; border-radius: 6px; font-size: 13px; font-weight: 500;">
                        Pending Approval Request
                    </span>
                `;
            } else {
                let borrowTitle = 'Request to borrow this item';
                if (!canBorrow) {
                    if (itemStatus === 'Borrowed') {
                        borrowTitle = 'Item is already borrowed';
                    } else if (itemStatus === 'Broken') {
                        borrowTitle = 'Item is broken and cannot be borrowed';
                    } else if (itemStatus === 'Missing') {
                        borrowTitle = 'Item is missing and cannot be borrowed';
                    } else if (itemStatus === 'Lost') {
                        borrowTitle = 'Item is lost and cannot be borrowed';
                    } else if (itemStatus === 'Under Maintenance') {
                        borrowTitle = 'Item is under maintenance and cannot be borrowed';
                    } else {
                        borrowTitle = 'Item cannot be borrowed';
                    }
                }
                actionHtml = `
                    <button class="viewer-borrow-btn" onclick="openViewerBorrowModal(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.item_code || ''}')" ${!canBorrow ? 'disabled' : ''} title="${borrowTitle}">
                        <img src="image/book.png" alt="Borrow" style="width:14px;height:14px;vertical-align:middle;" />
                        Request to Borrow
                    </button>
                `;
            }
        } else {
            // Non-viewer: Show action dropdown
            actionHtml = `
                <div class="action-dropdown">
                    <button class="action-btn" onclick="toggleActionMenu(${item.id})">⋮</button>
                    <div class="action-menu" id="menu-${item.id}">
                        <button onclick="viewItem(${item.id})"><img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> View Details</button>
                        ${(item.display_status || item.status || 'Unknown') !== 'Borrowed' ? `
                        ${(IS_SUPER_ADMIN || item.department_name === USER_DEPARTMENT) ? `
                        <button onclick="editItem(${item.id})"><img src="image/edit.png" alt="Edit" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> Edit</button>
                        <button onclick="archiveItem(${item.id})"><img src="image/icons8-archive-50.png" alt="Archive" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> Archive</button>
                        ` : ''}
                        ` : ''}
                    </div>
                </div>
            `;
        }
        
        return `
        <tr onclick="handleRowClick(event, ${item.id})" style="cursor: pointer;">
            <td>${item.id}</td>
            <td><span class="item-name-text">${item.name}</span></td>
            <td>${item.department_name}</td>
            <td>${item.category}</td>
            <td>${formatDate(item.updated_at)}</td>
            <td><span class="status-badge status-${(item.display_status || item.status || 'Unknown').toLowerCase().replace(' ', '-')}">${item.display_status || item.status || 'Unknown'}</span></td>
            <td>${item.location}</td>
            <td>${actionHtml}</td>
        </tr>
    `;
    }).join('');
    
    
        return `
    <div class="category-table-wrap" data-category="${categoryName}">
        <div class="category-title">
            <span>${categoryName} (${allItemsInCategory.length} items)</span>
            
        </div>
        <table class="data-table category-table">
                <thead>
                    <tr>
                        <th onclick="setSort('id')" class="sortable">ID <span class="sort-indicator" data-col="id"></span></th>
                        <th onclick="setSort('name')" class="sortable">Name <span class="sort-indicator" data-col="name"></span></th>
                        <th onclick="setSort('department_name')" class="sortable">Department <span class="sort-indicator" data-col="department_name"></span></th>
                        <th onclick="setSort('category')" class="sortable">Categories <span class="sort-indicator" data-col="category"></span></th>
                        <th onclick="setSort('updated_at')" class="sortable">Date Last Updated <span class="sort-indicator" data-col="updated_at"></span></th>
                        <th onclick="setSort('status')" class="sortable">Status <span class="sort-indicator" data-col="status"></span></th>
                        <th onclick="setSort('location')" class="sortable">Location <span class="sort-indicator" data-col="location"></span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows}
                </tbody>
            </table>
            ${generatePaginationControls(categoryName, allItemsInCategory.length, currentPage)}
        </div>
    `;
}


function initializeCategoryPagination(categoryName, items) {
    if (!currentCategoryPage[categoryName]) {
        currentCategoryPage[categoryName] = 1;
    }
    return currentCategoryPage[categoryName];
}

// Get paginated items for a category
function getPaginatedItems(items, page = 1) {
    const start = (page - 1) * ITEMS_PER_PAGE;
    const end = start + ITEMS_PER_PAGE;
    return items.slice(start, end);
}

// Toggle select all functionality
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const itemCheckboxes = document.querySelectorAll('.item-checkbox:not([disabled])');
    const userDept = USER_DEPARTMENT || '';
    
    itemCheckboxes.forEach(checkbox => {
        const itemId = parseInt(checkbox.value);
        
        if (selectAllCheckbox.checked) {
            // Only select items from user's department
            const item = allItems.find(i => i.id == itemId);
            const isUserDepartment = !userDept || IS_ADMIN || IS_SUPER_ADMIN || (item && String(item.department_name) === String(userDept));
            
            if (isUserDepartment) {
                selectedItems.add(itemId);
                checkbox.checked = true;
            } else {
                checkbox.checked = false;
            }
        } else {
            selectedItems.delete(itemId);
            checkbox.checked = false;
        }
    });
    
    updateSelectionUI();
    updateSelectedCount();
}

// Generate pagination controls HTML (KEEP AS IS)
function generatePaginationControls(categoryName, totalItems, currentPage) {
    const totalPages = Math.ceil(totalItems / ITEMS_PER_PAGE);
    
    if (totalPages <= 1) {
        return ''; // No pagination needed
    }
    
    const start = ((currentPage - 1) * ITEMS_PER_PAGE) + 1;
    const end = Math.min(currentPage * ITEMS_PER_PAGE, totalItems);
    
    let paginationHTML = `
        <div class="category-pagination">
            <div class="pagination-info">
                Showing ${start} to ${end} of ${totalItems} items
            </div>
            <div class="pagination-controls">
                <button class="page-btn" ${currentPage === 1 ? 'disabled' : ''} 
                    onclick="goToCategoryItemPage('${categoryName}', 1)">First</button>
                <button class="page-btn" ${currentPage === 1 ? 'disabled' : ''} 
                    onclick="goToCategoryItemPage('${categoryName}', ${currentPage - 1})">Previous</button>
                <div class="page-numbers">
    `;
    
    const maxPageButtons = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxPageButtons / 2));
    let endPage = Math.min(totalPages, startPage + maxPageButtons - 1);
    
    if (endPage - startPage < maxPageButtons - 1) {
        startPage = Math.max(1, endPage - maxPageButtons + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        paginationHTML += `
            <button class="page-num ${i === currentPage ? 'active' : ''}" 
                onclick="goToCategoryItemPage('${categoryName}', ${i})">${i}</button>
        `;
    }
    
    paginationHTML += `
                </div>
                <button class="page-btn" ${currentPage === totalPages ? 'disabled' : ''} 
                    onclick="goToCategoryItemPage('${categoryName}', ${currentPage + 1})">Next</button>
                <button class="page-btn" ${currentPage === totalPages ? 'disabled' : ''} 
                    onclick="goToCategoryItemPage('${categoryName}', ${totalPages})">Last</button>
            </div>
        </div>
    `;
    
    return paginationHTML;
}

// Navigate to specific page for categories (main table view)
function goToCategoryPage(page) {
    window.currentCategoryPage = page;
    const tableContainer = document.querySelector('.table-container');
    if (tableContainer && getComputedStyle(tableContainer).display !== 'none') {
        populateTableFromCards();
    }
}

// Navigate to specific page for items within a category
function goToCategoryItemPage(categoryName, page) {
    console.log(`Navigating ${categoryName} to page ${page}`);
    currentCategoryPage[categoryName] = page;
    
    // Check if we're viewing a specific item table
    if (window.currentTableId && window.currentTableName) {
        // We're in a specific table view, refresh the current table
        showTableForItemTable(window.currentTableId, window.currentTableName);
    } else {
        // We're in the main department view
        showTableForCategory(categoryName);
    }
}

// Function to populate table with current filtered items (separate table per category)
// Function to populate table with current filtered items (separate table per category with individual pagination)
function populateTableFromCards() {
    let itemsToShow = allItems;
    
    // For viewers, items are already filtered in loadAllItems, but double-check here
    const isViewer = document.body && document.body.dataset && document.body.dataset.userRole === 'viewer';
    if (isViewer) {
        const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance'];
        itemsToShow = itemsToShow.filter(item => {
            const itemStatus = (item.display_status || item.status || 'Unknown');
            return !nonBorrowableStatuses.includes(itemStatus);
        });
    }
    
    if (selectedDepartmentId !== 'all') {
        itemsToShow = itemsToShow.filter(item => item.department_id == selectedDepartmentId);
    }
    
    const tableContainer = document.querySelector('.table-container');
    
    if (!itemsToShow || itemsToShow.length === 0) {
        tableContainer.innerHTML = `
            <table class="data-table category-table">
                <thead>
                    <tr>
                        <th onclick="setSort('id')">ID <span class="sort-indicator" data-col="id"></span></th>
                        <th onclick="setSort('name')">Name <span class="sort-indicator" data-col="name"></span></th>
                        <th onclick="setSort('department_name')">Department <span class="sort-indicator" data-col="department_name"></span></th>
                        <th onclick="setSort('category')">Categories <span class="sort-indicator" data-col="category"></span></th>
                        <th onclick="setSort('updated_at')">Date Last Updated <span class="sort-indicator" data-col="updated_at"></span></th>
                        <th onclick="setSort('status')">Status <span class="sort-indicator" data-col="status"></span></th>
                        <th onclick="setSort('location')">Location <span class="sort-indicator" data-col="location"></span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="9" class="no-items" style="text-align: center; padding: 20px;">No items found</td></tr>
                </tbody>
            </table>
        `;
        updateSortIndicators();
        return;
    }
    
    // Group items by category
    const categoryToItems = {};
    itemsToShow.forEach(item => {
        const cat = item.category || 'Uncategorized';
        if (!categoryToItems[cat]) categoryToItems[cat] = [];
        categoryToItems[cat].push(item);
    });
    
    const sortedCategories = Object.keys(categoryToItems).sort((a, b) => a.localeCompare(b));
    let tablesHTML = '';
    
    // BAGUHIN: Gawing per-category ang pagination
    sortedCategories.forEach(categoryName => {
        // Sort all items in this category
        const allCategoryItems = categoryToItems[categoryName].sort((a,b)=>{
            const col = currentSort;
            let av = a[col]; let bv = b[col];
            if (col === 'name' || col === 'department_name' || col === 'category' || col === 'status' || col === 'location') {
                av = (av || '').toString().toLowerCase();
                bv = (bv || '').toString().toLowerCase();
                if (av < bv) return currentSortDir === 'asc' ? -1 : 1;
                if (av > bv) return currentSortDir === 'asc' ? 1 : -1;
                return 0;
            }
            if (col === 'id') {
                av = Number(av) || 0; bv = Number(bv) || 0;
                return currentSortDir === 'asc' ? av - bv : bv - av;
            }
            if (col === 'updated_at') {
                const ad = new Date(a.updated_at || 0).getTime();
                const bd = new Date(b.updated_at || 0).getTime();
                return currentSortDir === 'asc' ? ad - bd : bd - ad;
            }
            return 0;
        });
        
        // ✅ PER-CATEGORY PAGINATION
        const currentPage = initializeCategoryPagination(categoryName, allCategoryItems);
        const paginatedItems = getPaginatedItems(allCategoryItems, currentPage);
        
        console.log(`Category: ${categoryName}, Total: ${allCategoryItems.length}, Page: ${currentPage}, Showing: ${paginatedItems.length}`);
        
        // Generate table rows for PAGINATED items only
        const isViewer = document.body && document.body.dataset && document.body.dataset.userIsViewer === 'true';
        const rows = paginatedItems.map(item => {
            // For viewers, show borrow button; for others, show action dropdown
            let actionHtml = '';
            if (isViewer) {
                // Viewer: Show borrow button or pending status
                // Check if item can be borrowed - cannot borrow if status is Borrowed, Broken, Missing, Lost, Under Maintenance, or Consumable
                const itemStatus = (item.display_status || item.status || 'Unknown');
                const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance', 'Consumable'];
                const canBorrow = !nonBorrowableStatuses.includes(itemStatus);
                const hasPendingRequest = item.has_pending_request === 1 || item.has_pending_request === true;
                
                if (hasPendingRequest) {
                    // Show pending approval status instead of button
                    actionHtml = `
                        <td>
                            <span class="pending-approval-badge" style="display: inline-block; padding: 6px 12px; background-color: #f59e0b; color: white; border-radius: 6px; font-size: 13px; font-weight: 500;">
                                Pending Approval Request
                            </span>
                        </td>
                    `;
                } else if (itemStatus === 'Consumable') {
                    // Show consumable message instead of button
                    actionHtml = `
                        <td>
                            <span class="consumable-not-available-badge" style="display: inline-block; padding: 6px 12px; background-color: #fef3c7; color: #92400e; border-radius: 6px; font-size: 13px; font-weight: 500; border: 1px solid #fcd34d;">
                                Consumable not available to borrow
                            </span>
                        </td>
                    `;
                } else {
                    let borrowTitle = 'Request to borrow this item';
                    if (!canBorrow) {
                        if (itemStatus === 'Borrowed') {
                            borrowTitle = 'Item is already borrowed';
                        } else if (itemStatus === 'Broken') {
                            borrowTitle = 'Item is broken and cannot be borrowed';
                        } else if (itemStatus === 'Missing') {
                            borrowTitle = 'Item is missing and cannot be borrowed';
                        } else if (itemStatus === 'Lost') {
                            borrowTitle = 'Item is lost and cannot be borrowed';
                        } else if (itemStatus === 'Under Maintenance') {
                            borrowTitle = 'Item is under maintenance and cannot be borrowed';
                        } else {
                            borrowTitle = 'Item cannot be borrowed';
                        }
                    }
                    actionHtml = `
                        <td>
                            <button class="viewer-borrow-btn" onclick="openViewerBorrowModal(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.item_code || ''}')" ${!canBorrow ? 'disabled' : ''} title="${borrowTitle}">
                                <img src="image/book.png" alt="Borrow" style="width:14px;height:14px;vertical-align:middle;" />
                                Request to Borrow
                            </button>
                        </td>
                    `;
                }
            } else {
                // Non-viewer: Show action dropdown
                actionHtml = `
                    <td>
                        <div class="action-dropdown">
                            <button class="action-btn" onclick="toggleActionMenu(${item.id})">⋮</button>
                            <div class="action-menu" id="menu-${item.id}">
                                <button onclick="viewItem(${item.id})"><img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:5px;" /> View Details</button>
                                ${(item.display_status || item.status || 'Unknown') !== 'Borrowed' ? `
                                ${(IS_SUPER_ADMIN || item.department_name === USER_DEPARTMENT) ? `
                                <button onclick="editItem(${item.id})"><img src="image/edit.png" alt="Edit" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> Edit</button>
                                <button onclick="archiveItem(${item.id})"><img src="image/icons8-archive-50.png" alt="Archive" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> Archive</button>
                                ` : ''}
                                ` : ''}
                            </div>
                        </div>
                    </td>
                `;
            }
            
            return `
                <tr>
                    <td>${item.id}</td>
                    <td><span class="item-code-text" style="font-family: monospace; font-weight: bold; color: #2563eb;">${item.item_code || 'N/A'}</span></td>
                    <td><span class="item-name-text">${item.name}</span></td>
                    <td>${item.department_name}</td>
                    <td>${item.category}</td>
                    <td>${formatDate(item.updated_at)}</td>
                    <td><span class="status-badge status-${(item.display_status || item.status || 'Unknown').toLowerCase().replace(' ', '-')}">${item.display_status || item.status || 'Unknown'}</span></td>
                    <td>${item.location}</td>
                    ${actionHtml}
                </tr>
            `;
        }).join('');
        
        tablesHTML += `
    <div class="category-table-wrap" data-category="${categoryName}">
        <div class="category-title">${categoryName} (${allCategoryItems.length} items)</div>
        <table class="data-table category-table">
                    <thead>
                        <tr>
                            <th onclick="setSort('id')" class="sortable">ID <span class="sort-indicator" data-col="id"></span></th>
                            <th onclick="setSort('name')" class="sortable">Name <span class="sort-indicator" data-col="name"></span></th>
                            <th onclick="setSort('department_name')" class="sortable">Department <span class="sort-indicator" data-col="department_name"></span></th>
                            <th onclick="setSort('category')" class="sortable">Categories <span class="sort-indicator" data-col="category"></span></th>
                            <th onclick="setSort('updated_at')" class="sortable">Date Last Updated <span class="sort-indicator" data-col="updated_at"></span></th>
                            <th onclick="setSort('status')" class="sortable">Status <span class="sort-indicator" data-col="status"></span></th>
                            <th onclick="setSort('location')" class="sortable">Location <span class="sort-indicator" data-col="location"></span></th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
                ${generatePaginationControls(categoryName, allCategoryItems.length, currentPage)}
            </div>
        `;
    });
    
    tableContainer.innerHTML = tablesHTML;
    updateSummary();
    updateSortIndicators();
}
// Helper function to show card view
function showCardView() {
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.querySelector('.table-container');
    const noFilterMessage = document.getElementById('noFilterMessage');
    
    // Check if there's a search term - only show items if search is performed
    const searchInput = document.getElementById('nameFilter');
    const searchTerm = searchInput ? searchInput.value.trim() : '';
    
    // If no search term, hide items
    if (!searchTerm) {
        if (cardsContainer) {
            cardsContainer.style.display = 'none';
        }
        if (tableContainer) {
            tableContainer.style.display = 'none';
        }
        return;
    }
    
    // Hide "no filter" message
    if (noFilterMessage) {
        noFilterMessage.style.display = 'none';
    }
    
    // Show cards only if there's a search term
    if (cardsContainer) {
        cardsContainer.style.display = 'grid';
        cardsContainer.style.visibility = 'visible';
    }
    
    // Hide table
    if (tableContainer) {
        tableContainer.style.display = 'none';
        tableContainer.style.visibility = 'hidden';
    }
    
    // Maintain search state when switching views
    setTimeout(() => {
        performSearch();
    }, 100);
    
    // Show the view toggle for table cards
    const viewToggle = document.getElementById('tableCardsViewToggle');
    if (viewToggle) {
        viewToggle.style.display = 'inline-flex';
        console.log('View toggle shown');
    } else {
        console.error('View toggle not found');
    }
    
    // Update card view with current items
    updateCardView();
}

// Function to switch table cards to grid view
function switchToGridView() {
    console.log('Switching to grid view');
    const cardsContainer = document.getElementById('itemsCardsContainer');
    if (cardsContainer) {
        cardsContainer.classList.remove('list-layout');
        cardsContainer.classList.add('grid-layout');
        console.log('Grid layout applied');
    } else {
        console.error('Cards container not found');
    }
    updateViewToggleButtons('grid');
}

// Function to switch table cards to list view
function switchToListView() {
    console.log('Switching to list view');
    const cardsContainer = document.getElementById('itemsCardsContainer');
    if (cardsContainer) {
        cardsContainer.classList.remove('grid-layout');
        cardsContainer.classList.add('list-layout');
        console.log('List layout applied');
    } else {
        console.error('Cards container not found');
    }
    updateViewToggleButtons('list');
}

// Function to update view toggle button states
function updateViewToggleButtons(activeView) {
    const gridBtn = document.getElementById('gridViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    
    if (activeView === 'grid') {
        gridBtn.classList.add('active');
        listBtn.classList.remove('active');
    } else {
        listBtn.classList.add('active');
        gridBtn.classList.remove('active');
    }
}
// Replace your existing displayItems function with this
function displayItems(items) {
    // Prevent conflict with updateCardView
    if (isUpdatingCardView) {
        console.log('updateCardView in progress, skipping displayItems to prevent conflict');
        return;
    }
    
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.querySelector('.table-container');
    
    if (!cardsContainer) {
        console.error('Cards container not found');
        return;
    }
    
    // Show cards container
    cardsContainer.style.display = 'grid';
    cardsContainer.style.visibility = 'visible';
    
    // Hide table container
    tableContainer.style.display = 'none';
    tableContainer.style.visibility = 'hidden';
    
    // Show the view toggle for individual item cards
    const viewToggle = document.getElementById('tableCardsViewToggle');
    if (viewToggle) {
        viewToggle.style.display = 'inline-flex';
    }
    
    if (!items || items.length === 0) {
        // Clear container first
        cardsContainer.innerHTML = '';
        cardsContainer.innerHTML = `
            <div class="no-items-card">
                <div class="no-items-icon">📦</div>
                <h3>No Items Found</h3>
                <p>No items found for the selected department/category</p>
            </div>
        `;
        return;
    }
    
    // Create cards for each individual item
    const isViewer = document.body && document.body.dataset && document.body.dataset.userRole === 'viewer';
    const cardsHTML = items.map(item => {
        const itemImage = item.table_image_path ? 
            `<img src="${item.table_image_path}" alt="${item.name}" class="item-image" />` : 
            `<div class="item-image-placeholder">${getItemIcon(item.category)}</div>`;
        
        // For viewers, show borrow button instead of action dropdown
        let actionButton = '';
        if (isViewer) {
            // Check if item can be borrowed - cannot borrow if status is Borrowed, Broken, Missing, Lost, or Under Maintenance
            const itemStatus = (item.display_status || item.status || 'Unknown');
            const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance'];
            const canBorrow = !nonBorrowableStatuses.includes(itemStatus);
            const hasPendingRequest = item.has_pending_request === 1 || item.has_pending_request === true;
            
            if (hasPendingRequest) {
                // Show pending approval status instead of button
                actionButton = `
                    <span class="pending-approval-badge" style="display: inline-block; padding: 6px 12px; background-color: #f59e0b; color: white; border-radius: 6px; font-size: 13px; font-weight: 500; margin-top: 10px; width: 100%; text-align: center;">
                        Pending Approval Request
                    </span>
                `;
            } else {
                let borrowTitle = 'Request to borrow this item';
                if (!canBorrow) {
                    if (itemStatus === 'Borrowed') {
                        borrowTitle = 'Item is already borrowed';
                    } else if (itemStatus === 'Broken') {
                        borrowTitle = 'Item is broken and cannot be borrowed';
                    } else if (itemStatus === 'Missing') {
                        borrowTitle = 'Item is missing and cannot be borrowed';
                    } else if (itemStatus === 'Lost') {
                        borrowTitle = 'Item is lost and cannot be borrowed';
                    } else if (itemStatus === 'Under Maintenance') {
                        borrowTitle = 'Item is under maintenance and cannot be borrowed';
                    } else {
                        borrowTitle = 'Item cannot be borrowed';
                    }
                }
                actionButton = `
                    <button class="viewer-borrow-btn" onclick="event.stopPropagation(); openViewerBorrowModal(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.item_code || ''}')" ${!canBorrow ? 'disabled' : ''} title="${borrowTitle}" style="margin-top: 10px; width: 100%;">
                        <img src="image/book.png" alt="Borrow" style="width:14px;height:14px;vertical-align:middle;" />
                        Request to Borrow
                    </button>
                `;
            }
        } else {
            actionButton = `
                <div class="card-action-dropdown">
                    <button class="card-action-btn-menu" onclick="event.stopPropagation(); toggleCardActionMenu(${item.id})">⋮</button>
                    <div class="card-action-menu" id="card-menu-${item.id}">
                        <button onclick="viewItem(${item.id})">
                            <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                            View Details
                        </button>
                        ${(item.display_status || item.status || 'Unknown') !== 'Borrowed' ? `
                        ${(IS_SUPER_ADMIN || item.department_name === USER_DEPARTMENT) ? `
                        <button onclick="editItem(${item.id})">
                            <img src="image/edit.png" alt="Edit" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                            Edit
                        </button>
                        <button onclick="archiveItem(${item.id})" class="delete-action">
                            <img src="image/icons8-archive-50.png" alt="Archive" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                            Archive
                        </button>
                        ` : ''}
                        ` : ''}
                    </div>
                </div>
            `;
        }
        
        return `
            <div class="item-card clickable-card" data-item-id="${item.id}" onclick="viewItem(${item.id})">
                <div class="item-image-container">
                    ${itemImage}
                </div>
                <div class="item-card-content">
                    <div class="item-title-row">
                        <div class="item-card-title">${item.name}</div>
                        ${!isViewer ? actionButton : ''}
                    </div>
                    ${isViewer ? actionButton : ''}
                    <div class="quantity-text">Quantity: ${item.quantity}</div>
                    <div class="meta-row">
                        <div class="meta">
                            <span class="meta-label">Category:</span>
                            <span class="meta-value">${item.category || 'Uncategorized'}</span>
                        </div>
                        <div class="meta">
                            <span class="meta-label">Department:</span>
                            <span class="meta-value">${item.department_name || 'Unknown'}</span>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta">
                            <span class="meta-label">Status:</span>
                            <span class="meta-value status-${(item.display_status || item.status || 'Unknown').toLowerCase().replace(' ', '-')}">${item.display_status || item.status || 'Unknown'}</span>
                        </div>
                        <div class="meta">
                            <span class="meta-label">Created:</span>
                            <span class="meta-value">${formatDate(item.created_at)}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    // Clear container first to prevent duplicates
    cardsContainer.innerHTML = '';
    
    // Then add the new cards
    cardsContainer.innerHTML = cardsHTML;
    updateSummary();
}

// Update your loadAllItems function to properly call updateCardView
function loadAllItems() {
    // Prevent duplicate calls
    if (isLoadingAllItems) {
        console.log('loadAllItems already in progress, skipping duplicate call');
        return Promise.resolve();
    }
    
    isLoadingAllItems = true;
    
    return fetch('crud.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                allItems = data.items;
                
                // For viewers, filter to only show items available to borrow
                const isViewer = document.body && document.body.dataset && document.body.dataset.userIsViewer === 'true';
                if (isViewer) {
                    const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance'];
                    allItems = allItems.filter(item => {
                        const itemStatus = (item.display_status || item.status || 'Unknown');
                        return !nonBorrowableStatuses.includes(itemStatus);
                    });
                    console.log('Filtered items for viewer:', allItems.length, 'items available to borrow');
                }
                
                organizeDepartmentData();
                buildTreeStructure();
                
                // Don't auto-display items - wait for search
                // Items will only be displayed after user performs a search
                updateSummary();
            } else {
                console.error('Error loading items:', data.message);
                showNoItemsCard('Error loading items');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showNoItemsCard('Error connecting to server');
        })
        .finally(() => {
            // Always reset the flag
            isLoadingAllItems = false;
        });
}

// Helper function for showing no items in card view
function showNoItemsCard(message) {
    const cardsContainer = document.getElementById('itemsCardsContainer');
    cardsContainer.innerHTML = `
        <div class="no-items-card">
            <div class="no-items-icon">📦</div>
            <h3>No Items Found</h3>
            <p>${message}</p>
        </div>
    `;
}

// Update your DOMContentLoaded event listener
document.addEventListener('DOMContentLoaded', function() {
    // Don't load items automatically - wait for search
    // loadAllItems(); // Commented out - items should only load after search
    
    // Hide cards container initially - will show after search
    const cardsContainer = document.getElementById('itemsCardsContainer');
    if (cardsContainer) {
        cardsContainer.style.display = 'none';
        cardsContainer.classList.add('grid-layout');
    }
    
    // Add right-click context menu event listener
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        const contextMenu = document.getElementById('contextMenu');
        if (selectedItems.size > 0) {
            contextMenu.style.display = 'block';
            contextMenu.style.left = e.pageX + 'px';
            contextMenu.style.top = e.pageY + 'px';
        }
    });
    
    // Hide context menu when clicking elsewhere
    document.addEventListener('click', function(e) {
        const contextMenu = document.getElementById('contextMenu');
        if (!e.target.closest('.context-menu')) {
            contextMenu.style.display = 'none';
        }
    });
    
    // Other event listeners...
    if (document.getElementById('borrowQuantity')) {
        document.getElementById('borrowQuantity').addEventListener('input', function() {
            const max = parseInt(this.max);
            const value = parseInt(this.value);
            
            if (value > max) {
                this.value = max;
            }
        });
    }
    
    // Date validation
    if (document.getElementById('borrowDate')) {
        document.getElementById('borrowDate').addEventListener('change', function() {
            const borrowDate = new Date(this.value);
            const dueDateInput = document.getElementById('dueDate');
            
            // Set minimum due date to borrow date + 1 day
            const minDueDate = new Date(borrowDate);
            minDueDate.setDate(minDueDate.getDate() + 1);
            dueDateInput.min = minDueDate.toISOString().split('T')[0];
            
            // If current due date is invalid, update it
            if (new Date(dueDateInput.value) <= borrowDate) {
                const suggestedDue = new Date(borrowDate);
                suggestedDue.setDate(suggestedDue.getDate() + 7);
                dueDateInput.value = suggestedDue.toISOString().split('T')[0];
            }
        });
    }
});

// Enhanced View Item Functions
// Handle row click - open view details unless clicking on checkbox, button, or action menu
function handleRowClick(event, itemId) {
    // Don't open modal if clicking on:
    // - Checkbox
    // - Action button or menu
    // - Any button or link
    const target = event.target;
    const isCheckbox = target.type === 'checkbox' || target.closest('.checkbox-column');
    const isButton = target.tagName === 'BUTTON' || target.closest('button');
    const isActionMenu = target.closest('.action-dropdown') || target.closest('.action-menu');
    const isLink = target.tagName === 'A' || target.closest('a');
    
    if (isCheckbox || isButton || isActionMenu || isLink) {
        return; // Let the default action happen
    }
    
    // Open view details modal
    viewItem(itemId);
}

function viewItem(itemId) {
    console.log('Opening modal for item:', itemId);
    
    // AGGRESSIVELY clear QR section FIRST before anything else
    const qrSection = document.querySelector('.qr-section');
    if (qrSection) {
        qrSection.innerHTML = ''; // Completely clear
    }
    
    // Clear any pending messages
    const qrDownloadButtonContainer = document.getElementById('qrDownloadButtonContainer');
    if (qrDownloadButtonContainer) {
        qrDownloadButtonContainer.innerHTML = ''; // Clear entire container
    }
    
    // Reset currentViewingItemId to null first
    window.currentViewingItemId = null;
    window.currentItemData = null;
    
    // Prevent multiple modals from opening
    if (window.isModalOpening) {
        console.log('Modal already opening, ignoring request');
        return;
    }
    
    const item = allItems.find(i => i.id == itemId);
    if (!item) {
        modal.error('Item not found');
        return;
    }
    
    window.isModalOpening = true;
    
    // Ensure modal is completely closed first
    const modal = document.getElementById('itemDetailModal');
    modal.style.display = 'none';
    modal.classList.remove('show');
    
    // Clear any existing close timeout
    if (window.modalCloseTimeout) {
        clearTimeout(window.modalCloseTimeout);
        window.modalCloseTimeout = null;
    }
    
    // Small delay to ensure modal is reset
    setTimeout(() => {
        try {
            modal.style.display = 'flex';
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            document.body.classList.add('modal-open');
            
            // Explicitly enable the close button for viewers
            const closeBtn = document.getElementById('closeItemDetailBtn');
            if (closeBtn) {
                closeBtn.disabled = false;
                closeBtn.style.pointerEvents = 'auto';
                closeBtn.style.cursor = 'pointer';
                // Ensure onclick handler is set
                closeBtn.onclick = function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    closeItemDetailModal();
                };
            }
            
            // Small delay to ensure modal is visible before populating
            setTimeout(() => {
                populateItemDetailModal(item);
                
                // Ensure modal is scrolled to top
                setTimeout(() => {
                    modal.scrollTop = 0;
                }, 10);
                
                // Close any open action menus
                document.querySelectorAll('.action-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
                updateSortIndicators();
                
                window.currentViewingItemId = itemId;
            }, 100);
        } catch (error) {
            console.error('Error opening modal:', error);
        } finally {
            window.isModalOpening = false;
        }
    }, 50);
}

// Enhanced modal closing
function closeItemDetailModal() {
    console.log('Closing modal');
    
    const modal = document.getElementById('itemDetailModal');
    if (!modal) {
        console.error('Modal not found');
        return;
    }
    
    // Immediately hide the modal
    modal.style.display = 'none';
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
    document.body.classList.remove('modal-open');
    
    // Reset opening flag
    window.isModalOpening = false;
    window.currentViewingItemId = null;
    window.currentItemData = null;
    
    // AGGRESSIVELY clear QR section when closing modal
    const qrSection = document.querySelector('.qr-section');
    if (qrSection) {
        qrSection.innerHTML = ''; // Completely clear
    }
    
    // Clear any pending messages
    const qrDownloadButtonContainer = document.getElementById('qrDownloadButtonContainer');
    if (qrDownloadButtonContainer) {
        qrDownloadButtonContainer.innerHTML = ''; // Clear entire container
    }
    
    // Clear any existing timeout
    if (window.modalCloseTimeout) {
        clearTimeout(window.modalCloseTimeout);
        window.modalCloseTimeout = null;
    }
    
    console.log('Modal closed successfully');
}

function populateItemDetailModal(item) {
    // AGGRESSIVELY clear QR section FIRST to prevent any stale content
    const qrSection = document.querySelector('.qr-section');
    if (qrSection) {
        qrSection.innerHTML = ''; // Completely clear
    }
    
    // Clear any pending messages
    const qrDownloadButtonContainer = document.getElementById('qrDownloadButtonContainer');
    if (qrDownloadButtonContainer) {
        qrDownloadButtonContainer.innerHTML = ''; // Clear entire container
    }
    
    // Reset currentViewingItemId to null first, then set to new item
    window.currentViewingItemId = null;
    
    // Debug: Log the item data to see what's available
    console.log('Item data for modal:', item);
    console.log('table_image_path:', item.table_image_path);
    
    // Helper function to safely set text content
    function safeSetTextContent(elementId, value) {
        const element = document.getElementById(elementId);
        if (element) {
            element.textContent = value;
        } else {
            console.warn(`Element with id '${elementId}' not found`);
        }
    }
    
    // Helper function to safely set innerHTML
    function safeSetInnerHTML(elementId, value) {
        const element = document.getElementById(elementId);
        if (element) {
            element.innerHTML = value;
        } else {
            console.warn(`Element with id '${elementId}' not found`);
        }
    }
    
    // Set header information
    safeSetTextContent('itemDetailTitle', 'ITEM DETAILS');
    safeSetTextContent('itemDetailSubtitle', 'Complete item information');
    
    // Set item icon - only show item image if available, otherwise white background only
    const itemIconElement = document.getElementById('itemDetailIcon');
    if (item.image_path && item.image_path.trim() !== '') {
        console.log('Using item image:', item.image_path);
        // Reset styles first
        itemIconElement.style.background = '';
        itemIconElement.style.width = '';
        itemIconElement.style.height = '';
        itemIconElement.style.borderRadius = '';
        itemIconElement.innerHTML = `<img src="${item.image_path}" alt="${item.name}" style="max-width: 100%; max-height: 100%; width: 100%; height: 100%; object-fit: contain; border-radius: 8px;" onerror="this.parentElement.innerHTML=''; this.parentElement.style.background='white'; this.parentElement.style.width='100px'; this.parentElement.style.height='100px'; this.parentElement.style.borderRadius='10px';" />`;
    } else {
        console.log('No item image available, showing white background only');
        itemIconElement.innerHTML = '';
        itemIconElement.style.background = 'white';
        itemIconElement.style.width = '100px';
        itemIconElement.style.height = '100px';
        itemIconElement.style.borderRadius = '10px';
    }
    
    // Set main item information with your system's structure
    safeSetTextContent('itemTitleDisplay', item.name.toUpperCase());
    safeSetTextContent('detailName', item.name);
    safeSetTextContent('detailId', item.id);
    safeSetTextContent('detailCategory', item.category);
    safeSetTextContent('detailUpdated', formatDateForModal(item.updated_at));
    safeSetTextContent('detailLocation', item.location);
    // Use display_status if available, otherwise fall back to status
    const statusToUse = item.display_status || item.status || 'Unknown';
    safeSetInnerHTML('detailStatus', `<span class="status-${statusToUse.toLowerCase().replace(/\s+/g, '-')}">${statusToUse}</span>`);
    safeSetTextContent('detailDepartment', item.department_name);
    
    // Set description (no fixed placeholder)
    const description = item.description || 'No description available for this item.';
    safeSetTextContent('detailDescription', description);
    
    // Set QR item code
    safeSetTextContent('qrItemCode', item.item_code || 'N/A');
    
    // Check if user is a teacher or viewer (not admin/super admin)
    // Teacher = has department but not admin/super admin
    // Viewer = no department and not admin/super admin
    const shouldHideQr = !IS_ADMIN && !IS_SUPER_ADMIN;
    
    // Function to hide QR code elements for teachers and viewers
    function hideQrForNonAdmins() {
        if (!shouldHideQr) return;
        
        // Hide all QR-related elements
        const qrElements = [
            '.detail-right-column',
            '.qr-section',
            '.qr-code-container',
            '#detailQrImage',
            '.qr-info',
            '.btn-download[onclick*="downloadQrFromDetail"]',
            '.qr-item-detail'
        ];
        
        qrElements.forEach(selector => {
            const elements = document.querySelectorAll(selector);
            elements.forEach(el => {
                el.style.display = 'none';
                el.style.visibility = 'hidden';
                el.style.opacity = '0';
                el.style.height = '0';
                el.style.overflow = 'hidden';
            });
        });
    }
    
    // Hide QR section immediately for teachers and viewers
    if (shouldHideQr) {
        hideQrForNonAdmins();
        
        // Use MutationObserver to watch for any dynamic changes
        const modal = document.getElementById('itemDetailModal');
        if (modal) {
            const observer = new MutationObserver(() => {
                hideQrForNonAdmins();
            });
            observer.observe(modal, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['style', 'class']
            });
            
            // Also hide on any interval check
            const hideInterval = setInterval(() => {
                hideQrForNonAdmins();
            }, 100);
            
            // Clean up when modal closes
            const originalClose = window.closeItemDetailModal;
            if (originalClose) {
                window.closeItemDetailModal = function() {
                    observer.disconnect();
                    clearInterval(hideInterval);
                    originalClose();
                };
            }
        }
    } else {
        // AGGRESSIVELY clear QR section FIRST (before setting new item ID)
        const qrSection = document.querySelector('.qr-section');
        if (qrSection) {
            qrSection.innerHTML = ''; // Completely clear
        }
        
        // Clear any pending messages
        const qrDownloadButtonContainer = document.getElementById('qrDownloadButtonContainer');
        if (qrDownloadButtonContainer) {
            qrDownloadButtonContainer.innerHTML = ''; // Clear entire container
        }
        
        // Store current item ID FIRST to prevent race conditions (before any async operations)
        window.currentViewingItemId = item.id;
        
        // Show QR code section only for admins and super admins AND same department
        // Check department permission first
        const canViewQr = IS_SUPER_ADMIN || !USER_DEPARTMENT || item.department_name === USER_DEPARTMENT;
        
        if (canViewQr) {
        const qrRightColumn = document.querySelector('.detail-right-column');
        if (qrRightColumn) {
            qrRightColumn.style.display = '';
            qrRightColumn.style.visibility = 'visible';
        }
            // Check if QR should be shown before generating
            checkAndShowQrForDetail(item);
        } else {
            // Hide QR section completely if not user's department
            const qrRightColumn = document.querySelector('.detail-right-column');
            if (qrRightColumn) {
                qrRightColumn.style.display = 'none';
                qrRightColumn.style.visibility = 'hidden';
            }
        }
    }
    
    // Store current item ID for action buttons (already set above for admin/super admin, set here for others)
    if (!window.currentViewingItemId) {
    window.currentViewingItemId = item.id;
    }
    
    // Store full item data for QR download with text
    window.currentItemData = item;

    // Show/hide download QR button based on permissions and QR request approval status
    checkAndHideDownloadButton(item);

    // Wire the Borrow History button
    const histBtn = document.getElementById('openBorrowHistoryBtn');
    if (histBtn) {
        histBtn.onclick = function() { openBorrowHistoryModal(item.id); };
    }
}

// Removed duplicate closeItemDetailModal function - using the one at line 8114

function editItemFromDetail() {
    if (window.currentViewingItemId) {
        closeItemDetailModal();
        editItem(window.currentViewingItemId);
    }
}


// Check if QR code should be shown and generate it
async function checkAndShowQrForDetail(item) {
    // Store the item ID we're processing to prevent race conditions
    const processingItemId = item.id;
    
    // Clear any existing QR section content first to prevent stale data
    const qrSection = document.querySelector('.qr-section');
    if (qrSection) {
        qrSection.innerHTML = ''; // Clear previous content
    }
    
    // Remove any pending messages
    const qrDownloadButtonContainer = document.getElementById('qrDownloadButtonContainer');
    if (qrDownloadButtonContainer) {
        const pendingMsg = qrDownloadButtonContainer.querySelector('.qr-pending-message');
        if (pendingMsg) {
            pendingMsg.remove();
        }
    }
    
    // Check department permission first - hide QR section if not user's department
    const canViewQr = IS_SUPER_ADMIN || !USER_DEPARTMENT || item.department_name === USER_DEPARTMENT;
    
    if (!canViewQr) {
        // Hide QR section completely if not user's department
        const qrRightColumn = document.querySelector('.detail-right-column');
        if (qrRightColumn) {
            qrRightColumn.style.display = 'none';
            qrRightColumn.style.visibility = 'hidden';
        }
        return;
    }
    
    // Make sure QR section is visible
    const qrRightColumn = document.querySelector('.detail-right-column');
    if (qrRightColumn) {
        qrRightColumn.style.display = '';
        qrRightColumn.style.visibility = 'visible';
    }
    
    // Helper function to check if we're still viewing the same item
    function isStillCurrentItem() {
        return window.currentViewingItemId === processingItemId;
    }
    
    // If item has item_table_id, check if it's consumable first
    if (item.item_table_id) {
        try {
            // First check if the item table is consumable
            const consumableResponse = await fetch(`crud.php?action=check_item_table_consumable&item_table_id=${item.item_table_id}`);
            const consumableData = await consumableResponse.json();
            
            // Check if we're still viewing the same item before processing response
            if (!isStillCurrentItem()) {
                console.log('Item changed during fetch, ignoring response');
                return;
            }
            
            // If consumable, hide QR section completely
            if (consumableData.success && consumableData.is_consumable) {
                const qrRightColumn = document.querySelector('.detail-right-column');
                if (qrRightColumn) {
                    qrRightColumn.style.display = 'none';
                    qrRightColumn.style.visibility = 'hidden';
                }
                console.log('Item table is consumable - hiding QR section');
                return;
            }
            
            // If not consumable, check QR request status (pass item_id to also check download status)
            const response = await fetch(`crud.php?action=check_item_table_qr_status&item_table_id=${item.item_table_id}&item_id=${item.id}`);
            const data = await response.json();
            
            // Check if we're still viewing the same item before processing response
            if (!isStillCurrentItem()) {
                console.log('Item changed during fetch, ignoring response');
                return;
            }
            
            if (data.success) {
                // Show QR only if:
                // 1. Priority is 'low' (auto-generated), OR
                // 2. Priority is 'medium'/'high' AND has approved QR request OR table has QR code
                if (data.priority === 'low' || data.table_has_qr) {
                    // Double-check we're still viewing the same item
                    if (!isStillCurrentItem()) {
                        console.log('Item changed before generating QR, ignoring');
                        return;
                    }
                    generateQrForDetail(item);
                    // After generating QR, check and update download button visibility
                    setTimeout(() => {
                        if (isStillCurrentItem()) {
                            checkAndHideDownloadButton(item);
                        }
                    }, 100);
                } else {
                    // Show pending approval message in QR section (don't hide the section)
                    // Double-check we're still viewing the same item
                    if (!isStillCurrentItem()) {
                        console.log('Item changed before showing pending message, ignoring');
                        return;
                    }
                    // Clear any existing content first
                    const qrSection = document.querySelector('.qr-section');
                    if (qrSection) {
                        qrSection.innerHTML = ''; // Clear first
                        const priorityLabel = data.priority === 'high' ? 'High' : 'Medium';
                        qrSection.innerHTML = `
                            <div class="qr-item-detail" style="
                                background: white;
                                border: 2px dashed #f59e0b;
                                border-radius: 8px;
                                padding: 40px 20px;
                                text-align: center;
                                max-width: 300px;
                                margin: 0 auto;
                                position: relative;
                            ">
                                <div style="
                                    display: flex;
                                    align-items: center;
                                    justify-content: flex-start;
                                    margin-bottom: 15px;
                                    gap: 8px;
                                ">
                                    <img src="assets/logo.png" style="width: 20px; height: 20px; object-fit: contain;" alt="Logo" />
                                    <div style="
                                        font-size: 16px;
                                        font-weight: bold;
                                        color: #000;
                                        text-align: left;
                                    ">OCABIS</div>
                                </div>
                                <div style="
                                    margin: 30px 0;
                                    display: flex;
                                    justify-content: center;
                                    align-items: center;
                                ">
                                    <div style="
                                        font-size: 64px;
                                        margin-bottom: 16px;
                                    ">⏳</div>
                                </div>
                                <div style="
                                    font-size: 16px;
                                    font-weight: 600;
                                    margin-bottom: 8px;
                                    color: #f59e0b;
                                ">QR Code Pending Approval</div>
                                <div style="
                                    font-size: 13px;
                                    color: #6b7280;
                                    margin-bottom: 12px;
                                    line-height: 1.5;
                                ">This item belongs to a <strong>${priorityLabel} Priority</strong> item table. The QR code request is pending admin approval.</div>
                                <div style="
                                    font-size: 12px;
                                    color: #9ca3af;
                                    margin-top: 16px;
                                    padding-top: 16px;
                                    border-top: 1px solid #e5e7eb;
                                ">The QR code will be available for download once approved by an administrator.</div>
                            </div>
                        `;
                    }
                }
            } else {
                // If check fails, show QR anyway (fallback)
                // But only if we're still viewing the same item
                if (isStillCurrentItem()) {
                    generateQrForDetail(item);
                    // After generating QR, check and update download button visibility
                    setTimeout(() => {
                        if (isStillCurrentItem()) {
                            checkAndHideDownloadButton(item);
                        }
                    }, 100);
                }
            }
            } catch (error) {
            console.error('Error checking QR request status:', error);
            // On error, show QR anyway (fallback)
            // But only if we're still viewing the same item
            if (isStillCurrentItem()) {
                generateQrForDetail(item);
                // After generating QR, check and update download button visibility
                setTimeout(() => {
                    if (isStillCurrentItem()) {
                        checkAndHideDownloadButton(item);
                    }
                }, 100);
            }
        }
    } else {
        // No item_table_id, show QR normally
        // But only if we're still viewing the same item
        if (isStillCurrentItem()) {
            generateQrForDetail(item);
            // After generating QR, check and update download button visibility
            setTimeout(() => {
                if (isStillCurrentItem()) {
                    checkAndHideDownloadButton(item);
                }
            }, 100);
        }
    }
}

// Generate a QR image inside the detail modal (simple client-side approach)
function generateQrForDetail(item) {
    try {
        const qrSection = document.querySelector('.qr-section');
        if (!qrSection) return;
        
        // Create professional QR layout matching the exact design
        qrSection.innerHTML = `
            <div class="qr-item-detail" style="
                background: white;
                border: 2px dashed #999;
                border-radius: 8px;
                padding: 20px;
                text-align: center;
                max-width: 300px;
                margin: 0 auto;
                position: relative;
            ">
                <div style="
                    display: flex;
                    align-items: center;
                    justify-content: flex-start;
                    margin-bottom: 15px;
                    gap: 8px;
                ">
                    <img src="assets/logo.png" style="width: 20px; height: 20px; object-fit: contain;" alt="Logo" />
                    <div style="
                        font-size: 16px;
                        font-weight: bold;
                        color: #000;
                        text-align: left;
                    ">OCABIS</div>
                </div>
                <div style="
                    margin: 15px 0;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                ">
                    <img id="detailQrImage" style="
                        max-width: 120px;
                        max-height: 120px;
                        width: 100%;
                        height: auto;
                    " src="" alt="QR code" />
                </div>
                <div class="qr-item-code" id="qrItemCode" style="
                    font-size: 12px;
                    font-weight: bold;
                    color: #000;
                    margin-top: 10px;
                    text-align: center;
                    font-family: monospace;
                ">${item.item_code || 'ITEM-' + item.id}</div>
                <div id="qrDownloadButtonContainer" style="margin-top: 15px;">
                    <button class="btn-primary btn-download" id="qrDownloadBtn" onclick="downloadQrFromDetail()" style="
                        background: #e53e3e;
                        color: white;
                        border: none;
                        padding: 8px 16px;
                        border-radius: 6px;
                        font-size: 12px;
                        font-weight: bold;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        gap: 6px;
                        margin: 0 auto;
                    ">⬇️ DOWNLOAD QR CODE</button>
                </div>
            </div>
        `;
        
        const img = document.getElementById('detailQrImage');
        
        // Always generate QR code on-the-fly with department color for display
        // This ensures QR codes always have the correct color, even if saved files don't
        console.log('Generating QR code on-the-fly for item details display:', item.id, 'Department:', item.department_name);
        generateQrCodeOnTheFly(item, img);
        
        // Note: Saved QR code files might not have color, so we always generate on-the-fly for display
        // The download function will create a new colored QR code when downloading
    } catch (e) {
        console.error('QR generation failed', e);
    }
}

// Generate QR code on-the-fly for display
function generateQrCodeOnTheFly(item, imgElement) {
    const protocol = window.location.protocol;
    const host = window.location.host;
    const baseUrl = `${protocol}//${host}/ocabisFrontend/ocabis/`;
    const qrData = baseUrl + 'view_item.php?id=' + item.id;
    
    // Get department color for QR code
    const deptColor = getDepartmentColorHexJS(item.department_name || '');
    console.log('Generating QR code on-the-fly for item:', item.id, 'Department:', item.department_name, 'Color:', deptColor);
    
    // Generate QR code using API with department color
    const qrApiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=H&color=${deptColor}&bgcolor=FFFFFF&data=${encodeURIComponent(qrData)}`;
    imgElement.src = qrApiUrl;
    imgElement.dataset.downloadName = `item-${item.id}-qr-temp.png`;
    imgElement.onerror = function() {
        console.error('Failed to generate QR code on-the-fly');
        imgElement.src = 'image/qr-placeholder.png';
    };
}

// Check if parent item table has approved QR request before showing download button
async function checkAndHideDownloadButton(item) {
    console.log('checkAndHideDownloadButton called for item:', item.id, 'Item Table ID:', item.item_table_id);
    // Store the item ID we're processing to prevent race conditions
    const processingItemId = item.id;
    
    // Helper function to check if we're still viewing the same item
    function isStillCurrentItem() {
        return window.currentViewingItemId === processingItemId;
    }
    
    // Wait a bit for the button to be created in the DOM
    await new Promise(resolve => setTimeout(resolve, 100));
    
    // Check if we're still viewing the same item
    if (!isStillCurrentItem()) {
        console.log('Item changed before checking download button, ignoring');
        return;
    }
    
    const downloadBtn = document.getElementById('qrDownloadBtn');
    if (!downloadBtn) {
        console.warn('Download button not found, retrying...');
        setTimeout(() => {
            if (isStillCurrentItem()) {
                checkAndHideDownloadButton(item);
            }
        }, 200);
        return;
    }
    
    console.log('Download button found, proceeding with status check...');
    
    // ALWAYS remove any existing pending messages first
    const container = document.getElementById('qrDownloadButtonContainer');
    if (container) {
        const existingMsg = container.querySelector('.qr-pending-message');
        if (existingMsg) {
            existingMsg.remove();
        }
        
        // If manual replacement was done, don't override it unless we're sure the item was downloaded
        if (container.dataset.manualReplacement === 'true') {
            const requestBtn = container.querySelector('.request-new-qr-btn');
            if (requestBtn && requestBtn.style.display !== 'none') {
                console.log('Manual replacement detected, keeping request button visible');
                // Only proceed if we need to verify, otherwise keep the manual replacement
                // We'll still check the database but won't override if request button exists
            }
        }
    }
    
    // Check permissions first
    if (!IS_SUPER_ADMIN && USER_DEPARTMENT && item.department_name !== USER_DEPARTMENT) {
        downloadBtn.style.display = 'none';
        return;
    }
    
    // If item has item_table_id, check QR request status
    if (item.item_table_id) {
        console.log('Item has item_table_id, checking QR status...');
        try {
            // Pass item_id to also check if QR has been downloaded
            const response = await fetch(`crud.php?action=check_item_table_qr_status&item_table_id=${item.item_table_id}&item_id=${item.id}`);
            const data = await response.json();
            console.log('checkAndHideDownloadButton - QR status response:', data);
            
            // Check if we're still viewing the same item before processing response
            if (!isStillCurrentItem()) {
                console.log('Item changed during fetch in checkAndHideDownloadButton, ignoring response');
                return;
            }
            
            if (data.success) {
                // Convert item_qr_downloaded to boolean for consistent checking
                const itemDownloaded = data.item_qr_downloaded === true || data.item_qr_downloaded === 1 || data.item_qr_downloaded === '1' || parseInt(data.item_qr_downloaded) > 0;
                console.log('checkAndHideDownloadButton - Priority:', data.priority, 'Has pending QR:', data.has_pending_qr, 'Has approved QR:', data.has_approved_qr, 'Item QR downloaded (raw):', data.item_qr_downloaded, 'Item QR downloaded (bool):', itemDownloaded);
                
                // Handle recent rejection cooldown
                if (data.item_recently_rejected) {
                    console.log('Item recently rejected - enforcing cooldown');
                    downloadBtn.style.display = 'none';
                    downloadBtn.style.visibility = 'hidden';
                    downloadBtn.style.opacity = '0';
                    
                    if (container) {
                        // Remove request button while in cooldown
                        const existingRequestBtn = container.querySelector('.request-new-qr-btn');
                        if (existingRequestBtn) {
                            existingRequestBtn.remove();
                        }
                        
                        const existingPendingMsg = container.querySelector('.qr-pending-message');
                        if (existingPendingMsg) existingPendingMsg.remove();
                        
                        const waitDate = data.item_rejection_wait_until ? new Date(data.item_rejection_wait_until) : null;
                        const waitText = waitDate ? waitDate.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: 'numeric' }) : 'a few days';
                        const msg = document.createElement('div');
                        msg.className = 'qr-pending-message';
                        msg.style.cssText = 'color: #dc2626; font-size: 11px; text-align: center; margin-top: 8px; padding: 8px; background: #fee2e2; border-radius: 4px; font-weight: 600;';
                        msg.textContent = `QR request rejected. Please wait until ${waitText} before requesting again.`;
                        container.appendChild(msg);
                    }
                    return;
                }
                // Show button if:
                // 1. Priority is 'low' (auto-generated - always allow download), OR
                // 2. Priority is 'medium'/'high' AND has approved QR request OR table has QR code AND QR not yet downloaded
                if (data.priority === 'low') {
                    // Low priority - ALWAYS show download button and NEVER show pending message
                    // Double-check we're still viewing the same item
                    if (!isStillCurrentItem()) {
                        console.log('Item changed before showing download button for low priority, ignoring');
                        return;
                    }
                    downloadBtn.style.display = 'flex';
                    downloadBtn.style.visibility = 'visible';
                    // Make sure no pending message exists for low priority
                    if (container) {
                        const pendingMsg = container.querySelector('.qr-pending-message');
                        if (pendingMsg) {
                            pendingMsg.remove();
                        }
                        const requestNewBtn = container.querySelector('.request-new-qr-btn');
                        if (requestNewBtn) {
                            requestNewBtn.remove();
                        }
                    }
                    console.log('Showing download button for low priority item');
                } else if ((data.priority === 'medium' || data.priority === 'high') && data.table_has_qr) {
                    // Medium/High priority with approved QR - apply one-time download restriction
                    
                    // FIRST: Check if there's a pending QR request AFTER an approved one
                    // Only show pending if there's a NEW pending request (user requested new QR after downloading)
                    // If there's an approved QR and item not downloaded yet, allow download even if there's an old pending request
                const itemPending = !!(data.item_has_pending_qr);
                const tablePending = !!(data.has_pending_qr);
                console.log('Checking pending QR status - itemPending:', itemPending, 'tablePending:', tablePending, 'itemDownloaded:', data.item_qr_downloaded);
                    
                // Show pending when this specific item has a pending request
                if (itemPending) {
                        console.log('⚠️ Pending QR request detected - FORCING download button to hide');
                        // There's a pending request for a NEW QR (after download) OR initial pending (no approval yet)
                        if (!isStillCurrentItem()) {
                            console.log('Item changed before showing pending QR message, ignoring');
                            return;
                        }
                        // Force hide download button with multiple methods
                        downloadBtn.style.display = 'none';
                        downloadBtn.style.visibility = 'hidden';
                        downloadBtn.style.opacity = '0';
                        downloadBtn.style.position = 'absolute';
                        downloadBtn.style.left = '-9999px';
                        console.log('✅ Download button FORCED to hide due to pending request');
                        
                        // Remove request button if exists
                        if (container) {
                            const requestNewBtn = container.querySelector('.request-new-qr-btn');
                            if (requestNewBtn) {
                                requestNewBtn.remove();
                            }
                            // Show pending message if not already present
                            const existingPendingMsg = container.querySelector('.qr-pending-message');
                            if (existingPendingMsg) {
                                existingPendingMsg.remove();
                            }
                            const msg = document.createElement('div');
                            msg.className = 'qr-pending-message';
                            msg.style.cssText = 'color: #f59e0b; font-size: 11px; text-align: center; margin-top: 8px; padding: 8px; background: #fef3c7; border-radius: 4px;';
                            msg.textContent = 'QR code request pending approval';
                            container.appendChild(msg);
                            console.log('✅ Pending message shown');
                        }
                        return; // CRITICAL: Exit early to prevent showing download button
                    }
                    
                    // No pending request - proceed with normal logic
                    // ONLY Super Admin is not affected by download restrictions
                    if (IS_SUPER_ADMIN) {
                        // Super Admin - always show download button, no restrictions
                        if (!isStillCurrentItem()) {
                            console.log('Item changed before showing download button for admin, ignoring');
                            return;
                        }
                        downloadBtn.style.display = 'flex';
                        downloadBtn.style.visibility = 'visible';
                        // Remove any pending messages or request buttons
                        if (container) {
                            const pendingMsg = container.querySelector('.qr-pending-message');
                            if (pendingMsg) {
                                pendingMsg.remove();
                            }
                            const requestNewBtn = container.querySelector('.request-new-qr-btn');
                            if (requestNewBtn) {
                                requestNewBtn.remove();
                            }
                        }
                    } else {
                        // Head Department - apply one-time download restriction (medium/high priority only)
                        
                        // Check if item has been downloaded first - use converted boolean
                        const itemDownloaded = data.item_qr_downloaded === true || data.item_qr_downloaded === 1 || data.item_qr_downloaded === '1' || parseInt(data.item_qr_downloaded) > 0;
                        if (itemDownloaded) {
                            console.log('Item QR already downloaded - checking for pending request...');
                            
                            // If there's a pending request, show pending message (user requested new QR after download)
                            if (itemPending) {
                                console.log('Item downloaded AND has pending request - showing pending message');
                                if (!isStillCurrentItem()) {
                                    console.log('Item changed before showing pending message, ignoring');
                                    return;
                                }
                                
                                // Force hide download button
                                downloadBtn.style.display = 'none';
                                downloadBtn.style.visibility = 'hidden';
                                downloadBtn.style.opacity = '0';
                                
                                // Remove request button
                                if (container) {
                                    const requestNewBtn = container.querySelector('.request-new-qr-btn');
                                    if (requestNewBtn) {
                                        requestNewBtn.remove();
                                    }
                                    
                                    // Show pending message
                                    const existingPendingMsg = container.querySelector('.qr-pending-message');
                                    if (existingPendingMsg) {
                                        existingPendingMsg.remove();
                                    }
                                    const msg = document.createElement('div');
                                    msg.className = 'qr-pending-message';
                                    msg.style.cssText = 'color: #f59e0b; font-size: 11px; text-align: center; margin-top: 8px; padding: 8px; background: #fef3c7; border-radius: 4px;';
                                    msg.textContent = 'QR code request pending approval';
                                    container.appendChild(msg);
                                    console.log('Pending message shown for downloaded item with pending request');
                                }
                                return; // Exit early
                            }
                            
                            // No pending request but item downloaded - show request button
                            console.log('Item downloaded but no pending request - showing request button');
                            if (!isStillCurrentItem()) {
                                console.log('Item changed before hiding downloaded QR button, ignoring');
                                return;
                            }
                            
                            // Force hide download button
                            downloadBtn.style.display = 'none';
                            downloadBtn.style.visibility = 'hidden';
                            downloadBtn.style.opacity = '0';
                            
                            // Remove any pending messages
                            if (container) {
                                const pendingMsg = container.querySelector('.qr-pending-message');
                                if (pendingMsg) {
                                    pendingMsg.remove();
                                }
                                
                                // Set manual replacement flag
                                container.dataset.manualReplacement = 'true';
                                
                                // Show "Request New QR Code" button if not already present
                                const existingRequestBtn = container.querySelector('.request-new-qr-btn');
                                if (!existingRequestBtn) {
                                    const requestBtn = document.createElement('button');
                                    requestBtn.className = 'request-new-qr-btn';
                                    requestBtn.id = 'requestNewQrBtn';
                                    requestBtn.style.cssText = 'background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px; margin: 0 auto; margin-top: 10px;';
                                    requestBtn.innerHTML = '🔄 REQUEST NEW QR CODE';
                                    requestBtn.onclick = function() {
                                        requestNewItemQR(item.id);
                                    };
                                    container.appendChild(requestBtn);
                                    console.log('Request button added from database check');
                                } else {
                                    // Ensure existing request button is visible
                                    existingRequestBtn.style.display = 'flex';
                                    existingRequestBtn.style.visibility = 'visible';
                                    console.log('Request button already exists, ensuring visibility');
                                }
                            }
                        } else {
                            // QR not yet downloaded and no pending request - show download button (first time download allowed)
                            console.log('Item NOT downloaded yet - checking if should show download button');
                            
                            // CRITICAL: Double-check database value - sometimes the check might be wrong
                            // If item_qr_downloaded is actually true but we're here, force check again
                            if (data.item_qr_downloaded === true || data.item_qr_downloaded === 1 || data.item_qr_downloaded === '1') {
                                console.warn('⚠️ item_qr_downloaded is TRUE but we reached else block - forcing request button');
                                // Force hide and show request button
                                downloadBtn.style.display = 'none';
                                downloadBtn.style.visibility = 'hidden';
                                downloadBtn.style.opacity = '0';
                                
                                if (container) {
                                    const existingRequestBtn = container.querySelector('.request-new-qr-btn');
                                    if (!existingRequestBtn) {
                                        const requestBtn = document.createElement('button');
                                        requestBtn.className = 'request-new-qr-btn';
                                        requestBtn.id = 'requestNewQrBtn';
                                        requestBtn.style.cssText = 'background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px; margin: 0 auto; margin-top: 10px;';
                                        requestBtn.innerHTML = '🔄 REQUEST NEW QR CODE';
                                        requestBtn.onclick = function() {
                                            requestNewItemQR(item.id);
                                        };
                                        container.appendChild(requestBtn);
                                    }
                                }
                                return;
                            }
                            
                            // BUT: Don't show download button if request button already exists (manual replacement in progress)
                            const existingRequestBtn = container ? container.querySelector('.request-new-qr-btn') : null;
                            if (existingRequestBtn && existingRequestBtn.style.display !== 'none') {
                                console.log('Request button already exists and visible, keeping it - manual replacement active');
                                // Keep download button hidden
                                downloadBtn.style.display = 'none';
                                downloadBtn.style.visibility = 'hidden';
                                return;
                            }
                            
                            // Also check if manual replacement flag is set
                            if (container && container.dataset.manualReplacement === 'true') {
                                console.log('Manual replacement flag set, keeping request button');
                                downloadBtn.style.display = 'none';
                                downloadBtn.style.visibility = 'hidden';
                                return;
                            }
                            
                            if (!isStillCurrentItem()) {
                                console.log('Item changed before showing download button for approved QR, ignoring');
                                return;
                            }
                            
                            console.log('Showing download button - first time download allowed');
                            downloadBtn.style.display = 'flex';
                            downloadBtn.style.visibility = 'visible';
                            // Make sure no pending message or request button exists
                            if (container) {
                                const pendingMsg = container.querySelector('.qr-pending-message');
                                if (pendingMsg) {
                                    pendingMsg.remove();
                                }
                                const requestNewBtn = container.querySelector('.request-new-qr-btn');
                                if (requestNewBtn) {
                                    requestNewBtn.remove();
                                }
                                // Clear manual replacement flag if we're showing download button
                                container.dataset.manualReplacement = 'false';
                            }
                        }
                    }
                } else {
                    // Medium/High priority without an approved QR code stored
                    if (!isStillCurrentItem()) {
                        console.log('Item changed before handling missing QR, ignoring');
                        return;
                    }
                    
                    downloadBtn.style.display = 'none';
                    downloadBtn.style.visibility = 'hidden';
                    downloadBtn.style.opacity = '0';
                    
                    if (container) {
                        // Remove any existing pending messages before we decide what to show
                        const existingMsg = container.querySelector('.qr-pending-message');
                        if (existingMsg) {
                            existingMsg.remove();
                        }
                    }
                    
                    if (data.has_pending_qr) {
                        // There is an active pending request for this table - show pending message
                        console.log('Table has pending QR request - showing pending message');
                        if (container) {
                            const requestBtn = container.querySelector('.request-new-qr-btn');
                            if (requestBtn) {
                                requestBtn.remove();
                            }
                            container.dataset.manualReplacement = 'false';
                            
                            const msg = document.createElement('div');
                            msg.className = 'qr-pending-message';
                            msg.style.cssText = 'color: #f59e0b; font-size: 11px; text-align: center; margin-top: 8px; padding: 8px; background: #fef3c7; border-radius: 4px;';
                            msg.textContent = 'QR code request pending approval';
                            container.appendChild(msg);
                        }
                    } else {
                        // No QR and no pending request - show "Request QR" button
                        console.log('No approved QR and no pending request - showing request button');
                        if (container) {
                            container.dataset.manualReplacement = 'true';
                        }
                        replaceDownloadButtonWithRequest(item.id);
                    }
                }
            } else {
                // If check fails, show button for low priority (fallback)
                if (isStillCurrentItem()) {
                    downloadBtn.style.display = 'flex';
                    downloadBtn.style.visibility = 'visible';
                }
            }
        } catch (error) {
            console.error('Error checking QR request status:', error);
            // On error, show button (fallback) only if still viewing same item
            if (isStillCurrentItem()) {
                downloadBtn.style.display = 'flex';
                downloadBtn.style.visibility = 'visible';
            }
        }
    } else {
        // No item_table_id, show button only if user has permission (already checked above)
        if (isStillCurrentItem()) {
            downloadBtn.style.display = 'flex';
            downloadBtn.style.visibility = 'visible';
        }
    }
}

function downloadQrFromDetail() {
    const img = document.getElementById('detailQrImage');
    if (!img || !img.src) {
        modal.error('QR code not available');
        return;
    }
    
    // Get current item data
    const currentItem = window.currentItemData;
    if (!currentItem) {
        console.error('No item data available');
        modal.error('Item data not available');
        return;
    }
    
    // Check if user has permission (super admin or same department) - IMPORTANT SECURITY CHECK
    if (!IS_SUPER_ADMIN && USER_DEPARTMENT && currentItem.department_name !== USER_DEPARTMENT) {
        modal.warning('You can only download QR codes for items from your own department.');
        return;
    }
    
    // Check if parent item table has approved QR request (only for medium/high priority)
    console.log('downloadQrFromDetail - Item:', currentItem.id, 'Item Table ID:', currentItem.item_table_id);
    if (currentItem.item_table_id) {
        fetch(`crud.php?action=check_item_table_qr_status&item_table_id=${currentItem.item_table_id}&item_id=${currentItem.id}`)
            .then(r => r.json())
            .then(data => {
                console.log('QR status check response:', data);
                if (data.success) {
                    if (data.item_recently_rejected) {
                        const waitDate = data.item_rejection_wait_until ? new Date(data.item_rejection_wait_until) : null;
                        const waitText = waitDate ? waitDate.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: 'numeric' }) : 'a few days';
                        modal.warning(`QR code request was recently rejected. Please wait until ${waitText} before requesting again.`);
                        return;
                    }
                    
                    console.log('Priority:', data.priority, 'Has approved QR:', data.has_approved_qr, 'Table has QR:', data.table_has_qr, 'Item QR downloaded:', data.item_qr_downloaded);
                    // For low priority, always allow download (can generate on-the-fly) - NO RESTRICTIONS
                    if (data.priority === 'low') {
                        console.log('Taking LOW priority path');
                        // Low priority - generate/download QR code (unlimited downloads)
                        createProfessionalQRCode(currentItem, function() {
                            console.log('QR code downloaded successfully');
                        });
                    } else if (data.priority === 'medium' || data.priority === 'high') {
                        if (!data.table_has_qr) {
                            if (data.has_pending_qr) {
                                modal.warning('QR code request for this table is pending approval. Please wait for admin approval before downloading.');
                            } else {
                                modal.warning('No approved QR code is available for this item table. Please request a QR code first.');
                            }
                            return;
                        }
                        
                        console.log('Taking MEDIUM/HIGH priority path with approval');
                        console.log('IS_SUPER_ADMIN:', IS_SUPER_ADMIN, 'IS_ADMIN:', IS_ADMIN);
                        const itemDownloaded = data.item_qr_downloaded === true || data.item_qr_downloaded === 1 || data.item_qr_downloaded === '1' || parseInt(data.item_qr_downloaded) > 0;
                        const itemPending = !!(data.item_has_pending_qr);
                        
                        if (itemPending) {
                            modal.warning('A new QR code has already been requested for this item. Please wait for admin approval before downloading again.');
                            return;
                        }
                        // Medium/High priority with approval - apply one-time download restriction
                        // ONLY Super Admin gets unlimited downloads - Head Department (Admin but not Super Admin) should have restrictions
                        if (IS_SUPER_ADMIN) {
                            console.log('Super Admin - unlimited downloads');
                            // Super Admin - allow unlimited downloads
                            createProfessionalQRCode(currentItem, function() {
                                console.log('QR code downloaded successfully');
                            });
                        } else {
                            // Head Department (Admin but not Super Admin) OR Regular users - check if already downloaded (one-time restriction for medium/high priority only)
                            console.log('Head Department or Regular user - applying one-time download restriction');
                            if (itemDownloaded) {
                                console.log('Item already downloaded - showing warning');
                                modal.warning('QR code has already been downloaded. Please request a new QR code to download again.');
                            } else {
                                // Allow download and mark as downloaded after successful download
                                console.log('Starting download for medium/high priority item:', currentItem.id);
                                createProfessionalQRCode(currentItem, function() {
                                    console.log('✅ QR code downloaded successfully for medium/high priority item:', currentItem.id);
                                    
                                    // FORCE button replacement immediately - no delays
                                    const downloadBtn = document.getElementById('qrDownloadBtn');
                                    const container = document.getElementById('qrDownloadButtonContainer');
                                    
                                    if (downloadBtn && container) {
                                        console.log('Hiding download button and showing request button...');
                                        
                                        // Hide download button
                                        downloadBtn.style.display = 'none';
                                        downloadBtn.style.visibility = 'hidden';
                                        downloadBtn.style.opacity = '0';
                                        
                                        // Remove existing request button
                                        const existingBtn = container.querySelector('.request-new-qr-btn');
                                        if (existingBtn) existingBtn.remove();
                                        
                                        // Create and add request button
                                        const requestBtn = document.createElement('button');
                                        requestBtn.className = 'request-new-qr-btn';
                                        requestBtn.style.cssText = 'background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px; margin: 0 auto; margin-top: 10px;';
                                        requestBtn.innerHTML = '🔄 REQUEST NEW QR CODE';
                                        requestBtn.onclick = function() {
                                            requestNewItemQR(currentItem.id);
                                        };
                                        container.appendChild(requestBtn);
                                        container.dataset.manualReplacement = 'true';
                                        
                                        console.log('✅ Request button added successfully!');
                                    
                                    // CRITICAL: Mark in database FIRST before anything else
                                    // This ensures the flag is set immediately
                                    markItemQRDownloaded(currentItem.id);
                                    
                                    } else {
                                        console.error('Button or container not found!', {downloadBtn, container});
                                        // Still mark in database even if button replacement fails
                                        markItemQRDownloaded(currentItem.id);
                                        // Retry button replacement
                                        setTimeout(() => replaceDownloadButtonWithRequest(currentItem.id), 200);
                                    }
                                });
                            }
                        }
                    } else if (data.priority === 'medium' || data.priority === 'high') {
                        // Medium/High priority without approval - block download
                        modal.warning('QR code is pending approval. Please wait for admin approval before downloading.');
                    } else {
                        console.log('Taking UNKNOWN/OTHER priority path - Priority:', data.priority);
                        // Unknown priority or other case - allow download (fallback)
                        createProfessionalQRCode(currentItem, function() {
                            console.log('QR code downloaded successfully');
                        });
                    }
                } else {
                    console.log('QR status check failed - data.success is false');
                    // If check fails, allow download (fallback)
                    createProfessionalQRCode(currentItem, function() {
                        console.log('QR code downloaded successfully');
                    });
                }
            })
            .catch(error => {
                console.error('Error checking QR status:', error);
                // On error, allow download (fallback)
                createProfessionalQRCode(currentItem, function() {
                    console.log('QR code downloaded successfully');
                });
            });
    } else {
        console.log('No item_table_id - taking fallback path');
        // No item_table_id, proceed with download
        createProfessionalQRCode(currentItem, function() {
            console.log('QR code downloaded successfully');
        });
    }
}

// Mark item QR as downloaded (for high/medium priority items)
function markItemQRDownloaded(itemId) {
    console.log('markItemQRDownloaded called for item:', itemId);
    fetch(`crud.php?action=mark_item_qr_downloaded`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `item_id=${itemId}`
    })
    .then(r => r.json())
    .then(data => {
        console.log('markItemQRDownloaded response:', data);
        if (data.success) {
            console.log('QR code marked as downloaded - replacing button now');
            // Immediately replace download button with request button for medium/high priority items
            const currentItem = window.currentItemData;
            if (currentItem && currentItem.id === itemId) {
                // Replace button immediately without delay
                replaceDownloadButtonWithRequest(itemId);
            } else {
                console.warn('Current item mismatch or not found');
            }
        } else {
            console.error('Failed to mark QR as downloaded:', data.message);
        }
    })
    .catch(error => {
        console.error('Error marking QR as downloaded:', error);
    });
}

// Helper function to replace download button with request button
function replaceDownloadButtonWithRequest(itemId) {
    console.log('replaceDownloadButtonWithRequest called for item:', itemId);
    
    // Retry logic with multiple attempts
    let attempts = 0;
    const maxAttempts = 5;
    
    function tryReplace() {
        attempts++;
        console.log(`Attempt ${attempts} to replace button for item ${itemId}`);
        
        const downloadBtn = document.getElementById('qrDownloadBtn');
        const container = document.getElementById('qrDownloadButtonContainer');
        
        if (!downloadBtn) {
            console.warn(`Download button not found (attempt ${attempts}/${maxAttempts})`);
            if (attempts < maxAttempts) {
                setTimeout(() => {
                    if (window.currentItemData && window.currentItemData.id === itemId) {
                        tryReplace();
                    }
                }, 300);
            } else {
                console.error('Failed to find download button after multiple attempts');
            }
            return;
        }
        
        if (!container) {
            console.error('Container not found!');
            if (attempts < maxAttempts) {
                setTimeout(() => {
                    if (window.currentItemData && window.currentItemData.id === itemId) {
                        tryReplace();
                    }
                }, 300);
            }
            return;
        }
        
        // Hide download button immediately with multiple methods
        downloadBtn.style.display = 'none';
        downloadBtn.style.visibility = 'hidden';
        downloadBtn.style.opacity = '0';
        downloadBtn.style.position = 'absolute';
        downloadBtn.style.left = '-9999px';
        console.log('Download button hidden with multiple methods');
        
        // Remove any existing request button to avoid duplicates
        const existingRequestBtn = container.querySelector('.request-new-qr-btn');
        if (existingRequestBtn) {
            existingRequestBtn.remove();
            console.log('Removed existing request button');
        }
        
        // Show "Request New QR Code" button immediately
        const requestBtn = document.createElement('button');
        requestBtn.className = 'request-new-qr-btn';
        requestBtn.id = 'requestNewQrBtn';
        requestBtn.style.cssText = 'background: #f59e0b; color: white; border: none; padding: 8px 16px; border-radius: 6px; font-size: 12px; font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 6px; margin: 0 auto; margin-top: 10px; transition: all 0.3s ease;';
        requestBtn.innerHTML = '🔄 REQUEST NEW QR CODE';
        requestBtn.onclick = function() {
            requestNewItemQR(itemId);
        };
        requestBtn.onmouseover = function() {
            this.style.background = '#d97706';
            this.style.transform = 'scale(1.05)';
        };
        requestBtn.onmouseout = function() {
            this.style.background = '#f59e0b';
            this.style.transform = 'scale(1)';
        };
        container.appendChild(requestBtn);
        
        console.log('✅ Request button added successfully - one-time download completed');
        
        // Mark that we've manually replaced the button to prevent override
        container.dataset.manualReplacement = 'true';
        container.dataset.replacedForItem = itemId;
        
        // Verify after a short delay
        setTimeout(() => {
            const verifyDownloadBtn = document.getElementById('qrDownloadBtn');
            const verifyRequestBtn = container.querySelector('.request-new-qr-btn');
            if (verifyDownloadBtn && verifyDownloadBtn.style.display !== 'none') {
                console.warn('Download button was shown again, forcing hide...');
                verifyDownloadBtn.style.display = 'none';
                verifyDownloadBtn.style.visibility = 'hidden';
                verifyDownloadBtn.style.opacity = '0';
            }
            if (!verifyRequestBtn) {
                console.warn('Request button missing, recreating...');
                replaceDownloadButtonWithRequest(itemId);
            } else {
                console.log('✅ Verification passed - request button is visible');
            }
        }, 500);
    }
    
    // Start the replacement process
    tryReplace();
}

// Request new QR code for an item
async function requestNewItemQR(itemId) {
    // Show custom form modal for notes
    const notes = await new Promise((resolve) => {
        const formModalId = 'qr-request-form-modal';
        let formModal = document.getElementById(formModalId);
        
        if (!formModal) {
            const formModalHTML = `
                <div id="${formModalId}" class="ocabis-modal" style="display: none;">
                    <div class="ocabis-modal-overlay"></div>
                    <div class="ocabis-modal-content" style="max-width: 500px;">
                        <div class="ocabis-modal-header">
                            <h3 class="ocabis-modal-title">Request New QR Code</h3>
                            <button class="ocabis-modal-close" onclick="document.getElementById('${formModalId}').style.display='none'; document.body.style.overflow=''; resolve(null);">&times;</button>
                        </div>
                        <div class="ocabis-modal-body">
                            <p class="ocabis-modal-message" style="margin-bottom: 12px;">This request will need to be approved by Super Admin or Admin before you can download the QR code again.</p>
                            <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #374151;">Notes (optional):</label>
                            <textarea id="qr-request-notes" placeholder="Enter any additional notes or reason for this QR code request..." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box; min-height: 100px; resize: vertical; font-family: inherit;"></textarea>
                        </div>
                        <div class="ocabis-modal-footer">
                            <button class="ocabis-modal-btn ocabis-modal-btn-secondary" onclick="document.getElementById('${formModalId}').style.display='none'; document.body.style.overflow=''; resolve(null);">Cancel</button>
                            <button class="ocabis-modal-btn ocabis-modal-btn-primary" id="qr-request-submit-btn">Submit Request</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', formModalHTML);
            formModal = document.getElementById(formModalId);
        }
        
        const notesTextarea = formModal.querySelector('#qr-request-notes');
        const submitBtn = formModal.querySelector('#qr-request-submit-btn');
        const cancelBtn = formModal.querySelector('.ocabis-modal-btn-secondary');
        const closeBtn = formModal.querySelector('.ocabis-modal-close');
        
        // Clear previous value
        notesTextarea.value = '';
        
        // Remove old event listeners by cloning
        const newSubmitBtn = submitBtn.cloneNode(true);
        submitBtn.parentNode.replaceChild(newSubmitBtn, submitBtn);
        const newCancelBtn = cancelBtn.cloneNode(true);
        cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
        const newCloseBtn = closeBtn.cloneNode(true);
        closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
        
        // Add event listeners
        newSubmitBtn.onclick = () => {
            const notesValue = notesTextarea.value.trim();
            formModal.style.display = 'none';
            document.body.style.overflow = '';
            resolve(notesValue);
        };
        
        newCancelBtn.onclick = () => {
            formModal.style.display = 'none';
            document.body.style.overflow = '';
            resolve(null);
        };
        
        newCloseBtn.onclick = () => {
            formModal.style.display = 'none';
            document.body.style.overflow = '';
            resolve(null);
        };
        
        // Handle Enter key (Ctrl+Enter to submit)
        notesTextarea.onkeydown = (e) => {
            if (e.key === 'Enter' && e.ctrlKey) {
                e.preventDefault();
                newSubmitBtn.click();
            }
        };
        
        // Show modal
        formModal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Focus on textarea
        setTimeout(() => {
            notesTextarea.focus();
        }, 100);
    });
    
    if (notes === null) {
        return; // User cancelled
    }
    
    fetch(`crud.php?action=request_new_item_qr`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `item_id=${itemId}&notes=${encodeURIComponent(notes || '')}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (data.requires_approval) {
                modal.success(data.message || 'QR code request has been submitted and is pending approval. You will be able to download once approved.');
                // Immediately hide download button and show pending message
                const downloadBtn = document.getElementById('qrDownloadBtn');
                const container = document.getElementById('qrDownloadButtonContainer');
                if (downloadBtn && container) {
                    console.log('Hiding download button after QR request');
                    downloadBtn.style.display = 'none';
                    downloadBtn.style.visibility = 'hidden';
                    downloadBtn.style.opacity = '0';
                    
                    // Remove existing request button
                    const existingBtn = container.querySelector('.request-new-qr-btn');
                    if (existingBtn) existingBtn.remove();
                    
                    // Show pending message
                    const existingPendingMsg = container.querySelector('.qr-pending-message');
                    if (existingPendingMsg) existingPendingMsg.remove();
                    
                    const msg = document.createElement('div');
                    msg.className = 'qr-pending-message';
                    msg.style.cssText = 'color: #f59e0b; font-size: 11px; text-align: center; margin-top: 8px; padding: 8px; background: #fef3c7; border-radius: 4px;';
                    msg.textContent = 'QR code request pending approval';
                    container.appendChild(msg);
                    console.log('Pending message shown after request');
                }
            } else {
                modal.success(data.message || 'QR code request reset. You can now download a new QR code.');
            }
            // Refresh the download button visibility after a delay to ensure database is updated
            const currentItem = window.currentItemData;
            if (currentItem && currentItem.id === itemId) {
                setTimeout(() => {
                    checkAndHideDownloadButton(currentItem);
                }, 500);
            }
        } else {
            if (data.cooldown && data.wait_until) {
                const waitDate = new Date(data.wait_until);
                const waitText = waitDate.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: 'numeric' });
                modal.warning(`QR code request was recently rejected. Please wait until ${waitText} before requesting again.`);
            } else {
                modal.error(data.message || 'Failed to request new QR code');
            }
        }
    })
    .catch(error => {
        console.error('Error requesting new QR code:', error);
        modal.error('Error requesting new QR code. Please try again.');
    });
}

function formatDateForModal(dateString) {
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { 
            year: 'numeric',
            month: 'long', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return dateString;
    }
}
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
                    const fixedBtn = document.getElementById('sidebarToggleFixed');
                    sidebar.classList.remove('open');
                    if (overlay) overlay.classList.remove('show');
                    document.body.style.overflow = '';
                    // Ensure fixed button is visible on mobile
                    if (fixedBtn) fixedBtn.style.display = 'flex';
                } else {
                    // On desktop, apply saved state
                    document.body.classList.toggle(BODY_CLASS, isCollapsed);
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
                        // Show fixed button when sidebar closes
                        if (fixedBtn) fixedBtn.style.display = 'flex';
                    } else {
                        sidebar.classList.add('open');
                        if (overlay) overlay.classList.add('show');
                        document.body.style.overflow = 'hidden';
                        // Hide fixed button when sidebar opens
                        if (fixedBtn) fixedBtn.style.display = 'none';
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
                        // Show fixed button when sidebar closes
                        if (fixedBtn) fixedBtn.style.display = 'flex';
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
                        const fixedBtn = document.getElementById('sidebarToggleFixed');
                        document.body.classList.remove(BODY_CLASS);
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                        // Ensure fixed button is visible on mobile
                        if (fixedBtn) fixedBtn.style.display = 'flex';
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

// Close modal when clicking outside
document.addEventListener('click', function(e) {
    const modal = document.getElementById('itemDetailModal');
    if (e.target === modal) {
        closeItemDetailModal();
    }
    
    // Close viewer borrow modal when clicking outside
    const viewerBorrowModal = document.getElementById('viewerBorrowModal');
    if (viewerBorrowModal && e.target === viewerBorrowModal) {
        closeViewerBorrowModal();
    }
    
    // Close manage borrow requests modal when clicking outside
    const manageBorrowRequestsModal = document.getElementById('manageBorrowRequestsModal');
    if (manageBorrowRequestsModal && e.target === manageBorrowRequestsModal) {
        closeManageBorrowRequestsModal();
    }
});

// Prevent modal from closing when clicking inside the modal
document.addEventListener('click', function(e) {
    const viewerBorrowModal = document.getElementById('viewerBorrowModal');
    if (viewerBorrowModal && viewerBorrowModal.style.display !== 'none') {
        const modalContent = viewerBorrowModal.querySelector('.modal');
        if (modalContent && modalContent.contains(e.target)) {
            e.stopPropagation();
        }
    }
    
    const manageBorrowRequestsModal = document.getElementById('manageBorrowRequestsModal');
    if (manageBorrowRequestsModal && manageBorrowRequestsModal.style.display !== 'none') {
        const modalContent = manageBorrowRequestsModal.querySelector('.modal');
        if (modalContent && modalContent.contains(e.target)) {
            e.stopPropagation();
        }
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeItemDetailModal();
        
        // Also close viewer borrow modal if open
        const viewerBorrowModal = document.getElementById('viewerBorrowModal');
        if (viewerBorrowModal && viewerBorrowModal.style.display !== 'none') {
            closeViewerBorrowModal();
        }
        
        // Also close manage borrow requests modal if open
        const manageBorrowRequestsModal = document.getElementById('manageBorrowRequestsModal');
        if (manageBorrowRequestsModal && manageBorrowRequestsModal.style.display !== 'none') {
            closeManageBorrowRequestsModal();
        }
    }
});

// Enforce view-only restrictions for viewer role
document.addEventListener('DOMContentLoaded', function() {
    try {
        var body = document.body;
        if (body && body.dataset && body.dataset.userIsViewer === 'true') {
            // Disable all form controls
            document.querySelectorAll('input, select, textarea, button').forEach(function(el) {
                // Keep sidebar toggles and logout clickable
                var id = el.id || '';
                if (id === 'sidebarToggle' || id === 'sidebarToggleFixed') return;
                if (el.closest('.sign-out')) return;
                // Keep profile and notifications clickable
                if (el.closest('.user-profile-section')) return;
                // Allow search and filtering controls
                if (el.closest('.filters-section')) return;
                if (id === 'treeSearch' || id === 'nameFilter') return;
                // Keep viewer borrow modal elements enabled
                if (el.closest('#viewerBorrowModal')) return;
                if (id === 'viewerBorrowSubmitBtn' || id === 'viewerBorrowerName' || id === 'viewerBorrowerEmail' || 
                    id === 'viewerBorrowDate' || id === 'viewerNeededDate' || id === 'viewerDueDate' || id === 'viewerBorrowPurpose') return;
                // Keep item detail modal close button and other modal buttons enabled
                if (el.closest('#itemDetailModal')) return;
                if (id === 'closeItemDetailBtn') return;
                // Keep viewer borrow buttons enabled
                if (el.classList.contains('viewer-borrow-btn')) return;
                el.disabled = true;
            });
            // Prevent form submissions (except viewer borrow form)
            document.querySelectorAll('form').forEach(function(form) {
                if (form.id === 'viewerBorrowForm') return; // Allow viewer borrow form
                form.addEventListener('submit', function(e) { e.preventDefault(); }, true);
            });
            
            // Watch for item detail modal and ensure close button is always enabled
            const itemDetailModal = document.getElementById('itemDetailModal');
            if (itemDetailModal) {
                // Observer to watch for disabled buttons in the modal
                const observer = new MutationObserver(function(mutations) {
                    const closeBtn = document.getElementById('closeItemDetailBtn');
                    if (closeBtn && closeBtn.disabled) {
                        closeBtn.disabled = false;
                        closeBtn.style.pointerEvents = 'auto';
                        closeBtn.style.cursor = 'pointer';
                    }
                });
                
                observer.observe(itemDetailModal, {
                    attributes: true,
                    attributeFilter: ['disabled'],
                    childList: true,
                    subtree: true
                });
                
                // Also ensure close button is enabled when modal becomes visible
                const modalObserver = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                            const modal = document.getElementById('itemDetailModal');
                            if (modal && modal.style.display !== 'none') {
                                const closeBtn = document.getElementById('closeItemDetailBtn');
                                if (closeBtn) {
                                    closeBtn.disabled = false;
                                    closeBtn.style.pointerEvents = 'auto';
                                    closeBtn.style.cursor = 'pointer';
                                }
                            }
                        }
                    });
                });
                
                modalObserver.observe(itemDetailModal, {
                    attributes: true,
                    attributeFilter: ['style', 'class']
                });
            }
        }
    } catch (err) { 
        console.error('Error in viewer restriction code:', err);
    }
});

</script>

<!-- Load modal script after DOM is ready -->
<script src="modal.js"></script>

</body>
</html>
<?php
session_start();

// redirect if not logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$conn = @new mysqli('localhost', 'root', '', 'ocabis');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
// Role field removed - using is_admin and department instead
$department = isset($_SESSION['department']) ? $_SESSION['department'] : '';
// Department head: admin but not super admin
$isDepartmentHead = $isAdmin && !$isSuperAdmin;

// Get user's department ID
$user_department_id = null;
if (!empty($department)) {
    $user_dept_query = "SELECT id FROM departments WHERE name = ? LIMIT 1";
    $user_dept_stmt = $conn->prepare($user_dept_query);
    if ($user_dept_stmt) {
        $user_dept_stmt->bind_param("s", $department);
        $user_dept_stmt->execute();
        $user_dept_result = $user_dept_stmt->get_result();
        if ($user_dept_result && $user_dept_row = $user_dept_result->fetch_assoc()) {
            $user_department_id = (int)$user_dept_row['id'];
        }
        $user_dept_stmt->close();
    }
}

// Get departments for dropdown
$departments_query = "SELECT * FROM departments ORDER BY name";
$departments_result = $conn->query($departments_query);
$departments = [];
while ($row = $departments_result->fetch_assoc()) {
    $departments[] = $row;
}

// Get item tables for dropdown
$tables_query = "SELECT it.id, it.table_name, it.category, it.department_id, d.name as department_name 
                 FROM item_tables it 
                 LEFT JOIN departments d ON it.department_id = d.id 
                 ORDER BY it.table_name ASC";
$tables_result = $conn->query($tables_query);
$item_tables = [];
if ($tables_result) {
    while ($row = $tables_result->fetch_assoc()) {
        $item_tables[] = $row;
    }
}

// Get categories from database - filter by department
// Super admins see all categories, others only see categories from their own department
if ($isSuperAdmin) {
    // Super admins see all categories
    $categories_query = "SELECT DISTINCT name FROM categories ORDER BY name ASC";
    $categories_result = $conn->query($categories_query);
} else if (!empty($user_department_id)) {
    // Regular users only see categories from their own department
    $categories_query = "SELECT DISTINCT c.name 
                        FROM categories c 
                        WHERE c.department_id = ? 
                        ORDER BY c.name ASC";
    $categories_stmt = $conn->prepare($categories_query);
    $categories_stmt->bind_param("i", $user_department_id);
    $categories_stmt->execute();
    $categories_result = $categories_stmt->get_result();
} else {
    // Users without department see no categories
    $categories_result = false;
}

$categories = [];
if ($categories_result) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
    }
    if (isset($categories_stmt)) {
        $categories_stmt->close();
    }
}

// Get locations from database (buildings, floors, rooms)
$locations_query = "SELECT 
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
            WHERE r.id IS NOT NULL
            ORDER BY b.name, f.floor_number, r.room_number";
$locations_result = $conn->query($locations_query);
$locations = [];
if ($locations_result) {
    while ($row = $locations_result->fetch_assoc()) {
        $locations[] = [
            'full_location' => htmlspecialchars($row['full_location'], ENT_QUOTES, 'UTF-8')
        ];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode Scanner - OCABIS</title>
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="js/session_monitor.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <link rel="stylesheet" href="Css/department.css">
    <script src="modal.js"></script>
    <style>
        /* Normalize sidebar spacing with dashboard */
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
        .scanner-page {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        .scanner-content-container {
            flex: 1;
            background: #f7fafc;
            min-height: 100vh;
            margin-left: 250px;
            transition: margin-left 0.3s ease;
            overflow-x: hidden;
            width: calc(100% - 250px);
            max-width: 100%;
        }

        .scanner-header {
            color: white;
            padding: 40px 20px;
            text-align: center;
            margin-bottom: 30px;
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }

        .scanner-header h1 {
            font-size: 32px;
            margin: 0 0 10px 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            color: #e53e3e;
        }

        .scanner-header p {
            font-size: 16px;
            margin: 0;
            opacity: 0.95;
            color: #e53e3e;
        }

        .scanner-wrapper {
            max-width: 100%;
            margin: 0;
            padding: 0 20px 30px 20px;
            width: 100%;
            box-sizing: border-box;
        }

        .scanner-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
            width: 100%;
            box-sizing: border-box;
        }

        .scanner-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
        }

        .scanner-card-title {
            font-size: 22px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .scanner-card-title i {
            font-size: 24px;
            color:  #e53e3e;
            flex-shrink: 0;
        }

        #reader {
            width: 100%;
            height: 600px;
            min-height: 600px;
            border: 3px dashed #cbd5e0;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f7fafc;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }

        .scanner-placeholder {
            text-align: center;
            color: #a0aec0;
        }

        .scanner-placeholder i {
            font-size: 64px;
            margin-bottom: 15px;
            display: block;
        }

        .scanner-controls {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #e53e3e, #c53030);
            color: white;
            flex: 1;
            min-width: 150px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #e53e3e, #c53030);
            color: white;
            flex: 1;
            min-width: 150px;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(229, 62, 62, 0.3);
        }

        .upload-area {
            border: 3px dashed #cbd5e0;
            border-radius: 12px;
            padding: 50px 20px;
            text-align: center;
            background: #f7fafc;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .upload-area:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .upload-area.dragover {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .upload-icon {
            font-size: 56px;
            color: #a0aec0;
            margin-bottom: 20px;
        }

        .upload-text {
            color: #4a5568;
            font-size: 18px;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .upload-subtext {
            color: #718096;
            font-size: 14px;
        }

        .scan-result {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            font-size: 15px;
        }

        .scan-result.success {
            background: #f0fff4;
            border: 2px solid #9ae6b4;
            color: #22543d;
        }

        .scan-result.error {
            background: #fed7d7;
            border: 2px solid #feb2b2;
            color: #742a2a;
        }

        .scan-result.info {
            background: #ebf8ff;
            border: 2px solid #90cdf4;
            color: #2a4365;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 14px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .required {
            color: #e53e3e;
        }

        .item-preview {
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
        }

        .item-preview h4 {
            margin: 0 0 15px 0;
            color: #2d3748;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .item-preview h4 i {
            color: #667eea;
            flex-shrink: 0;
        }

        .item-preview p {
            margin: 8px 0;
            color: #4a5568;
            font-size: 14px;
            word-break: break-word;
        }

        .item-preview strong {
            color: #2d3748;
            font-weight: 600;
            min-width: 100px;
            display: inline-block;
        }

        .hidden {
            display: none;
        }

        /* Sidebar closed state */
        .sidebar.closed ~ .scanner-content-container {
            margin-left: 0;
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

        /* Tablet responsiveness */
        @media (max-width: 1200px) {
            .scanner-wrapper {
                padding: 0 15px 30px 15px;
            }

            .scanner-card {
                padding: 20px;
            }

            .scanner-header {
                padding: 30px 15px;
            }

            .scanner-header h1 {
                font-size: 28px;
            }
        }

        /* Tablet to mobile transition */
        @media (max-width: 1024px) {
            .scanner-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: 1;
            }
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
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

            /* Keep inline toggle visible inside sidebar for closing */
            #sidebarToggle,
            .sidebar-toggle-inline {
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                pointer-events: auto !important;
                position: static !important;
                margin-left: auto !important;
            }

            /* Slide sidebar in/out on mobile */
            .sidebar { 
                transform: translateX(-100%); 
                transition: transform 0.3s ease;
                z-index: 1200;
                width: 250px !important;
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            }
            
            .sidebar.open { 
                transform: translateX(0); 
            }

            .scanner-content-container {
                margin-left: 0 !important;
                width: 100% !important;
            }

            .scanner-header {
                padding: 25px 15px;
                margin-bottom: 20px;
            }

            .scanner-header h1 {
                font-size: 24px;
                gap: 10px;
            }

            .scanner-header p {
                font-size: 14px;
            }

            .scanner-wrapper {
                padding: 0 15px 20px 15px;
            }

            .scanner-grid {
                gap: 20px;
            }

            .scanner-card {
                padding: 20px;
                border-radius: 15px;
            }

            .scanner-card-title {
                font-size: 18px;
                margin-bottom: 20px;
            }

            #reader {
                height: 500px !important;
                min-height: 500px !important;
                max-height: 70vh !important;
                margin-bottom: 15px;
            }

            .scanner-controls {
                gap: 10px;
            }

            .btn {
                padding: 12px 20px;
                font-size: 14px;
                min-width: 120px;
            }

            .upload-area {
                padding: 40px 15px;
            }

            .upload-icon {
                font-size: 48px;
                margin-bottom: 15px;
            }

            .upload-text {
                font-size: 16px;
            }

            .form-grid {
                gap: 15px;
            }

            .form-group {
                margin-bottom: 15px;
            }

            .form-label {
                font-size: 13px;
                margin-bottom: 8px;
            }

            .form-input, .form-select, .form-textarea {
                padding: 12px;
                font-size: 14px;
            }

            .item-preview {
                padding: 15px;
                margin-top: 15px;
            }

            .item-preview h4 {
                font-size: 16px;
                margin-bottom: 12px;
            }

            .item-preview p {
                font-size: 13px;
                margin: 6px 0;
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
        }

        /* Small mobile devices */
        @media (max-width: 480px) {
            .scanner-header h1 {
                font-size: 20px;
            }

            .scanner-header p {
                font-size: 12px;
            }

            .scanner-card-title {
                font-size: 16px;
            }

            #reader {
                height: 450px !important;
                min-height: 450px !important;
                max-height: 65vh !important;
            }

            .btn {
                padding: 10px 16px;
                font-size: 13px;
            }

            .upload-area {
                padding: 30px 10px;
            }

            .upload-icon {
                font-size: 40px;
            }

            .form-input, .form-select, .form-textarea {
                padding: 10px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body data-user-logged-in="true"
      data-user-super-admin="<?= $isSuperAdmin ? 'true' : 'false' ?>"
      data-user-admin="<?= $isAdmin ? 'true' : 'false' ?>"
      data-user-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
      data-user-department="<?= htmlspecialchars($department, ENT_QUOTES, 'UTF-8') ?>">
    
    <div class="scanner-page">
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
                    <a href="dashboard.php" class="nav-link">
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
                    <a href="location.php" class="nav-link">
                        <span class="nav-icon">
                            <img src="image/icons8-building-64.png" alt="Location">
                        </span>
                        <span class="nav-label">Location</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="categories.php" class="nav-link">
                        <span class="nav-icon">
                            <img src="image/icons8-categorize-50.png" alt="Categories">
                        </span>
                        <span class="nav-label">Categories</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="BorrowHistory.php" class="nav-link">
                        <span class="nav-icon">
                            <img src="image/book.png" alt="Borrow History">
                        </span>
                        <span class="nav-label">Borrow History</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="archive.php" class="nav-link">
                        <span class="nav-icon">
                            <img src="image/icons8-archive-50.png" alt="Archive">
                        </span>
                        <span class="nav-label">Archive</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="qrscanner.php" class="nav-link">
                        <span class="nav-icon">
                            <img src="image/qr.png" alt="QR Scanner">
                        </span>
                        <span class="nav-label">QR Code Scanner</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="barcode_scanner.php" class="nav-link active">
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
                        <span class="nav-icon">
                            <img src="image/application.png" alt="Item Requests">
                        </span>
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
                <a href="logout.php" class="nav-link">
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
        
        <!-- Main Content -->
        <div class="scanner-content-container">
            <?php include 'profile_dropdown.php'; ?>
            
            <div class="scanner-header">
                <h1><i class="fas fa-barcode"></i> Barcode Scanner for New Items</h1>
                <p>Scan barcodes to automatically add new items to the system</p>
            </div>
            
            <div class="scanner-wrapper">
                <div class="scanner-grid">
                    <!-- Scanner Section -->
                    <div class="scanner-card">
                        <div class="scanner-card-title">
                            <i class="fas fa-qrcode"></i>
                            Barcode Scanner
                        </div>
                        
                        <div id="reader"></div>
                        
                        <div class="scanner-controls">
                            <button id="startBtn" class="btn btn-primary" onclick="startCamera()">
                                <i class="fas fa-play"></i> Start Scanner
                            </button>
                            <button id="stopBtn" class="btn btn-danger hidden" onclick="stopCamera()">
                                <i class="fas fa-stop"></i> Stop Scanner
                            </button>
                        </div>
                        
                        <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                            <div class="upload-icon">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div class="upload-text">Upload Barcode Image</div>
                            <div class="upload-subtext">Click here or drag and drop an image</div>
                        </div>
                        
                        <input type="file" id="fileInput" accept="image/*" style="display: none;" onchange="handleFileUpload(event)">
                        
                        <div id="scan-result" class="scan-result hidden"></div>
                    </div>
                    
                    <!-- Item Form Section -->
                    <div class="scanner-card">
                        <div class="scanner-card-title">
                            <i class="fas fa-plus-circle"></i>
                            Add New Item
                        </div>
                        
                        <form id="addItemForm" enctype="multipart/form-data">
                            <div class="form-grid">
                                <div class="form-group full-width">
                                    <label class="form-label">Item Table <span class="required">*</span></label>
                                    <select id="itemTable" class="form-select" required>
                                        <option value="">Select Item Table</option>
                                        <?php 
                                        // Filter item tables:
                                        // - Super admins see all item tables
                                        // - Department heads (admin but not super admin) only see item tables from their own department
                                        // - Regular users only see item tables from their own department
                                        if ($isSuperAdmin) {
                                            $tablesToShow = $item_tables;
                                        } else {
                                            $tablesToShow = array_filter($item_tables, function($t) use ($department) {
                                                return $t['department_name'] === $department;
                                            });
                                        }
                                        foreach ($tablesToShow as $table): 
                                        ?>
                                            <option value="<?= $table['id'] ?>" data-table-name="<?= htmlspecialchars($table['table_name']) ?>">
                                                <?= htmlspecialchars($table['table_name']) ?> 
                                                <?php if ($table['department_name']): ?>
                                                    (<?= htmlspecialchars($table['department_name']) ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label class="form-label">Item Name <span class="required">*</span></label>
                                    <input type="text" id="itemName" class="form-input" required placeholder="Enter item name">
                                </div>
                                
                                <div class="form-group full-width">
                                    <label class="form-label">Barcode/Item Code</label>
                                    <input type="text" id="itemCode" class="form-input" placeholder="Scanned barcode will appear here">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Department <span class="required">*</span></label>
                                    <select id="itemDepartmentId" class="form-select" required>
                                        <option value="">Select Department</option>
                                        <?php 
                                        // Filter departments: 
                                        // - Super admins see all departments
                                        // - Department heads (admin but not super admin) only see their own department
                                        // - Regular users only see their own department
                                        if ($isSuperAdmin) {
                                            $deptsToShow = $departments;
                                        } else {
                                            $deptsToShow = array_filter($departments, function($d) use ($department) {
                                                return $d['name'] === $department;
                                            });
                                        }
                                        foreach ($deptsToShow as $dept): 
                                        ?>
                                            <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Category <span class="required">*</span></label>
                                    <select id="itemCategory" class="form-select" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Quantity <span class="required">*</span></label>
                                    <input type="number" id="itemQuantity" class="form-input" required min="1" value="1">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Location <span class="required">*</span></label>
                                    <select id="itemLocation" class="form-select" required>
                                        <option value="">Select Location</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?= htmlspecialchars($location['full_location']) ?>"><?= htmlspecialchars($location['full_location']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Status <span class="required">*</span></label>
                                    <select id="itemStatus" class="form-select" required>
                                        <option value="">Select Status</option>
                                        <option value="Working">Working</option>
                                        <option value="Under Maintenance">Under Maintenance</option>
                                        <option value="Broken">Broken</option>
                                        <option value="Lost">Lost</option>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label class="form-label">Item Image (Optional)</label>
                                    <input type="file" id="itemImage" class="form-input" accept="image/*">
                                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Upload a picture of the actual item</small>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label class="form-label">Description</label>
                                    <textarea id="itemDescription" class="form-textarea" rows="3" placeholder="Enter item description"></textarea>
                                </div>
                            </div>
                            
                            <button type="button" class="btn btn-primary" onclick="addNewItem()" style="width: 100%; background: linear-gradient(135deg, #38a169, #2f855a); margin-top: 10px;">
                                <i class="fas fa-plus"></i> Add Item to System
                            </button>
                        </form>
                        
                        <div id="item-preview" class="item-preview hidden"></div>
                    </div>
                </div>
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
        let html5QrCode = null;
        let isScanning = false;
        
        function startCamera() {
            if (isScanning) return;
            
            const startBtn = document.getElementById('startBtn');
            const stopBtn = document.getElementById('stopBtn');
            
            startBtn.style.display = 'none';
            stopBtn.style.display = 'inline-flex';
            
            html5QrCode = new Html5Qrcode("reader");
            
            // Adjust QR box size for mobile
            const isMobileDevice = window.innerWidth <= 768;
            const isSmallMobile = window.innerWidth <= 480;
            
            // Calculate optimal QR box size based on screen width - maximize for mobile
            let qrBoxSize;
            if (isSmallMobile) {
                // For small mobile, use almost full width minus padding
                qrBoxSize = Math.min(window.innerWidth - 40, 320);
            } else if (isMobileDevice) {
                // For regular mobile, use larger size
                qrBoxSize = Math.min(window.innerWidth - 60, 350);
            } else {
                qrBoxSize = 250;
            }
            
            const config = {
                fps: 10,
                qrbox: { width: qrBoxSize, height: qrBoxSize },
                aspectRatio: 1.0
            };
            
            html5QrCode.start(
                { facingMode: "environment" },
                config,
                handleBarcodeResult,
                handleScanError
            ).then(() => {
                isScanning = true;
            }).catch(err => {
                console.error("Error starting camera:", err);
                showScanResult("Error starting camera: " + err, "error");
                startBtn.style.display = 'inline-flex';
                stopBtn.style.display = 'none';
            });
        }
        
        function stopCamera() {
            if (html5QrCode) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                    html5QrCode = null;
                    isScanning = false;
                    
                    document.getElementById('startBtn').style.display = 'inline-flex';
                    document.getElementById('stopBtn').style.display = 'none';
                }).catch(err => {
                    isScanning = false;
                });
            }
        }
        
        function handleBarcodeResult(decodedText) {
            showScanResult("✓ Barcode scanned successfully!", "success");
            
            // Auto-fill the barcode field
            document.getElementById('itemCode').value = decodedText;
            
            // Try to extract item information from barcode
            extractItemInfo(decodedText);
            
            // Stop scanning after successful scan
            setTimeout(() => {
                stopCamera();
            }, 1000);
        }
        
        function handleScanError(error) {
            // Don't show error for every failed scan attempt
            console.log("Scan error:", error);
        }
        
        function handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const resultDiv = document.getElementById('scan-result');
            resultDiv.innerHTML = '<div class="scan-result info">Processing barcode image...</div>';
            resultDiv.style.display = 'block';
            
            const html5QrCodeScanner = new Html5Qrcode("scan-result");
            
            html5QrCodeScanner.scanFile(file, true)
                .then(decodedText => {
                    handleBarcodeResult(decodedText);
                    event.target.value = '';
                })
                .catch(err => {
                    showScanResult("Failed to decode barcode. Please try another image.", "error");
                    event.target.value = '';
                });
        }
        
        function extractItemInfo(barcode) {
            // Try to extract item information from barcode
            // This is a simple example - you can enhance this based on your barcode format
            
            // If barcode contains product info in a specific format
            if (barcode.includes('|')) {
                const parts = barcode.split('|');
                if (parts.length >= 3) {
                    document.getElementById('itemName').value = parts[0] || '';
                    
                    // Try to set category from dropdown
                    const categorySelect = document.getElementById('itemCategory');
                    const categoryOptions = Array.from(categorySelect.options);
                    const categoryValue = parts[1];
                    const categoryOption = categoryOptions.find(opt => opt.text.toLowerCase() === categoryValue.toLowerCase());
                    if (categoryOption) {
                        categorySelect.value = categoryOption.value;
                    }
                    
                    // Try to set location from dropdown
                    const locationSelect = document.getElementById('itemLocation');
                    const locationOptions = Array.from(locationSelect.options);
                    const locationValue = parts[2];
                    const locationOption = locationOptions.find(opt => opt.text.toLowerCase().includes(locationValue.toLowerCase()));
                    if (locationOption) {
                        locationSelect.value = locationOption.value;
                    }
                }
            }
            
            // If barcode is a product code, try to look it up
            if (barcode.length >= 8) {
                // You can add API calls here to look up product information
                // For now, we'll just show a preview
                showItemPreview();
            }
        }
        
        function showItemPreview() {
            const preview = document.getElementById('item-preview');
            const name = document.getElementById('itemName').value;
            const code = document.getElementById('itemCode').value;
            const categorySelect = document.getElementById('itemCategory');
            const category = categorySelect.options[categorySelect.selectedIndex].text;
            const locationSelect = document.getElementById('itemLocation');
            const location = locationSelect.options[locationSelect.selectedIndex].text;
            
            if (name || code || category !== 'Select Category' || location !== 'Select Location') {
                preview.innerHTML = `
                    <h4><i class="fas fa-eye"></i> Item Preview</h4>
                    <p><strong>Name:</strong> ${name || 'Not specified'}</p>
                    <p><strong>Code:</strong> ${code || 'Not specified'}</p>
                    <p><strong>Category:</strong> ${category !== 'Select Category' ? category : 'Not specified'}</p>
                    <p><strong>Location:</strong> ${location !== 'Select Location' ? location : 'Not specified'}</p>
                `;
                preview.classList.remove('hidden');
            }
        }
        
        function showScanResult(message, type) {
            const resultDiv = document.getElementById('scan-result');
            resultDiv.innerHTML = `<div class="scan-result ${type}">${message}</div>`;
            resultDiv.style.display = 'block';
            
            // Auto-hide after 3 seconds
            setTimeout(() => {
                resultDiv.style.display = 'none';
            }, 3000);
        }
        
        function addNewItem() {
            // Validate required fields
            const requiredFields = ['itemTable', 'itemName', 'itemDepartmentId', 'itemCategory', 'itemQuantity', 'itemLocation', 'itemStatus'];
            const missingFields = [];
            
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field || !field.value.trim()) {
                    missingFields.push(fieldId);
                }
            });
            
            if (missingFields.length > 0) {
                showScanResult('Please fill in all required fields.', 'error');
                return;
            }
            
            // Show loading state
            const submitBtn = document.querySelector('.btn-success, .btn-primary[onclick="addNewItem()"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding Item...';
            submitBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'add');
            formData.append('item_table_id', document.getElementById('itemTable').value);
            formData.append('name', document.getElementById('itemName').value);
            formData.append('item_code', document.getElementById('itemCode').value);
            formData.append('department_id', document.getElementById('itemDepartmentId').value);
            formData.append('category', document.getElementById('itemCategory').value);
            formData.append('quantity', document.getElementById('itemQuantity').value);
            formData.append('location', document.getElementById('itemLocation').value);
            formData.append('status', document.getElementById('itemStatus').value);
            formData.append('description', document.getElementById('itemDescription').value);
            
            // Add image file if selected
            const itemImageInput = document.getElementById('itemImage');
            if (itemImageInput.files.length > 0) {
                formData.append('image', itemImageInput.files[0]);
            }
            
            fetch('crud.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showScanResult('✓ Item added successfully!', 'success');
                    
                    // Clear form
                    document.getElementById('addItemForm').reset();
                    document.getElementById('item-preview').classList.add('hidden');
                    
                    // Show success message longer
                    setTimeout(() => {
                        document.getElementById('scan-result').style.display = 'none';
                    }, 5000);
                } else {
                    showScanResult('Error: ' + (data.message || 'Failed to add item'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showScanResult('Error adding item: ' + error.message, 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }
        
        // Drag and drop functionality
        const uploadArea = document.querySelector('.upload-area');
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                const file = files[0];
                if (file.type.startsWith('image/')) {
                    handleFileUpload({ target: { files: [file] } });
                } else {
                    showScanResult('Please drop an image file.', 'error');
                }
            }
        });
        
        // Auto-preview when fields change
        document.getElementById('itemName').addEventListener('input', showItemPreview);
        document.getElementById('itemCode').addEventListener('input', showItemPreview);
        document.getElementById('itemCategory').addEventListener('change', showItemPreview);
        document.getElementById('itemLocation').addEventListener('change', showItemPreview);
        
        // Auto-populate department when item table is selected
        document.getElementById('itemTable').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                // Get the department name from the option text
                const optionText = selectedOption.textContent;
                const departmentMatch = optionText.match(/\(([^)]+)\)/);
                if (departmentMatch) {
                    const departmentName = departmentMatch[1];
                    const departmentSelect = document.getElementById('itemDepartmentId');
                    const departmentOptions = Array.from(departmentSelect.options);
                    const departmentOption = departmentOptions.find(opt => opt.text === departmentName);
                    if (departmentOption) {
                        departmentSelect.value = departmentOption.value;
                    }
                }
            }
        });
        
    </script>
</body>
</html>
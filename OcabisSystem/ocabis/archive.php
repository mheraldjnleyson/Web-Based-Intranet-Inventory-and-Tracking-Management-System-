<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit; 
}

// Get user context for department restrictions
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isDepartmentHead = $isAdmin && !$isSuperAdmin; // Department head (admin but not super admin)
$userDepartment = isset($_SESSION['department']) ? $_SESSION['department'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>OCABIS Archive</title>
    <link rel="stylesheet" href="Css/archive.css">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="Css/department.css">
    <script src="js/session_monitor.js"></script>
    <script src="modal.js"></script>
    <style>
        /* Desktop Table Styles - Ensure visibility */
        .table-container {
            width: 100%;
            overflow-x: auto;
            display: block;
        }

        table {
            width: 100%;
            display: table;
            border-collapse: collapse;
        }

        table th,
        table td {
            display: table-cell;
            visibility: visible;
            pointer-events: auto;
        }

        table tbody tr {
            display: table-row;
            visibility: visible;
        }

        /* Card View Styles */
        .no-items-card {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .no-items-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .no-items-card h3 {
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 20px;
        }

        .no-items-card p {
            color: #718096;
            font-size: 14px;
        }

        .item-image-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            background: #f3f4f6;
            border-radius: 8px;
        }

        .item-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }

        .archived-table-card {
            cursor: default;
        }

        .archived-item-card {
            cursor: pointer;
        }

        .archived-item-card:hover {
            transform: translateY(-2px);
        }

        /* Items cards container - grid layout like department page */
        #itemsCardsContainer {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)) !important;
            gap: 20px !important;
            padding: 20px !important;
        }

        /* Vertical card styling - like item tables in department page */
        .archived-table-card {
            display: flex !important;
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 0 !important;
            padding: 16px 20px !important;
            min-height: auto !important;
            height: auto !important;
            max-height: none !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
            border: 1px solid #e5e7eb !important;
            border-radius: 12px !important;
            background: white !important;
        }

        .archived-table-card .item-image-container {
            width: 100% !important;
            height: 200px !important;
            flex-shrink: 0 !important;
            margin: 0 0 12px 0 !important;
            border-radius: 8px !important;
            overflow: hidden !important;
            background: #f3f4f6 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }

        .archived-table-card .item-card-content {
            flex: 1 !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
            min-width: 0 !important;
            justify-content: flex-start !important;
            padding: 0 !important;
        }

        .archived-table-card .item-title-row {
            margin-bottom: 12px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
        }

        .archived-table-card .item-card-title {
            font-size: 18px !important;
            font-weight: 600 !important;
            line-height: 1.3 !important;
            margin: 0 !important;
            color: #1f2937 !important;
        }

        .archived-table-card .quantity-text {
            margin: 0 0 8px 0 !important;
            font-size: 14px !important;
            color: #374151 !important;
            font-weight: 500 !important;
            line-height: 1.4 !important;
        }

        .archived-table-card .meta-row {
            margin-top: 8px !important;
            display: flex !important;
            gap: 20px !important;
            margin-bottom: 0 !important;
        }

        .archived-table-card .meta {
            font-size: 12px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 2px !important;
        }

        .archived-table-card .meta-label {
            font-size: 12px !important;
            color: #6b7280 !important;
            font-weight: 500 !important;
            margin-bottom: 2px !important;
        }

        .archived-table-card .meta-value {
            font-size: 13px !important;
            color: #374151 !important;
            font-weight: 400 !important;
            margin: 0 !important;
        }

        /* Ensure modal shows properly */
        #viewArchivedGroupModal.show {
            display: flex !important;
        }

        /* Table Group Styling */
        .item-table-group-header {
            background: #f8f9fa !important;
            border-top: 2px solid #dee2e6 !important;
            font-weight: 600;
        }

        .item-table-group-header:hover {
            background: #e9ecef !important;
        }

        .item-in-table {
            background: #ffffff;
        }

        .item-in-table:hover {
            background: #f8f9fa;
        }

        .table-group-action-btn {
            transition: all 0.2s ease;
        }

        .table-group-action-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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

        /* Pagination Styles - Desktop */
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
        
        .pagination-controls {
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
        
        .page-numbers {
            display: flex;
            gap: 4px;
            margin: 0 10px;
            align-items: center;
        }
        
        .page-number {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background-color: white;
            color: #6c757d;
            cursor: pointer;
            border-radius: 4px;
            font-size: 14px;
            transition: all 0.2s ease;
            min-width: 40px;
            text-align: center;
        }
        
        .page-number:hover:not(.active) {
            background-color: #f8f9fa;
            border-color: #999;
        }
        
        .page-number.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }

        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            .type-badge {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .type-item {
                background: #e3f2fd;
                color: #1976d2;
            }
            
            .type-category {
                background: #f3e5f5;
                color: #7b1fa2;
            }

            /* Sidebar Mobile Responsive Styles */
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
                padding: 10px !important;
                width: 100% !important;
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

            /* Header */
            .header {
                margin-bottom: 15px;
            }

            .header h2 {
                font-size: 20px !important;
            }

            /* Search Bar */
            .search-bar {
                width: 100% !important;
                margin-bottom: 15px;
                font-size: 14px;
            }

            /* Filters */
            .filters {
                flex-direction: column !important;
                gap: 10px !important;
                margin-bottom: 15px;
            }

            .filter-select {
                width: 100% !important;
                font-size: 14px;
            }

            /* Bulk Selection */
            .bulk-selection-container {
                flex-direction: column !important;
                gap: 10px !important;
                padding: 12px !important;
            }

            .bulk-selection-actions {
                flex-direction: column !important;
                gap: 8px !important;
            }

            .bulk-action-btn {
                width: 100% !important;
                font-size: 13px;
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

            table {
                min-width: 900px;
                width: 100%;
                display: table;
            }

            table th,
            table td {
                padding: 8px !important;
                font-size: 12px !important;
                display: table-cell;
                visibility: visible;
            }

            table tbody tr {
                display: table-row;
                visibility: visible;
            }

            /* Archive Table Title */
            .archive-table-title {
                margin-bottom: 10px;
            }

            .archive-table-title h3 {
                font-size: 16px !important;
            }

            /* Pagination */
            .pagination-container {
                flex-direction: column !important;
                gap: 15px !important;
                align-items: flex-start !important;
            }

            .pagination-controls {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .pagination-btn,
            .page-number {
                font-size: 12px !important;
                padding: 6px 10px !important;
            }

            /* Modals */
            .modal {
                width: 95% !important;
                max-width: 95% !important;
                padding: 16px !important;
                margin: 10px !important;
            }

            .modal-header {
                padding: 15px !important;
            }

            .modal-title {
                font-size: 18px !important;
            }

            .modal-body {
                padding: 15px !important;
            }

            .modal-buttons {
                flex-direction: column !important;
                gap: 10px !important;
            }

            .modal-btn {
                width: 100% !important;
            }

            /* Action Menu */
            .action-menu {
                position: relative;
            }

            .action-dropdown {
                position: fixed !important;
                z-index: 10000 !important;
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

        @media (max-width: 480px) {
            .header h2 {
                font-size: 18px !important;
            }

            .archive-table-title h3 {
                font-size: 14px !important;
            }

            table th,
            table td {
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
<body data-user-logged-in="true" data-user-super-admin="<?= $isSuperAdmin ? 'true' : 'false' ?>" data-user-is-department-head="<?= $isDepartmentHead ? 'true' : 'false' ?>">
     <!-- Sidebar toggle (hamburger) -->
    
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
        <a href="archive.php" class="nav-link active" title="Archive">
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

    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>

    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        <div class="header">
            <img src="image/icons8-archive-50.png" alt="Archive" style="width:24px;height:24px;vertical-align:middle;margin-right:8px;opacity:0.9;" />
            <h2>Archive</h2>
        </div>

        <input type="text" class="search-bar" placeholder="Search...">

        <div class="filters">
            <select class="filter-select" id="typeFilter">
                <option value="">All Types</option>
                <option value="item">Items</option>
                <option value="category">Categories</option>
            </select>
            
            <?php if ($isSuperAdmin): ?>
            <select class="filter-select" id="departmentFilter">
                <option value="">All Departments</option>
            </select>
            <?php endif; ?>
            
            <select class="filter-select" id="categoryFilter">
                <option value="">All Categories</option>
            </select>
            
            <select class="filter-select" id="locationFilter">
                <option value="">All Locations</option>
            </select>
            
            <select class="filter-select" id="archivedByFilter">
                <option value="">All Users</option>
            </select>
        </div>

        <!-- Bulk Selection Container -->
        <div class="bulk-selection-container" id="bulkSelectionContainer" style="display: none;">
            <div class="bulk-selection-info">
                <span id="selectedCount">0</span> items selected
            </div>
            <div class="bulk-selection-actions">
                <button class="bulk-action-btn restore-btn" onclick="bulkRestoreSelected()">
                    <img src="image/restore.png" alt="Restore" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;" />
                    Restore Selected
                </button>
                <?php if (!$isDepartmentHead): ?>
                <button class="bulk-action-btn delete-btn" onclick="bulkDeleteSelected()">
                    <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;" />
                    Delete Selected
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items Cards Container -->
        <div class="items-cards-container" id="itemsCardsContainer" style="display: none;">
            <!-- Cards will be rendered here -->
        </div>
        
        <!-- Items Table Container (Hidden by default, kept for compatibility) -->
        <div class="table-container" id="itemsTableContainer" style="display: none;">
            <div class="archive-table-title">
                <img src="image/icons8-archive-50.png" alt="Items" style="width:24px;height:24px;vertical-align:middle;margin-right:8px;" />
                <h3>Archived Items</h3>
            </div>
            <table id="itemsArchiveTable">
                <thead>
                    <tr>
                        <th class="checkbox-column">
                            <input type="checkbox" id="selectAllArchived" onchange="toggleSelectAllArchived()" />
                        </th>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Archived by</th>
                        <th>Archived date <span class="sort-arrow">▼</span></th>
                        <th>Original Department</th>
                        <th>Category</th>
                        <th>Location</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="archiveTableBody">
                    <tr><td colspan="9" style="text-align:center;padding:16px;">Loading archived items...</td></tr>
                </tbody>
            </table>
        </div>
        
        <!-- Categories Table Container (Separated) -->
        <div class="table-container" id="categoriesTableContainer" style="overflow: visible; position: relative; display: none; margin-top: 40px;">
            <div class="archive-table-title">
                <img src="image/icons8-categorize-50.png" alt="Categories" style="width:24px;height:24px;vertical-align:middle;margin-right:8px;" />
                <h3>Archived Categories</h3>
            </div>
            <table id="categoriesArchiveTable">
                <thead>
                    <tr>
                        <th class="checkbox-column">
                            <input type="checkbox" id="selectAllArchivedCategories" onchange="toggleSelectAllArchivedCategories()" />
                        </th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Account</th>
                        <th>Archived by</th>
                        <th>Archived date <span class="sort-arrow">▼</span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="categoriesArchiveTableBody">
                    <tr><td colspan="7" style="text-align:center;padding:16px;">Loading archived categories...</td></tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination Controls -->
        <div class="pagination-container">
            <div class="pagination-info">
                <span id="paginationInfo">Showing 0-0 of 0 items</span>
            </div>
            <div class="pagination-controls">
                <button id="prevPage" class="pagination-btn" disabled>Previous</button>
                <span id="pageNumbers" class="page-numbers"></span>
                <button id="nextPage" class="pagination-btn" disabled>Next</button>
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

    <!-- Restore Confirmation Modal -->
    <div class="modal-overlay" id="restoreConfirmModal">
        <div class="modal">
            <div class="modal-header" style="background: linear-gradient(135deg, #e53e3e, #c53030); color: white; display: flex; justify-content: space-between; align-items: center; padding: 20px 25px;">
                <h3 style="color: white; margin: 0;">Restore Item</h3>
                <button class="close-btn" onclick="closeRestoreModal()" style="color: white; background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3); width: 30px; height: 30px; border-radius: 50%; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;">×</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <div style="width: 80px; height: 80px; margin: 0 auto 20px; background: linear-gradient(135deg, #10b981, #059669); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <img src="image/restore.png" alt="Restore" style="width: 40px; height: 40px; filter: brightness(0) invert(1);" />
                    </div>
                    <h4 style="margin: 0 0 12px 0; color: #333; font-size: 20px; font-weight: 600;">Are you sure you want to restore this item?</h4>
                    <p style="color: #666; margin: 0 0 8px 0; font-size: 15px;" id="restoreMessage">This item will be moved back to the active inventory.</p>
                    <p style="color: #999; font-size: 13px; margin: 0;">The item will be available for use again.</p>
                </div>
            </div>
            <div class="modal-buttons" style="padding: 20px 25px; border-top: 1px solid #eee; background: #f8f9fa; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="modal-btn modal-btn-cancel" onclick="closeRestoreModal()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">Cancel</button>
                <button class="modal-btn modal-btn-success" id="confirmRestoreBtn" style="padding: 10px 20px; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                    <img src="image/restore.png" alt="Restore" style="width: 16px; height: 16px; filter: brightness(0) invert(1);">
                    Restore Item
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteConfirmModal">
        <div class="modal">
            <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white; display: flex; justify-content: space-between; align-items: center; padding: 20px 25px;">
                <h3 style="color: white; margin: 0;">Delete Item</h3>
                <button class="close-btn" onclick="closeDeleteModal()" style="color: white; background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3); width: 30px; height: 30px; border-radius: 50%; font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;">×</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <div style="width: 80px; height: 80px; margin: 0 auto 20px; background: linear-gradient(135deg, #dc3545, #c82333); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <img src="image/delete.png" alt="Delete" style="width: 40px; height: 40px; filter: brightness(0) invert(1);" />
                    </div>
                    <h4 style="margin: 0 0 12px 0; color: #333; font-size: 20px; font-weight: 600;">Are you sure you want to delete this item?</h4>
                    <p style="color: #666; margin: 0 0 8px 0; font-size: 15px;" id="deleteMessage">This action cannot be undone.</p>
                    <p style="color: #999; font-size: 13px; margin: 0;">The item will be permanently removed from the system.</p>
                </div>
            </div>
            <div class="modal-buttons" style="padding: 20px 25px; border-top: 1px solid #eee; background: #f8f9fa; display: flex; justify-content: flex-end; gap: 10px;">
                <button class="modal-btn modal-btn-cancel" onclick="closeDeleteModal()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">Cancel</button>
                <button class="modal-btn modal-btn-danger" id="confirmDeleteBtn" style="padding: 10px 20px; background: linear-gradient(135deg, #dc3545, #c82333); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                    <img src="image/delete.png" alt="Delete" style="width: 16px; height: 16px; filter: brightness(0) invert(1);">
                    Delete Permanently
                </button>
            </div>
        </div>
    </div>

    <!-- Archived Item Details Modal -->
    <div class="modal-overlay" id="archivedItemDetailsModal">
        <div class="modal details-modal" style="max-width: 700px; border-radius: 16px; overflow: hidden;">
            <div class="modal-header details-header" style="background: linear-gradient(135deg, #e53e3e, #c53030); color: white; padding: 30px; display: flex; justify-content: space-between; align-items: center;">
                <div class="details-header-content" style="display: flex; align-items: center; gap: 20px;">
                    <div class="modal-icon-box" style="background: rgba(255, 255, 255, 0.2); border-radius: 16px; padding: 16px; backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3);">
                        <img src="image/icons8-archive-50.png" alt="Archive" style="width:40px;height:40px;filter:brightness(0) invert(1);" />
                    </div>
                    <div>
                        <h3 class="modal-title" style="margin: 0; font-size: 24px; font-weight: 700; color: white;">ITEM DETAILS</h3>
                        <p class="modal-subtitle" style="margin: 4px 0 0 0; font-size: 14px; opacity: 0.9; color: white;">Complete item information</p>
                    </div>
                </div>
                <button class="close-btn" onclick="closeArchivedDetails()" style="background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3); color: white; font-size: 24px; cursor: pointer; padding: 0; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 12px; backdrop-filter: blur(10px); transition: all 0.2s;">×</button>
            </div>
            <div class="modal-body details-body" id="archivedItemDetailsContent" style="padding: 30px; max-height: calc(80vh - 120px); overflow-y: auto;"></div>
        </div>
    </div>

    <!-- View Archived Table Group Items Modal -->
    <div class="modal-overlay" id="viewArchivedGroupModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.6); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px); visibility: hidden;">
        <div class="modal" style="max-width: 900px; max-height: 85vh; border-radius: 16px; overflow: hidden; background: white; box-shadow: 0 25px 50px rgba(0,0,0,0.25); display: flex; flex-direction: column;">
            <div class="modal-header" style="background: linear-gradient(135deg, #e53e3e, #c53030); color: white; padding: 25px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
                <div>
                    <h3 class="modal-title" style="margin: 0; font-size: 22px; font-weight: 700; color: white;" id="archivedGroupModalTitle">Archived Items</h3>
                    <p style="margin: 4px 0 0 0; font-size: 14px; opacity: 0.9; color: white;" id="archivedGroupModalSubtitle">View all items in this group</p>
                </div>
                <button class="close-btn" onclick="closeArchivedGroupModal()" style="background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3); color: white; font-size: 24px; cursor: pointer; padding: 0; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 12px; backdrop-filter: blur(10px); transition: all 0.2s;">×</button>
            </div>
            <div class="modal-body" style="padding: 25px; max-height: calc(85vh - 120px); overflow-y: auto; flex: 1; min-height: 0;">
                <div id="archivedGroupItemsContent" style="display: flex; flex-direction: column; gap: 12px;">
                    <!-- Items will be rendered here -->
                </div>
            </div>
            <div class="modal-buttons" style="padding: 20px 25px; border-top: 1px solid #eee; background: #f8f9fa; display: flex; justify-content: flex-end; gap: 10px; flex-shrink: 0;">
                <button onclick="closeArchivedGroupModal()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 500;">Close</button>
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

        // Highlight active nav-link (optional for SPA feel)
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function() {
                document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
                this.classList.add('active');
                // No preventDefault, so links work!
            });
        });

        // Filter functionality
        document.querySelectorAll('.filter-select').forEach(select => {
            select.addEventListener('change', function() {
                console.log(`Filter changed: ${this.value}`);
                applyFilters();
            });
        });

        // Search functionality
        document.querySelector('.search-bar').addEventListener('input', function() {
            applyFilters();
        });

        // Permission flags from PHP session
        const IS_SUPER_ADMIN = (document.body?.dataset?.userSuperAdmin || 'false') === 'true';
        const IS_DEPARTMENT_HEAD = (document.body?.dataset?.userIsDepartmentHead || 'false') === 'true';
        
        // Global variables to store data
        let allArchivedItems = [];
        let filteredItems = [];
        let currentPage = 1;
        const itemsPerPage = 10;
        
        // Selection state for bulk actions
        let selectedItems = new Set();

        // Load filter data from backend
        async function loadFilterData() {
            try {
                const res = await fetch('crud.php?action=get_filter_data');
                const data = await res.json();
                if (!data.success) throw new Error(data.message || 'Failed to load filter data');
                
                // Populate department filter (only if it exists - super admins only)
                const deptSelect = document.getElementById('departmentFilter');
                if (deptSelect) {
                    data.departments.forEach(dept => {
                        const option = document.createElement('option');
                        option.value = dept.name;
                        option.textContent = dept.name;
                        deptSelect.appendChild(option);
                    });
                }
                
                // Populate category filter
                const catSelect = document.getElementById('categoryFilter');
                data.categories.forEach(cat => {
                    const option = document.createElement('option');
                    option.value = cat;
                    option.textContent = cat;
                    catSelect.appendChild(option);
                });
                
                // Populate location filter
                const locSelect = document.getElementById('locationFilter');
                data.locations.forEach(loc => {
                    const option = document.createElement('option');
                    option.value = loc;
                    option.textContent = loc;
                    locSelect.appendChild(option);
                });
                
                // Populate archived by filter
                const archivedBySelect = document.getElementById('archivedByFilter');
                data.archived_by_users.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.username;
                    option.textContent = user.username;
                    archivedBySelect.appendChild(option);
                });
            } catch (e) {
                console.error('Error loading filter data:', e);
            }
        }

        // Load archived items and categories from backend
        async function loadArchivedItems() {
            const tbody = document.getElementById('archiveTableBody');
            try {
                // Load archived items
                const itemsRes = await fetch('crud.php?action=get_archived');
                const itemsData = await itemsRes.json();
                if (!itemsData.success) throw new Error(itemsData.message || 'Failed to load items');
                
                // Load archived categories
                const categoriesRes = await fetch('crud.php?action=get_archived_categories');
                const categoriesData = await categoriesRes.json();
                if (!categoriesData.success) throw new Error(categoriesData.message || 'Failed to load categories');
                
                // Combine items and categories
                const archivedItems = (itemsData.items || []).map(item => ({...item, type: 'item'}));
                const archivedCategories = (categoriesData.categories || []).map(cat => ({...cat, type: 'category'}));
                
                // Sort by archived_at date (newest first) for better organization
                allArchivedItems = [...archivedItems, ...archivedCategories].sort((a, b) => {
                    const dateA = new Date(a.archived_at || 0);
                    const dateB = new Date(b.archived_at || 0);
                    return dateB - dateA; // Newest first
                });
                
                filteredItems = [...allArchivedItems];
                
                if (allArchivedItems.length === 0) {
                    document.getElementById('archiveTableBody').innerHTML = '<tr><td colspan="9" style="text-align:center;padding:16px;">No archived items</td></tr>';
                    document.getElementById('categoriesArchiveTableBody').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:16px;">No archived categories</td></tr>';
                    return;
                }
                
                renderTable();
            } catch (e) {
                document.getElementById('archiveTableBody').innerHTML = '<tr><td colspan="9" style="text-align:center;padding:16px;color:#c00;">Error loading archived items</td></tr>';
                document.getElementById('categoriesArchiveTableBody').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:16px;color:#c00;">Error loading archived categories</td></tr>';
                console.error(e);
            }
        }

        // Render table with current filtered items
        function renderTable() {
            const typeFilter = document.getElementById('typeFilter').value;
            const itemsTableContainer = document.getElementById('itemsTableContainer');
            const categoriesTableContainer = document.getElementById('categoriesTableContainer');
            const itemsTbody = document.getElementById('archiveTableBody');
            const categoriesTbody = document.getElementById('categoriesArchiveTableBody');
            
            // Separate items and categories, and sort by archived_at (newest first)
            const filteredItemsOnly = filteredItems.filter(item => item.type === 'item').sort((a, b) => {
                const dateA = new Date(a.archived_at || 0);
                const dateB = new Date(b.archived_at || 0);
                return dateB - dateA; // Newest first
            });
            const filteredCategoriesOnly = filteredItems.filter(item => item.type === 'category').sort((a, b) => {
                const dateA = new Date(a.archived_at || 0);
                const dateB = new Date(b.archived_at || 0);
                return dateB - dateA; // Newest first
            });
            
            // Show/hide containers based on filter
            const cardsContainer = document.getElementById('itemsCardsContainer');
            
            if (typeFilter === 'category') {
                if (itemsTableContainer) itemsTableContainer.style.display = 'none';
                if (cardsContainer) cardsContainer.style.display = 'none';
                categoriesTableContainer.style.display = 'block';
            } else if (typeFilter === 'item') {
                if (itemsTableContainer) itemsTableContainer.style.display = 'none';
                if (cardsContainer) cardsContainer.style.display = filteredItemsOnly.length > 0 ? 'grid' : 'none';
                categoriesTableContainer.style.display = 'none';
            } else {
                // Show both if no filter (with spacing)
                if (itemsTableContainer) itemsTableContainer.style.display = 'none';
                if (cardsContainer) cardsContainer.style.display = filteredItemsOnly.length > 0 ? 'grid' : 'none';
                categoriesTableContainer.style.display = filteredCategoriesOnly.length > 0 ? 'block' : 'none';
                // Add margin-top to categories container when both are shown
                if (filteredItemsOnly.length > 0 && filteredCategoriesOnly.length > 0) {
                    categoriesTableContainer.style.marginTop = '40px';
                } else {
                    categoriesTableContainer.style.marginTop = '0';
                }
            }
            
            // Group items by item_table_id first, then by name within each table
            // This ensures items with same table_id AND same name are grouped together
            const itemsByTable = {};
            const itemsWithoutTable = [];
            
            filteredItemsOnly.forEach(item => {
                if (item.item_table_id && item.item_table_id !== null) {
                    // Group by table_id first, then by name within the table
                    // Use table_id as primary key, but group items with same name together
                    const tableKey = item.item_table_id;
                    const itemName = item.name || 'unnamed';
                    
                    if (!itemsByTable[tableKey]) {
                        itemsByTable[tableKey] = {
                            tableId: tableKey,
                            tableName: item.item_table_name || itemName || `Table ID: ${tableKey}`,
                            items: [],
                            archivedAt: item.archived_at,
                            archivedBy: item.archived_by,
                            department: item.department_name,
                            category: item.category,
                            tableImagePath: item.table_image_path,
                            imagePath: item.image_path
                        };
                    }
                    itemsByTable[tableKey].items.push(item);
            } else {
                    // For items without table_id, group by name
                    const nameKey = item.name || 'unnamed';
                    const existingGroup = itemsWithoutTable.find(group => group.itemName === nameKey);
                    
                    if (!existingGroup) {
                        itemsWithoutTable.push({
                            itemName: nameKey,
                            items: [item]
                        });
                    } else {
                        existingGroup.items.push(item);
                    }
                }
            });
            
            // Convert grouped tables and standalone items into display units for pagination
            const displayUnits = [];
            
            // Add each table group as one unit
            Object.values(itemsByTable).forEach(tableGroup => {
                displayUnits.push({
                    type: 'table_group',
                    tableGroup: tableGroup,
                    archivedAt: tableGroup.archivedAt
                });
            });
            
            // Add standalone items as grouped units (grouped by name)
            itemsWithoutTable.forEach(group => {
                // Sort items by archived date (newest first) to get the most recent archived date
                group.items.sort((a, b) => {
                    const dateA = new Date(a.archived_at || 0);
                    const dateB = new Date(b.archived_at || 0);
                    return dateB - dateA;
                });
                
                displayUnits.push({
                    type: 'standalone_group',
                    group: {
                        itemName: group.itemName,
                        items: group.items,
                        archivedAt: group.items[0].archived_at, // Use first item's date (newest)
                        archivedBy: group.items[0].archived_by,
                        department: group.items[0].department_name,
                        category: group.items[0].category
                    },
                    archivedAt: group.items[0].archived_at
                });
            });
            
            // Sort display units by archived date (newest first)
            displayUnits.sort((a, b) => {
                const dateA = new Date(a.archivedAt || 0);
                const dateB = new Date(b.archivedAt || 0);
                return dateB - dateA;
            });
            
            // Determine which items are active for pagination
            let activeDisplayUnits = [];
            let totalItemsForPagination = 0;
            
            if (typeFilter === 'category') {
                activeDisplayUnits = filteredCategoriesOnly.map(cat => ({
                    type: 'category',
                    item: cat,
                    archivedAt: cat.archived_at
                })).sort((a, b) => {
                    const dateA = new Date(a.archivedAt || 0);
                    const dateB = new Date(b.archivedAt || 0);
                    return dateB - dateA;
                });
                totalItemsForPagination = activeDisplayUnits.length;
            } else if (typeFilter === 'item') {
                activeDisplayUnits = displayUnits;
                totalItemsForPagination = activeDisplayUnits.length; // Each group counts as 1 unit
            } else {
                // When showing both, combine them for pagination
                const categoryUnits = filteredCategoriesOnly.map(cat => ({
                    type: 'category',
                    item: cat,
                    archivedAt: cat.archived_at
                }));
                activeDisplayUnits = [...displayUnits, ...categoryUnits].sort((a, b) => {
                    const dateA = new Date(a.archivedAt || 0);
                    const dateB = new Date(b.archivedAt || 0);
                    return dateB - dateA;
                });
                totalItemsForPagination = activeDisplayUnits.length;
            }
            
            // Paginate the display units (each table group counts as 1 unit)
            const totalPages = Math.ceil(totalItemsForPagination / itemsPerPage);
                    const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = Math.min(startIndex + itemsPerPage, totalItemsForPagination);
            const paginatedDisplayUnits = activeDisplayUnits.slice(startIndex, endIndex);
            
            // Separate paginated units back into items and categories
            const paginatedItemsOnly = [];
            const paginatedCategoriesOnly = [];
            
            paginatedDisplayUnits.forEach(unit => {
                if (unit.type === 'table_group') {
                    // Add all items from this table group
                    paginatedItemsOnly.push(...unit.tableGroup.items);
                } else if (unit.type === 'standalone_item') {
                    paginatedItemsOnly.push(unit.item);
                } else if (unit.type === 'standalone_group') {
                    // Add all items from this standalone group
                    paginatedItemsOnly.push(...unit.group.items);
                } else if (unit.type === 'category') {
                    paginatedCategoriesOnly.push(unit.item);
                }
            });
            
            // Render items as cards grouped by item_table_id
            if (filteredItemsOnly.length === 0) {
                if (cardsContainer) {
                    cardsContainer.innerHTML = '<div class="no-items-card"><div class="no-items-icon">📦</div><h3>No Archived Items</h3><p>No archived items match the current filters</p></div>';
                    cardsContainer.style.display = 'grid';
                }
                itemsTbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:16px;">No archived items match the current filters</td></tr>';
            } else {
                // Build HTML for grouped items - use the already grouped and paginated data
                let html = '';
                
                // Get paginated table groups for table view
                const paginatedTableGroupsForTable = paginatedDisplayUnits
                    .filter(unit => unit.type === 'table_group')
                    .map(unit => unit.tableGroup);
                
                // Render items grouped by table (for table view)
                paginatedTableGroupsForTable.forEach(tableGroup => {
                    // Table header row
                    const itemCount = tableGroup.items.length;
                    const tableName = tableGroup.tableName;
                    const tableId = tableGroup.tableId;
                    const allItemIds = tableGroup.items.map(i => i.id).join(',');
                    
                    html += `
                        <tr class="item-table-group-header" style="background: #f8f9fa; border-top: 2px solid #dee2e6;">
                            <td class="checkbox-column">
                                <input type="checkbox" class="table-group-checkbox" data-table-id="${tableId}" onchange="toggleTableGroupSelection(${tableId}, event)" />
                            </td>
                            <td colspan="7" style="padding: 12px; font-weight: 600; color: #495057;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div>
                                        <span style="font-size: 16px;">📦 ${tableName}</span>
                                        <span style="margin-left: 12px; font-size: 13px; color: #6c757d; font-weight: 500;">(${itemCount} ${itemCount === 1 ? 'item' : 'items'})</span>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <button class="table-group-action-btn" onclick="restoreTableGroup(${tableId}, '${tableName.replace(/'/g, "\\'")}')" style="padding: 6px 12px; background: #10b981; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;">
                                            <img src="image/restore.png" alt="Restore" style="width:12px;height:12px;vertical-align:middle;margin-right:4px;filter:brightness(0) invert(1);" />
                                            Restore All
                                        </button>
                                        ${!IS_DEPARTMENT_HEAD ? `
                                        <button class="table-group-action-btn" onclick="deleteTableGroup(${tableId}, '${tableName.replace(/'/g, "\\'")}')" style="padding: 6px 12px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;">
                                            <img src="image/delete.png" alt="Delete" style="width:12px;height:12px;vertical-align:middle;margin-right:4px;filter:brightness(0) invert(1);" />
                                            Delete All
                                        </button>
                                        ` : ''}
                                    </div>
                                </div>
                            </td>
                            <td></td>
                        </tr>
                    `;
                    
                    // Render items in this table group
                    tableGroup.items.forEach(item => {
                        html += `
                            <tr data-item-id="${item.id}" data-item-type="${item.type}" data-table-id="${tableId}" class="selectable-row item-in-table" style="background: #ffffff;">
                                <td class="checkbox-column">
                                    <input type="checkbox" class="item-checkbox" data-table-id="${tableId}" onchange="toggleItemSelection(${item.id}, event)" />
                                </td>
                                <td>
                                    <span class="type-badge type-item">Item</span>
                                </td>
                                <td><span class="item-icon"></span>${item.name}</td>
                                <td>${item.archived_by || '—'}</td>
                                <td>${new Date(item.archived_at).toLocaleString()}</td>
                                <td>${item.department_name || '—'}</td>
                                <td>${item.category || '—'}</td>
                                <td>${item.location || '—'}</td>
                                <td>
                                    <div class="action-menu">
                                        <button class="action-btn" onclick="toggleActionMenu(${item.id})">⋮</button>
                                        <div class="action-dropdown" id="actionMenu${item.id}">
                                            <button class="action-item" onclick="openRestoreConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')">
                                                <img src="image/restore.png" alt="Restore" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                Restore
                                            </button>
                                            <button class="action-item" onclick="viewItemDetails(${item.id}, '${item.type}')">
                                                <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> 
                                                View Details
                                            </button>
                                            ${!IS_DEPARTMENT_HEAD ? `
                                            <button class="action-item delete-action" onclick="openDeleteConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')">
                                                <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                Delete
                                            </button>
                                            ` : ''}
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });
                });
                
                // Render items without table (standalone items) - grouped by name
                const paginatedStandaloneGroups = paginatedDisplayUnits
                    .filter(unit => unit.type === 'standalone_group')
                    .map(unit => unit.group);
                
                // Render standalone groups
                paginatedStandaloneGroups.forEach(group => {
                    const itemCount = group.items.length;
                    const itemName = group.itemName;
                    const archivedDate = new Date(group.archivedAt).toLocaleDateString();
                    
                    // Table header row for standalone group
                    html += `
                        <tr class="item-table-group-header" style="background: #f8f9fa; border-top: 2px solid #dee2e6;">
                            <td class="checkbox-column">
                                <input type="checkbox" class="table-group-checkbox" data-group-name="${itemName.replace(/'/g, "\\'")}" onchange="toggleStandaloneGroupSelection('${itemName.replace(/'/g, "\\'")}', event)" />
                            </td>
                            <td colspan="7" style="padding: 12px; font-weight: 600; color: #495057;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <div>
                                        <span style="font-size: 16px;">📦 ${itemName}</span>
                                        <span style="margin-left: 12px; font-size: 13px; color: #6c757d; font-weight: 500;">(${itemCount} ${itemCount === 1 ? 'item' : 'items'})</span>
                                    </div>
                                    <div style="display: flex; gap: 8px;">
                                        <button class="table-group-action-btn" onclick="restoreStandaloneGroup('${itemName.replace(/'/g, "\\'")}')" style="padding: 6px 12px; background: #10b981; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;">
                                            <img src="image/restore.png" alt="Restore" style="width:12px;height:12px;vertical-align:middle;margin-right:4px;filter:brightness(0) invert(1);" />
                                            Restore All
                                        </button>
                                        ${!IS_DEPARTMENT_HEAD ? `
                                        <button class="table-group-action-btn" onclick="deleteStandaloneGroup('${itemName.replace(/'/g, "\\'")}')" style="padding: 6px 12px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;">
                                            <img src="image/delete.png" alt="Delete" style="width:12px;height:12px;vertical-align:middle;margin-right:4px;filter:brightness(0) invert(1);" />
                                            Delete All
                                        </button>
                                        ` : ''}
                                    </div>
                                </div>
                            </td>
                            <td></td>
                        </tr>
                    `;
                    
                    // Render items in this standalone group
                    group.items.forEach(item => {
                        html += `
                            <tr data-item-id="${item.id}" data-item-type="${item.type}" data-group-name="${itemName.replace(/'/g, "\\'")}" class="selectable-row item-in-table" style="background: #ffffff;">
                                <td class="checkbox-column">
                                    <input type="checkbox" class="item-checkbox" data-group-name="${itemName.replace(/'/g, "\\'")}" onchange="toggleItemSelection(${item.id}, event)" />
                                </td>
                                <td>
                                    <span class="type-badge type-item">Item</span>
                                </td>
                                <td><span class="item-icon"></span>${item.name}</td>
                                <td>${item.archived_by || '—'}</td>
                                <td>${new Date(item.archived_at).toLocaleString()}</td>
                                <td>${item.department_name || '—'}</td>
                                <td>${item.category || '—'}</td>
                                <td>${item.location || '—'}</td>
                                <td>
                                    <div class="action-menu">
                                        <button class="action-btn" onclick="toggleActionMenu(${item.id})">⋮</button>
                                        <div class="action-dropdown" id="actionMenu${item.id}">
                                            <button class="action-item" onclick="openRestoreConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')">
                                                <img src="image/restore.png" alt="Restore" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                Restore
                                            </button>
                                            <button class="action-item" onclick="viewItemDetails(${item.id}, '${item.type}')">
                                                <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> 
                                                View Details
                                            </button>
                                            ${!IS_DEPARTMENT_HEAD ? `
                                            <button class="action-item delete-action" onclick="openDeleteConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')">
                                                <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                Delete
                                            </button>
                                            ` : ''}
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });
                });
                
                // Also handle old standalone_item type for backward compatibility
                const paginatedStandaloneItemsForTable = paginatedDisplayUnits
                    .filter(unit => unit.type === 'standalone_item')
                    .map(unit => unit.item);
                
                paginatedStandaloneItemsForTable.forEach(item => {
                    html += `
                    <tr data-item-id="${item.id}" data-item-type="${item.type}" class="selectable-row">
                        <td class="checkbox-column">
                            <input type="checkbox" class="item-checkbox" onchange="toggleItemSelection(${item.id}, event)" />
                        </td>
                        <td>
                            <span class="type-badge type-item">Item</span>
                        </td>
                        <td><span class="item-icon"></span>${item.name}</td>
                        <td>${item.archived_by || '—'}</td>
                        <td>${new Date(item.archived_at).toLocaleString()}</td>
                        <td>${item.department_name || '—'}</td>
                        <td>${item.category || '—'}</td>
                        <td>${item.location || '—'}</td>
                        <td>
                            <div class="action-menu">
                                <button class="action-btn" onclick="toggleActionMenu(${item.id})">⋮</button>
                                <div class="action-dropdown" id="actionMenu${item.id}">
                                    <button class="action-item" onclick="openRestoreConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')">
                                        <img src="image/restore.png" alt="Restore" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Restore
                                    </button>
                                    <button class="action-item" onclick="viewItemDetails(${item.id}, '${item.type}')">
                                        <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> 
                                        View Details
                                    </button>
                                    ${!IS_DEPARTMENT_HEAD ? `
                                    <button class="action-item delete-action" onclick="openDeleteConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')">
                                        <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Delete
                                    </button>
                                    ` : ''}
                                </div>
                            </div>
                        </td>
                    </tr>
                    `;
                });
                
                itemsTbody.innerHTML = html;
                
                // Render cards view - use the already grouped items
                if (cardsContainer) {
                    let cardsHTML = '';
                    
                    // Get only the table groups that are in the paginated display units
                    const paginatedTableGroups = paginatedDisplayUnits
                        .filter(unit => unit.type === 'table_group')
                        .map(unit => unit.tableGroup);
                    
                    // Render cards for each table group in paginated results
                    paginatedTableGroups.forEach(tableGroup => {
                        const itemCount = tableGroup.items.length;
                        const tableName = tableGroup.tableName;
                        const tableId = tableGroup.tableId;
                        const archivedDate = new Date(tableGroup.archivedAt);
                        const formattedDate = archivedDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: '2-digit' });
                        
                        // Get table image if available - prefer table_image_path, fallback to first item's image_path
                        const firstItem = tableGroup.items[0];
                        const tableImagePath = firstItem.table_image_path || firstItem.image_path;
                        const tableImage = tableImagePath ? 
                            `<img src="${tableImagePath}" alt="${tableName}" class="item-image" />` : 
                            `<div class="item-image-placeholder">📦</div>`;
                        
                        // Determine stock level badge (for consistency with department page)
                        const stockLevel = itemCount <= 5 ? 'LOW' : 'HIGH';
                        const stockLevelColor = itemCount <= 5 ? '#10b981' : '#dc2626';
                        const stockLevelBg = itemCount <= 5 ? '#d1fae5' : '#fee2e2';
                        
                        cardsHTML += `
                            <div class="item-card clickable-card archived-table-card" data-table-id="${tableId}" style="cursor: pointer;" onclick="viewArchivedTableGroup(${tableId}, '${tableName.replace(/'/g, "\\'")}')">
                                <div class="item-image-container">
                                    ${tableImage}
                                </div>
                                <div class="item-card-content">
                                    <div class="item-title-row">
                                        <div class="item-card-title">${tableName}</div>
                                        <div class="card-action-dropdown">
                                            <button class="card-action-btn-menu" onclick="event.stopPropagation(); toggleArchivedTableActionMenu('${tableId}')">⋮</button>
                                            <div class="card-action-menu" id="archived-card-menu-${tableId}">
                                                <button onclick="event.stopPropagation(); viewArchivedTableGroup(${tableId}, '${tableName.replace(/'/g, "\\'")}')">
                                                    <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                    View Items
                                                </button>
                                                <button onclick="event.stopPropagation(); restoreTableGroup(${tableId}, '${tableName.replace(/'/g, "\\'")}')">
                                                    <img src="image/restore.png" alt="Restore" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                    Restore All Items
                                                </button>
                                                ${!IS_DEPARTMENT_HEAD ? `
                                                <button onclick="event.stopPropagation(); deleteTableGroup(${tableId}, '${tableName.replace(/'/g, "\\'")}')" style="color:#dc3545;">
                                                    <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                    Delete All Items
                                                </button>
                                                ` : ''}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="quantity-text">Category: ${tableGroup.category || 'Uncategorized'}</div>
                                    <div class="quantity-text">Items: ${itemCount}</div>
                                    <div style="margin: 2px 0 4px 0;">
                                        <span style="
                                            padding: 4px 10px;
                                            border-radius: 12px;
                                            font-size: 11px;
                                            font-weight: 600;
                                            text-transform: uppercase;
                                            display: inline-block;
                                            background-color: ${stockLevelBg};
                                            color: ${stockLevelColor};
                                        ">${stockLevel === 'LOW' ? '🟢' : '🔴'} ${stockLevel}</span>
                                    </div>
                                    <div class="meta-row">
                                        <div class="meta">
                                            <span class="meta-label">Department:</span>
                                            <span class="meta-value">${tableGroup.department || 'Unknown'}</span>
                                        </div>
                                        <div class="meta">
                                            <span class="meta-label">Archived:</span>
                                            <span class="meta-value">${formattedDate}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    // Render standalone groups (without table) as cards - grouped by name
                    const paginatedStandaloneGroups = paginatedDisplayUnits
                        .filter(unit => unit.type === 'standalone_group')
                        .map(unit => unit.group);
                    
                    paginatedStandaloneGroups.forEach(group => {
                        const itemCount = group.items.length;
                        const itemName = group.itemName;
                        const archivedDate = new Date(group.archivedAt);
                        const formattedDate = archivedDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: '2-digit' });
                        const firstItem = group.items[0];
                        
                        // Use image_path for standalone items
                        const itemImagePath = firstItem.image_path;
                        const itemImage = itemImagePath ? 
                            `<img src="${itemImagePath}" alt="${itemName}" class="item-image" />` : 
                            `<div class="item-image-placeholder">📦</div>`;
                        
                        // Determine stock level badge
                        const stockLevel = itemCount <= 5 ? 'LOW' : 'HIGH';
                        const stockLevelColor = itemCount <= 5 ? '#10b981' : '#dc2626';
                        const stockLevelBg = itemCount <= 5 ? '#d1fae5' : '#fee2e2';
                        
                        cardsHTML += `
                            <div class="item-card clickable-card archived-table-card" data-group-name="${itemName.replace(/'/g, "\\'")}" style="cursor: pointer;" onclick="viewArchivedStandaloneGroup('${itemName.replace(/'/g, "\\'")}')">
                                <div class="item-image-container">
                                    ${itemImage}
                                </div>
                                <div class="item-card-content">
                                    <div class="item-title-row">
                                        <div class="item-card-title">${itemName}</div>
                                        <div class="card-action-dropdown">
                                            <button class="card-action-btn-menu" onclick="event.stopPropagation(); toggleArchivedTableActionMenu('standalone-${itemName.replace(/'/g, "\\'")}')">⋮</button>
                                            <div class="card-action-menu" id="archived-card-menu-standalone-${itemName.replace(/'/g, "\\'")}">
                                                <button onclick="event.stopPropagation(); viewArchivedStandaloneGroup('${itemName.replace(/'/g, "\\'")}')">
                                                    <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                    View Items
                                                </button>
                                                <button onclick="event.stopPropagation(); restoreStandaloneGroup('${itemName.replace(/'/g, "\\'")}')">
                                                    <img src="image/restore.png" alt="Restore" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                    Restore All Items
                                                </button>
                                                ${!IS_DEPARTMENT_HEAD ? `
                                                <button onclick="event.stopPropagation(); deleteStandaloneGroup('${itemName.replace(/'/g, "\\'")}')" style="color:#dc3545;">
                                                    <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                    Delete All Items
                                                </button>
                                                ` : ''}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="quantity-text">Category: ${group.category || 'Uncategorized'}</div>
                                    <div class="quantity-text">Items: ${itemCount}</div>
                                    <div style="margin: 2px 0 4px 0;">
                                        <span style="
                                            padding: 4px 10px;
                                            border-radius: 12px;
                                            font-size: 11px;
                                            font-weight: 600;
                                            text-transform: uppercase;
                                            display: inline-block;
                                            background-color: ${stockLevelBg};
                                            color: ${stockLevelColor};
                                        ">${stockLevel === 'LOW' ? '🟢' : '🔴'} ${stockLevel}</span>
                                    </div>
                                    <div class="meta-row">
                                        <div class="meta">
                                            <span class="meta-label">Department:</span>
                                            <span class="meta-value">${group.department || 'Unknown'}</span>
                                        </div>
                                        <div class="meta">
                                            <span class="meta-label">Archived:</span>
                                            <span class="meta-value">${formattedDate}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    // Also handle old standalone_item type for backward compatibility
                    const paginatedStandaloneItems = paginatedDisplayUnits
                        .filter(unit => unit.type === 'standalone_item')
                        .map(unit => unit.item);
                    
                    paginatedStandaloneItems.forEach(item => {
                        const itemImage = item.image_path ? 
                            `<img src="${item.image_path}" alt="${item.name}" class="item-image" />` : 
                            `<div class="item-image-placeholder">📦</div>`;
                        
                        cardsHTML += `
                            <div class="item-card archived-item-card" data-item-id="${item.id}">
                                <div class="item-image-container">
                                    ${itemImage}
                                </div>
                                <div class="item-card-content">
                                    <div class="item-title-row">
                                        <div class="item-card-title">${item.name}</div>
                                        <div class="card-action-dropdown">
                                            <button class="card-action-btn-menu" onclick="event.stopPropagation(); toggleActionMenu(${item.id})">⋮</button>
                                            <div class="action-dropdown" id="actionMenu${item.id}">
                                                <button class="action-item" onclick="openRestoreConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')">
                                                    <img src="image/restore.png" alt="Restore" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                    Restore
                                                </button>
                                                <button class="action-item" onclick="viewItemDetails(${item.id}, '${item.type}')">
                                                    <img src="image/view-details.png" alt="View" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" /> 
                                                    View Details
                                                </button>
                                                ${!IS_DEPARTMENT_HEAD ? `
                                                <button class="action-item delete-action" onclick="openDeleteConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')">
                                                    <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                                    Delete
                                                </button>
                                                ` : ''}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="quantity-text">Item Code: ${item.item_code || '—'}</div>
                                    <div class="meta-row">
                                        <div class="meta">
                                            <span class="meta-label">Department:</span>
                                            <span class="meta-value">${item.department_name || '—'}</span>
                                        </div>
                                        <div class="meta">
                                            <span class="meta-label">Category:</span>
                                            <span class="meta-value">${item.category || '—'}</span>
                                        </div>
                                    </div>
                                    <div class="meta-row">
                                        <div class="meta">
                                            <span class="meta-label">Archived:</span>
                                            <span class="meta-value">${new Date(item.archived_at).toLocaleDateString()}</span>
                                        </div>
                                        <div class="meta">
                                            <span class="meta-label">Archived by:</span>
                                            <span class="meta-value">${item.archived_by || '—'}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    cardsContainer.innerHTML = cardsHTML;
                    cardsContainer.style.display = 'grid';
                }
            }
            
            // Render categories table
            if (filteredCategoriesOnly.length === 0) {
                categoriesTbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:16px;">No archived categories match the current filters</td></tr>';
            } else {
                // Show paginated categories when filtered, or all categories when showing both (but paginated)
                const categoriesToShow = typeFilter === 'category' ? paginatedCategoriesOnly : 
                                        (typeFilter === '' ? paginatedCategoriesOnly : filteredCategoriesOnly);
                
                categoriesTbody.innerHTML = categoriesToShow.map(cat => `
                    <tr data-item-id="${cat.id}" data-item-type="${cat.type}" class="selectable-row category-archive-row">
                        <td class="checkbox-column">
                            <input type="checkbox" class="item-checkbox" onchange="toggleItemSelection(${cat.id}, event)" />
                        </td>
                        <td>${cat.category_id || cat.id}</td>
                        <td>${cat.name}</td>
                        <td><span class="account-badge">${cat.account || '—'}</span></td>
                        <td>${cat.archived_by || '—'}</td>
                        <td>${new Date(cat.archived_at).toLocaleString()}</td>
                        <td>
                            <div class="action-menu">
                                <button class="action-btn" onclick="toggleActionMenu(${cat.id})">⋮</button>
                                <div class="action-dropdown" id="actionMenu${cat.id}">
                                    <button class="action-item" onclick="openRestoreConfirm(${cat.id}, '${cat.name.replace(/'/g, "\\'")}', '${cat.type}')">
                                        <img src="image/restore.png" alt="Restore" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Restore
                                    </button>
                                    ${!IS_DEPARTMENT_HEAD ? `
                                    <button class="action-item delete-action" onclick="openDeleteConfirm(${cat.id}, '${cat.name.replace(/'/g, "\\'")}', '${cat.type}')">
                                        <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                        Delete
                                    </button>
                                    ` : ''}
                                </div>
                            </div>
                        </td>
                    </tr>
                `).join('');
            }
            
            // Always update pagination when there are items to show
                const paginationContainer = document.querySelector('.pagination-container');
            if (totalItemsForPagination > 0) {
                if (paginationContainer) {
                    paginationContainer.style.display = 'flex';
                }
                updatePagination(totalItemsForPagination);
            } else {
                if (paginationContainer) {
                    paginationContainer.style.display = 'none';
                }
            }
        }

        // Apply filters
        function applyFilters() {
            const typeFilter = document.getElementById('typeFilter').value;
            const deptSelect = document.getElementById('departmentFilter');
            const deptFilter = deptSelect ? deptSelect.value : '';
            const catFilter = document.getElementById('categoryFilter').value;
            const locFilter = document.getElementById('locationFilter').value;
            const archivedByFilter = document.getElementById('archivedByFilter').value;
            const searchTerm = document.querySelector('.search-bar').value.toLowerCase();
            
            filteredItems = allArchivedItems.filter(item => {
                const matchesType = !typeFilter || item.type === typeFilter;
                const matchesDept = !deptFilter || item.department_name === deptFilter;
                const matchesCat = !catFilter || item.category === catFilter;
                const matchesLoc = !locFilter || item.location === locFilter;
                const matchesArchivedBy = !archivedByFilter || item.archived_by === archivedByFilter;
                const matchesSearch = !searchTerm || 
                    item.name.toLowerCase().includes(searchTerm) ||
                    (item.department_name && item.department_name.toLowerCase().includes(searchTerm)) ||
                    (item.category && item.category.toLowerCase().includes(searchTerm)) ||
                    (item.location && item.location.toLowerCase().includes(searchTerm)) ||
                    (item.archived_by && item.archived_by.toLowerCase().includes(searchTerm));
                
                return matchesType && matchesDept && matchesCat && matchesLoc && matchesArchivedBy && matchesSearch;
            });
            
            // Sort filtered items by archived_at (newest first) for better organization
            filteredItems.sort((a, b) => {
                const dateA = new Date(a.archived_at || 0);
                const dateB = new Date(b.archived_at || 0);
                return dateB - dateA; // Newest first
            });
            
            // Reset to first page when filtering
            currentPage = 1;
            renderTable();
        }
        
        // Pagination functions
        function updatePagination(totalItems = null) {
            if (totalItems === null) {
                const typeFilter = document.getElementById('typeFilter').value;
                const filteredItemsOnly = filteredItems.filter(item => item.type === 'item');
                const filteredCategoriesOnly = filteredItems.filter(item => item.type === 'category');
                totalItems = typeFilter === 'category' ? filteredCategoriesOnly.length : 
                            typeFilter === 'item' ? filteredItemsOnly.length : 
                            filteredItems.length;
            }
            
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            const startIndex = (currentPage - 1) * itemsPerPage + 1;
            const endIndex = Math.min(currentPage * itemsPerPage, totalItems);
            
            // Update pagination info
            document.getElementById('paginationInfo').textContent = 
                totalItems > 0 ? `Showing ${startIndex}-${endIndex} of ${totalItems} items` : 'Showing 0-0 of 0 items';
            
            // Update button states
            document.getElementById('prevPage').disabled = currentPage === 1;
            document.getElementById('nextPage').disabled = currentPage === totalPages || totalPages === 0;
            
            // Update page numbers
            updatePageNumbers(totalPages);
        }
        
        function updatePageNumbers(totalPages) {
            const pageNumbersContainer = document.getElementById('pageNumbers');
            pageNumbersContainer.innerHTML = '';
            
            if (totalPages <= 1) return;
            
            const maxVisiblePages = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
            
            if (endPage - startPage + 1 < maxVisiblePages) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.className = `page-number ${i === currentPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => goToPage(i);
                pageNumbersContainer.appendChild(pageBtn);
            }
        }
        
        function goToPage(page) {
            const typeFilter = document.getElementById('typeFilter').value;
            const filteredItemsOnly = filteredItems.filter(item => item.type === 'item');
            const filteredCategoriesOnly = filteredItems.filter(item => item.type === 'category');
            
            // Calculate total items for pagination
            let totalItems = 0;
            if (typeFilter === 'category') {
                totalItems = filteredCategoriesOnly.length;
            } else if (typeFilter === 'item') {
                totalItems = filteredItemsOnly.length;
            } else {
                // When showing both, combine them
                totalItems = filteredItems.length;
            }
            
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            if (page >= 1 && page <= totalPages) {
                currentPage = page;
                renderTable();
            }
        }
        
        function goToPrevPage() {
            goToPage(currentPage - 1);
        }
        
        function goToNextPage() {
            goToPage(currentPage + 1);
        }

        // Action menu functions
        function toggleActionMenu(itemId) {
            // Close all other menus
            document.querySelectorAll('.action-dropdown').forEach(menu => {
                if (menu.id !== `actionMenu${itemId}`) {
                    menu.classList.remove('show');
                }
            });
            
            // Toggle current menu
            const menu = document.getElementById(`actionMenu${itemId}`);
            if (menu) {
            menu.classList.toggle('show');
            }
        }

        function toggleArchivedTableActionMenu(tableId) {
            // Close all other menus
            document.querySelectorAll('.card-action-menu').forEach(menu => {
                if (menu.id !== `archived-card-menu-${tableId}`) {
                    menu.classList.remove('show');
                }
            });
            
            // Toggle current menu
            const menu = document.getElementById(`archived-card-menu-${tableId}`);
            if (menu) {
                menu.classList.toggle('show');
            }
        }

        // Close action menus when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.action-menu') && !e.target.closest('.card-action-dropdown')) {
                document.querySelectorAll('.action-dropdown').forEach(menu => {
                    menu.classList.remove('show');
                });
                document.querySelectorAll('.card-action-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });

        // Restore item function
        function openRestoreConfirm(itemId, itemName, type) {
            document.getElementById('restoreMessage').textContent = `Are you sure you want to restore "${itemName}"?`;
            const btn = document.getElementById('confirmRestoreBtn');
            btn.onclick = async function() {
                await restoreItem(itemId, itemName, type);
                closeRestoreModal();
            };
            document.getElementById('restoreConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeRestoreModal() {
            document.getElementById('restoreConfirmModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        async function restoreItem(itemId, itemName, type, skipReload = false) {
            try {
                const formData = new FormData();
                formData.append('action', type === 'category' ? 'restore_category' : 'restore');
                formData.append('id', itemId);

                const response = await fetch('crud.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    // Only reload if not in bulk mode
                    if (!skipReload) {
                        await loadArchivedItems();
                    }
                    return { success: true };
                } else {
                    if (!skipReload) {
                        modal.error(result.message);
                    }
                    return { success: false, message: result.message };
                }
            } catch (error) {
                console.error('Error restoring item:', error);
                if (!skipReload) {
                    modal.error('Error restoring item. Please try again.');
                }
                return { success: false, message: error.message };
            }
        }

        // Delete functions
        function openDeleteConfirm(itemId, itemName, type) {
            // Prevent department heads from deleting
            if (IS_DEPARTMENT_HEAD) {
                modal.error('You do not have permission to delete archived items. Only restore is allowed.');
                return;
            }
            
            document.getElementById('deleteMessage').textContent = `Are you sure you want to permanently delete "${itemName}"?`;
            
            const btn = document.getElementById('confirmDeleteBtn');
            btn.onclick = async function() {
                await deleteItem(itemId, itemName, type);
            };
            document.getElementById('deleteConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteModal() {
            document.getElementById('deleteConfirmModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        async function deleteItem(itemId, itemName, type, skipReload = false) {
            // Prevent department heads from deleting
            if (IS_DEPARTMENT_HEAD) {
                if (!skipReload) {
                    modal.error('You do not have permission to delete archived items. Only restore is allowed.');
                }
                return { success: false, message: 'Permission denied' };
            }
            
            try {
                const formData = new FormData();
                formData.append('action', type === 'category' ? 'delete_archived_category' : 'delete_archived');
                formData.append('id', itemId);

                const response = await fetch('crud.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();
                
                if (result.success) {
                    if (!skipReload) {
                        closeDeleteModal();
                        await loadArchivedItems();
                        modal.success('Item deleted successfully!');
                    }
                    return { success: true };
                } else {
                    if (!skipReload) {
                        modal.error(result.message);
                    }
                    return { success: false, message: result.message };
                }
            } catch (error) {
                console.error('Error deleting item:', error);
                if (!skipReload) {
                    modal.error('Error deleting item. Please try again.');
                }
                return { success: false, message: error.message };
            }
        }

        // View item details function (modal view only)
        function viewItemDetails(itemId) {
            const item = allArchivedItems.find(i => i.id === itemId);
            if (!item) return;

            const content = `
                <div class="item-details-container">
                    <h2 class="item-name">${item.name}</h2>
                    <div class="details-grid">
                        <div class="detail-row">
                            <div class="detail-label">Item Table Name:</div>
                            <div class="detail-value">${item.name}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">ID :</div>
                            <div class="detail-value">${item.item_id ?? '-'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Categories:</div>
                            <div class="detail-value">${item.category || '—'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Location:</div>
                            <div class="detail-value">${item.location || '—'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value">
                                <span class="status-badge ${String(item.status).toLowerCase() === 'working' ? 'status-working' : 'status-archived'}">${item.status || '—'}</span>
                            </div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Department:</div>
                            <div class="detail-value">${item.department_name || '—'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Archived by:</div>
                            <div class="detail-value">${item.archived_by || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Archived at:</div>
                            <div class="detail-value">${new Date(item.archived_at).toLocaleString()}</div>
                        </div>
                        ${item.description ? `
                        <div class="description-section">
                            <div class="section-title">📝 Description</div>
                            <p class="description-text">${item.description}</p>
                        </div>` : ''}
                    </div>
                </div>
            `;

            document.getElementById('archivedItemDetailsContent').innerHTML = content;
            document.getElementById('archivedItemDetailsModal').classList.add('show');
        }

        function closeArchivedDetails() {
            document.getElementById('archivedItemDetailsModal').classList.remove('show');
        }

        // View archived table group items
        function viewArchivedTableGroup(tableId, tableName) {
            console.log('viewArchivedTableGroup called:', tableId, tableName);
            console.log('allArchivedItems:', allArchivedItems);
            
            // Find the table group from allArchivedItems
            const itemsInTable = allArchivedItems.filter(item => 
                item.item_table_id != null &&
                parseInt(item.item_table_id) === parseInt(tableId) && 
                item.type === 'item'
            );
            
            console.log('itemsInTable found:', itemsInTable);
            
            if (itemsInTable.length === 0) {
                console.warn('No items found for table:', tableId);
                if (typeof modal !== 'undefined' && modal.error) {
                    modal.error('No items found in this group.');
                } else {
                    alert('No items found in this group.');
                }
                return;
            }
            
            showArchivedGroupModal(tableName, itemsInTable, tableId);
        }

        // View archived standalone group items
        function viewArchivedStandaloneGroup(itemName) {
            // Find items with the same name and no table_id
            const itemsInGroup = allArchivedItems.filter(item => 
                item.name === itemName && 
                (!item.item_table_id || item.item_table_id === null) && 
                item.type === 'item'
            );
            
            if (itemsInGroup.length === 0) {
                if (typeof modal !== 'undefined' && modal.error) {
                    modal.error('No items found in this group.');
                } else {
                    alert('No items found in this group.');
                }
                return;
            }
            
            showArchivedGroupModal(itemName, itemsInGroup, null);
        }

        function showArchivedGroupModal(groupName, items, tableId) {
            console.log('showArchivedGroupModal called:', groupName, items.length, 'items');
            
            const modalEl = document.getElementById('viewArchivedGroupModal');
            const titleEl = document.getElementById('archivedGroupModalTitle');
            const subtitleEl = document.getElementById('archivedGroupModalSubtitle');
            const contentEl = document.getElementById('archivedGroupItemsContent');
            
            if (!modalEl || !titleEl || !subtitleEl || !contentEl) {
                console.error('Modal elements not found:', {
                    modalEl: !!modalEl,
                    titleEl: !!titleEl,
                    subtitleEl: !!subtitleEl,
                    contentEl: !!contentEl
                });
                return;
            }
            
            titleEl.textContent = groupName;
            subtitleEl.textContent = `${items.length} ${items.length === 1 ? 'item' : 'items'} archived`;
            
            // Render items
            let itemsHTML = '';
            if (items.length === 0) {
                itemsHTML = '<div style="text-align: center; padding: 40px; color: #6b7280;">No items found in this group.</div>';
            } else {
                items.forEach(item => {
                    const itemImage = item.image_path ? 
                        `<img src="${item.image_path}" alt="${item.name}" style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px;" />` : 
                        `<div style="width: 60px; height: 60px; background: #f3f4f6; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 24px;">📦</div>`;
                    
                    itemsHTML += `
                        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 16px; transition: all 0.2s; cursor: pointer;" 
                             onclick="viewItemDetails(${item.id}, '${item.type}')"
                             onmouseover="this.style.boxShadow='0 4px 8px rgba(0,0,0,0.1)'; this.style.borderColor='#cbd5e0';"
                             onmouseout="this.style.boxShadow='none'; this.style.borderColor='#e5e7eb';">
                            <div style="flex-shrink: 0;">
                                ${itemImage}
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <h4 style="margin: 0 0 8px 0; color: #1f2937; font-size: 16px; font-weight: 600;">${item.name}</h4>
                                <div style="display: flex; flex-direction: column; gap: 4px; color: #6b7280; font-size: 13px;">
                                    ${item.item_code ? `<div><strong>Item Code:</strong> <span style="font-family: monospace; background: #e0e7ff; padding: 2px 6px; border-radius: 3px; font-size: 12px;">${item.item_code}</span></div>` : ''}
                                    <div><strong>Department:</strong> ${item.department_name || '—'}</div>
                                    <div><strong>Category:</strong> ${item.category || '—'}</div>
                                    ${item.location ? `<div><strong>Location:</strong> ${item.location}</div>` : ''}
                                </div>
                            </div>
                            <div style="flex-shrink: 0; display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                                <div style="color: #6b7280; font-size: 12px;">
                                    <strong>Archived:</strong><br>${new Date(item.archived_at).toLocaleDateString()}
                                </div>
                                <div style="color: #6b7280; font-size: 12px;">
                                    <strong>By:</strong> ${item.archived_by || '—'}
                                </div>
                                <div style="margin-top: 8px; display: flex; gap: 6px;">
                                    <button onclick="event.stopPropagation(); openRestoreConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')" style="padding: 6px 12px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500;">
                                        <img src="image/restore.png" alt="Restore" style="width:12px;height:12px;vertical-align:middle;margin-right:4px;filter:brightness(0) invert(1);" />
                                        Restore
                                    </button>
                                    ${!IS_DEPARTMENT_HEAD ? `
                                    <button onclick="event.stopPropagation(); openDeleteConfirm(${item.id}, '${item.name.replace(/'/g, "\\'")}', '${item.type}')" style="padding: 6px 12px; background: #dc3545; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 500;">
                                        <img src="image/delete.png" alt="Delete" style="width:12px;height:12px;vertical-align:middle;margin-right:4px;filter:brightness(0) invert(1);" />
                                        Delete
                                    </button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    `;
                });
            }
            
            contentEl.innerHTML = itemsHTML;
            
            // Show modal with proper styling
            modalEl.style.display = 'flex';
            modalEl.style.visibility = 'visible';
            modalEl.style.opacity = '1';
            document.body.style.overflow = 'hidden';
            
            console.log('Modal displayed successfully');
        }

        function closeArchivedGroupModal() {
            const modal = document.getElementById('viewArchivedGroupModal');
            if (modal) {
                modal.style.display = 'none';
                modal.style.visibility = 'hidden';
                document.body.style.overflow = 'auto';
            }
        }

        // Close modal when clicking outside - wait for DOM to be ready
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('viewArchivedGroupModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeArchivedGroupModal();
                    }
                });
            }
        });
        
        // Also set up immediately if DOM is already loaded
        const viewModal = document.getElementById('viewArchivedGroupModal');
        if (viewModal) {
            viewModal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeArchivedGroupModal();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', async () => {
            await loadFilterData();
            await loadArchivedItems();
            
            // Add pagination event listeners with a small delay to ensure elements exist
            setTimeout(() => {
                const prevPageBtn = document.getElementById('prevPage');
                const nextPageBtn = document.getElementById('nextPage');
                
                if (prevPageBtn) prevPageBtn.addEventListener('click', goToPrevPage);
                if (nextPageBtn) nextPageBtn.addEventListener('click', goToNextPage);
            }, 100);
        });

        // Listen for archive updates from other pages (real-time update)
        window.addEventListener('storage', (e) => {
            if (e.key === 'archiveUpdated') {
                // Immediately reload archived items when item is archived from another page
                loadArchivedItems();
            }
        });

        // Also listen for same-page updates (when archiving from archive page itself)
        const originalSetItem = localStorage.setItem;
        localStorage.setItem = function(key, value) {
            originalSetItem.apply(this, arguments);
            if (key === 'archiveUpdated') {
                // Trigger storage event for same-page listeners
                window.dispatchEvent(new StorageEvent('storage', {
                    key: key,
                    newValue: value
                }));
            }
        };

        // // Sign out modal functionality
        function signOut() {
            showSignOutModal();
        }

        function showSignOutModal() {
            const modal = document.getElementById('signOutModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }

        function closeSignOutModal() {
            const modal = document.getElementById('signOutModal');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto'; // Restore scrolling
        }

        function confirmSignOut() {
            const confirmBtn = document.getElementById('confirmSignOut');
            
            // Show loading state
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<div class="loading-spinner"></div>Signing out...';
            
            // Simulate sign out process
            setTimeout(() => {
                // Clear any stored session data (if any)
                // In a real application, you would:
                // - Clear authentication tokens
                // - Make API call to invalidate session
                // - Clear local storage/session storage
                
                // Close modal
                closeSignOutModal();
                
                // Redirect to login page or home page
                // In a real application, you would redirect to your actual login page
                window.location.href = 'logout.php'; // Change this to your actual login page
                
                // Fallback: reload page if redirect fails
                setTimeout(() => {
                    window.location.reload();
                }, 100);
            }, 1500);
        }

        // Close modals when clicking outside
        document.getElementById('signOutModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeSignOutModal();
            }
        });

        document.getElementById('archivedItemDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeArchivedDetails();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSignOutModal();
                closeArchivedDetails();
            }
        });

        // Bulk Selection Functions
        function toggleItemSelection(itemId, event) {
            if (event) {
                event.stopPropagation();
            }
            
            if (selectedItems.has(itemId)) {
                selectedItems.delete(itemId);
            } else {
                selectedItems.add(itemId);
            }
            
            updateSelectionUI();
            updateBulkSelectionBar();
        }

        function toggleSelectAllArchived() {
            const selectAllCheckbox = document.getElementById('selectAllArchived');
            const checkboxes = document.querySelectorAll('#archiveTableBody .item-checkbox');
            
            checkboxes.forEach(checkbox => {
                const itemId = parseInt(checkbox.closest('tr').dataset.itemId);
                if (selectAllCheckbox.checked) {
                    selectedItems.add(itemId);
                } else {
                    selectedItems.delete(itemId);
                }
            });
            
            updateSelectionUI();
            updateBulkSelectionBar();
        }

        function toggleSelectAllArchivedCategories() {
            const selectAllCheckbox = document.getElementById('selectAllArchivedCategories');
            const checkboxes = document.querySelectorAll('#categoriesArchiveTableBody .item-checkbox');
            
            checkboxes.forEach(checkbox => {
                const itemId = parseInt(checkbox.closest('tr').dataset.itemId);
                if (selectAllCheckbox.checked) {
                    selectedItems.add(itemId);
                } else {
                    selectedItems.delete(itemId);
                }
            });
            
            updateSelectionUI();
            updateBulkSelectionBar();
        }

        function updateSelectionUI() {
            const rows = document.querySelectorAll('.selectable-row');
            rows.forEach(row => {
                const itemId = parseInt(row.dataset.itemId);
                const checkbox = row.querySelector('.item-checkbox');
                
                if (selectedItems.has(itemId)) {
                    row.classList.add('selected');
                    if (checkbox) checkbox.checked = true;
                } else {
                    row.classList.remove('selected');
                    if (checkbox) checkbox.checked = false;
                }
            });
            
            // Update table group checkboxes
            const tableGroups = document.querySelectorAll('.item-table-group-header');
            tableGroups.forEach(header => {
                const tableId = header.querySelector('.table-group-checkbox')?.dataset.tableId;
                if (tableId) {
                    const itemsInTable = document.querySelectorAll(`tr[data-table-id="${tableId}"].item-in-table`);
                    const checkedItems = Array.from(itemsInTable).filter(row => {
                        const itemId = parseInt(row.dataset.itemId);
                        return selectedItems.has(itemId);
                    });
                    const groupCheckbox = header.querySelector('.table-group-checkbox');
                    if (groupCheckbox) {
                        groupCheckbox.checked = itemsInTable.length > 0 && checkedItems.length === itemsInTable.length;
                        groupCheckbox.indeterminate = checkedItems.length > 0 && checkedItems.length < itemsInTable.length;
                    }
                }
            });
            
            // Update select all checkbox for items
            const selectAllCheckbox = document.getElementById('selectAllArchived');
            if (selectAllCheckbox) {
                const checkboxes = document.querySelectorAll('#archiveTableBody .item-checkbox');
                const checkedCheckboxes = document.querySelectorAll('#archiveTableBody .item-checkbox:checked');
                selectAllCheckbox.checked = checkboxes.length > 0 && checkboxes.length === checkedCheckboxes.length;
            }
            
            // Update select all checkbox for categories
            const selectAllCategoriesCheckbox = document.getElementById('selectAllArchivedCategories');
            if (selectAllCategoriesCheckbox) {
                const checkboxes = document.querySelectorAll('#categoriesArchiveTableBody .item-checkbox');
                const checkedCheckboxes = document.querySelectorAll('#categoriesArchiveTableBody .item-checkbox:checked');
                selectAllCategoriesCheckbox.checked = checkboxes.length > 0 && checkboxes.length === checkedCheckboxes.length;
            }
        }

        function updateBulkSelectionBar() {
            const container = document.getElementById('bulkSelectionContainer');
            const countElement = document.getElementById('selectedCount');
            
            if (selectedItems.size > 0) {
                container.style.display = 'flex';
                countElement.textContent = selectedItems.size;
            } else {
                container.style.display = 'none';
            }
        }

        // Bulk Actions
        async function bulkRestoreSelected() {
            if (selectedItems.size === 0) {
                alert('Please select items to restore.');
                return;
            }
            
            // Open restore modal for bulk operation
            const count = selectedItems.size;
            document.getElementById('restoreMessage').textContent = `Are you sure you want to restore ${count} selected item${count > 1 ? 's' : ''}? This will move ${count > 1 ? 'them' : 'it'} back to the active inventory.`;
            
            const btn = document.getElementById('confirmRestoreBtn');
            btn.onclick = async function() {
                const selectedItemsArray = Array.from(selectedItems);
                let completed = 0;
                let failed = 0;
                
                // Close modal
                closeRestoreModal();
                
                // Show loading state
                const container = document.getElementById('itemsTableContainer');
                const cardsContainer = document.querySelector('.items-grid');
                if (container) container.style.opacity = '0.6';
                if (cardsContainer) cardsContainer.style.opacity = '0.6';
                
                // Process all items without reloading
                for (const itemId of selectedItemsArray) {
                    try {
                        const item = allArchivedItems.find(i => i.id === itemId);
                        if (item) {
                            const result = await restoreItem(itemId, item.name, item.type, true);
                            if (result.success) {
                                completed++;
                            } else {
                                failed++;
                            }
                        }
                    } catch (error) {
                        console.error(`Failed to restore item ${itemId}:`, error);
                        failed++;
                    }
                }
                
                // Clear selection
                selectedItems.clear();
                updateBulkSelectionBar();
                
                // Reload only once at the end using requestAnimationFrame for smooth update
                requestAnimationFrame(async () => {
                    await loadArchivedItems();
                    if (container) container.style.opacity = '1';
                    if (cardsContainer) cardsContainer.style.opacity = '1';
                    
                    if (completed > 0) {
                        modal.success(`Successfully restored ${completed} items.${failed > 0 ? ` ${failed} items failed to restore.` : ''}`);
                    } else if (failed > 0) {
                        modal.error(`Failed to restore ${failed} items.`);
                    }
                });
            };
            
            // Show modal
            document.getElementById('restoreConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        async function bulkDeleteSelected() {
            // Prevent department heads from deleting
            if (IS_DEPARTMENT_HEAD) {
                modal.error('You do not have permission to delete archived items. Only restore is allowed.');
                return;
            }
            
            if (selectedItems.size === 0) {
                alert('Please select items to delete.');
                return;
            }
            
            // Open delete modal for bulk operation
            const count = selectedItems.size;
            document.getElementById('deleteMessage').textContent = `Are you sure you want to permanently delete ${count} selected item${count > 1 ? 's' : ''}? This action cannot be undone.`;
            
            const btn = document.getElementById('confirmDeleteBtn');
            btn.onclick = async function() {
                const selectedItemsArray = Array.from(selectedItems);
                let completed = 0;
                let failed = 0;
                
                // Close modal
                closeDeleteModal();
                
                // Show loading state
                const container = document.getElementById('itemsTableContainer');
                const cardsContainer = document.querySelector('.items-grid');
                if (container) container.style.opacity = '0.6';
                if (cardsContainer) cardsContainer.style.opacity = '0.6';
                
                // Process all items without reloading
                for (const itemId of selectedItemsArray) {
                    try {
                        const item = allArchivedItems.find(i => i.id === itemId);
                        if (item) {
                            const result = await deleteItem(itemId, item.name, item.type, true);
                            if (result.success) {
                                completed++;
                            } else {
                                failed++;
                            }
                        }
                    } catch (error) {
                        console.error(`Failed to delete item ${itemId}:`, error);
                        failed++;
                    }
                }
                
                // Clear selection
                selectedItems.clear();
                updateBulkSelectionBar();
                
                // Reload only once at the end using requestAnimationFrame for smooth update
                requestAnimationFrame(async () => {
                    await loadArchivedItems();
                    if (container) container.style.opacity = '1';
                    if (cardsContainer) cardsContainer.style.opacity = '1';
                    
                    if (completed > 0) {
                        modal.success(`Successfully deleted ${completed} items.${failed > 0 ? ` ${failed} items failed to delete.` : ''}`);
                    } else if (failed > 0) {
                        modal.error(`Failed to delete ${failed} items.`);
                    }
                });
            };
            
            // Show modal
            document.getElementById('deleteConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        // Table Group Functions
        function toggleTableGroupSelection(tableId, event) {
            if (event) {
                event.stopPropagation();
            }
            
            const checkbox = event.target;
            const isChecked = checkbox.checked;
            const itemsInTable = document.querySelectorAll(`tr[data-table-id="${tableId}"].item-in-table`);
            
            itemsInTable.forEach(row => {
                const itemId = parseInt(row.dataset.itemId);
                const itemCheckbox = row.querySelector('.item-checkbox');
                
                if (isChecked) {
                    selectedItems.add(itemId);
                    if (itemCheckbox) itemCheckbox.checked = true;
                    row.classList.add('selected');
                } else {
                    selectedItems.delete(itemId);
                    if (itemCheckbox) itemCheckbox.checked = false;
                    row.classList.remove('selected');
                }
            });
            
            updateBulkSelectionBar();
        }

        async function restoreTableGroup(tableId, tableName) {
            const itemsInTable = filteredItems.filter(item => item.item_table_id === tableId && item.type === 'item');
            
            if (itemsInTable.length === 0) {
                modal.error('No items found in this table group.');
                return;
            }
            
            // Open restore modal for group operation
            const count = itemsInTable.length;
            document.getElementById('restoreMessage').textContent = `Are you sure you want to restore all ${count} items from "${tableName}"? This will move them back to the active inventory.`;
            
            const btn = document.getElementById('confirmRestoreBtn');
            btn.onclick = async function() {
                let completed = 0;
                let failed = 0;
                
                // Close modal
                closeRestoreModal();
                
                // Show loading state
                const container = document.getElementById('itemsTableContainer');
                const cardsContainer = document.querySelector('.items-grid');
                if (container) container.style.opacity = '0.6';
                if (cardsContainer) cardsContainer.style.opacity = '0.6';
                
                // Process all items without reloading
                for (const item of itemsInTable) {
                    try {
                        const result = await restoreItem(item.id, item.name, item.type, true);
                        if (result.success) {
                            completed++;
                        } else {
                            failed++;
                        }
                    } catch (error) {
                        console.error(`Failed to restore item ${item.id}:`, error);
                        failed++;
                    }
                }
                
                // Reload only once at the end using requestAnimationFrame for smooth update
                requestAnimationFrame(async () => {
                    await loadArchivedItems();
                    if (container) container.style.opacity = '1';
                    if (cardsContainer) cardsContainer.style.opacity = '1';
                    
                    if (completed > 0) {
                        modal.success(`Successfully restored ${completed} items from "${tableName}".${failed > 0 ? ` ${failed} items failed to restore.` : ''}`);
                    } else if (failed > 0) {
                        modal.error(`Failed to restore ${failed} items from "${tableName}".`);
                    }
                });
            };
            
            // Show modal
            document.getElementById('restoreConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        async function deleteTableGroup(tableId, tableName) {
            // Prevent department heads from deleting
            if (IS_DEPARTMENT_HEAD) {
                modal.error('You do not have permission to delete archived items. Only restore is allowed.');
                return;
            }
            
            const itemsInTable = filteredItems.filter(item => item.item_table_id === tableId && item.type === 'item');
            
            if (itemsInTable.length === 0) {
                modal.error('No items found in this table group.');
                return;
            }
            
            // Open delete modal for group operation
            const count = itemsInTable.length;
            document.getElementById('deleteMessage').textContent = `Are you sure you want to permanently delete all ${count} items from "${tableName}"? This action cannot be undone.`;
            
            const btn = document.getElementById('confirmDeleteBtn');
            btn.onclick = async function() {
                let completed = 0;
                let failed = 0;
                
                // Close modal
                closeDeleteModal();
                
                // Show loading state
                const container = document.getElementById('itemsTableContainer');
                const cardsContainer = document.querySelector('.items-grid');
                if (container) container.style.opacity = '0.6';
                if (cardsContainer) cardsContainer.style.opacity = '0.6';
                
                // Process all items without reloading
                for (const item of itemsInTable) {
                    try {
                        const result = await deleteItem(item.id, item.name, item.type, true);
                        if (result.success) {
                            completed++;
                        } else {
                            failed++;
                        }
                    } catch (error) {
                        console.error(`Failed to delete item ${item.id}:`, error);
                        failed++;
                    }
                }
                
                // Reload only once at the end using requestAnimationFrame for smooth update
                requestAnimationFrame(async () => {
                    await loadArchivedItems();
                    if (container) container.style.opacity = '1';
                    if (cardsContainer) cardsContainer.style.opacity = '1';
                    
                    if (completed > 0) {
                        modal.success(`Successfully deleted ${completed} items from "${tableName}".${failed > 0 ? ` ${failed} items failed to delete.` : ''}`);
                    } else if (failed > 0) {
                        modal.error(`Failed to delete ${failed} items from "${tableName}".`);
                    }
                });
            };
            
            // Show modal
            document.getElementById('deleteConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        // Standalone Group Functions (for items without item_table_id, grouped by name)
        function toggleStandaloneGroupSelection(groupName, event) {
            if (event) {
                event.stopPropagation();
            }
            
            const checkbox = event.target;
            const isChecked = checkbox.checked;
            const itemsInGroup = document.querySelectorAll(`tr[data-group-name="${groupName}"].item-in-table`);
            
            itemsInGroup.forEach(row => {
                const itemId = parseInt(row.dataset.itemId);
                const itemCheckbox = row.querySelector('.item-checkbox');
                
                if (isChecked) {
                    selectedItems.add(itemId);
                    if (itemCheckbox) itemCheckbox.checked = true;
                    row.classList.add('selected');
                } else {
                    selectedItems.delete(itemId);
                    if (itemCheckbox) itemCheckbox.checked = false;
                    row.classList.remove('selected');
                }
            });
            
            updateBulkSelectionBar();
        }

        async function restoreStandaloneGroup(itemName) {
            const itemsInGroup = filteredItems.filter(item => 
                item.name === itemName && 
                (!item.item_table_id || item.item_table_id === null) && 
                item.type === 'item'
            );
            
            if (itemsInGroup.length === 0) {
                modal.error('No items found in this group.');
                return;
            }
            
            // Open restore modal for group operation
            const count = itemsInGroup.length;
            document.getElementById('restoreMessage').textContent = `Are you sure you want to restore all ${count} items named "${itemName}"? This will move them back to the active inventory.`;
            
            const btn = document.getElementById('confirmRestoreBtn');
            btn.onclick = async function() {
                let completed = 0;
                let failed = 0;
                
                // Close modal
                closeRestoreModal();
                
                // Show loading state
                const container = document.getElementById('itemsTableContainer');
                const cardsContainer = document.querySelector('.items-grid');
                if (container) container.style.opacity = '0.6';
                if (cardsContainer) cardsContainer.style.opacity = '0.6';
                
                // Process all items without reloading
                for (const item of itemsInGroup) {
                    try {
                        const result = await restoreItem(item.id, item.name, item.type, true);
                        if (result.success) {
                            completed++;
                        } else {
                            failed++;
                        }
                    } catch (error) {
                        console.error(`Failed to restore item ${item.id}:`, error);
                        failed++;
                    }
                }
                
                // Reload only once at the end using requestAnimationFrame for smooth update
                requestAnimationFrame(async () => {
                    await loadArchivedItems();
                    if (container) container.style.opacity = '1';
                    if (cardsContainer) cardsContainer.style.opacity = '1';
                    
                    if (completed > 0) {
                        modal.success(`Successfully restored ${completed} items named "${itemName}".${failed > 0 ? ` ${failed} items failed to restore.` : ''}`);
                    } else if (failed > 0) {
                        modal.error(`Failed to restore ${failed} items named "${itemName}".`);
                    }
                });
            };
            
            // Show modal
            document.getElementById('restoreConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        async function deleteStandaloneGroup(itemName) {
            // Prevent department heads from deleting
            if (IS_DEPARTMENT_HEAD) {
                modal.error('You do not have permission to delete archived items. Only restore is allowed.');
                return;
            }
            
            const itemsInGroup = filteredItems.filter(item => 
                item.name === itemName && 
                (!item.item_table_id || item.item_table_id === null) && 
                item.type === 'item'
            );
            
            if (itemsInGroup.length === 0) {
                modal.error('No items found in this group.');
                return;
            }
            
            // Open delete modal for group operation
            const count = itemsInGroup.length;
            document.getElementById('deleteMessage').textContent = `Are you sure you want to permanently delete all ${count} items named "${itemName}"? This action cannot be undone.`;
            
            const btn = document.getElementById('confirmDeleteBtn');
            btn.onclick = async function() {
                let completed = 0;
                let failed = 0;
                
                // Close modal
                closeDeleteModal();
                
                // Show loading state
                const container = document.getElementById('itemsTableContainer');
                const cardsContainer = document.querySelector('.items-grid');
                if (container) container.style.opacity = '0.6';
                if (cardsContainer) cardsContainer.style.opacity = '0.6';
                
                // Process all items without reloading
                for (const item of itemsInGroup) {
                    try {
                        const result = await deleteItem(item.id, item.name, item.type, true);
                        if (result.success) {
                            completed++;
                        } else {
                            failed++;
                        }
                    } catch (error) {
                        console.error(`Failed to delete item ${item.id}:`, error);
                        failed++;
                    }
                }
                
                // Reload only once at the end using requestAnimationFrame for smooth update
                requestAnimationFrame(async () => {
                    await loadArchivedItems();
                    if (container) container.style.opacity = '1';
                    if (cardsContainer) cardsContainer.style.opacity = '1';
                    
                    if (completed > 0) {
                        modal.success(`Successfully deleted ${completed} items named "${itemName}".${failed > 0 ? ` ${failed} items failed to delete.` : ''}`);
                    } else if (failed > 0) {
                        modal.error(`Failed to delete ${failed} items named "${itemName}".`);
                    }
                });
            };
            
            // Show modal
            document.getElementById('deleteConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

    </script>
</body>
</html>
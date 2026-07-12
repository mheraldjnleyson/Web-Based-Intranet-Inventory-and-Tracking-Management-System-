<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
require_once '../db_connect.php';

$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
// Admin role: is_admin = 1 AND role = 'admin'
$isAdminRole = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$isAdminOrSuper = $isSuperAdmin || $isAdminRole;
// Department head: admin but not super admin
$isDepartmentHead = $isAdminOrSuper && !$isSuperAdmin;

if (!$isAdminOrSuper) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Unauthorized</title><link rel="stylesheet" href="Css/dashboard.css"></head><body><div style="padding:24px;">Unauthorized - Only Admins and Super Admins can access this page.</div></body></html>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>OCABIS - Item Requests</title>
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/department.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Keep sidebar spacing uniform with dashboard */
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
        /* Desktop Table Styles - Ensure visibility */
        .table-container {
            width: 100%;
            overflow-x: visible;
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

        /* Pagination Styles */
        #itemRequestsPagination button,
        #qrRequestsPagination button {
            transition: all 0.2s ease;
        }
        
        #itemRequestsPagination button:hover:not(:disabled),
        #qrRequestsPagination button:hover:not(:disabled) {
            background: #f3f4f6 !important;
            border-color: #9ca3af !important;
        }
        
        #itemRequestsPagination button:disabled,
        #qrRequestsPagination button:disabled {
            background: white !important;
        }
        
        @media (max-width: 768px) {
            #itemRequestsPagination,
            #qrRequestsPagination {
                flex-direction: column;
                gap: 10px;
            }
            
            #itemRequestsPagination > div:last-child,
            #qrRequestsPagination > div:last-child {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Mobile Responsive Styles */
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

            /* Hide inline toggle on mobile when sidebar is closed */
            #sidebarToggle,
            .sidebar-toggle-inline {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }
            
            /* Show inline toggle on mobile when sidebar is open (so user can close it) */
            .sidebar.open #sidebarToggle,
            .sidebar.open .sidebar-toggle-inline,
            .sidebar.open button.sidebar-toggle-inline {
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                pointer-events: auto !important;
                position: relative !important;
                z-index: 1001 !important;
                left: auto !important;
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

            /* Main Content Mobile */
            .main-content {
                margin-left: 0 !important;
                padding: 10px !important;
                width: 100% !important;
            }

            /* Top Section */
            .top-section {
                margin-bottom: 15px;
            }

            .breadcrumb {
                font-size: 14px !important;
            }

            /* Summary Stats */
            .summary-stats {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 10px !important;
                margin-bottom: 15px !important;
            }

            .stat-card {
                padding: 12px !important;
            }

            .stat-number {
                font-size: 20px !important;
            }

            .stat-label {
                font-size: 12px !important;
            }

            /* Filters Section */
            .filters-section {
                flex-direction: column !important;
                gap: 10px !important;
                margin-bottom: 15px;
            }

            .filter-select {
                width: 100% !important;
                font-size: 14px;
            }

            .export-btn {
                width: 100% !important;
                margin-left: 0 !important;
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
                min-width: 1000px;
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

            /* Action Menu */
            .action-menu {
                position: fixed !important;
                z-index: 10000 !important;
                min-width: 140px !important;
            }

            .action-menu-btn {
                padding: 6px 10px !important;
                font-size: 14px !important;
            }

            .action-menu-item {
                padding: 8px 12px !important;
                font-size: 13px !important;
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
            .summary-stats {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 8px !important;
            }

            .stat-card {
                padding: 10px !important;
            }

            .stat-number {
                font-size: 18px !important;
            }

            .stat-label {
                font-size: 11px !important;
            }

            .table th,
            .table td {
                padding: 6px !important;
                font-size: 11px !important;
            }
        }
    </style>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= $isSuperAdmin ? 'true' : 'false' ?>" data-user-admin="<?= $isAdminOrSuper ? 'true' : 'false' ?>">
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
            <li class="nav-item"><a href="dashboard.php" class="nav-link" title="Dashboard"><span class="nav-icon"><img src="image/admin.png" alt="Dashboard"></span><span class="nav-label">Dashboard</span></a></li>
            <li class="nav-item"><a href="department.php" class="nav-link" title="<?= ($isDepartmentHead || $isAdminRole || $isSuperAdmin) ? 'Item List' : 'Department' ?>"><span class="nav-icon"><img src="image/department.png" alt="<?= ($isDepartmentHead || $isAdminRole || $isSuperAdmin) ? 'Item List' : 'Department' ?>"></span><span class="nav-label"><?= ($isDepartmentHead || $isAdminRole || $isSuperAdmin) ? 'Item List' : 'Department' ?></span></a></li>
            <li class="nav-item"><a href="location.php" class="nav-link" title="Location"><span class="nav-icon"><img src="image/icons8-building-64.png" alt="Location"></span><span class="nav-label">Location</span></a></li>
            <li class="nav-item"><a href="categories.php" class="nav-link" title="Categories"><span class="nav-icon"><img src="image/icons8-categorize-50.png" alt="Categories"></span><span class="nav-label">Categories</span></a></li>
            <li class="nav-item"><a href="BorrowHistory.php" class="nav-link" title="Borrow History"><span class="nav-icon"><img src="image/book.png" alt="Borrow History"></span><span class="nav-label">Borrow History</span></a></li>
            <li class="nav-item"><a href="archive.php" class="nav-link" title="Archive"><span class="nav-icon"><img src="image/icons8-archive-50.png" alt="Archive"></span><span class="nav-label">Archive</span></a></li>
            <li class="nav-item"><a href="qrscanner.php" class="nav-link" title="QR Code Scanner"><span class="nav-icon"><img src="image/qr.png" alt="QR Scanner"></span><span class="nav-label">QR Code Scanner</span></a></li>
            <li class="nav-item">
                <a href="barcode_scanner.php" class="nav-link" title="Barcode Scanner">
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
                <a href="item_requests.php" class="nav-link active" title="Item Requests">
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
            <a href="logout.php" class="nav-link" title="Sign out"><span class="nav-icon"><img src="image/icons8-sign-out-48.png" alt="Sign Out"></span><span class="nav-label">Sign out</span></a>
        </div>
    </div>

    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>

    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        <div class="top-section">
            <div class="breadcrumb" id="breadcrumb">
                <span class="breadcrumb-item"><img src="image/application.png" alt="Req" style="width: 16px; height: 16px; margin-right: 5px; vertical-align: middle;"> Item Requests</span>
            </div>
        </div>

        <!-- Tabs -->
        <div style="display: flex; gap: 10px; margin-top: 20px; border-bottom: 2px solid #e5e7eb;">
            <button id="tabItemRequests" onclick="switchTab('item_requests')" style="padding: 12px 24px; background: #e53e3e; color: white; border: none; border-bottom: 3px solid #c53030; cursor: pointer; font-weight: bold; border-radius: 4px 4px 0 0;">
                Item Requests
            </button>
            <button id="tabQrRequests" onclick="switchTab('qr_requests')" style="padding: 12px 24px; background: #f8f9fa; color: #666; border: none; cursor: pointer; border-radius: 4px 4px 0 0; position: relative;">
                QR Requests
                <span id="qrRequestBadge" style="display: none; position: absolute; top: 6px; right: 6px; background: #e53e3e; color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 11px; font-weight: bold; align-items: center; justify-content: center; line-height: 1; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">0</span>
            </button>
        </div>

        <!-- Item Requests Section -->
        <div id="itemRequestsSection">
        <div class="summary-stats" style="margin-top:12px;">
            <div class="stat-card" id="pendingCount" onclick="filterRequestsByCard('pending')" style="cursor:pointer;"><div class="stat-number">0</div><div class="stat-label">Pending</div></div>
            <div class="stat-card" id="approvedCount" onclick="filterRequestsByCard('approved')" style="cursor:pointer;"><div class="stat-number">0</div><div class="stat-label">Approved</div></div>
            <div class="stat-card" id="rejectedCount" onclick="filterRequestsByCard('rejected')" style="cursor:pointer;"><div class="stat-number">0</div><div class="stat-label">Rejected</div></div>
            <div class="stat-card" id="fulfilledCount" onclick="filterRequestsByCard('fulfilled')" style="cursor:pointer;"><div class="stat-number">0</div><div class="stat-label">Fulfilled</div></div>
        </div>

        <div class="filters-section">
            <select id="statusFilter" class="filter-select">
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="fulfilled">Fulfilled</option>
            </select>
            <button onclick="exportToPDF()" class="export-btn" style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-left: 10px;">
                <i class="fas fa-file-pdf" style="margin-right: 5px;"></i>
                Export PDF
            </button>
        </div>

        <div class="table-container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
            <table class="table" style="width: 100% !important; border-collapse: collapse !important; background: white !important; border: 1px solid #ddd !important; display: table !important;">
                <thead style="background: #f8f9fa !important; display: table-header-group !important;">
                    <tr style="display: table-row !important;">
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">ID</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Item Name</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Qty</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Category</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Requested By</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Department</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Date Requested</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Date Needed</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Notes</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Status</th>
                        <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Action</th>
                    </tr>
                </thead>
                <tbody id="requestsBody" style="display: table-row-group !important;">
                    <tr style="display: table-row !important;"><td colspan="11" style="text-align:center;padding:24px;color:#666;display:table-cell !important;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination for Item Requests -->
        <div id="itemRequestsPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
            <div id="itemRequestsPageInfo" style="color: #6b7280; font-size: 14px;">Showing 0 to 0 of 0 entries</div>
            <div style="display: flex; gap: 8px;">
                <button id="itemRequestsPrevBtn" onclick="changeItemRequestsPage(-1)" style="padding: 6px 12px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; cursor: pointer; font-size: 14px;" disabled>Previous</button>
                <button id="itemRequestsNextBtn" onclick="changeItemRequestsPage(1)" style="padding: 6px 12px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; cursor: pointer; font-size: 14px;" disabled>Next</button>
            </div>
        </div>
        </div>

        <!-- QR Requests Section -->
        <div id="qrRequestsSection" style="display: none;">
            <div class="summary-stats" style="margin-top:12px;">
                <div class="stat-card" id="qrPendingCount" onclick="filterQrRequestsByCard('pending')" style="cursor:pointer;"><div class="stat-number">0</div><div class="stat-label">Pending</div></div>
                <div class="stat-card" id="qrApprovedCount" onclick="filterQrRequestsByCard('approved')" style="cursor:pointer;"><div class="stat-number">0</div><div class="stat-label">Approved</div></div>
                <div class="stat-card" id="qrRejectedCount" onclick="filterQrRequestsByCard('rejected')" style="cursor:pointer;"><div class="stat-number">0</div><div class="stat-label">Rejected</div></div>
            </div>

            <div class="filters-section">
                <select id="qrStatusFilter" class="filter-select">
                    <option value="pending">Pending</option>
                    <option value="all">All</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>

            <div class="table-container" style="display: block !important; visibility: visible !important; opacity: 1 !important;">
                <table class="table" style="width: 100% !important; border-collapse: collapse !important; background: white !important; border: 1px solid #ddd !important; display: table !important;">
                    <thead style="background: #f8f9fa !important; display: table-header-group !important;">
                        <tr style="display: table-row !important;">
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">ID</th>
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Item Table</th>
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Priority</th>
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Requested By</th>
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Department</th>
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Date Requested</th>
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Notes</th>
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Status</th>
                            <th style="border: 1px solid #ddd !important; padding: 12px !important; text-align: left !important; display: table-cell !important;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="qrRequestsBody" style="display: table-row-group !important;">
                        <tr style="display: table-row !important;"><td colspan="9" style="text-align:center;padding:24px;color:#666;display:table-cell !important;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination for QR Requests -->
            <div id="qrRequestsPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div id="qrRequestsPageInfo" style="color: #6b7280; font-size: 14px;">Showing 0 to 0 of 0 entries</div>
                <div style="display: flex; gap: 8px;">
                    <button id="qrRequestsPrevBtn" onclick="changeQrRequestsPage(-1)" style="padding: 6px 12px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; cursor: pointer; font-size: 14px;" disabled>Previous</button>
                    <button id="qrRequestsNextBtn" onclick="changeQrRequestsPage(1)" style="padding: 6px 12px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; cursor: pointer; font-size: 14px;" disabled>Next</button>
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
function fetchCounts() {
    const statuses = ['pending','approved','rejected','fulfilled'];
    statuses.forEach(s => {
        fetch('crud.php?action=get_item_requests&status=' + s, {
            credentials: 'same-origin'
        })
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                const map = {pending:'pendingCount',approved:'approvedCount',rejected:'rejectedCount',fulfilled:'fulfilledCount'};
                const el = document.getElementById(map[s]);
                if (el) {
                    el.querySelector('.stat-number').textContent = d.requests.length;
                }
            })
            .catch(error => {
                console.error('Count fetch error for', s, ':', error);
            });
    });
}
// Pagination state for item requests
let itemRequestsCurrentPage = 1;
const itemRequestsLimit = 10;

// Pagination state for QR requests
let qrRequestsCurrentPage = 1;
const qrRequestsLimit = 10;

function loadRequests(page = 1) {
    itemRequestsCurrentPage = page;
    const status = document.getElementById('statusFilter').value;
    
    fetch(`crud.php?action=get_item_requests&status=${status}&page=${page}&limit=${itemRequestsLimit}`, {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('requestsBody');
            
            if (!tbody) {
                console.error('tbody element not found!');
                return;
            }
            
            if (!data.success) { 
                tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:24px;color:#e53e3e;">Failed to load: ' + (data.message || 'Unknown error') + '</td></tr>'; 
                updateItemRequestsPagination(1, 1, 0);
                return; 
            }
            if (!data.requests || !data.requests.length) { 
                tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:24px;color:#666;">No requests</td></tr>'; 
                updateItemRequestsPagination(1, 1, 0);
                return; 
            }
            
            // Update pagination
            if (data.pagination) {
                updateItemRequestsPagination(data.pagination.current_page, data.pagination.total_pages, data.pagination.total_count);
            }
            
            // Debug: Log first request to check date_needed
            if (data.requests.length > 0) {
                console.log('Sample request data:', data.requests[0]);
            }
            
            // Create the HTML content with important inline styles to ensure visibility
            const htmlContent = data.requests.map(r=>{
                const dateRequested = r.created_at ? new Date(r.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
                
                // Parse date_needed with proper error handling
                let dateNeeded = '—';
                const dateNeededValue = r.date_needed;
                if (dateNeededValue && 
                    dateNeededValue !== null && 
                    dateNeededValue !== 'null' && 
                    dateNeededValue !== '' && 
                    dateNeededValue !== '0000-00-00' &&
                    typeof dateNeededValue === 'string') {
                    try {
                        // Handle both date-only format (YYYY-MM-DD) and datetime format
                        const dateStr = dateNeededValue.includes('T') ? dateNeededValue : dateNeededValue + 'T00:00:00';
                        const parsedDate = new Date(dateStr);
                        if (!isNaN(parsedDate.getTime()) && parsedDate.getFullYear() > 1970) {
                            dateNeeded = parsedDate.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
                        }
                    } catch (e) {
                        console.error('Error parsing date_needed for request ID', r.id, ':', dateNeededValue, e);
                    }
                }
                return `
                <tr style="background-color: white !important; border: 1px solid #ddd !important; display: table-row !important;">
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.id}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; font-weight: bold !important; display: table-cell !important;">${r.item_name}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.quantity}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.category || ''}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.requested_by}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.department_name}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${dateRequested}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${dateNeeded}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.notes || ''}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;"><span class="status-badge status-${r.status}" style="padding: 4px 8px !important; border-radius: 4px !important; background-color: #fef3c7 !important; color: #92400e !important; display: inline-block !important;">${r.status}</span></td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important; position: relative !important;">
                        ${status==='pending' || status==='approved' ? `
                        <div class="action-menu-container" style="position: relative !important; display: inline-block !important;">
                            <button class="action-menu-btn" onclick="toggleActionMenu(${r.id}, event)" style="padding: 8px 12px !important; background-color: #f8f9fa !important; border: 1px solid #ddd !important; border-radius: 4px !important; cursor: pointer !important; display: inline-block !important; font-size: 16px !important;">
                                ⋮
                            </button>
                            <div class="action-menu" id="actionMenu${r.id}" style="display: none !important; position: absolute !important; top: 100% !important; right: 0 !important; background: white !important; border: 1px solid #ddd !important; border-radius: 4px !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important; z-index: 1000 !important; min-width: 120px !important;">
                                ${status==='pending' ? `
                                <button class="action-menu-item" onclick="updateRequest(${r.id}, 'approved')" style="width: 100% !important; padding: 8px 12px !important; border: none !important; background: none !important; text-align: left !important; cursor: pointer !important; color: #10b981 !important; display: block !important;">✓ Approve</button>
                                <button class="action-menu-item" onclick="updateRequest(${r.id}, 'rejected')" style="width: 100% !important; padding: 8px 12px !important; border: none !important; background: none !important; text-align: left !important; cursor: pointer !important; color: #ef4444 !important; display: block !important;">✗ Reject</button>
                                ` : `
                                <button class="action-menu-item" onclick="updateRequest(${r.id}, 'fulfilled')" style="width: 100% !important; padding: 8px 12px !important; border: none !important; background: none !important; text-align: left !important; cursor: pointer !important; color: #6366f1 !important; display: block !important;">✓ Mark Fulfilled</button>
                                `}
                            </div>
                        </div>
                        ` : `
                        <span style="color:#999;">—</span>
                        `}
                    </td>
                </tr>
                `;
            }).join('');
            
            // Set the actual data
            tbody.innerHTML = htmlContent;
        })
        .catch(error => {
            console.error('Fetch error:', error);
            const tbody = document.getElementById('requestsBody');
            tbody.innerHTML = '<tr><td colspan="11" style="text-align:center;padding:24px;color:#e53e3e;">Network error: ' + error.message + '</td></tr>';
        });
}
function toggleActionMenu(id, event) {
    // Close all other menus first
    document.querySelectorAll('.action-menu').forEach(menu => {
        if (menu.id !== 'actionMenu' + id) {
            menu.style.display = 'none';
        }
    });
    
    // Toggle current menu
    const menu = document.getElementById('actionMenu' + id);
    if (menu) {
        const isVisible = menu.style.display === 'block';
        menu.style.display = isVisible ? 'none' : 'block';
        
        // Position menu properly on mobile
        if (!isVisible && window.innerWidth <= 768) {
            const button = event ? event.target.closest('.action-menu-btn') : document.querySelector(`button[onclick*="toggleActionMenu(${id})"]`);
            if (button) {
                const rect = button.getBoundingClientRect();
                menu.style.position = 'fixed';
                menu.style.top = (rect.bottom + 5) + 'px';
                menu.style.left = (rect.left + (rect.width / 2) - (menu.offsetWidth / 2)) + 'px';
                menu.style.zIndex = '10000';
            }
        }
    }
}

function updateRequest(id, status) {
    // Close the menu
    const menu = document.getElementById('actionMenu' + id);
    if (menu) {
        menu.style.display = 'none';
    }
    // Prevent updates when viewing rejected or fulfilled lists
    const current = document.getElementById('statusFilter') ? document.getElementById('statusFilter').value : '';
    if (current === 'rejected' || current === 'fulfilled') {
        alert('No actions allowed for ' + current + ' requests.');
        return;
    }
    
    const fd = new FormData();
    fd.append('action','update_item_request');
    fd.append('id', id);
    fd.append('status', status);
    fetch('crud.php', { 
        method: 'POST', 
        body: fd,
        credentials: 'same-origin'
    })
        .then(r=>r.json()).then(d=>{ if (d.success) { loadRequests(itemRequestsCurrentPage); fetchCounts(); } });
}
document.getElementById('statusFilter').addEventListener('change', function() {
    itemRequestsCurrentPage = 1;
    loadRequests(1);
});

function filterRequestsByCard(status) {
    try {
        const sel = document.getElementById('statusFilter');
        if (!sel) return;
        sel.value = status;
        itemRequestsCurrentPage = 1;
        loadRequests(1);
    } catch (e) { /* no-op */ }
}
// Close action menus when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.action-menu-container')) {
        document.querySelectorAll('.action-menu').forEach(menu => {
            menu.style.display = 'none';
        });
    }
});

function exportToPDF() {
    const status = document.getElementById('statusFilter').value;
    const params = new URLSearchParams({
        type: 'item_requests',
        status: status
    });
    
    window.open('pdf_export.php?' + params.toString(), '_blank');
}

// QR Requests Functions
document.getElementById('qrStatusFilter').addEventListener('change', function() {
    qrRequestsCurrentPage = 1;
    loadQrRequests(1);
});

// Check URL for tab parameter
const urlParams = new URLSearchParams(window.location.search);
const tab = urlParams.get('tab');
if (tab === 'qr_requests') {
    switchTab('qr_requests');
}

function switchTab(tabName) {
    const itemRequestsSection = document.getElementById('itemRequestsSection');
    const qrRequestsSection = document.getElementById('qrRequestsSection');
    const tabItemRequests = document.getElementById('tabItemRequests');
    const tabQrRequests = document.getElementById('tabQrRequests');
    
    if (tabName === 'qr_requests') {
        itemRequestsSection.style.display = 'none';
        qrRequestsSection.style.display = 'block';
        tabItemRequests.style.background = '#f8f9fa';
        tabItemRequests.style.color = '#666';
        tabItemRequests.style.borderBottom = 'none';
        tabQrRequests.style.background = '#e53e3e';
        tabQrRequests.style.color = 'white';
        tabQrRequests.style.borderBottom = '3px solid #c53030';
        // Set filter to pending by default
        document.getElementById('qrStatusFilter').value = 'pending';
        fetchQrCounts();
        qrRequestsCurrentPage = 1;
        loadQrRequests(1);
    } else {
        itemRequestsSection.style.display = 'block';
        qrRequestsSection.style.display = 'none';
        tabItemRequests.style.background = '#e53e3e';
        tabItemRequests.style.color = 'white';
        tabItemRequests.style.borderBottom = '3px solid #c53030';
        tabQrRequests.style.background = '#f8f9fa';
        tabQrRequests.style.color = '#666';
        tabQrRequests.style.borderBottom = 'none';
        fetchCounts();
        itemRequestsCurrentPage = 1;
        loadRequests(1);
    }
}

function fetchQrCounts() {
    const statuses = ['pending', 'approved', 'rejected'];
    statuses.forEach(s => {
        fetch('crud.php?action=get_qr_requests&status=' + s, {
            credentials: 'same-origin'
        })
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                const map = {pending: 'qrPendingCount', approved: 'qrApprovedCount', rejected: 'qrRejectedCount'};
                const el = document.getElementById(map[s]);
                if (el) {
                    el.querySelector('.stat-number').textContent = d.qr_requests.length;
                }
            })
            .catch(error => {
                console.error('QR Count fetch error for', s, ':', error);
            });
    });
    
    // Update QR request badge
    updateQrRequestBadge();
}

function updateQrRequestBadge() {
    fetch('notification_api.php', {
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.qr_request_count !== undefined) {
                const badge = document.getElementById('qrRequestBadge');
                if (badge) {
                    const count = data.qr_request_count || 0;
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'flex' : 'none';
                }
            }
        })
        .catch(error => {
            console.error('Error fetching QR request badge count:', error);
        });
}

function loadQrRequests(page = 1) {
    qrRequestsCurrentPage = page;
    const status = document.getElementById('qrStatusFilter').value;
    
    fetch(`crud.php?action=get_qr_requests&status=${status}&page=${page}&limit=${qrRequestsLimit}`, {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('qrRequestsBody');
            
            if (!tbody) {
                console.error('qrRequestsBody element not found!');
                return;
            }
            
            if (!data.success) { 
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:#e53e3e;">Failed to load: ' + (data.message || 'Unknown error') + '</td></tr>'; 
                updateQrRequestsPagination(1, 1, 0);
                return; 
            }
            if (!data.qr_requests || !data.qr_requests.length) { 
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:#666;">No QR requests</td></tr>'; 
                updateQrRequestsPagination(1, 1, 0);
                return; 
            }
            
            // Update pagination
            if (data.pagination) {
                updateQrRequestsPagination(data.pagination.current_page, data.pagination.total_pages, data.pagination.total_count);
            }
            
            const htmlContent = data.qr_requests.map(r => {
                const dateRequested = r.created_at ? new Date(r.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
                const priorityColors = {high: '#ef4444', medium: '#f59e0b', low: '#10b981'};
                const priorityLabels = {high: 'High', medium: 'Medium', low: 'Low'};
                
                return `
                <tr style="background-color: white !important; border: 1px solid #ddd !important; display: table-row !important;">
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.id}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; font-weight: bold !important; display: table-cell !important;">${r.table_name || 'N/A'}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">
                        <span style="padding: 4px 8px !important; border-radius: 4px !important; background-color: ${priorityColors[r.priority] || '#6b7280'} !important; color: white !important; display: inline-block !important; font-weight: bold !important;">
                            ${priorityLabels[r.priority] || r.priority}
                        </span>
                    </td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.requested_by}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${r.department_name || 'N/A'}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">${dateRequested}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important; max-width: 200px; word-wrap: break-word;">${r.notes ? r.notes.replace(/\n/g, '<br>') : '—'}</td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important;">
                        <span class="status-badge status-${r.status}" style="padding: 4px 8px !important; border-radius: 4px !important; background-color: ${r.status === 'approved' ? '#d1fae5' : r.status === 'rejected' ? '#fee2e2' : '#fef3c7'} !important; color: ${r.status === 'approved' ? '#065f46' : r.status === 'rejected' ? '#991b1b' : '#92400e'} !important; display: inline-block !important;">
                            ${r.status}
                        </span>
                    </td>
                    <td style="padding: 12px !important; border: 1px solid #ddd !important; display: table-cell !important; position: relative !important;">
                        ${r.status === 'pending' ? `
                        <div class="action-menu-container" style="position: relative !important; display: inline-block !important;">
                            <button class="action-menu-btn" onclick="toggleQrActionMenu(${r.id}, event)" style="padding: 8px 12px !important; background-color: #f8f9fa !important; border: 1px solid #ddd !important; border-radius: 4px !important; cursor: pointer !important; display: inline-block !important; font-size: 16px !important;">
                                ⋮
                            </button>
                            <div class="action-menu" id="qrActionMenu${r.id}" style="display: none !important; position: absolute !important; top: 100% !important; right: 0 !important; background: white !important; border: 1px solid #ddd !important; border-radius: 4px !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important; z-index: 1000 !important; min-width: 120px !important;">
                                <button class="action-menu-item" onclick="updateQrRequest(${r.id}, 'approved')" style="width: 100% !important; padding: 8px 12px !important; border: none !important; background: none !important; text-align: left !important; cursor: pointer !important; color: #10b981 !important; display: block !important;">✓ Approve</button>
                                <button class="action-menu-item" onclick="showRejectModal(${r.id})" style="width: 100% !important; padding: 8px 12px !important; border: none !important; background: none !important; text-align: left !important; cursor: pointer !important; color: #ef4444 !important; display: block !important;">✗ Reject</button>
                            </div>
                        </div>
                        ` : r.status === 'approved' && r.download_count === 0 ? `
                        <a href="crud.php?action=download_qr_code&request_id=${r.id}" style="padding: 6px 12px !important; background-color: #10b981 !important; color: white !important; border: none !important; border-radius: 4px !important; cursor: pointer !important; text-decoration: none !important; display: inline-block !important;">
                            Download QR
                        </a>
                        ` : r.status === 'approved' && r.download_count > 0 ? `
                        <span style="color:#999;">Already Downloaded</span>
                        ` : `
                        <span style="color:#999;">—</span>
                        `}
                    </td>
                </tr>
                `;
            }).join('');
            
            tbody.innerHTML = htmlContent;
        })
        .catch(error => {
            console.error('QR Requests fetch error:', error);
            const tbody = document.getElementById('qrRequestsBody');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:#e53e3e;">Network error: ' + error.message + '</td></tr>';
        });
}

function filterQrRequestsByCard(status) {
    document.getElementById('qrStatusFilter').value = status;
    qrRequestsCurrentPage = 1;
    loadQrRequests(1);
}

// Pagination helper functions
function updateItemRequestsPagination(currentPage, totalPages, totalCount) {
    const prevBtn = document.getElementById('itemRequestsPrevBtn');
    const nextBtn = document.getElementById('itemRequestsNextBtn');
    const pageInfo = document.getElementById('itemRequestsPageInfo');
    
    if (prevBtn && nextBtn && pageInfo) {
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
        
        // Calculate showing range
        const start = totalCount === 0 ? 0 : ((currentPage - 1) * itemRequestsLimit) + 1;
        const end = Math.min(currentPage * itemRequestsLimit, totalCount);
        
        pageInfo.textContent = `Showing ${start} to ${end} of ${totalCount} entries`;
        
        // Update button styles
        if (prevBtn.disabled) {
            prevBtn.style.color = '#9ca3af';
            prevBtn.style.borderColor = '#e5e7eb';
            prevBtn.style.cursor = 'not-allowed';
        } else {
            prevBtn.style.color = '#374151';
            prevBtn.style.borderColor = '#d1d5db';
            prevBtn.style.cursor = 'pointer';
        }
        
        if (nextBtn.disabled) {
            nextBtn.style.color = '#9ca3af';
            nextBtn.style.borderColor = '#e5e7eb';
            nextBtn.style.cursor = 'not-allowed';
        } else {
            nextBtn.style.color = '#374151';
            nextBtn.style.borderColor = '#d1d5db';
            nextBtn.style.cursor = 'pointer';
        }
    }
}

function updateQrRequestsPagination(currentPage, totalPages, totalCount) {
    const prevBtn = document.getElementById('qrRequestsPrevBtn');
    const nextBtn = document.getElementById('qrRequestsNextBtn');
    const pageInfo = document.getElementById('qrRequestsPageInfo');
    
    if (prevBtn && nextBtn && pageInfo) {
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
        
        // Calculate showing range
        const start = totalCount === 0 ? 0 : ((currentPage - 1) * qrRequestsLimit) + 1;
        const end = Math.min(currentPage * qrRequestsLimit, totalCount);
        
        pageInfo.textContent = `Showing ${start} to ${end} of ${totalCount} entries`;
        
        // Update button styles
        if (prevBtn.disabled) {
            prevBtn.style.color = '#9ca3af';
            prevBtn.style.borderColor = '#e5e7eb';
            prevBtn.style.cursor = 'not-allowed';
        } else {
            prevBtn.style.color = '#374151';
            prevBtn.style.borderColor = '#d1d5db';
            prevBtn.style.cursor = 'pointer';
        }
        
        if (nextBtn.disabled) {
            nextBtn.style.color = '#9ca3af';
            nextBtn.style.borderColor = '#e5e7eb';
            nextBtn.style.cursor = 'not-allowed';
        } else {
            nextBtn.style.color = '#374151';
            nextBtn.style.borderColor = '#d1d5db';
            nextBtn.style.cursor = 'pointer';
        }
    }
}

function changeItemRequestsPage(direction) {
    const newPage = itemRequestsCurrentPage + direction;
    if (newPage >= 1) {
        loadRequests(newPage);
    }
}

function changeQrRequestsPage(direction) {
    const newPage = qrRequestsCurrentPage + direction;
    if (newPage >= 1) {
        loadQrRequests(newPage);
    }
}

function toggleQrActionMenu(id, event) {
    event.stopPropagation();
    const menu = document.getElementById('qrActionMenu' + id);
    const allMenus = document.querySelectorAll('.action-menu');
    allMenus.forEach(m => {
        if (m.id !== 'qrActionMenu' + id) m.style.display = 'none';
    });
    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}

async function updateQrRequest(id, status) {
    if (status === 'rejected') {
        showRejectModal(id);
        return;
    }
    
    const confirmed = await modal.confirm(
        `Are you sure you want to ${status} this QR request?`,
        `${status.charAt(0).toUpperCase() + status.slice(1)} QR Request`
    );
    
    if (!confirmed) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_qr_request');
    formData.append('id', id);
    formData.append('status', status);
    
    fetch('crud.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadQrRequests(qrRequestsCurrentPage);
                fetchQrCounts();
                updateQrRequestBadge();
                if (typeof modal !== 'undefined' && modal) {
                    modal.success('QR request ' + status + ' successfully!');
                } else {
                    alert('QR request ' + status + ' successfully!');
                }
            } else {
                if (typeof modal !== 'undefined' && modal) {
                    modal.error(data.message || 'Failed to update request');
                } else {
                    alert('Error: ' + (data.message || 'Failed to update request'));
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (typeof modal !== 'undefined' && modal) {
                modal.error('Network error: ' + error.message);
            } else {
                alert('Network error: ' + error.message);
            }
        });
}

async function showRejectModal(id) {
    const reason = await modal.prompt(
        'Please provide a reason for rejection (optional):',
        'Reject QR Request',
        ''
    );
    
    if (reason === null) return; // User cancelled
    
    const formData = new FormData();
    formData.append('action', 'update_qr_request');
    formData.append('id', id);
    formData.append('status', 'rejected');
    formData.append('rejection_reason', reason || '');
    
    fetch('crud.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadQrRequests(qrRequestsCurrentPage);
                fetchQrCounts();
                updateQrRequestBadge();
                if (typeof modal !== 'undefined' && modal) {
                    modal.success('QR request rejected successfully!');
                } else {
                    alert('QR request rejected successfully!');
                }
            } else {
                if (typeof modal !== 'undefined' && modal) {
                    modal.error(data.message || 'Failed to reject request');
                } else {
                    alert('Error: ' + (data.message || 'Failed to reject request'));
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            if (typeof modal !== 'undefined' && modal) {
                modal.error('Network error: ' + error.message);
            } else {
                alert('Network error: ' + error.message);
            }
        });
}

fetchCounts();
loadRequests(1);
updateQrRequestBadge();

// Update badge periodically
setInterval(updateQrRequestBadge, 30000); // Update every 30 seconds
</script>

<!-- Load modal script -->
<script src="modal.js"></script>

</body>
</html>



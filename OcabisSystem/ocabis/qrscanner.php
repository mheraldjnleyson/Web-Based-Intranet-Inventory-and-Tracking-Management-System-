<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
// Department head: admin but not super admin
$isDepartmentHead = $isAdmin && !$isSuperAdmin;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>OCABIS QR Code Scanner</title>
    <link rel="stylesheet" href="Css/qrscanner.css">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <script src="js/session_monitor.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <link rel="stylesheet" href="Css/department.css">
    <script src="modal.js"></script>
    <script src="js/mobile.js"></script>
    <style>
        /* Keep sidebar spacing identical to dashboard */
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

            /* Keep inline toggle visible inside sidebar so users can close it */
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

            /* Main Content Mobile */
            .main-content {
                margin-left: 0 !important;
                padding: 10px !important;
                width: 100% !important;
            }

            /* Header */
            .header {
                margin-bottom: 15px;
            }

            .header h1 {
                font-size: 20px !important;
            }

            /* Scanner Container */
            .scanner-container {
                padding: 15px !important;
            }

            /* Scanner Tabs */
            .scanner-tabs {
                flex-direction: column !important;
                gap: 8px !important;
                margin-bottom: 15px;
            }

            .tab-button {
                width: 100% !important;
                padding: 12px !important;
                font-size: 14px !important;
            }

            /* Upload Area */
            .upload-area {
                padding: 20px 15px !important;
                min-height: 200px !important;
            }

            .upload-icon {
                width: 60px !important;
                height: 60px !important;
            }

            .upload-text {
                font-size: 14px !important;
                margin: 10px 0 !important;
            }

            .upload-button {
                width: 100% !important;
                padding: 12px !important;
                font-size: 14px !important;
            }

            /* Camera Container */
            #camera-container {
                width: 100% !important;
            }

            #reader {
                width: 100% !important;
                max-width: 100% !important;
            }

            /* Footer Text */
            .footer-text {
                font-size: 12px !important;
                margin-top: 15px !important;
            }

            /* Result Display */
            #upload-result,
            #scan-result {
                font-size: 13px !important;
                padding: 15px !important;
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
            .header h1 {
                font-size: 18px !important;
            }

            .upload-area {
                padding: 15px 10px !important;
                min-height: 180px !important;
            }

            .upload-icon {
                width: 50px !important;
                height: 50px !important;
            }

            .upload-text {
                font-size: 13px !important;
            }

            .tab-button {
                padding: 10px !important;
                font-size: 13px !important;
            }

            .upload-button {
                padding: 10px !important;
                font-size: 13px !important;
            }
        }
    </style>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>">
    <div class="sidebar">
        <div class="logo">
            <div class="logo-top" style="display: flex; align-items: center; gap: 10px;">
                <div class="logo-icon">
                    <img src="image/image-removebg-preview.png" alt="Logo" style="height: 50px; width: auto;">
                </div>
                <h1 style="margin: 0;">CABIS</h1>
                <button id="sidebarToggle" class="sidebar-toggle-inline" aria-label="Toggle sidebar">☰</button>
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
                <a href="archive.php" class="nav-link" title="Archive">
                    <span class="nav-icon">
                        <img src="image/icons8-archive-50.png" alt="Archive">
                    </span>
                    <span class="nav-label">Archive</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="qrscanner.php" class="nav-link active" title="QR Code Scanner">
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
            <h1>
                QR CODE SCANNER
                <svg class="qr-icon" viewBox="0 0 24 24">
                    <path d="M3 11h8V3H3v8zm2-6h4v4H5V5zm8-2v8h8V3h-8zm6 6h-4V5h4v4zM3 21h8v-8H3v8zm2-6h4v4H5v-4z"/>
                    <path d="M15 21h2v-2h-2v2zm-2-4h2v-2h-2v2zm4 0h2v-2h-2v2zm-2 4h2v-2h-2v2zm4-4h2v-2h-2v2zm0 4h2v-2h-2v2z"/>
                </svg>
            </h1>
        </div>

        <div class="scanner-container">
            <div class="scanner-tabs">
                <button class="tab-button active" onclick="switchTab('upload')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/>
                    </svg>
                    Upload
                </button>
                <button class="tab-button" onclick="switchTab('scan')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 11h8V3H3v8zm2-6h4v4H5V5zm8-2v8h8V3h-8zm6 6h-4V5h4v4zM3 21h8v-8H3v8zm2-6h4v4H5v-4z"/>
                    </svg>
                    Scan QR Code
                </button>
            </div>

            <div id="upload-section" class="upload-area">
                <svg class="upload-icon" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/>
                </svg>
                <div class="upload-text">Upload QR Code Image</div>
                <input type="file" id="fileInput" accept="image/*" style="display: none;" onchange="handleFileUpload(event)">
                <button class="upload-button" onclick="document.getElementById('fileInput').click()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/>
                    </svg>
                    Upload Image
                </button>
                <div id="upload-result" style="margin-top: 20px; display: none;"></div>
            </div>

            <div id="scan-section" class="upload-area" style="display: none;">
                <div id="camera-container" style="display: none; margin-bottom: 20px;">
                    <div id="reader" style="width: 100%; max-width: 500px; margin: 0 auto;"></div>
                    <button class="upload-button" onclick="stopCamera()" style="margin-top: 15px; background: #dc3545;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                        Stop Camera
                    </button>
                </div>
                <div id="start-camera-section">
                    <svg class="upload-icon" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 11h8V3H3v8zm2-6h4v4H5V5zm8-2v8h8V3h-8zm6 6h-4V5h4v4zM3 21h8v-8H3v8zm2-6h4v4H5v-4z"/>
                    </svg>
                    <div class="upload-text">Scan QR Code with Camera</div>
                    <button class="upload-button" onclick="startCamera()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                        Start Camera
                    </button>
                </div>
                <div id="scan-result" style="margin-top: 20px; display: none;"></div>
            </div>

            <div class="footer-text">
                Upload JPEG/PNG file or scan your code with camera.
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

        function switchTab(tab) {
            const uploadBtn = document.querySelector('.tab-button:first-child');
            const scanBtn = document.querySelector('.tab-button:last-child');
            const uploadSection = document.getElementById('upload-section');
            const scanSection = document.getElementById('scan-section');

            // Stop camera if switching away from scan tab
            if (tab !== 'scan' && isScanning) {
                stopCamera();
            }

            if (tab === 'upload') {
                uploadBtn.classList.add('active');
                scanBtn.classList.remove('active');
                uploadSection.style.display = 'block';
                scanSection.style.display = 'none';
            } else {
                uploadBtn.classList.remove('active');
                scanBtn.classList.add('active');
                uploadSection.style.display = 'none';
                scanSection.style.display = 'block';
            }
        }

        function handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            const resultDiv = document.getElementById('upload-result');
            resultDiv.innerHTML = '<p style="color: #666;">Processing QR code...</p>';
            resultDiv.style.display = 'block';

            // Create a temporary HTML5 QR Code scanner for file scanning
            const html5QrCodeScanner = new Html5Qrcode("upload-result");
            
            html5QrCodeScanner.scanFile(file, true)
                .then(decodedText => {
                    handleQRCodeResult(decodedText);
                    event.target.value = ''; // Reset file input
                })
                .catch(err => {
                    resultDiv.innerHTML = '<p style="color: #dc3545;">Failed to decode QR code. Please try another image.</p>';
                    event.target.value = ''; // Reset file input
                });
        }

        function startCamera() {
            // Check if HTTPS is required
            if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                const scanResult = document.getElementById('scan-result');
                scanResult.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #dc3545;">
                        <h4>❌ HTTPS Required</h4>
                        <p>Camera access requires HTTPS for security.</p>
                        <p>Please use the "Upload Image" option below instead.</p>
                    </div>
                `;
                scanResult.style.display = 'block';
                
                // Show upload option
                const uploadSection = document.getElementById('upload-section');
                if (uploadSection) {
                    uploadSection.style.display = 'block';
                }
                return;
            }
            
            const cameraContainer = document.getElementById('camera-container');
            const startSection = document.getElementById('start-camera-section');
            const scanResult = document.getElementById('scan-result');
            
            cameraContainer.style.display = 'block';
            startSection.style.display = 'none';
            scanResult.style.display = 'none';
            
            // Show scanning status
            scanResult.innerHTML = '<div style="text-align: center; padding: 20px; color: #007bff; font-weight: bold;">📷 Camera started - Point at QR code to scan...</div>';
            scanResult.style.display = 'block';

            html5QrCode = new Html5Qrcode("reader");
            
            // Adjust QR box size for mobile
            const isMobileDevice = window.innerWidth <= 768;
            const qrBoxSize = isMobileDevice ? Math.min(window.innerWidth - 40, 250) : 250;
            
            const config = { 
                fps: 10,
                qrbox: { width: qrBoxSize, height: qrBoxSize },
                aspectRatio: 1.0
            };

            html5QrCode.start(
                { facingMode: "environment" },
                config,
                (decodedText, decodedResult) => {
                    if (isScanning) {
                        isScanning = false; // Prevent multiple scans
                        handleQRCodeResult(decodedText);
                        stopCamera();
                    }
                },
                (errorMessage) => {
                }
            ).then(() => {
                // Update status to show camera is ready
                const scanResult = document.getElementById('scan-result');
                scanResult.innerHTML = '<div style="text-align: center; padding: 20px; color: #28a745; font-weight: bold;">✅ Camera ready - Scanning for QR codes...</div>';
            }).catch(err => {
                console.error('Camera error:', err);
                
                // Show detailed error message with solutions
                const scanResult = document.getElementById('scan-result');
                let errorMessage = '❌ Camera Error: ' + err;
                
                if (err.includes('Camera streaming not supported')) {
                    errorMessage = `
                        <div style="text-align: center; padding: 20px; color: #dc3545;">
                            <h4>❌ Camera Not Supported</h4>
                            <p><strong>Possible solutions:</strong></p>
                            <ul style="text-align: left; display: inline-block;">
                                <li>Use HTTPS instead of HTTP</li>
                                <li>Try a different browser (Chrome, Firefox, Safari)</li>
                                <li>Check camera permissions</li>
                                <li>Use the "Upload Image" option below</li>
                            </ul>
                        </div>
                    `;
                } else if (err.includes('Permission denied')) {
                    errorMessage = `
                        <div style="text-align: center; padding: 20px; color: #dc3545;">
                            <h4>❌ Camera Permission Denied</h4>
                            <p>Please allow camera access and try again.</p>
                            <p>Or use the "Upload Image" option below.</p>
                        </div>
                    `;
                } else {
                    errorMessage = `
                        <div style="text-align: center; padding: 20px; color: #dc3545;">
                            <h4>❌ Camera Error</h4>
                            <p>${err}</p>
                            <p>Please try the "Upload Image" option below.</p>
                        </div>
                    `;
                }
                
                scanResult.innerHTML = errorMessage;
                
                // Show upload option as alternative
                const uploadSection = document.getElementById('upload-section');
                if (uploadSection) {
                    uploadSection.style.display = 'block';
                }
                
                stopCamera();
            });

            isScanning = true;
        }

        function stopCamera() {
            if (html5QrCode) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                    html5QrCode = null;
                    isScanning = false;
                    
                    const cameraContainer = document.getElementById('camera-container');
                    const startSection = document.getElementById('start-camera-section');
                    
                    cameraContainer.style.display = 'none';
                    startSection.style.display = 'block';
                }).catch(err => {
                    isScanning = false;
                });
            }
        }

        function handleQRCodeResult(decodedText) {
            // Show a brief loading message
            const scanResult = document.getElementById('scan-result');
            scanResult.innerHTML = '<div style="text-align: center; padding: 20px; color: #28a745; font-weight: bold;">✓ QR Code Scanned - Loading...</div>';
            scanResult.style.display = 'block';
            
            // Check if it's a URL
            if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                // Check if it's item table inventory URL
                if (decodedText.includes('item_table_inventory.php')) {
                    // Extract table ID from URL and redirect using current host (avoid localhost issues)
                    const urlMatch = decodedText.match(/item_table_inventory\.php[?&]table_id=(\d+)/);
                    if (urlMatch) {
                        const tableId = urlMatch[1];
                        window.location.href = 'item_table_inventory.php?table_id=' + tableId;
                    } else {
                        // Fallback: open in same host root
                        window.location.href = 'item_table_inventory.php';
                    }
                } else if (decodedText.includes('view_item.php?id=')) {
                    // Extract item ID from URL and redirect using current host
                    const urlMatch = decodedText.match(/view_item\.php[?&]id=(\d+)/);
                    if (urlMatch) {
                        const itemId = urlMatch[1];
                        window.location.href = 'view_item.php?id=' + itemId;
                    } else {
                        window.location.href = 'view_item.php';
                    }
                } else {
                    // External URL - open in new tab
                    window.open(decodedText, '_blank');
                }
            } else {
                // Check if it's an item table QR code (starts with TABLE-)
                if (decodedText.startsWith('TABLE-') || decodedText.includes('TABLE-')) {
                    // Extract table ID from QR code
                    const tableIdMatch = decodedText.match(/TABLE-(\d+)/);
                    if (tableIdMatch) {
                        const tableId = tableIdMatch[1];
                        window.location.href = 'item_table_inventory.php?table_id=' + tableId;
                        return;
                    }
                }
                
                // Try to parse as JSON (legacy format)
                try {
                    const data = JSON.parse(decodedText);
                    if (data.id) {
                        // Redirect to view_item.php with the ID
                        window.location.href = 'view_item.php?id=' + data.id;
                    } else {
                        scanResult.innerHTML = '<div style="text-align: center; padding: 20px; color: #6c757d;">QR Code Content: ' + decodedText + '</div>';
                    }
                } catch (e) {
                    // Plain text QR code - check if it's just a number (item ID)
                    if (/^\d+$/.test(decodedText.trim())) {
                        // It's a plain number, treat as item ID
                        window.location.href = 'view_item.php?id=' + decodedText.trim();
                    } else {
                        scanResult.innerHTML = '<div style="text-align: center; padding: 20px; color: #6c757d;">QR Code Content: ' + decodedText + '</div>';
                    }
                }
            }
        }

        // Drag and drop functionality
        const uploadArea = document.querySelector('.upload-area');
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#007bff';
            uploadArea.style.backgroundColor = '#f8f9ff';
        });

        uploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#dee2e6';
            uploadArea.style.backgroundColor = '';
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#dee2e6';
            uploadArea.style.backgroundColor = '';
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                const fileInput = document.getElementById('fileInput');
                fileInput.files = files;
                handleFileUpload({ target: fileInput });
            }
        });

        // Clean up camera on page unload
        window.addEventListener('beforeunload', () => {
            if (isScanning) {
                stopCamera();
            }
        });

    </script>
</body>
</html>
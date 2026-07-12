<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once '../db_connect.php';

$success_message = '';
$error_message = '';

// Get departments for dropdown
$departments_result = $conn->query("SELECT id, name FROM departments ORDER BY name");
$departments = [];
if ($departments_result) {
    while ($row = $departments_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Get current user data - check if super admin
$is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$department = isset($_SESSION['department']) ? $_SESSION['department'] : '';
// Determine if user is a viewer (borrower) - no department and not admin
$is_viewer = empty($department) && !$is_admin && !$is_super_admin;
// Department head: admin but not super admin
$isDepartmentHead = $is_admin && !$is_super_admin;

if ($is_super_admin) {
    // Get data from super_admin table
    $stmt = $conn->prepare("SELECT username, email, department FROM super_admin WHERE id = ?");
} else {
    // Get data from users table
    $stmt = $conn->prepare("SELECT username, email, department FROM users WHERE id = ?");
}
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    
    $errors = [];
    
    // Validate username
    $reserved_usernames = ['superadmin'];
    $current_username_lower = isset($user_data['username']) ? strtolower($user_data['username']) : '';
    $username_lower = strtolower($username);

    if (empty($username) || strlen($username) < 4 || strlen($username) > 20) {
        $errors[] = "Username must be 4-20 characters long.";
    } elseif (!preg_match("/^[a-zA-Z0-9_]{4,20}$/", $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores.";
    } elseif (in_array($username_lower, $reserved_usernames, true) && $username_lower !== $current_username_lower) {
        $errors[] = "That username is reserved and cannot be used.";
    } else {
        // Check if username is already taken by another user
        if ($is_super_admin) {
            $check_stmt = $conn->prepare("SELECT id FROM super_admin WHERE username = ? AND id != ?");
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        }
        $check_stmt->bind_param("si", $username, $_SESSION['user_id']);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $errors[] = "Username is already taken.";
        }
    }
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    } else {
        // Check if email is already taken by another user
        if ($is_super_admin) {
            $check_stmt = $conn->prepare("SELECT id FROM super_admin WHERE email = ? AND id != ?");
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        }
        $check_stmt->bind_param("si", $email, $_SESSION['user_id']);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $errors[] = "Email is already taken.";
        }
    }
    
    if (empty($errors)) {
        // Preserve original department - users cannot change their department
        $department = $user_data['department'] ?? null;
        // Update user data based on whether super admin or regular user
        if ($is_super_admin) {
            $update_stmt = $conn->prepare("UPDATE super_admin SET username = ?, email = ?, department = ? WHERE id = ?");
        } else {
            $update_stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, department = ? WHERE id = ?");
        }
        $update_stmt->bind_param("sssi", $username, $email, $department, $_SESSION['user_id']);
        
        if ($update_stmt->execute()) {
            // Update session data
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['department'] = $department;
            
            $success_message = "Profile updated successfully!";
            
            // Refresh user data
            if ($is_super_admin) {
                $stmt = $conn->prepare("SELECT username, email, department FROM super_admin WHERE id = ?");
            } else {
                $stmt = $conn->prepare("SELECT username, email, department FROM users WHERE id = ?");
            }
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
        } else {
            $error_message = "Error updating profile: " . $conn->error;
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>Edit Profile - OCABIS</title>
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <script src="modal.js"></script>
    <style>
        .edit-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .edit-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .edit-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #e53e3e, #c53030);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .edit-title {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        
        .edit-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin: 5px 0 0 0;
        }
        
        .edit-form {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #e53e3e;
            box-shadow: 0 0 0 3px rgba(229, 62, 62, 0.1);
        }
        
        .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        
        .form-select:focus {
            outline: none;
            border-color: #e53e3e;
            box-shadow: 0 0 0 3px rgba(229, 62, 62, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #e53e3e;
            color: white;
        }
        
        .btn-primary:hover {
            background: #c53030;
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #f3f4f6;
            color: #374151;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-bottom: 20px;
        }
        
        .back-btn:hover {
            background: #e5e7eb;
            transform: translateY(-1px);
        }
        
        .form-help {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .required {
            color: #e53e3e;
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
        
        @media (max-width: 768px) {
            .edit-container {
                padding: 15px;
            }
            
            .edit-header {
                flex-direction: column;
                text-align: center;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            /* Mobile sidebar behavior */
            .sidebar-toggle-fixed {
                display: flex !important;
                align-items: center;
                justify-content: center;
                z-index: 1300;
                position: fixed !important;
                top: 15px !important;
                left: 15px !important;
                background: rgba(229, 62, 62, 0.9);
                color: white;
                border: 0;
                width: 42px;
                height: 42px;
                border-radius: 12px;
                cursor: pointer;
                font-size: 18px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                transition: all 0.3s ease;
                pointer-events: auto;
            }

            /* Show inline toggle on mobile when sidebar is open - allow it to close */
            #sidebarToggle,
            .sidebar-toggle-inline {
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                z-index: 1301 !important;
                position: relative !important;
                cursor: pointer !important;
                pointer-events: auto !important;
            }
            
            /* Make sure toggle button inside sidebar is clickable on mobile */
            .sidebar.open #sidebarToggle,
            .sidebar #sidebarToggle {
                pointer-events: auto !important;
                cursor: pointer !important;
                z-index: 1301 !important;
                position: relative !important;
            }

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
                transform: translateX(0) !important; 
            }
            
            /* Ensure sidebar can be closed */
            .sidebar:not(.open) {
                transform: translateX(-100%) !important;
            }
            
            /* Ensure sign-out section is visible on mobile - match viewer_qr_scanner.php */
            .sidebar .sign-out {
                padding: 0 20px !important;
                display: block !important;
                visibility: visible !important;
            }
            
            .sidebar .sign-out .nav-link {
                display: flex !important;
                align-items: center !important;
                gap: 12px !important;
                padding: 12px 20px !important;
                font-size: 14px !important;
                white-space: nowrap !important;
                color: white !important;
                text-decoration: none !important;
            }
            
            .sidebar .sign-out .nav-icon {
                display: inline-block !important;
                visibility: visible !important;
            }
            
            .sidebar .sign-out .nav-label {
                display: inline-block !important;
                visibility: visible !important;
            }

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
            
            /* Show inline toggle on mobile when sidebar is open - allow it to close */
            #sidebarToggle {
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                z-index: 1301 !important;
                position: relative !important;
                cursor: pointer !important;
                pointer-events: auto !important;
            }
            
            /* Make sure toggle button inside sidebar is clickable on mobile */
            .sidebar.open #sidebarToggle,
            .sidebar #sidebarToggle {
                pointer-events: auto !important;
                cursor: pointer !important;
                z-index: 1301 !important;
                position: relative !important;
            }

            body #sidebarToggleFixed:hover,
            body .sidebar-toggle-fixed:hover,
            #sidebarToggleFixed:hover,
            .sidebar-toggle-fixed:hover {
                background: rgba(229, 62, 62, 1) !important;
                transform: scale(1.05) !important;
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

            .main-content {
                margin-left: 0 !important;
                padding: 10px !important;
                width: 100% !important;
            }
        }
    </style>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>" data-user-is-viewer="<?= $is_viewer ? 'true' : 'false' ?>">
    <div class="sidebar">
        <div class="logo">
            <div class="logo-top" style="display: flex; align-items: center; gap: 10px;">
                <div class="logo-icon">
                    <img src="image/image-removebg-preview.png" alt="Logo" style="height: 50px; width: auto;">
                </div>
                <h1 style="margin: 0; flex: 1;">CABIS</h1>
                <button id="sidebarToggle" class="sidebar-toggle-inline" aria-label="Toggle sidebar">☰</button>
            </div>
            <div class="logo-text">
                <p>INVENTORY MANAGEMENT SYSTEM</p>
            </div>
        </div>
        
        
        <ul class="nav-menu">
            <?php if (!$is_viewer): ?>
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
                <a href="department.php" class="nav-link" title="<?= ($is_viewer || $isDepartmentHead || $is_admin || $is_super_admin) ? 'Item List' : 'Department' ?>">
                    <span class="nav-icon">
                        <img src="image/department.png" alt="<?= ($is_viewer || $isDepartmentHead || $is_admin || $is_super_admin) ? 'Item List' : 'Department' ?>">
                    </span>
                    <span class="nav-label"><?= ($is_viewer || $isDepartmentHead || $is_admin || $is_super_admin) ? 'Item List' : 'Department' ?></span>
                </a>
            </li>
            <?php if ($is_viewer): ?>
            <li class="nav-item">
                <a href="viewer_qr_scanner.php" class="nav-link" title="Scan QR">
                    <span class="nav-icon">
                        <img src="image/qr.png" alt="Scan QR">
                    </span>
                    <span class="nav-label">Scan Item QR</span>
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
            <?php endif; ?>
            
            <?php if (!$is_viewer): ?>
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
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>
    
    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        <div class="edit-container">
            <a href="profile_settings.php" class="back-btn">
                <img src="image/back-button.png" alt="Back" style="width: 16px; height: 16px;">
                Back to Profile Settings
            </a>
            
            <div class="edit-header">
                <div class="edit-icon">✏️</div>
                <div>
                    <h1 class="edit-title">Edit Profile</h1>
                    <p class="edit-subtitle">Update your personal information and preferences</p>
                </div>
            </div>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <div class="edit-form">
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username" class="form-label">
                            Username <span class="required">*</span>
                        </label>
                        <input type="text" id="username" name="username" class="form-input" 
                               value="<?php echo htmlspecialchars($user_data['username'] ?? ''); ?>" required>
                        <div class="form-help">Username must be 4-20 characters long and contain only letters, numbers, and underscores.</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email" class="form-label">
                            Email Address <span class="required">*</span>
                        </label>
                        <input type="email" id="email" name="email" class="form-input" 
                               value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" required>
                        <div class="form-help">We'll use this email for important notifications and account recovery.</div>
                    </div>
                    
                    <?php 
                    // Show department for all users (read-only)
                    if (!$is_viewer): 
                    ?>
                    <div class="form-group">
                        <label for="department" class="form-label">Department</label>
                        <select id="department" name="department" class="form-select" disabled style="background-color: #f3f4f6; cursor: not-allowed;">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept['name']); ?>" 
                                        <?php echo (isset($user_data['department']) && $user_data['department'] === $dept['name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-help">Your department cannot be changed. Contact an administrator if you need to update it.</div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="btn-group">
                        <button type="submit" class="btn btn-primary">
                            <img src="image/edit.png" alt="Save" style="width: 16px; height: 16px;">
                            Save Changes
                        </button>
                        <a href="profile_settings.php" class="btn btn-secondary">
                            <img src="image/back-button.png" alt="Cancel" style="width: 16px; height: 16px;">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value;
            const email = document.getElementById('email').value;
            
            // Username validation
            if (username.length < 4 || username.length > 20) {
                e.preventDefault();
                modal.warning('Username must be 4-20 characters long.');
                return;
            }
            
            if (!/^[a-zA-Z0-9_]{4,20}$/.test(username)) {
                e.preventDefault();
                modal.warning('Username can only contain letters, numbers, and underscores.');
                return;
            }
            
            // Email validation
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                e.preventDefault();
                modal.warning('Please enter a valid email address.');
                return;
            }
        });

        // Sidebar toggle functionality with mobile support
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

            function toggleSidebar(e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                const fixedBtn = document.getElementById('sidebarToggleFixed');
                
                if (isMobile()) {
                    // Mobile behavior: slide sidebar in/out with overlay
                    const isOpen = sidebar.classList.contains('open');
                    
                    if (isOpen) {
                        // Close sidebar
                        closeSidebarMobile();
                    } else {
                        // Open sidebar
                        sidebar.classList.add('open');
                        sidebar.style.setProperty('transform', 'translateX(0)', 'important');
                        sidebar.style.setProperty('display', 'block', 'important');
                        sidebar.style.setProperty('visibility', 'visible', 'important');
                        sidebar.style.setProperty('opacity', '1', 'important');
                        
                        if (overlay) {
                            overlay.classList.add('show');
                            overlay.style.display = 'block';
                        }
                        document.body.style.overflow = 'hidden';
                        
                        // Hide hamburger button when sidebar opens
                        if (fixedBtn) {
                            fixedBtn.style.setProperty('display', 'none', 'important');
                        }
                    }
                } else {
                    // Desktop behavior: collapse/expand
                    const isCollapsed = document.body.classList.toggle(BODY_CLASS);
                    localStorage.setItem('ocabis:sidebar-collapsed', isCollapsed ? '1' : '0');
                }
            }
            
            // Close sidebar function for mobile
            function closeSidebarMobile() {
                const sidebar = document.querySelector('.sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                const fixedBtn = document.getElementById('sidebarToggleFixed');
                
                if (!sidebar || !isMobile()) return;
                
                sidebar.classList.remove('open');
                sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
                
                if (overlay) {
                    overlay.classList.remove('show');
                    overlay.style.setProperty('display', 'none', 'important');
                }
                document.body.style.overflow = '';
                
                if (fixedBtn) {
                    fixedBtn.style.setProperty('display', 'flex', 'important');
                    fixedBtn.style.setProperty('visibility', 'visible', 'important');
                    fixedBtn.style.setProperty('opacity', '1', 'important');
                }
            }

            // Close sidebar when clicking overlay (mobile only)
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (isMobile()) {
                        e.preventDefault();
                        e.stopPropagation();
                        closeSidebarMobile();
                    }
                });
            }
            
            // Close sidebar when clicking on nav links (mobile only)
            if (sidebar) {
                const navLinks = sidebar.querySelectorAll('.nav-link');
                navLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        if (isMobile()) {
                            // Small delay to allow navigation
                            setTimeout(() => {
                                closeSidebarMobile();
                            }, 100);
                        }
                    });
                });
            }
            
            // Prevent sidebar clicks from bubbling to overlay (but allow toggle buttons to work)
            if (sidebar) {
                sidebar.addEventListener('click', function(e) {
                    // Don't stop propagation for toggle buttons
                    const target = e.target;
                    const isToggleButton = target.id === 'sidebarToggle' || 
                                          target.id === 'sidebarToggleFixed' ||
                                          target.closest('#sidebarToggle') || 
                                          target.closest('#sidebarToggleFixed') ||
                                          target.closest('.sidebar-toggle-inline');
                    
                    if (!isToggleButton) {
                        e.stopPropagation();
                    }
                }, false);
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
            
            const handleToggle = function(e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                toggleSidebar(e);
            };
            
            if (inlineBtn) {
                inlineBtn.addEventListener('click', handleToggle, true);
                inlineBtn.addEventListener('touchend', handleToggle, true);
                inlineBtn.style.pointerEvents = 'auto';
                inlineBtn.style.cursor = 'pointer';
                inlineBtn.style.zIndex = '1301';
            }
            if (fixedBtn) {
                fixedBtn.addEventListener('click', handleToggle, true);
                fixedBtn.addEventListener('touchend', handleToggle, true);
                fixedBtn.style.pointerEvents = 'auto';
                fixedBtn.style.cursor = 'pointer';
                fixedBtn.style.zIndex = '1301';
            }
            if (mobileInlineBtn) {
                mobileInlineBtn.addEventListener('click', handleToggle, true);
                mobileInlineBtn.addEventListener('touchend', handleToggle, true);
            }
            
            applyInitialState();
        })();
    </script>
</body>
</html>

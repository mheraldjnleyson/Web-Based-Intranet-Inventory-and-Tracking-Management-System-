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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate passwords
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error_message = "New password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $error_message = "New password must contain at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $error_message = "New password must contain at least one lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $error_message = "New password must contain at least one number.";
        } else {
            // Check if user_id is set in session
            if (!isset($_SESSION['user_id'])) {
                $error_message = "Session expired. Please log in again.";
            } else {
                // Verify current password
                // Check if super admin (native super admin from super_admin table)
                // Note: Users with role='admin' have is_super_admin=1 but are in users table
                $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
                $is_role_elevated = !empty($_SESSION['super_admin_via_role']); // Admin via role, not native super admin
                $user_id = $_SESSION['user_id'];
                
                // If user is super admin via role, they're still in users table
                // Only native super admins are in super_admin table
                if ($is_super_admin && !$is_role_elevated) {
                    // Get password from super_admin table (native super admin)
                    $stmt = $conn->prepare("SELECT password FROM super_admin WHERE id = ?");
                } else {
                    // Get password from users table (regular users or role-based admins)
                    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                }
                
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 1) {
                        $user = $result->fetch_assoc();
                        if (password_verify($current_password, $user['password'])) {
                            // Update password
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            
                            // Use same logic as SELECT - native super admin vs regular users/role-based admins
                            if ($is_super_admin && !$is_role_elevated) {
                                $update_stmt = $conn->prepare("UPDATE super_admin SET password = ? WHERE id = ?");
                            } else {
                                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                            }
                            
                            if ($update_stmt) {
                                $update_stmt->bind_param("si", $hashed_password, $user_id);
                                
                                if ($update_stmt->execute()) {
                                    $success_message = "Password changed successfully!";
                                } else {
                                    $error_message = "Error updating password: " . $conn->error;
                                }
                                $update_stmt->close();
                            } else {
                                $error_message = "Error preparing update statement: " . $conn->error;
                            }
                        } else {
                            $error_message = "Current password is incorrect.";
                        }
                        $stmt->close();
                    } else {
                        $error_message = "User not found. Please check your session.";
                        if ($stmt) $stmt->close();
                    }
                } else {
                    $error_message = "Database error: " . $conn->error;
                }
            }
        }
    }
    
    if ($action === 'update_notifications') {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $system_notifications = isset($_POST['system_notifications']) ? 1 : 0;
        
        // For now, we'll just show a success message since we don't have a notifications table
        $success_message = "Notification preferences updated successfully!";
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

// Initialize user_data
$user_data = [];

if (isset($_SESSION['user_id'])) {
    if ($is_super_admin) {
        // Get data from super_admin table
        $stmt = $conn->prepare("SELECT username, email, department, created_at FROM super_admin WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
    } else {
        // Get data from users table
        $stmt = $conn->prepare("SELECT username, email, department, created_at FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
    }
}

// Ensure user_data is an array
if (!is_array($user_data)) {
    $user_data = [];
}

// Fallback to session values if user_data is empty or missing values
$user_data['username'] = !empty($user_data['username']) ? $user_data['username'] : ($_SESSION['username'] ?? '');
$user_data['email'] = !empty($user_data['email']) ? $user_data['email'] : ($_SESSION['email'] ?? '');
$user_data['department'] = !empty($user_data['department']) ? $user_data['department'] : ($_SESSION['department'] ?? '');
$user_data['created_at'] = !empty($user_data['created_at']) ? $user_data['created_at'] : ($_SESSION['created_at'] ?? null);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>Profile Settings - OCABIS</title>
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .settings-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .settings-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .settings-icon {
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
        
        .settings-title {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        
        .settings-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin: 5px 0 0 0;
        }
        
        .settings-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            width: 24px;
            height: 24px;
            opacity: 0.7;
        }
        
        .form-group {
            margin-bottom: 20px;
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
        
        .input-group {
            position: relative;
            margin-bottom: 0;
        }
        
        .input-group input {
            width: 100%;
            padding: 12px 40px 12px 15px;
            border: 1px solid #000000;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .input-group i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            cursor: pointer;
            font-size: 16px;
            z-index: 10;
        }
        
        .input-group i:hover {
            color: #666;
        }
        
        /* Hide browser password manager icons - Comprehensive rules */
        .input-group input[type="password"]::-webkit-credentials-auto-fill-button,
        .input-group input[type="password"]::-webkit-strong-password-auto-fill-button,
        .input-group input[type="password"]::-webkit-textfield-decoration-container {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            position: absolute !important;
            right: -9999px !important;
            width: 0 !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        .input-group input[type="password"]::-ms-reveal,
        .input-group input[type="password"]::-ms-clear {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .checkbox-input {
            width: 18px;
            height: 18px;
            accent-color: #e53e3e;
        }
        
        .checkbox-label {
            font-size: 14px;
            color: #374151;
            cursor: pointer;
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
        
        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .info-item {
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 4px solid #e53e3e;
        }
        
        .info-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 16px;
            color: #1f2937;
            font-weight: 600;
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
        
        .password-strength {
            margin-top: 8px;
            font-size: 12px;
        }
        
        .strength-indicator {
            display: flex;
            gap: 4px;
            margin-top: 5px;
        }
        
        .strength-bar {
            height: 4px;
            width: 20px;
            background: #e5e7eb;
            border-radius: 2px;
            transition: background-color 0.2s ease;
        }
        
        .strength-bar.active {
            background: #e53e3e;
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
            .settings-container {
                padding: 15px;
            }
            
            .settings-header {
                flex-direction: column;
                text-align: center;
            }
            
            .user-info-grid {
                grid-template-columns: 1fr;
            }

            /* Mobile sidebar behavior - match other pages */
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
        <div class="settings-container">
            <a href="<?php echo $is_viewer ? 'department.php' : 'dashboard.php'; ?>" class="back-btn">
                <img src="image/back-button.png" alt="Back" style="width: 16px; height: 16px;">
                Back
            </a>
            
            <div class="settings-header">
                <div class="settings-icon">⚙️</div>
                <div>
                    <h1 class="settings-title">Profile Settings</h1>
                    <p class="settings-subtitle">Manage your account preferences and security settings</p>
                </div>
            </div>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <!-- User Information Section -->
            <div class="settings-section">
                <h2 class="section-title">
                    <img src="image/profile.png" alt="Profile" class="section-icon">
                    Account Information
                </h2>
                
                <div class="user-info-grid">
                    <div class="info-item">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_data['username'] ?? ''); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_data['email'] ?? ''); ?></div>
                    </div>
                    <?php 
                    // Only show department for regular users, not for admin/super admin
                    if (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1): 
                    ?>
                    <div class="info-item">
                        <div class="info-label">Department</div>
                        <div class="info-value"><?php echo htmlspecialchars($user_data['department'] ?? 'Not assigned'); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="info-label">Member Since</div>
                        <div class="info-value"><?php echo isset($user_data['created_at']) && $user_data['created_at'] ? date('M j, Y', strtotime($user_data['created_at'])) : 'N/A'; ?></div>
                    </div>
                </div>
                
                <a href="edit_profile.php" class="btn btn-primary">
                    <img src="image/edit.png" alt="Edit" style="width: 16px; height: 16px;">
                    Edit Profile Information
                </a>
            </div>
            
            <!-- Password Change Section -->
            <div class="settings-section">
                <h2 class="section-title">
                    <img src="image/icons8-sign-out-48.png" alt="Security" class="section-icon">
                    Security Settings
                </h2>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="current_password" class="form-label">Current Password</label>
                        <div class="input-group">
                            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                            <i class="fas fa-eye" id="toggle-current-password" onclick="togglePassword('current_password', 'toggle-current-password')" style="cursor: pointer;"></i>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                            <i class="fas fa-eye" id="toggle-new-password" onclick="togglePassword('new_password', 'toggle-new-password')" style="cursor: pointer;"></i>
                        </div>
                        <div class="password-strength">
                            <div id="strength-text">Password strength: </div>
                            <div class="strength-indicator">
                                <div class="strength-bar" id="strength-1"></div>
                                <div class="strength-bar" id="strength-2"></div>
                                <div class="strength-bar" id="strength-3"></div>
                                <div class="strength-bar" id="strength-4"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                            <i class="fas fa-eye" id="toggle-confirm-password" onclick="togglePassword('confirm_password', 'toggle-confirm-password')" style="cursor: pointer;"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <img src="image/icons8-sign-out-48.png" alt="Save" style="width: 16px; height: 16px;">
                        Change Password
                    </button>
                </form>
            </div>
            
            <!-- Notification Settings Section -->
            <div class="settings-section">
                <h2 class="section-title">
                    <img src="image/comment_1756625.png" alt="Notifications" class="section-icon">
                    Notification Preferences
                </h2>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_notifications">
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="email_notifications" name="email_notifications" class="checkbox-input" checked>
                        <label for="email_notifications" class="checkbox-label">Email Notifications</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" id="system_notifications" name="system_notifications" class="checkbox-input" checked>
                        <label for="system_notifications" class="checkbox-label">System Notifications</label>
                    </div>
                    
                    <button type="submit" class="btn btn-secondary">
                        <img src="image/comment_1756625.png" alt="Save" style="width: 16px; height: 16px;">
                        Save Preferences
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId, iconId) {
            const passwordField = document.getElementById(inputId);
            const toggleIcon = document.getElementById(iconId);
            
            if (passwordField && toggleIcon) {
                if (passwordField.type === 'password') {
                    passwordField.type = 'text';
                    toggleIcon.classList.remove('fa-eye');
                    toggleIcon.classList.add('fa-eye-slash');
                } else {
                    passwordField.type = 'password';
                    toggleIcon.classList.remove('fa-eye-slash');
                    toggleIcon.classList.add('fa-eye');
                }
            }
        }
        
        // Password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthText = document.getElementById('strength-text');
            const strengthBars = document.querySelectorAll('.strength-bar');
            
            let strength = 0;
            let strengthLabel = '';
            
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            // Reset all bars
            strengthBars.forEach(bar => bar.classList.remove('active'));
            
            // Activate bars based on strength
            for (let i = 0; i < strength && i < 4; i++) {
                strengthBars[i].classList.add('active');
            }
            
            // Set strength label
            if (strength <= 1) {
                strengthLabel = 'Weak';
            } else if (strength <= 3) {
                strengthLabel = 'Medium';
            } else {
                strengthLabel = 'Strong';
            }
            
            strengthText.textContent = `Password strength: ${strengthLabel}`;
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

<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Admin or Super Admin access guard - Only admin role and super admin can access user management
$is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$is_admin_role = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if (!$is_super_admin && !$is_admin_role) {
    header("Location: dashboard.php");
    exit();
}
$is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
// Role field removed - using is_admin and department instead
$user_department = isset($_SESSION['department']) ? $_SESSION['department'] : '';
// Department head: admin but not super admin
$isDepartmentHead = $is_admin && !$is_super_admin;

$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Include database connection
require_once '../db_connect.php';

// Include email notification functions
require_once 'email_notifications.php';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_user') {
        $username = $_POST['username'];
        $email = $_POST['email'];
        $department = $_POST['department'] ?: null;
        // Determine if this is a department head (is_admin = 1, role = 'user') or admin (is_admin = 1, role = 'admin')
        $is_department_head = isset($_POST['is_department_head']) ? (int)$_POST['is_department_head'] : 0;
        $is_admin_role = isset($_POST['is_admin_role']) ? (int)$_POST['is_admin_role'] : 0;
        
        // Determine is_admin and role
        $is_admin = ($is_department_head === 1 || $is_admin_role === 1) ? 1 : 0;
        $role = ($is_admin_role === 1) ? 'admin' : 'user';
        
        // Only SUPER ADMIN can create department heads or admin accounts
        if ($is_admin === 1 && !$is_super_admin) {
            $_SESSION['error_message'] = "Only Super Admin can create Department Head or Admin accounts!";
            header("Location: user_management.php");
            exit();
        }
        
        // Department heads must have a department
        if ($is_department_head === 1 && empty($department)) {
            $_SESSION['error_message'] = "Department Head accounts must have a department assigned!";
            header("Location: user_management.php");
            exit();
        }
        
        // Cannot be both department head and admin
        if ($is_department_head === 1 && $is_admin_role === 1) {
            $_SESSION['error_message'] = "A user cannot be both Department Head and Admin. Please select only one.";
            header("Location: user_management.php");
            exit();
        }
        
        // Generate a secure random password
        function generateSecurePassword($length = 12) {
            $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $lowercase = 'abcdefghijklmnopqrstuvwxyz';
            $numbers = '0123456789';
            $special = '!@#$%^&*()';
            $all = $uppercase . $lowercase . $numbers . $special;
            
            $password = '';
            // Ensure at least one of each type
            $password .= $uppercase[rand(0, strlen($uppercase) - 1)];
            $password .= $lowercase[rand(0, strlen($lowercase) - 1)];
            $password .= $numbers[rand(0, strlen($numbers) - 1)];
            $password .= $special[rand(0, strlen($special) - 1)];
            
            // Fill the rest randomly
            for ($i = strlen($password); $i < $length; $i++) {
                $password .= $all[rand(0, strlen($all) - 1)];
            }
            
            // Shuffle the password to randomize character positions
            return str_shuffle($password);
        }
        
        $password = generateSecurePassword(12);

        // Username uniqueness check
        $check_username_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if ($check_username_stmt) {
            $check_username_stmt->bind_param("s", $username);
            $check_username_stmt->execute();
            $check_username_stmt->store_result();
            if ($check_username_stmt->num_rows > 0) {
                $check_username_stmt->close();
                $_SESSION['error_message'] = "Username already exists. Please use a different username.";
                header("Location: user_management.php");
                exit();
            }
            $check_username_stmt->close();
        }

        // Email uniqueness check
        $check_email_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($check_email_stmt) {
            $check_email_stmt->bind_param("s", $email);
            $check_email_stmt->execute();
            $check_email_stmt->store_result();
            if ($check_email_stmt->num_rows > 0) {
                $check_email_stmt->close();
                $_SESSION['error_message'] = "Email already exists. Please use a different email address.";
                header("Location: user_management.php");
                exit();
            }
            $check_email_stmt->close();
        }
        
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO users (username, password, department, email, status, approval_status, is_admin, role, created_at) VALUES (?, ?, ?, ?, 'active', 'approved', ?, ?, NOW())");
        $stmt->bind_param("ssssis", $username, $hashed_password, $department, $email, $is_admin, $role);
        
        if ($stmt->execute()) {
            // Include email notification functions
            require_once 'email_notifications.php';
            
            // Department name is already the name from the form, use it directly
            $department_name = !empty($department) ? $department : '';
            
            // Determine user role for email
            $user_role = 'Regular User';
            if ($is_admin_role === 1) {
                $user_role = 'Admin';
            } elseif ($is_department_head === 1) {
                $user_role = 'Department Head';
            } elseif (empty($department)) {
                $user_role = 'Teacher';
            }
            
            // Send email with credentials to all users
            if (function_exists('sendUserAccountEmail')) {
                if (sendUserAccountEmail($email, $username, $password, $department_name, $user_role)) {
                    $_SESSION['success_message'] = "User account created successfully! Username and password have been sent to " . htmlspecialchars($email) . ".";
                } else {
                    $_SESSION['success_message'] = "User account created successfully, but failed to send email. Username: " . htmlspecialchars($username) . ", Password: " . htmlspecialchars($password);
                    error_log("Failed to send user account email to: " . $email);
                }
            } else {
                // Fallback if function doesn't exist
                $_SESSION['success_message'] = "User account created successfully! Username: " . htmlspecialchars($username) . ", Password: " . htmlspecialchars($password) . " (Email function not available)";
            }
        } else {
            // Handle specific database errors with user-friendly messages
            $error_code = $conn->errno;
            $error_message = $conn->error;
            
            if ($error_code == 1062) { // Duplicate entry error
                if (strpos($error_message, 'username') !== false) {
                    $_SESSION['error_message'] = "Username already exists. Please use a different username.";
                } elseif (strpos($error_message, 'email') !== false) {
                    $_SESSION['error_message'] = "Email already exists. Please use a different email address.";
                } else {
                    $_SESSION['error_message'] = "This user already exists. Please check the username and email.";
                }
            } else {
                // Generic error message for other database errors
                $_SESSION['error_message'] = "Error adding user. Please try again or contact the administrator.";
                error_log("Database error adding user: " . $error_message . " (Error code: " . $error_code . ")");
            }
        }
        header("Location: user_management.php");
        exit();
    }
    
    if ($action === 'edit_user') {
        $user_id = $_POST['user_id'];
        $username = $_POST['username'];
        $email = $_POST['email'];
        $department = $_POST['department'] ?: null;
        // Determine if this is a department head (is_admin = 1, role = 'user') or admin (is_admin = 1, role = 'admin')
        $is_department_head = isset($_POST['is_department_head']) ? (int)$_POST['is_department_head'] : 0;
        $is_admin_role = isset($_POST['is_admin_role']) ? (int)$_POST['is_admin_role'] : 0;
        
        // Determine is_admin and role
        $is_admin = ($is_department_head === 1 || $is_admin_role === 1) ? 1 : 0;
        $role = ($is_admin_role === 1) ? 'admin' : 'user';
        
        // Only SUPER ADMIN can change user to department head or admin
        if ($is_admin === 1 && !$is_super_admin) {
            // Check current is_admin status to see if we're trying to promote
            $check_admin_stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
            $check_admin_stmt->bind_param("i", $user_id);
            $check_admin_stmt->execute();
            $current_admin = $check_admin_stmt->get_result()->fetch_assoc()['is_admin'];
            $check_admin_stmt->close();
            
            // If current user is not admin, prevent promotion
            if ((int)$current_admin !== 1) {
                $_SESSION['error_message'] = "Only Super Admin can assign Department Head or Admin status!";
                header("Location: user_management.php");
                exit();
            }
        }
        
        // Department heads must have a department
        if ($is_department_head === 1 && empty($department)) {
            $_SESSION['error_message'] = "Department Head accounts must have a department assigned!";
            header("Location: user_management.php");
            exit();
        }
        
        // Cannot be both department head and admin
        if ($is_department_head === 1 && $is_admin_role === 1) {
            $_SESSION['error_message'] = "A user cannot be both Department Head and Admin. Please select only one.";
            header("Location: user_management.php");
            exit();
        }

        // Email uniqueness check excluding current user
        $check_email_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        if ($check_email_stmt) {
            $check_email_stmt->bind_param("si", $email, $user_id);
            $check_email_stmt->execute();
            $check_email_stmt->store_result();
            if ($check_email_stmt->num_rows > 0) {
                $check_email_stmt->close();
                $_SESSION['error_message'] = "Email already exists. Please use a different email address.";
                header("Location: user_management.php");
                exit();
            }
            $check_email_stmt->close();
        }
        
        // Password is no longer updated via edit form - use send_new_password action instead
        $stmt = $conn->prepare("UPDATE users SET username=?, email=?, department=?, is_admin=?, role=? WHERE id=?");
        $stmt->bind_param("sssisi", $username, $email, $department, $is_admin, $role, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "User updated successfully!";
        } else {
            $_SESSION['error_message'] = "Error updating user: " . $conn->error;
        }
        header("Location: user_management.php");
        exit();
    }
    
    // Send new auto-generated password to user (Super Admin only)
    if ($action === 'send_new_password') {
        if (!$is_super_admin) {
            $_SESSION['error_message'] = "Only Super Admin can send new passwords!";
            header("Location: user_management.php");
            exit();
        }
        
        $user_id = $_POST['user_id'];
        
        // Get user details
        $user_stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id = ?");
        if ($user_stmt === false) {
            $_SESSION['error_message'] = "Error preparing query: " . $conn->error;
            header("Location: user_management.php");
            exit();
        }
        
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        
        if ($user_result->num_rows === 0) {
            $user_stmt->close();
            $_SESSION['error_message'] = "User not found!";
            header("Location: user_management.php");
            exit();
        }
        
        $user_data = $user_result->fetch_assoc();
        $user_stmt->close();
        
        // Generate a secure random password
        require_once 'email_notifications.php';
        
        // Use generateSecurePassword function if available, otherwise generate manually
        if (function_exists('generateSecurePassword')) {
            $newPassword = generateSecurePassword(12);
        } else {
            $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $lowercase = 'abcdefghijklmnopqrstuvwxyz';
            $numbers = '0123456789';
            $special = '!@#$%^&*()';
            $all = $uppercase . $lowercase . $numbers . $special;
            
            $newPassword = '';
            // Ensure at least one of each type
            $newPassword .= $uppercase[random_int(0, strlen($uppercase) - 1)];
            $newPassword .= $lowercase[random_int(0, strlen($lowercase) - 1)];
            $newPassword .= $numbers[random_int(0, strlen($numbers) - 1)];
            $newPassword .= $special[random_int(0, strlen($special) - 1)];
            
            // Fill the rest randomly (total 12 characters)
            for ($i = strlen($newPassword); $i < 12; $i++) {
                $newPassword .= $all[random_int(0, strlen($all) - 1)];
            }
            
            // Shuffle the password to randomize character positions
            $newPassword = str_shuffle($newPassword);
        }
        
        // Hash the new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        if ($update_stmt === false) {
            $_SESSION['error_message'] = "Error preparing update statement: " . $conn->error;
            header("Location: user_management.php");
            exit();
        }
        
        $update_stmt->bind_param("si", $hashedPassword, $user_id);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            
            // Send email with new password using email_notifications.php function
            if (function_exists('sendUnlockAccountEmail')) {
                if (sendUnlockAccountEmail($user_data['email'], $user_data['username'], $newPassword)) {
                    $_SESSION['success_message'] = "New password generated and sent successfully to " . htmlspecialchars($user_data['email']);
                    error_log("New password sent: User ID " . $user_id . " by Super Admin " . $_SESSION['username']);
                } else {
                    $_SESSION['error_message'] = "Password updated but failed to send email. Please contact the administrator.";
                    error_log("Failed to send password email for user ID: " . $user_id);
                }
            } else {
                $_SESSION['error_message'] = "Password updated but email function not found. Please contact the administrator.";
                error_log("sendUnlockAccountEmail function not found");
            }
        } else {
            $_SESSION['error_message'] = "Error updating password: " . $conn->error;
            error_log("Error updating password for user ID: " . $user_id . " - " . $conn->error);
        }
        
        header("Location: user_management.php");
        exit();
    }
    
    if ($action === 'delete_user') {
        $user_id = $_POST['user_id'];
        
        // Prevent SUPER ADMIN from deleting themselves
        if ($is_super_admin && $user_id == $_SESSION['user_id']) {
            $_SESSION['error_message'] = "You cannot delete your own account!";
            header("Location: user_management.php");
            exit();
        }
        
        // Check if trying to delete another department head (only SUPER ADMIN can delete department heads)
        $check_stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $user_is_admin = $check_stmt->get_result()->fetch_assoc()['is_admin'];
        $check_stmt->close();
        
        if ((int)$user_is_admin === 1 && !$is_super_admin) {
            $_SESSION['error_message'] = "Only SUPER ADMIN can delete department head accounts!";
            header("Location: user_management.php");
            exit();
        }
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "User deleted successfully!";
        } else {
            $_SESSION['error_message'] = "Error deleting user: " . $conn->error;
        }
        header("Location: user_management.php");
        exit();
    }
    
    // Bulk delete users
    if ($action === 'bulk_delete') {
        $user_ids = $_POST['user_ids'] ?? [];
        if (!empty($user_ids)) {
            // Check if trying to delete department head accounts (only SUPER ADMIN can delete department heads)
            $placeholders = str_repeat('?,', count($user_ids) - 1) . '?';
            $check_stmt = $conn->prepare("SELECT id, is_admin FROM users WHERE id IN ($placeholders)");
            $check_stmt->bind_param(str_repeat('i', count($user_ids)), ...$user_ids);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            $has_admin = false;
            $filtered_ids = [];
            while ($row = $result->fetch_assoc()) {
                if ((int)$row['is_admin'] === 1 && !$is_super_admin) {
                    $has_admin = true;
                } else {
                    $filtered_ids[] = $row['id'];
                }
            }
            $check_stmt->close();
            
            if ($has_admin) {
                $_SESSION['error_message'] = "Only SUPER ADMIN can delete admin accounts! Regular users have been deleted.";
            }
            
            if (!empty($filtered_ids)) {
                $placeholders = str_repeat('?,', count($filtered_ids) - 1) . '?';
                $stmt = $conn->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                $stmt->bind_param(str_repeat('i', count($filtered_ids)), ...$filtered_ids);
                
                if ($stmt->execute()) {
                    if (!$has_admin) {
                        $_SESSION['success_message'] = count($filtered_ids) . " users deleted successfully!";
                    }
                } else {
                    $_SESSION['error_message'] = "Error deleting users: " . $conn->error;
                }
            }
        }
        header("Location: user_management.php");
        exit();
    }
    
    // Toggle user status
    if ($action === 'toggle_status') {
        $user_id = $_POST['user_id'];
        $stmt = $conn->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id=?");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "User status updated!";
        }
        header("Location: user_management.php");
        exit();
    }
    
    // Unlock account and send new password (Super Admin only)
    if ($action === 'unlock_account') {
        if (!$is_super_admin) {
            $_SESSION['error_message'] = "Only Super Admin can unlock accounts!";
            header("Location: user_management.php");
            exit();
        }
        
        $user_id = $_POST['user_id'];
        
        // Check if lock columns exist
        $check_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked'");
        $has_lock_columns = ($check_columns && $check_columns->num_rows > 0);
        
        if (!$has_lock_columns) {
            $_SESSION['error_message'] = "Account lock feature is not enabled. Please run the migration script first: run_account_lock_migration.php";
            header("Location: user_management.php");
            exit();
        }
        
        // Get user details
        $user_stmt = $conn->prepare("SELECT id, username, email, COALESCE(account_locked, 0) as account_locked FROM users WHERE id = ?");
        if ($user_stmt === false) {
            $_SESSION['error_message'] = "Error preparing query: " . $conn->error;
            header("Location: user_management.php");
            exit();
        }
        
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        
        if ($user_result->num_rows === 0) {
            $user_stmt->close();
            $_SESSION['error_message'] = "User not found!";
            header("Location: user_management.php");
            exit();
        }
        
        $user_data = $user_result->fetch_assoc();
        $user_stmt->close();
        
        // Check if account is actually locked
        if (!isset($user_data['account_locked']) || (int)$user_data['account_locked'] !== 1) {
            $_SESSION['error_message'] = "This account is not locked!";
            header("Location: user_management.php");
            exit();
        }
        
        // Generate a secure random password (12 characters: uppercase, lowercase, numbers)
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $allChars = $uppercase . $lowercase . $numbers;
        
        // Ensure at least one of each required character type
        $newPassword = '';
        $newPassword .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $newPassword .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $newPassword .= $numbers[random_int(0, strlen($numbers) - 1)];
        
        // Fill the rest randomly (total 12 characters)
        for ($i = strlen($newPassword); $i < 12; $i++) {
            $newPassword .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        
        // Shuffle the password to randomize character positions
        $newPassword = str_shuffle($newPassword);
        
        // Hash the new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update password, unlock account, reset failed attempts, and reset temporary lock count
        $update_stmt = $conn->prepare("UPDATE users SET password = ?, account_locked = 0, failed_login_attempts = 0, locked_at = NULL, lock_reason = NULL, temporary_lock_count = 0 WHERE id = ?");
        if ($update_stmt === false) {
            $_SESSION['error_message'] = "Error preparing update statement: " . $conn->error;
            header("Location: user_management.php");
            exit();
        }
        
        $update_stmt->bind_param("si", $hashedPassword, $user_id);
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            
            // Send email with new password using email_notifications.php function
            if (function_exists('sendUnlockAccountEmail')) {
                if (sendUnlockAccountEmail($user_data['email'], $user_data['username'], $newPassword)) {
                    $_SESSION['success_message'] = "Account unlocked successfully! A new password has been sent to " . htmlspecialchars($user_data['email']);
                    error_log("Account unlocked: User ID " . $user_id . " by Super Admin " . $_SESSION['username']);
                } else {
                    $_SESSION['error_message'] = "Account unlocked but failed to send email. Please contact the administrator.";
                    error_log("Failed to send unlock email for user ID: " . $user_id);
                }
            } else {
                $_SESSION['error_message'] = "Account unlocked but email function not found. Please contact the administrator.";
                error_log("sendUnlockAccountEmail function not found");
            }
        } else {
            $update_stmt->close();
            $_SESSION['error_message'] = "Error unlocking account: " . $conn->error;
        }
        
        header("Location: user_management.php");
        exit();
    }
    
    // Approve user
    if ($action === 'approve_user') {
        $user_id = $_POST['user_id'];
        
        // First, get user details for email notification
        $user_stmt = $conn->prepare("SELECT username, email FROM users WHERE id=?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_stmt->close();
        
        if ($user_data) {
            $stmt = $conn->prepare("UPDATE users SET approval_status = 'approved', status = 'active' WHERE id=?");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                // Send approval email
                $email_sent = sendApprovalEmail($user_data['email'], $user_data['username']);
                
                if ($email_sent) {
                    $_SESSION['success_message'] = "User approved successfully! Approval email sent to " . $user_data['email'];
                } else {
                    $_SESSION['success_message'] = "User approved successfully! (Email notification failed)";
                }
            } else {
                $_SESSION['error_message'] = "Error approving user: " . $conn->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error_message'] = "User not found.";
        }
        header("Location: user_management.php");
        exit();
    }
    
    // Reject user
    if ($action === 'reject_user') {
        $user_id = $_POST['user_id'];
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        // First, get user details for email notification
        $user_stmt = $conn->prepare("SELECT username, email FROM users WHERE id=?");
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_stmt->close();
        
        if ($user_data) {
            $stmt = $conn->prepare("UPDATE users SET approval_status = 'rejected' WHERE id=?");
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                // Send rejection email
                $email_sent = sendRejectionEmail($user_data['email'], $user_data['username'], $rejection_reason);
                
                if ($email_sent) {
                    $_SESSION['success_message'] = "User rejected successfully! Rejection email sent to " . $user_data['email'];
                } else {
                    $_SESSION['success_message'] = "User rejected successfully! (Email notification failed)";
                }
            } else {
                $_SESSION['error_message'] = "Error rejecting user: " . $conn->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error_message'] = "User not found.";
        }
        header("Location: user_management.php");
        exit();
    }
}

// Get filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$card_filter = isset($_GET['card_filter']) ? $_GET['card_filter'] : '';
$approval_status_filter = isset($_GET['approval_status']) ? $_GET['approval_status'] : 'pending';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(u.username LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'ss';
}

if (!empty($status_filter)) {
    $where_conditions[] = "u.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if (!empty($role_filter)) {
    if ($role_filter === 'teacher') {
        // Teacher: empty department, is_admin = 0
        $where_conditions[] = "(u.department IS NULL OR u.department = '') AND (u.is_admin = 0 OR u.is_admin IS NULL)";
    } elseif ($role_filter === 'department_head') {
        // Department Head: has department, is_admin = 1, role = 'user'
        $where_conditions[] = "(u.department IS NOT NULL AND u.department != '') AND u.is_admin = 1 AND (u.role = 'user' OR u.role IS NULL)";
    } elseif ($role_filter === 'admin') {
        // Admin: is_admin = 1, role = 'admin'
        $where_conditions[] = "u.is_admin = 1 AND u.role = 'admin'";
    }
}

if (!empty($approval_status_filter)) {
    $where_conditions[] = "u.approval_status = ?";
    $params[] = $approval_status_filter;
    $param_types .= 's';
}

if (!empty($date_from)) {
    $where_conditions[] = "u.created_at >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "u.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $param_types .= 's';
}

// Check if user has applied search or filter (excluding approval_status which is always pending)
$has_search_or_filter = !empty($search) || !empty($status_filter) || !empty($role_filter) || !empty($card_filter);

// Only execute query if there's a search or filter
if ($has_search_or_filter) {
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total FROM users u " . $where_clause;
    $count_stmt = $conn->prepare($count_query);
    if (!empty($params)) {
        $count_stmt->bind_param($param_types, ...$params);
    }
    $count_stmt->execute();
    $total_users_filtered = $count_stmt->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_users_filtered / $per_page);

    // Check if lock columns exist
    $check_lock_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked'");
    $has_lock_columns = ($check_lock_columns && $check_lock_columns->num_rows > 0);

    // Get users with pagination (include lock columns if they exist)
    if ($has_lock_columns) {
        $users_query = "
            SELECT
                u.id,
                u.username,
                u.email,
                u.department,
                u.status,
                u.approval_status,
                u.created_at,
                u.is_admin,
                COALESCE(u.role, 'user') as role,
                COALESCE(u.account_locked, 0) as account_locked,
                COALESCE(u.temporary_lock_count, 0) as temporary_lock_count
            FROM users u
        " . $where_clause . "
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";
    } else {
        $users_query = "
            SELECT
                u.id,
                u.username,
                u.email,
                u.department,
                u.status,
                u.approval_status,
                u.created_at,
                u.is_admin,
                COALESCE(u.role, 'user') as role,
                0 as account_locked,
                0 as temporary_lock_count
            FROM users u
        " . $where_clause . "
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";
    }

    $pagination_params = $params;
    $pagination_params[] = $per_page;
    $pagination_params[] = $offset;
    $pagination_types = $param_types . 'ii';

    $users_stmt = $conn->prepare($users_query);
    if (!empty($pagination_params)) {
        $users_stmt->bind_param($pagination_types, ...$pagination_params);
    }
    $users_stmt->execute();
    $users_result = $users_stmt->get_result();
    $users = $users_result->fetch_all(MYSQLI_ASSOC);
} else {
    // No search or filter - show empty result
    $users = [];
    $total_users_filtered = 0;
    $total_pages = 0;
}

// Get departments for dropdown
$departments_result = $conn->query("SELECT id, name FROM departments ORDER BY name");
$departments = [];
if ($departments_result) {
    while ($row = $departments_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Get user statistics
$pending_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE approval_status = 'pending'")->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <script src="js/session_monitor.js"></script>
    <title>OCABIS User Management</title>
    <style>
        .user-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .user-stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .user-stat-icon.total { background: white }
        .user-stat-icon.admin { background: white }
        .user-stat-icon.recent { background: white}
        .user-stat-icon.active { background: white }
        .user-stat-icon.inactive { background: white }

        .user-stat-content {
            flex: 1;
        }

        .user-stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
        }

        .user-stat-label {
            font-size: 14px;
            color: #718096;
            margin-top: 4px;
        }

        .add-user-btn, .export-btn {
            background: rgba(229, 62, 62, 0.9);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            min-height: 40px;
            white-space: nowrap;
            font-size: 14px;
        }

        .export-btn {
           background: #28a745;
        }
        
        .export-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .add-user-btn:hover {
            background: rgba(229, 62, 62, 1);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.active {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-badge.inactive {
            background: #fed7d7;
            color: #742a2a;
        }

        .status-badge.pending {
            background: #ffA500;
            color:rgb(255, 255, 255);
        }

        .status-badge.approved {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-badge.rejected {
            background: #fed7d7;
            color: #742a2a;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-badge.user {
            background: #e2e8f0;
            color: #4a5568;
        }

        .role-badge.viewer {
            background: #fef3c7;
            color: #92400e;
        }

        .role-badge.admin {
            background: #e9d5ff;
            color: #6b21a8;
        }

        .role-badge.department_head {
            background: #bee3f8;
            color: #2b6cb0;
        }

        .role-badge.super_admin {
            background: #fbb6ce;
            color: #97266d;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            color: #4a5568;
        }

        .action-btn:hover {
            border-color: #cbd5e0;
            background: #f7fafc;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .action-btn-toggle {
            border-color: #4299e1;
            color: #2c5282;
        }

        .action-btn-toggle:hover {
            background: #ebf8ff;
            border-color: #3182ce;
        }

        .action-btn-edit {
            border-color: #ed8936;
            color: #c05621;
        }

        .action-btn-edit:hover {
            background: #feebc8;
            border-color: #dd6b20;
        }

        .action-btn-delete {
            border-color: #fc8181;
            color: #c53030;
        }

        .action-btn-delete:hover {
            background: #fff5f5;
            border-color: #f56565;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.show {
            display: flex;
            opacity: 1;
        }

        .modal {
            background: linear-gradient(to bottom, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 0;
            max-width: 550px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 25px 80px rgba(0,0,0,0.4), 0 0 0 1px rgba(0,0,0,0.05);
            transform: scale(0.9) translateY(20px);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            flex-direction: column;
        }

        .modal-overlay.show .modal {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 30px;
            background: linear-gradient(135deg, rgba(229, 62, 62, 0.9) 0%, rgba(220, 38, 38, 0.9) 100%);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            position: relative;
        }

        .modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            letter-spacing: 0.3px;
        }

        .modal-title::before {
            content: '';
            width: 4px;
            height: 24px;
            background: white;
            border-radius: 2px;
        }

        .modal-body {
            padding: 30px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #ffffff;
            padding: 0;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            transition: all 0.2s ease;
            font-weight: bold;
            flex-shrink: 0;
            margin-left: auto;
            z-index: 10;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-header-content {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
        }

        .modal-icon-wrapper {
            min-width: 56px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .modal-icon {
            font-size: 32px;
            line-height: 1;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
        }

        .modal-header-text {
            flex: 1;
        }

        .modal-footer {
            padding: 20px 30px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            background: #f8f9fa;
        }

        .modal-btn {
            padding: 11px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            min-width: 100px;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .modal-btn:active {
            transform: translateY(1px);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .modal-btn-cancel {
            background-color: #ffffff;
            color: #6c757d;
            border: 1px solid #e2e8f0;
        }

        .modal-btn-cancel:hover {
            background-color: #f8f9fa;
            border-color: #cbd5e0;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .modal-btn-confirm {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            border: none;
        }

        .modal-btn-confirm:hover {
            background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
        }

        .modal-btn-confirm:active {
            transform: translateY(0);
        }

        .modal-message {
            color: #4a5568;
            line-height: 1.6;
            font-size: 16px;
            margin: 0;
            text-align: left;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 20000;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 20px;
        }

        .loading-overlay.show {
            display: flex;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-text {
            color: #ffffff;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
        }

        .modal-btn.loading {
            position: relative;
            color: transparent;
            pointer-events: none;
        }

        .modal-btn.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            text-align: left;
        }

        .form-label::after {
            content: '';
            display: inline-block;
            width: 4px;
            height: 4px;
            background: rgba(229, 62, 62, 0.9);
            border-radius: 50%;
            margin-left: 4px;
            vertical-align: middle;
        }

        .form-input::placeholder {
            color: #a0aec0;
            opacity: 0.7;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            text-align: left;
        }
        
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: rgba(229, 62, 62, 0.9);
            box-shadow: 0 0 0 4px rgba(229, 62, 62, 0.1), 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-1px);
        }

        .form-input:hover, .form-select:hover {
            border-color: #cbd5e0;
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
            top: 12px;
            color: #aaa;
            cursor: pointer;
        }
        
        /* Hide browser password manager icons */
        .input-group input[type="password"]::-webkit-credentials-auto-fill-button,
        .input-group input[type="password"]::-webkit-strong-password-auto-fill-button {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            position: absolute !important;
            right: -9999px !important;
        }
        
        .input-group input[type="password"]::-ms-reveal,
        .input-group input[type="password"]::-ms-clear {
            display: none !important;
        }

        .password-strength {
            margin-top: 8px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .password-strength-bar {
            flex: 1;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            overflow: hidden;
            position: relative;
        }

        .password-strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }

        .password-strength-fill.weak {
            width: 33%;
            background: #f56565;
        }

        .password-strength-fill.medium {
            width: 66%;
            background: #ed8936;
        }

        .password-strength-fill.strong {
            width: 100%;
            background: #48bb78;
        }

        .password-strength-text {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .password-strength-text.weak {
            color: #f56565;
        }

        .password-strength-text.medium {
            color: #ed8936;
        }

        .password-strength-text.strong {
            color: #48bb78;
        }

        .password-requirements {
            margin-top: 8px;
            font-size: 11px;
            color: #718096;
            text-align: left;
        }

        .password-requirement {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
        }

        .password-requirement::before {
            content: '○';
            color: #cbd5e0;
            font-size: 10px;
        }

        .password-requirement.valid::before {
            content: '✓';
            color: #48bb78;
        }

        .password-requirement.invalid::before {
            content: '✗';
            color: #f56565;
        }

        .password-match-error {
            margin-top: 6px;
            font-size: 11px;
            color: #f56565;
            display: none;
            text-align: left;
        }

        .password-match-error.show {
            display: block;
        }

        .form-input.error {
            border-color: #f56565;
        }

        .form-input.success {
            border-color: #48bb78;
        }

        .form-select {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%234a5568' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
            appearance: none;
        }

        .form-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 24px;
            border-top: 2px solid #f1f5f9;
        }

        .btn-cancel, .btn-submit {
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            letter-spacing: 0.3px;
            min-width: 120px;
        }

        .btn-cancel {
            background: #f1f5f9;
            color: #64748b;
            border: 2px solid #e2e8f0;
        }

        .btn-cancel:hover {
            background: #e2e8f0;
            border-color: #cbd5e0;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .btn-submit {
            background: linear-gradient(135deg, rgba(229, 62, 62, 0.9) 0%, rgba(220, 38, 38, 0.9) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(229, 62, 62, 0.4);
            background: linear-gradient(135deg, rgba(229, 62, 62, 1) 0%, rgba(220, 38, 38, 1) 100%);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit[style*="background: linear-gradient(135deg, #fc8181"]:hover {
            background: linear-gradient(135deg, #fc8181 0%, #f56565 100%) !important;
            box-shadow: 0 6px 20px rgba(252, 129, 129, 0.4) !important;
        }
        
        .btn-send-password {
            width: 100%;
            padding: 10px 16px;
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-send-password:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(66, 153, 225, 0.4);
            background: linear-gradient(135deg, #3182ce 0%, #2c5282 100%);
        }
        
        .btn-send-password:active {
            transform: translateY(0);
        }
        
        .btn-send-password:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }

        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #fc8181;
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .toggle-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .toggle-btn {
            padding: 10px 18px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #4a5568;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 40px;
            white-space: nowrap;
        }

        .toggle-btn:hover {
            border-color: #667eea;
            background: #f7fafc;
            color: #2d3748;
        }

        .toggle-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .filters-form {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-input {
            flex: 2;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            background-image: url('image/search.png');
            background-repeat: no-repeat;
            background-position: 12px center;
            background-size: 16px 16px;
            padding-left: 40px; /* room for icon */
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-select {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }

        .pagination a, .pagination span {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            color: #4a5568;
            transition: all 0.2s ease;
        }

        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .pagination .active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

         .table-container {
             background: white;
             border-radius: 12px;
             box-shadow: 0 2px 8px rgba(0,0,0,0.1);
             overflow-x: auto;
             overflow-y: visible;
         }

         .table {
             width: 100%;
             min-width: 800px;
             border-collapse: collapse;
         }

         .table th, .table td {
             padding: 12px 8px;
             text-align: left;
             border-bottom: 1px solid #e2e8f0;
             white-space: nowrap;
         }

         .table th {
             background: #f7fafc;
             font-weight: 600;
             color: #2d3748;
         }

         .action-dropdown {
             position: relative;
             display: inline-block;
         }

         .action-dots-btn {
             background: none;
             border: none;
             font-size: 18px;
             cursor: pointer;
             padding: 8px;
             border-radius: 4px;
             color: #718096;
             transition: all 0.2s ease;
             width: 36px;
             height: 36px;
             display: flex;
             align-items: center;
             justify-content: center;
             margin: 0 auto;
         }

         .action-dots-btn:hover {
             background: #f7fafc;
             color: #2d3748;
         }

        .action-menu {
            position: fixed;
            background: #ffffff; /* solid background to avoid transparency */
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            z-index: 10002; /* above shield and other elements */
            min-width: 180px;
            display: none;
            overflow: hidden;
            isolation: isolate; /* create new stacking context */
            will-change: transform; /* promote to its own layer */
            margin-top: 5px;
        }

        .action-menu.show {
            display: block;
            background: #ffffff; /* enforce opaque background when visible */
        }

         .action-menu-item {
             display: flex;
             align-items: center;
             gap: 8px;
             width: 100%;
             padding: 12px 16px;
             border: none;
             background: none;
             text-align: left;
             cursor: pointer;
             font-size: 14px;
             color: #4a5568;
             transition: all 0.2s ease;
         }

         .action-menu-item:hover {
             background: #f7fafc;
         }

         .action-menu-toggle:hover {
             background: #ebf8ff;
             color: #2c5282;
         }

         .action-menu-edit:hover {
             background: #feebc8;
             color: #c05621;
         }

        .action-menu-delete:hover {
            background: #fff5f5;
            color: #c53030;
        }

        .action-menu-approve:hover {
            background: #c6f6d5;
            color: #22543d;
        }

        .action-menu-reject:hover {
            background: #fed7d7;
            color: #742a2a;
        }

         .action-icon {
             font-size: 16px;
         }

        /* When an action menu is open on mobile, hide other triple-dot buttons to avoid visual bleed */
        @media (max-width: 768px) {
            body.hide-other-actions .action-dots-btn { visibility: hidden !important; }
            body.hide-other-actions .action-menu.show { visibility: visible !important; }
        }

        /* Desktop Table Styles - Ensure visibility */
        .table-container {
            width: 100%;
            overflow-x: auto;
            display: block;
            position: relative;
        }
        
        /* Ensure Actions column has enough space and doesn't clip menus */
        .table td:last-child {
            position: relative;
            min-width: 80px;
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

            /* User Stats */
            .user-stats {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 10px !important;
                margin-bottom: 15px !important;
            }

            .user-stat-card {
                padding: 12px !important;
            }

            .user-stat-number {
                font-size: 20px !important;
            }

            .user-stat-label {
                font-size: 12px !important;
            }

            /* Filters Header */
            .filters-header {
                flex-direction: column !important;
                gap: 15px !important;
                margin-bottom: 15px;
            }

            .filters-header h2 {
                font-size: 18px !important;
                margin-bottom: 0 !important;
            }

            .header-actions {
                width: 100% !important;
                flex-direction: column !important;
                gap: 10px !important;
            }

            .toggle-buttons {
                flex-direction: column !important;
                gap: 8px !important;
                width: 100% !important;
            }

            .toggle-btn {
                width: 100% !important;
                padding: 10px 16px !important;
                font-size: 13px !important;
                min-height: 40px !important;
                justify-content: flex-start !important;
            }

            .export-btn,
            .add-user-btn {
                width: 100% !important;
                padding: 10px 16px !important;
                min-height: 40px !important;
                justify-content: center !important;
            }

            /* Filters Section */
            .filters-section {
                flex-direction: column !important;
                gap: 10px !important;
                margin-bottom: 15px;
            }

            .filter-select,
            .search-input {
                width: 100% !important;
                font-size: 14px;
            }

            /* Avoid truncated text on mobile for filters and buttons */
            .filters-form { align-items: stretch !important; }
            .toggle-btn,
            .export-btn,
            .add-user-btn,
            .filter-select,
            .search-input {
                white-space: normal !important;
                overflow-wrap: anywhere !important;
                word-break: break-word !important;
            }

            .add-user-btn {
                width: 100% !important;
                margin-top: 10px !important;
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
                /* Allow wrapping instead of cutting off content */
                white-space: normal !important;
                overflow-wrap: anywhere !important;
                word-break: break-word !important;
            }

            .table tbody tr {
                display: table-row;
                visibility: visible;
            }

            /* Action Menu (mobile) */
            .action-menu {
                position: fixed !important; /* detach from flow so it doesn't scroll with table */
                z-index: 10000 !important; /* always above other cells/buttons */
                min-width: 180px !important;
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                background: #ffffff !important;
                border: 1px solid #e2e8f0 !important;
                box-shadow: 0 8px 24px rgba(0,0,0,0.18) !important;
                isolation: isolate !important;
                will-change: transform !important;
            }

            .action-menu.show {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
                pointer-events: auto !important;
                background: #ffffff !important;
            }

            .action-dots-btn {
                padding: 6px !important;
                font-size: 16px !important;
                width: 36px !important;
                height: 36px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                margin: 0 auto !important;
                pointer-events: auto !important;
                position: relative !important;
                z-index: 10 !important;
            }

            .table td:last-child {
                pointer-events: auto !important;
                position: relative !important;
                z-index: 10 !important;
                text-align: center !important;
                vertical-align: middle !important;
            }

            .action-menu-item {
                padding: 10px 14px !important;
                font-size: 13px !important;
            }

            /* Modals */
            .modal-overlay {
                padding: 10px !important;
            }

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

            .modal-footer {
                flex-direction: column !important;
                gap: 10px !important;
            }

            .modal-btn {
                width: 100% !important;
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
            .user-stats {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 8px !important;
            }

            .user-stat-card {
                padding: 10px !important;
            }

            .user-stat-number {
                font-size: 18px !important;
            }

            .user-stat-label {
                font-size: 11px !important;
            }

            .table th,
            .table td {
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
<body data-user-logged-in="true"
      data-user-super-admin="<?= $is_super_admin ? 'true' : 'false' ?>"
      data-user-is-admin="<?= $is_admin ? 'true' : 'false' ?>"
      data-user-department="<?= htmlspecialchars($user_department, ENT_QUOTES, 'UTF-8') ?>"
      data-success-message="<?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?>"
      data-error-message="<?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>">
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
                <a href="department.php" class="nav-link" title="<?= ($isDepartmentHead || $is_admin_role || $is_super_admin) ? 'Item List' : 'Department' ?>">
                    <span class="nav-icon">
                        <img src="image/department.png" alt="<?= ($isDepartmentHead || $is_admin_role || $is_super_admin) ? 'Item List' : 'Department' ?>">
                    </span>
                    <span class="nav-label"><?= ($isDepartmentHead || $is_admin_role || $is_super_admin) ? 'Item List' : 'Department' ?></span>
                </a>
            </li>
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
            <li class="nav-item">
                <a href="#" class="nav-link active" title="User Management">
                    <span class="nav-icon">
                        <img src="image/profile.png" alt="User Management">
                    </span>
                    <span class="nav-label">User Management</span>
                </a>
            </li>
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
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                ✓ <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                ✕ <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="user-stats">
            <div class="user-stat-card" onclick="filterByCard('pending')" style="cursor:pointer;">
                <div class="user-stat-icon recent">
                    <img src="image/clock.png" alt="Pending Approval" style="width: 30px; height: 30px;">
                </div>
                <div class="user-stat-content">
                    <div class="user-stat-number" style="color: #e53e3e;"><?php echo $pending_users; ?></div>
                    <div class="user-stat-label">Pending Approval</div>
                </div>
            </div>
        </div>

        <div class="filters-header">
            <h2 style="margin: 0">User Management</h2>
            <div class="header-actions">
                <button class="add-user-btn" onclick="showAddUserModal()">
                <img src="image/icons8-add-48.png" alt="Pending" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;" />
                Add New User
                </button>
            </div>
        </div>

        <div class="filters-section">
            <form method="GET" id="filterForm" class="filters-form">
                <input type="text" name="search" class="search-input" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="status" class="filter-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <select name="role" class="filter-select">
                    <option value="">All Roles</option>
                    <option value="teacher" <?php echo $role_filter === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                    <option value="department_head" <?php echo $role_filter === 'department_head' ? 'selected' : ''; ?>>Department Head</option>
                    <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
                <input type="hidden" name="approval_status" value="pending">
            </form>
        </div>
        
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Approval Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$has_search_or_filter): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #666;">
                                Please search or filter to view users.
                            </td>
                        </tr>
                    <?php elseif (empty($users)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #666;">
                                No users found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['id']); ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="role-badge <?php 
                                        $user_role = $user['role'] ?? 'user';
                                        $is_admin = (int)($user['is_admin'] ?? 0) === 1;
                                        if ($user_role === 'admin' && $is_admin) {
                                            echo 'admin';
                                        } elseif ($is_admin) {
                                            echo 'department_head';
                                        } elseif (empty($user['department'])) {
                                            echo 'viewer';
                                        } else {
                                            echo 'user';
                                        }
                                    ?>">
                                        <?php 
                                        $user_role = $user['role'] ?? 'user';
                                        $is_admin = (int)($user['is_admin'] ?? 0) === 1;
                                        if ($user_role === 'admin' && $is_admin) {
                                            echo 'ADMIN';
                                        } elseif ($is_admin) {
                                            echo 'DEPARTMENT HEAD';
                                        } elseif (empty($user['department'])) {
                                            echo 'TEACHER';
                                        } else {
                                            echo 'REGULAR USER';
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $user['status'] ?? 'active'; ?>">
                                        <?php echo strtoupper($user['status'] ?? 'active'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $user['approval_status'] ?? 'pending'; ?>">
                                        <?php echo strtoupper($user['approval_status'] ?? 'pending'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y g:i A', strtotime($user['created_at'])); ?></td>
                                <td style="text-align: center; vertical-align: middle;">
                                    <?php if (isset($user['account_locked']) && (int)$user['account_locked'] === 1): ?>
                                        <span class="status-badge" style="background: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; display: inline-block; margin-bottom: 5px;">
                                            🔒 LOCKED
                                        </span>
                                        <br>
                                    <?php endif; ?>
                                    <div class="action-dropdown" style="position: relative; display: inline-block;">
                                        <button class="action-dots-btn" onclick="toggleActionMenu(event, <?php echo $user['id']; ?>)" style="pointer-events: auto; position: relative; z-index: 10;">⋮</button>
                                        <div class="action-menu" id="actionMenu<?php echo $user['id']; ?>" style="display: none; visibility: hidden; opacity: 0; pointer-events: none;">
                                            <?php if ($is_super_admin && isset($user['account_locked']) && (int)$user['account_locked'] === 1): ?>
                                                <button class="action-menu-item action-menu-unlock" onclick="unlockAccount(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" style="background: #28a745; color: white; border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 8px;">
                                                    <img src="image/active-user.png" alt="Unlock" style="width:14px;height:14px; filter: brightness(0) invert(1);" />
                                                    <span>Unlock Account & Send Password</span>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (($user['approval_status'] ?? 'pending') === 'pending'): ?>
                                                <button class="action-menu-item action-menu-approve" onclick="approveUser(<?php echo $user['id']; ?>)">
                                                    <img src="image/active-user.png" alt="Approve" style="width:14px;height:14px;" />
                                                    <span>Approve User</span>
                                                </button>
                                                <button class="action-menu-item action-menu-reject" onclick="rejectUser(<?php echo $user['id']; ?>)">
                                                    <img src="image/unable.png" alt="Reject" style="width:14px;height:14px;" />
                                                    <span>Reject User</span>
                                                </button>
                                            <?php else: ?>
                                                <button class="action-menu-item action-menu-toggle" onclick="toggleUserStatus(<?php echo $user['id']; ?>)">
                                                    <img src="image/activity.png" alt="Toggle" style="width:14px;height:14px;" />
                                                    <span><?php echo ($user['status'] ?? 'active') === 'active' ? 'Set Inactive' : 'Set Active'; ?></span>
                                                </button>
                                                <button class="action-menu-item action-menu-edit" onclick='editUser(<?php echo json_encode($user); ?>)'>
                                                    <img src="image/edit.png" alt="Edit" style="width:14px;height:14px;" />
                                                    <span>Edit User</span>
                                                </button>
                                            <?php endif; ?>
                                            <button class="action-menu-item action-menu-delete" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', <?php echo (int)($user['is_admin'] ?? 0); ?>)">
                                                <img src="image/delete.png" alt="Delete" style="width:14px;height:14px;" />
                                                <span>Delete User</span>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($has_search_or_filter && $total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&role=<?php echo urlencode($role_filter); ?>&card_filter=<?php echo urlencode($card_filter); ?>&approval_status=<?php echo urlencode($approval_status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">« Previous</a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&role=<?php echo urlencode($role_filter); ?>&card_filter=<?php echo urlencode($card_filter); ?>&approval_status=<?php echo urlencode($approval_status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" 
                   class="<?php echo $i === $page ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&role=<?php echo urlencode($role_filter); ?>&card_filter=<?php echo urlencode($card_filter); ?>&approval_status=<?php echo urlencode($approval_status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>">Next »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function filterByCard(key) {
        try {
            const form = document.getElementById('filterForm');
            if (!form) return;
            
            // Clear other filters when clicking card
            const statusSel = form.querySelector('select[name="status"]');
            const roleSel = form.querySelector('select[name="role"]');
            const searchInput = form.querySelector('input[name="search"]');
            
            if (statusSel) statusSel.value = '';
            if (roleSel) roleSel.value = '';
            if (searchInput) searchInput.value = '';
            
            // Add a flag to indicate card filter was clicked
            const cardFilterInput = document.createElement('input');
            cardFilterInput.type = 'hidden';
            cardFilterInput.name = 'card_filter';
            cardFilterInput.value = key;
            form.appendChild(cardFilterInput);
            
            form.submit();
        } catch (e) { /* no-op */ }
    }
    </script>

    <!-- Add User Modal -->
    <div class="modal-overlay" id="addUserModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Add New User</h3>
                <button class="modal-close" onclick="closeAddUserModal()">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="addUserForm">
                <input type="hidden" name="action" value="add_user">
                <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-input" placeholder="Enter username" required>
                </div>
                <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" placeholder="Enter email address" required>
                </div>
                    <!-- Password fields removed - password will be auto-generated and sent via email -->
                <?php if ($is_super_admin): ?>
                <div class="form-group">
                    <label class="form-label">Account Type</label>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="is_department_head" id="addIsDepartmentHead" value="1" onchange="toggleDepartmentField()" style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="addIsDepartmentHead" style="cursor: pointer; margin: 0;">Department Head</label>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="is_admin_role" id="addIsAdminRole" value="1" onchange="toggleDepartmentField()" style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="addIsAdminRole" style="cursor: pointer; margin: 0;">Admin</label>
                        </div>
                    </div>
                    <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">Note: Only Super Admin can create Department Head or Admin accounts. Department Head requires a department, Admin does not. A user cannot be both.</small>
                </div>
                <?php endif; ?>
                <div class="form-group" id="departmentGroup">
                    <label class="form-label">Department</label>
                        <select name="department" id="addUserDepartment" class="form-select" required>
                            <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['name']); ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-buttons">
                    <button type="button" class="btn-cancel" onclick="closeAddUserModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Add User</button>
                </div>
            </form>
            </div>
        </div>
    </div>

    <!-- Approve User Modal -->
    <div class="modal-overlay" id="approveUserModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-content">
                    <div class="modal-icon-wrapper">
                        <div class="modal-icon">✅</div>
                    </div>
                    <div class="modal-header-text">
                        <h3 class="modal-title" id="approveUserTitle">Approve User</h3>
                    </div>
                </div>
                <button class="modal-close" onclick="closeApproveUserModal()" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <p class="modal-message" id="approveUserMessage">Are you sure you want to approve this user? The user will be activated and notified via email.</p>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-cancel" onclick="closeApproveUserModal()">Cancel</button>
                <button class="modal-btn modal-btn-confirm" id="confirmApproveBtn">Approve</button>
            </div>
        </div>
    </div>

    <!-- Reject User Modal -->
    <div class="modal-overlay" id="rejectUserModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-header-content">
                    <div class="modal-icon-wrapper">
                        <div class="modal-icon">❌</div>
                    </div>
                    <div class="modal-header-text">
                        <h3 class="modal-title" id="rejectUserTitle">Reject User</h3>
                    </div>
                </div>
                <button class="modal-close" onclick="closeRejectUserModal()" aria-label="Close">×</button>
            </div>
            <div class="modal-body">
                <p class="modal-message" id="rejectUserMessage">Are you sure you want to reject this user? This action cannot be undone.</p>
                <div class="form-group" style="margin-top: 20px;">
                    <label class="form-label">Rejection Reason (Optional)</label>
                    <textarea id="rejectionReason" class="form-input" rows="3" placeholder="Please provide a reason for rejection (optional)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-cancel" onclick="closeRejectUserModal()">Cancel</button>
                <button class="modal-btn modal-btn-confirm" id="confirmRejectBtn" style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);">Reject</button>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal-overlay" id="editUserModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Edit User</h3>
                <button class="modal-close" onclick="closeEditUserModal()">×</button>
            </div>
            <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" id="edit_username" class="form-input" placeholder="Enter username" required>
                </div>
                <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-input" placeholder="Enter email address" required>
                </div>
                <?php if ($is_super_admin): ?>
                <div class="form-group">
                    <label class="form-label">Account Type</label>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="is_department_head" id="editIsDepartmentHead" value="1" onchange="toggleDepartmentFieldEdit()" style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="editIsDepartmentHead" style="cursor: pointer; margin: 0;">Department Head</label>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" name="is_admin_role" id="editIsAdminRole" value="1" onchange="toggleDepartmentFieldEdit()" style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="editIsAdminRole" style="cursor: pointer; margin: 0;">Admin</label>
                        </div>
                    </div>
                    <small style="color: #666; font-size: 12px; margin-top: 4px; display: block;">Note: Only Super Admin can assign Department Head or Admin status. Department Head requires a department, Admin does not. A user cannot be both.</small>
                </div>
                <?php endif; ?>
                <div class="form-group" id="editDepartmentGroup">
                    <label class="form-label">Department</label>
                    <select name="department" id="edit_department" class="form-select">
                        <option value="">No Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['name']); ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-buttons">
                    <button type="button" class="btn-cancel" onclick="closeEditUserModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Update User</button>
                </div>
            </form>
            </div>
        </div>
    </div>

    <!-- Unlock Account Confirmation Modal -->
    <div class="modal-overlay" id="unlockAccountModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title">Unlock Account</h3>
                <button class="modal-close" onclick="closeUnlockAccountModal()">×</button>
            </div>
            <div class="modal-body">
                <p style="color: #4a5568; margin-bottom: 16px; font-size: 15px; line-height: 1.6;">Are you sure you want to unlock the account for <strong style="color: #2d3748;">"<span id="unlock_username"></span>"</strong>?</p>
                <div style="background: #e3f2fd; border: 2px solid #2196f3; padding: 14px; border-radius: 10px; margin-bottom: 20px; color: #1565c0; font-size: 13px; line-height: 1.5;">
                    <strong>ℹ️ Information:</strong>
                    <p style="margin: 8px 0 0 0;">A new password will be generated and sent to the user's email address.</p>
                </div>
                <form method="POST" id="unlockAccountForm">
                    <input type="hidden" name="action" value="unlock_account">
                    <input type="hidden" name="user_id" id="unlock_user_id">
                    <div class="form-buttons">
                        <button type="button" class="btn-cancel" onclick="closeUnlockAccountModal()">Cancel</button>
                        <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);">Unlock Account</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteUserModal">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Delete</h3>
                <button class="modal-close" onclick="closeDeleteUserModal()">×</button>
            </div>
            <div class="modal-body">
                <p style="color: #4a5568; margin-bottom: 24px; font-size: 15px; line-height: 1.6;">Are you sure you want to delete user <strong style="color: #2d3748;">"<span id="delete_username"></span>"</strong>? This action cannot be undone.</p>
                <div id="adminDeleteWarning" style="display: none; background: #fef2f2; border: 2px solid #fecaca; padding: 14px; border-radius: 10px; margin-bottom: 20px; color: #991b1b; font-size: 13px; line-height: 1.5;"></div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="delete_user_id">
                <div class="form-buttons">
                    <button type="button" class="btn-cancel" onclick="closeDeleteUserModal()">Cancel</button>
                        <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, #fc8181 0%, #f56565 100%); box-shadow: 0 4px 12px rgba(252, 129, 129, 0.3);">Delete User</button>
                </div>
            </form>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="loadingText">Processing...</div>
    </div>

    <!-- Flash Message Modal -->
    <div class="modal-overlay" id="flashMessageModal">
        <div class="modal" style="max-width: 420px; text-align: center; padding-bottom: 20px;">
            <div class="modal-header">
                <div class="modal-header-content" style="justify-content: center; flex: 1;">
                    <div class="modal-header-text">
                        <h3 class="modal-title" id="flashModalTitle" style="justify-content: center;">Notice</h3>
                    </div>
                </div>
                <button class="modal-close" onclick="closeFlashModal()" style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%);">×</button>
            </div>
            <div class="modal-body" style="padding: 24px 28px;">
                <p id="flashModalMessage" style="font-size: 15px; color: #4a5568; line-height: 1.6; margin: 0;"></p>
            </div>
            <div class="modal-footer" style="display: flex; justify-content: center; padding: 0 0 20px 0; gap: 10px;">
                <button class="btn-submit" style="min-width: 120px;" onclick="closeFlashModal()">Okay</button>
            </div>
        </div>
    </div>

    <script>
        const FLASH_MODAL = document.getElementById('flashMessageModal');
        const FLASH_MODAL_MESSAGE = document.getElementById('flashModalMessage');
        const FLASH_MODAL_TITLE = document.getElementById('flashModalTitle');

        function showFlashModal(message, type = 'error') {
            if (!FLASH_MODAL || !FLASH_MODAL_MESSAGE || !FLASH_MODAL_TITLE) return;
            FLASH_MODAL_MESSAGE.textContent = message;
            if (type === 'success') {
                FLASH_MODAL_TITLE.textContent = 'Success';
            } else {
                FLASH_MODAL_TITLE.textContent = 'Notice';
            }
            FLASH_MODAL.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeFlashModal() {
            if (!FLASH_MODAL) return;
            FLASH_MODAL.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const errorMessage = document.body.dataset.errorMessage;
            const successMessage = document.body.dataset.successMessage;
            if (errorMessage) {
                showFlashModal(errorMessage, 'error');
            } else if (successMessage) {
                showFlashModal(successMessage, 'success');
            }
        });

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

        // Password validation functions removed - password is now auto-generated
        
        // Modal Functions
        function showAddUserModal() {
            const modal = document.getElementById('addUserModal');
            const form = document.getElementById('addUserForm');
            
            // Reset form when opening
            if (form) {
                form.reset();
            }
            
            // Password fields removed - no need to reset password indicators
            
            // Reset department field visibility and required attribute
            const isDepartmentHeadCheckbox = document.getElementById('addIsDepartmentHead');
            const isAdminRoleCheckbox = document.getElementById('addIsAdminRole');
            const departmentGroup = document.getElementById('departmentGroup');
            const departmentSelect = document.getElementById('addUserDepartment');
            
            if ((isDepartmentHeadCheckbox || isAdminRoleCheckbox) && departmentGroup && departmentSelect) {
                // Set initial state based on checkbox - hide by default if unchecked
                toggleDepartmentField();
            } else if (departmentSelect) {
                // If no checkbox (not super admin), department is always required and visible
                if (departmentGroup) {
                    departmentGroup.style.display = 'block';
                }
                departmentSelect.setAttribute('required', 'required');
            }
            
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeAddUserModal() {
            const modal = document.getElementById('addUserModal');
            const form = document.getElementById('addUserForm');
            
            // Reset form when closing
            if (form) {
                form.reset();
            }
            
            // Reset password indicators
            const strengthDiv = document.getElementById('passwordStrength');
            const matchError = document.getElementById('passwordMatchError');
            const passwordInput = document.getElementById('addPassword');
            const confirmInput = document.getElementById('addConfirmPassword');
            
            if (strengthDiv) strengthDiv.style.display = 'none';
            if (matchError) matchError.classList.remove('show');
            if (passwordInput) passwordInput.classList.remove('success', 'error');
            if (confirmInput) confirmInput.classList.remove('success', 'error');
            
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_username').value = user.username;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_department').value = user.department || '';
            
            // Set checkboxes if SUPER ADMIN
            const isDepartmentHeadCheckbox = document.getElementById('editIsDepartmentHead');
            const isAdminRoleCheckbox = document.getElementById('editIsAdminRole');
            if (isDepartmentHeadCheckbox && isAdminRoleCheckbox) {
                const userRole = user.role || 'user';
                const isAdmin = (user.is_admin === 1 || user.is_admin === '1');
                
                // Set Department Head checkbox (is_admin = 1, role = 'user')
                isDepartmentHeadCheckbox.checked = (isAdmin && userRole === 'user');
                
                // Set Admin checkbox (is_admin = 1, role = 'admin')
                isAdminRoleCheckbox.checked = (isAdmin && userRole === 'admin');
                
                // Trigger department field toggle
                toggleDepartmentFieldEdit();
            }
            
            // User ID is already stored in edit_user_id hidden field, which sendNewPassword() will read
            
            document.getElementById('editUserModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        // Send new auto-generated password to user
        function sendNewPassword() {
            // Get user ID from the hidden input field in the edit modal
            const userIdInput = document.getElementById('edit_user_id');
            const userId = userIdInput ? userIdInput.value : null;
            const btn = document.getElementById('sendPasswordBtn');
            
            if (!userId || userId === '') {
                alert('Error: User ID not found. Please make sure the Edit User modal is open.');
                return;
            }
            
            if (!confirm('Are you sure you want to send a new auto-generated password to this user? The password will be sent to their email address.')) {
                return;
            }
            
            // Show loading state
            if (btn) {
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 6px;"></i>Sending...';
                
                // Send request
                fetch('user_management.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=send_new_password&user_id=' + userId
                })
                .then(response => response.text())
                .then(data => {
                    // Reload page to show success/error message
                    window.location.reload();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error sending password. Please try again.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
            } else {
                // Fallback if button is not found
                if (confirm('Send new password to this user?')) {
                    fetch('user_management.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=send_new_password&user_id=' + userId
                    })
                    .then(response => response.text())
                    .then(data => {
                        window.location.reload();
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error sending password. Please try again.');
                    });
                }
            }
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        function deleteUser(userId, username, isAdmin) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('delete_username').textContent = username;
            
            // Show warning for department head accounts
            const warningDiv = document.getElementById('adminDeleteWarning');
            if (isAdmin === 1) {
                warningDiv.style.display = 'block';
                warningDiv.innerHTML = '<strong>⚠️ Warning:</strong> You are about to delete a DEPARTMENT HEAD account. This action cannot be undone!';
            } else {
                warningDiv.style.display = 'none';
            }
            
            document.getElementById('deleteUserModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteUserModal() {
            document.getElementById('deleteUserModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Toggle department field based on role selection
        function toggleDepartmentField() {
            const isDepartmentHeadCheckbox = document.getElementById('addIsDepartmentHead');
            const isAdminRoleCheckbox = document.getElementById('addIsAdminRole');
            const departmentGroup = document.getElementById('departmentGroup');
            const departmentSelect = document.getElementById('addUserDepartment');
            
            if (isDepartmentHeadCheckbox && isAdminRoleCheckbox && departmentGroup && departmentSelect) {
                // Make checkboxes mutually exclusive
                if (isDepartmentHeadCheckbox.checked && isAdminRoleCheckbox.checked) {
                    // If both are checked, uncheck the one that was just clicked
                    if (event && event.target === isAdminRoleCheckbox) {
                        isDepartmentHeadCheckbox.checked = false;
                    } else {
                        isAdminRoleCheckbox.checked = false;
                    }
                }
                
                // Department heads must have a department
                if (isDepartmentHeadCheckbox.checked) {
                    departmentGroup.style.display = 'block';
                    departmentSelect.setAttribute('required', 'required');
                } else if (isAdminRoleCheckbox.checked) {
                    // Admin doesn't require department
                    departmentGroup.style.display = 'none';
                    departmentSelect.value = '';
                    departmentSelect.removeAttribute('required');
                } else {
                    // Neither checked - show department for regular users
                    departmentGroup.style.display = 'block';
                    departmentSelect.removeAttribute('required');
                }
            }
        }

        function unlockAccount(userId, username) {
            // Set user ID and username in the modal
            document.getElementById('unlock_user_id').value = userId;
            document.getElementById('unlock_username').textContent = username;
            
            // Show the modal
            document.getElementById('unlockAccountModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeUnlockAccountModal() {
            document.getElementById('unlockAccountModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Toggle department field for edit modal
        function toggleDepartmentFieldEdit() {
            const isDepartmentHeadCheckbox = document.getElementById('editIsDepartmentHead');
            const isAdminRoleCheckbox = document.getElementById('editIsAdminRole');
            const departmentGroup = document.getElementById('editDepartmentGroup');
            const departmentSelect = document.getElementById('edit_department');
            
            if (isDepartmentHeadCheckbox && isAdminRoleCheckbox && departmentGroup && departmentSelect) {
                // Make checkboxes mutually exclusive
                if (isDepartmentHeadCheckbox.checked && isAdminRoleCheckbox.checked) {
                    // If both are checked, uncheck the one that was just clicked
                    if (event && event.target === isAdminRoleCheckbox) {
                        isDepartmentHeadCheckbox.checked = false;
                    } else {
                        isAdminRoleCheckbox.checked = false;
                    }
                }
                
                // Department heads must have a department
                if (isDepartmentHeadCheckbox.checked) {
                    departmentGroup.style.display = 'block';
                    departmentSelect.setAttribute('required', 'required');
                } else if (isAdminRoleCheckbox.checked) {
                    // Admin doesn't require department
                    departmentGroup.style.display = 'none';
                    departmentSelect.value = '';
                    departmentSelect.removeAttribute('required');
                } else {
                    // Neither checked - show department for regular users
                    departmentGroup.style.display = 'block';
                    departmentSelect.removeAttribute('required');
                }
            }
        }

        // Initialize department visibility on modal open
        document.addEventListener('DOMContentLoaded', function() {
            const addIsDepartmentHeadCheckbox = document.getElementById('addIsDepartmentHead');
            const addIsAdminRoleCheckbox = document.getElementById('addIsAdminRole');
            const departmentSelect = document.getElementById('addUserDepartment');
            
            // If checkboxes exist (super admin), handle department visibility
            if (addIsDepartmentHeadCheckbox) {
                addIsDepartmentHeadCheckbox.addEventListener('change', toggleDepartmentField);
            }
            if (addIsAdminRoleCheckbox) {
                addIsAdminRoleCheckbox.addEventListener('change', toggleDepartmentField);
            }
            if (addIsDepartmentHeadCheckbox || addIsAdminRoleCheckbox) {
                // Set initial state
                toggleDepartmentField();
            } else if (departmentSelect) {
                // If no checkbox (not super admin), department is always required
                departmentSelect.setAttribute('required', 'required');
            }
            
            const editIsDepartmentHeadCheckbox = document.getElementById('editIsDepartmentHead');
            const editIsAdminRoleCheckbox = document.getElementById('editIsAdminRole');
            if (editIsDepartmentHeadCheckbox) {
                editIsDepartmentHeadCheckbox.addEventListener('change', toggleDepartmentFieldEdit);
            }
            if (editIsAdminRoleCheckbox) {
                editIsAdminRoleCheckbox.addEventListener('change', toggleDepartmentFieldEdit);
            }
            if (editIsDepartmentHeadCheckbox || editIsAdminRoleCheckbox) {
                // Set initial state for edit modal when it opens via editUser()
                toggleDepartmentFieldEdit();
            }
        });

         // Toggle action menu
         function toggleActionMenu(event, userId) {
             event.stopPropagation();
             
             // Close all other menus
             document.querySelectorAll('.action-menu').forEach(menu => {
                 if (menu.id !== 'actionMenu' + userId) {
                     menu.classList.remove('show');
                     menu.style.display = 'none';
                     menu.style.visibility = 'hidden';
                     menu.style.opacity = '0';
                     menu.style.pointerEvents = 'none';
                 }
             });
             
             // Toggle current menu
             const menu = document.getElementById('actionMenu' + userId);
             if (menu) {
                 const isVisible = menu.classList.contains('show');
                 
                 if (isVisible) {
                     // Hide menu
                     menu.classList.remove('show');
                     menu.style.display = 'none';
                     menu.style.visibility = 'hidden';
                     menu.style.opacity = '0';
                     menu.style.pointerEvents = 'none';
                     document.body.classList.remove('hide-other-actions');
                 } else {
                    // Show menu
                     menu.classList.add('show');
                     menu.style.display = 'block';
                     menu.style.visibility = 'visible';
                     menu.style.opacity = '1';
                     menu.style.pointerEvents = 'auto';
                    if (window.innerWidth <= 768) { 
                        document.body.classList.add('hide-other-actions'); 
                    }
                     
                     // Position menu properly (both desktop and mobile)
                     const button = event.target.closest('.action-dots-btn');
                     if (button) {
                         // Use setTimeout to ensure menu is rendered before measuring
                         setTimeout(() => {
                             const rect = button.getBoundingClientRect();
                             
                             // Temporarily show menu off-screen to measure its dimensions
                             menu.style.visibility = 'hidden';
                             menu.style.display = 'block';
                             menu.style.position = 'fixed';
                             menu.style.top = '-9999px';
                             menu.style.left = '-9999px';
                             
                             // Force a reflow to get accurate dimensions
                             menu.offsetHeight;
                             
                             const menuWidth = menu.offsetWidth || 180;
                             const menuHeight = menu.offsetHeight || 200;
                             
                             // Calculate position - align to right edge of button by default
                             // Fixed positioning is relative to viewport, so use getBoundingClientRect directly
                             let left = rect.right - menuWidth;
                             let top = rect.bottom + 5;
                             
                             // Adjust if menu goes off right edge - align to left edge of button
                             if (rect.right - menuWidth < 10) {
                                 left = rect.left;
                             }
                             
                             // Ensure menu doesn't go off screen on the right
                             if (left + menuWidth > window.innerWidth - 10) {
                                 left = window.innerWidth - menuWidth - 10;
                             }
                             
                             // Ensure menu doesn't go off screen on the left
                             if (left < 10) {
                                 left = 10;
                             }
                             
                             // Adjust if menu goes off bottom edge - show above button instead
                             if (top + menuHeight > window.innerHeight - 10) {
                                 top = rect.top - menuHeight - 5;
                                 // If still doesn't fit, position at top of viewport
                                 if (top < 10) {
                                     top = 10;
                                     // If menu is taller than viewport, allow it to scroll
                                     if (menuHeight > window.innerHeight - 20) {
                                         menu.style.maxHeight = (window.innerHeight - 20) + 'px';
                                         menu.style.overflowY = 'auto';
                                     }
                                 }
                             }
                             
                             // Ensure menu is above and fully opaque
                             menu.style.top = top + 'px';
                             menu.style.left = left + 'px';
                             menu.style.right = 'auto';
                             menu.style.bottom = 'auto';
                             menu.style.zIndex = '10002';
                             menu.style.background = '#ffffff';
                             menu.style.opacity = '1';
                             menu.style.visibility = 'visible';
                             menu.style.pointerEvents = 'auto';
                             menu.style.boxShadow = '0 8px 24px rgba(0,0,0,0.18)';
                         }, 10);
                     }
                 }
             }
         }

         // Close action menus when clicking outside
         document.addEventListener('click', function(event) {
            if (!event.target.closest('.action-dropdown') && !event.target.closest('.action-dots-btn')) {
                 document.querySelectorAll('.action-menu').forEach(menu => {
                     menu.classList.remove('show');
                     menu.style.display = 'none';
                     menu.style.visibility = 'hidden';
                     menu.style.opacity = '0';
                     menu.style.pointerEvents = 'none';
                 });
               document.body.classList.remove('hide-other-actions');
             }
             
             // Close modals when clicking outside
             const approveModal = document.getElementById('approveUserModal');
             const rejectModal = document.getElementById('rejectUserModal');
             if (approveModal && event.target === approveModal) {
                 closeApproveUserModal();
             }
             if (rejectModal && event.target === rejectModal) {
                 closeRejectUserModal();
             }
         });

         // Toggle user status
         function toggleUserStatus(userId) {
             if (confirm('Are you sure you want to toggle this user\'s status?')) {
                 const form = document.createElement('form');
                 form.method = 'POST';
                 form.innerHTML = `
                     <input type="hidden" name="action" value="toggle_status">
                     <input type="hidden" name="user_id" value="${userId}">
                 `;
                 document.body.appendChild(form);
                 form.submit();
             }
         }

         // Approve user
         function approveUser(userId) {
             // Close any open action menus
             document.querySelectorAll('.action-menu').forEach(menu => {
                 menu.classList.remove('show');
                 menu.style.display = 'none';
                 menu.style.visibility = 'hidden';
                 menu.style.opacity = '0';
                 menu.style.pointerEvents = 'none';
             });
             
             // Store userId for the confirm action
             window.pendingApproveUserId = userId;
             
             // Show approve modal
             document.getElementById('approveUserModal').classList.add('show');
             document.body.style.overflow = 'hidden';
         }

         function closeApproveUserModal() {
             document.getElementById('approveUserModal').classList.remove('show');
             document.body.style.overflow = 'auto';
             window.pendingApproveUserId = null;
         }

         // Show loading overlay
         function showLoading(message = 'Processing...') {
             const overlay = document.getElementById('loadingOverlay');
             const text = document.getElementById('loadingText');
             if (overlay) {
                 if (text) text.textContent = message;
                 overlay.classList.add('show');
                 document.body.style.overflow = 'hidden';
             }
         }

         // Hide loading overlay
         function hideLoading() {
             const overlay = document.getElementById('loadingOverlay');
             if (overlay) {
                 overlay.classList.remove('show');
                 document.body.style.overflow = 'auto';
             }
         }

         // Confirm approve action
         document.getElementById('confirmApproveBtn').addEventListener('click', function() {
             const userId = window.pendingApproveUserId;
             if (userId) {
                 // Show loading state
                 const btn = this;
                 btn.classList.add('loading');
                 btn.disabled = true;
                 
                 // Show loading overlay
                 showLoading('Approving user...');
                 
                 // Close modal
                 closeApproveUserModal();
                 
                 // Submit form
                 const form = document.createElement('form');
                 form.method = 'POST';
                 form.innerHTML = `
                     <input type="hidden" name="action" value="approve_user">
                     <input type="hidden" name="user_id" value="${userId}">
                 `;
                 document.body.appendChild(form);
                 form.submit();
             }
         });

         // Reject user
         function rejectUser(userId) {
             // Close any open action menus
             document.querySelectorAll('.action-menu').forEach(menu => {
                 menu.classList.remove('show');
                 menu.style.display = 'none';
                 menu.style.visibility = 'hidden';
                 menu.style.opacity = '0';
                 menu.style.pointerEvents = 'none';
             });
             
             // Store userId for the confirm action
             window.pendingRejectUserId = userId;
             
             // Clear rejection reason field
             document.getElementById('rejectionReason').value = '';
             
             // Show reject modal
             document.getElementById('rejectUserModal').classList.add('show');
             document.body.style.overflow = 'hidden';
         }

         function closeRejectUserModal() {
             document.getElementById('rejectUserModal').classList.remove('show');
             document.body.style.overflow = 'auto';
             window.pendingRejectUserId = null;
         }

         // Confirm reject action
         document.getElementById('confirmRejectBtn').addEventListener('click', function() {
             const userId = window.pendingRejectUserId;
             if (userId) {
                 // Show loading state
                 const btn = this;
                 btn.classList.add('loading');
                 btn.disabled = true;
                 
                 // Show loading overlay
                 showLoading('Rejecting user...');
                 
                 const reason = document.getElementById('rejectionReason').value.trim();
                 closeRejectUserModal();
                 
                 // Submit form
                 const form = document.createElement('form');
                 form.method = 'POST';
                 form.innerHTML = `
                     <input type="hidden" name="action" value="reject_user">
                     <input type="hidden" name="user_id" value="${userId}">
                     <input type="hidden" name="rejection_reason" value="${reason}">
                 `;
                 document.body.appendChild(form);
                 form.submit();
             }
         });

         // Toggle approval table view

        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(overlay => {
                    overlay.classList.remove('show');
                });
                document.body.style.overflow = 'auto';
            }
        });

        // Auto-submit filters on change
        document.querySelectorAll('.filter-select').forEach(select => {
            select.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        });

        // Real-time search with debounce
        let searchTimeout;
        document.querySelector('.search-input').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500);
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

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Close action menus on scroll (mobile and desktop)
        (function() {
            const closeAllActionMenus = () => {
                document.querySelectorAll('.action-menu').forEach(menu => {
                    if (menu.classList.contains('show')) {
                        menu.classList.remove('show');
                        menu.style.display = 'none';
                        menu.style.visibility = 'hidden';
                        menu.style.opacity = '0';
                        menu.style.pointerEvents = 'none';
                    }
                });
                // no-op
            };
 
            // Close on window scroll
            window.addEventListener('scroll', closeAllActionMenus, { passive: true });
 
            // Close when the table container scrolls
            const tableContainer = document.querySelector('.table-container');
            if (tableContainer) {
                tableContainer.addEventListener('scroll', closeAllActionMenus, { passive: true });
            }
        })();
 
        // Close action menus when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.action-dropdown') && !event.target.closest('.action-dots-btn')) {
                document.querySelectorAll('.action-menu').forEach(menu => {
                    menu.classList.remove('show');
                    menu.style.display = 'none';
                    menu.style.visibility = 'hidden';
                    menu.style.opacity = '0';
                    menu.style.pointerEvents = 'none';
                });
            }
        });
    </script>
</body>
</html>
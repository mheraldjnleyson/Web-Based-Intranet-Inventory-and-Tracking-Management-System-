<?php
// ==========================================
// UNIVERSAL SESSION VALIDATION
// ==========================================
// Use this code at the top of EVERY protected page
// (dashboard.php, crud.php, department.php, etc.)
// Replace your existing session check with this

session_start();

// Check if user is logged in via PHP session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    // No session - redirect to login
    header("Location: login.php?session_expired=1");
    exit();
}

// Promote Admin role users to super-admin privileges while tracking them separately
$isRoleBasedAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin' &&
                    isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
if ($isRoleBasedAdmin) {
    $_SESSION['is_super_admin'] = 1;
    $_SESSION['super_admin_via_role'] = true;
} elseif (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1) {
    unset($_SESSION['super_admin_via_role']);
}

$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$isRoleElevated = !empty($_SESSION['super_admin_via_role']);

// IMPORTANT: Check if native super admin (not elevated via role)
if ($isSuperAdmin && !$isRoleElevated) {
    // ✅ SUPER ADMIN - Skip database session validation
    // Super admins don't use user_sessions table
    // They are authenticated via PHP session only
    
    // Optional: Update last activity time
    $_SESSION['last_activity'] = time();
    
    // Continue to page content - no need to check database
    // Super admin is valid!
    
} else {
    // ==========================================
    // REGULAR USER - Validate against database
    // ==========================================
    
    // Database connection (adjust as needed)
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "ocabis";
    
    $conn = @new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        // Database error - logout
        session_destroy();
        header("Location: login.php?error=database");
        exit();
    }
    
    // Get current session ID
    $sessionId = session_id();
    $userId = $_SESSION['user_id'];
    
    // Check if account is locked (check lock columns exist first)
    $check_lock_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked'");
    $has_lock_columns = ($check_lock_columns && $check_lock_columns->num_rows > 0);
    
    if ($has_lock_columns) {
        // Check if account is locked
        $lockCheck = $conn->prepare("SELECT COALESCE(account_locked, 0) as account_locked FROM users WHERE id = ?");
        if ($lockCheck) {
            $lockCheck->bind_param("i", $userId);
            $lockCheck->execute();
            $lockResult = $lockCheck->get_result();
            
            if ($lockResult->num_rows === 1) {
                $lockData = $lockResult->fetch_assoc();
                
                // If account is locked, logout immediately and show security notification
                if ((int)$lockData['account_locked'] === 1) {
                    // Deactivate all sessions for this user
                    $deactivateAll = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
                    $deactivateAll->bind_param("i", $userId);
                    $deactivateAll->execute();
                    $deactivateAll->close();
                    
                    $lockCheck->close();
                    $conn->close();
                    
                    // Destroy session
                    session_unset();
                    session_destroy();
                    
                    // Redirect to login page (security alert is shown by session monitor, no need for parameter)
                    header("Location: login.php");
                    exit();
                }
            }
            $lockCheck->close();
        }
    }
    
    // Check if session exists and is active in database
    $sessionCheck = $conn->prepare("SELECT is_active, last_activity FROM user_sessions WHERE session_id = ? AND user_id = ? AND is_active = 1");
    $sessionCheck->bind_param("si", $sessionId, $userId);
    $sessionCheck->execute();
    $result = $sessionCheck->get_result();
    
    if ($result->num_rows === 0) {
        // Session not found or inactive - logout
        $sessionCheck->close();
        $conn->close();
        
        session_unset();
        session_destroy();
        
        header("Location: login.php?session_expired=1");
        exit();
    }
    
    $sessionData = $result->fetch_assoc();
    $sessionCheck->close();
    
    // Optional: Check session timeout (e.g., 30 minutes of inactivity)
    $sessionTimeout = 1800; // 30 minutes in seconds
    $lastActivity = strtotime($sessionData['last_activity']);
    
    if ((time() - $lastActivity) > $sessionTimeout) {
        // Session expired due to inactivity
        
        // Deactivate session in database
        $deactivate = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_id = ?");
        $deactivate->bind_param("s", $sessionId);
        $deactivate->execute();
        $deactivate->close();
        
        $conn->close();
        
        session_unset();
        session_destroy();
        
        header("Location: login.php?session_expired=1");
        exit();
    }
    
    // ✅ Session is valid - Update last activity
    $updateActivity = $conn->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ?");
    $updateActivity->bind_param("s", $sessionId);
    $updateActivity->execute();
    $updateActivity->close();
    
    $conn->close();
}

// ✅ User is authenticated (either super admin or regular user)
// Continue with page content below...
?>
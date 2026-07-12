<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');

// database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ocabis";

try {
    $conn = @new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    // Create dummy connection object if database doesn't exist
    $conn = new class {
        public $connect_error = "Unknown database 'ocabis'";
        public $connect_errno = 1049;
        
        public function __call($method, $args) {
            return null;
        }
    };
}

// check connection
$db_connected = !$conn->connect_error;

// If database is not connected, show error but don't die
if (!$db_connected) {
    // Database is corrupted or deleted - will handle in login
    $database_error = true;
} else {
    $database_error = false;
}

$errors = [];
$loginSuccess = false;
$isPermanentlyLocked = false; // Track if submitted account is permanently locked

// Account lock configuration (applies to all accounts)
$MAX_ATTEMPTS = 5; // Failed login attempts before temporary lock
$LOCK_SECONDS = 300; // Seconds for temporary lock (5 minutes = 300 seconds)
$MAX_TEMPORARY_LOCKS = 3; // After 3 temporary locks, account will be permanently locked

// Initialize per-user attempt stores
if (!isset($_SESSION['login_attempts_map'])) $_SESSION['login_attempts_map'] = [];
if (!isset($_SESSION['lock_until_map'])) $_SESSION['lock_until_map'] = [];

function get_attempts_for(string $u): int {
    return (int)($_SESSION['login_attempts_map'][$u] ?? 0);
}
function set_attempts_for(string $u, int $n): void {
    $_SESSION['login_attempts_map'][$u] = max(0, $n);
}
function inc_attempts_for(string $u): void {
    $_SESSION['login_attempts_map'][$u] = get_attempts_for($u) + 1;
}
function reset_attempts_for(string $u): void {
    $_SESSION['login_attempts_map'][$u] = 0;
    $_SESSION['lock_until_map'][$u] = 0;
}
function is_locked_out_for(string $u): bool {
    return (int)($_SESSION['lock_until_map'][$u] ?? 0) > time();
}
function lock_remaining_seconds_for(string $u): int {
    $until = (int)($_SESSION['lock_until_map'][$u] ?? 0);
    return max(0, $until - time());
}
function apply_lock_for(string $u, int $seconds): void {
    $_SESSION['lock_until_map'][$u] = time() + $seconds;
}

// Check if user is already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    // Determine if user is a viewer (borrower) - no department and not admin
    $isViewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);
    $target = $isViewer ? 'department.php' : 'dashboard.php';
    header("Location: " . $target);
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $shouldIncrementAttempts = true;
    $user = trim($_POST['username']);
    $pass = $_POST['password'];

    // Block if locked FOR THIS USERNAME ONLY
    if ($user !== '' && is_locked_out_for($user)) {
        // Show static lock duration instead of countdown
        $lock_duration_display = '';
        if ($LOCK_SECONDS >= 60) {
            $lock_mins = floor($LOCK_SECONDS / 60);
            $lock_duration_display = "{$lock_mins} minute(s)";
        } else {
            $lock_duration_display = "{$LOCK_SECONDS} second(s)";
        }
        $errors[] = "Too many failed attempts for user '{$user}'. Please try again after {$lock_duration_display}. Consider using Forgot Password.";
    } else {
        // --- Input validation ---
        if (empty($user)) {
            $errors[] = "Username is required.";
        }
        
        if (empty($pass)) {
            $errors[] = "Password is required.";
        }

        // --- Check user credentials ---
        if (empty($errors)) {
            // First check if database is connected
            if (!$db_connected) {
                // Database is corrupted or deleted - check for emergency credentials
                $super_admin_config = include 'super_admin_config.php';
                $permanent_admin = $super_admin_config['super_admin'];
                
                if ($user === $permanent_admin['username'] && $pass === $permanent_admin['password']) {
                    // Emergency super admin login - redirect to emergency recovery
                    $_SESSION['user_id'] = 999999;
                    $_SESSION['username'] = $permanent_admin['username'];
                    $_SESSION['email'] = $permanent_admin['email'];
                    $_SESSION['department'] = $permanent_admin['department'];
                    // Super admin - no role field needed
                    $_SESSION['is_admin'] = 1;
                    $_SESSION['is_super_admin'] = 1;
                    $_SESSION['emergency_access'] = true;
                    // Reset attempts for this user on success
                    reset_attempts_for($user);
                    
                    header("Location: emergency_recovery.php");
                    exit();
                } else {
                    $errors[] = "Invalid username or password.";
                }
            }
        }
    }

    // ==========================================
    // DATABASE-CONNECTED LOGIN LOGIC
    // ==========================================
    if ($db_connected && empty($errors)) {
        // ==========================================
        // SUPER ADMIN LOGIN CHECK
        // ==========================================
        $super_admin_sql = "SELECT id, username, password, email, department, status, created_at FROM super_admin WHERE username = ?";
        $super_admin_stmt = $conn->prepare($super_admin_sql);
        $super_admin_stmt->bind_param("s", $user);
        $super_admin_stmt->execute();
        $super_admin_result = $super_admin_stmt->get_result();

        if ($super_admin_result->num_rows === 1) {
            // Super admin found
            $userData = $super_admin_result->fetch_assoc();
            
            // Check status
            if ($userData['status'] === 'inactive') {
                $errors[] = "Your super admin account is inactive. Please contact the system administrator.";
                $shouldIncrementAttempts = false;
            } else {
                // Verify password
                if (password_verify($pass, $userData['password'])) {
                    // STEP 1: Update last login timestamp
                    $updateSql = "UPDATE super_admin SET updated_at = NOW() WHERE id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("i", $userData['id']);
                    $updateStmt->execute();
                    $updateStmt->close();
                    
                    error_log("=== SUPER ADMIN LOGIN SUCCESS ===");
                    error_log("User ID: " . $userData['id']);
                    error_log("Username: " . $userData['username']);
                    
                    // STEP 2: Set PHP session variables for super admin
                    // Note: We do NOT insert into user_sessions for super admins
                    // Super admins don't need session tracking in the database
                    $_SESSION['user_id'] = $userData['id'];
                    $_SESSION['username'] = $userData['username'];
                    $_SESSION['email'] = $userData['email'];
                    $_SESSION['department'] = $userData['department'];
                    // Super admin - no role field needed
                    $_SESSION['is_admin'] = 1;
                    $_SESSION['is_super_admin'] = 1;
                    // Use actual account creation time from database, not current time
                    $_SESSION['created_at'] = isset($userData['created_at']) ? $userData['created_at'] : date('Y-m-d H:i:s');
                    $_SESSION['session_id'] = session_id(); // Store session ID in PHP session only
                    
                    // Reset login attempts
                    reset_attempts_for($user);
                    
                    $loginSuccess = true;
                    
                    error_log("Super admin logged in successfully - redirecting to dashboard.php");
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $errors[] = "Invalid username or password.";
                }
            }
        }
        $super_admin_stmt->close();
        
        // ==========================================
        // REGULAR USER LOGIN CHECK (only if super admin not found)
        // ==========================================
        if (empty($errors) && !$loginSuccess) {
            // Check if lock columns exist in database
            $check_lock_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked'");
            $has_lock_columns = ($check_lock_columns && $check_lock_columns->num_rows > 0);
            
            // Build SQL query based on whether lock columns exist
            if ($has_lock_columns) {
                // Include lock-related columns for all accounts
                $sql = "SELECT id, username, password, email, department, approval_status, status, is_admin, role, 
                               created_at,
                               COALESCE(failed_login_attempts, 0) as failed_login_attempts, 
                               COALESCE(account_locked, 0) as account_locked, 
                               locked_at, lock_reason,
                               COALESCE(temporary_lock_count, 0) as temporary_lock_count
                        FROM users WHERE username = ?";
            } else {
                // Fallback query without lock columns (for databases that haven't run migration yet)
                $sql = "SELECT id, username, password, email, department, approval_status, status, is_admin, role, created_at
                        FROM users WHERE username = ?";
            }
            
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                // If prepare fails, log error and try fallback query
                error_log("Failed to prepare login query: " . $conn->error);
                $sql = "SELECT id, username, password, email, department, approval_status, status, is_admin, role, created_at
                        FROM users WHERE username = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    error_log("Failed to prepare fallback login query: " . $conn->error);
                    $errors[] = "Database error. Please contact administrator.";
                    $stmt = null;
                } else {
                    $has_lock_columns = false; // Use fallback mode
                }
            }
            
            // Track if statement is closed early to prevent double-close errors
            $stmtClosed = false;
            
            if ($stmt !== null && $stmt !== false) {
                $stmt->bind_param("s", $user);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = null;
            }

            if ($result !== null && $result->num_rows === 1) {
                $userData = $result->fetch_assoc();
                
                // Initialize lock-related fields if they don't exist (for backward compatibility)
                if (!isset($userData['account_locked'])) {
                    $userData['account_locked'] = 0;
                }
                if (!isset($userData['failed_login_attempts'])) {
                    $userData['failed_login_attempts'] = 0;
                }
                if (!isset($userData['locked_at'])) {
                    $userData['locked_at'] = null;
                }
                if (!isset($userData['lock_reason'])) {
                    $userData['lock_reason'] = null;
                }
                if (!isset($userData['temporary_lock_count'])) {
                    $userData['temporary_lock_count'] = 0;
                }
                
                // Check if account is permanently locked (for all accounts)
                if ($has_lock_columns && isset($userData['account_locked']) && (int)$userData['account_locked'] === 1) {
                    $errors[] = "Contact admin for your account.";
                    $shouldIncrementAttempts = false;
                    $isPermanentlyLocked = true; // Set flag for display section
                    error_log("Login blocked: Account permanently locked for user: " . $user);
                    $stmt->close();
                    $stmtClosed = true;
                }
                // Check approval status first
                elseif ($userData['approval_status'] === 'pending') {
                    $errors[] = "Your account is pending admin approval. Please wait for approval before logging in.";
                    $shouldIncrementAttempts = false;
                } elseif ($userData['approval_status'] === 'rejected') {
                    $errors[] = "Your account has been rejected. Please contact the administrator.";
                    $shouldIncrementAttempts = false;
                } elseif (!isset($userData['status']) || strtolower($userData['status']) !== 'active') {
                    $errors[] = "Your account is inactive. Please contact the administrator.";
                    $shouldIncrementAttempts = false;
                } else {
                    // Verify password
                    if (password_verify($pass, $userData['password'])) {
                        $userId = $userData['id'];
                        
                        // Check for existing active sessions and logout other devices
                        $existingSessions = $conn->prepare("SELECT session_id FROM user_sessions WHERE user_id = ? AND is_active = 1");
                        $existingSessions->bind_param("i", $userId);
                        $existingSessions->execute();
                        $existingResult = $existingSessions->get_result();
                        
                        $otherSessions = [];
                        while ($row = $existingResult->fetch_assoc()) {
                            $otherSessions[] = $row['session_id'];
                        }
                        $existingSessions->close();
                        
                        // Deactivate other sessions
                        if (!empty($otherSessions)) {
                            $placeholders = str_repeat('?,', count($otherSessions) - 1) . '?';
                            $deactivateSql = "UPDATE user_sessions SET is_active = 0 WHERE session_id IN ($placeholders)";
                            $deactivateStmt = $conn->prepare($deactivateSql);
                            $deactivateStmt->bind_param(str_repeat('s', count($otherSessions)), ...$otherSessions);
                            $deactivateStmt->execute();
                            $deactivateStmt->close();
                        }
                        
                        // Set session variables
                        $_SESSION['user_id'] = $userData['id'];
                        $_SESSION['username'] = $userData['username'];
                        $_SESSION['email'] = $userData['email'];
                        $_SESSION['department'] = $userData['department'];
                        $_SESSION['is_admin'] = $userData['is_admin'];
                        $_SESSION['role'] = isset($userData['role']) ? $userData['role'] : 'user';

                        // Grant full system privileges to users with the Admin role
                        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
                            $_SESSION['is_super_admin'] = 1;
                            $_SESSION['super_admin_via_role'] = true;
                        } else {
                            unset($_SESSION['super_admin_via_role']);
                        }
                        // Use actual account creation time from database, not current time
                        $_SESSION['created_at'] = isset($userData['created_at']) ? $userData['created_at'] : date('Y-m-d H:i:s');

                        // Reset failed_login_attempts on successful login
                        // Also unlock account if it was locked (admin may have unlocked it)
                        // Reset temporary lock count on successful login
                        // Only update lock-related fields if lock columns exist
                        // Note: created_at should NEVER be updated - it's the account creation timestamp
                        if ($has_lock_columns) {
                            $updateSql = "UPDATE users SET failed_login_attempts = 0, account_locked = 0, locked_at = NULL, lock_reason = NULL, temporary_lock_count = 0 WHERE id = ?";
                        } else {
                            $updateSql = "UPDATE users SET failed_login_attempts = 0 WHERE id = ?";
                        }
                        $updateStmt = $conn->prepare($updateSql);
                        if ($updateStmt !== false) {
                            $updateStmt->bind_param("i", $userData['id']);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }
                        
                        // Record this session in database
                        $sessionId = session_id();
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

                        // First, deactivate ALL existing sessions for this user
                        $deactivateStmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
                        $deactivateStmt->bind_param("i", $userId);
                        $deactivateStmt->execute();
                        $deactivateStmt->close();

                        // Delete any existing session with same session_id (just in case)
                        $deleteStmt = $conn->prepare("DELETE FROM user_sessions WHERE session_id = ?");
                        $deleteStmt->bind_param("s", $sessionId);
                        $deleteStmt->execute();
                        $deleteStmt->close();

                        // Now insert fresh session record (THIS WORKS because userId is from users table)
                        $sessionSql = "INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, is_active, login_time, last_activity) 
                                       VALUES (?, ?, ?, ?, 1, NOW(), NOW())";
                        $sessionStmt = $conn->prepare($sessionSql);
                        $sessionStmt->bind_param("isss", $userId, $sessionId, $ipAddress, $userAgent);
                        $sessionStmt->execute();
                        $sessionStmt->close();
                        
                        // Set flag to show concurrent login alert
                        if (!empty($otherSessions)) {
                            $_SESSION['concurrent_login'] = true;
                        }
                        
                        // Reset session-based attempts for this user on success
                        reset_attempts_for($user);
                        
                        // Log successful login
                        error_log("Login success: User logged in: " . $user);
                        
                        $loginSuccess = true;
                        // Immediate redirect based on viewer status
                        // Determine if user is a viewer (borrower) - no department and not admin
                        $isViewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);
                        if ($isViewer) {
                            header("Location: department.php");
                            exit();
                        } else {
                            header("Location: dashboard.php");
                            exit();
                        }
                    } else {
                        // Password verification failed
                        $errors[] = "Invalid username or password.";
                    }
                }
                // Only close statement if it hasn't been closed already
                if ($stmt !== null && !$stmtClosed) {
                    $stmt->close();
                }
            } else if ($result === null) {
                // Query preparation failed
                $errors[] = "Database error. Please contact administrator.";
            } else {
                // Username does not exist in the system
                $errors[] = "Username is not registered in the system. Please check your username or contact the administrator.";
                $shouldIncrementAttempts = false; // Don't increment attempts for non-existent usernames
                if ($stmt !== null) {
                    $stmt->close();
                }
            }
        }
    }

    // Handle failed login attempts and apply locks (applies to all accounts uniformly)
    if (!empty($errors) && !$loginSuccess && $shouldIncrementAttempts) {
        if ($user !== '') {
            inc_attempts_for($user);
            $currentSessionAttempts = get_attempts_for($user);
            
            // ALWAYS apply lock if attempts >= MAX_ATTEMPTS, regardless of database connection or user existence
            if ($currentSessionAttempts >= $MAX_ATTEMPTS) {
                // First, always apply session-based lock immediately
                if (!is_locked_out_for($user)) {
                    apply_lock_for($user, $LOCK_SECONDS);
                    error_log("Session-based lock applied: User '{$user}' locked for {$LOCK_SECONDS} seconds (attempts: {$currentSessionAttempts})");
                }
                
                // Then try to update database if connected and user exists in users table
                if ($db_connected) {
                    // Check if lock columns exist
                    $check_lock_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked'");
                    $has_lock_columns = ($check_lock_columns && $check_lock_columns->num_rows > 0);
                    
                    if ($has_lock_columns) {
                        // Get user ID and current temporary lock count from database
                        $userCheckStmt = $conn->prepare("SELECT id, COALESCE(temporary_lock_count, 0) as temp_lock_count, COALESCE(account_locked, 0) as account_locked FROM users WHERE username = ?");
                        if ($userCheckStmt) {
                            $userCheckStmt->bind_param("s", $user);
                            $userCheckStmt->execute();
                            $userCheckResult = $userCheckStmt->get_result();
                            
                            if ($userCheckResult->num_rows === 1) {
                                $userCheckData = $userCheckResult->fetch_assoc();
                                $userId = $userCheckData['id'];
                                $currentTempLockCount = (int)$userCheckData['temp_lock_count'];
                                $isAlreadyLocked = (int)$userCheckData['account_locked'] === 1;
                                
                                // Only process if account is not already permanently locked
                                if (!$isAlreadyLocked) {
                                    // Check if user already has 3 or more temporary locks - if so, lock immediately
                                    if ($currentTempLockCount >= $MAX_TEMPORARY_LOCKS) {
                                        // Account should have been locked but wasn't - lock it now
                                        $permanentLockStmt = $conn->prepare("UPDATE users SET account_locked = 1, locked_at = NOW(), lock_reason = 'Too many temporary locks (3 or more temporary locks exceeded)' WHERE id = ?");
                                        if ($permanentLockStmt) {
                                            $permanentLockStmt->bind_param("i", $userId);
                                            if ($permanentLockStmt->execute()) {
                                                error_log("Account permanently locked: User '{$user}' already had {$currentTempLockCount} temporary locks - locking now");
                                                
                                                // Deactivate all active sessions for this user to force logout
                                                $deactivateSessions = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
                                                if ($deactivateSessions) {
                                                    $deactivateSessions->bind_param("i", $userId);
                                                    $deactivateSessions->execute();
                                                    $deactivateSessions->close();
                                                    error_log("All active sessions deactivated for locked user: {$user}");
                                                }
                                                
                                                // Set flag for display section
                                                $isPermanentlyLocked = true;
                                                
                                                // Replace error message
                                                $errors = array_filter($errors, function($msg) {
                                                    return strpos($msg, 'Invalid username or password') === false;
                                                });
                                                $errors[] = "Contact admin for your account.";
                                                $shouldIncrementAttempts = false;
                                            } else {
                                                error_log("Failed to permanently lock account: " . $permanentLockStmt->error);
                                            }
                                            $permanentLockStmt->close();
                                        }
                                    } else {
                                        // Increment temporary lock count
                                        $newTempLockCount = $currentTempLockCount + 1;
                                        
                                        // Check if this will be the 3rd temporary lock - if so, permanently lock the account
                                        if ($newTempLockCount >= $MAX_TEMPORARY_LOCKS) {
                                            // Permanently lock the account
                                            $permanentLockStmt = $conn->prepare("UPDATE users SET account_locked = 1, locked_at = NOW(), lock_reason = 'Too many temporary locks (3 temporary locks exceeded)', temporary_lock_count = ? WHERE id = ?");
                                            if ($permanentLockStmt) {
                                                $permanentLockStmt->bind_param("ii", $newTempLockCount, $userId);
                                                if ($permanentLockStmt->execute()) {
                                                    error_log("Account permanently locked: User '{$user}' has reached {$newTempLockCount} temporary locks (exceeded {$MAX_TEMPORARY_LOCKS})");
                                                    
                                                    // Deactivate all active sessions for this user to force logout
                                                    $deactivateSessions = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
                                                    if ($deactivateSessions) {
                                                        $deactivateSessions->bind_param("i", $userId);
                                                        $deactivateSessions->execute();
                                                        $deactivateSessions->close();
                                                        error_log("All active sessions deactivated for locked user: {$user}");
                                                    }
                                                    
                                                    // Set flag for display section
                                                    $isPermanentlyLocked = true;
                                                    
                                                    // Replace error message
                                                    $errors = array_filter($errors, function($msg) {
                                                        return strpos($msg, 'Invalid username or password') === false;
                                                    });
                                                    $errors[] = "Contact admin for your account.";
                                                    $shouldIncrementAttempts = false;
                                                } else {
                                                    error_log("Failed to permanently lock account: " . $permanentLockStmt->error);
                                                }
                                                $permanentLockStmt->close();
                                            }
                                        } else {
                                            // Apply temporary lock and increment temporary lock count
                                            $updateTempLockStmt = $conn->prepare("UPDATE users SET temporary_lock_count = ? WHERE id = ?");
                                            if ($updateTempLockStmt) {
                                                $updateTempLockStmt->bind_param("ii", $newTempLockCount, $userId);
                                                if ($updateTempLockStmt->execute()) {
                                                    error_log("Temporary lock #{$newTempLockCount} applied: User '{$user}' locked for {$LOCK_SECONDS} seconds");
                                                } else {
                                                    error_log("Failed to update temporary lock count: " . $updateTempLockStmt->error);
                                                }
                                                $updateTempLockStmt->close();
                                            }
                                        }
                                    }
                                }
                            }
                            $userCheckStmt->close();
                        }
                    }
                }
                
                // Reset session counter after locking (but keep lock active)
                set_attempts_for($user, 0);
            }
        }
    }
}

// Close database connection if it was opened
if ($db_connected) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
  <title>OCABIS Login</title>
  <link rel="stylesheet" href="Css/login.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* Default desktop behavior for mobile hero */
    .mobile-hero { display: none; }
    .database-status-error {
      background: linear-gradient(135deg, #fef3c7, #fde68a);
      border: 2px solid #f59e0b;
      border-radius: 12px;
      padding: 20px;
      margin: 20px 0;
      display: flex;
      align-items: center;
      gap: 15px;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2);
    }
    
    .database-status-error .status-icon {
      font-size: 32px;
      flex-shrink: 0;
    }
    
    .database-status-error .status-content h4 {
      margin: 0 0 8px 0;
      color: #92400e;
      font-size: 16px;
      font-weight: 600;
    }
    
    .database-status-error .status-content p {
      margin: 4px 0;
      color: #92400e;
      font-size: 14px;
      line-height: 1.4;
    }
    
    .database-status-error .status-content p strong {
      color: #78350f;
    }
    
    @media (max-width: 480px) {
      .database-status-error {
        flex-direction: column;
        text-align: center;
        gap: 10px;
      }
      
      .database-status-error .status-icon {
        font-size: 28px;
      }
    }

    /* Mobile Responsive Styles - Same Design, Scaled Down */
    @media (max-width: 768px) {
      /* Background logo for mobile */
      body::before {
        content: "";
        position: fixed;
        inset: 0;
        background: none; /* move logo into card */
        opacity: 0;
        pointer-events: none;
        z-index: 0;
      }

      html, body { height: 100%; min-height: 100vh; overflow: hidden; }
      body {
        padding: 0;
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #f3f4f6; /* slightly darker neutral background */
      }

      .login-container {
        width: 100%;
        max-width: 100%;
        border-radius: 0;
        margin: 0;
        min-height: 100vh;
        height: 100vh; /* lock viewport height */
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: none; /* remove outer card */
      }

      /* Centered stacked layout on mobile and remove white card */
      .login-box { flex-direction: column; align-items: stretch; text-align: center; width: 100%; height: 100%; background: transparent; }
      /* Hide left visual panel on mobile */
      .left-panel { display: none; }

      .left-panel {
        padding: 25px 20px;
        border-radius: 30px 30px 0 0;
        text-align: center;
      }

      .left-panel h3 {
        font-size: 16px;
        margin-bottom: 15px;
      }

      .logo-title {
        justify-content: center;
        margin-bottom: 15px;
      }

      .logo-title img {
        width: 45px;
        margin-right: 5px;
      }

      .logo-title h1 {
        font-size: 40px;
        letter-spacing: 10px;
      }

      .left-panel p {
        font-size: 13px;
      }

      .left-panel::before,
      .left-panel::after {
        display: block; /* Keep decorative elements */
        width: 100px;
        height: 100px;
      }

      .right-panel {
        padding: 25px 20px;
        width: 100%;
        min-height: auto;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        margin: 16px auto; /* center horizontally */
        background: rgba(255,255,255,0.97); /* light card for readability */
        backdrop-filter: blur(1px);
        box-shadow: 0 10px 24px rgba(0,0,0,0.12);
        position: relative;
        z-index: 1; /* above background logo */
        color: #111827;
      }

      /* Mobile condensed brand header (replaces hidden left panel) */
      .mobile-hero { display: flex; flex-direction: column; align-items: center; gap: 5px; margin-bottom: 50px; }
      .mobile-hero .mobile-welcome { font-weight: 700; font-size: 30px; color: #e11d48; letter-spacing: .5px; }
      .mobile-hero .mobile-logo { display: flex; align-items: center; gap: 8px; }
      .mobile-hero .mobile-logo img { width: 75px; height: 75px; }
      .mobile-hero .mobile-logo h1 { margin: 0; font-size: 50px; letter-spacing: 10px; color: #111827; }
      .mobile-hero .mobile-subtitle { font-size: clamp(12px, 4vw, 16px); color: #6b7280; white-space: nowrap; letter-spacing: 0.2px; }

      .right-panel h2 {
        font-size: 34px;
        text-align: center;
        color: #e11d48;
        padding:30px;
      }

      .right-panel p {
        font-size: 16px;
        text-align: center;
        margin: 8px 0 20px;
        color: #4b5563;
      }

      .input-group {
        margin-bottom: 15px;
      }

      .input-group input {
        padding: 16px 44px 16px 16px;
        font-size: 17px;
        border-radius: 12px;
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

      .input-group i {
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 20px;
      }

      .login-btn {
        width: 100%;
        padding: 16px;
        font-size: 18px;
        margin-bottom: 12px;
        border-radius: 14px;
      }

      .signup-text {
        font-size: 14px;
        margin-top: 14px;
      }

      .signup-text a {
        white-space: nowrap; /* Prevent "Sign up Now" from breaking */
      }

      .help-text {
        font-size: 14px;
        text-align: center;
        margin: 6px 0 12px 0;
      }

      .error-list {
        padding: 12px;
        font-size: 12px;
        margin-bottom: 15px;
      }

      .error-list ul {
        padding-left: 18px;
      }

      .error-list li {
        margin-bottom: 4px;
        font-size: 11px;
      }

      .success-message,
      .info-message {
        padding: 12px;
        font-size: 12px;
        margin-bottom: 15px;
      }

      .success-message p,
      .info-message {
        font-size: 11px;
        line-height: 1.5;
      }

      .modal-content {
        width: 85%;
        max-width: 400px;
        padding: 25px;
        margin: 20px;
      }

      .modal-content h2 {
        font-size: 1.3rem;
        margin: 10px 0;
      }

      .modal-content p {
        font-size: 0.95rem;
        margin-bottom: 20px;
      }

      .modal-ok-btn {
        padding: 10px 20px;
        font-size: 14px;
      }

      .close-btn {
        top: 10px;
        right: 15px;
        font-size: 1.5rem;
      }

      #loading-spinner {
        width: 45px;
        height: 45px;
        border-width: 3px;
      }
    }

    @media (max-width: 480px) {
      body { padding: 0; overflow: hidden; }

      .login-container {
        border-radius: 25px;
      }

      .left-panel {
        padding: 20px 15px;
        border-radius: 25px 0 0 25px;
      }

      .left-panel h3 {
        font-size: 14px;
        margin-bottom: 12px;
      }

      .logo-title img {
        width: 35px;
      }

      .logo-title h1 {
        font-size: 32px;
        letter-spacing: 8px;
      }

      .left-panel p {
        font-size: 11px;
      }

      .left-panel::before,
      .left-panel::after {
        width: 80px;
        height: 80px;
      }

      .right-panel { padding: 20px 15px; border-radius: 16px; margin: 12px auto; min-height: auto; background: rgba(255,255,255,0.97); color:#111827; box-shadow: 0 8px 20px rgba(0,0,0,0.12); max-width: 520px; width: calc(100% - 24px); }

      /* Logo watermark inside the light card */
      .right-panel::before {
        content: "";
        position: absolute;
        inset: 0;
        background: url('image/image-removebg-preview.png') center 28% / 60% no-repeat;
        opacity: 0.06;
        pointer-events: none;
        border-radius: inherit;
      }

      .right-panel h2 { font-size: 24px; color:#e11d48; }

      .right-panel p { font-size: 13px; margin: 6px 0 15px; color:#4b5563; }

      .input-group input { padding: 12px 38px 12px 12px; font-size: 15px; border-radius: 12px; }

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

      .input-group i {
        right: 10px;
        font-size: 13px;
      }

      .login-btn { width: 100%; padding: 14px; font-size: 16px; border-radius: 14px; }

      .signup-text {
        font-size: 10px;
      }

      .signup-text a {
        white-space: nowrap; /* Prevent "Sign up Now" from breaking */
      }

      .help-text {
        font-size: 10px;
      }

      .error-list,
      .success-message,
      .info-message {
        padding: 10px;
        font-size: 11px;
      }

      .modal-content {
        width: 90%;
        max-width: 350px;
        padding: 20px;
        margin: 15px;
        border-radius: 12px;
      }

      .modal-content h2 {
        font-size: 1.2rem;
      }

      .modal-content p {
        font-size: 0.9rem;
      }
    }
  </style>
</head>
<body>
  <!-- Loading overlay -->
  <div id="loading-overlay">
    <div id="loading-spinner"></div>
  </div>

  <div class="login-container">
    <div class="login-box">
      <div class="left-panel">
        <h3>WELCOME TO</h3>
        <div class="logo-title">
          <img src="image/image-removebg-preview.png" alt="Logo">
          <h1>CABIS</h1>
        </div>
        <p>INVENTORY MANAGEMENT SYSTEM</p>
      </div>
      <div class="right-panel">
        <!-- Mobile brand header (shown on mobile only) -->
        <div class="mobile-hero">
          <div class="mobile-welcome">WELCOME TO</div>
          <div class="mobile-logo">
            <img src="image/image-removebg-preview.png" alt="Logo">
            <h1>CABIS</h1>
          </div>
          <div class="mobile-subtitle">INVENTORY MANAGEMENT SYSTEM</div>
        </div>
        <h2>LOG IN</h2>
        <p>Enter your details to sign in to your account</p>

        <?php if (!empty($errors)): ?>
          <div class="error-list">
            <ul>
              <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php 
          // Only show info messages if this is a POST request (user just tried to login)
          if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $submittedUsername = trim($_POST['username'] ?? '');
            $isTemporarilyLocked = is_locked_out_for($submittedUsername);
            // $isPermanentlyLocked is already set in the PHP processing section above (line 42, 277, etc.)
            
            if ($isTemporarilyLocked): 
              // Show static lock duration (5 minutes) instead of countdown
              $lock_duration_display = '';
              if ($LOCK_SECONDS >= 60) {
                $lock_mins = floor($LOCK_SECONDS / 60);
                $lock_duration_display = "{$lock_mins} minute(s)";
              } else {
                $lock_duration_display = "{$LOCK_SECONDS} second(s)";
              }
        ?>
          <div class="info-message" style="margin:10px 0; color:#b45309; background:#fffbeb; border:1px solid #fbbf24; padding:10px; border-radius:8px;">
            Too many failed attempts. Please wait <strong><?= $lock_duration_display ?></strong> before trying again.
            <br/>Tip: Use <a href="forgot_password.php">Forgot Password</a> to reset your password.
          </div>
        <?php 
            elseif (!$isPermanentlyLocked): 
              $currentAttempts = get_attempts_for($submittedUsername); 
              if ($currentAttempts > 0): 
                $lock_duration_display = '';
                if ($LOCK_SECONDS >= 60) {
                  $lock_mins = floor($LOCK_SECONDS / 60);
                  $lock_duration_display = "{$lock_mins} minute(s)";
                } else {
                  $lock_duration_display = "{$LOCK_SECONDS} second(s)";
                }
        ?>
          <div class="info-message" style="margin:10px 0; color:#1f2937; background:#f3f4f6; border:1px solid #e5e7eb; padding:10px; border-radius:8px;">
            Attempt <?= (int)$currentAttempts ?> of <?= (int)$MAX_ATTEMPTS ?> for user <strong><?= htmlspecialchars($submittedUsername) ?></strong>. After <?= (int)$MAX_ATTEMPTS ?> failed attempts, login will be locked for <?= $lock_duration_display ?>.
            <br/>Forgot your password? <a href="forgot_password.php">Reset it here</a>.
          </div>
        <?php 
              endif;
            endif;
          }
        ?>

        <?php if (isset($_GET['registered']) && $_GET['registered'] == '1'): ?>
          <div class="success-message">
            <p>Registration successful! Please wait for admin approval before you can login.</p>
          </div>
        <?php endif; ?>

        <?php if (isset($_GET['logout']) && $_GET['logout'] == '1'): ?>
        <script>
          document.addEventListener('DOMContentLoaded', function() {
            showModal("You have been logged out successfully.", "info");
            document.querySelector('.modal-ok-btn').onclick = function () {
              window.location.href = "login.php"; 
            };
          });
        </script>
        <?php endif; ?>


        <?php if ($database_error): ?>
        <div class="database-status-error">
            <div class="status-icon">⚠️</div>
            <div class="status-content">
                <h4>System Maintenance</h4>
                <p>The database is currently unavailable. Our technical team is working to resolve this issue.</p>
            </div>
        </div>
        <?php endif; ?>

        <form id="loginForm" method="POST" action="" onsubmit="return onSubmitForm(event)">
          <div class="input-group">
            <input type="text" name="username" placeholder="Username" required 
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" />
            <i class="fas fa-user"></i>
          </div>
          
          <div class="input-group">
            <input type="password" name="password" id="password" placeholder="Password" required autocomplete="current-password" />
            <i class="fas fa-eye" id="toggle-password" onclick="togglePassword()" style="cursor: pointer;"></i>
          </div>

          <small class="help-text">
            <a href="forgot_password.php">Forgot Password?</a>
          </small>

          <button type="submit" class="login-btn">LOGIN</button>

          <p class="signup-text">Don't you have an Account? <a href="register.php">Sign up Now</a></p>
        </form>
      </div>
    </div>
  </div>

  <div id="alertModal" class="modal">
    <div class="modal-content">
      <span class="close-btn" onclick="closeModal()">&times;</span>
      <h2 id="modal-title"></h2>
      <p id="modal-message"></p>
      <button onclick="closeModal()" class="modal-ok-btn">OK</button>
    </div>
  </div>

  <script>
    // Show loading on form submit
    function onSubmitForm(event) {
      if (!validateForm()) {
        event.preventDefault();
        return false;
      }
      document.getElementById('loading-overlay').style.display = 'flex';
      return true;
    }

    // Form validation
    function validateForm() {
      const username = document.querySelector('input[name="username"]').value.trim();
      const password = document.querySelector('input[name="password"]').value;

      if (username === '') {
        showModal('Please enter your username.');
        return false;
      }

      if (password === '') {
        showModal('Please enter your password.');
        return false;
      }

      if (username.length < 3) {
        showModal('Username must be at least 3 characters long.');
        return false;
      }

      return true;
    }

    // Toggle password visibility
    function togglePassword() {
      const passwordField = document.getElementById('password');
      const toggleIcon = document.getElementById('toggle-password');
      
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

    function showModal(message, type = "info") {
      const modal = document.getElementById('alertModal');
      const modalMessage = document.getElementById('modal-message');
      const modalTitle = document.getElementById('modal-title');
      
      // Reset theme classes
      modal.classList.remove("success", "info", "error");
      modal.classList.add(type);

      // Set title automatically based on type
      if (type === "success") modalTitle.textContent = "Success";
      else if (type === "info") modalTitle.textContent = "Information";
      else if (type === "error") modalTitle.textContent = "Error";

      modalMessage.textContent = message;
      modal.style.display = 'flex';
      // Ensure security alert modal has highest z-index
      if (type === "error" && message.includes("SECURITY ALERT")) {
        modal.style.zIndex = '9999999';
      } else {
        modal.style.zIndex = '9999';
      }
    }

    function closeModal() {
      const modal = document.getElementById('alertModal');
      if (modal) {
        modal.style.display = 'none';
      }
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('alertModal');
      if (event.target === modal) {
        closeModal();
      }
    }

    // Hide loading overlay when page loads
    window.addEventListener('load', () => {
      document.getElementById('loading-overlay').style.display = 'none';
    });

    // Auto-hide messages after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
      const successMsg = document.querySelector('.success-message');
      const infoMsg = document.querySelector('.info-message');
      
      if (successMsg) {
        setTimeout(() => {
          successMsg.style.opacity = '0';
          setTimeout(() => successMsg.style.display = 'none', 300);
        }, 5000);
      }
      
      if (infoMsg) {
        setTimeout(() => {
          infoMsg.style.opacity = '0';
          setTimeout(() => infoMsg.style.display = 'none', 300);
        }, 5000);
      }
    });
  </script>

  <?php if ($database_error): ?>
  <script>
    // Auto-check database status every 10 seconds
    let dbCheckInterval;
    
    function checkDatabaseStatus() {
      fetch('crud.php?action=check_session', {
        method: 'GET',
        credentials: 'same-origin'
      })
      .then(response => response.json())
      .then(data => {
        if (data.success || !data.database_error) {
          // Database is back online!
          showDatabaseRestoredMessage();
        }
      })
      .catch(error => {
        // Network error, continue checking
        console.log('Database check failed:', error);
      });
    }
    
    function showDatabaseRestoredMessage() {
      // Clear check interval
      clearInterval(dbCheckInterval);
      
      // Show success notification
      const successDiv = document.createElement('div');
      successDiv.style.cssText = `
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        padding: 15px 25px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        z-index: 9999;
        font-weight: 600;
        animation: slideDown 0.5s ease-out;
      `;
      successDiv.innerHTML = `
        <i class="fas fa-check-circle" style="margin-right: 8px;"></i>
        Database Restored! Refreshing page...
      `;
      
      // Add animation CSS
      const style = document.createElement('style');
      style.textContent = `
        @keyframes slideDown {
          from { transform: translateX(-50%) translateY(-100%); opacity: 0; }
          to { transform: translateX(-50%) translateY(0); opacity: 1; }
        }
      `;
      document.head.appendChild(style);
      document.body.appendChild(successDiv);
      
      // Hide database error banner
      const errorBanner = document.querySelector('.database-status-error');
      if (errorBanner) {
        errorBanner.style.display = 'none';
      }
      
      // Refresh page after 2 seconds
      setTimeout(() => {
        window.location.reload();
      }, 2000);
    }
    
    // Start checking after 5 seconds, then every 10 seconds
    setTimeout(() => {
      dbCheckInterval = setInterval(checkDatabaseStatus, 10000);
    }, 5000);
    
    // Also check when page becomes visible
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        checkDatabaseStatus();
      }
    });
  </script>
  <?php endif; ?>

  <?php if ($loginSuccess && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      <?php 
      $isViewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);
      $target = $isViewer ? 'department.php' : 'dashboard.php';
      ?>
      var target = '<?php echo $target; ?>';
      <?php if (isset($_SESSION['concurrent_login']) && $_SESSION['concurrent_login']): ?>
        showModal("Login successful! You have been logged out from other devices. Redirecting...", "success");
        <?php unset($_SESSION['concurrent_login']); ?>
      <?php else: ?>
        showModal("Login successful! Redirecting...", "success");
      <?php endif; ?>
      
      document.querySelector('.modal-ok-btn').onclick = function () {
        window.location.href = target;
      };
      setTimeout(function() {
        window.location.href = target;
      }, 2000);
    });
  </script>
  <?php endif; ?>

</body>
</html>
<?php
// Try to connect to database
$conn = @new mysqli('localhost', 'root', '', 'ocabis');

// Check if database connection failed
if ($conn->connect_error) {
    // Database is deleted or corrupted
    // Check user role before redirecting
    
    $current_file = basename($_SERVER['PHP_SELF'] ?? '');
    $current_path = $_SERVER['PHP_SELF'] ?? '';
    
    // Files that should NOT redirect
    $no_redirect_files = [
        'emergency_recovery.php',
        'emergency_login.php',
        'database_export.php',
        'login.php',
        'register.php',
        'database_down.php',
        'test_',
        'debug_'
    ];
    
    $should_redirect = true;
    
    foreach ($no_redirect_files as $no_redirect) {
        if (strpos($current_file, $no_redirect) !== false) {
            $should_redirect = false;
            break;
        }
    }
    
    // Don't redirect AJAX/API requests
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $should_redirect = false;
    }
    
    // Don't redirect API files (files ending with _api.php)
    if (strpos($current_file, '_api.php') !== false || strpos($current_file, 'api.php') !== false) {
        $should_redirect = false;
    }
    
    // Redirect based on user role
    if ($should_redirect) {
        // Check if user is super admin (from session)
        $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
        
        if ($is_super_admin) {
            // Super admin - redirect to emergency recovery
            $path_to_emergency = '';
            if (strpos($current_path, '/ocabis/') !== false) {
                $path_to_emergency = 'emergency_recovery.php';
            } else {
                $path_to_emergency = 'ocabis/emergency_recovery.php';
            }
            header("Location: " . $path_to_emergency);
            exit();
        } else {
            // Regular user - redirect to database down page
            $path_to_down = '';
            if (strpos($current_path, '/ocabis/') !== false) {
                $path_to_down = 'database_down.php';
            } else {
                $path_to_down = 'ocabis/database_down.php';
            }
            header("Location: " . $path_to_down);
            exit();
        }
    }
}
?>

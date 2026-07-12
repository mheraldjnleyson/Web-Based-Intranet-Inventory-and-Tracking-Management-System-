<?php
session_start();

// Suppress database connection errors
error_reporting(0);
ini_set('display_errors', 0);
mysqli_report(MYSQLI_REPORT_OFF);

// Database connection
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

// Deactivate session in database if user is logged in and database is connected
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    $sessionId = session_id();
    $userId = $_SESSION['user_id'];
    
    if (!$conn->connect_error) {
        try {
            $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_id = ? AND user_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $sessionId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            // Database error, continue with logout
        }
    }
}

if (!$conn->connect_error) {
    $conn->close();
}

// Clear all session variables
$_SESSION = [];

// If a session cookie exists, delete it too
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page with a query parameter
header("Location: login.php?logout=1");
exit();
?>

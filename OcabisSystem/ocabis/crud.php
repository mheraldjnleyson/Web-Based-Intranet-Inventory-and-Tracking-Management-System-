<?php
// =============================================
// Enhanced CRUD with QR Code + Centered Logo (ULTIMATE Color Fix)
// =============================================

session_start();
// Set timezone to Philippines (Asia/Manila) for accurate date/time
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Session validation function
function validateSession($conn) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        return false;
    }
    
    // Bypass database session checks for super admin accounts
    if (isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1) {
        // Optionally refresh activity timestamp in PHP session only
        $_SESSION['last_activity'] = time();
        return true;
    }
    
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
                
                // If account is locked, logout immediately
                if ((int)$lockData['account_locked'] === 1) {
                    // Deactivate all sessions for this user
                    $deactivateAll = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
                    $deactivateAll->bind_param("i", $userId);
                    $deactivateAll->execute();
                    $deactivateAll->close();
                    
                    $lockCheck->close();
                    
                    // Destroy session
                    session_destroy();
                    
                    // Return false and let caller handle redirect
                    // The caller should redirect to login.php?security_threat=1
                    return false;
                }
            }
            $lockCheck->close();
        }
    }
    
    // Check if session exists and is active
    $stmt = $conn->prepare("SELECT is_active FROM user_sessions WHERE session_id = ? AND user_id = ?");
    $stmt->bind_param("si", $sessionId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Session not found in database, invalidate
        session_destroy();
        return false;
    }
    
    $session = $result->fetch_assoc();
    if (!$session['is_active']) {
        // Session deactivated, logout user
        session_destroy();
        return false;
    }
    
    // Update last activity
    $updateStmt = $conn->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ?");
    $updateStmt->bind_param("s", $sessionId);
    $updateStmt->execute();
    $updateStmt->close();
    
    return true;
}

// Determine user context for department restrictions
$currentUser = isset($_SESSION['username']) ? $_SESSION['username'] : null;
$currentDepartment = isset($_SESSION['department']) ? $_SESSION['department'] : null;
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ocabis";

$conn = null;

/**
 * Ensure qr_requests table has item_id column for per-item QR requests.
 */
function ensureQrRequestsItemColumn($conn) {
    static $itemColumnChecked = false;
    if ($itemColumnChecked || !$conn) {
        return;
    }
    $tableExists = $conn->query("SHOW TABLES LIKE 'qr_requests'");
    if (!$tableExists || $tableExists->num_rows === 0) {
        return;
    }
    $checkItemColumn = $conn->query("SHOW COLUMNS FROM qr_requests LIKE 'item_id'");
    if ($checkItemColumn && $checkItemColumn->num_rows === 0) {
        $conn->query("ALTER TABLE qr_requests ADD COLUMN item_id INT(11) NULL AFTER item_table_id");
        $conn->query("ALTER TABLE qr_requests ADD INDEX idx_qr_requests_item_id (item_id)");
    }
    $itemColumnChecked = true;
}

try {
    $conn = @new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        // Check if user is super admin
        $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
        
        if ($is_super_admin) {
            echo json_encode([
                'success' => false, 
                'message' => 'Database connection failed. Please export database.', 
                'database_error' => true,
                'redirect_to' => 'emergency_recovery.php'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'System is currently under maintenance. Please try again later.', 
                'database_error' => true,
                'redirect_to' => 'database_down.php'
            ]);
        }
        exit;
    }
    
    $conn->set_charset("utf8");
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    
    // Check if user is super admin
    $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
    
    if ($is_super_admin) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database connection failed. Please export database.', 
            'database_error' => true,
            'redirect_to' => 'emergency_recovery.php'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'System is currently under maintenance. Please try again later.', 
            'database_error' => true,
            'redirect_to' => 'database_down.php'
        ]);
    }
    exit;
}

// Validate session for all requests except login and check_session
$action = $_GET['action'] ?? $_POST['action'] ?? '';
// Exclude borrow action from session validation since teachers (viewers) need to submit requests
if ($action !== 'get_categories' && 
    $action !== 'request_item' && 
    $action !== 'check_session' &&
    $action !== 'borrow' &&  // Allow borrow action without strict session validation
    !validateSession($conn)) {
    // Check if account was locked (validateSession checks this and destroys session)
    // If we reach here and user_id was set, check if it's due to account lock
    if (isset($_SESSION['user_id']) && $action !== 'check_session') {
        $checkLock = $conn->prepare("SELECT COALESCE(account_locked, 0) as account_locked FROM users WHERE id = ?");
        if ($checkLock) {
            $checkLock->bind_param("i", $_SESSION['user_id']);
            $checkLock->execute();
            $lockResult = $checkLock->get_result();
            if ($lockResult->num_rows === 1) {
                $lockData = $lockResult->fetch_assoc();
                if ((int)$lockData['account_locked'] === 1) {
                    $checkLock->close();
                    $conn->close();
                    // Return JSON response that indicates account lock
                    echo json_encode(['success' => false, 'error' => 'Account locked', 'account_locked' => true, 'redirect' => 'login.php']);
                    exit();
                }
            }
            $checkLock->close();
        }
    }
    
    // Ensure qr_requests has the latest schema (item-level support)
    ensureQrRequestsItemColumn($conn);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.', 'session_expired' => true]);
    exit;
}
// Resolve current user's department to an ID for reliable comparisons
$currentDepartmentId = null;
if (!$isAdmin && !empty($currentDepartment)) {
    $deptLookup = $conn->prepare("SELECT id FROM departments WHERE name = ? LIMIT 1");
    if ($deptLookup) {
        $deptLookup->bind_param("s", $currentDepartment);
        if ($deptLookup->execute()) {
            $deptRes = $deptLookup->get_result();
            if ($deptRes && $deptRes->num_rows > 0) {
                $row = $deptRes->fetch_assoc();
                $currentDepartmentId = (int)$row['id'];
            }
        }
        $deptLookup->close();
    }
}

function sanitizeInput($input) {
    if ($input === null) return '';
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Department color mapper (hex without #) with explicit name mapping
function getDepartmentColorHex($departmentId, $departmentName = null) {
    // Normalize department name: lowercase, trim, remove extra spaces
    $name = is_string($departmentName) ? preg_replace('/\s+/', ' ', strtolower(trim($departmentName))) : '';
    
    if ($name !== '') {
        // ICT Equipment - red
        if ($name === 'ict equipment' || strpos($name, 'ict') !== false && strpos($name, 'equipment') !== false) {
            error_log("getDepartmentColorHex: Matched 'ICT Equipment' -> C62828 (red)");
            return 'C62828';
        }
        // SLRC - blue
        if ($name === 'slrc' || strpos($name, 'student learning resource center') !== false || strpos($name, 'slrc') !== false) {
            error_log("getDepartmentColorHex: Matched 'SLRC' -> 1565C0 (blue)");
            return '1565C0';
        }
        // Science Equipment - yellow
        if ($name === 'science equipment' || (strpos($name, 'science') !== false && strpos($name, 'equipment') !== false)) {
            error_log("getDepartmentColorHex: Matched 'Science Equipment' -> F59E0B (yellow)");
            return 'F59E0B';
        }
        // SPS Equipment - green
        if ($name === 'sps equipment' || (strpos($name, 'sps') !== false && strpos($name, 'equipment') !== false)) {
            error_log("getDepartmentColorHex: Matched 'SPS Equipment' -> 2E7D32 (green)");
            return '2E7D32';
        }
        
        error_log("getDepartmentColorHex: No exact match for name: '$name', using fallback");
    }
    
    // Fallback deterministic palette by ID
    $palette = [
        '000000', '1F497D', '2E7D32', '7B1FA2', 'C62828', '1565C0', '00695C', '4E342E', '37474F', 'AD1457', '283593', '00838F'
    ];
    if (!is_numeric($departmentId) || $departmentId <= 0) {
        error_log("getDepartmentColorHex: Invalid department ID, using black");
        return $palette[0];
    }
    $index = ((int)$departmentId) % count($palette);
    $fallbackColor = $palette[$index];
    error_log("getDepartmentColorHex: Using fallback color from palette index $index: $fallbackColor for department ID: $departmentId");
    return $fallbackColor;
}

function generateAndSaveQRCodeSimple($itemId, $itemData) {
    global $conn;
    
    // Check if parent item table has approved QR request (for medium/high priority)
    if (isset($itemData['item_table_id']) && $itemData['item_table_id']) {
        $item_table_id = $itemData['item_table_id'];
        $checkTableSql = "SELECT it.priority, 
            (SELECT COUNT(*) FROM qr_requests qr WHERE qr.item_table_id = it.id AND qr.status = 'approved') as has_approved_qr,
            it.qr_code as table_has_qr
            FROM item_tables it WHERE it.id = ?";
        $checkTableStmt = $conn->prepare($checkTableSql);
        if ($checkTableStmt) {
            $checkTableStmt->bind_param("i", $item_table_id);
            $checkTableStmt->execute();
            $tableResult = $checkTableStmt->get_result();
            if ($tableRow = $tableResult->fetch_assoc()) {
                $priority = strtolower($tableRow['priority'] ?? 'low');
                $hasApprovedQr = (int)$tableRow['has_approved_qr'] > 0;
                $tableHasQr = !empty($tableRow['table_has_qr']);
                
                // For medium/high priority, require approved QR request
                if (in_array($priority, ['medium', 'high'])) {
                    if (!$hasApprovedQr && !$tableHasQr) {
                        error_log("BLOCKED QR generation in generateAndSaveQRCodeSimple: Item $itemId, Table $item_table_id (priority: $priority) - no approved QR request");
                        return [
                            'success' => false,
                            'error' => 'Parent item table QR request is pending approval. QR code will be generated after approval.',
                            'pending_approval' => true,
                            'blocked' => true
                        ];
                    }
                }
            } else {
                // Table not found - block QR generation for safety
                error_log("BLOCKED QR generation in generateAndSaveQRCodeSimple: Item $itemId, Table $item_table_id not found");
                return [
                    'success' => false,
                    'error' => 'Parent item table not found. Cannot generate QR code.',
                    'blocked' => true
                ];
            }
            $checkTableStmt->close();
        }
    }
    
    try {
        // Get the actual server URL instead of hardcoded localhost
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
        $qrData = $baseUrl . 'view_item.php?id=' . $itemId;
        
        $qrCodeFilename = 'qr_item_' . $itemId . '_' . time() . '.png';
        $qrCodePath = 'qr_codes/' . $qrCodeFilename;
        
        // Ensure qr_codes folder exists
        if (!file_exists('qr_codes')) {
            if (!mkdir('qr_codes', 0777, true)) {
                throw new Exception('Failed to create qr_codes folder');
            }
        }
        
        // Generate QR code URL with department-based color
        $deptId = $itemData['department_id'] ?? null;
        $deptName = $itemData['department_name'] ?? null;
        
        // Log for debugging
        error_log("QR Generation (Simple) - Item ID: $itemId, Department ID: $deptId, Name: " . ($deptName ?? 'NULL'));
        
        $fgColor = getDepartmentColorHex($deptId, $deptName);
        
        // Ensure color is properly formatted (hex without #)
        $fgColor = strtoupper(ltrim($fgColor, '#'));
        
        // Log the color being used
        error_log("QR Generation (Simple) - Using color: $fgColor for department: $deptName");
        
        // Log the final API URL for debugging (without the data parameter for security)
        error_log("QR Generation (Simple) - API URL (partial): https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=" . $fgColor . "&bgcolor=FFFFFF");
        
        $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=' . $fgColor . '&bgcolor=FFFFFF&data=' . urlencode($qrData);
        
        // Download QR code directly
        $qrImage = @file_get_contents($qrApiUrl);
        
        if ($qrImage === false) {
            throw new Exception('Failed to download QR code from API');
        }
        
        // Check if GD extension is available for image processing
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            // Fallback: Save QR code without text overlay for now
            // The text will be added client-side when downloading
            if (file_put_contents($qrCodePath, $qrImage) === false) {
                throw new Exception('Failed to save QR code file');
            }
        } else {
            // Create composite image with QR code and item code text
            $qrResource = imagecreatefromstring($qrImage);
            if ($qrResource === false) {
                throw new Exception('Failed to create QR image resource');
            }
            
            // Get item code for display
            $itemCode = $itemData['item_code'] ?? 'ITEM-' . $itemId;
            
            // Create a larger canvas to accommodate text below QR code
            $qrWidth = imagesx($qrResource);
            $qrHeight = imagesy($qrResource);
            $textHeight = 40; // Space for text below QR code
            $canvasWidth = $qrWidth;
            $canvasHeight = $qrHeight + $textHeight;
            
            // Create new canvas
            $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            $black = imagecolorallocate($canvas, 0, 0, 0);
            
            // Fill canvas with white background
            imagefill($canvas, 0, 0, $white);
            
            // Copy QR code to top of canvas
            imagecopy($canvas, $qrResource, 0, 0, 0, 0, $qrWidth, $qrHeight);
            
            // Add item code text below QR code
            $fontSize = 5; // Built-in font size
            $textWidth = strlen($itemCode) * imagefontwidth($fontSize);
            $textX = ($canvasWidth - $textWidth) / 2; // Center the text
            $textY = $qrHeight + 5; // Position below QR code
            
            imagestring($canvas, $fontSize, $textX, $textY, $itemCode, $black);
            
            // Save the composite image
            if (imagepng($canvas, $qrCodePath, 0) === false) {
                throw new Exception('Failed to save composite QR code file');
            }
            
            // Clean up resources
            imagedestroy($qrResource);
            imagedestroy($canvas);
        }
        
        // Update database with QR code path
        global $conn;
        $stmt = $conn->prepare("UPDATE items SET qr_code = ? WHERE id = ?");
        $stmt->bind_param("si", $qrCodePath, $itemId);
        $stmt->execute();
        $stmt->close();
        
        return [
            'success' => true,
            'qr_path' => $qrCodePath,
            'qr_url' => $qrApiUrl,
            'method' => 'composite_with_text'
        ];
        
    } catch (Exception $e) {
        error_log("Simple QR Code generation error: " . $e->getMessage());
        return [
            'success' => false, 
            'error' => 'Failed to generate QR code: ' . $e->getMessage(),
            'method' => 'composite_with_text'
        ];
    }
}

function generateAndSaveQRCode($conn, $itemId, $itemData) {
    $debugInfo = [];
    
    // Check if parent item table has approved QR request (for medium/high priority)
    if (isset($itemData['item_table_id']) && $itemData['item_table_id']) {
        $item_table_id = $itemData['item_table_id'];
        $checkTableSql = "SELECT it.priority, 
            (SELECT COUNT(*) FROM qr_requests qr WHERE qr.item_table_id = it.id AND qr.status = 'approved') as has_approved_qr,
            it.qr_code as table_has_qr
            FROM item_tables it WHERE it.id = ?";
        $checkTableStmt = $conn->prepare($checkTableSql);
        if ($checkTableStmt) {
            $checkTableStmt->bind_param("i", $item_table_id);
            $checkTableStmt->execute();
            $tableResult = $checkTableStmt->get_result();
            if ($tableRow = $tableResult->fetch_assoc()) {
                $priority = strtolower($tableRow['priority'] ?? 'low');
                $hasApprovedQr = (int)$tableRow['has_approved_qr'] > 0;
                $tableHasQr = !empty($tableRow['table_has_qr']);
                
                // For medium/high priority, require approved QR request
                if (in_array($priority, ['medium', 'high'])) {
                    if (!$hasApprovedQr && !$tableHasQr) {
                        error_log("BLOCKED QR generation in generateAndSaveQRCode: Item $itemId, Table $item_table_id (priority: $priority) - no approved QR request");
                        return [
                            'success' => false,
                            'error' => 'Parent item table QR request is pending approval. QR code will be generated after approval.',
                            'pending_approval' => true,
                            'blocked' => true
                        ];
                    }
                }
            } else {
                // Table not found - block QR generation for safety
                error_log("BLOCKED QR generation in generateAndSaveQRCode: Item $itemId, Table $item_table_id not found");
                return [
                    'success' => false,
                    'error' => 'Parent item table not found. Cannot generate QR code.',
                    'blocked' => true
                ];
            }
            $checkTableStmt->close();
        }
    }
    
    // Check if GD extension is available
    if (!extension_loaded('gd')) {
        // Fallback: Use simple file download method
        return generateAndSaveQRCodeSimple($itemId, $itemData);
    }
    
    if (!function_exists('imagecreatefromstring')) {
        // Fallback: Use simple file download method
        return generateAndSaveQRCodeSimple($itemId, $itemData);
    }
    
    try {
        // Get the actual server URL instead of hardcoded localhost
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
        $qrData = $baseUrl . 'view_item.php?id=' . $itemId;
        
        $qrCodeFilename = 'qr_item_' . $itemId . '_' . time() . '.png';
        $qrCodePath = 'qr_codes/' . $qrCodeFilename;
        
        if (!file_exists('qr_codes')) {
            if (mkdir('qr_codes', 0777, true)) {
                $debugInfo['qr_folder_created'] = true;
                error_log("QR codes folder created successfully");
            } else {
                $debugInfo['qr_folder_created'] = false;
                error_log("Failed to create QR codes folder");
            }
        } else {
            $debugInfo['qr_folder_exists'] = true;
        }
        
        // Generate QR code with high error correction and department-based color
        $deptId = $itemData['department_id'] ?? null;
        $deptName = $itemData['department_name'] ?? null;
        
        // Log for debugging
        error_log("QR Generation (Full) - Item ID: $itemId, Department ID: $deptId, Name: " . ($deptName ?? 'NULL'));
        
        $fgColor = getDepartmentColorHex($deptId, $deptName);
        
        // Ensure color is properly formatted (hex without #)
        $fgColor = strtoupper(ltrim($fgColor, '#'));
        
        // Log the color being used
        error_log("QR Generation (Full) - Using color: $fgColor for department: $deptName");
        
        // Log the final API URL for debugging (without the data parameter for security)
        error_log("QR Generation (Full) - API URL (partial): https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=" . $fgColor . "&bgcolor=FFFFFF");
        
        $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=' . $fgColor . '&bgcolor=FFFFFF&data=' . urlencode($qrData);
        
        $qrImage = @file_get_contents($qrApiUrl);
        
        if ($qrImage === false) {
            throw new Exception('Failed to generate QR code from API');
        }
        
        $debugInfo['qr_downloaded'] = true;
        
        $qrResource = imagecreatefromstring($qrImage);
        
        if ($qrResource === false) {
            throw new Exception('Failed to create QR image resource');
        }
        
        $debugInfo['qr_resource_created'] = true;
        
        // Try multiple possible logo paths
        $possibleLogoPaths = [
            __DIR__ . '/assets/logo.png',
            __DIR__ . '/assets/logo.jpg',
            __DIR__ . '/assets/logo.jpeg',
            'assets/logo.png',
            'assets/logo.jpg',
            '../assets/logo.png',
            './logo.png'
        ];
        
        $logoPath = null;
        foreach ($possibleLogoPaths as $path) {
            if (file_exists($path)) {
                $logoPath = $path;
                break;
            }
        }
        
        $debugInfo['logo_found'] = $logoPath !== null;
        
        if ($logoPath !== null) {
            $debugInfo['logo_path'] = $logoPath;
            $debugInfo['logo_real_path'] = realpath($logoPath);
            
            $logoInfo = @getimagesize($logoPath);
            
            if ($logoInfo !== false) {
                $debugInfo['logo_dimensions'] = $logoInfo[0] . 'x' . $logoInfo[1];
                $debugInfo['logo_mime'] = $logoInfo['mime'];
                
                $logoMime = $logoInfo['mime'];
                
                $logo = null;
                switch ($logoMime) {
                    case 'image/png':
                        $logo = @imagecreatefrompng($logoPath);
                        $debugInfo['logo_type'] = 'PNG';
                        break;
                    case 'image/jpeg':
                    case 'image/jpg':
                        $logo = @imagecreatefromjpeg($logoPath);
                        $debugInfo['logo_type'] = 'JPEG';
                        break;
                    case 'image/gif':
                        $logo = @imagecreatefromgif($logoPath);
                        $debugInfo['logo_type'] = 'GIF';
                        break;
                    default:
                        $debugInfo['logo_unsupported_type'] = $logoMime;
                }
                
                if ($logo !== null && $logo !== false) {
                    $debugInfo['logo_resource_created'] = true;
                    
                    $qrWidth = imagesx($qrResource);
                    $qrHeight = imagesy($qrResource);
                    $logoWidth = imagesx($logo);
                    $logoHeight = imagesy($logo);
                    
                    $debugInfo['qr_dimensions'] = $qrWidth . 'x' . $qrHeight;
                    $debugInfo['logo_original_size'] = $logoWidth . 'x' . $logoHeight;
                    
                    $logoQrWidth = intval($qrWidth / 6.5);
                    $logoQrHeight = intval($logoHeight * ($logoQrWidth / $logoWidth));
                    
                    $debugInfo['logo_scaled_size'] = $logoQrWidth . 'x' . $logoQrHeight;
                    
                    $logoX = intval(($qrWidth - $logoQrWidth) / 2);
                    $logoY = intval(($qrHeight - $logoQrHeight) / 2);
                    
                    $debugInfo['logo_position'] = 'center_of_qr';
                    $debugInfo['logo_coordinates'] = $logoX . ',' . $logoY;
                    
                    // Create TRUE COLOR resized logo
                    $logoResized = imagecreatetruecolor($logoQrWidth, $logoQrHeight);
                    
                    imagealphablending($logoResized, false);
                    $transparent = imagecolorallocatealpha($logoResized, 0, 0, 0, 127);
                    imagefill($logoResized, 0, 0, $transparent);
                    imagesavealpha($logoResized, true);
                    imagealphablending($logoResized, true);
                    
                    if (function_exists('imagesetinterpolation')) {
                        imagesetinterpolation($logoResized, IMG_BICUBIC);
                        imagesetinterpolation($logo, IMG_BICUBIC);
                    }
                    
                    imagecopyresampled(
                        $logoResized,
                        $logo,
                        0, 0, 0, 0,
                        $logoQrWidth,
                        $logoQrHeight,
                        $logoWidth,
                        $logoHeight
                    );
                    
                    $debugInfo['logo_resized'] = true;
                    
                    imagealphablending($qrResource, true);
                    imagesavealpha($qrResource, true);
                    
                    $white = imagecolorallocate($qrResource, 255, 255, 255);
                    $padding = 12;
                    
                    imagefilledrectangle(
                        $qrResource,
                        $logoX - $padding,
                        $logoY - $padding,
                        $logoX + $logoQrWidth + $padding,
                        $logoY + $logoQrHeight + $padding,
                        $white
                    );
                    
                    $borderColor = imagecolorallocate($qrResource, 200, 200, 200);
                    imagerectangle(
                        $qrResource,
                        $logoX - $padding,
                        $logoY - $padding,
                        $logoX + $logoQrWidth + $padding,
                        $logoY + $logoQrHeight + $padding,
                        $borderColor
                    );
                    
                    imagecopy(
                        $qrResource,
                        $logoResized,
                        $logoX,
                        $logoY,
                        0,
                        0,
                        $logoQrWidth,
                        $logoQrHeight
                    );
                    
                    $debugInfo['logo_placed'] = 'SUCCESS - COLORS PRESERVED';
                    
                    imagedestroy($logo);
                    imagedestroy($logoResized);
                    
                } else {
                    $debugInfo['logo_resource_failed'] = true;
                    error_log("Failed to create logo resource from: " . $logoPath);
                }
                
            } else {
                $debugInfo['logo_getimagesize_failed'] = true;
                error_log("getimagesize failed for: " . $logoPath);
            }
            
        } else {
            $debugInfo['logo_not_found'] = 'No logo found in searched paths';
            error_log("Logo file not found. Searched paths: " . implode(', ', $possibleLogoPaths));
        }
        
        // Get item code for display
        $itemCode = $itemData['item_code'] ?? 'ITEM-' . $itemId;
        
        // Create a larger canvas to accommodate text below QR code
        $qrWidth = imagesx($qrResource);
        $qrHeight = imagesy($qrResource);
        $textHeight = 40; // Space for text below QR code
        $canvasWidth = $qrWidth;
        $canvasHeight = $qrHeight + $textHeight;
        
        // Create new canvas for composite image
        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $black = imagecolorallocate($canvas, 0, 0, 0);
        
        // Fill canvas with white background
        imagefill($canvas, 0, 0, $white);
        
        // Copy QR code to top of canvas
        imagecopy($canvas, $qrResource, 0, 0, 0, 0, $qrWidth, $qrHeight);
        
        // Add item code text below QR code
        $fontSize = 5; // Built-in font size
        $textWidth = strlen($itemCode) * imagefontwidth($fontSize);
        $textX = ($canvasWidth - $textWidth) / 2; // Center the text
        $textY = $qrHeight + 5; // Position below QR code
        
        imagestring($canvas, $fontSize, $textX, $textY, $itemCode, $black);
        
        // Save the composite image
        $saveResult = imagepng($canvas, $qrCodePath, 0);
        $debugInfo['qr_saved'] = $saveResult ? 'SUCCESS' : 'FAILED';
        $debugInfo['composite_with_text'] = true;
        $debugInfo['item_code_displayed'] = $itemCode;
        
        if ($saveResult && file_exists($qrCodePath)) {
            $debugInfo['qr_file_size'] = filesize($qrCodePath) . ' bytes';
            $debugInfo['qr_full_path'] = realpath($qrCodePath);
            error_log("QR code with text saved successfully: " . $qrCodePath);
        } else {
            $debugInfo['qr_save_error'] = 'Failed to save composite QR code file';
            error_log("Failed to save composite QR code: " . $qrCodePath);
        }
        
        // Clean up resources
        imagedestroy($qrResource);
        imagedestroy($canvas);
        
        $stmt = $conn->prepare("UPDATE items SET qr_code = ? WHERE id = ?");
        $stmt->bind_param("si", $qrCodePath, $itemId);
        $stmt->execute();
        $stmt->close();
        
        error_log("QR Code Debug Info for Item $itemId: " . json_encode($debugInfo));
        
        return [
            'success' => true,
            'qr_path' => $qrCodePath,
            'qr_url' => $qrApiUrl,
            'debug' => $debugInfo
        ];
        
    } catch (Exception $e) {
        error_log("QR Code generation error: " . $e->getMessage());
        return [
            'success' => false, 
            'error' => 'Failed to generate QR code: ' . $e->getMessage(),
            'debug' => $debugInfo ?? []
        ];
    }
}

function ensureBorrowHistoryTable($conn) {
    $check_table = "SHOW TABLES LIKE 'borrow_history'";
    $table_result = $conn->query($check_table);
    
    if ($table_result && $table_result->num_rows === 0) {
        $create_table = "CREATE TABLE `borrow_history` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `borrow_id` varchar(50) NOT NULL,
            `borrower_name` varchar(255) NOT NULL,
            `borrower_email` varchar(255) NOT NULL,
            `item_id` int(11) NOT NULL,
            `item_name` varchar(255) NOT NULL,
            `department_name` varchar(255) NOT NULL,
            `category` varchar(100) NOT NULL,
            `quantity_borrowed` int(11) NOT NULL,
            `borrow_date` date NOT NULL,
            `due_date` date NOT NULL,
            `return_date` date DEFAULT NULL,
            `status` enum('pending','active','returned','overdue','declined') NOT NULL DEFAULT 'pending',
            `priority` enum('low','medium','high') NOT NULL DEFAULT 'low',
            `purpose` text,
            `notes` text,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `borrow_id` (`borrow_id`),
            KEY `item_id` (`item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        
        if (!$conn->query($create_table)) {
            throw new Exception("Failed to create borrow_history table: " . $conn->error);
        }
    } else {
        // Check if borrower_contact column exists and rename it to borrower_email
        $check_column = "SHOW COLUMNS FROM borrow_history LIKE 'borrower_contact'";
        $column_result = $conn->query($check_column);
        
        if ($column_result && $column_result->num_rows > 0) {
            $alter_table = "ALTER TABLE borrow_history CHANGE borrower_contact borrower_email varchar(255) NOT NULL";
            if (!$conn->query($alter_table)) {
                error_log("Failed to rename borrower_contact to borrower_email: " . $conn->error);
            }
        }
    }
}

function ensureArchivedItemsTable($conn) {
    $check_table = "SHOW TABLES LIKE 'archived_items'";
    $table_result = $conn->query($check_table);
    
    if ($table_result && $table_result->num_rows === 0) {
        $create_table = "CREATE TABLE `archived_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `item_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `department_id` int(11) DEFAULT NULL,
            `department_name` varchar(255) DEFAULT NULL,
            `category` varchar(100) DEFAULT NULL,
            `quantity` int(11) DEFAULT NULL,
            `location` varchar(255) DEFAULT NULL,
            `status` varchar(50) DEFAULT NULL,
            `description` text,
            `archived_by` varchar(255) DEFAULT NULL,
            `archived_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `item_id` (`item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        
        if (!$conn->query($create_table)) {
            throw new Exception("Failed to create archived_items table: " . $conn->error);
        }
    }
}

function ensureItemRequestsTable($conn) {
    $check_table = "SHOW TABLES LIKE 'item_requests'";
    $table_result = $conn->query($check_table);
    if ($table_result && $table_result->num_rows === 0) {
        $create_table = "CREATE TABLE `item_requests` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `requested_by` varchar(255) NOT NULL,
            `department_name` varchar(255) NOT NULL,
            `item_name` varchar(255) NOT NULL,
            `category` varchar(100) DEFAULT NULL,
            `quantity` int(11) NOT NULL DEFAULT 1,
            `notes` text,
            `date_needed` date DEFAULT NULL,
            `status` enum('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        
        if (!$conn->query($create_table)) {
            throw new Exception("Failed to create item_requests table: " . $conn->error);
        }
    } else {
        // Table exists, check if date_needed column exists
        $check_column = $conn->query("SHOW COLUMNS FROM item_requests LIKE 'date_needed'");
        if ($check_column && $check_column->num_rows == 0) {
            $conn->query("ALTER TABLE item_requests ADD COLUMN date_needed date DEFAULT NULL AFTER notes");
        }
    }
}

ensureBorrowHistoryTable($conn);
ensureArchivedItemsTable($conn);
ensureItemRequestsTable($conn);

// Ensure items table has item_code field
function ensureItemCodeField($conn) {
    // Check if item_code column exists
    $check_column = "SHOW COLUMNS FROM items LIKE 'item_code'";
    $column_result = $conn->query($check_column);
    
    if ($column_result && $column_result->num_rows === 0) {
        // Add item_code column
        $alter_table = "ALTER TABLE items ADD COLUMN item_code VARCHAR(50) UNIQUE AFTER id";
        if (!$conn->query($alter_table)) {
            error_log("Failed to add item_code column: " . $conn->error);
        }
    }
}

ensureItemCodeField($conn);

// Ensure items table has item_table_id field
function ensureItemTableIdField($conn) {
    // Check if item_table_id column exists
    $check_column = "SHOW COLUMNS FROM items LIKE 'item_table_id'";
    $column_result = $conn->query($check_column);
    
    if ($column_result && $column_result->num_rows === 0) {
        // Add item_table_id column
        $alter_table = "ALTER TABLE items ADD COLUMN item_table_id INT(11) DEFAULT NULL AFTER item_code";
        if (!$conn->query($alter_table)) {
            error_log("Failed to add item_table_id column: " . $conn->error);
        }
    }
}

ensureItemTableIdField($conn);

// Ensure archived_items table has item_code and item_table_id fields
function ensureArchivedItemsFields($conn) {
    // Check if item_code column exists in archived_items
    $check_item_code = "SHOW COLUMNS FROM archived_items LIKE 'item_code'";
    $item_code_result = $conn->query($check_item_code);
    
    if ($item_code_result && $item_code_result->num_rows === 0) {
        // Add item_code column
        $alter_table = "ALTER TABLE archived_items ADD COLUMN item_code VARCHAR(50) DEFAULT NULL AFTER description";
        if (!$conn->query($alter_table)) {
            error_log("Failed to add item_code column to archived_items: " . $conn->error);
        }
    }
    
    // Check if item_table_id column exists in archived_items
    $check_item_table_id = "SHOW COLUMNS FROM archived_items LIKE 'item_table_id'";
    $item_table_id_result = $conn->query($check_item_table_id);
    
    if ($item_table_id_result && $item_table_id_result->num_rows === 0) {
        // Add item_table_id column
        $alter_table = "ALTER TABLE archived_items ADD COLUMN item_table_id INT(11) DEFAULT NULL AFTER item_code";
        if (!$conn->query($alter_table)) {
            error_log("Failed to add item_table_id column to archived_items: " . $conn->error);
        }
    }
}

ensureArchivedItemsFields($conn);

// Function to generate unique item code
function generateUniqueItemCode($conn, $name, $department_id, $category) {
    // Base parts: first 3 letters of name, first 2 letters of category
    $prefix_name = strtoupper(preg_replace('/[^A-Z]/', '', substr($name, 0, 3)));
    $prefix_cat = strtoupper(preg_replace('/[^A-Z]/', '', substr($category, 0, 2)));
    if ($prefix_name === '') { $prefix_name = 'ITM'; }
    if ($prefix_cat === '') { $prefix_cat = 'GN'; }

    // 12-13 digit time component (milliseconds) for compact numeric middle
    $millis = (int)round(microtime(true) * 1000);

    // Build code like MON-CO-705632441947
    $candidate = $prefix_name . '-' . $prefix_cat . '-' . $millis;

    // Ensure uniqueness; if collision, append 2 random digits
    $check_sql = "SELECT id FROM items WHERE item_code = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_sql);
    if ($check_stmt) {
        $final_code = $candidate;
        $attempts = 0;
        do {
            $probe = $final_code;
            $check_stmt->bind_param('s', $probe);
            $check_stmt->execute();
            $res = $check_stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $attempts++;
                $final_code = $candidate . substr((string)rand(10, 99), -2);
            } else {
                break;
            }
        } while ($attempts < 3);
        $check_stmt->close();
        return $final_code;
    }

    return $candidate;
}

function buildItemCodeFromBarcode($name, $category, $barcodeRaw) {
    $prefix_name = strtoupper(preg_replace('/[^A-Z]/', '', substr($name, 0, 3)));
    $prefix_cat = strtoupper(preg_replace('/[^A-Z]/', '', substr($category, 0, 2)));
    if ($prefix_name === '') { $prefix_name = 'ITM'; }
    if ($prefix_cat === '') { $prefix_cat = 'GN'; }
    $barcode = preg_replace('/[^A-Z0-9]/i', '', (string)$barcodeRaw);
    return $prefix_name . '-' . $prefix_cat . '-' . $barcode;
}

$action = '';
if (isset($_GET['action'])) {
    $action = $_GET['action'];
} elseif (isset($_POST['action'])) {
    $action = $_POST['action'];
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = 'get';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['id']) && !empty($_POST['id'])) {
            if (isset($_POST['_method']) && $_POST['_method'] === 'DELETE') {
                $action = 'delete';
            } else {
                $action = 'update';
            }
        } else {
            $action = 'add';
        }
    }
}

try {
    // Debug endpoint to test JSON response
    if ($action === 'test') {
        echo json_encode([
            'success' => true,
            'message' => 'CRUD endpoint is working',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    
    // Debug endpoint to test database connection
    if ($action === 'test_db') {
        try {
            $test_query = "SELECT COUNT(*) as item_count FROM items";
            $test_result = $conn->query($test_query);
            if ($test_result) {
                $count = $test_result->fetch_assoc()['item_count'];
                echo json_encode([
                    'success' => true,
                    'message' => 'Database connection working',
                    'item_count' => $count,
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Database query failed: ' . $conn->error
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    switch ($action) {
        case 'get':
            error_log("GET request received - loading items");
            
            // Get current user's email to check for pending requests
            $current_user_email = '';
            $current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            if ($current_user_id > 0) {
                $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
                try {
                    if ($is_super_admin) {
                        $email_stmt = $conn->prepare("SELECT email FROM super_admin WHERE id = ?");
                    } else {
                        $email_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
                    }
                    $email_stmt->bind_param("i", $current_user_id);
                    $email_stmt->execute();
                    $email_result = $email_stmt->get_result();
                    if ($email_result && $email_row = $email_result->fetch_assoc()) {
                        $current_user_email = $email_row['email'] ?? '';
                    }
                    $email_stmt->close();
                } catch (Exception $e) {
                    error_log("Error fetching user email: " . $e->getMessage());
                }
            }
            
            // Return all items; UI will restrict editing and backend already enforces permissions for writes
            $sql = "SELECT 
                            i.id,
                            i.item_code,
                            i.name,
                            i.department_id,
                            d.name as department_name,
                            i.category,
                            i.quantity,
                            i.location,
                            i.status,
                            i.description,
                            i.image_path,
                            i.qr_code,
                            i.item_table_id,
                            i.created_at,
                            i.updated_at,
                            it.table_image_path,
                            CASE 
                                WHEN EXISTS (
                                    SELECT 1 FROM borrow_history bh 
                                    WHERE bh.item_id = i.id 
                                    AND bh.status IN ('approved', 'active', 'overdue', 'received')
                                ) THEN 'Borrowed'
                                WHEN EXISTS (
                                    SELECT 1 FROM item_tables it 
                                    WHERE it.id = i.item_table_id 
                                    AND COALESCE(it.is_consumable, 0) = 1
                                ) THEN 'Consumable'
                                ELSE COALESCE(i.status, 'Working')
                            END as display_status,
                            CASE 
                                WHEN ? != '' AND EXISTS (
                                    SELECT 1 FROM borrow_history bh 
                                    WHERE bh.item_id = i.id 
                                    AND bh.borrower_email = ?
                                    AND bh.status = 'pending'
                                ) THEN 1
                                ELSE 0
                            END as has_pending_request
                        FROM items i 
                        LEFT JOIN departments d ON i.department_id = d.id 
                        LEFT JOIN item_tables it ON i.item_table_id = it.id
                        ORDER BY i.updated_at DESC";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ss", $current_user_email, $current_user_email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                $items = [];
                while ($row = $result->fetch_assoc()) {
                    $items[] = [
                        'id' => (int)$row['id'],
                        'item_code' => $row['item_code'],
                        'name' => $row['name'],
                        'department_id' => (int)$row['department_id'],
                        'department_name' => $row['department_name'],
                        'category' => $row['category'],
                        'quantity' => (int)$row['quantity'],
                        'location' => $row['location'],
                        'status' => $row['status'],
                        'display_status' => $row['display_status'],
                        'description' => $row['description'],
                        'image_path' => $row['image_path'],
                        'qr_code' => $row['qr_code'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                        'table_image_path' => $row['table_image_path'],
                        'item_table_id' => isset($row['item_table_id']) ? (int)$row['item_table_id'] : null,
                        'has_pending_request' => (int)$row['has_pending_request']
                    ];
                }
                
                error_log("Found " . count($items) . " items");
                echo json_encode([
                    'success' => true,
                    'items' => $items
                ]);
            } else {
                error_log("Query failed: " . $conn->error);
                throw new Exception("Query failed: " . $conn->error);
            }
            break;

        case 'get_archived':
            if (!$isSuperAdmin && !empty($currentDepartment)) {
                $sql = "SELECT 
                            ai.id,
                            ai.item_id,
                            ai.name,
                            ai.department_id,
                            ai.department_name,
                            ai.category,
                            ai.quantity,
                            ai.location,
                            ai.status,
                            ai.description,
                            ai.item_code,
                            ai.item_table_id,
                            ai.archived_by,
                            ai.archived_at,
                            ai.image_path,
                            it.table_name as item_table_name,
                            it.table_image_path
                        FROM archived_items ai
                        LEFT JOIN item_tables it ON ai.item_table_id = it.id
                        WHERE ai.department_name = ?
                        ORDER BY ai.archived_at DESC";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $conn->error);
                }
                $stmt->bind_param('s', $currentDepartment);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $sql = "SELECT 
                            ai.id,
                            ai.item_id,
                            ai.name,
                            ai.department_id,
                            ai.department_name,
                            ai.category,
                            ai.quantity,
                            ai.location,
                            ai.status,
                            ai.description,
                            ai.item_code,
                            ai.item_table_id,
                            ai.archived_by,
                            ai.archived_at,
                            ai.image_path,
                            it.table_name as item_table_name,
                            it.table_image_path
                        FROM archived_items ai
                        LEFT JOIN item_tables it ON ai.item_table_id = it.id
                        ORDER BY ai.archived_at DESC";
                $result = $conn->query($sql);
            }
            if ($result) {
                $items = [];
                while ($row = $result->fetch_assoc()) {
                    $items[] = [
                        'id' => (int)$row['id'],
                        'item_id' => (int)$row['item_id'],
                        'name' => $row['name'],
                        'department_id' => isset($row['department_id']) ? (int)$row['department_id'] : null,
                        'department_name' => $row['department_name'],
                        'category' => $row['category'],
                        'quantity' => isset($row['quantity']) ? (int)$row['quantity'] : null,
                        'location' => $row['location'],
                        'status' => $row['status'],
                        'description' => $row['description'],
                        'item_code' => $row['item_code'],
                        'item_table_id' => isset($row['item_table_id']) ? (int)$row['item_table_id'] : null,
                        'item_table_name' => $row['item_table_name'],
                        'image_path' => $row['image_path'],
                        'table_image_path' => $row['table_image_path'],
                        'archived_by' => $row['archived_by'],
                        'archived_at' => $row['archived_at']
                    ];
                }
                echo json_encode(['success' => true, 'items' => $items]);
            } else {
                throw new Exception('Query failed: ' . $conn->error);
            }
            break;

        case 'get_archived_categories':
            // Check if department_id and department_name columns exist in archived_categories
            $check_columns = $conn->query("SHOW COLUMNS FROM archived_categories LIKE 'department_id'");
            $has_department_id = ($check_columns && $check_columns->num_rows > 0);
            
            if (!$isSuperAdmin && !empty($currentDepartment)) {
                if ($has_department_id) {
                    // Filter by department_id if column exists
                    $dept_sql = "SELECT id FROM departments WHERE name = ?";
                    $dept_stmt = $conn->prepare($dept_sql);
                    $dept_stmt->bind_param('s', $currentDepartment);
                    $dept_stmt->execute();
                    $dept_result = $dept_stmt->get_result();
                    if ($dept_result->num_rows > 0) {
                        $dept_row = $dept_result->fetch_assoc();
                        $dept_id = (int)$dept_row['id'];
                        $dept_stmt->close();
                        
                        $sql = "SELECT 
                                    ac.id,
                                    ac.category_id,
                                    ac.name,
                                    ac.account,
                                    ac.department_id,
                                    ac.department_name,
                                    ac.archived_by,
                                    ac.archived_at
                                FROM archived_categories ac
                                WHERE ac.department_id = ?
                                ORDER BY ac.archived_at DESC";
                        $stmt = $conn->prepare($sql);
                        if (!$stmt) {
                            throw new Exception('Prepare failed: ' . $conn->error);
                        }
                        $stmt->bind_param('i', $dept_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                    } else {
                        $dept_stmt->close();
                        // No department found, return empty result
                        $result = false;
                    }
                } else {
                    // If department columns don't exist, return empty for non-super-admins
                    // (they can't see archived categories without department info)
                    $result = false;
                }
            } else {
                // Super admin or no department restriction - show all
                if ($has_department_id) {
                    $sql = "SELECT 
                                ac.id,
                                ac.category_id,
                                ac.name,
                                ac.account,
                                ac.department_id,
                                ac.department_name,
                                ac.archived_by,
                                ac.archived_at
                            FROM archived_categories ac
                            ORDER BY ac.archived_at DESC";
                } else {
                    $sql = "SELECT 
                                ac.id,
                                ac.category_id,
                                ac.name,
                                ac.account,
                                ac.archived_by,
                                ac.archived_at
                            FROM archived_categories ac
                            ORDER BY ac.archived_at DESC";
                }
                $result = $conn->query($sql);
            }
            
            if ($result) {
                $categories = [];
                while ($row = $result->fetch_assoc()) {
                    $category = [
                        'id' => (int)$row['id'],
                        'category_id' => isset($row['category_id']) ? (int)$row['category_id'] : null,
                        'name' => $row['name'],
                        'account' => $row['account'],
                        'archived_by' => $row['archived_by'],
                        'archived_at' => $row['archived_at']
                    ];
                    if ($has_department_id) {
                        $category['department_id'] = isset($row['department_id']) ? (int)$row['department_id'] : null;
                        $category['department_name'] = $row['department_name'] ?? null;
                    }
                    $categories[] = $category;
                }
                echo json_encode(['success' => true, 'categories' => $categories]);
            } else {
                echo json_encode(['success' => true, 'categories' => []]);
            }
            break;

        case 'get_item_requests':
            if (!$isAdmin) {
                throw new Exception('Unauthorized');
            }
            $status = isset($_GET['status']) ? $_GET['status'] : 'pending';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            // Get total count
            $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM item_requests WHERE status = ?");
            $countStmt->bind_param('s', $status);
            $countStmt->execute();
            $countResult = $countStmt->get_result();
            $totalCount = $countResult->fetch_assoc()['total'];
            $countStmt->close();
            
            // Get paginated results
            $stmt = $conn->prepare("SELECT id, requested_by, department_name, item_name, category, quantity, notes, date_needed, status, created_at, updated_at FROM item_requests WHERE status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param('sii', $status, $limit, $offset);
            $stmt->execute();
            $res = $stmt->get_result();
            $requests = [];
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['quantity'] = (int)$row['quantity'];
                // Ensure date_needed is properly formatted or null
                if (isset($row['date_needed']) && $row['date_needed']) {
                    $row['date_needed'] = $row['date_needed'];
                } else {
                    $row['date_needed'] = null;
                }
                $requests[] = $row;
            }
            $stmt->close();
            
            $totalPages = ceil($totalCount / $limit);
            echo json_encode([
                'success' => true, 
                'requests' => $requests,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_count' => (int)$totalCount,
                    'limit' => $limit
                ]
            ]);
            break;

        case 'update_item_request':
            if (!$isAdmin) {
                throw new Exception('Unauthorized');
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request');
            }
            $id = (int)($_POST['id'] ?? 0);
            $new_status = $_POST['status'] ?? '';
            if (!$id || !in_array($new_status, ['approved','rejected','fulfilled','pending'])) {
                throw new Exception('Invalid parameters');
            }
            
            // Get request details before updating
            $get_request_stmt = $conn->prepare("SELECT requested_by, item_name, status FROM item_requests WHERE id = ?");
            $get_request_stmt->bind_param('i', $id);
            $get_request_stmt->execute();
            $request_result = $get_request_stmt->get_result();
            $request_data = $request_result->fetch_assoc();
            $get_request_stmt->close();
            
            if (!$request_data) {
                throw new Exception('Request not found');
            }
            
            $stmt = $conn->prepare("UPDATE item_requests SET status = ?, updated_at = NOW() WHERE id = ?");
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param('si', $new_status, $id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to update request');
            }
            
            // Send notification email if status changed to approved or rejected
            if (in_array($new_status, ['approved', 'rejected']) && $request_data['status'] !== $new_status) {
                try {
                    // Get user email from users table
                    $user_stmt = $conn->prepare("SELECT email FROM users WHERE username = ?");
                    $user_stmt->bind_param('s', $request_data['requested_by']);
                    $user_stmt->execute();
                    $user_result = $user_stmt->get_result();
                    $user_data = $user_result->fetch_assoc();
                    $user_stmt->close();
                    
                    if ($user_data && !empty($user_data['email'])) {
                        require_once 'email_notifications.php'; // Include if not already
                        if ($new_status === 'approved') {
                            sendItemRequestApprovalEmail($user_data['email'], $request_data['requested_by'], $request_data['item_name']);
                        } elseif ($new_status === 'rejected') {
                            sendItemRequestRejectionEmail($user_data['email'], $request_data['requested_by'], $request_data['item_name']);
                        }
                    }
                } catch (Exception $email_error) {
                    error_log("Email notification failed: " . $email_error->getMessage());
                }
            }
            
            echo json_encode(['success' => true]);
            break;

        case 'restore_category':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for restoring category');
            }

            $id = 0;
            if (isset($_POST['id'])) {
                $id = (int)$_POST['id'];
            }
            if (!$id) {
                throw new Exception("Valid category ID is required");
            }

            $conn->begin_transaction();
            try {
                // Get archived category details
                $get_sql = "SELECT * FROM archived_categories WHERE id = ?";
                $get_stmt = $conn->prepare($get_sql);
                if (!$get_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $get_stmt->bind_param("i", $id);
                $get_stmt->execute();
                $result = $get_stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Archived category not found");
                }
                
                $archivedCategory = $result->fetch_assoc();
                
                // Insert back into categories table
                $ins_sql = "INSERT INTO categories (id, name, account) VALUES (?, ?, ?)";
                $ins_stmt = $conn->prepare($ins_sql);
                if (!$ins_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $ins_stmt->bind_param("iss", $archivedCategory['category_id'], $archivedCategory['name'], $archivedCategory['account']);
                if (!$ins_stmt->execute()) {
                    throw new Exception("Failed to restore category: " . $ins_stmt->error);
                }

                // Delete from archived_categories table
                $del_stmt = $conn->prepare("DELETE FROM archived_categories WHERE id = ?");
                if (!$del_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $del_stmt->bind_param("i", $id);
                if (!$del_stmt->execute() || $del_stmt->affected_rows <= 0) {
                    throw new Exception("Failed to remove category from archive");
                }

                $conn->commit();
                echo json_encode(['success' => true, 'message' => "Category restored successfully"]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'get_filter_data':
            $departments = [];
            $categories = [];
            $locations = [];
            $archived_by_users = [];
            
            // Departments: only show user's department for non-super-admins
            if ($isSuperAdmin) {
                $dept_sql = "SELECT id, name FROM departments ORDER BY name ASC";
                $dept_result = $conn->query($dept_sql);
            } else if (!empty($currentDepartment)) {
                $dept_sql = "SELECT id, name FROM departments WHERE name = ? ORDER BY name ASC";
                $dept_stmt = $conn->prepare($dept_sql);
                $dept_stmt->bind_param('s', $currentDepartment);
                $dept_stmt->execute();
                $dept_result = $dept_stmt->get_result();
            } else {
                $dept_result = false;
            }
            
            if ($dept_result) {
                while ($row = $dept_result->fetch_assoc()) {
                    $departments[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name']
                    ];
                }
                if (isset($dept_stmt)) {
                    $dept_stmt->close();
                }
            }
            
            // Categories: filter by user's department for non-super-admins
            if ($isSuperAdmin) {
                $cat_sql = "SELECT DISTINCT name FROM categories ORDER BY name ASC";
                $cat_result = $conn->query($cat_sql);
            } else if (!empty($currentDepartment)) {
                $cat_sql = "SELECT DISTINCT c.name 
                           FROM categories c 
                           LEFT JOIN departments d ON c.department_id = d.id 
                           WHERE d.name = ? 
                           ORDER BY c.name ASC";
                $cat_stmt = $conn->prepare($cat_sql);
                $cat_stmt->bind_param('s', $currentDepartment);
                $cat_stmt->execute();
                $cat_result = $cat_stmt->get_result();
            } else {
                $cat_result = false;
            }
            
            if ($cat_result) {
                while ($row = $cat_result->fetch_assoc()) {
                    $categories[] = $row['name'];
                }
                if (isset($cat_stmt)) {
                    $cat_stmt->close();
                }
            }
            
            // Locations: filter by user's department for non-super-admins
            if ($isSuperAdmin) {
                $loc_sql = "SELECT DISTINCT location FROM archived_items WHERE location IS NOT NULL AND location != '' ORDER BY location";
                $loc_result = $conn->query($loc_sql);
            } else if (!empty($currentDepartment)) {
                $loc_sql = "SELECT DISTINCT location 
                           FROM archived_items 
                           WHERE location IS NOT NULL AND location != '' AND department_name = ? 
                           ORDER BY location";
                $loc_stmt = $conn->prepare($loc_sql);
                $loc_stmt->bind_param('s', $currentDepartment);
                $loc_stmt->execute();
                $loc_result = $loc_stmt->get_result();
            } else {
                $loc_result = false;
            }
            
            if ($loc_result) {
                while ($row = $loc_result->fetch_assoc()) {
                    $locations[] = $row['location'];
                }
                if (isset($loc_stmt)) {
                    $loc_stmt->close();
                }
            }
            
            // Archived by users: filter by user's department for non-super-admins
            if ($isSuperAdmin) {
                $users_sql = "SELECT DISTINCT u.username, u.email 
                             FROM users u 
                             INNER JOIN archived_items ai ON u.username = ai.archived_by 
                             WHERE ai.archived_by IS NOT NULL AND ai.archived_by != '' 
                             ORDER BY u.username ASC";
                $users_result = $conn->query($users_sql);
            } else if (!empty($currentDepartment)) {
                $users_sql = "SELECT DISTINCT u.username, u.email 
                             FROM users u 
                             INNER JOIN archived_items ai ON u.username = ai.archived_by 
                             WHERE ai.archived_by IS NOT NULL AND ai.archived_by != '' 
                             AND ai.department_name = ?
                             ORDER BY u.username ASC";
                $users_stmt = $conn->prepare($users_sql);
                $users_stmt->bind_param('s', $currentDepartment);
                $users_stmt->execute();
                $users_result = $users_stmt->get_result();
            } else {
                $users_result = false;
            }
            
            if ($users_result) {
                while ($row = $users_result->fetch_assoc()) {
                    $archived_by_users[] = [
                        'username' => $row['username'],
                        'email' => $row['email']
                    ];
                }
                if (isset($users_stmt)) {
                    $users_stmt->close();
                }
            }
            
            echo json_encode([
                'success' => true,
                'departments' => $departments,
                'categories' => $categories,
                'locations' => $locations,
                'archived_by_users' => $archived_by_users
            ]);
            break;

        case 'create_department':
            // Only super admins can create departments
            if (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                break;
            }
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                echo json_encode(['success' => false, 'message' => 'Department name is required']);
                break;
            }
            // Check duplicate
            $check = $conn->prepare("SELECT id FROM departments WHERE name = ? LIMIT 1");
            if ($check) {
                $check->bind_param("s", $name);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();
                if ($exists) {
                    echo json_encode(['success' => true, 'message' => 'Department already exists']);
                    break;
                }
            }
            $ins = $conn->prepare("INSERT INTO departments (name) VALUES (?)");
            if (!$ins) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                break;
            }
            $ins->bind_param("s", $name);
            if ($ins->execute()) {
                echo json_encode(['success' => true, 'message' => 'Department added', 'id' => $ins->insert_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $ins->error]);
            }
            $ins->close();
            break;

        case 'delete_department':
            // Only super admins can delete departments
            if (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                break;
            }
            $deptId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($deptId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid department id']);
                break;
            }

            // Check for items in this department
            $itemCount = 0;
            if ($stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM items WHERE department_id = ?")) {
                $stmt->bind_param("i", $deptId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) { $itemCount = (int)$row['cnt']; }
                $stmt->close();
            }

            if ($itemCount > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete: department has items']);
                break;
            }

            // Check for item tables in this department
            $tableCount = 0;
            if ($stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM item_tables WHERE department_id = ?")) {
                $stmt->bind_param("i", $deptId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) { $tableCount = (int)$row['cnt']; }
                $stmt->close();
            }

            if ($tableCount > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete: department has item tables']);
                break;
            }

            // Check for categories assigned to this department if column exists
            $categoryCount = 0;
            $hasDeptCol = false;
            if ($result = $conn->query("SHOW COLUMNS FROM categories LIKE 'department_id'")) {
                $hasDeptCol = $result->num_rows > 0;
            }
            if ($hasDeptCol) {
                if ($stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM categories WHERE department_id = ?")) {
                    $stmt->bind_param("i", $deptId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) { $categoryCount = (int)$row['cnt']; }
                    $stmt->close();
                }
            }
            if ($categoryCount > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete: department has categories']);
                break;
            }

            // Finally, delete the department
            if ($stmt = $conn->prepare("DELETE FROM departments WHERE id = ?")) {
                $stmt->bind_param("i", $deptId);
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Department deleted']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $stmt->error]);
                }
                $stmt->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
            }
            break;

        case 'get_items_by_table':
            if (!isset($_GET['table_id']) || empty($_GET['table_id'])) {
                throw new Exception('Table ID is required');
            }
            
            $table_id = (int)$_GET['table_id'];
            
            // Get current user's email to check for pending requests
            $current_user_email = '';
            $current_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            if ($current_user_id > 0) {
                $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
                try {
                    if ($is_super_admin) {
                        $email_stmt = $conn->prepare("SELECT email FROM super_admin WHERE id = ?");
                    } else {
                        $email_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
                    }
                    $email_stmt->bind_param("i", $current_user_id);
                    $email_stmt->execute();
                    $email_result = $email_stmt->get_result();
                    if ($email_result && $email_row = $email_result->fetch_assoc()) {
                        $current_user_email = $email_row['email'] ?? '';
                    }
                    $email_stmt->close();
                } catch (Exception $e) {
                    error_log("Error fetching user email: " . $e->getMessage());
                }
            }
            
            $sql = "SELECT 
                        i.id,
                        i.item_code,
                        i.name,
                        i.department_id,
                        d.name as department_name,
                        i.category,
                        i.quantity,
                        i.location,
                        i.status,
                        i.description,
                        i.image_path,
                        i.qr_code,
                        i.created_at,
                        i.updated_at,
                        CASE 
                            WHEN EXISTS (
                                SELECT 1 FROM borrow_history bh 
                                WHERE bh.item_id = i.id 
                                AND bh.status IN ('approved', 'active', 'overdue', 'received')
                            ) THEN 'Borrowed'
                            WHEN EXISTS (
                                SELECT 1 FROM item_tables it 
                                WHERE it.id = i.item_table_id 
                                AND COALESCE(it.is_consumable, 0) = 1
                            ) THEN 'Consumable'
                            ELSE COALESCE(i.status, 'Working')
                        END as display_status,
                        CASE 
                            WHEN ? != '' AND EXISTS (
                                SELECT 1 FROM borrow_history bh 
                                WHERE bh.item_id = i.id 
                                AND bh.borrower_email = ?
                                AND bh.status = 'pending'
                            ) THEN 1
                            ELSE 0
                        END as has_pending_request
                    FROM items i 
                    LEFT JOIN departments d ON i.department_id = d.id 
                    WHERE i.item_table_id = ?
                    ORDER BY i.updated_at DESC";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ssi", $current_user_email, $current_user_email, $table_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                $items = [];
                while ($row = $result->fetch_assoc()) {
                    $items[] = $row;
                }
                
                echo json_encode([
                    'success' => true,
                    'items' => $items
                ]);
            } else {
                throw new Exception("Failed to fetch items: " . $stmt->error);
            }
            break;

        case 'get_item_borrow_history':
            // Return borrow history records for a specific item
            $item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
            $item_code = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
            $status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
            if ($item_id <= 0 && $item_code === '') {
                throw new Exception('Item identifier is required');
            }

            $sql = "SELECT 
                        bh.id,
                        bh.borrow_id,
                        bh.borrower_name,
                        bh.borrower_email,
                        bh.item_id,
                        i.name as item_name,
                        bh.department_name,
                        bh.category,
                        bh.quantity_borrowed,
                        bh.borrow_date,
                        bh.due_date,
                        bh.return_date,
                        bh.status,
                        bh.priority,
                        bh.purpose,
                        bh.created_at,
                        bh.updated_at
                    FROM borrow_history bh
                    LEFT JOIN items i ON bh.item_id = i.id
                    WHERE " . ($item_id > 0 ? "bh.item_id = ?" : "i.item_code = ?") . ($status_filter !== '' ? " AND bh.status = ?" : "") . "
                    ORDER BY bh.created_at DESC";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            if ($status_filter !== '') {
                if ($item_id > 0) {
                    $stmt->bind_param('is', $item_id, $status_filter);
                } else {
                    $stmt->bind_param('ss', $item_code, $status_filter);
                }
            } else {
                if ($item_id > 0) {
                    $stmt->bind_param('i', $item_id);
                } else {
                    $stmt->bind_param('s', $item_code);
                }
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $history = [];
            while ($row = $res->fetch_assoc()) {
                $history[] = $row;
            }
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        case 'get_item_tables':
            $sql = "SELECT 
                        it.id,
                        it.table_name,
                        it.category,
                        it.department_id,
                        d.name as department_name,
                        it.description,
                        it.table_image_path,
                        it.priority,
                        it.qr_code,
                        COALESCE(it.is_consumable, 0) as is_consumable,
                        it.created_at,
                        it.updated_at
                    FROM item_tables it 
                    LEFT JOIN departments d ON it.department_id = d.id 
                    ORDER BY it.table_name ASC";
            $result = $conn->query($sql);
            
            if ($result) {
                $item_tables = [];
                while ($row = $result->fetch_assoc()) {
                    $item_tables[] = $row;
                }
                
                echo json_encode([
                    'success' => true,
                    'item_tables' => $item_tables
                ]);
            } else {
                throw new Exception("Failed to fetch item tables: " . $conn->error);
            }
            break;

        case 'get_item_table':
            $table_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if (!$table_id) {
                echo json_encode(['success' => false, 'message' => 'Item table ID required']);
                break;
            }
            
            $sql = "SELECT 
                        it.id,
                        it.table_name,
                        it.category,
                        it.department_id,
                        d.name as department_name,
                        it.description,
                        it.table_image_path,
                        it.created_at,
                        it.updated_at
                    FROM item_tables it 
                    LEFT JOIN departments d ON it.department_id = d.id 
                    WHERE it.id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $table_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $item_table = $result->fetch_assoc();
                echo json_encode([
                    'success' => true,
                    'item_table' => $item_table
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Item table not found'
                ]);
            }
            $stmt->close();
            break;

        case 'update_item_table':
            // Block viewers (borrowers) from updating item tables
            $isViewer = empty($currentDepartment) && !$isAdmin && !$isSuperAdmin;
            if ($isViewer) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: borrowers cannot edit item tables']);
                break;
            }

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                break;
            }

            $table_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if (!$table_id) {
                echo json_encode(['success' => false, 'message' => 'Item table ID required']);
                break;
            }

            // Get item table to check permissions
            $get_table_stmt = $conn->prepare("SELECT it.id, it.department_id, d.name AS department_name FROM item_tables it LEFT JOIN departments d ON it.department_id = d.id WHERE it.id = ?");
            if (!$get_table_stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                break;
            }
            $get_table_stmt->bind_param("i", $table_id);
            $get_table_stmt->execute();
            $get_table_result = $get_table_stmt->get_result();
            
            if ($get_table_result->num_rows === 0) {
                $get_table_stmt->close();
                echo json_encode(['success' => false, 'message' => 'Item table not found']);
                break;
            }
            
            $table_data = $get_table_result->fetch_assoc();
            $get_table_stmt->close();
            
            // Permission check: super admin or same department
            $table_department_name = $table_data['department_name'] ?? '';
            if (!$isSuperAdmin && $table_department_name !== $currentDepartment) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only edit item tables from your own department']);
                break;
            }

            $required_fields = ['table_name', 'department_id'];
            foreach ($required_fields as $field) {
                if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                    echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
                    break 2;
                }
            }
            
            $table_name = trim($_POST['table_name']);
            // Category is optional - preserve existing if not provided
            $category = isset($_POST['category']) && trim($_POST['category']) !== '' ? trim($_POST['category']) : null;
            $department_id = (int)$_POST['department_id'];
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            
            // If category not provided, get existing category from database
            if ($category === null) {
                $get_category_sql = "SELECT category FROM item_tables WHERE id = ?";
                $get_category_stmt = $conn->prepare($get_category_sql);
                $get_category_stmt->bind_param("i", $table_id);
                $get_category_stmt->execute();
                $get_category_result = $get_category_stmt->get_result();
                if ($get_category_result->num_rows > 0) {
                    $existing_table = $get_category_result->fetch_assoc();
                    $category = $existing_table['category'];
                }
                $get_category_stmt->close();
            }
            
            // Check if item table name already exists in this department (excluding current table)
            $check_table = $conn->prepare("SELECT id FROM item_tables WHERE table_name = ? AND department_id = ? AND id != ?");
            $check_table->bind_param("sii", $table_name, $department_id, $table_id);
            $check_table->execute();
            $check_result = $check_table->get_result();
            
            if ($check_result->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'An item table with this name already exists in this department']);
                $check_table->close();
                break;
            }
            $check_table->close();
            
            // Handle image upload if provided
            $table_image_path = null;
            if (isset($_FILES['table_image']) && $_FILES['table_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/item_tables/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['table_image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (!in_array($file_extension, $allowed_extensions)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.']);
                    break;
                }
                
                $new_filename = 'table_' . time() . '_' . uniqid() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['table_image']['tmp_name'], $upload_path)) {
                    // Get old image path to delete it later
                    $old_image_sql = "SELECT table_image_path FROM item_tables WHERE id = ?";
                    $old_image_stmt = $conn->prepare($old_image_sql);
                    $old_image_stmt->bind_param("i", $table_id);
                    $old_image_stmt->execute();
                    $old_image_result = $old_image_stmt->get_result();
                    if ($old_image_result->num_rows > 0) {
                        $old_row = $old_image_result->fetch_assoc();
                        $old_image_path = $old_row['table_image_path'];
                        if ($old_image_path && file_exists($old_image_path)) {
                            @unlink($old_image_path);
                        }
                    }
                    $old_image_stmt->close();
                    
                    $table_image_path = $upload_path;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to upload image']);
                    break;
                }
            }
            
            // Update item table
            if ($table_image_path) {
                $update_sql = "UPDATE item_tables SET table_name = ?, category = ?, department_id = ?, description = ?, table_image_path = ?, updated_at = NOW() WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssissi", $table_name, $category, $department_id, $description, $table_image_path, $table_id);
            } else {
                $update_sql = "UPDATE item_tables SET table_name = ?, category = ?, department_id = ?, description = ?, updated_at = NOW() WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssisi", $table_name, $category, $department_id, $description, $table_id);
            }
            
            if ($update_stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Item table updated successfully',
                    'table_id' => $table_id
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update item table: ' . $conn->error]);
            }
            $update_stmt->close();
            break;

        case 'add_item_table':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for adding item table');
            }

            // Check if consumable
            $is_consumable = isset($_POST['is_consumable']) && (int)$_POST['is_consumable'] === 1;
            
            // For consumable, priority is not required
            if (!$is_consumable) {
                $required_fields = ['table_name', 'category', 'department_id', 'priority'];
                foreach ($required_fields as $field) {
                    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                        throw new Exception("Field '$field' is required");
                    }
                }
            } else {
            $required_fields = ['table_name', 'category', 'department_id'];
            foreach ($required_fields as $field) {
                if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                    throw new Exception("Field '$field' is required");
                    }
                }
            }
            
            $table_name = trim($_POST['table_name']);
            $category = trim($_POST['category']);
            $department_id = (int)$_POST['department_id'];
            $priority = isset($_POST['priority']) && in_array($_POST['priority'], ['low', 'medium', 'high']) ? $_POST['priority'] : 'low';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            
            // Check if item table name already exists in this department
            $check_table = $conn->prepare("SELECT id FROM item_tables WHERE table_name = ? AND department_id = ?");
            if (!$check_table) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $check_table->bind_param("si", $table_name, $department_id);
            $check_table->execute();
            $check_result = $check_table->get_result();
            
            if ($check_result->num_rows > 0) {
                throw new Exception("Item table '$table_name' already exists in this department. Please choose a different name.");
            }
            
            // Handle table image upload
            $table_image_path = null;
            if (isset($_FILES['table_image']) && $_FILES['table_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/item_tables/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['table_image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = 'table_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['table_image']['tmp_name'], $file_path)) {
                        $table_image_path = $file_path;
                    }
                }
            }
            
            // Check if department exists
            $dept_check = $conn->prepare("SELECT id, name FROM departments WHERE id = ?");
            if (!$dept_check) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $dept_check->bind_param("i", $department_id);
            $dept_check->execute();
            $dept_result = $dept_check->get_result();
            if ($dept_result->num_rows === 0) {
                throw new Exception("Invalid department");
            }
            $dept_row = $dept_result->fetch_assoc();
            // Super admins have no restrictions - can add to any department
            // Department heads (admin but not super admin) can only add item tables to their own department
            // Regular users with departments can only add to their own department
            if (!$isSuperAdmin && !empty($currentDepartment) && strcasecmp($dept_row['name'], $currentDepartment) !== 0) {
                throw new Exception("You can only add item tables for your own department");
            }
            
            // Check if priority column exists, if not add it
            $checkPriorityColumn = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'priority'");
            if ($checkPriorityColumn->num_rows == 0) {
                // Try to add after description, if that fails, add at the end
                $alterResult = $conn->query("ALTER TABLE item_tables ADD COLUMN priority ENUM('low', 'medium', 'high') DEFAULT 'low' AFTER description");
                if (!$alterResult) {
                    // If AFTER description fails, try adding at the end
                    $alterResult2 = $conn->query("ALTER TABLE item_tables ADD COLUMN priority ENUM('low', 'medium', 'high') DEFAULT 'low'");
                    if (!$alterResult2) {
                        error_log("Failed to add priority column: " . $conn->error);
                        // Continue anyway - will use default 'low' value
                    }
                }
            }
            
            // Check if is_consumable column exists, if not add it
            $checkConsumableColumn = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'is_consumable'");
            if ($checkConsumableColumn->num_rows == 0) {
                $alterConsumable = $conn->query("ALTER TABLE item_tables ADD COLUMN is_consumable TINYINT(1) DEFAULT 0 AFTER priority");
                if (!$alterConsumable) {
                    error_log("Failed to add is_consumable column: " . $conn->error);
                }
            }
            
            // Check if qr_requests table exists, if not create it
            $checkQrRequestsTable = $conn->query("SHOW TABLES LIKE 'qr_requests'");
            if ($checkQrRequestsTable->num_rows == 0) {
                // Create table without foreign key constraint first (can be added later if needed)
                $createQrRequestsTable = "CREATE TABLE IF NOT EXISTS `qr_requests` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `item_table_id` int(11) NOT NULL,
                    `item_id` int(11) DEFAULT NULL,
                    `requested_by` varchar(100) NOT NULL,
                    `priority` ENUM('low', 'medium', 'high') NOT NULL,
                    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                    `qr_code` varchar(255) DEFAULT NULL,
                    `qr_code_path` varchar(500) DEFAULT NULL,
                    `download_count` int(11) DEFAULT 0,
                    `downloaded_at` datetime DEFAULT NULL,
                    `approved_by` varchar(100) DEFAULT NULL,
                    `rejected_by` varchar(100) DEFAULT NULL,
                    `rejection_reason` text DEFAULT NULL,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `item_table_id` (`item_table_id`),
                    KEY `item_id` (`item_id`),
                    KEY `requested_by` (`requested_by`),
                    KEY `status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                $createResult = $conn->query($createQrRequestsTable);
                if (!$createResult) {
                    error_log("Failed to create qr_requests table: " . $conn->error);
                    // Don't throw error, just log it - table might already exist
                }
            }
            // Ensure latest schema adjustments are applied
            ensureQrRequestsItemColumn($conn);
            
            // Insert into item_tables table - check if priority and is_consumable columns exist first
            $checkPriorityFinal = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'priority'");
            $priorityExists = $checkPriorityFinal->num_rows > 0;
            $checkConsumableFinal = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'is_consumable'");
            $consumableExists = $checkConsumableFinal->num_rows > 0;
            
            if ($priorityExists && $consumableExists) {
                // Both columns exist, include them in INSERT
                $stmt = $conn->prepare("INSERT INTO item_tables (table_name, category, department_id, description, table_image_path, priority, is_consumable) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("ssisssi", $table_name, $category, $department_id, $description, $table_image_path, $priority, $is_consumable);
            } elseif ($priorityExists) {
                // Only priority column exists
                $stmt = $conn->prepare("INSERT INTO item_tables (table_name, category, department_id, description, table_image_path, priority) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("ssisss", $table_name, $category, $department_id, $description, $table_image_path, $priority);
            } elseif ($consumableExists) {
                // Only consumable column exists
                $stmt = $conn->prepare("INSERT INTO item_tables (table_name, category, department_id, description, table_image_path, is_consumable) VALUES (?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("ssissi", $table_name, $category, $department_id, $description, $table_image_path, $is_consumable);
            } else {
                // Neither column exists, insert without them
            $stmt = $conn->prepare("INSERT INTO item_tables (table_name, category, department_id, description, table_image_path) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ssiss", $table_name, $category, $department_id, $description, $table_image_path);
                // Set priority to 'low' for logic below
                $priority = 'low';
            }
            
            if ($stmt->execute()) {
                $tableId = $conn->insert_id;
                
                // Check if qr_code column exists, if not add it
                $checkColumn = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'qr_code'");
                if ($checkColumn->num_rows == 0) {
                    // Add qr_code column if it doesn't exist
                    $conn->query("ALTER TABLE item_tables ADD COLUMN qr_code varchar(255) DEFAULT NULL AFTER table_image_path");
                    $conn->query("ALTER TABLE item_tables ADD INDEX idx_qr_code (qr_code)");
                }
                
                $currentUsername = $_SESSION['username'] ?? 'system';
                
                // For consumable items, skip QR code generation entirely
                if ($is_consumable) {
                    // Ensure no output before JSON
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    echo json_encode([
                        'success' => true,
                        'message' => 'Consumable item table added successfully (no QR code)',
                        'table_id' => $tableId,
                        'is_consumable' => true
                    ]);
                    exit; // Prevent any further output
                }
                
                // Handle QR code based on priority (only for non-consumable items)
                // Super Admin: Always auto-generate QR code regardless of priority
                // Head Department (Admin but not Super Admin): Auto-generate for low priority, request approval for medium/high priority
                // Regular users: Same as Head Department
                if ($priority === 'low' || $isSuperAdmin) {
                    // Auto-generate QR code for low priority OR for Super Admin (any priority)
                    // Note: Head Department (isAdmin but not isSuperAdmin) with medium/high priority will create QR request below
                $qrCodeValue = 'TABLE-' . $tableId . '-' . time();
                
                // Get department info for color
                $deptSql = "SELECT id, name FROM departments WHERE id = ?";
                $deptStmt = $conn->prepare($deptSql);
                $deptStmt->bind_param("i", $department_id);
                $deptStmt->execute();
                $deptResult = $deptStmt->get_result();
                $departmentData = $deptResult->fetch_assoc();
                $deptStmt->close();
                
                // Get department color
                $fgColor = getDepartmentColorHex($department_id, $departmentData['name'] ?? null);
                
                // Generate QR code image
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
                $qrData = $baseUrl . 'item_table_inventory.php?table_id=' . $tableId;
                
                $qrCodeFilename = 'qr_table_' . $tableId . '_' . time() . '.png';
                $qrCodePath = 'qr_codes/' . $qrCodeFilename;
                
                // Ensure qr_codes folder exists
                if (!file_exists('qr_codes')) {
                    mkdir('qr_codes', 0777, true);
                }
                
                // Generate QR code using API with department color
                $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=' . $fgColor . '&bgcolor=FFFFFF&data=' . urlencode($qrData);
                $qrImage = @file_get_contents($qrApiUrl);
                
                if ($qrImage !== false) {
                    // Save QR code image
                    file_put_contents($qrCodePath, $qrImage);
                    
                    // Update item_tables with QR code
                    $updateQrStmt = $conn->prepare("UPDATE item_tables SET qr_code = ? WHERE id = ?");
                    $updateQrStmt->bind_param("si", $qrCodeValue, $tableId);
                    $updateQrStmt->execute();
                    $updateQrStmt->close();
                }
                
                    // Ensure no output before JSON
                    if (ob_get_level()) {
                        ob_clean();
                    }
                $message = ($isSuperAdmin || $isAdmin) 
                    ? 'Item table added successfully with QR code (auto-generated for admin)'
                    : 'Item table added successfully with QR code';
                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'table_id' => $tableId,
                    'qr_code' => $qrCodeValue
                ]);
                    exit; // Prevent any further output
                } else {
                    // For medium and high priority (Head Department only), create QR request (table-level)
                    ensureQrRequestsItemColumn($conn);
                    $qrRequestStmt = $conn->prepare("INSERT INTO qr_requests (item_table_id, item_id, requested_by, priority, status) VALUES (?, NULL, ?, ?, 'pending')");
                    if (!$qrRequestStmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $qrRequestStmt->bind_param("iss", $tableId, $currentUsername, $priority);
                    
                    if ($qrRequestStmt->execute()) {
                        $qrRequestId = $conn->insert_id;
                        $qrRequestStmt->close();
                        
                        // Send notification to admins and super admins
                        try {
                            // Directly insert notifications into database to avoid output issues from included files
                            // Get all admins from users table and super admins from super_admin table
                            $admins = [];
                            
                            // Get admins from users table
                            $adminQuery = $conn->query("SELECT username FROM users WHERE is_admin = 1 AND username != '" . $conn->real_escape_string($currentUsername) . "'");
                            if ($adminQuery) {
                                while ($admin = $adminQuery->fetch_assoc()) {
                                    $admins[] = $admin['username'];
                                }
                            }
                            
                            // Get super admins from super_admin table
                            $superAdminQuery = $conn->query("SELECT username FROM super_admin WHERE username != '" . $conn->real_escape_string($currentUsername) . "'");
                            if ($superAdminQuery) {
                                while ($superAdmin = $superAdminQuery->fetch_assoc()) {
                                    $admins[] = $superAdmin['username'];
                                }
                            }
                            
                            // Check if notifications table exists
                            $checkNotificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
                            if ($checkNotificationsTable && $checkNotificationsTable->num_rows > 0 && !empty($admins)) {
                                $notificationMessage = "New QR code request for item table: {$table_name} (Priority: {$priority})";
                                $notificationLink = "item_requests.php?tab=qr_requests";
                                
                                foreach ($admins as $adminUsername) {
                                    try {
                                        $notifStmt = $conn->prepare("INSERT INTO notifications (username, type, message, link, is_read, created_at) VALUES (?, 'qr_request', ?, ?, 0, NOW())");
                                        if ($notifStmt) {
                                            $notifStmt->bind_param("sss", $adminUsername, $notificationMessage, $notificationLink);
                                            $notifStmt->execute();
                                            $notifStmt->close();
                                        }
                                    } catch (Exception $notifError) {
                                        error_log("Notification error for user {$adminUsername}: " . $notifError->getMessage());
                                    }
                                }
                            }
                        } catch (Exception $notifException) {
                            error_log("Notification system error: " . $notifException->getMessage());
                            // Don't fail the request if notification fails
                        }
                        
                        // Ensure no output before JSON
                        if (ob_get_level()) {
                            ob_clean();
                        }
                        echo json_encode([
                            'success' => true,
                            'message' => 'Item table added successfully. QR code request has been submitted and is pending approval.',
                            'table_id' => $tableId,
                            'qr_request_id' => $qrRequestId,
                            'requires_approval' => true
                        ]);
                        exit; // Prevent any further output
                    } else {
                        throw new Exception("Failed to create QR request: " . $qrRequestStmt->error);
                    }
                }
            } else {
                throw new Exception("Failed to add item table: " . $stmt->error);
            }
            break;

        case 'delete_item_table':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for deleting item table');
            }

            $table_id = 0;
            if (isset($_POST['id'])) {
                $table_id = (int)$_POST['id'];
            } elseif (isset($_GET['id'])) {
                $table_id = (int)$_GET['id'];
            }
            if (!$table_id) {
                throw new Exception('Valid item table ID is required');
            }

            // Load table and department for permission checks
            $tbl_stmt = $conn->prepare("SELECT it.id, it.table_name, it.table_image_path, d.name AS department_name FROM item_tables it LEFT JOIN departments d ON it.department_id = d.id WHERE it.id = ?");
            if (!$tbl_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $tbl_stmt->bind_param('i', $table_id);
            $tbl_stmt->execute();
            $tbl_res = $tbl_stmt->get_result();
            if ($tbl_res->num_rows === 0) {
                throw new Exception('Item table not found');
            }
            $table_row = $tbl_res->fetch_assoc();
            $tbl_stmt->close();

            // Explicitly block viewers (borrowers) from deleting item tables
            $isViewer = empty($currentDepartment) && !$isAdmin && !$isSuperAdmin;
            if ($isViewer) {
                throw new Exception('Unauthorized: borrowers cannot delete item tables');
            }

            // Permission: super admin or same department can delete item tables
            $table_department_name = $table_row['department_name'] ?? '';
            if (!$isSuperAdmin && $table_department_name !== $currentDepartment) {
                throw new Exception('Unauthorized: You can only delete item tables from your own department');
            }

            // Ensure the table has no items
            $cnt_stmt = $conn->prepare('SELECT COUNT(*) as cnt FROM items WHERE item_table_id = ?');
            if (!$cnt_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $cnt_stmt->bind_param('i', $table_id);
            $cnt_stmt->execute();
            $cnt_res = $cnt_stmt->get_result();
            $cnt_row = $cnt_res->fetch_assoc();
            $cnt_stmt->close();
            if ($cnt_row && (int)$cnt_row['cnt'] > 0) {
                throw new Exception('Cannot delete this item table because it still contains items');
            }

            // Delete related assets: table image and QR image(s)
            if (!empty($table_row['table_image_path']) && file_exists($table_row['table_image_path'])) {
                @unlink($table_row['table_image_path']);
            }
            foreach (glob('qr_codes/qr_table_' . $table_id . '_*.png') as $qrFile) {
                @unlink($qrFile);
            }

            // Delete the item table
            $del_stmt = $conn->prepare('DELETE FROM item_tables WHERE id = ?');
            if (!$del_stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            $del_stmt->bind_param('i', $table_id);
            if ($del_stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => "Item table '" . $table_row['table_name'] . "' deleted successfully"
                ]);
            } else {
                throw new Exception('Failed to delete item table');
            }
            $del_stmt->close();
            break;

        case 'add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for adding item');
            }

            // Debug: Log the received POST data
            error_log("Add item POST data: " . print_r($_POST, true));

            // Get item_table_id first to check if it's consumable
            $item_table_id = isset($_POST['item_table_id']) ? (int)$_POST['item_table_id'] : 0;
            
            // Check if parent item table is consumable FIRST (before validation)
            $isConsumableTable = false;
            if ($item_table_id) {
                $checkConsumableSql = "SELECT is_consumable FROM item_tables WHERE id = ?";
                $checkConsumableStmt = $conn->prepare($checkConsumableSql);
                if ($checkConsumableStmt) {
                    $checkConsumableStmt->bind_param("i", $item_table_id);
                    $checkConsumableStmt->execute();
                    $consumableResult = $checkConsumableStmt->get_result();
                    if ($consumableRow = $consumableResult->fetch_assoc()) {
                        $isConsumableTable = (int)($consumableRow['is_consumable'] ?? 0) === 1;
                    }
                    $checkConsumableStmt->close();
                }
            }
            
            // For consumable tables, status is not required (will be set to "Consumable" automatically)
            // For non-consumable tables, status is required
            if ($isConsumableTable) {
                $required_fields = ['item_table_id', 'name', 'department_id', 'category', 'quantity', 'location'];
            } else {
                $required_fields = ['item_table_id', 'name', 'department_id', 'category', 'quantity', 'location', 'status'];
            }
            
            foreach ($required_fields as $field) {
                if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                    error_log("Missing required field: $field");
                    throw new Exception("Field '$field' is required");
                }
            }
            
            $name = trim($_POST['name']);
            $department_id = (int)$_POST['department_id'];
            $category = trim($_POST['category']);
            $quantity = (int)$_POST['quantity'];
            $location = trim($_POST['location']);
            error_log("Location received from POST: '" . $_POST['location'] . "' -> After trim: '$location'");
            
            // Set status: if consumable, always "Consumable"; otherwise use provided status
            if ($isConsumableTable) {
                $status = 'Consumable';
                error_log("Parent item table is consumable - setting item status to 'Consumable'");
            } else {
                $status = isset($_POST['status']) ? trim($_POST['status']) : 'Working';
            }
            
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $provided_item_code = isset($_POST['item_code']) ? trim($_POST['item_code']) : '';
            
            // Handle image upload
            $image_path = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/items/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = 'item_' . time() . '_' . uniqid() . '.' . $file_extension;
                    $file_path = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $file_path)) {
                        $image_path = $file_path;
                    }
                }
            }
            
            $valid_statuses = ['Working', 'Under Maintenance', 'Broken', 'Lost', 'Missing', 'Consumable'];
            if (!in_array($status, $valid_statuses)) {
                throw new Exception("Invalid status: '" . $status . "'");
            }
            
            if ($quantity < 1) {
                throw new Exception("Quantity must be at least 1");
            }
            
            $dept_check = $conn->prepare("SELECT id, name FROM departments WHERE id = ?");
            if (!$dept_check) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $dept_check->bind_param("i", $department_id);
            $dept_check->execute();
            $dept_result = $dept_check->get_result();
            if ($dept_result->num_rows === 0) {
                throw new Exception("Invalid department");
            }
            $dept_row = $dept_result->fetch_assoc();
            // Super admins have no restrictions - can add to any department
            // Department heads (admin but not super admin) can only add items to their own department
            // Regular users with departments can only add to their own department
            if (!$isSuperAdmin && !empty($currentDepartment) && strcasecmp($dept_row['name'], $currentDepartment) !== 0) {
                throw new Exception("You can only add items for your own department");
            }
            
            // Create individual entries for each quantity
            $createdItems = [];
            $successCount = 0;
            $failedCount = 0;
            $qrResults = [];
            
            // Test database connection
            if (!$conn) {
                throw new Exception("Database connection failed");
            }
            
            error_log("Starting transaction for $quantity items");
            $conn->begin_transaction();
            try {
                for ($i = 0; $i < $quantity; $i++) {
                    error_log("Creating item " . ($i + 1) . " of $quantity");
                    
                    // Determine item_code: use provided barcode/item_code if given for the first item, otherwise generate
                    if ($i === 0 && $provided_item_code !== '') {
                        // Build formatted code using name/category prefixes + raw barcode
                        $formatted_code = buildItemCodeFromBarcode($name, $category, $provided_item_code);
                        // Ensure uniqueness of provided code
                        $check_sql = "SELECT id FROM items WHERE item_code = ?";
                        $check_stmt = $conn->prepare($check_sql);
                        if (!$check_stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        $check_stmt->bind_param("s", $formatted_code);
                        $check_stmt->execute();
                        $exists_res = $check_stmt->get_result();
                        if ($exists_res && $exists_res->num_rows > 0) {
                            throw new Exception("Item code already exists. Please scan a different code.");
                        }
                        $item_code = $formatted_code;
                        $check_stmt->close();
                    } else {
                        // Generate unique item code for each item
                        $item_code = generateUniqueItemCode($conn, $name, $department_id, $category);
                    }
                    error_log("Generated item code: $item_code");
                    error_log("Location value being inserted: '$location' (length: " . strlen($location) . ")");
                    
                    // Use PHP's current date/time to ensure correct year (2025, not 2001)
                    $current_datetime = date('Y-m-d H:i:s');
                    error_log("Using current datetime: $current_datetime");
                    
                    $stmt = $conn->prepare("INSERT INTO items (item_code, item_table_id, name, department_id, category, quantity, location, status, description, image_path, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        error_log("Prepare failed: " . $conn->error);
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $stmt->bind_param("sisisssssss", $item_code, $item_table_id, $name, $department_id, $category, $location, $status, $description, $image_path, $current_datetime, $current_datetime);
                    
                    if ($stmt->execute()) {
                        $itemId = $conn->insert_id;
                        $createdItems[] = $itemId;
                        $successCount++;
                        error_log("Created item with unique ID: $itemId");
                        
                        // Verify the location was saved correctly
                        $verifyStmt = $conn->prepare("SELECT location FROM items WHERE id = ?");
                        if ($verifyStmt) {
                            $verifyStmt->bind_param("i", $itemId);
                            $verifyStmt->execute();
                            $verifyResult = $verifyStmt->get_result();
                            if ($verifyRow = $verifyResult->fetch_assoc()) {
                                error_log("Verified location in database for item $itemId: '" . $verifyRow['location'] . "'");
                                if (trim($verifyRow['location']) !== trim($location)) {
                                    error_log("WARNING: Location mismatch! Expected: '$location', Got: '" . $verifyRow['location'] . "'");
                                }
                            }
                            $verifyStmt->close();
                        }
                        
                        // Try to generate QR code for each item - but skip if consumable or if parent table has no approved QR request
                        try {
                            // Skip QR generation for consumable items
                            if ($isConsumableTable) {
                                $qrResults[$itemId] = [
                                    'success' => false,
                                    'error' => 'Consumable items do not have QR codes',
                                    'consumable' => true,
                                    'blocked' => true
                                ];
                                error_log("QR code generation SKIPPED for item $itemId: Parent table $item_table_id is consumable");
                            } else {
                                // Check if parent item table has approved QR request (for medium/high priority)
                                $canGenerateQr = true;
                                if ($item_table_id) {
                                    // Check item table priority and QR request status
                                    $checkTableSql = "SELECT it.priority, 
                                        (SELECT COUNT(*) FROM qr_requests qr WHERE qr.item_table_id = it.id AND qr.status = 'approved') as has_approved_qr,
                                        it.qr_code as table_has_qr
                                        FROM item_tables it WHERE it.id = ?";
                                    $checkTableStmt = $conn->prepare($checkTableSql);
                                    if ($checkTableStmt) {
                                        $checkTableStmt->bind_param("i", $item_table_id);
                                        $checkTableStmt->execute();
                                        $tableResult = $checkTableStmt->get_result();
                                        if ($tableRow = $tableResult->fetch_assoc()) {
                                            $priority = strtolower($tableRow['priority'] ?? 'low');
                                            $hasApprovedQr = (int)$tableRow['has_approved_qr'] > 0;
                                            $tableHasQr = !empty($tableRow['table_has_qr']);
                                            
                                            // For medium/high priority, require approved QR request
                                            if (in_array($priority, ['medium', 'high'])) {
                                                if (!$hasApprovedQr && !$tableHasQr) {
                                                    $canGenerateQr = false;
                                                    error_log("BLOCKED QR generation for item $itemId: Parent table $item_table_id (priority: $priority) has no approved QR request. hasApprovedQr=$hasApprovedQr, tableHasQr=" . ($tableHasQr ? 'yes' : 'no'));
                                                } else {
                                                    error_log("ALLOWED QR generation for item $itemId: Parent table $item_table_id (priority: $priority) has approved QR. hasApprovedQr=$hasApprovedQr, tableHasQr=" . ($tableHasQr ? 'yes' : 'no'));
                                                }
                                            } else {
                                                error_log("ALLOWED QR generation for item $itemId: Parent table $item_table_id (priority: $priority) - low priority, auto-generate");
                                            }
                                        } else {
                                            error_log("WARNING: Could not find item table $item_table_id for item $itemId - blocking QR generation");
                                            $canGenerateQr = false;
                                        }
                                        $checkTableStmt->close();
                                    }
                                }
                                
                                if ($canGenerateQr) {
                            error_log("Attempting QR code generation for item $itemId");
                            $qrResult = generateAndSaveQRCode($conn, $itemId, [
                                'name' => $name,
                                'department_id' => $department_id,
                                'department_name' => $dept_row['name'] ?? null,
                                'category' => $category,
                                'location' => $location,
                                        'item_code' => $item_code,
                                        'item_table_id' => $item_table_id
                            ]);
                            $qrResults[$itemId] = $qrResult;
                                    if ($qrResult['success']) {
                                        error_log("QR code generation SUCCESS for item $itemId");
                                    } else {
                                        error_log("QR code generation FAILED for item $itemId: " . ($qrResult['error'] ?? 'Unknown error'));
                                    }
                                } else {
                                    // DO NOT generate QR code - parent table QR request is pending
                                    $qrResults[$itemId] = [
                                        'success' => false, 
                                        'error' => 'Parent item table QR request is pending approval. QR code will be generated after approval.',
                                        'pending_approval' => true,
                                        'blocked' => true
                                    ];
                                    error_log("QR code generation BLOCKED for item $itemId: Parent table $item_table_id QR request is pending approval");
                                }
                            }
                        } catch (Exception $qrError) {
                            error_log("QR generation failed for item $itemId: " . $qrError->getMessage());
                            $qrResults[$itemId] = ['success' => false, 'error' => $qrError->getMessage()];
                        }
                    } else {
                        $failedCount++;
                        error_log("Failed to create item " . ($i + 1) . ": " . $stmt->error);
                    }
                }
                
                $conn->commit();
                
                $response = [
                    'success' => true,
                    'message' => "Successfully created $successCount individual items" . ($failedCount > 0 ? " ($failedCount failed)" : ""),
                    'created_items' => $createdItems,
                    'success_count' => $successCount,
                    'failed_count' => $failedCount,
                    'total_quantity' => $quantity
                ];
                
                // Add QR code results
                $qrSuccessCount = 0;
                foreach ($qrResults as $itemId => $qrResult) {
                    if ($qrResult['success']) {
                        $qrSuccessCount++;
                    }
                }
                
                if ($qrSuccessCount > 0) {
                    $response['qr_success_count'] = $qrSuccessCount;
                    $response['qr_message'] = "QR codes generated for $qrSuccessCount out of $successCount items";
                } else {
                    $response['qr_warning'] = 'QR code generation failed for all items, but items were saved successfully';
                }
                
                echo json_encode($response);
                
            } catch (Exception $e) {
                error_log("Transaction failed: " . $e->getMessage());
                $conn->rollback();
                throw $e;
            }
            break;

        case 'update':
            try {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    throw new Exception('Invalid request method for updating item');
                }

                error_log("Update item: Starting update process");

                $required_fields = ['id', 'name', 'department_id', 'category', 'location', 'status'];
                foreach ($required_fields as $field) {
                    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                        error_log("Update item: Missing required field: $field");
                        throw new Exception("Field '$field' is required");
                    }
                }
                
                $id = (int)$_POST['id'];
                $name = trim($_POST['name']);
                $department_id = (int)$_POST['department_id'];
                $category = trim($_POST['category']);
                $location = trim($_POST['location']);
                $status = trim($_POST['status']);
                $description = isset($_POST['description']) ? trim($_POST['description']) : '';
                
                // Ensure description is not null for database
                if ($description === null) {
                    $description = '';
                }
                
                error_log("Update item: ID=$id, Name=$name, Status=$status");
                
                // Handle image upload
                $image_path = null;
                $update_image = false;
                
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    error_log("Update item: Image upload detected");
                    $upload_dir = 'uploads/items/';
                    if (!file_exists($upload_dir)) {
                        if (!mkdir($upload_dir, 0777, true)) {
                            error_log("Update item: Failed to create upload directory");
                            throw new Exception("Failed to create upload directory");
                        }
                    }
                    
                    $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (in_array($file_extension, $allowed_extensions)) {
                        $file_name = 'item_' . time() . '_' . uniqid() . '.' . $file_extension;
                        $file_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $file_path)) {
                            $image_path = $file_path;
                            $update_image = true;
                            error_log("Update item: Image uploaded successfully: $file_path");
                        } else {
                            error_log("Update item: Failed to move uploaded file");
                        }
                    } else {
                        error_log("Update item: Invalid file extension: $file_extension");
                    }
                } else {
                    error_log("Update item: No image upload (error: " . (isset($_FILES['image']) ? $_FILES['image']['error'] : 'not set') . ")");
                }
                
                $valid_statuses = ['Working', 'Under Maintenance', 'Broken', 'Lost', 'Missing', 'Consumable'];
                if (!in_array($status, $valid_statuses)) {
                    error_log("Update item: Invalid status: $status");
                    throw new Exception("Invalid status: $status");
                }
                
                error_log("Update item: Fetching existing item data");
                $item_check = $conn->prepare("SELECT i.id, i.image_path, i.item_table_id, d.name as department_name FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE i.id = ?");
                if (!$item_check) {
                    error_log("Update item: Prepare failed: " . $conn->error);
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $item_check->bind_param("i", $id);
                if (!$item_check->execute()) {
                    error_log("Update item: Execute failed: " . $item_check->error);
                    $item_check->close();
                    throw new Exception("Execute failed: " . $item_check->error);
                }
                $item_res = $item_check->get_result();
                if ($item_res->num_rows === 0) {
                    $item_check->close();
                    error_log("Update item: Item not found: ID=$id");
                    throw new Exception("Item not found");
                }
                $existing_item = $item_res->fetch_assoc();
                $item_check->close();
                
                // Check if item belongs to a consumable table - if so, enforce restrictions
                if ($existing_item['item_table_id']) {
                    $checkConsumableSql = "SELECT COALESCE(is_consumable, 0) as is_consumable FROM item_tables WHERE id = ?";
                    $checkConsumableStmt = $conn->prepare($checkConsumableSql);
                    if ($checkConsumableStmt) {
                        $checkConsumableStmt->bind_param("i", $existing_item['item_table_id']);
                        $checkConsumableStmt->execute();
                        $consumableResult = $checkConsumableStmt->get_result();
                        if ($consumableRow = $consumableResult->fetch_assoc()) {
                            $isConsumableTable = (int)($consumableRow['is_consumable'] ?? 0) === 1;
                            if ($isConsumableTable) {
                                // Get original item data to compare
                                $getOriginalSql = "SELECT name, department_id, category, location FROM items WHERE id = ?";
                                $getOriginalStmt = $conn->prepare($getOriginalSql);
                                $getOriginalStmt->bind_param("i", $id);
                                $getOriginalStmt->execute();
                                $originalResult = $getOriginalStmt->get_result();
                                $originalItem = $originalResult->fetch_assoc();
                                $getOriginalStmt->close();
                                
                                // Prevent name changes
                                if ($originalItem && $name !== $originalItem['name']) {
                                    error_log("Update item: Attempted to change name of consumable item from '{$originalItem['name']}' to '$name' - blocked");
                                    throw new Exception("Cannot change item name for consumable items.");
                                }
                                
                                // Prevent department changes
                                if ($originalItem && $department_id != $originalItem['department_id']) {
                                    error_log("Update item: Attempted to change department of consumable item from {$originalItem['department_id']} to $department_id - blocked");
                                    throw new Exception("Cannot change department for consumable items.");
                                }
                                
                                // Prevent category changes
                                if ($originalItem && $category !== $originalItem['category']) {
                                    error_log("Update item: Attempted to change category of consumable item from '{$originalItem['category']}' to '$category' - blocked");
                                    throw new Exception("Cannot change category for consumable items.");
                                }
                                
                                // Prevent location changes
                                if ($originalItem && $location !== $originalItem['location']) {
                                    error_log("Update item: Attempted to change location of consumable item from '{$originalItem['location']}' to '$location' - blocked");
                                    throw new Exception("Cannot change location for consumable items.");
                                }
                                
                                // Quantity is not editable, so no need to check for quantity changes
                                
                                // Prevent status changes
                                if ($status !== 'Consumable') {
                                    error_log("Update item: Attempted to change status of consumable item from Consumable to $status - blocked");
                                    throw new Exception("Cannot change status of consumable items. Status must remain 'Consumable'.");
                                }
                                
                                // Force status to Consumable
                                $status = 'Consumable';
                                error_log("Update item: Item belongs to consumable table - enforcing restrictions");
                            }
                        }
                        $checkConsumableStmt->close();
                    }
                }
                
                if (!$isAdmin && !$isSuperAdmin && !empty($currentDepartment) && strcasecmp($existing_item['department_name'], $currentDepartment) !== 0) {
                    error_log("Update item: Permission denied - department mismatch");
                    throw new Exception("You can only update items in your own department");
                }
                
                // Delete old image if a new one is being uploaded
                if ($update_image && !empty($existing_item['image_path']) && file_exists($existing_item['image_path'])) {
                    if (@unlink($existing_item['image_path'])) {
                        error_log("Update item: Old image deleted: " . $existing_item['image_path']);
                    }
                }
                
                // Update query - include image_path if new image is uploaded
                error_log("Update item: Preparing update query (update_image=" . ($update_image ? 'true' : 'false') . ")");
                // Use PHP's current date/time to ensure correct year (2025, not 2001)
                $current_datetime = date('Y-m-d H:i:s');
                error_log("Update item: Using current datetime: $current_datetime");
                
                if ($update_image) {
                    // With image: name(s), department_id(i), category(s), location(s), status(s), description(s), image_path(s), updated_at(s), id(i) = 9 params
                    $stmt = $conn->prepare("UPDATE items SET name = ?, department_id = ?, category = ?, location = ?, status = ?, description = ?, image_path = ?, updated_at = ? WHERE id = ?");
                    if (!$stmt) {
                        error_log("Update item: Prepare failed: " . $conn->error);
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    // Type string: s-i-s-s-s-s-s-s-i (9 chars for 9 params)
                    $stmt->bind_param("sissssssi", $name, $department_id, $category, $location, $status, $description, $image_path, $current_datetime, $id);
                } else {
                    // Without image: name(s), department_id(i), category(s), location(s), status(s), description(s), updated_at(s), id(i) = 8 params
                    $stmt = $conn->prepare("UPDATE items SET name = ?, department_id = ?, category = ?, location = ?, status = ?, description = ?, updated_at = ? WHERE id = ?");
                    if (!$stmt) {
                        error_log("Update item: Prepare failed: " . $conn->error);
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    // Type string: s-i-s-s-s-s-s-i (8 chars for 8 params)
                    $stmt->bind_param("sisssssi", $name, $department_id, $category, $location, $status, $description, $current_datetime, $id);
                }
                
                error_log("Update item: Executing update query");
                if ($stmt->execute()) {
                    error_log("Update item: Update successful");
                    $stmt->close();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Item updated successfully'
                    ]);
                } else {
                    $error_msg = $stmt->error;
                    error_log("Update item: Execute failed: " . $error_msg);
                    $stmt->close();
                    throw new Exception("Failed to update item: " . $error_msg);
                }
            } catch (Exception $e) {
                error_log("Update item: Exception caught: " . $e->getMessage());
                error_log("Update item: Stack trace: " . $e->getTraceAsString());
                throw $e;
            }
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for deleting item');
            }

            $id = 0;
            if (isset($_POST['id'])) {
                $id = (int)$_POST['id'];
            } elseif (isset($_GET['id'])) {
                $id = (int)$_GET['id'];
            }
            
            if (!$id) {
                throw new Exception("Valid item ID is required");
            }
            
            $item_check = $conn->prepare("SELECT i.id, i.name, i.qr_code, d.name as department_name FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE i.id = ?");
            if (!$item_check) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $item_check->bind_param("i", $id);
            $item_check->execute();
            $result = $item_check->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Item not found");
            }
            
            $item = $result->fetch_assoc();
            if (!$isAdmin && !$isSuperAdmin && !empty($currentDepartment) && strcasecmp($item['department_name'], $currentDepartment) !== 0) {
                throw new Exception("You can only delete items in your own department");
            }
            
            if (!empty($item['qr_code']) && file_exists($item['qr_code'])) {
                unlink($item['qr_code']);
            }
            
            $stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo json_encode([
                        'success' => true,
                        'message' => "Item '{$item['name']}' deleted successfully"
                    ]);
                } else {
                    throw new Exception("Failed to delete item");
                }
            } else {
                throw new Exception("Delete operation failed: " . $stmt->error);
            }
            break;

        case 'archive':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for archiving item');
            }

            $id = 0;
            if (isset($_POST['id'])) {
                $id = (int)$_POST['id'];
            } elseif (isset($_GET['id'])) {
                $id = (int)$_GET['id'];
            }
            if (!$id) {
                throw new Exception("Valid item ID is required");
            }

            $archived_by = null;
            if (isset($_POST['archived_by']) && !empty($_POST['archived_by'])) {
                $archived_by = sanitizeInput($_POST['archived_by']);
            } elseif (isset($_SESSION['username'])) {
                $archived_by = $_SESSION['username'];
            }

            $conn->begin_transaction();
            try {
                $item_sql = "SELECT i.*, d.name as department_name FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE i.id = ?";
            $item_stmt = $conn->prepare($item_sql);
                if (!$item_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $item_stmt->bind_param("i", $id);
                $item_stmt->execute();
                $item_res = $item_stmt->get_result();
                if ($item_res->num_rows === 0) {
                    throw new Exception("Item not found");
                }
                $item = $item_res->fetch_assoc();

            if (!$isAdmin && !$isSuperAdmin && !empty($currentDepartment) && strcasecmp($item['department_name'], $currentDepartment) !== 0) {
                throw new Exception("You can only archive items in your own department");
            }

                if (!empty($item['qr_code']) && file_exists($item['qr_code'])) {
                    unlink($item['qr_code']);
                }

                $ins_sql = "INSERT INTO archived_items (item_id, name, department_id, department_name, category, quantity, location, status, description, item_code, item_table_id, archived_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
                $ins_stmt = $conn->prepare($ins_sql);
                if (!$ins_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $ins_stmt->bind_param(
                    "isississssss",
                    $item['id'],
                    $item['name'],
                    $item['department_id'],
                    $item['department_name'],
                    $item['category'],
                    $item['quantity'],
                    $item['location'],
                    $item['status'],
                    $item['description'],
                    $item['item_code'],
                    $item['item_table_id'],
                    $archived_by
                );
                if (!$ins_stmt->execute()) {
                    throw new Exception("Failed to archive item: " . $ins_stmt->error);
                }

                $del_stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
                if (!$del_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $del_stmt->bind_param("i", $id);
                if (!$del_stmt->execute() || $del_stmt->affected_rows <= 0) {
                    throw new Exception("Failed to remove item after archiving");
                }

                $conn->commit();
                echo json_encode(['success' => true, 'message' => "Item archived successfully"]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'restore':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for restoring item');
            }

            $id = 0;
            if (isset($_POST['id'])) {
                $id = (int)$_POST['id'];
            } elseif (isset($_GET['id'])) {
                $id = (int)$_GET['id'];
            }
            if (!$id) {
                throw new Exception("Valid archived item ID is required");
            }

            $conn->begin_transaction();
            try {
                $archived_sql = "SELECT * FROM archived_items WHERE id = ?";
            $archived_stmt = $conn->prepare($archived_sql);
                if (!$archived_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $archived_stmt->bind_param("i", $id);
                $archived_stmt->execute();
                $archived_res = $archived_stmt->get_result();
                if ($archived_res->num_rows === 0) {
                    throw new Exception("Archived item not found");
                }
                $archived_item = $archived_res->fetch_assoc();

            if (!$isAdmin && !$isSuperAdmin && !empty($currentDepartment) && strcasecmp($archived_item['department_name'], $currentDepartment) !== 0) {
                throw new Exception("You can only restore items in your own department");
            }

                $existing_check = $conn->prepare("SELECT id FROM items WHERE id = ?");
                if (!$existing_check) {
                    throw new Exception
                    ("Prepare failed: " . $conn->error);
                }
                $existing_check->bind_param("i", $archived_item['item_id']);
                $existing_check->execute();
                if ($existing_check->get_result()->num_rows > 0) {
                    throw new Exception("Item already exists in active items");
                }

                // Use PHP's current date/time to ensure correct year (2025, not 2001)
                $current_datetime = date('Y-m-d H:i:s');
                
                $restore_sql = "INSERT INTO items (id, name, department_id, category, quantity, location, status, description, item_code, item_table_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $restore_stmt = $conn->prepare($restore_sql);
                if (!$restore_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $restore_stmt->bind_param(
                    "isisissssiss",
                    $archived_item['item_id'],
                    $archived_item['name'],
                    $archived_item['department_id'],
                    $archived_item['category'],
                    $archived_item['quantity'],
                    $archived_item['location'],
                    $archived_item['status'],
                    $archived_item['description'],
                    $archived_item['item_code'],
                    $archived_item['item_table_id'],
                    $current_datetime,
                    $current_datetime
                );
                if (!$restore_stmt->execute()) {
                    throw new Exception("Failed to restore item: " . $restore_stmt->error);
                }

                $restoredItemId = $archived_item['item_id'];

                generateAndSaveQRCode($conn, $restoredItemId, [
                    'name' => $archived_item['name'],
                    'department_id' => $archived_item['department_id'],
                    'department_name' => $archived_item['department_name'] ?? null,
                    'category' => $archived_item['category'],
                    'location' => $archived_item['location'],
                    'item_code' => $archived_item['item_code'] ?: 'ITEM-' . $restoredItemId // Use original item_code or fallback
                ]);

                $delete_stmt = $conn->prepare("DELETE FROM archived_items WHERE id = ?");
                if (!$delete_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $delete_stmt->bind_param("i", $id);
                if (!$delete_stmt->execute() || $delete_stmt->affected_rows <= 0) {
                    throw new Exception("Failed to remove item from archive after restore");
                }

                $conn->commit();
                echo json_encode(['success' => true, 'message' => "Item '{$archived_item['name']}' restored successfully"]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;


        case 'borrow':
            // Check if user is logged in (basic check, not strict session validation)
            if (!isset($_SESSION['username']) || !isset($_SESSION['user_id'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'You must be logged in to submit a borrow request.',
                    'session_expired' => true
                ]);
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for borrowing item');
            }

            $conn->begin_transaction();
            
            try {
                $required_fields = ['borrow_id', 'borrower_name', 'item_id', 'quantity', 'borrow_date', 'due_date'];
                foreach ($required_fields as $field) {
                    if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                        throw new Exception("Required field '$field' is missing");
                    }
                }
                
                $borrow_id = sanitizeInput($_POST['borrow_id']);
                $borrower_name = sanitizeInput($_POST['borrower_name']);
                $item_id = (int)$_POST['item_id'];
                $quantity_borrowed = (int)$_POST['quantity'];
                $borrow_date_input = sanitizeInput($_POST['borrow_date']); // Form input (not used for borrow_date)
                $due_date_input = sanitizeInput($_POST['due_date']);
                $date_needed = isset($_POST['date_needed']) && trim($_POST['date_needed']) !== '' ? sanitizeInput($_POST['date_needed']) : null;
                $return_date = !empty($_POST['return_date']) ? sanitizeInput($_POST['return_date']) : null;
                $borrower_email = sanitizeInput($_POST['borrower_email'] ?? '');
                
                // Format dates with timestamps for DATETIME columns
                // For borrow_date: ALWAYS use current server date and time (exact moment of submission)
                // This ensures the Borrow Date reflects when the request was actually submitted, not the form field value
                $borrow_date = date('Y-m-d H:i:s');
                
                // For due_date: if only date is provided, append end of day time (23:59:59)
                if ($due_date_input) {
                    if (strlen($due_date_input) === 10) {
                        // Only date provided (YYYY-MM-DD), append time
                        $due_date = $due_date_input . ' 23:59:59';
                    } else {
                        // Already has time, use as is
                        $due_date = $due_date_input;
                    }
                } else {
                    $due_date = null;
                }
                
                // Validate email format
                if (!filter_var($borrower_email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Invalid email format for borrower email");
                }
                $purpose = sanitizeInput($_POST['purpose'] ?? '');
                
                // Check if date_needed column exists, if not add it
                $check_date_needed = $conn->query("SHOW COLUMNS FROM borrow_history LIKE 'date_needed'");
                if (!$check_date_needed || $check_date_needed->num_rows == 0) {
                    $alter_result = $conn->query("ALTER TABLE borrow_history ADD COLUMN date_needed date DEFAULT NULL AFTER due_date");
                    if (!$alter_result) {
                        error_log("Failed to add date_needed column: " . $conn->error);
                    }
                }
                
                // Check if item_placement column exists, if not add it
                $check_item_placement = $conn->query("SHOW COLUMNS FROM borrow_history LIKE 'item_placement'");
                if (!$check_item_placement || $check_item_placement->num_rows == 0) {
                    $alter_result = $conn->query("ALTER TABLE borrow_history ADD COLUMN item_placement varchar(255) DEFAULT NULL AFTER date_needed");
                    if (!$alter_result) {
                        error_log("Failed to add item_placement column: " . $conn->error);
                    }
                }
                
                // Log date_needed value for debugging
                error_log("date_needed value: " . ($date_needed ?? 'NULL'));
                
                $item_placement = isset($_POST['item_placement']) && trim($_POST['item_placement']) !== '' ? sanitizeInput($_POST['item_placement']) : null;
                
                if ($quantity_borrowed < 1) {
                    throw new Exception("Quantity must be at least 1");
                }
                
                $dup_check = $conn->prepare("SELECT id FROM borrow_history WHERE borrow_id = ?");
                if (!$dup_check) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $dup_check->bind_param("s", $borrow_id);
                $dup_check->execute();
                if ($dup_check->get_result()->num_rows > 0) {
                    throw new Exception("Borrow ID already exists. Please use a different ID.");
                }
                
                $item_query = "SELECT i.*, 
                              i.status,
                              CASE 
                                  WHEN EXISTS (
                                      SELECT 1 FROM borrow_history bh 
                                      WHERE bh.item_id = i.id 
                                      AND bh.status IN ('approved', 'active', 'overdue', 'received')
                                  ) THEN 'Borrowed'
                                  WHEN EXISTS (
                                      SELECT 1 FROM item_tables it 
                                      WHERE it.id = i.item_table_id 
                                      AND COALESCE(it.is_consumable, 0) = 1
                                  ) THEN 'Consumable'
                                  ELSE COALESCE(i.status, 'Working')
                              END as display_status,
                              d.name as department_name 
                              FROM items i 
                              LEFT JOIN departments d ON i.department_id = d.id 
                              WHERE i.id = ?";
                $item_stmt = $conn->prepare($item_query);
                if (!$item_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $item_stmt->bind_param("i", $item_id);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                
                if ($item_result->num_rows === 0) {
                    throw new Exception("Item not found");
                }
                
                $item = $item_result->fetch_assoc();
                // Determine if user is a viewer (borrower) - no department and not admin
                $isViewer = empty($currentDepartment) && !$isAdmin && !$isSuperAdmin;
                
                // Permission: non-admins (except viewers/borrowers) can borrow only from their own department
                // Viewers/borrowers can borrow from any department since they don't have a department
                if (!$isAdmin && !$isSuperAdmin && !$isViewer && !empty($currentDepartment) && strcasecmp($item['department_name'], $currentDepartment) !== 0) {
                    throw new Exception('You can only borrow items from your own department');
                }
                
                // Check if item can be borrowed - cannot borrow if status is Broken, Missing, Lost, Under Maintenance, or Consumable
                $itemStatus = $item['display_status'] ?? $item['status'] ?? 'Unknown';
                $nonBorrowableStatuses = ['Broken', 'Missing', 'Lost', 'Under Maintenance', 'Consumable'];
                if (in_array($itemStatus, $nonBorrowableStatuses)) {
                    if ($itemStatus === 'Consumable') {
                        throw new Exception("Cannot borrow this item. Consumable not available to borrow.");
                    }
                    throw new Exception("Cannot borrow this item. Item status is '$itemStatus' and items with this status cannot be borrowed.");
                }
                
                // Check if item is already borrowed
                if ($itemStatus === 'Borrowed') {
                    throw new Exception('Cannot borrow this item. Item is already borrowed.');
                }
                
                // Additional check: verify if item belongs to a consumable table (double-check)
                if ($item['item_table_id']) {
                    $checkConsumableSql = "SELECT COALESCE(is_consumable, 0) as is_consumable FROM item_tables WHERE id = ?";
                    $checkConsumableStmt = $conn->prepare($checkConsumableSql);
                    if ($checkConsumableStmt) {
                        $checkConsumableStmt->bind_param("i", $item['item_table_id']);
                        $checkConsumableStmt->execute();
                        $consumableResult = $checkConsumableStmt->get_result();
                        if ($consumableRow = $consumableResult->fetch_assoc()) {
                            $isConsumableTable = (int)($consumableRow['is_consumable'] ?? 0) === 1;
                            if ($isConsumableTable) {
                                error_log("Borrow blocked: Item $item_id belongs to consumable table");
                                throw new Exception("Cannot borrow this item. Consumable not available to borrow.");
                            }
                        }
                        $checkConsumableStmt->close();
                    }
                }
                
                // Set initial status to 'pending' for approval workflow
                // Admin needs to approve before status becomes 'active'
                $status = 'pending';
                if ($return_date) {
                    $status = 'returned';
                } elseif (strtotime($due_date) < time()) {
                    $status = 'overdue';
                }
                
                // Log the status being set
                error_log("Setting borrow request status to: $status (borrow_id: $borrow_id, item_id: $item_id, dept: $department_name)");
                
                $days_until_due = (strtotime($due_date) - time()) / (24 * 60 * 60);
                $priority = 'low';
                if ($days_until_due <= 3) {
                    $priority = 'high';
                } elseif ($days_until_due <= 7) {
                    $priority = 'medium';
                }
                
                // Get department name from item - ensure it's not null
                $department_name = $item['department_name'] ?? 'Unknown';
                // If department_name is still null or empty, try to get it from departments table
                if (empty($department_name) || $department_name === 'Unknown') {
                    if (!empty($item['department_id'])) {
                        $deptQuery = $conn->prepare("SELECT name FROM departments WHERE id = ? LIMIT 1");
                        $deptQuery->bind_param("i", $item['department_id']);
                        $deptQuery->execute();
                        $deptResult = $deptQuery->get_result();
                        if ($deptResult && $deptRow = $deptResult->fetch_assoc()) {
                            $department_name = trim($deptRow['name']);
                        }
                        $deptQuery->close();
                    }
                } else {
                    $department_name = trim($department_name);
                }
                
                // Log for debugging
                error_log("=== BORROW REQUEST CREATED ===");
                error_log("Borrow ID: $borrow_id");
                error_log("Item ID: $item_id");
                error_log("Item Name: " . ($item['name'] ?? 'N/A'));
                error_log("Department Name: $department_name");
                error_log("Department ID: " . ($item['department_id'] ?? 'N/A'));
                error_log("Status: $status");
                error_log("Borrower: $borrower_name ($borrower_email)");
                error_log("==============================");
                
                // Handle NULL return_date - use conditional SQL
                if ($return_date) {
                    $borrow_query = "INSERT INTO borrow_history (
                        borrow_id, 
                        borrower_name, 
                        borrower_email,
                        item_id, 
                        item_name,
                        department_name,
                        category,
                        quantity_borrowed, 
                        borrow_date, 
                        due_date, 
                        date_needed,
                        item_placement,
                        return_date,
                        status,
                        priority,
                        purpose
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $borrow_stmt = $conn->prepare($borrow_query);
                    if (!$borrow_stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $borrow_stmt->bind_param("sssisssissssssss", 
                        $borrow_id,
                        $borrower_name,
                        $borrower_email,
                        $item_id,
                        $item['name'],
                        $department_name,
                        $item['category'],
                        $quantity_borrowed,
                        $borrow_date,
                        $due_date,
                        $date_needed,
                        $item_placement,
                        $return_date,
                        $status,
                        $priority,
                        $purpose
                    );
                } else {
                    $borrow_query = "INSERT INTO borrow_history (
                        borrow_id, 
                        borrower_name, 
                        borrower_email,
                        item_id, 
                        item_name,
                        department_name,
                        category,
                        quantity_borrowed, 
                        borrow_date, 
                        due_date, 
                        date_needed,
                        item_placement,
                        return_date,
                        status,
                        priority,
                        purpose
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)";
                    
                    $borrow_stmt = $conn->prepare($borrow_query);
                    if (!$borrow_stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    
                    $borrow_stmt->bind_param("sssisssisssssss", 
                        $borrow_id,
                        $borrower_name,
                        $borrower_email,
                        $item_id,
                        $item['name'],
                        $department_name,
                        $item['category'],
                        $quantity_borrowed,
                        $borrow_date,
                        $due_date,
                        $date_needed,
                        $item_placement,
                        $status,
                        $priority,
                        $purpose
                    );
                }
                
                // Log the values being inserted for debugging
                error_log("Inserting borrow record - date_needed: " . ($date_needed ?? 'NULL') . ", borrow_date: $borrow_date, due_date: $due_date (input was: $borrow_date_input, $due_date_input)");
                
                if (!$borrow_stmt->execute()) {
                    error_log("Borrow execute error: " . $borrow_stmt->error);
                    error_log("SQL Error: " . $borrow_stmt->error);
                    error_log("SQL State: " . $borrow_stmt->sqlstate);
                    throw new Exception("Failed to create borrow record: " . $borrow_stmt->error);
                }
                
                // Verify date_needed was saved
                $verify_date_needed = $conn->prepare("SELECT date_needed FROM borrow_history WHERE borrow_id = ?");
                $verify_date_needed->bind_param("s", $borrow_id);
                $verify_date_needed->execute();
                $verify_result = $verify_date_needed->get_result();
                if ($verify_row = $verify_result->fetch_assoc()) {
                    error_log("Verified date_needed in database: " . ($verify_row['date_needed'] ?? 'NULL'));
                }
                $verify_date_needed->close();
                
                // Verify the record was created - check immediately after insert
                $verifyQuery = $conn->prepare("SELECT borrow_id, status, department_name, created_at FROM borrow_history WHERE borrow_id = ?");
                $verifyQuery->bind_param("s", $borrow_id);
                $verifyQuery->execute();
                $verifyResult = $verifyQuery->get_result();
                if ($verifyRow = $verifyResult->fetch_assoc()) {
                    error_log("VERIFIED: Borrow record created - ID: " . $verifyRow['borrow_id'] . ", Status: " . $verifyRow['status'] . ", Dept: " . $verifyRow['department_name'] . ", Created: " . $verifyRow['created_at']);
                    // Double-check status is 'pending'
                    if ($verifyRow['status'] !== 'pending') {
                        error_log("WARNING: Status is NOT 'pending'! Actual status: " . $verifyRow['status'] . " (Expected: pending)");
                    }
                } else {
                    error_log("ERROR: Borrow record NOT found after insert! Borrow ID: $borrow_id");
                    // Try to find any recent records
                    $checkAll = $conn->query("SELECT borrow_id, status, department_name, created_at FROM borrow_history ORDER BY created_at DESC LIMIT 5");
                    if ($checkAll) {
                        error_log("Last 5 borrow_history records:");
                        while ($row = $checkAll->fetch_assoc()) {
                            error_log("  - " . $row['borrow_id'] . " | Status: " . $row['status'] . " | Dept: " . $row['department_name'] . " | Created: " . $row['created_at']);
                        }
                    }
                }
                $verifyQuery->close();
                
                // NOTE: For pending requests, we DON'T update the item quantity or status yet
                // Quantity will remain unchanged until the request is approved
                // Status will remain "Working" until the request is approved
                // Only update quantity when request is approved (handled in update_borrow_status)
                // Do NOT decrease quantity for pending requests
                if ($status === 'returned') {
                    // Only handle quantity update for returned items (shouldn't happen on new request)
                    // This is just a safety check
                }
                // For pending requests, do nothing - quantity stays the same
                
                // Commit transaction BEFORE sending email (so record is saved even if email fails)
                $conn->commit();
                
                // Double-check the record was saved after commit
                $finalCheck = $conn->prepare("SELECT borrow_id, status, department_name FROM borrow_history WHERE borrow_id = ?");
                $finalCheck->bind_param("s", $borrow_id);
                $finalCheck->execute();
                $finalResult = $finalCheck->get_result();
                if ($finalRow = $finalResult->fetch_assoc()) {
                    error_log("FINAL CHECK: Record confirmed in database after commit - Status: " . $finalRow['status'] . ", Dept: " . $finalRow['department_name']);
                } else {
                    error_log("CRITICAL ERROR: Record NOT found after commit! Borrow ID: $borrow_id");
                }
                $finalCheck->close();
                
                // Send confirmation email to borrower
                require_once __DIR__ . '/email_notifications.php';
                if (function_exists('sendBorrowConfirmationEmail')) {
                    try {
                        sendBorrowConfirmationEmail(
                            $borrower_email,
                            $borrower_name,
                            $item['name'],
                            $borrow_date,
                            $due_date
                        );
                        error_log("Borrow confirmation email sent to: $borrower_email");
                    } catch (Exception $e) {
                        error_log("Failed to send borrow confirmation email: " . $e->getMessage());
                        // Don't fail the transaction if email fails
                    }
                }
                
                // Send automatic overdue email if item is already overdue
                if ($status === 'overdue' && !empty($borrower_email)) {
                    require_once __DIR__ . '/email_notifications.php';
                    
                    $dueDate = new DateTime($due_date);
                    $todayDate = new DateTime();
                    $daysOverdue = $todayDate->diff($dueDate)->days;
                    
                    try {
                        $emailSent = sendOverdueItemEmail(
                            $borrower_email,
                            $borrower_name,
                            $item['name'],
                            $due_date,
                            $daysOverdue
                        );
                        
                        if ($emailSent) {
                            error_log("Automatic overdue email sent during borrow to: " . $borrower_email . " for item: " . $item['name']);
                        }
                    } catch (Exception $e) {
                        error_log("Failed to send automatic overdue email during borrow: " . $e->getMessage());
                    }
                }
                
                $borrow_record = [
                    'borrow_id' => $borrow_id,
                    'borrower_name' => $borrower_name,
                    'item_name' => $item['name'],
                    'department_name' => $department_name,
                    'quantity' => $quantity_borrowed,
                    'borrow_date' => $borrow_date,
                    'due_date' => $due_date,
                    'status' => $status,
                    'priority' => $priority
                ];
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Item borrowed successfully',
                    'borrow_record' => $borrow_record,
                    'new_quantity' => $new_quantity
                ]);
                
            } catch (Exception $e) {
                $conn->rollback();
                error_log("=== BORROW REQUEST ERROR ===");
                error_log("Error message: " . $e->getMessage());
                error_log("Error trace: " . $e->getTraceAsString());
                error_log("User: " . ($_SESSION['username'] ?? 'N/A'));
                error_log("Item ID: " . ($_POST['item_id'] ?? 'N/A'));
                error_log("Borrow ID: " . ($_POST['borrow_id'] ?? 'N/A'));
                error_log("===========================");
                
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to submit borrow request: ' . $e->getMessage(),
                    'error' => $e->getMessage()
                ]);
                exit;
            }
            break;

        case 'get_pending_borrow_requests':
            // Allow admins, super admins, and users with departments (department heads)
            $hasDepartment = !empty($currentDepartment);
            if (!$isAdmin && !$isSuperAdmin && !$hasDepartment) {
                throw new Exception('Unauthorized - Admin or department head access required');
            }
            
            try {
                // Get admin's department - trim and normalize
                $adminDepartment = isset($_SESSION['department']) ? trim($_SESSION['department']) : null;
                
                // Get department filter from GET parameter (for super admin filtering by selected department)
                $filterDepartment = isset($_GET['department']) ? trim($_GET['department']) : '';
                
                // Build query - filter by department if provided (even for super admin)
                if ($isSuperAdmin && !empty($filterDepartment)) {
                    // Super admin filtering by selected department
                    $query = "SELECT bh.*, i.name as item_name, i.category, i.department_id, d.name as dept_name
                             FROM borrow_history bh 
                             LEFT JOIN items i ON bh.item_id = i.id 
                             LEFT JOIN departments d ON i.department_id = d.id
                             WHERE bh.status = 'pending' 
                             AND d.name = ?
                             ORDER BY bh.created_at DESC";
                    $stmt = $conn->prepare($query);
                    if ($stmt) {
                        $stmt->bind_param("s", $filterDepartment);
                    }
                } elseif ($isSuperAdmin) {
                    // Super admin with no department filter: can see all pending requests
                    $query = "SELECT bh.*, i.name as item_name, i.category, i.department_id, d.name as dept_name
                             FROM borrow_history bh 
                             LEFT JOIN items i ON bh.item_id = i.id 
                             LEFT JOIN departments d ON i.department_id = d.id
                             WHERE bh.status = 'pending' 
                             ORDER BY bh.created_at DESC";
                    $stmt = $conn->prepare($query);
                } else if ($hasDepartment || $isAdmin) {
                    // Regular admin or user with department can see requests from their department
                    // Regular admin can only see requests from their department
                    // Use case-insensitive comparison and also check against items table department
                    if (empty($adminDepartment)) {
                        throw new Exception('Admin department not set in session');
                    }
                    
                    // Get admin's department ID for more reliable matching
                    $deptIdQuery = $conn->prepare("SELECT id FROM departments WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                    $deptIdQuery->bind_param("s", $adminDepartment);
                    $deptIdQuery->execute();
                    $deptResult = $deptIdQuery->get_result();
                    $adminDeptId = null;
                    if ($deptResult && $deptRow = $deptResult->fetch_assoc()) {
                        $adminDeptId = (int)$deptRow['id'];
                    }
                    $deptIdQuery->close();
                    
                    // Query using both department_name in borrow_history and department_id in items
                    // This handles cases where department_name might have slight variations
                    if ($adminDeptId) {
                        $query = "SELECT bh.*, i.name as item_name, i.category, i.department_id, d.name as dept_name
                                 FROM borrow_history bh 
                                 LEFT JOIN items i ON bh.item_id = i.id 
                                 LEFT JOIN departments d ON i.department_id = d.id
                                 WHERE bh.status = 'pending' 
                                 AND (LOWER(TRIM(bh.department_name)) = LOWER(TRIM(?)) OR i.department_id = ?)
                                 ORDER BY bh.created_at DESC";
                        $stmt = $conn->prepare($query);
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        $stmt->bind_param("si", $adminDepartment, $adminDeptId);
                    } else {
                        // Fallback to department_name only if department ID not found
                        $query = "SELECT bh.*, i.name as item_name, i.category, i.department_id, d.name as dept_name
                                 FROM borrow_history bh 
                                 LEFT JOIN items i ON bh.item_id = i.id 
                                 LEFT JOIN departments d ON i.department_id = d.id
                                 WHERE bh.status = 'pending' 
                                 AND LOWER(TRIM(bh.department_name)) = LOWER(TRIM(?))
                                 ORDER BY bh.created_at DESC";
                        $stmt = $conn->prepare($query);
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        $stmt->bind_param("s", $adminDepartment);
                    }
                }
                
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->execute();
                $result = $stmt->get_result();
                $requests = [];
                
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $requests[] = $row;
                    }
                }
                
                $stmt->close();
                
                echo json_encode([
                    'success' => true,
                    'requests' => $requests
                ]);
            } catch (Exception $e) {
                error_log("Error fetching borrow requests: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => $e->getMessage()
                ]);
            }
            break;

        case 'update_borrow_status':
            // Allow admins, super admins, and users with departments (department heads)
            $hasDepartment = !empty($currentDepartment);
            if (!$isAdmin && !$isSuperAdmin && !$hasDepartment) {
                throw new Exception('Unauthorized - Admin or department head access required');
            }
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method');
            }
            
            $borrow_id = sanitizeInput($_POST['borrow_id'] ?? '');
            $new_status = sanitizeInput($_POST['status'] ?? '');
            
            if (empty($borrow_id) || !in_array($new_status, ['approved', 'declined'])) {
                throw new Exception('Invalid parameters');
            }
            
            $conn->begin_transaction();
            
            try {
                // Get admin's department for filtering - trim and normalize
                $adminDepartment = isset($_SESSION['department']) ? trim($_SESSION['department']) : null;
                
                // Get borrow record details - filter by department unless super admin
                if ($isSuperAdmin) {
                    $get_stmt = $conn->prepare("SELECT * FROM borrow_history WHERE borrow_id = ? AND status = 'pending'");
                    $get_stmt->bind_param("s", $borrow_id);
                } else {
                    // Regular admin can only approve/decline requests from their department
                    // Use case-insensitive comparison and also check against items table department
                    if (empty($adminDepartment)) {
                        throw new Exception('Admin department not set in session');
                    }
                    
                    // Get admin's department ID for more reliable matching
                    $deptIdQuery = $conn->prepare("SELECT id FROM departments WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                    $deptIdQuery->bind_param("s", $adminDepartment);
                    $deptIdQuery->execute();
                    $deptResult = $deptIdQuery->get_result();
                    $adminDeptId = null;
                    if ($deptResult && $deptRow = $deptResult->fetch_assoc()) {
                        $adminDeptId = (int)$deptRow['id'];
                    }
                    $deptIdQuery->close();
                    
                    // Query using both department_name and department_id from items table
                    if ($adminDeptId) {
                        $get_stmt = $conn->prepare("SELECT bh.* FROM borrow_history bh 
                                                   LEFT JOIN items i ON bh.item_id = i.id 
                                                   WHERE bh.borrow_id = ? 
                                                   AND bh.status = 'pending' 
                                                   AND (LOWER(TRIM(bh.department_name)) = LOWER(TRIM(?)) OR i.department_id = ?)");
                        $get_stmt->bind_param("ssi", $borrow_id, $adminDepartment, $adminDeptId);
                    } else {
                        // Fallback to department_name only
                        $get_stmt = $conn->prepare("SELECT * FROM borrow_history WHERE borrow_id = ? AND status = 'pending' AND LOWER(TRIM(department_name)) = LOWER(TRIM(?))");
                        $get_stmt->bind_param("ss", $borrow_id, $adminDepartment);
                    }
                }
                
                $get_stmt->execute();
                $result = $get_stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception('Pending borrow request not found or you do not have permission to manage this request');
                }
                
                $borrow_record = $result->fetch_assoc();
                $get_stmt->close();
                
                if ($new_status === 'approved') {
                    // Update status to 'approved' (not 'active')
                    $update_stmt = $conn->prepare("UPDATE borrow_history SET status = 'approved', updated_at = NOW() WHERE borrow_id = ?");
                    $update_stmt->bind_param("s", $borrow_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Update item quantity and status when request is approved
                    // Note: Quantity was already decreased when request was submitted (can be negative for pending)
                    // Now that it's approved, we finalize the quantity and update status to "Borrowed" if needed
                    $item_id = $borrow_record['item_id'];
                    $quantity_borrowed = $borrow_record['quantity_borrowed'];
                    
                    // Lock the item row to prevent race conditions when multiple approvals happen simultaneously
                    $item_stmt = $conn->prepare("SELECT quantity, status FROM items WHERE id = ? FOR UPDATE");
                    $item_stmt->bind_param("i", $item_id);
                    $item_stmt->execute();
                    $item_result = $item_stmt->get_result();
                    $item = $item_result->fetch_assoc();
                    $item_stmt->close();
                    
                    if ($item) {
                        // NOW decrease quantity when request is approved
                        // Quantity was NOT decreased when request was submitted (pending status)
                        $current_quantity = (int)$item['quantity'];
                        $new_quantity = $current_quantity - (int)$quantity_borrowed;
                        // Ensure quantity doesn't go below 0
                        $new_quantity = max(0, $new_quantity);
                        
                        // Update status to "Borrowed" if quantity becomes 0
                        // Check if display_status column exists
                        $check_col = $conn->query("SHOW COLUMNS FROM items LIKE 'display_status'");
                        // Use PHP's current date/time to ensure correct year (2025, not 2001)
                        $current_datetime = date('Y-m-d H:i:s');
                        
                        if ($check_col && $check_col->num_rows > 0) {
                            // Column exists, update display_status to 'Borrowed' if quantity is 0
                            $displayStatus = ($new_quantity <= 0) ? 'Borrowed' : null;
                            if ($displayStatus !== null) {
                                $update_item = $conn->prepare("UPDATE items SET quantity = ?, display_status = ?, updated_at = ? WHERE id = ?");
                                $update_item->bind_param("issi", $new_quantity, $displayStatus, $current_datetime, $item_id);
                            } else {
                                // Keep status as "Working" if quantity > 0
                                $update_item = $conn->prepare("UPDATE items SET quantity = ?, updated_at = ? WHERE id = ?");
                                $update_item->bind_param("isi", $new_quantity, $current_datetime, $item_id);
                            }
                        } else {
                            // Column doesn't exist, update status field directly
                            $itemStatus = ($new_quantity <= 0) ? 'Borrowed' : 'Working';
                            $update_item = $conn->prepare("UPDATE items SET quantity = ?, status = ?, updated_at = ? WHERE id = ?");
                            $update_item->bind_param("issi", $new_quantity, $itemStatus, $current_datetime, $item_id);
                        }
                        
                        $update_item->execute();
                        $update_item->close();
                    }
                    
                    // Send approval email
                    require_once __DIR__ . '/email_notifications.php';
                    if (function_exists('sendBorrowApprovalEmail')) {
                        sendBorrowApprovalEmail(
                            $borrow_record['borrower_email'],
                            $borrow_record['borrower_name'],
                            $borrow_record['item_name'],
                            $borrow_record['borrow_date'],
                            $borrow_record['due_date']
                        );
                    }
                    
                    // Auto-decline all other pending requests for the same item
                    // When one request is approved, the item is no longer available for other pending requests
                    $otherPendingQuery = $conn->prepare("SELECT borrow_id, borrower_name, borrower_email, item_name, quantity_borrowed 
                                                          FROM borrow_history 
                                                          WHERE item_id = ? 
                                                          AND status = 'pending' 
                                                          AND borrow_id != ?");
                    $otherPendingQuery->bind_param("is", $item_id, $borrow_id);
                    $otherPendingQuery->execute();
                    $otherPendingResult = $otherPendingQuery->get_result();
                    
                    $declinedCount = 0;
                    while ($otherRequest = $otherPendingResult->fetch_assoc()) {
                        // Decline this other pending request
                        $declineStmt = $conn->prepare("UPDATE borrow_history SET status = 'declined', updated_at = NOW() WHERE borrow_id = ?");
                        $declineStmt->bind_param("s", $otherRequest['borrow_id']);
                        $declineStmt->execute();
                        $declineStmt->close();
                        
                        // No need to restore quantity - quantity was NOT decreased when request was submitted (pending status)
                        // Quantity only decreases when request is approved
                        
                        // Send decline email to the borrower
                        if (function_exists('sendBorrowRejectionEmail')) {
                            try {
                                sendBorrowRejectionEmail(
                                    $otherRequest['borrower_email'],
                                    $otherRequest['borrower_name'],
                                    $otherRequest['item_name']
                                );
                                error_log("Auto-declined and sent email to: " . $otherRequest['borrower_email'] . " for item: " . $otherRequest['item_name']);
                            } catch (Exception $e) {
                                error_log("Failed to send auto-decline email: " . $e->getMessage());
                            }
                        }
                        
                        $declinedCount++;
                    }
                    $otherPendingQuery->close();
                    
                    if ($declinedCount > 0) {
                        error_log("Auto-declined $declinedCount other pending request(s) for item ID: $item_id");
                    }
                } else {
                    // Update status to 'declined'
                    $update_stmt = $conn->prepare("UPDATE borrow_history SET status = 'declined', updated_at = NOW() WHERE borrow_id = ?");
                    $update_stmt->bind_param("s", $borrow_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // No need to restore quantity since request was declined
                    // Quantity was NOT decreased when request was submitted (pending status)
                    // Quantity only decreases when request is approved, so nothing to restore here
                    
                    // Send decline email
                    require_once __DIR__ . '/email_notifications.php';
                    if (function_exists('sendBorrowRejectionEmail')) {
                        sendBorrowRejectionEmail(
                            $borrow_record['borrower_email'],
                            $borrow_record['borrower_name'],
                            $borrow_record['item_name']
                        );
                    }
                }
                
                $conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Borrow request ' . $new_status . ' successfully'
                ]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'delete_archived':
            // Prevent department heads from deleting archived items
            $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
            $is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
            $is_department_head = $is_admin && !$is_super_admin;
            
            if ($is_department_head) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to delete archived items. Only restore is allowed.']);
                break;
            }
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for deleting archived item');
            }

            $itemId = intval($_POST['id']);
            
            try {
                $conn->begin_transaction();
                
                // Get archived item details
                $stmt = $conn->prepare("SELECT * FROM archived_items WHERE id = ?");
                $stmt->bind_param("i", $itemId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Archived item not found");
                }
                
                $archived_item = $result->fetch_assoc();
                
                // Check permissions
                if (!$isAdmin && !$isSuperAdmin && !empty($currentDepartment) && strcasecmp($archived_item['department_name'], $currentDepartment) !== 0) {
                    throw new Exception("You can only delete items in your own department");
                }
                
                // Ensure deleted_items table exists (minimal schema capturing important fields)
                $conn->query("CREATE TABLE IF NOT EXISTS deleted_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    archived_id INT NULL,
                    name VARCHAR(255) NULL,
                    department_name VARCHAR(255) NULL,
                    category VARCHAR(255) NULL,
                    status VARCHAR(50) NULL,
                    location VARCHAR(255) NULL,
                    description TEXT NULL,
                    archived_by VARCHAR(255) NULL,
                    archived_at DATETIME NULL,
                    deleted_by VARCHAR(255) NULL,
                    deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

                // Move record into deleted_items
                $deletedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $insert_deleted = $conn->prepare("INSERT INTO deleted_items (archived_id, name, department_name, category, status, location, description, archived_by, archived_at, deleted_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $insert_deleted->bind_param(
                    "isssssssss",
                    $archived_item['id'],
                    $archived_item['name'],
                    $archived_item['department_name'],
                    $archived_item['category'],
                    $archived_item['status'],
                    $archived_item['location'],
                    $archived_item['description'],
                    $archived_item['archived_by'],
                    $archived_item['archived_at'],
                    $deletedBy
                );
                if (!$insert_deleted->execute()) {
                    throw new Exception("Failed to move to deleted_items: " . $insert_deleted->error);
                }
                $insert_deleted->close();

                // Remove from archived_items after moving
                $delete_stmt = $conn->prepare("DELETE FROM archived_items WHERE id = ?");
                $delete_stmt->bind_param("i", $itemId);
                
                if (!$delete_stmt->execute() || $delete_stmt->affected_rows <= 0) {
                    throw new Exception("Failed to delete archived item");
                }
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => "Item '{$archived_item['name']}' moved to Deleted table"]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'delete_archived_category':
            // Prevent department heads from deleting archived categories
            $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
            $is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
            $is_department_head = $is_admin && !$is_super_admin;
            
            if ($is_department_head) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to delete archived items. Only restore is allowed.']);
                break;
            }
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for deleting archived category');
            }

            $categoryId = intval($_POST['id']);
            
            try {
                $conn->begin_transaction();
                
                // Get archived category details
                $stmt = $conn->prepare("SELECT * FROM archived_categories WHERE id = ?");
                $stmt->bind_param("i", $categoryId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Archived category not found");
                }
                
                $archived_category = $result->fetch_assoc();
                
                // Ensure deleted_categories table exists
                $conn->query("CREATE TABLE IF NOT EXISTS deleted_categories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    archived_id INT NULL,
                    name VARCHAR(255) NULL,
                    account VARCHAR(255) NULL,
                    archived_by VARCHAR(255) NULL,
                    archived_at DATETIME NULL,
                    deleted_by VARCHAR(255) NULL,
                    deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

                // Move record into deleted_categories
                $deletedBy = isset($_SESSION['username']) ? $_SESSION['username'] : 'system';
                $insert_deleted = $conn->prepare("INSERT INTO deleted_categories (archived_id, name, account, archived_by, archived_at, deleted_by) VALUES (?,?,?,?,?,?)");
                $insert_deleted->bind_param(
                    "isssss",
                    $archived_category['id'],
                    $archived_category['name'],
                    $archived_category['account'],
                    $archived_category['archived_by'],
                    $archived_category['archived_at'],
                    $deletedBy
                );
                if (!$insert_deleted->execute()) {
                    throw new Exception("Failed to move to deleted_categories: " . $insert_deleted->error);
                }
                $insert_deleted->close();

                // Remove from archived_categories after moving
                $delete_stmt = $conn->prepare("DELETE FROM archived_categories WHERE id = ?");
                $delete_stmt->bind_param("i", $categoryId);
                
                if (!$delete_stmt->execute() || $delete_stmt->affected_rows <= 0) {
                    throw new Exception("Failed to delete archived category");
                }
                
                $conn->commit();
                echo json_encode(['success' => true, 'message' => "Category '{$archived_category['name']}' moved to Deleted table"]);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;

        case 'request_item':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for item request');
            }

            $requested_by = isset($_SESSION['username']) ? $_SESSION['username'] : 'unknown';
            $department_name = isset($_SESSION['department']) ? $_SESSION['department'] : 'Unknown';
            $item_name = trim($_POST['item_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $quantity = (int)($_POST['quantity'] ?? 1);
            $notes = trim($_POST['notes'] ?? '');

            if ($item_name === '' || $quantity < 1) {
                throw new Exception('Item name and valid quantity are required');
            }

            // Validate category belongs to user's department for head departments
            $isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
            $isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
            $isDepartmentHead = $isAdmin && !$isSuperAdmin; // Head department (admin but not super admin)
            
            if ($isDepartmentHead && !empty($category) && !empty($department_name)) {
                // Get user's department ID
                $deptStmt = $conn->prepare("SELECT id FROM departments WHERE name = ? LIMIT 1");
                if ($deptStmt) {
                    $deptStmt->bind_param('s', $department_name);
                    $deptStmt->execute();
                    $deptResult = $deptStmt->get_result();
                    if ($deptRow = $deptResult->fetch_assoc()) {
                        $userDeptId = (int)$deptRow['id'];
                        
                        // Check if category belongs to user's department
                        $checkCol = $conn->query("SHOW COLUMNS FROM categories LIKE 'department_id'");
                        if ($checkCol && $checkCol->num_rows > 0) {
                            $catStmt = $conn->prepare("SELECT department_id FROM categories WHERE name = ? LIMIT 1");
                            if ($catStmt) {
                                $catStmt->bind_param('s', $category);
                                $catStmt->execute();
                                $catResult = $catStmt->get_result();
                                if ($catRow = $catResult->fetch_assoc()) {
                                    $categoryDeptId = (int)$catRow['department_id'];
                                    if ($categoryDeptId !== $userDeptId) {
                                        throw new Exception('You can only request items from categories that belong to your department.');
                                    }
                                }
                                $catStmt->close();
                            }
                        }
                    }
                    $deptStmt->close();
                }
            }

            $date_needed = isset($_POST['date_needed']) && !empty($_POST['date_needed']) ? $_POST['date_needed'] : null;
            $stmt = $conn->prepare("INSERT INTO item_requests (requested_by, department_name, item_name, category, quantity, notes, date_needed) VALUES (?,?,?,?,?,?,?)");
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            // types: s s s s i s s
            $stmt->bind_param('ssssiss', $requested_by, $department_name, $item_name, $category, $quantity, $notes, $date_needed);

            if (!$stmt->execute()) {
                throw new Exception('Failed to submit request: ' . $stmt->error);
            }

            echo json_encode(['success' => true, 'message' => 'Request submitted']);
            break;

        case 'move_location':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method for moving item location');
            }

            $id = 0;
            if (isset($_POST['id'])) {
                $id = (int)$_POST['id'];
            } elseif (isset($_GET['id'])) {
                $id = (int)$_GET['id'];
            }
            if (!$id) {
                throw new Exception("Valid item ID is required");
            }

            $newLocation = '';
            if (isset($_POST['location']) && !empty($_POST['location'])) {
                $newLocation = sanitizeInput($_POST['location']);
            } elseif (isset($_GET['location']) && !empty($_GET['location'])) {
                $newLocation = sanitizeInput($_GET['location']);
            }
            if (empty($newLocation)) {
                throw new Exception("New location is required");
            }

            try {
                // Get current item details
                $item_sql = "SELECT i.*, d.name as department_name FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE i.id = ?";
                $item_stmt = $conn->prepare($item_sql);
                if (!$item_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $item_stmt->bind_param("i", $id);
                $item_stmt->execute();
                $item_res = $item_stmt->get_result();
                if ($item_res->num_rows === 0) {
                    throw new Exception("Item not found");
                }
                $item = $item_res->fetch_assoc();

                // Check permissions
                if (!$isAdmin && !$isSuperAdmin && !empty($currentDepartment) && strcasecmp($item['department_name'], $currentDepartment) !== 0) {
                    throw new Exception("You can only move items in your own department");
                }

                // Update item location
                $update_sql = "UPDATE items SET location = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if (!$update_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $update_stmt->bind_param("si", $newLocation, $id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update item location: " . $update_stmt->error);
                }
                
                if ($update_stmt->affected_rows <= 0) {
                    throw new Exception("No changes made to item location");
                }

                echo json_encode(['success' => true, 'message' => "Item '{$item['name']}' moved to '{$newLocation}' successfully"]);
            } catch (Exception $e) {
                throw $e;
            }
            break;

        case 'get_locations':
            try {
                $locations_sql = "SELECT 
                    b.id as building_id,
                    b.name as building_name,
                    f.id as floor_id,
                    f.floor_number,
                    f.floor_name,
                    r.id as room_id,
                    r.room_number,
                    r.room_name,
                    CONCAT(b.name, ', Floor ', f.floor_number, ', ', 
                           CASE 
                               WHEN r.room_name IS NOT NULL AND TRIM(r.room_name) != '' THEN r.room_name
                               ELSE r.room_number
                           END) as full_location
                FROM buildings b
                LEFT JOIN floors f ON b.id = f.building_id
                LEFT JOIN rooms r ON f.id = r.floor_id
                WHERE r.id IS NOT NULL
                ORDER BY b.name, f.floor_number, r.room_number";
                
                $locations_result = $conn->query($locations_sql);
                
                if (!$locations_result) {
                    throw new Exception("Failed to fetch locations: " . $conn->error);
                }
                
                $locations = [];
                while ($row = $locations_result->fetch_assoc()) {
                    $locations[] = [
                        'building_id' => (int)$row['building_id'],
                        'building_name' => htmlspecialchars($row['building_name'], ENT_QUOTES, 'UTF-8'),
                        'floor_id' => (int)$row['floor_id'],
                        'floor_number' => (int)$row['floor_number'],
                        'floor_name' => htmlspecialchars($row['floor_name'], ENT_QUOTES, 'UTF-8'),
                        'room_id' => (int)$row['room_id'],
                        'room_number' => htmlspecialchars($row['room_number'], ENT_QUOTES, 'UTF-8'),
                        'room_name' => htmlspecialchars($row['room_name'], ENT_QUOTES, 'UTF-8'),
                        'full_location' => htmlspecialchars($row['full_location'], ENT_QUOTES, 'UTF-8')
                    ];
                }
                
                echo json_encode(['success' => true, 'locations' => $locations]);
            } catch (Exception $e) {
                throw $e;
            }
            break;

        case 'get_categories':
            try {
                $deptId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
                // Check if department_id column exists
                $hasDeptCol = false;
                $check_col = $conn->query("SHOW COLUMNS FROM categories LIKE 'department_id'");
                if ($check_col && $check_col->num_rows > 0) {
                    $hasDeptCol = true;
                }
                
                if ($hasDeptCol && $deptId > 0) {
                    // Filter by department_id
                    $stmt = $conn->prepare("SELECT DISTINCT name FROM categories WHERE department_id = ? ORDER BY name ASC");
                    if (!$stmt) {
                        throw new Exception('Prepare failed: ' . $conn->error);
                    }
                    $stmt->bind_param('i', $deptId);
                    $stmt->execute();
                    $categories_result = $stmt->get_result();
                    $stmt->close();
                } else {
                    // No department filter - get all categories
                    $categories_result = $conn->query("SELECT DISTINCT name FROM categories ORDER BY name ASC");
                }
                
                if (!$categories_result) {
                    throw new Exception("Failed to fetch categories: " . $conn->error);
                }
                
                $categories = [];
                while ($row = $categories_result->fetch_assoc()) {
                    $categories[] = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
                }
                
                echo json_encode(['success' => true, 'categories' => $categories]);
            } catch (Exception $e) {
                throw $e;
            }
            break;

            case 'check_session':
                try {
                    // Check database connection and availability FIRST
                    if (!$conn || $conn->connect_error) {
                        echo json_encode([
                            'success' => false,
                            'database_error' => true,
                            'database_corrupted' => true,
                            'message' => 'Database connection failed'
                        ]);
                        break;
                    }
                    
                    // Test if database is working by running a simple query
                    try {
                        $test_query = $conn->query("SELECT 1");
                        if (!$test_query) {
                            echo json_encode([
                                'success' => false,
                                'database_error' => true,
                                'database_corrupted' => true,
                                'message' => 'Database query failed'
                            ]);
                            break;
                        }
                    } catch (Exception $db_error) {
                        echo json_encode([
                            'success' => false,
                            'database_error' => true,
                            'database_corrupted' => true,
                            'message' => 'Database exception: ' . $db_error->getMessage()
                        ]);
                        break;
                    }
                    
                    // Now check if session exists
                    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
                        echo json_encode(['success' => false, 'message' => 'No session found', 'session_expired' => true]);
                        break;
                    }
                    
                    // Super admin sessions are validated via PHP session only
                    if (isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1) {
                        // Keep behavior consistent with session_validation.php
                        $_SESSION['last_activity'] = time();
                        echo json_encode(['success' => true, 'message' => 'Session is valid (super admin)']);
                        break;
                    }
                    
                    // Check session in user_sessions table
                    $sessionId = session_id();
                    $userId = $_SESSION['user_id'];
                    
                    // Check if account is locked FIRST (before checking session)
                    $check_lock_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked'");
                    $has_lock_columns = ($check_lock_columns && $check_lock_columns->num_rows > 0);
                    
                    if ($has_lock_columns) {
                        $lockCheck = $conn->prepare("SELECT COALESCE(account_locked, 0) as account_locked FROM users WHERE id = ?");
                        if ($lockCheck) {
                            $lockCheck->bind_param("i", $userId);
                            $lockCheck->execute();
                            $lockResult = $lockCheck->get_result();
                            
                            if ($lockResult->num_rows === 1) {
                                $lockData = $lockResult->fetch_assoc();
                                
                                // If account is locked, logout immediately
                                if ((int)$lockData['account_locked'] === 1) {
                                    // Deactivate all sessions for this user
                                    $deactivateAll = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
                                    if ($deactivateAll) {
                                        $deactivateAll->bind_param("i", $userId);
                                        $deactivateAll->execute();
                                        $deactivateAll->close();
                                    }
                                    
                                    $lockCheck->close();
                                    
                                    // Destroy session
                                    session_destroy();
                                    
                    // Return account locked response
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Account locked', 
                        'account_locked' => true,
                        'redirect' => 'login.php'
                    ]);
                                    break;
                                }
                            }
                            $lockCheck->close();
                        }
                    }
                    
                    try {
                        // Check if session exists and is active
                        $stmt = $conn->prepare("SELECT is_active FROM user_sessions WHERE session_id = ? AND user_id = ?");
                        
                        if (!$stmt) {
                            // If prepare fails, might be database issue
                            echo json_encode([
                                'success' => false,
                                'database_error' => true,
                                'message' => 'Database prepare failed: ' . $conn->error
                            ]);
                            break;
                        }
                        
                        $stmt->bind_param("si", $sessionId, $userId);
                        
                        if (!$stmt->execute()) {
                            // If execute fails, might be database issue
                            echo json_encode([
                                'success' => false,
                                'database_error' => true,
                                'message' => 'Database execute failed: ' . $stmt->error
                            ]);
                            $stmt->close();
                            break;
                        }
                        
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows === 0) {
                            // Session not found in database, invalidate
                            $stmt->close();
                            session_destroy();
                            echo json_encode(['success' => false, 'message' => 'Session not found', 'session_expired' => true]);
                        } else {
                            $session = $result->fetch_assoc();
                            if (!$session['is_active']) {
                                // Session deactivated by another login
                                $stmt->close();
                                session_destroy();
                                echo json_encode(['success' => false, 'message' => 'Session invalidated by another device', 'session_invalidated' => true]);
                            } else {
                                // Update last activity and return success
                                $stmt->close();
                                
                                try {
                                    $updateStmt = $conn->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ?");
                                    if ($updateStmt) {
                                        $updateStmt->bind_param("s", $sessionId);
                                        $updateStmt->execute();
                                        $updateStmt->close();
                                    }
                                } catch (Exception $update_error) {
                                    // Log but don't fail on update error
                                    error_log("Failed to update session activity: " . $update_error->getMessage());
                                }
                                
                                echo json_encode(['success' => true, 'message' => 'Session is valid']);
                            }
                        }
                    } catch (Exception $query_error) {
                        // Any database query error might indicate corrupted database
                        echo json_encode([
                            'success' => false,
                            'database_error' => true,
                            'message' => 'Database query error: ' . $query_error->getMessage()
                        ]);
                    }
                } catch (Exception $e) {
                    // Catch-all for any unexpected errors
                    echo json_encode([
                        'success' => false,
                        'database_error' => true,
                        'message' => 'Session check failed: ' . $e->getMessage()
                    ]);
                }
            break;

        case 'logout_session':
            try {
                $sessionId = session_id();
                $userId = $_SESSION['user_id'] ?? null;
                
                if ($sessionId && $userId) {
                    // Deactivate current session
                    $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_id = ? AND user_id = ?");
                    $stmt->bind_param("si", $sessionId, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Clear session data
                $_SESSION = [];
                session_destroy();
                
                echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            } catch (Exception $e) {
                throw $e;
            }
            break;

        case 'get_qr_requests':
            if (!$isAdmin) {
                throw new Exception('Unauthorized');
            }
            
            $status = $_GET['status'] ?? 'all';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            $whereClause = "";
            if ($status !== 'all' && in_array($status, ['pending', 'approved', 'rejected'])) {
                $whereClause = "WHERE qr.status = '" . $conn->real_escape_string($status) . "'";
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total
                    FROM qr_requests qr
                    LEFT JOIN item_tables it ON qr.item_table_id = it.id
                    LEFT JOIN departments d ON it.department_id = d.id
                    LEFT JOIN users u ON qr.requested_by = u.username
                    {$whereClause}";
            $countResult = $conn->query($countSql);
            $totalCount = $countResult->fetch_assoc()['total'];
            
            // Get paginated results
            $sql = "SELECT qr.*, it.table_name, it.category, d.name as department_name, u.email as requester_email
                    FROM qr_requests qr
                    LEFT JOIN item_tables it ON qr.item_table_id = it.id
                    LEFT JOIN departments d ON it.department_id = d.id
                    LEFT JOIN users u ON qr.requested_by = u.username
                    {$whereClause}
                    ORDER BY qr.created_at DESC
                    LIMIT {$limit} OFFSET {$offset}";
            
            $result = $conn->query($sql);
            $requests = [];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $requests[] = $row;
                }
            }
            
            $totalPages = ceil($totalCount / $limit);
            echo json_encode([
                'success' => true, 
                'qr_requests' => $requests,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_count' => (int)$totalCount,
                    'limit' => $limit
                ]
            ]);
            break;
            
        case 'check_item_table_consumable':
            $item_table_id = (int)($_GET['item_table_id'] ?? 0);
            if (!$item_table_id) {
                echo json_encode(['success' => false, 'message' => 'Item table ID required']);
                break;
            }
            
            $sql = "SELECT COALESCE(it.is_consumable, 0) as is_consumable FROM item_tables it WHERE it.id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("i", $item_table_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    echo json_encode([
                        'success' => true,
                        'is_consumable' => (int)$row['is_consumable'] === 1
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Item table not found']);
                }
                $stmt->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
            break;

        case 'check_item_table_qr_status':
            ensureQrRequestsItemColumn($conn);
            $item_table_id = (int)($_GET['item_table_id'] ?? 0);
            $item_id = (int)($_GET['item_id'] ?? 0);
            if (!$item_table_id) {
                echo json_encode(['success' => false, 'message' => 'Item table ID required']);
                break;
            }
            
            // Check if qr_downloaded column exists, if not add it
            $checkColumn = $conn->query("SHOW COLUMNS FROM items LIKE 'qr_downloaded'");
            if ($checkColumn->num_rows === 0) {
                $conn->query("ALTER TABLE items ADD COLUMN qr_downloaded TINYINT(1) DEFAULT 0 AFTER qr_code");
            }
            
            $sql = "SELECT it.priority, 
                    (SELECT COUNT(*) FROM qr_requests qr WHERE qr.item_table_id = it.id AND qr.status = 'approved') as has_approved_qr,
                    (SELECT COUNT(*) FROM qr_requests qr WHERE qr.item_table_id = it.id AND qr.status = 'pending') as has_pending_qr,
                    CASE WHEN it.qr_code IS NOT NULL AND it.qr_code != '' THEN 1 ELSE 0 END as table_has_qr";
            
            // If item_id is provided, also check if this specific item's QR has been downloaded
            if ($item_id) {
                $sql .= ", (SELECT COUNT(*) FROM qr_requests qr WHERE qr.item_table_id = it.id AND qr.status = 'pending' AND qr.item_id = ?) as item_has_pending_qr";
                $sql .= ", COALESCE(i.qr_downloaded, 0) as item_qr_downloaded";
                $sql .= ", (SELECT COALESCE(updated_at, created_at) FROM qr_requests qr WHERE qr.item_table_id = it.id AND qr.item_id = ? AND qr.status = 'rejected' ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 1) as item_last_rejected";
                $sql .= " FROM item_tables it LEFT JOIN items i ON i.id = ? AND i.item_table_id = it.id WHERE it.id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("iiii", $item_id, $item_id, $item_id, $item_table_id);
                }
            } else {
                $sql .= " FROM item_tables it WHERE it.id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $item_table_id);
                }
            }
            
            if ($stmt) {
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $response = [
                        'success' => true,
                        'priority' => $row['priority'] ?? 'low',
                        'has_approved_qr' => (int)$row['has_approved_qr'] > 0,
                        'has_pending_qr' => (int)$row['has_pending_qr'] > 0,
                        'table_has_qr' => (int)$row['table_has_qr'] > 0,
                        'item_has_pending_qr' => isset($row['item_has_pending_qr']) ? (int)$row['item_has_pending_qr'] > 0 : false
                    ];
                    if ($item_id && isset($row['item_qr_downloaded'])) {
                        $response['item_qr_downloaded'] = (int)$row['item_qr_downloaded'] > 0;
                    }
                    $cooldownSeconds = 1 * 24 * 60 * 60; // 1 day
                    $response['item_recently_rejected'] = false;
                    $response['item_rejection_wait_until'] = null;
                    if ($item_id && !empty($row['item_last_rejected'])) {
                        $lastRejectedTs = strtotime($row['item_last_rejected']);
                        if ($lastRejectedTs) {
                            $waitUntil = $lastRejectedTs + $cooldownSeconds;
                            $response['item_last_rejected'] = date('c', $lastRejectedTs);
                            if ($waitUntil > time()) {
                                $response['item_recently_rejected'] = true;
                                $response['item_rejection_wait_until'] = date('c', $waitUntil);
                            }
                        }
                    }
                    echo json_encode($response);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Item table not found']);
                }
                $stmt->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
            break;
            
        case 'mark_item_qr_downloaded':
            $item_id = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
            if (!$item_id) {
                echo json_encode(['success' => false, 'message' => 'Item ID required']);
                break;
            }
            
            // ONLY Super Admin should skip tracking - Head Department (Admin but not Super Admin) should be tracked
            if ($isSuperAdmin) {
                if (ob_get_level()) {
                    ob_clean();
                }
                echo json_encode(['success' => true, 'message' => 'QR code download not tracked for super admin users']);
                exit;
            }
            // Head Department (Admin but not Super Admin) should be tracked for one-time download restriction
            
            // Check if qr_downloaded column exists, if not add it
            $checkColumn = $conn->query("SHOW COLUMNS FROM items LIKE 'qr_downloaded'");
            if ($checkColumn->num_rows === 0) {
                $conn->query("ALTER TABLE items ADD COLUMN qr_downloaded TINYINT(1) DEFAULT 0 AFTER qr_code");
            }
            
            // Verify that the item belongs to a medium or high priority table before marking as downloaded
            // This restriction should ONLY apply to medium/high priority tables
            $priorityCheckSql = "SELECT it.priority 
                                 FROM items i 
                                 INNER JOIN item_tables it ON i.item_table_id = it.id 
                                 WHERE i.id = ?";
            $priorityStmt = $conn->prepare($priorityCheckSql);
            if ($priorityStmt) {
                $priorityStmt->bind_param("i", $item_id);
                $priorityStmt->execute();
                $priorityResult = $priorityStmt->get_result();
                if ($priorityRow = $priorityResult->fetch_assoc()) {
                    $priority = strtolower($priorityRow['priority'] ?? 'low');
                    
                    // Only mark as downloaded for medium/high priority tables
                    // Low priority items should never be marked as downloaded (unlimited downloads)
                    if ($priority === 'low') {
                        $priorityStmt->close();
                        if (ob_get_level()) {
                            ob_clean();
                        }
                        echo json_encode(['success' => false, 'message' => 'Download tracking only applies to medium/high priority items']);
                        exit;
                    }
                } else {
                    $priorityStmt->close();
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    echo json_encode(['success' => false, 'message' => 'Item or item table not found']);
                    exit;
                }
                $priorityStmt->close();
            } else {
                // If priority check fails, don't mark as downloaded (safety measure)
                if (ob_get_level()) {
                    ob_clean();
                }
                echo json_encode(['success' => false, 'message' => 'Failed to verify item priority']);
                exit;
            }
            
            // Mark QR as downloaded (Head Department only, for medium/high priority items)
            $updateStmt = $conn->prepare("UPDATE items SET qr_downloaded = 1 WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("i", $item_id);
                if ($updateStmt->execute()) {
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    echo json_encode(['success' => true, 'message' => 'QR code marked as downloaded']);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update']);
                }
                $updateStmt->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
            break;
            
        case 'request_new_item_qr':
            $item_id = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
            if (!$item_id) {
                echo json_encode(['success' => false, 'message' => 'Item ID required']);
                break;
            }
            
            // Get notes from request
            $notes = trim($_POST['notes'] ?? '');
            
            // Get current username from session
            $currentUsername = $_SESSION['username'] ?? 'system';
            if (!$currentUsername || $currentUsername === 'system') {
                echo json_encode(['success' => false, 'message' => 'User not authenticated']);
                break;
            }
            
            // Ensure notes column exists in qr_requests table
            $checkNotesColumn = $conn->query("SHOW COLUMNS FROM qr_requests LIKE 'notes'");
            if ($checkNotesColumn->num_rows === 0) {
                $conn->query("ALTER TABLE qr_requests ADD COLUMN notes TEXT DEFAULT NULL AFTER rejection_reason");
            }
            
            // Check if qr_downloaded column exists, if not add it
            $checkColumn = $conn->query("SHOW COLUMNS FROM items LIKE 'qr_downloaded'");
            if ($checkColumn->num_rows === 0) {
                $conn->query("ALTER TABLE items ADD COLUMN qr_downloaded TINYINT(1) DEFAULT 0 AFTER qr_code");
            }
            
            // Get item details and item table info
            $itemStmt = $conn->prepare("SELECT i.item_table_id, it.priority, it.table_name FROM items i LEFT JOIN item_tables it ON i.item_table_id = it.id WHERE i.id = ?");
            if (!$itemStmt) {
                echo json_encode(['success' => false, 'message' => 'Database error']);
                break;
            }
            $itemStmt->bind_param("i", $item_id);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result();
            if ($itemResult->num_rows === 0) {
                $itemStmt->close();
                echo json_encode(['success' => false, 'message' => 'Item not found']);
                break;
            }
            $itemData = $itemResult->fetch_assoc();
            $itemStmt->close();
            
            $item_table_id = (int)$itemData['item_table_id'];
            $priority = $itemData['priority'] ?? 'low';
            $table_name = $itemData['table_name'] ?? 'Unknown';
            $cooldownSeconds = 5 * 24 * 60 * 60; // 5 days
            
            // Only create QR request for medium/high priority item tables
            if ($priority === 'low') {
                // For low priority, just reset the download flag
                $updateStmt = $conn->prepare("UPDATE items SET qr_downloaded = 0 WHERE id = ?");
                if ($updateStmt) {
                    $updateStmt->bind_param("i", $item_id);
                    if ($updateStmt->execute()) {
                        if (ob_get_level()) {
                            ob_clean();
                        }
                        echo json_encode(['success' => true, 'message' => 'QR code request reset. You can now download a new QR code.', 'requires_approval' => false]);
                        exit;
                    }
                    $updateStmt->close();
                }
                echo json_encode(['success' => false, 'message' => 'Failed to reset']);
                break;
            }
            
            ensureQrRequestsItemColumn($conn);
            
            // Check if there is a recent rejection for this specific item within the cooldown window
            $cooldownStmt = $conn->prepare("SELECT COALESCE(updated_at, created_at) as last_rejected FROM qr_requests WHERE item_id = ? AND status = 'rejected' ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 1");
            if ($cooldownStmt) {
                $cooldownStmt->bind_param("i", $item_id);
                $cooldownStmt->execute();
                $cooldownResult = $cooldownStmt->get_result();
                if ($cooldownRow = $cooldownResult->fetch_assoc()) {
                    $lastRejectedTs = strtotime($cooldownRow['last_rejected']);
                    if ($lastRejectedTs) {
                        $waitUntil = $lastRejectedTs + $cooldownSeconds;
                        if ($waitUntil > time()) {
                            $cooldownStmt->close();
                            if (ob_get_level()) {
                                ob_clean();
                            }
                            echo json_encode([
                                'success' => false,
                                'message' => 'QR code request was recently rejected. Please wait before requesting again.',
                                'cooldown' => true,
                                'wait_until' => date('c', $waitUntil)
                            ]);
                            exit;
                        }
                    }
                }
                $cooldownStmt->close();
            }
            // For medium/high priority, check if there's already a pending QR request for this table/item
            $checkPendingStmt = $conn->prepare("SELECT id FROM qr_requests WHERE item_table_id = ? AND status = 'pending' AND (item_id IS NULL OR item_id = ?)");
            $checkPendingStmt->bind_param("ii", $item_table_id, $item_id);
            $checkPendingStmt->execute();
            $pendingResult = $checkPendingStmt->get_result();
            $hasPendingRequest = $pendingResult->num_rows > 0;
            $checkPendingStmt->close();
            
            if (!$hasPendingRequest) {
                // Create new QR request for the item table
                $qrRequestStmt = $conn->prepare("INSERT INTO qr_requests (item_table_id, item_id, requested_by, priority, status, notes) VALUES (?, ?, ?, ?, 'pending', ?)");
                if (!$qrRequestStmt) {
                    echo json_encode(['success' => false, 'message' => 'Database error']);
                    break;
                }
                $qrRequestStmt->bind_param("iisss", $item_table_id, $item_id, $currentUsername, $priority, $notes);
                
                if ($qrRequestStmt->execute()) {
                    $qrRequestId = $conn->insert_id;
                    $qrRequestStmt->close();
                    
                    // Send notification to admins and super admins
                    try {
                        // Get all admins from users table and super admins from super_admin table
                        $admins = [];
                        
                        // Get admins from users table
                        $adminQuery = $conn->query("SELECT username FROM users WHERE is_admin = 1 AND username != '" . $conn->real_escape_string($currentUsername) . "'");
                        if ($adminQuery) {
                            while ($admin = $adminQuery->fetch_assoc()) {
                                $admins[] = $admin['username'];
                            }
                        }
                        
                        // Get super admins from super_admin table
                        $superAdminQuery = $conn->query("SELECT username FROM super_admin WHERE username != '" . $conn->real_escape_string($currentUsername) . "'");
                        if ($superAdminQuery) {
                            while ($superAdmin = $superAdminQuery->fetch_assoc()) {
                                $admins[] = $superAdmin['username'];
                            }
                        }
                        
                        // Check if notifications table exists
                        $checkNotificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
                        if ($checkNotificationsTable && $checkNotificationsTable->num_rows > 0 && !empty($admins)) {
                            $notificationMessage = "New QR code request for item table: {$table_name} (Priority: {$priority}) - Requested by: {$currentUsername}";
                            $notificationLink = "item_requests.php?tab=qr_requests";
                            
                            foreach ($admins as $adminUsername) {
                                try {
                                    $notifStmt = $conn->prepare("INSERT INTO notifications (username, type, message, link, is_read, created_at) VALUES (?, 'qr_request', ?, ?, 0, NOW())");
                                    if ($notifStmt) {
                                        $notifStmt->bind_param("sss", $adminUsername, $notificationMessage, $notificationLink);
                                        $notifStmt->execute();
                                        $notifStmt->close();
                                    }
                                } catch (Exception $notifError) {
                                    error_log("Notification error for user {$adminUsername}: " . $notifError->getMessage());
                                }
                            }
                        }
                    } catch (Exception $notifException) {
                        error_log("Notification system error: " . $notifException->getMessage());
                        // Don't fail the request if notification fails
                    }
                } else {
                    $qrRequestStmt->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to create QR request']);
                    break;
                }
            }
            
            // Reset qr_downloaded flag for this item
            $updateStmt = $conn->prepare("UPDATE items SET qr_downloaded = 0 WHERE id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("i", $item_id);
                if ($updateStmt->execute()) {
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    $message = $hasPendingRequest 
                        ? 'QR code request is already pending approval. Your download flag has been reset. Please wait for admin approval.'
                        : 'QR code request has been submitted and is pending approval. You will be able to download once approved.';
                    echo json_encode([
                        'success' => true, 
                        'message' => $message,
                        'requires_approval' => true,
                        'qr_request_id' => isset($qrRequestId) ? $qrRequestId : null
                    ]);
                    exit;
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to reset download flag']);
                }
                $updateStmt->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
            break;
            
        case 'update_qr_request':
            if (!$isAdmin) {
                throw new Exception('Unauthorized');
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request');
            }
            
            $id = (int)($_POST['id'] ?? 0);
            $new_status = $_POST['status'] ?? '';
            $rejection_reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';
            
            if (!$id || !in_array($new_status, ['approved', 'rejected'])) {
                throw new Exception('Invalid parameters');
            }
            
            // Get QR request details
            $get_request_stmt = $conn->prepare("SELECT qr.*, it.table_name, it.department_id FROM qr_requests qr LEFT JOIN item_tables it ON qr.item_table_id = it.id WHERE qr.id = ?");
            $get_request_stmt->bind_param('i', $id);
            $get_request_stmt->execute();
            $request_result = $get_request_stmt->get_result();
            $request_data = $request_result->fetch_assoc();
            $get_request_stmt->close();
            
            if (!$request_data) {
                throw new Exception('QR request not found');
            }
            
            $currentUsername = $_SESSION['username'] ?? 'system';
            
            if ($new_status === 'approved') {
                // Generate QR code
                $tableId = $request_data['item_table_id'];
                $qrCodeValue = 'TABLE-' . $tableId . '-' . time();
                
                // Get department info for color
                $deptSql = "SELECT id, name FROM departments WHERE id = ?";
                $deptStmt = $conn->prepare($deptSql);
                $deptStmt->bind_param("i", $request_data['department_id']);
                $deptStmt->execute();
                $deptResult = $deptStmt->get_result();
                $departmentData = $deptResult->fetch_assoc();
                $deptStmt->close();
                
                // Get department color - use both ID and name for better matching
                $deptName = $departmentData['name'] ?? null;
                $deptId = $request_data['department_id'] ?? null;
                
                // Log for debugging
                error_log("QR Approval - Department ID: $deptId, Name: " . ($deptName ?? 'NULL'));
                
                $fgColor = getDepartmentColorHex($deptId, $deptName);
                
                // Log the color being used
                error_log("QR Approval - Using color: $fgColor for department: $deptName");
                
                // Generate QR code image
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
                $qrData = $baseUrl . 'item_table_inventory.php?table_id=' . $tableId;
                
                $qrCodeFilename = 'qr_table_' . $tableId . '_' . time() . '.png';
                $qrCodePath = 'qr_codes/' . $qrCodeFilename;
                
                // Ensure qr_codes folder exists
                if (!file_exists('qr_codes')) {
                    mkdir('qr_codes', 0777, true);
                }
                
                // Generate QR code using API with department color
                // Ensure color is properly formatted (hex without #)
                $fgColor = strtoupper(ltrim($fgColor, '#'));
                
                // Log the final API URL for debugging (without the data parameter for security)
                error_log("QR Approval - API URL (partial): https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=" . $fgColor . "&bgcolor=FFFFFF");
                
                $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=' . $fgColor . '&bgcolor=FFFFFF&data=' . urlencode($qrData);
                $qrImage = @file_get_contents($qrApiUrl);
                
                // Log if QR generation failed
                if ($qrImage === false) {
                    error_log("QR Approval - Failed to generate QR code from API. URL: " . substr($qrApiUrl, 0, 200));
                } else {
                    error_log("QR Approval - Successfully generated QR code with color: $fgColor");
                }
                
                if ($qrImage !== false) {
                    // Save QR code image
                    file_put_contents($qrCodePath, $qrImage);
                    
                    // Update qr_requests table
                    $updateStmt = $conn->prepare("UPDATE qr_requests SET status = 'approved', qr_code = ?, qr_code_path = ?, approved_by = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->bind_param("sssi", $qrCodeValue, $qrCodePath, $currentUsername, $id);
                    $updateStmt->execute();
                    $updateStmt->close();
                    
                    // Update item_tables with QR code
                    $updateItemTableStmt = $conn->prepare("UPDATE item_tables SET qr_code = ? WHERE id = ?");
                    $updateItemTableStmt->bind_param("si", $qrCodeValue, $tableId);
                    $updateItemTableStmt->execute();
                    $updateItemTableStmt->close();
                    
                    // Reset qr_downloaded flag for all items in this table so users can download the newly approved QR
                    // This allows users to download the new QR code after requesting it
                    $resetDownloadStmt = $conn->prepare("UPDATE items SET qr_downloaded = 0 WHERE item_table_id = ?");
                    if ($resetDownloadStmt) {
                        $resetDownloadStmt->bind_param("i", $tableId);
                        $resetDownloadStmt->execute();
                        $resetDownloadStmt->close();
                    }
                    
                    // Send notification to requester (including department heads)
                    // Insert notification directly to avoid output issues
                    error_log("QR Approval - Starting notification creation for requester: {$request_data['requested_by']}, table: {$request_data['table_name']}");
                    try {
                        // Check if notifications table exists, create if it doesn't
                        $checkNotificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
                        if (!$checkNotificationsTable || $checkNotificationsTable->num_rows == 0) {
                            // Create notifications table if it doesn't exist
                            $createTableSql = "CREATE TABLE IF NOT EXISTS `notifications` (
                                `id` int(11) NOT NULL AUTO_INCREMENT,
                                `user_id` int(11) DEFAULT NULL,
                                `username` varchar(255) DEFAULT NULL,
                                `type` varchar(50) NOT NULL,
                                `message` text NOT NULL,
                                `link` varchar(255) DEFAULT NULL,
                                `is_read` tinyint(1) DEFAULT 0,
                                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                PRIMARY KEY (`id`),
                                KEY `user_id` (`user_id`),
                                KEY `username` (`username`),
                                KEY `type` (`type`),
                                KEY `is_read` (`is_read`),
                                KEY `created_at` (`created_at`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                            
                            if ($conn->query($createTableSql)) {
                                error_log("QR Approval - Created notifications table");
                            } else {
                                error_log("QR Approval - Failed to create notifications table: " . $conn->error);
                            }
                        }
                        
                        // Check again after potential creation
                        $checkNotificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
                        error_log("QR Approval - Notifications table check: " . ($checkNotificationsTable && $checkNotificationsTable->num_rows > 0 ? "EXISTS" : "NOT EXISTS"));
                        if ($checkNotificationsTable && $checkNotificationsTable->num_rows > 0) {
                            // Check if notifications table has user_id column
                            $checkUserIdColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'user_id'");
                            error_log("QR Approval - user_id column check: " . ($checkUserIdColumn && $checkUserIdColumn->num_rows > 0 ? "EXISTS" : "NOT EXISTS"));
                            if ($checkUserIdColumn && $checkUserIdColumn->num_rows > 0) {
                                // Try using user_id first (preferred method)
                                error_log("QR Approval - Attempting to create notification using user_id method for: {$request_data['requested_by']}");
                                $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, created_at) SELECT id, 'qr_request_approved', ?, ?, 0, NOW() FROM users WHERE username = ?");
                                if (!$notifStmt) {
                                    error_log("QR Approval - Failed to prepare notification statement: " . $conn->error);
                                    // Fallback to username if user_id method fails
                                    $checkUsernameColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'username'");
                                    if ($checkUsernameColumn && $checkUsernameColumn->num_rows > 0) {
                                        $notifMessage = "QR request approved for '{$request_data['table_name']}'";
                                        $notifLink = "item_requests.php?tab=qr_requests";
                                        $notifStmt2 = $conn->prepare("INSERT INTO notifications (username, type, message, link, is_read, created_at) VALUES (?, 'qr_request_approved', ?, ?, 0, NOW())");
                                        if ($notifStmt2) {
                                            $notifStmt2->bind_param("sss", $request_data['requested_by'], $notifMessage, $notifLink);
                                            if ($notifStmt2->execute()) {
                                                $affected_rows2 = $notifStmt2->affected_rows;
                                                error_log("QR approval notification created (fallback username) for user: {$request_data['requested_by']}, affected_rows: {$affected_rows2}");
                                            } else {
                                                error_log("QR approval notification fallback failed: " . $notifStmt2->error);
                                            }
                                            $notifStmt2->close();
                                        }
                                    }
                                } else {
                                    $notifMessage = "QR request approved for '{$request_data['table_name']}'";
                                    $notifLink = "item_requests.php?tab=qr_requests";
                                    $notifStmt->bind_param("sss", $notifMessage, $notifLink, $request_data['requested_by']);
                                    if ($notifStmt->execute()) {
                                        $affected_rows = $notifStmt->affected_rows;
                                        error_log("QR approval notification created for user: {$request_data['requested_by']}, table: {$request_data['table_name']}, affected_rows: {$affected_rows}");
                                        if ($affected_rows == 0) {
                                            error_log("WARNING: QR approval notification insert returned 0 affected rows for user: {$request_data['requested_by']} - User might not exist in users table");
                                        }
                                    } else {
                                        error_log("Failed to execute QR approval notification insert: " . $notifStmt->error);
                                        // Fallback to username if user_id method fails
                                        $checkUsernameColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'username'");
                                        if ($checkUsernameColumn && $checkUsernameColumn->num_rows > 0) {
                                            $notifStmt2 = $conn->prepare("INSERT INTO notifications (username, type, message, link, is_read, created_at) VALUES (?, 'qr_request_approved', ?, ?, 0, NOW())");
                                            if ($notifStmt2) {
                                                $notifStmt2->bind_param("sss", $request_data['requested_by'], $notifMessage, $notifLink);
                                                if ($notifStmt2->execute()) {
                                                    $affected_rows2 = $notifStmt2->affected_rows;
                                                    error_log("QR approval notification created (fallback username) for user: {$request_data['requested_by']}, affected_rows: {$affected_rows2}");
                                                } else {
                                                    error_log("QR approval notification fallback failed: " . $notifStmt2->error);
                                                }
                                                $notifStmt2->close();
                                            }
                                        }
                                    }
                                    $notifStmt->close();
                                }
                            } else {
                                // Fallback: Use username if user_id column doesn't exist
                                $checkUsernameColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'username'");
                                if ($checkUsernameColumn && $checkUsernameColumn->num_rows > 0) {
                                    $notifStmt = $conn->prepare("INSERT INTO notifications (username, type, message, link, is_read, created_at) VALUES (?, 'qr_request_approved', ?, ?, 0, NOW())");
                                $notifMessage = "QR request approved for '{$request_data['table_name']}'";
                                $notifLink = "item_requests.php?tab=qr_requests";
                                $notifStmt->bind_param("sss", $request_data['requested_by'], $notifMessage, $notifLink);
                                    if ($notifStmt->execute()) {
                                        $affected_rows = $notifStmt->affected_rows;
                                        error_log("QR approval notification created (username method) for user: {$request_data['requested_by']}, table: {$request_data['table_name']}, affected_rows: {$affected_rows}");
                                    } else {
                                        error_log("Failed to execute QR approval notification insert (username method): " . $notifStmt->error);
                                    }
                                    $notifStmt->close();
                                }
                            }
                        } else {
                            error_log("QR Approval - Notifications table does not exist, skipping notification creation");
                        }
                    } catch (Exception $e) {
                        error_log('Failed to create QR approval notification: ' . $e->getMessage());
                        error_log('QR Approval notification exception trace: ' . $e->getTraceAsString());
                        // Don't fail the request if notification fails
                    }
                } else {
                    throw new Exception('Failed to generate QR code image');
                }
            } else {
                // Rejected
                $updateStmt = $conn->prepare("UPDATE qr_requests SET status = 'rejected', rejected_by = ?, rejection_reason = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->bind_param("ssi", $currentUsername, $rejection_reason, $id);
                $updateStmt->execute();
                $updateStmt->close();
                
                // When an admin rejects the QR request, remove any existing QR code references.
                // For table-level requests (item_id NULL), clear the entire table. For item-level requests, clear only that item.
                $tableId = (int)$request_data['item_table_id'];
                $itemId  = isset($request_data['item_id']) ? (int)$request_data['item_id'] : 0;
                
                if ($itemId > 0) {
                    // Item-level QR request: clear QR data only for this specific item
                    $clearItemStmt = $conn->prepare("UPDATE items SET qr_code = NULL, qr_downloaded = 0 WHERE id = ?");
                    if ($clearItemStmt) {
                        $clearItemStmt->bind_param("i", $itemId);
                        $clearItemStmt->execute();
                        $clearItemStmt->close();
                        error_log("QR Rejection - Cleared QR code references for item_id {$itemId}");
                    }
                } elseif ($tableId > 0) {
                    // Table-level QR request: clear QR data for entire table and its items
                    $clearTableStmt = $conn->prepare("UPDATE item_tables SET qr_code = NULL WHERE id = ?");
                    if ($clearTableStmt) {
                        $clearTableStmt->bind_param("i", $tableId);
                        $clearTableStmt->execute();
                        $clearTableStmt->close();
                    }
                    
                    $clearItemsStmt = $conn->prepare("UPDATE items SET qr_code = NULL, qr_downloaded = 0 WHERE item_table_id = ?");
                    if ($clearItemsStmt) {
                        $clearItemsStmt->bind_param("i", $tableId);
                        $clearItemsStmt->execute();
                        $clearItemsStmt->close();
                    }
                    
                    error_log("QR Rejection - Cleared QR code references for item_table_id {$tableId}");
                }
                
                // Send notification to requester (including department heads)
                // Insert notification directly to avoid output issues
                error_log("QR Rejection - Starting notification creation for requester: {$request_data['requested_by']}, table: {$request_data['table_name']}");
                try {
                    // Check if notifications table exists, create if it doesn't
                    $checkNotificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
                    if (!$checkNotificationsTable || $checkNotificationsTable->num_rows == 0) {
                        // Create notifications table if it doesn't exist
                        $createTableSql = "CREATE TABLE IF NOT EXISTS `notifications` (
                            `id` int(11) NOT NULL AUTO_INCREMENT,
                            `user_id` int(11) DEFAULT NULL,
                            `username` varchar(255) DEFAULT NULL,
                            `type` varchar(50) NOT NULL,
                            `message` text NOT NULL,
                            `link` varchar(255) DEFAULT NULL,
                            `is_read` tinyint(1) DEFAULT 0,
                            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (`id`),
                            KEY `user_id` (`user_id`),
                            KEY `username` (`username`),
                            KEY `type` (`type`),
                            KEY `is_read` (`is_read`),
                            KEY `created_at` (`created_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                        
                        if ($conn->query($createTableSql)) {
                            error_log("QR Rejection - Created notifications table");
                        } else {
                            error_log("QR Rejection - Failed to create notifications table: " . $conn->error);
                        }
                    }
                    
                    // Check again after potential creation
                    $checkNotificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
                    error_log("QR Rejection - Notifications table check: " . ($checkNotificationsTable && $checkNotificationsTable->num_rows > 0 ? "EXISTS" : "NOT EXISTS"));
                    if ($checkNotificationsTable && $checkNotificationsTable->num_rows > 0) {
                        // Check if notifications table has user_id column
                        $checkUserIdColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'user_id'");
                        error_log("QR Rejection - user_id column check: " . ($checkUserIdColumn && $checkUserIdColumn->num_rows > 0 ? "EXISTS" : "NOT EXISTS"));
                        if ($checkUserIdColumn && $checkUserIdColumn->num_rows > 0) {
                            // Try using user_id first (preferred method)
                            error_log("QR Rejection - Attempting to create notification using user_id method for: {$request_data['requested_by']}");
                            $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, type, message, link, is_read, created_at) SELECT id, 'qr_request_rejected', ?, ?, 0, NOW() FROM users WHERE username = ?");
                            if (!$notifStmt) {
                                error_log("QR Rejection - Failed to prepare notification statement: " . $conn->error);
                                // Fallback to username if user_id method fails
                                $checkUsernameColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'username'");
                                if ($checkUsernameColumn && $checkUsernameColumn->num_rows > 0) {
                                    $notifMessage = "QR request rejected for '{$request_data['table_name']}'" . ($rejection_reason ? ": {$rejection_reason}" : "");
                                    $notifLink = "item_requests.php?tab=qr_requests";
                                    $notifStmt2 = $conn->prepare("INSERT INTO notifications (username, type, message, link, is_read, created_at) VALUES (?, 'qr_request_rejected', ?, ?, 0, NOW())");
                                    if ($notifStmt2) {
                                        $notifStmt2->bind_param("sss", $request_data['requested_by'], $notifMessage, $notifLink);
                                        if ($notifStmt2->execute()) {
                                            $affected_rows2 = $notifStmt2->affected_rows;
                                            error_log("QR rejection notification created (fallback username) for user: {$request_data['requested_by']}, affected_rows: {$affected_rows2}");
                                        } else {
                                            error_log("QR rejection notification fallback failed: " . $notifStmt2->error);
                                        }
                                        $notifStmt2->close();
                                    }
                                }
                            } else {
                                $notifMessage = "QR request rejected for '{$request_data['table_name']}'" . ($rejection_reason ? ": {$rejection_reason}" : "");
                                $notifLink = "item_requests.php?tab=qr_requests";
                                $notifStmt->bind_param("sss", $notifMessage, $notifLink, $request_data['requested_by']);
                                if ($notifStmt->execute()) {
                                    $affected_rows = $notifStmt->affected_rows;
                                    error_log("QR rejection notification created for user: {$request_data['requested_by']}, table: {$request_data['table_name']}, affected_rows: {$affected_rows}");
                                    if ($affected_rows == 0) {
                                        error_log("WARNING: QR rejection notification insert returned 0 affected rows for user: {$request_data['requested_by']} - User might not exist in users table");
                                    }
                                } else {
                                    error_log("Failed to execute QR rejection notification insert: " . $notifStmt->error);
                                    // Fallback to username if user_id method fails
                                    $checkUsernameColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'username'");
                                    if ($checkUsernameColumn && $checkUsernameColumn->num_rows > 0) {
                                        $notifStmt2 = $conn->prepare("INSERT INTO notifications (username, type, message, link, is_read, created_at) VALUES (?, 'qr_request_rejected', ?, ?, 0, NOW())");
                                        if ($notifStmt2) {
                                            $notifStmt2->bind_param("sss", $request_data['requested_by'], $notifMessage, $notifLink);
                                            if ($notifStmt2->execute()) {
                                                $affected_rows2 = $notifStmt2->affected_rows;
                                                error_log("QR rejection notification created (fallback username) for user: {$request_data['requested_by']}, affected_rows: {$affected_rows2}");
                                            } else {
                                                error_log("QR rejection notification fallback failed: " . $notifStmt2->error);
                                            }
                                            $notifStmt2->close();
                                        }
                                    }
                                }
                                $notifStmt->close();
                            }
                        } else {
                            // Fallback: Use username if user_id column doesn't exist
                            $checkUsernameColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'username'");
                            if ($checkUsernameColumn && $checkUsernameColumn->num_rows > 0) {
                                $notifStmt = $conn->prepare("INSERT INTO notifications (username, type, message, link, is_read, created_at) VALUES (?, 'qr_request_rejected', ?, ?, 0, NOW())");
                                $notifMessage = "QR request rejected for '{$request_data['table_name']}'" . ($rejection_reason ? ": {$rejection_reason}" : "");
                                $notifLink = "item_requests.php?tab=qr_requests";
                                $notifStmt->bind_param("sss", $request_data['requested_by'], $notifMessage, $notifLink);
                                if ($notifStmt->execute()) {
                                    $affected_rows = $notifStmt->affected_rows;
                                    error_log("QR rejection notification created (username method) for user: {$request_data['requested_by']}, table: {$request_data['table_name']}, affected_rows: {$affected_rows}");
                                } else {
                                    error_log("Failed to execute QR rejection notification insert (username method): " . $notifStmt->error);
                                }
                                $notifStmt->close();
                            }
                        }
                        } else {
                            error_log("QR Rejection - Notifications table does not exist, skipping notification creation");
                        }
                    } catch (Exception $e) {
                        error_log('Failed to create QR rejection notification: ' . $e->getMessage());
                        error_log('QR Rejection notification exception trace: ' . $e->getTraceAsString());
                        // Don't fail the request if notification fails
                    }
            }
            
            // Ensure no output before JSON
            if (ob_get_level()) {
                ob_clean();
            }
            echo json_encode(['success' => true]);
            exit;
            break;
            
        case 'download_qr_code':
            $requestId = (int)($_GET['request_id'] ?? 0);
            if (!$requestId) {
                throw new Exception('Invalid request ID');
            }
            
            // Get QR request
            $get_request_stmt = $conn->prepare("SELECT qr.*, it.table_name FROM qr_requests qr LEFT JOIN item_tables it ON qr.item_table_id = it.id WHERE qr.id = ? AND qr.status = 'approved'");
            $get_request_stmt->bind_param('i', $requestId);
            $get_request_stmt->execute();
            $request_result = $get_request_stmt->get_result();
            $request_data = $request_result->fetch_assoc();
            $get_request_stmt->close();
            
            if (!$request_data || !$request_data['qr_code_path'] || !file_exists($request_data['qr_code_path'])) {
                throw new Exception('QR code not found or not available');
            }
            
            // Check if already downloaded (one-time use)
            if ($request_data['download_count'] > 0) {
                throw new Exception('QR code has already been downloaded. This is a one-time use download.');
            }
            
            // Update download count
            $updateStmt = $conn->prepare("UPDATE qr_requests SET download_count = 1, downloaded_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $requestId);
            $updateStmt->execute();
            $updateStmt->close();
            
            // Return file path for download
            $fileName = 'qr_' . $request_data['table_name'] . '_' . $requestId . '.png';
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($request_data['qr_code_path']));
            readfile($request_data['qr_code_path']);
            exit;

        default:
            throw new Exception("Invalid action specified: " . $action);
    }

} catch (Exception $e) {
    error_log("CRUD Error: " . $e->getMessage());
    error_log("CRUD Error Stack: " . $e->getTraceAsString());
    
    // Make sure we output valid JSON even on error
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_type' => get_class($e)
    ]);
} catch (Error $e) {
    // Catch PHP 7+ Errors (fatal errors, type errors, etc.)
    error_log("CRUD Fatal Error: " . $e->getMessage());
    error_log("CRUD Fatal Error Stack: " . $e->getTraceAsString());
    
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'A fatal error occurred: ' . $e->getMessage(),
        'error_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

if ($conn) {
    $conn->close();
}
?>
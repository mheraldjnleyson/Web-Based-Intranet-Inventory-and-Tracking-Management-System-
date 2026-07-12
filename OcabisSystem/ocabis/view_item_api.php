<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include '../db_connect.php';

// Check database connection
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection error. Please try again later.']);
    exit;
}

// Get item ID from URL
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$item_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid item ID']);
    exit;
}

try {
    // Fetch item details with table image, item image, and QR code
    $sql = "SELECT i.*, d.name as department_name, it.table_image_path
            FROM items i 
            LEFT JOIN departments d ON i.department_id = d.id 
            LEFT JOIN item_tables it ON i.item_table_id = it.id
            WHERE i.id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Database query preparation failed: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $item_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Database query execution failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Item not found']);
        $stmt->close();
        $conn->close();
        exit;
    }

    $item = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    // Return item details as JSON
    echo json_encode([
        'success' => true,
        'item' => $item
    ]);
} catch (Exception $e) {
    error_log('view_item_api.php error: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error loading item details: ' . $e->getMessage()
    ]);
    if (isset($stmt)) $stmt->close();
    if (isset($conn)) $conn->close();
}
?>

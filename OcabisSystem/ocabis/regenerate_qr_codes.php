<?php
// Script to regenerate QR codes with correct URLs
include '../db_connect.php';

echo "QR Code Regeneration Script\n";
echo "==========================\n\n";

// Deterministic dark color per department (hex without #)
function getDepartmentColorHex($departmentId, $departmentName = null) {
    $name = is_string($departmentName) ? strtolower(trim($departmentName)) : '';
    if ($name !== '') {
        if ($name === 'ict equipment') return 'C62828'; // red
        if ($name === 'slrc' || strpos($name, 'student learning resource center') !== false) return '1565C0'; // blue
        if ($name === 'science equipment') return 'F59E0B'; // yellow (amber)
        if ($name === 'sps equipment') return '2E7D32'; // green
    }
    $palette = [
        '000000', '1F497D', '2E7D32', '7B1FA2', 'C62828', '1565C0', '00695C', '4E342E', '37474F', 'AD1457', '283593', '00838F'
    ];
    if (!is_numeric($departmentId) || $departmentId <= 0) {
        return $palette[0];
    }
    $index = ((int)$departmentId) % count($palette);
    return $palette[$index];
}

// Get all items that have QR codes
$sql = "SELECT i.id, i.name, i.qr_code, i.department_id, d.name AS department_name FROM items i LEFT JOIN departments d ON i.department_id = d.id WHERE i.qr_code IS NOT NULL ORDER BY i.id";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "Found " . $result->num_rows . " items with existing QR codes.\n\n";
    
    // Get the current server URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
    
    echo "Using base URL: " . $baseUrl . "\n\n";
    
    while ($item = $result->fetch_assoc()) {
        echo "Regenerating QR code for Item ID " . $item['id'] . " (" . $item['name'] . ")...\n";
        
        // Generate new QR code with correct URL
        $qrData = $baseUrl . 'view_item.php?id=' . $item['id'];
        // Department-based foreground color and white background
        $fgColor = getDepartmentColorHex($item['department_id'] ?? null, $item['department_name'] ?? null);
        $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&color=' . $fgColor . '&bgcolor=FFFFFF&data=' . urlencode($qrData);
        
        // Download the new QR code
        $qrImage = @file_get_contents($qrApiUrl);
        
        if ($qrImage !== false) {
            // Create new filename
            $qrCodeFilename = 'qr_item_' . $item['id'] . '_' . time() . '.png';
            $qrCodePath = 'qr_codes/' . $qrCodeFilename;
            
            // Save the new QR code
            if (file_put_contents($qrCodePath, $qrImage)) {
                // Update database with new QR code path
                $updateSql = "UPDATE items SET qr_code = ? WHERE id = ?";
                $stmt = $conn->prepare($updateSql);
                $stmt->bind_param("si", $qrCodePath, $item['id']);
                
                if ($stmt->execute()) {
                    echo "✅ Successfully regenerated QR code for Item " . $item['id'] . "\n";
                    echo "   New URL: " . $qrData . "\n";
                    echo "   File: " . $qrCodePath . "\n\n";
                } else {
                    echo "❌ Failed to update database for Item " . $item['id'] . "\n\n";
                }
                $stmt->close();
            } else {
                echo "❌ Failed to save QR code file for Item " . $item['id'] . "\n\n";
            }
        } else {
            echo "❌ Failed to download QR code for Item " . $item['id'] . "\n\n";
        }
    }
} else {
    echo "No items found with existing QR codes.\n";
}

$conn->close();
echo "QR code regeneration completed.\n";
?>


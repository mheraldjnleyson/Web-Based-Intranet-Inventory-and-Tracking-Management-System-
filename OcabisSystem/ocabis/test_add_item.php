<?php
// Test script to add an item and check QR code generation
include '../db_connect.php';

echo "Testing Item Creation with QR Code Generation\n";
echo "============================================\n\n";

// Test data
$testItem = [
    'name' => 'Test Item ' . time(),
    'department_id' => 1, // Assuming department 1 exists
    'category' => 'Test Category',
    'quantity' => 1,
    'location' => 'Test Location',
    'status' => 'Working',
    'description' => 'Test item for QR code generation'
];

echo "Creating test item: " . $testItem['name'] . "\n";

// Insert item
$stmt = $conn->prepare("INSERT INTO items (name, department_id, category, quantity, location, status, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sisisss", 
    $testItem['name'], 
    $testItem['department_id'], 
    $testItem['category'], 
    $testItem['quantity'], 
    $testItem['location'], 
    $testItem['status'], 
    $testItem['description']
);

if ($stmt->execute()) {
    $itemId = $conn->insert_id;
    echo "✅ Item created with ID: $itemId\n";
    
    // Now test QR code generation
    echo "\nTesting QR code generation...\n";
    
    // Include the QR generation function
    include 'crud.php';
    
    try {
        $qrResult = generateAndSaveQRCode($conn, $itemId, $testItem);
        
        if ($qrResult['success']) {
            echo "✅ QR code generated successfully!\n";
            echo "QR Path: " . $qrResult['qr_path'] . "\n";
            echo "File exists: " . (file_exists($qrResult['qr_path']) ? 'YES' : 'NO') . "\n";
            if (file_exists($qrResult['qr_path'])) {
                echo "File size: " . filesize($qrResult['qr_path']) . " bytes\n";
            }
        } else {
            echo "❌ QR code generation failed: " . ($qrResult['error'] ?? 'Unknown error') . "\n";
        }
        
        // Show debug info
        if (isset($qrResult['debug'])) {
            echo "\nDebug Information:\n";
            foreach ($qrResult['debug'] as $key => $value) {
                echo "  $key: $value\n";
            }
        }
        
    } catch (Exception $e) {
        echo "❌ QR code generation exception: " . $e->getMessage() . "\n";
    }
    
} else {
    echo "❌ Failed to create item: " . $stmt->error . "\n";
}

$stmt->close();
$conn->close();

echo "\nTest completed.\n";
?>

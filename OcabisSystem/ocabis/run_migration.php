<?php
// Quick Migration Script - Add QR Code Field to item_tables
// Run this file once to add the qr_code column

include '../db_connect.php';

try {
    // Check if qr_code column exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'qr_code'");
    
    if ($checkColumn->num_rows == 0) {
        // Add qr_code column
        $sql = "ALTER TABLE `item_tables` 
                ADD COLUMN `qr_code` varchar(255) DEFAULT NULL AFTER `table_image_path`";
        
        if ($conn->query($sql)) {
            echo "✓ Successfully added qr_code column to item_tables table\n";
            
            // Add index
            $indexSql = "ALTER TABLE `item_tables` ADD INDEX `idx_qr_code` (`qr_code`)";
            if ($conn->query($indexSql)) {
                echo "✓ Successfully added index on qr_code column\n";
            } else {
                echo "⚠ Warning: Could not add index (may already exist): " . $conn->error . "\n";
            }
        } else {
            echo "✗ Error adding column: " . $conn->error . "\n";
        }
    } else {
        echo "✓ qr_code column already exists in item_tables table\n";
    }
    
    echo "\nMigration completed!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

$conn->close();
?>


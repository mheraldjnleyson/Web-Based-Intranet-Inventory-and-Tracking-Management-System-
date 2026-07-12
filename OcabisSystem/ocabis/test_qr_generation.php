<?php
// Test QR code generation
echo "QR Code Generation Test\n";
echo "======================\n";

// Check GD extension
echo "GD Extension Status:\n";
echo "- extension_loaded('gd'): " . (extension_loaded('gd') ? 'YES' : 'NO') . "\n";
echo "- function_exists('imagecreatefromstring'): " . (function_exists('imagecreatefromstring') ? 'YES' : 'NO') . "\n";
echo "- function_exists('imagepng'): " . (function_exists('imagepng') ? 'YES' : 'NO') . "\n";

if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
    echo "\n✅ GD extension is working!\n";
    
    // Test QR code generation
    echo "\nTesting QR code generation...\n";
    
    $testUrl = 'http://localhost/ocabisFrontend/ocabis/view_item.php?id=999';
    $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($testUrl);
    
    echo "QR API URL: " . $qrApiUrl . "\n";
    
    // Try to download QR code
    $qrImage = @file_get_contents($qrApiUrl);
    if ($qrImage !== false) {
        echo "✅ QR code downloaded successfully (" . strlen($qrImage) . " bytes)\n";
        
        // Try to create image resource
        $qrResource = @imagecreatefromstring($qrImage);
        if ($qrResource !== false) {
            echo "✅ Image resource created successfully\n";
            
            // Test saving to file
            $testFile = 'qr_codes/test_qr_' . time() . '.png';
            if (!file_exists('qr_codes')) {
                mkdir('qr_codes', 0777, true);
                echo "✅ Created qr_codes directory\n";
            }
            
            $saveResult = @imagepng($qrResource, $testFile);
            if ($saveResult) {
                echo "✅ QR code saved to: " . $testFile . "\n";
                echo "✅ File size: " . filesize($testFile) . " bytes\n";
            } else {
                echo "❌ Failed to save QR code to file\n";
            }
            
            imagedestroy($qrResource);
        } else {
            echo "❌ Failed to create image resource from QR code\n";
        }
    } else {
        echo "❌ Failed to download QR code from API\n";
    }
} else {
    echo "\n❌ GD extension is NOT working properly\n";
}

echo "\nTest completed.\n";
?>


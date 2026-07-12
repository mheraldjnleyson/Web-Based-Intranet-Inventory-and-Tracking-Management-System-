<?php
// Test the simple QR code generation without database
echo "Testing Simple QR Code Generation\n";
echo "=================================\n\n";

// Test data
$testItemId = 25;
$testItemData = [
    'name' => 'Test Item Simple',
    'department_id' => 1,
    'category' => 'Test Category',
    'location' => 'Test Location'
];

echo "Testing QR code generation for item ID: $testItemId\n";

// Simple QR generation function (without database)
function testQRGeneration($itemId, $itemData) {
    try {
        // Get the actual server URL
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
        
        // Generate QR code URL
        $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=500x500&ecc=H&data=' . urlencode($qrData);
        
        echo "Generated URL: " . $qrData . "\n";
        echo "Saving to: " . $qrCodePath . "\n";
        
        // Download QR code directly
        $qrImage = @file_get_contents($qrApiUrl);
        
        if ($qrImage === false) {
            throw new Exception('Failed to download QR code from API');
        }
        
        echo "Downloaded QR code (" . strlen($qrImage) . " bytes)\n";
        
        // Save QR code directly to file
        if (file_put_contents($qrCodePath, $qrImage) === false) {
            throw new Exception('Failed to save QR code file');
        }
        
        return [
            'success' => true,
            'qr_path' => $qrCodePath,
            'qr_url' => $qrApiUrl,
            'method' => 'simple_download'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false, 
            'error' => 'Failed to generate QR code: ' . $e->getMessage(),
            'method' => 'simple_download'
        ];
    }
}

try {
    $qrResult = testQRGeneration($testItemId, $testItemData);
    
    if ($qrResult['success']) {
        echo "✅ QR code generated successfully!\n";
        echo "QR Path: " . $qrResult['qr_path'] . "\n";
        echo "Method: " . $qrResult['method'] . "\n";
        echo "File exists: " . (file_exists($qrResult['qr_path']) ? 'YES' : 'NO') . "\n";
        if (file_exists($qrResult['qr_path'])) {
            echo "File size: " . filesize($qrResult['qr_path']) . " bytes\n";
        }
    } else {
        echo "❌ QR code generation failed: " . ($qrResult['error'] ?? 'Unknown error') . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ QR code generation exception: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";
?>

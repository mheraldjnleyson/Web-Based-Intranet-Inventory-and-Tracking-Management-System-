<?php
// Simple test to verify QR code generation and folder saving
echo "Testing QR Code Generation\n";
echo "=========================\n\n";

// Test folder creation
$qrFolder = 'qr_codes';
if (!file_exists($qrFolder)) {
    if (mkdir($qrFolder, 0777, true)) {
        echo "✅ Created qr_codes folder\n";
    } else {
        echo "❌ Failed to create qr_codes folder\n";
        exit;
    }
} else {
    echo "✅ qr_codes folder already exists\n";
}

// Test QR code generation
$testItemId = 999;
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
$qrData = $baseUrl . 'view_item.php?id=' . $testItemId;

echo "Generated URL: " . $qrData . "\n";

$qrCodeFilename = 'qr_test_' . $testItemId . '_' . time() . '.png';
$qrCodePath = $qrFolder . '/' . $qrCodeFilename;

echo "Saving to: " . $qrCodePath . "\n";

// Generate QR code
$qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qrData);
$qrImage = @file_get_contents($qrApiUrl);

if ($qrImage === false) {
    echo "❌ Failed to download QR code from API\n";
    exit;
}

echo "✅ Downloaded QR code (" . strlen($qrImage) . " bytes)\n";

// Save QR code
if (file_put_contents($qrCodePath, $qrImage)) {
    echo "✅ QR code saved successfully\n";
    echo "File size: " . filesize($qrCodePath) . " bytes\n";
    echo "Full path: " . realpath($qrCodePath) . "\n";
} else {
    echo "❌ Failed to save QR code file\n";
}

echo "\nTest completed.\n";
?>

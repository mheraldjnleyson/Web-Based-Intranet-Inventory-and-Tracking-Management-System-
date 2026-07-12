<?php
// Test QR code URL generation
echo "QR Code URL Test\n";
echo "================\n";

// Test with a sample item ID
$itemId = 1;
// Get the actual server URL instead of hardcoded localhost
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . '://' . $host . '/ocabisFrontend/ocabis/';
$qrData = $baseUrl . 'view_item.php?id=' . $itemId;

echo "Generated QR Code URL: " . $qrData . "\n";
echo "Full URL: " . $qrData . "\n\n";

// Test if the URL is accessible
echo "Testing URL accessibility...\n";
$context = stream_context_create([
    'http' => [
        'timeout' => 5,
        'method' => 'GET'
    ]
]);

$response = @file_get_contents($qrData, false, $context);
if ($response !== false) {
    echo "✅ URL is accessible!\n";
    echo "Response length: " . strlen($response) . " bytes\n";
} else {
    echo "❌ URL is not accessible\n";
    echo "Error: " . error_get_last()['message'] . "\n";
}

echo "\nTo test manually, visit: " . $qrData . "\n";
?>

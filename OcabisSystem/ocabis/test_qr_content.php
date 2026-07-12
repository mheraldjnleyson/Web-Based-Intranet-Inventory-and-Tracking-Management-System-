<?php
// Test to check what content is in the generated QR codes
echo "QR Code Content Test\n";
echo "===================\n\n";

// List QR codes in the folder
$qrFolder = 'qr_codes';
if (file_exists($qrFolder)) {
    $files = scandir($qrFolder);
    $qrFiles = array_filter($files, function($file) {
        return pathinfo($file, PATHINFO_EXTENSION) === 'png';
    });
    
    echo "Found " . count($qrFiles) . " QR code files:\n";
    foreach ($qrFiles as $file) {
        echo "  - $file\n";
    }
    echo "\n";
    
    // Test the most recent QR code (sort by modification time)
    if (!empty($qrFiles)) {
        $qrFilesWithTime = [];
        foreach ($qrFiles as $file) {
            $qrFilesWithTime[$file] = filemtime($qrFolder . '/' . $file);
        }
        arsort($qrFilesWithTime);
        $latestFile = array_key_first($qrFilesWithTime);
        $qrPath = $qrFolder . '/' . $latestFile;
        
        echo "Testing latest QR code: $latestFile\n";
        echo "File size: " . filesize($qrPath) . " bytes\n";
        echo "File exists: " . (file_exists($qrPath) ? 'YES' : 'NO') . "\n";
        
        // Try to decode the QR code using an online service
        echo "\nTrying to decode QR code content...\n";
        
        // Use a QR code decoding API
        $apiUrl = 'https://api.qrserver.com/v1/read-qr-code/';
        
        // Create a cURL request to decode the QR code
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => new CURLFile($qrPath, 'image/png', $latestFile)
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $decoded = json_decode($response, true);
            if ($decoded && isset($decoded[0]['symbol'][0]['data'])) {
                $qrContent = $decoded[0]['symbol'][0]['data'];
                echo "✅ QR Code Content: $qrContent\n";
                
                // Check if it's a valid URL
                if (filter_var($qrContent, FILTER_VALIDATE_URL)) {
                    echo "✅ Valid URL detected\n";
                    
                    // Test if the URL is accessible
                    $testResponse = @file_get_contents($qrContent);
                    if ($testResponse !== false) {
                        echo "✅ URL is accessible (" . strlen($testResponse) . " bytes)\n";
                    } else {
                        echo "❌ URL is not accessible\n";
                        echo "Error: " . error_get_last()['message'] . "\n";
                    }
                } else {
                    echo "❌ Not a valid URL\n";
                }
            } else {
                echo "❌ Could not decode QR code content\n";
                echo "Response: " . $response . "\n";
            }
        } else {
            echo "❌ Failed to decode QR code (HTTP $httpCode)\n";
            echo "Response: " . $response . "\n";
        }
    }
} else {
    echo "❌ QR codes folder not found\n";
}

echo "\nTest completed.\n";
?>

<?php
// Test GD extension
echo "GD Extension Test\n";
echo "================\n";

if (extension_loaded('gd')) {
    echo "✅ GD extension is loaded!\n";
    
    if (function_exists('imagecreatefromstring')) {
        echo "✅ imagecreatefromstring() function is available!\n";
        
        if (function_exists('imagepng')) {
            echo "✅ imagepng() function is available!\n";
            echo "✅ QR code generation should work now!\n";
        } else {
            echo "❌ imagepng() function is NOT available\n";
        }
    } else {
        echo "❌ imagecreatefromstring() function is NOT available\n";
    }
} else {
    echo "❌ GD extension is NOT loaded\n";
}

echo "\nGD Info:\n";
if (function_exists('gd_info')) {
    $gd_info = gd_info();
    echo "GD Version: " . $gd_info['GD Version'] . "\n";
    echo "PNG Support: " . ($gd_info['PNG Support'] ? 'Yes' : 'No') . "\n";
    echo "JPEG Support: " . ($gd_info['JPEG Support'] ? 'Yes' : 'No') . "\n";
}
?>

<?php
// Check GD extension status
echo "GD Extension Check\n";
echo "==================\n\n";

echo "GD Extension loaded: " . (extension_loaded('gd') ? 'YES' : 'NO') . "\n";
echo "GD Version: " . (extension_loaded('gd') ? gd_info()['GD Version'] : 'Not available') . "\n";
echo "imagecreatefromstring available: " . (function_exists('imagecreatefromstring') ? 'YES' : 'NO') . "\n";
echo "imagepng available: " . (function_exists('imagepng') ? 'YES' : 'NO') . "\n";

if (extension_loaded('gd')) {
    $info = gd_info();
    echo "\nGD Information:\n";
    foreach ($info as $key => $value) {
        echo "  $key: " . (is_bool($value) ? ($value ? 'YES' : 'NO') : $value) . "\n";
    }
} else {
    echo "\n❌ GD extension is not loaded!\n";
    echo "To fix this, you need to enable the GD extension in your PHP configuration.\n";
    echo "In XAMPP, edit php.ini and uncomment the line: extension=gd\n";
}

echo "\nPHP Version: " . phpversion() . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n";
?>

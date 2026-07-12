<?php
   if (extension_loaded('gd')) {
       echo "✅ GD Library is enabled!<br>";
       echo "Supported formats: ";
       $formats = [];
       if (function_exists('imagecreatefrompng')) $formats[] = 'PNG';
       if (function_exists('imagecreatefromjpeg')) $formats[] = 'JPEG';
       if (function_exists('imagecreatefromgif')) $formats[] = 'GIF';
       echo implode(', ', $formats);
   } else {
       echo "❌ GD Library is NOT enabled. Please enable it in php.ini";
   }
   ?>
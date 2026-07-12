<?php
/**
 * Get Local IP Address Helper
 * Use this to find your computer's IP address for mobile access
 */

// Get server IP address
$serverIP = $_SERVER['SERVER_ADDR'] ?? 'Not found';

// Get all possible IP addresses
$localIPs = [];

// Try to get local IP addresses
if (function_exists('gethostbyname')) {
    $hostname = gethostname();
    $localIP = gethostbyname($hostname);
    if ($localIP !== $hostname) {
        $localIPs[] = $localIP;
    }
}

// Get all network interfaces (Windows)
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $output = shell_exec('ipconfig');
    if ($output) {
        preg_match_all('/IPv4 Address[^\d]+(\d+\.\d+\.\d+\.\d+)/', $output, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $ip) {
                if ($ip !== '127.0.0.1' && !in_array($ip, $localIPs)) {
                    $localIPs[] = $ip;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Local IP Address Finder</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #e53e3e;
            margin-bottom: 20px;
        }
        .ip-section {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #e53e3e;
        }
        .ip-address {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            margin: 10px 0;
            word-break: break-all;
        }
        .copy-btn {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
        .copy-btn:hover {
            background: #c53030;
        }
        .instructions {
            background: #edf2f7;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .instructions h2 {
            color: #2d3748;
            margin-bottom: 15px;
            font-size: 18px;
        }
        .instructions ol {
            margin-left: 20px;
        }
        .instructions li {
            margin: 10px 0;
            line-height: 1.6;
        }
        .instructions code {
            background: #2d3748;
            color: #68d391;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        .url-example {
            background: #2d3748;
            color: #68d391;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            font-family: monospace;
            word-break: break-all;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .warning strong {
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Local IP Address Finder</h1>
        
        <div class="ip-section">
            <h2>Your Computer's IP Address:</h2>
            <?php if (!empty($localIPs)): ?>
                <?php foreach ($localIPs as $ip): ?>
                    <div class="ip-address"><?= htmlspecialchars($ip) ?></div>
                    <div class="url-example">
                        http://<?= htmlspecialchars($ip) ?>/ocabisFrontend/ocabis/viewer_qr_scanner.php
                    </div>
                    <button class="copy-btn" onclick="copyToClipboard('http://<?= htmlspecialchars($ip) ?>/ocabisFrontend/ocabis/viewer_qr_scanner.php')">
                        Copy URL
                    </button>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="ip-address"><?= htmlspecialchars($serverIP) ?></div>
                <?php if ($serverIP !== '127.0.0.1' && $serverIP !== 'Not found'): ?>
                    <div class="url-example">
                        http://<?= htmlspecialchars($serverIP) ?>/ocabisFrontend/ocabis/viewer_qr_scanner.php
                    </div>
                    <button class="copy-btn" onclick="copyToClipboard('http://<?= htmlspecialchars($serverIP) ?>/ocabisFrontend/ocabis/viewer_qr_scanner.php')">
                        Copy URL
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="warning">
            <strong>⚠️ If you see 127.0.0.1:</strong><br>
            This means the IP detection didn't work. Please find your IP manually:
            <ul style="margin-top: 10px; margin-left: 20px;">
                <li><strong>Windows:</strong> Open Command Prompt and type: <code>ipconfig</code> - Look for "IPv4 Address"</li>
                <li><strong>Mac/Linux:</strong> Open Terminal and type: <code>ifconfig</code> or <code>ip addr</code></li>
            </ul>
        </div>

        <div class="instructions">
            <h2>📱 How to Access from Mobile:</h2>
            <ol>
                <li><strong>Make sure both devices are on the same Wi-Fi network</strong></li>
                <li>Copy the URL above (or use one of the IP addresses shown)</li>
                <li>Open your mobile browser</li>
                <li>Paste the URL in the address bar</li>
                <li>Press Enter</li>
            </ol>

            <h2 style="margin-top: 20px;">🔧 Troubleshooting:</h2>
            <ul style="margin-left: 20px; margin-top: 10px;">
                <li>If it doesn't work, check that XAMPP is running</li>
                <li>Make sure Apache is started in XAMPP Control Panel</li>
                <li>Check Windows Firewall - it might be blocking port 80</li>
                <li>Verify both devices are on the same network</li>
                <li>Try accessing from another device first to test</li>
            </ul>
        </div>
    </div>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                alert('URL copied to clipboard!');
            }, function(err) {
                // Fallback for older browsers
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('URL copied to clipboard!');
            });
        }
    </script>
</body>
</html>


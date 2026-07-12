<?php
session_start();

// Suppress all PHP warnings and errors
error_reporting(0);
ini_set('display_errors', 0);

// Check if database is back online
$db_connected = false;
$conn = null;

try {
    $conn = @new mysqli('localhost', 'root', '', 'ocabis');
    if (!$conn->connect_error) {
        $result = $conn->query("SELECT 1");
        if ($result !== false) {
            $db_connected = true;
        }
    }
} catch (Exception $e) {
    $db_connected = false;
}

// If database is back online, redirect to appropriate page
if ($db_connected) {
    // Check if user is logged in
    if (isset($_SESSION['username'])) {
        // Determine if user is a viewer (teacher) - no department and not admin
        $isViewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);
        
        // Redirect viewers/teachers to department.php, others to dashboard.php
        if ($isViewer) {
            header("Location: department.php");
        } else {
            header("Location: dashboard.php");
        }
        exit();
    } else {
        // Redirect to login
        header("Location: login.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/logo.png">
    <title>System Maintenance - OCABIS</title>
    <link rel="stylesheet" href="Css/login.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .maintenance-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            box-sizing: border-box;
        }
        
        .maintenance-box {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 500px;
            text-align: center;
        }
        
        .maintenance-box .logo-section {
            margin-bottom: 30px;
        }
        
        .maintenance-box .logo-section img {
            height: 60px;
            width: auto;
            margin-bottom: 15px;
        }
        
        .maintenance-box h2 {
            color: #1f2937;
            margin: 0 0 10px 0;
            font-size: 24px;
        }
        
        .maintenance-box p {
            color: #6b7280;
            margin: 0 0 20px 0;
            line-height: 1.6;
        }
        
        .maintenance-icon {
            font-size: 80px;
            margin-bottom: 20px;
            color: #f59e0b;
        }
        
        .status-info {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .status-info h3 {
            margin: 0 0 10px 0;
            color: #92400e;
            font-size: 18px;
        }
        
        .status-info p {
            margin: 0;
            color: #92400e;
            font-size: 14px;
        }
        
        .contact-info {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .contact-info h3 {
            margin: 0 0 10px 0;
            color: #0369a1;
            font-size: 16px;
        }
        
        .contact-info p {
            margin: 5px 0;
            color: #0369a1;
            font-size: 14px;
        }
        
        .refresh-btn {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }
        
        .refresh-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .logout-btn {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }
        
        .logout-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(107, 114, 128, 0.3);
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="maintenance-box">
            <div class="logo-section">
                <img src="image/image-removebg-preview.png" alt="Logo">
                <h2>SYSTEM MAINTENANCE</h2>
                <p>Database is currently unavailable</p>
            </div>
            
            <div class="maintenance-icon">
                <i class="fas fa-tools"></i>
            </div>
            
            <div class="status-info">
                <h3><i class="fas fa-exclamation-triangle"></i> System Status</h3>
                <p>The database is currently undergoing maintenance or experiencing technical difficulties. Our technical team is working to resolve this issue as quickly as possible.</p>
            </div>
            
            <div class="contact-info">
                <h3><i class="fas fa-info-circle"></i> What You Can Do</h3>
                <p><strong>• Try refreshing the page</strong> - The issue may have been resolved</p>
                <p><strong>• Contact your administrator</strong> - They have access to recovery tools</p>
                <p><strong>• Wait for maintenance to complete</strong> - Usually takes a few minutes</p>
            </div>
            
            <button class="refresh-btn" onclick="window.location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh Page
            </button>
            
            <?php if (isset($_SESSION['username'])): ?>
            <br>
            <button class="logout-btn" onclick="window.location.href='logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
            <?php endif; ?>
            
            <div class="contact-info" style="margin-top: 30px;">
                <h3><i class="fas fa-clock"></i> Estimated Resolution Time</h3>
                <p>Most database issues are resolved within 5-15 minutes. If the problem persists, please contact your system administrator.</p>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-refresh every 30 seconds
        let refreshTimeout;
        let countdown = 30;
        
        // Show countdown
        const countdownElement = document.createElement('div');
        countdownElement.style.cssText = 'margin-top: 15px; color: #6b7280; font-size: 12px;';
        document.querySelector('.maintenance-box').appendChild(countdownElement);
        
        // Database status checker
        function checkDatabaseStatus() {
            fetch('crud.php?action=check_session', {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success || !data.database_error) {
                    // Database is back online!
                    showDatabaseRestoredMessage();
                }
            })
            .catch(error => {
                // Network error, continue checking
                console.log('Database check failed:', error);
            });
        }
        
        function showDatabaseRestoredMessage() {
            // Clear refresh timeout
            clearTimeout(refreshTimeout);
            
            // Show success message
            const successDiv = document.createElement('div');
            successDiv.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: linear-gradient(135deg, #10b981, #059669);
                color: white;
                padding: 15px 25px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
                z-index: 9999;
                font-weight: 600;
                animation: slideDown 0.5s ease-out;
            `;
            successDiv.innerHTML = `
                <i class="fas fa-check-circle" style="margin-right: 8px;"></i>
                Database Restored! Redirecting to dashboard...
            `;
            
            // Add animation CSS
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideDown {
                    from { transform: translateX(-50%) translateY(-100%); opacity: 0; }
                    to { transform: translateX(-50%) translateY(0); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
            document.body.appendChild(successDiv);
            
            // Update countdown to show redirect
            countdownElement.textContent = 'Redirecting in 3 seconds...';
            
            // Redirect after 3 seconds - check if user is viewer/teacher
            setTimeout(() => {
                // Check if user is viewer (no department, not admin)
                const isViewer = <?php echo (empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1)) ? 'true' : 'false'; ?>;
                
                if (isViewer) {
                    window.location.href = 'department.php';
                } else {
                    window.location.href = 'dashboard.php';
                }
            }, 3000);
        }
        
        const updateCountdown = () => {
            if (countdown > 0) {
                countdownElement.textContent = `Auto-refresh in ${countdown} seconds`;
                countdown--;
            } else {
                countdownElement.textContent = 'Checking database status...';
                checkDatabaseStatus();
                countdown = 30;
            }
        };
        
        // Start countdown
        updateCountdown();
        const countdownInterval = setInterval(updateCountdown, 1000);
        
        // Set refresh timeout
        refreshTimeout = setTimeout(function() {
            window.location.reload();
        }, 30000);
        
        // Also check when page becomes visible
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                checkDatabaseStatus();
            }
        });
    </script>
</body>
</html>

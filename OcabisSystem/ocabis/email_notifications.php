<?php
/**
 * Email Notification Functions for OCABIS
 * Handles sending emails for registration approval/rejection
 */

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!function_exists('getAppBaseUrlOptions')) {
    /**
     * Determine possible base URLs for application links used in emails.
     * Prefers environment overrides but keeps local fallback available.
     * Includes IP address options for intranet access.
     *
     * @return array<string> Unique list of base URLs ordered by priority
     */
    function getAppBaseUrlOptions(): array {
        $urls = [];

        $envList = getenv('APP_BASE_URLS');
        if (!empty($envList)) {
            foreach (explode(',', $envList) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $urls[] = $candidate;
                }
            }
        }

        $envBase = getenv('APP_BASE_URL');
        if (!empty($envBase)) {
            $urls[] = $envBase;
        }

        $scheme = 'http';
        if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https')) {
            $scheme = 'https';
        }

        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $scriptDir = rtrim($scriptDir, '/\\');

        // Get HTTP_HOST (can be domain or IP)
        if (!empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
            $base = $scheme . '://' . $host;
            if (!empty($scriptDir) && $scriptDir !== '.' && $scriptDir !== '/') {
                $base .= $scriptDir;
            }
            $urls[] = $base;
        }

        // Get network IP addresses for intranet access (prioritize these)
        $networkIPs = [];
        
        // Method 1: Get IP from SERVER_ADDR (if not localhost)
        $serverIP = $_SERVER['SERVER_ADDR'] ?? null;
        if (!empty($serverIP) && $serverIP !== '127.0.0.1' && filter_var($serverIP, FILTER_VALIDATE_IP)) {
            $networkIPs[] = $serverIP;
        }

        // Method 2: Get IP from hostname
        if (function_exists('gethostbyname')) {
            $hostname = gethostname();
            $localIP = gethostbyname($hostname);
            if ($localIP !== $hostname && $localIP !== '127.0.0.1' && filter_var($localIP, FILTER_VALIDATE_IP) && !in_array($localIP, $networkIPs)) {
                $networkIPs[] = $localIP;
            }
        }

        // Method 3: Get IPs from network interfaces (Windows - most reliable)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = @shell_exec('ipconfig');
            if ($output) {
                preg_match_all('/IPv4 Address[^\d]+(\d+\.\d+\.\d+\.\d+)/', $output, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $ip) {
                        if ($ip !== '127.0.0.1' && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $networkIPs)) {
                            $networkIPs[] = $ip;
                        }
                    }
                }
            }
        }

        // Add network IP addresses to URLs (prioritize these for intranet access)
        foreach ($networkIPs as $ip) {
            $ipBase = 'http://' . $ip;
            if (!empty($scriptDir) && $scriptDir !== '.' && $scriptDir !== '/') {
                $ipBase .= $scriptDir;
            }
            // Insert at beginning to prioritize IP addresses
            array_unshift($urls, $ipBase);
        }

        // Add localhost as last resort (only works on same device)
        $urls[] = 'http://localhost/ocabisFrontend/ocabis';

        $urls = array_map(static fn($url) => rtrim($url, '/'), $urls);
        $urls = array_unique(array_filter($urls));

        return array_values($urls);
    }
}

if (!function_exists('getAppBaseUrl')) {
    function getAppBaseUrl(): string {
        $options = getAppBaseUrlOptions();
        return $options[0] ?? 'http://localhost/ocabisFrontend/ocabis';
    }
}

if (!function_exists('buildAppLinks')) {
    /**
     * Build prioritized absolute URLs for a given application path across all base URL options.
     *
     * @param string $path Relative path within the application (e.g. 'login.php').
     * @return array<string>
     */
    function buildAppLinks(string $path): array {
        $trimmedPath = ltrim($path, '/');
        return array_map(
            static fn($base) => rtrim($base, '/') . '/' . $trimmedPath,
            getAppBaseUrlOptions()
        );
    }
}

if (!function_exists('renderAlternateLinkHtml')) {
    /**
     * Render HTML snippet listing alternate links (if any) beneath a primary action button.
     *
     * @param array<string> $links Ordered list of absolute URLs
     * @return string HTML snippet
     */
    function renderAlternateLinkHtml(array $links): string {
        if (count($links) <= 1) {
            return '';
        }

        $items = '';
        $alternatives = array_slice($links, 1);
        foreach ($alternatives as $index => $altLink) {
            $label = count($alternatives) === 1 ? 'Alternative link' : 'Alternative link ' . ($index + 1);
            $items .= "<p style='margin: 4px 0;'><strong>{$label}:</strong> <a href='{$altLink}' style='color: #2563eb;'>{$altLink}</a></p>";
        }

        return "<div style='margin-top: 18px; font-size: 14px; color: #555;'>{$items}</div>";
    }
}

/**
 * Send registration approval email to user
 * @param string $email User's email address
 * @param string $username User's username
 * @return bool True if email sent successfully, false otherwise
 */
function sendApprovalEmail($email, $username) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Registration Approved - OCABIS";

        // Get login URL
        $loginLinks = buildAppLinks('login.php');
        $loginUrl = $loginLinks[0] ?? getAppBaseUrl() . '/login.php';

        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .success-icon { font-size: 48px; color: #28a745; margin-bottom: 20px; }
                .login-button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; margin: 25px 0; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
                .login-button:hover { background: linear-gradient(135deg, #5568d3 0%, #6a3d8f 100%); }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎉 Registration Approved!</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='success-icon'>✅</div>
                        <h2>Congratulations, " . htmlspecialchars($username) . "!</h2>
                        <p>Your registration has been <strong>approved</strong> by the administrator. You can now log in to your account and start using the OCABIS Intranet Management System.</p>
                        
                        <a href='" . htmlspecialchars($loginUrl) . "' class='login-button' style='color: white; text-decoration: none;'>🔐 Login to Intranet</a>
                        
                        <p><strong>What's next?</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Click the button above to log in to your account</li>
                            <li>Use your username and password to access the system</li>
                            <li>Explore the dashboard and available features</li>
                            <li>Contact your administrator if you have any questions</li>
                        </ul>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>If you have any questions, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Approval email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send registration rejection email to user
 * @param string $email User's email address
 * @param string $username User's username
 * @param string $reason Optional rejection reason
 * @return bool True if email sent successfully, false otherwise
 */
function sendRejectionEmail($email, $username, $reason = '') {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Registration Status Update - OCABIS";

        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .rejection-icon { font-size: 48px; color: #dc3545; margin-bottom: 20px; }
                .reason-box { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>📋 Registration Status Update</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='rejection-icon'>❌</div>
                        <h2>Registration Not Approved</h2>
                        <p>Dear " . htmlspecialchars($username) . ",</p>
                        <p>We regret to inform you that your registration request has not been approved at this time.</p>";
        
        if (!empty($reason)) {
            $mail->Body .= "
                        <div class='reason-box'>
                            <strong>Reason:</strong> " . htmlspecialchars($reason) . "
                        </div>";
        }
        
        $mail->Body .= "
                        <p><strong>What you can do:</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Contact your administrator for more information</li>
                            <li>Verify that your registration details are correct</li>
                            <li>Ensure you have the proper authorization to access the system</li>
                        </ul>
                        
                        <p>If you believe this is an error, please contact your system administrator.</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>For assistance, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Rejection email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send registration confirmation email to user
 * @param string $email User's email address
 * @param string $username User's username
 * @return bool True if email sent successfully, false otherwise
 */
function sendRegistrationConfirmationEmail($email, $username) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Registration Complete - OCABIS";

        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .success-icon { font-size: 48px; color: #28a745; margin-bottom: 20px; }
                .info-box { background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>📋 Registration Complete!</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='success-icon'>⏳</div>
                        <h2>Thank you for registering, " . htmlspecialchars($username) . "!</h2>
                        <p>Your account has been successfully created and is now pending admin approval.</p>
                        
                        <div class='info-box'>
                            <h3 style='margin-top: 0; color: #856404;'>⏳ Waiting for Approval</h3>
                            <p style='color: #856404; margin-bottom: 0;'>
                                <strong>Your registration is complete!</strong> However, your account is currently pending approval from an administrator. 
                                You will receive another email notification once your account has been reviewed.
                            </p>
                        </div>
                        
                        <p><strong>What happens next?</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>An administrator will review your registration request</li>
                            <li>You will receive an email notification when your account is approved or rejected</li>
                            <li>If approved, you can then log in to access the OCABIS Intranet Management System</li>
                            <li>If rejected, you will receive information about why and what to do next</li>
                        </ul>
                        
                        <p style='margin-top: 30px;'><strong>Please check your email regularly for updates on your account status.</strong></p>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>If you have any questions, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Registration confirmation email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send notification email to admin about new registration
 * @param string $adminEmail Admin's email address
 * @param string $username New user's username
 * @param string $email New user's email
 * @param string $department New user's department
 * @return bool True if email sent successfully, false otherwise
 */
function sendAdminNotificationEmail($adminEmail, $username, $email, $department) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($adminEmail);
        $mail->isHTML(true);
        $mail->Subject = "New User Registration - OCABIS";

        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .notification-icon { font-size: 48px; color: #ffc107; margin-bottom: 20px; }
                .user-details { background: white; padding: 20px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #667eea; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔔 New Registration Request</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='notification-icon'>📋</div>
                        <h2>New User Registration</h2>
                        <p>A new user has registered and is waiting for your approval.</p>
                        
                        <div class='user-details'>
                            <h3>User Details:</h3>
                            <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                            <p><strong>Email:</strong> " . htmlspecialchars($email) . "</p>
                            <p><strong>Department:</strong> " . htmlspecialchars($department) . "</p>
                            <p><strong>Registration Date:</strong> " . date('F j, Y \a\t g:i A') . "</p>
                        </div>
                        
                        <p>Please review and approve or reject this registration request in the OCABIS Intranet Management System.</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated notification from the OCABIS Intranet Management System.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Admin notification email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send item request approval email to user
 * @param string $email User's email address
 * @param string $username User's username
 * @param string $itemName Name of the requested item
 * @return bool True if email sent successfully, false otherwise
 */
function sendItemRequestApprovalEmail($email, $username, $itemName) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Item Request Approved - OCABIS";

        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .success-icon { font-size: 48px; color: #22c55e; margin-bottom: 20px; }
                .item-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #22c55e; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✅ Request Approved!</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='success-icon'>🎉</div>
                        <h2>Great news, " . htmlspecialchars($username) . "!</h2>
                        <p>Your item request has been <strong>approved</strong> by the administrator.</p>
                        
                        <div class='item-details'>
                            <h3>Request Details:</h3>
                            <p><strong>Item Requested:</strong> " . htmlspecialchars($itemName) . "</p>
                            <p><strong>Status:</strong> <span style='color: #22c55e; font-weight: bold;'>APPROVED</span></p>
                            <p><strong>Approval Date:</strong> " . date('F j, Y \a\t g:i A') . "</p>
                        </div>
                        
                        <p><strong>What's next?</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Contact your administrator to arrange pickup</li>
                            <li>Check the OCABIS Intranet Management System for any additional instructions</li>
                            <li>Ensure you have proper authorization for the item</li>
                        </ul>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>If you have any questions, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Item request approval email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send item request rejection email to user
 * @param string $email User's email address
 * @param string $username User's username
 * @param string $itemName Name of the requested item
 * @param string $reason Optional rejection reason
 * @return bool True if email sent successfully, false otherwise
 */
function sendItemRequestRejectionEmail($email, $username, $itemName, $reason = '') {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Item Request Status Update - OCABIS";

        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .rejection-icon { font-size: 48px; color: #ef4444; margin-bottom: 20px; }
                .item-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ef4444; }
                .reason-box { background: #fef2f2; border: 1px solid #fecaca; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>📋 Request Status Update</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='rejection-icon'>❌</div>
                        <h2>Request Not Approved</h2>
                        <p>Dear " . htmlspecialchars($username) . ",</p>
                        <p>We regret to inform you that your item request has not been approved at this time.</p>
                        
                        <div class='item-details'>
                            <h3>Request Details:</h3>
                            <p><strong>Item Requested:</strong> " . htmlspecialchars($itemName) . "</p>
                            <p><strong>Status:</strong> <span style='color: #ef4444; font-weight: bold;'>REJECTED</span></p>
                            <p><strong>Decision Date:</strong> " . date('F j, Y \a\t g:i A') . "</p>
                        </div>";
        
        if (!empty($reason)) {
            $mail->Body .= "
                        <div class='reason-box'>
                            <strong>Reason:</strong> " . htmlspecialchars($reason) . "
                        </div>";
        }
        
        $mail->Body .= "
                        <p><strong>What you can do:</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Contact your administrator for more information</li>
                            <li>Verify that your request details are correct</li>
                            <li>Consider requesting alternative items if available</li>
                            <li>Ensure you have the proper authorization for the requested item</li>
                        </ul>
                        
                        <p>If you believe this is an error, please contact your system administrator.</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>For assistance, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Item request rejection email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send due date reminder email to borrower
 * @param string $email Borrower's email address
 * @param string $borrowerName Borrower's name
 * @param string $itemName Name of the borrowed item
 * @param string $dueDate Due date of the item
 * @param int $daysUntilDue Days until due date
 * @return bool True if email sent successfully, false otherwise
 */
function sendDueDateReminderEmail($email, $borrowerName, $itemName, $dueDate, $daysUntilDue) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Due Date Reminder - OCABIS";

        // HTML email body
        $urgencyColor = $daysUntilDue <= 1 ? '#dc2626' : ($daysUntilDue <= 3 ? '#f59e0b' : '#3b82f6');
        $urgencyText = $daysUntilDue <= 1 ? 'URGENT' : ($daysUntilDue <= 3 ? 'SOON' : 'REMINDER');
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Due Date Reminder</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; }
                .header { background: linear-gradient(135deg, #e53e3e, #c53030); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; }
                .alert-box { background: {$urgencyColor}; color: white; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; font-weight: bold; font-size: 18px; }
                .item-details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .detail-row { display: flex; justify-content: space-between; margin: 10px 0; padding: 8px 0; border-bottom: 1px solid #e9ecef; }
                .detail-label { font-weight: bold; color: #495057; }
                .detail-value { color: #212529; }
                .footer { background: #6c757d; color: white; padding: 20px; text-align: center; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>📅 Due Date Reminder</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$borrowerName}</strong>,</p>
                    
                    <div class='alert-box'>
                        {$urgencyText}: Your borrowed item is due in {$daysUntilDue} day(s)
                    </div>
                    
                    <p>This is a friendly reminder that you have a borrowed item that is approaching its due date.</p>
                    
                    <div class='item-details'>
                        <h3>📦 Borrowed Item Details</h3>
                        <div class='detail-row'>
                            <span class='detail-label'>Item Name:</span>
                            <span class='detail-value'>{$itemName}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Due Date:</span>
                            <span class='detail-value'>{$dueDate}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Days Remaining:</span>
                            <span class='detail-value'>{$daysUntilDue} day(s)</span>
                        </div>
                    </div>
                    
                    <p><strong>Please ensure you return the item on or before the due date to avoid any penalties.</strong></p>
                    
                    <p>If you need to extend the borrowing period, please contact your department administrator as soon as possible.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated reminder from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>For assistance, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Due date reminder email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send overdue item email to borrower
 * @param string $email Borrower's email address
 * @param string $borrowerName Borrower's name
 * @param string $itemName Name of the overdue item
 * @param string $dueDate Due date of the item
 * @param int $daysOverdue Days overdue
 * @return bool True if email sent successfully, false otherwise
 */
function sendOverdueItemEmail($email, $borrowerName, $itemName, $dueDate, $daysOverdue) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "URGENT: Overdue Item - OCABIS";

        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Overdue Item Notice</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; }
                .header { background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; }
                .alert-box { background: #dc2626; color: white; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; font-weight: bold; font-size: 20px; }
                .item-details { background: #fef2f2; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #dc2626; }
                .detail-row { display: flex; justify-content: space-between; margin: 10px 0; padding: 8px 0; border-bottom: 1px solid #fecaca; }
                .detail-label { font-weight: bold; color: #991b1b; }
                .detail-value { color: #7f1d1d; }
                .footer { background: #6c757d; color: white; padding: 20px; text-align: center; font-size: 12px; }
                .urgent-notice { background: #fef3c7; border: 2px solid #f59e0b; padding: 15px; border-radius: 8px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⚠️ OVERDUE ITEM NOTICE</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <p>Dear <strong>{$borrowerName}</strong>,</p>
                    
                    <div class='alert-box'>
                        URGENT: Your borrowed item is {$daysOverdue} day(s) OVERDUE
                    </div>
                    
                    <p>This is an urgent notice that you have a borrowed item that is past its due date.</p>
                    
                    <div class='item-details'>
                        <h3>📦 Overdue Item Details</h3>
                        <div class='detail-row'>
                            <span class='detail-label'>Item Name:</span>
                            <span class='detail-value'>{$itemName}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Original Due Date:</span>
                            <span class='detail-value'>{$dueDate}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>Days Overdue:</span>
                            <span class='detail-value'>{$daysOverdue} day(s)</span>
                        </div>
                    </div>
                    
                    <div class='urgent-notice'>
                        <h4>🚨 IMMEDIATE ACTION REQUIRED</h4>
                        <p><strong>Please return this item immediately to avoid further penalties or restrictions on your borrowing privileges.</strong></p>
                    </div>
                    
                    <p>If you have already returned the item, please contact your department administrator to update the OCABIS Intranet Management System.</p>
                    
                    <p>Continued failure to return overdue items may result in:</p>
                    <ul>
                        <li>Suspension of borrowing privileges</li>
                        <li>Additional penalties or fees</li>
                        <li>Escalation to department management</li>
                    </ul>
                </div>
                <div class='footer'>
                    <p>This is an automated urgent notice from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>For assistance, please contact your system administrator immediately.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Overdue item email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send database backup email to super admin
 * @param string $superAdminEmail Super admin's email address
 * @param string $backupFilePath Full path to the backup file
 * @param string $backupFilename Name of the backup file
 * @return bool True if email sent successfully, false otherwise
 */
function sendDatabaseBackupEmail($superAdminEmail, $backupFilePath, $backupFilename) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Get file size for display
        $fileSize = filesize($backupFilePath);
        $fileSizeFormatted = formatBytes($fileSize);
        $backupDate = date('F j, Y \a\t g:i A');

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($superAdminEmail);
        $mail->isHTML(true);
        $mail->Subject = "Monthly Database Backup - OCABIS";

        // HTML email body
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Monthly Database Backup</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; }
                .container { max-width: 600px; margin: 0 auto; background-color: white; }
                .header { background: linear-gradient(135deg, #e53e3e, #c53030); color: white; padding: 30px; text-align: center; }
                .content { padding: 30px; }
                .success-icon { font-size: 48px; color: #22c55e; margin-bottom: 20px; }
                .backup-details { background: #f0f9ff; border-left: 4px solid #0ea5e9; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .detail-row { display: flex; justify-content: space-between; margin: 10px 0; padding: 8px 0; border-bottom: 1px solid #bae6fd; }
                .detail-label { font-weight: bold; color: #0c4a6e; }
                .detail-value { color: #0e7490; }
                .footer { background: #6c757d; color: white; padding: 20px; text-align: center; font-size: 12px; }
                .info-box { background: #fffbeb; border: 2px solid #fbbf24; padding: 15px; border-radius: 8px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>💾 Monthly Database Backup</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='success-icon'>✅</div>
                        <h2>Automatic Monthly Backup Completed</h2>
                    </div>
                    
                    <p>Dear <strong>Super Administrator</strong>,</p>
                    
                    <p>Your monthly database backup has been successfully created and is attached to this email.</p>
                    
                    <div class='backup-details'>
                        <h3>📊 Backup Information</h3>
                        <div class='detail-row'>
                            <span class='detail-label'>Backup Date:</span>
                            <span class='detail-value'>{$backupDate}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>File Name:</span>
                            <span class='detail-value'>{$backupFilename}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='detail-label'>File Size:</span>
                            <span class='detail-value'>{$fileSizeFormatted}</span>
                        </div>
                    </div>
                    
                    <div class='info-box'>
                        <h4>📌 Important Information</h4>
                        <ul style='margin: 10px 0; padding-left: 20px;'>
                            <li>This backup contains all database data and structure</li>
                            <li>Keep this file in a secure location</li>
                            <li>The backup includes all tables and their data</li>
                            <li>You can use this file to restore the database if needed</li>
                        </ul>
                    </div>
                    
                    <p><strong>What's included in this backup:</strong></p>
                    <ul>
                        <li>Complete database structure (all tables)</li>
                        <li>All user data and accounts</li>
                        <li>All inventory items and records</li>
                        <li>All borrowing history and transactions</li>
                        <li>All system configuration and settings</li>
                    </ul>
                    
                    <p><strong>Note:</strong> This is an automated monthly backup. Keep this file for disaster recovery purposes.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated backup notification from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>For assistance, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        // Attach the backup file
        $mail->addAttachment($backupFilePath, $backupFilename);

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Database backup email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Helper function to format bytes
 * @param int $bytes Bytes to format
 * @param int $precision Precision
 * @return string Formatted size
 */
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Send borrow request approval email to borrower
 * @param string $email Borrower's email address
 * @param string $borrowerName Borrower's name
 * @param string $itemName Name of the borrowed item
 * @param string $borrowDate Borrow date
 * @param string $dueDate Due date
 * @return bool True if email sent successfully, false otherwise
 */
function sendBorrowApprovalEmail($email, $borrowerName, $itemName, $borrowDate, $dueDate) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Borrow Request Approved - OCABIS";

        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .approval-icon { font-size: 48px; color: #10b981; margin-bottom: 20px; }
                .item-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #10b981; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✓ Borrow Request Approved</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='approval-icon'>✓</div>
                        <h2>Your Borrow Request Has Been Approved</h2>
                        <p>Dear " . htmlspecialchars($borrowerName) . ",</p>
                        <p>We are pleased to inform you that your borrow request has been approved.</p>
                        
                        <div class='item-details'>
                            <h3>Borrow Details:</h3>
                            <p><strong>Item:</strong> " . htmlspecialchars($itemName) . "</p>
                            <p><strong>Borrow Date:</strong> " . htmlspecialchars($borrowDate) . "</p>
                            <p><strong>Due Date:</strong> " . htmlspecialchars($dueDate) . "</p>
                            <p><strong>Status:</strong> <span style='color: #10b981; font-weight: bold;'>APPROVED</span></p>
                        </div>
                        
                        <p><strong>Important Reminders:</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Please return the item on or before the due date</li>
                            <li>Take good care of the borrowed item</li>
                            <li>Contact the administrator if you need to extend the borrow period</li>
                        </ul>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>For assistance, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Borrow approval email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send unlock account email with new password to user
 * @param string $email User's email address
 * @param string $username User's username
 * @param string $newPassword New password generated for the user
 * @return bool True if email sent successfully, false otherwise
 */
function sendUnlockAccountEmail($email, $username, $newPassword) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Account Unlocked - New Password - OCABIS";

        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .success-icon { font-size: 48px; color: #10b981; margin-bottom: 20px; }
                .password-box { background: #d1fae5; border: 2px solid #10b981; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
                .password-text { font-size: 24px; font-weight: bold; color: #065f46; font-family: 'Courier New', monospace; letter-spacing: 2px; }
                .warning-box { background: #fef3c7; border: 1px solid #fbbf24; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔓 Account Unlocked</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='success-icon'>✅</div>
                        <h2>Your Account Has Been Unlocked</h2>
                        <p>Dear " . htmlspecialchars($username) . ",</p>
                        <p>Your account has been unlocked by the administrator. A new password has been generated for your account.</p>
                        
                        <div class='password-box'>
                            <h3 style='margin-top: 0; color: #065f46;'>Your New Password</h3>
                            <div class='password-text'>" . htmlspecialchars($newPassword) . "</div>
                            <p style='color: #047857; margin-bottom: 0; font-size: 14px;'>Please use this password to log in to your account</p>
                        </div>
                        
                        <div class='warning-box'>
                            <h4 style='margin-top: 0; color: #92400e;'>⚠️ Important Security Notice</h4>
                            <p style='color: #92400e; margin-bottom: 0;'>
                                <strong>Please change your password immediately after logging in</strong> for security purposes. 
                                Do not share this password with anyone.
                            </p>
                        </div>
                        
                        <p><strong>What to do next:</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Log in to the OCABIS Intranet Management System using your username and the new password above</li>
                            <li>Change your password to something memorable after logging in</li>
                            <li>Keep your password secure and do not share it with anyone</li>
                            <li>Contact the administrator if you have any questions</li>
                        </ul>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>If you have any questions, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Unlock account email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send borrow request rejection email to borrower
 * @param string $email Borrower's email address
 * @param string $borrowerName Borrower's name
 * @param string $itemName Name of the requested item
 * @return bool True if email sent successfully, false otherwise
 */
function sendBorrowRejectionEmail($email, $borrowerName, $itemName) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Borrow Request Status Update - OCABIS";

        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .rejection-icon { font-size: 48px; color: #ef4444; margin-bottom: 20px; }
                .item-details { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ef4444; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Borrow Request Status Update</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='rejection-icon'>✗</div>
                        <h2>Borrow Request Not Approved</h2>
                        <p>Dear " . htmlspecialchars($borrowerName) . ",</p>
                        <p>We regret to inform you that your borrow request has not been approved at this time.</p>
                        
                        <div class='item-details'>
                            <h3>Request Details:</h3>
                            <p><strong>Item Requested:</strong> " . htmlspecialchars($itemName) . "</p>
                            <p><strong>Status:</strong> <span style='color: #ef4444; font-weight: bold;'>DECLINED</span></p>
                            <p><strong>Decision Date:</strong> " . date('F j, Y \a\t g:i A') . "</p>
                        </div>
                        
                        <p><strong>What you can do:</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Contact your administrator for more information</li>
                            <li>Verify that your request details are correct</li>
                            <li>Consider requesting alternative items if available</li>
                            <li>Ensure you have the proper authorization for the requested item</li>
                        </ul>
                        
                        <p>If you believe this is an error, please contact your system administrator.</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>For assistance, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Borrow rejection email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send department head account creation email with auto-generated password
 * @param string $email Department head's email address
 * @param string $username Department head's username
 * @param string $password Auto-generated password
 * @param string $department Department name
 * @return bool True if email sent successfully, false otherwise
 */
function sendDepartmentHeadAccountEmail($email, $username, $password, $department) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Department Head Account Created - OCABIS";

        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .success-icon { font-size: 48px; color: #667eea; margin-bottom: 20px; }
                .account-box { background: white; border: 2px solid #667eea; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .password-box { background: #e0e7ff; border: 2px solid #667eea; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
                .password-text { font-size: 24px; font-weight: bold; color: #4338ca; font-family: 'Courier New', monospace; letter-spacing: 2px; }
                .warning-box { background: #fef3c7; border: 1px solid #fbbf24; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>👤 Department Head Account Created</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='success-icon'>✅</div>
                        <h2>Welcome, " . htmlspecialchars($username) . "!</h2>
                        <p>Your Department Head account has been successfully created.</p>
                        
                        <div class='account-box'>
                            <h3 style='margin-top: 0; color: #4338ca;'>Account Details</h3>
                            <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                            <p><strong>Department:</strong> " . htmlspecialchars($department) . "</p>
                            <p><strong>Role:</strong> Department Head</p>
                        </div>
                        
                        <div class='password-box'>
                            <h3 style='margin-top: 0; color: #4338ca;'>Your Auto-Generated Password</h3>
                            <div class='password-text'>" . htmlspecialchars($password) . "</div>
                            <p style='color: #4338ca; margin-bottom: 0; font-size: 14px;'>Please use this password to log in to your account</p>
                        </div>
                        
                        <div class='warning-box'>
                            <h4 style='margin-top: 0; color: #92400e;'>⚠️ Important Security Notice</h4>
                            <p style='color: #92400e; margin-bottom: 0;'>
                                <strong>Please change your password immediately after logging in</strong> for security purposes. 
                                Do not share this password with anyone.
                            </p>
                        </div>
                        
                        <p><strong>What to do next:</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Log in to the OCABIS Intranet Management System using your username and the password above</li>
                            <li>Change your password to something memorable after logging in</li>
                            <li>Keep your password secure and do not share it with anyone</li>
                            <li>Contact the system administrator if you have any questions</li>
                        </ul>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>If you have any questions, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Department head account email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send user account credentials email to newly created user
 * @param string $email User's email address
 * @param string $username User's username
 * @param string $password User's password (plain text)
 * @param string $department User's department (optional)
 * @param string $role User's role (e.g., "Regular User", "Department Head", "Teacher")
 * @return bool True if email sent successfully, false otherwise
 */
function sendUserAccountEmail($email, $username, $password, $department = '', $role = 'Regular User') {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;
        $mail->Username = "capstone12025@gmail.com";
        $mail->Password = "ehsp zlyl vkuc xtvd"; // app password
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];

        // Email content
        $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = "Your Account Has Been Created - OCABIS";

        $departmentDisplay = !empty($department) ? htmlspecialchars($department) : 'N/A';
        
        // Get login URL
        $loginLinks = buildAppLinks('login.php');
        $loginUrl = $loginLinks[0] ?? getAppBaseUrl() . '/login.php';
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .success-icon { font-size: 48px; color: #667eea; margin-bottom: 20px; }
                .account-box { background: white; border: 2px solid #667eea; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .password-box { background: #e0e7ff; border: 2px solid #667eea; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
                .password-text { font-size: 24px; font-weight: bold; color: #4338ca; font-family: 'Courier New', monospace; letter-spacing: 2px; }
                .warning-box { background: #fef3c7; border: 1px solid #fbbf24; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #f59e0b; }
                .login-button { display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; margin: 25px 0; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
                .login-button:hover { background: linear-gradient(135deg, #5568d3 0%, #6a3d8f 100%); }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>👤 Account Created</h1>
                    <p>OCABIS Intranet Management System</p>
                </div>
                <div class='content'>
                    <div style='text-align: center;'>
                        <div class='success-icon'>✅</div>
                        <h2>Welcome, " . htmlspecialchars($username) . "!</h2>
                        <p>Your account has been successfully created.</p>
                        
                        <div class='account-box'>
                            <h3 style='margin-top: 0; color: #4338ca;'>Account Details</h3>
                            <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                            <p><strong>Department:</strong> " . $departmentDisplay . "</p>
                            <p><strong>Role:</strong> " . htmlspecialchars($role) . "</p>
                        </div>
                        
                        <div class='password-box'>
                            <h3 style='margin-top: 0; color: #4338ca;'>Your Password</h3>
                            <div class='password-text'>" . htmlspecialchars($password) . "</div>
                            <p style='color: #4338ca; margin-bottom: 0; font-size: 14px;'>Please use this password to log in to your account</p>
                        </div>
                        
                        <a href='" . htmlspecialchars($loginUrl) . "' class='login-button' style='color: white; text-decoration: none;'>🔐 Login to Intranet</a>
                        
                        <div class='warning-box'>
                            <h4 style='margin-top: 0; color: #92400e;'>⚠️ Important Security Notice</h4>
                            <p style='color: #92400e; margin-bottom: 0;'>
                                <strong>Please change your password immediately after logging in</strong> for security purposes. 
                                Do not share this password with anyone.
                            </p>
                        </div>
                        
                        <p><strong>What to do next:</strong></p>
                        <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                            <li>Click the button above to log in to the OCABIS Intranet Management System</li>
                            <li>Use your username and the password shown above to access your account</li>
                            <li>Change your password to something memorable after logging in</li>
                            <li>Keep your password secure and do not share it with anyone</li>
                            <li>Contact the system administrator if you have any questions</li>
                        </ul>
                    </div>
                </div>
                <div class='footer'>
                    <p>This is an automated message from the OCABIS Intranet Management System. Please do not reply to this email.</p>
                    <p>If you have any questions, please contact your system administrator.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("User account email failed: " . $mail->ErrorInfo);
        return false;
    }
}

?>

<?php
session_start();
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$conn = @new mysqli('localhost', 'root', '', 'ocabis');
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$errors = [];
$success = false;
$successMessage = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    } else {
        // First check users table
        $check_lock_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked'");
        $has_lock_columns = ($check_lock_columns && $check_lock_columns->num_rows > 0);
        
        // Fetch id, username, and account_locked status (if column exists)
        if ($has_lock_columns) {
            $sql = "SELECT id, username, COALESCE(account_locked, 0) as account_locked FROM users WHERE email = ?";
        } else {
            $sql = "SELECT id, username FROM users WHERE email = ?";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $isSuperAdmin = false;
        $userTable = 'users';

        // If not found in users table, check super_admin table
        if (!$row) {
            $stmt->close();
            $super_admin_sql = "SELECT id, username, status FROM super_admin WHERE email = ?";
            $super_admin_stmt = $conn->prepare($super_admin_sql);
            $super_admin_stmt->bind_param("s", $email);
            $super_admin_stmt->execute();
            $super_admin_result = $super_admin_stmt->get_result();
            
            if ($super_admin_result->num_rows === 1) {
                $row = $super_admin_result->fetch_assoc();
                $isSuperAdmin = true;
                $userTable = 'super_admin';
            }
            $super_admin_stmt->close();
        } else {
            $stmt->close();
        }

        if ($row) {
            // Check if account is locked (only for users table)
            if (!$isSuperAdmin && $has_lock_columns && isset($row['account_locked']) && (int)$row['account_locked'] === 1) {
                $errors[] = "Your account is locked. Please contact the administrator to unlock your account.";
            } else {
                // Check if super admin account is inactive
                if ($isSuperAdmin && isset($row['status']) && $row['status'] === 'inactive') {
                    $errors[] = "Your super admin account is inactive. Please contact the system administrator.";
                } else {
                    $userId = $row['id'];
                    $username = $row['username'];

                    // fallback if username is empty
                    if (empty($username)) {
                        $username = explode('@', $email)[0];
                    }

                    // Generate a secure random password (12 characters: uppercase, lowercase, numbers)
                    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
                    $numbers = '0123456789';
                    $allChars = $uppercase . $lowercase . $numbers;
                    
                    // Ensure at least one of each required character type
                    $newPassword = '';
                    $newPassword .= $uppercase[random_int(0, strlen($uppercase) - 1)];
                    $newPassword .= $lowercase[random_int(0, strlen($lowercase) - 1)];
                    $newPassword .= $numbers[random_int(0, strlen($numbers) - 1)];
                    
                    // Fill the rest randomly (total 12 characters)
                    for ($i = strlen($newPassword); $i < 12; $i++) {
                        $newPassword .= $allChars[random_int(0, strlen($allChars) - 1)];
                    }
                    
                    // Shuffle the password to randomize character positions
                    $newPassword = str_shuffle($newPassword);

                    // Hash the new password
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                    // Update password in database
                    if ($isSuperAdmin) {
                        // Update super_admin table
                        $update = "UPDATE super_admin SET password=? WHERE email=?";
                    } else {
                        // Update users table and clear reset token fields
                        $update = "UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE email=?";
                    }
                    $up = $conn->prepare($update);
                    $up->bind_param("ss", $hashedPassword, $email);
                    $up->execute();
                    $up->close();

                $mail = new PHPMailer(true);
                try {
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

                    $mail->setFrom("capstone12025@gmail.com", "OCABIS Intranet Management System");
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = "Your New Password - OCABIS";
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8f9fa; padding: 20px;'>
                            <div style='background: white; border-radius: 10px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                                <div style='text-align: center; margin-bottom: 30px;'>
                                    <h2 style='color: #333; margin: 0; font-size: 24px;'>OCABIS Intranet Management System - Password Reset</h2>
                                    <div style='width: 50px; height: 3px; background: linear-gradient(45deg, #28a745, #20c997); margin: 10px auto;'></div>
                                </div>
                                
                                <p style='color: #333; font-size: 16px; margin-bottom: 10px;'>Hello <strong>" . htmlspecialchars($username) . "</strong>,</p>
                                
                                <p style='color: #666; line-height: 1.6; margin-bottom: 25px;'>
                                    Your password has been automatically reset. Please use the temporary password below to login to your OCABIS Intranet Management System account:
                                </p>
                                
                                <div style='background: #f8f9fa; padding: 25px; border-radius: 8px; margin: 25px 0; border: 2px solid #28a745;'>
                                    <p style='color: #666; margin: 0 0 10px 0; font-size: 14px; font-weight: bold; text-align: center;'>
                                        Your Temporary Password:
                                    </p>
                                    <div style='background: white; padding: 15px; border-radius: 6px; text-align: center;'>
                                        <p style='color: #28a745; margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 3px; font-family: monospace; word-break: break-all;'>
                                            " . htmlspecialchars($newPassword) . "
                                        </p>
                                    </div>
                                </div>
                                
                                <div style='border-left: 4px solid #ffc107; background: #fff3cd; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0;'>
                                    <p style='color: #856404; margin: 0; font-size: 14px;'>
                                        <strong>🔐 Important:</strong> Please change your password immediately after logging in for security purposes. You can change it in your profile settings.
                                    </p>
                                </div>
                                
                                <div style='border-left: 4px solid #17a2b8; background: #d1ecf1; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0;'>
                                    <p style='color: #0c5460; margin: 0; font-size: 14px;'>
                                        <strong>📝 Note:</strong> This is a temporary password. For your security, please update it to a password that only you know.
                                    </p>
                                </div>
                                
                                <div style='border-left: 4px solid #dc3545; background: #f8d7da; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0;'>
                                    <p style='color: #721c24; margin: 0; font-size: 14px;'>
                                        <strong>🛡️ Security Note:</strong> If you didn't request this password reset, please contact the administrator immediately and change your password.
                                    </p>
                                </div>
                                
                                <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                                
                                <div style='text-align: center;'>
                                    <p style='color: #999; font-size: 12px; margin: 0;'>
                                        This is an automated message from the OCABIS Intranet Management System.<br>
                                        Please do not reply to this email.
                                    </p>
                                </div>
                            </div>
                        </div>
                    ";

                    $mail->send();
                    $success = true;
                    $successMessage = "A new password has been generated and sent to your email address. Please check your inbox and login with the temporary password. Remember to change it after logging in.";
                } catch (Exception $e) {
                    $errors[] = "Failed to send email. Please try again later.";
                }
                }
            }
        } else {
            $errors[] = "Email address not found in our system.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="icon" type="image/png" href="assets/logo.png">
  <link rel="shortcut icon" type="image/png" href="assets/logo.png">
  <title>Forgot Password - OCABIS</title>
  <link rel="stylesheet" href="Css/login.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* Mobile Responsive Styles - Same Design, Scaled Down */
    @media (max-width: 768px) {
      body::before {
        content: "";
        position: fixed;
        inset: 0;
        background: none; /* move logo into card */
        opacity: 0;
        pointer-events: none;
        z-index: 0;
      }
      html, body { height: 100%; min-height: 100vh; overflow: hidden; }
      body {
        height: 100vh;
        min-height: 100vh;
        padding: 0;
        align-items: center;
        background-color: #f3f4f6; /* slightly darker neutral background */
      }

      .login-container { width: 100%; max-width: 100%; border-radius: 0; margin: 0; box-shadow: none; min-height: 100vh; height: 100vh; display: flex; align-items: center; justify-content: center; }

      .login-box { flex-direction: column; background: transparent; }

      .left-panel { display: none; }

      .left-panel h3 {
        font-size: 16px;
        margin-bottom: 15px;
      }

      .logo-title {
        justify-content: flex-start; /* Keep left alignment */
        margin-bottom: 15px;
      }

      .logo-title img {
        width: 45px;
        margin-right: 5px;
      }

      .logo-title h1 {
        font-size: 40px;
        letter-spacing: 10px;
      }

      .left-panel p {
        font-size: 13px;
      }

      .left-panel::before,
      .left-panel::after {
        display: block; /* Keep decorative elements */
        width: 100px;
        height: 100px;
      }

      .right-panel {
        padding: 25px 20px;
        border-radius: 18px;
        margin: 16px auto;
        background: rgba(255,255,255,0.97);
        box-shadow: 0 10px 24px rgba(0,0,0,0.12);
        backdrop-filter: blur(1px);
        position: relative;
        z-index: 1;
        min-height: auto;
        color: #111827;
      }

      /* Mobile brand header (match login) */
      .mobile-hero { display: flex; flex-direction: column; align-items: center; gap: 8px; margin-bottom: 16px; }
      .mobile-hero .mobile-welcome { font-weight: 700; font-size: 30px; color: #e11d48; letter-spacing: .5px; }
      .mobile-hero .mobile-logo { display: flex; align-items: center; gap: 8px; }
      .mobile-hero .mobile-logo img { width: 75px; height: 75px; }
      .mobile-hero .mobile-logo h1 { margin: 0; font-size: 50px; letter-spacing: 10px; color: #111827; }
      .mobile-hero .mobile-subtitle { font-size: clamp(12px, 4vw, 16px); color: #6b7280; white-space: nowrap; letter-spacing: 0.2px; }

      .right-panel h2 { font-size: 28px; text-align: center; }

      .right-panel p { font-size: 14px; text-align: center; margin: 8px 0 20px; }

      .input-group {
        margin-bottom: 15px;
      }

      .input-group input { padding: 14px 40px 14px 14px; font-size: 15px; border-radius: 12px; }

      .input-group i {
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 14px;
      }

      .login-btn { width: 100%; padding: 14px; font-size: 16px; margin-bottom: 6px; border-radius: 14px; }

      .signup-text {
        font-size: 11px;
        margin-top: 10px;
      }

      .signup-text a {
        white-space: nowrap; /* Prevent "Sign In" from breaking */
      }

      .error-list {
        padding: 12px;
        font-size: 12px;
        margin-bottom: 15px;
      }

      .error-list ul {
        padding-left: 18px;
      }

      .error-list li {
        margin-bottom: 4px;
        font-size: 11px;
      }

      .success-message {
        padding: 12px;
        font-size: 12px;
        margin-bottom: 15px;
      }

      .success-message p {
        font-size: 11px;
        line-height: 1.5;
      }

      .modal-content {
        width: 85%;
        max-width: 400px;
        padding: 25px;
        margin: 20px;
      }

      .modal-content h2 {
        font-size: 1.3rem;
        margin: 10px 0;
      }

      .modal-content p {
        font-size: 0.95rem;
        margin-bottom: 20px;
      }

      .modal-ok-btn {
        padding: 10px 20px;
        font-size: 14px;
      }

      .close-btn {
        top: 10px;
        right: 15px;
        font-size: 1.5rem;
      }

      #loading-spinner {
        width: 45px;
        height: 45px;
        border-width: 5px;
      }
    }

    @media (max-width: 480px) {
      body { padding: 0; overflow: hidden; }

      .login-container {
        border-radius: 25px;
      }

      .left-panel {
        padding: 20px 15px;
        border-radius: 25px 0 0 25px;
      }

      .left-panel h3 {
        font-size: 14px;
        margin-bottom: 12px;
      }

      .logo-title img {
        width: 35px;
      }

      .logo-title h1 {
        font-size: 32px;
        letter-spacing: 8px;
      }

      .left-panel p {
        font-size: 11px;
      }

      .left-panel::before,
      .left-panel::after {
        width: 80px;
        height: 80px;
      }

      .right-panel { padding: 20px 15px; border-radius: 16px; margin: 12px auto; min-height: auto; background: rgba(255,255,255,0.97); color:#111827; box-shadow: 0 8px 20px rgba(0,0,0,0.12); max-width: 520px; width: calc(100% - 24px); }

      /* Logo watermark inside the light card */
      .right-panel::before {
        content: "";
        position: absolute;
        inset: 0;
        background: url('image/image-removebg-preview.png') center 28% / 60% no-repeat;
        opacity: 0.06;
        pointer-events: none;
        border-radius: inherit;
      }

      .right-panel h2 { font-size: 24px; }

      .right-panel p { font-size: 13px; margin: 6px 0 15px; }

      .input-group input { padding: 12px 38px 12px 12px; font-size: 15px; border-radius: 12px; }

      .input-group i {
        right: 10px;
        font-size: 13px;
      }

      .login-btn { width: 100%; padding: 14px; font-size: 16px; border-radius: 14px; }

      .signup-text {
        font-size: 10px;
      }

      .signup-text a {
        white-space: nowrap; /* Prevent "Sign In" from breaking */
      }

      .error-list,
      .success-message {
        padding: 10px;
        font-size: 11px;
      }

      .modal-content {
        width: 90%;
        max-width: 350px;
        padding: 20px;
        margin: 15px;
        border-radius: 12px;
      }

      .modal-content h2 {
        font-size: 1.2rem;
      }

      .modal-content p {
        font-size: 0.9rem;
      }
    }
  </style>
</head>
<body>
  <!-- Loading overlay -->
  <div id="loading-overlay">
    <div id="loading-spinner"></div>
  </div>

  <div class="login-container">
    <div class="login-box">
      <div class="left-panel">
        <h3>WELCOME TO</h3>
        <div class="logo-title">
          <img src="image/image-removebg-preview.png" alt="Logo">
          <h1>CABIS</h1>
        </div>
        <p>INVENTORY MANAGEMENT SYSTEM</p>
      </div>
      <div class="right-panel">
        <!-- Mobile brand header (shown on mobile only) -->
        <div class="mobile-hero" style="display:none"></div>
        <script>
          (function(){
            if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
              var hero = document.querySelector('.mobile-hero');
              if (hero) {
                hero.style.display = 'flex';
                hero.innerHTML = '<div class="mobile-welcome">WELCOME TO</div>'+
                                 '<div class="mobile-logo">'+
                                 '  <img src="image/image-removebg-preview.png" alt="Logo">'+
                                 '  <h1>CABIS</h1>'+
                                 '</div>'+
                                 '<div class="mobile-subtitle">INVENTORY MANAGEMENT SYSTEM</div>';
              }
            }
          })();
        </script>
        <h2>FORGOT PASSWORD</h2>
        <p>Enter your email address to receive a password reset link</p>

        <?php if (!empty($errors)): ?>
          <div class="error-list">
            <ul>
              <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="success-message">
            <p><?= htmlspecialchars($successMessage) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form id="forgotForm" method="POST" action="" onsubmit="return onSubmitForm(event)">
          <div class="input-group">
            <input type="email" name="email" placeholder="Email Address" required 
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
            <i class="fas fa-envelope"></i>
          </div>

          <button type="submit" class="login-btn">SEND RESET LINK</button>

          <p class="signup-text">Remember your password? <a href="login.php">Sign In</a></p>
        </form>
        <?php else: ?>
          <p class="signup-text"><a href="login.php">Back to Sign In</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

<div id="alertModal" class="modal">
  <div class="modal-content">
    <span class="close-btn" onclick="closeModal()">&times;</span>
    <h2 id="modal-title"></h2>
    <p id="modal-message"></p>
    <button onclick="closeModal()" class="modal-ok-btn">OK</button>
  </div>
</div>

  <script>
    function onSubmitForm(event) {
      if (!validateForm()) {
        event.preventDefault();
        return false;
      }
      document.getElementById('loading-overlay').style.display = 'flex';
      return true;
    }

    function validateForm() {
      const email = document.querySelector('input[name="email"]').value.trim();

      if (email === '') {
        showModal('Please enter your email address.');
        return false;
      }

      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
        showModal('Please enter a valid email address.');
        return false;
      }

      return true;
    }

    function showModal(message, type = "info") {
      const modal = document.getElementById('alertModal');
      const modalMessage = document.getElementById('modal-message');
      const modalTitle = document.getElementById('modal-title');
      
      modal.classList.remove("success", "info", "error");
      modal.classList.add(type);

      if (type === "success") modalTitle.textContent = "Success";
      else if (type === "info") modalTitle.textContent = "Information";
      else if (type === "error") modalTitle.textContent = "Error";

      modalMessage.textContent = message;
      modal.style.display = 'flex';
    }

    function closeModal() {
      const modal = document.getElementById('alertModal');
      if (modal) {
        modal.style.display = 'none';
      }
    }

    window.onclick = function(event) {
      const modal = document.getElementById('alertModal');
      if (event.target === modal) {
        closeModal();
      }
    }

    window.addEventListener('load', () => {
      document.getElementById('loading-overlay').style.display = 'none';
    });

    document.addEventListener('DOMContentLoaded', function() {
      const successMsg = document.querySelector('.success-message');
      
      if (successMsg) {
        setTimeout(() => {
          successMsg.style.opacity = '0';
          setTimeout(() => successMsg.style.display = 'none', 300);
        }, 8000);
      }
    });

    <?php if ($success): ?>
    document.addEventListener('DOMContentLoaded', function() {
      showModal("<?= addslashes($successMessage) ?>", "success");
    });
    <?php endif; ?>
  </script>
</body>
</html>

<?php
session_start();

$conn = @new mysqli('localhost', 'root', '', 'ocabis');
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$token = $_GET['token'] ?? '';
$errors = [];
$success = false;
$tokenValid = false;
$tokenExpired = false;

// Check if token exists and is valid
if (!empty($token)) {
    $sql = "SELECT id, reset_expires FROM users WHERE reset_token=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        if (strtotime($row['reset_expires']) > time()) {
            $tokenValid = true;
        } else {
            $tokenExpired = true;
        }
    }
} else {
    $errors[] = "Invalid reset token.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $tokenValid) {
    $token = $_POST['token'];
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];

    // Validation
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    } elseif ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    } else {
        $newPass = password_hash($password, PASSWORD_BCRYPT);

        $sql = "SELECT id FROM users WHERE reset_token=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $update = "UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?";
            $up = $conn->prepare($update);
            $up->bind_param("si", $newPass, $row['id']);
            
            if ($up->execute()) {
                $success = true;
            } else {
                $errors[] = "Failed to update password. Please try again.";
            }
        } else {
            $errors[] = "Invalid token.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reset Password - OCABIS</title>
  <link rel="stylesheet" href="Css/login.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* Mobile responsiveness without changing desktop design */
    @media (max-width: 768px) {
      body { background: #f7f9fc; }
      .login-container { padding: 16px; }
      .login-box { max-width: 100%; width: 100%; height: auto; }
      .left-panel { padding: 20px; }
      .left-panel h3 { font-size: 14px; }
      .logo-title img { width: 40px; height: 40px; }
      .logo-title h1 { font-size: 22px; }
      .left-panel p { font-size: 12px; }
      .right-panel { padding: 20px; }
      .right-panel h2 { font-size: 20px; }
      .right-panel p { font-size: 13px; }
      .input-group { margin-bottom: 15px; }
      .input-group input { padding: 16px 44px 16px 16px; font-size: 17px; border-radius: 12px; }
      /* Hide browser password manager icons */
      .input-group input[type="password"]::-webkit-credentials-auto-fill-button,
      .input-group input[type="password"]::-webkit-strong-password-auto-fill-button {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
        position: absolute !important;
        right: -9999px !important;
      }
      .input-group input[type="password"]::-ms-reveal,
      .input-group input[type="password"]::-ms-clear {
        display: none !important;
      }
      .input-group i { right: 16px; top: 50%; transform: translateY(-50%); font-size: 20px; }
      .login-btn { padding: 16px; font-size: 18px; width: 100%; margin-bottom: 12px; border-radius: 14px; }
      .signup-text { font-size: 13px; }
      .signup-text a { white-space: nowrap; }
      .error-list li, .success-message p, .info-message p { font-size: 13px; }
      #loading-overlay { align-items: center; justify-content: center; }
      .modal-content { width: 90%; padding: 16px; }
      .modal-content h2 { font-size: 18px; }
      .modal-content p { font-size: 14px; }
      .modal-ok-btn { width: 100%; padding: 10px; }
    }
    @media (max-width: 480px) {
      .right-panel h2 { font-size: 18px; }
      .logo-title h1 { font-size: 20px; }
      .input-group input { padding: 16px 44px 16px 16px; font-size: 17px; border-radius: 12px; }
      /* Hide browser password manager icons */
      .input-group input[type="password"]::-webkit-credentials-auto-fill-button,
      .input-group input[type="password"]::-webkit-strong-password-auto-fill-button {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
        position: absolute !important;
        right: -9999px !important;
      }
      .input-group input[type="password"]::-ms-reveal,
      .input-group input[type="password"]::-ms-clear {
        display: none !important;
      }
      .input-group i { right: 16px; top: 50%; transform: translateY(-50%); font-size: 20px; }
      .login-btn { padding: 16px; font-size: 18px; border-radius: 14px; }
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
        <?php if ($success): ?>
          <h2>PASSWORD RESET</h2>
          <p>Your password has been successfully updated</p>
          
          <div class="success-message">
            <p>Password reset successful! You can now login with your new password.</p>
          </div>
          
          <button onclick="window.location.href='login.php'" class="login-btn">GO TO LOGIN</button>
          
        <?php elseif ($tokenExpired): ?>
          <h2>LINK EXPIRED</h2>
          <p>This password reset link has expired</p>
          
          <div class="info-message">
            <p>This password reset link has expired. Please request a new password reset link.</p>
          </div>
          
          <button onclick="window.location.href='forgot_password.php'" class="login-btn">REQUEST NEW LINK</button>
          <p class="signup-text"><a href="login.php">Back to Sign In</a></p>
          
        <?php elseif (!$tokenValid): ?>
          <h2>INVALID LINK</h2>
          <p>This password reset link is invalid</p>
          
          <div class="error-list">
            <ul>
              <li>This password reset link is invalid or has already been used.</li>
            </ul>
          </div>
          
          <button onclick="window.location.href='forgot_password.php'" class="login-btn">REQUEST NEW LINK</button>
          <p class="signup-text"><a href="login.php">Back to Sign In</a></p>
          
        <?php else: ?>
          <h2>RESET PASSWORD</h2>
          <p>Enter your new password below</p>

          <?php if (!empty($errors)): ?>
            <div class="error-list">
              <ul>
                <?php foreach ($errors as $error): ?>
                  <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form id="resetForm" method="POST" action="" onsubmit="return onSubmitForm(event)">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            
            <div class="input-group">
              <input type="password" name="password" id="password" placeholder="New Password" required autocomplete="new-password" />
              <i class="fas fa-eye" id="toggle-password" onclick="togglePassword('password', 'toggle-password')" style="cursor: pointer;"></i>
            </div>
            <small id="password-hint" style="display:block;margin-top:6px;color:#555;font-weight:bold;">
              Must be at least 8 characters and include uppercase, lowercase, and a number.
            </small>
            <div class="strength-meter" style="width: 100%; height: 4px; background: #ddd; border-radius: 2px; margin: 8px 0 12px 0;">
              <div class="strength-bar" id="strength-bar" style="height: 100%; width: 0%; border-radius: 2px; transition: width 0.25s, background-color 0.25s; background-color: #ddd;"></div>
            </div>
            <small id="strength-text" style="display:block;font-size:0.85em;margin-bottom:8px;font-weight:bold;color:#666;"></small>
            
            <div class="input-group">
              <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm New Password" required autocomplete="new-password" />
              <i class="fas fa-eye" id="toggle-confirm-password" onclick="togglePassword('confirm_password', 'toggle-confirm-password')" style="cursor: pointer;"></i>
            </div>
            <small id="match-hint" style="display:block;margin-top:6px;font-weight:bold;color:#666;"></small>

            <button type="submit" class="login-btn">RESET PASSWORD</button>

            <p class="signup-text"><a href="login.php">Back to Sign In</a></p>
          </form>
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
    // Show loading on form submit
    function onSubmitForm(event) {
      if (!validateForm()) {
        event.preventDefault();
        return false;
      }
      document.getElementById('loading-overlay').style.display = 'flex';
      return true;
    }

    // Form validation
    function validateForm() {
      const password = document.querySelector('input[name="password"]').value;
      const confirmPassword = document.querySelector('input[name="confirm_password"]').value;

      if (password === '') {
        showModal('Please enter your new password.');
        return false;
      }

      if (confirmPassword === '') {
        showModal('Please confirm your new password.');
        return false;
      }

      if (password.length < 8) {
        showModal('Password must be at least 8 characters long.');
        return false;
      }
      if (!/[A-Z]/.test(password)) {
        showModal('Password must contain at least one uppercase letter.');
        return false;
      }
      if (!/[a-z]/.test(password)) {
        showModal('Password must contain at least one lowercase letter.');
        return false;
      }
      if (!/[0-9]/.test(password)) {
        showModal('Password must contain at least one number.');
        return false;
      }

      if (password !== confirmPassword) {
        showModal('Passwords do not match.');
        return false;
      }

      return true;
    }

    // Live strength + match feedback
    function updateStrengthUI(password) {
      const strengthBar = document.getElementById('strength-bar');
      const strengthText = document.getElementById('strength-text');
      if (!strengthBar || !strengthText) return;

      let strength = 0;
      if (password.length >= 8) strength++;
      if (/[A-Z]/.test(password)) strength++;
      if (/[a-z]/.test(password)) strength++;
      if (/[0-9]/.test(password)) strength++;

      const percentage = (strength / 4) * 100;
      strengthBar.style.width = percentage + '%';

      if (strength <= 1) {
        strengthBar.style.backgroundColor = '#dc3545';
        strengthText.textContent = 'Weak';
        strengthText.style.color = '#dc3545';
      } else if (strength === 2) {
        strengthBar.style.backgroundColor = '#ffc107';
        strengthText.textContent = 'Fair';
        strengthText.style.color = '#ffc107';
      } else if (strength === 3) {
        strengthBar.style.backgroundColor = '#fd7e14';
        strengthText.textContent = 'Good';
        strengthText.style.color = '#fd7e14';
      } else {
        strengthBar.style.backgroundColor = '#28a745';
        strengthText.textContent = 'Strong';
        strengthText.style.color = '#28a745';
      }

      if (password.length === 0) {
        strengthBar.style.width = '0%';
        strengthText.textContent = '';
      }
    }

    function updateMatchUI() {
      const password = document.getElementById('password');
      const confirm = document.getElementById('confirm_password');
      const hint = document.getElementById('match-hint');
      if (!password || !confirm || !hint) return;
      if (confirm.value.length === 0) { hint.textContent = ''; return; }
      if (password.value === confirm.value) {
        hint.textContent = 'Passwords match';
        hint.style.color = '#28a745';
      } else {
        hint.textContent = 'Passwords do not match';
        hint.style.color = '#dc3545';
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const pwd = document.getElementById('password');
      const conf = document.getElementById('confirm_password');
      if (pwd) {
        pwd.addEventListener('input', function() {
          updateStrengthUI(pwd.value);
          updateMatchUI();
        });
      }
      if (conf) {
        conf.addEventListener('input', function() {
          updateMatchUI();
        });
      }
    });

    // Toggle password visibility
    function togglePassword(inputId, iconId) {
      const passwordField = document.getElementById(inputId);
      const toggleIcon = document.getElementById(iconId);
      
      if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.classList.remove('fa-eye');
        toggleIcon.classList.add('fa-eye-slash');
      } else {
        passwordField.type = 'password';
        toggleIcon.classList.remove('fa-eye-slash');
        toggleIcon.classList.add('fa-eye');
      }
    }

    function showModal(message, type = "info") {
      const modal = document.getElementById('alertModal');
      const modalMessage = document.getElementById('modal-message');
      const modalTitle = document.getElementById('modal-title');
      
      // Reset theme classes
      modal.classList.remove("success", "info", "error");
      modal.classList.add(type);

      // Set title automatically based on type
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

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('alertModal');
      if (event.target === modal) {
        closeModal();
      }
    }

    // Hide loading overlay when page loads
    window.addEventListener('load', () => {
      document.getElementById('loading-overlay').style.display = 'none';
    });

    // Auto-hide messages after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
      const successMsg = document.querySelector('.success-message');
      const infoMsg = document.querySelector('.info-message');
      
      if (successMsg) {
        setTimeout(() => {
          successMsg.style.opacity = '0';
          setTimeout(() => successMsg.style.display = 'none', 300);
        }, 5000);
      }
      
      if (infoMsg) {
        setTimeout(() => {
          infoMsg.style.opacity = '0';
          setTimeout(() => infoMsg.style.display = 'none', 300);
        }, 5000);
      }
    });

    <?php if ($success): ?>
    // Show success modal and redirect
    document.addEventListener('DOMContentLoaded', function() {
      showModal("Password reset successful! Redirecting to login...", "success");
      document.querySelector('.modal-ok-btn').onclick = function () {
        window.location.href = "login.php";
      };
      setTimeout(function() {
        window.location.href = "login.php";
      }, 3000);
    });
    <?php endif; ?>
  </script>
</body>
</html>
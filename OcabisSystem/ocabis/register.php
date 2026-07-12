<?php
// database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ocabis";

$conn = @new mysqli($servername, $username, $password, $dbname);

// Check if database is connected
$db_connected = !$conn->connect_error;

if (!$db_connected) {
    // Database is down - redirect to database_down.php
    header("Location: database_down.php");
    exit();
}

// Include email notification functions
require_once 'email_notifications.php';

$errors = [];
$registrationSuccess = false;
$usernameExists = false;
$emailExists = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = trim($_POST['username']);
    $pass = $_POST['password'];
    $confirm_pass = $_POST['confirm_password'];
    $email = trim($_POST['email']);

    if (empty($user) || strlen($user) < 4 || strlen($user) > 20) {
        $errors[] = "Username must be 4-20 characters long.";
    } elseif (!preg_match("/^[a-zA-Z0-9_]{4,20}$/", $user)) {
        $errors[] = "Username can only contain letters, numbers, and underscores.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }

    if (empty($pass)) {
        $errors[] = "Password is required.";
    } elseif (strlen($pass) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }
    if (!preg_match('/[A-Z]/', $pass)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    if (!preg_match('/[a-z]/', $pass)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    if (!preg_match('/[0-9]/', $pass)) {
        $errors[] = "Password must contain at least one number.";
    }
    
    if ($pass !== $confirm_pass) {
        $errors[] = "Passwords do not match.";
    }

    if (empty($errors)) {
        $check = $conn->prepare("SELECT username, email FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $user, $email);
        $check->execute();
        $result = $check->get_result();

        if ($row = $result->fetch_assoc()) {
            if ($row['username'] === $user) {
                $usernameExists = true;
                $errors[] = "Username already exists.";
            }
            if ($row['email'] === $email) {
                $emailExists = true;
                $errors[] = "Email already exists.";
            }
        }
        $check->close();
    }

    if (empty($errors)) {
        $hashed_pass = password_hash($pass, PASSWORD_BCRYPT);
        // Teachers register as viewers with no department
        $role = 'viewer';
        $department = null;
        $is_admin = 0;
        $sql = "INSERT INTO users (username, password, department, email, status, approval_status, role, is_admin, created_at) VALUES (?, ?, ?, ?, 'inactive', 'pending', ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssi", $user, $hashed_pass, $department, $email, $role, $is_admin);

        if ($stmt->execute()) {
            $registrationSuccess = true;
            
            // Send registration confirmation email to user
            sendRegistrationConfirmationEmail($email, $user);
            
            // Send admin notification email about new teacher registration
            // Get admin email (check for users with is_admin = 1 or role = 'admin')
            $admin_query = "SELECT email FROM users WHERE (is_admin = 1 OR role = 'admin') AND status = 'active' LIMIT 1";
            $admin_result = $conn->query($admin_query);
            if ($admin_result && $admin_row = $admin_result->fetch_assoc()) {
                $admin_email = $admin_row['email'];
                sendAdminNotificationEmail($admin_email, $user, $email, 'Teacher');
            }
        } else {
            die("Insert failed: " . $stmt->error);
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>OCABIS Register</title>
  <link rel="stylesheet" href="Css/register.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* Simple full-screen loading overlay */
    #loading-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.35);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      backdrop-filter: blur(1px);
    }
    #loading-spinner {
      width: 56px;
      height: 56px;
      border: 6px solid #fff;
      border-top-color: #e53e3e;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .login-btn[disabled] { opacity: 0.7; cursor: not-allowed; }

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
        backdrop-filter: blur(1px);
        box-shadow: 0 10px 24px rgba(0,0,0,0.12);
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

      .input-group input,
      .input-group select { padding: 16px 44px 16px 16px; font-size: 17px; border-radius: 12px; }

      /* Hide browser password manager icons - Comprehensive rules */
      .input-group input[type="password"]::-webkit-credentials-auto-fill-button,
      .input-group input[type="password"]::-webkit-strong-password-auto-fill-button,
      .input-group input[type="password"]::-webkit-textfield-decoration-container {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
        position: absolute !important;
        right: -9999px !important;
        width: 0 !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
      }

      .input-group input[type="password"]::-ms-reveal,
      .input-group input[type="password"]::-ms-clear {
        display: none !important;
        width: 0 !important;
        height: 0 !important;
      }

      .input-group input[type="password"] {
        -moz-appearance: textfield;
      }

      .input-group i {
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 20px;
        z-index: 10;
      }

      .login-btn { width: 100%; padding: 14px; font-size: 16px; margin-bottom: 6px; border-radius: 14px; }

      .signup-text {
        font-size: 11px;
        margin-top: 10px;
      }

      .signup-text a {
        white-space: nowrap; /* Prevent "Sign In Now" from breaking */
      }

      .password-hint {
        font-size: 0.75em;
        margin-top: 4px;
        margin-bottom: 8px;
      }

      .strength-meter {
        height: 3px;
        margin-bottom: 4px;
      }

      .strength-text {
        font-size: 0.75em;
        margin-bottom: 12px;
      }

      small {
        font-size: 11px;
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

      .modal-content #modal-icon i {
        font-size: 60px !important;
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

      .input-group input,
      .input-group select { padding: 16px 44px 16px 16px; font-size: 17px; border-radius: 12px; }

      /* Hide browser password manager icons - Comprehensive rules */
      .input-group input[type="password"]::-webkit-credentials-auto-fill-button,
      .input-group input[type="password"]::-webkit-strong-password-auto-fill-button,
      .input-group input[type="password"]::-webkit-textfield-decoration-container {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
        position: absolute !important;
        right: -9999px !important;
        width: 0 !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
      }

      .input-group input[type="password"]::-ms-reveal,
      .input-group input[type="password"]::-ms-clear {
        display: none !important;
        width: 0 !important;
        height: 0 !important;
      }

      .input-group input[type="password"] {
        -moz-appearance: textfield;
      }

      .input-group i {
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 20px;
        z-index: 10;
      }

      .login-btn { width: 100%; padding: 14px; font-size: 16px; border-radius: 14px; }

      .signup-text {
        font-size: 10px;
      }

      .signup-text a {
        white-space: nowrap; /* Prevent "Sign In Now" from breaking */
      }

      .password-hint {
        font-size: 0.7em;
      }

      .strength-text {
        font-size: 0.7em;
      }

      small {
        font-size: 10px;
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

      .modal-content #modal-icon i {
        font-size: 50px !important;
      }
    }
  </style>
</head>
<body>
  <div id="loading-overlay">
    <div id="loading-spinner"></div>
  </div>

  <div class="login-container">
    <div class="login-box">
      <div class="left-panel">
        <h3>WELCOME TO</h3>
        <div class="logo-title">
          <img src="image/image-removebg-preview.png" alt="Logo" />
          <h1>CABIS</h1>
        </div>
        <p>INVENTORY MANAGEMENT SYSTEM</p>
      </div>
      <div class="right-panel">
        <!-- Mobile brand header (shown on mobile only) -->
        <div class="mobile-hero" style="display:none"></div>
        <script>
          // render hero only on small screens
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
        <h2>TEACHER REGISTRATION</h2>
        <p>Register as a teacher to view items and request to borrow</p>

        <form id="registerForm" method="POST" action="" onsubmit="return onSubmitForm(event)">
          <div class="input-group">
            <input type="text" name="username" id="username" placeholder="Username" required 
                   pattern="[A-Za-z0-9_]{4,20}" 
                   title="4–20 characters, letters/numbers/underscore only"
                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" />
            <i class="fas fa-user"></i>
          </div>
          <small id="username-error" style="color: red; display: <?php echo $usernameExists ? 'block' : 'none'; ?>; font-weight:bold;">
            Username already exists
          </small>

          <div class="input-group">
            <input type="email" name="email" id="email" placeholder="Email" required
                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" />
            <i class="fas fa-envelope"></i>
          </div>
          <small id="email-error" style="color: red; display: <?php echo $emailExists ? 'block' : 'none'; ?>; font-weight:bold;">
            Email already exists
          </small>

          <div class="input-group">
            <input type="password" name="password" id="password" placeholder="Password" required minlength="8"
                   oninput="checkPasswordStrength(this.value); checkPasswordMatch();" 
                   autocomplete="new-password" />
            <i class="fas fa-eye" id="toggle-password" onclick="togglePassword('password', 'toggle-password')" style="cursor: pointer;"></i>
          </div>
          <small id="password-error" style="color: red; display: none; font-weight: bold;">
            Password is required
          </small>

          <small class="password-hint">Password must be at least 8 characters with uppercase, lowercase, and number.</small>
          <div class="strength-meter" style="width: 100%; height: 4px; background: #ddd; border-radius: 2px; margin-bottom: 5px;">
            <div class="strength-bar" id="strength-bar" style="height: 100%; width: 0%; border-radius: 2px; transition: width 0.3s, background-color 0.3s; background-color: #ddd;"></div>
          </div>
          <small id="strength-text" class="strength-text" style="display: block; font-size: 0.8em; margin-bottom: 15px; font-weight: bold;"></small>

          <div class="input-group">
            <input type="password" name="confirm_password" id="confirm-password" placeholder="Retype Password" required minlength="8"
                   oninput="checkPasswordMatch()" 
                   autocomplete="new-password" />
            <i class="fas fa-eye" id="toggle-confirm-password" onclick="togglePassword('confirm-password', 'toggle-confirm-password')" style="cursor: pointer;"></i>
          </div>
          <small id="confirm-error" style="color: red; display: none; font-weight: bold;">
            Password did not match
          </small>

          <button type="submit" class="login-btn">REGISTER AS TEACHER</button>

          <p class="signup-text">Already have an account? <a href="login.php">Sign In Now</a></p>
        </form>
      </div>
    </div>
  </div>

  <div id="alertModal" class="modal">
    <div class="modal-content">
      <span class="close-btn" onclick="closeModal()">&times;</span>
      <div id="modal-icon">
        <i class="fas fa-check-circle" style="font-size: 70px; color: #28a745;"></i>
      </div>
      <h2 id="modal-title">Registration Successful!</h2>
      <p id="modal-message">Your account has been created successfully. Please check your email for registration confirmation and status updates. You will receive an email notification once your account is approved or rejected by the administrator.</p>
      <button onclick="closeModal()" class="modal-ok-btn">OK</button>
    </div>
  </div>

  <script>
    function onSubmitForm(event) {
      if (!validateForm()) {
        event.preventDefault();
        return false;
      }
      if (window.__isSubmitting) {
        event.preventDefault();
        return false;
      }
      window.__isSubmitting = true;
      const btn = document.querySelector('.login-btn');
      if (btn) {
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
        btn.disabled = true;
      }
      document.getElementById('loading-overlay').style.display = 'flex';
      return true;
    }

    function validateForm() {
      const password = document.getElementById('password').value;
      const confirmPassword = document.getElementById('confirm-password').value;
      let valid = true;

      if (password.length === 0) {
        document.getElementById("password-error").style.display = "block";
        valid = false;
      } else {
        document.getElementById("password-error").style.display = "none";
      }

      if (password !== confirmPassword) {
        document.getElementById("confirm-error").style.display = "block";
        valid = false;
      } else {
        document.getElementById("confirm-error").style.display = "none";
      }

      return valid;
    }

    function checkPasswordMatch() {
      const password = document.getElementById("password").value;
      const confirmPassword = document.getElementById("confirm-password").value;
      const errorText = document.getElementById("confirm-error");

      if (confirmPassword.length > 0 && password !== confirmPassword) {
        errorText.style.display = "block";
      } else {
        errorText.style.display = "none";
      }
    }

    function checkPasswordStrength(password) {
      const strengthBar = document.getElementById('strength-bar');
      const strengthText = document.getElementById('strength-text');

      let strength = 0;
      if (password.length >= 8) strength++;
      if (/[A-Z]/.test(password)) strength++;
      if (/[a-z]/.test(password)) strength++;
      if (/[0-9]/.test(password)) strength++;
      if (/[^A-Za-z0-9]/.test(password)) strength++;

      const percentage = (strength / 5) * 100;
      strengthBar.style.width = percentage + "%";

      if (strength <= 2) {
        strengthBar.style.backgroundColor = "#dc3545";
        strengthText.textContent = "Weak";
        strengthText.style.color = "#dc3545";
      } else if (strength === 3) {
        strengthBar.style.backgroundColor = "#ffc107";
        strengthText.textContent = "Fair";
        strengthText.style.color = "#ffc107";
      } else if (strength === 4) {
        strengthBar.style.backgroundColor = "#fd7e14";
        strengthText.textContent = "Good";
        strengthText.style.color = "#fd7e14";
      } else {
        strengthBar.style.backgroundColor = "#28a745";
        strengthText.textContent = "Strong";
        strengthText.style.color = "#28a745";
      }

      if (password.length === 0) {
        strengthBar.style.width = "0%";
        strengthText.textContent = "";
      }
    }

    function showModal(message, redirect = false, type = "success") {
      const modal = document.getElementById('alertModal');
      const modalMessage = document.getElementById('modal-message');
      const modalTitle = document.getElementById('modal-title');
      const modalIcon = document.getElementById('modal-icon');

      modal.classList.remove("success", "error");
      modalMessage.textContent = message;

      if (type === "success") {
        modalTitle.textContent = "Registration Successful!";
        modalIcon.innerHTML = '<i class="fas fa-check-circle" style="font-size:70px; color:#28a745;"></i>';
        modal.classList.add("success");
      } else {
        modalTitle.textContent = "Registration Error";
        modalIcon.innerHTML = '<i class="fas fa-times-circle" style="font-size:70px; color:#dc3545;"></i>';
        modal.classList.add("error");
      }

      modal.style.display = 'flex';

      if (redirect && type === "success") {
        setTimeout(() => {
          window.location.href = "login.php?registered=1";
        }, 3000);
      }
    }

    function closeModal() {
      document.getElementById('alertModal').style.display = 'none';
    }

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

    window.onclick = function(event) {
      const modal = document.getElementById('alertModal');
      if (event.target === modal) closeModal();
    }

    document.addEventListener('DOMContentLoaded', () => {
      document.getElementById('alertModal').style.display = 'none';
      document.getElementById('loading-overlay').style.display = 'none';
    });
  </script>

  <?php if ($registrationSuccess && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('loading-overlay').style.display = 'none';
    const btn = document.querySelector('.login-btn');
    if (btn) {
      btn.disabled = false;
      if (btn.dataset.originalText) btn.innerHTML = btn.dataset.originalText;
    }
    window.__isSubmitting = false;
    showModal("Registration successful! Please check your email for confirmation and status updates. You will be notified via email when your account is approved or rejected.", false, "success");
  });
  </script>
  <?php endif; ?>

  <?php if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <script>
  document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('loading-overlay').style.display = 'none';
    const btn = document.querySelector('.login-btn');
    if (btn) {
      btn.disabled = false;
      if (btn.dataset.originalText) btn.innerHTML = btn.dataset.originalText;
    }
    window.__isSubmitting = false;
    showModal("<?php echo implode('. ', $errors); ?>", false, "error");
  });
  </script>
  <?php endif; ?>

</body>
</html>
<?php
// Debug script for super admin login issues
session_start();

echo "<h1>Super Admin Login Debug</h1>";

try {
    require_once '../db_connect.php';
    
    if ($conn->connect_error) {
        echo "<p style='color: red;'>❌ Database connection failed: " . $conn->connect_error . "</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Database connected successfully</p>";
    
    echo "<h2>Step 1: Check Super Admin Table</h2>";
    
    // Check if super_admin table exists
    $check_table = $conn->query("SHOW TABLES LIKE 'super_admin'");
    if ($check_table->num_rows === 0) {
        echo "<p style='color: red;'>❌ super_admin table does not exist!</p>";
        echo "<p><a href='fix_super_admin.php'>Click here to fix the super admin table</a></p>";
        exit;
    } else {
        echo "<p style='color: green;'>✅ super_admin table exists</p>";
    }
    
    // Check table structure
    echo "<h3>Table Structure:</h3>";
    $structure_query = "DESCRIBE super_admin";
    $structure_result = $conn->query($structure_query);
    
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $structure_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check records in super_admin table
    $count_query = "SELECT COUNT(*) as count FROM super_admin";
    $count_result = $conn->query($count_query);
    $count_row = $count_result->fetch_assoc();
    
    echo "<h3>Records in super_admin table: " . $count_row['count'] . "</h3>";
    
    if ($count_row['count'] > 0) {
        echo "<h3>All Super Admin Records:</h3>";
        $select_query = "SELECT id, username, email, department, status, is_permanent, created_at FROM super_admin";
        $select_result = $conn->query($select_query);
        
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Department</th><th>Status</th><th>Is Permanent</th><th>Created</th></tr>";
        while ($row = $select_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['department']) . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . ($row['is_permanent'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>❌ No records found in super_admin table!</p>";
        echo "<p><a href='fix_super_admin.php'>Click here to populate the super admin table</a></p>";
        exit;
    }
    
    echo "<h2>Step 2: Check Super Admin Config</h2>";
    
    // Load permanent super admin credentials
    $super_admin_config = include 'super_admin_config.php';
    $permanent_admin = $super_admin_config['super_admin'];
    
    echo "<h3>Config File Credentials:</h3>";
    echo "<ul>";
    echo "<li><strong>Username:</strong> " . htmlspecialchars($permanent_admin['username']) . "</li>";
    echo "<li><strong>Password:</strong> " . htmlspecialchars($permanent_admin['password']) . "</li>";
    echo "<li><strong>Email:</strong> " . htmlspecialchars($permanent_admin['email']) . "</li>";
    echo "<li><strong>Department:</strong> " . htmlspecialchars($permanent_admin['department']) . "</li>";
    echo "<li><strong>Status:</strong> " . htmlspecialchars($permanent_admin['status']) . "</li>";
    echo "</ul>";
    
    echo "<h2>Step 3: Test Password Verification</h2>";
    
    // Test password verification for each super admin account
    $test_query = "SELECT id, username, password, status FROM super_admin";
    $test_result = $conn->query($test_query);
    
    while ($test_row = $test_result->fetch_assoc()) {
        echo "<h4>Testing account: " . htmlspecialchars($test_row['username']) . "</h4>";
        
        // Test with config password
        if (password_verify($permanent_admin['password'], $test_row['password'])) {
            echo "<p style='color: green;'>✅ Password verification successful with config password</p>";
        } else {
            echo "<p style='color: red;'>❌ Password verification failed with config password</p>";
        }
        
        // Test with common passwords
        $common_passwords = ['admin123', 'admin', 'password', '123456', 'superadmin'];
        foreach ($common_passwords as $test_pass) {
            if (password_verify($test_pass, $test_row['password'])) {
                echo "<p style='color: orange;'>⚠️ Password verification successful with: " . htmlspecialchars($test_pass) . "</p>";
            }
        }
        
        echo "<p><strong>Status:</strong> " . htmlspecialchars($test_row['status']) . "</p>";
        echo "<hr>";
    }
    
    echo "<h2>Step 4: Test Login Process</h2>";
    
    // Simulate the login process
    echo "<h3>Testing Login Process:</h3>";
    
    $test_username = $permanent_admin['username'];
    $test_password = $permanent_admin['password'];
    
    echo "<p>Testing with username: <strong>" . htmlspecialchars($test_username) . "</strong></p>";
    echo "<p>Testing with password: <strong>" . htmlspecialchars($test_password) . "</strong></p>";
    
    // Check super_admin table
    $super_admin_sql = "SELECT id, username, email, password, status FROM super_admin WHERE username = ?";
    $super_admin_stmt = $conn->prepare($super_admin_sql);
    $super_admin_stmt->bind_param("s", $test_username);
    $super_admin_stmt->execute();
    $super_admin_result = $super_admin_stmt->get_result();
    
    if ($super_admin_result->num_rows === 1) {
        echo "<p style='color: green;'>✅ Found super admin account in database</p>";
        
        $userData = $super_admin_result->fetch_assoc();
        echo "<p><strong>Account Details:</strong></p>";
        echo "<ul>";
        echo "<li>ID: " . htmlspecialchars($userData['id']) . "</li>";
        echo "<li>Username: " . htmlspecialchars($userData['username']) . "</li>";
        echo "<li>Email: " . htmlspecialchars($userData['email']) . "</li>";
        echo "<li>Status: " . htmlspecialchars($userData['status']) . "</li>";
        echo "</ul>";
        
        if ($userData['status'] === 'inactive') {
            echo "<p style='color: red;'>❌ Account is inactive!</p>";
        } else {
            echo "<p style='color: green;'>✅ Account is active</p>";
        }
        
        if (password_verify($test_password, $userData['password'])) {
            echo "<p style='color: green;'>✅ Password verification successful!</p>";
            echo "<p style='color: green;'>✅ Login should work!</p>";
        } else {
            echo "<p style='color: red;'>❌ Password verification failed!</p>";
            echo "<p>This means the password in the database doesn't match the config password.</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ No super admin account found with username: " . htmlspecialchars($test_username) . "</p>";
    }
    
    $super_admin_stmt->close();
    
    echo "<h2>Step 5: Quick Fix</h2>";
    
    if ($count_row['count'] === 0) {
        echo "<p style='color: red;'>❌ No super admin accounts found. You need to run the fix script.</p>";
        echo "<p><a href='fix_super_admin.php' style='background: #e53e3e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔧 Fix Super Admin Table</a></p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Super admin accounts exist but login might be failing.</p>";
        echo "<p>Try these solutions:</p>";
        echo "<ol>";
        echo "<li>Make sure you're using the correct username and password from the config</li>";
        echo "<li>Check if the account status is 'active'</li>";
        echo "<li>Try resetting the password by running the fix script again</li>";
        echo "</ol>";
        echo "<p><a href='fix_super_admin.php' style='background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔄 Reset Super Admin Account</a></p>";
    }
    
    echo "<h2>Step 6: Manual Login Test</h2>";
    echo "<p>Try logging in with these credentials:</p>";
    echo "<div style='background: #f0f9ff; border: 1px solid #0ea5e9; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
    echo "<p><strong>Username:</strong> " . htmlspecialchars($permanent_admin['username']) . "</p>";
    echo "<p><strong>Password:</strong> " . htmlspecialchars($permanent_admin['password']) . "</p>";
    echo "</div>";
    
    echo "<p><a href='login.php' style='background: #059669; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔑 Go to Login Page</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>

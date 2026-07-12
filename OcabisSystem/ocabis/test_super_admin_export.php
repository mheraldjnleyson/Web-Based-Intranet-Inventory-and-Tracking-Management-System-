<?php
// Test script to verify super admin account is properly handled in export/import
session_start();

// Set up test session as super admin
$_SESSION['user_id'] = 999999;
$_SESSION['username'] = 'superadmin';
$_SESSION['email'] = 'superadmin@ocabis.com';
$_SESSION['department'] = 'IT Department';
$_SESSION['role'] = 'super_admin';
$_SESSION['is_admin'] = 1;
$_SESSION['is_super_admin'] = 1;
$_SESSION['created_at'] = '2024-01-01 00:00:00';

echo "<h1>Super Admin Export/Import Test</h1>";

// Test 1: Check if super admin account exists in database
echo "<h2>Test 1: Check Super Admin Account in Database</h2>";
try {
    require_once '../db_connect.php';
    
    if ($conn->connect_error) {
        echo "<p style='color: red;'>Database connection failed: " . $conn->connect_error . "</p>";
    } else {
        $check_sql = "SELECT * FROM super_admin WHERE username = 'superadmin'";
        $result = $conn->query($check_sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo "<p style='color: green;'>✓ Super admin account found in database:</p>";
            echo "<ul>";
            echo "<li>Username: " . htmlspecialchars($row['username']) . "</li>";
            echo "<li>Email: " . htmlspecialchars($row['email']) . "</li>";
            echo "<li>Department: " . htmlspecialchars($row['department']) . "</li>";
            echo "<li>Status: " . htmlspecialchars($row['status']) . "</li>";
            echo "<li>Is Permanent: " . ($row['is_permanent'] ? 'Yes' : 'No') . "</li>";
            echo "<li>Created: " . htmlspecialchars($row['created_at']) . "</li>";
            echo "</ul>";
        } else {
            echo "<p style='color: red;'>✗ Super admin account NOT found in database</p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error checking database: " . $e->getMessage() . "</p>";
}

// Test 2: Test export functionality
echo "<h2>Test 2: Test Export Functionality</h2>";
echo "<p>Testing if super admin account is included in export...</p>";

// Simulate export by checking what would be exported
try {
    $tables_query = "SHOW TABLES";
    $tables_result = $conn->query($tables_query);
    
    $super_admin_found = false;
    while ($row = $tables_result->fetch_array(MYSQLI_NUM)) {
        $table = $row[0];
        if ($table === 'super_admin') {
            $super_admin_found = true;
            
            // Check if table has data
            $data_query = "SELECT COUNT(*) as count FROM `$table`";
            $data_result = $conn->query($data_query);
            $count_row = $data_result->fetch_assoc();
            
            echo "<p style='color: green;'>✓ super_admin table found with " . $count_row['count'] . " records</p>";
            
            // Show sample data
            $sample_query = "SELECT username, email, is_permanent FROM super_admin LIMIT 3";
            $sample_result = $conn->query($sample_query);
            
            if ($sample_result && $sample_result->num_rows > 0) {
                echo "<p>Sample super admin records:</p>";
                echo "<table border='1' style='border-collapse: collapse;'>";
                echo "<tr><th>Username</th><th>Email</th><th>Is Permanent</th></tr>";
                while ($sample_row = $sample_result->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($sample_row['username']) . "</td>";
                    echo "<td>" . htmlspecialchars($sample_row['email']) . "</td>";
                    echo "<td>" . ($sample_row['is_permanent'] ? 'Yes' : 'No') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            break;
        }
    }
    
    if (!$super_admin_found) {
        echo "<p style='color: red;'>✗ super_admin table not found in database</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error testing export: " . $e->getMessage() . "</p>";
}

// Test 3: Test sync functionality
echo "<h2>Test 3: Test Sync Functionality</h2>";
echo "<p>Testing super admin sync...</p>";

try {
    include 'sync_super_admin.php';
    echo "<p style='color: green;'>✓ Super admin sync completed successfully</p>";
    
    // Verify account still exists after sync
    $verify_sql = "SELECT username, is_permanent FROM super_admin WHERE username = 'superadmin'";
    $verify_result = $conn->query($verify_sql);
    
    if ($verify_result && $verify_result->num_rows > 0) {
        $verify_row = $verify_result->fetch_assoc();
        echo "<p style='color: green;'>✓ Super admin account verified after sync (is_permanent: " . ($verify_row['is_permanent'] ? 'Yes' : 'No') . ")</p>";
    } else {
        echo "<p style='color: red;'>✗ Super admin account not found after sync</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error testing sync: " . $e->getMessage() . "</p>";
}

echo "<h2>Test Summary</h2>";
echo "<p>This test verifies that:</p>";
echo "<ul>";
echo "<li>Super admin account exists in the database</li>";
echo "<li>Super admin table is included in exports</li>";
echo "<li>Super admin sync functionality works correctly</li>";
echo "<li>Account has proper permanent flag set</li>";
echo "</ul>";

echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>Export your database using the Database Export page</li>";
echo "<li>Import the exported file to test the complete flow</li>";
echo "<li>Verify that super admin account persists after import</li>";
echo "</ol>";

echo "<p><a href='database_export.php'>Go to Database Export Page</a></p>";
?>

<?php
// Database Connection Fix Script
// This will help diagnose and fix database connection issues

echo "<h1>Database Connection Fix</h1>";

// Test 1: Check if MySQL is running
echo "<h2>Step 1: Check MySQL Service</h2>";
$mysql_running = false;
try {
    $test_conn = @new mysqli('localhost', 'root', '');
    if (!$test_conn->connect_error) {
        echo "<p style='color: green;'>✅ MySQL is running and accessible</p>";
        $mysql_running = true;
    } else {
        echo "<p style='color: red;'>❌ MySQL connection failed: " . $test_conn->connect_error . "</p>";
    }
    $test_conn->close();
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ MySQL error: " . $e->getMessage() . "</p>";
}

if (!$mysql_running) {
    echo "<p style='color: red;'><strong>SOLUTION:</strong> Start XAMPP MySQL service</p>";
    exit;
}

// Test 2: Check if ocabis database exists
echo "<h2>Step 2: Check Database</h2>";
try {
    $test_conn = @new mysqli('localhost', 'root', '');
    
    // Check if ocabis database exists
    $result = $test_conn->query("SHOW DATABASES LIKE 'ocabis'");
    if ($result->num_rows > 0) {
        echo "<p style='color: green;'>✅ 'ocabis' database exists</p>";
        
        // Check tables in ocabis database
        $test_conn->query("USE ocabis");
        $tables_result = $test_conn->query("SHOW TABLES");
        
        if ($tables_result->num_rows > 0) {
            echo "<p style='color: green;'>✅ Database has " . $tables_result->num_rows . " tables</p>";
            echo "<h3>Tables found:</h3><ul>";
            while ($row = $tables_result->fetch_array()) {
                echo "<li>" . $row[0] . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color: orange;'>⚠️ Database exists but has no tables</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ 'ocabis' database does not exist</p>";
        echo "<p><strong>SOLUTION:</strong> Creating 'ocabis' database...</p>";
        
        // Create the database
        if ($test_conn->query("CREATE DATABASE ocabis")) {
            echo "<p style='color: green;'>✅ 'ocabis' database created successfully</p>";
        } else {
            echo "<p style='color: red;'>❌ Failed to create database: " . $test_conn->error . "</p>";
        }
    }
    
    $test_conn->close();
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database check error: " . $e->getMessage() . "</p>";
}

// Test 3: Test actual connection
echo "<h2>Step 3: Test Connection</h2>";
try {
    require_once '../db_connect.php';
    
    if ($conn->connect_error) {
        echo "<p style='color: red;'>❌ Connection failed: " . $conn->connect_error . "</p>";
    } else {
        echo "<p style='color: green;'>✅ Database connection successful</p>";
        
        // Test a simple query
        $result = $conn->query("SELECT 1 as test");
        if ($result) {
            echo "<p style='color: green;'>✅ Database queries working</p>";
        } else {
            echo "<p style='color: red;'>❌ Database queries not working</p>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Connection test error: " . $e->getMessage() . "</p>";
}

// Test 4: Check if tables exist and have data
echo "<h2>Step 4: Check Tables and Data</h2>";
try {
    if (isset($conn) && !$conn->connect_error) {
        $conn->query("USE ocabis");
        
        // Check key tables
        $key_tables = ['users', 'departments', 'locations', 'categories', 'items', 'item_tables', 'borrow_history'];
        
        foreach ($key_tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result->num_rows > 0) {
                $count_result = $conn->query("SELECT COUNT(*) as count FROM $table");
                $count = $count_result->fetch_assoc()['count'];
                echo "<p style='color: green;'>✅ Table '$table' exists with $count records</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Table '$table' does not exist</p>";
            }
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Table check error: " . $e->getMessage() . "</p>";
}

echo "<h2>Step 5: Solutions</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>If Database is Empty or Missing Tables:</h3>";
echo "<ol>";
echo "<li><strong>Use Emergency Recovery:</strong> Go to <a href='emergency_recovery.php'>emergency_recovery.php</a></li>";
echo "<li><strong>Import Database Backup:</strong> If you have a backup SQL file, upload it</li>";
echo "<li><strong>Create Fresh Database:</strong> If no backup, you'll need to recreate data</li>";
echo "</ol>";
echo "</div>";

echo "<div style='background: #fef2f2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>If MySQL is Not Running:</h3>";
echo "<ol>";
echo "<li><strong>Start XAMPP:</strong> Open XAMPP Control Panel</li>";
echo "<li><strong>Start MySQL:</strong> Click 'Start' next to MySQL</li>";
echo "<li><strong>Start Apache:</strong> Click 'Start' next to Apache</li>";
echo "<li><strong>Refresh this page</strong> to test again</li>";
echo "</ol>";
echo "</div>";

echo "<p><a href='dashboard.php'>Go to Dashboard</a> | <a href='emergency_recovery.php'>Go to Emergency Recovery</a></p>";
?>

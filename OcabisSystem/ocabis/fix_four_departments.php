<?php
// Script to ensure the 4 fixed departments exist
session_start();

echo "<h1>Fix Four Fixed Departments</h1>";

try {
    require_once '../db_connect.php';
    
    echo "<h2>Current Departments in Database:</h2>";
    
    // Check existing departments
    $result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
    if ($result && $result->num_rows > 0) {
        echo "<p>Found " . $result->num_rows . " departments:</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Name</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr><td>" . $row['id'] . "</td><td>" . htmlspecialchars($row['name']) . "</td></tr>";
        }
        echo "</table>";
    }
    
    // Define the 4 fixed departments
    $fixed_departments = [
        "ICT Equipment",
        "Science Equipment",
        "SPS Equipment",
        "Student Learning Resource Center (SLRC)"
    ];
    
    echo "<h2>Adding/Verifying the 4 Fixed Departments:</h2>";
    
    $inserted = 0;
    $existing = 0;
    
    foreach ($fixed_departments as $dept) {
        // Check if exists using simple query
        $check_sql = "SELECT id FROM departments WHERE name = '" . $conn->real_escape_string($dept) . "'";
        $exists = $conn->query($check_sql);
        
        if ($exists && $exists->num_rows > 0) {
            echo "<p style='color: green;'>✅ Department already exists: $dept</p>";
            $existing++;
        } else {
            // Insert if doesn't exist (without description field)
            $insert_sql = "INSERT INTO departments (name) VALUES ('" . $conn->real_escape_string($dept) . "')";
            
            if ($conn->query($insert_sql)) {
                echo "<p style='color: green;'>✅ Added department: $dept</p>";
                $inserted++;
            } else {
                echo "<p style='color: red;'>❌ Failed to add department: $dept - " . $conn->error . "</p>";
            }
        }
    }
    
    echo "<h2>Summary:</h2>";
    echo "<p>✅ Already existed: $existing</p>";
    echo "<p>✅ Newly added: $inserted</p>";
    
    // Show final list
    echo "<h2>Final Department List:</h2>";
    $result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
    if ($result && $result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Name</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr><td>" . $row['id'] . "</td><td>" . htmlspecialchars($row['name']) . "</td></tr>";
        }
        echo "</table>";
        echo "<p style='color: green;'><strong>Total: " . $result->num_rows . " departments</strong></p>";
    }
    
    $conn->close();
    
    echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46;'>✅ Done!</h3>";
    echo "<p>The 4 fixed departments are now in your database. These are the ONLY departments that should exist and no more can be added.</p>";
    echo "</div>";
    
    echo "<p><a href='department.php' style='background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Go to Department Page</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>

<?php
// Test script to check if departments are in database
session_start();

echo "<h1>Department Data Test</h1>";

try {
    require_once '../db_connect.php';
    
    echo "<h2>Step 1: Database Connection</h2>";
    if ($conn->connect_error) {
        echo "<p style='color: red;'>❌ Connection failed: " . $conn->connect_error . "</p>";
    } else {
        echo "<p style='color: green;'>✅ Database connected</p>";
    }
    
    echo "<h2>Step 2: Check Departments Table</h2>";
    $sql = "SELECT id, name FROM departments ORDER BY name ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✅ Found " . $result->num_rows . " departments in database:</p>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>";
        echo "<tr><th>ID</th><th>Name</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['name']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ No departments found in database</p>";
        echo "<p><strong>Adding sample departments...</strong></p>";
        
        // Add sample departments
        $sample_departments = [
            "ICT Equipment",
            "Science Equipment", 
            "SPS Equipment",
            "Student Learning Resource Center (SLRC)"
        ];
        
        $insert_sql = "INSERT IGNORE INTO departments (name, description) VALUES (?, ?)";
        $stmt = $conn->prepare($insert_sql);
        
        foreach ($sample_departments as $dept) {
            $description = "Department for " . $dept;
            $stmt->bind_param("ss", $dept, $description);
            if ($stmt->execute()) {
                echo "<p style='color: green;'>✅ Added department: " . htmlspecialchars($dept) . "</p>";
            } else {
                echo "<p style='color: red;'>❌ Failed to add department: " . $dept . " - " . $stmt->error . "</p>";
            }
        }
        $stmt->close();
        
        // Show updated departments
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            echo "<p style='color: green;'>✅ Now found " . $result->num_rows . " departments:</p>";
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0; width: 100%;'>";
            echo "<tr><th>ID</th><th>Name</th></tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
    
    echo "<h2>Step 3: Check Categories</h2>";
    $sql = "SELECT DISTINCT name FROM categories ORDER BY name ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✅ Found " . $result->num_rows . " categories</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ No categories found</p>";
    }
    
    echo "<h2>Step 4: Test Department Page Query</h2>";
    echo "<p>Simulating what department.php does:</p>";
    
    $departments = [];
    $result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $departments[] = [
                'id' => (int)$row['id'],
                'name' => htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8')
            ];
        }
    }
    
    if (!empty($departments)) {
        echo "<p style='color: green;'>✅ Department query successful. Found " . count($departments) . " departments:</p>";
        echo "<ul>";
        foreach ($departments as $dept) {
            echo "<li>ID: " . $dept['id'] . " - Name: " . $dept['name'] . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>❌ Department array is empty!</p>";
    }
    
    $conn->close();
    
    echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #065f46;'>✅ Test Complete!</h3>";
    echo "<p>If departments were added, they should now appear on the department page.</p>";
    echo "</div>";
    
    echo "<p><a href='department.php' style='background: #10b981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Department Page</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>

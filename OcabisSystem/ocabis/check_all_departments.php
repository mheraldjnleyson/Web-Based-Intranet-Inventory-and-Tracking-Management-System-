<?php
require_once '../db_connect.php';

echo "<h1>Check All 4 Fixed Departments</h1>";

$fixed_departments = [
    "ICT Equipment",
    "Science Equipment",
    "SPS Equipment",
    "Student Learning Resource Center (SLRC)"
];

echo "<h2>Current Departments in Database:</h2>";
$result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");

if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Name</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>" . $row['id'] . "</td><td>" . htmlspecialchars($row['name']) . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>No departments found!</p>";
}

echo "<h2>Checking for 4 Fixed Departments:</h2>";
$missing = [];

foreach ($fixed_departments as $dept) {
    $result = $conn->query("SELECT id, name FROM departments WHERE name = '" . $conn->real_escape_string($dept) . "'");
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✅ $dept exists</p>";
    } else {
        echo "<p style='color: red;'>❌ $dept MISSING</p>";
        $missing[] = $dept;
    }
}

if (!empty($missing)) {
    echo "<h2>Adding Missing Departments:</h2>";
    
    foreach ($missing as $dept) {
        $insert_sql = "INSERT INTO departments (name) VALUES ('" . $conn->real_escape_string($dept) . "')";
        
        if ($conn->query($insert_sql)) {
            echo "<p style='color: green;'>✅ Added: $dept</p>";
        } else {
            echo "<p style='color: red;'>❌ Failed to add: $dept - " . $conn->error . "</p>";
        }
    }
    
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
} else {
    echo "<h2 style='color: green;'>✅ All 4 fixed departments are present!</h2>";
}

$conn->close();
echo "<p>Done! Refresh your department page to see the changes.</p>";
echo "<p><a href='department.php'>Go to Department Page</a></p>";
?>

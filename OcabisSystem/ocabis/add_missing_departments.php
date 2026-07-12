<?php
require_once '../db_connect.php';

$missing_departments = [
    "ICT Equipment",
    "Science Equipment"
];

echo "<h1>Adding Missing Departments</h1>";

foreach ($missing_departments as $dept) {
    $check_sql = "SELECT id FROM departments WHERE name = '" . $conn->real_escape_string($dept) . "'";
    $exists = $conn->query($check_sql);
    
    if ($exists && $exists->num_rows > 0) {
        echo "<p style='color: orange;'>⚠️ $dept already exists</p>";
    } else {
        $insert_sql = "INSERT INTO departments (name) VALUES ('" . $conn->real_escape_string($dept) . "')";
        if ($conn->query($insert_sql)) {
            echo "<p style='color: green;'>✅ Added: $dept</p>";
        } else {
            echo "<p style='color: red;'>❌ Failed to add: $dept - " . $conn->error . "</p>";
        }
    }
}

echo "<h2>Final Department List:</h2>";
$result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Name</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>" . $row['id'] . "</td><td>" . htmlspecialchars($row['name']) . "</td></tr>";
    }
    echo "</table>";
    echo "<p style='color: green;'><strong>Total: " . $result->num_rows . " departments</strong></p>";
}

$conn->close();
echo "<p>Done! Now refresh your department.php page.</p>";
?>

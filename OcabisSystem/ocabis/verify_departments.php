<?php
session_start();
require_once '../db_connect.php';

echo "<h1>Verify Departments</h1>";

// Check departments in database
$result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");

echo "<h2>Departments in Database:</h2>";
if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Name</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>" . $row['id'] . "</td><td>" . htmlspecialchars($row['name']) . "</td></tr>";
    }
    echo "</table>";
    echo "<p style='color: green;'><strong>Total: " . $result->num_rows . " departments</strong></p>";
} else {
    echo "<p style='color: red;'>No departments found!</p>";
}

// Check if the 4 fixed departments exist
echo "<h2>Checking for 4 Fixed Departments:</h2>";
$fixed_departments = [
    "ICT Equipment",
    "Science Equipment",
    "SPS Equipment",
    "Student Learning Resource Center (SLRC)"
];

foreach ($fixed_departments as $dept) {
    $stmt = $conn->prepare("SELECT id, name FROM departments WHERE name = ?");
    if ($stmt) {
        $stmt->bind_param('s', $dept);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            echo "<p style='color: green;'>✅ " . $dept . " exists (ID: " . $row['id'] . ")</p>";
        } else {
            echo "<p style='color: red;'>❌ " . $dept . " NOT FOUND</p>";
        }
        $stmt->close();
    } else {
        echo "<p style='color: red;'>Error preparing statement: " . htmlspecialchars($conn->error) . "</p>";
    }
}

echo "<h2>Test Department Page Query:</h2>";
// Simulate what department.php does
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
    echo "<p style='color: green;'>✅ Department array has " . count($departments) . " items</p>";
    foreach ($departments as $dept) {
        echo "<li>ID: " . $dept['id'] . " - Name: " . $dept['name'] . "</li>";
    }
} else {
    echo "<p style='color: red;'>❌ Department array is EMPTY!</p>";
}

$conn->close();
?>

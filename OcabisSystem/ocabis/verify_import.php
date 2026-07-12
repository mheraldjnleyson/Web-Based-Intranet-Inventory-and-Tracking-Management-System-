<?php
// Verify import - Check if all data is restored
session_start();
require_once '../db_connect.php';

echo "<h1>Verify Import - Check Database Data</h1>";

// Check all tables and their record counts
$tables_to_check = [
    'users' => 'Users',
    'departments' => 'Departments', 
    'categories' => 'Categories',
    'locations' => 'Locations',
    'item_tables' => 'Item Tables',
    'items' => 'Items',
    'borrow_history' => 'Borrow History',
    'super_admin' => 'Super Admin'
];

echo "<h2>Table Data Check:</h2>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Table Name</th><th>Record Count</th><th>Status</th></tr>";

foreach ($tables_to_check as $table => $display_name) {
    $result = $conn->query("SELECT COUNT(*) as count FROM `$table`");
    
    if ($result) {
        $row = $result->fetch_assoc();
        $count = $row['count'];
        
        $status = $count > 0 ? '✅ Has Data' : '⚠️ Empty';
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($display_name) . "</td>";
        echo "<td>" . $count . "</td>";
        echo "<td>" . $status . "</td>";
        echo "</tr>";
        
        // Show sample data for empty tables
        if ($count == 0) {
            echo "<tr><td colspan='3' style='color: orange;'>⚠️ Table '$display_name' is empty - import may have failed for this table</td></tr>";
        }
    } else {
        echo "<tr><td>" . htmlspecialchars($display_name) . "</td><td colspan='2' style='color: red;'>❌ Error: " . $conn->error . "</td></tr>";
    }
}

echo "</table>";

// Check specific departments
echo "<h2>Departments Check:</h2>";
$result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");

if ($result && $result->num_rows > 0) {
    echo "<p style='color: green;'>✅ Found " . $result->num_rows . " departments:</p>";
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>" . htmlspecialchars($row['name']) . " (ID: " . $row['id'] . ")</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: red;'>❌ No departments found! Import may have failed.</p>";
}

// Check if ICT Equipment exists
echo "<h2>ICT Equipment Check:</h2>";
$result = $conn->query("SELECT id, name FROM departments WHERE name = 'ICT Equipment'");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "<p style='color: green;'>✅ ICT Equipment exists (ID: " . $row['id'] . ")</p>";
} else {
    echo "<p style='color: red;'>❌ ICT Equipment NOT FOUND! This needs to be added.</p>";
}

$conn->close();

echo "<h2>Solutions:</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>If data is missing:</h3>";
echo "<ol>";
echo "<li>Go to <a href='emergency_recovery.php'>emergency_recovery.php</a></li>";
echo "<li>Import your backup SQL file again</li>";
echo "<li>After import, refresh this page to verify data</li>";
echo "</ol>";
echo "</div>";

echo "<div style='background: #fef2f2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>If departments are missing:</h3>";
echo "<ol>";
echo "<li>Go to <a href='add_missing_departments.php'>add_missing_departments.php</a></li>";
echo "<li>This will add the 4 fixed departments</li>";
echo "<li>After adding, refresh the department page</li>";
echo "</ol>";
echo "</div>";

echo "<p><a href='department.php'>Go to Department Page</a> | <a href='emergency_recovery.php'>Go to Emergency Recovery</a></p>";
?>

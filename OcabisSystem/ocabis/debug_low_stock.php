<?php
session_start();
require_once '../db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    die('Not logged in');
}

$department = $_GET['department'] ?? $_SESSION['department'] ?? '';

if (empty($department)) {
    die('Department is required');
}

echo "<h2>Debug Low Stock Items for Department: " . htmlspecialchars($department) . "</h2>";

// Test query 1: All items in department
echo "<h3>1. All Items in Department:</h3>";
$query1 = "SELECT i.id, i.name, i.quantity, i.status, i.item_table_id, d.name as dept_name 
           FROM items i 
           LEFT JOIN departments d ON i.department_id = d.id 
           WHERE TRIM(d.name) = TRIM(?) 
           ORDER BY i.name";
$stmt1 = $conn->prepare($query1);
$stmt1->bind_param("s", $department);
$stmt1->execute();
$result1 = $stmt1->get_result();
echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Quantity</th><th>Status</th><th>Item Table ID</th><th>Dept</th></tr>";
while ($row = $result1->fetch_assoc()) {
    echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['quantity']}</td><td>{$row['status']}</td><td>{$row['item_table_id']}</td><td>{$row['dept_name']}</td></tr>";
}
echo "</table>";

// Test query 2: Items with quantity <= 5
echo "<h3>2. Items with Quantity <= 5:</h3>";
$query2 = "SELECT i.id, i.name, i.quantity, i.status, i.item_table_id 
           FROM items i 
           LEFT JOIN departments d ON i.department_id = d.id 
           WHERE TRIM(d.name) = TRIM(?) 
           AND i.quantity <= 5 
           AND i.quantity > 0
           ORDER BY i.name";
$stmt2 = $conn->prepare($query2);
$stmt2->bind_param("s", $department);
$stmt2->execute();
$result2 = $stmt2->get_result();
echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Quantity</th><th>Status</th><th>Item Table ID</th></tr>";
while ($row = $result2->fetch_assoc()) {
    echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['quantity']}</td><td>{$row['status']}</td><td>{$row['item_table_id']}</td></tr>";
}
echo "</table>";

// Test query 3: Items with consumable item tables
echo "<h3>3. Items with Consumable Item Tables:</h3>";
$query3 = "SELECT i.id, i.name, i.quantity, i.status, i.item_table_id, it.table_name, it.is_consumable
           FROM items i 
           LEFT JOIN departments d ON i.department_id = d.id 
           LEFT JOIN item_tables it ON i.item_table_id = it.id
           WHERE TRIM(d.name) = TRIM(?) 
           AND i.item_table_id IS NOT NULL
           ORDER BY i.name";
$stmt3 = $conn->prepare($query3);
$stmt3->bind_param("s", $department);
$stmt3->execute();
$result3 = $stmt3->get_result();
echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Quantity</th><th>Status</th><th>Item Table ID</th><th>Table Name</th><th>Is Consumable</th></tr>";
while ($row = $result3->fetch_assoc()) {
    $is_consumable = $row['is_consumable'] ?? 'NULL';
    echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['quantity']}</td><td>{$row['status']}</td><td>{$row['item_table_id']}</td><td>{$row['table_name']}</td><td>{$is_consumable}</td></tr>";
}
echo "</table>";

// Test query 4: Low stock consumable items (the actual query)
echo "<h3>4. Low Stock Consumable Items (Final Query):</h3>";
$query4 = "SELECT i.id, i.name, i.quantity, i.status, i.item_table_id, it.table_name, it.is_consumable
           FROM items i 
           INNER JOIN departments d ON i.department_id = d.id 
           INNER JOIN item_tables it ON i.item_table_id = it.id
           WHERE TRIM(d.name) = TRIM(?) 
           AND i.quantity <= 5 
           AND i.quantity > 0
           AND COALESCE(i.status, 'Working') = 'Working'
           AND COALESCE(it.is_consumable, 0) = 1
           ORDER BY i.name";
$stmt4 = $conn->prepare($query4);
$stmt4->bind_param("s", $department);
$stmt4->execute();
$result4 = $stmt4->get_result();
echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Quantity</th><th>Status</th><th>Item Table ID</th><th>Table Name</th><th>Is Consumable</th></tr>";
$count = 0;
while ($row = $result4->fetch_assoc()) {
    $count++;
    $is_consumable = $row['is_consumable'] ?? 'NULL';
    echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['quantity']}</td><td>{$row['status']}</td><td>{$row['item_table_id']}</td><td>{$row['table_name']}</td><td>{$is_consumable}</td></tr>";
}
echo "</table>";
echo "<p><strong>Total Count: {$count}</strong></p>";

$stmt1->close();
$stmt2->close();
$stmt3->close();
$stmt4->close();
?>


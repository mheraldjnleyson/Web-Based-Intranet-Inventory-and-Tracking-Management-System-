<?php
// Test script to check current PHP date
echo "PHP Current Date/Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Year: " . date('Y') . "\n";
echo "PHP Timezone: " . date_default_timezone_get() . "\n";

// Test database connection and date
require_once '../db_connect.php';
$conn = getDBConnection();

if ($conn) {
    $result = $conn->query("SELECT NOW() as mysql_date, CURDATE() as mysql_date_only");
    if ($result && $row = $result->fetch_assoc()) {
        echo "MySQL NOW(): " . $row['mysql_date'] . "\n";
        echo "MySQL CURDATE(): " . $row['mysql_date_only'] . "\n";
    }
    
    // Check latest item dates
    $result = $conn->query("SELECT id, name, created_at, updated_at FROM items ORDER BY id DESC LIMIT 5");
    if ($result) {
        echo "\nLatest 5 items:\n";
        while ($row = $result->fetch_assoc()) {
            echo "ID: {$row['id']}, Name: {$row['name']}, Created: {$row['created_at']}, Updated: {$row['updated_at']}\n";
        }
    }
    
    $conn->close();
} else {
    echo "Database connection failed\n";
}
?>


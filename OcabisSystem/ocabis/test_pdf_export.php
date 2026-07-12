<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

echo "<h1>PDF Export Test</h1>";
echo "<p>Testing PDF export functionality...</p>";

// Test links for different export types
$export_types = [
    'users' => 'User Management Export',
    'item_requests' => 'Item Requests Export', 
    'dashboard' => 'Dashboard Export',
    'borrow_history' => 'Borrow History Export'
];

echo "<h2>Available Export Types:</h2>";
echo "<ul>";
foreach ($export_types as $type => $name) {
    echo "<li><a href='pdf_export.php?type=$type' target='_blank'>$name</a></li>";
}
echo "</ul>";

echo "<h2>Test with Filters:</h2>";
echo "<ul>";
echo "<li><a href='pdf_export.php?type=users&status=active' target='_blank'>Users - Active Only</a></li>";
echo "<li><a href='pdf_export.php?type=item_requests&status=pending' target='_blank'>Item Requests - Pending Only</a></li>";
echo "<li><a href='pdf_export.php?type=borrow_history&status=active' target='_blank'>Borrow History - Active Only</a></li>";
echo "</ul>";

echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>";
?>

<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

echo "<h1>Dashboard Export Test</h1>";
echo "<p>Testing dashboard export functionality...</p>";

echo "<h2>Direct Export Links:</h2>";
echo "<ul>";
echo "<li><a href='pdf_export.php?type=dashboard' target='_blank'>Dashboard Export</a></li>";
echo "<li><a href='pdf_export.php?type=users' target='_blank'>Users Export</a></li>";
echo "<li><a href='pdf_export.php?type=item_requests' target='_blank'>Item Requests Export</a></li>";
echo "<li><a href='pdf_export.php?type=borrow_history' target='_blank'>Borrow History Export</a></li>";
echo "</ul>";

echo "<h2>Test JavaScript Function:</h2>";
echo "<button onclick=\"window.open('pdf_export.php?type=dashboard', '_blank')\" style=\"padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;\">Test Dashboard Export</button>";

echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>";
?>

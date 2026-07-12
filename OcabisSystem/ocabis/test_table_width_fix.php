<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

echo "<h1>Table Width Fix Test</h1>";
echo "<p>Testing fixed table widths to prevent cutoff...</p>";

echo "<h2>✅ Table Width Fixes Applied:</h2>";
echo "<ul>";
echo "<li>✅ <strong>Users Table:</strong> Reduced from 240px to 190px total width</li>";
echo "<li>✅ <strong>Item Requests:</strong> Optimized column widths for better fit</li>";
echo "<li>✅ <strong>Borrow History:</strong> Adjusted all columns to fit page</li>";
echo "<li>✅ <strong>Font Size:</strong> Reduced to 7pt for better fit</li>";
echo "</ul>";

echo "<h2>📏 New Column Widths:</h2>";
echo "<h3>Users Table:</h3>";
echo "<ul>";
echo "<li>ID: 15px (was 20px)</li>";
echo "<li>Username: 30px (was 40px)</li>";
echo "<li>Email: 45px (was 60px)</li>";
echo "<li>Department: 35px (was 40px)</li>";
echo "<li>Status: 20px (was 25px)</li>";
echo "<li>Role: 20px (was 25px)</li>";
echo "<li>Created: 25px (was 30px)</li>";
echo "<li><strong>Total: 190px (fits page width)</strong></li>";
echo "</ul>";

echo "<h2>🔍 Test the Fixed Tables:</h2>";
echo "<ul>";
echo "<li><a href='pdf_export.php?type=users' target='_blank'><strong>Users Export</strong> - All columns should now be visible</a></li>";
echo "<li><a href='pdf_export.php?type=item_requests' target='_blank'><strong>Item Requests Export</strong> - Optimized layout</a></li>";
echo "<li><a href='pdf_export.php?type=borrow_history' target='_blank'><strong>Borrow History Export</strong> - All columns fit</a></li>";
echo "</ul>";

echo "<h2>🎯 What to Check:</h2>";
echo "<ol>";
echo "<li><strong>All Columns Visible:</strong> No more cut-off columns</li>";
echo "<li><strong>Proper Alignment:</strong> Tables fit within page margins</li>";
echo "<li><strong>Readable Text:</strong> Text is still readable despite smaller columns</li>";
echo "<li><strong>Professional Layout:</strong> Clean, organized appearance</li>";
echo "</ol>";

echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>";
?>

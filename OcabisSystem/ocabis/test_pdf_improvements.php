<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

echo "<h1>PDF Export Improvements Test</h1>";
echo "<p>Testing improved PDF exports with table names and full text...</p>";

echo "<h2>✅ Improvements Made:</h2>";
echo "<ul>";
echo "<li>✅ <strong>Table Names Added:</strong> Each PDF now shows the database table name</li>";
echo "<li>✅ <strong>Full Text Display:</strong> Removed text truncation (substr) - now shows complete data</li>";
echo "<li>✅ <strong>Wider Columns:</strong> Increased column widths for better readability</li>";
echo "<li>✅ <strong>Better Formatting:</strong> Improved font sizes and spacing</li>";
echo "</ul>";

echo "<h2>📊 Test Export Links:</h2>";
echo "<ul>";
echo "<li><a href='pdf_export.php?type=users' target='_blank'><strong>Users Export</strong> - Shows 'USERS TABLE DATA'</a></li>";
echo "<li><a href='pdf_export.php?type=item_requests' target='_blank'><strong>Item Requests Export</strong> - Shows 'ITEM_REQUESTS TABLE DATA'</a></li>";
echo "<li><a href='pdf_export.php?type=borrow_history' target='_blank'><strong>Borrow History Export</strong> - Shows 'BORROW_HISTORY TABLE DATA'</a></li>";
echo "<li><a href='pdf_export.php?type=dashboard' target='_blank'><strong>Dashboard Export</strong> - Shows 'ITEMS & BORROW_HISTORY TABLES DATA'</a></li>";
echo "</ul>";

echo "<h2>🔍 What to Check in PDF:</h2>";
echo "<ol>";
echo "<li><strong>Table Names:</strong> Look for blue-colored table name headers</li>";
echo "<li><strong>Full Text:</strong> All usernames, emails, and item names should be complete</li>";
echo "<li><strong>Column Widths:</strong> Text should not be cut off</li>";
echo "<li><strong>Professional Layout:</strong> Clean, organized tables</li>";
echo "</ol>";

echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>";
?>

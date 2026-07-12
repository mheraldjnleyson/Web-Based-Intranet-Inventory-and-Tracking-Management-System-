<?php
// Test script to verify emergency recovery navigation
session_start();

// Set emergency access
$_SESSION['emergency_access'] = true;
$_SESSION['emergency_user'] = 'emergency';

echo "<h1>Emergency Recovery Navigation Test</h1>";
echo "<p>This test verifies that the emergency recovery system only shows Database Export in the navigation.</p>";

echo "<h2>Expected Navigation Items:</h2>";
echo "<ul>";
echo "<li>✅ Database Export (should be present)</li>";
echo "<li>❌ Dashboard (should be hidden)</li>";
echo "<li>❌ Department (should be hidden)</li>";
echo "<li>❌ Location (should be hidden)</li>";
echo "<li>❌ Categories (should be hidden)</li>";
echo "<li>❌ Borrow History (should be hidden)</li>";
echo "<li>❌ Archive (should be hidden)</li>";
echo "<li>❌ QR Code Scanner (should be hidden)</li>";
echo "<li>❌ Item Requests (should be hidden)</li>";
echo "<li>❌ User Management (should be hidden)</li>";
echo "<li>✅ Sign out (should be present)</li>";
echo "</ul>";

echo "<h2>Test Instructions:</h2>";
echo "<ol>";
echo "<li>Go to <a href='emergency_recovery.php' target='_blank'>emergency_recovery.php</a></li>";
echo "<li>Login with emergency credentials: emergency / recovery2024</li>";
echo "<li>Check the sidebar - it should only show 'Database Export' and 'Sign out'</li>";
echo "<li>Verify that all other navigation items are missing</li>";
echo "</ol>";

echo "<h2>If you still see other navigation items:</h2>";
echo "<ul>";
echo "<li>Clear your browser cache (Ctrl+F5)</li>";
echo "<li>Make sure you're accessing emergency_recovery.php, not database_export.php</li>";
echo "<li>Check if you're logged in as emergency user, not super admin</li>";
echo "</ul>";

echo "<p><strong>Note:</strong> The emergency recovery system is separate from the main database export page. Make sure you're accessing the correct URL.</p>";
?>

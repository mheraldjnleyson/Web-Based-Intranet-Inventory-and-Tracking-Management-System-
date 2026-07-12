<?php
/**
 * Test script for email notifications
 * This script tests the email notification functionality
 */

require_once 'email_notifications.php';

echo "<h2>Testing Email Notifications</h2>";

// Test data
$test_email = "test@example.com"; // Replace with a real email for testing
$test_username = "TestUser";
$test_department = "IT Department";
$admin_email = "admin@example.com"; // Replace with admin email for testing

echo "<h3>1. Testing Approval Email</h3>";
$approval_result = sendApprovalEmail($test_email, $test_username);
if ($approval_result) {
    echo "<p style='color: green;'>✅ Approval email sent successfully!</p>";
} else {
    echo "<p style='color: red;'>❌ Approval email failed to send.</p>";
}

echo "<h3>2. Testing Rejection Email</h3>";
$rejection_result = sendRejectionEmail($test_email, $test_username, "Test rejection reason");
if ($rejection_result) {
    echo "<p style='color: green;'>✅ Rejection email sent successfully!</p>";
} else {
    echo "<p style='color: red;'>❌ Rejection email failed to send.</p>";
}

echo "<h3>3. Testing Admin Notification Email</h3>";
$admin_result = sendAdminNotificationEmail($admin_email, $test_username, $test_email, $test_department);
if ($admin_result) {
    echo "<p style='color: green;'>✅ Admin notification email sent successfully!</p>";
} else {
    echo "<p style='color: red;'>❌ Admin notification email failed to send.</p>";
}

echo "<hr>";
echo "<p><strong>Note:</strong> To test with real emails, update the email addresses in this script and run it.</p>";
echo "<p><strong>Email Configuration:</strong> The system is configured to use Gmail SMTP with the following settings:</p>";
echo "<ul>";
echo "<li>SMTP Host: smtp.gmail.com</li>";
echo "<li>Port: 587</li>";
echo "<li>Security: TLS</li>";
echo "<li>From: capstone12025@gmail.com</li>";
echo "</ul>";
?>

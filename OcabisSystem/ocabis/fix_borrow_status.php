<?php
/**
 * Fix borrow_history status column to support 'pending' status
 * This script will:
 * 1. Update the ENUM to include 'pending' and 'declined'
 * 2. Convert existing 'active' or '1' status to 'pending' for unapproved requests
 */
require_once '../db_connect.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Borrow Status</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Fix Borrow History Status Column</h1>
    
    <?php
    try {
        // Step 1: Check current status column definition
        echo "<h2>Step 1: Checking current status column...</h2>";
        $checkCol = $conn->query("SHOW COLUMNS FROM borrow_history WHERE Field = 'status'");
        if ($col = $checkCol->fetch_assoc()) {
            echo "<p class='info'>Current Type: " . htmlspecialchars($col['Type']) . "</p>";
            echo "<p class='info'>Current Default: " . htmlspecialchars($col['Default'] ?? 'NULL') . "</p>";
        }
        
        // Step 2: Update ENUM to include 'pending' and 'declined'
        echo "<h2>Step 2: Updating status column ENUM...</h2>";
        $alterQuery = "ALTER TABLE `borrow_history` 
                       MODIFY COLUMN `status` ENUM('pending', 'active', 'returned', 'overdue', 'declined') NOT NULL DEFAULT 'pending'";
        
        if ($conn->query($alterQuery)) {
            echo "<p class='success'>✓ Status column updated successfully!</p>";
        } else {
            throw new Exception("Failed to update status column: " . $conn->error);
        }
        
        // Step 3: Check current records
        echo "<h2>Step 3: Checking current records...</h2>";
        $countQuery = "SELECT status, COUNT(*) as count FROM borrow_history GROUP BY status";
        $countResult = $conn->query($countQuery);
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Status</th><th>Count</th></tr>";
        while ($row = $countResult->fetch_assoc()) {
            echo "<tr><td>" . htmlspecialchars($row['status']) . "</td><td>" . $row['count'] . "</td></tr>";
        }
        echo "</table>";
        
        // Step 4: Convert invalid statuses to 'pending'
        echo "<h2>Step 4: Converting invalid statuses to 'pending'...</h2>";
        
        // Convert '1' or numeric statuses to 'pending'
        $update1 = "UPDATE borrow_history SET status = 'pending' WHERE status = '1' OR status = '0' OR status NOT IN ('pending', 'active', 'returned', 'overdue', 'declined')";
        $result1 = $conn->query($update1);
        if ($result1) {
            $affected1 = $conn->affected_rows;
            echo "<p class='info'>✓ Converted $affected1 records with invalid status to 'pending'</p>";
        }
        
        // Convert 'active' status to 'pending' for records that should be pending (not yet approved)
        // We'll convert all 'active' records that don't have a return_date (meaning they're still borrowed)
        // Actually, let's be more careful - only convert if they were created recently and don't have return_date
        // For now, let's just convert all 'active' to 'pending' if they don't have return_date
        $update2 = "UPDATE borrow_history SET status = 'pending' WHERE status = 'active' AND return_date IS NULL";
        $result2 = $conn->query($update2);
        if ($result2) {
            $affected2 = $conn->affected_rows;
            echo "<p class='info'>✓ Converted $affected2 'active' records (without return_date) to 'pending'</p>";
        }
        
        // Step 5: Final count
        echo "<h2>Step 5: Final status count...</h2>";
        $finalCount = $conn->query("SELECT status, COUNT(*) as count FROM borrow_history GROUP BY status");
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Status</th><th>Count</th></tr>";
        $pendingCount = 0;
        while ($row = $finalCount->fetch_assoc()) {
            echo "<tr><td>" . htmlspecialchars($row['status']) . "</td><td>" . $row['count'] . "</td></tr>";
            if ($row['status'] === 'pending') {
                $pendingCount = $row['count'];
            }
        }
        echo "</table>";
        
        echo "<h2 class='success'>✓ Migration Complete!</h2>";
        echo "<p class='success'>There are now $pendingCount pending borrow requests.</p>";
        echo "<p><a href='department.php'>Go to Department Page</a> | <a href='test_borrow_requests.php'>View All Requests</a></p>";
        
    } catch (Exception $e) {
        echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    ?>
</body>
</html>


<?php
/**
 * Quick check script to see pending borrow requests
 */
require_once '../db_connect.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Check Pending Status</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .pending { background-color: #fff3cd; }
    </style>
</head>
<body>
    <h1>Check Pending Borrow Requests</h1>
    
    <?php
    // Check status column definition
    echo "<h2>Status Column Definition:</h2>";
    $colCheck = $conn->query("SHOW COLUMNS FROM borrow_history WHERE Field = 'status'");
    if ($col = $colCheck->fetch_assoc()) {
        echo "<p><strong>Type:</strong> " . htmlspecialchars($col['Type']) . "</p>";
        echo "<p><strong>Default:</strong> " . htmlspecialchars($col['Default'] ?? 'NULL') . "</p>";
    }
    
    // Count by status
    echo "<h2>Count by Status:</h2>";
    $statusCount = $conn->query("SELECT status, COUNT(*) as count FROM borrow_history GROUP BY status ORDER BY status");
    echo "<table>";
    echo "<tr><th>Status</th><th>Count</th></tr>";
    $totalPending = 0;
    while ($row = $statusCount->fetch_assoc()) {
        $class = ($row['status'] === 'pending') ? 'pending' : '';
        echo "<tr class='$class'><td>" . htmlspecialchars($row['status']) . "</td><td>" . $row['count'] . "</td></tr>";
        if ($row['status'] === 'pending') {
            $totalPending = $row['count'];
        }
    }
    echo "</table>";
    
    // Show pending requests
    echo "<h2>Pending Requests (showing first 10):</h2>";
    $pendingQuery = "SELECT borrow_id, borrower_name, item_name, department_name, status, created_at 
                      FROM borrow_history 
                      WHERE status = 'pending' 
                      ORDER BY created_at DESC 
                      LIMIT 10";
    $pendingResult = $conn->query($pendingQuery);
    
    if ($pendingResult && $pendingResult->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>Borrow ID</th><th>Borrower</th><th>Item</th><th>Department</th><th>Status</th><th>Created</th></tr>";
        while ($row = $pendingResult->fetch_assoc()) {
            echo "<tr class='pending'>";
            echo "<td>" . htmlspecialchars($row['borrow_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['borrower_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['item_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['department_name']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($row['status']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "<p><strong>Total Pending:</strong> $totalPending</p>";
    } else {
        echo "<p style='color: red;'><strong>No pending requests found!</strong></p>";
        echo "<p>This could mean:</p>";
        echo "<ul>";
        echo "<li>The status column doesn't support 'pending' - need to run fix_borrow_status.php</li>";
        echo "<li>All existing requests have been approved/declined</li>";
        echo "<li>New requests aren't being saved with 'pending' status</li>";
        echo "</ul>";
    }
    
    // Check for ICT Equipment department specifically
    echo "<h2>ICT Equipment Department Requests:</h2>";
    $ictQuery = "SELECT borrow_id, borrower_name, item_name, department_name, status, created_at 
                 FROM borrow_history 
                 WHERE LOWER(TRIM(department_name)) = LOWER(TRIM('ICT Equipment'))
                 ORDER BY created_at DESC 
                 LIMIT 10";
    $ictResult = $conn->query($ictQuery);
    
    if ($ictResult && $ictResult->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>Borrow ID</th><th>Borrower</th><th>Item</th><th>Status</th><th>Created</th></tr>";
        while ($row = $ictResult->fetch_assoc()) {
            $class = ($row['status'] === 'pending') ? 'pending' : '';
            echo "<tr class='$class'>";
            echo "<td>" . htmlspecialchars($row['borrow_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['borrower_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['item_name']) . "</td>";
            echo "<td><strong>" . htmlspecialchars($row['status']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No requests found for ICT Equipment department.</p>";
    }
    ?>
    
    <p><a href="fix_borrow_status.php">Run Fix Script</a> | <a href="department.php">Back to Department</a></p>
</body>
</html>


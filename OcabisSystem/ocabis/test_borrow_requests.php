<?php
/**
 * Test script to check borrow requests in database
 */
session_start();
require_once '../db_connect.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Borrow Requests</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .pending { background-color: #fff3cd; }
        .active { background-color: #d4edda; }
        .declined { background-color: #f8d7da; }
    </style>
</head>
<body>
    <h1>Borrow Requests Database Test</h1>
    
    <?php
    // Get all borrow requests
    $query = "SELECT bh.*, i.name as item_name, d.name as dept_name_from_items 
              FROM borrow_history bh 
              LEFT JOIN items i ON bh.item_id = i.id 
              LEFT JOIN departments d ON i.department_id = d.id
              ORDER BY bh.created_at DESC";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        echo "<h2>All Borrow Requests (" . $result->num_rows . " total)</h2>";
        echo "<table>";
        echo "<tr>
                <th>Borrow ID</th>
                <th>Borrower</th>
                <th>Item</th>
                <th>Dept (borrow_history)</th>
                <th>Dept (items table)</th>
                <th>Status</th>
                <th>Quantity</th>
                <th>Borrow Date</th>
                <th>Due Date</th>
                <th>Created At</th>
              </tr>";
        
        while ($row = $result->fetch_assoc()) {
            $statusClass = '';
            if ($row['status'] === 'pending') $statusClass = 'pending';
            elseif ($row['status'] === 'active') $statusClass = 'active';
            elseif ($row['status'] === 'declined') $statusClass = 'declined';
            
            echo "<tr class='$statusClass'>";
            echo "<td>" . htmlspecialchars($row['borrow_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['borrower_name']) . "<br><small>" . htmlspecialchars($row['borrower_email']) . "</small></td>";
            echo "<td>" . htmlspecialchars($row['item_name'] ?? $row['item_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['department_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['dept_name_from_items'] ?? 'N/A') . "</td>";
            echo "<td><strong>" . htmlspecialchars($row['status']) . "</strong></td>";
            echo "<td>" . $row['quantity_borrowed'] . "</td>";
            echo "<td>" . htmlspecialchars($row['borrow_date']) . "</td>";
            echo "<td>" . htmlspecialchars($row['due_date']) . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Count by status
        $statusQuery = "SELECT status, COUNT(*) as count FROM borrow_history GROUP BY status";
        $statusResult = $conn->query($statusQuery);
        if ($statusResult) {
            echo "<h2>Status Summary</h2>";
            echo "<table>";
            echo "<tr><th>Status</th><th>Count</th></tr>";
            while ($statusRow = $statusResult->fetch_assoc()) {
                echo "<tr><td>" . htmlspecialchars($statusRow['status']) . "</td><td>" . $statusRow['count'] . "</td></tr>";
            }
            echo "</table>";
        }
        
        // Count by department
        $deptQuery = "SELECT department_name, status, COUNT(*) as count 
                      FROM borrow_history 
                      GROUP BY department_name, status 
                      ORDER BY department_name, status";
        $deptResult = $conn->query($deptQuery);
        if ($deptResult) {
            echo "<h2>By Department and Status</h2>";
            echo "<table>";
            echo "<tr><th>Department</th><th>Status</th><th>Count</th></tr>";
            while ($deptRow = $deptResult->fetch_assoc()) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($deptRow['department_name']) . "</td>";
                echo "<td>" . htmlspecialchars($deptRow['status']) . "</td>";
                echo "<td>" . $deptRow['count'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } else {
        echo "<p style='color: red; font-size: 18px;'><strong>NO BORROW REQUESTS FOUND IN DATABASE!</strong></p>";
        echo "<p>This means either:</p>";
        echo "<ul>";
        echo "<li>No requests have been submitted yet</li>";
        echo "<li>Requests are being saved but then deleted</li>";
        echo "<li>There's an error preventing the save</li>";
        echo "</ul>";
    }
    
    // Check current user session
    echo "<h2>Current Session Info</h2>";
    echo "<p><strong>Username:</strong> " . (isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Not logged in') . "</p>";
    echo "<p><strong>Department:</strong> " . (isset($_SESSION['department']) ? htmlspecialchars($_SESSION['department']) : 'Not set') . "</p>";
    echo "<p><strong>Role:</strong> " . (isset($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : 'Not set') . "</p>";
    echo "<p><strong>Is Admin:</strong> " . (isset($_SESSION['is_admin']) ? ($_SESSION['is_admin'] ? 'Yes' : 'No') : 'Not set') . "</p>";
    ?>
    
    <p><a href="department.php">Back to Department Page</a></p>
</body>
</html>


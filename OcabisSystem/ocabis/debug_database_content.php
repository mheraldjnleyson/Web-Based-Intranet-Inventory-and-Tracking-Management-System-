<?php
// Debug script to check database content and connection
session_start();

echo "<h1>Database Content Debug</h1>";

// Test database connection
echo "<h2>Step 1: Database Connection Test</h2>";
try {
    require_once '../db_connect.php';
    
    if ($conn->connect_error) {
        echo "<p style='color: red;'>❌ Database connection failed: " . $conn->connect_error . "</p>";
        exit;
    } else {
        echo "<p style='color: green;'>✅ Database connected successfully</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
    exit;
}

// Test basic queries
echo "<h2>Step 2: Basic Table Content</h2>";

$tables_to_check = [
    'departments' => 'SELECT COUNT(*) as count FROM departments',
    'categories' => 'SELECT COUNT(*) as count FROM categories', 
    'locations' => 'SELECT COUNT(*) as count FROM locations',
    'item_tables' => 'SELECT COUNT(*) as count FROM item_tables',
    'items' => 'SELECT COUNT(*) as count FROM items',
    'users' => 'SELECT COUNT(*) as count FROM users'
];

foreach ($tables_to_check as $table => $query) {
    try {
        $result = $conn->query($query);
        if ($result) {
            $row = $result->fetch_assoc();
            $count = $row['count'];
            if ($count > 0) {
                echo "<p style='color: green;'>✅ Table '$table' has $count records</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Table '$table' is empty</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Table '$table' query failed: " . $conn->error . "</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Table '$table' error: " . $e->getMessage() . "</p>";
    }
}

// Test specific department queries
echo "<h2>Step 3: Department Page Queries Test</h2>";

// Test departments query
echo "<h3>Departments Query:</h3>";
try {
    $sql = "SELECT id, name FROM departments ORDER BY name ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✅ Found " . $result->num_rows . " departments:</p>";
        echo "<ul>";
        while ($row = $result->fetch_assoc()) {
            echo "<li>ID: " . $row['id'] . " - Name: " . htmlspecialchars($row['name']) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: orange;'>⚠️ No departments found</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Departments query error: " . $e->getMessage() . "</p>";
}

// Test categories query
echo "<h3>Categories Query:</h3>";
try {
    $sql = "SELECT DISTINCT name FROM categories ORDER BY name ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✅ Found " . $result->num_rows . " categories:</p>";
        echo "<ul>";
        while ($row = $result->fetch_assoc()) {
            echo "<li>" . htmlspecialchars($row['name']) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color: orange;'>⚠️ No categories found</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Categories query error: " . $e->getMessage() . "</p>";
}

// Test item_tables query
echo "<h3>Item Tables Query:</h3>";
try {
    $sql = "SELECT it.*, c.name as category_name, l.name as location_name, d.name as department_name 
            FROM item_tables it 
            LEFT JOIN categories c ON it.category_id = c.id 
            LEFT JOIN locations l ON it.location_id = l.id 
            LEFT JOIN departments d ON it.department_id = d.id 
            ORDER BY it.name ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✅ Found " . $result->num_rows . " item tables:</p>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Name</th><th>Category</th><th>Location</th><th>Department</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['category_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['location_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['department_name'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ No item tables found</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Item tables query error: " . $e->getMessage() . "</p>";
}

// Test items query
echo "<h3>Items Query:</h3>";
try {
    $sql = "SELECT i.*, it.name as item_table_name 
            FROM items i 
            LEFT JOIN item_tables it ON i.item_table_id = it.id 
            ORDER BY i.id ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "<p style='color: green;'>✅ Found " . $result->num_rows . " items:</p>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Item Table</th><th>Status</th><th>Notes</th></tr>";
        $count = 0;
        while ($row = $result->fetch_assoc() && $count < 10) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['item_table_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . htmlspecialchars($row['notes'] ?? '') . "</td>";
            echo "</tr>";
            $count++;
        }
        if ($result->num_rows > 10) {
            echo "<tr><td colspan='4'>... and " . ($result->num_rows - 10) . " more items</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ No items found</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Items query error: " . $e->getMessage() . "</p>";
}

// Test session data
echo "<h2>Step 4: Session Data</h2>";
if (isset($_SESSION['username'])) {
    echo "<p style='color: green;'>✅ User logged in: " . htmlspecialchars($_SESSION['username']) . "</p>";
    echo "<p>User ID: " . ($_SESSION['user_id'] ?? 'N/A') . "</p>";
    echo "<p>Department: " . ($_SESSION['department'] ?? 'N/A') . "</p>";
    echo "<p>Role: " . ($_SESSION['role'] ?? 'N/A') . "</p>";
    echo "<p>Is Admin: " . (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] ? 'Yes' : 'No') . "</p>";
} else {
    echo "<p style='color: red;'>❌ User not logged in</p>";
}

echo "<h2>Step 5: Solutions</h2>";
echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>If tables are empty:</h3>";
echo "<ol>";
echo "<li>Go to <a href='restore_database_structure.php'>restore_database_structure.php</a> to add sample data</li>";
echo "<li>Or import your backup using <a href='emergency_recovery.php'>emergency_recovery.php</a></li>";
echo "</ol>";
echo "</div>";

echo "<div style='background: #fef2f2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
echo "<h3>If queries are failing:</h3>";
echo "<ol>";
echo "<li>Check if XAMPP MySQL is running</li>";
echo "<li>Check if 'ocabis' database exists</li>";
echo "<li>Check if tables exist in the database</li>";
echo "</ol>";
echo "</div>";

echo "<p><a href='department.php'>Go to Department Page</a> | <a href='dashboard.php'>Go to Dashboard</a></p>";
?>

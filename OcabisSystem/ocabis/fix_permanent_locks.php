<?php
/**
 * Fix script to permanently lock accounts that have 3 or more temporary locks
 * but are not yet permanently locked
 */

require_once '../db_connect.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Fix Permanent Locks</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
    .container { max-width: 800px; margin: auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    h1, h2 { color: #0056b3; }
    p { margin-bottom: 10px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: #007bff; }
    .warning { color: #ff9800; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
    th { background-color: #f2f2f2; }
</style>";
echo "</head><body><div class='container'>";
echo "<h1>Fix Permanent Locks</h1>";

try {
    // Check if lock columns exist
    $check_columns = $conn->query("SHOW COLUMNS FROM users LIKE 'account_locked'");
    $has_lock_columns = ($check_columns && $check_columns->num_rows > 0);
    
    if (!$has_lock_columns) {
        echo "<p class='error'>❌ Account lock feature is not enabled. Please run the migration script first: run_account_lock_migration.php</p>";
        echo "</div></body></html>";
        exit();
    }
    
    echo "<h2>Step 1: Finding accounts with 3+ temporary locks that are not permanently locked</h2>";
    
    // Find users with 3 or more temporary locks but account_locked = 0
    $find_query = "SELECT id, username, email, temporary_lock_count, account_locked, locked_at, lock_reason 
                   FROM users 
                   WHERE temporary_lock_count >= 3 AND (account_locked = 0 OR account_locked IS NULL)";
    
    $result = $conn->query($find_query);
    
    if ($result && $result->num_rows > 0) {
        echo "<p class='info'>Found " . $result->num_rows . " account(s) that need to be permanently locked:</p>";
        
        echo "<table>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Temporary Locks</th><th>Current Status</th></tr>";
        
        $users_to_lock = [];
        while ($row = $result->fetch_assoc()) {
            $users_to_lock[] = $row;
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['temporary_lock_count']) . "</td>";
            echo "<td>" . ((int)$row['account_locked'] === 1 ? "Locked" : "Not Locked") . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        echo "<h2>Step 2: Permanently locking these accounts</h2>";
        
        $locked_count = 0;
        $failed_count = 0;
        
        foreach ($users_to_lock as $user) {
            $update_query = "UPDATE users SET account_locked = 1, locked_at = NOW(), 
                            lock_reason = 'Too many temporary locks ({$user['temporary_lock_count']} temporary locks exceeded - fixed by script)' 
                            WHERE id = ?";
            
            $stmt = $conn->prepare($update_query);
            if ($stmt) {
                $stmt->bind_param("i", $user['id']);
                if ($stmt->execute()) {
                    echo "<p class='success'>✓ Permanently locked account: " . htmlspecialchars($user['username']) . " (ID: {$user['id']}, Temp Locks: {$user['temporary_lock_count']})</p>";
                    $locked_count++;
                } else {
                    echo "<p class='error'>✗ Failed to lock account: " . htmlspecialchars($user['username']) . " - " . $stmt->error . "</p>";
                    $failed_count++;
                }
                $stmt->close();
            } else {
                echo "<p class='error'>✗ Failed to prepare statement for user: " . htmlspecialchars($user['username']) . " - " . $conn->error . "</p>";
                $failed_count++;
            }
        }
        
        echo "<h2>Summary</h2>";
        echo "<p class='success'>✓ Successfully locked: {$locked_count} account(s)</p>";
        if ($failed_count > 0) {
            echo "<p class='error'>✗ Failed to lock: {$failed_count} account(s)</p>";
        }
        
    } else {
        echo "<p class='info'>✓ No accounts found with 3+ temporary locks that are not permanently locked. All accounts are in correct state.</p>";
    }
    
    // Show current state of all accounts with locks
    echo "<h2>Current State of All Accounts with Locks</h2>";
    $state_query = "SELECT id, username, email, temporary_lock_count, account_locked, locked_at, lock_reason 
                    FROM users 
                    WHERE temporary_lock_count > 0 OR account_locked = 1
                    ORDER BY temporary_lock_count DESC, account_locked DESC";
    
    $state_result = $conn->query($state_query);
    
    if ($state_result && $state_result->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Temp Locks</th><th>Locked</th><th>Locked At</th><th>Lock Reason</th></tr>";
        
        while ($row = $state_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['temporary_lock_count']) . "</td>";
            echo "<td>" . ((int)$row['account_locked'] === 1 ? "Yes" : "No") . "</td>";
            echo "<td>" . ($row['locked_at'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($row['lock_reason'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='info'>No accounts with locks found.</p>";
    }
    
    echo "<p class='success'>Fix script completed!</p>";
    
} catch (Exception $e) {
    echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
    error_log("Fix permanent locks failed: " . $e->getMessage());
} finally {
    $conn->close();
}

echo "</div></body></html>";
?>


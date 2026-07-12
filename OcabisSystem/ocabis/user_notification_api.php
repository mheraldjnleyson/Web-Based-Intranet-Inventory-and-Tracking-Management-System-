<?php
session_start();
require_once '../db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $username = $_SESSION['username'];
    $department = isset($_SESSION['department']) ? $_SESSION['department'] : '';
    $isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
    $isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
    $isViewer = (trim($department) === '') && !$isAdmin && !$isSuperAdmin;
    
    // Update overdue status for active borrows
    $update_overdue = "UPDATE borrow_history SET status = 'overdue', priority = 'high' WHERE status = 'active' AND due_date < CURDATE()";
    $conn->query($update_overdue);
    
    // Check if updated_at column exists in borrow_history
    $check_updated_at = $conn->query("SHOW COLUMNS FROM borrow_history LIKE 'updated_at'");
    $has_updated_at = $check_updated_at && $check_updated_at->num_rows > 0;
    
    $notifications = [];
    $total_count = 0;
    
    // 1. Get due date notifications for borrowed items
    // Items due within 3 days or overdue
    $due_date_sql = "SELECT 'due_date' as type, id, borrow_id, item_name, due_date, 
                            CASE 
                                WHEN due_date < CURDATE() THEN 'overdue'
                                WHEN due_date = CURDATE() THEN 'due_today'
                                WHEN due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 'due_tomorrow'
                                WHEN due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 'due_soon'
                                ELSE 'normal'
                            END as urgency_level
                     FROM borrow_history 
                     WHERE borrower_name = ? 
                     AND status IN ('active', 'overdue')
                     AND (due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) OR due_date < CURDATE())
                     ORDER BY due_date ASC";
    
    $stmt = $conn->prepare($due_date_sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
        $total_count++;
    }
    
    // 2. Get borrow request status notifications (approved/declined)
    // Show notifications when a borrow request status changes from pending to approved/declined
    if ($has_updated_at) {
        $borrow_status_sql = "SELECT 'borrow_status' as type, id, borrow_id, item_name, status, updated_at, created_at, borrow_date, due_date, date_needed,
                                   CASE 
                                       WHEN status = 'active' THEN 'borrow_approved'
                                       WHEN status = 'declined' THEN 'borrow_declined'
                                       ELSE 'other'
                                   END as notification_type
                            FROM borrow_history 
                            WHERE borrower_name = ? 
                            AND status IN ('active', 'declined')
                            AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            AND updated_at > created_at
                            ORDER BY updated_at DESC";
    } else {
        // Fallback if updated_at column doesn't exist
        $borrow_status_sql = "SELECT 'borrow_status' as type, id, borrow_id, item_name, status, created_at as updated_at, created_at, borrow_date, due_date, date_needed,
                                   CASE 
                                       WHEN status = 'active' THEN 'borrow_approved'
                                       WHEN status = 'declined' THEN 'borrow_declined'
                                       ELSE 'other'
                                   END as notification_type
                            FROM borrow_history 
                            WHERE borrower_name = ? 
                            AND status IN ('active', 'declined')
                            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            ORDER BY created_at DESC";
    }
    
    $stmt = $conn->prepare($borrow_status_sql);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
            $total_count++;
        }
        $stmt->close();
    }
    
    // 3. Get QR request notifications
    // For Super Admin: show new pending QR requests
    // For regular users: show when their QR requests are approved/rejected
    if ($isSuperAdmin) {
        // Super Admin: Get new pending QR requests from the last 7 days
        $pending_qr_sql = "SELECT 'new_qr_request' as type, qr.id, it.table_name as item_name, qr.status, qr.created_at, qr.requested_by, qr.item_id,
                          CASE 
                              WHEN qr.item_id IS NOT NULL THEN 1
                              ELSE (SELECT COUNT(*) FROM items WHERE item_table_id = qr.item_table_id)
                          END as quantity,
                          CASE 
                              WHEN qr.status = 'pending' THEN 'new_qr_request'
                              ELSE 'other'
                          END as notification_type
                   FROM qr_requests qr
                   LEFT JOIN item_tables it ON qr.item_table_id = it.id
                   WHERE qr.status = 'pending'
                   AND qr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   ORDER BY qr.created_at DESC
                   LIMIT 10";
        
        $stmt = $conn->prepare($pending_qr_sql);
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $notifications[] = $row;
                $total_count++;
            }
            $stmt->close();
        }
    }
    
    if (!$isViewer) {
    // For all users (including department heads): Get their own QR request status updates (approved/rejected)
    // Primary source: qr_requests table (most reliable)
    // Show approved/rejected requests from the last 30 days (or all if updated_at is recent)
    // Use BINARY comparison to ensure exact match, but also try case-insensitive as fallback
    $qr_status_sql = "SELECT 'qr_request_status' as type, qr.id, it.table_name as item_name, qr.status, qr.updated_at, qr.created_at, qr.item_id,
                      CASE 
                          WHEN qr.item_id IS NOT NULL THEN 1
                          ELSE (SELECT COUNT(*) FROM items WHERE item_table_id = qr.item_table_id)
                      END as quantity,
                      CASE 
                          WHEN qr.status = 'approved' THEN 'qr_request_approved'
                          WHEN qr.status = 'rejected' THEN 'qr_request_rejected'
                          ELSE 'other'
                      END as notification_type
               FROM qr_requests qr
               LEFT JOIN item_tables it ON qr.item_table_id = it.id
               WHERE (qr.requested_by = ? OR LOWER(qr.requested_by) = LOWER(?))
               AND qr.status IN ('approved', 'rejected')
               AND (qr.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                    OR qr.updated_at IS NULL 
                    OR qr.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
               ORDER BY COALESCE(qr.updated_at, qr.created_at) DESC
               LIMIT 50";
    
    $stmt = $conn->prepare($qr_status_sql);
    if ($stmt) {
        $stmt->bind_param("ss", $username, $username); // Bind twice for both conditions
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            
            $qr_request_ids = []; // Track IDs to avoid duplicates
            $qr_found_count = 0;
            
            while ($row = $result->fetch_assoc()) {
                // Only add if not already in array (avoid duplicates)
                if (!in_array($row['id'], $qr_request_ids)) {
                    $qr_request_ids[] = $row['id'];
                    $notifications[] = $row;
                    $total_count++;
                    $qr_found_count++;
                }
            }
            
            // Debug logging for all users (not just department heads) to see what's happening
            error_log("User ({$username}) QR notifications from qr_requests: Found {$qr_found_count} QR request status notifications");
            if ($qr_found_count > 0) {
                foreach ($notifications as $notif) {
                    if ($notif['type'] === 'qr_request_status') {
                        error_log("  - QR Notification: {$notif['notification_type']} for '{$notif['item_name']}' (Status: {$notif['status']})");
                    }
                }
            }
            
            // Debug logging for department heads
            if ($isAdmin && !$isSuperAdmin) {
                error_log("Department Head ({$username}) QR notifications: Found {$qr_found_count} QR request status notifications from qr_requests table");
            }
        } else {
            // Log error for debugging
            error_log("QR status query error for user {$username}: " . $stmt->error);
        }
        $stmt->close();
    } else {
        // Log error for debugging
        error_log("Failed to prepare QR status query for user {$username}: " . $conn->error);
    }
    
    // Also get from notifications table as backup (where notifications are explicitly stored)
    $checkNotificationsTable = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($checkNotificationsTable && $checkNotificationsTable->num_rows > 0) {
        // Get user_id for current user
        $userStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $userStmt->bind_param("s", $username);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        if ($userRow = $userResult->fetch_assoc()) {
            $userId = $userRow['id'];
            
            // Check if notifications table has user_id column
            $checkUserIdColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'user_id'");
            if ($checkUserIdColumn && $checkUserIdColumn->num_rows > 0) {
                // Query using user_id - remove is_read restriction to show all notifications
                $notifStmt = $conn->prepare("SELECT 'qr_request_status' as type, id, 
                                            CASE 
                                                WHEN type = 'qr_request_approved' THEN 'qr_request_approved'
                                                WHEN type = 'qr_request_rejected' THEN 'qr_request_rejected'
                                                ELSE 'other'
                                            END as notification_type,
                                            message as item_name, created_at as updated_at, created_at, 1 as quantity
                                            FROM notifications 
                                            WHERE user_id = ? 
                                            AND type IN ('qr_request_approved', 'qr_request_rejected')
                                            AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                            ORDER BY created_at DESC
                                            LIMIT 20");
                if ($notifStmt) {
                    $notifStmt->bind_param("i", $userId);
                    $notifStmt->execute();
                    $notifResult = $notifStmt->get_result();
                    
                    $notif_found_count = 0;
                    while ($notifRow = $notifResult->fetch_assoc()) {
                        // Extract table name from message
                        if (preg_match("/'([^']+)'/", $notifRow['item_name'], $matches)) {
                            $notifRow['item_name'] = $matches[1];
                        }
                        // Check if we already have this notification from qr_requests table
                        $is_duplicate = false;
                        foreach ($notifications as $existing_notif) {
                            if (isset($existing_notif['type']) && $existing_notif['type'] === 'qr_request_status' 
                                && isset($existing_notif['item_name']) && $existing_notif['item_name'] === $notifRow['item_name']
                                && isset($existing_notif['notification_type']) && $existing_notif['notification_type'] === $notifRow['notification_type']) {
                                $is_duplicate = true;
                                break;
                            }
                        }
                        if (!$is_duplicate) {
                            $notifications[] = $notifRow;
                            $total_count++;
                            $notif_found_count++;
                        }
                    }
                    
                    // Debug logging for all users
                    error_log("User ({$username}) QR notifications from notifications table (user_id): Found {$notif_found_count} notifications");
                    if ($notif_found_count > 0) {
                        error_log("  - User ID: {$userId}, Username: {$username}");
                    }
                    
                    // Debug logging for department heads
                    if ($isAdmin && !$isSuperAdmin) {
                        error_log("Department Head ({$username}) QR notifications: Found {$notif_found_count} QR request status notifications from notifications table (user_id)");
                    }
                    
                    $notifStmt->close();
                }
            } else {
                // Fallback: Query using username if user_id column doesn't exist
                $checkUsernameColumn = $conn->query("SHOW COLUMNS FROM notifications LIKE 'username'");
                if ($checkUsernameColumn && $checkUsernameColumn->num_rows > 0) {
                    $notifStmt = $conn->prepare("SELECT 'qr_request_status' as type, id, 
                                                CASE 
                                                    WHEN type = 'qr_request_approved' THEN 'qr_request_approved'
                                                    WHEN type = 'qr_request_rejected' THEN 'qr_request_rejected'
                                                    ELSE 'other'
                                                END as notification_type,
                                                message as item_name, created_at as updated_at, created_at, 1 as quantity
                                                FROM notifications 
                                                WHERE username = ? 
                                                AND type IN ('qr_request_approved', 'qr_request_rejected')
                                                AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                                ORDER BY created_at DESC
                                                LIMIT 20");
                    if ($notifStmt) {
                        $notifStmt->bind_param("s", $username);
                        $notifStmt->execute();
                        $notifResult = $notifStmt->get_result();
                        
                        $notif_found_count = 0;
                        while ($notifRow = $notifResult->fetch_assoc()) {
                            // Extract table name from message
                            if (preg_match("/'([^']+)'/", $notifRow['item_name'], $matches)) {
                                $notifRow['item_name'] = $matches[1];
                            }
                            // Check if we already have this notification from qr_requests table
                            $is_duplicate = false;
                            foreach ($notifications as $existing_notif) {
                                if (isset($existing_notif['type']) && $existing_notif['type'] === 'qr_request_status' 
                                    && isset($existing_notif['item_name']) && $existing_notif['item_name'] === $notifRow['item_name']
                                    && isset($existing_notif['notification_type']) && $existing_notif['notification_type'] === $notifRow['notification_type']) {
                                    $is_duplicate = true;
                                    break;
                                }
                            }
                            if (!$is_duplicate) {
                                $notifications[] = $notifRow;
                                $total_count++;
                                $notif_found_count++;
                            }
                        }
                        
                        // Debug logging for department heads
                        if ($isAdmin && !$isSuperAdmin) {
                            error_log("Department Head ({$username}) QR notifications: Found {$notif_found_count} QR request status notifications from notifications table (username)");
                        }
                        
                        $notifStmt->close();
                    }
                }
            }
        }
        if (isset($userStmt)) {
            $userStmt->close();
        }
    }
    } // end !isViewer block for QR request notifications
    
    // 4. Get item request status notifications
    // For regular users and department heads: show approved/rejected/fulfilled requests for their own requests
    // For super admin only: show new pending requests from all users AND status updates for their own requests
    if ($isSuperAdmin) {
        // Super Admin: Get new pending requests from the last 7 days
        $pending_request_sql = "SELECT 'new_request' as type, id, item_name, status, created_at, date_needed, requested_by,
                                CASE 
                                    WHEN status = 'pending' THEN 'new_request'
                                    ELSE 'other'
                                END as notification_type
                         FROM item_requests 
                         WHERE status = 'pending'
                         AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                         ORDER BY created_at DESC";
        
        $stmt = $conn->prepare($pending_request_sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
            $total_count++;
        }
        
        // Also get status updates for requests made by super admin themselves
        $request_sql = "SELECT 'request_status' as type, id, item_name, status, updated_at, date_needed, created_at,
                               CASE 
                                   WHEN status = 'approved' THEN 'approved'
                                   WHEN status = 'rejected' THEN 'rejected'
                                   WHEN status = 'fulfilled' THEN 'fulfilled'
                                   ELSE 'other'
                               END as notification_type
                        FROM item_requests 
                        WHERE requested_by = ? 
                        AND status IN ('approved', 'rejected', 'fulfilled')
                        AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        ORDER BY updated_at DESC";
        
        $stmt = $conn->prepare($request_sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
            $total_count++;
        }
    } else {
        // Regular users and department heads: Show approved/rejected/fulfilled requests for their own requests
        $request_sql = "SELECT 'request_status' as type, id, item_name, status, updated_at, date_needed, created_at,
                               CASE 
                                   WHEN status = 'approved' THEN 'approved'
                                   WHEN status = 'rejected' THEN 'rejected'
                                   WHEN status = 'fulfilled' THEN 'fulfilled'
                                   ELSE 'other'
                               END as notification_type
                        FROM item_requests 
                        WHERE requested_by = ? 
                        AND status IN ('approved', 'rejected', 'fulfilled')
                        AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        ORDER BY updated_at DESC";
        
        $stmt = $conn->prepare($request_sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
            $total_count++;
        }
    }
    
    // Debug: Log QR request notifications found
    $qr_notif_count = 0;
    foreach ($notifications as $notif) {
        if ($notif['type'] === 'qr_request_status') {
            $qr_notif_count++;
        }
    }
    
    // Enhanced debugging for department heads
    if ($isAdmin && !$isSuperAdmin) {
        error_log("Department Head ({$username}) - Total QR request notifications found: {$qr_notif_count}");
        if ($qr_notif_count > 0) {
            foreach ($notifications as $notif) {
                if ($notif['type'] === 'qr_request_status') {
                    error_log("  - QR Notification: {$notif['notification_type']} for '{$notif['item_name']}' (Status: {$notif['status']})");
                }
            }
        }
    } else if ($qr_notif_count > 0) {
        error_log("Found {$qr_notif_count} QR request notifications for user: {$username}");
    }
    
    // Sort notifications by priority and date
    usort($notifications, function($a, $b) {
        // Priority order: overdue > due_today > due_tomorrow > due_soon > borrow_approved > borrow_declined > new_qr_request > new_request > qr_request_approved > approved > fulfilled > qr_request_rejected > rejected
        $priority_order = [
            'overdue' => 1,
            'due_today' => 2,
            'due_tomorrow' => 3,
            'due_soon' => 4,
            'borrow_approved' => 5,
            'borrow_declined' => 6,
            'new_qr_request' => 7,
            'new_request' => 8,
            'qr_request_approved' => 9,
            'approved' => 10,
            'fulfilled' => 11,
            'qr_request_rejected' => 12,
            'rejected' => 13
        ];
        
        $a_priority = $priority_order[$a['urgency_level'] ?? $a['notification_type']] ?? 7;
        $b_priority = $priority_order[$b['urgency_level'] ?? $b['notification_type']] ?? 7;
        
        if ($a_priority !== $b_priority) {
            return $a_priority - $b_priority;
        }
        
        // If same priority, sort by date (most recent first for requests/borrow status, earliest due first for borrows)
        if ($a['type'] === 'request_status' || $a['type'] === 'new_request' || $a['type'] === 'borrow_status' || $a['type'] === 'qr_request_status' || $a['type'] === 'new_qr_request') {
            $a_date = $a['updated_at'] ?? $a['created_at'] ?? '';
            $b_date = $b['updated_at'] ?? $b['created_at'] ?? '';
            return strtotime($b_date) - strtotime($a_date);
        } else {
            return strtotime($a['due_date']) - strtotime($b['due_date']);
        }
    });
    
    // Limit to most recent 10 notifications
    $notifications = array_slice($notifications, 0, 10);
    
    // Count by type for summary
    $due_date_count = 0;
    $request_count = 0;
    $borrow_status_count = 0;
    $overdue_count = 0;
    $qr_request_count = 0;
    
    foreach ($notifications as $notification) {
        if ($notification['type'] === 'due_date') {
            $due_date_count++;
            if ($notification['urgency_level'] === 'overdue') {
                $overdue_count++;
            }
        } elseif ($notification['type'] === 'request_status' || $notification['type'] === 'new_request') {
            $request_count++;
        } elseif ($notification['type'] === 'borrow_status') {
            $borrow_status_count++;
        } elseif ($notification['type'] === 'qr_request_status' || $notification['type'] === 'new_qr_request') {
            $qr_request_count++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'count' => (int)$total_count,
        'due_date_count' => (int)$due_date_count,
        'request_count' => (int)$request_count,
        'borrow_status_count' => (int)$borrow_status_count,
        'overdue_count' => (int)$overdue_count,
        'qr_request_count' => (int)$qr_request_count,
        'notifications' => $notifications
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

<?php
session_start();
require_once '../db_connect.php';

// Check if user is logged in and is admin or super admin
$is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$user_department = isset($_SESSION['department']) ? trim($_SESSION['department']) : '';

// Distinguish roles
$is_department_head = $is_admin && !$is_super_admin && $user_department !== '';
$is_general_admin = $is_admin && !$is_super_admin && $user_department === '';
$is_qr_reviewer = $is_super_admin || $is_general_admin;

if (!isset($_SESSION['username']) || (!$is_admin && !$is_super_admin)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    // Update overdue status for active borrows
    $update_overdue = "UPDATE borrow_history SET status = 'overdue', priority = 'high' WHERE status = 'active' AND due_date < CURDATE()";
    $conn->query($update_overdue);
    
    // Get count of pending item requests (only for super admins, not for department heads)
    $request_count = 0;
    $requests = [];
    if ($is_qr_reviewer) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM item_requests WHERE status = 'pending'");
        $stmt->execute();
        $result = $stmt->get_result();
        $request_count = $result->fetch_assoc()['count'];
    }
    
    // Get count of pending QR requests (only for super admins)
    $qr_request_count = 0;
    $qr_requests = [];
    if ($is_qr_reviewer) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM qr_requests WHERE status = 'pending'");
        $stmt->execute();
        $result = $stmt->get_result();
        $qr_request_count = $result->fetch_assoc()['count'];
    }
    
    // Build department filter for borrow requests (only for non-super admins)
    $borrow_dept_filter = '';
    $borrow_dept_params = [];
    if ($is_department_head && !empty($user_department)) {
        $borrow_dept_filter = " AND department_name = ?";
        $borrow_dept_params[] = $user_department;
    }
    
    // Get count of pending borrow requests (from viewers/teachers) - filtered by department
    $borrow_count_sql = "SELECT COUNT(*) as count FROM borrow_history WHERE status = 'pending'" . $borrow_dept_filter;
    $stmt = $conn->prepare($borrow_count_sql);
    if (!empty($borrow_dept_params)) {
        $stmt->bind_param("s", $user_department);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $pending_borrow_count = $result->fetch_assoc()['count'];
    
    // Get count of overdue borrows - filtered by department
    $overdue_count_sql = "SELECT COUNT(*) as count FROM borrow_history WHERE status = 'overdue'" . $borrow_dept_filter;
    $stmt = $conn->prepare($overdue_count_sql);
    if (!empty($borrow_dept_params)) {
        $stmt->bind_param("s", $user_department);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $overdue_count = $result->fetch_assoc()['count'];
    
    // Get count of item request status notifications for department heads (their own requests)
    $item_request_status_count = 0;
    $qr_request_status_count = 0;
    if ($is_department_head) {
        $username = $_SESSION['username'];
        $status_count_sql = "SELECT COUNT(*) as count FROM item_requests 
                            WHERE requested_by = ? 
                            AND status IN ('approved', 'rejected', 'fulfilled')
                            AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $stmt = $conn->prepare($status_count_sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $item_request_status_count = $result->fetch_assoc()['count'];
        
        // Get count of QR request status notifications for department heads
        $qr_status_count_sql = "SELECT COUNT(*) as count FROM qr_requests 
                               WHERE (requested_by = ? OR LOWER(requested_by) = LOWER(?))
                               AND status IN ('approved', 'rejected')
                               AND (updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                                    OR updated_at IS NULL 
                                    OR created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))";
        $stmt = $conn->prepare($qr_status_count_sql);
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $qr_request_status_count = $result->fetch_assoc()['count'];
    }
    
    // Total notification count
    $total_count = $request_count + $qr_request_count + $pending_borrow_count + $overdue_count + $item_request_status_count + $qr_request_status_count;
    
    // Get recent pending item requests (last 3) - only for super admins
    if ($is_qr_reviewer) {
        $stmt = $conn->prepare("SELECT 'request' as type, id, requested_by as name, department_name, item_name, quantity, created_at FROM item_requests WHERE status = 'pending' ORDER BY created_at DESC LIMIT 3");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
    }
    
    // Get recent pending QR requests (last 3) - only for super admins
    if ($is_qr_reviewer) {
        $stmt = $conn->prepare("SELECT 'qr_request' as type, qr.id, qr.requested_by as name, d.name as department_name, it.table_name as item_name, qr.item_id,
                                CASE 
                                    WHEN qr.item_id IS NOT NULL THEN 1
                                    ELSE (SELECT COUNT(*) FROM items WHERE item_table_id = qr.item_table_id)
                                END as quantity, qr.created_at 
                                FROM qr_requests qr 
                                LEFT JOIN item_tables it ON qr.item_table_id = it.id 
                                LEFT JOIN departments d ON it.department_id = d.id 
                                WHERE qr.status = 'pending' 
                                ORDER BY qr.created_at DESC 
                                LIMIT 3");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $qr_requests[] = $row;
        }
    }
    
    // Get recent pending borrow requests (last 3) - filtered by department
    $borrow_request_sql = "SELECT 'borrow_request' as type, id, borrow_id, borrower_name as name, department_name, item_name, quantity_borrowed as quantity, created_at FROM borrow_history WHERE status = 'pending'" . $borrow_dept_filter . " ORDER BY created_at DESC LIMIT 3";
    $stmt = $conn->prepare($borrow_request_sql);
    if (!empty($borrow_dept_params)) {
        $stmt->bind_param("s", $user_department);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $borrow_requests = [];
    while ($row = $result->fetch_assoc()) {
        $borrow_requests[] = $row;
    }
    
    // Get recent overdue borrows (last 3) - filtered by department
    $overdue_sql = "SELECT 'overdue' as type, id, borrower_name as name, department_name, item_name, quantity_borrowed as quantity, due_date, created_at FROM borrow_history WHERE status = 'overdue'" . $borrow_dept_filter . " ORDER BY due_date ASC LIMIT 3";
    $stmt = $conn->prepare($overdue_sql);
    if (!empty($borrow_dept_params)) {
        $stmt->bind_param("s", $user_department);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $overdues = [];
    while ($row = $result->fetch_assoc()) {
        $overdues[] = $row;
    }
    
    // Get item request status notifications for department heads (their own requests)
    // Department heads should see when their own item requests are approved/rejected/fulfilled
    $item_request_status = [];
    if ($is_department_head) {
        // Department head: Get their own item request status updates
        $username = $_SESSION['username'];
        $status_sql = "SELECT 'request_status' as type, id, item_name, status, updated_at, date_needed, created_at,
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
                      ORDER BY updated_at DESC
                      LIMIT 5";
        $stmt = $conn->prepare($status_sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $item_request_status[] = $row;
        }
    }
    
    // Get QR request status notifications for department heads (their own QR requests)
    // Department heads should see when their own QR requests are approved/rejected
    $qr_request_status = [];
    if ($is_department_head) {
        // Department head: Get their own QR request status updates
        $username = $_SESSION['username'];
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
                   LIMIT 10";
        $stmt = $conn->prepare($qr_status_sql);
        if ($stmt) {
            $stmt->bind_param("ss", $username, $username);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $qr_request_status[] = $row;
                }
                error_log("Department Head ({$username}) QR notifications: Found " . count($qr_request_status) . " QR request status notifications from qr_requests table");
            } else {
                error_log("QR status query error for department head {$username}: " . $stmt->error);
            }
            $stmt->close();
        }
        
        // Also get from notifications table if it exists
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
                                                LIMIT 10");
                    if ($notifStmt) {
                        $notifStmt->bind_param("i", $userId);
                        $notifStmt->execute();
                        $notifResult = $notifStmt->get_result();
                        
                        $notif_count = 0;
                        while ($notifRow = $notifResult->fetch_assoc()) {
                            // Extract table name from message
                            if (preg_match("/'([^']+)'/", $notifRow['item_name'], $matches)) {
                                $notifRow['item_name'] = $matches[1];
                            }
                            // Check if we already have this notification from qr_requests table
                            $is_duplicate = false;
                            foreach ($qr_request_status as $existing_notif) {
                                if (isset($existing_notif['item_name']) && $existing_notif['item_name'] === $notifRow['item_name']
                                    && isset($existing_notif['notification_type']) && $existing_notif['notification_type'] === $notifRow['notification_type']) {
                                    $is_duplicate = true;
                                    break;
                                }
                            }
                            if (!$is_duplicate) {
                                $qr_request_status[] = $notifRow;
                                $notif_count++;
                            }
                        }
                        error_log("Department Head ({$username}) QR notifications: Found {$notif_count} QR request status notifications from notifications table (user_id)");
                        $notifStmt->close();
                    }
                }
            }
            if (isset($userStmt)) {
                $userStmt->close();
            }
        }
    }
    
    // Combine and sort by date
    $all_notifications = array_merge($requests, $qr_requests, $borrow_requests, $overdues, $item_request_status, $qr_request_status);
    usort($all_notifications, function($a, $b) {
        // Use updated_at for request_status, created_at for others
        $a_date = isset($a['updated_at']) && !empty($a['updated_at']) ? $a['updated_at'] : $a['created_at'];
        $b_date = isset($b['updated_at']) && !empty($b['updated_at']) ? $b['updated_at'] : $b['created_at'];
        return strtotime($b_date) - strtotime($a_date);
    });
    
    // Take only the most recent 5
    $all_notifications = array_slice($all_notifications, 0, 5);
    
    echo json_encode([
        'success' => true,
        'count' => (int)$total_count,
        'request_count' => (int)$request_count + (int)$item_request_status_count, // Include item request status count for department heads
        'qr_request_count' => (int)$qr_request_count + (int)$qr_request_status_count, // Include QR request status count for department heads
        'pending_borrow_count' => (int)$pending_borrow_count,
        'overdue_count' => (int)$overdue_count,
        'notifications' => $all_notifications
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

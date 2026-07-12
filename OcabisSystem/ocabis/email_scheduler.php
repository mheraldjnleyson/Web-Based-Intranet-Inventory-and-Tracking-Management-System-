<?php
/**
 * Email Scheduler for OCABIS
 * Handles automatic email notifications for due dates and overdue items
 * This file should be run as a cron job or scheduled task
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/email_notifications.php';

// Suppress errors for cron execution
error_reporting(0);
ini_set('display_errors', 0);

try {
    $conn = @new mysqli('localhost', 'root', '', 'ocabis');
    
    if ($conn->connect_error) {
        error_log("Database connection failed in email scheduler: " . $conn->connect_error);
        exit(1);
    }
    
    $conn->set_charset("utf8");
    
    // Get current date
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $dayAfterTomorrow = date('Y-m-d', strtotime('+2 days'));
    $threeDaysFromNow = date('Y-m-d', strtotime('+3 days'));
    
    // Send due date reminders (1-3 days before due date)
    $reminderQuery = "SELECT * FROM borrow_history 
                      WHERE status = 'active' 
                      AND due_date IN (?, ?, ?)
                      AND borrower_email IS NOT NULL 
                      AND borrower_email != ''";
    
    $stmt = $conn->prepare($reminderQuery);
    $stmt->bind_param("sss", $tomorrow, $dayAfterTomorrow, $threeDaysFromNow);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $remindersSent = 0;
    $reminderErrors = 0;
    
    while ($row = $result->fetch_assoc()) {
        $dueDate = new DateTime($row['due_date']);
        $todayDate = new DateTime($today);
        $daysUntilDue = $todayDate->diff($dueDate)->days;
        
        // Only send reminder if it's 1-3 days before due date
        if ($daysUntilDue >= 1 && $daysUntilDue <= 3) {
            $success = sendDueDateReminderEmail(
                $row['borrower_email'],
                $row['borrower_name'],
                $row['item_name'],
                $row['due_date'],
                $daysUntilDue
            );
            
            if ($success) {
                $remindersSent++;
                error_log("Due date reminder sent to: " . $row['borrower_email'] . " for item: " . $row['item_name']);
            } else {
                $reminderErrors++;
                error_log("Failed to send due date reminder to: " . $row['borrower_email']);
            }
        }
    }
    
    // Update overdue items and send overdue emails
    $overdueQuery = "SELECT * FROM borrow_history 
                     WHERE status = 'active' 
                     AND due_date < ?
                     AND borrower_email IS NOT NULL 
                     AND borrower_email != ''";
    
    $stmt = $conn->prepare($overdueQuery);
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $overdueEmailsSent = 0;
    $overdueErrors = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Update status to overdue
        $updateQuery = "UPDATE borrow_history SET status = 'overdue' WHERE id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("i", $row['id']);
        $updateStmt->execute();
        
        // Calculate days overdue
        $dueDate = new DateTime($row['due_date']);
        $todayDate = new DateTime($today);
        $daysOverdue = $todayDate->diff($dueDate)->days;
        
        // Send overdue email
        $success = sendOverdueItemEmail(
            $row['borrower_email'],
            $row['borrower_name'],
            $row['item_name'],
            $row['due_date'],
            $daysOverdue
        );
        
        if ($success) {
            $overdueEmailsSent++;
            error_log("Overdue email sent to: " . $row['borrower_email'] . " for item: " . $row['item_name']);
        } else {
            $overdueErrors++;
            error_log("Failed to send overdue email to: " . $row['borrower_email']);
        }
    }
    
    // Log summary
    error_log("Email Scheduler Summary - Date: " . $today);
    error_log("Due date reminders sent: " . $remindersSent);
    error_log("Due date reminder errors: " . $reminderErrors);
    error_log("Overdue emails sent: " . $overdueEmailsSent);
    error_log("Overdue email errors: " . $overdueErrors);
    
    echo "Email scheduler completed successfully\n";
    echo "Due date reminders sent: " . $remindersSent . "\n";
    echo "Overdue emails sent: " . $overdueEmailsSent . "\n";
    
} catch (Exception $e) {
    error_log("Email scheduler error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>


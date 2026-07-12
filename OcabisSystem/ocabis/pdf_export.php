<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once '../db_connect.php';

// Include TCPDF library
if (!file_exists('tcpdf/tcpdf.php')) {
    die('TCPDF library not found. Please ensure the tcpdf folder is present in the ocabis directory.');
}
require_once('tcpdf/tcpdf.php');

class OCABISExport extends TCPDF {
    
    public $report_title = '';
    
    // Page header
    public function Header() {
        // Calculate page dimensions
        $leftMargin = 10;
        $rightMargin = 10;
        $pageWidth = $this->getPageWidth();
        $usableRight = $pageWidth - $rightMargin;

        // Top band
        $logo_path = 'assets/logo.png';
        if (file_exists($logo_path)) {
            $this->Image($logo_path, $leftMargin, 10, 20, 20, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }

        // Main title centered
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(0, 0, 0);
        $this->SetY(12);
        $this->Cell(0, 8, 'OCABIS - Inventory Management System', 0, 1, 'C');

        // Report title centered
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 7, $this->report_title, 0, 1, 'C');

        // Date/time right aligned on same header block
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(100, 100, 100);
        $this->SetY(27);
        $this->Cell(0, 5, 'Generated on: ' . date('F j, Y \a\t g:i A'), 0, 1, 'R');

        // Separator line spanning full width
        $this->Line($leftMargin, 35, $usableRight, 35);
    }
    
    // Page footer
    public function Footer() {
        // Position at 15 mm from bottom
        $this->SetY(-15);
        
        // Set font
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        
        // Page number
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        
        // Line
        $this->Line(10, $this->GetY() - 5, 200, $this->GetY() - 5);
    }
    
    public function setReportTitle($title) {
        $this->report_title = $title;
    }
}

// Get export type and parameters
$export_type = $_GET['type'] ?? 'users';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';

// Check if user is department head and restrict to their department
$is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$is_department_head = $is_admin && !$is_super_admin;
$user_department = isset($_SESSION['department']) ? trim($_SESSION['department']) : '';

// Get department ID for department heads
$user_department_id = null;
if ($is_department_head && !empty($user_department) && $export_type === 'dashboard') {
    $department = $user_department;
    // Get department ID for more accurate filtering
    $dept_id_stmt = $conn->prepare("SELECT id FROM departments WHERE name = ?");
    $dept_id_stmt->bind_param("s", $user_department);
    $dept_id_stmt->execute();
    $dept_id_result = $dept_id_stmt->get_result();
    if ($dept_id_row = $dept_id_result->fetch_assoc()) {
        $user_department_id = $dept_id_row['id'];
    }
    $dept_id_stmt->close();
}

try {
    // Create new PDF document
    // Force Landscape for wider tables
    $pdf = new OCABISExport('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('OCABIS System');
    $pdf->SetAuthor('OCABIS Administrator');
    $pdf->SetTitle('OCABIS Export Report');
    $pdf->SetSubject('Professional Export Report');
    
    // Set default header data
    $pdf->SetHeaderData('', 0, 'OCABIS Export Report', 'Inventory Management System');
    
    // Set header and footer fonts
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Set margins (more room for header, wider page)
    $pdf->SetMargins(PDF_MARGIN_LEFT, 40, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    
    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    
    // Add a page
    $pdf->AddPage();
    
    // Generate export based on type
    switch ($export_type) {
        case 'users':
            generateUsersExport($pdf, $conn, $date_from, $date_to, $department, $status);
            break;
        case 'item_requests':
            generateItemRequestsExport($pdf, $conn, $date_from, $date_to, $department, $status);
            break;
        case 'dashboard':
            generateDashboardExport($pdf, $conn, $department, $user_department_id);
            break;
        case 'borrow_history':
            generateBorrowHistoryExport($pdf, $conn, $date_from, $date_to, $department, $status);
            break;
        case 'inventory_table':
            $table_id = $_GET['table_id'] ?? null;
            generateInventoryTableExport($pdf, $conn, $table_id);
            break;
        default:
            generateUsersExport($pdf, $conn, $date_from, $date_to, $department, $status);
    }
    
    // Close and output PDF document
    $filename = 'OCABIS_' . ucfirst($export_type) . '_Export_' . date('Y-m-d_H-i-s') . '.pdf';
    $pdf->Output($filename, 'D');
    
} catch (Exception $e) {
    error_log("PDF Export Error: " . $e->getMessage());
    echo "Error generating PDF export: " . $e->getMessage();
}

function generateUsersExport($pdf, $conn, $date_from, $date_to, $department, $status) {
    $pdf->setReportTitle('User Management Export');
    
    // Report header (simple and readable)
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'USER MANAGEMENT EXPORT', 0, 1, 'C');
    $pdf->Ln(4);
    
    // Get user data
    $users = getUsersData($conn, $date_from, $date_to, $department, $status);
    
    // Summary statistics (simple)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'SUMMARY', 0, 1, 'L');
    $pdf->Ln(1);
    
    $total_users = count($users);
    $active_users = count(array_filter($users, function($user) { return $user['status'] === 'active'; }));
    $inactive_users = count(array_filter($users, function($user) { return $user['status'] === 'inactive'; }));
    $pending_users = count(array_filter($users, function($user) { return $user['approval_status'] === 'pending'; }));
    $approved_users = count(array_filter($users, function($user) { return $user['approval_status'] === 'approved'; }));
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Total Users: ' . $total_users, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Active: ' . $active_users . '    Inactive: ' . $inactive_users, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Approved: ' . $approved_users . '    Pending: ' . $pending_users, 0, 1, 'L');
    
    $pdf->Ln(10);
    
    // User details table (simple)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'USER DETAILS', 0, 1, 'L');
    $pdf->Ln(1);
    
    // Header
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(12, 8, 'ID', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Username', 1, 0, 'C', true);
    $pdf->Cell(55, 8, 'Email', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Department', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Created', 1, 1, 'C', true);
    
    // Rows
    $pdf->SetFont('helvetica', '', 9);
    foreach ($users as $user) {
        $pdf->Cell(12, 8, $user['id'], 1, 0, 'C');
        $pdf->Cell(35, 8, substr($user['username'], 0, 18), 1, 0, 'L');
        $pdf->Cell(55, 8, substr($user['email'], 0, 32), 1, 0, 'L');
        $pdf->Cell(35, 8, substr($user['department'], 0, 22), 1, 0, 'L');
        $pdf->Cell(20, 8, ucfirst($user['status']), 1, 0, 'C');
        $pdf->Cell(25, 8, date('m/d/Y', strtotime($user['created_at'])), 1, 1, 'C');
    }
}

function generateItemRequestsExport($pdf, $conn, $date_from, $date_to, $department, $status) {
    $pdf->setReportTitle('Item Requests Export');
    
    // Report header (simple)
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'ITEM REQUESTS EXPORT', 0, 1, 'C');
    $pdf->Ln(4);
    
    // Get item requests data
    $requests = getItemRequestsData($conn, $date_from, $date_to, $department, $status);
    
    // Summary (simple)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'SUMMARY', 0, 1, 'L');
    $pdf->Ln(1);
    
    $total_requests = count($requests);
    $pending_requests = count(array_filter($requests, function($req) { return $req['status'] === 'pending'; }));
    $approved_requests = count(array_filter($requests, function($req) { return $req['status'] === 'approved'; }));
    $rejected_requests = count(array_filter($requests, function($req) { return $req['status'] === 'rejected'; }));
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Total Requests: ' . $total_requests, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Pending Requests: ' . $pending_requests, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Approved Requests: ' . $approved_requests, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Rejected Requests: ' . $rejected_requests, 0, 1, 'L');
    
    $pdf->Ln(10);
    
    // Request details table (simple)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'REQUEST DETAILS', 0, 1, 'L');
    $pdf->Ln(1);
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(12, 8, 'ID', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Requester', 1, 0, 'C', true);
    $pdf->Cell(50, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Department', 1, 0, 'C', true);
    $pdf->Cell(12, 8, 'Qty', 1, 0, 'C', true);
    $pdf->Cell(22, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(24, 8, 'Created', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($requests as $request) {
        $pdf->Cell(12, 8, $request['id'], 1, 0, 'C');
        $pdf->Cell(35, 8, substr($request['requester_name'], 0, 22), 1, 0, 'L');
        $pdf->Cell(50, 8, substr($request['item_name'], 0, 30), 1, 0, 'L');
        $pdf->Cell(35, 8, substr($request['department'], 0, 22), 1, 0, 'L');
        $pdf->Cell(12, 8, $request['quantity'], 1, 0, 'C');
        $pdf->Cell(22, 8, ucfirst($request['status']), 1, 0, 'C');
        $pdf->Cell(24, 8, date('m/d/Y', strtotime($request['created_at'])), 1, 1, 'C');
    }
}

function generateDashboardExport($pdf, $conn, $department_filter = '', $department_id = null) {
    // Check if user is department head
    $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
    $is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
    $is_department_head = $is_admin && !$is_super_admin;
    $user_department = isset($_SESSION['department']) ? trim($_SESSION['department']) : '';
    
    // For department heads, use their department
    if ($is_department_head && !empty($user_department)) {
        $department_filter = $user_department;
    }
    
    $title = $department_filter ? "Dashboard Summary Export - {$department_filter}" : 'Dashboard Summary Export';
    $pdf->setReportTitle($title);
    
    // Report header (simple)
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'DASHBOARD SUMMARY EXPORT' . ($department_filter ? " - {$department_filter}" : ''), 0, 1, 'C');
    $pdf->Ln(4);
    
    // Get dashboard statistics (filtered by department if provided)
    $stats = getDashboardStats($conn, $department_filter, $department_id);
    
    // System overview
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'SYSTEM OVERVIEW' . ($department_filter ? " ({$department_filter})" : ''), 0, 1, 'L');
    $pdf->Ln(1);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Total Items: ' . $stats['total_items'], 0, 1, 'L');
    $pdf->Cell(0, 6, 'Working Items: ' . $stats['working_items'], 0, 1, 'L');
    $pdf->Cell(0, 6, 'Broken Items: ' . $stats['broken_items'], 0, 1, 'L');
    $pdf->Cell(0, 6, 'Under Maintenance: ' . $stats['maintenance_items'], 0, 1, 'L');
    $pdf->Cell(0, 6, 'Low Stock Items: ' . $stats['low_stock_items'], 0, 1, 'L');
    $pdf->Cell(0, 6, 'Active Borrows: ' . $stats['active_borrows'], 0, 1, 'L');
    $pdf->Cell(0, 6, 'Overdue Items: ' . $stats['overdue_items'], 0, 1, 'L');
    
    $pdf->Ln(10);
    
    // Department breakdown (only show filtered department if filter is applied)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'DEPARTMENT BREAKDOWN', 0, 1, 'L');
    $pdf->Ln(1);
    
    $dept_data = getDepartmentBreakdown($conn, $department_filter, $department_id);
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(70, 8, 'Department', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Total', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Working', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Borrowed', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Available', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($dept_data as $dept) {
        $pdf->Cell(70, 8, $dept['department'], 1, 0, 'L');
        $pdf->Cell(30, 8, $dept['total_items'], 1, 0, 'C');
        $pdf->Cell(30, 8, $dept['working_items'], 1, 0, 'C');
        $pdf->Cell(30, 8, $dept['borrowed_items'], 1, 0, 'C');
        $pdf->Cell(30, 8, $dept['available_items'], 1, 1, 'C');
    }
    
    // Add new page for items table
    $pdf->AddPage();
    
    // Get all items with status logic (similar to inventory table export)
    $checkConsumableColumn = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'is_consumable'");
    $hasConsumableColumn = $checkConsumableColumn && $checkConsumableColumn->num_rows > 0;
    
    // Build WHERE clause for department filter
    $items_where = "";
    $items_params = [];
    $items_param_types = "";
    
    if ($department_id) {
        $items_where = " WHERE i.department_id = ?";
        $items_params[] = $department_id;
        $items_param_types .= "i";
    } elseif ($department_filter) {
        $items_where = " WHERE d.name = ?";
        $items_params[] = $department_filter;
        $items_param_types .= "s";
    }
    
    $items_sql = "SELECT i.*, i.name, i.item_code, i.quantity, i.location, i.category,
                  d.name as department_name,
                  CASE 
                      WHEN EXISTS (
                          SELECT 1 FROM borrow_history bh 
                          WHERE bh.item_id = i.id 
                          AND bh.status IN ('approved', 'active', 'overdue', 'received')
                      ) THEN 'Borrowed'
                      WHEN EXISTS (
                          SELECT 1 FROM item_tables it 
                          WHERE it.id = i.item_table_id";
    
    if ($hasConsumableColumn) {
        $items_sql .= " AND COALESCE(it.is_consumable, 0) = 1";
    } else {
        $items_sql .= " AND 0 = 1";
    }
    
    $items_sql .= "                      ) THEN 'Consumable'
                      ELSE COALESCE(i.status, 'Working')
                  END as status,
                  i.status as original_status
                  FROM items i
                  LEFT JOIN departments d ON i.department_id = d.id" . $items_where . "
                  ORDER BY d.name ASC, i.name ASC, i.item_code ASC";
    
    $items_stmt = $conn->prepare($items_sql);
    if (!empty($items_params)) {
        $items_stmt->bind_param($items_param_types, ...$items_params);
    }
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    $items = [];
    while ($row = $items_result->fetch_assoc()) {
        $items[] = $row;
    }
    $items_stmt->close();
    
    // Items table
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'ALL ITEMS' . ($department_filter ? " ({$department_filter})" : ''), 0, 1, 'L');
    $pdf->Ln(1);
    
    if (count($items) === 0) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'No items found.', 0, 1, 'L');
    } else {
        // Table header - same format as inventory table export
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(15, 8, 'ID', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Item Code', 1, 0, 'C', true);
        $pdf->Cell(65, 8, 'Item Name', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'Department', 1, 0, 'C', true);
        $pdf->Cell(52, 8, 'Location', 1, 0, 'C', true);
        $pdf->Cell(22, 8, 'Quantity', 1, 0, 'C', true);
        $pdf->Cell(32, 8, 'Status', 1, 1, 'C', true);
        
        // Table rows
        $pdf->SetFont('helvetica', '', 9);
        foreach ($items as $item) {
            $pdf->Cell(15, 8, $item['id'], 1, 0, 'C');
            
            // Item Code
            $itemCode = $item['item_code'] ?? 'N/A';
            $pdf->Cell(40, 8, substr($itemCode, 0, 25), 1, 0, 'L');
            
            // Item Name
            $itemName = $item['name'] ?? 'N/A';
            $pdf->Cell(65, 8, substr($itemName, 0, 45), 1, 0, 'L');
            
            // Department - truncate long names like "Student Learning Resource Center"
            $department = $item['department_name'] ?? 'N/A';
            // Shorten "Student Learning Resource Center" to "SLRC" if needed
            if (strlen($department) > 20) {
                if (stripos($department, 'student learning resource center') !== false || stripos($department, 'slrc') !== false) {
                    $department = 'SLRC';
                } else {
                    $department = substr($department, 0, 20);
                }
            }
            $pdf->Cell(40, 8, substr($department, 0, 28), 1, 0, 'L');
            
            // Location
            $location = $item['location'] ?? 'N/A';
            $pdf->Cell(52, 8, substr($location, 0, 35), 1, 0, 'L');
            
            // Quantity
            $pdf->Cell(22, 8, $item['quantity'] ?? 0, 1, 0, 'C');
            
            // Status
            $status = $item['status'] ?? 'N/A';
            $pdf->Cell(32, 8, substr($status, 0, 20), 1, 1, 'C');
        }
        
        // Footer note
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 6, 'Note: Status is automatically determined based on borrow history and item table type.', 0, 1, 'L');
    }
}

function generateBorrowHistoryExport($pdf, $conn, $date_from, $date_to, $department, $status) {
    $pdf->setReportTitle('Borrow History Export');
    
    // Report header (simple)
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'BORROW HISTORY EXPORT', 0, 1, 'C');
    $pdf->Ln(4);
    
    // Get borrow history data
    $borrows = getBorrowHistoryData($conn, $date_from, $date_to, $department, $status);
    
    // Summary (simple)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'SUMMARY', 0, 1, 'L');
    $pdf->Ln(1);
    
    $total_borrows = count($borrows);
    $active_borrows = count(array_filter($borrows, function($borrow) { return $borrow['status'] === 'active'; }));
    $returned_borrows = count(array_filter($borrows, function($borrow) { return $borrow['status'] === 'returned'; }));
    $overdue_borrows = count(array_filter($borrows, function($borrow) { return $borrow['status'] === 'overdue'; }));
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Total Borrows: ' . $total_borrows, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Active Borrows: ' . $active_borrows, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Returned Items: ' . $returned_borrows, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Overdue Items: ' . $overdue_borrows, 0, 1, 'L');
    
    $pdf->Ln(10);
    
    // Borrow history table (simple)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'BORROW HISTORY DETAILS', 0, 1, 'L');
    $pdf->Ln(1);
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(22, 8, 'Borrow ID', 1, 0, 'C', true);
    $pdf->Cell(38, 8, 'Borrower', 1, 0, 'C', true);
    $pdf->Cell(42, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(28, 8, 'Department', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Borrow', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Due', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Return', 1, 0, 'C', true);
    $pdf->Cell(15, 8, 'Status', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($borrows as $borrow) {
        $pdf->Cell(22, 8, $borrow['borrow_id'], 1, 0, 'C');
        $pdf->Cell(38, 8, substr($borrow['borrower_name'], 0, 24), 1, 0, 'L');
        $pdf->Cell(42, 8, substr($borrow['item_name'], 0, 26), 1, 0, 'L');
        $pdf->Cell(28, 8, substr($borrow['department_name'], 0, 18), 1, 0, 'L');
        $pdf->Cell(20, 8, date('m/d/Y', strtotime($borrow['borrow_date'])), 1, 0, 'C');
        $pdf->Cell(20, 8, date('m/d/Y', strtotime($borrow['due_date'])), 1, 0, 'C');
        $pdf->Cell(20, 8, $borrow['return_date'] ? date('m/d/Y', strtotime($borrow['return_date'])) : '-', 1, 0, 'C');
        $pdf->Cell(15, 8, ucfirst($borrow['status']), 1, 1, 'C');
    }
}

// Helper functions to get data from database
function getUsersData($conn, $date_from, $date_to, $department, $status) {
    $where_conditions = [];
    $params = [];
    $param_types = '';
    
    if ($date_from) {
        $where_conditions[] = "created_at >= ?";
        $params[] = $date_from;
        $param_types .= 's';
    }
    
    if ($date_to) {
        $where_conditions[] = "created_at <= ?";
        $params[] = $date_to;
        $param_types .= 's';
    }
    
    if ($department) {
        $where_conditions[] = "department = ?";
        $params[] = $department;
        $param_types .= 's';
    }
    
    if ($status) {
        $where_conditions[] = "status = ?";
        $params[] = $status;
        $param_types .= 's';
    }
    
    $query = "SELECT id, username, email, department, status, approval_status, created_at 
              FROM users";
    
    if (!empty($where_conditions)) {
        $query .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $query .= " ORDER BY created_at DESC";
    
    try {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Failed to prepare users query: " . $conn->error);
            return [];
        }
        
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        
        if (!$stmt->execute()) {
            error_log("Failed to execute users query: " . $stmt->error);
            return [];
        }
        
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    } catch (Exception $e) {
        error_log("Error in getUsersData: " . $e->getMessage());
        return [];
    }
}

function getItemRequestsData($conn, $date_from, $date_to, $department, $status) {
    $where_conditions = [];
    $params = [];
    $param_types = '';
    
    if ($date_from) {
        $where_conditions[] = "created_at >= ?";
        $params[] = $date_from;
        $param_types .= 's';
    }
    
    if ($date_to) {
        $where_conditions[] = "created_at <= ?";
        $params[] = $date_to;
        $param_types .= 's';
    }
    
    if ($department) {
        $where_conditions[] = "department_name = ?";
        $params[] = $department;
        $param_types .= 's';
    }
    
    if ($status) {
        $where_conditions[] = "status = ?";
        $params[] = $status;
        $param_types .= 's';
    }
    
    $query = "SELECT id, requested_by as requester_name, item_name, department_name as department, quantity, status, created_at 
              FROM item_requests";
    
    if (!empty($where_conditions)) {
        $query .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $query .= " ORDER BY created_at DESC";
    
    try {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Failed to prepare item_requests query: " . $conn->error);
            return [];
        }
        
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        
        if (!$stmt->execute()) {
            error_log("Failed to execute item_requests query: " . $stmt->error);
            return [];
        }
        
        $result = $stmt->get_result();
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    } catch (Exception $e) {
        error_log("Error in getItemRequestsData: " . $e->getMessage());
        return [];
    }
}

function getDashboardStats($conn, $department_filter = '', $department_id = null) {
    $stats = [];
    
    // Use department_id if available (more accurate), otherwise use department name
    $dept_where = '';
    
    if (!empty($department_id)) {
        // Use department_id for direct filtering (more accurate)
        $dept_where = " WHERE i.department_id = ?";
    } elseif (!empty($department_filter)) {
        // Fallback to department name filtering
        $dept_where = " WHERE i.department_id IN (SELECT id FROM departments WHERE name = ?)";
    }
    
    // Total items
    $query = "SELECT COUNT(*) as count FROM items i" . $dept_where;
    $stmt = $conn->prepare($query);
    if (!empty($department_id)) {
        $stmt->bind_param("i", $department_id);
    } elseif (!empty($department_filter)) {
        $stmt->bind_param("s", $department_filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['total_items'] = $result->fetch_assoc()['count'];
    
    // Working items
    $query = "SELECT COUNT(*) as count FROM items i" . $dept_where . ($dept_where ? " AND" : " WHERE") . " i.status = 'Working'";
    $stmt = $conn->prepare($query);
    if (!empty($department_id)) {
        $stmt->bind_param("i", $department_id);
    } elseif (!empty($department_filter)) {
        $stmt->bind_param("s", $department_filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['working_items'] = $result->fetch_assoc()['count'];
    
    // Broken items
    $query = "SELECT COUNT(*) as count FROM items i" . $dept_where . ($dept_where ? " AND" : " WHERE") . " i.status = 'Broken'";
    $stmt = $conn->prepare($query);
    if (!empty($department_id)) {
        $stmt->bind_param("i", $department_id);
    } elseif (!empty($department_filter)) {
        $stmt->bind_param("s", $department_filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['broken_items'] = $result->fetch_assoc()['count'];
    
    // Maintenance items
    $query = "SELECT COUNT(*) as count FROM items i" . $dept_where . ($dept_where ? " AND" : " WHERE") . " i.status = 'Under Maintenance'";
    $stmt = $conn->prepare($query);
    if (!empty($department_id)) {
        $stmt->bind_param("i", $department_id);
    } elseif (!empty($department_filter)) {
        $stmt->bind_param("s", $department_filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['maintenance_items'] = $result->fetch_assoc()['count'];
    
    // Low stock items
    $query = "SELECT COUNT(*) as count FROM items i" . $dept_where . ($dept_where ? " AND" : " WHERE") . " i.quantity <= 5 AND i.status = 'Working'";
    $stmt = $conn->prepare($query);
    if (!empty($department_id)) {
        $stmt->bind_param("i", $department_id);
    } elseif (!empty($department_filter)) {
        $stmt->bind_param("s", $department_filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['low_stock_items'] = $result->fetch_assoc()['count'];
    
    // Active borrows (filtered by department if provided)
    $borrow_join = '';
    $borrow_where = " WHERE bh.status = 'active'";
    if (!empty($department_filter)) {
        $borrow_where .= " AND bh.department_name = ?";
    }
    $query = "SELECT COUNT(*) as count FROM borrow_history bh" . $borrow_where;
    $stmt = $conn->prepare($query);
    if (!empty($department_filter)) {
        $stmt->bind_param("s", $department_filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['active_borrows'] = $result->fetch_assoc()['count'];
    
    // Overdue items (filtered by department if provided)
    $borrow_where = " WHERE bh.status = 'overdue'";
    if (!empty($department_filter)) {
        $borrow_where .= " AND bh.department_name = ?";
    }
    $query = "SELECT COUNT(*) as count FROM borrow_history bh" . $borrow_where;
    $stmt = $conn->prepare($query);
    if (!empty($department_filter)) {
        $stmt->bind_param("s", $department_filter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['overdue_items'] = $result->fetch_assoc()['count'];
    
    return $stats;
}

function getDepartmentBreakdown($conn, $department_filter = '', $department_id = null) {
    // Use department_id if available (more accurate), otherwise use department name
    $where_clause = '';
    
    if (!empty($department_id)) {
        // Use department_id for direct filtering (more accurate)
        $where_clause = " WHERE d.id = ?";
    } elseif (!empty($department_filter)) {
        // Fallback to department name filtering
        $where_clause = " WHERE d.name = ?";
    }
    
    $query = "SELECT d.name as department, 
                     COUNT(DISTINCT i.id) as total_items,
                     SUM(CASE WHEN i.status = 'Working' THEN 1 ELSE 0 END) as working_items,
                     COUNT(DISTINCT CASE WHEN bh.status = 'active' THEN bh.item_id ELSE NULL END) as borrowed_items,
                     SUM(CASE WHEN i.status = 'Working' AND (bh.status IS NULL OR bh.status != 'active') THEN 1 ELSE 0 END) as available_items
              FROM departments d
              LEFT JOIN items i ON d.id = i.department_id
              LEFT JOIN borrow_history bh ON i.id = bh.item_id AND bh.status = 'active'" . $where_clause . "
              GROUP BY d.id, d.name
              ORDER BY d.name";
    
    if (!empty($department_id)) {
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $department_id);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            error_log("Failed to prepare department breakdown query: " . $conn->error);
            return [];
        }
    } elseif (!empty($department_filter)) {
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("s", $department_filter);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            error_log("Failed to prepare department breakdown query: " . $conn->error);
            return [];
        }
    } else {
        $result = $conn->query($query);
    }
    
    if (!$result) {
        error_log("Failed to execute department breakdown query: " . $conn->error);
        return [];
    }
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    return $data;
}

function getBorrowHistoryData($conn, $date_from, $date_to, $department, $status) {
    $where_conditions = [];
    $params = [];
    $param_types = '';
    
    if ($date_from) {
        $where_conditions[] = "bh.borrow_date >= ?";
        $params[] = $date_from;
        $param_types .= 's';
    }
    
    if ($date_to) {
        $where_conditions[] = "bh.borrow_date <= ?";
        $params[] = $date_to;
        $param_types .= 's';
    }
    
    if ($department) {
        $where_conditions[] = "bh.department_name = ?";
        $params[] = $department;
        $param_types .= 's';
    }
    
    if ($status) {
        $where_conditions[] = "bh.status = ?";
        $params[] = $status;
        $param_types .= 's';
    }
    
    $query = "SELECT bh.*, i.name as item_name 
              FROM borrow_history bh 
              LEFT JOIN items i ON bh.item_id = i.id";
    
    if (!empty($where_conditions)) {
        $query .= " WHERE " . implode(" AND ", $where_conditions);
    }
    
    $query .= " ORDER BY bh.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    return $data;
}

function generateInventoryTableExport($pdf, $conn, $table_id) {
    if (!$table_id) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Error: Item table ID is required', 0, 1, 'C');
        return;
    }
    
    // Get item table information
    $table_sql = "SELECT it.*, d.name as department_name 
                  FROM item_tables it 
                  LEFT JOIN departments d ON it.department_id = d.id 
                  WHERE it.id = ?";
    $table_stmt = $conn->prepare($table_sql);
    $table_stmt->bind_param("i", $table_id);
    $table_stmt->execute();
    $table_result = $table_stmt->get_result();
    
    if ($table_result->num_rows === 0) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 10, 'Error: Item table not found', 0, 1, 'C');
        $table_stmt->close();
        return;
    }
    
    $item_table = $table_result->fetch_assoc();
    $table_stmt->close();
    
    // Set report title
    $title = 'Inventory Table: ' . $item_table['table_name'];
    $pdf->setReportTitle($title);
    
    // Report header
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'INVENTORY TABLE REPORT', 0, 1, 'C');
    $pdf->Ln(4);
    
    // Table information
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'TABLE INFORMATION', 0, 1, 'L');
    $pdf->Ln(1);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(50, 6, 'Table Name:', 0, 0, 'L');
    $pdf->Cell(0, 6, $item_table['table_name'] ?? 'N/A', 0, 1, 'L');
    
    $pdf->Cell(50, 6, 'Department:', 0, 0, 'L');
    $pdf->Cell(0, 6, $item_table['department_name'] ?? 'N/A', 0, 1, 'L');
    
    if (!empty($item_table['category'])) {
        $pdf->Cell(50, 6, 'Category:', 0, 0, 'L');
        // Truncate long category names
        $category = $item_table['category'];
        if (strlen($category) > 50) {
            $category = substr($category, 0, 47) . '...';
        }
        $pdf->Cell(0, 6, $category, 0, 1, 'L');
    }
    
    if (!empty($item_table['description'])) {
        $pdf->Cell(50, 6, 'Description:', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 6, $item_table['description'], 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
    }
    
    $pdf->Ln(10);
    
    // Get items with status logic (same as API)
    $checkConsumableColumn = $conn->query("SHOW COLUMNS FROM item_tables LIKE 'is_consumable'");
    $hasConsumableColumn = $checkConsumableColumn && $checkConsumableColumn->num_rows > 0;
    
    $items_sql = "SELECT i.*, i.name, i.item_code, i.quantity, i.location, i.category,
                  CASE 
                      WHEN EXISTS (
                          SELECT 1 FROM borrow_history bh 
                          WHERE bh.item_id = i.id 
                          AND bh.status IN ('approved', 'active', 'overdue', 'received')
                      ) THEN 'Borrowed'
                      WHEN EXISTS (
                          SELECT 1 FROM item_tables it 
                          WHERE it.id = i.item_table_id";
    
    if ($hasConsumableColumn) {
        $items_sql .= " AND COALESCE(it.is_consumable, 0) = 1";
    } else {
        $items_sql .= " AND 0 = 1";
    }
    
    $items_sql .= "                      ) THEN 'Consumable'
                      ELSE COALESCE(i.status, 'Working')
                  END as status,
                  i.status as original_status
                  FROM items i
                  WHERE i.item_table_id = ?
                  ORDER BY i.name ASC, i.item_code ASC";
    
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("i", $table_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    
    $items = [];
    while ($row = $items_result->fetch_assoc()) {
        $items[] = $row;
    }
    $items_stmt->close();
    
    // Summary statistics
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'SUMMARY', 0, 1, 'L');
    $pdf->Ln(1);
    
    $total_items = count($items);
    $total_quantity = array_sum(array_column($items, 'quantity'));
    $borrowed_count = count(array_filter($items, function($item) { return $item['status'] === 'Borrowed'; }));
    $consumable_count = count(array_filter($items, function($item) { return $item['status'] === 'Consumable'; }));
    $working_count = count(array_filter($items, function($item) { return $item['status'] === 'Working'; }));
    $broken_count = count(array_filter($items, function($item) { return in_array($item['status'], ['Broken', 'Lost']); }));
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Total Items: ' . $total_items, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Total Quantity: ' . $total_quantity, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Working: ' . $working_count . '    Borrowed: ' . $borrowed_count . '    Consumable: ' . $consumable_count, 0, 1, 'L');
    $pdf->Cell(0, 6, 'Broken/Lost: ' . $broken_count, 0, 1, 'L');
    
    $pdf->Ln(10);
    
    // Items table
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'ITEMS IN TABLE', 0, 1, 'L');
    $pdf->Ln(1);
    
    // Table header - Adjusted column widths for landscape (total ~270mm)
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(15, 8, 'ID', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Item Code', 1, 0, 'C', true);
    $pdf->Cell(65, 8, 'Item Name', 1, 0, 'C', true);
    $pdf->Cell(52, 8, 'Location', 1, 0, 'C', true);
    $pdf->Cell(22, 8, 'Quantity', 1, 0, 'C', true);
    $pdf->Cell(32, 8, 'Status', 1, 1, 'C', true);
    
    // Table rows - Use MultiCell for longer text that might wrap
    $pdf->SetFont('helvetica', '', 9);
    foreach ($items as $item) {
        $startY = $pdf->GetY();
        $maxHeight = 8;
        
        // ID
        $pdf->Cell(15, 8, $item['id'], 1, 0, 'C');
        
        // Item Code - wider column
        $itemCode = $item['item_code'] ?? 'N/A';
        $pdf->Cell(40, 8, substr($itemCode, 0, 25), 1, 0, 'L');
        
        // Item Name
        $itemName = $item['name'] ?? 'N/A';
        $pdf->Cell(65, 8, substr($itemName, 0, 45), 1, 0, 'L');
        
        // Location - wider column
        $location = $item['location'] ?? 'N/A';
        $pdf->Cell(52, 8, substr($location, 0, 35), 1, 0, 'L');
        
        // Quantity
        $pdf->Cell(22, 8, $item['quantity'] ?? 0, 1, 0, 'C');
        
        // Status
        $status = $item['status'] ?? 'N/A';
        $pdf->Cell(32, 8, substr($status, 0, 20), 1, 1, 'C');
    }
    
    // Footer note
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'Note: Status is automatically determined based on borrow history and item table type.', 0, 1, 'L');
}
?>

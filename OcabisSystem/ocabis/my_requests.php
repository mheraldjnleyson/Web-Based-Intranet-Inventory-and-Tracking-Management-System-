<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once '../db_connect.php';

$username = $_SESSION['username'];
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;

// Get user's item requests
$requests = [];
try {
    $stmt = $conn->prepare("SELECT id, item_name, category, quantity, notes, status, created_at, updated_at FROM item_requests WHERE requested_by = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    $error_message = "Error loading requests: " . $e->getMessage();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link rel="shortcut icon" type="image/png" href="assets/logo.png">
    <title>My Item Requests - OCABIS</title>
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        .requests-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .requests-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 24px;
            text-align: center;
        }

        .requests-header h1 {
            margin: 0 0 8px 0;
            font-size: 28px;
        }

        .requests-header p {
            margin: 0;
            opacity: 0.9;
        }

        .requests-content {
            padding: 24px;
        }

        .request-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }

        .request-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .request-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .request-item-name {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .request-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .request-status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .request-status.approved {
            background: #d1fae5;
            color: #065f46;
        }

        .request-status.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .request-status.fulfilled {
            background: #dbeafe;
            color: #1e40af;
        }

        .request-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }

        .request-detail {
            display: flex;
            flex-direction: column;
        }

        .request-detail-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .request-detail-value {
            font-size: 14px;
            color: #374151;
        }

        .request-notes {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            margin-top: 12px;
        }

        .request-notes-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 4px;
        }

        .request-notes-content {
            font-size: 14px;
            color: #374151;
            line-height: 1.5;
        }

        .no-requests {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .no-requests-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .no-requests h3 {
            margin: 0 0 8px 0;
            color: #374151;
        }

        .no-requests p {
            margin: 0;
        }

        .request-actions {
            margin-top: 16px;
            display: flex;
            gap: 8px;
        }

        .request-action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .request-action-btn.primary {
            background: #667eea;
            color: white;
        }

        .request-action-btn.primary:hover {
            background: #5a67d8;
        }

        .request-action-btn.secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .request-action-btn.secondary:hover {
            background: #cbd5e0;
        }

        @media (max-width: 768px) {
            .request-item-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .request-details {
                grid-template-columns: 1fr;
            }

            .request-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>">
    <div class="sidebar">
        <div class="logo">
            <div class="logo-top" style="display: flex; align-items: center; gap: 10px;">
                <div class="logo-icon">
                    <img src="image/image-removebg-preview.png" alt="Logo" style="height: 50px; width: auto;">
                </div>
                <h1 style="margin: 0; flex: 1;">CABIS</h1>
                <button id="sidebarToggle" class="sidebar-toggle-inline" aria-label="Toggle sidebar">☰</button>
            </div>
            <div class="logo-text">
                <p>INVENTORY MANAGEMENT SYSTEM</p>
            </div>
        </div>
        <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link" title="Dashboard">
                    <span class="nav-icon">
                        <img src="image/admin.png" alt="Dashboard">
                    </span>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="department.php" class="nav-link" title="Department">
                    <span class="nav-icon">
                        <img src="image/department.png" alt="Department">
                    </span>
                    <span class="nav-label">Department</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="location.php" class="nav-link" title="Location">
                    <span class="nav-icon">
                        <img src="image/icons8-building-64.png" alt="Location">
                    </span>
                    <span class="nav-label">Location</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="categories.php" class="nav-link" title="Categories">
                    <span class="nav-icon">
                        <img src="image/icons8-categorize-50.png" alt="Categories">
                    </span>
                    <span class="nav-label">Categories</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="BorrowHistory.php" class="nav-link" title="Borrow History">
                    <span class="nav-icon">
                        <img src="image/book.png" alt="Borrow History">
                    </span>
                    <span class="nav-label">Borrow History</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="archive.php" class="nav-link" title="Archive">
                    <span class="nav-icon">
                        <img src="image/icons8-archive-50.png" alt="Archive">
                    </span>
                    <span class="nav-label">Archive</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="qrscanner.php" class="nav-link" title="QR Code Scanner">
                    <span class="nav-icon">
                        <img src="image/barcode-scan.png" alt="QR Scanner">
                    </span>
                    <span class="nav-label">QR Code Scanner</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="my_requests.php" class="nav-link active" title="My Requests">
                    <span class="nav-icon">
                        <img src="image/request.png" alt="My Requests">
                    </span>
                    <span class="nav-label">My Requests</span>
                </a>
            </li>
            <?php 
            // Admin role: is_admin = 1 AND role = 'admin'
            $is_admin_role = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
            $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
            $is_admin_or_super = $is_super_admin || $is_admin_role;
            ?>
            <?php if ($is_admin_or_super): ?>
            <li class="nav-item">
                <a href="item_requests.php" class="nav-link" title="Item Requests">
                    <span class="nav-icon"><img src="image/application.png" alt="Requests"></span>
                    <span class="nav-label">Item Requests</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($is_admin_or_super): ?>
            <li class="nav-item">
                <a href="user_management.php" class="nav-link" title="User Management">
                    <span class="nav-icon">
                        <img src="image/profile.png" alt="User Management">
                    </span>
                    <span class="nav-label">User Management</span>
                </a>
            </li>
            <?php endif; ?>
            <?php 
            // Database Export/Import/Backup - ONLY for native super admin (not elevated via role)
            $is_native_super_admin = $is_super_admin && !isset($_SESSION['super_admin_via_role']);
            if ($is_native_super_admin): 
            ?>
            <li class="nav-item">
                <a href="database_export.php" class="nav-link" title="Backup">
                    <span class="nav-icon">
                        <img src="image/sqlbackup.png" alt="Backup">
                    </span>
                    <span class="nav-label">Backup</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="sign-out">
            <a href="logout.php" class="nav-link" title="Sign out">
                <span class="nav-icon">
                    <img src="image/icons8-sign-out-48.png" alt="Sign Out">
                </span>
                <span class="nav-label">Sign out</span>
            </a>
        </div>
    </div>
    
    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        
        <div class="requests-container">
            <div class="requests-header">
                <h1>📋 My Item Requests</h1>
                <p>Track the status of your item requests</p>
            </div>
            
            <div class="requests-content">
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error">
                        ✕ <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($requests)): ?>
                    <div class="no-requests">
                        <div class="no-requests-icon">📝</div>
                        <h3>No Requests Yet</h3>
                        <p>You haven't made any item requests yet.</p>
                        <p>Contact your administrator to request items.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <div class="request-item">
                            <div class="request-item-header">
                                <div class="request-item-name"><?php echo htmlspecialchars($request['item_name']); ?></div>
                                <div class="request-status <?php echo $request['status']; ?>">
                                    <?php echo ucfirst($request['status']); ?>
                                </div>
                            </div>
                            
                            <div class="request-details">
                                <div class="request-detail">
                                    <div class="request-detail-label">Request ID</div>
                                    <div class="request-detail-value">#<?php echo $request['id']; ?></div>
                                </div>
                                
                                <div class="request-detail">
                                    <div class="request-detail-label">Category</div>
                                    <div class="request-detail-value"><?php echo htmlspecialchars($request['category'] ?: 'N/A'); ?></div>
                                </div>
                                
                                <div class="request-detail">
                                    <div class="request-detail-label">Quantity</div>
                                    <div class="request-detail-value"><?php echo $request['quantity']; ?></div>
                                </div>
                                
                                <div class="request-detail">
                                    <div class="request-detail-label">Requested Date</div>
                                    <div class="request-detail-value"><?php echo date('M j, Y', strtotime($request['created_at'])); ?></div>
                                </div>
                                
                                <?php if ($request['status'] !== 'pending'): ?>
                                <div class="request-detail">
                                    <div class="request-detail-label">Updated Date</div>
                                    <div class="request-detail-value"><?php echo date('M j, Y', strtotime($request['updated_at'])); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($request['notes'])): ?>
                            <div class="request-notes">
                                <div class="request-notes-label">Notes</div>
                                <div class="request-notes-content"><?php echo htmlspecialchars($request['notes']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="request-actions">
                                <?php if ($request['status'] === 'approved'): ?>
                                    <button class="request-action-btn primary" onclick="contactAdmin()">
                                        <i class="fas fa-phone"></i> Contact Admin
                                    </button>
                                <?php elseif ($request['status'] === 'rejected'): ?>
                                    <button class="request-action-btn secondary" onclick="requestAgain()">
                                        <i class="fas fa-redo"></i> Request Again
                                    </button>
                                <?php endif; ?>
                                
                                <button class="request-action-btn secondary" onclick="viewDetails(<?php echo $request['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle functionality
        (function() {
            const BODY_CLASS = 'sidebar-collapsed';

            function applyInitialState() {
                const saved = localStorage.getItem('ocabis:sidebar-collapsed');
                const isCollapsed = saved === '1';
                document.body.classList.toggle(BODY_CLASS, isCollapsed);
            }

            function toggleSidebar() {
                const isCollapsed = document.body.classList.toggle(BODY_CLASS);
                localStorage.setItem('ocabis:sidebar-collapsed', isCollapsed ? '1' : '0');
            }

            const inlineBtn = document.getElementById('sidebarToggle');
            const fixedBtn = document.getElementById('sidebarToggleFixed');
            
            if (inlineBtn) {
                inlineBtn.addEventListener('click', toggleSidebar);
            }
            
            if (fixedBtn) {
                fixedBtn.addEventListener('click', toggleSidebar);
            }

            applyInitialState();
        })();

        function contactAdmin() {
            alert('Please contact your administrator to arrange pickup of the approved item.');
        }

        function requestAgain() {
            if (confirm('Would you like to make a new request for this item?')) {
                alert('Please contact your administrator to make a new request.');
            }
        }

        function viewDetails(requestId) {
            alert('Request details for ID: ' + requestId + '\n\nThis feature can be expanded to show more detailed information.');
        }
    </script>
</body>
</html>

<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Include database connection
require_once '../db_connect.php';

$success_message = '';
$error_message = '';
$available_items = [];

// Get available items for borrowing
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$userDept = isset($_SESSION['department']) ? $_SESSION['department'] : '';

$items_sql = "SELECT i.id, i.name, i.category, i.quantity, i.location, i.status, d.name as department_name 
              FROM items i 
              JOIN departments d ON i.department_id = d.id 
              WHERE i.status NOT IN ('Broken', 'Missing', 'Lost', 'Under Maintenance', 'Borrowed') 
              AND i.status = 'Available' AND i.quantity > 0 ";

// Restrict to user's department if not admins
if (!$isAdmin && !empty($userDept)) {
    $items_sql .= "AND d.name = '" . $conn->real_escape_string($userDept) . "' ";
}

$items_sql .= "ORDER BY i.name";
$items_result = $conn->query($items_sql);
if ($items_result) {
    while ($row = $items_result->fetch_assoc()) {
        $available_items[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)$_POST['item_id'];
    $borrower_name = trim($_POST['borrower_name']);
    $borrower_email = trim($_POST['borrower_email']);
    $borrower_phone = trim($_POST['borrower_phone']);
    $borrow_quantity = (int)$_POST['borrow_quantity'];
    $borrow_date = $_POST['borrow_date'];
    $due_date = $_POST['due_date'];
    $purpose = trim($_POST['purpose']);

    // Validation
    if ($item_id <= 0) {
        $error_message = "Please select an item.";
    } elseif (empty($borrower_name)) {
        $error_message = "Borrower name is required.";
    } elseif (empty($borrower_email)) {
        $error_message = "Borrower email is required.";
    } elseif (!filter_var($borrower_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } elseif ($borrow_quantity <= 0) {
        $error_message = "Borrow quantity must be greater than 0.";
    } elseif (empty($borrow_date)) {
        $error_message = "Borrow date is required.";
    } elseif (empty($due_date)) {
        $error_message = "Due date is required.";
    } elseif ($due_date <= $borrow_date) {
        $error_message = "Due date must be after borrow date.";
    } else {
        // Check if item exists and can be borrowed
        $check_sql = "SELECT quantity, status FROM items WHERE id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $item_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $item = $check_result->fetch_assoc();
            
            // Check if item status allows borrowing
            $nonBorrowableStatuses = ['Broken', 'Missing', 'Lost', 'Under Maintenance', 'Borrowed'];
            if (in_array($item['status'], $nonBorrowableStatuses)) {
                $error_message = "Cannot borrow this item. Item status is '" . $item['status'] . "' and items with this status cannot be borrowed.";
            } elseif ($borrow_quantity > $item['quantity']) {
                $error_message = "Not enough quantity available. Available: " . $item['quantity'];
            } else {
                // Insert borrow record
                $insert_sql = "INSERT INTO borrow_history (item_id, borrower_name, borrower_email, borrower_phone, borrow_quantity, borrow_date, due_date, purpose, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("isssiiss", $item_id, $borrower_name, $borrower_email, $borrower_phone, $borrow_quantity, $borrow_date, $due_date, $purpose);

                if ($insert_stmt->execute()) {
                    // Don't decrease quantity when borrowing - keep it at 1
                    // The borrow status will be handled by the display_status logic
                    
                    $success_message = "Item borrowed successfully!";
                    
                    // Refresh available items
                    $available_items = [];
                    $items_result = $conn->query($items_sql);
                    if ($items_result) {
                        while ($row = $items_result->fetch_assoc()) {
                            $available_items[] = $row;
                        }
                    }
                } else {
                    $error_message = "Error borrowing item: " . $conn->error;
                }
                $insert_stmt->close();
            }
        } else {
            $error_message = "Item not found.";
        }
        $check_stmt->close();
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrow Item - OCABIS</title>
    <link rel="stylesheet" href="Css/dashboard.css">
    <style>
        .borrow-item-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .borrow-item-header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }

        .borrow-item-header h1 {
            color: #2d3748;
            margin: 0 0 10px 0;
            font-size: 28px;
        }

        .borrow-item-header p {
            color: #718096;
            margin: 0;
            font-size: 16px;
        }

        .borrow-item-form {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-textarea {
            height: 80px;
            resize: vertical;
        }

        .form-buttons {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }

        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #fc8181;
        }

        .no-items {
            text-align: center;
            padding: 40px;
            color: #718096;
            background: #f7fafc;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .item-info {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }

        .item-info.show {
            display: block;
        }
    </style>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>">
    <div class="sidebar">
        <div class="logo">
            <?php if (isset($_SESSION['username'])): ?>
            <div class="welcome-user" style="margin: 4px 0 8px 0; font-size: 14px; color: #374151; letter-spacing: 0.2px;">
                Welcome, <strong><?php echo htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <?php endif; ?>
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
                <a href="department.php" class="nav-link active" title="Department">
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
        <div class="borrow-item-container">
            <div class="borrow-item-header">
                <h1>📚 Borrow Item</h1>
                <p>Borrow an item from your inventory</p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    ✓ <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    ✕ <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form class="borrow-item-form" method="POST">
                <div class="form-group">
                    <label class="form-label">Select Item *</label>
                    <select name="item_id" id="itemSelect" class="form-select" required onchange="showItemInfo()">
                        <option value="">Select an item to borrow</option>
                        <?php foreach ($available_items as $item): ?>
                            <option value="<?php echo $item['id']; ?>" 
                                    data-quantity="<?php echo $item['quantity']; ?>"
                                    data-category="<?php echo htmlspecialchars($item['category']); ?>"
                                    data-location="<?php echo htmlspecialchars($item['location']); ?>"
                                    data-department="<?php echo htmlspecialchars($item['department_name']); ?>">
                                <?php echo htmlspecialchars($item['name']); ?> 
                                (<?php echo htmlspecialchars($item['item_code'] ?: 'No Code'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (empty($available_items)): ?>
                    <div class="no-items">
                        <h3>No items available for borrowing</h3>
                        <p>All items are currently unavailable or out of stock.</p>
                    </div>
                <?php endif; ?>

                <div class="item-info" id="itemInfo">
                    <h4>Item Information</h4>
                    <div class="form-grid">
                        <div><strong>Category:</strong> <span id="itemCategory">-</span></div>
                        <div><strong>Location:</strong> <span id="itemLocation">-</span></div>
                        <div><strong>Department:</strong> <span id="itemDepartment">-</span></div>
                        <div><strong>Available Quantity:</strong> <span id="itemQuantity">-</span></div>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Borrower Name *</label>
                        <input type="text" name="borrower_name" class="form-input" required 
                               value="<?php echo isset($borrower_name) ? htmlspecialchars($borrower_name) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Borrower Email *</label>
                        <input type="email" name="borrower_email" class="form-input" required 
                               value="<?php echo isset($borrower_email) ? htmlspecialchars($borrower_email) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Borrower Phone</label>
                        <input type="tel" name="borrower_phone" class="form-input" 
                               value="<?php echo isset($borrower_phone) ? htmlspecialchars($borrower_phone) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Borrow Quantity *</label>
                        <input type="number" name="borrow_quantity" id="borrowQuantity" class="form-input" required min="1" max="1">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Borrow Date *</label>
                        <input type="date" name="borrow_date" class="form-input" required 
                               value="<?php echo isset($borrow_date) ? $borrow_date : date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Due Date *</label>
                        <input type="date" name="due_date" class="form-input" required 
                               value="<?php echo isset($due_date) ? $due_date : date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Purpose of Borrowing</label>
                    <textarea name="purpose" class="form-textarea" 
                              placeholder="Enter the purpose for borrowing this item..."><?php echo isset($purpose) ? htmlspecialchars($purpose) : ''; ?></textarea>
                </div>

                <div class="form-buttons">
                    <a href="department.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary" <?php echo empty($available_items) ? 'disabled' : ''; ?>>
                        <span>📚</span> Borrow Item
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showItemInfo() {
            const select = document.getElementById('itemSelect');
            const info = document.getElementById('itemInfo');
            const quantityInput = document.getElementById('borrowQuantity');
            
            if (select.value) {
                const option = select.options[select.selectedIndex];
                const quantity = parseInt(option.dataset.quantity);
                
                document.getElementById('itemCategory').textContent = option.dataset.category;
                document.getElementById('itemLocation').textContent = option.dataset.location;
                document.getElementById('itemDepartment').textContent = option.dataset.department;
                document.getElementById('itemQuantity').textContent = quantity;
                
                quantityInput.max = quantity;
                quantityInput.value = Math.min(1, quantity);
                
                info.classList.add('show');
            } else {
                info.classList.remove('show');
            }
        }

        // Set minimum due date based on borrow date
        document.addEventListener('DOMContentLoaded', function() {
            const borrowDate = document.querySelector('input[name="borrow_date"]');
            const dueDate = document.querySelector('input[name="due_date"]');
            
            borrowDate.addEventListener('change', function() {
                const borrowDateValue = new Date(this.value);
                const minDueDate = new Date(borrowDateValue);
                minDueDate.setDate(minDueDate.getDate() + 1);
                dueDate.min = minDueDate.toISOString().split('T')[0];
                
                if (new Date(dueDate.value) <= borrowDateValue) {
                    const suggestedDue = new Date(borrowDateValue);
                    suggestedDue.setDate(suggestedDue.getDate() + 7);
                    dueDate.value = suggestedDue.toISOString().split('T')[0];
                }
            });
        });

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

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>




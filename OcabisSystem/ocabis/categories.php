<?php
session_start();

// redirect if not logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

$conn = @new mysqli('localhost', 'root', '', 'ocabis');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get current logged in info
$username = $_SESSION['username'];
$department = $_SESSION['department']; // keep for display purposes
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$isViewer = strcasecmp($role, 'viewer') === 0;
// Department head: admin but not super admin
$isDepartmentHead = $isAdmin && !$isSuperAdmin;

// Get departments list
$departments = [];
$dept_query = "SELECT id, name FROM departments ORDER BY name ASC";
$dept_result = $conn->query($dept_query);
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row;
    }
}

// Get user's department ID
$user_department_id = null;
if (!empty($department)) {
    $user_dept_query = "SELECT id FROM departments WHERE name = ? LIMIT 1";
    $user_dept_stmt = $conn->prepare($user_dept_query);
    if ($user_dept_stmt) {
        $user_dept_stmt->bind_param("s", $department);
        $user_dept_stmt->execute();
        $user_dept_result = $user_dept_stmt->get_result();
        if ($user_dept_result && $user_dept_row = $user_dept_result->fetch_assoc()) {
            $user_department_id = (int)$user_dept_row['id'];
        }
        $user_dept_stmt->close();
    }
}

// Handle add category
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_category'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $department_id = isset($_POST['department_id']) ? (int)$_POST['department_id'] : 0;
    
    // Validate department selection
    if ($department_id <= 0) {
        $_SESSION['error'] = "Please select a department";
        header("Location: categories.php");
        exit;
    }
    
    // Only super admins can add categories to any department; others can only add to their own department
    if (!$isSuperAdmin && $department_id !== $user_department_id) {
        $_SESSION['error'] = "You can only add categories for your own department";
        header("Location: categories.php");
        exit;
    }
    
    // Check if category_id column exists, if not add it
    $check_column = $conn->query("SHOW COLUMNS FROM categories LIKE 'department_id'");
    if ($check_column && $check_column->num_rows == 0) {
        $conn->query("ALTER TABLE categories ADD COLUMN department_id INT NULL AFTER name");
    }
    
    $sql = "INSERT INTO categories (name, department_id, account) VALUES ('$name', $department_id, '$username')";
    $conn->query($sql);
    header("Location: categories.php");
    exit;
}

// Handle edit category
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_category'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = trim($_POST['name'] ?? '');
    if ($id > 0 && $name !== '') {
        // Get category details including department
        $old_category_sql = "SELECT c.name, c.department_id, d.name AS department_name FROM categories c LEFT JOIN departments d ON c.department_id = d.id WHERE c.id = ?";
        $old_stmt = $conn->prepare($old_category_sql);
        $old_stmt->bind_param("i", $id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result();
        $old_category = $old_result->fetch_assoc();
        
        // Permission check: only super admins can edit categories from other departments
        if (!$isSuperAdmin && $old_category) {
            $category_dept_id = (int)($old_category['department_id'] ?? 0);
            if ($category_dept_id !== $user_department_id) {
                $_SESSION['error'] = "You can only edit categories from your own department";
                header("Location: categories.php");
                exit;
            }
        }
        
        // Update category name
        $sql = "UPDATE categories SET name = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $name, $id);
        $stmt->execute();
        
        // If category name changed, update all items and item_tables that have the old category name
        if ($old_category && $old_category['name'] !== $name) {
            $old_name = $old_category['name'];
            
            // Update all items that have the old category name
            $update_items_sql = "UPDATE items SET category = ? WHERE category = ?";
            $update_items_stmt = $conn->prepare($update_items_sql);
            $update_items_stmt->bind_param("ss", $name, $old_name);
            $update_items_stmt->execute();
            
            // Update all item_tables that have the old category name (for cards in Department page)
            $update_item_tables_sql = "UPDATE item_tables SET category = ? WHERE category = ?";
            $update_item_tables_stmt = $conn->prepare($update_item_tables_sql);
            $update_item_tables_stmt->bind_param("ss", $name, $old_name);
            $update_item_tables_stmt->execute();
        }
    }
    header("Location: categories.php");
    exit;
}

// Handle archive category
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['archive_category'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
        // Get category details including department for permission check
        $category_check_sql = "SELECT c.*, c.department_id, d.name AS department_name FROM categories c LEFT JOIN departments d ON c.department_id = d.id WHERE c.id = ?";
        $check_stmt = $conn->prepare($category_check_sql);
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $category_check = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if (!$category_check) {
            $_SESSION['error'] = "Category not found";
            header("Location: categories.php");
            exit;
        }
        
        // Permission check: only super admins can archive categories from other departments
        if (!$isSuperAdmin) {
            $category_dept_id = (int)($category_check['department_id'] ?? 0);
            if ($category_dept_id !== $user_department_id) {
                $_SESSION['error'] = "You can only archive categories from your own department";
                header("Location: categories.php");
                exit;
            }
        }
        
        // Create archived_categories table if it doesn't exist
        $create_archived_table = "CREATE TABLE IF NOT EXISTS `archived_categories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `category_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `account` varchar(255) DEFAULT NULL,
            `archived_by` varchar(255) DEFAULT NULL,
            `archived_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `category_id` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8";
        $conn->query($create_archived_table);
        
        // Add department_id and department_name columns if they don't exist
        $check_dept_id = $conn->query("SHOW COLUMNS FROM archived_categories LIKE 'department_id'");
        if (!$check_dept_id || $check_dept_id->num_rows === 0) {
            $conn->query("ALTER TABLE archived_categories ADD COLUMN department_id INT NULL AFTER account");
        }
        $check_dept_name = $conn->query("SHOW COLUMNS FROM archived_categories LIKE 'department_name'");
        if (!$check_dept_name || $check_dept_name->num_rows === 0) {
            $conn->query("ALTER TABLE archived_categories ADD COLUMN department_name VARCHAR(255) NULL AFTER department_id");
        }

        // Temporarily relax FK checks for safe archival
        $conn->query("SET FOREIGN_KEY_CHECKS=0");

        // Get category details with department info
        $category_query = "SELECT c.*, d.name AS department_name, d.id AS department_id 
                          FROM categories c 
                          LEFT JOIN departments d ON c.department_id = d.id 
                          WHERE c.id = ?";
        $category_stmt = $conn->prepare($category_query);
        $category_stmt->bind_param("i", $id);
        $category_stmt->execute();
        $category_result = $category_stmt->get_result();
        
        if ($category_result && $category_result->num_rows > 0) {
            $category = $category_result->fetch_assoc();
            $category_stmt->close();
            
            // Insert into archived_categories with department info
            $archive_sql = "INSERT INTO archived_categories (category_id, name, account, department_id, department_name, archived_by) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($archive_sql);
            $dept_id = isset($category['department_id']) && $category['department_id'] ? (int)$category['department_id'] : null;
            $dept_name = isset($category['department_name']) ? $category['department_name'] : null;
            // Use bind_param with proper types: i (int), s (string), s (string), i (int/null), s (string/null), s (string)
            // For NULL values in bind_param, we need to pass them as-is
            $stmt->bind_param("ississ", 
                $category['id'], 
                $category['name'], 
                $category['account'], 
                $dept_id, 
                $dept_name, 
                $username
            );
            $stmt->execute();
            
            // Before deleting the category, detach archived rows to avoid FK block
            $detach_stmt = $conn->prepare("UPDATE archived_categories SET category_id = NULL WHERE category_id = ?");
            if ($detach_stmt) {
                $detach_stmt->bind_param("i", $id);
                $detach_stmt->execute();
                $detach_stmt->close();
            }
            
            // Delete from categories table
            $delete_sql = "DELETE FROM categories WHERE id=$id";
            $conn->query($delete_sql);
        }
        // Re-enable FK checks
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
    }
    header("Location: categories.php");
    exit;
}

// Fetch categories with department info (only user's department if not super admin)
if ($isSuperAdmin) {
    // Super admins see all categories
    $category_query = "SELECT c.*, d.name AS department_name FROM categories c LEFT JOIN departments d ON c.department_id = d.id ORDER BY c.id DESC";
    $result = $conn->query($category_query);
} else if (!empty($user_department_id)) {
    // Regular users only see categories from their own department
    $category_query = "SELECT c.*, d.name AS department_name FROM categories c LEFT JOIN departments d ON c.department_id = d.id WHERE c.department_id = ? ORDER BY c.id DESC";
    $stmt = $conn->prepare($category_query);
    $stmt->bind_param("i", $user_department_id);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    // Users without department see no categories
    $category_query = "SELECT c.*, d.name AS department_name FROM categories c LEFT JOIN departments d ON c.department_id = d.id WHERE 1=0 ORDER BY c.id DESC";
    $result = $conn->query($category_query);
}

// Check if query was successful
if (!$result) {
    die("Database error: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>OCABIS Categories</title>
    <link rel="stylesheet" href="Css/categories.css">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <script src="js/session_monitor.js"></script>
    <script src="modal.js"></script>
    <style>
        .action-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .dropdown-toggle {
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 16px;
            color: #6c757d;
        }
        
        .dropdown-toggle:hover {
            background: #e9ecef;
        }
        
        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            z-index: 1000;
            min-width: 120px;
            display: none;
        }
        
        .dropdown-menu.show {
            display: block;
        }
        
        .dropdown-item {
            display: block;
            width: 100%;
            padding: 8px 16px;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            color: #495057;
            font-size: 14px;
        }
        
        .dropdown-item:hover {
            background: #f8f9fa;
        }
        
        .dropdown-item:first-child {
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
        }
        
        .dropdown-item:last-child {
            border-bottom-left-radius: 4px;
            border-bottom-right-radius: 4px;
        }
        
        /* Sort dropdown styling */
        #sortSelect {
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            background: white;
            font-size: 14px;
            color: #495057;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 140px;
        }
        
        #sortSelect:hover {
            border-color: #adb5bd;
        }
        
        #sortSelect:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        
        #sortSelect option {
            padding: 8px;
        }

        /* Sidebar Mobile Responsive Styles */
        @media (max-width: 768px) {
            /* Show fixed hamburger on mobile - always visible */
            #sidebarToggleFixed { 
                display: flex !important; 
                visibility: visible !important;
                opacity: 1 !important;
                z-index: 1300;
                position: fixed !important;
                top: 15px !important;
                left: 15px !important;
            }

            /* Hide inline toggle on mobile */
            #sidebarToggle {
                display: none;
            }

            /* Slide sidebar in/out on mobile */
            .sidebar { 
                transform: translateX(-100%); 
                transition: transform 0.3s ease;
                z-index: 1200;
            }
            
            .sidebar.open { 
                transform: translateX(0); 
            }

            /* Content should be full width */
            .main-content { 
                margin-left: 0 !important; 
            }

            /* Ensure toggle button is always on top */
            .sidebar-toggle-fixed {
                background: rgba(229, 62, 62, 0.95) !important;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
            }

            /* Hide mobile inline toggle - we only use fixed toggle on left */
            #sidebarToggleMobile,
            .sidebar-toggle-mobile-inline {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }
        }

        /* Mobile Inline Sidebar Toggle - Hidden on desktop */
        .sidebar-toggle-mobile-inline {
            display: none !important;
        }

        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1199;
        }

        .sidebar-overlay.show {
            display: block;
        }
    </style>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>">
    
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>
    
    <div class="sidebar">
        <div class="logo">
            <div class="logo-top" style="display: flex !important; align-items: center; gap: 10px; width: 100%; position: relative;">
                <div class="logo-icon">
                    <img src="image/image-removebg-preview.png" alt="Logo" style="height: 50px; width: auto;">
                </div>
                <h1 style="margin: 0; flex: 1; min-width: 0;">CABIS</h1>
                <button id="sidebarToggle" class="sidebar-toggle-inline" aria-label="Toggle sidebar" style="display: flex !important; visibility: visible !important; opacity: 1 !important; margin-left: auto !important; flex-shrink: 0 !important;">☰</button>
            </div>
            <div class="logo-text">
                <p>INVENTORY MANAGEMENT SYSTEM</p>
            </div>
        </div>

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
            <a href="department.php" class="nav-link" title="<?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
                <span class="nav-icon">
                    <img src="image/department.png" alt="<?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?>">
                </span>
                <span class="nav-label"><?= ($isDepartmentHead || $isAdmin || $isSuperAdmin) ? 'Item List' : 'Department' ?></span>
            </a>
        </li>
        <?php if ($isDepartmentHead): ?>
        <li class="nav-item">
            <a href="head_borrow_items.php" class="nav-link" title="Borrow Items">
                <span class="nav-icon">
                    <img src="image/book.png" alt="Borrow Items">
                </span>
                <span class="nav-label">Borrow Items</span>
            </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a href="location.php" class="nav-link" title="Location">
                <span class="nav-icon">
                    <img src="image/icons8-building-64.png" alt="Location">
                </span>
                <span class="nav-label">Location</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="categories.php" class="nav-link active" title="Categories">
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
                    <img src="image/qr.png" alt="QR Scanner">
                </span>
                <span class="nav-label">QR Code Scanner</span>
            </a>
        </li>
        <li class="nav-item">
        <a href="barcode_scanner.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/barcode-scan.png" alt="Barcode Scanner">
            </span>
            <span class="nav-label">Barcode Scanner</span>
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

    <!-- Main Content -->
    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        <!-- Mobile Inline Sidebar Toggle (visible on mobile only) -->
        <button id="sidebarToggleMobile" class="sidebar-toggle-mobile-inline" aria-label="Toggle sidebar">☰</button>
        <div class="header">
            <div class="icon">📦</div>
            <h2>Categories<?= $isSuperAdmin ? ' (All Departments)' : (!empty($department) ? ' - ' . htmlspecialchars($department) : '') ?></h2>
        </div>

        <div class="controls">
            <div class="search-box" style="position:relative;">
                <img src="image/search.png" alt="Search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);width:16px;height:16px;opacity:0.5;pointer-events:none;" />
                <input type="text" id="searchInput" placeholder="Search categories..." style="padding-left:36px;padding-right:40px;">
                <button id="clearSearch" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;display:none;color:#6c757d;font-size:18px;" onclick="clearSearch()">×</button>
            </div>
            <!-- Remove department dropdown since we're showing all -->
            <div class="dropdown">
                <select id="sortSelect">
                    <option value="">Sort by...</option>
                    <option value="modified">Modified</option>
                    <option value="created">Created</option>
                    <option value="name">Name</option>
                    <option value="department">Department</option>
                    <option value="account">Account</option>
                </select>
            </div>
            <button class="add-btn">
                <img src="image/icons8-add-48.png" alt="Add" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;filter:brightness(0) invert(1);" />
                ADD NEW
            </button>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Department</th><th>Account</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr data-name="<?= htmlspecialchars($row['name']) ?>" 
                        data-account="<?= htmlspecialchars($row['account']) ?>"
                        data-department="<?= htmlspecialchars($row['department_name'] ?? 'Unassigned') ?>"
                        data-created="<?= $row['id'] ?>"
                        data-modified="<?= $row['id'] ?>">
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td>
                            <span class="department-badge">
                                <?= htmlspecialchars($row['department_name'] ?? 'Unassigned') ?>
                            </span>
                        </td>
                        <td><span class="account-badge"><?= htmlspecialchars($row['account']) ?></span></td>
                        <td>
                            <?php 
                                $rowDept = $row['department_name'] ?? '';
                                // Allow super admin anywhere; otherwise allow any non-viewer user only within their own department
                                $canManageThisCategory = $isSuperAdmin || (!$isViewer && !empty($department) && strcasecmp($rowDept, $department) === 0);
                            ?>
                            <?php if ($canManageThisCategory): ?>
                                <div class="action-dropdown">
                                    <button class="action-btn dropdown-toggle" data-id="<?= (int)$row['id'] ?>" data-name="<?= htmlspecialchars($row['name']) ?>">⋮</button>
                                    <div class="dropdown-menu">
                                        <button class="dropdown-item edit-btn" data-id="<?= (int)$row['id'] ?>" data-name="<?= htmlspecialchars($row['name']) ?>">
                                            <img src="image/edit.png" alt="Edit" style="width:16px;height:16px;margin-right:8px;vertical-align:middle;">
                                            Edit
                                        </button>
                                        <button class="dropdown-item archive-btn" data-id="<?= (int)$row['id'] ?>" data-name="<?= htmlspecialchars($row['name']) ?>">
                                            <img src="image/icons8-archive-50.png" alt="Archive" style="width:16px;height:16px;margin-right:8px;vertical-align:middle;">
                                            Archive
                                        </button>
                                    </div>
                                </div>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

<!-- Add Category Modal -->
<div class="modal-overlay" id="addCategoryModal">
  <div class="modal">
    <div class="modal-header">
      <h3>Add New Category</h3>
    </div>
    
    <div class="modal-body">
      <form method="POST" id="categoryForm">
        <div class="form-group">
          <label for="categoryDepartment">Department: <span class="required">*</span></label>
          <select id="categoryDepartment" name="department_id" required 
                  style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 16px; box-sizing: border-box; background: #ffffff; appearance: none;">
            <option value="">Select Department</option>
            <?php 
            // Only super admins can see all departments; others can only see their own department
            $dept_to_show = $isSuperAdmin ? $departments : array_filter($departments, function($d) use ($user_department_id) {
                return $d['id'] == $user_department_id;
            });
            foreach ($dept_to_show as $dep): 
            ?>
            <option value="<?= $dep['id'] ?>" <?= (!$isSuperAdmin && $dep['id'] == $user_department_id) ? 'selected' : '' ?>>
              <?= htmlspecialchars($dep['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="categoryName">Category Name: <span class="required">*</span></label>
          <input type="text" 
                 id="categoryName" 
                 name="name" 
                 placeholder="Enter a descriptive category name..." 
                 required 
                 autocomplete="off"
                 maxlength="50">
        </div>
        
        <input type="hidden" name="add_category" value="1">
        
        <div class="modal-buttons">
          <button type="button" class="modal-btn modal-btn-cancel" onclick="closeAddModal()">
            Cancel
          </button>
          <button type="submit" class="modal-btn modal-btn-confirm" id="CreateBtn">
            Create
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


    <!-- Sign Out Modal -->
    <div class="modal-overlay" id="signOutModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-icon">🚪</div>
                <h3 class="modal-title">Sign Out</h3>
                <p class="modal-message">Are you sure you want to sign out of your account?</p>
            </div>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-cancel" onclick="closeSignOutModal()">Cancel</button>
                <button class="modal-btn modal-btn-confirm" id="confirmSignOut" onclick="confirmSignOut()">Sign Out</button>
            </div>
        </div>
    </div>
    <!-- Edit Category Modal -->
    <div class="modal-overlay" id="editCategoryModal">
      <div class="modal">
        <div class="modal-header">
          <h3>Edit Category</h3>
        </div>
        <div class="modal-body">
          <form method="POST" id="editCategoryForm">
            <div class="form-group">
              <label for="editCategoryName">Category Name</label>
              <input type="text" id="editCategoryName" name="name" placeholder="Update category name..." required autocomplete="off" maxlength="50">
            </div>
            <input type="hidden" name="id" id="editCategoryId">
            <input type="hidden" name="edit_category" value="1">
            <div class="modal-buttons">
              <button type="button" class="modal-btn modal-btn-cancel" onclick="closeEditModal()">Cancel</button>
              <button type="submit" class="modal-btn modal-btn-confirm" id="SaveEditBtn">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Archive Category Modal -->
    <div class="modal-overlay" id="archiveCategoryModal">
      <div class="modal">
        <div class="modal-header">
          <h3>Archive Category</h3>
        </div>
        <div class="modal-body">
          <p id="archiveConfirmText">Are you sure you want to archive this category?</p>
          <form method="POST" id="archiveCategoryForm">
            <input type="hidden" name="id" id="archiveCategoryId">
            <input type="hidden" name="archive_category" value="1">
            <div class="modal-buttons">
              <button type="button" class="modal-btn modal-btn-cancel" onclick="closeArchiveModal()">Cancel</button>
              <button type="submit" class="modal-btn modal-btn-confirm" id="ConfirmArchiveBtn">Archive</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
        function archiveItem(itemId) {
        // Send a request to the server to archive the item
        fetch('archive.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-wwwform-urlencoded'
            },
            body: `id=${itemId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Item archived successfully, update the table and close the modal
                updateTable();
                closeArchiveModal(itemId);
            } else {
                // Error archiving the item, show an error message
                modal.error('Error archiving item: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error archiving item:', error);
            modal.error('Error archiving item. Please try again.');
        });
    }

    function openArchiveModal(itemId) {
        // Show the archive modal
        document.getElementById('archiveModal' + itemId).classList.add('show');
    }

    function closeArchiveModal(itemId) {
        // Close the archive modal
        document.getElementById('archiveModal' + itemId).classList.remove('show');
    }
        // Sidebar collapse/expand with mobile support
        (function() {
            const BODY_CLASS = 'sidebar-collapsed';
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');

            function isMobile() {
                return window.innerWidth <= 768;
            }

            function applyInitialState() {
                const saved = localStorage.getItem('ocabis:sidebar-collapsed');
                const isCollapsed = saved === '1';
                
                if (isMobile()) {
                    // On mobile, don't apply collapsed state initially
                    sidebar.classList.remove('open');
                    if (overlay) overlay.classList.remove('show');
                    document.body.style.overflow = '';
                } else {
                    // On desktop, apply saved state
                    document.body.classList.toggle(BODY_CLASS, isCollapsed);
                }
            }

            function toggleSidebar() {
                if (isMobile()) {
                    // Mobile behavior: slide sidebar in/out with overlay
                    const isOpen = sidebar.classList.contains('open');
                    
                    if (isOpen) {
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                    } else {
                        sidebar.classList.add('open');
                        if (overlay) overlay.classList.add('show');
                        document.body.style.overflow = 'hidden';
                    }
                } else {
                    // Desktop behavior: collapse/expand
                    const isCollapsed = document.body.classList.toggle(BODY_CLASS);
                    localStorage.setItem('ocabis:sidebar-collapsed', isCollapsed ? '1' : '0');
                }
            }

            // Close sidebar when clicking overlay (mobile only)
            if (overlay) {
                overlay.addEventListener('click', function() {
                    if (isMobile()) {
                        sidebar.classList.remove('open');
                        overlay.classList.remove('show');
                        document.body.style.overflow = '';
                    }
                });
            }

            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (isMobile()) {
                        // On mobile, ensure sidebar is closed and reset desktop state
                        document.body.classList.remove(BODY_CLASS);
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                    } else {
                        // On desktop, close mobile sidebar and apply desktop state
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                        applyInitialState();
                    }
                }, 250);
            });

            const inlineBtn = document.getElementById('sidebarToggle');
            const fixedBtn = document.getElementById('sidebarToggleFixed');
            const mobileInlineBtn = document.getElementById('sidebarToggleMobile');
            if (inlineBtn) inlineBtn.addEventListener('click', toggleSidebar);
            if (fixedBtn) fixedBtn.addEventListener('click', toggleSidebar);
            if (mobileInlineBtn) mobileInlineBtn.addEventListener('click', toggleSidebar);
            
            applyInitialState();
        })();

        // Enhanced search functionality that works with sorting
        const searchInput = document.querySelector('.search-box input');
        const tableRows = document.querySelectorAll('tbody tr');
        
        function performSearch() {
            const searchTerm = searchInput.value.toLowerCase();
            const currentSort = sortSelect.value;
            
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const shouldShow = text.includes(searchTerm);
                row.style.display = shouldShow ? '' : 'none';
            });
            
            // Re-apply sorting if there's an active sort
            if (currentSort) {
                sortSelect.dispatchEvent(new Event('change'));
            }
        }
        
        searchInput.addEventListener('input', performSearch);
        
        // Clear search functionality
        function clearSearch() {
            searchInput.value = '';
            performSearch();
            searchInput.focus();
        }
        
        // Show/hide clear button based on input
        searchInput.addEventListener('input', function() {
            const clearBtn = document.getElementById('clearSearch');
            clearBtn.style.display = this.value ? 'block' : 'none';
        });

        // Enhanced sort functionality that works with search
        const sortSelect = document.getElementById('sortSelect');
        sortSelect.addEventListener('change', function() {
            const sortBy = this.value;
            const tbody = document.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            if (!sortBy) {
                // Reset to original order
                rows.forEach(row => tbody.appendChild(row));
                return;
            }
            
            // Separate visible and hidden rows
            const visibleRows = rows.filter(row => row.style.display !== 'none');
            const hiddenRows = rows.filter(row => row.style.display === 'none');
            
            // Sort only visible rows
            visibleRows.sort((a, b) => {
                let aValue, bValue;
                
                switch(sortBy) {
                    case 'name':
                        aValue = a.dataset.name.toLowerCase();
                        bValue = b.dataset.name.toLowerCase();
                        return aValue.localeCompare(bValue);
                        
                    case 'account':
                        aValue = a.dataset.account.toLowerCase();
                        bValue = b.dataset.account.toLowerCase();
                        return aValue.localeCompare(bValue);

                    case 'department':
                        aValue = (a.dataset.department || '').toLowerCase();
                        bValue = (b.dataset.department || '').toLowerCase();
                        return aValue.localeCompare(bValue);
                        
                    case 'created':
                        aValue = parseInt(a.dataset.created);
                        bValue = parseInt(b.dataset.created);
                        return bValue - aValue; // Highest ID first (newest)
                        
                    case 'modified':
                        aValue = parseInt(a.dataset.modified);
                        bValue = parseInt(b.dataset.modified);
                        return bValue - aValue; // Highest ID first (newest)
                        
                    default:
                        return 0;
                }
            });
            
            // Re-append sorted visible rows first, then hidden rows
            [...visibleRows, ...hiddenRows].forEach(row => tbody.appendChild(row));
        });

        // Add new button -> open modal
        document.querySelector('.add-btn').addEventListener('click', () => {
            document.getElementById('addCategoryModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        });

        function closeAddModal() {
            document.getElementById('addCategoryModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Dropdown toggle functionality
        function removeFloatingMenu() {
            const m = document.getElementById('catActionMenu');
            if (m) m.remove();
        }

        function openEditCategory(id, name) {
            removeFloatingMenu();
            const idEl = document.getElementById('editCategoryId');
            const nameEl = document.getElementById('editCategoryName');
            if (idEl && nameEl) {
                idEl.value = id;
                nameEl.value = name;
                const modal = document.getElementById('editCategoryModal');
                if (modal) {
                    modal.classList.add('show');
                    document.body.style.overflow = 'hidden';
                }
            }
        }

        function openArchiveCategory(id, name) {
            removeFloatingMenu();
            const idEl = document.getElementById('archiveCategoryId');
            const txt = document.getElementById('archiveConfirmText');
            if (idEl) idEl.value = id;
            if (txt) txt.textContent = `Are you sure you want to archive "${name}"?`;
            const modal = document.getElementById('archiveCategoryModal');
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function openCategoryMenu(btn) {
            // Clean existing
            removeFloatingMenu();
            const id = btn.getAttribute('data-id');
            const name = btn.getAttribute('data-name') || '';
            const rect = btn.getBoundingClientRect();

            const menu = document.createElement('div');
            menu.id = 'catActionMenu';
            menu.style.position = 'fixed';
            menu.style.background = '#fff';
            menu.style.border = '1px solid #dee2e6';
            menu.style.borderRadius = '4px';
            menu.style.boxShadow = '0 8px 24px rgba(0,0,0,0.15)';
            menu.style.minWidth = '140px';
            menu.style.zIndex = '2147483000';
            menu.style.overflow = 'auto';
            menu.style.maxHeight = '240px';

            const escapedName = name.replace(/\\/g, "\\\\").replace(/'/g, "\\'");
            menu.innerHTML = `
                <button class="dropdown-item" onclick="openEditCategory(${parseInt(id,10)}, '${escapedName}')">
                    <img src="image/edit.png" alt="Edit" style="width:16px;height:16px;margin-right:8px;vertical-align:middle;"> Edit
                </button>
                <button class="dropdown-item" onclick="openArchiveCategory(${parseInt(id,10)}, '${escapedName}')">
                    <img src="image/icons8-archive-50.png" alt="Archive" style="width:16px;height:16px;margin-right:8px;vertical-align:middle;"> Archive
                </button>
            `;

            document.body.appendChild(menu);

            // Placement: prefer below; if not enough, open upward
            const menuHeight = Math.min(240, menu.scrollHeight || 240);
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;
            let top = rect.bottom + 6;
            if (spaceBelow < menuHeight && spaceAbove > spaceBelow) {
                top = rect.top - menuHeight - 6;
                if (top < 8) top = 8;
            }
            let left = rect.right - menu.offsetWidth;
            if (left < 8) left = 8;
            menu.style.top = `${Math.round(top)}px`;
            menu.style.left = `${Math.round(rect.right - Math.max(menu.offsetWidth, 140))}px`;

            // Close on outside click/scroll/resize
            setTimeout(() => {
                const closeHandler = (ev) => {
                    if (!menu.contains(ev.target)) removeFloatingMenu();
                };
                document.addEventListener('click', closeHandler, { once: true });
                window.addEventListener('scroll', removeFloatingMenu, { once: true });
                window.addEventListener('resize', removeFloatingMenu, { once: true });
            }, 0);
        }

        document.querySelectorAll('.dropdown-toggle').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                openCategoryMenu(e.currentTarget);
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.action-dropdown')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });

        // Edit button -> open edit modal and populate
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = e.currentTarget.getAttribute('data-id');
                const name = e.currentTarget.getAttribute('data-name');
                document.getElementById('editCategoryId').value = id;
                document.getElementById('editCategoryName').value = name;
                document.getElementById('editCategoryModal').classList.add('show');
                document.body.style.overflow = 'hidden';
                // Close dropdown
                e.currentTarget.closest('.dropdown-menu').classList.remove('show');
                setTimeout(() => { document.getElementById('editCategoryName').focus(); }, 200);
            });
        });

        function closeEditModal() {
            document.getElementById('editCategoryModal').classList.remove('show');
            document.body.style.overflow = 'auto';
            const form = document.getElementById('editCategoryForm');
            if (form) form.reset();
        }

        // Archive button -> open archive modal and set id
        document.querySelectorAll('.archive-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = e.currentTarget.getAttribute('data-id');
                const name = e.currentTarget.getAttribute('data-name');
                document.getElementById('archiveCategoryId').value = id;
                const text = document.getElementById('archiveConfirmText');
                if (text) { text.textContent = `Archive category "${name}"? This cannot be undone.`; }
                document.getElementById('archiveCategoryModal').classList.add('show');
                document.body.style.overflow = 'hidden';
                // Close dropdown
                e.currentTarget.closest('.dropdown-menu').classList.remove('show');
            });
        });

        function closeArchiveModal() {
            document.getElementById('archiveCategoryModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Sign out modal functionality
        function signOut() { showSignOutModal(); }
        function showSignOutModal() {
            const modal = document.getElementById('signOutModal');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function closeSignOutModal() {
            const modal = document.getElementById('signOutModal');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        function confirmSignOut() {
            const confirmBtn = document.getElementById('confirmSignOut');
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<div class="loading-spinner"></div>Signing out...';
            setTimeout(() => {
                closeSignOutModal();
                window.location.href = 'logout.php';
                setTimeout(() => { window.location.reload(); }, 100);
            }, 1500);
        }
        document.getElementById('signOutModal').addEventListener('click', function(e) {
            if (e.target === this) { closeSignOutModal(); }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { 
                closeSignOutModal(); 
                closeAddModal();
                closeEditModal();
                closeArchiveModal();
            }
        });
        // Enhanced Add Category Modal Functionality
document.addEventListener('DOMContentLoaded', function() {
    const addModal = document.getElementById('addCategoryModal');
    const categoryForm = document.getElementById('categoryForm');
    const categoryInput = document.getElementById('categoryName');
    const CreateButton = document.getElementById('CreateBtn');
    
    // Open modal with enhanced animation
    document.querySelector('.add-btn').addEventListener('click', () => {
        openAddModal();
    });
    
    function openAddModal() {
        addModal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // Focus the input field after animation completes
        setTimeout(() => {
            categoryInput.focus();
        }, 200);
    }
    
    function closeAddModal() {
        addModal.classList.remove('show');
        document.body.style.overflow = 'auto';
        
        // Reset form
        categoryForm.reset();
        CreateButton.classList.remove('success');
        CreateButton.disabled = false;
        CreateButton.textContent = 'Create Category';
    }
    
    // Enhanced form submission
    categoryForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const categoryName = categoryInput.value.trim();
        
        if (categoryName.length < 2) {
            showInputError('Category name must be at least 2 characters long');
            return;
        }
        
        // Show loading state
        CreateButton.disabled = true;
        CreateButton.innerHTML = '<div class="loading-spinner"></div>Creating...';

        // Simulate form submission with better UX
        setTimeout(() => {
            // Show success state
            CreateButton.classList.add('success');
            CreateButton.innerHTML = '✓ Created Successfully';
            
            setTimeout(() => {
                // Submit the actual form
                categoryForm.submit();
            }, 800);
        }, 1000);
    });
    
    // Input validation and feedback
    categoryInput.addEventListener('input', function() {
        const value = this.value.trim();
        clearInputError();
        
        if (value.length > 50) {
            showInputError('Category name is too long (max 50 characters)');
        }
    });
    
    function showInputError(message) {
        clearInputError();
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'input-error';
        errorDiv.textContent = message;
        errorDiv.style.cssText = `
            color: #ef4444;
            font-size: 13px;
            margin-top: 6px;
            padding: 4px 0;
            animation: fadeIn 0.3s ease;
        `;
        
        categoryInput.parentNode.appendChild(errorDiv);
        categoryInput.style.borderColor = '#ef4444';
    }
    
    function clearInputError() {
        const existingError = categoryInput.parentNode.querySelector('.input-error');
        if (existingError) {
            existingError.remove();
        }
        categoryInput.style.borderColor = '#e5e7eb';
    }
    
    // Close modal on outside click
    addModal.addEventListener('click', function(e) {
        if (e.target === this) {
            closeAddModal();
        }
    });
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && addModal.classList.contains('show')) {
            closeAddModal();
        }
    });
    
    // Make closeAddModal globally accessible
    window.closeAddModal = closeAddModal;
});

// Inject spinner CSS if not already present
if (!document.querySelector('#spinner-styles')) {
    const style = document.createElement('style');
    style.id = 'spinner-styles';
    style.textContent = spinnerCSS;
    document.head.appendChild(style);
}

    </script>
</body>
</html>
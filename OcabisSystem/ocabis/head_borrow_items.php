<?php
include '../db_connect.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Determine user context
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$isAdmin = (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1) || $isSuperAdmin;
$isDepartmentHead = $isAdmin && !$isSuperAdmin; // Department head (admin but not super admin)

// Only allow Head Department users to access this page
if (!$isDepartmentHead) {
    header("Location: department.php");
    exit();
}

$userDepartmentName = isset($_SESSION['department']) ? $_SESSION['department'] : '';

// Initialize departments array
$departments = [];
$error_message = '';

try {
    // Get all departments
    $sql = "SELECT id, name FROM departments ORDER BY name ASC";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $deptName = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
            // Exclude user's own department
            if (!empty($userDepartmentName) && html_entity_decode($deptName, ENT_QUOTES, 'UTF-8') === $userDepartmentName) {
                continue;
            }
            $departments[] = [
                'id' => (int)$row['id'],
                'name' => $deptName
            ];
        }
    }
    
    // Resolve current user's department ID for filtering
    $userDepartmentId = null;
    if (!empty($userDepartmentName) && !empty($departments)) {
        $allDeptsResult = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
        if ($allDeptsResult && $allDeptsResult->num_rows > 0) {
            while ($row = $allDeptsResult->fetch_assoc()) {
                if (html_entity_decode(htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') === $userDepartmentName) {
                    $userDepartmentId = (int)$row['id'];
                    break;
                }
            }
        }
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("Database query error: " . $error_message);
}

$current_username = isset($_SESSION['username']) ? $_SESSION['username'] : '';

// Get current user email for borrow modal
$current_user_email = '';
try {
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        $current_user_email = $row['email'] ?? '';
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching user email: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OCABIS - Borrow Items</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="Css/department.css">
    <script src="js/session_monitor.js"></script>
    <style>
        .viewer-borrow-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .viewer-borrow-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #218838, #1ea080);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }
        .viewer-borrow-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .pending-approval-badge {
            display: inline-block;
            padding: 6px 12px;
            background-color: #f59e0b;
            color: white;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
        }
        .consumable-not-available-badge {
            display: inline-block;
            padding: 6px 12px;
            background-color: #fef3c7;
            color: #92400e;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid #fcd34d;
        }
    </style>
</head>
<body class="department-page" data-user-logged-in="true" data-user-is-department-head="true" data-user-username="<?= htmlspecialchars($current_username, ENT_QUOTES, 'UTF-8') ?>" data-user-email="<?= htmlspecialchars($current_user_email, ENT_QUOTES, 'UTF-8') ?>">
    
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <div class="logo-top" style="display: flex !important; align-items: center; gap: 10px; width: 100%; position: relative;">
                <div class="logo-icon">
                    <img src="image/image-removebg-preview.png" alt="Logo" style="height: 50px; width: auto;">
                </div>
                <h1 style="margin: 0; flex: 1; min-width: 0;">CABIS</h1>
            </div>
            <div class="logo-text">
                <p>INVENTORY MANAGEMENT SYSTEM</p>
            </div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <span class="nav-icon">
                        <img src="image/admin.png" alt="Dashboard">
                    </span>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="department.php" class="nav-link">
                    <span class="nav-icon">
                        <img src="image/department.png" alt="Items">
                    </span>
                    Item List
                </a>
            </li>
            <li class="nav-item">
                <a href="head_borrow_items.php" class="nav-link active">
                    <span class="nav-icon">
                        <img src="image/book.png" alt="Borrow Items">
                    </span>
                    Borrow Items
                </a>
            </li>
            <li class="nav-item">
                <a href="location.php" class="nav-link">
                    <span class="nav-icon">
                        <img src="image/icons8-building-64.png" alt="Location">
                    </span>
                    Location
                </a>
            </li>
            <li class="nav-item">
                <a href="categories.php" class="nav-link">
                    <span class="nav-icon">
                        <img src="image/icons8-categorize-50.png" alt="Categories">
                    </span>
                    Categories
                </a>
            </li>
            <li class="nav-item">
                <a href="BorrowHistory.php" class="nav-link">
                    <span class="nav-icon">
                        <img src="image/book.png" alt="Borrow History">
                    </span>
                    Borrow History
                </a>
            </li>
            <li class="nav-item">
                <a href="archive.php" class="nav-link">
                    <span class="nav-icon">
                        <img src="image/icons8-archive-50.png" alt="Archive">
                    </span>
                    Archive
                </a>
            </li>
            <li class="nav-item">
                <a href="qrscanner.php" class="nav-link">
                    <span class="nav-icon">
                        <img src="image/qr.png" alt="QR Code Scanner">
                    </span>
                    QR Code Scanner
                </a>
            </li>
            <li class="nav-item">
                <a href="barcode_scanner.php" class="nav-link">
                    <span class="nav-icon">
                        <img src="image/barcode-scan.png" alt="Barcode Scanner">
                    </span>
                    Barcode Scanner
                </a>
            </li>
        </ul>
        
        <div class="sign-out">
            <a href="logout.php" class="nav-link">
                <span class="nav-icon">
                    <img src="image/icons8-sign-out-48.png" alt="Sign Out">
                </span>
                Sign out
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <?php include 'profile_dropdown.php'; ?>
        
        <div class="top-section">
            <div class="breadcrumb" id="breadcrumb">
                <span class="breadcrumb-item clickable" onclick="goBackToTables()" style="cursor: pointer; color: #333; text-decoration: underline;">Borrow Items</span>
                <span class="breadcrumb-separator" id="breadcrumbSeparator" style="display: none;"> > </span>
                <span class="breadcrumb-department" id="breadcrumbDepartment" style="display: none;"></span>
                <span class="breadcrumb-separator" id="breadcrumbTableSeparator" style="display: none;"> > </span>
                <span class="breadcrumb-table" id="breadcrumbTable" style="display: none;"></span>
            </div>
            <div class="top-buttons">
                <!-- Buttons can be added here if needed -->
            </div>
        </div>
        
        <div class="content-area">
            <div class="sidebar-tree">
                <div class="tree-menu" id="treeMenu">
                    <div class="tree-item" data-dept-id="all">
                        <div class="tree-node active" onclick="selectDepartment('all', 'All Departments')">
                            <img src="image/building-1062.png" alt="Building" class="tree-icon">
                            <span class="tree-text">All Departments</span>
                        </div>
                    </div>
                    <?php foreach ($departments as $dept): ?>
                        <div class="tree-item" data-dept-id="<?= $dept['id'] ?>">
                            <div class="tree-node" onclick="selectDepartment(<?= $dept['id'] ?>, '<?= $dept['name'] ?>')">
                                <img src="image/building-1062.png" alt="Building" class="tree-icon">
                                <span class="tree-text"><?= $dept['name'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="data-section">
                <div class="filters-section" id="filtersSection" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
                    <div style="position: relative; display: inline-block;">
                        <input type="text" id="nameFilter" class="filter-input" placeholder="Search items" style="width: 200px; padding-right: 35px;" />
                        <button type="button" onclick="performSearch()" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 4px;" title="Search">
                            <img src="image/search.png" alt="Search" style="width: 16px; height: 15px; opacity: 0.7;" />
                        </button>
                    </div>

                    <!-- Grid/List View Toggle Button -->
                    <div class="view-toggle" id="tableCardsViewToggle" style="display: inline-flex;">
                        <button class="view-btn active" id="gridViewBtn" onclick="switchToGridView()" title="Grid View">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <rect x="3" y="3" width="7" height="7"/>
                                <rect x="14" y="3" width="7" height="7"/>
                                <rect x="3" y="14" width="7" height="7"/>
                                <rect x="14" y="14" width="7" height="7"/>
                            </svg>
                        </button>
                        <button class="view-btn" id="listViewBtn" onclick="switchToListView()" title="List View">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <rect x="3" y="6" width="18" height="2"/>
                                <rect x="3" y="11" width="18" height="2"/>
                                <rect x="3" y="16" width="18" height="2"/>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="summary-info" id="summaryInfo" style="margin-left: auto;">
                        Item Table: <strong id="itemTableCount">0</strong> Items: <strong id="itemCount">0</strong> Total Quantity: <strong id="totalQuantity">0 units</strong>
                    </div>
                </div>
                
                <div id="noFilterMessage" style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 60px 40px; text-align: center; margin-top: 30px; display: block;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                    <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 20px; font-weight: 600;">Search for Items to View Them</h3>
                    <p style="color: #718096; font-size: 14px; max-width: 500px; margin: 0 auto;">
                        Please use the search box above to search for items. Items will only be displayed after you perform a search.
                    </p>
                </div>
                
                <div class="items-cards-container" id="itemsCardsContainer" style="display: none;">
                    <!-- Item tables will be populated dynamically -->
                </div>
                
                <div class="table-container" id="tableContainer" style="display: none;">
                    <!-- Items from selected table will be shown here -->
                </div>
            </div>
        </div>
    </div>

<!-- Viewer Borrow Request Modal -->
<div class="modal-overlay" id="viewerBorrowModal" style="display:none;">
    <div class="modal" style="max-width: 550px;">
        <div class="modal-header" style="position: relative; z-index: 10;">
            <h3>Request to Borrow Item</h3>
            <button class="close-btn" onclick="closeViewerBorrowModal()" style="position: relative; z-index: 11; pointer-events: auto;">×</button>
        </div>
        <div class="modal-body" style="position: relative; z-index: 1; pointer-events: auto;">
            <form id="viewerBorrowForm" onsubmit="event.preventDefault(); return false;" style="pointer-events: auto;">
                <div class="form-group">
                    <label>Item Name: <span class="required">*</span></label>
                    <input type="text" id="viewerBorrowItemName" readonly style="background-color: #f8f9fa;" required>
                    <input type="hidden" id="viewerBorrowItemId">
                </div>
                <div class="form-group">
                    <label>Item Code:</label>
                    <input type="text" id="viewerBorrowItemCode" readonly style="background-color: #f8f9fa;">
                </div>
                <div class="form-group">
                    <label>Borrower Name: <span class="required">*</span></label>
                    <input type="text" id="viewerBorrowerName" required readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                </div>
                <div class="form-group">
                    <label>Borrower Email: <span class="required">*</span></label>
                    <input type="email" id="viewerBorrowerEmail" required readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                </div>
                <div class="form-group">
                    <label>Borrow Date (Request Date): <span class="required">*</span></label>
                    <input type="date" id="viewerBorrowDate" required readonly style="position: relative; z-index: 10; pointer-events: none; background-color: #f8f9fa; cursor: not-allowed;">
                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Automatically set to today (when you submit the request)</small>
                </div>
                <div class="form-group">
                    <label>Needed Date: <span class="required">*</span></label>
                    <input type="date" id="viewerNeededDate" required style="position: relative; z-index: 10; pointer-events: auto;">
                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">When do you need this item?</small>
                </div>
                <div class="form-group">
                    <label>Due Date: <span class="required">*</span></label>
                    <input type="date" id="viewerDueDate" required style="position: relative; z-index: 10; pointer-events: auto;">
                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">When will you return this item?</small>
                </div>
                <div class="form-group">
                    <label>Item Placement:</label>
                    <select id="viewerItemPlacement" style="position: relative; z-index: 10; pointer-events: auto; width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="">Select Location</option>
                        <!-- Locations will be populated dynamically -->
                    </select>
                    <small style="color: #666; font-size: 12px; display: block; margin-top: 4px;">Where will this item be placed?</small>
                </div>
                <div class="form-group">
                    <label>Purpose/Notes:</label>
                    <textarea id="viewerBorrowPurpose" rows="3" placeholder="Purpose of borrowing or additional notes..." style="position: relative; z-index: 10; pointer-events: auto;"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="position: relative; z-index: 1; pointer-events: auto;">
            <button type="button" class="btn-cancel" onclick="closeViewerBorrowModal()" style="pointer-events: auto; cursor: pointer;">Cancel</button>
            <button type="button" class="btn-submit" id="viewerBorrowSubmitBtn" onclick="submitViewerBorrowRequest()" style="pointer-events: auto; cursor: pointer;">Submit Borrow Request</button>
        </div>
    </div>
</div>

<script src="modal.js"></script>

<script>
// Escape HTML helper function (defined early for use throughout)
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Global variables
let allItemTables = [];
let selectedDepartmentId = 'all';
let selectedDepartmentName = 'All Departments';
const USER_DEPARTMENT = '<?= addslashes($userDepartmentName) ?>';
const USER_DEPARTMENT_ID = <?= $userDepartmentId ?? 'null' ?>;

// Load item tables from API
function loadItemTables() {
    return fetch('crud.php?action=get_item_tables')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.item_tables) {
                // Filter out item tables from user's own department
                allItemTables = data.item_tables.filter(table => {
                    const tableDept = String(table.department_name || '');
                    const userDept = String(USER_DEPARTMENT || '');
                    return tableDept !== userDept;
                });
                console.log('Loaded item tables (excluding own department):', allItemTables.length);
                // Don't display tables initially - require search first
                // displayItemTables(allItemTables);
                // updateSummary();
            } else {
                console.error('Error loading item tables:', data.message);
                showNoTablesMessage();
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showNoTablesMessage();
        });
}

// Select department
function selectDepartment(deptId, deptName) {
    selectedDepartmentId = deptId;
    selectedDepartmentName = deptName;
    
    // Update breadcrumb
    updateBreadcrumb(deptName);
    
    // Update active state
    document.querySelectorAll('.tree-node').forEach(node => {
        node.classList.remove('active');
    });
    const targetNode = document.querySelector(`[data-dept-id="${deptId}"] > .tree-node`);
    if (targetNode) {
        targetNode.classList.add('active');
    }
    
    // Hide table view, show filters and cards
    const tableContainer = document.getElementById('tableContainer');
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const noFilterMessage = document.getElementById('noFilterMessage');
    const filtersSection = document.getElementById('filtersSection');
    
    if (tableContainer) tableContainer.style.display = 'none';
    if (filtersSection) filtersSection.style.display = 'flex';
    
    // Filter item tables by department (works for all departments including "All Departments")
    let filteredTables = allItemTables;
    if (deptId !== 'all') {
        filteredTables = allItemTables.filter(table => table.department_id == deptId);
    }
    
    // Check if there's a search term
    const searchTerm = document.getElementById('nameFilter').value.trim();
    
    if (!searchTerm) {
        // No search term - show search prompt
        if (noFilterMessage) noFilterMessage.style.display = 'block';
        if (cardsContainer) cardsContainer.style.display = 'none';
        updateSummary([]);
        return;
    }
    
    // Filter tables by search term
    filteredTables = filteredTables.filter(table => 
        table.table_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        (table.category && table.category.toLowerCase().includes(searchTerm.toLowerCase()))
    );
    
    if (filteredTables.length === 0) {
        if (noFilterMessage) {
            noFilterMessage.innerHTML = `
                <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 60px 40px; text-align: center;">
                    <div style="font-size: 64px; margin-bottom: 20px;">🔍</div>
                    <h3 style="color: #2d3748; margin-bottom: 12px; font-size: 24px; font-weight: 600;">No Items Found</h3>
                    <p style="color: #718096; font-size: 16px; max-width: 500px; margin: 0 auto;">
                        No item tables match your search criteria. Try a different search term.
                    </p>
                </div>
            `;
            noFilterMessage.style.display = 'block';
        }
        if (cardsContainer) cardsContainer.style.display = 'none';
    } else {
        if (noFilterMessage) noFilterMessage.style.display = 'none';
        displayItemTables(filteredTables);
    }
    
    updateSummary(filteredTables);
}

// Perform search - searches for item tables only (no items table display)
async function performSearch() {
    const searchTerm = document.getElementById('nameFilter').value.trim().toLowerCase();
    
    const noFilterMessage = document.getElementById('noFilterMessage');
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.getElementById('tableContainer');
    const filtersSection = document.getElementById('filtersSection');
    
    // Show filters section and hide table view (we don't show items table anymore)
    if (filtersSection) filtersSection.style.display = 'flex';
    if (tableContainer) {
        tableContainer.style.display = 'none';
    }
    
    // Require search term to show tables
    if (!searchTerm) {
        // Show search prompt
        if (noFilterMessage) {
            noFilterMessage.innerHTML = `
                <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 40px; text-align: center; margin-top: 30px;">
                    <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                    <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 20px; font-weight: 600;">Search for Items to View Them</h3>
                    <p style="color: #718096; font-size: 14px; max-width: 500px; margin: 0 auto;">
                        Please use the search box above to search for items. Items will only be displayed after you perform a search.
                    </p>
                </div>
            `;
            noFilterMessage.style.display = 'block';
        }
        if (cardsContainer) cardsContainer.style.display = 'none';
        updateSummary([]);
        return;
    }
    
    // First filter by department (works for all departments including "All Departments")
    let filteredTables = allItemTables;
    if (selectedDepartmentId !== 'all') {
        filteredTables = allItemTables.filter(table => table.department_id == selectedDepartmentId);
    }
    
    // Then filter by search term - search in table name, category, and items within tables
    const matchingTables = [];
    
    // Use Promise.all for better performance
    const tableChecks = filteredTables.map(async (table) => {
        const tableNameMatch = table.table_name.toLowerCase().includes(searchTerm);
        const categoryMatch = table.category && table.category.toLowerCase().includes(searchTerm);
        
        // Also check if any items in this table match
        let hasMatchingItems = false;
        try {
            const itemsResponse = await fetch(`crud.php?action=get_items_by_table&table_id=${table.id}`);
            const itemsData = await itemsResponse.json();
            if (itemsData.success && itemsData.items) {
                hasMatchingItems = itemsData.items.some(item => {
                    const itemName = (item.name || '').toLowerCase();
                    const itemCode = (item.item_code || '').toLowerCase();
                    return itemName.includes(searchTerm) || itemCode.includes(searchTerm);
                });
            }
        } catch (error) {
            console.error(`Error checking items for table ${table.id}:`, error);
        }
        
        if (tableNameMatch || categoryMatch || hasMatchingItems) {
            return table;
        }
        return null;
    });
    
    const results = await Promise.all(tableChecks);
    const validTables = results.filter(table => table !== null);
    matchingTables.push(...validTables);
    
    if (matchingTables.length === 0) {
        if (noFilterMessage) {
            noFilterMessage.innerHTML = `
                <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 60px 40px; text-align: center;">
                    <div style="font-size: 64px; margin-bottom: 20px;">🔍</div>
                    <h3 style="color: #2d3748; margin-bottom: 12px; font-size: 24px; font-weight: 600;">No Items Found</h3>
                    <p style="color: #718096; font-size: 16px; max-width: 500px; margin: 0 auto;">
                        No item tables match your search criteria. Try a different search term.
                    </p>
                </div>
            `;
            noFilterMessage.style.display = 'block';
        }
        if (cardsContainer) cardsContainer.style.display = 'none';
    } else {
        if (noFilterMessage) noFilterMessage.style.display = 'none';
        displayItemTables(matchingTables);
    }
    
    updateSummary(matchingTables);
}

// Display item tables in card view
function displayItemTables(tables) {
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const noFilterMessage = document.getElementById('noFilterMessage');
    
    if (!cardsContainer) return;
    
    if (noFilterMessage) {
        noFilterMessage.style.display = 'none';
    }
    
    if (!tables || tables.length === 0) {
        cardsContainer.innerHTML = `
            <div class="no-items-card">
                <div class="no-items-icon">📦</div>
                <h3>No Item Tables Found</h3>
                <p>No item tables found for the selected department</p>
            </div>
        `;
        cardsContainer.style.display = 'grid';
        return;
    }
    
    // Load item count for each table and create cards
    const cardsPromises = tables.map(async (table) => {
        const tableImage = table.table_image_path ? 
            `<img src="${table.table_image_path}" alt="${table.table_name}" class="item-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" /><div class="item-image-placeholder" style="display: none;">📦</div>` : 
            `<div class="item-image-placeholder">📦</div>`;
        
        // Get item count and available items count for this table
        let itemCount = 0;
        let availableItemCount = 0;
        try {
            const countResponse = await fetch(`crud.php?action=get_items_by_table&table_id=${table.id}`);
            const countData = await countResponse.json();
            if (countData.success && countData.items) {
                itemCount = countData.items.length;
                // Count available items (status = "Working")
                availableItemCount = countData.items.filter(item => {
                    const status = (item.display_status || item.status || '').toLowerCase();
                    return status === 'working';
                }).length;
            }
        } catch (error) {
            console.error(`Error getting item count for table ${table.id}:`, error);
        }
        
        const escapedTableName = (table.table_name || '').replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\n/g, ' ').replace(/\r/g, '');
        return `
            <div class="item-card clickable-card" data-table-id="${table.id}" style="cursor: default;">
                <div class="item-image-container">
                    ${tableImage}
                </div>
                <div class="item-card-content">
                    <div class="item-title-row">
                        <div class="item-card-title">${table.table_name}</div>
                        <div class="card-action-dropdown">
                            <button class="card-action-btn-menu" onclick="event.stopPropagation(); toggleTableActionMenu('${table.id}')">⋮</button>
                            <div class="card-action-menu" id="card-menu-${table.id}">
                                <button onclick="requestBorrowFromTable('${table.id}', event)">
                                    <img src="image/book.png" alt="Borrow" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;" />
                                    Request to Borrow
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="quantity-text">Category: ${table.category || 'Uncategorized'}</div>
                    <div class="quantity-text">Available Items: ${availableItemCount}</div>
                    <div class="meta-row">
                        <div class="meta">
                            <span class="meta-label">Department:</span>
                            <span class="meta-value">${table.department_name || 'Unknown'}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    Promise.all(cardsPromises).then(cardsHTML => {
        cardsContainer.innerHTML = cardsHTML.join('');
        cardsContainer.style.display = 'grid';
        cardsContainer.classList.add('grid-layout');
    });
}

// Update breadcrumb
function updateBreadcrumb(deptName, tableName = null) {
    const separator = document.getElementById('breadcrumbSeparator');
    const deptBreadcrumb = document.getElementById('breadcrumbDepartment');
    const tableSeparator = document.getElementById('breadcrumbTableSeparator');
    const tableBreadcrumb = document.getElementById('breadcrumbTable');
    
    if (deptName === 'All Departments') {
        separator.style.display = 'none';
        deptBreadcrumb.style.display = 'none';
        tableSeparator.style.display = 'none';
        tableBreadcrumb.style.display = 'none';
    } else {
        separator.style.display = 'inline';
        deptBreadcrumb.style.display = 'inline';
        deptBreadcrumb.innerHTML = `<span class="clickable" onclick="selectDepartment(selectedDepartmentId, '${deptName}')" style="cursor: pointer; color: #333; text-decoration: underline;">${deptName}</span>`;
        
        if (tableName) {
            tableSeparator.style.display = 'inline';
            tableBreadcrumb.style.display = 'inline';
            tableBreadcrumb.textContent = tableName;
        } else {
            tableSeparator.style.display = 'none';
            tableBreadcrumb.style.display = 'none';
        }
    }
}

// Show items from selected item table
function showTableItems(tableId, tableName) {
    console.log('Showing items for table:', tableId, tableName);
    
    // Store current table info
    window.currentTableId = tableId;
    window.currentTableName = tableName;
    currentTableId = tableId;
    currentTableName = tableName;
    
    // Update breadcrumb
    updateBreadcrumb(selectedDepartmentName, tableName);
    
    // Hide cards and show table container
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.getElementById('tableContainer');
    const noFilterMessage = document.getElementById('noFilterMessage');
    const filtersSection = document.getElementById('filtersSection');
    
    if (cardsContainer) cardsContainer.style.display = 'none';
    if (noFilterMessage) noFilterMessage.style.display = 'none';
    if (filtersSection) filtersSection.style.display = 'flex';
    
    // Load item table details and items
    console.log('Loading table details and items for table ID:', tableId);
    Promise.all([
        fetch(`crud.php?action=get_item_table&id=${tableId}`).then(r => {
            console.log('Table details response status:', r.status);
            return r.json();
        }),
        fetch(`crud.php?action=get_items_by_table&table_id=${tableId}`).then(r => {
            console.log('Items response status:', r.status);
            return r.json();
        })
    ])
    .then(([tableData, itemsData]) => {
        console.log('Table data:', tableData);
        console.log('Items data:', itemsData);
        
        // Check if table data loaded successfully
        if (!tableData.success) {
            console.warn('Table details not loaded, using defaults');
        }
        
        if (!itemsData.success) {
            console.error('Error loading items for table:', itemsData.message);
            if (tableContainer) {
                tableContainer.style.display = 'block';
                tableContainer.innerHTML = `
                    <div class="no-items-card">
                        <div class="no-items-icon">❌</div>
                        <h3>Error Loading Items</h3>
                        <p>${itemsData.message || 'Unable to load items from this table'}</p>
                    </div>
                `;
            }
            return;
        }
            
        let tableItems = itemsData.items || [];
            // Store items globally for borrow modal access
            allTableItems = tableItems;
        window.allTableItems = tableItems;
            console.log('Found items for table:', tableItems.length);
            
        // Get table details
        const tableDetails = tableData.success && tableData.item_table ? tableData.item_table : null;
        const tableCategory = tableDetails ? (tableDetails.category || 'Uncategorized') : 'Uncategorized';
        const tableDepartment = tableDetails ? (tableDetails.department_name || 'Unknown') : 'Unknown';
        const tableCreated = tableDetails && tableDetails.created_at ? new Date(tableDetails.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: '2-digit' }) : 'N/A';
        
        // Calculate totals
        const itemCount = tableItems.length;
        const totalQuantity = tableItems.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
        const stockLevel = itemCount <= 5 ? 'LOW' : 'HIGH';
        const stockLevelColor = itemCount <= 5 ? '#10b981' : '#dc2626';
        const stockLevelBg = itemCount <= 5 ? '#d1fae5' : '#fee2e2';
        
        // Update summary
        updateItemTableSummary(itemCount, totalQuantity);
        
        // Store items for filtering
        window.currentTableItems = tableItems;
        
        // Show search prompt instead of displaying all items immediately (like teacher version)
        if (tableContainer) {
            if (tableItems.length === 0) {
                tableContainer.style.display = 'block';
                tableContainer.innerHTML = `
                    <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 60px 40px; text-align: center;">
                        <div style="font-size: 64px; margin-bottom: 20px;">📦</div>
                        <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 20px; font-weight: 600;">No Items Found</h3>
                        <p style="color: #718096; font-size: 14px;">
                            This item table is empty.
                        </p>
                    </div>
                `;
            } else {
                // Show search prompt - require search first (like teacher version)
                tableContainer.style.display = 'block';
                tableContainer.innerHTML = `
                    <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 60px 40px; text-align: center;">
                        <div style="font-size: 64px; margin-bottom: 20px;">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="11" cy="11" r="8" stroke="#3b82f6" stroke-width="2" fill="none"/>
                                <path d="m21 21-4.35-4.35" stroke="#a855f7" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3 style="color: #2d3748; margin-bottom: 12px; font-size: 24px; font-weight: 600;">Search for Items to View Them</h3>
                        <p style="color: #718096; font-size: 16px; max-width: 500px; margin: 0 auto;">
                            Please use the search box above to search for items. Items will only be displayed after you perform a search.
                        </p>
                    </div>
                `;
            }
        }
    })
    .catch(error => {
        console.error('Error loading items for table:', error);
        console.error('Error stack:', error.stack);
        
        if (tableContainer) {
            tableContainer.style.display = 'block';
            tableContainer.innerHTML = `
                <div class="no-items-card">
                    <div class="no-items-icon">❌</div>
                    <h3>Error Loading Items</h3>
                    <p>Unable to load items from this table. Please try again.</p>
                    <p style="color: #dc2626; font-size: 12px; margin-top: 8px;">${error.message || ''}</p>
                    <button onclick="goBackToTables()" style="margin-top: 20px; padding: 10px 20px; background: #e53e3e; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        Back to Item Tables
                    </button>
                </div>
            `;
        }
    });
}

// Display items in table
function filterItems() {
    const tableContainer = document.getElementById('tableContainer');
    
    if (!window.currentTableItems || window.currentTableItems.length === 0) {
        return;
    }
    
    let filteredItems = window.currentTableItems;
    
    // Sort by modified date (using current sort order)
    filteredItems.sort((a, b) => {
        const dateA = a.updated_at || a.created_at || '';
        const dateB = b.updated_at || b.created_at || '';
        return sortOrder === 'desc' ? new Date(dateB) - new Date(dateA) : new Date(dateA) - new Date(dateB);
    });
    
    // Generate table HTML
    const tableHTML = generateItemsTableHTML(filteredItems);
    
    if (tableContainer) {
        tableContainer.innerHTML = tableHTML;
        tableContainer.style.display = 'block';
    }
    
    // Update summary
    const totalQuantity = filteredItems.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
    updateItemTableSummary(filteredItems.length, totalQuantity);
}

// Format date helper (matching department.php)
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return 'N/A';
    return date.toLocaleString('en-US', { 
        month: '2-digit', 
        day: '2-digit', 
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });
}

// Generate items table HTML (matching department.php structure)
function generateItemsTableHTML(items) {
    if (items.length === 0) {
        return `
            <div style="background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 40px; text-align: center;">
                <div style="font-size: 48px; margin-bottom: 16px;">🔍</div>
                <h3 style="color: #2d3748; margin-bottom: 8px; font-size: 20px; font-weight: 600;">No Items Found</h3>
                <p style="color: #718096; font-size: 14px;">
                    No items match your search criteria.
                </p>
            </div>
        `;
    }
    
    return `
        <div class="category-table-wrap">
            <div class="category-title">${escapeHtml(window.currentTableName || 'Items')}</div>
            <table class="data-table category-table">
                <thead>
                    <tr>
                        <th class="sortable">ID</th>
                        <th class="sortable">Item Code</th>
                        <th class="sortable">Name</th>
                        <th class="sortable">Department</th>
                        <th class="sortable">Category</th>
                        <th class="sortable" onclick="sortByModified()" style="cursor: pointer;">
                            Modified <span id="sortIndicator">↓</span>
                        </th>
                        <th class="sortable">Status</th>
                        <th class="sortable">Location</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map(item => {
                        const itemStatus = (item.display_status || item.status || 'Unknown');
                        const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance', 'Consumable'];
                        const canBorrow = !nonBorrowableStatuses.includes(itemStatus);
                        const hasPendingRequest = item.has_pending_request === 1 || item.has_pending_request === true;
                        
                        let actionHtml = '';
                        if (hasPendingRequest) {
                            actionHtml = `
                                <td>
                                    <span class="pending-approval-badge" style="display: inline-block; padding: 6px 12px; background-color: #f59e0b; color: white; border-radius: 6px; font-size: 13px; font-weight: 500;">
                                        Pending Approval Request
                                    </span>
                                </td>
                            `;
                        } else if (itemStatus === 'Consumable') {
                            actionHtml = `
                                <td>
                                    <span class="consumable-not-available-badge" style="display: inline-block; padding: 6px 12px; background-color: #fef3c7; color: #92400e; border-radius: 6px; font-size: 13px; font-weight: 500; border: 1px solid #fcd34d;">
                                        Consumable not available to borrow
                                    </span>
                                </td>
                            `;
                        } else {
                            let borrowTitle = 'Request to borrow this item';
                            if (!canBorrow) {
                                if (itemStatus === 'Borrowed') {
                                    borrowTitle = 'Item is already borrowed';
                                } else if (itemStatus === 'Broken') {
                                    borrowTitle = 'Item is broken and cannot be borrowed';
                                } else if (itemStatus === 'Missing') {
                                    borrowTitle = 'Item is missing and cannot be borrowed';
                                } else if (itemStatus === 'Lost') {
                                    borrowTitle = 'Item is lost and cannot be borrowed';
                                } else if (itemStatus === 'Under Maintenance') {
                                    borrowTitle = 'Item is under maintenance and cannot be borrowed';
                                } else {
                                    borrowTitle = 'Item cannot be borrowed';
                                }
                            }
                            actionHtml = `
                                <td>
                                    <button class="viewer-borrow-btn" onclick="openViewerBorrowModal(${item.id}, '${escapeHtml(item.name).replace(/'/g, "\\'")}', '${escapeHtml(item.item_code || '')}')" ${!canBorrow ? 'disabled' : ''} title="${borrowTitle}">
                                        <img src="image/book.png" alt="Borrow" style="width:14px;height:14px;vertical-align:middle;flex-shrink:0;" />
                                        <span style="white-space: nowrap;">Request to Borrow</span>
                                    </button>
                                </td>
                            `;
                        }
                        
                        return `
                            <tr>
                                <td>${item.id}</td>
                                <td><span class="item-code-text" style="font-family: monospace; font-weight: bold; color: #2563eb;">${escapeHtml(item.item_code || 'N/A')}</span></td>
                                <td><span class="item-name-text">${escapeHtml(item.name)}</span></td>
                                <td>${escapeHtml(item.department_name || 'Unknown')}</td>
                                <td>${escapeHtml(item.category || 'Uncategorized')}</td>
                                <td>${formatDate(item.updated_at)}</td>
                                <td><span class="status-badge status-${itemStatus.toLowerCase().replace(/\s+/g, '-')}">${escapeHtml(itemStatus)}</span></td>
                                <td>${escapeHtml(item.location || 'N/A')}</td>
                                ${actionHtml}
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;
}

// Sort by modified date
let sortOrder = 'desc';
function sortByModified() {
    sortOrder = sortOrder === 'desc' ? 'asc' : 'desc';
    const indicator = document.getElementById('sortIndicator');
    if (indicator) {
        indicator.textContent = sortOrder === 'desc' ? '↓' : '↑';
    }
    
    if (window.currentTableItems) {
        window.currentTableItems.sort((a, b) => {
            const dateA = a.updated_at || a.created_at || '';
            const dateB = b.updated_at || b.created_at || '';
            return sortOrder === 'desc' ? new Date(dateB) - new Date(dateA) : new Date(dateA) - new Date(dateB);
        });
        filterItems();
    }
}

// Update item table summary
function updateItemTableSummary(itemCount, totalQuantity) {
    const itemTableCountEl = document.getElementById('itemTableCount');
    const itemCountEl = document.getElementById('itemCount');
    const totalQuantityEl = document.getElementById('totalQuantity');
    
    if (itemTableCountEl) itemTableCountEl.textContent = '1';
    if (itemCountEl) itemCountEl.textContent = itemCount;
    if (totalQuantityEl) totalQuantityEl.textContent = totalQuantity + ' units';
}

// Go back to item tables view
function goBackToTables() {
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.getElementById('tableContainer');
    const filtersSection = document.getElementById('filtersSection');
    const searchInput = document.getElementById('nameFilter');
    
    // Update breadcrumb
    updateBreadcrumb(selectedDepartmentName);
    
    if (tableContainer) tableContainer.style.display = 'none';
    if (filtersSection) filtersSection.style.display = 'flex';
    
    // Clear current table items
    window.currentTableItems = null;
    window.currentTableId = null;
    window.currentTableName = null;
    
    // Clear search field to show search prompt
    if (searchInput) {
        searchInput.value = '';
    }
    
    if (cardsContainer) {
        cardsContainer.style.display = 'grid';
        // Reload current view (will show search prompt since search is cleared)
        selectDepartment(selectedDepartmentId, selectedDepartmentName);
    }
}

// Update summary
function updateSummary(tables = null) {
    const tablesToCount = tables !== null ? tables : allItemTables;
    let itemCount = 0;
    
    // Count total items in all tables
    tablesToCount.forEach(table => {
        // This is approximate, actual count would require loading each table
        // For now, just count tables
    });
    
    document.getElementById('itemCount').textContent = tablesToCount.length;
    document.getElementById('totalQuantity').textContent = tablesToCount.length + ' tables';
}

// Show no tables message
function showNoTablesMessage() {
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const noFilterMessage = document.getElementById('noFilterMessage');
    
    if (cardsContainer) {
        cardsContainer.innerHTML = `
            <div class="no-items-card">
                <div class="no-items-icon">📦</div>
                <h3>No Item Tables Found</h3>
                <p>No item tables available from other departments</p>
            </div>
        `;
        cardsContainer.style.display = 'grid';
    }
}

// View toggle functions
function switchToGridView() {
    const gridBtn = document.getElementById('gridViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.getElementById('tableContainer');
    
    if (gridBtn) gridBtn.classList.add('active');
    if (listBtn) listBtn.classList.remove('active');
    
    // Show cards, hide table
    if (cardsContainer && cardsContainer.innerHTML.trim() !== '') {
        cardsContainer.style.display = 'grid';
    }
    if (tableContainer) {
        tableContainer.style.display = 'none';
    }
}

function switchToListView() {
    const gridBtn = document.getElementById('gridViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    const cardsContainer = document.getElementById('itemsCardsContainer');
    const tableContainer = document.getElementById('tableContainer');
    
    if (gridBtn) gridBtn.classList.remove('active');
    if (listBtn) listBtn.classList.add('active');
    
    // If we have a table with items, show it; otherwise show cards
    if (tableContainer && window.currentTableItems && window.currentTableItems.length > 0) {
        tableContainer.style.display = 'block';
        if (cardsContainer) cardsContainer.style.display = 'none';
    } else if (cardsContainer && cardsContainer.innerHTML.trim() !== '') {
        cardsContainer.style.display = 'grid';
        if (tableContainer) tableContainer.style.display = 'none';
    }
}

// Allow Enter key to trigger search
document.getElementById('nameFilter').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        performSearch();
    }
});

// Real-time search as user types (with debounce)
let searchTimeout;
document.getElementById('nameFilter').addEventListener('input', function(e) {
    // Clear previous timeout
    clearTimeout(searchTimeout);
    
    // Set new timeout to trigger search after 300ms of no typing
    searchTimeout = setTimeout(function() {
        performSearch();
    }, 300);
});

// Toggle table action menu (dropdown)
function toggleTableActionMenu(tableId) {
    // Close all other open menus
    document.querySelectorAll('.card-action-menu').forEach(menu => {
        if (menu.id !== `card-menu-${tableId}`) {
            menu.classList.remove('show');
        }
    });
    
    // Toggle current menu
    const menu = document.getElementById(`card-menu-${tableId}`);
    if (menu) {
        menu.classList.toggle('show');
        
        // Close menu when clicking outside
        const closeMenu = function(e) {
            if (!e.target.closest('.card-action-dropdown')) {
                menu.classList.remove('show');
                document.removeEventListener('click', closeMenu);
            }
        };
        document.addEventListener('click', closeMenu);
    }
}

// Request to borrow from item table - randomly selects an available item
function requestBorrowFromTable(tableId, event) {
    if (event) {
        event.stopPropagation();
    }
    
    // Close the menu
    const menu = document.getElementById(`card-menu-${tableId}`);
    if (menu) {
        menu.classList.remove('show');
    }
    
    // Fetch items from the table
    fetch(`crud.php?action=get_items_by_table&table_id=${tableId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.items) {
                const modalObj = window.modal || {};
                if (modalObj.warning) {
                    modalObj.warning('Unable to load items from this table. Please try again.');
                } else {
                    alert('Unable to load items from this table. Please try again.');
                }
                return;
            }
            
            // Filter for available items (status = "Working")
            const availableItems = data.items.filter(item => {
                const status = (item.display_status || item.status || '').toLowerCase();
                return status === 'working';
            });
            
            if (availableItems.length === 0) {
                const modalObj = window.modal || {};
                if (modalObj.warning) {
                    modalObj.warning('No available items (Working status) found in this table.');
                } else {
                    alert('No available items (Working status) found in this table.');
                }
                return;
            }
            
            // Randomly select one available item
            const randomIndex = Math.floor(Math.random() * availableItems.length);
            const selectedItem = availableItems[randomIndex];
            
            // Open borrow modal with the selected item
            openViewerBorrowModal(
                selectedItem.id,
                selectedItem.name || 'Unknown Item',
                selectedItem.item_code || ''
            );
        })
        .catch(error => {
            console.error('Error fetching items for table:', error);
            const modalObj = window.modal || {};
            if (modalObj.error) {
                modalObj.error('Error loading items. Please try again.');
            } else {
                alert('Error loading items. Please try again.');
            }
        });
}

// Viewer Borrow Modal Functions
function openViewerBorrowModal(itemId, itemName, itemCode) {
    const modal = document.getElementById('viewerBorrowModal');
    if (!modal) {
        console.error('Viewer borrow modal not found');
        return;
    }
    
    // Check if item can be borrowed - fetch item details from API
    fetch(`crud.php?action=get_item&id=${itemId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.item) {
                const item = data.item;
                const itemStatus = (item.display_status || item.status || 'Unknown');
                const nonBorrowableStatuses = ['Borrowed', 'Broken', 'Missing', 'Lost', 'Under Maintenance', 'Consumable'];
                if (nonBorrowableStatuses.includes(itemStatus)) {
                    const modalObj = window.modal || modal;
                    let errorMessage = 'Cannot borrow this item. ';
                    if (itemStatus === 'Broken') {
                        errorMessage += 'Item is broken and cannot be borrowed.';
                    } else if (itemStatus === 'Missing') {
                        errorMessage += 'Item is missing and cannot be borrowed.';
                    } else if (itemStatus === 'Lost') {
                        errorMessage += 'Item is lost and cannot be borrowed.';
                    } else if (itemStatus === 'Under Maintenance') {
                        errorMessage += 'Item is under maintenance and cannot be borrowed.';
                    } else if (itemStatus === 'Borrowed') {
                        errorMessage += 'Item is already borrowed.';
                    } else if (itemStatus === 'Consumable') {
                        errorMessage += 'Consumable not available to borrow.';
                    } else {
                        errorMessage += 'Item status does not allow borrowing.';
                    }
                    if (modalObj && modalObj.warning) {
                        modalObj.warning(errorMessage);
                    } else {
                        alert(errorMessage);
                    }
                    return;
                }
            }
            // Continue with opening modal if item is borrowable
            openBorrowModalInternal(modal, itemId, itemName, itemCode);
        })
        .catch(error => {
            console.error('Error checking item status:', error);
            // Continue with opening modal even if check fails
            openBorrowModalInternal(modal, itemId, itemName, itemCode);
        });
}

function openBorrowModalInternal(modal, itemId, itemName, itemCode) {
    // Get user info from body data attributes
    const body = document.body;
    const username = body.dataset.userUsername || '';
    const email = body.dataset.userEmail || '';
    
    // Set item details
    document.getElementById('viewerBorrowItemId').value = itemId;
    document.getElementById('viewerBorrowItemName').value = itemName;
    document.getElementById('viewerBorrowItemCode').value = itemCode || 'N/A';
    
    // Auto-populate user info
    document.getElementById('viewerBorrowerName').value = username;
    document.getElementById('viewerBorrowerEmail').value = email;
    
    // Set default dates
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('viewerBorrowDate').value = today;
    
    const neededDate = new Date();
    neededDate.setDate(neededDate.getDate() + 1);
    document.getElementById('viewerNeededDate').value = neededDate.toISOString().split('T')[0];
    
    const dueDate = new Date();
    dueDate.setDate(dueDate.getDate() + 7);
    document.getElementById('viewerDueDate').value = dueDate.toISOString().split('T')[0];
    
    // Reset purpose field
    document.getElementById('viewerBorrowPurpose').value = '';
    
    // Reset item placement
    const itemPlacementSelect = document.getElementById('viewerItemPlacement');
    if (itemPlacementSelect) {
        itemPlacementSelect.value = '';
        loadLocationsForBorrowModal();
    }
    
    // Show modal
    modal.style.display = 'flex';
    modal.style.pointerEvents = 'auto';
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    document.body.classList.add('modal-open');
    
    // Focus on purpose field
    setTimeout(() => {
        const purposeField = document.getElementById('viewerBorrowPurpose');
        if (purposeField) {
            purposeField.focus();
        }
    }, 300);
}

function loadLocationsForBorrowModal() {
    const itemPlacementSelect = document.getElementById('viewerItemPlacement');
    if (!itemPlacementSelect) return;
    
    itemPlacementSelect.innerHTML = '<option value="">Select Location</option>';
    
    fetch('crud.php?action=get_locations', {
        method: 'GET',
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.locations) {
            data.locations.forEach(location => {
                const option = document.createElement('option');
                // Format: Building, Floor X, Room Name (if available) or Room Number (if no Room Name)
                let displayText = location.building_name + ', Floor ' + location.floor_number + ', ';
                if (location.room_name && location.room_name.trim() !== '') {
                    displayText += location.room_name;
                } else {
                    displayText += location.room_number;
                }
                option.value = location.full_location;
                option.textContent = displayText;
                itemPlacementSelect.appendChild(option);
            });
        }
    })
    .catch(error => {
        console.error('Error loading locations:', error);
    });
}

function closeViewerBorrowModal() {
    const modal = document.getElementById('viewerBorrowModal');
    if (!modal) return;
    
    modal.style.display = 'none';
    modal.classList.remove('show');
    document.body.style.overflow = 'auto';
    document.body.classList.remove('modal-open');
    
    const form = document.getElementById('viewerBorrowForm');
    if (form) {
        form.reset();
    }
    
    const submitBtn = document.getElementById('viewerBorrowSubmitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Borrow Request';
        submitBtn.removeAttribute('data-submitting');
    }
}

function submitViewerBorrowRequest() {
    const btn = document.getElementById('viewerBorrowSubmitBtn');
    if (btn && (btn.disabled || btn.hasAttribute('data-submitting'))) {
        return;
    }
    
    if (btn) {
        btn.setAttribute('data-submitting', 'true');
        btn.disabled = true;
    }
    
    const itemId = document.getElementById('viewerBorrowItemId').value;
    const itemName = document.getElementById('viewerBorrowItemName').value;
    const borrowerName = document.getElementById('viewerBorrowerName').value.trim();
    const borrowerEmail = document.getElementById('viewerBorrowerEmail').value.trim();
    const borrowDate = document.getElementById('viewerBorrowDate').value;
    const neededDate = document.getElementById('viewerNeededDate').value;
    const dueDate = document.getElementById('viewerDueDate').value;
    const itemPlacement = document.getElementById('viewerItemPlacement').value.trim();
    const purpose = document.getElementById('viewerBorrowPurpose').value.trim();
    
    const modalObj = window.modal || {};
    
    if (!borrowerName || !borrowerEmail || !borrowDate || !neededDate || !dueDate) {
        if (btn) {
            btn.removeAttribute('data-submitting');
            btn.disabled = false;
        }
        if (modalObj.warning) {
            modalObj.warning('Please fill in all required fields.');
        } else {
            alert('Please fill in all required fields.');
        }
        return;
    }
    
    if (new Date(neededDate) < new Date(borrowDate)) {
        if (btn) {
            btn.removeAttribute('data-submitting');
            btn.disabled = false;
        }
        if (modalObj.warning) {
            modalObj.warning('Needed date cannot be before the request date (today).');
        } else {
            alert('Needed date cannot be before the request date (today).');
        }
        return;
    }
    
    if (new Date(dueDate) < new Date(neededDate)) {
        if (btn) {
            btn.removeAttribute('data-submitting');
            btn.disabled = false;
        }
        if (modalObj.warning) {
            modalObj.warning('Due date must be on or after the needed date.');
        } else {
            alert('Due date must be on or after the needed date.');
        }
        return;
    }
    
    if (btn) {
        btn.textContent = 'Submitting...';
    }
    
    const borrowId = 'BRW-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5).toUpperCase();
    
    const formData = new FormData();
    formData.append('action', 'borrow');
    formData.append('borrow_id', borrowId);
    formData.append('borrower_name', borrowerName);
    formData.append('borrower_email', borrowerEmail);
    formData.append('item_id', itemId);
    formData.append('quantity', '1');
    formData.append('borrow_date', borrowDate);
    formData.append('date_needed', neededDate);
    formData.append('due_date', dueDate);
    formData.append('item_placement', itemPlacement);
    formData.append('purpose', purpose);
    
    fetch('crud.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (modalObj.success) {
                modalObj.success('Borrow request submitted successfully! You will be notified once it is approved.');
            } else {
                alert('Borrow request submitted successfully!');
            }
            closeViewerBorrowModal();
            // Note: We don't reload items table anymore since we don't display it
        } else {
            if (btn) {
                btn.removeAttribute('data-submitting');
                btn.disabled = false;
                btn.textContent = 'Submit Borrow Request';
            }
            if (modalObj.error) {
                modalObj.error(data.message || 'Failed to submit borrow request.');
            } else {
                alert('Error: ' + (data.message || 'Failed to submit borrow request.'));
            }
        }
    })
    .catch(error => {
        console.error('Error submitting borrow request:', error);
        if (btn) {
            btn.removeAttribute('data-submitting');
            btn.disabled = false;
            btn.textContent = 'Submit Borrow Request';
        }
        if (modalObj.error) {
            modalObj.error('Error submitting borrow request. Please try again.');
        } else {
            alert('Error submitting borrow request. Please try again.');
        }
    });
}

// Store current table items for borrow modal access
let allTableItems = [];
let currentTableId = null;
let currentTableName = null;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadItemTables();
    
    // Date validation for needed date
    const neededDateInput = document.getElementById('viewerNeededDate');
    if (neededDateInput) {
        neededDateInput.addEventListener('change', function() {
            const borrowDateInput = document.getElementById('viewerBorrowDate');
            if (borrowDateInput && borrowDateInput.value) {
                const minNeededDate = new Date(borrowDateInput.value);
                minNeededDate.setDate(minNeededDate.getDate());
                this.min = minNeededDate.toISOString().split('T')[0];
            }
        });
    }
    
    // Date validation for due date
    const dueDateInput = document.getElementById('viewerDueDate');
    if (dueDateInput) {
        dueDateInput.addEventListener('change', function() {
            const neededDateInput = document.getElementById('viewerNeededDate');
            if (neededDateInput && neededDateInput.value) {
                const minDueDate = new Date(neededDateInput.value);
                this.min = minDueDate.toISOString().split('T')[0];
            }
        });
    }
});
</script>

</body>
</html>


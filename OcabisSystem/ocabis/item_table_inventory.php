<?php
include '../db_connect.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Check if user has permission to access item table inventory
// Only department users, admin, and super admin can access
$isViewer = isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'viewer';
$isAdmin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$isSuperAdmin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
$hasDepartment = isset($_SESSION['department']) && !empty(trim($_SESSION['department']));

// Deny access if user is viewer or doesn't have department (unless admin/super_admin)
if ($isViewer || (!$hasDepartment && !$isAdmin && !$isSuperAdmin)) {
    header("Location: dashboard.php?error=access_denied");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>Item Table Inventory - OCABIS</title>
    <link rel="stylesheet" href="Css/item_table_inventory.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Standalone Page - No Sidebar */
        body {
            margin: 0;
            padding: 0;
        }
        
        .sidebar,
        .sidebar-overlay,
        .sidebar-toggle-fixed {
            display: none !important;
        }
        
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 20px !important;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .header h1 {
            margin: 0;
            color: #2d3748;
            font-size: 24px;
        }
        
        .back-button {
            padding: 10px 20px;
            background: #e53e3e;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s ease;
            margin-right:50px;
        }
        
        .back-button:hover {
            background: #c53030;
        }

        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            .main-content {
                padding: 10px !important;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .header h1 {
                font-size: 20px !important;
            }
            
            .back-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body data-user-logged-in="true" data-user-super-admin="<?= isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1 ? 'true' : 'false' ?>" data-user-role="<?= isset($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : '' ?>">
    
    <div class="main-content">
        <div class="header">
            <div>
                <h1>Item Table Inventory</h1>
                <p style="margin: 5px 0 0 0; color: #718096; font-size: 14px;">Scan QR code from QR Scanner page</p>
            </div>
            <a href="department.php" class="back-button">
                <i class="fas fa-arrow-left"></i> Back to Department
            </a>
        </div>

        <div class="item-table-inventory-container">
            <!-- Empty State - Show when no table loaded -->
            <div id="emptyState" style="background: white; border-radius: 12px; padding: 60px 20px; text-align: center; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);">
                <div style="font-size: 64px; color: #cbd5e0; margin-bottom: 20px;">
                    <i class="fas fa-qrcode"></i>
                </div>
                <h2 style="color: #2d3748; margin-bottom: 10px;">Scan Item Table QR Code</h2>
                <p style="color: #718096; margin-bottom: 30px;">Go to QR Scanner page and scan the item table QR code to view inventory</p>
                <a href="qrscanner.php" class="btn-primary" style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: #e53e3e; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none;">
                    <i class="fas fa-qrcode"></i> Go to QR Scanner
                </a>
            </div>

            <!-- Item Table Info Section -->
            <div id="itemTableInfo" class="item-table-info" style="display: none;">
                <div class="info-header">
                    <div class="info-title-section">
                        <div class="info-icon" id="infoIcon">
                            <i class="fas fa-table"></i>
                            <img id="tableImage" src="" alt="Table Image" style="display: none; width: 100%; height: 100%; object-fit: cover; border-radius: 12px;">
                        </div>
                        <div class="info-title-content">
                            <h3 id="tableName"></h3>
                            <div class="info-badges" id="infoBadges"></div>
                        </div>
                    </div>
                    <div class="info-details">
                        <div class="info-detail-item">
                            <i class="fas fa-tag"></i>
                            <span id="tableCategory"></span>
                        </div>
                        <div class="info-detail-item" id="tableDescriptionContainer" style="display: none;">
                            <i class="fas fa-info-circle"></i>
                            <span id="tableDescription"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div id="summaryStats" class="summary-stats" style="display: none;">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #dbeafe;">
                        <i class="fas fa-boxes" style="color: #2563eb;"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalItems">0</div>
                        <div class="stat-label">Total Items</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #d1fae5;">
                        <i class="fas fa-check-circle" style="color: #059669;"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="totalQuantity">0</div>
                        <div class="stat-label">Total Quantity</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fef3c7;">
                        <i class="fas fa-exclamation-triangle" style="color: #d97706;"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value" id="brokenItems">0</div>
                        <div class="stat-label">Broken/Lost</div>
                    </div>
                </div>
            </div>

            <!-- Inventory Table Section -->
            <div id="inventoryTableSection" class="inventory-table-section" style="display: none;">
                <div class="table-header">
                    <div class="table-header-left">
                        <h3><i class="fas fa-list"></i> Items in Table</h3>
                        <span class="items-count-badge" id="itemsCountBadge">0 items</span>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <div style="position: relative;">
                            <button class="btn-filter" onclick="toggleFilterDropdown()" id="filterBtn" style="background: #f7fafc; color: #2d3748; border: 1px solid #e2e8f0; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
                                <i class="fas fa-filter"></i> Filter
                                <i class="fas fa-chevron-down" style="font-size: 10px; margin-left: 4px;"></i>
                            </button>
                            <div id="filterDropdown" style="display: none; position: absolute; top: 100%; right: 0; margin-top: 5px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 1000; min-width: 200px; padding: 8px 0;">
                                <button onclick="applyFilter('all')" class="filter-option" style="width: 100%; text-align: left; padding: 10px 16px; border: none; background: none; cursor: pointer; font-size: 14px; color: #2d3748; transition: background 0.2s;">
                                    <i class="fas fa-list" style="margin-right: 8px; width: 16px;"></i> All Items
                                </button>
                                <button onclick="applyFilter('defective')" class="filter-option" style="width: 100%; text-align: left; padding: 10px 16px; border: none; background: none; cursor: pointer; font-size: 14px; color: #2d3748; transition: background 0.2s;">
                                    <i class="fas fa-exclamation-triangle" style="margin-right: 8px; width: 16px; color: #c53030;"></i> Defective Items
                                </button>
                            </div>
                        </div>
                        <button class="btn-primary" onclick="downloadInventoryPDF()" id="downloadPdfBtn" style="background: #dc3545; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 14px; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fas fa-file-pdf"></i> Download PDF
                        </button>
                        <button class="btn-success" onclick="saveInventory()" id="saveBtn">
                            <i class="fas fa-save"></i> Save Inventory
                        </button>
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="inventory-table" id="inventoryTable">
                        <thead>
                            <tr>
                                <th style="width: 120px;">Item Code</th>
                                <th>Item Name</th>
                                <th style="width: 100px;">Quantity</th>
                                <th style="width: 150px;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="inventoryTableBody">
                            <!-- Items will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentItemTableId = null;
        let originalInventoryData = {};
        let currentFilter = 'all'; // 'all' or 'defective'

        // Display item table info
        function displayItemTableInfo(itemTable) {
            // Hide empty state
            document.getElementById('emptyState').style.display = 'none';
            
            document.getElementById('tableName').textContent = itemTable.table_name || 'N/A';
            
            // Category badge
            const categoryBadge = `<span class="category-badge">${escapeHtml(itemTable.category || 'N/A')}</span>`;
            document.getElementById('infoBadges').innerHTML = categoryBadge;
            
            // Category text
            document.getElementById('tableCategory').textContent = itemTable.category || 'N/A';
            
            // Description
            if (itemTable.description && itemTable.description.trim()) {
                document.getElementById('tableDescription').textContent = itemTable.description;
                document.getElementById('tableDescriptionContainer').style.display = 'flex';
            } else {
                document.getElementById('tableDescriptionContainer').style.display = 'none';
            }
            
            // Display table image if available
            const iconDiv = document.getElementById('infoIcon');
            const iconElement = iconDiv.querySelector('i');
            const imageElement = document.getElementById('tableImage');
            
            if (itemTable.table_image_path && itemTable.table_image_path.trim()) {
                // Show image, hide icon
                imageElement.src = itemTable.table_image_path;
                imageElement.style.display = 'block';
                iconElement.style.display = 'none';
            } else {
                // Show icon, hide image
                imageElement.style.display = 'none';
                iconElement.style.display = 'block';
            }
            
            document.getElementById('itemTableInfo').style.display = 'block';
        }

        // Load table items
        async function loadTableItems(itemTableId) {
            try {
                const response = await fetch('item_table_inventory_api.php?action=get_items&item_table_id=' + itemTableId);
                const data = await response.json();
                
                if (data.success) {
                    displayInventoryTable(data.items);
                    document.getElementById('inventoryTableSection').style.display = 'block';
                } else {
                    alert('Error loading items: ' + data.message);
                }
            } catch (error) {
                console.error('Error loading items:', error);
                alert('Error loading items. Please try again.');
            }
        }

        // Display inventory table
        function displayInventoryTable(items) {
            const tbody = document.getElementById('inventoryTableBody');
            tbody.innerHTML = '';
            originalInventoryData = {};

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 40px; color: #9ca3af;"><i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px; display: block; opacity: 0.3;"></i>No items found in this table</td></tr>';
                updateSummaryStats(items);
                document.getElementById('itemsCountBadge').textContent = '0 items';
                return;
            }

            items.forEach(item => {
                // Store original values
                originalInventoryData[item.id] = {
                    quantity: item.quantity,
                    status: item.status
                };

                // Status badge color and check if status is read-only (Borrowed only)
                const isBorrowed = item.status === 'Borrowed';
                const isConsumable = item.status === 'Consumable';
                let statusBadgeClass = 'status-working';
                if (item.status === 'Broken') {
                    statusBadgeClass = 'status-broken';
                } else if (item.status === 'Lost') {
                    statusBadgeClass = 'status-lost';
                } else if (item.status === 'Under Maintenance') {
                    statusBadgeClass = 'status-maintenance';
                } else if (item.status === 'Borrowed') {
                    statusBadgeClass = 'status-borrowed';
                } else if (item.status === 'Consumable') {
                    statusBadgeClass = 'status-consumable';
                }
                
                const row = document.createElement('tr');
                row.dataset.itemId = item.id;
                
                // Status display - read-only badge for Borrowed, badge for Consumable (but editable), dropdown for others
                let statusDisplay;
                if (isBorrowed) {
                    // Borrowed: read-only badge (cannot edit)
                    statusDisplay = `
                        <span class="status-badge-readonly ${statusBadgeClass}" style="
                            display: inline-block;
                            padding: 6px 12px;
                            border-radius: 6px;
                            font-size: 13px;
                            font-weight: 500;
                            background: #fef3c7; 
                            color: #d97706;
                        ">
                            ${escapeHtml(item.status)}
                        </span>
                    `;
                } else if (isConsumable) {
                    // Consumable: badge (read-only status, but quantity can be edited)
                    statusDisplay = `
                        <span class="status-badge-readonly ${statusBadgeClass}" style="
                            display: inline-block;
                            padding: 6px 12px;
                            border-radius: 6px;
                            font-size: 13px;
                            font-weight: 500;
                            background: #dbeafe; 
                            color: #2563eb;
                        ">
                            ${escapeHtml(item.status)}
                        </span>
                    `;
                } else {
                    // Other statuses: editable dropdown
                    statusDisplay = `
                        <select class="status-select ${statusBadgeClass}" 
                                data-original="${item.status}"
                                onchange="checkStatusChange(${item.id}, this.value)">
                            <option value="Working" ${item.status === 'Working' ? 'selected' : ''}>Working</option>
                            <option value="Under Maintenance" ${item.status === 'Under Maintenance' ? 'selected' : ''}>Under Maintenance</option>
                            <option value="Broken" ${item.status === 'Broken' ? 'selected' : ''}>Broken</option>
                            <option value="Lost" ${item.status === 'Lost' ? 'selected' : ''}>Lost</option>
                        </select>
                    `;
                }
                
                row.innerHTML = `
                    <td>
                        <div class="item-code-cell">
                            <i class="fas fa-barcode" style="color: #9ca3af; margin-right: 6px;"></i>
                            <span class="item-code-text">${escapeHtml(item.item_code || 'N/A')}</span>
                        </div>
                    </td>
                    <td>
                        <div class="item-name-cell">
                            <strong>${escapeHtml(item.name || 'N/A')}</strong>
                            ${item.location ? `<div class="item-location"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(item.location)}</div>` : ''}
                        </div>
                    </td>
                    <td>
                        <div class="quantity-cell">
                            <input type="number" 
                                   class="quantity-input" 
                                   value="${item.quantity}" 
                                   min="0" 
                                   max="${item.quantity}"
                                   data-original="${item.quantity}"
                                   ${isBorrowed ? 'readonly style="background: #f3f4f6; cursor: not-allowed;"' : ''}
                                   onchange="checkQuantityChange(${item.id}, this.value)">
                            ${!isBorrowed ? `<button class="quantity-btn quantity-minus" onclick="adjustQuantity(${item.id}, -1)" title="Bawasan">
                                <i class="fas fa-minus"></i>
                            </button>` : ''}
                        </div>
                    </td>
                    <td>
                        ${statusDisplay}
                    </td>
                `;
                
                tbody.appendChild(row);
            });

            updateSummaryStats(items);
            document.getElementById('itemsCountBadge').textContent = `${items.length} ${items.length === 1 ? 'item' : 'items'}`;
        }

        // Update summary statistics
        function updateSummaryStats(items) {
            const totalItems = items.length;
            const totalQuantity = items.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
            const brokenItems = items.filter(item => item.status === 'Broken' || item.status === 'Lost').length;

            document.getElementById('totalItems').textContent = totalItems;
            document.getElementById('totalQuantity').textContent = totalQuantity;
            document.getElementById('brokenItems').textContent = brokenItems;
            
            if (totalItems > 0) {
                document.getElementById('summaryStats').style.display = 'flex';
            }
        }

        // Get current item status from DOM (handles both select and readonly badge)
        function getItemStatusFromRow(row) {
            const statusSelect = row.querySelector('.status-select');
            if (statusSelect) {
                return statusSelect.value;
            }
            const statusBadge = row.querySelector('.status-badge-readonly');
            if (statusBadge) {
                return statusBadge.textContent.trim();
            }
            return 'Working';
        }

        // Adjust quantity with buttons (only decrease allowed)
        function adjustQuantity(itemId, change) {
            const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
            if (!row) return;
            
            const quantityInput = row.querySelector('.quantity-input');
            if (!quantityInput) return;
            
            const original = originalInventoryData[itemId];
            if (!original) return;
            
            const currentValue = parseInt(quantityInput.value) || 0;
            const originalValue = parseInt(original.quantity) || 0;
            
            // Only allow decrease (negative change), and don't go below 0
            // Also don't allow going above original quantity
            let newValue = Math.max(0, currentValue + change);
            newValue = Math.min(newValue, originalValue); // Can't exceed original
            
            quantityInput.value = newValue;
            
            checkQuantityChange(itemId, newValue);
            updateSummaryStats(Array.from(document.querySelectorAll('#inventoryTableBody tr')).map(r => {
                const id = r.dataset.itemId;
                const qty = r.querySelector('.quantity-input')?.value || 0;
                const status = getItemStatusFromRow(r);
                return { id: parseInt(id), quantity: parseInt(qty), status: status };
            }).filter(item => item.id));
        }

        // Check quantity change
        function checkQuantityChange(itemId, newQuantity) {
            const original = originalInventoryData[itemId];
            const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
            if (!row) return;
            
            const quantityInput = row.querySelector('.quantity-input');
            if (quantityInput) {
                const originalValue = parseInt(original?.quantity) || 0;
                const newValue = parseInt(newQuantity) || 0;
                
                // Ensure quantity doesn't exceed original
                if (newValue > originalValue) {
                    quantityInput.value = originalValue;
                    newQuantity = originalValue;
                }
                
                // Only allow decrease, not increase
                quantityInput.setAttribute('max', originalValue);
            }
            
            if (original && parseInt(newQuantity) !== parseInt(original.quantity)) {
                // Quantity changed - mark row
                row.classList.add('quantity-changed');
                if (parseInt(newQuantity) < parseInt(original.quantity)) {
                    row.classList.add('quantity-decreased');
                } else {
                    row.classList.remove('quantity-decreased');
                }
            } else {
                row.classList.remove('quantity-changed', 'quantity-decreased');
            }
            
            // Update summary
            updateSummaryStats(Array.from(document.querySelectorAll('#inventoryTableBody tr')).map(r => {
                const id = r.dataset.itemId;
                const qty = r.querySelector('.quantity-input')?.value || 0;
                const status = getItemStatusFromRow(r);
                return { id: parseInt(id), quantity: parseInt(qty), status: status };
            }).filter(item => item.id));
        }

        // Check status change
        function checkStatusChange(itemId, newStatus) {
            const original = originalInventoryData[itemId];
            const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
            if (!row) return;
            
            if (original && newStatus !== original.status) {
                // Status changed - mark row
                row.classList.add('status-changed');
                if (newStatus === 'Broken' || newStatus === 'Lost') {
                    row.classList.add('status-critical');
                } else {
                    row.classList.remove('status-critical');
                }
            } else {
                row.classList.remove('status-changed', 'status-critical');
            }
            
            // Update status select class
            const statusSelect = row.querySelector('.status-select');
            if (statusSelect) {
                statusSelect.className = 'status-select';
                if (newStatus === 'Broken') {
                    statusSelect.classList.add('status-broken');
                } else if (newStatus === 'Lost') {
                    statusSelect.classList.add('status-lost');
                } else if (newStatus === 'Under Maintenance') {
                    statusSelect.classList.add('status-maintenance');
                } else {
                    statusSelect.classList.add('status-working');
                }
            }
            
            // Update summary
            updateSummaryStats(Array.from(document.querySelectorAll('#inventoryTableBody tr')).map(r => {
                const id = r.dataset.itemId;
                const qty = r.querySelector('.quantity-input')?.value || 0;
                const status = getItemStatusFromRow(r);
                return { id: parseInt(id), quantity: parseInt(qty), status: status };
            }).filter(item => item.id));
        }

        // Save inventory
        async function saveInventory() {
            if (!currentItemTableId) {
                alert('No item table selected');
                return;
            }

            const tableBody = document.getElementById('inventoryTableBody');
            const rows = tableBody.querySelectorAll('tr');
            const updates = [];

            rows.forEach(row => {
                const itemId = row.dataset.itemId;
                if (!itemId) return;
                
                const quantityInput = row.querySelector('.quantity-input');
                const statusSelect = row.querySelector('.status-select');
                const statusBadge = row.querySelector('.status-badge-readonly');
                
                if (!quantityInput) return;
                
                // Skip items with Borrowed status - they can't be edited
                if (statusBadge && statusBadge.textContent.trim() === 'Borrowed') {
                    return; // Skip this item, it's borrowed and read-only
                }
                
                // Get status - for consumable items, status is from badge (read-only)
                // For other items, status is from select dropdown
                let newStatus;
                if (statusBadge) {
                    // Consumable item - status is read-only from badge
                    newStatus = statusBadge.textContent.trim();
                } else if (statusSelect) {
                    // Regular item - status from dropdown
                    newStatus = statusSelect.value;
                } else {
                    return; // No status found
                }
                
                const newQuantity = parseInt(quantityInput.value);
                const original = originalInventoryData[itemId];

                // Only include if changed
                if (original && (newQuantity !== original.quantity || newStatus !== original.status)) {
                    updates.push({
                        item_id: itemId,
                        quantity: newQuantity,
                        status: newStatus,
                        previous_quantity: original.quantity,
                        previous_status: original.status
                    });
                }
            });

            if (updates.length === 0) {
                alert('No changes to save');
                return;
            }

            // Confirm save
            if (!confirm(`Save ${updates.length} item update(s)?`)) {
                return;
            }

            try {
                const response = await fetch('item_table_inventory_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'save_inventory',
                        item_table_id: currentItemTableId,
                        updates: updates
                    })
                });

                const data = await response.json();

                if (data.success) {
                    // Show success message
                    showNotification('Inventory saved successfully! Redirecting...', 'success');
                    
                    // Redirect to department page after 1.5 seconds
                    setTimeout(() => {
                        window.location.href = 'department.php';
                    }, 1500);
                } else {
                    showNotification('Error saving inventory: ' + data.message, 'error');
                }
            } catch (error) {
                console.error('Error saving inventory:', error);
                alert('Error saving inventory. Please try again.');
            }
        }

        // Show notification
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <div class="notification-content">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 300);
            }, 3000);
        }

        // Utility functions
        function escapeHtml(text) {
            if (!text) return 'N/A';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Check if table_id is in URL (from QR scan redirect)
        function checkUrlForTableId() {
            const urlParams = new URLSearchParams(window.location.search);
            const tableId = urlParams.get('table_id');
            
            if (tableId) {
                loadItemTableById(parseInt(tableId));
            }
        }

        // Load item table by ID
        async function loadItemTableById(tableId) {
            try {
                const response = await fetch('item_table_inventory_api.php?action=get_item_table&table_id=' + tableId);
                const data = await response.json();
                
                if (data.success) {
                    currentItemTableId = data.item_table.id;
                    displayItemTableInfo(data.item_table);
                    loadTableItems(data.item_table.id);
                } else {
                    alert('Item table not found: ' + data.message);
                }
            } catch (error) {
                console.error('Error loading item table:', error);
                alert('Error loading item table. Please try again.');
            }
        }

        // Download inventory as PDF
        function downloadInventoryPDF() {
            if (!currentItemTableId) {
                alert('No item table selected');
                return;
            }
            
            // Open PDF export in new window
            window.open('pdf_export.php?type=inventory_table&table_id=' + currentItemTableId, '_blank');
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Check if table_id is in URL (from QR scan redirect)
            checkUrlForTableId();
        });
    </script>
</body>
</html>


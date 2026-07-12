<?php
// Profile Dropdown Component
// This component can be included in any page that needs the profile dropdown functionality
?>

<!-- User Profile Section -->
<?php 
// Determine if user is a viewer (borrower) - no department and not admin
$is_viewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);
$user_department = isset($_SESSION['department']) ? $_SESSION['department'] : '';
// Determine if user is a department head (admin but not super admin)
$is_department_head = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1 && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1) && !empty($user_department);
?>
<div class="user-profile-section">
    <!-- Welcome Text on Left Side -->
    <div class="welcome-text">
        <span class="welcome-message">
            <?php 
            $display_name = htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
            $display_department = '';
            
            if ($is_department_head && !empty($user_department)) {
                // Replace "Equipment" with "Department" for professional display
                $display_department = str_ireplace(' Equipment', ' Department', $user_department);
                $display_department = htmlspecialchars($display_department, ENT_QUOTES, 'UTF-8');
            } elseif ($is_viewer && !empty($user_department)) {
                // Replace "Equipment" with "Department" for professional display
                $display_department = str_ireplace(' Equipment', ' Department', $user_department);
                $display_department = htmlspecialchars($display_department, ENT_QUOTES, 'UTF-8');
            }
            ?>
            <?php if (!empty($display_department)): ?>
                <?php if ($is_department_head): ?>
                    Welcome, <strong><?php echo $display_name; ?></strong> | <span style="color: #6b7280; font-weight: 500; font-size: 0.95em;"><?php echo $display_department; ?> Head</span>
                <?php else: ?>
                    Welcome, <strong><?php echo $display_name; ?></strong> | <span style="color: #6b7280; font-weight: 500; font-size: 0.95em;"><?php echo $display_department; ?></span>
                <?php endif; ?>
            <?php else: ?>
                Welcome, <strong><?php echo $display_name; ?></strong>
            <?php endif; ?>
        </span>
    </div>
    
    <!-- Notification container -->
    <div class="notification-container">
        <button class="notification-btn" onclick="toggleNotificationDropdown()" id="notificationBtn">
            <img src="image/notification.png" alt="Notifications" class="notification-icon">
            <span class="notification-badge" id="notificationBadge">0</span>
        </button>
        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-header">
                <h4>Notifications</h4>
                <span class="notification-count" id="notificationCount">0 total</span>
            </div>
            <div class="notification-summary" id="notificationSummary">
                <?php 
                $is_super_admin = isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1;
                $is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
                ?>
                <?php if ($is_admin || $is_super_admin): ?>
                <?php 
                // Show Item Requests for both super admin and department heads
                $is_department_head = $is_admin && !$is_super_admin;
                ?>
                <?php if ($is_super_admin || $is_department_head): ?>
                <div class="summary-item">
                    <span class="summary-label">Item Requests:</span>
                    <span class="summary-count" id="requestCount">0</span>
                </div>
                <?php endif; ?>
                <div class="summary-item">
                    <span class="summary-label">Borrow Requests:</span>
                    <span class="summary-count" id="pendingBorrowCount">0</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Overdue:</span>
                    <span class="summary-count" id="overdueCount">0</span>
                </div>
                <?php else: ?>
                <div class="summary-item">
                    <span class="summary-label">Due Soon:</span>
                    <span class="summary-count" id="dueDateCount">0</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Requests:</span>
                    <span class="summary-count" id="requestCount">0</span>
                </div>
                <?php endif; ?>
            </div>
            <div class="notification-list" id="notificationList">
                <div class="notification-item loading">
                    <div class="loading-spinner"></div>
                    Loading notifications...
                </div>
            </div>
            <div class="notification-footer">
                <?php if (isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1): ?>
                <a href="item_requests.php" class="view-all-btn">View Requests</a>
                <a href="BorrowHistory.php" class="view-all-btn">View Borrows</a>
                <?php else: ?>
                <a href="BorrowHistory.php" class="view-all-btn">View Borrows</a>
                <?php endif; ?>
                <a href="#" class="view-all-btn" onclick="showAllNotifications(); return false;">All</a>
                <a href="#" class="view-all-btn" onclick="clearNotifications(); return false;">Clear</a>
            </div>
        </div>
    </div>
    <div class="profile-dropdown">
        <button class="profile-btn" onclick="toggleProfileDropdown()">
            <img src="image/user.png" alt="Profile" class="profile-avatar">
            <span class="profile-name"><?php echo htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="dropdown-arrow">▼</span>
        </button>
        <div class="profile-dropdown-menu" id="profileDropdown">
            <div class="profile-actions">
                <?php if (!$is_viewer): ?>
                <a href="dashboard.php" class="profile-action-btn">
                    <img src="image/admin.png" alt="Dashboard" class="action-icon">
                    Dashboard
                </a>
                <?php endif; ?>
                <a href="#" class="profile-action-btn" onclick="openProfileSettings(); return false;">
                    <img src="image/edit.png" alt="Settings" class="action-icon">
                    Profile Settings
                </a>
                <a href="#" class="profile-action-btn" onclick="openEditProfile(); return false;">
                    <img src="image/profile.png" alt="Profile" class="action-icon">
                    Profile
                </a>
                <a href="logout.php" class="profile-action-btn logout-btn">
                    <img src="image/icons8-sign-out-48.png" alt="Logout" class="action-icon">
                    Sign Out
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-header-content">
                <div class="modal-icon-wrapper">
                    <div class="modal-icon" id="confirmIcon">⚠️</div>
                </div>
                <div class="modal-header-text">
                    <h3 class="modal-title" id="confirmTitle">Confirm Action</h3>
                </div>
            </div>
            <button class="close-btn" onclick="closeConfirmModal()" aria-label="Close">×</button>
        </div>
        <div class="modal-body">
            <p class="modal-message" id="confirmMessage">Are you sure you want to perform this action?</p>
        </div>
        <div class="modal-footer">
            <button class="modal-btn modal-btn-cancel" onclick="closeConfirmModal()">Cancel</button>
            <button class="modal-btn modal-btn-confirm" id="confirmActionBtn">Confirm</button>
        </div>
    </div>
</div>

<style>
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 10000;
    opacity: 0;
    transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    align-items: center;
    justify-content: center;
}

.modal-overlay.show {
    display: flex;
    opacity: 1;
}

.modal {
    background: #ffffff;
    border-radius: 16px;
    padding: 0;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 
        0 25px 50px -12px rgba(0, 0, 0, 0.25),
        0 0 0 1px rgba(0, 0, 0, 0.05);
    transform: scale(0.9) translateY(20px);
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: flex;
    flex-direction: column;
}

.modal-overlay.show .modal {
    transform: scale(1) translateY(0);
}

.modal-header {
    background: linear-gradient(135deg, #e53e3e 0%, #c53030 100%);
    padding: 24px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: hidden;
}

.modal-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
    opacity: 0.3;
    pointer-events: none;
}

.modal-header-content {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
    position: relative;
    z-index: 1;
}

.modal-icon-wrapper {
    min-width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 14px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.modal-icon {
    font-size: 32px;
    line-height: 1;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}

.modal-header-text {
    flex: 1;
}

.modal-title {
    font-size: 22px;
    font-weight: 700;
    color: #ffffff;
    margin: 0;
    letter-spacing: -0.3px;
    line-height: 1.3;
}

.close-btn {
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white;
    font-size: 24px;
    cursor: pointer;
    padding: 0;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: all 0.2s ease;
    position: relative;
    z-index: 1;
    line-height: 1;
    font-weight: 300;
}

.close-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.05);
}

.close-btn:active {
    transform: scale(0.95);
}

.modal-body {
    padding: 28px;
    flex: 1;
    overflow-y: auto;
    background: #ffffff;
}

.modal-message {
    color: #4a5568;
    line-height: 1.6;
    font-size: 16px;
    margin: 0;
    text-align: left;
}

.modal-footer {
    padding: 20px 28px 24px;
    border-top: 1px solid #e9ecef;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    background: #f8f9fa;
}

.modal-btn {
    padding: 11px 24px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 100px;
    letter-spacing: 0.3px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.modal-btn:active {
    transform: translateY(1px);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.modal-btn-cancel {
    background-color: #ffffff;
    color: #6c757d;
    border: 1px solid #e2e8f0;
}

.modal-btn-cancel:hover {
    background-color: #f8f9fa;
    border-color: #cbd5e0;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.modal-btn-confirm {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white;
    border: none;
}

.modal-btn-confirm:hover {
    background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
}

.modal-btn-confirm:active {
    transform: translateY(0);
}

/* Responsive Design */
@media (max-width: 576px) {
    .modal {
        width: 95%;
        max-width: 95%;
        border-radius: 12px;
    }
    
    .modal-header {
        padding: 20px;
    }
    
    .modal-body {
        padding: 24px 20px;
    }
    
    .modal-footer {
        padding: 16px 20px 20px;
        flex-direction: column-reverse;
    }
    
    .modal-btn {
        width: 100%;
    }
    
    .modal-icon-wrapper {
        min-width: 48px;
        height: 48px;
    }
    
    .modal-icon {
        font-size: 28px;
    }
    
    .modal-title {
        font-size: 20px;
    }
}
</style>

<script>
// ========================================
// NOTIFICATION FUNCTIONALITY
// ========================================

function toggleNotificationDropdown() {
    const dropdown = document.getElementById('notificationDropdown');
    const btn = document.getElementById('notificationBtn');
    
    dropdown.classList.toggle('show');
    
    // Load notifications when dropdown opens
    if (dropdown.classList.contains('show')) {
        loadNotifications();
    }
}

function loadNotifications() {
    const list = document.getElementById('notificationList');
    if (!list) {
        console.error('Notification list element not found');
        return;
    }
    
    <?php if (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1): ?>
    // Admin notifications
    fetch('notification_api.php')
    <?php else: ?>
    // End-user notifications
    fetch('user_notification_api.php')
    <?php endif; ?>
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                try {
                    // Filter notifications first to get accurate count
                    const clearedAt = getNotificationsClearedAt();
                    const showAll = isShowAllNotifications();
                    let filteredNotifications = data.notifications || [];
                    
                    if (!showAll && clearedAt) {
                        filteredNotifications = filteredNotifications.filter(n => {
                            try {
                                if ((n.type === 'request_status' || n.type === 'new_request' || n.type === 'borrow_status' || n.type === 'qr_request_status' || n.type === 'new_qr_request') && (n.updated_at || n.created_at)) {
                                    const dateToCheck = n.updated_at || n.created_at;
                                    return new Date(dateToCheck).getTime() > clearedAt;
                                }
                                if (n.type === 'due_date' && n.due_date) {
                                    const dt = new Date(n.due_date + 'T23:59:59');
                                    return dt.getTime() > clearedAt;
                                }
                                return true;
                            } catch (e) {
                                console.error('Error filtering notification:', e, n);
                                return true; // Keep notification if filtering fails
                            }
                        });
                    }
                    
                    // Update badge with filtered count
                    updateNotificationBadge(filteredNotifications.length);
                    <?php if (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1): ?>
                    updateNotificationSummary(data.request_count, data.pending_borrow_count || 0, data.overdue_count);
                    <?php else: ?>
                    updateUserNotificationSummary(data.due_date_count, data.request_count, data.borrow_status_count || 0);
                    <?php endif; ?>
                    updateNotificationList(data.notifications || []);
                } catch (error) {
                    console.error('Error processing notifications:', error);
                    list.innerHTML = '<div class="notification-item empty">Error displaying notifications: ' + error.message + '</div>';
                }
            } else {
                console.error('Failed to load notifications:', data.message);
                list.innerHTML = '<div class="notification-item empty">Error loading notifications: ' + (data.message || 'Unknown error') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading notifications:', error);
            list.innerHTML = '<div class="notification-item empty">Error loading notifications. Please check console for details.</div>';
        });
}

function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationBadge');
    const countSpan = document.getElementById('notificationCount');
    
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'block' : 'none';
    }
    
    if (countSpan) {
        countSpan.textContent = count + ' total';
    }
}

function updateUserNotificationSummary(dueDateCount, requestCount, borrowStatusCount) {
    const dueDateCountSpan = document.getElementById('dueDateCount');
    const requestCountSpan = document.getElementById('requestCount');
    
    if (dueDateCountSpan) {
        dueDateCountSpan.textContent = dueDateCount;
    }
    
    if (requestCountSpan) {
        // Include borrow status count in request count display
        const totalCount = (requestCount || 0) + (borrowStatusCount || 0);
        requestCountSpan.textContent = totalCount;
    }
}

function updateNotificationSummary(requestCount, pendingBorrowCount, overdueCount) {
    const requestCountSpan = document.getElementById('requestCount');
    const pendingBorrowCountSpan = document.getElementById('pendingBorrowCount');
    const overdueCountSpan = document.getElementById('overdueCount');
    
    if (requestCountSpan) {
        requestCountSpan.textContent = requestCount;
    }
    
    if (pendingBorrowCountSpan) {
        pendingBorrowCountSpan.textContent = pendingBorrowCount || 0;
    }
    
    if (overdueCountSpan) {
        overdueCountSpan.textContent = overdueCount;
    }
}

function updateNotificationList(notifications) {
    try {
        const list = document.getElementById('notificationList');
        // Check if notification list element exists before trying to update it
        if (!list) {
            console.log('Notification list element not found');
            return;
        }
        
        // Ensure notifications is an array
        if (!Array.isArray(notifications)) {
            console.error('Notifications is not an array:', notifications);
            list.innerHTML = '<div class="notification-item empty">No notifications</div>';
            return;
        }
        
        // Filter out notifications cleared by user (per-session/localStorage)
        const clearedAt = getNotificationsClearedAt();
        const showAll = isShowAllNotifications();
        if (!showAll && clearedAt) {
            notifications = notifications.filter(n => {
                try {
                    if ((n.type === 'request_status' || n.type === 'new_request' || n.type === 'borrow_status' || n.type === 'qr_request_status' || n.type === 'new_qr_request') && (n.updated_at || n.created_at)) {
                        const dateToCheck = n.updated_at || n.created_at;
                        return new Date(dateToCheck).getTime() > clearedAt;
                    }
                    if (n.type === 'due_date' && n.due_date) {
                        const dt = new Date(n.due_date + 'T23:59:59');
                        return dt.getTime() > clearedAt;
                    }
                    return true;
                } catch (e) {
                    console.error('Error filtering notification:', e, n);
                    return true; // Keep notification if filtering fails
                }
            });
        }
        
        if (!notifications || notifications.length === 0) {
            list.innerHTML = '<div class="notification-item empty">No notifications</div>';
            updateNotificationBadge(0);
            return;
        }
        
        const html = notifications.map(notification => {
            try {
        <?php if (isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1): ?>
        // Admin notification format
        const timeAgo = getTimeAgo(notification.created_at || notification.updated_at);
        const isOverdue = notification.type === 'overdue';
        const isNewRequest = notification.type === 'new_request' || notification.type === 'request';
        const isBorrowRequest = notification.type === 'borrow_request';
        const isRequestStatus = notification.type === 'request_status';
        const isQrRequest = notification.type === 'qr_request' || notification.type === 'new_qr_request';
        const isQrRequestStatus = notification.type === 'qr_request_status';
        
        let typeIcon, typeLabel, typeClass;
        if (isOverdue) {
            typeIcon = '⏰';
            typeLabel = 'OVERDUE';
            typeClass = 'overdue';
        } else if (isBorrowRequest) {
            typeIcon = '📝';
            typeLabel = 'BORROW REQUEST';
            typeClass = 'borrow-request';
        } else if (isQrRequest) {
            typeIcon = '<span style="display: inline-flex !important; align-items: center !important; justify-content: center !important; width: 20px !important; height: 20px !important; background:#000000 !important; border-radius: 4px !important; padding: 2px !important;"><img src="image/qr.png" alt="QR Code" style="width: 14px !important; height: 14px !important; object-fit: contain !important; display: block !important; filter: brightness(0) invert(1) !important;"></span>';
            typeLabel = 'ITEM QR REQUEST';
            typeClass = 'qr-request';
        } else if (isNewRequest) {
            typeIcon = '📋';
            typeLabel = 'NEW REQUEST';
            typeClass = 'new-request';
        } else if (isRequestStatus || isQrRequestStatus) {
            if (notification.notification_type === 'approved' || notification.notification_type === 'qr_request_approved') {
                typeIcon = '✅';
                typeLabel = 'APPROVED';
                typeClass = 'approved';
            } else if (notification.notification_type === 'fulfilled') {
                typeIcon = '📦';
                typeLabel = 'FULFILLED';
                typeClass = 'fulfilled';
            } else if (notification.notification_type === 'qr_request_rejected' || notification.notification_type === 'rejected') {
                typeIcon = '❌';
                typeLabel = 'REJECTED';
                typeClass = 'rejected';
            } else {
                typeIcon = '❌';
                typeLabel = 'REJECTED';
                typeClass = 'rejected';
            }
        } else {
            typeIcon = '📋';
            typeLabel = 'REQUEST';
            typeClass = 'request';
        }
        
        let detailsHtml = '';
        if (isOverdue) {
            const daysOverdue = Math.ceil((new Date() - new Date(notification.due_date)) / (1000 * 60 * 60 * 24));
            detailsHtml = `
                <span class="requester" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.name}</span>
                <span class="department" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.department_name}</span>
                <span class="overdue-days" style="background: #fef2f2 !important; color: #dc2626 !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">${daysOverdue}d overdue</span>
            `;
        } else if (isBorrowRequest) {
            detailsHtml = `
                <span class="requester" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.name}</span>
                <span class="department" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.department_name || 'N/A'}</span>
                <span class="quantity" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">Qty: ${notification.quantity}</span>
            `;
            // Add approve/reject buttons for borrow requests - compact design
            const actionButtons = `
                <div style="display: flex !important; gap: 6px !important; margin-top: 8px !important; align-items: center !important;">
                    <button onclick="approveBorrowRequestFromNotif('${notification.borrow_id || notification.id}')" style="background: #10b981 !important; color: white !important; border: none !important; padding: 5px 10px !important; border-radius: 4px !important; font-size: 11px !important; font-weight: 500 !important; cursor: pointer !important; transition: all 0.2s !important; white-space: nowrap !important; display: inline-flex !important; align-items: center !important; gap: 4px !important;" onmouseover="this.style.background='#059669'; this.style.transform='scale(1.02)'" onmouseout="this.style.background='#10b981'; this.style.transform='scale(1)'">
                        <span style="font-size: 10px;">✓</span> Approve
                    </button>
                    <button onclick="rejectBorrowRequestFromNotif('${notification.borrow_id || notification.id}')" style="background: #ef4444 !important; color: white !important; border: none !important; padding: 5px 10px !important; border-radius: 4px !important; font-size: 11px !important; font-weight: 500 !important; cursor: pointer !important; transition: all 0.2s !important; white-space: nowrap !important; display: inline-flex !important; align-items: center !important; gap: 4px !important;" onmouseover="this.style.background='#dc2626'; this.style.transform='scale(1.02)'" onmouseout="this.style.background='#ef4444'; this.style.transform='scale(1)'">
                        <span style="font-size: 10px;">✕</span> Reject
                    </button>
                </div>
            `;
            detailsHtml += actionButtons;
        } else if (isQrRequest) {
            const dateRequested = notification.created_at ? new Date(notification.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—';
            detailsHtml = `
                <span class="requester" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.requested_by || notification.name || 'User'}</span>
                <span class="department" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.department_name || 'N/A'}</span>
                <span class="quantity" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">Qty: ${notification.quantity || 0}</span>
                <span class="date-requested" style="background: #eff6ff !important; color: #1e40af !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Requested: ${dateRequested}</span>
            `;
        } else if (isNewRequest) {
            const dateRequested = notification.created_at ? new Date(notification.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—';
            const dateNeeded = notification.date_needed ? new Date(notification.date_needed + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—';
            detailsHtml = `
                <span class="requester" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.requested_by || notification.name || 'User'}</span>
                <span class="date-requested" style="background: #eff6ff !important; color: #1e40af !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Requested: ${dateRequested}</span>
                ${notification.date_needed ? `<span class="date-needed" style="background: #fef3c7 !important; color: #92400e !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Needed: ${dateNeeded}</span>` : ''}
            `;
        } else if (isRequestStatus || isQrRequestStatus) {
            const dateUpdated = notification.updated_at ? new Date(notification.updated_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—';
            if (notification.notification_type === 'approved' || notification.notification_type === 'qr_request_approved') {
                detailsHtml = `
                    <span class="status" style="background: #d1fae5 !important; color: #065f46 !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Approved</span>
                    <span class="date-updated" style="background: #f3f4f6 !important; color: #6b7280 !important; font-weight: 500 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Updated: ${dateUpdated}</span>
                `;
            } else if (notification.notification_type === 'fulfilled') {
                detailsHtml = `
                    <span class="status" style="background: #dbeafe !important; color: #1e40af !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Fulfilled</span>
                    <span class="date-updated" style="background: #f3f4f6 !important; color: #6b7280 !important; font-weight: 500 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Updated: ${dateUpdated}</span>
                `;
            } else if (notification.notification_type === 'qr_request_rejected' || notification.notification_type === 'rejected') {
                detailsHtml = `
                    <span class="status" style="background: #fee2e2 !important; color: #991b1b !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Rejected</span>
                    <span class="date-updated" style="background: #f3f4f6 !important; color: #6b7280 !important; font-weight: 500 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Updated: ${dateUpdated}</span>
                `;
            } else {
                detailsHtml = `
                    <span class="status" style="background: #fee2e2 !important; color: #991b1b !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Rejected</span>
                    <span class="date-updated" style="background: #f3f4f6 !important; color: #6b7280 !important; font-weight: 500 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Updated: ${dateUpdated}</span>
                `;
            }
        } else {
            detailsHtml = `
                <span class="requester" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.name}</span>
                <span class="department" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">${notification.department_name}</span>
                <span class="quantity" style="background: #f3f4f6 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; font-weight: 500 !important; color: #6b7280 !important; display: inline-block !important; margin: 0 !important;">Qty: ${notification.quantity}</span>
            `;
        }
        <?php else: ?>
        // End-user notification format
        const timeAgo = getTimeAgo(notification.updated_at || notification.created_at || notification.due_date || new Date().toISOString());
        const isBorrowRequest = notification.type === 'borrow_request';
        const isQrRequestStatus = notification.type === 'qr_request_status';
        let typeIcon, typeLabel, typeClass, detailsHtml;
        
        if (notification.type === 'due_date') {
            switch (notification.urgency_level) {
                case 'overdue':
                    typeIcon = '⚠️';
                    typeLabel = 'OVERDUE';
                    typeClass = 'overdue';
                    const daysOverdue = Math.ceil((new Date() - new Date(notification.due_date)) / (1000 * 60 * 60 * 24));
                    detailsHtml = `
                        <span class="due-date" style="background: #fef2f2 !important; color: #dc2626 !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">${daysOverdue}d overdue</span>
                    `;
                    break;
                case 'due_today':
                    typeIcon = '📅';
                    typeLabel = 'DUE TODAY';
                    typeClass = 'due-today';
                    detailsHtml = `
                        <span class="due-date" style="background: #fff7ed !important; color: #f97316 !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Due today</span>
                    `;
                    break;
                case 'due_tomorrow':
                    typeIcon = '⏰';
                    typeLabel = 'DUE TOMORROW';
                    typeClass = 'due-tomorrow';
                    detailsHtml = `
                        <span class="due-date" style="background: #fefce8 !important; color: #eab308 !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Due tomorrow</span>
                    `;
                    break;
                default:
                    typeIcon = '📋';
                    typeLabel = 'DUE SOON';
                    typeClass = 'due-soon';
                    detailsHtml = `
                        <span class="due-date" style="background: #f3f4f6 !important; color: #6b7280 !important; font-weight: 500 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Due soon</span>
                    `;
            }
        } else if (notification.type === 'request_status' || notification.type === 'qr_request_status') {
            if (notification.notification_type === 'approved' || notification.notification_type === 'qr_request_approved') {
                typeIcon = '✅';
                typeLabel = 'APPROVED';
                typeClass = 'approved';
                detailsHtml = `
                    <span class="status" style="background: #d1fae5 !important; color: #065f46 !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Approved</span>
                `;
            } else if (notification.notification_type === 'fulfilled') {
                typeIcon = '📦';
                typeLabel = 'FULFILLED';
                typeClass = 'fulfilled';
                detailsHtml = `
                    <span class="status" style="background: #dbeafe !important; color: #1e40af !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Fulfilled</span>
                `;
            } else if (notification.notification_type === 'qr_request_rejected' || notification.notification_type === 'rejected') {
                typeIcon = '❌';
                typeLabel = 'REJECTED';
                typeClass = 'rejected';
                detailsHtml = `
                    <span class="status" style="background: #fee2e2 !important; color: #991b1b !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Rejected</span>
                `;
            } else {
                typeIcon = '❌';
                typeLabel = 'REJECTED';
                typeClass = 'rejected';
                detailsHtml = `
                    <span class="status" style="background: #fee2e2 !important; color: #991b1b !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Rejected</span>
                `;
            }
        } else if (notification.type === 'borrow_status') {
            if (notification.notification_type === 'borrow_approved') {
                typeIcon = '✅';
                typeLabel = 'BORROW APPROVED';
                typeClass = 'borrow-approved';
                const borrowDate = notification.borrow_date ? new Date(notification.borrow_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—';
                const dueDate = notification.due_date ? new Date(notification.due_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—';
                detailsHtml = `
                    <span class="status" style="background: #d1fae5 !important; color: #065f46 !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Approved</span>
                    <span class="borrow-date" style="background: #f3f4f6 !important; color: #6b7280 !important; font-weight: 500 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Borrow: ${borrowDate}</span>
                    <span class="due-date" style="background: #fef3c7 !important; color: #92400e !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Due: ${dueDate}</span>
                `;
            } else if (notification.notification_type === 'borrow_declined') {
                typeIcon = '❌';
                typeLabel = 'REQUEST REJECTED';
                typeClass = 'borrow-declined';
                detailsHtml = `
                    <span class="status" style="background: #fee2e2 !important; color: #991b1b !important; font-weight: 600 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Rejected</span>
                `;
            } else {
                // Fallback for other borrow_status types
                typeIcon = '📋';
                typeLabel = 'BORROW UPDATE';
                typeClass = 'borrow-status';
                detailsHtml = `
                    <span class="status" style="background: #f3f4f6 !important; color: #6b7280 !important; font-weight: 500 !important; padding: 3px 8px !important; border-radius: 4px !important; white-space: nowrap !important; font-size: 10px !important; display: inline-block !important; margin: 0 !important;">Status: ${notification.status || 'Unknown'}</span>
                `;
            }
        } else {
            // Fallback for unknown notification types
            typeIcon = '📋';
            typeLabel = 'NOTIFICATION';
            typeClass = 'default';
            detailsHtml = '';
        }
        <?php endif; ?>
        
        // For borrow requests, include action buttons in detailsHtml
        const notificationContent = isBorrowRequest ? `
            <div class="notification-content" style="display: flex !important; flex-direction: column !important; gap: 8px !important; margin-top: 8px !important; flex: 1 !important;">
                <div class="notification-title" style="font-size: 13px !important; font-weight: 600 !important; color: #1f2937 !important; line-height: 1.3 !important; margin: 0 !important; padding: 0 !important;">${notification.item_name}</div>
                <div class="notification-details" style="display: flex !important; gap: 6px !important; font-size: 11px !important; color: #6b7280 !important; flex-wrap: wrap !important; margin: 6px 0 !important;">
                    ${detailsHtml}
                </div>
                <div class="notification-time" style="font-size: 10px !important; color: #9ca3af !important; margin-top: 8px !important; font-style: italic !important; padding-bottom: 4px !important;">${timeAgo}</div>
            </div>
        ` : `
            <div class="notification-content" style="display: flex !important; flex-direction: column !important; gap: 8px !important; margin-top: 8px !important; flex: 1 !important;">
                <div class="notification-title" style="font-size: 13px !important; font-weight: 600 !important; color: #1f2937 !important; line-height: 1.3 !important; margin: 0 !important; padding: 0 !important;">${isQrRequestStatus ? (notification.notification_type === 'qr_request_approved' ? 'QR request approved' : notification.notification_type === 'qr_request_rejected' ? 'QR request rejected' : notification.item_name) : notification.item_name}</div>
                <div class="notification-details" style="display: flex !important; gap: 6px !important; font-size: 11px !important; color: #6b7280 !important; flex-wrap: wrap !important; margin: 6px 0 !important;">
                    ${detailsHtml}
                </div>
                <div class="notification-time" style="font-size: 10px !important; color: #9ca3af !important; margin-top: 8px !important; font-style: italic !important; padding-bottom: 4px !important;">${timeAgo}</div>
            </div>
        `;
        
        return `
            <div class="notification-item ${typeClass}" style="padding: 12px 16px !important; border-bottom: 1px solid #f8f9fa !important; ${isBorrowRequest ? 'min-height: 140px !important;' : 'min-height: 90px !important;'} display: flex !important; flex-direction: column !important; justify-content: space-between !important; background: white !important;">
                <div class="notification-type" style="display: flex !important; align-items: center !important; gap: 6px !important; margin-bottom: 8px !important;">
                    <span class="type-icon" style="font-size: 12px !important; display: flex !important; align-items: center !important; justify-content: center !important;">${typeIcon}</span>
                    <span class="type-label" style="font-size: 10px !important; font-weight: 600 !important; padding: 2px 6px !important; border-radius: 4px !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; ${typeClass === 'overdue' ? 'background: #fef2f2 !important; color: #dc2626 !important;' : typeClass === 'due-today' ? 'background: #fff7ed !important; color: #f97316 !important;' : typeClass === 'due-tomorrow' ? 'background: #fefce8 !important; color: #eab308 !important;' : typeClass === 'borrow-request' ? 'background: #fef3c7 !important; color: #92400e !important;' : typeClass === 'borrow-approved' ? 'background: #d1fae5 !important; color: #065f46 !important;' : typeClass === 'borrow-declined' ? 'background: #fee2e2 !important; color: #991b1b !important;' : typeClass === 'qr-request' ? 'background: #e0e7ff !important; color: #4338ca !important;' : typeClass === 'new-request' ? 'background: #dbeafe !important; color: #1e40af !important;' : typeClass === 'approved' ? 'background: #d1fae5 !important; color: #065f46 !important;' : typeClass === 'fulfilled' ? 'background: #dbeafe !important; color: #1e40af !important;' : typeClass === 'rejected' ? 'background: #fee2e2 !important; color: #991b1b !important;' : 'background: #dbeafe !important; color: #1e40af !important;'}">${typeLabel}</span>
                </div>
                ${notificationContent}
            </div>
        `;
            } catch (error) {
                console.error('Error rendering notification:', error, notification);
                return '<div class="notification-item empty">Error displaying notification</div>';
            }
        }).join('');
        
        list.innerHTML = html;
    } catch (error) {
        console.error('Error in updateNotificationList:', error);
        const list = document.getElementById('notificationList');
        if (list) {
            list.innerHTML = '<div class="notification-item empty">Error displaying notifications: ' + error.message + '</div>';
        }
    }
}

function getTimeAgo(dateString) {
    const now = new Date();
    const date = new Date(dateString);
    const diffInSeconds = Math.floor((now - date) / 1000);
    
    if (diffInSeconds < 60) return 'Just now';
    if (diffInSeconds < 3600) return Math.floor(diffInSeconds / 60) + 'm ago';
    if (diffInSeconds < 86400) return Math.floor(diffInSeconds / 3600) + 'h ago';
    return Math.floor(diffInSeconds / 86400) + 'd ago';
}

function clearNotifications() {
    try {
        const key = getNotificationClearKey();
        localStorage.setItem(key, Date.now().toString());
        // Disable show-all mode when clearing
        localStorage.setItem(getNotificationShowAllKey(), '0');
        // Immediately clear UI
        const list = document.getElementById('notificationList');
        if (list) list.innerHTML = '<div class="notification-item empty">No notifications</div>';
        updateNotificationBadge(0);
    } catch (e) { /* no-op */ }
}

function getNotificationsClearedAt() {
    try {
        const key = getNotificationClearKey();
        const v = localStorage.getItem(key);
        return v ? parseInt(v, 10) : 0;
    } catch (e) { return 0; }
}

function getNotificationClearKey() {
    // Per-user clear key to avoid cross-user interference
    const user = <?php echo json_encode($_SESSION['username'] ?? 'guest'); ?>;
    return 'ocabis:notif_cleared_at:' + user;
}

function getNotificationShowAllKey() {
    const user = <?php echo json_encode($_SESSION['username'] ?? 'guest'); ?>;
    return 'ocabis:notif_show_all:' + user;
}

function isShowAllNotifications() {
    try {
        return localStorage.getItem(getNotificationShowAllKey()) === '1';
    } catch (e) { return false; }
}

function showAllNotifications() {
    try {
        localStorage.setItem(getNotificationShowAllKey(), '1');
        // Re-fetch to display all
        // Quick refresh: toggle dropdown to trigger fetch
        const dropdown = document.getElementById('notificationDropdown');
        const isOpen = dropdown && dropdown.classList.contains('show');
        if (isOpen) {
            toggleNotificationDropdown();
            toggleNotificationDropdown();
        } else {
            toggleNotificationDropdown();
        }
    } catch (e) { /* no-op */ }
}

// Show confirmation modal
function showConfirmModal(title, message, icon, confirmCallback) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    document.getElementById('confirmIcon').textContent = icon;
    
    const confirmBtn = document.getElementById('confirmActionBtn');
    confirmBtn.onclick = function() {
        closeConfirmModal();
        if (confirmCallback) confirmCallback();
    };
    
    document.getElementById('confirmModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('show');
    document.body.style.overflow = 'auto';
}

// Show notification
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#cce7ff'};
        color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#004085'};
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10001;
        max-width: 300px;
        word-wrap: break-word;
        opacity: 0;
        transform: translateX(100%);
        transition: all 0.3s ease;
        font-size: 14px;
    `;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateX(0)';
    }, 100);
    
    // Remove after 5 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 5000);
}

// Approve borrow request from notification
async function approveBorrowRequestFromNotif(borrowId) {
    showConfirmModal(
        'Approve Borrow Request',
        'Approve this borrow request?',
        '✅',
        async function() {
            try {
                const formData = new FormData();
                formData.append('action', 'update_borrow_status');
                formData.append('borrow_id', borrowId);
                formData.append('status', 'approved');
                
                const response = await fetch('crud.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Borrow request approved successfully!', 'success');
                    // Reload notifications
                    loadNotifications();
                } else {
                    showNotification(data.message || 'Failed to approve request', 'error');
                }
            } catch (error) {
                console.error('Error approving request:', error);
                showNotification('Error approving request', 'error');
            }
        }
    );
}

// Reject borrow request from notification
async function rejectBorrowRequestFromNotif(borrowId) {
    showConfirmModal(
        'Reject Borrow Request',
        'Reject this borrow request? This action cannot be undone.',
        '❌',
        async function() {
            try {
                const formData = new FormData();
                formData.append('action', 'update_borrow_status');
                formData.append('borrow_id', borrowId);
                formData.append('status', 'declined');
                
                const response = await fetch('crud.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Borrow request rejected', 'success');
                    // Reload notifications
                    loadNotifications();
                } else {
                    showNotification(data.message || 'Failed to reject request', 'error');
                }
            } catch (error) {
                console.error('Error rejecting request:', error);
                showNotification('Error rejecting request', 'error');
            }
        }
    );
}

// Auto-refresh notifications every 30 seconds
setInterval(() => {
    if (document.getElementById('notificationDropdown') && 
        document.getElementById('notificationDropdown').classList.contains('show')) {
        loadNotifications();
    }
}, 30000);

// Load initial notifications
document.addEventListener('DOMContentLoaded', function() {
    // Only load if notification container exists (not hidden)
    const notificationContainer = document.querySelector('.notification-container');
    if (notificationContainer) {
        loadNotifications();
    }
});

// ========================================
// PROFILE DROPDOWN FUNCTIONALITY
// ========================================

function toggleProfileDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    const arrow = document.querySelector('.dropdown-arrow');
    
    dropdown.classList.toggle('show');
    arrow.style.transform = dropdown.classList.contains('show') ? 'rotate(180deg)' : 'rotate(0deg)';
}

function openProfileSettings() {
    // Close dropdown first
    document.getElementById('profileDropdown').classList.remove('show');
    document.querySelector('.dropdown-arrow').style.transform = 'rotate(0deg)';
    
    // Redirect to profile settings page
    window.location.href = 'profile_settings.php';
    return false;
}

function openEditProfile() {
    // Close dropdown first
    document.getElementById('profileDropdown').classList.remove('show');
    document.querySelector('.dropdown-arrow').style.transform = 'rotate(0deg)';
    
    // Redirect to edit profile page
    window.location.href = 'edit_profile.php';
    return false;
}

// ========================================
// HIDE WELCOME TEXT WHEN SIDEBAR IS OPEN (MOBILE)
// ========================================

// Function to toggle body class when sidebar opens/closes on mobile
function handleSidebarToggle() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    
    function updateBodyClass() {
        if (window.innerWidth <= 768) {
            if (sidebar.classList.contains('open')) {
                document.body.classList.add('sidebar-open');
            } else {
                document.body.classList.remove('sidebar-open');
            }
        } else {
            document.body.classList.remove('sidebar-open');
        }
    }
    
    // Watch for sidebar class changes
    const observer = new MutationObserver(updateBodyClass);
    observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    
    // Also check on window resize
    window.addEventListener('resize', updateBodyClass);
    
    // Initial check
    updateBodyClass();
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', handleSidebarToggle);
} else {
    handleSidebarToggle();
}

// ========================================
// GLOBAL EVENT LISTENERS
// ========================================

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    // Close profile dropdown
    const dropdown = document.getElementById('profileDropdown');
    const profileBtn = document.querySelector('.profile-btn');
    
    if (dropdown && profileBtn && !profileBtn.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.classList.remove('show');
        const arrow = document.querySelector('.dropdown-arrow');
        if (arrow) {
            arrow.style.transform = 'rotate(0deg)';
        }
    }
    
    // Close notification dropdown when clicking outside
    const notificationDropdown = document.getElementById('notificationDropdown');
    const notificationBtn = document.getElementById('notificationBtn');
    
    if (notificationDropdown && notificationBtn && 
        !notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
        notificationDropdown.classList.remove('show');
    }
    
    // Close modal when clicking outside
    const confirmModal = document.getElementById('confirmModal');
    if (confirmModal && e.target === confirmModal) {
        closeConfirmModal();
    }
});
</script>
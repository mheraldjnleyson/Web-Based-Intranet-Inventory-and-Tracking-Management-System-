<?php
include '../db_connect.php';
session_start();

// Get item ID from URL
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$item_id) {
    die('Invalid item ID');
}

// Fetch item details with table image, item image, and QR code
$sql = "SELECT i.*, d.name as department_name, it.table_image_path
        FROM items i 
        LEFT JOIN departments d ON i.department_id = d.id 
        LEFT JOIN item_tables it ON i.item_table_id = it.id
        WHERE i.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die('Item not found');
}

$item = $result->fetch_assoc();
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($item['name']) ?> - Item Details</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
           
            height: 100vh;
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.1);
            max-width: 1200px;
            width: 100%;
            max-height: 95vh;
            overflow: hidden;
            animation: slideUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            display: flex;
            flex-direction: column;
            margin: 20px;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 15px 25px;
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 1;
        }
        
        .close-button {
            position: absolute;
            top: 15px;
            right: 25px;
            width: 36px;
            height: 36px;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 10;
            color: white;
            font-size: 20px;
            text-decoration: none;
        }
        
        .close-button:hover {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.5);
            transform: scale(1.1);
        }
        
        .close-button:active {
            transform: scale(0.95);
        }
        
        .logo {
            text-align: left;
        }
        
        .logo-top {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 5px;
        }
        
        .logo-icon img {
            height: 40px;
            width: auto;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }
        
        .logo-top h1 {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .logo-text p {
            margin: 0;
            font-size: 10px;
            letter-spacing: 1.5px;
            opacity: 0.95;
            font-weight: 500;
        }
        
        .header-center {
            text-align: center;
            flex: 1;
            padding: 0 20px;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="40" fill="none" stroke="rgba(255,255,255,0.03)" stroke-width="2"/></svg>');
            opacity: 0.3;
        }
        
        .header-content {
            position: relative;
            z-index: 1;
        }
        
        .header-center h1 {
            font-size: 20px;
            margin-bottom: 4px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .header-center p {
            opacity: 0.95;
            font-size: 11px;
            font-weight: 400;
        }
        
        .content {
            padding: 20px 25px 25px 25px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            align-items: start;
            flex: 1;
            overflow: hidden;
            min-height: 0;
        }
        
        .left-column {
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-height: 0;
            overflow: hidden;
        }
        
        .right-column {
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-height: 0;
            overflow: hidden;
        }
        
        .history-card {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 12px;
            flex: 0 1 auto;
            max-height: 150px;
            overflow-y: auto;
        }
        .history-title {
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            color: #212529;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .history-title::before { content: '📘'; font-size: 12px; }
        .history-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .history-table th, .history-table td { border: 1px solid #e9ecef; padding: 6px 8px; text-align: left; }
        .history-table th { background: #f8f9fa; font-weight: 700; color: #495057; }
        .history-empty { color: #6c757d; font-size: 12px; }
        
        .item-image {
            text-align: center;
            font-size: 50px;
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.08) 0%, rgba(200, 35, 51, 0.08) 100%);
            padding: 15px;
            border-radius: 16px;
            border: 2px solid rgba(220, 53, 69, 0.1);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            flex: 0 1 auto;
        }
        
        .item-image:hover {
            transform: scale(1.02);
        }
        
        .item-image img {
            max-width: 100%;
            max-height: 180px;
            object-fit: contain;
            border-radius: 8px;
        }
        
        .qr-code-container {
            margin-top: 0;
            text-align: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 10px;
        }
        
        .qr-code-container img {
            max-width: 140px;
            max-height: 140px;
            border: 2px solid rgba(220, 53, 69, 0.2);
            border-radius: 8px;
            padding: 8px;
            background: white;
        }
        
        .qr-code-label {
            font-size: 12px;
            color: #6c757d;
            margin-top: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .details-grid {
            display: grid;
            gap: 12px;
        }
        
        .detail-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 12px;
            padding: 12px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .detail-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border-color: rgba(220, 53, 69, 0.2);
        }
        
        .detail-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .detail-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(200, 35, 51, 0.1) 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .detail-content {
            flex: 1;
            min-width: 0;
        }
        
        .detail-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        
        .detail-value {
            color: #212529;
            font-size: 13px;
            font-weight: 500;
            word-wrap: break-word;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 24px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .status-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .status-working {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }
        
        .status-working::before {
            background: #28a745;
            box-shadow: 0 0 8px rgba(40, 167, 69, 0.5);
        }
        
        .status-not-working {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }
        
        .status-not-working::before {
            background: #dc3545;
            box-shadow: 0 0 8px rgba(220, 53, 69, 0.5);
        }
        
        .description-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            padding: 12px;
            border-radius: 12px;
            font-size: 12px;
            line-height: 1.5;
            color: #495057;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            flex: 0 1 auto;
            max-height: 120px;
            overflow-y: auto;
        }
        
        .description-title {
            font-weight: 700;
            color: #212529;
            margin-bottom: 8px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .description-title::before {
            content: '📝';
            font-size: 12px;
        }
        
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: auto;
            padding-top: 15px;
            flex-shrink: 0;
        }
        
        .btn {
            padding: 12px 18px;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
        }
        
        .btn::before {
            font-size: 16px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        
        .btn-primary::before {
            content: '📊';
        }
        
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
        }
        
        .btn-primary:active {
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #495057;
            border: 1px solid #dee2e6;
        }
        
        .btn-secondary::before {
            content: '🖨️';
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #dee2e6 100%);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        }
        
        .qr-scanned-badge {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 12px;
            font-weight: 600;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }
        
        .qr-scanned-badge::before {
            content: '✓';
            display: inline-block;
            width: 18px;
            height: 18px;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            text-align: center;
            line-height: 18px;
        }
        
        @media (max-width: 900px) {
            .content {
                grid-template-columns: 1fr;
                padding: 30px 20px;
                gap: 30px;
            }
            
            .item-image {
                font-size: 80px;
                padding: 30px;
            }
            
            .action-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="logo">
                    <div class="logo-top">
                        <div class="logo-icon">
                            <img src="image/image-removebg-preview.png" alt="Logo">
                        </div>
                        <h1>CABIS</h1>
                    </div>
                    <div class="logo-text">
                        <p>INVENTORY MANAGEMENT SYSTEM</p>
                    </div>
                </div>
                
                <div class="header-center">
                    <div class="qr-scanned-badge">QR Code Scanned</div>
                    <h1><?= htmlspecialchars($item['name']) ?></h1>
                    <p>Item Details from OCABIS Inventory</p>
                </div>
            </div>
            <a href="department.php" class="close-button" title="Close" aria-label="Close">
                ✕
            </a>
        </div>
        
        <div class="content">
            <div class="left-column">
                <div class="item-image">
                    <?php 
                    // Priority: 1. Item's own image, 2. Item table's image, 3. Nothing (no default icon)
                    $itemImagePath = null;
                    if (!empty($item['image_path']) && file_exists($item['image_path'])) {
                        $itemImagePath = $item['image_path'];
                    } elseif (!empty($item['table_image_path']) && file_exists($item['table_image_path'])) {
                        $itemImagePath = $item['table_image_path'];
                    }
                    
                    if ($itemImagePath): ?>
                        <img src="<?= htmlspecialchars($itemImagePath) ?>" alt="<?= htmlspecialchars($item['name']) ?>" />
                    <?php else: ?>
                        <!-- No image available - show empty placeholder instead of default icon -->
                        <div style="width: 100%; height: 180px; background: #f8f9fa; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #6c757d; font-size: 14px;">
                            No Image Available
                        </div>
                    <?php endif; ?>
                    
                    <?php 
                    // Always show QR code container if item has qr_code field or item_code
                    $hasQrCode = !empty($item['qr_code']) || !empty($item['item_code']);
                    if ($hasQrCode): ?>
                        <div class="qr-code-container" id="qrCodeContainer">
                            <?php if (!empty($item['qr_code']) && file_exists($item['qr_code'])): ?>
                                <img src="<?= htmlspecialchars($item['qr_code']) ?>" alt="QR Code" id="qrCodeImage" />
                            <?php else: ?>
                                <!-- QR code will be generated on-the-fly via JavaScript -->
                                <div id="qrCodePlaceholder" style="width: 100%; height: 180px; background: #f8f9fa; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #6c757d;">
                                    <div style="text-align: center;">
                                        <div style="font-size: 24px; margin-bottom: 8px;">📱</div>
                                        <div>Generating QR Code...</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="qr-code-label">QR Code</div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($item['description'])): ?>
                <div class="description-box">
                    <div class="description-title">Description</div>
                    <?= nl2br(htmlspecialchars($item['description'])) ?>
                </div>
                <?php endif; ?>
                
                <div class="action-buttons">
                    <a href="dashboard.php<?php 
                        $params = [];
                        // Use department name, not ID (dashboard expects department name)
                        if (!empty($item['department_name'])) {
                            $params[] = 'department=' . urlencode($item['department_name']);
                        }
                        if (!empty($item['name'])) {
                            $params[] = 'search=' . urlencode($item['name']);
                        }
                        echo !empty($params) ? '?' . implode('&', $params) : '';
                    ?>" class="btn btn-primary">View in Dashboard</a>
                    <button onclick="window.print()" class="btn btn-secondary">Print Details</button>
                </div>
            </div>
            
            <div class="right-column">
                <div class="details-grid">
                    <div class="detail-card">
                        <div class="detail-row">
                            <div class="detail-icon">🆔</div>
                            <div class="detail-content">
                                <div class="detail-label">Item ID</div>
                                <div class="detail-value"><?= $item['id'] ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-row">
                            <div class="detail-icon">🏢</div>
                            <div class="detail-content">
                                <div class="detail-label">Department</div>
                                <div class="detail-value"><?= htmlspecialchars($item['department_name']) ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-row">
                            <div class="detail-icon">📁</div>
                            <div class="detail-content">
                                <div class="detail-label">Category</div>
                                <div class="detail-value"><?= htmlspecialchars($item['category']) ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-row">
                            <div class="detail-icon">📍</div>
                            <div class="detail-content">
                                <div class="detail-label">Location</div>
                                <div class="detail-value"><?= htmlspecialchars($item['location']) ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-row">
                            <div class="detail-icon">⚡</div>
                            <div class="detail-content">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $item['status'])) ?>">
                                        <?= htmlspecialchars($item['status']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="detail-card">
                        <div class="detail-row">
                            <div class="detail-icon">🕐</div>
                            <div class="detail-content">
                                <div class="detail-label">Last Updated</div>
                                <div class="detail-value"><?= date('M d, Y h:i A', strtotime($item['updated_at'])) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="history-card" id="borrow-history-card">
                    <div class="history-title">Borrow History</div>
                    <div id="borrow-history-content" class="history-empty">Loading history...</div>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function() {
            const content = document.getElementById('borrow-history-content');
            fetch('crud.php?action=get_item_borrow_history&item_id=<?= (int)$item['id'] ?>', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) {
                        content.textContent = 'Failed to load history.';
                        return;
                    }
                    if (!data.history || data.history.length === 0) {
                        content.textContent = 'No borrow history for this item.';
                        return;
                    }
                    const rows = data.history.map(h => `
                        <tr>
                            <td>${h.borrow_id}</td>
                            <td>${escapeHtml(h.borrower_name || '')}</td>
                            <td>${escapeHtml(h.status || '')}</td>
                            <td>${formatDate(h.borrow_date)}</td>
                            <td>${formatDate(h.due_date)}</td>
                            <td>${h.return_date ? formatDate(h.return_date) : '-'}</td>
                        </tr>
                    `).join('');
                    content.innerHTML = `
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Borrow ID</th>
                                    <th>Borrower</th>
                                    <th>Status</th>
                                    <th>Borrowed</th>
                                    <th>Due</th>
                                    <th>Returned</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>`;
                })
                .catch(() => { content.textContent = 'Failed to load history.'; });
            
            function formatDate(d) {
                if (!d) return '';
                const dt = new Date(d);
                if (isNaN(dt)) return d;
                return dt.toLocaleDateString();
            }
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]));
            }
        })();
        
        // Generate QR code on-the-fly if file doesn't exist
        (function() {
            const qrPlaceholder = document.getElementById('qrCodePlaceholder');
            if (qrPlaceholder) {
                const itemId = <?= $item_id ?>;
                const itemCode = '<?= htmlspecialchars($item['item_code'] ?? 'ITEM-' . $item_id, ENT_QUOTES) ?>';
                const departmentName = '<?= htmlspecialchars($item['department_name'] ?? '', ENT_QUOTES) ?>';
                
                // Get department color
                function getDepartmentColorHex(deptName) {
                    const name = String(deptName).trim().toLowerCase();
                    if (name.includes('ict')) return 'E53E3E';
                    if (name.includes('science')) return 'F59E0B';
                    if (name.includes('sps')) return '805AD5';
                    if (name.includes('slrc') || name.includes('student')) return '3182CE';
                    return 'E53E3E'; // Default red
                }
                
                const deptColor = getDepartmentColorHex(departmentName);
                const protocol = window.location.protocol;
                const host = window.location.host;
                const baseUrl = `${protocol}//${host}/ocabisFrontend/ocabis/`;
                const qrData = baseUrl + 'view_item.php?id=' + itemId;
                
                // Generate QR code using API
                const qrApiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=H&color=${deptColor}&bgcolor=FFFFFF&data=${encodeURIComponent(qrData)}`;
                
                // Create img element
                const img = document.createElement('img');
                img.src = qrApiUrl;
                img.alt = 'QR Code';
                img.style.width = '100%';
                img.style.height = '180px';
                img.style.objectFit = 'contain';
                img.style.borderRadius = '8px';
                img.onerror = function() {
                    qrPlaceholder.innerHTML = '<div style="text-align: center; color: #6c757d;">Failed to generate QR code</div>';
                };
                img.onload = function() {
                    qrPlaceholder.replaceWith(img);
                };
            }
        })();
    </script>
</body>
</html>
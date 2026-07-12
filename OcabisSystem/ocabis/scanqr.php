<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>OCABIS QR Scanner</title>
    <link rel="stylesheet" href="Css/mobile.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="js/mobile.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .scanner-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.25);
            max-width: 800px;
            width: 100%;
            overflow: hidden;
            animation: slideUp 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
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
            padding: 25px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
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
        
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .logo img {
            height: 50px;
            width: auto;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }
        
        .logo h1 {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .logo-text {
            font-size: 11px;
            letter-spacing: 1.5px;
            opacity: 0.95;
            font-weight: 500;
        }
        
        .header h2 {
            font-size: 24px;
            margin: 15px 0 8px 0;
            font-weight: 700;
        }
        
        .header p {
            opacity: 0.95;
            font-size: 14px;
        }
        
        .scanner-content {
            padding: 30px;
        }
        
        .scanner-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }
        
        .tab-button {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e9ecef;
            background: white;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .tab-button.active {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border-color: #dc3545;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        
        .tab-button:hover:not(.active) {
            border-color: #dc3545;
            color: #dc3545;
        }
        
        .scanner-section {
            display: none;
        }
        
        .scanner-section.active {
            display: block;
        }
        
        .upload-area {
            border: 3px dashed #dee2e6;
            border-radius: 15px;
            padding: 40px 20px;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .upload-area:hover {
            border-color: #dc3545;
            background: #f8f9ff;
        }
        
        .upload-area.dragover {
            border-color: #dc3545;
            background: #f8f9ff;
        }
        
        .upload-icon {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }
        
        .upload-text {
            font-size: 18px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
        }
        
        .upload-button {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .upload-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }
        
        .camera-container {
            text-align: center;
        }
        
        #reader {
            width: 100%;
            max-width: 500px;
            margin: 0 auto 20px auto;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .camera-controls {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }
        
        .scan-result {
            margin-top: 20px;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
        }
        
        .scan-result.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .scan-result.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .scan-result.info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .item-details {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-top: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: none;
        }
        
        .item-details.show {
            display: block;
            animation: slideUp 0.5s ease;
        }
        
        .item-header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .item-name {
            font-size: 24px;
            font-weight: 700;
            color: #212529;
            margin-bottom: 8px;
        }
        
        .item-id {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }
        
        .item-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .item-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }
        
        .item-card-label {
            font-size: 12px;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .item-card-value {
            font-size: 16px;
            font-weight: 500;
            color: #212529;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-working {
            background: #d4edda;
            color: #155724;
        }
        
        .status-not-working {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 25px;
        }
        
        .close-scanner {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .close-scanner:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.1);
        }
        
        @media (max-width: 768px) {
            .scanner-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .scanner-content {
                padding: 20px;
            }
            
            .item-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="scanner-container mobile-container">
        <div class="header mobile-header">
            <button class="close-scanner mobile-btn mobile-btn-danger" onclick="window.close()" title="Close Scanner">×</button>
            <div class="header-content">
                <div class="logo">
                    <img src="image/image-removebg-preview.png" alt="Logo">
                    <h1>CABIS</h1>
                </div>
                <div class="logo-text">INVENTORY MANAGEMENT SYSTEM</div>
                <h2>QR Code Scanner</h2>
                <p>Scan QR codes to view item details instantly</p>
            </div>
        </div>
        
        <div class="scanner-content mobile-p-3">
            <div class="scanner-tabs mobile-grid mobile-grid-2">
                <button class="tab-button active mobile-btn mobile-btn-primary" onclick="switchTab('upload')">
                    📁 Upload Image
                </button>
                <button class="tab-button mobile-btn mobile-btn-secondary" onclick="switchTab('scan')">
                    📷 Scan with Camera
                </button>
            </div>
            
            <!-- Upload Section -->
            <div id="upload-section" class="scanner-section active">
                <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                    <div class="upload-icon">📁</div>
                    <div class="upload-text">Upload QR Code Image</div>
                    <button class="upload-button">
                        📤 Choose File
                    </button>
                    <input type="file" id="fileInput" accept="image/*" style="display: none;" onchange="handleFileUpload(event)">
                </div>
                <div id="upload-result" class="scan-result" style="display: none;"></div>
            </div>
            
            <!-- Camera Section -->
            <div id="scan-section" class="scanner-section">
                <div class="camera-container">
                    <div id="reader"></div>
                    <div class="camera-controls">
                        <button class="btn btn-primary" onclick="startCamera()" id="startBtn">
                            📷 Start Camera
                        </button>
                        <button class="btn btn-danger" onclick="stopCamera()" id="stopBtn" style="display: none;">
                            ⏹️ Stop Camera
                        </button>
                    </div>
                    <div id="camera-help" style="margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 10px; font-size: 14px; color: #6c757d; display: none;">
                        <strong>Camera Tips:</strong><br>
                        • Make sure to allow camera permissions when prompted<br>
                        • Try using HTTPS if camera doesn't work (some browsers require secure connection)<br>
                        • If camera fails, use the "Upload Image" tab instead<br>
                        • On mobile: ensure you're using a modern browser (Chrome, Firefox, Safari)
                    </div>
                </div>
                <div id="scan-result" class="scan-result" style="display: none;"></div>
            </div>
            
            <!-- Item Details Section -->
            <div id="item-details" class="item-details">
                <div class="item-header">
                    <div class="item-name" id="item-name"></div>
                    <div class="item-id">Item ID: <span id="item-id"></span></div>
                </div>
                <div class="item-grid" id="item-grid"></div>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="scanAnother()">
                        🔄 Scan Another
                    </button>
                    <button class="btn btn-secondary" onclick="viewInDashboard()">
                        📊 View in Dashboard
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let html5QrCode = null;
        let isScanning = false;
        let currentScannedItemId = null;

        function switchTab(tab) {
            // Update tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`[onclick="switchTab('${tab}')"]`).classList.add('active');
            
            // Update sections
            document.querySelectorAll('.scanner-section').forEach(section => section.classList.remove('active'));
            document.getElementById(`${tab}-section`).classList.add('active');
            
            // Stop camera if switching away from scan tab
            if (tab !== 'scan' && isScanning) {
                stopCamera();
            }
            
            // Show camera help when switching to scan tab
            if (tab === 'scan') {
                document.getElementById('camera-help').style.display = 'block';
            } else {
                document.getElementById('camera-help').style.display = 'none';
            }
        }

        function handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;

            const resultDiv = document.getElementById('upload-result');
            resultDiv.innerHTML = '<div class="scan-result info">Processing QR code...</div>';
            resultDiv.style.display = 'block';

            const html5QrCodeScanner = new Html5Qrcode("upload-result");
            
            html5QrCodeScanner.scanFile(file, true)
                .then(decodedText => {
                    handleQRCodeResult(decodedText);
                    event.target.value = '';
                })
                .catch(err => {
                    resultDiv.innerHTML = '<div class="scan-result error">Failed to decode QR code. Please try another image.</div>';
                    event.target.value = '';
                });
        }

        function startCamera() {
            const startBtn = document.getElementById('startBtn');
            const stopBtn = document.getElementById('stopBtn');
            const scanResult = document.getElementById('scan-result');
            
            startBtn.style.display = 'none';
            stopBtn.style.display = 'inline-flex';
            
            scanResult.innerHTML = '<div class="scan-result info">📷 Starting camera...</div>';
            scanResult.style.display = 'block';

            html5QrCode = new Html5Qrcode("reader");
            
            const config = { 
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            };

            // Try different camera configurations
            const cameraConfigs = [
                { facingMode: "environment" },
                { facingMode: "user" },
                { deviceId: { exact: "environment" } },
                { deviceId: { exact: "user" } }
            ];

            let configIndex = 0;
            
            function tryNextConfig() {
                if (configIndex >= cameraConfigs.length) {
                    scanResult.innerHTML = '<div class="scan-result error">❌ Camera not available. Please try uploading an image instead or check browser permissions.</div>';
                    document.getElementById('camera-help').style.display = 'block';
                    stopCamera();
                    return;
                }

                const currentConfig = cameraConfigs[configIndex];
                
                html5QrCode.start(
                    currentConfig,
                    config,
                    (decodedText, decodedResult) => {
                        if (isScanning) {
                            isScanning = false;
                            handleQRCodeResult(decodedText);
                            stopCamera();
                        }
                    },
                    (errorMessage) => {
                        // Silent error handling for individual attempts
                    }
                ).then(() => {
                    scanResult.innerHTML = '<div class="scan-result success">✅ Camera ready - Scanning for QR codes...</div>';
                }).catch(err => {
                    console.log('Camera config failed:', currentConfig, err);
                    configIndex++;
                    tryNextConfig();
                });
            }

            tryNextConfig();
            isScanning = true;
        }

        function stopCamera() {
            if (html5QrCode) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                    html5QrCode = null;
                    isScanning = false;
                    
                    document.getElementById('startBtn').style.display = 'inline-flex';
                    document.getElementById('stopBtn').style.display = 'none';
                }).catch(err => {
                    isScanning = false;
                });
            }
        }

        function handleQRCodeResult(decodedText) {
            const scanResult = document.getElementById('scan-result');
            scanResult.innerHTML = '<div class="scan-result info">✓ QR Code Scanned - Loading item details...</div>';
            scanResult.style.display = 'block';
            
            let itemId = null;
            
            // Check if it's a URL
            if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                if (decodedText.includes('view_item.php?id=')) {
                    const urlParams = new URLSearchParams(decodedText.split('?')[1]);
                    itemId = urlParams.get('id');
                }
            } else {
                // Try to parse as JSON
                try {
                    const data = JSON.parse(decodedText);
                    if (data.id) {
                        itemId = data.id;
                    }
                } catch (e) {
                    // Plain text QR code - check if it's just a number (item ID)
                    if (/^\d+$/.test(decodedText.trim())) {
                        itemId = decodedText.trim();
                    }
                }
            }
            
            if (itemId) {
                fetchItemDetails(itemId);
            } else {
                scanResult.innerHTML = '<div class="scan-result error">❌ Invalid QR code format. Please scan a valid item QR code.</div>';
            }
        }

        function fetchItemDetails(itemId) {
            fetch(`view_item_api.php?id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayItemDetails(data.item);
                    } else {
                        document.getElementById('scan-result').innerHTML = '<div class="scan-result error">❌ Item not found or error loading details.</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('scan-result').innerHTML = '<div class="scan-result error">❌ Error loading item details.</div>';
                });
        }

        function displayItemDetails(item) {
            // Store current item ID for view in dashboard button
            currentScannedItemId = item.id;
            
            // Hide scanner sections and show item details
            document.querySelectorAll('.scanner-section').forEach(section => section.style.display = 'none');
            document.getElementById('item-details').classList.add('show');
            
            // Populate item details
            document.getElementById('item-name').textContent = item.name;
            document.getElementById('item-id').textContent = item.id;
            
            const itemGrid = document.getElementById('item-grid');
            itemGrid.innerHTML = `
                <div class="item-card">
                    <div class="item-card-label">Department</div>
                    <div class="item-card-value">${item.department_name || 'N/A'}</div>
                </div>
                <div class="item-card">
                    <div class="item-card-label">Category</div>
                    <div class="item-card-value">${item.category || 'N/A'}</div>
                </div>
                <div class="item-card">
                    <div class="item-card-label">Location</div>
                    <div class="item-card-value">${item.location || 'N/A'}</div>
                </div>
                <div class="item-card">
                    <div class="item-card-label">Quantity</div>
                    <div class="item-card-value">${item.quantity || 'N/A'}</div>
                </div>
                <div class="item-card">
                    <div class="item-card-label">Status</div>
                    <div class="item-card-value">
                        <span class="status-badge status-${item.status ? item.status.toLowerCase().replace(' ', '-') : 'unknown'}">
                            ${item.status || 'Unknown'}
                        </span>
                    </div>
                </div>
                <div class="item-card">
                    <div class="item-card-label">Last Updated</div>
                    <div class="item-card-value">${item.updated_at ? new Date(item.updated_at).toLocaleDateString() : 'N/A'}</div>
                </div>
            `;
        }

        function scanAnother() {
            // Reset to scanner view
            document.getElementById('item-details').classList.remove('show');
            document.querySelectorAll('.scanner-section').forEach(section => section.style.display = 'block');
            document.getElementById('scan-result').style.display = 'none';
            document.getElementById('upload-result').style.display = 'none';
            
            // Reset file input
            document.getElementById('fileInput').value = '';
        }

        function viewInDashboard() {
            if (currentScannedItemId) {
                window.open(`view_item.php?id=${currentScannedItemId}`, '_blank');
            } else {
                window.open('dashboard.php', '_blank');
            }
        }

        // Drag and drop functionality
        const uploadArea = document.querySelector('.upload-area');
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                const fileInput = document.getElementById('fileInput');
                fileInput.files = files;
                handleFileUpload({ target: fileInput });
            }
        });

        // Clean up camera on page unload
        window.addEventListener('beforeunload', () => {
            if (isScanning) {
                stopCamera();
            }
        });
    </script>
</body>
</html>

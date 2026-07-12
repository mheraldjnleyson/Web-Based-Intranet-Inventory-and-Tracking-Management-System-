<?php
include '../db_connect.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Check if user is viewer (borrower) - no department and not admin
$isViewer = empty($_SESSION['department']) && (!isset($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) && (!isset($_SESSION['is_super_admin']) || (int)$_SESSION['is_super_admin'] !== 1);
if (!$isViewer) {
    // Non-viewers should use the regular QR scanner
    header("Location: qrscanner.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="image/image-removebg-preview.png" type="image/png">
    <title>Scan Item QR Code - OCABIS</title>
    <link rel="stylesheet" href="Css/dashboard.css">
    <link rel="stylesheet" href="Css/profile_dropdown.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script src="js/mobile.js"></script>
    <script src="js/session_monitor.js"></script>
    <style>
        /* Sidebar Toggle Fixed - Hidden on Desktop by Default */
        .sidebar-toggle-fixed {
            display: none;
        }

        /* Mobile Responsive Styles - Match department.php */
        @media (max-width: 768px) {
            /* Show fixed hamburger on mobile - always visible (unless sidebar is open) */
            /* Override any desktop rules */
            body #sidebarToggleFixed,
            body .sidebar-toggle-fixed,
            #sidebarToggleFixed,
            .sidebar-toggle-fixed {
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                z-index: 1300 !important;
                position: fixed !important;
                top: 15px !important;
                left: 15px !important;
                background: rgba(229, 62, 62, 0.95) !important;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
            }

            /* Hide fixed button when sidebar is open on mobile - handled by JavaScript */

            /* Show inline toggle on mobile when sidebar is open - allow it to close */
            #sidebarToggle {
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                z-index: 1301 !important;
                position: relative !important;
                cursor: pointer !important;
                pointer-events: auto !important;
            }

            /* Slide sidebar in/out on mobile */
            .sidebar { 
                transform: translateX(-100%) !important; 
                transition: transform 0.3s ease !important;
                z-index: 1200 !important;
                width: 250px !important;
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                height: 100vh !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
            }
            
            .sidebar.open { 
                transform: translateX(0) !important; 
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
            }
            
            /* Ensure sidebar can be closed */
            .sidebar:not(.open) {
                transform: translateX(-100%) !important;
            }
            
            /* Make sure toggle button inside sidebar is clickable on mobile */
            .sidebar.open #sidebarToggle,
            .sidebar #sidebarToggle {
                pointer-events: auto !important;
                cursor: pointer !important;
                z-index: 1301 !important;
                position: relative !important;
            }
            
            /* Ensure sidebar has proper padding on mobile */
            .sidebar {
                padding: 20px 0 !important;
                padding-bottom: 80px !important;
            }
            
            /* Ensure sidebar content is properly styled on mobile */
            .sidebar .logo {
                padding: 0 20px !important;
                margin-bottom: 30px !important;
            }
            
            .sidebar .nav-menu {
                list-style: none !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .sidebar .nav-item {
                margin-bottom: 8px !important;
            }
            
            /* Nav link styling - match desktop layout */
            .sidebar .nav-link {
                display: flex !important;
                align-items: center !important;
                padding: 12px 20px !important;
                color: white !important;
                text-decoration: none !important;
                font-size: 14px !important;
                width: 100% !important;
                box-sizing: border-box !important;
                white-space: nowrap !important;
                overflow: visible !important;
            }
            
            /* Nav icon styling */
            .sidebar .nav-icon {
                width: 16px !important;
                height: 16px !important;
                margin-right: 12px !important;
                opacity: 0.8 !important;
                flex-shrink: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            
            .sidebar .nav-icon img {
                width: 16px !important;
                height: 16px !important;
                object-fit: contain !important;
            }
            
            /* Nav label styling - ensure text is visible */
            .sidebar .nav-label,
            .sidebar .nav-link span:not(.nav-icon) {
                display: inline-block !important;
                visibility: visible !important;
                opacity: 1 !important;
                white-space: nowrap !important;
                flex: 1 !important;
            }

            /* Fix sidebar text alignment on mobile */
            .sidebar .nav-link {
                display: flex !important;
                align-items: center !important;
                justify-content: flex-start !important;
                padding: 12px 20px !important;
                gap: 12px !important;
            }

            .sidebar .nav-icon {
                width: 22px !important;
                height: 22px !important;
                margin-right: 0 !important;
                flex-shrink: 0 !important;
            }

            .sidebar .nav-icon img {
                width: 22px !important;
                height: 22px !important;
                margin-right: 0 !important;
            }

            .sidebar .nav-label,
            .sidebar .nav-link span:not(.nav-icon) {
                display: inline-block !important;
                white-space: nowrap !important;
                text-align: left !important;
            }

            /* Content should be full width */
            .main-content { 
                margin-left: 0 !important;
                padding: 10px !important;
                width: 100% !important;
                display: block !important;
                visibility: visible !important;
            }
            
            .header {
                display: block !important;
                visibility: visible !important;
                padding: 10px !important;
                margin-bottom: 10px !important;
                position: relative !important;
                z-index: 1 !important;
            }
            
            .header h1 {
                font-size: 18px !important;
                margin-top: 50px !important;
                margin-bottom: 15px !important;
                color: #2d3748 !important;
                display: block !important;
                visibility: visible !important;
                padding-top: 10px !important;
            }
        }
        
        /* Sidebar overlay for mobile - Match department.php */
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

        .scanner-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 30px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .scanner-header {
            text-align: center;
            margin-bottom: 30px;
            display: block !important;
            visibility: visible !important;
        }

        .scanner-header h1 {
            color: #2d3748;
            font-size: 28px;
            margin-bottom: 10px;
            display: block !important;
            visibility: visible !important;
        }

        .scanner-header p {
            color: #718096;
            font-size: 14px;
            display: block !important;
            visibility: visible !important;
        }

        .scanner-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .tab-button {
            flex: 1;
            padding: 12px 20px;
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #4a5568;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .tab-button:hover {
            background: #edf2f7;
            border-color: #cbd5e0;
        }

        .tab-button.active {
            background: #e53e3e;
            color: white;
            border-color: #e53e3e;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        #qr-reader {
            width: 100%;
            margin: 20px 0;
            min-height: 300px;
            display: none;
            visibility: hidden;
        }

        #qr-reader.active {
            display: block !important;
            visibility: visible !important;
        }

        #qr-reader > div {
            width: 100% !important;
            max-width: 100% !important;
        }

        /* Mobile QR reader styles */
        @media (max-width: 768px) {
            #qr-reader {
                width: 100% !important;
                margin: 15px 0 !important;
                min-height: 250px !important;
                display: none !important;
                visibility: hidden !important;
            }

            #qr-reader.active {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
            }

            #qr-reader > div {
                width: 100% !important;
                max-width: 100% !important;
            }

            #qr-reader video {
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
            }

            #qr-reader canvas {
                width: 100% !important;
                max-width: 100% !important;
            }
        }

        .start-camera-button {
            width: 100%;
            padding: 16px 24px;
            background: #e53e3e;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 20px 0;
        }

        .start-camera-button:hover {
            background: #c53030;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }

        .start-camera-button:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            transform: none;
        }

        .camera-placeholder {
            text-align: center;
            padding: 40px 20px;
            background: #f7fafc;
            border-radius: 12px;
            border: 2px dashed #cbd5e0;
            margin: 20px 0;
        }

        .camera-placeholder-icon {
            font-size: 64px;
            color: #cbd5e0;
            margin-bottom: 15px;
        }

        .camera-placeholder-text {
            font-size: 16px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 8px;
        }

        .camera-placeholder-hint {
            font-size: 14px;
            color: #718096;
        }

        .upload-area {
            border: 2px dashed #cbd5e0;
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .upload-area:hover {
            border-color: #e53e3e;
            background: #fef2f2;
        }

        .upload-icon {
            font-size: 48px;
            color: #cbd5e0;
            margin-bottom: 15px;
        }

        .upload-area:hover .upload-icon {
            color: #e53e3e;
        }

        .upload-text {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .upload-hint {
            font-size: 13px;
            color: #718096;
            margin-bottom: 15px;
        }

        .upload-button {
            padding: 12px 24px;
            background: #e53e3e;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .upload-button:hover {
            background: #c53030;
        }

        .scan-result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
            text-align: center;
        }

        .scan-result.success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #059669;
        }

        .scan-result.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        /* Item Details Display */
        .item-details-container {
            display: none;
            margin-top: 30px;
            padding: 25px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            animation: slideUp 0.5s ease;
        }

        .item-details-container.show {
            display: block;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .item-details-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .item-details-title {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .item-details-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
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
            background: #d4edda;
            color: #155724;
        }

        .status-working::before {
            background: #28a745;
        }

        .status-not-working {
            background: #f8d7da;
            color: #721c24;
        }

        .status-not-working::before {
            background: #dc3545;
        }

        .item-description {
            grid-column: 1 / -1;
            background: white;
            padding: 15px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        .item-description .detail-label {
            margin-bottom: 10px;
        }

        .item-description .detail-value {
            font-size: 14px;
            font-weight: 400;
            line-height: 1.6;
            color: #4a5568;
        }

        .item-image-container {
            grid-column: 1 / -1;
            background: white;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .item-image-container .detail-label {
            margin-bottom: 15px;
        }

        .item-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            object-fit: contain;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .item-image-placeholder {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: #adb5bd;
            border: 2px dashed #dee2e6;
        }

        .item-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e2e8f0;
        }

        /* Item Table Inventory Styles */
        .inventory-table-container {
            grid-column: 1 / -1;
            background: white;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-top: 20px;
        }

        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .inventory-table th {
            background: #f7fafc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
        }

        .inventory-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .inventory-table tr:hover {
            background: #f9fafb;
        }

        .quantity-input {
            width: 80px;
            padding: 6px 10px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            font-size: 14px;
            text-align: center;
        }

        .quantity-input:focus {
            outline: none;
            border-color: #e53e3e;
            box-shadow: 0 0 0 3px rgba(229, 62, 62, 0.1);
        }

        .status-select {
            padding: 6px 10px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }

        .status-select:focus {
            outline: none;
            border-color: #e53e3e;
            box-shadow: 0 0 0 3px rgba(229, 62, 62, 0.1);
        }

        .status-select.working {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .status-select.not-working {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        .status-select.defective {
            background: #fff3cd;
            color: #856404;
            border-color: #ffeaa7;
        }

        .status-select.missing {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        .btn-save-inventory {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
        }

        .btn-save-inventory:hover {
            background: #c53030;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }

        .btn-save-inventory:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            transform: none;
        }

        .changed-row {
            background: #fef3c7 !important;
        }

        .btn-action {
            flex: 1;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-view-department {
            background: #e53e3e;
            color: white;
        }

        .btn-view-department:hover {
            background: #c53030;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.3);
        }

        .btn-scan-again {
            background: #f7fafc;
            color: #4a5568;
            border: 2px solid #e2e8f0;
        }

        .btn-scan-again:hover {
            background: #edf2f7;
            border-color: #cbd5e0;
        }

        .changed-row {
            background: #fef3c7 !important;
        }

        @media (max-width: 768px) {
            .scanner-container {
                margin: 10px !important;
                margin-top: 70px !important;
                padding: 15px !important;
                width: calc(100% - 20px) !important;
                max-width: 100% !important;
                display: block !important;
                visibility: visible !important;
            }
            
            .header {
                margin-bottom: 10px !important;
                padding-bottom: 10px !important;
            }
            
            .header h1 {
                margin-top: 50px !important;
                margin-bottom: 15px !important;
            }

            .scanner-header {
                display: block !important;
                visibility: visible !important;
                margin-bottom: 20px !important;
            }

            .scanner-header h1 {
                font-size: 20px !important;
                color: #2d3748 !important;
                display: block !important;
                visibility: visible !important;
            }

            .scanner-header p {
                font-size: 13px !important;
                color: #718096 !important;
                display: block !important;
                visibility: visible !important;
                margin: 0 !important;
            }

            /* Ensure scan section is visible on mobile */
            #scan-section {
                display: block !important;
                visibility: visible !important;
            }

            #scan-section.tab-content.active {
                display: block !important;
                visibility: visible !important;
            }

            /* Camera placeholder visibility */
            #camera-placeholder {
                display: block !important;
                visibility: visible !important;
            }

            /* Start camera button visibility */
            #start-camera-btn {
                display: flex !important;
                visibility: visible !important;
                width: 100% !important;
            }

            .scanner-tabs {
                flex-direction: column;
            }

            .upload-area {
                padding: 30px 15px;
            }

            .upload-icon {
                font-size: 36px;
            }

            .item-details-content {
                grid-template-columns: 1fr;
            }

            .item-actions {
                flex-direction: column;
            }

            /* Mobile responsive for inventory table */
            .inventory-table-container {
                padding: 15px;
                overflow-x: auto;
                display: block !important;
                visibility: visible !important;
            }

            .inventory-table {
                font-size: 12px;
                min-width: 600px;
            }

            .inventory-table th,
            .inventory-table td {
                padding: 10px 8px;
                font-size: 12px;
            }

            .inventory-table th {
                font-size: 11px;
                white-space: nowrap;
            }

            .quantity-input {
                width: 70px;
                padding: 6px 8px;
                font-size: 13px;
            }

            .status-select {
                padding: 6px 8px;
                font-size: 13px;
                min-width: 100px;
            }

            .btn-save-inventory {
                width: 100%;
                padding: 14px 20px;
                font-size: 15px;
            }

            .item-image-container {
                padding: 15px;
            }

            .item-image {
                max-height: 200px;
            }

            .item-image-placeholder {
                height: 150px;
            }

            .detail-item {
                padding: 12px;
            }

            .detail-value {
                font-size: 15px;
            }

            .item-details-title {
                font-size: 20px;
            }
        }
        /* Fix for sidebar text visibility - MOBILE ONLY - Match department.php */
@media (max-width: 768px) {
            .sidebar .logo {
                padding: 0 20px !important;
            }
            
            .sidebar .logo h1,
            .sidebar .logo-text {
                display: block !important;
                visibility: visible !important;
                color: white !important;
            }
            
            .sidebar .logo-text p {
                display: block !important;
                visibility: visible !important;
                color: white !important;
            }
            
            .sidebar .sign-out {
                padding: 0 20px !important;
            }
            
            .sidebar .sign-out .nav-link {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        padding: 12px 20px !important;
        font-size: 14px !important;
        white-space: nowrap !important;
                color: white !important;
    }
}

/* Desktop collapsed sidebar - icons only (no text) */
@media (min-width: 769px) {
    /* Do NOT override sidebar behavior on desktop */
    /* Let dashboard.css handle collapsed state naturally */
}
/* Desktop sidebar collapse behavior - match dashboard.css */
@media (min-width: 769px) {
    /* Collapsed sidebar - icons only */
    body.sidebar-collapsed .sidebar {
        width: 70px !important;
    }
    
    /* Hide text but KEEP icons visible */
    body.sidebar-collapsed .sidebar .logo h1,
    body.sidebar-collapsed .sidebar .logo-text {
        display: none !important;
    }
    
    /* Hide nav link TEXT only, not the entire link */
    body.sidebar-collapsed .sidebar .nav-link {
        justify-content: center !important;
        padding: 15px !important;
        font-size: 0 !important; /* Hide text */
    }
    
    /* But SHOW icons */
    body.sidebar-collapsed .sidebar .nav-icon {
        display: inline-flex !important;
        margin: 0 !important;
        font-size: 24px !important; /* Restore icon size */
    }
    
    body.sidebar-collapsed .sidebar .nav-icon img {
        width: 24px !important;
        height: 24px !important;
    }
    
    /* Expanded sidebar - full width with text */
    body:not(.sidebar-collapsed) .sidebar {
        width: 250px !important;
    }
    
    body:not(.sidebar-collapsed) .sidebar .nav-link {
        font-size: 14px !important; /* Show text */
    }
    
    /* Adjust main content margin */
    body.sidebar-collapsed .main-content {
        margin-left: 70px !important;
    }
    
    body:not(.sidebar-collapsed) .main-content {
        margin-left: 250px !important;
    }
}
    </style>
</head>
<body data-user-logged-in="true" data-user-is-viewer="true">
    
    <!-- Sidebar overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar toggle (hamburger) - Fixed position for mobile -->
    <button id="sidebarToggleFixed" class="sidebar-toggle-fixed">☰</button>
    
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
            
             <ul class="nav-menu">
    <li class="nav-item">
        <a href="department.php" class="nav-link">
            <span class="nav-icon">
                <img src="image/department.png" alt="Item List">
            </span>
            Item List
        </a>
    </li>
    <li class="nav-item">
        <a href="viewer_qr_scanner.php" class="nav-link active">
            <span class="nav-icon">
                <img src="image/qr.png" alt="Scan QR">
            </span>
            Scan Item QR
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

        <div class="main-content" style="display: block !important; visibility: visible !important;">
        <div class="header" style="display: block !important; visibility: visible !important;">
            <?php include 'profile_dropdown.php'; ?>
            <h1 style="display: block !important; visibility: visible !important;">Scan Item QR Code</h1>
        </div>

        <div class="scanner-container" style="display: block !important; visibility: visible !important;">
            <div class="scanner-header" style="display: block !important; visibility: visible !important;">
                <h1 style="display: block !important; visibility: visible !important;"><i class="fas fa-qrcode"></i> Item QR Scanner</h1>
                <p style="display: block !important; visibility: visible !important;">Scan QR code with camera to view item details</p>
            </div>

            <div class="scanner-tabs" style="display: none;">
                <button class="tab-button active" onclick="switchTab('scan')">
                    <i class="fas fa-camera"></i> Scan with Camera
                </button>
            </div>

            <!-- Camera Scan Section -->
            <div id="scan-section" class="tab-content active">
                <div id="camera-placeholder" class="camera-placeholder">
                    <div class="camera-placeholder-icon">
                        <i class="fas fa-camera"></i>
                    </div>
                    <div class="camera-placeholder-text">Camera Ready</div>
                    <p class="camera-placeholder-hint">Click the button below to start scanning</p>
                </div>
                <button id="start-camera-btn" class="start-camera-button" onclick="startCameraScan()">
                    <i class="fas fa-video"></i> Start Camera
                </button>
                <div id="qr-reader"></div>
                <div id="scan-result" class="scan-result"></div>
            </div>

            <!-- Upload Section - Hidden for viewers/teachers -->
            <div id="upload-section" class="tab-content" style="display: none !important;">
            </div>

            <!-- Item Details Display -->
            <div id="item-details-container" class="item-details-container">
                <div class="item-details-header">
                    <h2 class="item-details-title">
                        <i class="fas fa-box"></i> <span id="details-title-text">Item Details</span>
                    </h2>
                </div>
                <div id="item-details-content" class="item-details-content">
                    <!-- Item details will be loaded here -->
                </div>
                
                <!-- Item Table Inventory -->
                <div id="inventory-table-container" class="inventory-table-container" style="display: none;">
                    <h3 style="margin: 0 0 15px 0; font-size: 18px; font-weight: 600; color: #2d3748;">
                        <i class="fas fa-list"></i> Inventory Items
                    </h3>
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Item Code</th>
                                <th>Quantity</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="inventory-table-body">
                            <!-- Items will be loaded here -->
                        </tbody>
                    </table>
                    <button id="btn-save-inventory" class="btn-save-inventory" onclick="saveInventory()">
                        <i class="fas fa-save"></i> Save Inventory
                    </button>
                </div>
                
                <div class="item-actions">
                    <button class="btn-action btn-view-department" onclick="viewInDepartment()">
                        <i class="fas fa-building"></i> View in Department
                    </button>
                    <button class="btn-action btn-scan-again" onclick="scanAgain()">
                        <i class="fas fa-qrcode"></i> Scan Another
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            if (text == null) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        let html5QrCode = null;
        let currentTab = 'scan';

        // Tab switching
        function switchTab(tab) {
            // Only scan tab is available for viewers/teachers
            currentTab = 'scan';
            
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            document.querySelector('.tab-button:first-child').classList.add('active');
            document.getElementById('scan-section').classList.add('active');
            // Don't auto-start camera, wait for user to click button
            stopQRScanner();
        }

        // Start camera scanning (called by button click)
        function startCameraScan() {
            const startBtn = document.getElementById('start-camera-btn');
            const placeholder = document.getElementById('camera-placeholder');
            const qrReader = document.getElementById('qr-reader');
            const scannerHeader = document.querySelector('.scanner-header');
            
            // Ensure scanner header is visible
            if (scannerHeader) {
                scannerHeader.style.setProperty('display', 'block', 'important');
                scannerHeader.style.setProperty('visibility', 'visible', 'important');
            }
            
            startBtn.disabled = true;
            startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Starting Camera...';
            
            startQRScanner();
        }

        // Sidebar toggle
        // Mobile detection function - Match department.php
        function isMobile() {
            return window.innerWidth <= 768;
        }
        
        function toggleSidebar(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const fixedBtn = document.getElementById('sidebarToggleFixed');
            
            if (!sidebar) {
                console.error('Sidebar not found!');
                return;
            }
            
            if (isMobile()) {
                // Mobile behavior: slide sidebar in/out with overlay
                const isOpen = sidebar.classList.contains('open');
                
                console.log('Mobile toggle - isOpen:', isOpen);
                
                if (isOpen) {
                    // Close sidebar
                    console.log('Closing sidebar...');
                    closeSidebarMobile();
                } else {
                    // Open sidebar
                    console.log('Opening sidebar...');
                    sidebar.classList.add('open');
                    sidebar.style.setProperty('transform', 'translateX(0)', 'important');
                    sidebar.style.setProperty('display', 'block', 'important');
                    sidebar.style.setProperty('visibility', 'visible', 'important');
                    sidebar.style.setProperty('opacity', '1', 'important');
                    
                    if (overlay) {
                        overlay.classList.add('show');
                        overlay.style.display = 'block';
                    }
                    document.body.style.overflow = 'hidden';
                    
                    // Hide fixed button when sidebar opens
                    if (fixedBtn) {
                        fixedBtn.style.setProperty('display', 'none', 'important');
                    }
                }
            } else {
                // Desktop behavior: collapse/expand
                const isCollapsed = document.body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('ocabis:sidebar-collapsed', isCollapsed ? '1' : '0');
            }
        }
        
        // Close sidebar function for mobile
        function closeSidebarMobile() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const fixedBtn = document.getElementById('sidebarToggleFixed');
            
            if (!sidebar) {
                console.error('Sidebar not found in closeSidebarMobile');
                return;
            }
            
            if (!isMobile()) {
                console.log('Not mobile, skipping close');
                return;
            }
            
            console.log('Closing sidebar mobile...');
            
            // Remove open class first
            sidebar.classList.remove('open');
            
            // Force transform with !important - multiple attempts to ensure it works
            sidebar.style.removeProperty('transform');
            setTimeout(() => {
                sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
            }, 10);
            
            sidebar.style.setProperty('display', 'block', 'important');
            sidebar.style.setProperty('visibility', 'visible', 'important');
            sidebar.style.setProperty('opacity', '1', 'important');
            
            // Hide overlay
            if (overlay) {
                overlay.classList.remove('show');
                overlay.style.setProperty('display', 'none', 'important');
                overlay.style.setProperty('opacity', '0', 'important');
            }
            
            // Restore body scroll
            document.body.style.overflow = '';
            document.body.style.overflowX = '';
            document.body.style.overflowY = '';
            document.body.classList.remove('sidebar-open');
            
            // Show fixed button
            if (fixedBtn) {
                fixedBtn.style.setProperty('display', 'flex', 'important');
                fixedBtn.style.setProperty('visibility', 'visible', 'important');
                fixedBtn.style.setProperty('opacity', '1', 'important');
                fixedBtn.style.setProperty('z-index', '1300', 'important');
            }
            
            // Force close with timeout as fallback
            setTimeout(() => {
                if (sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                    sidebar.style.setProperty('transform', 'translateX(-100%)', 'important');
                }
            }, 50);
            
            console.log('Sidebar closed');
        }

        // Start QR Scanner
        function startQRScanner() {
            if (html5QrCode) {
                return; // Already started
            }

            // Mobile browsers require HTTPS for camera access (except localhost)
            if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                const resultDiv = document.getElementById('scan-result');
                if (resultDiv) {
                    resultDiv.className = 'scan-result error';
                    resultDiv.innerHTML = '<p>Camera access requires HTTPS on mobile when using IP address.<br/>Please access this page over HTTPS (e.g., ngrok/mkcert) to use the camera scanner.</p>';
                    resultDiv.style.display = 'block';
                }
                document.getElementById('scan-section').classList.remove('active');
                return;
            }

            const startBtn = document.getElementById('start-camera-btn');
            const placeholder = document.getElementById('camera-placeholder');
            const qrReader = document.getElementById('qr-reader');

            // Show qr-reader before initializing
            if (qrReader) {
                qrReader.style.setProperty('display', 'block', 'important');
                qrReader.style.setProperty('visibility', 'visible', 'important');
                qrReader.style.setProperty('opacity', '1', 'important');
                qrReader.style.setProperty('width', '100%', 'important');
                qrReader.style.setProperty('min-height', '250px', 'important');
                qrReader.classList.add('active');
            }

            html5QrCode = new Html5Qrcode("qr-reader");
            
            // Adjust QR box size for mobile
            const isMobileDevice = window.innerWidth <= 768;
            const qrboxSize = isMobileDevice ? Math.min(250, window.innerWidth - 60) : 250;
            
            console.log('Starting QR scanner with box size:', qrboxSize, 'isMobile:', isMobileDevice);
            
            html5QrCode.start(
                { facingMode: "environment" },
                {
                    fps: 10,
                    qrbox: { width: qrboxSize, height: qrboxSize },
                    aspectRatio: 1.0
                },
                onScanSuccess,
                onScanError
            ).then(() => {
                // Camera started successfully
                console.log('Camera started successfully');
                if (placeholder) {
                    placeholder.style.setProperty('display', 'none', 'important');
                    placeholder.style.setProperty('visibility', 'hidden', 'important');
                }
                if (startBtn) {
                    startBtn.style.setProperty('display', 'none', 'important');
                    startBtn.style.setProperty('visibility', 'hidden', 'important');
                }
                // Ensure QR reader is visible
                if (qrReader) {
                    qrReader.style.setProperty('display', 'block', 'important');
                    qrReader.style.setProperty('visibility', 'visible', 'important');
                    qrReader.style.setProperty('opacity', '1', 'important');
                    qrReader.style.setProperty('width', '100%', 'important');
                    qrReader.style.setProperty('min-height', '250px', 'important');
                    qrReader.classList.add('active');
                    
                    // Force show on mobile
                    if (isMobileDevice) {
                        qrReader.style.setProperty('position', 'relative', 'important');
                        qrReader.style.setProperty('z-index', '1', 'important');
                    }
                }
            }).catch(err => {
                console.error("Unable to start QR scanner", err);
                showResult('error', 'Unable to access camera. Please check permissions.');
                if (startBtn) {
                    startBtn.disabled = false;
                    startBtn.innerHTML = '<i class="fas fa-video"></i> Start Camera';
                }
                if (qrReader) {
                    qrReader.style.display = 'none';
                    qrReader.classList.remove('active');
                }
            });
        }

        function stopQRScanner() {
            if (html5QrCode) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                    html5QrCode = null;
                }).catch(err => {
                    console.error("Error stopping scanner", err);
                });
            }
            
            // Reset UI
            const startBtn = document.getElementById('start-camera-btn');
            const placeholder = document.getElementById('camera-placeholder');
            const qrReader = document.getElementById('qr-reader');
            
            if (placeholder) placeholder.style.display = 'block';
            if (startBtn) {
                startBtn.style.display = 'block';
                startBtn.disabled = false;
                startBtn.innerHTML = '<i class="fas fa-video"></i> Start Camera';
            }
            if (qrReader) {
                qrReader.style.display = 'none';
                qrReader.classList.remove('active');
                qrReader.innerHTML = ''; // Clear the scanner
            }
        }

        function onScanSuccess(decodedText) {
            console.log('QR Code scanned:', decodedText);
            stopQRScanner();
            showResult('success', 'QR Code scanned! Loading...');
            processItemQR(decodedText);
        }

        function onScanError(errorMessage) {
            // Ignore scan errors - just keep trying
        }

        // Handle file upload - Disabled for viewers/teachers
        function handleFileUpload(event) {
            // Upload disabled for viewers/teachers - they can only use camera scanner
            return;
        }
        
        // Removed upload functionality - viewers/teachers can only use camera scanner

        // Store current item ID and table ID
        let currentItemId = null;
        let currentTableId = null;
        let originalInventoryData = {};

        // Process QR code (items or item tables)
        async function processItemQR(qrCode) {
            console.log('Processing QR code:', qrCode);
            
            let itemId = null;
            let tableId = null;
            
            // Check if it's a URL
            if (qrCode.startsWith('http://') || qrCode.startsWith('https://')) {
                // Check if it's view_item URL
                if (qrCode.includes('view_item.php?id=')) {
                    const urlMatch = qrCode.match(/view_item\.php[?&]id=(\d+)/);
                    if (urlMatch) {
                        itemId = urlMatch[1];
                    }
                }
                // Check if it's item table inventory URL
                else if (qrCode.includes('item_table_inventory.php')) {
                    const urlMatch = qrCode.match(/item_table_inventory\.php[?&]table_id=(\d+)/);
                    if (urlMatch) {
                        tableId = urlMatch[1];
                    }
                }
            }
            
            // Check if it's an item table QR code (starts with TABLE-)
            if (!tableId && (qrCode.startsWith('TABLE-') || qrCode.includes('TABLE-'))) {
                const tableIdMatch = qrCode.match(/TABLE-(\d+)/);
                if (tableIdMatch) {
                    tableId = tableIdMatch[1];
                }
            }
            
            // If it's an item table QR code, load item table inventory
            if (tableId) {
                currentTableId = tableId;
                await loadItemTableInventory(tableId);
                return;
            }
            
            // Try to parse as JSON (legacy format)
            if (!itemId) {
                try {
                    const data = JSON.parse(qrCode);
                    if (data.id) {
                        itemId = data.id;
                    }
                } catch (e) {
                    // Not JSON, continue
                }
            }
            
            // Try as plain number (item ID)
            if (!itemId && /^\d+$/.test(qrCode.trim())) {
                itemId = qrCode.trim();
            }
            
            // If we found an item ID, fetch and display item details
            if (itemId) {
                currentItemId = itemId;
                await loadItemDetails(itemId);
            } else {
                showResult('error', 'Invalid QR code. Please scan a valid item or item table QR code.');
                if (currentTab === 'scan') {
                    setTimeout(() => startQRScanner(), 2000);
                }
            }
        }

        // Load and display item details
        async function loadItemDetails(itemId) {
            try {
                console.log('Loading item details for ID:', itemId);
                showResult('success', 'Loading item details...');
                
                const response = await fetch('view_item_api.php?id=' + itemId);
                console.log('API Response status:', response.status, response.statusText);
                
                // Check if response is OK
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('HTTP error response:', errorText);
                    throw new Error('HTTP error! status: ' + response.status + ' - ' + errorText);
                }
                
                // Try to parse JSON
                let data;
                try {
                    const responseText = await response.text();
                    console.log('API Response text:', responseText);
                    data = JSON.parse(responseText);
                } catch (jsonError) {
                    console.error('JSON parse error:', jsonError);
                    throw new Error('Invalid response from server. Please check your connection.');
                }
                
                console.log('Parsed API data:', data);
                
                if (data.success && data.item) {
                    displayItemDetails(data.item);
                    // Hide scanner section
                    document.getElementById('scan-section').style.display = 'none';
                    document.getElementById('upload-section').style.display = 'none';
                    document.querySelector('.scanner-tabs').style.display = 'none';
                    document.querySelector('.scanner-header').style.display = 'none';
                } else {
                    const errorMsg = data.message || 'Unknown error';
                    console.error('API returned error:', errorMsg);
                    showResult('error', 'Item not found: ' + errorMsg);
                    if (currentTab === 'scan') {
                        setTimeout(() => startQRScanner(), 2000);
                    }
                }
            } catch (error) {
                console.error('Error loading item details:', error);
                console.error('Error stack:', error.stack);
                const errorMessage = error.message || 'Error loading item details. Please try again.';
                showResult('error', errorMessage);
                if (currentTab === 'scan') {
                    setTimeout(() => startQRScanner(), 2000);
                }
            }
        }

        // Display item details
        function displayItemDetails(item) {
            const detailsContainer = document.getElementById('item-details-container');
            const detailsContent = document.getElementById('item-details-content');
            
            // Format status badge
            const statusClass = item.status && item.status.toLowerCase().includes('working') ? 'status-working' : 'status-not-working';
            const statusText = item.status || 'Unknown';
            
            // Format date
            const dateAdded = item.date_added ? new Date(item.date_added).toLocaleDateString() : 'N/A';
            
            // Item table image - always show, with placeholder if no image
            const tableImage = item.table_image_path ? 
                `<img src="${escapeHtml(item.table_image_path)}" alt="Item Table" class="item-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                 <div class="item-image-placeholder" style="display: none;">
                     <i class="fas fa-table"></i>
                 </div>` : 
                `<div class="item-image-placeholder">
                     <i class="fas fa-table"></i>
                 </div>`;
            
            detailsContent.innerHTML = `
                <div class="item-image-container">
                    <div class="detail-label">Item Table</div>
                    ${tableImage}
                </div>
                <div class="detail-item">
                    <div class="detail-label">Item Name</div>
                    <div class="detail-value">${escapeHtml(item.name || 'N/A')}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Item Code</div>
                    <div class="detail-value">${escapeHtml(item.item_code || 'N/A')}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Quantity</div>
                    <div class="detail-value">${item.quantity || 0}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span class="status-badge ${statusClass}">${escapeHtml(statusText)}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Department</div>
                    <div class="detail-value">${escapeHtml(item.department_name || 'N/A')}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Location</div>
                    <div class="detail-value">${escapeHtml(item.location || 'N/A')}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Date Added</div>
                    <div class="detail-value">${dateAdded}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Category</div>
                    <div class="detail-value">${escapeHtml(item.category || 'N/A')}</div>
                </div>
                ${item.description ? `
                <div class="item-description">
                    <div class="detail-label">Description</div>
                    <div class="detail-value">${escapeHtml(item.description)}</div>
                </div>
                ` : ''}
            `;
            
            detailsContainer.classList.add('show');
        }

        // Load and display item table inventory
        async function loadItemTableInventory(tableId) {
            try {
                showResult('success', 'Loading item table inventory...');
                
                // Get item table details
                const tableResponse = await fetch('item_table_inventory_api.php?action=get_item_table&table_id=' + tableId);
                const tableData = await tableResponse.json();
                
                if (!tableData.success || !tableData.item_table) {
                    showResult('error', 'Item table not found: ' + (tableData.message || 'Unknown error'));
                    if (currentTab === 'scan') {
                        setTimeout(() => startQRScanner(), 2000);
                    }
                    return;
                }
                
                // Get items in the table
                const itemsResponse = await fetch('item_table_inventory_api.php?action=get_items&item_table_id=' + tableId);
                const itemsData = await itemsResponse.json();
                
                if (!itemsData.success) {
                    showResult('error', 'Error loading items: ' + (itemsData.message || 'Unknown error'));
                    if (currentTab === 'scan') {
                        setTimeout(() => startQRScanner(), 2000);
                    }
                    return;
                }
                
                // Display item table details and inventory
                displayItemTableInventory(tableData.item_table, itemsData.items || []);
                
                // Hide scanner section
                document.getElementById('scan-section').style.display = 'none';
                document.getElementById('upload-section').style.display = 'none';
                document.querySelector('.scanner-tabs').style.display = 'none';
                document.querySelector('.scanner-header').style.display = 'none';
            } catch (error) {
                console.error('Error loading item table inventory:', error);
                showResult('error', 'Error loading item table inventory. Please try again.');
                if (currentTab === 'scan') {
                    setTimeout(() => startQRScanner(), 2000);
                }
            }
        }

        // Display item table inventory
        function displayItemTableInventory(itemTable, items) {
            const detailsContainer = document.getElementById('item-details-container');
            const detailsContent = document.getElementById('item-details-content');
            const inventoryContainer = document.getElementById('inventory-table-container');
            const titleText = document.getElementById('details-title-text');
            
            // Update title
            titleText.textContent = 'Item Table Inventory';
            
            // Item table image
            const tableImage = itemTable.table_image_path ? 
                `<img src="${escapeHtml(itemTable.table_image_path)}" alt="Item Table" class="item-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                 <div class="item-image-placeholder" style="display: none;">
                     <i class="fas fa-table"></i>
                 </div>` : 
                `<div class="item-image-placeholder">
                     <i class="fas fa-table"></i>
                 </div>`;
            
            // Display item table info
            detailsContent.innerHTML = `
                <div class="item-image-container">
                    <div class="detail-label">Item Table</div>
                    ${tableImage}
                </div>
                <div class="detail-item">
                    <div class="detail-label">Table Name</div>
                    <div class="detail-value">${escapeHtml(itemTable.table_name || 'N/A')}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Total Items</div>
                    <div class="detail-value">${items.length}</div>
                </div>
            `;
            
            // Display inventory table
            displayInventoryTable(items);
            if (inventoryContainer) {
                inventoryContainer.style.display = 'block';
                inventoryContainer.style.visibility = 'visible';
                // Force show on mobile
                inventoryContainer.style.setProperty('display', 'block', 'important');
            }
            
            detailsContainer.classList.add('show');
            
            // Scroll to inventory table on mobile for better UX
            setTimeout(() => {
                if (window.innerWidth <= 768 && inventoryContainer) {
                    inventoryContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }, 300);
        }

        // Display inventory table
        function displayInventoryTable(items) {
            const tbody = document.getElementById('inventory-table-body');
            if (!tbody) {
                console.error('Inventory table body not found');
                return;
            }
            
            tbody.innerHTML = '';
            originalInventoryData = {};
            
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; padding: 40px; color: #9ca3af;">No items found in this table</td></tr>';
                return;
            }
            
            items.forEach(item => {
                // Store original values
                originalInventoryData[item.id] = {
                    quantity: parseInt(item.quantity) || 0,
                    status: item.status || 'Working'
                };
                
                const statusClass = getStatusClass(item.status);
                const row = document.createElement('tr');
                row.setAttribute('data-item-id', item.id);
                
                row.innerHTML = `
                    <td>${escapeHtml(item.name || 'N/A')}</td>
                    <td>${escapeHtml(item.item_code || 'N/A')}</td>
                    <td>
                        <input type="number" 
                               class="quantity-input" 
                               value="${item.quantity || 0}" 
                               min="0" 
                               max="${originalInventoryData[item.id].quantity}"
                               data-item-id="${item.id}"
                               onchange="handleQuantityChange(${item.id}, this.value, ${originalInventoryData[item.id].quantity})">
                    </td>
                    <td>
                        <select class="status-select ${statusClass}" 
                                data-item-id="${item.id}"
                                onchange="handleStatusChange(${item.id}, this.value, '${originalInventoryData[item.id].status}')">
                            <option value="Working" ${item.status === 'Working' ? 'selected' : ''}>Working</option>
                            <option value="Not Working" ${item.status === 'Not Working' ? 'selected' : ''}>Not Working</option>
                            <option value="Defective" ${item.status === 'Defective' ? 'selected' : ''}>Defective</option>
                            <option value="Missing" ${item.status === 'Missing' ? 'selected' : ''}>Missing</option>
                        </select>
                    </td>
                `;
                
                tbody.appendChild(row);
            });
            
            console.log('Inventory table displayed with', items.length, 'items');
        }

        function getStatusClass(status) {
            if (!status) return '';
            const statusLower = status.toLowerCase();
            if (statusLower.includes('working') && !statusLower.includes('not')) return 'working';
            if (statusLower.includes('defective')) return 'defective';
            if (statusLower.includes('missing')) return 'missing';
            return 'not-working';
        }

        function handleQuantityChange(itemId, newValue, originalValue) {
            const newQty = parseInt(newValue) || 0;
            const origQty = parseInt(originalValue) || 0;
            
            // Prevent increasing quantity beyond original
            if (newQty > origQty) {
                const input = document.querySelector(`input[data-item-id="${itemId}"]`);
                input.value = origQty;
                alert('Cannot increase quantity beyond original stock. You can only decrease it.');
                return;
            }
            
            // Highlight changed row
            const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
            if (newQty !== origQty) {
                row.classList.add('changed-row');
            } else {
                const statusSelect = document.querySelector(`select[data-item-id="${itemId}"]`);
                const currentStatus = statusSelect.value;
                const originalStatus = originalInventoryData[itemId].status;
                
                if (currentStatus === originalStatus) {
                    row.classList.remove('changed-row');
                }
            }
        }

        function handleStatusChange(itemId, newStatus, originalStatus) {
            const select = document.querySelector(`select[data-item-id="${itemId}"]`);
            select.className = 'status-select ' + getStatusClass(newStatus);
            
            // Highlight changed row
            const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
            const quantityInput = document.querySelector(`input[data-item-id="${itemId}"]`);
            const currentQty = parseInt(quantityInput.value) || 0;
            const originalQty = originalInventoryData[itemId].quantity;
            
            if (newStatus !== originalStatus || currentQty !== originalQty) {
                row.classList.add('changed-row');
            } else {
                row.classList.remove('changed-row');
            }
        }

        // Save inventory
        async function saveInventory() {
            if (!currentTableId) {
                alert('No item table selected.');
                return;
            }
            
            const btn = document.getElementById('btn-save-inventory');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            
            // Collect all changes
            const updates = [];
            const rows = document.querySelectorAll('#inventory-table-body tr[data-item-id]');
            
            rows.forEach(row => {
                const itemId = parseInt(row.getAttribute('data-item-id'));
                const quantityInput = row.querySelector('.quantity-input');
                const statusSelect = row.querySelector('.status-select');
                
                const currentQty = parseInt(quantityInput.value) || 0;
                const currentStatus = statusSelect.value;
                const originalQty = originalInventoryData[itemId].quantity;
                const originalStatus = originalInventoryData[itemId].status;
                
                // Only include if changed
                if (currentQty !== originalQty || currentStatus !== originalStatus) {
                    updates.push({
                        item_id: itemId,
                        quantity: currentQty,
                        status: currentStatus,
                        previous_quantity: originalQty,
                        previous_status: originalStatus
                    });
                }
            });
            
            if (updates.length === 0) {
                alert('No changes to save.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save Inventory';
                return;
            }
            
            try {
                const response = await fetch('item_table_inventory_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_inventory',
                        item_table_id: currentTableId,
                        updates: updates
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Inventory saved successfully!');
                    // Reload inventory to reflect changes
                    await loadItemTableInventory(currentTableId);
                } else {
                    alert('Error saving inventory: ' + (data.message || 'Unknown error'));
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save"></i> Save Inventory';
                }
            } catch (error) {
                console.error('Error saving inventory:', error);
                alert('Error saving inventory. Please try again.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save Inventory';
            }
        }

        // View in Department
        function viewInDepartment() {
            window.location.href = 'department.php';
        }

        // Scan again
        function scanAgain() {
            // Reset UI
            document.getElementById('item-details-container').classList.remove('show');
            document.getElementById('scan-section').style.display = 'block';
            // Upload section hidden for viewers/teachers
            document.getElementById('upload-section').style.display = 'none';
            document.querySelector('.scanner-tabs').style.display = 'none';
            document.querySelector('.scanner-header').style.display = 'block';
            
            // Clear results
            document.getElementById('scan-result').style.display = 'none';
            document.getElementById('upload-result').style.display = 'none';
            
            // Reset scanner
            stopQRScanner();
            
            // Reset current item and table
            currentItemId = null;
            currentTableId = null;
            originalInventoryData = {};
            
            // Hide inventory table
            document.getElementById('inventory-table-container').style.display = 'none';
            
            // Switch to scan tab
            switchTab('scan');
        }

        // Show result message
        function showResult(type, message) {
            const resultDiv = document.getElementById('scan-result');
            resultDiv.className = 'scan-result ' + type;
            resultDiv.innerHTML = '<p>' + message + '</p>';
            resultDiv.style.display = 'block';
        }

        // Initialize page - Match department.php
        document.addEventListener('DOMContentLoaded', function() {
            const BODY_CLASS = 'sidebar-collapsed';
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            function applyInitialState() {
                const saved = localStorage.getItem('ocabis:sidebar-collapsed');
                const isCollapsed = saved === '1';
                
                if (isMobile()) {
                    // On mobile, don't apply collapsed state initially
                    const fixedBtn = document.getElementById('sidebarToggleFixed');
                    sidebar.classList.remove('open');
                    if (overlay) overlay.classList.remove('show');
                    document.body.style.overflow = '';
                    // Ensure fixed button is visible on mobile
                    if (fixedBtn) fixedBtn.style.display = 'flex';
                } else {
                    // On desktop, apply saved state
                    document.body.classList.toggle(BODY_CLASS, isCollapsed);
                }
            }
            
            // Apply initial state
            applyInitialState();
            
            // Sidebar toggle buttons - use capture phase to ensure it fires first
            const inlineBtn = document.getElementById('sidebarToggle');
            const fixedBtn = document.getElementById('sidebarToggleFixed');
            
            const handleToggle = function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                toggleSidebar(e);
                return false;
            };
            
            if (inlineBtn) {
                // Use capture phase so it fires before sidebar's stopPropagation
                inlineBtn.addEventListener('click', handleToggle, true);
                inlineBtn.addEventListener('touchend', handleToggle, true);
                inlineBtn.addEventListener('touchstart', function(e) { e.stopPropagation(); }, true);
                // Make sure button is clickable
                inlineBtn.style.pointerEvents = 'auto';
                inlineBtn.style.cursor = 'pointer';
                inlineBtn.style.zIndex = '1301';
                inlineBtn.style.position = 'relative';
            }
            if (fixedBtn) {
                // Use capture phase so it fires before sidebar's stopPropagation
                fixedBtn.addEventListener('click', handleToggle, true);
                fixedBtn.addEventListener('touchend', handleToggle, true);
                fixedBtn.addEventListener('touchstart', function(e) { e.stopPropagation(); }, true);
                // Make sure button is clickable
                fixedBtn.style.pointerEvents = 'auto';
                fixedBtn.style.cursor = 'pointer';
                fixedBtn.style.zIndex = '1301';
            }
            
            // Close sidebar when clicking overlay (mobile only) - Match department.php
            if (overlay) {
                const handleOverlayClose = function(e) {
                    if (isMobile()) {
                        e.preventDefault();
                        e.stopPropagation();
                        closeSidebarMobile();
                    }
                };
                overlay.addEventListener('click', handleOverlayClose);
                overlay.addEventListener('touchend', handleOverlayClose);
                // Make overlay clickable
                overlay.style.pointerEvents = 'auto';
                overlay.style.cursor = 'pointer';
            }
            
            // Close sidebar when clicking on nav links (mobile only)
            if (sidebar) {
                const navLinks = sidebar.querySelectorAll('.nav-link');
                navLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        if (isMobile()) {
                            // Small delay to allow navigation
                            setTimeout(() => {
                                closeSidebarMobile();
                            }, 100);
                        }
                    });
                });
            }
            
            // Prevent sidebar clicks from bubbling to overlay (but allow toggle buttons to work)
            if (sidebar) {
                sidebar.addEventListener('click', function(e) {
                    // Don't stop propagation for toggle buttons or their children
                    const target = e.target;
                    const isToggleButton = target.id === 'sidebarToggle' || 
                                          target.id === 'sidebarToggleFixed' ||
                                          target.closest('#sidebarToggle') || 
                                          target.closest('#sidebarToggleFixed') ||
                                          target.closest('.sidebar-toggle-inline') ||
                                          target.closest('.sidebar-toggle-fixed') ||
                                          target.classList.contains('sidebar-toggle-inline');
                    
                    if (!isToggleButton) {
                        e.stopPropagation(); // Prevent click from reaching overlay
                    } else {
                        // Allow toggle button clicks to work
                        console.log('Toggle button clicked, allowing event');
                    }
                }, false); // Use bubble phase, not capture
            }
            
            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (isMobile()) {
                        // On mobile, ensure sidebar is closed and reset desktop state
                        const fixedBtn = document.getElementById('sidebarToggleFixed');
                        document.body.classList.remove(BODY_CLASS);
                        sidebar.classList.remove('open');
                        if (overlay) overlay.classList.remove('show');
                        document.body.style.overflow = '';
                        if (fixedBtn) fixedBtn.style.display = 'flex';
                    } else {
                        // On desktop, apply saved state
                        const saved = localStorage.getItem('ocabis:sidebar-collapsed');
                        const isCollapsed = saved === '1';
                        document.body.classList.toggle(BODY_CLASS, isCollapsed);
                    }
                }, 100);
            });

            // Don't auto-start camera - wait for user to click button
        });

        // Cleanup when page is unloaded
        window.addEventListener('beforeunload', function() {
            stopQRScanner();
        });
    </script>
</body>
</html>



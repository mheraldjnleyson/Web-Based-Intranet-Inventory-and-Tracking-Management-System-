// Session monitoring and conflict detection
class SessionMonitor {
    constructor() {
        this.checkInterval = 5000; // Check every 5 seconds for faster response
        this.isActive = true;
        this.consecutiveFailures = 0; // Track consecutive failures
        this.maxConsecutiveFailures = 3; // Allow 3 failures before showing modal
        this.init();
    }

    init() {
        // Check for database error on page load
        this.checkDatabaseErrorOnLoad();
        
        // Start monitoring
        this.startMonitoring();
        
        // Monitor page visibility changes
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.checkSession();
            }
        });

        // Monitor beforeunload to clean up
        window.addEventListener('beforeunload', () => {
            this.cleanup();
        });
    }

    startMonitoring() {
        if (this.isActive) {
            this.checkSession();
            setTimeout(() => this.startMonitoring(), this.checkInterval);
        }
    }

    checkDatabaseErrorOnLoad() {
        // Check if we have a stored database error from previous page load
        const dbError = localStorage.getItem('ocabis_database_error');
        const errorTimestamp = localStorage.getItem('ocabis_database_error_time');
        
        // Only show error if it happened within last 30 seconds
        if (dbError === 'true' && errorTimestamp) {
            const timeSinceError = Date.now() - parseInt(errorTimestamp);
            if (timeSinceError < 30000) { // 30 seconds
                this.handleDatabaseError({ database_error: true });
            } else {
                // Clear old error
                localStorage.removeItem('ocabis_database_error');
                localStorage.removeItem('ocabis_database_error_time');
            }
        }
    }

    async checkSession() {
        try {
            const response = await fetch('crud.php?action=check_session', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-cache'
            });
            
            // Check if response is ok
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            const data = await response.json();
            
            // Reset consecutive failures on successful response
            this.consecutiveFailures = 0;
            
            // Clear any previous database error flags on successful connection
            localStorage.removeItem('ocabis_database_error');
            localStorage.removeItem('ocabis_database_error_time');
            
            // Check if database error occurred FIRST (priority check)
            if (data.database_error || data.database_corrupted) {
                // Save database error state with timestamp
                localStorage.setItem('ocabis_database_error', 'true');
                localStorage.setItem('ocabis_database_error_time', Date.now().toString());
                this.handleDatabaseError(data);
                return;
            }
            
            // Check if account is locked FIRST (highest priority)
            // This must be checked before session_expired to prevent session expired modal from showing
            if (data.account_locked) {
                this.handleAccountLocked(data);
                return; // Stop here - don't show session expired modal
            }
            
            // Only do normal session checks if database is working and account is not locked
            // Check if account locked modal is already showing - if so, don't show session expired
            const accountLockedModal = document.getElementById('accountLockedModal');
            if (accountLockedModal) {
                // Account locked modal is showing - don't show session expired
                return;
            }
            
            if (data.session_expired || !data.success) {
                this.handleSessionExpired();
            } else if (data.session_invalidated) {
                this.handleSessionInvalidated();
            }
        } catch (error) {
            console.log('Session check failed:', error);
            this.consecutiveFailures++;
            
            // Only handle as error if we have multiple consecutive failures
            if (this.consecutiveFailures >= this.maxConsecutiveFailures) {
                const dbError = localStorage.getItem('ocabis_database_error');
                const errorTimestamp = localStorage.getItem('ocabis_database_error_time');
                
                // Check if error happened recently (within last 30 seconds)
                if (dbError === 'true' && errorTimestamp) {
                    const timeSinceError = Date.now() - parseInt(errorTimestamp);
                    if (timeSinceError < 30000) {
                        this.handleDatabaseError({ database_error: true });
                        return;
                    }
                }
                
                // If no recent database error, save new one
                localStorage.setItem('ocabis_database_error', 'true');
                localStorage.setItem('ocabis_database_error_time', Date.now().toString());
                this.handleDatabaseError({ database_error: true });
            }
        }
    }

    handleSessionExpired() {
        this.isActive = false;
        
        // Clear database error flags
        localStorage.removeItem('ocabis_database_error');
        localStorage.removeItem('ocabis_database_error_time');
        
        // Show session expired modal
        this.showSessionExpiredModal();
    }

    handleSessionInvalidated() {
        this.isActive = false;
        
        // Clear database error flags
        localStorage.removeItem('ocabis_database_error');
        localStorage.removeItem('ocabis_database_error_time');
        
        // Show session invalidated modal (logged in from another device)
        this.showSessionInvalidatedModal();
    }

    handleAccountLocked(data) {
        this.isActive = false;
        
        // Clear database error flags
        localStorage.removeItem('ocabis_database_error');
        localStorage.removeItem('ocabis_database_error_time');
        
        // Remove any other modals that might be showing (especially session expired modal)
        const sessionExpiredModal = document.getElementById('sessionExpiredModal');
        if (sessionExpiredModal) {
            sessionExpiredModal.remove();
        }
        const sessionInvalidatedModal = document.getElementById('sessionInvalidatedModal');
        if (sessionInvalidatedModal) {
            sessionInvalidatedModal.remove();
        }
        const databaseErrorModal = document.getElementById('databaseErrorModal');
        if (databaseErrorModal) {
            databaseErrorModal.remove();
        }
        
        // Show account locked modal with security alert (this has highest priority)
        this.showAccountLockedModal(data);
    }

    handleDatabaseError(data) {
        this.isActive = false;
        
        // Hide any other modals first
        const sessionExpiredModal = document.getElementById('sessionExpiredModal');
        const sessionInvalidatedModal = document.getElementById('sessionInvalidatedModal');
        const accountLockedModal = document.getElementById('accountLockedModal');
        if (sessionExpiredModal) sessionExpiredModal.remove();
        if (sessionInvalidatedModal) sessionInvalidatedModal.remove();
        if (accountLockedModal) accountLockedModal.remove();
        
        this.showDatabaseErrorModal(data);
    }

    showSessionExpiredModal() {
        // Don't show session expired modal if account locked modal is already showing
        const accountLockedModal = document.getElementById('accountLockedModal');
        if (accountLockedModal) {
            return; // Account locked modal has priority
        }
        
        // Disable body scroll
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        document.body.style.height = '100%';
        
        // Remove any existing modal first (except account locked modal)
        const existingModal = document.getElementById('sessionExpiredModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Create new modal with completely fresh styling
        // Lower z-index than account locked modal
        const modal = document.createElement('div');
        modal.id = 'sessionExpiredModal';
        modal.innerHTML = `
            <div style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(0,0,0,0.8);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 999999;
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            ">
                <div style="
                    background: white;
                    padding: 40px;
                    border-radius: 15px;
                    text-align: center;
                    max-width: 450px;
                    width: 90%;
                    box-shadow: 0 10px 30px rgba(156, 156, 156, 0.3);
                ">
                    <h2 style="color: #e74c3c; margin-bottom: 20px; font-size: 24px; font-weight: bold;">Session Expired</h2>
                    <p style="margin-bottom: 30px; color: #666; font-size: 16px; line-height: 1.5;">Your session has expired. Please login again.</p>
                    <button onclick="
                        localStorage.removeItem('ocabis_database_error');
                        localStorage.removeItem('ocabis_database_error_time');
                        window.location.href='login.php';
                    " style="
                        background: linear-gradient(135deg, #3498db, #2980b9);
                        color: white;
                        border: none;
                        padding: 12px 30px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-size: 16px;
                        font-weight: 600;
                    ">Login Again</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }

    showSessionInvalidatedModal() {
        // Disable body scroll
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        document.body.style.height = '100%';
        
        // Remove any existing modal first
        const existingModal = document.getElementById('sessionInvalidatedModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Create new modal with completely fresh styling
        const modal = document.createElement('div');
        modal.id = 'sessionInvalidatedModal';
        modal.innerHTML = `
            <div style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(0,0,0,0.95);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 999999;
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            ">
                <div style="
                    background: white;
                    padding: 40px;
                    border-radius: 15px;
                    text-align: center;
                    max-width: 450px;
                    width: 90%;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                ">
                    <h2 style="color: #e74c3c; margin-bottom: 20px; font-size: 24px; font-weight: bold;">Logged In From Another Device</h2>
                    <p style="margin-bottom: 30px; color: #666; font-size: 16px; line-height: 1.5;">You have been automatically logged out because you logged in from another device. Please login again to continue.</p>
                    <button onclick="
                        localStorage.removeItem('ocabis_database_error');
                        localStorage.removeItem('ocabis_database_error_time');
                        window.location.href='login.php';
                    " style="
                        background: linear-gradient(135deg, #3498db, #2980b9);
                        color: white;
                        border: none;
                        padding: 12px 30px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-size: 16px;
                        font-weight: 600;
                    ">Login Again</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }

    showDatabaseErrorModal(data) {
        // Disable body scroll
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        document.body.style.height = '100%';
        
        // Remove any existing modal first
        const existingModal = document.getElementById('databaseErrorModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Check if user is super admin
        const isSuperAdmin = document.body.getAttribute('data-user-super-admin') === 'true' || 
                            document.querySelector('[data-user-super-admin="true"]');
        
        let modalContent = '';
        
        if (isSuperAdmin) {
            // Super admin modal
            modalContent = `
                <div style="font-size: 60px; margin-bottom: 20px;">🚨</div>
                <h2 style="color: #e74c3c; margin-bottom: 20px; font-size: 24px; font-weight: bold;">Database Error Detected</h2>
                <p style="margin-bottom: 20px; color: #666; font-size: 16px; line-height: 1.5;">
                    The database appears to be corrupted or deleted. Please access the emergency recovery to restore your database using a backup file.
                </p>
                <p style="margin-bottom: 30px; color: #f59e0b; font-size: 14px; line-height: 1.5;">
                    Click the button below to access the emergency recovery page.
                </p>
                <button onclick="
                    localStorage.removeItem('ocabis_database_error');
                    localStorage.removeItem('ocabis_database_error_time');
                    window.location.href='emergency_recovery.php';
                " style="
                    background: linear-gradient(135deg, #e74c3c, #c0392b);
                    color: white;
                    border: none;
                    padding: 14px 30px;
                    border-radius: 8px;
                    cursor: pointer;
                    font-size: 16px;
                    font-weight: 600;
                    transition: transform 0.2s;
                " onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    Go to Emergency Recovery
                </button>
            `;
        } else {
            // Regular user modal
            modalContent = `
                <div style="font-size: 60px; margin-bottom: 20px;">🔧</div>
                <h2 style="color: #f59e0b; margin-bottom: 20px; font-size: 24px; font-weight: bold;">System Maintenance</h2>
                <p style="margin-bottom: 20px; color: #666; font-size: 16px; line-height: 1.5;">
                    The system is currently undergoing maintenance. Our technical team is working to resolve this issue.
                </p>
                <p style="margin-bottom: 30px; color: #3b82f6; font-size: 14px; line-height: 1.5;">
                    Please try again in a few minutes or contact your administrator.
                </p>
                <button onclick="
                    localStorage.removeItem('ocabis_database_error');
                    localStorage.removeItem('ocabis_database_error_time');
                    window.location.href='database_down.php';
                " style="
                    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
                    color: white;
                    border: none;
                    padding: 14px 30px;
                    border-radius: 8px;
                    cursor: pointer;
                    font-size: 16px;
                    font-weight: 600;
                    transition: transform 0.2s;
                " onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    Go to Maintenance Page
                </button>
            `;
        }
        
        // Create new modal with completely fresh styling
        const modal = document.createElement('div');
        modal.id = 'databaseErrorModal';
        modal.innerHTML = `
            <div style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(0,0,0,0.95);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 999999;
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            ">
                <div style="
                    background: white;
                    padding: 40px;
                    border-radius: 15px;
                    text-align: center;
                    max-width: 450px;
                    width: 90%;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                ">
                    ${modalContent}
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }

    showAccountLockedModal(data) {
        // Disable body scroll
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        document.body.style.height = '100%';
        
        // Remove any existing modals first (especially session expired)
        const existingAccountLockedModal = document.getElementById('accountLockedModal');
        if (existingAccountLockedModal) {
            existingAccountLockedModal.remove();
        }
        const sessionExpiredModal = document.getElementById('sessionExpiredModal');
        if (sessionExpiredModal) {
            sessionExpiredModal.remove();
        }
        const sessionInvalidatedModal = document.getElementById('sessionInvalidatedModal');
        if (sessionInvalidatedModal) {
            sessionInvalidatedModal.remove();
        }
        const databaseErrorModal = document.getElementById('databaseErrorModal');
        if (databaseErrorModal) {
            databaseErrorModal.remove();
        }
        
        // Create new modal with security alert - HIGHEST PRIORITY (higher z-index than session expired)
        const modal = document.createElement('div');
        modal.id = 'accountLockedModal';
        modal.innerHTML = `
            <div style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(0,0,0,0.95);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 9999999;
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            ">
                <div style="
                    background: white;
                    padding: 40px;
                    border-radius: 15px;
                    text-align: center;
                    max-width: 500px;
                    width: 90%;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                ">
                    <div style="font-size: 60px; margin-bottom: 20px;">⚠️</div>
                    <h2 style="color: #e74c3c; margin-bottom: 20px; font-size: 24px; font-weight: bold;">SECURITY ALERT</h2>
                    <p style="margin-bottom: 20px; color: #666; font-size: 16px; line-height: 1.6; font-weight: 600;">
                        Someone is trying to hack your password!
                    </p>
                    <p style="margin-bottom: 30px; color: #666; font-size: 14px; line-height: 1.5;">
                        Your account has been locked for security reasons. Please contact the administrator to unlock your account.
                    </p>
                    <button onclick="
                        localStorage.removeItem('ocabis_database_error');
                        localStorage.removeItem('ocabis_database_error_time');
                        // Redirect to login page without security_threat parameter to avoid duplicate message
                        window.location.href='login.php';
                    " style="
                        background: linear-gradient(135deg, #e74c3c, #c0392b);
                        color: white;
                        border: none;
                        padding: 12px 30px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-size: 16px;
                        font-weight: 600;
                    ">Go to Login</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
    }

    cleanup() {
        this.isActive = false;
        // Optionally notify server that user is leaving
        navigator.sendBeacon('crud.php?action=cleanup_session');
    }
}

// Initialize session monitor when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Only start monitoring if user is logged in
    if (document.body.getAttribute('data-user-logged-in') === 'true' || 
        document.querySelector('[data-user-logged-in="true"]')) {
        new SessionMonitor();
    }
});
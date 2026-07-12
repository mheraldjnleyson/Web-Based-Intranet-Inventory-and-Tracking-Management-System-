// Mobile-specific JavaScript for OCABIS
// Handles mobile optimizations and touch interactions

(function() {
    'use strict';

    // Mobile detection
    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 0;

    // Add mobile classes to body (safe if script is loaded in <head>)
    function applyBodyDeviceClasses() {
        if (!document.body) return;
        if (isMobile) {
            document.body.classList.add('mobile-device');
        }
        if (isTouch) {
            document.body.classList.add('touch-device');
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyBodyDeviceClasses);
    } else {
        applyBodyDeviceClasses();
    }

    // Mobile viewport fix for iOS
    function fixMobileViewport() {
        const viewport = document.querySelector('meta[name="viewport"]');
        if (viewport && isMobile) {
            viewport.setAttribute('content', 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no');
        }
    }

    // Mobile sidebar toggle
    function initMobileSidebar() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.createElement('div');
        overlay.className = 'mobile-sidebar-overlay';
        document.body.appendChild(overlay);

        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
                overlay.classList.toggle('show');
                document.body.classList.toggle('sidebar-open');
            });

            overlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
                document.body.classList.remove('sidebar-open');
            });
        }
    }

    // Mobile table responsive
    function initMobileTables() {
        const tables = document.querySelectorAll('table');
        tables.forEach(table => {
            if (isMobile) {
                table.classList.add('mobile-table');
                
                // Wrap table in mobile container
                const wrapper = document.createElement('div');
                wrapper.className = 'mobile-table-wrapper';
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            }
        });
    }

    // Mobile form optimizations
    function initMobileForms() {
        const inputs = document.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            if (isMobile) {
                input.classList.add('mobile-form-input');
                
                // Prevent zoom on focus for iOS
                if (input.type === 'text' || input.type === 'email' || input.type === 'password') {
                    input.addEventListener('focus', function() {
                        if (isMobile) {
                            this.style.fontSize = '16px';
                        }
                    });
                }
            }
        });
    }

    // Mobile button optimizations
    function initMobileButtons() {
        const buttons = document.querySelectorAll('button, .btn, input[type="button"], input[type="submit"]');
        buttons.forEach(button => {
            if (isMobile) {
                button.classList.add('mobile-touch-target');
                
                // Add touch feedback
                button.addEventListener('touchstart', function() {
                    this.classList.add('mobile-touch-active');
                });
                
                button.addEventListener('touchend', function() {
                    setTimeout(() => {
                        this.classList.remove('mobile-touch-active');
                    }, 150);
                });
            }
        });
    }

    // Mobile navigation
    function initMobileNavigation() {
        const nav = document.querySelector('.nav-menu');
        if (nav && isMobile) {
            nav.classList.add('mobile-nav');
            
            // Make navigation scrollable
            const navContainer = document.createElement('div');
            navContainer.className = 'mobile-nav-container';
            nav.parentNode.insertBefore(navContainer, nav);
            navContainer.appendChild(nav);
        }
    }

    // Mobile card optimizations
    function initMobileCards() {
        const cards = document.querySelectorAll('.card, .item-card, .detail-card');
        cards.forEach(card => {
            if (isMobile) {
                card.classList.add('mobile-card');
            }
        });
    }

    // Mobile alerts
    function initMobileAlerts() {
        const alerts = document.querySelectorAll('.alert, .notification');
        alerts.forEach(alert => {
            if (isMobile) {
                alert.classList.add('mobile-alert');
            }
        });
    }

    // Mobile grid system
    function initMobileGrid() {
        const grids = document.querySelectorAll('.grid, .row');
        grids.forEach(grid => {
            if (isMobile) {
                grid.classList.add('mobile-grid');
                
                // Auto-detect grid columns
                const children = grid.children;
                if (children.length <= 2) {
                    grid.classList.add('mobile-grid-2');
                } else if (children.length <= 3) {
                    grid.classList.add('mobile-grid-3');
                } else {
                    grid.classList.add('mobile-grid-1');
                }
            }
        });
    }

    // Mobile QR Scanner optimizations
    function initMobileQRScanner() {
        const qrScanner = document.querySelector('.scanner-container');
        if (qrScanner && isMobile) {
            qrScanner.classList.add('mobile-qr-scanner');
            
            // Add mobile-specific controls
            const controls = qrScanner.querySelector('.scanner-tabs');
            if (controls) {
                controls.classList.add('mobile-grid', 'mobile-grid-2');
            }
        }
    }

    // Mobile loading states
    function initMobileLoading() {
        const loadingElements = document.querySelectorAll('.loading, .spinner');
        loadingElements.forEach(element => {
            if (isMobile) {
                element.classList.add('mobile-spinner');
            }
        });
    }

    // Mobile modal optimizations
    function initMobileModals() {
        const modals = document.querySelectorAll('.modal, .popup');
        modals.forEach(modal => {
            if (isMobile) {
                modal.classList.add('mobile-modal');
                
                const content = modal.querySelector('.modal-content');
                if (content) {
                    content.classList.add('mobile-modal-content');
                }
            }
        });
    }

    // Mobile touch gestures
    function initMobileGestures() {
        if (isTouch) {
            // Swipe gestures for navigation
            let startX, startY, endX, endY;
            
            document.addEventListener('touchstart', function(e) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
            });
            
            document.addEventListener('touchend', function(e) {
                endX = e.changedTouches[0].clientX;
                endY = e.changedTouches[0].clientY;
                
                const diffX = startX - endX;
                const diffY = startY - endY;
                
                // Swipe left/right detection
                if (Math.abs(diffX) > Math.abs(diffY) && Math.abs(diffX) > 50) {
                    if (diffX > 0) {
                        // Swipe left
                        triggerSwipeLeft();
                    } else {
                        // Swipe right
                        triggerSwipeRight();
                    }
                }
            });
        }
    }

    function triggerSwipeLeft() {
        // Handle swipe left
        const event = new CustomEvent('mobileSwipeLeft');
        document.dispatchEvent(event);
    }

    function triggerSwipeRight() {
        // Handle swipe right
        const event = new CustomEvent('mobileSwipeRight');
        document.dispatchEvent(event);
    }

    // Mobile performance optimizations
    function initMobilePerformance() {
        if (isMobile) {
            // Lazy load images
            const images = document.querySelectorAll('img[data-src]');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy');
                        observer.unobserve(img);
                    }
                });
            });

            images.forEach(img => imageObserver.observe(img));

            // Debounce scroll events
            let scrollTimeout;
            window.addEventListener('scroll', function() {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(function() {
                    // Handle scroll events here
                }, 100);
            });
        }
    }

    // Mobile accessibility
    function initMobileAccessibility() {
        if (isMobile) {
            // Add ARIA labels for touch targets
            const touchTargets = document.querySelectorAll('.mobile-touch-target');
            touchTargets.forEach(target => {
                if (!target.getAttribute('aria-label')) {
                    target.setAttribute('aria-label', target.textContent || 'Touch target');
                }
            });

            // Improve focus management
            const focusableElements = document.querySelectorAll('button, input, select, textarea, a[href]');
            focusableElements.forEach(element => {
                element.classList.add('mobile-focus');
            });
        }
    }

    // Initialize all mobile features
    function initMobile() {
        fixMobileViewport();
        initMobileSidebar();
        initMobileTables();
        initMobileForms();
        initMobileButtons();
        initMobileNavigation();
        initMobileCards();
        initMobileAlerts();
        initMobileGrid();
        initMobileQRScanner();
        initMobileLoading();
        initMobileModals();
        initMobileGestures();
        initMobilePerformance();
        initMobileAccessibility();
    }

    // Run initialization when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobile);
    } else {
        initMobile();
    }

    // Export mobile utilities
    window.MobileUtils = {
        isMobile: isMobile,
        isTouch: isTouch,
        initMobile: initMobile
    };

})();

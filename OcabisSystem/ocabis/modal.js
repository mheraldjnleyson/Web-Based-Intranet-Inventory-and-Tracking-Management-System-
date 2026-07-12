/**
 * Modal Component for OCABIS
 * Replaces alert() calls with styled modal dialogs
 */

class Modal {
    constructor() {
        this.modalId = 'ocabis-modal';
        this.createModal();
    }

    createModal() {
        // Check if body exists
        if (!document.body) {
            console.error('Document body not ready, retrying...');
            setTimeout(() => this.createModal(), 100);
            return;
        }

        // Remove existing modal if it exists
        const existingModal = document.getElementById(this.modalId);
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal HTML
        const modalHTML = `
            <div id="${this.modalId}" class="ocabis-modal" style="display: none;">
                <div class="ocabis-modal-overlay"></div>
                <div class="ocabis-modal-content">
                    <div class="ocabis-modal-header">
                        <h3 class="ocabis-modal-title">Notification</h3>
                        <button class="ocabis-modal-close" onclick="modal.close()">&times;</button>
                    </div>
                    <div class="ocabis-modal-body">
                        <p class="ocabis-modal-message"></p>
                    </div>
                    <div class="ocabis-modal-footer">
                        <button class="ocabis-modal-btn ocabis-modal-btn-primary" onclick="modal.close()">OK</button>
                    </div>
                </div>
            </div>
        `;

        // Add modal HTML to body
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Add modal styles if not already added
        this.addStyles();
    }

    addStyles() {
        if (document.getElementById('ocabis-modal-styles')) return;

        const styles = `
            <style id="ocabis-modal-styles">
                .ocabis-modal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    z-index: 2147483100 !important;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .ocabis-modal-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    backdrop-filter: blur(2px);
                }

                .ocabis-modal-content {
                    position: relative;
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
                    max-width: 500px;
                    width: 90%;
                    max-height: 80vh;
                    overflow: hidden;
                    animation: modalSlideIn 0.3s ease-out;
                    z-index: 2147483101 !important;
                }

                @keyframes modalSlideIn {
                    from {
                        opacity: 0;
                        transform: scale(0.9) translateY(-20px);
                    }
                    to {
                        opacity: 1;
                        transform: scale(1) translateY(0);
                    }
                }

                .ocabis-modal-header {
                    padding: 20px 24px 16px;
                    border-bottom: 1px solid #e9ecef;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }

                .ocabis-modal-title {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 600;
                    color: #2c3e50;
                }

                .ocabis-modal-close {
                    background: none;
                    border: none;
                    font-size: 24px;
                    color: #6c757d;
                    cursor: pointer;
                    padding: 0;
                    width: 30px;
                    height: 30px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 50%;
                    transition: all 0.2s ease;
                }

                .ocabis-modal-close:hover {
                    background: #f8f9fa;
                    color: #495057;
                }

                .ocabis-modal-body {
                    padding: 20px 24px;
                }

                .ocabis-modal-message {
                    margin: 0;
                    font-size: 16px;
                    line-height: 1.5;
                    color: #495057;
                    word-wrap: break-word;
                }

                .ocabis-modal-footer {
                    padding: 16px 24px 20px;
                    border-top: 1px solid #e9ecef;
                    display: flex;
                    justify-content: flex-end;
                    gap: 12px;
                }

                .ocabis-modal-btn {
                    padding: 10px 20px;
                    border: none;
                    border-radius: 6px;
                    font-size: 14px;
                    font-weight: 500;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    min-width: 80px;
                }

                .ocabis-modal-btn-primary {
                    background: #007bff;
                    color: white;
                }

                .ocabis-modal-btn-primary:hover {
                    background: #0056b3;
                }

                .ocabis-modal-btn-secondary {
                    background: #6c757d;
                    color: white;
                }

                .ocabis-modal-btn-secondary:hover {
                    background: #545b62;
                }

                .ocabis-modal-btn-success {
                    background: #28a745;
                    color: white;
                }

                .ocabis-modal-btn-success:hover {
                    background: #1e7e34;
                }

                .ocabis-modal-btn-danger {
                    background: #dc3545;
                    color: white;
                }

                .ocabis-modal-btn-danger:hover {
                    background: #c82333;
                }

                .ocabis-modal-btn-warning {
                    background: #ffc107;
                    color: #212529;
                }

                .ocabis-modal-btn-warning:hover {
                    background: #e0a800;
                }

                /* Modal types */
                .ocabis-modal.success .ocabis-modal-title {
                    color: #28a745;
                }

                .ocabis-modal.error .ocabis-modal-title {
                    color: #dc3545;
                }

                .ocabis-modal.warning .ocabis-modal-title {
                    color: #ffc107;
                }

                .ocabis-modal.info .ocabis-modal-title {
                    color: #17a2b8;
                }

                /* Responsive */
                @media (max-width: 576px) {
                    .ocabis-modal-content {
                        width: 95%;
                        margin: 20px;
                    }
                    
                    .ocabis-modal-header,
                    .ocabis-modal-body,
                    .ocabis-modal-footer {
                        padding-left: 16px;
                        padding-right: 16px;
                    }
                }
            </style>
        `;

        document.head.insertAdjacentHTML('beforeend', styles);
    }

    show(message, type = 'info', title = null) {
        const modal = document.getElementById(this.modalId);
        const titleEl = modal.querySelector('.ocabis-modal-title');
        const messageEl = modal.querySelector('.ocabis-modal-message');
        const modalContent = modal.querySelector('.ocabis-modal-content');

        // Set content
        messageEl.textContent = message;
        
        // Set title based on type if not provided
        if (!title) {
            switch (type) {
                case 'success':
                    title = 'Success';
                    break;
                case 'error':
                    title = 'Error';
                    break;
                case 'warning':
                    title = 'Warning';
                    break;
                case 'info':
                default:
                    title = 'Notification';
                    break;
            }
        }
        titleEl.textContent = title;

        // Set modal type class
        modal.className = `ocabis-modal ${type}`;

        // Show modal
        modal.style.display = 'flex';

        // Focus on OK button for accessibility
        setTimeout(() => {
            const okBtn = modal.querySelector('.ocabis-modal-btn-primary');
            if (okBtn) okBtn.focus();
        }, 100);

        // Prevent body scroll
        document.body.style.overflow = 'hidden';
    }

    close() {
        const modal = document.getElementById(this.modalId);
        modal.style.display = 'none';
        
        // Restore body scroll
        document.body.style.overflow = '';
    }

    // Convenience methods
    success(message, title = 'Success') {
        this.show(message, 'success', title);
    }

    error(message, title = 'Error') {
        this.show(message, 'error', title);
    }

    warning(message, title = 'Warning') {
        this.show(message, 'warning', title);
    }

    info(message, title = 'Notification') {
        this.show(message, 'info', title);
    }

    // Confirmation modal - returns a Promise that resolves to true/false
    confirm(message, title = 'Confirm Action') {
        return new Promise((resolve) => {
            // Create confirmation modal HTML
            const confirmModalId = 'ocabis-confirm-modal';
            let confirmModal = document.getElementById(confirmModalId);
            
            if (!confirmModal) {
                const confirmModalHTML = `
                    <div id="${confirmModalId}" class="ocabis-modal" style="display: none; z-index: 2147483100 !important;">
                        <div class="ocabis-modal-overlay"></div>
                        <div class="ocabis-modal-content" style="z-index: 2147483101 !important;">
                            <div class="ocabis-modal-header">
                                <h3 class="ocabis-modal-title"></h3>
                                <button class="ocabis-modal-close" onclick="modal.closeConfirm()">&times;</button>
                            </div>
                            <div class="ocabis-modal-body">
                                <p class="ocabis-modal-message"></p>
                            </div>
                            <div class="ocabis-modal-footer">
                                <button class="ocabis-modal-btn ocabis-modal-btn-secondary" onclick="modal.closeConfirm()">Cancel</button>
                                <button class="ocabis-modal-btn ocabis-modal-btn-primary" id="ocabis-confirm-btn">Confirm</button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.insertAdjacentHTML('beforeend', confirmModalHTML);
                confirmModal = document.getElementById(confirmModalId);
            }

            // Set content
            const messageEl = confirmModal.querySelector('.ocabis-modal-message');
            const titleEl = confirmModal.querySelector('.ocabis-modal-title');
            messageEl.textContent = message;
            titleEl.textContent = title;

            // Set modal type
            confirmModal.className = 'ocabis-modal warning';

            // Store resolve function
            let resolveFn = resolve;

            // Remove existing event listeners
            const confirmBtn = confirmModal.querySelector('#ocabis-confirm-btn');
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

            // Get cancel button
            const cancelBtn = confirmModal.querySelector('.ocabis-modal-btn-secondary');
            const newCancelBtn = cancelBtn.cloneNode(true);
            cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

            // Add event listeners
            newConfirmBtn.onclick = () => {
                this.closeConfirm();
                resolveFn(true);
            };

            newCancelBtn.onclick = () => {
                this.closeConfirm();
                resolveFn(false);
            };

            // Handle close button
            const closeBtn = confirmModal.querySelector('.ocabis-modal-close');
            if (closeBtn) {
                closeBtn.onclick = () => {
                    this.closeConfirm();
                    resolveFn(false);
                };
            }

            // Show modal
            confirmModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Focus on confirm button
            setTimeout(() => {
                newConfirmBtn.focus();
            }, 100);
        });
    }

    closeConfirm() {
        const confirmModal = document.getElementById('ocabis-confirm-modal');
        if (confirmModal) {
            confirmModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    // Prompt modal - returns a Promise that resolves to the input value or null
    prompt(message, title = 'Enter Information', defaultValue = '') {
        return new Promise((resolve) => {
            // Create prompt modal HTML
            const promptModalId = 'ocabis-prompt-modal';
            let promptModal = document.getElementById(promptModalId);
            
            if (!promptModal) {
                const promptModalHTML = `
                    <div id="${promptModalId}" class="ocabis-modal" style="display: none; z-index: 2147483100 !important;">
                        <div class="ocabis-modal-overlay"></div>
                        <div class="ocabis-modal-content" style="z-index: 2147483101 !important;">
                            <div class="ocabis-modal-header">
                                <h3 class="ocabis-modal-title"></h3>
                                <button class="ocabis-modal-close" onclick="modal.closePrompt()">&times;</button>
                            </div>
                            <div class="ocabis-modal-body">
                                <p class="ocabis-modal-message" style="margin-bottom: 12px;"></p>
                                <input type="text" class="ocabis-modal-input" id="ocabis-prompt-input" placeholder="Enter text here..." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; box-sizing: border-box;">
                            </div>
                            <div class="ocabis-modal-footer">
                                <button class="ocabis-modal-btn ocabis-modal-btn-secondary" onclick="modal.closePrompt()">Cancel</button>
                                <button class="ocabis-modal-btn ocabis-modal-btn-primary" id="ocabis-prompt-btn">OK</button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.insertAdjacentHTML('beforeend', promptModalHTML);
                promptModal = document.getElementById(promptModalId);
            }

            // Set content
            const messageEl = promptModal.querySelector('.ocabis-modal-message');
            const titleEl = promptModal.querySelector('.ocabis-modal-title');
            const inputEl = promptModal.querySelector('#ocabis-prompt-input');
            messageEl.textContent = message;
            titleEl.textContent = title;
            inputEl.value = defaultValue;

            // Set modal type
            promptModal.className = 'ocabis-modal info';

            // Store resolve function for cancel
            let resolveFn = resolve;

            // Remove existing event listeners
            const promptBtn = promptModal.querySelector('#ocabis-prompt-btn');
            const newPromptBtn = promptBtn.cloneNode(true);
            promptBtn.parentNode.replaceChild(newPromptBtn, promptBtn);

            // Get cancel button
            const cancelBtn = promptModal.querySelector('.ocabis-modal-btn-secondary');
            const newCancelBtn = cancelBtn.cloneNode(true);
            cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

            // Add event listeners
            newPromptBtn.onclick = () => {
                const value = inputEl.value;
                this.closePrompt();
                resolveFn(value);
            };

            newCancelBtn.onclick = () => {
                this.closePrompt();
                resolveFn(null);
            };

            // Handle close button
            const closeBtn = promptModal.querySelector('.ocabis-modal-close');
            if (closeBtn) {
                closeBtn.onclick = () => {
                    this.closePrompt();
                    resolveFn(null);
                };
            }

            // Handle Enter key
            inputEl.onkeydown = (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    newPromptBtn.click();
                }
            };

            // Show modal
            promptModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            // Focus on input
            setTimeout(() => {
                inputEl.focus();
                inputEl.select();
            }, 100);
        });
    }

    closePrompt() {
        const promptModal = document.getElementById('ocabis-prompt-modal');
        if (promptModal) {
            promptModal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }
}

// Create global modal instance
let modal;

// Wait for DOM to be ready before creating modal
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        modal = new Modal();
    });
} else {
    modal = new Modal();
}

// Removed auto-close functionality - modals with OK button must be closed by clicking OK
// This ensures users can read the message and intentionally close it
// Modal can only be closed by:
// 1. Clicking the OK button
// 2. Clicking the X close button in the header

// Export for use in other scripts
window.Modal = Modal;
window.modal = modal;

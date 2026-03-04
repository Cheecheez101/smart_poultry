            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="mt-auto py-3" style="background-color: var(--secondary-color); color: white; margin-left: var(--sidebar-width); transition: margin-left 0.3s;">
        <div class="container-fluid px-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <small>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved.</small>
                </div>
                <div class="col-md-6 text-end">
                    <small>Version <?php echo APP_VERSION; ?> | Powered by SmartPoultry</small>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Global App Script -->
    <script src="<?php echo APP_URL; ?>assets/js/script.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Initialize popovers
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        const popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
        
        // Sidebar toggle functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const footer = document.querySelector('footer');
            const isMobile = window.innerWidth <= 768;

            if (isMobile) {
                sidebar.classList.toggle('mobile-open');
                if (!sidebar.classList.contains('collapsed')) {
                    sidebar.classList.add('collapsed');
                }
                footer.style.marginLeft = '70px';
                return;
            }

            sidebar.classList.toggle('collapsed');

            // Adjust footer margin based on sidebar state
            footer.style.marginLeft = sidebar.classList.contains('collapsed') ? '70px' : 'var(--sidebar-width)';
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                try {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                } catch (e) {
                    // Alert might already be closed
                }
            });
        }, 5000);
        
        // Mobile responsive sidebar
        function checkMobileView() {
            const sidebar = document.getElementById('sidebar');
            const footer = document.querySelector('footer');
            
            if (window.innerWidth <= 768) {
                sidebar.classList.add('collapsed');
                sidebar.classList.remove('mobile-open');
                footer.style.marginLeft = '70px';
            } else {
                footer.style.marginLeft = sidebar.classList.contains('collapsed') ? '70px' : 'var(--sidebar-width)';
            }
        }
        
        // Check on load and resize
        window.addEventListener('load', checkMobileView);
        window.addEventListener('resize', checkMobileView);
        
        // Add loading spinner function
        function showLoadingSpinner(element) {
            const spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm me-2';
            element.prepend(spinner);
            element.disabled = true;
        }
        
        function hideLoadingSpinner(element) {
            const spinner = element.querySelector('.spinner-border');
            if (spinner) {
                spinner.remove();
            }
            element.disabled = false;
        }
        
        // Form validation helper
        function validateForm(formId) {
            const form = document.getElementById(formId);
            if (!form) return false;
            
            let isValid = true;
            const requiredFields = form.querySelectorAll('[required]');
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                    field.classList.add('is-valid');
                }
            });
            
            return isValid;
        }
        
        // Number formatting functions
        function formatCurrency(amount, currency = 'KSH') {
            return currency + ' ' + parseFloat(amount).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        
        function formatNumber(number) {
            return parseFloat(number).toLocaleString('en-US');
        }
        
        // Date formatting
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        // Confirmation dialog
        function confirmAction(message, callback) {
            if (confirm(message)) {
                callback();
            }
        }
        
        // Show/hide loading overlay
        function showLoadingOverlay() {
            const overlay = document.createElement('div');
            overlay.id = 'loadingOverlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 9999;
            `;
            overlay.innerHTML = '<div class="spinner-border text-light" style="width: 3rem; height: 3rem;"></div>';
            document.body.appendChild(overlay);
        }
        
        function hideLoadingOverlay() {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.remove();
            }
        }
        
        // AJAX helper function
        function makeAjaxRequest(url, method, data, callback) {
            showLoadingOverlay();
            
            const xhr = new XMLHttpRequest();
            xhr.open(method, url, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    hideLoadingOverlay();
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            callback(response);
                        } catch (e) {
                            callback({ success: false, message: 'Invalid response from server' });
                        }
                    } else {
                        callback({ success: false, message: 'Server error occurred' });
                    }
                }
            };
            
            if (method.toLowerCase() === 'post') {
                let formData = '';
                if (typeof data === 'object') {
                    const params = new URLSearchParams();
                    for (const key in data) {
                        params.append(key, data[key]);
                    }
                    formData = params.toString();
                } else {
                    formData = data;
                }
                xhr.send(formData);
            } else {
                xhr.send();
            }
        }
        
        // Show toast notification
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 1050; min-width: 300px;';
            toast.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(function() {
                if (toast.parentNode) {
                    const bsAlert = new bootstrap.Alert(toast);
                    bsAlert.close();
                }
            }, 5000);
        }
        
        // Initialize any page-specific functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handlers for any delete buttons
            document.querySelectorAll('.btn-delete').forEach(function(button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const message = this.dataset.message || 'Are you sure you want to delete this item?';
                    confirmAction(message, () => {
                        if (this.href) {
                            window.location.href = this.href;
                        } else if (this.dataset.form) {
                            document.getElementById(this.dataset.form).submit();
                        }
                    });
                });
            });
            
            // Add form validation on submit
            document.querySelectorAll('form[data-validate="true"]').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    if (!validateForm(this.id)) {
                        e.preventDefault();
                        showToast('Please fill in all required fields', 'danger');
                    }
                });
            });
        });
    </script>
    
    <!-- Page-specific scripts -->
    <?php if (isset($page_scripts)): ?>
        <?php foreach ($page_scripts as $script): ?>
            <script src="<?php echo $script; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (isset($inline_scripts)): ?>
        <script>
            <?php echo $inline_scripts; ?>
        </script>
    <?php endif; ?>

</body>
</html>
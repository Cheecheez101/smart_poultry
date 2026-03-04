/**
 * SmartPoultry Management System - Custom JavaScript
 */

// Global variables
const SmartPoultry = {
    // Configuration
    config: {
        dateFormat: 'MM/DD/YYYY',
        currency: 'USD',
        currencySymbol: '$'
    },

    // Utility functions
    utils: {
        // Format number with commas
        formatNumber: function(number) {
            return new Intl.NumberFormat().format(number);
        },

        // Format currency
        formatCurrency: function(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: SmartPoultry.config.currency
            }).format(amount);
        },

        // Format date
        formatDate: function(date, format = 'MM/DD/YYYY') {
            if (!date) return '-';
            const d = new Date(date);
            return d.toLocaleDateString();
        },

        // Show loading spinner
        showLoading: function(element) {
            element.innerHTML = '<span class="loading"></span> Loading...';
        },

        // Show alert message
        showAlert: function(message, type = 'info', container = 'body') {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            
            if (container === 'body') {
                const alertContainer = document.createElement('div');
                alertContainer.innerHTML = alertHtml;
                document.body.insertBefore(alertContainer.firstElementChild, document.body.firstChild);
            } else {
                document.querySelector(container).insertAdjacentHTML('afterbegin', alertHtml);
            }

            // Auto-hide after 5 seconds
            setTimeout(() => {
                const alert = document.querySelector('.alert');
                if (alert) {
                    alert.remove();
                }
            }, 5000);
        },

        // Confirm action
        confirmAction: function(message, callback) {
            if (confirm(message)) {
                callback();
            }
        },

        // AJAX helper
        ajax: function(url, options = {}) {
            const defaultOptions = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };

            const finalOptions = { ...defaultOptions, ...options };

            return fetch(url, finalOptions)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .catch(error => {
                    console.error('AJAX Error:', error);
                    SmartPoultry.utils.showAlert('An error occurred. Please try again.', 'danger');
                    throw error;
                });
        }
    },

    // Form validation
    validation: {
        // Validate email
        isValidEmail: function(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },

        // Validate phone number
        isValidPhone: function(phone) {
            const phoneRegex = /^[\+]?[1-9][\d]{0,15}$/;
            return phoneRegex.test(phone.replace(/\s/g, ''));
        },

        // Validate required fields
        validateRequired: function(form) {
            let isValid = true;
            const requiredFields = form.querySelectorAll('[required]');

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });

            return isValid;
        },

        // Validate numeric input
        isNumeric: function(value) {
            return !isNaN(parseFloat(value)) && isFinite(value);
        }
    },

    // Chart helpers
    charts: {
        // Default chart colors
        colors: {
            primary: '#4e73df',
            success: '#1cc88a',
            info: '#36b9cc',
            warning: '#f6c23e',
            danger: '#e74a3b',
            secondary: '#858796'
        },

        // Create line chart
        createLineChart: function(ctx, data, options = {}) {
            const defaultOptions = {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            };

            return new Chart(ctx, {
                type: 'line',
                data: data,
                options: { ...defaultOptions, ...options }
            });
        },

        // Create pie chart
        createPieChart: function(ctx, data, options = {}) {
            const defaultOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            };

            return new Chart(ctx, {
                type: 'doughnut',
                data: data,
                options: { ...defaultOptions, ...options }
            });
        }
    },

    // Data table helpers
    tables: {
        // Initialize sortable table
        initSortableTable: function(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;

            const headers = table.querySelectorAll('th[data-sort]');
            
            headers.forEach(header => {
                header.style.cursor = 'pointer';
                header.innerHTML += ' <i class="fas fa-sort"></i>';
                
                header.addEventListener('click', () => {
                    this.sortTable(table, header.dataset.sort);
                });
            });
        },

        // Sort table by column
        sortTable: function(table, column) {
            // Simple table sorting implementation
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            rows.sort((a, b) => {
                const aText = a.cells[column].textContent.trim();
                const bText = b.cells[column].textContent.trim();
                
                if (SmartPoultry.validation.isNumeric(aText) && SmartPoultry.validation.isNumeric(bText)) {
                    return parseFloat(aText) - parseFloat(bText);
                }
                
                return aText.localeCompare(bText);
            });

            rows.forEach(row => tbody.appendChild(row));
        },

        // Filter table
        filterTable: function(tableId, filterValue) {
            const table = document.getElementById(tableId);
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filterValue.toLowerCase()) ? '' : 'none';
            });
        }
    }
};

// Document ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize popovers
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function(popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Auto-hide alerts
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);

    // Form validation
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });

    // Numeric input formatting
    const numericInputs = document.querySelectorAll('input[data-type="currency"]');
    numericInputs.forEach(input => {
        input.addEventListener('input', function() {
            let value = this.value.replace(/[^\d.]/g, '');
            if (value) {
                this.value = parseFloat(value).toFixed(2);
            }
        });
    });

    // Date picker initialization (if needed)
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(input => {
        if (!input.value) {
            input.value = new Date().toISOString().split('T')[0];
        }
    });

    // Search functionality
    const searchInput = document.getElementById('globalSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const searchableElements = document.querySelectorAll('[data-searchable]');
            
            searchableElements.forEach(element => {
                const text = element.textContent.toLowerCase();
                const parent = element.closest('tr') || element.closest('.card') || element;
                parent.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    }

    // Auto-refresh notifications (every 5 minutes)
    if (document.querySelector('.notifications-container')) {
        setInterval(() => {
            fetch('/api/notifications')
                .then(response => response.json())
                .then(data => {
                    // Update notifications UI
                    console.log('Notifications updated');
                })
                .catch(error => console.error('Failed to update notifications:', error));
        }, 300000); // 5 minutes
    }

    // Sidebar submenu toggle
    const submenuItems = document.querySelectorAll('.nav-item.has-submenu');
    submenuItems.forEach(item => {
        const toggle = item.querySelector('.submenu-toggle');
        const submenu = item.querySelector('.nav-submenu');
        if (!toggle || !submenu) return;

        toggle.addEventListener('click', function(event) {
            event.preventDefault();
            const isOpen = item.classList.contains('open');

            submenuItems.forEach(otherItem => {
                if (otherItem !== item) {
                    otherItem.classList.remove('open');
                    const otherToggle = otherItem.querySelector('.submenu-toggle');
                    if (otherToggle) {
                        otherToggle.setAttribute('aria-expanded', 'false');
                    }
                }
            });

            item.classList.toggle('open', !isOpen);
            toggle.setAttribute('aria-expanded', (!isOpen).toString());
        });
    });

    const topLevelLinks = document.querySelectorAll('.nav-item:not(.has-submenu) > .nav-link');
    topLevelLinks.forEach(link => {
        link.addEventListener('click', function() {
            submenuItems.forEach(item => {
                item.classList.remove('open');
                const toggle = item.querySelector('.submenu-toggle');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        });
    });

    // Confirmation dialogs
    const confirmButtons = document.querySelectorAll('[data-confirm]');
    confirmButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            const message = this.dataset.confirm || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });

    // Print functionality
    const printButtons = document.querySelectorAll('.btn-print');
    printButtons.forEach(button => {
        button.addEventListener('click', function() {
            window.print();
        });
    });

    // Export functionality
    const exportButtons = document.querySelectorAll('.btn-export');
    exportButtons.forEach(button => {
        button.addEventListener('click', function() {
            const format = this.dataset.format || 'csv';
            const table = document.querySelector('table');
            
            if (table && format === 'csv') {
                SmartPoultry.utils.exportTableToCSV(table, 'export.csv');
            }
        });
    });
});

// Export table to CSV
SmartPoultry.utils.exportTableToCSV = function(table, filename) {
    const csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        cols.forEach(col => rowData.push(col.textContent));
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
};

// Error handling
window.addEventListener('error', function(e) {
    console.error('JavaScript Error:', e.error);
    // Could send error to logging service
});

// Online/offline status
window.addEventListener('online', function() {
    SmartPoultry.utils.showAlert('Connection restored', 'success');
});

window.addEventListener('offline', function() {
    SmartPoultry.utils.showAlert('Connection lost. Some features may not work.', 'warning');
});
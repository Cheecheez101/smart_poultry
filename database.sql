-- SmartPoultry Management System Database Schema
CREATE DATABASE IF NOT EXISTS smart_poultry;
USE smart_poultry;

-- Users table for authentication
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('admin', 'manager', 'worker') DEFAULT 'worker',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    status ENUM('active', 'inactive') DEFAULT 'active'
);

-- Per-user preferences
CREATE TABLE user_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_setting (user_id, setting_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Flocks table
CREATE TABLE flocks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    batch_number VARCHAR(20) UNIQUE NOT NULL,
    breed VARCHAR(50) NOT NULL,
    purpose ENUM('egg_production', 'meat_production', 'dual_purpose') DEFAULT 'egg_production',
    age_days INT NOT NULL,
    location VARCHAR(100),
    initial_count INT NOT NULL,
    current_count INT NOT NULL,
    arrival_date DATE NOT NULL,
    expected_slaughter_date DATE,
    status ENUM('active', 'inactive', 'sold', 'slaughtered') DEFAULT 'active',
    notes TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Egg production table
CREATE TABLE egg_production (
    id INT PRIMARY KEY AUTO_INCREMENT,
    flock_id INT NOT NULL,
    production_date DATE NOT NULL,
    eggs_collected INT NOT NULL,
    eggs_broken INT DEFAULT 0,
    eggs_sold INT DEFAULT 0,
    eggs_stored INT DEFAULT 0,
    average_weight DECIMAL(5,2),
    notes TEXT,
    recorded_by INT,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Feed inventory table
CREATE TABLE feed_inventory (
    id INT PRIMARY KEY AUTO_INCREMENT,
    feed_type VARCHAR(50) NOT NULL,
    supplier_id INT,
    quantity DECIMAL(10,2) NOT NULL, -- in kg
    unit_price DECIMAL(10,2),
    expiry_date DATE,
    reorder_level DECIMAL(10,2) DEFAULT 50,
    last_delivery_date DATE,
    storage_location VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (supplier_id)
);

-- Feed consumption log
CREATE TABLE feed_consumption (
    id INT PRIMARY KEY AUTO_INCREMENT,
    flock_id INT NOT NULL,
    feed_id INT NOT NULL,
    consumption_date DATE NOT NULL,
    quantity_kg DECIMAL(10,2) NOT NULL,
    feeding_time TIME,
    recorded_by INT,
    notes TEXT,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE,
    FOREIGN KEY (feed_id) REFERENCES feed_inventory(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Medication/Vaccination table
CREATE TABLE medications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    flock_id INT NOT NULL,
    medication_name VARCHAR(100) NOT NULL,
    medication_type ENUM('vaccine', 'antibiotic', 'vitamin', 'other') NOT NULL,
    administration_date DATE NOT NULL,
    next_due_date DATE,
    dosage VARCHAR(50),
    administered_by VARCHAR(100),
    cost DECIMAL(10,2),
    notes TEXT,
    reminder_sent BOOLEAN DEFAULT FALSE,
    recorded_by INT,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (flock_id) REFERENCES flocks(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Suppliers table
CREATE TABLE suppliers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    supplier_name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    supply_type ENUM('feed', 'medication', 'equipment', 'other') NOT NULL,
    payment_terms VARCHAR(100),
    account_balance DECIMAL(10,2) DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add FK after both tables exist
ALTER TABLE feed_inventory
    ADD CONSTRAINT fk_feed_inventory_supplier
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;

-- Customers table
CREATE TABLE customers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    customer_type ENUM('wholesaler', 'retailer', 'individual', 'other'),
    registration_date DATE DEFAULT (CURRENT_DATE),
    total_purchases DECIMAL(10,2) DEFAULT 0,
    last_purchase_date DATE,
    notes TEXT
);

-- Sales table
CREATE TABLE sales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_number VARCHAR(20) UNIQUE NOT NULL,
    customer_id INT,
    sale_date DATE NOT NULL,
    product_type ENUM('eggs', 'chicken', 'feed', 'other') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'mpesa', 'bank', 'credit') DEFAULT 'cash',
    payment_status ENUM('paid', 'pending', 'partial') DEFAULT 'pending',
    notes TEXT,
    sold_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (sold_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Sales items (for detailed invoices)
CREATE TABLE sale_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sale_id INT NOT NULL,
    item_description VARCHAR(200) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
);

-- Alerts/Notifications table
CREATE TABLE alerts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alert_type ENUM('reorder', 'vaccination', 'health', 'system') NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    related_id INT, -- e.g., flock_id or feed_id
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    status ENUM('unread', 'read', 'dismissed') DEFAULT 'unread',
    created_for INT, -- user_id
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATE,
    FOREIGN KEY (created_for) REFERENCES users(id) ON DELETE CASCADE
);

-- System logs
CREATE TABLE system_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    log_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Sample data

-- Insert default users (password: admin123)
-- Hash generated via PHP password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO users (username, full_name, email, phone, role, password, status) VALUES
('admin', 'System Administrator', 'admin@smartpoultry.com', '+254700000000', 'admin', '$2y$10$1.jgXLCw.1I/zco90oqTiOy9Nh9sjJTckrMwxyWGn1UmAqf7n5y5W', 'active'),
('manager', 'Farm Manager', 'manager@smartpoultry.com', '+254700000001', 'manager', '$2y$10$1.jgXLCw.1I/zco90oqTiOy9Nh9sjJTckrMwxyWGn1UmAqf7n5y5W', 'active'),
('worker', 'Farm Worker', 'worker@smartpoultry.com', '+254700000002', 'worker', '$2y$10$1.jgXLCw.1I/zco90oqTiOy9Nh9sjJTckrMwxyWGn1UmAqf7n5y5W', 'active');

-- Sample suppliers
INSERT INTO suppliers (supplier_name, contact_person, phone, email, supply_type, status) VALUES
('Kenya Feed Company', 'John Mwangi', '+254712345678', 'info@kenyafeed.co.ke', 'feed', 'active'),
('Vet Supplies Ltd', 'Dr. Sarah Wanjiku', '+254723456789', 'orders@vetsupplies.co.ke', 'medication', 'active');

-- Sample customers
INSERT INTO customers (customer_name, phone, email, customer_type) VALUES
('Naivas Supermarket', '+254734567890', 'procurement@naivas.co.ke', 'wholesaler'),
('Local Market Vendor', '+254745678901', 'vendor@localmarket.com', 'retailer');

-- Sample flock
INSERT INTO flocks (batch_number, breed, purpose, age_days, location, initial_count, current_count, arrival_date, status, created_by) VALUES
('KIK001', 'Kienyeji', 'egg_production', 45, 'House A1', 500, 485, '2024-01-15', 'active', 1);

-- Sample feed inventory
INSERT INTO feed_inventory (feed_type, supplier_id, quantity, unit_price, reorder_level, storage_location) VALUES
('Layers Mash', 1, 1500.00, 75.00, 200.00, 'Store Room 1'),
('Chick Mash', 1, 800.00, 80.00, 150.00, 'Store Room 1');

-- Sample production data
INSERT INTO egg_production (flock_id, production_date, eggs_collected, eggs_broken, eggs_sold, recorded_by) VALUES
(1, '2024-01-20', 320, 5, 300, 2),
(1, '2024-01-21', 315, 3, 310, 2);

SET FOREIGN_KEY_CHECKS = 1;
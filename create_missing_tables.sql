SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS suppliers (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feed_inventory (
    id INT PRIMARY KEY AUTO_INCREMENT,
    feed_type VARCHAR(50) NOT NULL,
    supplier_id INT,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2),
    expiry_date DATE,
    reorder_level DECIMAL(10,2) DEFAULT 50,
    last_delivery_date DATE,
    storage_location VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feed_consumption (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medications (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sale_id INT NOT NULL,
    item_description VARCHAR(200) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alerts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alert_type ENUM('reorder', 'vaccination', 'health', 'system') NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    related_id INT,
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    status ENUM('unread', 'read', 'dismissed') DEFAULT 'unread',
    created_for INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATE,
    FOREIGN KEY (created_for) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    log_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Sample data for newly created tables
INSERT INTO suppliers (supplier_name, contact_person, phone, email, supply_type, status)
SELECT 'Kenya Feed Company', 'John Mwangi', '+254712345678', 'info@kenyafeed.co.ke', 'feed', 'active'
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE supplier_name = 'Kenya Feed Company');

INSERT INTO suppliers (supplier_name, contact_person, phone, email, supply_type, status)
SELECT 'Vet Supplies Ltd', 'Dr. Sarah Wanjiku', '+254723456789', 'orders@vetsupplies.co.ke', 'medication', 'active'
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE supplier_name = 'Vet Supplies Ltd');

INSERT INTO customers (customer_name, phone, email, customer_type)
SELECT 'Naivas Supermarket', '+254734567890', 'procurement@naivas.co.ke', 'wholesaler'
WHERE NOT EXISTS (SELECT 1 FROM customers WHERE customer_name = 'Naivas Supermarket');

INSERT INTO customers (customer_name, phone, email, customer_type)
SELECT 'Local Market Vendor', '+254745678901', 'vendor@localmarket.com', 'retailer'
WHERE NOT EXISTS (SELECT 1 FROM customers WHERE customer_name = 'Local Market Vendor');

INSERT INTO feed_inventory (feed_type, supplier_id, quantity, unit_price, reorder_level, storage_location)
SELECT 'Layers Mash', (SELECT id FROM suppliers WHERE supplier_name = 'Kenya Feed Company' LIMIT 1), 1500.00, 75.00, 200.00, 'Store Room 1'
WHERE NOT EXISTS (SELECT 1 FROM feed_inventory WHERE feed_type = 'Layers Mash');

INSERT INTO feed_inventory (feed_type, supplier_id, quantity, unit_price, reorder_level, storage_location)
SELECT 'Chick Mash', (SELECT id FROM suppliers WHERE supplier_name = 'Kenya Feed Company' LIMIT 1), 800.00, 80.00, 150.00, 'Store Room 1'
WHERE NOT EXISTS (SELECT 1 FROM feed_inventory WHERE feed_type = 'Chick Mash');

SET FOREIGN_KEY_CHECKS=1;

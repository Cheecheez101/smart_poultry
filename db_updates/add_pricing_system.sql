-- Add pricing table for eggs and products
CREATE TABLE IF NOT EXISTS product_pricing (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_type ENUM('egg_single', 'egg_tray', 'chicken_live', 'chicken_dressed') NOT NULL,
    customer_type ENUM('wholesaler', 'retailer', 'individual', 'other') NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    effective_date DATE NOT NULL DEFAULT (CURRENT_DATE),
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_customer (product_type, customer_type),
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default pricing
INSERT INTO product_pricing (product_type, customer_type, price, updated_by) VALUES
('egg_single', 'wholesaler', 15.00, 1),
('egg_single', 'retailer', 18.00, 1),
('egg_single', 'individual', 20.00, 1),
('egg_single', 'other', 20.00, 1),
('egg_tray', 'wholesaler', 400.00, 1),
('egg_tray', 'retailer', 480.00, 1),
('egg_tray', 'individual', 550.00, 1),
('egg_tray', 'other', 550.00, 1),
('chicken_live', 'wholesaler', 800.00, 1),
('chicken_live', 'retailer', 900.00, 1),
('chicken_live', 'individual', 1000.00, 1),
('chicken_live', 'other', 1000.00, 1),
('chicken_dressed', 'wholesaler', 1200.00, 1),
('chicken_dressed', 'retailer', 1350.00, 1),
('chicken_dressed', 'individual', 1500.00, 1),
('chicken_dressed', 'other', 1500.00, 1)
ON DUPLICATE KEY UPDATE price = VALUES(price);

-- Modify sales table to track egg inventory deduction
ALTER TABLE sales 
ADD COLUMN eggs_from_storage INT DEFAULT 0 AFTER quantity,
ADD COLUMN inventory_updated BOOLEAN DEFAULT FALSE AFTER payment_status;

-- Add index for faster inventory queries
CREATE INDEX idx_production_date ON egg_production(production_date);
CREATE INDEX idx_eggs_stored ON egg_production(eggs_stored);

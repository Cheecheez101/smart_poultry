<?php
require_once 'includes/config.php';

try {
    // Insert sample users
    $pdo->exec("INSERT INTO users (username, full_name, email, phone, role, password, status) VALUES
('admin', 'System Administrator', 'admin@smartpoultry.com', '+254700000000', 'admin', '$2y$10$1.jgXLCw.1I/zco90oqTiOy9Nh9sjJTckrMwxyWGn1UmAqf7n5y5W', 'active'),
('manager', 'Farm Manager', 'manager@smartpoultry.com', '+254700000001', 'manager', '$2y$10$1.jgXLCw.1I/zco90oqTiOy9Nh9sjJTckrMwxyWGn1UmAqf7n5y5W', 'active'),
('worker', 'Farm Worker', 'worker@smartpoultry.com', '+254700000002', 'worker', '$2y$10$1.jgXLCw.1I/zco90oqTiOy9Nh9sjJTckrMwxyWGn1UmAqf7n5y5W', 'active')");

    // Insert suppliers
    $pdo->exec("INSERT INTO suppliers (supplier_name, contact_person, phone, email, supply_type, status) VALUES
('Kenya Feed Company', 'John Mwangi', '+254712345678', 'info@kenyafeed.co.ke', 'feed', 'active'),
('Vet Supplies Ltd', 'Dr. Sarah Wanjiku', '+254723456789', 'orders@vetsupplies.co.ke', 'medication', 'active')");

    // Insert customers
    $pdo->exec("INSERT INTO customers (customer_name, phone, email, customer_type) VALUES
('Naivas Supermarket', '+254734567890', 'procurement@naivas.co.ke', 'wholesaler'),
('Local Market Vendor', '+254745678901', 'vendor@localmarket.com', 'retailer')");

    // Insert flock
    $pdo->exec("INSERT INTO flocks (batch_number, breed, purpose, age_days, location, initial_count, current_count, arrival_date, status, created_by) VALUES
('KIK001', 'Kienyeji', 'egg_production', 45, 'House A1', 500, 485, '2024-01-15', 'active', 1)");

    // Insert feed
    $pdo->exec("INSERT INTO feed_inventory (feed_type, supplier_id, quantity, unit_price, reorder_level, storage_location) VALUES
('Layers Mash', 1, 1500.00, 75.00, 200.00, 'Store Room 1'),
('Chick Mash', 1, 800.00, 80.00, 150.00, 'Store Room 1')");

    // Insert production
    $pdo->exec("INSERT INTO egg_production (flock_id, production_date, eggs_collected, eggs_broken, eggs_sold, recorded_by) VALUES
(1, '2024-01-20', 320, 5, 300, 2),
(1, '2024-01-21', 315, 3, 310, 2)");

    echo "Sample data inserted successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
# Sales & Pricing System Setup Guide

## Overview
This guide will help you complete the setup of the new sales and pricing management system that includes:
- Customer-type-based pricing
- Automatic inventory deduction (FIFO)
- Admin pricing management interface
- Separation of production tracking from sales

## Step 1: Execute Database Migration

You need to create the `product_pricing` table. Choose one of the following methods:

### Method A: Using phpMyAdmin
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Select the `smart_poultry` database
3. Click on the "SQL" tab
4. Copy the contents of `db_updates/add_pricing_system.sql`
5. Paste into the SQL query box
6. Click "Go" to execute

### Method B: Using MySQL Command Line
1. Open Command Prompt
2. Navigate to the project directory:
   ```
   cd C:\xampp\htdocs\smart_poultry
   ```
3. Run the SQL file:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root smart_poultry < db_updates\add_pricing_system.sql
   ```

## Step 2: Set Up Initial Prices

1. Log in as an admin user
2. Navigate to **Admin** → **Pricing Management** (new menu item)
3. Enter prices for each product type and customer type:
   - **Product Types:**
     - Single Egg
     - Egg Tray (30 eggs)
     - Live Chicken
     - Dressed Chicken
   
   - **Customer Types:**
     - Wholesaler (bulk buyers)
     - Retailer (resellers)
     - Individual (direct consumers)
     - Other

4. Click **Update All Prices**

**Pricing Tips:**
- The system will auto-calculate: `Single Egg Price = Tray Price ÷ 30`
- Wholesalers typically get the lowest prices
- Individuals typically pay the highest prices
- Update effective date when changing prices for record-keeping

## Step 3: Test the System

### A. Test Egg Production (Updated)
1. Navigate to **Production** → **Egg Production**
2. Add a new production record:
   - Select a flock
   - Enter eggs collected
   - Enter eggs broken (if any)
   - **Note:** Eggs sold field has been removed
   - Eggs stored = Eggs collected - Eggs broken

### B. Test Sales Module (New)
1. Navigate to **Sales Management** (now using sales_new.php)
2. Create a new sale:
   - Select a customer (customer type determines pricing)
   - Select product type (eggs, chicken, etc.)
   - For eggs:
     - Choose "Single Eggs" or "Trays"
     - Enter quantity
     - System shows available eggs in storage
     - Pricing auto-loads based on customer type
   - Enter payment details
   - Click **Record Sale**

3. Verify:
   - Sale recorded successfully
   - Inventory automatically deducted (check egg_production table)
   - FIFO logic applied (oldest production records deducted first)

## How the System Works

### Customer Type Pricing
```
Customer Table → customer_type field
    ↓
Product Pricing Table → price lookup
    ↓
Sales Form → auto-fills price based on customer + product
```

### Inventory Deduction (FIFO)
```
Sale Created (100 eggs)
    ↓
System queries egg_production table
ORDER BY production_date ASC (oldest first)
    ↓
Deducts from oldest batches first:
- Batch 1 (50 eggs) → deduct 50, remaining = 0
- Batch 2 (80 eggs) → deduct 50, remaining = 30
    ↓
Updates eggs_stored in both batches
Records which batches were used in the sale
```

### Automatic Calculations
- **Tray Conversion:** 1 tray = 30 eggs
- **Total Calculation:** Quantity × Unit Price = Total
- **Inventory Check:** Prevents overselling (sale blocked if insufficient inventory)

## Database Schema

### New Table: product_pricing
```sql
Columns:
- id (INT, AUTO_INCREMENT)
- product_type (ENUM: egg_single, egg_tray, chicken_live, chicken_dressed)
- customer_type (ENUM: wholesaler, retailer, individual, other)
- price (DECIMAL 10,2)
- effective_date (DATE)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

Unique Constraint: (product_type, customer_type)
```

### Updated Table: egg_production
```sql
Removed: eggs_sold column
Calculation: eggs_stored = eggs_collected - eggs_broken
Used by: Sales module for inventory deduction
```

### Updated Table: sales
```sql
New Columns (if not exist):
- unit_type (VARCHAR) - 'single' or 'tray'
- eggs_from_storage (INT) - number of eggs deducted
- inventory_updated (BOOLEAN) - deduction status flag
```

## File Structure

```
db_updates/
    add_pricing_system.sql - Database migration script

admin/
    pricing.php - Pricing management interface (NEW)
    users.php - User management
    settings.php - System settings

pages/sales/
    sales.php - Old sales module (backup)
    sales_new.php - New sales module with inventory integration (NOW ACTIVE)

pages/production/
    egg_production.php - Updated (removed eggs_sold field)
```

## Troubleshooting

### Issue: "Pricing not found" error when creating sale
**Solution:** Make sure you've executed the database migration and set prices in Admin → Pricing Management

### Issue: "Insufficient eggs in storage" error
**Solution:** 
1. Check egg_production records: `SELECT SUM(eggs_stored) FROM egg_production`
2. Ensure you have production records with eggs_stored > 0
3. Remember: Only eggs in storage (not broken, not already sold) are available

### Issue: Sales recorded but inventory not deducted
**Solution:**
1. Check the `inventory_updated` field in the sales record
2. Review `eggs_from_storage` field - should match quantity sold
3. Check error logs in `logs/` directory

### Issue: Navigation link not showing for Pricing Management
**Solution:** Make sure you're logged in as an admin user (role = 'admin')

## Testing Checklist

- [ ] Database migration executed successfully
- [ ] product_pricing table exists and has data
- [ ] Admin can access Pricing Management page
- [ ] Prices can be updated and saved
- [ ] Sales form loads customer-specific pricing
- [ ] Egg production records show eggs_stored correctly
- [ ] Sales deduct inventory automatically
- [ ] FIFO order is maintained (oldest eggs sold first)
- [ ] Tray conversion works (1 tray = 30 eggs)
- [ ] Error handling prevents overselling

## Support

If you encounter any issues:
1. Check the browser console for JavaScript errors (F12)
2. Check PHP error logs: `C:\xampp\apache\logs\error.log`
3. Verify database connection in `includes/config.php`
4. Ensure all files are saved and Apache has been restarted

## Next Steps

After successful setup:
1. Train users on the new sales process
2. Import historical pricing data if needed
3. Set up regular price reviews
4. Monitor inventory levels through reports
5. Consider adding:
   - Price history tracking
   - Bulk import for pricing
   - Customer-specific pricing overrides
   - Automated low-stock alerts

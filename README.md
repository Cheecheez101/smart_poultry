# SmartPoultry Management System

A simple and effective poultry farm management system built with PHP and MySQL that helps farmers track their flocks, monitor health, manage sales, and generate reports.

## Features

### 🐔 Flock Management
- Add and manage multiple flocks with detailed information
- Track bird counts, breeds, and acquisition details
- Record supplier information and costs
- Monitor flock lifecycle from acquisition to sale

### 🏥 Health & Production Monitoring
- Record daily egg production
- Log medication and treatments
- Track mortality rates and health observations
- Schedule vaccinations and reminders

### 💰 Sales & Financial Tracking
- Record sales transactions with customer details
- Track payment methods and status
- Calculate profits and losses
- Generate financial summaries

### 📦 Inventory Management
- Track feed, medication, and equipment
- Monitor stock levels and reorder alerts
- Manage supplier relationships
- Record expenses and purchases

### 📊 Reports & Analytics
- Production reports with charts
- Financial summaries
- Health trend analysis
- Inventory reports

## Technology Stack

### Backend
- **PHP 8.1+** with PDO for database operations
- **MySQL 8.0** for data storage
- **Bootstrap 5** for responsive UI
- **Chart.js** for data visualization

## Quick Start

### Prerequisites
- PHP 8.1+
- MySQL 8.0+
- Apache/Nginx web server
- Web browser

### Installation

1. **Clone or download the project**
   ```bash
   git clone https://github.com/smartpoultry/management-system.git
   cd management-system
   ```

2. **Database Setup**
   - Create a MySQL database named `smart_poultry`
   - Import the database schema:
   ```bash
   mysql -u root -p smart_poultry < database.sql
   ```

3. **Configuration**
   - Edit `includes/config.php` to match your database settings:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'smart_poultry');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

4. **Web Server Setup**
   - Point your web server document root to the project folder
   - Ensure PHP has write permissions to the `logs/` directory

5. **Access the Application**
   - Open your web browser and navigate to your server
   - Login with demo credentials:
     - **Admin**: admin / admin123
     - **User**: demo / demo123

## Project Structure

```
SmartPoultry_Management_System/
├── assets/                    # Static files (CSS, JS, images)
│   ├── css/
│   │   └── style.css         # Main stylesheet
│   ├── js/
│   │   └── script.js         # Custom JavaScript
│   └── images/               # Logos, icons, photos
├── includes/                 # Reusable PHP files
│   ├── config.php           # Database connection
│   ├── header.php           # HTML head + navigation
│   ├── footer.php           # Closing HTML + scripts
│   ├── functions.php        # Helper functions
│   └── auth.php             # Session validation
├── pages/                   # Main feature pages
│   ├── dashboard.php        # Overview dashboard
│   ├── flocks/             # Flock management
│   │   ├── list_flocks.php
│   │   ├── add_flock.php
│   │   ├── edit_flock.php
│   │   └── delete_flock.php
│   ├── production/         # Production tracking
│   │   ├── record_production.php
│   │   └── view_production.php
│   ├── inventory/          # Inventory management
│   │   ├── list_inventory.php
│   │   ├── add_feed.php
│   │   └── reorder_alerts.php
│   ├── medication/         # Health management
│   │   ├── schedule_medication.php
│   │   ├── log_treatment.php
│   │   └── vaccination_reminders.php
│   ├── sales/             # Sales tracking
│   │   ├── list_sales.php
│   │   ├── add_sale.php
│   │   └── customer_history.php
│   ├── suppliers/         # Supplier management
│   │   ├── list_suppliers.php
│   │   └── add_supplier.php
│   └── reports/           # Reports and analytics
│       ├── production_report.php
│       ├── inventory_report.php
│       ├── sales_report.php
│       └── health_report.php
├── admin/                 # Admin-only pages
│   ├── users.php         # User management
│   └── settings.php      # System configuration
├── login.php             # Login form
├── logout.php            # Logout handler
├── index.php             # Main entry point
├── database.sql          # Database schema
└── README.md             # This file
```

## Key Features

### Dashboard Overview
- Real-time statistics and charts
- Recent activity tracking
- Low stock alerts
- Upcoming vaccination reminders
- Quick access to all modules

### Flock Management
- Add/edit/delete flocks
- Track bird counts and mortality
- Monitor age and production stages
- Breed and supplier information

### Production Tracking
- Daily egg production recording
- Feed consumption monitoring
- Mortality tracking
- Production trend analysis

### Health Management
- Medication scheduling and tracking
- Vaccination reminders
- Treatment logging
- Health report generation

### Sales Management  
- Customer transaction recording
- Payment tracking
- Sales history and analysis
- Invoice generation

### Inventory Control
- Stock level monitoring
- Reorder alerts
- Supplier management
- Expense tracking

## Usage

### Getting Started
1. **Login** with your credentials
2. **Add your first flock** from the Flocks menu
3. **Record daily production** data
4. **Track sales** and expenses
5. **Generate reports** for analysis

### Daily Workflow
1. Record egg production for each flock
2. Log any medication or treatments
3. Update inventory levels
4. Record sales transactions
5. Check dashboard for alerts and reminders

## Database Schema

The system uses the following main tables:
- `users` - System users and authentication
- `flocks` - Flock information and status
- `production` - Daily production records
- `inventory` - Feed, medication, and equipment
- `sales` - Sales transactions
- `medication` - Health treatments and schedules
- `suppliers` - Supplier contact information
- `expenses` - Cost tracking and management

## Contributing

We welcome contributions! Please:
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## Support

- 📧 Email: support@smartpoultry.com
- 🐛 Issues: [GitHub Issues](https://github.com/smartpoultry/management-system/issues)
- 📖 Documentation: See the code comments and this README

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

**SmartPoultry Management System** - Simple, effective poultry farm management.
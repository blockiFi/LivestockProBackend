# LiveStock Pro Backend

A comprehensive livestock management system backend built with Laravel 11. This API provides robust functionality for managing poultry farms, flocks, health records, and inventory tracking.

## 🌟 Features

### 🐔 Poultry Management
- **Flock Management**: Create, manage, and track poultry flocks
- **Daily Records**: Monitor daily activities, health, and production metrics
- **Batch Scheduling**: Plan and schedule feeding and care activities
- **Stage Tracking**: Monitor flock growth stages and transitions

### 💊 Health & Medication Management
- **Medication Records**: Complete CRUD operations for medication administration
- **Vaccination Records**: Comprehensive vaccination tracking and management
- **Inventory Integration**: Automatic stock reduction and restoration
- **Administration Methods**: Track various medication delivery methods
- **Cost Calculations**: Automatic cost computation based on dosage and inventory

### 🔐 Permission & Security
- **Role-Based Access Control**: Granular permission system with groups
- **API Authentication**: Secure API access with Sanctum
- **Group Management**: Organize permissions into logical groups
- **Permission Validation**: Comprehensive validation for all operations

### 📊 Advanced Features
- **Inventory Management**: Real-time stock tracking with automatic updates
- **Audit Logging**: Detailed logs for inventory changes and operations
- **Error Handling**: Comprehensive error handling with meaningful messages
- **Data Validation**: Robust validation for all inputs and operations
- **Relationship Management**: Complex model relationships with proper constraints

## 🚀 Technology Stack

- **Framework**: Laravel 11
- **Database**: MySQL/SQLite
- **Authentication**: Laravel Sanctum
- **Permissions**: Custom role-based system
- **API**: RESTful API with comprehensive endpoints
- **Validation**: Laravel Form Requests
- **Database**: Eloquent ORM with advanced relationships

## 📋 API Endpoints

### Medication Management
- `GET /api/poultry-medication-records` - List medication records
- `POST /api/poultry-medication-records` - Create medication record
- `DELETE /api/poultry-medication-records/{id}` - Delete medication record

### Vaccination Management
- `GET /api/poultry-vaccination-records` - List vaccination records
- `POST /api/poultry-vaccination-records` - Create vaccination record
- `DELETE /api/poultry-vaccination-records/{id}` - Delete vaccination record

### Supporting Endpoints
- `GET /api/vaccines` - List available vaccines
- `GET /api/vaccine-inventories` - List vaccine inventory
- `GET /api/administration-methods` - List administration methods
- `GET /api/medication-products` - List medication products
- `GET /api/medication-inventories` - List medication inventory

## 🛠️ Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/blockiFi/LivestockProBackend.git
   cd LivestockProBackend
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Database setup**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

5. **Start the server**
   ```bash
   php artisan serve
   ```

## 🔧 Configuration

### Database Configuration
Update your `.env` file with database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=livestock_pro
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### API Configuration
Configure Sanctum for API authentication:
```env
SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000
```

## 📊 Database Schema

### Key Models
- **Flock**: Core flock management
- **PoultryMedicationRecord**: Medication administration tracking
- **PoultryVaccinationRecord**: Vaccination tracking
- **MedicationProduct**: Available medications
- **VaccineProduct**: Available vaccines
- **Inventory Models**: Stock management for medications and vaccines
- **Permission & Group**: Role-based access control

### Relationships
- Flocks have many medication and vaccination records
- Records are linked to products and inventory items
- Automatic inventory updates on record creation/deletion
- Comprehensive audit trails for all operations

## 🧪 Testing

Run the test suite:
```bash
php artisan test
```

For enhanced seeder testing:
```bash
php test_enhanced_seeders.php
```

## 📝 Key Features Implementation

### Inventory Management
- Automatic stock reduction when creating records
- Automatic stock restoration when deleting records
- Real-time inventory validation
- Comprehensive error handling for insufficient stock

### Permission System
- Group-based permission organization
- Granular access control for all operations
- Dynamic permission validation
- Secure API endpoints with proper authorization

### Error Handling
- Meaningful error messages for all operations
- Validation errors with detailed feedback
- Database constraint handling
- Inventory insufficient stock protection

## 🤝 Contributing

We welcome contributions to LiveStock Pro! Please follow these steps:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## 🆘 Support

For support and questions:
- Create an issue on GitHub
- Check the documentation
- Review the API endpoints

## 🎯 Roadmap

- [ ] Mobile API enhancements
- [ ] Advanced analytics dashboard
- [ ] Integration with IoT devices
- [ ] Multi-farm management
- [ ] Advanced reporting features
- [ ] Automated alert system

---

Built with ❤️ for modern livestock management

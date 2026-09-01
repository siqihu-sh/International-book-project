# International Book Project

International Book Project is a PHP and MySQL web application for managing an international book distribution process.

The application demonstrates how a web application can connect to a relational database, display related records, validate user input, and keep inventory data consistent across multiple business steps.

## Technologies

- PHP
- MySQL
- HTML
- CSS
- JavaScript
- Git and GitHub

## Database Design

The Entity Relationship Diagram for the application is shown below.

[View the Entity Relationship Diagram](docs/database_schema_erd.pdf)
## Main Features

- View requests, shipments, returns, recipients, and inventory in separate tabs
- Create a request for an existing or new recipient
- Add multiple books and quantities to one request
- Check inventory before creating a request
- Decrease inventory when a request is created
- Create a shipment from a request that has not been shipped
- Prevent duplicate shipments for the same request
- Process a return from an existing shipment
- Restore inventory when a return is processed
- Add, edit, and delete supported records
- Display clear error messages for invalid operations

## Business Workflow

```text
Create Request
      |
      v
Check Inventory and Decrease Quantity
      |
      v
Create Shipment
      |
      v
Process Return, if needed
      |
      v
Restore Inventory
```

## Project Structure

```text
siqi_demo/
├── index.php                 # Main application page and data tabs
├── request_create.php        # Create a request with request items
├── shipment_create.php       # Create a shipment from an available request
├── return_create.php         # Process a return and restore inventory
├── manage.php                # Basic record management page
├── css/
│   └── style.css             # Basic page styling
├── js/
│   └── request_create.js     # Request form interactions
└── include/
    ├── mysqli_connect.php    # Local database connection; not committed to Git
    └── request_functions.php # Request validation and transaction logic
```

## Database Setup

This project uses the `international_book_project` MySQL database.

1. Start Apache from XAMPP.
2. Start the standalone MySQL server running on port `3306`.
3. Create the database and import the provided SQL dump.
4. Configure the local database connection in `include/mysqli_connect.php`.
5. Open the application at:

```text
http://localhost/siqi_demo/index.php
```

The connection file should contain your local credentials, for example:

```php
$conn = new mysqli(
    '127.0.0.1',
    'root',
    'YOUR_LOCAL_PASSWORD',
    'international_book_project',
    3306
);
```

Do not commit the real database password. The project ignores the local connection file through `.gitignore`.

## Data Integrity Rules

- A request cannot be created when the requested quantity is greater than available inventory.
- Request creation and inventory deduction use one database transaction.
- A request with an existing shipment cannot be deleted.
- A request's child request items are removed before the request is deleted.
- A shipment can only be created once for a request through the business workflow.
- A shipment can only be processed for return once.
- Processing a return restores the quantities recorded in the original request.

## Running the Application

Use a browser to open:

```text
http://localhost/siqi_demo/index.php
```

The user interface is currently designed for an administrator. Authentication and role-based access control are planned future improvements.

## Future Improvements

- Add automated tests and a test checklist
- Add authentication and role-based permissions
- Add CSRF protection and additional server-side validation
- Improve responsive design and accessibility compliance
- Add deployment documentation and HTTPS configuration
- Consider a JavaScript framework for a larger version of the application

<?php
// quick_setup.php - One-click database setup
echo "<!DOCTYPE html>
<html>
<head>
    <title>SPC Textiles - Database Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .btn { background: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; margin: 10px; }
        .btn:hover { background: #2980b9; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .step { margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>SPC Textiles - Database Setup</h1>";
        
// Database credentials
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'textile_gst_billing';

// Step 1: Connect to MySQL
echo "<div class='step'>";
echo "<h3>Step 1: Connecting to MySQL Server...</h3>";
$conn = @new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    echo "<div class='error'>Failed to connect to MySQL: " . $conn->connect_error . "</div>";
    exit();
}
echo "<div class='success'>✓ Connected to MySQL server successfully</div>";
echo "</div>";

// Step 2: Create Database
echo "<div class='step'>";
echo "<h3>Step 2: Creating Database...</h3>";
$sql = "CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn->query($sql) === TRUE) {
    echo "<div class='success'>✓ Database '$dbname' created successfully</div>";
} else {
    echo "<div class='error'>Error creating database: " . $conn->error . "</div>";
    exit();
}
echo "</div>";

// Step 3: Select Database and Create Tables
$conn->select_db($dbname);

$tables_sql = array(
    "customers" => "CREATE TABLE IF NOT EXISTS customers (
        customer_id INT AUTO_INCREMENT PRIMARY KEY,
        gstin VARCHAR(15) UNIQUE,
        customer_name VARCHAR(100) NOT NULL,
        address TEXT,
        city VARCHAR(50),
        state VARCHAR(50) DEFAULT 'Gujarat',
        pincode VARCHAR(10),
        email VARCHAR(100),
        phone VARCHAR(15),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "products" => "CREATE TABLE IF NOT EXISTS products (
        product_id INT AUTO_INCREMENT PRIMARY KEY,
        hsn_code VARCHAR(10),
        product_name VARCHAR(100) NOT NULL,
        description TEXT,
        category VARCHAR(50),
        unit VARCHAR(20),
        price DECIMAL(10,2) NOT NULL,
        gst_rate DECIMAL(5,2) DEFAULT 5.00,
        stock_quantity INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    
    "invoices" => "CREATE TABLE IF NOT EXISTS invoices (
        invoice_id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(20) UNIQUE NOT NULL,
        customer_id INT,
        invoice_date DATE NOT NULL,
        due_date DATE,
        subtotal DECIMAL(10,2) DEFAULT 0.00,
        discount DECIMAL(10,2) DEFAULT 0.00,
        cgst DECIMAL(10,2) DEFAULT 0.00,
        sgst DECIMAL(10,2) DEFAULT 0.00,
        igst DECIMAL(10,2) DEFAULT 0.00,
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        payment_status ENUM('paid', 'unpaid', 'partial') DEFAULT 'unpaid',
        payment_method VARCHAR(50),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL
    )",
    
    "invoice_items" => "CREATE TABLE IF NOT EXISTS invoice_items (
        item_id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT,
        product_id INT,
        quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL,
        discount DECIMAL(10,2) DEFAULT 0.00,
        tax_amount DECIMAL(10,2) DEFAULT 0.00,
        total_amount DECIMAL(10,2) DEFAULT 0.00,
        FOREIGN KEY (invoice_id) REFERENCES invoices(invoice_id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE SET NULL
    )"
);

echo "<div class='step'>";
echo "<h3>Step 3: Creating Tables...</h3>";
foreach ($tables_sql as $table_name => $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "<div class='success'>✓ Table '$table_name' created successfully</div>";
    } else {
        echo "<div class='error'>Error creating table '$table_name': " . $conn->error . "</div>";
    }
}
echo "</div>";

// Step 4: Insert Sample Data
echo "<div class='step'>";
echo "<h3>Step 4: Inserting Sample Data...</h3>";

// Insert sample customers
$customers = array(
    "('Fashion Trends', '27AABCF1234M1Z5', '123 Market Street', 'Surat', 'Gujarat', '395003', 'fashion@example.com', '9876543210')",
    "('Textile Palace', '27AABCG5678N2Z6', '456 Wholesale Market', 'Ahmedabad', 'Gujarat', '380001', 'palace@example.com', '9876543211')",
    "('Silk World', '29AABCH9101P3Z7', '789 Silk Road', 'Mumbai', 'Maharashtra', '400001', 'silk@example.com', '9876543212')"
);

foreach ($customers as $customer) {
    $sql = "INSERT INTO customers (customer_name, gstin, address, city, state, pincode, email, phone) VALUES $customer";
    if ($conn->query($sql) === TRUE) {
        echo "<div class='success'>✓ Sample customer added</div>";
    }
}

// Insert sample products
$products = array(
    "('Cotton Saree', '5407', 'Pure Cotton Printed Saree', 'Sarees', 'Piece', 850.00, 5, 100)",
    "('Silk Fabric', '5007', 'Pure Silk Fabric', 'Silk Fabrics', 'Meter', 1200.00, 5, 50)",
    "('Woolen Shawl', '5110', 'Woolen Winter Shawl', 'Woolen', 'Piece', 650.00, 5, 75)",
    "('Designer Suit', '5407', 'Designer Women Suit', 'Dress Materials', 'Set', 2500.00, 5, 30)"
);

foreach ($products as $product) {
    $sql = "INSERT INTO products (product_name, hsn_code, description, category, unit, price, gst_rate, stock_quantity) VALUES $product";
    if ($conn->query($sql) === TRUE) {
        echo "<div class='success'>✓ Sample product added</div>";
    }
}
echo "</div>";

// Step 5: Setup Complete
echo "<div class='step'>";
echo "<h3>Setup Complete!</h3>";
echo "<div class='success' style='font-size: 18px; padding: 20px;'>";
echo "✓ Database: <strong>$dbname</strong><br>";
echo "✓ Tables created: customers, products, invoices, invoice_items<br>";
echo "✓ Sample data inserted<br>";
echo "✓ Ready to use!";
echo "</div>";
echo "</div>";

echo "<div style='text-align: center; margin-top: 30px;'>";
echo "<a href='index.php' class='btn'>Go to SPC Textiles Application</a>";
echo "</div>";

$conn->close();

echo "</div></body></html>";
?>
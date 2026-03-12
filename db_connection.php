<?php
// db_connection.php
require_once 'config.php';

class Database {
    private $conn;
    private $error;
    
    public function __construct() {
        $this->connect();
    }
    
    private function connect() {
        try {
            // Create connection
            $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            // Check connection
            if ($this->conn->connect_error) {
                // If database doesn't exist, create it
                if ($this->conn->connect_errno == 1049) { // Error code for unknown database
                    $this->createDatabase();
                } else {
                    throw new Exception("Connection failed: " . $this->conn->connect_error);
                }
            }
            
            // Set charset to UTF-8
            $this->conn->set_charset("utf8mb4");
            
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->displayError();
        }
    }
    
    private function createDatabase() {
        // Create connection without database
        $temp_conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        
        if ($temp_conn->connect_error) {
            throw new Exception("Failed to connect to MySQL: " . $temp_conn->connect_error);
        }
        
        // Create database
        $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if ($temp_conn->query($sql) === FALSE) {
            throw new Exception("Failed to create database: " . $temp_conn->error);
        }
        
        $temp_conn->close();
        
        // Reconnect with database
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        if ($this->conn->connect_error) {
            throw new Exception("Failed to connect after creating database: " . $this->conn->connect_error);
        }
        
        // Create tables
        $this->createTables();
    }
    
    private function createTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS customers (
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
        );
        
        CREATE TABLE IF NOT EXISTS products (
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
        );
        
        CREATE TABLE IF NOT EXISTS invoices (
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
        );
        
        CREATE TABLE IF NOT EXISTS invoice_items (
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
        );
        ";
        
        // Execute multi-query
        if ($this->conn->multi_query($sql)) {
            while ($this->conn->more_results() && $this->conn->next_result()) {
                // Flush results
            }
        }
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function getError() {
        return $this->error;
    }
    
    private function displayError() {
        echo '<div style="background: #f8d7da; color: #721c24; padding: 20px; margin: 20px; border-radius: 5px; border: 1px solid #f5c6cb;">';
        echo '<h3>Database Connection Error</h3>';
        echo '<p>' . $this->error . '</p>';
        echo '<p>Please run the setup script first: <a href="setup_database.php">setup_database.php</a></p>';
        echo '</div>';
        exit();
    }
}
?>
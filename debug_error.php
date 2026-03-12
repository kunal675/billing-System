<?php
// debug_error.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

echo "<h2>Debug Information</h2>";

// Test basic PHP
echo "PHP Version: " . phpversion() . "<br>";
echo "Current Directory: " . __DIR__ . "<br>";

// Test file permissions
$files = ['index.php', 'print_invoice.php', 'config.php', 'db_connection.php'];
foreach ($files as $file) {
    echo "$file exists: " . (file_exists($file) ? 'Yes' : 'No') . "<br>";
}

// Test session
echo "Session started: " . (session_status() === PHP_SESSION_ACTIVE ? 'Yes' : 'No') . "<br>";

// Test database connection
try {
    require_once 'config.php';
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        echo "Database connection failed: " . $conn->connect_error . "<br>";
    } else {
        echo "Database connection: Success<br>";
    }
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "<br>";
}
?>
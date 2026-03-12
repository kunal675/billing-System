<?php
// config.php
session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'u838186511_khelwin_user');
define('DB_PASS', 'Alphadelta#675');
define('DB_NAME', 'u838186511_khelwin_bill');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set timezone
date_default_timezone_set('Asia/Kolkata');
?>
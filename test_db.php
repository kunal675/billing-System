<?php
$conn = new mysqli('localhost', 'u838186511_spc', 'Alphadelta#675', 'u838186511_textile_bill');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
echo "Connected successfully";
?>
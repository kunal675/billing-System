<?php
// addvendor.php
session_start();

// Include configuration and database connection
require_once 'config.php';
require_once 'db_connection.php';

// Create database connection
$db = new Database();
$conn = $db->getConnection();

// Check if connection was successful
if ($db->getError()) {
    die("Database connection failed: " . $db->getError());
}

// Initialize response array
$response = ['success' => false, 'message' => ''];

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get and sanitize form data
    $vendor_name = trim($_POST['vendor_name'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $gstin = trim($_POST['gstin'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? 'Gujarat');
    $pincode = trim($_POST['pincode'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $payment_terms = trim($_POST['payment_terms'] ?? '');
    $bank_details = trim($_POST['bank_details'] ?? '');
    
    // Validate required fields
    if (empty($vendor_name) || empty($phone)) {
        $response['message'] = 'Vendor Name and Phone are required fields';
    } else {
        try {
            // Prepare SQL statement
            $stmt = $conn->prepare("INSERT INTO vendors (vendor_name, company_name, gstin, contact_person, address, city, state, pincode, phone, email, payment_terms, bank_details) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            // Bind parameters
            $stmt->bind_param("ssssssssssss",
                $vendor_name,
                $company_name,
                $gstin,
                $contact_person,
                $address,
                $city,
                $state,
                $pincode,
                $phone,
                $email,
                $payment_terms,
                $bank_details
            );
            
            // Execute the statement
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Vendor added successfully!';
                $response['vendor_id'] = $stmt->insert_id;
                $response['vendor_name'] = $vendor_name;
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            $response['message'] = 'Error adding vendor: ' . $e->getMessage();
            error_log("Vendor add error: " . $e->getMessage());
        }
    }
} else {
    $response['message'] = 'Invalid request method';
}

// Set JSON header
header('Content-Type: application/json');

// Return JSON response
echo json_encode($response);
?>

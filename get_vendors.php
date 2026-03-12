<?php
session_start();
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $sql = "SELECT vendor_id, vendor_name, company_name, gstin, phone, email, 
                   address, city, state, contact_person 
            FROM vendors 
            ORDER BY vendor_name";
    
    $result = $conn->query($sql);
    
    $vendors = [];
    while ($row = $result->fetch_assoc()) {
        $vendors[] = $row;
    }
    
    echo json_encode($vendors);
} else if ($action === 'details' && isset($_GET['id'])) {
    $vendor_id = intval($_GET['id']);
    
    $stmt = $conn->prepare("SELECT * FROM vendors WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($vendor = $result->fetch_assoc()) {
        echo json_encode($vendor);
    } else {
        echo json_encode(['error' => 'Vendor not found']);
    }
}

$conn->close();
?>
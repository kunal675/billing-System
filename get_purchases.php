<?php
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

$sql = "SELECT po.*, v.vendor_name, v.company_name, 
        (SELECT COUNT(*) FROM purchase_order_items WHERE purchase_id = po.purchase_id) as total_items
        FROM purchase_orders po
        JOIN vendors v ON po.vendor_id = v.vendor_id
        ORDER BY po.purchase_date DESC";
        
$result = $conn->query($sql);

$purchases = [];
while ($row = $result->fetch_assoc()) {
    $purchases[] = $row;
}

echo json_encode($purchases);
?>
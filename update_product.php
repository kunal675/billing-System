<?php
// update_product.php
session_start();
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = intval($_POST['product_id']);
    $product_name = $conn->real_escape_string($_POST['product_name']);
    $hsn_code = $conn->real_escape_string($_POST['hsn_code']);
    $description = $conn->real_escape_string($_POST['description'] ?? '');
    $category = $conn->real_escape_string($_POST['category']);
    $unit = $conn->real_escape_string($_POST['unit']);
    $price = floatval($_POST['price']);
    $gst_rate = floatval($_POST['gst_rate']);
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    
    $sql = "UPDATE products SET 
            product_name = '$product_name',
            hsn_code = '$hsn_code',
            description = '$description',
            category = '$category',
            unit = '$unit',
            price = $price,
            gst_rate = $gst_rate,
            stock_quantity = $stock_quantity
            WHERE product_id = $product_id";
    
    if ($conn->query($sql) === TRUE) {
        echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating product: ' . $conn->error]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
<?php
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $purchase_id = intval($_POST['purchase_id']);
    
    $conn->begin_transaction();
    
    try {
        // Get purchase items
        $stmt = $conn->prepare("SELECT product_id, quantity FROM purchase_order_items WHERE purchase_id = ?");
        $stmt->bind_param("i", $purchase_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Update product stock for each item
        while ($item = $result->fetch_assoc()) {
            $update_stmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE product_id = ?");
            $update_stmt->bind_param("ii", $item['quantity'], $item['product_id']);
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update stock for product ID: " . $item['product_id']);
            }
        }
        
        // Update purchase status
        $update_po_stmt = $conn->prepare("UPDATE purchase_orders SET status = 'received', updated_at = NOW() WHERE purchase_id = ?");
        $update_po_stmt->bind_param("i", $purchase_id);
        if (!$update_po_stmt->execute()) {
            throw new Exception("Failed to update purchase status");
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase received successfully! Stock updated.'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
?>
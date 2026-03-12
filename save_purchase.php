<?php
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_purchase') {
        $conn->begin_transaction();
        
        try {
            // Generate PO number
            $year = date('Y');
            $month = date('m');
            $prefix = "PO" . $year . $month;
            
            $stmt = $conn->prepare("SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY purchase_id DESC LIMIT 1");
            $like_prefix = $prefix . "%";
            $stmt->bind_param("s", $like_prefix);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $last_number = intval(substr($row['po_number'], -4));
                $new_number = $last_number + 1;
            } else {
                $new_number = 1001;
            }
            
            $po_number = $prefix . str_pad($new_number, 4, '0', STR_PAD_LEFT);
            
            // Insert purchase order
            $stmt = $conn->prepare("INSERT INTO purchase_orders 
                (vendor_id, purchase_date, po_number, subtotal, discount, cgst, sgst, igst, total_amount, 
                 status, payment_status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $vendor_id = $_POST['vendor_id'];
            $purchase_date = $_POST['purchase_date'];
            $status = $_POST['status'] ?? 'pending';
            $payment_status = $_POST['payment_status'] ?? 'pending';
            $notes = $_POST['notes'] ?? '';
            
            // Calculate totals from form
            $subtotal = 0;
            $total_discount = 0;
            $total_cgst = 0;
            $total_sgst = 0;
            $total_igst = 0;
            
            if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
                for ($i = 0; $i < count($_POST['product_id']); $i++) {
                    if (!empty($_POST['product_id'][$i])) {
                        $quantity = intval($_POST['quantity'][$i]);
                        $purchase_price = floatval($_POST['purchase_price'][$i]);
                        $discount_percent = floatval($_POST['discount'][$i]) ?? 0;
                        $gst_rate = floatval($_POST['gst_rate'][$i]) ?? 5;
                        
                        $item_subtotal = $quantity * $purchase_price;
                        $item_discount = $item_subtotal * ($discount_percent / 100);
                        $item_taxable = $item_subtotal - $item_discount;
                        $item_gst = $item_taxable * ($gst_rate / 100);
                        
                        $subtotal += $item_subtotal;
                        $total_discount += $item_discount;
                        $total_cgst += $item_gst / 2; // Assuming Gujarat vendor
                        $total_sgst += $item_gst / 2;
                    }
                }
            }
            
            $total_amount = $subtotal - $total_discount + $total_cgst + $total_sgst + $total_igst;
            
            $stmt->bind_param("issddddddsss", 
                $vendor_id, $purchase_date, $po_number, $subtotal, $total_discount, 
                $total_cgst, $total_sgst, $total_igst, $total_amount, 
                $status, $payment_status, $notes
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save purchase order: " . $stmt->error);
            }
            
            $purchase_id = $stmt->insert_id;
            
            // Save purchase items
            if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
                for ($i = 0; $i < count($_POST['product_id']); $i++) {
                    if (!empty($_POST['product_id'][$i])) {
                        $product_id = intval($_POST['product_id'][$i]);
                        $quantity = intval($_POST['quantity'][$i]);
                        $purchase_price = floatval($_POST['purchase_price'][$i]);
                        $selling_price = floatval($_POST['selling_price'][$i]);
                        $discount_percent = floatval($_POST['discount'][$i]) ?? 0;
                        $gst_rate = floatval($_POST['gst_rate'][$i]) ?? 5;
                        
                        $item_subtotal = $quantity * $purchase_price;
                        $item_discount = $item_subtotal * ($discount_percent / 100);
                        $item_taxable = $item_subtotal - $item_discount;
                        $item_gst = $item_taxable * ($gst_rate / 100);
                        $item_total = $item_taxable + $item_gst;
                        
                        $item_stmt = $conn->prepare("INSERT INTO purchase_order_items 
                            (purchase_id, product_id, quantity, purchase_price, selling_price, 
                             discount_percent, discount_amount, gst_rate, gst_amount, total_amount) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        
                        $item_stmt->bind_param("iiiddddddd",
                            $purchase_id, $product_id, $quantity, $purchase_price, $selling_price,
                            $discount_percent, $item_discount, $gst_rate, $item_gst, $item_total
                        );
                        
                        if (!$item_stmt->execute()) {
                            throw new Exception("Failed to save purchase item: " . $item_stmt->error);
                        }
                    }
                }
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Purchase order saved successfully!',
                'purchase_id' => $purchase_id,
                'po_number' => $po_number
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
?>
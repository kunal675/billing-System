<?php
// functions.php
require_once 'db_connection.php';

class GSTBillingSystem {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    // Add new customer
    public function addCustomer($data) {
        $conn = $this->db->getConnection();
        
        $sql = "INSERT INTO customers (gstin, customer_name, address, city, state, pincode, email, phone) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssss", 
            $data['gstin'],
            $data['customer_name'],
            $data['address'],
            $data['city'],
            $data['state'],
            $data['pincode'],
            $data['email'],
            $data['phone']
        );
        
        return $stmt->execute();
    }
    
    // Add new product
    public function addProduct($data) {
        $conn = $this->db->getConnection();
        
        $sql = "INSERT INTO products (hsn_code, product_name, description, category, unit, price, gst_rate, stock_quantity) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssdii",
            $data['hsn_code'],
            $data['product_name'],
            $data['description'],
            $data['category'],
            $data['unit'],
            $data['price'],
            $data['gst_rate'],
            $data['stock_quantity']
        );
        
        return $stmt->execute();
    }
    
    // Create invoice
    public function createInvoice($invoiceData, $items) {
        $conn = $this->db->getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert invoice
            $invoiceSql = "INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date, 
                          subtotal, discount, cgst, sgst, igst, total_amount, notes) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($invoiceSql);
            $stmt->bind_param("sissdddddds",
                $invoiceData['invoice_number'],
                $invoiceData['customer_id'],
                $invoiceData['invoice_date'],
                $invoiceData['due_date'],
                $invoiceData['subtotal'],
                $invoiceData['discount'],
                $invoiceData['cgst'],
                $invoiceData['sgst'],
                $invoiceData['igst'],
                $invoiceData['total_amount'],
                $invoiceData['notes']
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create invoice");
            }
            
            $invoiceId = $conn->insert_id;
            
            // Insert invoice items
            foreach ($items as $item) {
                $itemSql = "INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price, 
                           discount, tax_amount, total_amount) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $itemStmt = $conn->prepare($itemSql);
                $itemStmt->bind_param("iiidddd",
                    $invoiceId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['discount'],
                    $item['tax_amount'],
                    $item['total_amount']
                );
                
                if (!$itemStmt->execute()) {
                    throw new Exception("Failed to add invoice items");
                }
                
                // Update stock
                $this->updateStock($item['product_id'], $item['quantity']);
            }
            
            $conn->commit();
            return $invoiceId;
            
        } catch (Exception $e) {
            $conn->rollback();
            return false;
        }
    }
    
    // Update product stock
    private function updateStock($productId, $quantity) {
        $conn = $this->db->getConnection();
        
        $sql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $quantity, $productId);
        return $stmt->execute();
    }
    
    // Generate GST report
    public function generateGSTReport($startDate, $endDate) {
        $conn = $this->db->getConnection();
        
        $sql = "SELECT 
                    DATE(i.invoice_date) as date,
                    i.invoice_number,
                    c.customer_name,
                    c.gstin as customer_gstin,
                    SUM(ii.total_amount) as taxable_value,
                    SUM(ii.tax_amount) as total_gst,
                    SUM(CASE WHEN c.state != 'Gujarat' THEN ii.tax_amount ELSE 0 END) as igst,
                    SUM(CASE WHEN c.state = 'Gujarat' THEN ii.tax_amount/2 ELSE 0 END) as cgst,
                    SUM(CASE WHEN c.state = 'Gujarat' THEN ii.tax_amount/2 ELSE 0 END) as sgst
                FROM invoices i
                JOIN customers c ON i.customer_id = c.customer_id
                JOIN invoice_items ii ON i.invoice_id = ii.invoice_id
                WHERE i.invoice_date BETWEEN ? AND ?
                GROUP BY i.invoice_id
                ORDER BY i.invoice_date";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $startDate, $endDate);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    // Get invoice for printing
    public function getInvoice($invoiceId) {
        $conn = $this->db->getConnection();
        
        $sql = "SELECT 
                    i.*,
                    c.*,
                    DATE_FORMAT(i.invoice_date, '%d/%m/%Y') as formatted_date
                FROM invoices i
                JOIN customers c ON i.customer_id = c.customer_id
                WHERE i.invoice_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $invoiceId);
        $stmt->execute();
        
        $invoice = $stmt->get_result()->fetch_assoc();
        
        if ($invoice) {
            $sql = "SELECT 
                        ii.*,
                        p.product_name,
                        p.hsn_code,
                        p.description
                    FROM invoice_items ii
                    JOIN products p ON ii.product_id = p.product_id
                    WHERE ii.invoice_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $invoiceId);
            $stmt->execute();
            
            $invoice['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        
        return $invoice;
    }
}

// Usage example
$billingSystem = new GSTBillingSystem();
?>
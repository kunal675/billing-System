<?php
// index.php
session_start();

// Include configuration first
require_once 'config.php';

// Include database connection
require_once 'db_connection.php';

// Create database connection
$db = new Database();
$conn = $db->getConnection();

// Check if connection was successful
if ($db->getError()) {
    // Error already displayed by Database class
    exit();
}

// Initialize variables
$error = '';
$success = '';
$invoice_number = '';
$saved_invoice_id = 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_invoice':
                $result = createInvoice($conn, $_POST);
                if ($result['success']) {
                    $success = "Invoice created successfully! Invoice #: " . $result['invoice_number'];
                    $invoice_number = $result['invoice_number'];
                    $saved_invoice_id = $result['invoice_id'];
                } else {
                    $error = "Error creating invoice: " . $result['message'];
                }
                break;
            case 'add_customer':
                if (addCustomer($conn, $_POST)) {
                    $success = "Customer added successfully!";
                } else {
                    $error = "Error adding customer";
                }
                break;
            case 'add_product':
                if (addProduct($conn, $_POST)) {
                    $success = "Product added successfully!";
                } else {
                    $error = "Error adding product";
                }
                break;
           
        }
    }
}

// Function to create invoice
function createInvoice($conn, $data) {
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Generate invoice number
        $invoice_number = generateInvoiceNumber($conn);
        
        // Get customer details
        $customer_id = intval($data['customer_id']);
        $customer_state = 'Uttar Pradesh'; // Default
        
        $stmt = $conn->prepare("SELECT state FROM customers WHERE customer_id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $customer_state = $row['state'];
        }
        
        // Calculate totals from form data
        $subtotal = floatval($data['subtotal']) ?? 0;
        $discount = floatval($data['discount']) ?? 0;
        $taxable_amount = $subtotal - $discount;
        
        // Calculate GST
        if ($customer_state == 'Gujarat') {
            $cgst = $taxable_amount * (GST_RATE_CGST / 100);
            $sgst = $taxable_amount * (GST_RATE_SGST / 100);
            $igst = 0;
        } else {
            $cgst = 0;
            $sgst = 0;
            $igst = $taxable_amount * (GST_RATE_IGST / 100);
        }
        
        $total_amount = $subtotal - $discount + $cgst + $sgst + $igst;
        
        // Insert invoice
        $stmt = $conn->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date, subtotal, discount, cgst, sgst, igst, total_amount, payment_status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid')");
        
        $invoice_date = $data['invoice_date'];
        $due_date = date('Y-m-d', strtotime($invoice_date . ' + 30 days')); // 30 days from invoice date
        
        $stmt->bind_param("sisddddddd", 
            $invoice_number,
            $customer_id,
            $invoice_date,
            $due_date,
            $subtotal,
            $discount,
            $cgst,
            $sgst,
            $igst,
            $total_amount
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save invoice: " . $stmt->error);
        }
        
        $invoice_id = $stmt->insert_id;
        
        // Save invoice items
        if (isset($data['product_id']) && is_array($data['product_id'])) {
            for ($i = 0; $i < count($data['product_id']); $i++) {
                if (!empty($data['product_id'][$i])) {
                    $product_id = intval($data['product_id'][$i]);
                    $quantity = intval($data['quantity'][$i]);
                    $unit_price = floatval($data['price'][$i]);
                    $item_discount = floatval($data['item_discount'][$i]) ?? 0;
                    
                    // Calculate item totals
                    $item_subtotal = $quantity * $unit_price;
                    $item_discount_amount = $item_subtotal * ($item_discount / 100);
                    $item_taxable = $item_subtotal - $item_discount_amount;
                    
                    // Get product GST rate
                    $product_stmt = $conn->prepare("SELECT gst_rate FROM products WHERE product_id = ?");
                    $product_stmt->bind_param("i", $product_id);
                    $product_stmt->execute();
                    $product_result = $product_stmt->get_result();
                    $gst_rate = 5; // Default
                    if ($product_row = $product_result->fetch_assoc()) {
                        $gst_rate = $product_row['gst_rate'];
                    }
                    
                    // Calculate GST for this item
                    if ($customer_state == 'Gujarat') {
                        $item_cgst = $item_taxable * (GST_RATE_CGST / 100);
                        $item_sgst = $item_taxable * (GST_RATE_SGST / 100);
                        $item_igst = 0;
                    } else {
                        $item_cgst = 0;
                        $item_sgst = 0;
                        $item_igst = $item_taxable * (GST_RATE_IGST / 100);
                    }
                    
                    $item_tax_amount = $item_cgst + $item_sgst + $item_igst;
                    $item_total = $item_taxable + $item_tax_amount;
                    
                    // Insert invoice item
                    $item_stmt = $conn->prepare("INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price, discount, tax_amount, total_amount) 
                                                VALUES (?, ?, ?, ?, ?, ?, ?)");
                    
                    $item_stmt->bind_param("iiidddd",
                        $invoice_id,
                        $product_id,
                        $quantity,
                        $unit_price,
                        $item_discount_amount,
                        $item_tax_amount,
                        $item_total
                    );
                    
                    if (!$item_stmt->execute()) {
                        throw new Exception("Failed to save invoice item: " . $item_stmt->error);
                    }
                    
                    // Update product stock
                    $update_stmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ?");
                    $update_stmt->bind_param("ii", $quantity, $product_id);
                    if (!$update_stmt->execute()) {
                        throw new Exception("Failed to update stock: " . $update_stmt->error);
                    }
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        return [
            'success' => true, 
            'invoice_number' => $invoice_number, 
            'invoice_id' => $invoice_id
        ];
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Function to generate invoice number
function generateInvoiceNumber($conn) {
    $year = date('Y');
    $month = date('m');
    $prefix = "TXT" . $year . $month;
    $like_prefix = $prefix . "%";
    
    // Get last invoice number for this month
    $stmt = $conn->prepare("SELECT invoice_number FROM invoices WHERE invoice_number LIKE ? ORDER BY invoice_id DESC LIMIT 1");
    $stmt->bind_param("s", $like_prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $last_number = intval(substr($row['invoice_number'], -4));
        $new_number = $last_number + 1;
    } else {
        $new_number = 1001;
    }
    
    return $prefix . str_pad($new_number, 4, '0', STR_PAD_LEFT);
}

// Function to add customer
function addCustomer($conn, $data) {
    $stmt = $conn->prepare("INSERT INTO customers (customer_name, gstin, address, city, state, pincode, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssss",
        $data['customer_name'],
        $data['gstin'],
        $data['address'],
        $data['city'],
        $data['state'],
        $data['pincode'],
        $data['phone'],
        $data['email']
    );
    return $stmt->execute();
}

// Function to add product
function addProduct($conn, $data) {
    $stmt = $conn->prepare("INSERT INTO products (product_name, hsn_code, description, category, unit, price, gst_rate, stock_quantity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssdii",
        $data['product_name'],
        $data['hsn_code'],
        $data['description'],
        $data['category'],
        $data['unit'],
        $data['price'],
        $data['gst_rate'],
        $data['stock_quantity']
    );
    return $stmt->execute();
}

// Function to get invoice details
function getInvoiceDetails($conn, $invoice_id) {
    $stmt = $conn->prepare("
        SELECT i.*, c.*, DATE_FORMAT(i.invoice_date, '%d/%m/%Y') as formatted_date
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.customer_id 
        WHERE i.invoice_id = ?
    ");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    
    if ($invoice) {
        $stmt = $conn->prepare("
            SELECT ii.*, p.product_name, p.hsn_code 
            FROM invoice_items ii 
            JOIN products p ON ii.product_id = p.product_id 
            WHERE ii.invoice_id = ?
        ");
        $stmt->bind_param("i", $invoice_id);
        $stmt->execute();
        $invoice['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    return $invoice;
}

// Get customers for dropdown
$customers_result = $conn->query("SELECT customer_id, customer_name, gstin FROM customers ORDER BY customer_name");

// Get products for dropdown
$products_result = $conn->query("SELECT product_id, product_name, price, hsn_code FROM products ORDER BY product_name");

// If we have a saved invoice ID, get details for preview
$saved_invoice_details = null;
if ($saved_invoice_id > 0) {
    $saved_invoice_details = getInvoiceDetails($conn, $saved_invoice_id);
}
?>

<style>
    
    /* Modal Responsive Styles */
@media (max-width: 768px) {
    .modal-content {
        width: 95% !important;
        padding: 25px !important;
        margin: 10% auto !important;
    }
    
    /* Customer/Product Forms */
    #customerModal .modal-content form div[style*="grid-template-columns"],
    #productModal .modal-content form div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
        gap: 15px !important;
    }
    
    /* Invoice Preview Modal */
    #invoicePreviewModal .modal-content {
        max-width: 95% !important;
        padding: 20px !important;
    }
    
    .invoice-header {
        flex-direction: column !important;
        text-align: center !important;
    }
    
    .invoice-meta {
        text-align: center !important;
        margin-top: 20px !important;
    }
    
    .invoice-table th,
    .invoice-table td {
        padding: 8px 5px !important;
        font-size: 12px !important;
    }
    
    .invoice-table {
        font-size: 12px !important;
    }
    
    .totals-table {
        width: 100% !important;
        max-width: 100% !important;
    }
}

/* Small Mobile Devices */
@media (max-width: 480px) {
    .modal-content {
        padding: 15px !important;
    }
    
    h3 {
        font-size: 18px !important;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 10px !important;
        font-size: 14px !important;
    }
    
    .btn {
        padding: 12px 20px !important;
        font-size: 14px !important;
    }
    
    /* Product Items Grid */
    .product-item > div {
        grid-template-columns: 1fr !important;
        gap: 10px !important;
    }
    
    .product-item .form-group {
        margin: 0 0 15px 0 !important;
    }
}

/* Modal Scroll Fix */
.modal-content {
    max-height: 85vh;
    overflow-y: auto;
}

/* Form Group in Modals */
.modal-content .form-group {
    margin-bottom: 15px;
}

.modal-content label {
    font-size: 14px;
    margin-bottom: 5px;
    display: block;
    color: #2c3e50;
    font-weight: 600;
}

.modal-content input,
.modal-content select,
.modal-content textarea {
    width: 100%;
    padding: 10px;
    border: 2px solid #e1e5eb;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
    background: #f8f9fa;
}

.modal-content input:focus,
.modal-content select:focus,
.modal-content textarea:focus {
    outline: none;
    border-color: #4a6491;
    background: white;
    box-shadow: 0 0 0 3px rgba(74, 100, 145, 0.1);
}

/* Scrollbar Styling */
.modal-content::-webkit-scrollbar {
    width: 8px;
}

.modal-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.modal-content::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

.modal-content::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}


/* Products Table Styles */
.products-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-family: 'Poppins', sans-serif;
}

.products-table th {
    background: linear-gradient(135deg, #4a6491 0%, #2c3e50 100%);
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    user-select: none;
    position: sticky;
    top: 0;
    z-index: 10;
}

.products-table th:hover {
    background: linear-gradient(135deg, #3a5471 0%, #1c2e40 100%);
}

.products-table th i {
    margin-left: 5px;
    font-size: 12px;
}

.products-table td {
    padding: 15px;
    border-bottom: 1px solid #e1e5eb;
    font-size: 14px;
    color: #495057;
}

.products-table tbody tr {
    transition: all 0.3s ease;
}

.products-table tbody tr:hover {
    background-color: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.products-table tbody tr:nth-child(even) {
    background-color: #f8f9fa;
}

.stock-indicator {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.stock-low {
    background: #fff3cd;
    color: #856404;
}

.stock-out {
    background: #f8d7da;
    color: #721c24;
}

.stock-good {
    background: #d4edda;
    color: #155724;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s ease;
}

.action-btn:hover {
    transform: translateY(-2px);
}

.edit-btn {
    background: #007bff;
    color: white;
}

.delete-btn {
    background: #dc3545;
    color: white;
}

.view-btn {
    background: #28a745;
    color: white;
}

/* Pagination Styles */
#pageInfo {
    font-size: 14px;
    color: #495057;
}

/* Search and Filter Styles */
#productSearch {
    border: 2px solid #e1e5eb;
    border-radius: 8px;
    padding: 10px 15px;
    font-size: 14px;
    transition: all 0.3s ease;
}

#productSearch:focus {
    outline: none;
    border-color: #4a6491;
    box-shadow: 0 0 0 3px rgba(74, 100, 145, 0.1);
}

.products-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-family: 'Poppins', sans-serif;
}

.products-table th {
    background: linear-gradient(135deg, #4a6491 0%, #2c3e50 100%);
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    user-select: none;
    position: sticky;
    top: 0;
    z-index: 10;
}

.products-table th:hover {
    background: linear-gradient(135deg, #3a5471 0%, #1c2e40 100%);
}

.products-table th i {
    margin-left: 5px;
    font-size: 12px;
}

.products-table td {
    padding: 15px;
    border-bottom: 1px solid #e1e5eb;
    font-size: 14px;
}

.products-table tbody tr {
    transition: all 0.3s ease;
}

.products-table tbody tr:hover {
    background-color: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.products-table tbody tr:nth-child(even) {
    background-color: #f8f9fa;
}

.stock-indicator {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.stock-low {
    background: #fff3cd;
    color: #856404;
}

.stock-out {
    background: #f8d7da;
    color: #721c24;
}

.stock-good {
    background: #d4edda;
    color: #155724;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s ease;
}

.action-btn:hover {
    transform: translateY(-2px);
}

.edit-btn {
    background: #007bff;
    color: white;
}

.delete-btn {
    background: #dc3545;
    color: white;
}

.view-btn {
    background: #28a745;
    color: white;
}

/* Pagination Styles */
#pageInfo {
    font-size: 14px;
    color: #495057;
}

/* Search and Filter Styles */
#productSearch {
    border: 2px solid #e1e5eb;
    border-radius: 8px;
    padding: 10px 15px;
    font-size: 14px;
    transition: all 0.3s ease;
}

#productSearch:focus {
    outline: none;
    border-color: #4a6491;
    box-shadow: 0 0 0 3px rgba(74, 100, 145, 0.1);
}

/* Stat Cards */
.stat-card {
    background: #e3f2fd;
    padding: 10px 20px;
    border-radius: 8px;
    text-align: center;
    min-width: 120px;
}

.stat-card:nth-child(2) {
    background: #e8f5e9;
}

.stat-card:nth-child(3) {
    background: #fff3e0;
}
    
    
/* Vendor Module Styles */
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-family: 'Poppins', sans-serif;
}

.data-table th {
    background: linear-gradient(135deg, #4a6491 0%, #2c3e50 100%);
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    user-select: none;
}

.data-table th:hover {
    background: linear-gradient(135deg, #3a5471 0%, #1c2e40 100%);
}

.data-table th i {
    margin-left: 5px;
    font-size: 12px;
}

.data-table td {
    padding: 15px;
    border-bottom: 1px solid #e1e5eb;
    font-size: 14px;
    color: #495057;
}

.data-table tbody tr {
    transition: all 0.3s ease;
}

.data-table tbody tr:hover {
    background-color: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.data-table tbody tr:nth-child(even) {
    background-color: #f8f9fa;
}

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-approved {
    background: #d1ecf1;
    color: #0c5460;
}

.status-received {
    background: #d4edda;
    color: #155724;
}

.status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

.status-paid {
    background: #d4edda;
    color: #155724;
}

.payment-pending {
    background: #f8d7da;
    color: #721c24;
}

.payment-partial {
    background: #fff3cd;
    color: #856404;
}

.payment-paid {
    background: #d4edda;
    color: #155724;
}

/* Action buttons */
.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s ease;
}

.action-btn:hover {
    transform: translateY(-2px);
}

.view-btn {
    background: #28a745;
    color: white;
}

.edit-btn {
    background: #007bff;
    color: white;
}

.delete-btn {
    background: #dc3545;
    color: white;
}

.receive-btn {
    background: #17a2b8;
    color: white;
}

.payment-btn {
    background: #20c997;
    color: white;
}

.print-btn {
    background: #6f42c1;
    color: white;
}

/* Purchase totals */
.purchase-totals {
    background: #f8f9fa;
    padding: 25px;
    border-radius: 10px;
    margin-top: 30px;
}

/* Vendor info */
.vendor-info-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.vendor-info-card h4 {
    color: #2c3e50;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #4a6491;
}

.vendor-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.vendor-info-row {
    display: flex;
    margin-bottom: 8px;
}

.vendor-info-label {
    font-weight: 600;
    color: #495057;
    min-width: 120px;
}

.vendor-info-value {
    color: #2d3436;
    flex: 1;
}
</style>

<style>
    
    /* Vendor Module Styles */
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
    font-family: 'Poppins', sans-serif;
}

.data-table th {
    background: linear-gradient(135deg, #4a6491 0%, #2c3e50 100%);
    color: white;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    user-select: none;
}

.data-table th:hover {
    background: linear-gradient(135deg, #3a5471 0%, #1c2e40 100%);
}

.data-table th i {
    margin-left: 5px;
    font-size: 12px;
}

.data-table td {
    padding: 15px;
    border-bottom: 1px solid #e1e5eb;
    font-size: 14px;
    color: #495057;
}

.data-table tbody tr {
    transition: all 0.3s ease;
}

.data-table tbody tr:hover {
    background-color: #f8f9fa;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.data-table tbody tr:nth-child(even) {
    background-color: #f8f9fa;
}

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-approved {
    background: #d1ecf1;
    color: #0c5460;
}

.status-received {
    background: #d4edda;
    color: #155724;
}

.status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

.status-paid {
    background: #d4edda;
    color: #155724;
}

.payment-pending {
    background: #f8d7da;
    color: #721c24;
}

.payment-partial {
    background: #fff3cd;
    color: #856404;
}

.payment-paid {
    background: #d4edda;
    color: #155724;
}

/* Action buttons */
.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s ease;
}

.action-btn:hover {
    transform: translateY(-2px);
}

.view-btn {
    background: #28a745;
    color: white;
}

.edit-btn {
    background: #007bff;
    color: white;
}

.delete-btn {
    background: #dc3545;
    color: white;
}

.receive-btn {
    background: #17a2b8;
    color: white;
}

.payment-btn {
    background: #20c997;
    color: white;
}

.print-btn {
    background: #6f42c1;
    color: white;
}

/* Purchase totals */
.purchase-totals {
    background: #f8f9fa;
    padding: 25px;
    border-radius: 10px;
    margin-top: 30px;
}

/* Vendor info */
.vendor-info-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.vendor-info-card h4 {
    color: #2c3e50;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #4a6491;
}

.vendor-info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.vendor-info-row {
    display: flex;
    margin-bottom: 8px;
}

.vendor-info-label {
    font-weight: 600;
    color: #495057;
    min-width: 120px;
}

.vendor-info-value {
    color: #2d3436;
    flex: 1;
}
</style>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo COMPANY_NAME; ?>  -  Billing System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/x-icon" href="liyansh1.png">
    <style>
        /* Your existing CSS styles remain the same */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%);
            color: white;
            padding: 25px 0;
            margin-bottom: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
        }
        
        header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #ff7e5f, #feb47b);
        }
        
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 30px;
            position: relative;
            z-index: 1;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .logo-icon {
            font-size: 40px;
            color: #feb47b;
        }
        
        .logo-text h1 {
            font-size: 28px;
            margin-bottom: 5px;
            background: linear-gradient(90deg, #ff7e5f, #feb47b);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .logo-text p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        nav ul {
            display: flex;
            list-style: none;
            gap: 15px;
        }
        
        nav a {
            color: white;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 50px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        
        nav a:hover, nav a.active {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
        }
        
        .notification {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 10px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid #28a745;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .card h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 20px;
        }
        
        .card h3 i {
            color: #4a6491;
        }
        
        .stat-number {
            font-size: 42px;
            font-weight: 800;
            color: #2c3e50;
            line-height: 1;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #4a6491, #2c3e50);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 15px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px;
            border: 2px solid #e1e5eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4a6491;
            background: white;
            box-shadow: 0 0 0 3px rgba(74, 100, 145, 0.1);
        }
        
        .btn {
            background: linear-gradient(135deg, #4a6491 0%, #2c3e50 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 20px rgba(74, 100, 145, 0.3);
        }
        
        .btn-print {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
        }
        
        .invoice-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-top: 30px;
        }
        
        .invoice-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 3px solid #f0f0f0;
        }
        
        .company-info h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 28px;
        }
        
        .invoice-meta {
            text-align: right;
        }
        
        .invoice-meta h3 {
            color: #4a6491;
            font-size: 24px;
            margin-bottom: 20px;
        }
        
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
        }
        
        .invoice-table th {
            background: linear-gradient(135deg, #4a6491 0%, #2c3e50 100%);
            color: white;
            padding: 18px;
            text-align: left;
            font-weight: 600;
        }
        
        .invoice-table td {
            padding: 18px;
            border-bottom: 1px solid #eee;
        }
        
        .invoice-table tr:hover {
            background: #f8f9fa;
        }
        
        .totals-table {
            width: 100%;
            max-width: 400px;
            margin-left: auto;
        }
        
        .totals-table td {
            padding: 15px 10px;
            border-bottom: 1px solid #eee;
        }
        
        .total-row {
            font-weight: 700;
            font-size: 20px;
            color: #2c3e50;
            border-top: 2px solid #4a6491;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 40px;
            border-radius: 15px;
            width: 90%;
            max-width: 700px;
            position: relative;
            animation: slideUp 0.4s ease;
        }
        
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .close-modal {
            position: absolute;
            right: 25px;
            top: 25px;
            font-size: 28px;
            cursor: pointer;
            color: #6c757d;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            nav ul {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .invoice-header {
                flex-direction: column;
                gap: 30px;
            }
            
            .invoice-meta {
                text-align: left;
            }
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .invoice-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
            }
            
            body {
                background: white;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <div class="logo">
                    <div class="logo-icon">
                        <img src="liyansh1.png"></i>
                    </div>
                    <div class="logo-text">
                        
                        <p> Billing System | Wholesale Bazaar </p>
                    </div>
                </div>
               <nav class="no-print">
    <ul>
        <li><a href="#dashboard" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
        <li><a href="#new-invoice"><i class="fas fa-file-invoice-dollar"></i> New Invoice</a></li>
        <li><a href="#products-page" id="productsPageLink"><i class="fas fa-boxes"></i> All Products</a></li>
         <li><a href="#purchases-page"><i class="fas fa-shopping-cart"></i> Purchase Stock</a></li>
         <li><a href="add_vendor_page.php"><i class="fas fa-truck"></i> Add Vendor</a></li>
        <li><a href="javascript:void(0)" onclick="openModal('customerModal')"><i class="fas fa-users"></i> Add Customer</a></li>
        <li><a href="javascript:void(0)" onclick="openModal('productModal')"><i class="fas fa-box"></i> Add Product</a></li>
        <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
    </ul>
</nav>
            </div>
        </header>

        <?php if ($success): ?>
        <div class="notification success">
            <i class="fas fa-check-circle"></i>
            <?php echo $success; ?>
            <?php if ($saved_invoice_id > 0): ?>
            <button onclick="openSavedInvoice(<?php echo $saved_invoice_id; ?>)" class="btn btn-success" style="margin-left: 20px; padding: 8px 15px; font-size: 14px;">
                <i class="fas fa-eye"></i> View Saved Invoice
            </button>
            <button onclick="printSavedInvoice(<?php echo $saved_invoice_id; ?>)" class="btn btn-print" style="margin-left: 10px; padding: 8px 15px; font-size: 14px;">
                <i class="fas fa-print"></i> Print Invoice
            </button>

            <button onclick="window.open('print_all_products.php', '_blank')" class="btn btn-print" style="margin-left: 10px;">
    <i class="fas fa-print"></i> Print All Products
</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="notification error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <main id="dashboard">
            <div class="dashboard-grid">
                <div class="card">
                    <h3><i class="fas fa-receipt"></i> Today's Invoices</h3>
                    <div class="stat-number">
                        <?php
                        $today = date('Y-m-d');
                        $result = $conn->query("SELECT COUNT(*) as count FROM invoices WHERE DATE(invoice_date) = '$today'");
                        $row = $result->fetch_assoc();
                        echo $row['count'];
                        ?>
                    </div>
                    <p>Total: 
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as total FROM invoices");
                        $row = $result->fetch_assoc();
                        echo $row['total'];
                        ?> invoices
                    </p>
                </div>
                
                <div class="card">
                    <h3><i class="fas fa-rupee-sign"></i> Today's Revenue</h3>
                    <div class="stat-number">
                        ₹<?php
                        $result = $conn->query("SELECT SUM(total_amount) as total FROM invoices WHERE DATE(invoice_date) = '$today'");
                        $row = $result->fetch_assoc();
                        echo number_format($row['total'] ?? 0, 2);
                        ?>
                    </div>
                    <p>Total Revenue: ₹
                        <?php
                        $result = $conn->query("SELECT SUM(total_amount) as total FROM invoices");
                        $row = $result->fetch_assoc();
                        echo number_format($row['total'] ?? 0, 2);
                        ?>
                    </p>
                </div>
                
                <div class="card">
                    <h3><i class="fas fa-users"></i> Customers</h3>
                    <div class="stat-number">
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as count FROM customers");
                        $row = $result->fetch_assoc();
                        echo $row['count'];
                        ?>
                    </div>
                    <p>Active customers</p>
                </div>
                
                <div class="card">
                    <h3><i class="fas fa-cubes"></i> Products</h3>
                    <div class="stat-number">
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as count FROM products");
                        $row = $result->fetch_assoc();
                        echo $row['count'];
                        ?>
                    </div>
                    <p>In stock</p>
                </div>
            </div>


           <!-- All Products Page -->
<div class="card" id="products-page" style="display: none;">
    <h3><i class="fas fa-boxes"></i> All Products Inventory</h3>
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <div>
            <button onclick="loadAllProducts()" class="btn">
                <i class="fas fa-sync-alt"></i> Refresh Products
            </button>
            <button onclick="printProductsTable()" class="btn btn-print" style="margin-left: 10px;">
                <i class="fas fa-print"></i> Print List
            </button>
            <button onclick="exportProductsToExcel()" class="btn" style="background: #28a745; margin-left: 10px;">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
        </div>
        
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="form-group" style="margin: 0; width: 200px;">
                <select id="categoryFilter" onchange="filterProducts()" style="width: 100%;">
                    <option value="">All Categories</option>
                    <option value="Sarees">Sarees</option>
                    <option value="Dress Materials">Dress Materials</option>
                    <option value="Silk Fabrics">Silk Fabrics</option>
                    <option value="Cotton Fabrics">Cotton Fabrics</option>
                    <option value="Woolen">Woolen</option>
                    <option value="Synthetic">Synthetic</option>
                </select>
            </div>
            
            <div class="form-group" style="margin: 0; width: 200px;">
                <select id="stockFilter" onchange="filterProducts()" style="width: 100%;">
                    <option value="">All Stock Levels</option>
                    <option value="low">Low Stock (< 10)</option>
                    <option value="out">Out of Stock</option>
                    <option value="available">In Stock</option>
                </select>
            </div>
        </div>
    </div>
    
    <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <div class="form-group" style="margin: 0; display: flex; align-items: center; gap: 10px;">
                <input type="text" id="productSearch" placeholder="Search products..." 
                       style="width: 300px; padding: 10px;" onkeyup="searchProducts()">
                <button onclick="searchProducts()" class="btn" style="padding: 10px 20px;">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <div class="stat-card" style="background: #e3f2fd; padding: 10px 20px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #1976d2;">Total Products</div>
                <div id="totalProductsCount" style="font-size: 24px; font-weight: bold; color: #1976d2;">0</div>
            </div>
            <div class="stat-card" style="background: #e8f5e9; padding: 10px 20px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #2e7d32;">Total Stock Value</div>
                <div id="totalStockValue" style="font-size: 24px; font-weight: bold; color: #2e7d32;">₹0</div>
            </div>
            <div class="stat-card" style="background: #fff3e0; padding: 10px 20px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #f57c00;">Low Stock Items</div>
                <div id="lowStockCount" style="font-size: 24px; font-weight: bold; color: #f57c00;">0</div>
            </div>
        </div>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="products-table" id="productsTable">
            <thead>
                <tr>
                    <th onclick="sortProducts('product_id')"># <i class="fas fa-sort"></i></th>
                    <th onclick="sortProducts('product_name')">Product Name <i class="fas fa-sort"></i></th>
                    <th onclick="sortProducts('hsn_code')">HSN Code <i class="fas fa-sort"></i></th>
                    <th onclick="sortProducts('category')">Category <i class="fas fa-sort"></i></th>
                    <th onclick="sortProducts('stock_quantity')">Stock Qty <i class="fas fa-sort"></i></th>
                    <th onclick="sortProducts('unit')">Unit <i class="fas fa-sort"></i></th>
                    <th onclick="sortProducts('price')">Price <i class="fas fa-sort"></i></th>
                    <th onclick="sortProducts('gst_rate')">GST % <i class="fas fa-sort"></i></th>
                    <th>Stock Value</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="productsTableBody">
                <!-- Products will be loaded here -->
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <span id="productsInfo">Showing 0 products</span>
        </div>
        <div>
            <button onclick="previousPage()" class="btn btn-secondary" id="prevBtn" disabled>
                <i class="fas fa-chevron-left"></i> Previous
            </button>
            <span id="pageInfo" style="margin: 0 15px;">Page 1 of 1</span>
            <button onclick="nextPage()" class="btn btn-secondary" id="nextBtn" disabled>
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editProductModal" class="modal">
    <div class="modal-content" style="max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('editProductModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-edit"></i> Edit Product
        </h3>
        <form id="editProductForm" onsubmit="updateProduct(event)">
            <input type="hidden" id="editProductId" name="product_id">
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" id="editProductName" name="product_name" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>HSN Code *</label>
                    <input type="text" id="editHsnCode" name="hsn_code" required style="width: 100%;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea id="editDescription" name="description" rows="2" style="width: 100%; resize: vertical;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Category</label>
                    <select id="editCategory" name="category" style="width: 100%; padding: 10px;">
                        <option value="Sarees">Sarees</option>
                        <option value="Suits">Suits</option>
                        <option value="Silk Sarees">Silk Sarees</option>
                        <option value="Lahengas">Lahengas</option>
                        
                    </select>
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <select id="editUnit" name="unit" style="width: 100%; padding: 10px;">
                        <option value="Piece">Piece</option>
                        <option value="Meter">Meter</option>
                        <option value="Kg">Kg</option>
                        <option value="Set">Set</option>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Price (₹) *</label>
                    <input type="number" id="editPrice" name="price" step="0.01" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>GST Rate (%) *</label>
                    <input type="number" id="editGstRate" name="gst_rate" step="0.01" required style="width: 100%;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Stock Quantity</label>
                <input type="number" id="editStockQuantity" name="stock_quantity" style="width: 100%;">
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px;">
                <button type="button" onclick="closeModal('editProductModal')" class="btn btn-secondary" style="padding: 12px 25px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn" style="padding: 12px 25px;">
                    <i class="fas fa-save"></i> Update Product
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================= -->
<!-- VENDORS PAGE -->
<!-- ============================= -->
<div class="card" id="vendors-page" style="display: none;">
    <h3><i class="fas fa-truck"></i> Vendor Management</h3>
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <div>
            <button onclick="openVendorModal()" class="btn">
                <i class="fas fa-plus"></i> Add New Vendor
            </button>
            <button onclick="loadVendors()" class="btn btn-secondary" style="margin-left: 10px;">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
        
        <div style="display: flex; gap: 15px;">
            <div class="form-group" style="margin: 0; width: 200px;">
                <input type="text" id="vendorSearch" placeholder="Search vendors..." 
                       style="width: 100%;" onkeyup="searchVendors()">
            </div>
            <div class="stat-card" style="background: #e3f2fd; padding: 10px 20px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #1976d2;">Total Vendors</div>
                <div id="totalVendors" style="font-size: 24px; font-weight: bold; color: #1976d2;">0</div>
            </div>
        </div>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="data-table" id="vendorsTable">
            <thead>
                <tr>
                    <th onclick="sortVendors('vendor_id')"># <i class="fas fa-sort"></i></th>
                    <th onclick="sortVendors('vendor_name')">Vendor Name <i class="fas fa-sort"></i></th>
                    <th onclick="sortVendors('company_name')">Company <i class="fas fa-sort"></i></th>
                    <th onclick="sortVendors('gstin')">GSTIN <i class="fas fa-sort"></i></th>
                    <th>Contact</th>
                    <th>Address</th>
                    <th>Total Purchases</th>
                    <th>Balance Due</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="vendorsTableBody">
                <!-- Vendors will be loaded here -->
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 20px; text-align: center; color: #666;">
        <span id="vendorsInfo">No vendors found</span>
    </div>
</div>

<!-- ============================= -->
<!-- PURCHASE STOCK MODULE -->
<!-- ============================= -->
<div class="card" id="purchases-page" style="display: none;">
    <h3><i class="fas fa-shopping-cart"></i> Purchase Stock from Vendor</h3>
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <div>
            <button onclick="openPurchaseModal()" class="btn">
                <i class="fas fa-plus"></i> New Purchase Order
            </button>
            <button onclick="loadPurchaseOrders()" class="btn btn-secondary" style="margin-left: 10px;">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <button onclick="printPurchaseReport()" class="btn btn-print" style="margin-left: 10px;">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
        
        <div style="display: flex; gap: 15px; align-items: center;">
            <div class="form-group" style="margin: 0; width: 150px;">
                <select id="purchaseStatusFilter" onchange="filterPurchaseOrders()" style="width: 100%;">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="received">Received</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="paid">Paid</option>
                </select>
            </div>
            <div class="form-group" style="margin: 0; width: 200px;">
                <input type="date" id="purchaseDateFilter" onchange="filterPurchaseOrders()" style="width: 100%;">
            </div>
            <div class="stat-card" style="background: #e3f2fd; padding: 10px 20px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #1976d2;">Total Purchases</div>
                <div id="totalPurchases" style="font-size: 24px; font-weight: bold; color: #1976d2;">0</div>
            </div>
            <div class="stat-card" style="background: #e8f5e9; padding: 10px 20px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #2e7d32;">Total Value</div>
                <div id="totalPurchaseValue" style="font-size: 24px; font-weight: bold; color: #2e7d32;">₹0</div>
            </div>
        </div>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="data-table" id="purchasesTable">
            <thead>
                <tr>
                    <th onclick="sortPurchases('purchase_id')">PO # <i class="fas fa-sort"></i></th>
                    <th onclick="sortPurchases('purchase_date')">Date <i class="fas fa-sort"></i></th>
                    <th>Vendor</th>
                    <th>Items</th>
                    <th onclick="sortPurchases('total_amount')">Total Amount <i class="fas fa-sort"></i></th>
                    <th onclick="sortPurchases('status')">Status <i class="fas fa-sort"></i></th>
                    <th onclick="sortPurchases('payment_status')">Payment <i class="fas fa-sort"></i></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="purchasesTableBody">
                <!-- Purchase orders will be loaded here -->
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <span id="purchasesInfo">No purchase orders found</span>
        </div>
        <div>
            <button onclick="previousPurchasePage()" class="btn btn-secondary" id="purchasePrevBtn" disabled>
                <i class="fas fa-chevron-left"></i> Previous
            </button>
            <span id="purchasePageInfo" style="margin: 0 15px;">Page 1 of 1</span>
            <button onclick="nextPurchasePage()" class="btn btn-secondary" id="purchaseNextBtn" disabled>
                Next <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
</div>





            <div class="card" id="new-invoice">
                <h3><i class="fas fa-file-invoice"></i> Create New GST Invoice</h3>
                <form id="invoiceForm" method="POST" action="">
                    <input type="hidden" name="action" value="create_invoice">
                    <input type="hidden" id="subtotal" name="subtotal" value="0">
                    <input type="hidden" id="discount" name="discount" value="0">
                    
                    <div class="form-group">
                        <label for="customer_id"><i class="fas fa-user"></i> Select Customer</label>
                        <select id="customer_id" name="customer_id" required class="customer-select">
                            <option value="">-- Select Customer --</option>
                            <?php while ($customer = $customers_result->fetch_assoc()): ?>
                            <option value="<?php echo $customer['customer_id']; ?>">
                                <?php echo htmlspecialchars($customer['customer_name']); ?> 
                                <?php if ($customer['gstin']): ?>
                                    (GSTIN: <?php echo $customer['gstin']; ?>)
                                <?php endif; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="invoice_date"><i class="fas fa-calendar"></i> Invoice Date</label>
                        <input type="date" id="invoice_date" name="invoice_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <h4 style="margin: 25px 0 15px 0; color: #2c3e50;"><i class="fas fa-list"></i> Add Products</h4>
                    
                    <div id="productItems">
                        <div class="product-item">
                            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px; align-items: end;">
                                <div class="form-group" style="margin: 0;">
                                    <select name="product_id[]" class="product-select" onchange="updateProductPrice(this)">
                                        <option value="">Select Product</option>
                                        <?php 
                                        $products_result->data_seek(0); // Reset pointer
                                        while ($product = $products_result->fetch_assoc()): 
                                        ?>
                                        <option value="<?php echo $product['product_id']; ?>" data-price="<?php echo $product['price']; ?>" data-hsn="<?php echo $product['hsn_code']; ?>">
                                            <?php echo htmlspecialchars($product['product_name']); ?> - ₹<?php echo $product['price']; ?>
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <input type="number" name="quantity[]" placeholder="Qty" min="1" value="1" class="quantity" onchange="calculateRowTotal(this)" oninput="calculateRowTotal(this)">
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <input type="number" name="price[]" placeholder="Price" step="0.01" class="price" onchange="calculateRowTotal(this)" oninput="calculateRowTotal(this)">
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <input type="number" name="item_discount[]" placeholder="Disc %" step="0.01" value="0" class="item-discount" onchange="calculateRowTotal(this)" oninput="calculateRowTotal(this)">
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <input type="text" name="gst_rate[]" placeholder="GST %" value="5" class="gst-rate" readonly style="background: #e9ecef;">
                                </div>
                                <div class="form-group" style="margin: 0;">
                                    <input type="text" name="row_total[]" placeholder="Total" class="row-total" readonly style="background: #e9ecef; font-weight: bold;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="button" onclick="addProductRow()" class="btn btn-secondary" style="margin-bottom: 30px;">
                        <i class="fas fa-plus"></i> Add Product Row
                    </button>

                    <div class="invoice-totals" style="background: #f8f9fa; padding: 25px; border-radius: 10px; margin-top: 30px;">
                        <table class="totals-table">
                            <tr>
                                <td>Subtotal:</td>
                                <td id="display_subtotal" style="text-align: right;">₹0.00</td>
                            </tr>
                            <tr>
                                <td>Discount:</td>
                                <td id="display_discount" style="text-align: right;">₹0.00</td>
                            </tr>
                            <tr>
                                <td>CGST (<?php echo GST_RATE_CGST; ?>%):</td>
                                <td id="display_cgst" style="text-align: right;">₹0.00</td>
                            </tr>
                            <tr>
                                <td>SGST (<?php echo GST_RATE_SGST; ?>%):</td>
                                <td id="display_sgst" style="text-align: right;">₹0.00</td>
                            </tr>
                            <tr>
                                <td>IGST (<?php echo GST_RATE_IGST; ?>%):</td>
                                <td id="display_igst" style="text-align: right;">₹0.00</td>
                            </tr>
                            <tr class="total-row">
                                <td>Grand Total:</td>
                                <td id="display_grand_total" style="text-align: right;">₹0.00</td>
                            </tr>
                        </table>
                    </div>

                    <div style="display: flex; gap: 20px; margin-top: 30px;">
                        <button type="submit" class="btn">
                            <i class="fas fa-save"></i> Save & Generate Invoice
                        </button>
                        <button type="button" onclick="previewInvoice()" class="btn btn-secondary">
                            <i class="fas fa-eye"></i> Preview Invoice
                        </button>
                        <button type="button" onclick="resetForm()" class="btn btn-secondary" style="background: #6c757d;">
                            <i class="fas fa-redo"></i> Reset Form
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- Invoice Preview Modal -->
    <div id="invoicePreviewModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <span class="close-modal" onclick="closeModal('invoicePreviewModal')">&times;</span>
            <div id="invoicePreviewContent">
                <!-- Preview will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Saved Invoice Modal -->
    <div id="savedInvoiceModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <span class="close-modal" onclick="closeModal('savedInvoiceModal')">&times;</span>
            <div id="savedInvoiceContent">
                <!-- Saved invoice will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Add Customer Modal -->
    <!-- Add Customer Modal -->
<div id="customerModal" class="modal">
    <div class="modal-content" style="max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('customerModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-user-plus"></i> Add New Customer
        </h3>
        <form method="POST" action="" id="customerForm">
            <input type="hidden" name="action" value="add_customer">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Customer Name *</label>
                    <input type="text" name="customer_name" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>GSTIN</label>
                    <input type="text" name="gstin" placeholder="27XXXXXXXXXXXXX" style="width: 100%;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" rows="2" style="width: 100%; resize: vertical;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>State</label>
                    <select name="state" style="width: 100%; padding: 10px;">
                         <option value="Uttar Pradesh">Uttar Pradesh</option>
                        <option value="Gujarat">Gujarat</option>
                        <option value="Maharashtra">Maharashtra</option>
                        <option value="Rajasthan">Rajasthan</option>
                        <option value="Madhya Pradesh">Madhya Pradesh</option>
                        <option value="Delhi">Delhi</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Pincode</label>
                    <input type="text" name="pincode" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
                <div class="form-group">
                    <label>Phone *</label>
                    <input type="tel" name="phone" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end;">
                <button type="button" onclick="closeModal('customerModal')" class="btn btn-secondary" style="padding: 12px 25px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn" style="padding: 12px 25px;">
                    <i class="fas fa-save"></i> Save Customer
                </button>
            </div>
        </form>
    </div>
</div>



<!-- Add Vendor Modal -->
<!-- Add Vendor Modal -->
<div id="vendorModal" class="modal">
    <div class="modal-content" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('vendorModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-truck"></i> <span id="vendorModalTitle">Add New Vendor</span>
        </h3>
        <form id="vendorForm" onsubmit="saveVendor(event)">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Vendor Name *</label>
                    <input type="text" id="vendor_name" name="vendor_name" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Company Name</label>
                    <input type="text" id="company_name" name="company_name" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>GSTIN</label>
                    <input type="text" id="gstin" name="gstin" placeholder="27XXXXXXXXXXXXX" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" id="contact_person" name="contact_person" style="width: 100%;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea id="address" name="address" rows="2" style="width: 100%; resize: vertical;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>City</label>
                    <input type="text" id="city" name="city" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>State</label>
                    <select id="state" name="state" style="width: 100%; padding: 10px;">
                        <option value="Gujarat">Gujarat</option>
                        <option value="Maharashtra">Maharashtra</option>
                        <option value="Rajasthan">Rajasthan</option>
                        <option value="Madhya Pradesh">Madhya Pradesh</option>
                        <option value="Delhi">Delhi</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Pincode</label>
                    <input type="text" id="pincode" name="pincode" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Phone *</label>
                    <input type="tel" id="phone" name="phone" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="email" name="email" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Payment Terms</label>
                    <input type="text" id="payment_terms" name="payment_terms" placeholder="e.g., 30 days" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Bank Details</label>
                    <textarea id="bank_details" name="bank_details" rows="2" style="width: 100%; resize: vertical;" 
                              placeholder="Bank Name, Account No, IFSC, etc."></textarea>
                </div>
            </div>
            
            <div id="vendorMessage" style="display: none; margin: 15px 0; padding: 10px; border-radius: 5px;"></div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px;">
                <button type="button" onclick="closeModal('vendorModal')" class="btn btn-secondary" style="padding: 12px 25px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn" style="padding: 12px 25px;">
                    <i class="fas fa-save"></i> Save Vendor
                </button>
            </div>
        </form>
    </div>
</div>

<div id="purchaseModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('purchaseModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-shopping-cart"></i> <span id="purchaseModalTitle">New Purchase Order</span>
        </h3>
        <form id="purchaseForm" onsubmit="savePurchaseOrder(event)">
            <input type="hidden" id="purchaseId" name="purchase_id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Vendor *</label>
                    <select id="vendorSelect" name="vendor_id" required style="width: 100%; padding: 10px;"
                            onchange="loadVendorDetails(this.value)">
                        <option value="">Select Vendor</option>
                        <!-- Vendors will be loaded dynamically -->
                    </select>
                </div>
                <div class="form-group">
                    <label>Purchase Date *</label>
                    <input type="date" id="purchaseDate" name="purchase_date" required 
                           style="width: 100%;" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            
            <!-- Vendor Info Display -->
            <div id="vendorInfo" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <strong>Company:</strong> <span id="vendorCompany"></span><br>
                        <strong>GSTIN:</strong> <span id="vendorGstinDisplay"></span><br>
                        <strong>Contact:</strong> <span id="vendorContact"></span>
                    </div>
                    <div>
                        <strong>Address:</strong> <span id="vendorAddress"></span><br>
                        <strong>Phone:</strong> <span id="vendorPhoneDisplay"></span><br>
                        <strong>Email:</strong> <span id="vendorEmailDisplay"></span>
                    </div>
                </div>
            </div>
            
            <h4 style="margin: 25px 0 15px 0; color: #2c3e50;"><i class="fas fa-list"></i> Add Products to Purchase</h4>
            
            <div id="purchaseItems">
                <div class="purchase-item">
                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 1fr; gap: 10px; margin-bottom: 15px; align-items: end;">
                        <div class="form-group" style="margin: 0;">
                            <select name="product_id[]" class="purchase-product-select" onchange="updatePurchaseProductDetails(this)" required>
                                <option value="">Select Product</option>
                                <!-- Products will be loaded dynamically -->
                            </select>
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="quantity[]" placeholder="Qty" min="1" value="1" 
                                   class="purchase-quantity" required onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="purchase_price[]" placeholder="Cost Price" step="0.01" 
                                   class="purchase-price" required onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="selling_price[]" placeholder="Selling Price" step="0.01" 
                                   class="selling-price" required onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="discount[]" placeholder="Disc %" step="0.01" value="0" 
                                   class="purchase-discount" onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="gst_rate[]" placeholder="GST %" step="0.01" value="5" 
                                   class="purchase-gst-rate" onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="text" name="row_total[]" placeholder="Total" class="purchase-row-total" 
                                   readonly style="background: #e9ecef; font-weight: bold;">
                        </div>
                    </div>
                    <div id="productInfo_0" style="display: none; font-size: 12px; color: #666; margin-top: 5px;">
                        Current Stock: <span id="currentStock_0">0</span> | 
                        HSN: <span id="hsnCode_0">-</span>
                    </div>
                </div>
            </div>
            
            <button type="button" onclick="addPurchaseItemRow()" class="btn btn-secondary" style="margin-bottom: 30px;">
                <i class="fas fa-plus"></i> Add Another Product
            </button>
            
            <div class="purchase-totals" style="background: #f8f9fa; padding: 25px; border-radius: 10px; margin-top: 30px;">
                <table style="width: 100%; max-width: 400px; margin-left: auto;">
                    <tr>
                        <td>Subtotal:</td>
                        <td id="purchaseSubtotal" style="text-align: right;">₹0.00</td>
                    </tr>
                    <tr>
                        <td>Discount:</td>
                        <td id="purchaseDiscount" style="text-align: right;">₹0.00</td>
                    </tr>
                    <tr>
                        <td>CGST:</td>
                        <td id="purchaseCgst" style="text-align: right;">₹0.00</td>
                    </tr>
                    <tr>
                        <td>SGST:</td>
                        <td id="purchaseSgst" style="text-align: right;">₹0.00</td>
                    </tr>
                    <tr>
                        <td>IGST:</td>
                        <td id="purchaseIgst" style="text-align: right;">₹0.00</td>
                    </tr>
                    <tr style="font-weight: bold; font-size: 18px; border-top: 2px solid #4a6491;">
                        <td>Total Amount:</td>
                        <td id="purchaseTotal" style="text-align: right;">₹0.00</td>
                    </tr>
                </table>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <div class="form-group">
                    <label>Payment Status</label>
                    <select id="paymentStatus" name="payment_status" style="width: 100%; padding: 10px;">
                        <option value="pending">Pending</option>
                        <option value="partial">Partial</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Purchase Status</label>
                    <select id="purchaseStatus" name="status" style="width: 100%; padding: 10px;">
                        <option value="pending">Pending</option>
                        <option value="received">Received</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group" style="margin-top: 20px;">
                <label>Notes / Terms</label>
                <textarea id="purchaseNotes" name="notes" rows="3" style="width: 100%; resize: vertical;"
                          placeholder="Any special instructions or terms..."></textarea>
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px;">
                <button type="button" onclick="closeModal('purchaseModal')" class="btn btn-secondary" style="padding: 12px 25px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn" style="padding: 12px 25px;">
                    <i class="fas fa-save"></i> Save Purchase Order
                </button>
                <button type="button" onclick="previewPurchaseSlip()" class="btn btn-success" style="padding: 12px 25px;">
                    <i class="fas fa-eye"></i> Preview Slip
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Purchase Slip Preview Modal -->
<div id="purchaseSlipModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('purchaseSlipModal')">&times;</span>
        <div id="purchaseSlipContent">
            <!-- Purchase slip will be loaded here -->
        </div>
    </div>
</div>

<!-- Receive Products Modal -->
<div id="receiveModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <span class="close-modal" onclick="closeModal('receiveModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px;"><i class="fas fa-box-open"></i> Receive Products</h3>
        <div id="receiveContent">
            <!-- Receiving form will be loaded here -->
        </div>
    </div>
</div>

<!-- View Purchase Order Modal -->
<div id="viewPurchaseModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('viewPurchaseModal')">&times;</span>
        <div id="viewPurchaseContent">
            <!-- Purchase order details will be loaded here -->
        </div>
    </div>
</div>

<!-- Receive Products Modal -->
<div id="receiveModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <span class="close-modal" onclick="closeModal('receiveModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px;"><i class="fas fa-box-open"></i> Receive Products</h3>
        <div id="receiveContent">
            <!-- Receiving form will be loaded here -->
        </div>
    </div>
</div>

<!-- Make Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <span class="close-modal" onclick="closeModal('paymentModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px;"><i class="fas fa-money-bill-wave"></i> Make Payment</h3>
        <div id="paymentContent">
            <!-- Payment form will be loaded here -->
        </div>
    </div>
</div>


<div id="vendorModal" class="modal">
    <div class="modal-content" style="max-width: 700px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('vendorModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-truck"></i> <span id="vendorModalTitle">Add New Vendor</span>
        </h3>
        <form id="vendorForm" onsubmit="saveVendor(event)">
            <input type="hidden" id="vendorId" name="vendor_id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Vendor Name *</label>
                    <input type="text" id="vendorName" name="vendor_name" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Company Name</label>
                    <input type="text" id="companyName" name="company_name" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>GSTIN</label>
                    <input type="text" id="vendorGstin" name="gstin" placeholder="27XXXXXXXXXXXXX" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" id="contactPerson" name="contact_person" style="width: 100%;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea id="vendorAddress" name="address" rows="2" style="width: 100%; resize: vertical;"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>City</label>
                    <input type="text" id="vendorCity" name="city" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>State</label>
                    <select id="vendorState" name="state" style="width: 100%; padding: 10px;">
                        <option value="Gujarat">Gujarat</option>
                        <option value="Maharashtra">Maharashtra</option>
                        <option value="Rajasthan">Rajasthan</option>
                        <option value="Madhya Pradesh">Madhya Pradesh</option>
                        <option value="Delhi">Delhi</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Pincode</label>
                    <input type="text" id="vendorPincode" name="pincode" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Phone *</label>
                    <input type="tel" id="vendorPhone" name="phone" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" id="vendorEmail" name="email" style="width: 100%;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Payment Terms</label>
                    <input type="text" id="paymentTerms" name="payment_terms" placeholder="e.g., 30 days" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Bank Details</label>
                    <textarea id="bankDetails" name="bank_details" rows="2" style="width: 100%; resize: vertical;" 
                              placeholder="Bank Name, Account No, IFSC, etc."></textarea>
                </div>
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px;">
                <button type="button" onclick="closeModal('vendorModal')" class="btn btn-secondary" style="padding: 12px 25px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn" style="padding: 12px 25px;">
                    <i class="fas fa-save"></i> Save Vendor
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Add Purchase Order Modal -->
<div id="purchaseModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('purchaseModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-shopping-cart"></i> <span id="purchaseModalTitle">New Purchase Order</span>
        </h3>
        <form id="purchaseForm" onsubmit="savePurchaseOrder(event)">
            <input type="hidden" id="poId" name="po_id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Vendor *</label>
                    <select id="purchaseVendor" name="vendor_id" required style="width: 100%; padding: 10px;">
                        <option value="">Select Vendor</option>
                        <!-- Vendors will be loaded here -->
                    </select>
                </div>
                <div class="form-group">
                    <label>PO Date *</label>
                    <input type="date" id="poDate" name="po_date" required style="width: 100%;" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Expected Delivery Date</label>
                    <input type="date" id="expectedDate" name="expected_date" style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="poStatus" name="status" style="width: 100%; padding: 10px;">
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="received">Received</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            
            <h4 style="margin: 25px 0 15px 0; color: #2c3e50;"><i class="fas fa-list"></i> Add Products</h4>
            
            <div id="purchaseItems">
                <div class="purchase-item">
                    <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px; align-items: end;">
                        <div class="form-group" style="margin: 0;">
                            <select name="product_id[]" class="purchase-product-select" onchange="updatePurchasePrice(this)" required>
                                <option value="">Select Product</option>
                                <!-- Products will be loaded here -->
                            </select>
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="quantity[]" placeholder="Qty" min="1" value="1" class="purchase-quantity" required onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="purchase_price[]" placeholder="Cost" step="0.01" class="purchase-price" required onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="selling_price[]" placeholder="Selling" step="0.01" class="selling-price" onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="number" name="discount[]" placeholder="Disc %" step="0.01" value="0" class="purchase-discount" onchange="calculatePurchaseRowTotal(this)">
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <input type="text" name="row_total[]" placeholder="Total" class="purchase-row-total" readonly style="background: #e9ecef; font-weight: bold;">
                        </div>
                    </div>
                </div>
            </div>
            
            <button type="button" onclick="addPurchaseItemRow()" class="btn btn-secondary" style="margin-bottom: 30px;">
                <i class="fas fa-plus"></i> Add Product Row
            </button>
            
            <div class="purchase-totals" style="background: #f8f9fa; padding: 25px; border-radius: 10px; margin-top: 30px;">
                <table style="width: 100%; max-width: 400px; margin-left: auto;">
                    <tr>
                        <td>Subtotal:</td>
                        <td id="purchaseSubtotal" style="text-align: right;">₹0.00</td>
                    </tr>
                    <tr>
                        <td>Discount:</td>
                        <td id="purchaseDiscount" style="text-align: right;">₹0.00</td>
                    </tr>
                    <tr>
                        <td>Tax (18%):</td>
                        <td id="purchaseTax" style="text-align: right;">₹0.00</td>
                    </tr>
                    <tr style="font-weight: bold; font-size: 18px;">
                        <td>Total Amount:</td>
                        <td id="purchaseTotal" style="text-align: right;">₹0.00</td>
                    </tr>
                </table>
            </div>
            
            <div class="form-group" style="margin-top: 20px;">
                <label>Notes</label>
                <textarea id="poNotes" name="notes" rows="3" style="width: 100%; resize: vertical;"></textarea>
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px;">
                <button type="button" onclick="closeModal('purchaseModal')" class="btn btn-secondary" style="padding: 12px 25px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn" style="padding: 12px 25px;">
                    <i class="fas fa-save"></i> Save Purchase Order
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Purchase Order Modal -->
<div id="viewPurchaseModal" class="modal">
    <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('viewPurchaseModal')">&times;</span>
        <div id="viewPurchaseContent">
            <!-- Purchase order details will be loaded here -->
        </div>
    </div>
</div>

<!-- Receive Products Modal -->
<div id="receiveModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <span class="close-modal" onclick="closeModal('receiveModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px;"><i class="fas fa-box-open"></i> Receive Products</h3>
        <div id="receiveContent">
            <!-- Receiving form will be loaded here -->
        </div>
    </div>
</div>

<!-- Make Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <span class="close-modal" onclick="closeModal('paymentModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px;"><i class="fas fa-money-bill-wave"></i> Make Payment</h3>
        <div id="paymentContent">
            <!-- Payment form will be loaded here -->
        </div>
    </div>
</div>




<!-- Add Product Modal -->
<div id="productModal" class="modal">
    <div class="modal-content" style="max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <span class="close-modal" onclick="closeModal('productModal')">&times;</span>
        <h3 style="color: #2c3e50; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-box"></i> Add New Product
        </h3>
        <form method="POST" action="" id="productForm">
            <input type="hidden" name="action" value="add_product">
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>HSN Code *</label>
                    <input type="text" name="hsn_code" required style="width: 100%;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" style="width: 100%; resize: vertical;"></textarea>
            </div>
            
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" style="width: 100%; padding: 10px;">
                        <option value="Sarees">Sarees</option>
                        <option value="Suits">Suits</option>
                        <option value="Silk Sarees">Silk Sarees</option>
                        <option value="Lahengas">Lahengas</option>
                        
                    </select>
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <select name="unit" style="width: 100%; padding: 10px;">
                        <option value="Piece">Piece</option>
                        <option value="Meter">Meter</option>
                        <option value="Kg">Kg</option>
                        <option value="Set">Set</option>
                    </select>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Price (₹) *</label>
                    <input type="number" name="price" step="0.01" required style="width: 100%;">
                </div>
                <div class="form-group">
                    <label>GST Rate (%) *</label>
                    <input type="number" name="gst_rate" step="0.01" value="5" required style="width: 100%;">
                </div>
            </div>
            
            <div class="form-group">
                <label>Stock Quantity</label>
                <input type="number" name="stock_quantity" value="0" style="width: 100%;">
            </div>
            
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 25px;">
                <button type="button" onclick="closeModal('productModal')" class="btn btn-secondary" style="padding: 12px 25px;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn" style="padding: 12px 25px;">
                    <i class="fas fa-save"></i> Save Product
                </button>
            </div>
        </form>
    </div>
</div>
    <script>
        // Product management functions
        function addProductRow() {
            const productItems = document.getElementById('productItems');
            const newRow = document.createElement('div');
            newRow.className = 'product-item';
            newRow.innerHTML = `
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px; align-items: end;">
                    <div class="form-group" style="margin: 0;">
                        <select name="product_id[]" class="product-select" onchange="updateProductPrice(this)">
                            <option value="">Select Product</option>
                            <?php 
                            $products_result->data_seek(0);
                            while ($product = $products_result->fetch_assoc()): 
                            ?>
                            <option value="<?php echo $product['product_id']; ?>" data-price="<?php echo $product['price']; ?>" data-hsn="<?php echo $product['hsn_code']; ?>">
                                <?php echo htmlspecialchars($product['product_name']); ?> - ₹<?php echo $product['price']; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <input type="number" name="quantity[]" placeholder="Qty" min="1" value="1" class="quantity" onchange="calculateRowTotal(this)" oninput="calculateRowTotal(this)">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <input type="number" name="price[]" placeholder="Price" step="0.01" class="price" onchange="calculateRowTotal(this)" oninput="calculateRowTotal(this)">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <input type="number" name="item_discount[]" placeholder="Disc %" step="0.01" value="0" class="item-discount" onchange="calculateRowTotal(this)" oninput="calculateRowTotal(this)">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <input type="text" name="gst_rate[]" placeholder="GST %" value="5" class="gst-rate" readonly style="background: #e9ecef;">
                    </div>
                    <div class="form-group" style="margin: 0;">
                        <input type="text" name="row_total[]" placeholder="Total" class="row-total" readonly style="background: #e9ecef; font-weight: bold;">
                    </div>
                </div>
            `;
            productItems.appendChild(newRow);
        }

        function updateProductPrice(select) {
            const row = select.closest('.product-item');
            const priceInput = row.querySelector('.price');
            const selectedOption = select.options[select.selectedIndex];
            const price = selectedOption.getAttribute('data-price') || '';
            priceInput.value = price;
            calculateRowTotal(select);
        }

        function calculateRowTotal(input) {
            const row = input.closest('.product-item');
            const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
            const price = parseFloat(row.querySelector('.price').value) || 0;
            const discountPercent = parseFloat(row.querySelector('.item-discount').value) || 0;
            const gstRate = parseFloat(row.querySelector('.gst-rate').value) || 0;
            const totalInput = row.querySelector('.row-total');
            
            let subtotal = quantity * price;
            let discountAmount = subtotal * (discountPercent / 100);
            let taxableAmount = subtotal - discountAmount;
            let gstAmount = taxableAmount * (gstRate / 100);
            let total = taxableAmount + gstAmount;
            
            totalInput.value = '₹' + total.toFixed(2);
            updateInvoiceTotals();
        }

        function updateInvoiceTotals() {
            let subtotal = 0;
            let totalDiscount = 0;
            const rows = document.querySelectorAll('.product-item');
            
            rows.forEach(row => {
                const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
                const price = parseFloat(row.querySelector('.price').value) || 0;
                const discountPercent = parseFloat(row.querySelector('.item-discount').value) || 0;
                
                const rowSubtotal = quantity * price;
                subtotal += rowSubtotal;
                totalDiscount += rowSubtotal * (discountPercent / 100);
            });
            
            const taxableAmount = subtotal - totalDiscount;
            
            // Get customer state for GST calculation
            const customerSelect = document.getElementById('customer_id');
            const selectedCustomer = customerSelect.options[customerSelect.selectedIndex];
            const customerText = selectedCustomer.text;
            const customerState = customerText.includes('Gujarat') ? 'Gujarat' : 'Other';
            
            let cgst = 0, sgst = 0, igst = 0;
            
            if (customerState === 'Gujarat') {
                cgst = taxableAmount * (<?php echo GST_RATE_CGST; ?> / 100);
                sgst = taxableAmount * (<?php echo GST_RATE_SGST; ?> / 100);
            } else {
                igst = taxableAmount * (<?php echo GST_RATE_IGST; ?> / 100);
            }
            
            const grandTotal = subtotal - totalDiscount + cgst + sgst + igst;
            
            // Update hidden inputs
            document.getElementById('subtotal').value = subtotal.toFixed(2);
            document.getElementById('discount').value = totalDiscount.toFixed(2);
            
            // Update display
            document.getElementById('display_subtotal').textContent = '₹' + subtotal.toFixed(2);
            document.getElementById('display_discount').textContent = '₹' + totalDiscount.toFixed(2);
            document.getElementById('display_cgst').textContent = '₹' + cgst.toFixed(2);
            document.getElementById('display_sgst').textContent = '₹' + sgst.toFixed(2);
            document.getElementById('display_igst').textContent = '₹' + igst.toFixed(2);
            document.getElementById('display_grand_total').textContent = '₹' + grandTotal.toFixed(2);
        }

        function previewInvoice() {
            // Get form data
            const formData = new FormData(document.getElementById('invoiceForm'));
            
            // Create preview content
            let previewHTML = `
                <div class="invoice-container">
                    <div class="invoice-header">
                        <div class="company-info">
                            <h2><?php echo COMPANY_NAME; ?></h2>
                            <p><?php echo COMPANY_ADDRESS; ?></p>
                            <p>GSTIN: <?php echo COMPANY_GSTIN; ?></p>
                            <p>Phone: <?php echo COMPANY_PHONE; ?> | Email: <?php echo COMPANY_EMAIL; ?></p>
                        </div>
                        <div class="invoice-meta">
                            <h3>TAX INVOICE</h3>
                            <p><strong>Invoice #:</strong> TXT${new Date().getFullYear()}${(new Date().getMonth()+1).toString().padStart(2,'0')}XXXX</p>
                            <p><strong>Date:</strong> ${document.getElementById('invoice_date').value}</p>
                        </div>
                    </div>
                    
                    <div style="margin: 30px 0;">
                        <h4>Bill To:</h4>
                        <p>${document.getElementById('customer_id').options[document.getElementById('customer_id').selectedIndex].text}</p>
                    </div>
                    
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product Description</th>
                                <th>HSN Code</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Discount</th>
                                <th>GST %</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>`;
            
            // Add product rows
            const productItems = document.querySelectorAll('.product-item');
            productItems.forEach((item, index) => {
                const productSelect = item.querySelector('.product-select');
                const productName = productSelect.options[productSelect.selectedIndex].text.split(' - ')[0] || '';
                const hsn = productSelect.options[productSelect.selectedIndex].getAttribute('data-hsn') || '';
                const quantity = item.querySelector('.quantity').value || '0';
                const price = item.querySelector('.price').value || '0';
                const discount = item.querySelector('.item-discount').value || '0';
                const gst = item.querySelector('.gst-rate').value || '0';
                const total = item.querySelector('.row-total').value || '₹0.00';
                
                if (productName) {
                    previewHTML += `
                        <tr>
                            <td>${index + 1}</td>
                            <td>${productName}</td>
                            <td>${hsn}</td>
                            <td>${quantity}</td>
                            <td>₹${parseFloat(price).toFixed(2)}</td>
                            <td>${discount}%</td>
                            <td>${gst}%</td>
                            <td>${total}</td>
                        </tr>`;
                }
            });
            
            previewHTML += `
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 40px;">
                        <table class="totals-table">
                            <tr>
                                <td>Subtotal:</td>
                                <td style="text-align: right;">${document.getElementById('display_subtotal').textContent}</td>
                            </tr>
                            <tr>
                                <td>Discount:</td>
                                <td style="text-align: right;">${document.getElementById('display_discount').textContent}</td>
                            </tr>
                            <tr>
                                <td>CGST (<?php echo GST_RATE_CGST; ?>%):</td>
                                <td style="text-align: right;">${document.getElementById('display_cgst').textContent}</td>
                            </tr>
                            <tr>
                                <td>SGST (<?php echo GST_RATE_SGST; ?>%):</td>
                                <td style="text-align: right;">${document.getElementById('display_sgst').textContent}</td>
                            </tr>
                            <tr>
                                <td>IGST (<?php echo GST_RATE_IGST; ?>%):</td>
                                <td style="text-align: right;">${document.getElementById('display_igst').textContent}</td>
                            </tr>
                            <tr class="total-row">
                                <td>Grand Total:</td>
                                <td style="text-align: right;">${document.getElementById('display_grand_total').textContent}</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div style="margin-top: 60px; text-align: center;">
                        <p>Thank you for your business!</p>
                        <p style="margin-top: 40px;">
                            <strong>Authorized Signatory</strong><br>
                            <?php echo COMPANY_NAME; ?>
                        </p>
                    </div>
                </div>
                
                <div style="margin-top: 30px; text-align: center;" class="no-print">
                    <button onclick="window.print()" class="btn btn-print">
                        <i class="fas fa-print"></i> Print Invoice
                    </button>
                    <button onclick="closeModal('invoicePreviewModal')" class="btn btn-secondary" style="margin-left: 15px;">
                     <i class="fas fa-times"></i> Close
                    </button>
                </div>`;
            
            document.getElementById('invoicePreviewContent').innerHTML = previewHTML;
            openModal('invoicePreviewModal');
        }

        function openSavedInvoice(invoiceId) {
            // Load saved invoice via AJAX
            const xhr = new XMLHttpRequest();
            xhr.open('GET', `get_invoice.php?id=${invoiceId}`, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    document.getElementById('savedInvoiceContent').innerHTML = xhr.responseText;
                    openModal('savedInvoiceModal');
                }
            };
            xhr.send();
        }

        function printSavedInvoice(invoiceId) {
            // Open print page for saved invoice
            window.open(`print_invoice.php?id=${invoiceId}`, '_blank');
        }

        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                document.getElementById('invoiceForm').reset();
                const productItems = document.getElementById('productItems');
                // Keep only first row
                while (productItems.children.length > 1) {
                    productItems.removeChild(productItems.lastChild);
                }
                updateInvoiceTotals();
            }
        }

       function openModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    document.body.style.paddingRight = '8px'; // Prevent layout shift
    
    // Focus first input if available
    setTimeout(() => {
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput) firstInput.focus();
    }, 100);
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    document.body.style.paddingRight = '0';
}

// Close modal with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            if (modal.style.display === 'block') {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                document.body.style.paddingRight = '0';
            }
        });
    }
});

// Prevent form submission from closing modal
document.addEventListener('DOMContentLoaded', function() {
    // Handle customer form submission
    const customerForm = document.getElementById('customerForm');
    if (customerForm) {
        customerForm.addEventListener('submit', function(e) {
            // Form will submit and page will reload, no need for AJAX
        });
    }
    
    // Handle product form submission
    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function(e) {
            // Form will submit and page will reload
        });
    }
});

// Products Management Variables
let allProducts = [];
let filteredProducts = [];
let currentPage = 1;
let itemsPerPage = 15;
let currentSortColumn = 'product_id';
let currentSortDirection = 'asc';

// Load all products
function loadAllProducts() {
    console.log('Loading products...');
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'get_products.php', true);
    xhr.onload = function() {
        console.log('Response received:', xhr.status);
        if (xhr.status === 200) {
            try {
                allProducts = JSON.parse(xhr.responseText);
                console.log('Products loaded:', allProducts.length);
                filteredProducts = [...allProducts];
                updateProductsStats();
                renderProductsTable();
                updatePagination();
            } catch (e) {
                console.error('Error parsing JSON:', e);
                alert('Error loading products. Invalid data format.');
            }
        } else {
            alert('Error loading products. Please try again.');
        }
    };
    xhr.onerror = function() {
        console.error('XHR error');
        alert('Error loading products. Check console for details.');
    };
    xhr.send();
}

// Show products page
function showProductsPage() {
    console.log('Showing products page...');
    // Hide other sections
    const sections = document.querySelectorAll('.card');
    sections.forEach(section => {
        if (section.id !== 'products-page') {
            section.style.display = 'none';
        }
    });
    
    // Hide dashboard grid
    const dashboardGrid = document.querySelector('.dashboard-grid');
    if (dashboardGrid) {
        dashboardGrid.style.display = 'none';
    }
    
    // Show products page
    const productsPage = document.querySelector('#products-page');
    if (productsPage) {
        productsPage.style.display = 'block';
        console.log('Products page shown');
    }
    
    // Update active nav
    document.querySelectorAll('nav a').forEach(a => a.classList.remove('active'));
    const productsLink = document.querySelector('a[href="#products-page"]');
    if (productsLink) {
        productsLink.classList.add('active');
    }
    
    // Load products if not already loaded
    if (allProducts.length === 0) {
        loadAllProducts();
    } else {
        renderProductsTable();
        updatePagination();
    }
}

// Update products statistics
function updateProductsStats() {
    const totalProducts = allProducts.length;
    let totalStockValue = 0;
    let lowStockCount = 0;
    
    allProducts.forEach(product => {
        const stockValue = parseFloat(product.stock_quantity) * parseFloat(product.price);
        totalStockValue += stockValue;
        
        if (parseFloat(product.stock_quantity) < 10 && parseFloat(product.stock_quantity) > 0) {
            lowStockCount++;
        }
    });
    
    const totalProductsEl = document.getElementById('totalProductsCount');
    const totalStockValueEl = document.getElementById('totalStockValue');
    const lowStockCountEl = document.getElementById('lowStockCount');
    
    if (totalProductsEl) totalProductsEl.textContent = totalProducts;
    if (totalStockValueEl) totalStockValueEl.textContent = '₹' + totalStockValue.toFixed(2);
    if (lowStockCountEl) lowStockCountEl.textContent = lowStockCount;
}

// Render products table
function renderProductsTable() {
    const tbody = document.getElementById('productsTableBody');
    if (!tbody) {
        console.error('Products table body not found!');
        return;
    }
    
    tbody.innerHTML = '';
    
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const pageProducts = filteredProducts.slice(startIndex, endIndex);
    
    if (pageProducts.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="11" style="text-align: center; padding: 40px; color: #6c757d;">
                    <i class="fas fa-box-open" style="font-size: 48px; margin-bottom: 20px; display: block;"></i>
                    <h4>No products found</h4>
                    <p>Try adjusting your search or filters</p>
                </td>
            </tr>
        `;
        return;
    }
    
    pageProducts.forEach((product, index) => {
        const stockQuantity = parseFloat(product.stock_quantity);
        const price = parseFloat(product.price);
        const stockValue = stockQuantity * price;
        
        let stockClass = 'stock-good';
        let stockText = 'In Stock';
        
        if (stockQuantity === 0) {
            stockClass = 'stock-out';
            stockText = 'Out of Stock';
        } else if (stockQuantity < 10) {
            stockClass = 'stock-low';
            stockText = 'Low Stock';
        }
        
        const row = `
            <tr>
                <td>${startIndex + index + 1}</td>
                <td>
                    <strong>${escapeHtml(product.product_name)}</strong>
                    ${product.description ? '<br><small style="color: #666;">' + escapeHtml(product.description.substring(0, 50)) + (product.description.length > 50 ? '...' : '') + '</small>' : ''}
                </td>
                <td>${product.hsn_code}</td>
                <td>${product.category}</td>
                <td style="font-weight: 600; color: ${stockQuantity === 0 ? '#dc3545' : (stockQuantity < 10 ? '#ffc107' : '#28a745')}">
                    ${stockQuantity}
                </td>
                <td>${product.unit}</td>
                <td style="font-weight: 600;">₹${price.toFixed(2)}</td>
                <td>${product.gst_rate}%</td>
                <td style="font-weight: 600; color: #2c3e50;">₹${stockValue.toFixed(2)}</td>
                <td><span class="stock-indicator ${stockClass}">${stockText}</span></td>
                <td>
                    <div class="action-buttons">
                        <button onclick="editProduct(${product.product_id})" class="action-btn edit-btn" title="Edit Product">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteProduct(${product.product_id})" class="action-btn delete-btn" title="Delete Product">
                            <i class="fas fa-trash"></i>
                        </button>
                        <button onclick="viewProductDetails(${product.product_id})" class="action-btn view-btn" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        tbody.innerHTML += row;
    });
    
    // Update products info
    const showingCount = Math.min(filteredProducts.length, endIndex);
    const productsInfoEl = document.getElementById('productsInfo');
    if (productsInfoEl) {
        productsInfoEl.textContent = 
            `Showing ${startIndex + 1} to ${showingCount} of ${filteredProducts.length} products`;
    }
}

// Sort products
function sortProducts(column) {
    if (currentSortColumn === column) {
        currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        currentSortColumn = column;
        currentSortDirection = 'asc';
    }
    
    filteredProducts.sort((a, b) => {
        let aValue = a[column];
        let bValue = b[column];
        
        // Handle numeric sorting for numbers
        if (column === 'stock_quantity' || column === 'price' || column === 'gst_rate') {
            aValue = parseFloat(aValue);
            bValue = parseFloat(bValue);
        }
        
        if (aValue < bValue) {
            return currentSortDirection === 'asc' ? -1 : 1;
        }
        if (aValue > bValue) {
            return currentSortDirection === 'asc' ? 1 : -1;
        }
        return 0;
    });
    
    currentPage = 1;
    renderProductsTable();
    updatePagination();
}

// Filter products
function filterProducts() {
    const categoryFilter = document.getElementById('categoryFilter').value;
    const stockFilter = document.getElementById('stockFilter').value;
    
    filteredProducts = allProducts.filter(product => {
        let match = true;
        
        // Category filter
        if (categoryFilter && product.category !== categoryFilter) {
            match = false;
        }
        
        // Stock filter
        const stockQty = parseFloat(product.stock_quantity);
        if (stockFilter === 'low' && (stockQty >= 10 || stockQty === 0)) {
            match = false;
        } else if (stockFilter === 'out' && stockQty > 0) {
            match = false;
        } else if (stockFilter === 'available' && stockQty === 0) {
            match = false;
        }
        
        return match;
    });
    
    currentPage = 1;
    renderProductsTable();
    updatePagination();
}

// Search products
function searchProducts() {
    const searchInput = document.getElementById('productSearch');
    if (!searchInput) return;
    
    const searchTerm = searchInput.value.toLowerCase();
    
    if (!searchTerm) {
        filteredProducts = [...allProducts];
    } else {
        filteredProducts = allProducts.filter(product => {
            return (
                (product.product_name && product.product_name.toLowerCase().includes(searchTerm)) ||
                (product.description && product.description.toLowerCase().includes(searchTerm)) ||
                (product.hsn_code && product.hsn_code.toLowerCase().includes(searchTerm)) ||
                (product.category && product.category.toLowerCase().includes(searchTerm))
            );
        });
    }
    
    currentPage = 1;
    renderProductsTable();
    updatePagination();
}

// Pagination functions
function updatePagination() {
    const totalPages = Math.ceil(filteredProducts.length / itemsPerPage);
    const pageInfoEl = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    
    if (pageInfoEl) {
        pageInfoEl.textContent = `Page ${currentPage} of ${totalPages}`;
    }
    
    if (prevBtn) {
        prevBtn.disabled = currentPage === 1;
    }
    
    if (nextBtn) {
        nextBtn.disabled = currentPage === totalPages || totalPages === 0;
    }
}

function previousPage() {
    if (currentPage > 1) {
        currentPage--;
        renderProductsTable();
        updatePagination();
    }
}

function nextPage() {
    const totalPages = Math.ceil(filteredProducts.length / itemsPerPage);
    if (currentPage < totalPages) {
        currentPage++;
        renderProductsTable();
        updatePagination();
    }
}

// Edit product
function editProduct(productId) {
    const product = allProducts.find(p => p.product_id == productId);
    if (product) {
        document.getElementById('editProductId').value = product.product_id;
        document.getElementById('editProductName').value = product.product_name;
        document.getElementById('editHsnCode').value = product.hsn_code;
        document.getElementById('editDescription').value = product.description || '';
        document.getElementById('editCategory').value = product.category;
        document.getElementById('editUnit').value = product.unit;
        document.getElementById('editPrice').value = product.price;
        document.getElementById('editGstRate').value = product.gst_rate;
        document.getElementById('editStockQuantity').value = product.stock_quantity;
        
        openModal('editProductModal');
    }
}

// Update product
function updateProduct(event) {
    event.preventDefault();
    
    const formData = new FormData(document.getElementById('editProductForm'));
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'update_product.php', true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    alert('Product updated successfully!');
                    closeModal('editProductModal');
                    loadAllProducts();
                } else {
                    alert('Error updating product: ' + response.message);
                }
            } catch (e) {
                alert('Error parsing response. Please try again.');
            }
        } else {
            alert('Error updating product. Please try again.');
        }
    };
    xhr.send(formData);
}

// Delete product
function deleteProduct(productId) {
    if (confirm('Are you sure you want to delete this product? This action cannot be undone.')) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'delete_product.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        alert('Product deleted successfully!');
                        loadAllProducts();
                    } else {
                        alert('Error deleting product: ' + response.message);
                    }
                } catch (e) {
                    alert('Error parsing response. Please try again.');
                }
            } else {
                alert('Error deleting product. Please try again.');
            }
        };
        xhr.send('product_id=' + productId);
    }
}

// View product details
function viewProductDetails(productId) {
    const product = allProducts.find(p => p.product_id == productId);
    if (product) {
        const stockQuantity = parseFloat(product.stock_quantity);
        const price = parseFloat(product.price);
        const stockValue = stockQuantity * price;
        
        const details = `
            <div style="padding: 20px;">
                <h3 style="color: #2c3e50; margin-bottom: 20px;">Product Details</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <p><strong>Product Name:</strong> ${escapeHtml(product.product_name)}</p>
                        <p><strong>HSN Code:</strong> ${product.hsn_code}</p>
                        <p><strong>Category:</strong> ${product.category}</p>
                        <p><strong>Unit:</strong> ${product.unit}</p>
                        <p><strong>Created:</strong> ${new Date(product.created_at).toLocaleDateString()}</p>
                    </div>
                    <div>
                        <p><strong>Price:</strong> ₹${price.toFixed(2)}</p>
                        <p><strong>GST Rate:</strong> ${product.gst_rate}%</p>
                        <p><strong>Stock Quantity:</strong> ${stockQuantity}</p>
                        <p><strong>Stock Value:</strong> ₹${stockValue.toFixed(2)}</p>
                        <p><strong>Status:</strong> <span class="stock-indicator ${stockQuantity === 0 ? 'stock-out' : (stockQuantity < 10 ? 'stock-low' : 'stock-good')}">
                            ${stockQuantity === 0 ? 'Out of Stock' : (stockQuantity < 10 ? 'Low Stock' : 'In Stock')}
                        </span></p>
                    </div>
                </div>
                ${product.description ? `<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <strong>Description:</strong><br>${escapeHtml(product.description)}
                </div>` : ''}
                <div style="margin-top: 30px; text-align: center;">
                    <button onclick="closeModal('viewProductModal')" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        `;
        
        // Create modal for viewing
        const existingModal = document.getElementById('viewProductModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        const modal = document.createElement('div');
        modal.id = 'viewProductModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 700px;">
                <span class="close-modal" onclick="closeModal('viewProductModal')">&times;</span>
                ${details}
            </div>
        `;
        
        document.body.appendChild(modal);
        openModal('viewProductModal');
    }
}




// Print products table
function printProductsTable() {
    const printWindow = window.open('', '_blank');
    const table = document.getElementById('productsTable');
    if (!table) {
        alert('Products table not found!');
        return;
    }
    
    const tableHtml = table.outerHTML;
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${COMPANY_NAME} - Products Inventory</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #2c3e50; text-align: center; margin-bottom: 20px; }
                .print-header { text-align: center; margin-bottom: 30px; }
                .print-header p { margin: 5px 0; color: #666; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background: #2c3e50; color: white; padding: 12px; text-align: left; }
                td { padding: 10px; border: 1px solid #ddd; }
                tr:nth-child(even) { background: #f8f9fa; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #ddd; padding-top: 20px; }
                @media print {
                    body { margin: 0; padding: 10px; }
                    table { font-size: 12px; }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                <h1>${COMPANY_NAME}</h1>
                <p>Products Inventory Report</p>
                <p>Printed on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</p>
            </div>
            ${tableHtml}
            <div class="footer">
                <p>Total Products: ${allProducts.length} | Total Stock Value: ₹${calculateTotalStockValue()}</p>
                <p>Generated by SPC Textiles GST Billing System</p>
            </div>
            <script>
                window.onload = function() { 
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 500);
                }
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

// Export to Excel
function exportProductsToExcel() {
    if (filteredProducts.length === 0) {
        alert('No products to export!');
        return;
    }
    
    const data = filteredProducts.map(product => ({
        'Product Name': product.product_name,
        'HSN Code': product.hsn_code,
        'Category': product.category,
        'Stock Quantity': product.stock_quantity,
        'Unit': product.unit,
        'Price': parseFloat(product.price).toFixed(2),
        'GST Rate': product.gst_rate + '%',
        'Stock Value': (parseFloat(product.stock_quantity) * parseFloat(product.price)).toFixed(2)
    }));
    
    // Create CSV content
    let csvContent = "data:text/csv;charset=utf-8,";
    
    // Add headers
    const headers = Object.keys(data[0]);
    csvContent += headers.join(",") + "\n";
    
    // Add data rows
    data.forEach(row => {
        csvContent += headers.map(header => row[header]).join(",") + "\n";
    });
    
    // Create download link
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `products_inventory_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Calculate total stock value
function calculateTotalStockValue() {
    return allProducts.reduce((total, product) => {
        return total + (parseFloat(product.stock_quantity) * parseFloat(product.price));
    }, 0).toFixed(2);
}

// Utility function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Navigation handler
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, setting up navigation...');
    
    // Handle navigation to products page
    const productsPageLink = document.querySelector('a[href="#products-page"]');
    if (productsPageLink) {
        console.log('Found products page link');
        productsPageLink.addEventListener('click', function(e) {
            e.preventDefault();
            showProductsPage();
        });
    } else {
        console.error('Products page link not found!');
    }
    
    // Handle dashboard link
    const dashboardLink = document.querySelector('a[href="#dashboard"]');
    if (dashboardLink) {
        dashboardLink.addEventListener('click', function(e) {
            e.preventDefault();
            // Show dashboard
            document.querySelector('#products-page').style.display = 'none';
            document.querySelector('#new-invoice').style.display = 'none';
            document.querySelector('.dashboard-grid').style.display = 'grid';
            
            // Update active nav
            document.querySelectorAll('nav a').forEach(a => a.classList.remove('active'));
            this.classList.add('active');
        });
    }
    
    // Handle new invoice link
    const invoiceLink = document.querySelector('a[href="#new-invoice"]');
    if (invoiceLink) {
        invoiceLink.addEventListener('click', function(e) {
            e.preventDefault();
            // Show invoice form
            document.querySelector('#products-page').style.display = 'none';
            document.querySelector('.dashboard-grid').style.display = 'none';
            document.querySelector('#new-invoice').style.display = 'block';
            
            // Update active nav
            document.querySelectorAll('nav a').forEach(a => a.classList.remove('active'));
            this.classList.add('active');
        });
    }
    
    // Initialize products page on load if URL has hash
    if (window.location.hash === '#products-page') {
        setTimeout(() => {
            showProductsPage();
        }, 100);
    }
});



    </script>
    
    
    
    <script>
     // Print products table - ALL PRODUCTS
function printProductsTable() {
    console.log('Printing all products...');
    
    // Use allProducts (not filteredProducts) to get ALL products
    const productsToPrint = allProducts;
    
    if (productsToPrint.length === 0) {
        alert('No products to print!');
        return;
    }
    
    const printWindow = window.open('', '_blank');
    
    // Calculate totals
    const totalProducts = productsToPrint.length;
    let totalStockValue = 0;
    let totalStockQuantity = 0;
    let lowStockCount = 0;
    let outOfStockCount = 0;
    
    // Create table HTML
    let tableHtml = `
        <table style="width: 100%; border-collapse: collapse; margin-top: 20px; font-family: Arial, sans-serif;">
            <thead>
                <tr style="background: #2c3e50; color: white;">
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">#</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Product Name</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">HSN Code</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Category</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Stock Qty</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Unit</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Price</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">GST %</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Stock Value</th>
                    <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Status</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    // Add each product row
    productsToPrint.forEach((product, index) => {
        const stockQuantity = parseFloat(product.stock_quantity);
        const price = parseFloat(product.price);
        const stockValue = stockQuantity * price;
        
        // Update totals
        totalStockValue += stockValue;
        totalStockQuantity += stockQuantity;
        
        // Update status counts
        if (stockQuantity === 0) {
            outOfStockCount++;
        } else if (stockQuantity < 10) {
            lowStockCount++;
        }
        
        let statusText = '';
        let statusColor = '';
        
        if (stockQuantity === 0) {
            statusText = 'Out of Stock';
            statusColor = '#dc3545';
        } else if (stockQuantity < 10) {
            statusText = 'Low Stock';
            statusColor = '#ffc107';
        } else {
            statusText = 'In Stock';
            statusColor = '#28a745';
        }
        
        tableHtml += `
            <tr style="${index % 2 === 0 ? 'background: #f8f9fa;' : ''}">
                <td style="padding: 10px; border: 1px solid #ddd;">${index + 1}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>${escapeHtml(product.product_name)}</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">${product.hsn_code}</td>
                <td style="padding: 10px; border: 1px solid #ddd;">${product.category}</td>
                <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold; color: ${stockQuantity === 0 ? '#dc3545' : (stockQuantity < 10 ? '#ffc107' : '#28a745')}">
                    ${stockQuantity}
                </td>
                <td style="padding: 10px; border: 1px solid #ddd;">${product.unit}</td>
                <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">₹${price.toFixed(2)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;">${product.gst_rate}%</td>
                <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">₹${stockValue.toFixed(2)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;">
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; background: ${statusColor}; color: ${stockQuantity < 10 ? '#212529' : 'white'}">
                        ${statusText}
                    </span>
                </td>
            </tr>
        `;
    });
    
    tableHtml += `
            </tbody>
        </table>
    `;
    
    // Create summary section
    const summaryHtml = `
        <div style="margin: 30px 0; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e1e5eb;">
            <h3 style="color: #2c3e50; margin-bottom: 15px;">Summary</h3>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                <div style="text-align: center; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e1e5eb;">
                    <div style="font-size: 12px; color: #6c757d;">Total Products</div>
                    <div style="font-size: 24px; font-weight: bold; color: #2c3e50;">${totalProducts}</div>
                </div>
                <div style="text-align: center; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e1e5eb;">
                    <div style="font-size: 12px; color: #6c757d;">Total Stock Value</div>
                    <div style="font-size: 24px; font-weight: bold; color: #2c3e50;">₹${totalStockValue.toFixed(2)}</div>
                </div>
                <div style="text-align: center; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e1e5eb;">
                    <div style="font-size: 12px; color: #6c757d;">Low Stock Items</div>
                    <div style="font-size: 24px; font-weight: bold; color: #ffc107;">${lowStockCount}</div>
                </div>
                <div style="text-align: center; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e1e5eb;">
                    <div style="font-size: 12px; color: #6c757d;">Out of Stock</div>
                    <div style="font-size: 24px; font-weight: bold; color: #dc3545;">${outOfStockCount}</div>
                </div>
            </div>
        </div>
    `;
    
    // Complete HTML
    const completeHtml = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>${COMPANY_NAME} - Complete Products Inventory</title>
            <style>
                @media print {
                    @page {
                        size: A4 portrait;
                        margin: 0.5in;
                    }
                    
                    body {
                        margin: 0;
                        padding: 0;
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                        font-size: 12px;
                    }
                    
                    .header {
                        text-align: center;
                        margin-bottom: 30px;
                        padding-bottom: 20px;
                        border-bottom: 2px solid #2c3e50;
                    }
                    
                    .company-name {
                        font-size: 28px;
                        font-weight: bold;
                        color: #2c3e50;
                        margin-bottom: 5px;
                    }
                    
                    .report-title {
                        font-size: 20px;
                        color: #4a6491;
                        margin-bottom: 10px;
                    }
                    
                    .report-date {
                        color: #6c757d;
                        font-size: 14px;
                    }
                    
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        page-break-inside: auto;
                    }
                    
                    tr {
                        page-break-inside: avoid;
                        page-break-after: auto;
                    }
                    
                    thead {
                        display: table-header-group;
                    }
                    
                    tfoot {
                        display: table-footer-group;
                    }
                    
                    .summary {
                        page-break-inside: avoid;
                    }
                    
                    .footer {
                        position: fixed;
                        bottom: 0;
                        width: 100%;
                        text-align: center;
                        font-size: 10px;
                        color: #6c757d;
                        padding: 10px 0;
                        border-top: 1px solid #e1e5eb;
                    }
                }
                
                @media screen {
                    body {
                        margin: 20px;
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                        background: #f5f5f5;
                    }
                    
                    .print-container {
                        max-width: 210mm;
                        margin: 0 auto;
                        background: white;
                        padding: 20mm;
                        box-shadow: 0 0 20px rgba(0,0,0,0.1);
                    }
                    
                    .header {
                        text-align: center;
                        margin-bottom: 30px;
                        padding-bottom: 20px;
                        border-bottom: 2px solid #2c3e50;
                    }
                    
                    .company-name {
                        font-size: 32px;
                        font-weight: bold;
                        color: #2c3e50;
                        margin-bottom: 5px;
                    }
                    
                    .report-title {
                        font-size: 24px;
                        color: #4a6491;
                        margin-bottom: 10px;
                    }
                    
                    .report-date {
                        color: #6c757d;
                        font-size: 16px;
                    }
                    
                    .footer {
                        margin-top: 40px;
                        padding-top: 20px;
                        border-top: 1px solid #e1e5eb;
                        text-align: center;
                        font-size: 12px;
                        color: #6c757d;
                    }
                    
                    .print-controls {
                        position: fixed;
                        bottom: 20px;
                        right: 20px;
                        background: white;
                        padding: 15px;
                        border-radius: 10px;
                        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                        z-index: 1000;
                    }
                    
                    .print-btn {
                        background: #28a745;
                        color: white;
                        border: none;
                        padding: 12px 25px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-weight: bold;
                        display: flex;
                        align-items: center;
                        gap: 10px;
                    }
                    
                    .print-btn:hover {
                        background: #218838;
                    }
                }
            </style>
        </head>
        <body>
            <div class="print-container">
                <div class="header">
                    <div class="company-name">${COMPANY_NAME}</div>
                    <div class="report-title">COMPLETE PRODUCTS INVENTORY REPORT</div>
                    <div class="report-date">Generated on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</div>
                </div>
                
                ${summaryHtml}
                
                ${tableHtml}
                
                <div class="footer">
                    <p>This report shows all ${totalProducts} products in inventory</p>
                    <p>Total Stock Quantity: ${totalStockQuantity} | Total Stock Value: ₹${totalStockValue.toFixed(2)}</p>
                    <p>Generated by SPC Textiles GST Billing System</p>
                </div>
            </div>
            
            <div class="print-controls" style="position: fixed; bottom: 20px; right: 20px;">
                <button onclick="window.print()" class="print-btn">
                    <i class="fas fa-print"></i> Print Now
                </button>
            </div>
            
            <script>
                window.onload = function() {
                    // Auto-print after a short delay
                    setTimeout(function() {
                        window.print();
                    }, 1000);
                    
                    // Close window after print (optional)
                    window.onafterprint = function() {
                        setTimeout(function() {
                            window.close();
                        }, 500);
                    };
                };
                
                // Load Font Awesome for icons
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
                document.head.appendChild(link);
            <\/script>
        </body>
        </html>
    `;
    
    printWindow.document.write(completeHtml);
    printWindow.document.close();
}

    </script>
    
    
    <script>
       // Purchase Module Variables
let allPurchases = [];
let filteredPurchases = [];
let currentPurchasePage = 1;
let purchaseItemsPerPage = 10;
let purchaseItemCounter = 1;

// Load purchase orders
function loadPurchaseOrders() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'get_purchases.php', true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                allPurchases = JSON.parse(xhr.responseText);
                console.log('Purchases loaded:', allPurchases.length);
                filteredPurchases = [...allPurchases];
                updatePurchaseStats();
                renderPurchasesTable();
                updatePurchasePagination();
            } catch (e) {
                console.error('Error parsing purchases:', e);
                alert('Error loading purchase orders.');
            }
        } else {
            alert('Error loading purchase orders. Please try again.');
        }
    };
    xhr.send();
}

// Update purchase statistics
function updatePurchaseStats() {
    const totalPurchases = allPurchases.length;
    const totalValue = allPurchases.reduce((sum, purchase) => sum + parseFloat(purchase.total_amount || 0), 0);
    
    document.getElementById('totalPurchases').textContent = totalPurchases;
    document.getElementById('totalPurchaseValue').textContent = '₹' + totalValue.toFixed(2);
}

// Render purchases table
function renderPurchasesTable() {
    const tbody = document.getElementById('purchasesTableBody');
    if (!tbody) return;
    
    const startIndex = (currentPurchasePage - 1) * purchaseItemsPerPage;
    const endIndex = startIndex + purchaseItemsPerPage;
    const pageItems = filteredPurchases.slice(startIndex, endIndex);
    
    tbody.innerHTML = '';
    
    if (pageItems.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 40px; color: #6c757d;">
                    <i class="fas fa-shopping-cart" style="font-size: 48px; margin-bottom: 20px; display: block;"></i>
                    <h4>No purchase orders found</h4>
                    <p>Click "New Purchase Order" to get started</p>
                </td>
            </tr>
        `;
        document.getElementById('purchasesInfo').textContent = 'No purchase orders found';
        return;
    }
    
    pageItems.forEach((purchase, index) => {
        const statusClass = purchase.status === 'received' ? 'status-received' : 
                          purchase.status === 'cancelled' ? 'status-cancelled' : 'status-pending';
        
        const paymentClass = purchase.payment_status === 'paid' ? 'payment-paid' :
                           purchase.payment_status === 'partial' ? 'payment-partial' : 'payment-pending';
        
        const row = `
            <tr>
                <td><strong>PO-${purchase.purchase_id.toString().padStart(5, '0')}</strong></td>
                <td>${purchase.purchase_date}</td>
                <td>
                    <strong>${escapeHtml(purchase.vendor_name)}</strong>
                    ${purchase.company_name ? '<br><small>' + escapeHtml(purchase.company_name) + '</small>' : ''}
                </td>
                <td>${purchase.total_items || 0} items</td>
                <td style="font-weight: 600; color: #2c3e50;">₹${parseFloat(purchase.total_amount).toFixed(2)}</td>
                <td><span class="status-badge ${statusClass}">${purchase.status}</span></td>
                <td><span class="status-badge ${paymentClass}">${purchase.payment_status}</span></td>
                <td>
                    <div class="action-buttons">
                        <button onclick="viewPurchase(${purchase.purchase_id})" class="action-btn view-btn" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button onclick="receivePurchase(${purchase.purchase_id})" class="action-btn receive-btn" 
                                ${purchase.status === 'received' ? 'disabled' : ''} title="Receive Products">
                            <i class="fas fa-box-open"></i>
                        </button>
                        <button onclick="printPurchaseSlip(${purchase.purchase_id})" class="action-btn print-btn" title="Print Slip">
                            <i class="fas fa-print"></i>
                        </button>
                        <button onclick="deletePurchase(${purchase.purchase_id})" class="action-btn delete-btn" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
        tbody.innerHTML += row;
    });
    
    document.getElementById('purchasesInfo').textContent = 
        `Showing ${startIndex + 1} to ${Math.min(endIndex, filteredPurchases.length)} of ${filteredPurchases.length} purchases`;
}

// Open purchase modal
function openPurchaseModal() {
    document.getElementById('purchaseForm').reset();
    document.getElementById('purchaseId').value = '';
    document.getElementById('purchaseModalTitle').textContent = 'New Purchase Order';
    document.getElementById('vendorInfo').style.display = 'none';
    
    // Reset purchase items to one row
    const purchaseItems = document.getElementById('purchaseItems');
    purchaseItems.innerHTML = `
        <div class="purchase-item">
            <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 1fr; gap: 10px; margin-bottom: 15px; align-items: end;">
                <div class="form-group" style="margin: 0;">
                    <select name="product_id[]" class="purchase-product-select" onchange="updatePurchaseProductDetails(this)" required>
                        <option value="">Select Product</option>
                        <!-- Products will be loaded dynamically -->
                    </select>
                </div>
                <div class="form-group" style="margin: 0;">
                    <input type="number" name="quantity[]" placeholder="Qty" min="1" value="1" 
                           class="purchase-quantity" required onchange="calculatePurchaseRowTotal(this)">
                </div>
                <div class="form-group" style="margin: 0;">
                    <input type="number" name="purchase_price[]" placeholder="Cost Price" step="0.01" 
                           class="purchase-price" required onchange="calculatePurchaseRowTotal(this)">
                </div>
                <div class="form-group" style="margin: 0;">
                    <input type="number" name="selling_price[]" placeholder="Selling Price" step="0.01" 
                           class="selling-price" required onchange="calculatePurchaseRowTotal(this)">
                </div>
                <div class="form-group" style="margin: 0;">
                    <input type="number" name="discount[]" placeholder="Disc %" step="0.01" value="0" 
                           class="purchase-discount" onchange="calculatePurchaseRowTotal(this)">
                </div>
                <div class="form-group" style="margin: 0;">
                    <input type="number" name="gst_rate[]" placeholder="GST %" step="0.01" value="5" 
                           class="purchase-gst-rate" onchange="calculatePurchaseRowTotal(this)">
                </div>
                <div class="form-group" style="margin: 0;">
                    <input type="text" name="row_total[]" placeholder="Total" class="purchase-row-total" 
                           readonly style="background: #e9ecef; font-weight: bold;">
                </div>
            </div>
            <div id="productInfo_0" style="display: none; font-size: 12px; color: #666; margin-top: 5px;">
                Current Stock: <span id="currentStock_0">0</span> | 
                HSN: <span id="hsnCode_0">-</span>
            </div>
        </div>
    `;
    
    purchaseItemCounter = 1;
    
    // Load vendors and products
    loadVendorsForPurchase();
    loadProductsForPurchase(0);
    
    openModal('purchaseModal');
}

// Load vendors for purchase dropdown
function loadVendorsForPurchase() {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'get_vendors.php?action=list', true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                const vendors = JSON.parse(xhr.responseText);
                const select = document.getElementById('vendorSelect');
                select.innerHTML = '<option value="">Select Vendor</option>';
                
                vendors.forEach(vendor => {
                    const option = document.createElement('option');
                    option.value = vendor.vendor_id;
                    option.textContent = `${vendor.vendor_name}${vendor.company_name ? ' - ' + vendor.company_name : ''}`;
                    option.setAttribute('data-vendor', JSON.stringify(vendor));
                    select.appendChild(option);
                });
            } catch (e) {
                console.error('Error loading vendors:', e);
            }
        }
    };
    xhr.send();
}

// Load vendor details
function loadVendorDetails(vendorId) {
    if (!vendorId) {
        document.getElementById('vendorInfo').style.display = 'none';
        return;
    }
    
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `get_vendor_details.php?id=${vendorId}`, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                const vendor = JSON.parse(xhr.responseText);
                document.getElementById('vendorCompany').textContent = vendor.company_name || '-';
                document.getElementById('vendorGstinDisplay').textContent = vendor.gstin || '-';
                document.getElementById('vendorContact').textContent = vendor.contact_person || '-';
                document.getElementById('vendorAddress').textContent = vendor.address || '-';
                document.getElementById('vendorPhoneDisplay').textContent = vendor.phone || '-';
                document.getElementById('vendorEmailDisplay').textContent = vendor.email || '-';
                document.getElementById('vendorInfo').style.display = 'block';
            } catch (e) {
                console.error('Error loading vendor details:', e);
            }
        }
    };
    xhr.send();
}

// Load products for purchase dropdown
function loadProductsForPurchase(index) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'get_products.php?action=list', true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                const products = JSON.parse(xhr.responseText);
                const selects = document.querySelectorAll('.purchase-product-select');
                const select = selects[index];
                
                if (select) {
                    select.innerHTML = '<option value="">Select Product</option>';
                    products.forEach(product => {
                        const option = document.createElement('option');
                        option.value = product.product_id;
                        option.textContent = `${product.product_name} - ₹${product.price} (Stock: ${product.stock_quantity})`;
                        option.setAttribute('data-product', JSON.stringify(product));
                        select.appendChild(option);
                    });
                }
            } catch (e) {
                console.error('Error loading products:', e);
            }
        }
    };
    xhr.send();
}

// Update product details when selected
function updatePurchaseProductDetails(select) {
    const row = select.closest('.purchase-item');
    const index = Array.from(document.querySelectorAll('.purchase-item')).indexOf(row);
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const product = JSON.parse(selectedOption.getAttribute('data-product'));
        const priceInput = row.querySelector('.purchase-price');
        const sellingInput = row.querySelector('.selling-price');
        const gstInput = row.querySelector('.purchase-gst-rate');
        
        // Fill in product details
        priceInput.value = parseFloat(product.price) * 0.7; // Default cost price as 70% of selling
        sellingInput.value = product.price;
        gstInput.value = product.gst_rate;
        
        // Show product info
        const productInfo = document.getElementById(`productInfo_${index}`);
        if (productInfo) {
            document.getElementById(`currentStock_${index}`).textContent = product.stock_quantity;
            document.getElementById(`hsnCode_${index}`).textContent = product.hsn_code;
            productInfo.style.display = 'block';
        }
        
        calculatePurchaseRowTotal(select);
    } else {
        const productInfo = document.getElementById(`productInfo_${index}`);
        if (productInfo) {
            productInfo.style.display = 'none';
        }
    }
}

// Add new purchase item row
function addPurchaseItemRow() {
    const purchaseItems = document.getElementById('purchaseItems');
    const newRow = document.createElement('div');
    newRow.className = 'purchase-item';
    newRow.innerHTML = `
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 1fr; gap: 10px; margin-bottom: 15px; align-items: end;">
            <div class="form-group" style="margin: 0;">
                <select name="product_id[]" class="purchase-product-select" onchange="updatePurchaseProductDetails(this)" required>
                    <option value="">Select Product</option>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <input type="number" name="quantity[]" placeholder="Qty" min="1" value="1" 
                       class="purchase-quantity" required onchange="calculatePurchaseRowTotal(this)">
            </div>
            <div class="form-group" style="margin: 0;">
                <input type="number" name="purchase_price[]" placeholder="Cost Price" step="0.01" 
                       class="purchase-price" required onchange="calculatePurchaseRowTotal(this)">
            </div>
            <div class="form-group" style="margin: 0;">
                <input type="number" name="selling_price[]" placeholder="Selling Price" step="0.01" 
                       class="selling-price" required onchange="calculatePurchaseRowTotal(this)">
            </div>
            <div class="form-group" style="margin: 0;">
                <input type="number" name="discount[]" placeholder="Disc %" step="0.01" value="0" 
                       class="purchase-discount" onchange="calculatePurchaseRowTotal(this)">
            </div>
            <div class="form-group" style="margin: 0;">
                <input type="number" name="gst_rate[]" placeholder="GST %" step="0.01" value="5" 
                       class="purchase-gst-rate" onchange="calculatePurchaseRowTotal(this)">
            </div>
            <div class="form-group" style="margin: 0;">
                <input type="text" name="row_total[]" placeholder="Total" class="purchase-row-total" 
                       readonly style="background: #e9ecef; font-weight: bold;">
            </div>
        </div>
        <div id="productInfo_${purchaseItemCounter}" style="display: none; font-size: 12px; color: #666; margin-top: 5px;">
            Current Stock: <span id="currentStock_${purchaseItemCounter}">0</span> | 
            HSN: <span id="hsnCode_${purchaseItemCounter}">-</span>
        </div>
    `;
    
    purchaseItems.appendChild(newRow);
    loadProductsForPurchase(purchaseItemCounter);
    purchaseItemCounter++;
}

// Calculate purchase row total
function calculatePurchaseRowTotal(input) {
    const row = input.closest('.purchase-item');
    const quantity = parseFloat(row.querySelector('.purchase-quantity').value) || 0;
    const costPrice = parseFloat(row.querySelector('.purchase-price').value) || 0;
    const discountPercent = parseFloat(row.querySelector('.purchase-discount').value) || 0;
    const gstRate = parseFloat(row.querySelector('.purchase-gst-rate').value) || 0;
    const totalInput = row.querySelector('.purchase-row-total');
    
    const subtotal = quantity * costPrice;
    const discountAmount = subtotal * (discountPercent / 100);
    const taxableAmount = subtotal - discountAmount;
    const gstAmount = taxableAmount * (gstRate / 100);
    const total = taxableAmount + gstAmount;
    
    totalInput.value = '₹' + total.toFixed(2);
    updatePurchaseTotals();
}

// Update purchase totals
function updatePurchaseTotals() {
    let subtotal = 0;
    let totalDiscount = 0;
    let totalCgst = 0;
    let totalSgst = 0;
    let totalIgst = 0;
    
    const rows = document.querySelectorAll('.purchase-item');
    
    rows.forEach(row => {
        const quantity = parseFloat(row.querySelector('.purchase-quantity').value) || 0;
        const costPrice = parseFloat(row.querySelector('.purchase-price').value) || 0;
        const discountPercent = parseFloat(row.querySelector('.purchase-discount').value) || 0;
        const gstRate = parseFloat(row.querySelector('.purchase-gst-rate').value) || 0;
        
        const rowSubtotal = quantity * costPrice;
        subtotal += rowSubtotal;
        
        const rowDiscount = rowSubtotal * (discountPercent / 100);
        totalDiscount += rowDiscount;
        
        const rowTaxable = rowSubtotal - rowDiscount;
        const rowGst = rowTaxable * (gstRate / 100);
        
        // Assuming vendor is in Gujarat for GST calculation
        // In real scenario, check vendor state
        totalCgst += rowGst / 2;
        totalSgst += rowGst / 2;
    });
    
    const grandTotal = subtotal - totalDiscount + totalCgst + totalSgst + totalIgst;
    
    // Update display
    document.getElementById('purchaseSubtotal').textContent = '₹' + subtotal.toFixed(2);
    document.getElementById('purchaseDiscount').textContent = '₹' + totalDiscount.toFixed(2);
    document.getElementById('purchaseCgst').textContent = '₹' + totalCgst.toFixed(2);
    document.getElementById('purchaseSgst').textContent = '₹' + totalSgst.toFixed(2);
    document.getElementById('purchaseIgst').textContent = '₹' + totalIgst.toFixed(2);
    document.getElementById('purchaseTotal').textContent = '₹' + grandTotal.toFixed(2);
}

// Save purchase order
function savePurchaseOrder(event) {
    event.preventDefault();
    
    const formData = new FormData(document.getElementById('purchaseForm'));
    formData.append('action', 'save_purchase');
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'save_purchase.php', true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    alert('Purchase order saved successfully!');
                    closeModal('purchaseModal');
                    loadPurchaseOrders();
                    
                    if (response.purchase_id) {
                        setTimeout(() => {
                            printPurchaseSlip(response.purchase_id);
                        }, 500);
                    }
                } else {
                    alert('Error saving purchase: ' + response.message);
                }
            } catch (e) {
                alert('Error parsing response.');
            }
        } else {
            alert('Error saving purchase order.');
        }
    };
    xhr.send(formData);
}



// View purchase details
function viewPurchase(purchaseId) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', `view_purchase.php?id=${purchaseId}`, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            document.getElementById('purchaseSlipContent').innerHTML = xhr.responseText;
            openModal('purchaseSlipModal');
        }
    };
    xhr.send();
}

// Print purchase slip
function printPurchaseSlip(purchaseId) {
    window.open(`print_purchase_slip.php?id=${purchaseId}`, '_blank');
}

// Receive purchase (update stock)
function receivePurchase(purchaseId) {
    if (confirm('Mark this purchase as received? This will update product stock.')) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'receive_purchase.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        alert('Purchase received successfully! Stock updated.');
                        loadPurchaseOrders();
                        loadAllProducts(); // Refresh product list
                    } else {
                        alert('Error: ' + response.message);
                    }
                } catch (e) {
                    alert('Error processing response.');
                }
            }
        };
        xhr.send(`purchase_id=${purchaseId}`);
    }
}

// Delete purchase
function deletePurchase(purchaseId) {
    if (confirm('Are you sure you want to delete this purchase order?')) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'delete_purchase.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        alert('Purchase deleted successfully!');
                        loadPurchaseOrders();
                    } else {
                        alert('Error: ' + response.message);
                    }
                } catch (e) {
                    alert('Error processing response.');
                }
            }
        };
        xhr.send(`purchase_id=${purchaseId}`);
    }
}

// Filter purchase orders
function filterPurchaseOrders() {
    const statusFilter = document.getElementById('purchaseStatusFilter').value;
    const dateFilter = document.getElementById('purchaseDateFilter').value;
    
    filteredPurchases = allPurchases.filter(purchase => {
        let statusMatch = true;
        let dateMatch = true;
        
        if (statusFilter) {
            statusMatch = purchase.status === statusFilter;
        }
        
        if (dateFilter) {
            dateMatch = purchase.purchase_date === dateFilter;
        }
        
        return statusMatch && dateMatch;
    });
    
    currentPurchasePage = 1;
    renderPurchasesTable();
    updatePurchasePagination();
}

// Sort purchases
function sortPurchases(column) {
    filteredPurchases.sort((a, b) => {
        let aValue = a[column];
        let bValue = b[column];
        
        if (column === 'total_amount') {
            aValue = parseFloat(aValue);
            bValue = parseFloat(bValue);
        }
        
        if (aValue < bValue) return -1;
        if (aValue > bValue) return 1;
        return 0;
    });
    
    currentPurchasePage = 1;
    renderPurchasesTable();
    updatePurchasePagination();
}

// Purchase pagination functions
function updatePurchasePagination() {
    const totalPages = Math.ceil(filteredPurchases.length / purchaseItemsPerPage);
    const pageInfo = document.getElementById('purchasePageInfo');
    const prevBtn = document.getElementById('purchasePrevBtn');
    const nextBtn = document.getElementById('purchaseNextBtn');
    
    if (pageInfo) {
        pageInfo.textContent = `Page ${currentPurchasePage} of ${totalPages || 1}`;
    }
    
    if (prevBtn) {
        prevBtn.disabled = currentPurchasePage === 1;
    }
    
    if (nextBtn) {
        nextBtn.disabled = currentPurchasePage === totalPages || totalPages === 0;
    }
}

function previousPurchasePage() {
    if (currentPurchasePage > 1) {
        currentPurchasePage--;
        renderPurchasesTable();
        updatePurchasePagination();
    }
}

function nextPurchasePage() {
    const totalPages = Math.ceil(filteredPurchases.length / purchaseItemsPerPage);
    if (currentPurchasePage < totalPages) {
        currentPurchasePage++;
        renderPurchasesTable();
        updatePurchasePagination();
    }
}

// Preview purchase slip
function previewPurchaseSlip() {
    const formData = new FormData(document.getElementById('purchaseForm'));
    const vendorId = formData.get('vendor_id');
    
    if (!vendorId) {
        alert('Please select a vendor first');
        return;
    }
    
    // Create preview from form data
    const previewHTML = createPurchaseSlipPreview();
    document.getElementById('purchaseSlipContent').innerHTML = previewHTML;
    openModal('purchaseSlipModal');
}

// Create purchase slip preview
function createPurchaseSlipPreview() {
    const vendorSelect = document.getElementById('vendorSelect');
    const vendorName = vendorSelect.options[vendorSelect.selectedIndex].text;
    const purchaseDate = document.getElementById('purchaseDate').value;
    
    let itemsHTML = '';
    const rows = document.querySelectorAll('.purchase-item');
    
    rows.forEach((row, index) => {
        const productSelect = row.querySelector('.purchase-product-select');
        const productName = productSelect.options[productSelect.selectedIndex].text;
        const quantity = row.querySelector('.purchase-quantity').value;
        const price = row.querySelector('.purchase-price').value;
        const discount = row.querySelector('.purchase-discount').value;
        const total = row.querySelector('.purchase-row-total').value;
        
        if (productName && quantity > 0) {
            itemsHTML += `
                <tr>
                    <td>${index + 1}</td>
                    <td>${productName.split(' - ')[0]}</td>
                    <td>${quantity}</td>
                    <td>₹${parseFloat(price).toFixed(2)}</td>
                    <td>${discount}%</td>
                    <td>₹${total.replace('₹', '')}</td>
                </tr>
            `;
        }
    });
    
    const totals = {
        subtotal: document.getElementById('purchaseSubtotal').textContent,
        discount: document.getElementById('purchaseDiscount').textContent,
        cgst: document.getElementById('purchaseCgst').textContent,
        sgst: document.getElementById('purchaseSgst').textContent,
        total: document.getElementById('purchaseTotal').textContent
    };
    
    return `
        <div class="invoice-container">
            <div class="invoice-header">
                <div class="company-info">
                    <h2><?php echo COMPANY_NAME; ?></h2>
                    <p><?php echo COMPANY_ADDRESS; ?></p>
                    <p>GSTIN: <?php echo COMPANY_GSTIN; ?></p>
                </div>
                <div class="invoice-meta">
                    <h3>PURCHASE ORDER</h3>
                    <p><strong>Date:</strong> ${purchaseDate}</p>
                    <p><strong>PO #:</strong> PO-XXXXXX</p>
                </div>
            </div>
            
            <div style="margin: 30px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <div>
                    <h4>From:</h4>
                    <p><strong>${vendorName}</strong></p>
                    <div id="vendorInfoPreview">
                        <!-- Vendor details will be shown -->
                    </div>
                </div>
                <div>
                    <h4>To:</h4>
                    <p><strong><?php echo COMPANY_NAME; ?></strong></p>
                    <p><?php echo COMPANY_ADDRESS; ?></p>
                    <p>GSTIN: <?php echo COMPANY_GSTIN; ?></p>
                </div>
            </div>
            
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product Description</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Discount</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHTML}
                </tbody>
            </table>
            
            <div style="margin-top: 40px;">
                <table class="totals-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td style="text-align: right;">${totals.subtotal}</td>
                    </tr>
                    <tr>
                        <td>Discount:</td>
                        <td style="text-align: right;">${totals.discount}</td>
                    </tr>
                    <tr>
                        <td>CGST:</td>
                        <td style="text-align: right;">${totals.cgst}</td>
                    </tr>
                    <tr>
                        <td>SGST:</td>
                        <td style="text-align: right;">${totals.sgst}</td>
                    </tr>
                    <tr class="total-row">
                        <td>Total Amount:</td>
                        <td style="text-align: right;">${totals.total}</td>
                    </tr>
                </table>
            </div>
            
            <div style="margin-top: 60px; display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <div>
                    <p><strong>Vendor Signature:</strong></p>
                    <p style="margin-top: 40px;">_________________________</p>
                </div>
                <div>
                    <p><strong>Authorized Signatory:</strong></p>
                    <p style="margin-top: 40px;">_________________________</p>
                    <p><?php echo COMPANY_NAME; ?></p>
                </div>
            </div>
            
            <div style="margin-top: 30px; text-align: center;" class="no-print">
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Print Purchase Order
                </button>
                <button onclick="closeModal('purchaseSlipModal')" class="btn btn-secondary" style="margin-left: 15px;">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    `;
}

// Print purchase report
function printPurchaseReport() {
    if (filteredPurchases.length === 0) {
        alert('No purchase orders to print!');
        return;
    }
    
    const printWindow = window.open('', '_blank');
    const currentDate = new Date().toLocaleDateString();
    
    let reportHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Purchase Orders Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #2c3e50; text-align: center; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background: #2c3e50; color: white; padding: 10px; text-align: left; }
                td { padding: 8px; border: 1px solid #ddd; }
                .total { font-weight: bold; background: #f8f9fa; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <h1>Purchase Orders Report</h1>
            <p>Generated on: ${currentDate}</p>
            <p>Total Orders: ${filteredPurchases.length}</p>
            
            <table>
                <thead>
                    <tr>
                        <th>PO #</th>
                        <th>Date</th>
                        <th>Vendor</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Total Amount</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    let grandTotal = 0;
    
    filteredPurchases.forEach(purchase => {
        grandTotal += parseFloat(purchase.total_amount);
        reportHTML += `
            <tr>
                <td>PO-${purchase.purchase_id.toString().padStart(5, '0')}</td>
                <td>${purchase.purchase_date}</td>
                <td>${escapeHtml(purchase.vendor_name)}</td>
                <td>${purchase.status}</td>
                <td>${purchase.payment_status}</td>
                <td>₹${parseFloat(purchase.total_amount).toFixed(2)}</td>
            </tr>
        `;
    });
    
    reportHTML += `
                </tbody>
                <tfoot>
                    <tr class="total">
                        <td colspan="5" style="text-align: right;">Grand Total:</td>
                        <td>₹${grandTotal.toFixed(2)}</td>
                    </tr>
                </tfoot>
            </table>
            
            <div class="footer">
                <p>Generated by SPC Textiles GST Billing System</p>
            </div>
            
            <script>
                window.onload = function() { window.print(); }
            <\/script>
        </body>
        </html>
    `;
    
    printWindow.document.write(reportHTML);
    printWindow.document.close();
}

// Navigation handler for purchase page
document.addEventListener('DOMContentLoaded', function() {
    // Handle purchase page navigation
    const purchaseLink = document.querySelector('a[href="#purchases-page"]');
    if (purchaseLink) {
        purchaseLink.addEventListener('click', function(e) {
            e.preventDefault();
            showPurchasesPage();
        });
    }
    
    // Show purchases page function
    window.showPurchasesPage = function() {
        // Hide other sections
        document.querySelectorAll('.card').forEach(card => {
            card.style.display = 'none';
        });
        
        // Show dashboard grid if exists
        const dashboardGrid = document.querySelector('.dashboard-grid');
        if (dashboardGrid) {
            dashboardGrid.style.display = 'none';
        }
        
        // Show purchases page
        document.querySelector('#purchases-page').style.display = 'block';
        
        // Update active nav
        document.querySelectorAll('nav a').forEach(a => a.classList.remove('active'));
        const purchaseLink = document.querySelector('a[href="#purchases-page"]');
        if (purchaseLink) {
            purchaseLink.classList.add('active');
        }
        
        // Load purchase orders if not already loaded
        if (allPurchases.length === 0) {
            loadPurchaseOrders();
        }
    };
});
    </script>
    
    <script>
        
        function saveVendor(event) {
    event.preventDefault();
    
    // Get form data
    const formData = new FormData(document.getElementById('vendorForm'));
    
    // Show loading state
    const submitBtn = document.querySelector('#vendorForm button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    
    // Clear previous messages
    const messageDiv = document.getElementById('vendorMessage');
    messageDiv.style.display = 'none';
    messageDiv.innerHTML = '';
    
    // Send AJAX request
    fetch('addvendor.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            messageDiv.style.display = 'block';
            messageDiv.style.background = '#d4edda';
            messageDiv.style.color = '#155724';
            messageDiv.style.border = '1px solid #c3e6cb';
            messageDiv.innerHTML = `
                <i class="fas fa-check-circle"></i> ${data.message}
                <br><small>Vendor ID: ${data.vendor_id}</small>
            `;
            
            // Reset form
            document.getElementById('vendorForm').reset();
            
            // Close modal after 2 seconds
            setTimeout(() => {
                closeModal('vendorModal');
                
                // Refresh vendors list if on vendors page
                if (typeof loadVendors === 'function') {
                    loadVendors();
                }
                
                // Show success message on main page
                showNotification('success', `${data.message}: ${data.vendor_name}`);
                
                // Refresh vendors dropdown in purchase form
                if (typeof loadVendorsForPurchase === 'function') {
                    loadVendorsForPurchase();
                }
            }, 2000);
            
        } else {
            // Show error message
            messageDiv.style.display = 'block';
            messageDiv.style.background = '#f8d7da';
            messageDiv.style.color = '#721c24';
            messageDiv.style.border = '1px solid #f5c6cb';
            messageDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${data.message}`;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        messageDiv.style.display = 'block';
        messageDiv.style.background = '#f8d7da';
        messageDiv.style.color = '#721c24';
        messageDiv.style.border = '1px solid #f5c6cb';
        messageDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
    })
    .finally(() => {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

// Helper function to show notifications
function showNotification(type, message) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        ${message}
    `;
    
    // Add to page
    const container = document.querySelector('.container');
    const header = document.querySelector('header');
    container.insertBefore(notification, header.nextSibling);
    
    // Remove after 5 seconds
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Function to open vendor modal and reset form
function openVendorModal() {
    document.getElementById('vendorForm').reset();
    document.getElementById('vendorModalTitle').textContent = 'Add New Vendor';
    document.getElementById('vendorMessage').style.display = 'none';
    openModal('vendorModal');
}
    </script>
</body>
</html>
                
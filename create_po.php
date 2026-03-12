<?php
// create_po.php
require_once 'config1.php';

// Get vendors for dropdown
$vendors = $conn->query("SELECT vendor_id, vendor_name, company_name FROM vendors ORDER BY vendor_name");

// Get products (assuming you have a products table)
$products = $conn->query("SELECT product_id, product_name, current_stock FROM products ORDER BY product_name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendor_id = $_POST['vendor_id'];
    $po_number = generatePONumber($conn);
    $po_date = $_POST['po_date'];
    $expected_date = $_POST['expected_date'];
    $notes = $_POST['notes'];
    
    // Calculate totals
    $subtotal = 0;
    $discount = $_POST['total_discount'] ?? 0;
    $tax_amount = $_POST['total_tax'] ?? 0;
    $total_amount = $_POST['total_amount'] ?? 0;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert PO
        $stmt = $conn->prepare("
            INSERT INTO purchase_orders (po_number, vendor_id, po_date, expected_date, subtotal, discount, tax_amount, total_amount, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sissdddd", $po_number, $vendor_id, $po_date, $expected_date, $subtotal, $discount, $tax_amount, $total_amount, $notes);
        $stmt->execute();
        $po_id = $conn->insert_id;
        
        // Insert PO items
        if (isset($_POST['product_id'])) {
            $product_ids = $_POST['product_id'];
            $quantities = $_POST['quantity'];
            $purchase_prices = $_POST['purchase_price'];
            $selling_prices = $_POST['selling_price'];
            $discounts = $_POST['item_discount'];
            $taxes = $_POST['item_tax'];
            $item_totals = $_POST['item_total'];
            
            $stmt = $conn->prepare("
                INSERT INTO purchase_order_items (po_id, product_id, quantity, purchase_price, selling_price, discount, tax_amount, total_amount) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            for ($i = 0; $i < count($product_ids); $i++) {
                if (!empty($product_ids[$i]) && $quantities[$i] > 0) {
                    $stmt->bind_param("iiiddddd", 
                        $po_id, 
                        $product_ids[$i], 
                        $quantities[$i], 
                        $purchase_prices[$i], 
                        $selling_prices[$i], 
                        $discounts[$i], 
                        $taxes[$i], 
                        $item_totals[$i]
                    );
                    $stmt->execute();
                    $subtotal += $item_totals[$i];
                }
            }
            
            // Update PO with calculated totals
            $conn->query("UPDATE purchase_orders SET subtotal = $subtotal WHERE po_id = $po_id");
        }
        
        $conn->commit();
        $_SESSION['success'] = "Purchase Order created successfully! PO Number: $po_number";
        header("Location: view_po.php?id=$po_id");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error creating PO: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Purchase Order</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="header">
                <h1><i class="fas fa-file-invoice-dollar"></i> Create Purchase Order</h1>
                <div class="header-actions">
                    <a href="purchase_orders.php" class="btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to POs
                    </a>
                </div>
            </header>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="poForm">
                <div class="card">
                    <div class="card-header">
                        <h2>PO Details</h2>
                    </div>
                    <div class="card-body">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="po_number">PO Number</label>
                                <input type="text" id="po_number" value="<?php echo generatePONumber($conn); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="po_date">PO Date *</label>
                                <input type="date" id="po_date" name="po_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="expected_date">Expected Delivery Date</label>
                                <input type="date" id="expected_date" name="expected_date">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="vendor_id">Select Vendor *</label>
                            <select id="vendor_id" name="vendor_id" required>
                                <option value="">Select Vendor</option>
                                <?php while ($vendor = $vendors->fetch_assoc()): ?>
                                <option value="<?php echo $vendor['vendor_id']; ?>">
                                    <?php echo htmlspecialchars($vendor['vendor_name'] . ' - ' . $vendor['company_name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="2" placeholder="Any special instructions..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>Order Items</h2>
                        <button type="button" class="btn-primary" onclick="addItemRow()">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </div>
                    <div class="card-body">
                        <table id="itemsTable">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Purchase Price</th>
                                    <th>Selling Price</th>
                                    <th>Discount</th>
                                    <th>Tax</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                <!-- Items will be added here dynamically -->
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="5" style="text-align: right; padding-right: 20px;">
                                        <strong>Subtotal:</strong>
                                    </td>
                                    <td colspan="3">
                                        <input type="text" id="subtotal" readonly class="amount-display">
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="5" style="text-align: right; padding-right: 20px;">
                                        <strong>Total Discount:</strong>
                                    </td>
                                    <td colspan="3">
                                        <input type="text" id="total_discount" name="total_discount" value="0.00" readonly class="amount-display">
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="5" style="text-align: right; padding-right: 20px;">
                                        <strong>Total Tax:</strong>
                                    </td>
                                    <td colspan="3">
                                        <input type="text" id="total_tax" name="total_tax" value="0.00" readonly class="amount-display">
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="5" style="text-align: right; padding-right: 20px;">
                                        <strong>Grand Total:</strong>
                                    </td>
                                    <td colspan="3">
                                        <input type="text" id="total_amount" name="total_amount" value="0.00" readonly class="amount-display">
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="window.location.href='purchase_orders.php'">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Create Purchase Order
                    </button>
                </div>
            </form>
        </main>
    </div>

    <script>
        // Product data for autocomplete
        const products = <?php
            $product_data = [];
            while ($product = $products->fetch_assoc()) {
                $product_data[] = [
                    'id' => $product['product_id'],
                    'name' => $product['product_name'],
                    'stock' => $product['current_stock']
                ];
            }
            echo json_encode($product_data);
        ?>;

        let itemCount = 0;

        function addItemRow(product = null) {
            itemCount++;
            const row = document.createElement('tr');
            row.id = `item-row-${itemCount}`;
            
            row.innerHTML = `
                <td>
                    <select name="product_id[]" class="product-select" onchange="updateProductInfo(this, ${itemCount})" required>
                        <option value="">Select Product</option>
                        ${products.map(p => `<option value="${p.id}" data-stock="${p.stock}">${p.name}</option>`).join('')}
                    </select>
                    <div id="stock-info-${itemCount}" class="stock-info"></div>
                </td>
                <td>
                    <input type="number" name="quantity[]" min="1" value="1" step="1" class="quantity" onchange="calculateItemTotal(${itemCount})" required>
                </td>
                <td>
                    <input type="number" name="purchase_price[]" min="0" value="0" step="0.01" class="price" onchange="calculateItemTotal(${itemCount})" required>
                </td>
                <td>
                    <input type="number" name="selling_price[]" min="0" value="0" step="0.01" class="price" onchange="calculateItemTotal(${itemCount})">
                </td>
                <td>
                    <input type="number" name="item_discount[]" min="0" value="0" step="0.01" class="discount" onchange="calculateItemTotal(${itemCount})">
                </td>
                <td>
                    <input type="number" name="item_tax[]" min="0" value="0" step="0.01" class="tax" onchange="calculateItemTotal(${itemCount})">
                </td>
                <td>
                    <input type="text" name="item_total[]" value="0.00" readonly class="item-total">
                </td>
                <td>
                    <button type="button" class="btn-delete" onclick="removeItemRow(${itemCount})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            
            document.getElementById('itemsBody').appendChild(row);
            
            if (product) {
                const select = row.querySelector('.product-select');
                select.value = product.id;
                updateProductInfo(select, itemCount);
            }
        }

        function updateProductInfo(select, rowId) {
            const selectedOption = select.options[select.selectedIndex];
            const stock = selectedOption.dataset.stock || 0;
            const stockInfo = document.getElementById(`stock-info-${rowId}`);
            stockInfo.textContent = `Stock: ${stock}`;
            stockInfo.className = `stock-info ${stock > 0 ? 'in-stock' : 'out-stock'}`;
        }

        function calculateItemTotal(rowId) {
            const row = document.getElementById(`item-row-${rowId}`);
            const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
            const price = parseFloat(row.querySelector('.price').value) || 0;
            const discount = parseFloat(row.querySelector('.discount').value) || 0;
            const tax = parseFloat(row.querySelector('.tax').value) || 0;
            
            let itemTotal = (quantity * price) - discount;
            itemTotal += (itemTotal * tax) / 100;
            
            row.querySelector('.item-total').value = itemTotal.toFixed(2);
            updateTotals();
        }

        function updateTotals() {
            let subtotal = 0;
            let totalDiscount = 0;
            let totalTax = 0;
            
            document.querySelectorAll('#itemsBody tr').forEach(row => {
                subtotal += parseFloat(row.querySelector('.item-total').value) || 0;
                totalDiscount += parseFloat(row.querySelector('.discount').value) || 0;
                
                const itemSubtotal = (parseFloat(row.querySelector('.quantity').value) || 0) * 
                                   (parseFloat(row.querySelector('.price').value) || 0);
                const taxRate = parseFloat(row.querySelector('.tax').value) || 0;
                totalTax += (itemSubtotal * taxRate) / 100;
            });
            
            document.getElementById('subtotal').value = subtotal.toFixed(2);
            document.getElementById('total_discount').value = totalDiscount.toFixed(2);
            document.getElementById('total_tax').value = totalTax.toFixed(2);
            document.getElementById('total_amount').value = (subtotal - totalDiscount + totalTax).toFixed(2);
        }

        function removeItemRow(rowId) {
            const row = document.getElementById(`item-row-${rowId}`);
            row.remove();
            updateTotals();
        }

        // Add initial row
        document.addEventListener('DOMContentLoaded', function() {
            addItemRow();
        });

        // Form validation
        document.getElementById('poForm').addEventListener('submit', function(e) {
            const items = document.querySelectorAll('#itemsBody tr');
            if (items.length === 0) {
                e.preventDefault();
                alert('Please add at least one item to the purchase order.');
                return;
            }
            
            let hasValidItem = false;
            items.forEach(row => {
                const product = row.querySelector('.product-select').value;
                const quantity = row.querySelector('.quantity').value;
                if (product && quantity > 0) {
                    hasValidItem = true;
                }
            });
            
            if (!hasValidItem) {
                e.preventDefault();
                alert('Please add at least one valid item to the purchase order.');
            }
        });
    </script>
</body>
</html>
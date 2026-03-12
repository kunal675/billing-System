<?php
// print_all_products.php
session_start();
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

// Get all products
$sql = "SELECT * FROM products ORDER BY product_name";
$result = $conn->query($sql);

$products = array();
$totalStockValue = 0;
$totalStockQuantity = 0;
$lowStockCount = 0;
$outOfStockCount = 0;

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $stockQuantity = floatval($row['stock_quantity']);
        $price = floatval($row['price']);
        $stockValue = $stockQuantity * $price;
        
        // Calculate totals
        $totalStockValue += $stockValue;
        $totalStockQuantity += $stockQuantity;
        
        if ($stockQuantity === 0) {
            $outOfStockCount++;
        } elseif ($stockQuantity < 10) {
            $lowStockCount++;
        }
        
        $products[] = array(
            'product' => $row,
            'stockValue' => $stockValue,
            'status' => $stockQuantity === 0 ? 'Out of Stock' : ($stockQuantity < 10 ? 'Low Stock' : 'In Stock'),
            'statusColor' => $stockQuantity === 0 ? '#dc3545' : ($stockQuantity < 10 ? '#ffc107' : '#28a745')
        );
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Products Inventory - <?php echo COMPANY_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                font-size: 11px;
            }
            
            .no-print {
                display: none !important;
            }
            
            .header {
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #2c3e50;
                page-break-after: avoid;
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
            
            .footer {
                position: fixed;
                bottom: 0;
                width: 100%;
                text-align: center;
                font-size: 9px;
                color: #666;
                padding: 8px 0;
                border-top: 1px solid #ddd;
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
            font-size: 22px;
            color: #4a6491;
            margin-bottom: 10px;
        }
        
        .report-date {
            color: #6c757d;
            font-size: 14px;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e1e5eb;
        }
        
        .summary-item {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e1e5eb;
        }
        
        .summary-label {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .summary-value {
            font-size: 20px;
            font-weight: bold;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th {
            background: #2c3e50;
            color: white;
            padding: 12px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
        }
        
        td {
            padding: 10px 8px;
            border: 1px solid #ddd;
            font-size: 11px;
        }
        
        tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
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
            display: flex;
            gap: 10px;
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
        
        .close-btn {
            background: #6c757d;
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
        
        .close-btn:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="header">
            <div class="company-name"><?php echo COMPANY_NAME; ?></div>
            <div class="report-title">COMPLETE PRODUCTS INVENTORY REPORT</div>
            <div class="report-date">Generated on: <?php echo date('d/m/Y h:i A'); ?></div>
        </div>
        
        <div class="summary-grid">
            <div class="summary-item">
                <div class="summary-label">Total Products</div>
                <div class="summary-value" style="color: #2c3e50;"><?php echo count($products); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Total Stock Value</div>
                <div class="summary-value" style="color: #28a745;">₹<?php echo number_format($totalStockValue, 2); ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Low Stock Items</div>
                <div class="summary-value" style="color: #ffc107;"><?php echo $lowStockCount; ?></div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Out of Stock</div>
                <div class="summary-value" style="color: #dc3545;"><?php echo $outOfStockCount; ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">#</th>
                    <th style="width: 25%;">Product Name</th>
                    <th style="width: 8%;">HSN Code</th>
                    <th style="width: 12%;">Category</th>
                    <th style="width: 8%;">Stock Qty</th>
                    <th style="width: 6%;">Unit</th>
                    <th style="width: 10%;">Price</th>
                    <th style="width: 8%;">GST %</th>
                    <th style="width: 12%;">Stock Value</th>
                    <th style="width: 8%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($products) > 0): ?>
                    <?php foreach ($products as $index => $item): 
                        $product = $item['product'];
                        $stockQty = floatval($product['stock_quantity']);
                    ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                        <td><?php echo $product['hsn_code']; ?></td>
                        <td><?php echo $product['category']; ?></td>
                        <td style="font-weight: bold; color: <?php echo $item['statusColor']; ?>">
                            <?php echo $stockQty; ?>
                        </td>
                        <td><?php echo $product['unit']; ?></td>
                        <td style="font-weight: bold;">₹<?php echo number_format($product['price'], 2); ?></td>
                        <td><?php echo $product['gst_rate']; ?>%</td>
                        <td style="font-weight: bold;">₹<?php echo number_format($item['stockValue'], 2); ?></td>
                        <td>
                            <span class="status-badge" style="background: <?php echo $item['statusColor']; ?>; color: <?php echo $stockQty < 10 ? '#212529' : 'white'; ?>">
                                <?php echo $item['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 30px; color: #666;">
                            No products found in inventory
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="footer">
            <p>This report shows all <?php echo count($products); ?> products in inventory</p>
            <p>Total Stock Quantity: <?php echo $totalStockQuantity; ?> | Total Stock Value: ₹<?php echo number_format($totalStockValue, 2); ?></p>
            <p>Generated by SPC Textiles GST Billing System</p>
        </div>
    </div>
    
    <div class="print-controls no-print">
        <button onclick="window.print()" class="print-btn">
            <i class="fas fa-print"></i> Print Report
        </button>
        <button onclick="window.close()" class="close-btn">
            <i class="fas fa-times"></i> Close Window
        </button>
    </div>
    
    <script>
        window.onload = function() {
            // Optional: Auto-print
            // setTimeout(function() {
            //     window.print();
            // }, 1000);
        };
    </script>
</body>
</html>
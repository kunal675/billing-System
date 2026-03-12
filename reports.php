<?php
// reports.php
session_start();
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

// Default date range (current month)
$start_date = date('Y-m-01');
$end_date = date('Y-m-t');

if (isset($_GET['generate_report'])) {
    $start_date = $_GET['start_date'] ?? $start_date;
    $end_date = $_GET['end_date'] ?? $end_date;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GST Reports - <?php echo COMPANY_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Add styles from index.php or create separate CSS file */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            color: #333;
            margin: 0;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid #4a6491;
        }
        
        .report-form {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            flex: 1;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        input, select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 16px;
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
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .report-table th {
            background: linear-gradient(135deg, #4a6491 0%, #2c3e50 100%);
            color: white;
            padding: 15px;
            text-align: left;
        }
        
        .report-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .report-table tr:hover {
            background: #f8f9fa;
        }
        
        .total-row {
            font-weight: bold;
            background: #e9ecef;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #4a6491;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <h1><i class="fas fa-chart-bar"></i> GST Reports</h1>
        
        <div class="report-form">
            <form method="GET" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label>Start Date:</label>
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>End Date:</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>" required>
                    </div>
                </div>
                <button type="submit" name="generate_report" class="btn">
                    <i class="fas fa-search"></i> Generate Report
                </button>
            </form>
        </div>
        
        <?php
        if (isset($_GET['generate_report'])) {
            // Query to get invoice data
            $sql = "SELECT 
                        i.invoice_number,
                        DATE(i.invoice_date) as invoice_date,
                        c.customer_name,
                        c.gstin as customer_gstin,
                        c.state as customer_state,
                        i.subtotal,
                        i.discount,
                        i.cgst,
                        i.sgst,
                        i.igst,
                        i.total_amount
                    FROM invoices i
                    JOIN customers c ON i.customer_id = c.customer_id
                    WHERE i.invoice_date BETWEEN ? AND ?
                    ORDER BY i.invoice_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $start_date, $end_date);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0):
                $total_subtotal = 0;
                $total_discount = 0;
                $total_cgst = 0;
                $total_sgst = 0;
                $total_igst = 0;
                $total_amount = 0;
        ?>
        <div style="overflow-x: auto;">
            <h3>Report Period: <?php echo date('d/m/Y', strtotime($start_date)); ?> to <?php echo date('d/m/Y', strtotime($end_date)); ?></h3>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice No</th>
                        <th>Customer</th>
                        <th>GSTIN</th>
                        <th>State</th>
                        <th>Subtotal</th>
                        <th>Discount</th>
                        <th>CGST</th>
                        <th>SGST</th>
                        <th>IGST</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): 
                        $total_subtotal += $row['subtotal'];
                        $total_discount += $row['discount'];
                        $total_cgst += $row['cgst'];
                        $total_sgst += $row['sgst'];
                        $total_igst += $row['igst'];
                        $total_amount += $row['total_amount'];
                    ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($row['invoice_date'])); ?></td>
                        <td><?php echo $row['invoice_number']; ?></td>
                        <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                        <td><?php echo $row['customer_gstin'] ?: 'N/A'; ?></td>
                        <td><?php echo $row['customer_state']; ?></td>
                        <td>₹<?php echo number_format($row['subtotal'], 2); ?></td>
                        <td>₹<?php echo number_format($row['discount'], 2); ?></td>
                        <td>₹<?php echo number_format($row['cgst'], 2); ?></td>
                        <td>₹<?php echo number_format($row['sgst'], 2); ?></td>
                        <td>₹<?php echo number_format($row['igst'], 2); ?></td>
                        <td>₹<?php echo number_format($row['total_amount'], 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                    <tr class="total-row">
                        <td colspan="5"><strong>TOTALS:</strong></td>
                        <td><strong>₹<?php echo number_format($total_subtotal, 2); ?></strong></td>
                        <td><strong>₹<?php echo number_format($total_discount, 2); ?></strong></td>
                        <td><strong>₹<?php echo number_format($total_cgst, 2); ?></strong></td>
                        <td><strong>₹<?php echo number_format($total_sgst, 2); ?></strong></td>
                        <td><strong>₹<?php echo number_format($total_igst, 2); ?></strong></td>
                        <td><strong>₹<?php echo number_format($total_amount, 2); ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 30px;">
            <button onclick="window.print()" class="btn">
                <i class="fas fa-print"></i> Print Report
            </button>
            <button onclick="exportToExcel()" class="btn" style="margin-left: 15px; background: #28a745;">
                <i class="fas fa-file-excel"></i> Export to Excel
            </button>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 50px; color: #6c757d;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i>
            <h3>No invoices found for the selected period</h3>
            <p>Try selecting a different date range</p>
        </div>
        <?php endif; } ?>
    </div>
    
    <script>
        function exportToExcel() {
            // Simple Excel export
            const table = document.querySelector('.report-table');
            const html = table.outerHTML;
            const url = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'GST_Report_<?php echo date('Y-m-d'); ?>.xls';
            a.click();
        }
    </script>
</body>
</html>
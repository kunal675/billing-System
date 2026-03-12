<?php
// print_invoice.php - FIXED VERSION

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if required files exist before including
$required_files = ['config.php', 'db_connection.php'];
foreach ($required_files as $file) {
    if (!file_exists($file)) {
        die("Error: Required file '$file' not found.");
    }
}

// Include configuration
require_once 'config.php';

// Include database connection
require_once 'db_connection.php';

try {
    // Create database connection
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check if invoice ID is provided
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        throw new Exception("No invoice ID provided");
    }
    
    $invoice_id = intval($_GET['id']);
    
    if ($invoice_id <= 0) {
        throw new Exception("Invalid invoice ID");
    }
    
    // Get invoice details
    $stmt = $conn->prepare("
        SELECT i.*, c.*, 
               DATE_FORMAT(i.invoice_date, '%d/%m/%Y') as formatted_date,
               DATE_FORMAT(i.due_date, '%d/%m/%Y') as due_date_formatted
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.customer_id 
        WHERE i.invoice_id = ?
    ");
    
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Invoice not found");
    }
    
    $invoice = $result->fetch_assoc();
    $stmt->close();
    
    // Get invoice items
    $stmt = $conn->prepare("
        SELECT ii.*, p.product_name, p.hsn_code, p.description, p.category
        FROM invoice_items ii 
        JOIN products p ON ii.product_id = p.product_id 
        WHERE ii.invoice_id = ?
        ORDER BY ii.item_id
    ");
    
    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $items_result = $stmt->get_result();
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Close connection
    $conn->close();
    
} catch (Exception $e) {
    // Display error page
    displayError($e->getMessage());
    exit();
}

// Function to display error
function displayError($message) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error - Invoice Print</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                padding: 50px;
                text-align: center;
                background: #f8f9fa;
            }
            .error-container {
                background: white;
                padding: 40px;
                border-radius: 10px;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
                max-width: 600px;
                margin: 0 auto;
            }
            .error-icon {
                font-size: 60px;
                color: #dc3545;
                margin-bottom: 20px;
            }
            .error-message {
                color: #721c24;
                background: #f8d7da;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
                font-size: 16px;
            }
            .btn {
                display: inline-block;
                padding: 10px 20px;
                background: #007bff;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h2>Unable to Print Invoice</h2>
            <div class="error-message"><?php echo htmlspecialchars($message); ?></div>
            <p>Please check the following:</p>
            <ul style="text-align: left; display: inline-block;">
                <li>Invoice exists in database</li>
                <li>Database connection is working</li>
                <li>You have proper permissions</li>
            </ul>
            <br>
            <a href="javascript:history.back()" class="btn">Go Back</a>
            <a href="index.php" class="btn" style="background: #28a745;">Go to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
}

// Function to convert number to words
function numberToWords($number) {
    $number = round($number, 2); // Round to 2 decimal places
    $whole = floor($number);
    $fraction = round(($number - $whole) * 100);
    
    $words = convertNumberToWords($whole) . ' Rupees';
    
    if ($fraction > 0) {
        $words .= ' and ' . convertNumberToWords($fraction) . ' Paise';
    }
    
    $words .= ' Only';
    return $words;
}

function convertNumberToWords($number) {
    $ones = array(
        0 => '',
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen'
    );
    
    $tens = array(
        2 => 'Twenty',
        3 => 'Thirty',
        4 => 'Forty',
        5 => 'Fifty',
        6 => 'Sixty',
        7 => 'Seventy',
        8 => 'Eighty',
        9 => 'Ninety'
    );
    
    if ($number == 0) {
        return 'Zero';
    }
    
    // Handle negative numbers
    if ($number < 0) {
        return 'Negative ' . convertNumberToWords(abs($number));
    }
    
    $words = '';
    
    // Crores
    if ($number >= 10000000) {
        $crores = floor($number / 10000000);
        $words .= convertNumberToWords($crores) . ' Crore ';
        $number %= 10000000;
    }
    
    // Lakhs
    if ($number >= 100000) {
        $lakhs = floor($number / 100000);
        $words .= convertNumberToWords($lakhs) . ' Lakh ';
        $number %= 100000;
    }
    
    // Thousands
    if ($number >= 1000) {
        $thousands = floor($number / 1000);
        $words .= convertNumberToWords($thousands) . ' Thousand ';
        $number %= 1000;
    }
    
    // Hundreds
    if ($number >= 100) {
        $hundreds = floor($number / 100);
        $words .= convertNumberToWords($hundreds) . ' Hundred ';
        $number %= 100;
    }
    
    // Tens and Ones
    if ($number > 0) {
        if (!empty($words)) {
            $words .= 'and ';
        }
        
        if ($number < 20) {
            $words .= $ones[$number];
        } else {
            $words .= $tens[floor($number / 10)];
            $remainder = $number % 10;
            if ($remainder > 0) {
                $words .= ' ' . $ones[$remainder];
            }
        }
    }
    
    return trim($words);
}

// Alternative robust function (use this one if above doesn't work)
function amountToWords($amount) {
    $amount = round($amount, 2);
    $whole_number = floor($amount);
    $decimal = round(($amount - $whole_number) * 100);
    
    $words = '';
    
    // Convert whole number part
    if ($whole_number > 0) {
        $words = convertToIndianWords($whole_number) . ' Rupees';
    }
    
    // Convert decimal part (paise)
    if ($decimal > 0) {
        if (!empty($words)) {
            $words .= ' and ';
        }
        $words .= convertToIndianWords($decimal) . ' Paise';
    }
    
    if (empty($words)) {
        $words = 'Zero Rupees';
    }
    
    return $words . ' Only';
}

function convertToIndianWords($number) {
    if ($number == 0) {
        return 'Zero';
    }
    
    $words = '';
    $crores = floor($number / 10000000);
    $number %= 10000000;
    
    $lakhs = floor($number / 100000);
    $number %= 100000;
    
    $thousands = floor($number / 1000);
    $number %= 1000;
    
    $hundreds = floor($number / 100);
    $number %= 100;
    
    $tens = floor($number / 10);
    $units = $number % 10;
    
    // Crores
    if ($crores > 0) {
        $words .= convertToIndianWords($crores) . ' Crore ';
    }
    
    // Lakhs
    if ($lakhs > 0) {
        $words .= convertToIndianWords($lakhs) . ' Lakh ';
    }
    
    // Thousands
    if ($thousands > 0) {
        $words .= convertToIndianWords($thousands) . ' Thousand ';
    }
    
    // Hundreds
    if ($hundreds > 0) {
        $words .= convertToIndianWords($hundreds) . ' Hundred ';
    }
    
    // Tens and Units
    if ($tens > 0 || $units > 0) {
        if (!empty($words)) {
            $words .= 'and ';
        }
        
        $ones = array(
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
            5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
            10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
            15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
        );
        
        $tens_array = array(
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
        );
        
        if ($number < 20) {
            $words .= $ones[$number];
        } else {
            $words .= $tens_array[$tens];
            if ($units > 0) {
                $words .= ' ' . $ones[$units];
            }
        }
    }
    
    return trim($words);
}

// Simple function for testing
function simpleAmountToWords($amount) {
    $amount = round($amount, 2);
    $formatted = number_format($amount, 2, '.', ',');
    
    // Split into whole and decimal parts
    $parts = explode('.', $formatted);
    $whole = $parts[0];
    $decimal = isset($parts[1]) ? $parts[1] : '00';
    
    $words = '';
    
    // Remove commas and convert to integer
    $whole = (int) str_replace(',', '', $whole);
    
    if ($whole > 0) {
        $words = convertSimple($whole) . ' Rupees';
    }
    
    if ($decimal > 0) {
        if (!empty($words)) {
            $words .= ' and ';
        }
        $words .= convertSimple((int)$decimal) . ' Paise';
    }
    
    if (empty($words)) {
        $words = 'Zero Rupees';
    }
    
    return $words . ' Only';
}

function convertSimple($num) {
    $ones = array(
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    );
    
    $tens = array(
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    );
    
    if ($num == 0) return 'Zero';
    
    $result = '';
    
    // Crores
    if ($num >= 10000000) {
        $crores = floor($num / 10000000);
        $result .= convertSimple($crores) . ' Crore ';
        $num %= 10000000;
    }
    
    // Lakhs
    if ($num >= 100000) {
        $lakhs = floor($num / 100000);
        $result .= convertSimple($lakhs) . ' Lakh ';
        $num %= 100000;
    }
    
    // Thousands
    if ($num >= 1000) {
        $thousands = floor($num / 1000);
        $result .= convertSimple($thousands) . ' Thousand ';
        $num %= 1000;
    }
    
    // Hundreds
    if ($num >= 100) {
        $hundreds = floor($num / 100);
        $result .= convertSimple($hundreds) . ' Hundred ';
        $num %= 100;
    }
    
    // Last two digits
    if ($num > 0) {
        if (!empty($result)) {
            $result .= 'and ';
        }
        
        if ($num < 20) {
            $result .= $ones[$num];
        } else {
            $tens_digit = floor($num / 10);
            $units_digit = $num % 10;
            $result .= $tens[$tens_digit];
            if ($units_digit > 0) {
                $result .= ' ' . $ones[$units_digit];
            }
        }
    }
    
    return trim($result);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?> - <?php echo htmlspecialchars(COMPANY_NAME); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Simplified CSS - Remove complex features */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: white;
            padding: 20px;
        }
        
        .invoice-container {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            padding: 20mm;
            background: white;
            position: relative;
        }
        
        /* Watermark - SIMPLIFIED */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            opacity: 0.05;
            z-index: -1;
            pointer-events: none;
        }
        
        .watermark-text {
            font-size: 80px;
            font-weight: bold;
            color: rgba(0,0,0,0.1);
            white-space: nowrap;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        
        .company-info h2 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .invoice-title h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        th {
            background: #2c3e50;
            color: white;
            padding: 10px;
            text-align: left;
        }
        
        td {
            padding: 10px;
            border: 1px solid #ddd;
        }
        
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        /* Totals */
        .totals {
            margin-top: 30px;
            text-align: right;
        }
        
        .totals table {
            width: 300px;
            margin-left: auto;
        }
        
        .grand-total {
            font-weight: bold;
            font-size: 18px;
            border-top: 2px solid #333;
        }
        
        /* Footer */
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        
        /* Print controls */
        .print-controls {
            text-align: center;
            margin-top: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
        }
        
        .btn:hover {
            background: #218838;
        }
        
        /* Print styles */
        @media print {
            body {
                padding: 0;
                margin: 0;
            }
            
            .invoice-container {
                width: 100%;
                padding: 0;
                margin: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            .watermark {
                opacity: 0.08;
            }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Simple Watermark -->
        <div class="watermark">
            <div class="watermark-text"><?php echo htmlspecialchars(COMPANY_NAME); ?></div>
        </div>
        
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                 <img src="liyansh1.png">
                <h2><?php echo htmlspecialchars(COMPANY_NAME); ?></h2>
                <p><?php echo htmlspecialchars(COMPANY_ADDRESS); ?></p>
                <p>GSTIN: <?php echo htmlspecialchars(COMPANY_GSTIN); ?></p>
                <p>ACCOUNT NO: <?php echo htmlspecialchars(COMPANY_ACCOUNT_NO); ?></p>
                 <p>IFSC: <?php echo htmlspecialchars(COMPANY_IFSC); ?></p>
                 <p>BRANCH: <?php echo htmlspecialchars(COMPANY_BANKBRANCH); ?></p>
                <p>Phone: <?php echo htmlspecialchars(COMPANY_PHONE); ?></p>
            </div>
            <div class="invoice-title">
                <h1>TAX INVOICE</h1>
                <p><strong>Invoice #:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                <p><strong>Date:</strong> <?php echo htmlspecialchars($invoice['formatted_date']); ?></p>
            </div>
        </div>
        
        <!-- Customer Info -->
        <div style="margin: 20px 0;">
            <h3>Bill To:</h3>
            <p><strong><?php echo htmlspecialchars($invoice['customer_name']); ?></strong></p>
            <p><?php echo htmlspecialchars($invoice['address']); ?></p>
            <p><?php echo htmlspecialchars($invoice['city']) . ', ' . htmlspecialchars($invoice['state']) . ' - ' . $invoice['pincode']; ?></p>
            <p>GSTIN: <?php echo htmlspecialchars($invoice['gstin'] ?: 'N/A'); ?></p>
        </div>
        
        <!-- Items Table -->
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>HSN</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_qty = 0;
                $total_amount = 0;
                foreach ($items as $index => $item): 
                    $item_total = $item['quantity'] * $item['unit_price'];
                    $total_qty += $item['quantity'];
                    $total_amount += $item_total;
                ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($item['hsn_code']); ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td>₹<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>₹<?php echo number_format($item_total, 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totals -->
        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal:</td>
                    <td>₹<?php echo number_format($invoice['subtotal'], 2); ?></td>
                </tr>
                <tr>
                    <td>Discount:</td>
                    <td>₹<?php echo number_format($invoice['discount'], 2); ?></td>
                </tr>
                <?php if ($invoice['cgst'] > 0): ?>
                <tr>
                    <td>CGST:</td>
                    <td>₹<?php echo number_format($invoice['cgst'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($invoice['sgst'] > 0): ?>
                <tr>
                    <td>SGST:</td>
                    <td>₹<?php echo number_format($invoice['sgst'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($invoice['igst'] > 0): ?>
                <tr>
                    <td>IGST:</td>
                    <td>₹<?php echo number_format($invoice['igst'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand-total">
                    <td>Grand Total:</td>
                    <td>₹<?php echo number_format($invoice['total_amount'], 2); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p><strong>Amount in Words:</strong>  <?php echo simpleAmountToWords($invoice['total_amount']); ?> </p>
            <div style="margin-top: 40px;">
                <h5>Terms & Conditions:</h5>
                    <p>Goods once sold will not be taken back or exchanged</p>
                    <p>Payment should be made before delivery of order invoice date</p>
                    <p>All disputes subject to Varanasi jurisdiction only</p>
                    <p>This is a computer generated invoice. No signature required.</p>
                <p>Thank you for your business!</p>
                <p style="margin-top: 40px;">
                    <strong>Authorized Signatory</strong><br>
                    <?php echo htmlspecialchars(COMPANY_NAME); ?>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Print Controls -->
    <div class="print-controls no-print">
        <button onclick="window.print()" class="btn">
            <i class="fas fa-print"></i> Print Invoice
        </button>
        <button onclick="window.close()" class="btn" style="background: #6c757d;">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
    
    <script>
        // Auto-print after 1 second
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        };
        
        // Close window after print
        window.onafterprint = function() {
            setTimeout(function() {
                window.close();
            }, 500);
        };
    </script>
</body>
</html>
<?php
// print_purchase_slip.php
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

$purchase_id = intval($_GET['id']);

// Get purchase details
$stmt = $conn->prepare("
    SELECT po.*, v.*, 
           DATE_FORMAT(po.purchase_date, '%d/%m/%Y') as formatted_date,
           DATE_FORMAT(po.created_at, '%d/%m/%Y %h:%i %p') as created_at_formatted
    FROM purchase_orders po
    JOIN vendors v ON po.vendor_id = v.vendor_id
    WHERE po.purchase_id = ?
");
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$purchase = $stmt->get_result()->fetch_assoc();

// Get purchase items
$stmt = $conn->prepare("
    SELECT poi.*, p.product_name, p.hsn_code, p.unit,
           (poi.quantity * poi.purchase_price) as item_total,
           (poi.quantity * poi.purchase_price * poi.discount_percent / 100) as item_discount,
           ((poi.quantity * poi.purchase_price) - (poi.quantity * poi.purchase_price * poi.discount_percent / 100)) as taxable_amount,
           (((poi.quantity * poi.purchase_price) - (poi.quantity * poi.purchase_price * poi.discount_percent / 100)) * poi.gst_rate / 100) as gst_amount
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.product_id
    WHERE poi.purchase_id = ?
    ORDER BY poi.item_id
");
$stmt->bind_param("i", $purchase_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// FIXED: Function to convert number to words (Indian numbering system)
function amountToWords($number) {
    // Ensure it's a valid number
    $number = floatval($number);
    
    // Split into rupees and paise
    $rupees = intval($number);
    $paise = round(($number - $rupees) * 100);
    
    $words = array(
        '0' => '', '1' => 'One', '2' => 'Two', '3' => 'Three', '4' => 'Four',
        '5' => 'Five', '6' => 'Six', '7' => 'Seven', '8' => 'Eight', '9' => 'Nine',
        '10' => 'Ten', '11' => 'Eleven', '12' => 'Twelve', '13' => 'Thirteen',
        '14' => 'Fourteen', '15' => 'Fifteen', '16' => 'Sixteen',
        '17' => 'Seventeen', '18' => 'Eighteen', '19' => 'Nineteen',
        '20' => 'Twenty', '30' => 'Thirty', '40' => 'Forty', '50' => 'Fifty',
        '60' => 'Sixty', '70' => 'Seventy', '80' => 'Eighty', '90' => 'Ninety'
    );
    
    // Convert rupees to words
    $rupee_words = "";
    
    if ($rupees == 0) {
        $rupee_words = "Zero";
    } else {
        // Convert crores
        if ($rupees >= 10000000) {
            $crores = floor($rupees / 10000000);
            $rupee_words .= convertToWords($crores, $words) . " Crore ";
            $rupees %= 10000000;
        }
        
        // Convert lakhs
        if ($rupees >= 100000) {
            $lakhs = floor($rupees / 100000);
            $rupee_words .= convertToWords($lakhs, $words) . " Lakh ";
            $rupees %= 100000;
        }
        
        // Convert thousands
        if ($rupees >= 1000) {
            $thousands = floor($rupees / 1000);
            $rupee_words .= convertToWords($thousands, $words) . " Thousand ";
            $rupees %= 1000;
        }
        
        // Convert hundreds
        if ($rupees >= 100) {
            $hundreds = floor($rupees / 100);
            $rupee_words .= $words[$hundreds] . " Hundred ";
            $rupees %= 100;
        }
        
        // Convert remaining (1-99)
        if ($rupees > 0) {
            if ($rupees < 20) {
                $rupee_words .= $words[$rupees] . " ";
            } else {
                $tens = floor($rupees / 10) * 10;
                $ones = $rupees % 10;
                $rupee_words .= $words[$tens] . " ";
                if ($ones > 0) {
                    $rupee_words .= $words[$ones] . " ";
                }
            }
        }
        
        $rupee_words = trim($rupee_words);
    }
    
    // Convert paise to words
    $paise_words = "";
    if ($paise > 0) {
        if ($paise < 20) {
            $paise_words = $words[$paise];
        } else {
            $tens = floor($paise / 10) * 10;
            $ones = $paise % 10;
            $paise_words = $words[$tens];
            if ($ones > 0) {
                $paise_words .= " " . $words[$ones];
            }
        }
    }
    
    // Build final string
    $result = $rupee_words . " Rupees";
    
    if ($paise_words != "") {
        $result .= " and " . $paise_words . " Paise";
    }
    
    return $result . " Only";
}

// Helper function for conversion
function convertToWords($num, $words) {
    if ($num == 0) return "";
    
    $result = "";
    
    if ($num >= 100) {
        $hundreds = floor($num / 100);
        $result .= $words[$hundreds] . " Hundred ";
        $num %= 100;
    }
    
    if ($num > 0) {
        if ($num < 20) {
            $result .= $words[$num] . " ";
        } else {
            $tens = floor($num / 10) * 10;
            $ones = $num % 10;
            $result .= $words[$tens] . " ";
            if ($ones > 0) {
                $result .= $words[$ones] . " ";
            }
        }
    }
    
    return trim($result);
}

$amount_in_words = amountToWords($purchase['total_amount']);

// Generate QR code data
$qr_data = "PO: " . $purchase['po_number'] . "\n" .
           "Date: " . $purchase['formatted_date'] . "\n" .
           "Vendor: " . substr($purchase['vendor_name'], 0, 20) . "\n" .
           "Amount: ₹" . number_format($purchase['total_amount'], 2) . "\n" .
           COMPANY_NAME;

// Generate QR code URL
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=" . urlencode($qr_data);
?>

<!DOCTYPE html>
<html>
<head>
    <title>PURCHASE ORDER - <?php echo $purchase['po_number']; ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        
        @media print {
            body {
                font-family: 'Arial', sans-serif;
                margin: 0;
                padding: 0;
                font-size: 10px;
                color: #000;
                background: #fff;
                width: 210mm;
                height: 297mm;
            }
            
            .no-print { display: none !important; }
            .invoice-container {
                width: 190mm;
                min-height: 277mm;
                margin: 0 auto;
                padding: 0;
            }
        }
        
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            font-size: 10px;
            color: #000;
            background: #fff;
            width: 210mm;
        }
        
        .invoice-container {
            width: 190mm;
            min-height: 277mm;
            margin: 0 auto;
            border: 1px solid #ccc;
            background: #fff;
            padding: 5mm;
            box-sizing: border-box;
        }
        
        /* HEADER WITH SWAPPED LOGO/QR POSITIONS */
        .invoice-header {
            width: 100%;
            border-bottom: 2px solid #000;
            padding: 2mm 0;
            margin-bottom: 3mm;
        }
        
        .header-row {
            display: flex;
            width: 100%;
            margin-bottom: 2mm;
        }
        
        /* LEFT: QR CODE (NOW ON LEFT) */
        .qr-section {
            width: 20%;
            text-align: center;
        }
        
        .qr-box {
            height: 25mm;
            border: 1px solid #999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            font-size: 8px;
            color: #666;
            padding: 1mm;
        }
        
        .qr-img {
            max-width: 100%;
            max-height: 100%;
        }
        
        /* CENTER: COMPANY INFO */
        .company-section {
            width: 60%;
            text-align: center;
            padding: 0 5mm;
        }
        
        /* RIGHT: LOGO (NOW ON RIGHT) */
        .logo-section {
            width: 20%;
            text-align: center;
        }
        
        .logo-box {
            height: 25mm;
            border: 1px solid #999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            font-size: 8px;
            color: #666;
            padding: 1mm;
        }
        
        .logo-img {
            max-width: 100%;
            max-height: 100%;
        }
        
        .company-name {
            font-size: 14px;
            font-weight: bold;
            color: #000;
            margin: 0;
            line-height: 1.2;
        }
        
        .company-tagline {
            font-size: 10px;
            color: #333;
            margin: 1mm 0;
            font-weight: bold;
        }
        
        .company-address {
            font-size: 8px;
            color: #333;
            line-height: 1.2;
            margin: 1mm 0;
        }
        
        .gst-info {
            font-size: 8px;
            font-weight: bold;
            margin: 1mm 0;
        }
        
        .contact-info {
            font-size: 8px;
            margin: 1mm 0;
        }
        
        /* INVOICE TITLE */
        .invoice-title {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            margin: 2mm 0;
            text-transform: uppercase;
            color: #000;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 2mm 0;
        }
        
        /* PURCHASE INFO */
        .purchase-info {
            display: flex;
            width: 100%;
            margin-bottom: 3mm;
        }
        
        .purchase-info-left {
            width: 50%;
            padding-right: 2mm;
        }
        
        .purchase-info-right {
            width: 50%;
            padding-left: 2mm;
        }
        
        .info-box {
            border: 1px solid #000;
            padding: 2mm;
            margin-bottom: 2mm;
        }
        
        .info-title {
            font-weight: bold;
            margin-bottom: 1mm;
            font-size: 9px;
            color: #000;
        }
        
        .info-content {
            font-size: 8px;
            line-height: 1.2;
        }
        
        /* ORDER SUMMARY BOX IN HEADER */
        .order-summary {
            border: 1px solid #000;
            padding: 2mm;
            margin-top: 2mm;
            background: #f9f9f9;
        }
        
        .summary-title {
            font-weight: bold;
            text-align: center;
            margin-bottom: 1mm;
            font-size: 9px;
        }
        
        /* ITEMS TABLE */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 2mm 0;
            font-size: 8px;
            table-layout: fixed;
        }
        
        .items-table th {
            border: 1px solid #000;
            background: #f0f0f0;
            padding: 1mm;
            font-size: 8px;
            text-align: center;
            font-weight: bold;
            height: 6mm;
        }
        
        .items-table td {
            border: 1px solid #000;
            padding: 1mm;
            font-size: 8px;
            text-align: center;
            vertical-align: top;
            height: 5mm;
        }
        
        .items-table .description {
            text-align: left;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .items-table .number {
            text-align: right;
        }
        
        /* Fixed column widths */
        .col-sno { width: 4%; }
        .col-desc { width: 28%; }
        .col-hsn { width: 8%; }
        .col-unit { width: 6%; }
        .col-qty { width: 6%; }
        .col-rate { width: 9%; }
        .col-disc { width: 7%; }
        .col-amount { width: 9%; }
        .col-gstp { width: 7%; }
        .col-gst { width: 8%; }
        .col-total { width: 8%; }
        
        /* TOTALS SECTION */
        .totals-section {
            margin-top: 3mm;
            width: 100%;
        }
        
        .totals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        
        .totals-table td {
            padding: 1mm;
        }
        
        .totals-table .label {
            text-align: right;
            padding-right: 2mm;
            width: 70%;
            font-weight: bold;
        }
        
        .totals-table .value {
            text-align: right;
            border: 1px solid #000;
            padding: 1mm 2mm;
            width: 30%;
            font-weight: bold;
        }
        
        .total-row {
            font-weight: bold;
            background: #f0f0f0;
        }
        
        /* AMOUNT IN WORDS */
        .amount-words {
            border: 1px solid #000;
            padding: 2mm;
            margin: 2mm 0;
            font-size: 9px;
            background: #f8f8f8;
            line-height: 1.2;
        }
        
        .amount-label {
            font-weight: bold;
            margin-bottom: 1mm;
        }
        
        /* FOOTER */
        .footer {
            margin-top: 3mm;
            border-top: 1px solid #000;
            padding-top: 2mm;
        }
        
        .signatures {
            display: flex;
            width: 100%;
        }
        
        .signature-box {
            width: 50%;
            text-align: center;
            padding: 0 2mm;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            width: 60mm;
            margin: 15mm auto 1mm;
        }
        
        .signature-text {
            font-size: 9px;
            font-weight: bold;
        }
        
        /* TERMS AND CONDITIONS */
        .terms {
            margin-top: 3mm;
            font-size: 8px;
            line-height: 1.2;
            border-top: 1px solid #ccc;
            padding-top: 2mm;
        }
        
        .terms-title {
            font-weight: bold;
            margin-bottom: 1mm;
            font-size: 9px;
        }
        
        /* STATUS BADGES */
        .status-badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 2px;
            font-size: 8px;
            font-weight: bold;
        }
        
        .status-pending {
            background: #ffeb3b;
            color: #000;
        }
        
        .status-received {
            background: #4caf50;
            color: #fff;
        }
        
        .status-cancelled {
            background: #f44336;
            color: #fff;
        }
        
        /* PRINT CONTROLS */
        .print-controls {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        
        .print-btn {
            background: #2196f3;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
        }
        
        /* ORDER DETAILS WITH QR */
        .order-details-qr {
            border: 1px solid #000;
            padding: 2mm;
            margin-top: 2mm;
            text-align: center;
        }
        
        .qr-small {
            width: 30mm;
            height: 30mm;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #ccc;
        }
        
        .qr-small-img {
            max-width: 100%;
            max-height: 100%;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- HEADER WITH SWAPPED POSITIONS -->
        <div class="invoice-header">
            <div class="header-row">
                <!-- LEFT: QR CODE -->
                <div class="qr-section">
                    <div class="qr-box">
                        <img src="<?php echo $qr_url; ?>" alt="QR Code" class="qr-img" 
                             onerror="this.onerror=null; this.src='data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"80\" height=\"80\"><rect width=\"80\" height=\"80\" fill=\"%23f0f0f0\"/><text x=\"40\" y=\"40\" font-family=\"Arial\" font-size=\"10\" text-anchor=\"middle\" dy=\".3em\"></text></svg>';">
                    </div>
                    <div style="font-size: 7px; margin-top: 1mm;">Scan for Details</div>
                </div>
                
                <!-- CENTER: COMPANY INFO -->
                <div class="company-section">
                    <div class="company-name"><?php echo COMPANY_NAME; ?></div>
                    <div class="company-tagline">WHOLESALE TEXTILE MERCHANT</div>
                    <div class="company-address"><?php echo COMPANY_ADDRESS; ?></div>
                    <div class="gst-info">GSTIN: <?php echo COMPANY_GSTIN; ?>  </div>
                    <div class="contact-info">
                        📞 <?php echo COMPANY_PHONE; ?> | ✉️ <?php echo COMPANY_EMAIL; ?>
                    </div>
                    
                    <!-- ORDER SUMMARY -->
                    <div class="order-summary">
                        <div class="summary-title">ORDER SUMMARY</div>
                        <div style="font-size: 8px;">
                            <strong>PO:</strong> <?php echo $purchase['po_number']; ?> | 
                            <strong>Date:</strong> <?php echo $purchase['formatted_date']; ?><br>
                            <strong>Amount:</strong> ₹<?php echo number_format($purchase['total_amount'], 2); ?>
                        </div>
                    </div>
                </div>
                
                <!-- RIGHT: LOGO -->
                <div class="logo-section">
                    <div class="logo-box">
                        <?php 
                        // Check for logo files in common locations
                        $logo_paths = ['liyansh1.png', 'liyansh1.png', 'liyansh1.png', 'liyansh1.png', 'liyansh1.png'];
                        $logo_found = false;
                        
                        foreach ($logo_paths as $path) {
                            if (file_exists($path)) {
                                echo '<img src="' . $path . '" alt="Company Logo" class="logo-img">';
                                $logo_found = true;
                                break;
                            }
                        }
                        
                        if (!$logo_found): 
                        ?>
                        <div style="text-align: center;">
                            <div style="font-size: 12px; margin-bottom: 2mm; font-weight: bold;">LOGO</div>
                            <div style="font-size: 8px;"><?php echo substr(COMPANY_NAME, 0, 15); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 7px; margin-top: 1mm;"></div>
                </div>
            </div>
        </div>
        
        <!-- INVOICE TITLE -->
        <div class="invoice-title">
            PURCHASE ORDER
        </div>
        
        <!-- PURCHASE INFO -->
        <div class="purchase-info">
            <div class="purchase-info-left">
                <div class="info-box">
                    <div class="info-title">VENDOR DETAILS</div>
                    <div class="info-content">
                        <strong><?php echo htmlspecialchars($purchase['vendor_name']); ?></strong><br>
                        <?php if ($purchase['company_name']): ?>
                        <?php echo htmlspecialchars($purchase['company_name']); ?><br>
                        <?php endif; ?>
                        <?php echo htmlspecialchars(substr($purchase['address'], 0, 60)); ?><br>
                        <?php echo htmlspecialchars($purchase['city']); ?>, <?php echo $purchase['state']; ?> - <?php echo $purchase['pincode']; ?><br>
                        GSTIN: <?php echo $purchase['gstin'] ?: 'N/A'; ?><br>
                        📞 <?php echo $purchase['phone']; ?>
                        <?php if ($purchase['email']): ?><br>✉️ <?php echo $purchase['email']; ?><?php endif; ?>
                    </div>
                </div>
                
                <!-- ORDER DETAILS WITH SMALL QR -->
                <div class="">
                    <div class="info-title"></div>
                    <div class="qr-small">
                        <img src="liyansh1.png" alt="" class="qr-small-img"
                             onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML='QR Code<br><small>Not Available</small>';">
                    </div>
                    <div style="font-size: 7px; margin-top: 1mm;">
                       
                    </div>
                </div>
            </div>
            
            <div class="purchase-info-right">
                <div class="info-box">
                    <div class="info-title">ORDER & DELIVERY</div>
                    <div class="info-content">
                        <table style="width:100%; font-size:8px;">
                            <tr>
                                <td><strong>PO Number:</strong></td>
                                <td><?php echo $purchase['po_number']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Order Date:</strong></td>
                                <td><?php echo $purchase['formatted_date']; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Order Status:</strong></td>
                                <td>
                                    <span class="status-badge status-<?php echo $purchase['status']; ?>">
                                        <?php echo strtoupper($purchase['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Payment Status:</strong></td>
                                <td><?php echo strtoupper($purchase['payment_status']); ?></td>
                            </tr>
                           
                            <tr>
                                <td><strong>Payment Terms:</strong></td>
                                <td><?php echo $purchase['payment_terms'] ?: '30 Days'; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Generated On:</strong></td>
                                <td><?php echo $purchase['created_at_formatted']; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <div class="info-box" style="margin-top: 2mm;">
                    <div class="info-title">SHIP TO</div>
                    <div class="info-content">
                        <strong><?php echo COMPANY_NAME; ?></strong><br>
                        <?php echo COMPANY_ADDRESS; ?><br>
                        GSTIN: <?php echo COMPANY_GSTIN; ?><br>
                        📞 <?php echo COMPANY_PHONE; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ITEMS TABLE -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="col-sno">#</th>
                    <th class="col-desc">PRODUCT DESCRIPTION</th>
                    <th class="col-hsn">HSN</th>
                    <th class="col-unit">UNIT</th>
                    <th class="col-qty">QTY</th>
                    <th class="col-rate">RATE (₹)</th>
                    <th class="col-disc">DISC %</th>
                    <th class="col-amount">AMOUNT (₹)</th>
                    <th class="col-gstp">GST %</th>
                    <th class="col-gst">GST (₹)</th>
                    <th class="col-total">TOTAL (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                $total_qty = 0;
                
                foreach ($items as $item): 
                    $item_total = ($item['quantity'] * $item['purchase_price']);
                    $item_discount = $item_total * ($item['discount_percent'] / 100);
                    $taxable_amount = $item_total - $item_discount;
                    $gst_amount = $taxable_amount * ($item['gst_rate'] / 100);
                    $item_grand_total = $taxable_amount + $gst_amount;
                    
                    $total_qty += $item['quantity'];
                ?>
                <tr>
                    <td class="col-sno"><?php echo $counter++; ?></td>
                    <td class="col-desc description" title="<?php echo htmlspecialchars($item['product_name']); ?>">
                        <?php echo htmlspecialchars(substr($item['product_name'], 0, 30)); ?>
                        <?php if (strlen($item['product_name']) > 30): ?>...<?php endif; ?>
                    </td>
                    <td class="col-hsn"><?php echo $item['hsn_code']; ?></td>
                    <td class="col-unit"><?php echo $item['unit']; ?></td>
                    <td class="col-qty number"><?php echo number_format($item['quantity'], 2); ?></td>
                    <td class="col-rate number"><?php echo number_format($item['purchase_price'], 2); ?></td>
                    <td class="col-disc number"><?php echo number_format($item['discount_percent'], 2); ?></td>
                    <td class="col-amount number"><?php echo number_format($item_total, 2); ?></td>
                    <td class="col-gstp number"><?php echo number_format($item['gst_rate'], 2); ?></td>
                    <td class="col-gst number"><?php echo number_format($gst_amount, 2); ?></td>
                    <td class="col-total number"><?php echo number_format($item_grand_total, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Fill empty rows -->
                <?php for ($i = count($items); $i < 10; $i++): ?>
                <tr>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
        
        <!-- TOTALS SECTION -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td class="label">Total Quantity:</td>
                    <td class="value"><?php echo $total_qty; ?></td>
                </tr>
                <tr>
                    <td class="label">Sub Total:</td>
                    <td class="value">₹<?php echo number_format($purchase['subtotal'], 2); ?></td>
                </tr>
                <tr>
                    <td class="label">Discount:</td>
                    <td class="value">₹<?php echo number_format($purchase['discount'], 2); ?></td>
                </tr>
                <tr>
                    <td class="label">CGST (<?php echo GST_RATE_CGST; ?>%):</td>
                    <td class="value">₹<?php echo number_format($purchase['cgst'], 2); ?></td>
                </tr>
                <tr>
                    <td class="label">SGST (<?php echo GST_RATE_SGST; ?>%):</td>
                    <td class="value">₹<?php echo number_format($purchase['sgst'], 2); ?></td>
                </tr>
                <tr>
                    <td class="label">IGST (<?php echo GST_RATE_IGST; ?>%):</td>
                    <td class="value">₹<?php echo number_format($purchase['igst'], 2); ?></td>
                </tr>
                <tr class="total-row">
                    <td class="label">GRAND TOTAL:</td>
                    <td class="value">₹<?php echo number_format($purchase['total_amount'], 2); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- AMOUNT IN WORDS (FIXED) -->
        <div class="amount-words">
            <div class="amount-label">Amount in Words:</div>
            <div>
                <strong><?php echo $amount_in_words; ?></strong>
                <div style="font-size: 8px; color: #666; margin-top: 1mm;">
                    <em></em>
                </div>
            </div>
        </div>
        
        <!-- FOOTER SIGNATURES -->
        <div class="footer">
            <div class="signatures">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-text">FOR VENDOR</div>
                    <div style="font-size: 8px; margin-top: 1mm;">
                        Signature with Stamp
                    </div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-text">FOR <?php echo COMPANY_NAME; ?></div>
                    <div style="font-size: 8px; margin-top: 1mm;">
                        Authorized Signatory
                    </div>
                </div>
            </div>
        </div>
        
        <!-- TERMS AND CONDITIONS -->
        <div class="terms">
            <div class="terms-title">TERMS & CONDITIONS</div>
            <div>
                1. Goods as per specifications. 2. Delivery on time. 3. Payment within <?php echo $purchase['payment_terms'] ?: '30'; ?> days. 
                4. GST as applicable. 5. Interest @18% p.a. on overdue. 6. Dispute jurisdiction: <?php echo $purchase['city'] ?: 'Surat'; ?>. 
                7. Computer generated document.
            </div>
        </div>
    </div>
    
    <!-- PRINT CONTROLS -->
    <div class="print-controls no-print">
        <button onclick="window.print()" class="print-btn">
            🖨️ Print Purchase Order
        </button>
        <button onclick="window.close()" class="print-btn" style="background: #6c757d; margin-top: 5px;">
            ❌ Close Window
        </button>
    </div>
    
    <script>
        // Auto-print after 1 second
        setTimeout(function() {
            window.print();
        }, 1000);
        
        // Optional: Close after print
        window.onafterprint = function() {
            setTimeout(function() {
                // window.close(); // Uncomment to auto-close
            }, 1000);
        };
        
        // Test amount converter
        console.log("Test amount converter:");
        console.log("1119 = ", "<?php echo numberToWords(1119); ?>");
        console.log("<?php echo $purchase['total_amount']; ?> = ", "<?php echo $amount_in_words; ?>");
    </script>
</body>
</html>
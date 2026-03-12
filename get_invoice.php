<?php
// get_invoice.php
session_start();
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

if (isset($_GET['id'])) {
    $invoice_id = intval($_GET['id']);
    
    // Get invoice details
    $stmt = $conn->prepare("
        SELECT i.*, c.*, DATE_FORMAT(i.invoice_date, '%d/%m/%Y') as formatted_date,
               DATE_FORMAT(i.due_date, '%d/%m/%Y') as due_date_formatted
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.customer_id 
        WHERE i.invoice_id = ?
    ");
    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    
    if ($invoice) {
        // Get invoice items
        $stmt = $conn->prepare("
            SELECT ii.*, p.product_name, p.hsn_code, p.description
            FROM invoice_items ii 
            JOIN products p ON ii.product_id = p.product_id 
            WHERE ii.invoice_id = ?
        ");
        $stmt->bind_param("i", $invoice_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<style>
    .modal-invoice {
        width: 100%;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .invoice-header-modal {
        display: flex;
        justify-content: space-between;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 2px solid #4a6491;
    }
    
    .company-info-modal h2 {
        color: #2c3e50;
        font-size: 22px;
        margin-bottom: 8px;
    }
    
    .company-info-modal p {
        margin: 3px 0;
        font-size: 13px;
        color: #555;
    }
    
    .invoice-meta-modal {
        text-align: right;
    }
    
    .invoice-meta-modal h3 {
        color: #2c3e50;
        font-size: 20px;
        margin-bottom: 10px;
    }
    
    .invoice-meta-modal p {
        margin: 3px 0;
        font-size: 13px;
    }
    
    .customer-info-modal {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid #e1e5eb;
    }
    
    .customer-info-modal h4 {
        color: #2c3e50;
        margin-bottom: 10px;
        font-size: 16px;
    }
    
    .customer-info-modal p {
        margin: 3px 0;
        font-size: 13px;
    }
    
    .invoice-table-modal {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        font-size: 13px;
    }
    
    .invoice-table-modal th {
        background: #4a6491;
        color: white;
        padding: 10px 8px;
        text-align: left;
        font-weight: 600;
    }
    
    .invoice-table-modal td {
        padding: 10px 8px;
        border-bottom: 1px solid #e1e5eb;
    }
    
    .invoice-table-modal tr:hover {
        background: #f8f9fa;
    }
    
    .totals-table-modal {
        width: 100%;
        max-width: 350px;
        margin-left: auto;
        margin-top: 20px;
        font-size: 14px;
    }
    
    .totals-table-modal td {
        padding: 8px 10px;
        border-bottom: 1px solid #e1e5eb;
    }
    
    .total-row-modal {
        font-weight: 700;
        font-size: 16px;
        color: #2c3e50;
        background-color: #f8f9fa;
        border-top: 2px solid #4a6491;
    }
    
    .amount-col-modal {
        text-align: right;
        font-weight: 600;
    }
    
    .modal-buttons {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin-top: 25px;
        padding-top: 20px;
        border-top: 1px solid #e1e5eb;
    }
    
    .modal-btn {
        padding: 10px 20px;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }
    
    .modal-btn:hover {
        transform: translateY(-2px);
    }
    
    .modal-btn-print {
        background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
        color: white;
    }
    
    .modal-btn-close {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        color: white;
    }
    
    .payment-status {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .status-paid {
        background: #d4edda;
        color: #155724;
    }
    
    .status-unpaid {
        background: #f8d7da;
        color: #721c24;
    }
    
    .status-partial {
        background: #fff3cd;
        color: #856404;
    }
</style>

<div class="modal-invoice">
    <div class="invoice-header-modal">
        <div class="company-info-modal">
            <h2><?php echo COMPANY_NAME; ?></h2>
            <p><?php echo COMPANY_ADDRESS; ?></p>
            <p>GSTIN: <?php echo COMPANY_GSTIN; ?></p>
            <p>Phone: <?php echo COMPANY_PHONE; ?> | Email: <?php echo COMPANY_EMAIL; ?></p>
        </div>
        <div class="invoice-meta-modal">
            <h3>TAX INVOICE</h3>
            <p><strong>Invoice #:</strong> <?php echo $invoice['invoice_number']; ?></p>
            <p><strong>Date:</strong> <?php echo $invoice['formatted_date']; ?></p>
            <p><strong>Due Date:</strong> <?php echo $invoice['due_date_formatted']; ?></p>
            <p><strong>Status:</strong> 
                <span class="payment-status status-<?php echo $invoice['payment_status']; ?>">
                    <?php echo ucfirst($invoice['payment_status']); ?>
                </span>
            </p>
        </div>
    </div>
    
    <div class="customer-info-modal">
        <h4>Bill To:</h4>
        <p><strong><?php echo htmlspecialchars($invoice['customer_name']); ?></strong></p>
        <p><?php echo htmlspecialchars($invoice['address']); ?></p>
        <p><?php echo htmlspecialchars($invoice['city']) . ', ' . htmlspecialchars($invoice['state']) . ' - ' . $invoice['pincode']; ?></p>
        <p>GSTIN: <?php echo $invoice['gstin']; ?></p>
        <p>Phone: <?php echo $invoice['phone']; ?> | Email: <?php echo $invoice['email']; ?></p>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="invoice-table-modal">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product Description</th>
                    <th>HSN</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Discount</th>
                    <th>Tax</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $item_count = 1;
                foreach ($items as $item): 
                ?>
                <tr>
                    <td><?php echo $item_count++; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                        <?php if (!empty($item['description'])): ?>
                        <br><small style="color: #666; font-size: 11px;"><?php echo htmlspecialchars(substr($item['description'], 0, 50)); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $item['hsn_code']; ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td>₹<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>₹<?php echo number_format($item['discount'], 2); ?></td>
                    <td>₹<?php echo number_format($item['tax_amount'], 2); ?></td>
                    <td style="font-weight: 600;">₹<?php echo number_format($item['total_amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div>
        <table class="totals-table-modal">
            <tr>
                <td>Subtotal:</td>
                <td class="amount-col-modal">₹<?php echo number_format($invoice['subtotal'], 2); ?></td>
            </tr>
            <tr>
                <td>Discount:</td>
                <td class="amount-col-modal">₹<?php echo number_format($invoice['discount'], 2); ?></td>
            </tr>
            <?php if ($invoice['cgst'] > 0): ?>
            <tr>
                <td>CGST (<?php echo GST_RATE_CGST; ?>%):</td>
                <td class="amount-col-modal">₹<?php echo number_format($invoice['cgst'], 2); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($invoice['sgst'] > 0): ?>
            <tr>
                <td>SGST (<?php echo GST_RATE_SGST; ?>%):</td>
                <td class="amount-col-modal">₹<?php echo number_format($invoice['sgst'], 2); ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($invoice['igst'] > 0): ?>
            <tr>
                <td>IGST (<?php echo GST_RATE_IGST; ?>%):</td>
                <td class="amount-col-modal">₹<?php echo number_format($invoice['igst'], 2); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="total-row-modal">
                <td>Grand Total:</td>
                <td class="amount-col-modal">₹<?php echo number_format($invoice['total_amount'], 2); ?></td>
            </tr>
        </table>
    </div>
    
    <div class="modal-buttons">
        <button onclick="printModalInvoice()" class="modal-btn modal-btn-print">
            <i class="fas fa-print"></i> Print Invoice
        </button>
        <button onclick="openPrintPage(<?php echo $invoice_id; ?>)" class="modal-btn modal-btn-print" style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);">
            <i class="fas fa-external-link-alt"></i> Open Full Page
        </button>
        <button onclick="closeModal('savedInvoiceModal')" class="modal-btn modal-btn-close">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
</div>

<script>
function printModalInvoice() {
    const printContent = document.querySelector('.modal-invoice').outerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invoice #<?php echo $invoice['invoice_number']; ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .invoice-table-modal { width: 100%; border-collapse: collapse; }
                .invoice-table-modal th, .invoice-table-modal td { border: 1px solid #ddd; padding: 8px; }
                .invoice-table-modal th { background: #f2f2f2; }
            </style>
        </head>
        <body>
            ${printContent}
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 100);
                };
            <\/script>
        </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

function openPrintPage(invoiceId) {
    window.open(`print_invoice.php?id=${invoiceId}`, '_blank');
}
</script>
<?php
    } else {
        echo '<div style="padding: 30px; text-align: center; color: #721c24; background: #f8d7da; border-radius: 8px;">Invoice not found!</div>';
    }
} else {
    echo '<div style="padding: 30px; text-align: center; color: #721c24; background: #f8d7da; border-radius: 8px;">No invoice ID provided!</div>';
}
?>
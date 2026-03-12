<?php
// purchase_orders.php
require_once 'config1.php';



// Generate PO number
function generatePONumber($conn) {
    $year = date('Y');
    $month = date('m');
    $result = $conn->query("SELECT MAX(po_id) as max_id FROM purchase_orders");
    $row = $result->fetch_assoc();
    $next_id = ($row['max_id'] ?? 0) + 1;
    return "PO-$year-$month-" . str_pad($next_id, 4, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="header">
                <h1><i class="fas fa-file-invoice-dollar"></i> Purchase Orders</h1>
                <div class="header-actions">
                    <a href="create_po.php" class="btn-primary">
                        <i class="fas fa-plus"></i> Create New PO
                    </a>
                </div>
            </header>

            <div class="card">
                <div class="card-header">
                    <h2>All Purchase Orders</h2>
                    <div class="filters">
                        <select id="statusFilter" onchange="filterTable()">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="received">Received</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="paid">Paid</option>
                        </select>
                        <input type="date" id="dateFilter" onchange="filterTable()">
                    </div>
                </div>
                <div class="card-body">
                    <table id="poTable">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Vendor</th>
                                <th>Date</th>
                                <th>Expected Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $result = $conn->query("
                                SELECT po.*, v.vendor_name 
                                FROM purchase_orders po 
                                LEFT JOIN vendors v ON po.vendor_id = v.vendor_id 
                                ORDER BY po.po_date DESC
                            ");
                            
                            while ($po = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $po['po_number']; ?></td>
                                <td><?php echo htmlspecialchars($po['vendor_name']); ?></td>
                                <td><?php echo date('d-m-Y', strtotime($po['po_date'])); ?></td>
                                <td><?php echo date('d-m-Y', strtotime($po['expected_date'])); ?></td>
                                <td>₹<?php echo number_format($po['total_amount'], 2); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $po['status']; ?>">
                                        <?php echo ucfirst($po['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="payment-badge payment-<?php echo $po['payment_status']; ?>">
                                        <?php echo ucfirst($po['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="view_po.php?id=<?php echo $po['po_id']; ?>" class="btn-view">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit_po.php?id=<?php echo $po['po_id']; ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="print_po.php?id=<?php echo $po['po_id']; ?>" class="btn-print" target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php if ($po['status'] == 'pending'): ?>
                                    <a href="update_status.php?po_id=<?php echo $po['po_id']; ?>&status=approved" 
                                       class="btn-approve"
                                       onclick="return confirm('Approve this PO?')">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        function filterTable() {
            const statusFilter = document.getElementById('statusFilter').value;
            const dateFilter = document.getElementById('dateFilter').value;
            const rows = document.querySelectorAll('#poTable tbody tr');
            
            rows.forEach(row => {
                const status = row.querySelector('.status-badge').textContent.toLowerCase();
                const dateCell = row.cells[2].textContent;
                const filterDate = new Date(dateFilter);
                const rowDate = new Date(dateCell.split('-').reverse().join('-'));
                
                let show = true;
                
                if (statusFilter && status !== statusFilter) {
                    show = false;
                }
                
                if (dateFilter && rowDate.toDateString() !== filterDate.toDateString()) {
                    show = false;
                }
                
                row.style.display = show ? '' : 'none';
            });
        }
    </script>
</body>
</html>
<?php
// index.php
require_once 'config1.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <nav class="sidebar">
            <div class="logo">
                <h2><i class="fas fa-store"></i> Billing Software</h2>
            </div>
            <ul class="nav-menu">
                
                <li><a href="vendors1.php"><i class="fas fa-users"></i> Vendors</a></li>
                <li><a href="purchase_orders.php"><i class="fas fa-file-invoice-dollar"></i> Purchase Orders</a></li>
                <li><a href="payments.php"><i class="fas fa-credit-card"></i> Payments</a></li>
                <li><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                
            </ul>
        </nav>

        <main class="main-content">
            <header class="header">
                <h1>Purchase Management Dashboard</h1>
                <div class="user-info">
                    
                </div>
            </header>

            <div class="dashboard-stats">
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #4CAF50;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Vendors</h3>
                        <p><?php
                            $result = $conn->query("SELECT COUNT(*) as total FROM vendors");
                            echo $result->fetch_assoc()['total'];
                        ?></p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #2196F3;">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending POs</h3>
                        <p><?php
                            $result = $conn->query("SELECT COUNT(*) as total FROM purchase_orders WHERE status = 'pending'");
                            echo $result->fetch_assoc()['total'];
                        ?></p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #FF9800;">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Unpaid Amount</h3>
                        <p>₹<?php
                            $result = $conn->query("SELECT SUM(total_amount) as total FROM purchase_orders WHERE payment_status != 'paid'");
                            $total = $result->fetch_assoc()['total'] ?? 0;
                            echo number_format($total, 2);
                        ?></p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9C27B0;">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="stat-info">
                        <h3>This Month POs</h3>
                        <p><?php
                            $result = $conn->query("SELECT COUNT(*) as total FROM purchase_orders WHERE MONTH(po_date) = MONTH(CURRENT_DATE())");
                            echo $result->fetch_assoc()['total'];
                        ?></p>
                    </div>
                </div>
            </div>

            <div class="recent-activities">
                <h2>Recent Purchase Orders</h2>
                <table>
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Vendor</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $result = $conn->query("
                            SELECT po.*, v.vendor_name 
                            FROM purchase_orders po 
                            LEFT JOIN vendors v ON po.vendor_id = v.vendor_id 
                            ORDER BY po.created_at DESC 
                            LIMIT 5
                        ");
                        
                        while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $row['po_number']; ?></td>
                            <td><?php echo $row['vendor_name']; ?></td>
                            <td><?php echo date('d-m-Y', strtotime($row['po_date'])); ?></td>
                            <td>₹<?php echo number_format($row['total_amount'], 2); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $row['status']; ?>">
                                    <?php echo ucfirst($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="view_po.php?id=<?php echo $row['po_id']; ?>" class="btn-view">View</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
<?php
// vendors.php
require_once 'config.php';



// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendor_name = $_POST['vendor_name'];
    $company_name = $_POST['company_name'];
    $gstin = $_POST['gstin'];
    $address = $_POST['address'];
    $city = $_POST['city'];
    $state = $_POST['state'];
    $pincode = $_POST['pincode'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $contact_person = $_POST['contact_person'];
    $payment_terms = $_POST['payment_terms'];
    $bank_details = $_POST['bank_details'];

    $stmt = $conn->prepare("INSERT INTO vendors (vendor_name, company_name, gstin, address, city, state, pincode, email, phone, contact_person, payment_terms, bank_details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssssssss", $vendor_name, $company_name, $gstin, $address, $city, $state, $pincode, $email, $phone, $contact_person, $payment_terms, $bank_details);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Vendor added successfully!";
    } else {
        $_SESSION['error'] = "Error adding vendor: " . $conn->error;
    }
    header('Location: vendors.php');
    exit();
}

// Handle delete
if (isset($_GET['delete'])) {
    $vendor_id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM vendors WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Vendor deleted successfully!";
    } else {
        $_SESSION['error'] = "Error deleting vendor: " . $conn->error;
    }
    header('Location: vendors.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Management</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="header">
                <h1><i class="fas fa-users"></i> Vendor Management</h1>
                <div class="header-actions">
                    <button class="btn-primary" onclick="openModal()">
                        <i class="fas fa-plus"></i> Add New Vendor
                    </button>
                </div>
            </header>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h2>Vendor List</h2>
                    <div class="search-box">
                        <input type="text" id="searchVendor" placeholder="Search vendors...">
                        <i class="fas fa-search"></i>
                    </div>
                </div>
                <div class="card-body">
                    <table id="vendorsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vendor Name</th>
                                <th>Company</th>
                                <th>GSTIN</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>City</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $result = $conn->query("SELECT * FROM vendors ORDER BY vendor_name");
                            while ($vendor = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $vendor['vendor_id']; ?></td>
                                <td><?php echo htmlspecialchars($vendor['vendor_name']); ?></td>
                                <td><?php echo htmlspecialchars($vendor['company_name']); ?></td>
                                <td><?php echo $vendor['gstin']; ?></td>
                                <td><?php echo $vendor['phone']; ?></td>
                                <td><?php echo $vendor['email']; ?></td>
                                <td><?php echo $vendor['city']; ?></td>
                                <td class="actions">
                                    <a href="view_vendor.php?id=<?php echo $vendor['vendor_id']; ?>" class="btn-view">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit_vendor.php?id=<?php echo $vendor['vendor_id']; ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="vendors.php?delete=<?php echo $vendor['vendor_id']; ?>" 
                                       class="btn-delete" 
                                       onclick="return confirm('Are you sure you want to delete this vendor?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Vendor Modal -->
    <div id="vendorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Vendor</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="vendor_name">Vendor Name *</label>
                            <input type="text" id="vendor_name" name="vendor_name" required>
                        </div>
                        <div class="form-group">
                            <label for="company_name">Company Name</label>
                            <input type="text" id="company_name" name="company_name">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gstin">GSTIN</label>
                            <input type="text" id="gstin" name="gstin" placeholder="GST Number">
                        </div>
                        <div class="form-group">
                            <label for="contact_person">Contact Person</label>
                            <input type="text" id="contact_person" name="contact_person">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" rows="2"></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city">
                        </div>
                        <div class="form-group">
                            <label for="state">State</label>
                            <input type="text" id="state" name="state">
                        </div>
                        <div class="form-group">
                            <label for="pincode">Pincode</label>
                            <input type="text" id="pincode" name="pincode">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email">
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone</label>
                            <input type="tel" id="phone" name="phone">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="payment_terms">Payment Terms</label>
                        <input type="text" id="payment_terms" name="payment_terms" placeholder="e.g., Net 30">
                    </div>

                    <div class="form-group">
                        <label for="bank_details">Bank Details</label>
                        <textarea id="bank_details" name="bank_details" rows="3" placeholder="Bank Name, Account Number, IFSC Code, etc."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Vendor</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('vendorModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('vendorModal').style.display = 'none';
        }

        // Search functionality
        document.getElementById('searchVendor').addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#vendorsTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('vendorModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
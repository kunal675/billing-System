<?php
// add_vendor_page.php
session_start();
require_once 'config.php';
require_once 'db_connection.php';

$db = new Database();
$conn = $db->getConnection();

$message = '';
$message_type = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // ... same form processing code as addvendor.php ...
    
    if ($success) {
        $message = "Vendor added successfully!";
        $message_type = "success";
        $_POST = []; // Clear form
    } else {
        $message = "Error adding vendor. Please try again.";
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Vendor - <?php echo COMPANY_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; }
        .container { max-width: 800px; margin: 40px auto; padding: 30px; background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 30px; display: flex; align-items: center; gap: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50; }
        input, select, textarea { width: 100%; padding: 12px; border: 2px solid #e1e5eb; border-radius: 8px; font-size: 16px; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #4a6491; }
        .btn { background: #4a6491; color: white; border: none; padding: 15px 30px; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; }
        .btn:hover { background: #2c3e50; }
        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .back-btn { display: inline-block; margin-top: 20px; color: #4a6491; text-decoration: none; }
        .back-btn:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-truck"></i> Add New Vendor</h1>
        
        <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo $message; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="addvendor.php">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Vendor Name *</label>
                    <input type="text" name="vendor_name" value="<?php echo $_POST['vendor_name'] ?? ''; ?>" required>
                </div>
                <div class="form-group">
                    <label>Company Name</label>
                    <input type="text" name="company_name" value="<?php echo $_POST['company_name'] ?? ''; ?>">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>GSTIN</label>
                    <input type="text" name="gstin" value="<?php echo $_POST['gstin'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person" value="<?php echo $_POST['contact_person'] ?? ''; ?>">
                </div>
            </div>
            
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" rows="2"><?php echo $_POST['address'] ?? ''; ?></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" value="<?php echo $_POST['city'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label>State</label>
                    <select name="state">
                        <option value="Gujarat" <?php echo ($_POST['state'] ?? 'Gujarat') == 'Gujarat' ? 'selected' : ''; ?>>Gujarat</option>
                        <option value="Maharashtra" <?php echo ($_POST['state'] ?? '') == 'Maharashtra' ? 'selected' : ''; ?>>Maharashtra</option>
                        <option value="Rajasthan" <?php echo ($_POST['state'] ?? '') == 'Rajasthan' ? 'selected' : ''; ?>>Rajasthan</option>
                        <option value="Uttar Pradesh" <?php echo ($_POST['state'] ?? '') == 'Uttar Pradesh' ? 'selected' : ''; ?>>Uttar Pradesh</option>
                        <option value="Delhi" <?php echo ($_POST['state'] ?? '') == 'Delhi' ? 'selected' : ''; ?>>Delhi</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Pincode</label>
                    <input type="text" name="pincode" value="<?php echo $_POST['pincode'] ?? ''; ?>">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Phone *</label>
                    <input type="tel" name="phone" value="<?php echo $_POST['phone'] ?? ''; ?>" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo $_POST['email'] ?? ''; ?>">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Payment Terms</label>
                    <input type="text" name="payment_terms" value="<?php echo $_POST['payment_terms'] ?? ''; ?>" placeholder="e.g., 30 days">
                </div>
                <div class="form-group">
                    <label>Bank Details</label>
                    <textarea name="bank_details" rows="2" placeholder="Bank Name, Account No, IFSC, etc."><?php echo $_POST['bank_details'] ?? ''; ?></textarea>
                </div>
            </div>
            
            <div style="display: flex; gap: 15px; margin-top: 30px;">
                <button type="submit" class="btn">
                    <i class="fas fa-save"></i> Save Vendor
                </button>
                <a href="index.php" class="btn" style="background: #6c757d;">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
        
        <a href="index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</body>
</html>
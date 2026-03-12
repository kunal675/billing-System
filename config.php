<?php
// config.php - Database Configuration

// Check if database constants are defined, if not define them
if (!defined('DB_HOST')) {
    // Database configuration
    define('DB_HOST', 'localhost');
    define('DB_USER', 'u838186511_khelwin_user');
    define('DB_PASS', 'Alphadelta#675');
    define('DB_NAME', 'u838186511_khelwin_bill');
    
    // GST Configuration
    define('GST_RATE_CGST', 2.5); // 2.5% CGST
    define('GST_RATE_SGST', 2.5); // 2.5% SGST
    define('GST_RATE_IGST', 5);   // 5% IGST (for interstate)
    
    // Company Information
    define('COMPANY_NAME', 'LIYANSH FASHION WHOLESALE BAZAAR');
    define('COMPANY_NAME1', 'WHOLESALE BAZAAR');
    define('COMPANY_ADDRESS', 'NATHUPUR,PAC GATE,ROHANIA, VARANASI - 221010');
    define('COMPANY_GSTIN', '09AEJFS3796Q1ZT');
    define('COMPANY_ACCOUNT_NO', '924020016968423');
    define('COMPANY_IFSC', 'UTIB0000287');
    define('COMPANY_BANKBRANCH', 'AXISBANK VARANASI');
    define('COMPANY_PHONE', '9670030002');
    define('COMPANY_EMAIL', 'info@liyanshfashion.com');
    
    // Enable error reporting for development
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    // Timezone
    date_default_timezone_set('Asia/Kolkata');
}
?>
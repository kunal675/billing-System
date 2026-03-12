<?php
echo "<h2>Server Diagnostics</h2>";

// 1. PHP Version
echo "<h3>1. PHP Version:</h3>";
echo phpversion();

// 2. Extensions
echo "<h3>2. Required Extensions:</h3>";
$extensions = ['mysqli', 'session', 'json', 'mbstring'];
foreach ($extensions as $ext) {
    echo "$ext: " . (extension_loaded($ext) ? "✓ Enabled" : "✗ Missing") . "<br>";
}

// 3. File permissions
echo "<h3>3. File Permissions:</h3>";
$files = ['index.php', 'print_invoice.php', 'config.php'];
foreach ($files as $file) {
    echo "$file: " . (is_readable($file) ? "Readable" : "Not readable") . "<br>";
}

// 4. Session
echo "<h3>4. Session Status:</h3>";
echo "Session: " . session_status() . " (2 = active)<br>";

// 5. Memory limit
echo "<h3>5. Memory Limit:</h3>";
echo ini_get('memory_limit');
?>
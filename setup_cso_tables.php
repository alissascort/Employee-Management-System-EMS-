<?php
require_once 'db_connect.php';
require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();

echo "Setting up CSO Database Tables...\n";

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Read and execute the SQL file
    $sql = file_get_contents('create_cso_tables.sql');
    
    // Split by semicolon to execute multiple statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement) && !preg_match('/^--/', $statement)) {
            try {
                $conn->exec($statement);
                echo "✓ Executed: " . substr($statement, 0, 50) . "...\n";
            } catch (PDOException $e) {
                // Ignore "table already exists" errors
                if (strpos($e->getMessage(), 'already exists') === false) {
                    echo "⚠ Warning: " . $e->getMessage() . "\n";
                } else {
                    echo "✓ Table already exists (skipped)\n";
                }
            }
        }
    }
    
    echo "\n✅ CSO database tables setup completed successfully!\n";
    echo "You can now test the CSO dashboard functionality.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Please check your database connection settings.\n";
}
?> 
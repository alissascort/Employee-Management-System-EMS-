<?php
require_once 'db_connect.php';

echo "Fixing remaining database issues...\n";

try {
    $db = new Database();
    $conn = $db->connect();
    
    // 1. Check if system_logs table exists and has correct structure
    echo "1. Checking system_logs table...\n";
    $result = $conn->query("SHOW TABLES LIKE 'system_logs'");
    if ($result->rowCount() == 0) {
        echo "   Creating system_logs table...\n";
        $conn->exec("
            CREATE TABLE system_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                log_type VARCHAR(50) NOT NULL,
                log_level ENUM('info', 'warning', 'error', 'critical') NOT NULL,
                message TEXT NOT NULL,
                user_email VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created_at (created_at),
                INDEX idx_log_type (log_type),
                INDEX idx_log_level (log_level)
            )
        ");
        echo "   ✓ system_logs table created\n";
    } else {
        echo "   ✓ system_logs table exists\n";
        // Check if created_at column exists
        $columns = $conn->query("SHOW COLUMNS FROM system_logs LIKE 'created_at'");
        if ($columns->rowCount() == 0) {
            echo "   Adding created_at column...\n";
            $conn->exec("ALTER TABLE system_logs ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            echo "   ✓ created_at column added\n";
        } else {
            echo "   ✓ created_at column exists\n";
        }
    }
    
    // 2. Check if users table exists (needed for security_alerts)
    echo "2. Checking users table...\n";
    $result = $conn->query("SHOW TABLES LIKE 'users'");
    if ($result->rowCount() == 0) {
        echo "   Creating users table...\n";
        $conn->exec("
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) UNIQUE NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                full_name VARCHAR(255) NOT NULL,
                user_type ENUM('admin', 'employee', 'cso', 'manager') NOT NULL,
                status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_type (user_type),
                INDEX idx_status (status)
            )
        ");
        echo "   ✓ users table created\n";
        
        // Insert sample user for CSO
        $conn->exec("
            INSERT INTO users (username, email, full_name, user_type, status) VALUES
            ('cso_user', 'cso@company.com', 'CSO User', 'cso', 'active')
        ");
        echo "   ✓ Sample CSO user created\n";
    } else {
        echo "   ✓ users table exists\n";
    }
    
    // 3. Check if security_alerts table exists
    echo "3. Checking security_alerts table...\n";
    $result = $conn->query("SHOW TABLES LIKE 'security_alerts'");
    if ($result->rowCount() == 0) {
        echo "   Creating security_alerts table...\n";
        $conn->exec("
            CREATE TABLE security_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                severity ENUM('critical', 'warning', 'info') NOT NULL,
                alert_type VARCHAR(50) NOT NULL,
                description TEXT NOT NULL,
                user_id INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('active', 'acknowledged', 'resolved') DEFAULT 'active',
                resolved_by INT,
                resolved_at TIMESTAMP NULL,
                notes TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        echo "   ✓ security_alerts table created\n";
        
        // Insert sample alerts
        $conn->exec("
            INSERT INTO security_alerts (severity, alert_type, description, user_id) VALUES
            ('warning', 'unauthorized_access', 'Multiple failed login attempts detected', 1),
            ('info', 'system_notification', 'System maintenance completed successfully', 1),
            ('critical', 'tampering', 'Suspicious activity detected in attendance records', 1)
        ");
        echo "   ✓ Sample security alerts created\n";
    } else {
        echo "   ✓ security_alerts table exists\n";
    }
    
    // 4. Check if security_audits table exists
    echo "4. Checking security_audits table...\n";
    $result = $conn->query("SHOW TABLES LIKE 'security_audits'");
    if ($result->rowCount() == 0) {
        echo "   Creating security_audits table...\n";
        $conn->exec("
            CREATE TABLE security_audits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                audit_type VARCHAR(100) NOT NULL,
                auditor_id INT,
                scope TEXT,
                findings TEXT,
                severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
                status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
                audit_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at TIMESTAMP NULL,
                notes TEXT,
                INDEX idx_audit_date (audit_date),
                INDEX idx_audit_type (audit_type),
                INDEX idx_severity (severity)
            )
        ");
        echo "   ✓ security_audits table created\n";
        
        // Insert sample audits
        $conn->exec("
            INSERT INTO security_audits (audit_type, auditor_id, scope, findings, severity, status) VALUES
            ('Network Security', 1, 'Entire network infrastructure', 'Found 3 open ports that need to be closed', 'high', 'in_progress'),
            ('Physical Security', 1, 'Building access controls', 'All access points properly secured', 'low', 'completed'),
            ('Data Protection', 1, 'Employee data handling', 'Encryption protocols need updating', 'medium', 'pending')
        ");
        echo "   ✓ Sample security audits created\n";
    } else {
        echo "   ✓ security_audits table exists\n";
    }
    
    echo "\n✅ All database issues fixed successfully!\n";
    echo "The CSO dashboard should now work without errors.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Please check your database connection settings.\n";
}
?> 
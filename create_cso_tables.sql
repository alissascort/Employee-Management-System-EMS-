-- Create missing tables for CSO dashboard functionality

-- 1. System Logs table
CREATE TABLE IF NOT EXISTS system_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    log_type VARCHAR(50) NOT NULL,
    log_level ENUM('info', 'warning', 'error', 'critical') NOT NULL,
    message TEXT NOT NULL,
    user_email VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_log_type (log_type),
    INDEX idx_log_level (log_level)
);

-- 2. Security Audits table
CREATE TABLE IF NOT EXISTS security_audits (
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
);

-- 3. Security Incidents table
CREATE TABLE IF NOT EXISTS security_incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incident_type VARCHAR(100) NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(255),
    reported_by VARCHAR(100),
    status ENUM('open', 'investigating', 'resolved', 'closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    INDEX idx_created_at (created_at),
    INDEX idx_severity (severity),
    INDEX idx_status (status)
);

-- 4. Active Patrols table
CREATE TABLE IF NOT EXISTS active_patrols (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patrol_route VARCHAR(255) NOT NULL,
    cso_id INT NOT NULL,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    checkpoints_completed INT DEFAULT 0,
    total_checkpoints INT DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_cso_id (cso_id),
    INDEX idx_status (status)
);

-- 5. Vulnerability Scans table
CREATE TABLE IF NOT EXISTS vulnerability_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vulnerability_name VARCHAR(255) NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    affected_system VARCHAR(255),
    description TEXT,
    status ENUM('open', 'investigating', 'patched', 'closed') DEFAULT 'open',
    discovered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    patched_at TIMESTAMP NULL,
    INDEX idx_severity (severity),
    INDEX idx_status (status)
);

-- 6. API Endpoints table
CREATE TABLE IF NOT EXISTS api_endpoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_name VARCHAR(255) NOT NULL,
    endpoint_url VARCHAR(500) NOT NULL,
    status ENUM('up', 'down', 'slow', 'maintenance') DEFAULT 'up',
    response_time INT DEFAULT 0,
    last_check TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_last_check (last_check)
);

-- Insert sample data for testing
INSERT INTO security_incidents (incident_type, severity, description, location, reported_by, status) VALUES
('Unauthorized Access Attempt', 'high', 'Multiple failed login attempts detected', 'Main Building - Floor 3', 'System', 'investigating'),
('Suspicious Activity', 'medium', 'Unknown person loitering near entrance', 'Parking Lot A', 'Security Guard', 'open'),
('System Breach Alert', 'critical', 'Potential data breach detected', 'Server Room', 'Firewall', 'investigating');

INSERT INTO active_patrols (patrol_route, cso_id, status, checkpoints_completed, total_checkpoints) VALUES
('Main Building Perimeter', 1, 'active', 3, 8),
('Parking Lot Security', 1, 'active', 1, 5),
('Server Room Check', 1, 'completed', 4, 4);

INSERT INTO security_audits (audit_type, auditor_id, scope, findings, severity, status) VALUES
('Network Security', 1, 'Entire network infrastructure', 'Found 3 open ports that need to be closed', 'high', 'in_progress'),
('Physical Security', 1, 'Building access controls', 'All access points properly secured', 'low', 'completed'),
('Data Protection', 1, 'Employee data handling', 'Encryption protocols need updating', 'medium', 'pending');

INSERT INTO vulnerability_scans (vulnerability_name, severity, affected_system, description, status) VALUES
('SQL Injection Vulnerability', 'critical', 'Employee Portal', 'Login form vulnerable to SQL injection', 'open'),
('Cross-Site Scripting', 'high', 'Admin Dashboard', 'XSS vulnerability in user input fields', 'investigating'),
('Weak Password Policy', 'medium', 'All Systems', 'Password complexity requirements too low', 'patched');

INSERT INTO api_endpoints (api_name, endpoint_url, status, response_time) VALUES
('Employee Authentication', '/api/auth/employee', 'up', 150),
('Attendance System', '/api/attendance/record', 'up', 200),
('Payroll API', '/api/payroll/generate', 'slow', 800),
('Notification Service', '/api/notifications/send', 'down', 0); 
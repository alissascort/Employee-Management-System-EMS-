-- Create tables for Attendance System Security Monitoring

-- System Settings Table
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    value TEXT NOT NULL,
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Security Alerts Table
CREATE TABLE IF NOT EXISTS security_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    severity ENUM('critical', 'warning', 'info') NOT NULL,
    alert_type VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'acknowledged', 'resolved') DEFAULT 'active',
    resolved_by INT,
    resolved_at TIMESTAMP NULL,
    notes TEXT
);

-- User Sessions Table
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    user_type VARCHAR(20) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
);

-- API Logs Table
CREATE TABLE IF NOT EXISTS api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    user_id INT,
    user_type VARCHAR(20),
    ip_address VARCHAR(45),
    status_code INT NOT NULL,
    response_time FLOAT,
    request_data TEXT,
    response_data TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_status_code (status_code)
);

-- System Notifications Table
CREATE TABLE IF NOT EXISTS system_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    type ENUM('critical', 'warning', 'info', 'success') NOT NULL,
    sent_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_broadcast BOOLEAN DEFAULT TRUE
);

-- Insert default system settings
INSERT IGNORE INTO system_settings (setting_key, value, description) VALUES
('digital_attendance_enabled', '1', 'Whether digital attendance system is enabled'),
('security_monitoring_enabled', '1', 'Whether security monitoring is active'),
('auto_lockout_enabled', '1', 'Whether automatic account lockout is enabled'),
('session_timeout_minutes', '30', 'Session timeout in minutes');

-- Insert sample security alerts for testing
INSERT INTO security_alerts (severity, alert_type, description, user_id, created_at) VALUES
('warning', 'unauthorized_access', 'Multiple failed login attempts detected', NULL, NOW() - INTERVAL 2 HOUR),
('info', 'system_notification', 'System maintenance completed successfully', NULL, NOW() - INTERVAL 1 HOUR),
('critical', 'tampering', 'Suspicious activity detected in attendance records', NULL, NOW() - INTERVAL 30 MINUTE); 
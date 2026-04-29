-- Create Security Reports Table
CREATE TABLE IF NOT EXISTS security_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id VARCHAR(50) UNIQUE NOT NULL,
    generated_by INT NOT NULL,
    report_data JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_id (report_id),
    INDEX idx_generated_by (generated_by),
    INDEX idx_created_at (created_at)
); 
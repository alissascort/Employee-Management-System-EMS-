-- tickets.sql
CREATE TABLE tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open','in_progress','resolved','closed','escalated') DEFAULT 'open',
    created_by INT NOT NULL,
    assigned_to INT DEFAULT NULL,
    assigned_role VARCHAR(50) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    priority ENUM('low','medium','high','critical') DEFAULT 'medium',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES employees(employee_id),
    FOREIGN KEY (assigned_to) REFERENCES employees(employee_id)
);

-- Optional: comments table
CREATE TABLE IF NOT EXISTS ticket_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id),
    FOREIGN KEY (user_id) REFERENCES employees(employee_id)
); 
<?php
/**
 * Terminal Authentication Module
 * Handles secure session validation and token management
 */

require_once __DIR__ . '/api_monitoring1.php';
$monitor = new ApiMonitor(__FILE__);
$monitor->checkActive();
class TerminalAuth {
    private $conn;
    private $sessionTimeout = 900; // 15 minutes
    
    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }
    
    public function validateSession() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return false;
        }
        
        // Check if session exists in database
        $stmt = $this->conn->prepare("
            SELECT * FROM user_sessions 
            WHERE session_id = ? 
            AND user_id = ? 
            AND status = 'active'
            AND last_activity >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([session_id(), $_SESSION['user_id'], $this->sessionTimeout]);
        
        if ($stmt->rowCount() === 0) {
            return false;
        }
        
        // Update last activity
        $stmt = $this->conn->prepare("
            UPDATE user_sessions 
            SET last_activity = NOW() 
            WHERE session_id = ?
        ");
        $stmt->execute([session_id()]);
        
        return true;
    }
    
    public function generateSessionToken() {
        return bin2hex(random_bytes(32));
    }
    
    public function createSession($userId, $userType, $userName) {
        $token = $this->generateSessionToken();
        
        $stmt = $this->conn->prepare("
            INSERT INTO user_sessions (session_id, user_id, user_type, user_name, session_token, ip_address, user_agent, created_at, last_activity)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            session_id(),
            $userId,
            $userType,
            $userName,
            $token,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        $_SESSION['session_token'] = $token;
        return true;
    }
    
    public function terminateSession($sessionId) {
        $stmt = $this->conn->prepare("
            UPDATE user_sessions 
            SET status = 'terminated', 
                terminated_at = NOW() 
            WHERE session_id = ?
        ");
        return $stmt->execute([$sessionId]);
    }
}
?>

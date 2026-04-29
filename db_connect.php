<?php
/**
 * Enhanced Database Connection Class
 * Improved with connection pooling, better error handling, and security features
 */
class Database {
    private $host = 'localhost';
    private $db_name = 'employee_management_system';
    private $username = 'ems_user';
    private $password = 'securepassword123';
    private $conn = null;
    private static $instance = null;
    
    // Connection settings
    private $connectionTimeout = 30;
    private $maxRetries = 3;

    public function __construct() {
        // Public constructor for backward compatibility
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function connect() {
        if ($this->conn !== null) {
            try {
                // Test if connection is still alive
                $this->conn->query('SELECT 1');
                return $this->conn;
            } catch (PDOException $e) {
                // Connection lost, reconnect
                $this->conn = null;
            }
        }

        $retryCount = 0;
        while ($retryCount < $this->maxRetries) {
            try {
                $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    PDO::ATTR_TIMEOUT => $this->connectionTimeout
                ];
                
                $this->conn = new PDO($dsn, $this->username, $this->password, $options);
                
                // Log successful connection
                error_log("Database connection established successfully");
                return $this->conn;
                
            } catch(PDOException $e) {
                $retryCount++;
                error_log("Database Connection Error (Attempt $retryCount): " . $e->getMessage());
                
                if ($retryCount >= $this->maxRetries) {
                    throw new Exception("Database connection failed after {$this->maxRetries} attempts: " . $e->getMessage());
                }
                
                // Wait before retrying
                sleep(1);
            }
        }
    }

    public function getConnection() {
        return $this->connect();
    }

    public function closeConnection() {
        if ($this->conn !== null) {
            $this->conn = null;
            error_log("Database connection closed");
        }
    }

    public function beginTransaction() {
        return $this->connect()->beginTransaction();
    }

    public function commit() {
        return $this->connect()->commit();
    }

    public function rollback() {
        return $this->connect()->rollback();
    }

    public function prepare($sql) {
        return $this->connect()->prepare($sql);
    }

    public function query($sql) {
        return $this->connect()->query($sql);
    }

    public function lastInsertId() {
        return $this->connect()->lastInsertId();
    }

    public function quote($value) {
        return $this->connect()->quote($value);
    }

    // Destructor to ensure connection is closed
    public function __destruct() {
        $this->closeConnection();
    }
}

// Backward compatibility - maintain existing usage
if (!function_exists('getDatabaseConnection')) {
    function getDatabaseConnection() {
        return Database::getInstance()->getConnection();
    }
}
?>
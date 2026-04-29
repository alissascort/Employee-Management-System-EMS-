<?php
/**
 * core/api_monitoring1.php
 * Central API Monitoring Library
 * Include this at the top of every API file.
 * Usage:
 *   require_once __DIR__ . '/../core/api_monitoring.php';
 *   $monitor = new ApiMonitor(__FILE__);
 *   $monitor->checkActive();   // blocks with 403 if disabled
 *   // ... your API logic ...
 *   $monitor->logRequest($statusCode);
 */

class ApiMonitor
{
    private PDO   $db;
    private float $startTime;
    private int   $apiId       = 0;
    private string $endpointUrl = '';
    private string $apiName     = '';
    private string $method      = 'GET';
    private ?int   $userId      = null;
    private string $userType    = '';
    private string $ipAddress   = '';

    // Thresholds (milliseconds)
    const STATUS_UP_MAX   = 500;
    const STATUS_SLOW_MAX = 2000;

    public function __construct(string $callerFile)
    {
        $this->startTime   = microtime(true);
        $this->method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->ipAddress   = $this->resolveIp();
        $this->endpointUrl = '/' . basename($callerFile);
        $this->apiName     = $this->buildApiName($callerFile);

        $this->db = $this->connect();
        $this->ensureSchema();
        $this->apiId = $this->autoRegister();
        $this->resolveSession();
    }

    /* ── Public Interface ─────────────────────────────────── */

    /**
     * Call immediately after instantiation.
     * If the API is disabled it sends 403 JSON and exits.
     */
    public function checkActive(): void
    {
        $stmt = $this->db->prepare(
            'SELECT is_active FROM api_endpoints WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$this->apiId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && (int)$row['is_active'] === 0) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'This API endpoint is currently disabled. Contact the Security Officer.',
                'endpoint' => $this->endpointUrl,
                'disabled_at' => date('Y-m-d H:i:s'),
            ]);
            $this->writeLog(403);
            exit;
        }
    }

    /**
     * Call at the very end of your API, after sending the response.
     */
    public function logRequest(int $statusCode = 200, ?string $requestData = null, ?string $responseData = null): void
    {
        $this->writeLog($statusCode, $requestData, $responseData);
    }

    /* ── Private Helpers ──────────────────────────────────── */

    private function connect(): PDO
    {
        // Adjust DSN / credentials to match your environment
        $dsn  = 'mysql:host=localhost;dbname=employee_management_system;charset=utf8mb4';
        $user = 'ems_user';
        $pass = 'securepassword123';

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    }

    private function ensureSchema(): void
    {
        // api_endpoints
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS api_endpoints (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                api_name       VARCHAR(255) NOT NULL,
                endpoint_url   VARCHAR(500) NOT NULL,
                status         ENUM('up','down','slow') DEFAULT 'up',
                response_time  INT DEFAULT 0,
                last_check     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                check_interval INT DEFAULT 300,
                is_active      TINYINT(1) DEFAULT 1,
                created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_endpoint_url (endpoint_url(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // api_logs
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS api_logs (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                endpoint      VARCHAR(255) NOT NULL,
                method        VARCHAR(10)  NOT NULL,
                user_id       INT          DEFAULT NULL,
                user_type     VARCHAR(20)  DEFAULT NULL,
                ip_address    VARCHAR(45)  DEFAULT NULL,
                status_code   INT          NOT NULL,
                response_time FLOAT        DEFAULT NULL,
                request_data  TEXT         DEFAULT NULL,
                response_data TEXT         DEFAULT NULL,
                created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_status_code (status_code),
                INDEX idx_created_at  (created_at),
                INDEX idx_endpoint    (endpoint(100))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // api_monitoring_history
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS api_monitoring_history (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                api_id        INT          NOT NULL,
                status        VARCHAR(50)  DEFAULT NULL,
                response_time FLOAT        DEFAULT NULL,
                error_message TEXT         DEFAULT NULL,
                check_time    DATETIME     DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_api_id    (api_id),
                INDEX idx_check_time (check_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        try {
        $this->db->exec("
            ALTER TABLE api_monitoring_history 
            ADD CONSTRAINT fk_api_monitoring_history_api 
            FOREIGN KEY (api_id) REFERENCES api_endpoints(id) 
            ON DELETE CASCADE
        ");
    } catch (PDOException $e) {
        
    }
    
    }

    /**
     * Insert or ignore the API into api_endpoints.
     * Returns the existing or new id.
     */
    private function autoRegister(): int
{
    // First, check if endpoint already exists
    $stmt = $this->db->prepare(
        'SELECT id FROM api_endpoints WHERE endpoint_url = ? LIMIT 1'
    );
    $stmt->execute([$this->endpointUrl]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // UPDATE existing record with latest name and check time
        $stmt = $this->db->prepare("
            UPDATE api_endpoints 
            SET api_name = ?, last_check = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$this->apiName, $existing['id']]);
        return (int)$existing['id'];
    } else {
        // Insert new record (only for first-time APIs)
        $stmt = $this->db->prepare("
            INSERT INTO api_endpoints (api_name, endpoint_url, is_active, created_at)
            VALUES (?, ?, 1, NOW())
        ");
        $stmt->execute([$this->apiName, $this->endpointUrl]);
        return (int)$this->db->lastInsertId();
    }
}

    private function resolveSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $this->userId   = $_SESSION['user_id']   ?? null;
        $this->userType = $_SESSION['user_type']  ?? '';
    }

    private function resolveIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    private function buildApiName(string $file): string
    {
        $base = basename($file, '.php');
        // Convert snake_case to Title Words
        return ucwords(str_replace('_', ' ', $base));
    }

    private function writeLog(int $statusCode, ?string $reqData = null, ?string $resData = null): void
{
    $responseTime = round((microtime(true) - $this->startTime) * 1000, 2);

    try {
        // Insert into api_logs
        $stmt = $this->db->prepare("
            INSERT INTO api_logs
                (endpoint, method, user_id, user_type, ip_address, status_code, response_time, request_data, response_data, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $this->endpointUrl,
            $this->method,
            $this->userId,
            $this->userType ?: null,
            $this->ipAddress,
            $statusCode,
            $responseTime,
            $reqData  ? substr($reqData,  0, 4000) : null,
            $resData  ? substr($resData,  0, 4000) : null,
        ]);

        // Determine status
        $status = 'up';
        if ($responseTime >= self::STATUS_SLOW_MAX) $status = 'down';
        elseif ($responseTime >= self::STATUS_UP_MAX)  $status = 'slow';
        if ($statusCode >= 500) $status = 'down';

        // Update api_endpoints with current status
        $this->db->prepare("
            UPDATE api_endpoints
            SET status = ?, response_time = ?, last_check = NOW()
            WHERE id = ?
        ")->execute([$status, (int)$responseTime, $this->apiId]);

        // ✅ NEW: Insert into api_monitoring_history (this is the history!)
        $errorMsg = null;
        if ($statusCode >= 400) {
            $errorMsg = "HTTP $statusCode error";
        }
        
        $this->db->prepare("
            INSERT INTO api_monitoring_history (api_id, status, response_time, error_message, check_time)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$this->apiId, $status, $responseTime, $errorMsg]);

    } catch (Throwable $e) {
        // Logging must never crash the API
        error_log('ApiMonitor::writeLog error: ' . $e->getMessage());
    }
}

    /* ── Static Helper for Cron / Monitoring Engine ───────── */

    /**
     * Perform a live HTTP check on a URL.
     * Returns ['status'=>'up|slow|down', 'response_time'=>ms, 'error'=>null|string]
     */
    public static function pingEndpoint(string $url, int $timeoutMs = 3000): array
    {
        $start = microtime(true);
        $ch    = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY         => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'FSM-APIMonitor/1.0',
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $ms = round((microtime(true) - $start) * 1000, 2);

        if ($body === false || $httpCode === 0) {
            return ['status' => 'down', 'response_time' => $ms, 'error' => $error ?: 'No response'];
        }

        $status = 'up';
        if ($ms >= self::STATUS_SLOW_MAX) $status = 'down';
        elseif ($ms >= self::STATUS_UP_MAX)  $status = 'slow';

        return ['status' => $status, 'response_time' => $ms, 'error' => null];
    }
}

<?php
/*
 * Database Connection and Management Class
 * October 20, 2012 - MySQL 5.5 Compatible
 * PHP 5.4 PDO implementation for network monitoring system
 */

class DatabaseManager {
    
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $pdo;
    
    public function __construct($host = 'localhost', $dbname = 'network_monitoring', $username = 'netmon', $password = 'netmon123') {
        $this->host = $host;
        $this->dbname = $dbname;
        $this->username = $username;
        $this->password = $password;
        $this->connect();
    }
    
    /**
     * Establish database connection using PDO (PHP 5.4 compatible)
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8";
            $options = array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,  // Use native prepared statements
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
            );
            
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get database connection
     */
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Add new network device
     */
    public function addDevice($deviceName, $ipAddress, $deviceType = 'other', $snmpCommunity = 'public', $location = '', $description = '') {
        $sql = "INSERT INTO devices (device_name, ip_address, device_type, snmp_community, location, description) 
                VALUES (:device_name, :ip_address, :device_type, :snmp_community, :location, :description)";
        
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute(array(
            ':device_name' => $deviceName,
            ':ip_address' => $ipAddress,
            ':device_type' => $deviceType,
            ':snmp_community' => $snmpCommunity,
            ':location' => $location,
            ':description' => $description
        ));
        
        if ($result) {
            $deviceId = $this->pdo->lastInsertId();
            
            // Create polling schedule for new device
            $this->createPollingSchedule($deviceId);
            
            return $deviceId;
        }
        
        return false;
    }
    
    /**
     * Get all devices
     */
    public function getDevices($status = null) {
        $sql = "SELECT * FROM devices";
        $params = array();
        
        if ($status !== null) {
            $sql .= " WHERE status = :status";
            $params[':status'] = $status;
        }
        
        $sql .= " ORDER BY device_name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get device by ID
     */
    public function getDevice($deviceId) {
        $sql = "SELECT * FROM devices WHERE device_id = :device_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':device_id' => $deviceId));
        
        return $stmt->fetch();
    }
    
    /**
     * Store monitoring data
     */
    public function storeMonitoringData($deviceId, $interfaceId, $metricName, $metricValue, $numericValue = null) {
        $sql = "INSERT INTO monitoring_data (device_id, interface_id, metric_name, metric_value, numeric_value) 
                VALUES (:device_id, :interface_id, :metric_name, :metric_value, :numeric_value)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array(
            ':device_id' => $deviceId,
            ':interface_id' => $interfaceId,
            ':metric_name' => $metricName,
            ':metric_value' => $metricValue,
            ':numeric_value' => $numericValue
        ));
    }
    
    /**
     * Add/update device interface
     */
    public function updateInterface($deviceId, $interfaceIndex, $interfaceName, $interfaceType = null, $interfaceMtu = null, $interfaceSpeed = null, $adminStatus = 'up', $operStatus = 'unknown') {
        // Check if interface exists
        $sql = "SELECT interface_id FROM device_interfaces WHERE device_id = :device_id AND interface_index = :interface_index";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':device_id' => $deviceId, ':interface_index' => $interfaceIndex));
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing interface
            $sql = "UPDATE device_interfaces SET 
                    interface_name = :interface_name,
                    interface_type = :interface_type,
                    interface_mtu = :interface_mtu,
                    interface_speed = :interface_speed,
                    admin_status = :admin_status,
                    oper_status = :oper_status,
                    updated_at = CURRENT_TIMESTAMP
                    WHERE device_id = :device_id AND interface_index = :interface_index";
        } else {
            // Insert new interface
            $sql = "INSERT INTO device_interfaces (device_id, interface_index, interface_name, interface_type, interface_mtu, interface_speed, admin_status, oper_status)
                    VALUES (:device_id, :interface_index, :interface_name, :interface_type, :interface_mtu, :interface_speed, :admin_status, :oper_status)";
        }
        
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute(array(
            ':device_id' => $deviceId,
            ':interface_index' => $interfaceIndex,
            ':interface_name' => $interfaceName,
            ':interface_type' => $interfaceType,
            ':interface_mtu' => $interfaceMtu,
            ':interface_speed' => $interfaceSpeed,
            ':admin_status' => $adminStatus,
            ':oper_status' => $operStatus
        ));
        
        return $result;
    }
    
    /**
     * Create alert
     */
    public function createAlert($deviceId, $interfaceId, $alertType, $severity, $message) {
        $sql = "INSERT INTO alerts (device_id, interface_id, alert_type, severity, message) 
                VALUES (:device_id, :interface_id, :alert_type, :severity, :message)";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array(
            ':device_id' => $deviceId,
            ':interface_id' => $interfaceId,
            ':alert_type' => $alertType,
            ':severity' => $severity,
            ':message' => $message
        ));
    }
    
    /**
     * Get active alerts
     */
    public function getActiveAlerts($deviceId = null) {
        $sql = "SELECT a.*, d.device_name, d.ip_address 
                FROM alerts a 
                JOIN devices d ON a.device_id = d.device_id 
                WHERE a.status = 'open'";
        
        $params = array();
        if ($deviceId !== null) {
            $sql .= " AND a.device_id = :device_id";
            $params[':device_id'] = $deviceId;
        }
        
        $sql .= " ORDER BY a.severity DESC, a.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Create polling schedule for device
     */
    private function createPollingSchedule($deviceId, $interval = 300) {
        $sql = "INSERT INTO polling_schedule (device_id, poll_interval, next_poll) 
                VALUES (:device_id, :poll_interval, DATE_ADD(NOW(), INTERVAL :poll_interval SECOND))";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array(
            ':device_id' => $deviceId,
            ':poll_interval' => $interval
        ));
    }
    
    /**
     * Get devices due for polling
     */
    public function getDevicesDueForPolling() {
        $sql = "SELECT d.*, ps.poll_interval, ps.last_poll 
                FROM devices d 
                JOIN polling_schedule ps ON d.device_id = ps.device_id 
                WHERE d.status = 'active' 
                AND ps.enabled = TRUE 
                AND (ps.next_poll <= NOW() OR ps.next_poll IS NULL)
                ORDER BY ps.next_poll ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Update polling schedule after poll
     */
    public function updatePollingSchedule($deviceId) {
        $sql = "UPDATE polling_schedule SET 
                last_poll = NOW(),
                next_poll = DATE_ADD(NOW(), INTERVAL poll_interval SECOND)
                WHERE device_id = :device_id";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(array(':device_id' => $deviceId));
    }
    
    /**
     * Get system configuration
     */
    public function getConfig($key) {
        $sql = "SELECT config_value FROM system_config WHERE config_key = :config_key";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(':config_key' => $key));
        
        $result = $stmt->fetch();
        return $result ? $result['config_value'] : null;
    }
    
    /**
     * Get device status summary (using view)
     */
    public function getDeviceStatusSummary() {
        $sql = "SELECT * FROM device_status ORDER BY device_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}
?>
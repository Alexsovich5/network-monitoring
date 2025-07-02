<?php
/*
 * Performance Optimization Module
 * December 10, 2012 - Database indexing and query optimization
 * Implements caching, query optimization, and performance monitoring
 */

class PerformanceOptimizer {
    
    private $db;
    private $cacheDir;
    private $cacheEnabled;
    
    public function __construct($cacheDir = '/tmp/netmon_cache') {
        $this->db = new DatabaseManager();
        $this->cacheDir = $cacheDir;
        $this->cacheEnabled = true;
        
        // Create cache directory
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Optimize database indexes for better performance
     * 2012 MySQL optimization techniques
     */
    public function optimizeDatabase() {
        $optimizations = array();
        $pdo = $this->db->getConnection();
        
        try {
            // Add composite indexes for common queries
            $indexes = array(
                'monitoring_data' => array(
                    'idx_device_timestamp_metric' => '(device_id, timestamp, metric_name)',
                    'idx_timestamp_device' => '(timestamp, device_id)',
                    'idx_metric_timestamp' => '(metric_name, timestamp)'
                ),
                'alerts' => array(
                    'idx_device_status_created' => '(device_id, status, created_at)',
                    'idx_severity_status' => '(severity, status)',
                    'idx_created_status' => '(created_at, status)'
                ),
                'device_interfaces' => array(
                    'idx_device_oper_status' => '(device_id, oper_status)',
                    'idx_device_admin_status' => '(device_id, admin_status)'
                )
            );
            
            foreach ($indexes as $table => $tableIndexes) {
                foreach ($tableIndexes as $indexName => $indexColumns) {
                    try {
                        $sql = "CREATE INDEX $indexName ON $table $indexColumns";
                        $pdo->exec($sql);
                        $optimizations[] = "Added index $indexName to $table";
                    } catch (PDOException $e) {
                        // Index might already exist
                        if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                            $optimizations[] = "Failed to add index $indexName: " . $e->getMessage();
                        }
                    }
                }
            }
            
            // Analyze tables for query optimization
            $tables = array('devices', 'device_interfaces', 'monitoring_data', 'alerts', 'polling_schedule');
            foreach ($tables as $table) {
                try {
                    $pdo->exec("ANALYZE TABLE $table");
                    $optimizations[] = "Analyzed table $table";
                } catch (PDOException $e) {
                    $optimizations[] = "Failed to analyze $table: " . $e->getMessage();
                }
            }
            
            // Optimize tables (MySQL 5.5 compatible)
            foreach ($tables as $table) {
                try {
                    $pdo->exec("OPTIMIZE TABLE $table");
                    $optimizations[] = "Optimized table $table";
                } catch (PDOException $e) {
                    $optimizations[] = "Failed to optimize $table: " . $e->getMessage();
                }
            }
            
        } catch (Exception $e) {
            $optimizations[] = "Database optimization error: " . $e->getMessage();
        }
        
        return $optimizations;
    }
    
    /**
     * Simple file-based caching system (2012 approach)
     */
    public function getCachedData($key, $maxAge = 300) { // 5 minutes default
        if (!$this->cacheEnabled) {
            return false;
        }
        
        $cacheFile = $this->cacheDir . '/' . md5($key) . '.cache';
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $maxAge) {
            $data = file_get_contents($cacheFile);
            return unserialize($data);
        }
        
        return false;
    }
    
    /**
     * Store data in cache
     */
    public function setCachedData($key, $data) {
        if (!$this->cacheEnabled) {
            return false;
        }
        
        $cacheFile = $this->cacheDir . '/' . md5($key) . '.cache';
        $serialized = serialize($data);
        
        return file_put_contents($cacheFile, $serialized, LOCK_EX) !== false;
    }
    
    /**
     * Clear cache files older than specified age
     */
    public function clearCache($maxAge = 3600) {
        $files = glob($this->cacheDir . '/*.cache');
        $cutoff = time() - $maxAge;
        $cleared = 0;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $cleared++;
                }
            }
        }
        
        return $cleared;
    }
    
    /**
     * Optimized device status query with caching
     */
    public function getOptimizedDeviceStatus($useCache = true) {
        $cacheKey = 'device_status_summary';
        
        if ($useCache) {
            $cached = $this->getCachedData($cacheKey, 60); // 1 minute cache
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Optimized query with proper indexes
        $sql = "SELECT 
                    d.device_id,
                    d.device_name,
                    d.ip_address,
                    d.device_type,
                    d.status,
                    COUNT(DISTINCT di.interface_id) as interface_count,
                    COUNT(DISTINCT CASE WHEN a.status = 'open' THEN a.alert_id END) as open_alerts,
                    MAX(md.timestamp) as last_data_received,
                    COUNT(DISTINCT md.data_id) as total_datapoints
                FROM devices d
                LEFT JOIN device_interfaces di ON d.device_id = di.device_id
                LEFT JOIN alerts a ON d.device_id = a.device_id AND a.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                LEFT JOIN monitoring_data md ON d.device_id = md.device_id AND md.timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                GROUP BY d.device_id, d.device_name, d.ip_address, d.device_type, d.status
                ORDER BY d.device_name";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetchAll();
        
        if ($useCache) {
            $this->setCachedData($cacheKey, $result);
        }
        
        return $result;
    }
    
    /**
     * Optimized alert query with pagination
     */
    public function getOptimizedAlerts($deviceId = null, $limit = 50, $offset = 0, $useCache = true) {
        $cacheKey = "alerts_${deviceId}_${limit}_${offset}";
        
        if ($useCache) {
            $cached = $this->getCachedData($cacheKey, 120); // 2 minute cache
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $sql = "SELECT a.alert_id, a.device_id, a.alert_type, a.severity, a.message, 
                       a.status, a.created_at, a.acknowledged_at, a.resolved_at,
                       d.device_name, d.ip_address
                FROM alerts a
                FORCE INDEX (idx_created_status)
                JOIN devices d ON a.device_id = d.device_id
                WHERE 1=1";
        
        $params = array();
        
        if ($deviceId !== null) {
            $sql .= " AND a.device_id = :device_id";
            $params[':device_id'] = $deviceId;
        }
        
        $sql .= " ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $result = $stmt->fetchAll();
        
        if ($useCache) {
            $this->setCachedData($cacheKey, $result);
        }
        
        return $result;
    }
    
    /**
     * Batch insert monitoring data for better performance
     */
    public function batchInsertMonitoringData($dataPoints) {
        if (empty($dataPoints)) {
            return false;
        }
        
        // Prepare batch insert statement
        $sql = "INSERT INTO monitoring_data (device_id, interface_id, metric_name, metric_value, numeric_value, timestamp) VALUES ";
        $values = array();
        $params = array();
        
        foreach ($dataPoints as $i => $data) {
            $values[] = "(:device_id_$i, :interface_id_$i, :metric_name_$i, :metric_value_$i, :numeric_value_$i, :timestamp_$i)";
            $params[":device_id_$i"] = $data['device_id'];
            $params[":interface_id_$i"] = $data['interface_id'];
            $params[":metric_name_$i"] = $data['metric_name'];
            $params[":metric_value_$i"] = $data['metric_value'];
            $params[":numeric_value_$i"] = $data['numeric_value'];
            $params[":timestamp_$i"] = $data['timestamp'];
        }
        
        $sql .= implode(', ', $values);
        
        $stmt = $this->db->getConnection()->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * Clean old monitoring data to maintain performance
     */
    public function cleanOldData($retentionDays = 90) {
        $cutoffDate = date('Y-m-d H:i:s', time() - ($retentionDays * 86400));
        
        $results = array();
        
        // Clean monitoring data
        $sql = "DELETE FROM monitoring_data WHERE timestamp < :cutoff_date";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(':cutoff_date' => $cutoffDate));
        $results['monitoring_data'] = $stmt->rowCount();
        
        // Clean resolved alerts older than retention period
        $sql = "DELETE FROM alerts WHERE status = 'resolved' AND resolved_at < :cutoff_date";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(':cutoff_date' => $cutoffDate));
        $results['alerts'] = $stmt->rowCount();
        
        return $results;
    }
    
    /**
     * Get database performance statistics
     */
    public function getDatabaseStats() {
        $pdo = $this->db->getConnection();
        $stats = array();
        
        try {
            // Table sizes
            $sql = "SELECT table_name, 
                           ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb',
                           table_rows
                    FROM information_schema.TABLES 
                    WHERE table_schema = DATABASE()
                    ORDER BY (data_length + index_length) DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $stats['table_sizes'] = $stmt->fetchAll();
            
            // Index usage
            $sql = "SELECT DISTINCT 
                           TABLE_NAME, 
                           INDEX_NAME, 
                           SEQ_IN_INDEX, 
                           COLUMN_NAME
                    FROM information_schema.STATISTICS 
                    WHERE TABLE_SCHEMA = DATABASE()
                    ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $stats['indexes'] = $stmt->fetchAll();
            
            // Recent slow queries (if enabled)
            $sql = "SHOW VARIABLES LIKE 'slow_query_log'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $slowLogEnabled = $stmt->fetchColumn(1);
            
            $stats['slow_query_log'] = $slowLogEnabled;
            
        } catch (Exception $e) {
            $stats['error'] = $e->getMessage();
        }
        
        return $stats;
    }
    
    /**
     * Monitor query performance
     */
    public function profileQuery($sql, $params = array()) {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetchAll();
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage();
        
        return array(
            'execution_time' => round(($endTime - $startTime) * 1000, 2), // ms
            'memory_used' => $endMemory - $startMemory,
            'rows_returned' => count($result),
            'result' => $result
        );
    }
    
    /**
     * Optimize polling schedule based on device responsiveness
     */
    public function optimizePollingSchedule() {
        $sql = "SELECT device_id, poll_interval, last_poll,
                       (SELECT COUNT(*) FROM alerts 
                        WHERE device_id = ps.device_id 
                        AND alert_type = 'snmp_timeout' 
                        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as timeout_count
                FROM polling_schedule ps
                WHERE enabled = TRUE";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        $schedules = $stmt->fetchAll();
        
        $optimized = 0;
        
        foreach ($schedules as $schedule) {
            $newInterval = $schedule['poll_interval'];
            
            // If device has frequent timeouts, reduce polling frequency
            if ($schedule['timeout_count'] > 5) {
                $newInterval = min($newInterval * 2, 1800); // Max 30 minutes
            } 
            // If device is stable, can poll more frequently
            elseif ($schedule['timeout_count'] == 0) {
                $newInterval = max($newInterval * 0.8, 60); // Min 1 minute
            }
            
            if ($newInterval != $schedule['poll_interval']) {
                $updateSql = "UPDATE polling_schedule SET poll_interval = :interval WHERE device_id = :device_id";
                $updateStmt = $this->db->getConnection()->prepare($updateSql);
                $updateStmt->execute(array(
                    ':interval' => $newInterval,
                    ':device_id' => $schedule['device_id']
                ));
                $optimized++;
            }
        }
        
        return $optimized;
    }
    
    /**
     * Generate performance report
     */
    public function generatePerformanceReport() {
        $report = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'database_stats' => $this->getDatabaseStats(),
            'cache_stats' => array(
                'cache_dir' => $this->cacheDir,
                'cache_files' => count(glob($this->cacheDir . '/*.cache')),
                'cache_size_mb' => round(array_sum(array_map('filesize', glob($this->cacheDir . '/*.cache'))) / 1024 / 1024, 2)
            ),
            'recommendations' => array()
        );
        
        // Add performance recommendations
        foreach ($report['database_stats']['table_sizes'] as $table) {
            if ($table['size_mb'] > 100) {
                $report['recommendations'][] = "Consider archiving old data from {$table['table_name']} (current size: {$table['size_mb']} MB)";
            }
        }
        
        if ($report['cache_stats']['cache_files'] > 1000) {
            $report['recommendations'][] = "Cache directory has many files, consider cleanup";
        }
        
        return $report;
    }
}

// CLI interface for maintenance tasks
if (isset($argv[1])) {
    try {
        require_once 'database.php';
        
        $optimizer = new PerformanceOptimizer();
        
        switch ($argv[1]) {
            case 'optimize':
                echo "Optimizing database...\n";
                $results = $optimizer->optimizeDatabase();
                foreach ($results as $result) {
                    echo "- $result\n";
                }
                break;
                
            case 'cleanup':
                $days = isset($argv[2]) ? (int)$argv[2] : 90;
                echo "Cleaning data older than $days days...\n";
                $results = $optimizer->cleanOldData($days);
                echo "Cleaned monitoring_data: {$results['monitoring_data']} rows\n";
                echo "Cleaned alerts: {$results['alerts']} rows\n";
                break;
                
            case 'cache-clear':
                $cleared = $optimizer->clearCache();
                echo "Cleared $cleared cache files\n";
                break;
                
            case 'stats':
                $stats = $optimizer->getDatabaseStats();
                echo "Database Performance Statistics:\n";
                echo "================================\n";
                foreach ($stats['table_sizes'] as $table) {
                    echo "{$table['table_name']}: {$table['size_mb']} MB ({$table['table_rows']} rows)\n";
                }
                break;
                
            case 'report':
                $report = $optimizer->generatePerformanceReport();
                echo json_encode($report, JSON_PRETTY_PRINT);
                break;
                
            default:
                echo "Usage: php performance_optimizer.php [optimize|cleanup|cache-clear|stats|report] [days]\n";
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
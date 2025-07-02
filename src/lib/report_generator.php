<?php
/*
 * Automated Report Generator
 * December 2, 2012 - Cron jobs for daily/weekly network reports
 * Generates PDF and HTML reports with network statistics
 */

class ReportGenerator {
    
    private $db;
    private $rrd;
    private $outputPath;
    private $webPath;
    
    public function __construct($outputPath = '/var/www/html/netmon/reports', $webPath = '/netmon/reports') {
        $this->db = new DatabaseManager();
        $this->rrd = new RRDManager();
        $this->outputPath = $outputPath;
        $this->webPath = $webPath;
        
        // Create reports directory
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }
    
    /**
     * Generate daily network report
     */
    public function generateDailyReport($date = null) {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        $reportData = $this->collectDailyData($date);
        
        // Generate HTML report
        $htmlFile = $this->outputPath . "/daily_report_$date.html";
        $this->generateHTMLReport($reportData, $htmlFile, 'Daily Network Report', $date);
        
        // Generate text summary for email
        $textFile = $this->outputPath . "/daily_summary_$date.txt";
        $this->generateTextSummary($reportData, $textFile);
        
        return array(
            'html' => $this->webPath . "/daily_report_$date.html",
            'text' => $this->webPath . "/daily_summary_$date.txt",
            'data' => $reportData
        );
    }
    
    /**
     * Generate weekly network report
     */
    public function generateWeeklyReport($weekStart = null) {
        if ($weekStart === null) {
            // Get Monday of current week
            $weekStart = date('Y-m-d', strtotime('monday this week'));
        }
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        
        $reportData = $this->collectWeeklyData($weekStart, $weekEnd);
        
        // Generate HTML report
        $htmlFile = $this->outputPath . "/weekly_report_$weekStart.html";
        $this->generateHTMLReport($reportData, $htmlFile, 'Weekly Network Report', "$weekStart to $weekEnd");
        
        return array(
            'html' => $this->webPath . "/weekly_report_$weekStart.html",
            'data' => $reportData
        );
    }
    
    /**
     * Generate monthly network report
     */
    public function generateMonthlyReport($month = null) {
        if ($month === null) {
            $month = date('Y-m');
        }
        
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month -1 day'));
        
        $reportData = $this->collectMonthlyData($monthStart, $monthEnd);
        
        // Generate HTML report
        $htmlFile = $this->outputPath . "/monthly_report_$month.html";
        $this->generateHTMLReport($reportData, $htmlFile, 'Monthly Network Report', date('F Y', strtotime($monthStart)));
        
        return array(
            'html' => $this->webPath . "/monthly_report_$month.html",
            'data' => $reportData
        );
    }
    
    /**
     * Collect daily statistics
     */
    private function collectDailyData($date) {
        $startTime = strtotime($date . ' 00:00:00');
        $endTime = strtotime($date . ' 23:59:59');
        
        $data = array(
            'date' => $date,
            'period' => 'Daily',
            'devices' => $this->getDeviceStats($startTime, $endTime),
            'alerts' => $this->getAlertStats($startTime, $endTime),
            'availability' => $this->getAvailabilityStats($startTime, $endTime),
            'traffic' => $this->getTrafficStats($startTime, $endTime),
            'top_interfaces' => $this->getTopInterfaces($startTime, $endTime),
            'incidents' => $this->getIncidents($startTime, $endTime)
        );
        
        return $data;
    }
    
    /**
     * Collect weekly statistics
     */
    private function collectWeeklyData($startDate, $endDate) {
        $startTime = strtotime($startDate . ' 00:00:00');
        $endTime = strtotime($endDate . ' 23:59:59');
        
        $data = array(
            'date' => "$startDate to $endDate",
            'period' => 'Weekly',
            'devices' => $this->getDeviceStats($startTime, $endTime),
            'alerts' => $this->getAlertStats($startTime, $endTime),
            'availability' => $this->getAvailabilityStats($startTime, $endTime),
            'traffic' => $this->getTrafficStats($startTime, $endTime),
            'top_interfaces' => $this->getTopInterfaces($startTime, $endTime),
            'trends' => $this->getTrendAnalysis($startTime, $endTime),
            'incidents' => $this->getIncidents($startTime, $endTime)
        );
        
        return $data;
    }
    
    /**
     * Collect monthly statistics
     */
    private function collectMonthlyData($startDate, $endDate) {
        $startTime = strtotime($startDate . ' 00:00:00');
        $endTime = strtotime($endDate . ' 23:59:59');
        
        $data = array(
            'date' => date('F Y', strtotime($startDate)),
            'period' => 'Monthly',
            'devices' => $this->getDeviceStats($startTime, $endTime),
            'alerts' => $this->getAlertStats($startTime, $endTime),
            'availability' => $this->getAvailabilityStats($startTime, $endTime),
            'traffic' => $this->getTrafficStats($startTime, $endTime),
            'top_interfaces' => $this->getTopInterfaces($startTime, $endTime),
            'trends' => $this->getTrendAnalysis($startTime, $endTime),
            'capacity' => $this->getCapacityAnalysis($startTime, $endTime),
            'incidents' => $this->getIncidents($startTime, $endTime)
        );
        
        return $data;
    }
    
    /**
     * Get device statistics
     */
    private function getDeviceStats($startTime, $endTime) {
        $sql = "SELECT COUNT(*) as total_devices,
                       SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_devices,
                       SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_devices
                FROM devices";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute();
        $deviceCounts = $stmt->fetch();
        
        // Get devices with recent data
        $sql = "SELECT d.device_name, d.ip_address, d.device_type,
                       COUNT(DISTINCT md.metric_name) as metrics_collected,
                       MAX(md.timestamp) as last_data
                FROM devices d
                LEFT JOIN monitoring_data md ON d.device_id = md.device_id 
                    AND md.timestamp BETWEEN :start_time AND :end_time
                WHERE d.status = 'active'
                GROUP BY d.device_id, d.device_name, d.ip_address, d.device_type
                ORDER BY d.device_name";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(
            ':start_time' => date('Y-m-d H:i:s', $startTime),
            ':end_time' => date('Y-m-d H:i:s', $endTime)
        ));
        $deviceDetails = $stmt->fetchAll();
        
        return array(
            'summary' => $deviceCounts,
            'details' => $deviceDetails
        );
    }
    
    /**
     * Get alert statistics
     */
    private function getAlertStats($startTime, $endTime) {
        $sql = "SELECT 
                    COUNT(*) as total_alerts,
                    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_alerts,
                    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high_alerts,
                    SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium_alerts,
                    SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low_alerts,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_alerts,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_alerts
                FROM alerts 
                WHERE created_at BETWEEN :start_time AND :end_time";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(
            ':start_time' => date('Y-m-d H:i:s', $startTime),
            ':end_time' => date('Y-m-d H:i:s', $endTime)
        ));
        $alertCounts = $stmt->fetch();
        
        // Get recent critical alerts
        $sql = "SELECT a.alert_type, a.severity, a.message, a.created_at, 
                       d.device_name, d.ip_address
                FROM alerts a
                JOIN devices d ON a.device_id = d.device_id
                WHERE a.created_at BETWEEN :start_time AND :end_time
                  AND a.severity IN ('critical', 'high')
                ORDER BY a.created_at DESC
                LIMIT 10";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(
            ':start_time' => date('Y-m-d H:i:s', $startTime),
            ':end_time' => date('Y-m-d H:i:s', $endTime)
        ));
        $recentAlerts = $stmt->fetchAll();
        
        return array(
            'summary' => $alertCounts,
            'recent' => $recentAlerts
        );
    }
    
    /**
     * Get availability statistics
     */
    private function getAvailabilityStats($startTime, $endTime) {
        // Simple availability calculation based on successful polls
        $sql = "SELECT d.device_name, d.ip_address,
                       COUNT(md.data_id) as total_polls,
                       COUNT(CASE WHEN md.metric_name = 'sysUpTime' THEN 1 END) as successful_polls
                FROM devices d
                LEFT JOIN monitoring_data md ON d.device_id = md.device_id 
                    AND md.timestamp BETWEEN :start_time AND :end_time
                WHERE d.status = 'active'
                GROUP BY d.device_id, d.device_name, d.ip_address
                HAVING total_polls > 0
                ORDER BY successful_polls DESC";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(
            ':start_time' => date('Y-m-d H:i:s', $startTime),
            ':end_time' => date('Y-m-d H:i:s', $endTime)
        ));
        $availability = $stmt->fetchAll();
        
        // Calculate percentages
        foreach ($availability as &$device) {
            $device['availability_percent'] = $device['total_polls'] > 0 ? 
                round(($device['successful_polls'] / $device['total_polls']) * 100, 2) : 0;
        }
        
        return $availability;
    }
    
    /**
     * Get traffic statistics
     */
    private function getTrafficStats($startTime, $endTime) {
        $sql = "SELECT 
                    SUM(CASE WHEN metric_name LIKE '%_in_octets' THEN numeric_value ELSE 0 END) as total_input,
                    SUM(CASE WHEN metric_name LIKE '%_out_octets' THEN numeric_value ELSE 0 END) as total_output,
                    COUNT(DISTINCT device_id) as devices_with_traffic
                FROM monitoring_data 
                WHERE timestamp BETWEEN :start_time AND :end_time
                  AND metric_name LIKE '%_octets'";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(
            ':start_time' => date('Y-m-d H:i:s', $startTime),
            ':end_time' => date('Y-m-d H:i:s', $endTime)
        ));
        
        return $stmt->fetch();
    }
    
    /**
     * Get top interfaces by traffic
     */
    private function getTopInterfaces($startTime, $endTime, $limit = 10) {
        $sql = "SELECT d.device_name, d.ip_address, 
                       di.interface_name, di.interface_index,
                       SUM(CASE WHEN md.metric_name LIKE '%_in_octets' THEN md.numeric_value ELSE 0 END) as input_octets,
                       SUM(CASE WHEN md.metric_name LIKE '%_out_octets' THEN md.numeric_value ELSE 0 END) as output_octets
                FROM monitoring_data md
                JOIN devices d ON md.device_id = d.device_id
                LEFT JOIN device_interfaces di ON d.device_id = di.device_id 
                    AND md.metric_name LIKE CONCAT('%_', di.interface_index, '_%_octets')
                WHERE md.timestamp BETWEEN :start_time AND :end_time
                  AND md.metric_name LIKE '%_octets'
                GROUP BY d.device_id, di.interface_id
                HAVING (input_octets + output_octets) > 0
                ORDER BY (input_octets + output_octets) DESC
                LIMIT :limit";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':start_time', date('Y-m-d H:i:s', $startTime));
        $stmt->bindValue(':end_time', date('Y-m-d H:i:s', $endTime));
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get incidents and outages
     */
    private function getIncidents($startTime, $endTime) {
        $sql = "SELECT alert_type, COUNT(*) as incident_count,
                       AVG(CASE WHEN resolved_at IS NOT NULL 
                           THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) 
                           ELSE NULL END) as avg_resolution_time
                FROM alerts 
                WHERE created_at BETWEEN :start_time AND :end_time
                  AND severity IN ('critical', 'high')
                GROUP BY alert_type
                ORDER BY incident_count DESC";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(
            ':start_time' => date('Y-m-d H:i:s', $startTime),
            ':end_time' => date('Y-m-d H:i:s', $endTime)
        ));
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get trend analysis (for weekly/monthly reports)
     */
    private function getTrendAnalysis($startTime, $endTime) {
        // Simple trend analysis - compare with previous period
        $periodLength = $endTime - $startTime;
        $prevStartTime = $startTime - $periodLength;
        $prevEndTime = $startTime;
        
        // Current period alerts
        $sql = "SELECT COUNT(*) as current_alerts FROM alerts WHERE created_at BETWEEN :start1 AND :end1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array(':start1' => date('Y-m-d H:i:s', $startTime), ':end1' => date('Y-m-d H:i:s', $endTime)));
        $currentAlerts = $stmt->fetchColumn();
        
        // Previous period alerts
        $stmt->execute(array(':start1' => date('Y-m-d H:i:s', $prevStartTime), ':end1' => date('Y-m-d H:i:s', $prevEndTime)));
        $prevAlerts = $stmt->fetchColumn();
        
        $alertTrend = $prevAlerts > 0 ? round((($currentAlerts - $prevAlerts) / $prevAlerts) * 100, 1) : 0;
        
        return array(
            'alert_trend' => $alertTrend,
            'current_alerts' => $currentAlerts,
            'previous_alerts' => $prevAlerts
        );
    }
    
    /**
     * Get capacity analysis (for monthly reports)
     */
    private function getCapacityAnalysis($startTime, $endTime) {
        // Analyze interface utilization trends
        return array(
            'note' => 'Capacity analysis requires more historical data and complex calculations'
        );
    }
    
    /**
     * Generate HTML report
     */
    private function generateHTMLReport($data, $filename, $title, $period) {
        $html = $this->getHTMLTemplate($data, $title, $period);
        file_put_contents($filename, $html);
    }
    
    /**
     * Generate text summary for email
     */
    private function generateTextSummary($data, $filename) {
        $text = "NETWORK MONITORING SUMMARY\n";
        $text .= "Period: {$data['date']}\n";
        $text .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        $text .= "DEVICE STATUS:\n";
        $text .= "- Total Devices: {$data['devices']['summary']['total_devices']}\n";
        $text .= "- Active Devices: {$data['devices']['summary']['active_devices']}\n";
        $text .= "- Inactive Devices: {$data['devices']['summary']['inactive_devices']}\n\n";
        
        $text .= "ALERTS:\n";
        $text .= "- Total Alerts: {$data['alerts']['summary']['total_alerts']}\n";
        $text .= "- Critical: {$data['alerts']['summary']['critical_alerts']}\n";
        $text .= "- High: {$data['alerts']['summary']['high_alerts']}\n";
        $text .= "- Resolved: {$data['alerts']['summary']['resolved_alerts']}\n\n";
        
        if (!empty($data['alerts']['recent'])) {
            $text .= "RECENT CRITICAL/HIGH ALERTS:\n";
            foreach (array_slice($data['alerts']['recent'], 0, 5) as $alert) {
                $text .= "- {$alert['device_name']}: {$alert['message']} [{$alert['severity']}]\n";
            }
            $text .= "\n";
        }
        
        $text .= "AVAILABILITY (Top 10):\n";
        foreach (array_slice($data['availability'], 0, 10) as $device) {
            $text .= "- {$device['device_name']}: {$device['availability_percent']}%\n";
        }
        
        file_put_contents($filename, $text);
    }
    
    /**
     * HTML template for reports (2012 style)
     */
    private function getHTMLTemplate($data, $title, $period) {
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo htmlspecialchars($title); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
        .header { background: #f5f5f5; padding: 20px; border: 1px solid #ddd; margin-bottom: 20px; }
        .section { margin-bottom: 30px; }
        .section h3 { color: #333; border-bottom: 2px solid #ddd; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
        th { background: #f9f9f9; font-weight: bold; }
        .critical { color: #b94a48; font-weight: bold; }
        .high { color: #f89406; font-weight: bold; }
        .good { color: #468847; font-weight: bold; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
        .summary-box { background: #f0f8ff; border: 1px solid #b0d4f1; padding: 15px; margin: 10px 0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo htmlspecialchars($title); ?></h1>
        <p><strong>Period:</strong> <?php echo htmlspecialchars($period); ?></p>
        <p><strong>Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
    </div>

    <!-- Device Summary -->
    <div class="section">
        <h3>Device Summary</h3>
        <div class="summary-box">
            <table style="width: 50%;">
                <tr><td><strong>Total Devices:</strong></td><td><?php echo $data['devices']['summary']['total_devices']; ?></td></tr>
                <tr><td><strong>Active Devices:</strong></td><td class="good"><?php echo $data['devices']['summary']['active_devices']; ?></td></tr>
                <tr><td><strong>Inactive Devices:</strong></td><td class="<?php echo $data['devices']['summary']['inactive_devices'] > 0 ? 'critical' : 'good'; ?>"><?php echo $data['devices']['summary']['inactive_devices']; ?></td></tr>
            </table>
        </div>
    </div>

    <!-- Alert Summary -->
    <div class="section">
        <h3>Alert Summary</h3>
        <div class="summary-box">
            <table style="width: 50%;">
                <tr><td><strong>Total Alerts:</strong></td><td><?php echo $data['alerts']['summary']['total_alerts']; ?></td></tr>
                <tr><td><strong>Critical:</strong></td><td class="critical"><?php echo $data['alerts']['summary']['critical_alerts']; ?></td></tr>
                <tr><td><strong>High:</strong></td><td class="high"><?php echo $data['alerts']['summary']['high_alerts']; ?></td></tr>
                <tr><td><strong>Medium:</strong></td><td><?php echo $data['alerts']['summary']['medium_alerts']; ?></td></tr>
                <tr><td><strong>Low:</strong></td><td><?php echo $data['alerts']['summary']['low_alerts']; ?></td></tr>
                <tr><td><strong>Resolved:</strong></td><td class="good"><?php echo $data['alerts']['summary']['resolved_alerts']; ?></td></tr>
                <tr><td><strong>Still Open:</strong></td><td class="<?php echo $data['alerts']['summary']['open_alerts'] > 0 ? 'critical' : 'good'; ?>"><?php echo $data['alerts']['summary']['open_alerts']; ?></td></tr>
            </table>
        </div>

        <?php if (!empty($data['alerts']['recent'])): ?>
        <h4>Recent Critical/High Priority Alerts</h4>
        <table>
            <thead>
                <tr>
                    <th>Device</th>
                    <th>Alert Type</th>
                    <th>Severity</th>
                    <th>Message</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['alerts']['recent'] as $alert): ?>
                <tr>
                    <td><?php echo htmlspecialchars($alert['device_name']); ?></td>
                    <td><?php echo htmlspecialchars($alert['alert_type']); ?></td>
                    <td class="<?php echo $alert['severity']; ?>"><?php echo strtoupper($alert['severity']); ?></td>
                    <td><?php echo htmlspecialchars($alert['message']); ?></td>
                    <td><?php echo date('M j H:i', strtotime($alert['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Availability Report -->
    <div class="section">
        <h3>Device Availability</h3>
        <table>
            <thead>
                <tr>
                    <th>Device Name</th>
                    <th>IP Address</th>
                    <th>Total Polls</th>
                    <th>Successful Polls</th>
                    <th>Availability %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['availability'] as $device): ?>
                <tr>
                    <td><?php echo htmlspecialchars($device['device_name']); ?></td>
                    <td><?php echo htmlspecialchars($device['ip_address']); ?></td>
                    <td><?php echo $device['total_polls']; ?></td>
                    <td><?php echo $device['successful_polls']; ?></td>
                    <td class="<?php echo $device['availability_percent'] >= 99 ? 'good' : ($device['availability_percent'] >= 95 ? 'high' : 'critical'); ?>">
                        <?php echo $device['availability_percent']; ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Traffic Summary -->
    <?php if ($data['traffic']['total_input'] > 0 || $data['traffic']['total_output'] > 0): ?>
    <div class="section">
        <h3>Traffic Summary</h3>
        <div class="summary-box">
            <table style="width: 50%;">
                <tr><td><strong>Total Input:</strong></td><td><?php echo number_format($data['traffic']['total_input']); ?> octets</td></tr>
                <tr><td><strong>Total Output:</strong></td><td><?php echo number_format($data['traffic']['total_output']); ?> octets</td></tr>
                <tr><td><strong>Devices with Traffic:</strong></td><td><?php echo $data['traffic']['devices_with_traffic']; ?></td></tr>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="footer">
        <p>Report generated by Network Monitoring System v1.0 - December 2012</p>
        <p>Etech Eritrea PLC - Network Operations Center</p>
    </div>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Clean old reports
     */
    public function cleanOldReports($maxAge = 2592000) { // 30 days
        $files = glob($this->outputPath . '/*.{html,txt}', GLOB_BRACE);
        $cutoff = time() - $maxAge;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
    
    /**
     * Email report (basic implementation)
     */
    public function emailReport($reportFile, $recipients, $subject) {
        if (!file_exists($reportFile)) {
            return false;
        }
        
        $content = file_get_contents($reportFile);
        $headers = "From: netmon@etech.com\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        foreach ($recipients as $email) {
            mail($email, $subject, $content, $headers);
        }
        
        return true;
    }
}

// CLI interface for cron jobs
if (isset($argv[1])) {
    try {
        require_once 'database.php';
        require_once 'rrd_manager.php';
        
        $generator = new ReportGenerator();
        
        switch ($argv[1]) {
            case 'daily':
                $date = isset($argv[2]) ? $argv[2] : null;
                $report = $generator->generateDailyReport($date);
                echo "Daily report generated: {$report['html']}\n";
                break;
                
            case 'weekly':
                $week = isset($argv[2]) ? $argv[2] : null;
                $report = $generator->generateWeeklyReport($week);
                echo "Weekly report generated: {$report['html']}\n";
                break;
                
            case 'monthly':
                $month = isset($argv[2]) ? $argv[2] : null;
                $report = $generator->generateMonthlyReport($month);
                echo "Monthly report generated: {$report['html']}\n";
                break;
                
            case 'cleanup':
                $generator->cleanOldReports();
                echo "Old reports cleaned up\n";
                break;
                
            default:
                echo "Usage: php report_generator.php [daily|weekly|monthly|cleanup] [date]\n";
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
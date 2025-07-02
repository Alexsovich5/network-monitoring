<?php
/*
 * Real-time Dashboard Data Provider
 * November 15, 2012 - AJAX-based dashboard with auto-refresh
 * Returns JSON data for dashboard updates
 */

// Set content type for JSON response
header('Content-Type: application/json');

// Include required libraries
require_once '../lib/database.php';
require_once '../lib/snmp_poller.php';
require_once '../lib/nagios_integration.php';
require_once '../lib/performance_optimizer.php';

// Disable error output to prevent JSON corruption
error_reporting(0);

try {
    // Initialize components
    $db = new DatabaseManager();
    $nagios = new NagiosIntegration();
    $optimizer = new PerformanceOptimizer();
    
    $response = array(
        'status' => 'success',
        'timestamp' => time(),
        'datetime' => date('Y-m-d H:i:s'),
        'data' => array()
    );
    
    // Get action parameter
    $action = isset($_GET['action']) ? $_GET['action'] : 'summary';
    
    switch ($action) {
        case 'summary':
            // Get device summary (optimized with caching)
            $devices = $optimizer->getOptimizedDeviceStatus();
            $totalDevices = count($devices);
            $activeAlerts = 0;
            $devicesUp = 0;
            
            foreach ($devices as $device) {
                $activeAlerts += $device['open_alerts'];
                if ($device['status'] == 'active') {
                    $devicesUp++;
                }
            }
            
            // Get Nagios summary if available
            $nagiosData = array(
                'connected' => false,
                'hosts_up' => 0,
                'hosts_down' => 0,
                'services_ok' => 0,
                'services_problem' => 0
            );
            
            try {
                $nagiosInfo = $nagios->getNagiosInfo();
                $nagiosData = array(
                    'connected' => true,
                    'hosts_up' => $nagiosInfo['host_states']['up'],
                    'hosts_down' => $nagiosInfo['host_states']['down'] + $nagiosInfo['host_states']['unreachable'],
                    'services_ok' => $nagiosInfo['service_states']['ok'],
                    'services_problem' => $nagiosInfo['service_states']['warning'] + $nagiosInfo['service_states']['critical']
                );
            } catch (Exception $e) {
                // Nagios not available
            }
            
            $response['data'] = array(
                'devices' => array(
                    'total' => $totalDevices,
                    'up' => $devicesUp,
                    'down' => $totalDevices - $devicesUp,
                    'alerts' => $activeAlerts
                ),
                'nagios' => $nagiosData,
                'system' => array(
                    'load' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 0,
                    'memory_mb' => round(memory_get_usage() / 1024 / 1024, 2),
                    'uptime' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown'
                )
            );
            break;
            
        case 'devices':
            // Get detailed device status (optimized)
            $devices = $optimizer->getOptimizedDeviceStatus();
            $deviceData = array();
            
            foreach ($devices as $device) {
                $deviceData[] = array(
                    'id' => $device['device_id'],
                    'name' => $device['device_name'],
                    'ip' => $device['ip_address'],
                    'type' => $device['device_type'],
                    'status' => $device['status'],
                    'interfaces' => $device['interface_count'],
                    'alerts' => $device['open_alerts'],
                    'last_data' => $device['last_data_received']
                );
            }
            
            $response['data'] = $deviceData;
            break;
            
        case 'alerts':
            // Get active alerts (optimized with caching)
            $alerts = $optimizer->getOptimizedAlerts(null, 50, 0, true);
            $alertData = array();
            
            foreach ($alerts as $alert) {
                $alertData[] = array(
                    'id' => $alert['alert_id'],
                    'device' => $alert['device_name'],
                    'ip' => $alert['ip_address'],
                    'type' => $alert['alert_type'],
                    'severity' => $alert['severity'],
                    'message' => $alert['message'],
                    'created' => $alert['created_at'],
                    'age' => time() - strtotime($alert['created_at'])
                );
            }
            
            $response['data'] = $alertData;
            break;
            
        case 'poll_device':
            // Poll specific device via SNMP
            $deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
            
            if ($deviceId > 0) {
                $device = $db->getDevice($deviceId);
                
                if ($device) {
                    $poller = new SNMPPoller($device['snmp_community']);
                    
                    if ($poller->testDevice($device['ip_address'])) {
                        // Get basic device info
                        $basicInfo = $poller->pollDevice($device['ip_address']);
                        
                        // Store monitoring data
                        foreach ($basicInfo as $metric => $value) {
                            $numericValue = is_numeric($value) ? $value : null;
                            $db->storeMonitoringData($deviceId, null, $metric, $value, $numericValue);
                        }
                        
                        // Update polling schedule
                        $db->updatePollingSchedule($deviceId);
                        
                        $response['data'] = array(
                            'device_id' => $deviceId,
                            'status' => 'polled',
                            'metrics' => $basicInfo,
                            'timestamp' => time()
                        );
                    } else {
                        // Device not responding - create alert
                        $db->createAlert($deviceId, null, 'snmp_timeout', 'high', 
                            'Device ' . $device['device_name'] . ' not responding to SNMP');
                        
                        $response['data'] = array(
                            'device_id' => $deviceId,
                            'status' => 'timeout',
                            'message' => 'Device not responding to SNMP'
                        );
                    }
                } else {
                    throw new Exception('Device not found');
                }
            } else {
                throw new Exception('Invalid device ID');
            }
            break;
            
        case 'interface_stats':
            // Get interface statistics for a device
            $deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
            
            if ($deviceId > 0) {
                $device = $db->getDevice($deviceId);
                
                if ($device) {
                    $poller = new SNMPPoller($device['snmp_community']);
                    $interfaces = $poller->getInterfaceList($device['ip_address']);
                    $interfaceStats = array();
                    
                    foreach ($interfaces as $ifIndex => $ifName) {
                        $stats = $poller->getInterfaceStats($device['ip_address'], $ifIndex);
                        if ($stats) {
                            // Update interface in database
                            $adminStatus = isset($stats['ifAdminStatus']) && $stats['ifAdminStatus'] == '1' ? 'up' : 'down';
                            $operStatus = isset($stats['ifOperStatus']) ? 
                                ($stats['ifOperStatus'] == '1' ? 'up' : 'down') : 'unknown';
                            
                            $db->updateInterface(
                                $deviceId, 
                                $ifIndex, 
                                $ifName,
                                isset($stats['ifType']) ? $stats['ifType'] : null,
                                isset($stats['ifMtu']) ? $stats['ifMtu'] : null,
                                isset($stats['ifSpeed']) ? $stats['ifSpeed'] : null,
                                $adminStatus,
                                $operStatus
                            );
                            
                            // Store traffic counters
                            if (isset($stats['ifInOctets'])) {
                                $db->storeMonitoringData($deviceId, null, "interface_{$ifIndex}_in_octets", 
                                    $stats['ifInOctets'], $stats['ifInOctets']);
                            }
                            if (isset($stats['ifOutOctets'])) {
                                $db->storeMonitoringData($deviceId, null, "interface_{$ifIndex}_out_octets", 
                                    $stats['ifOutOctets'], $stats['ifOutOctets']);
                            }
                            
                            $interfaceStats[] = array(
                                'index' => $ifIndex,
                                'name' => $ifName,
                                'admin_status' => $adminStatus,
                                'oper_status' => $operStatus,
                                'speed' => isset($stats['ifSpeed']) ? $stats['ifSpeed'] : 0,
                                'in_octets' => isset($stats['ifInOctets']) ? $stats['ifInOctets'] : 0,
                                'out_octets' => isset($stats['ifOutOctets']) ? $stats['ifOutOctets'] : 0
                            );
                        }
                    }
                    
                    $response['data'] = array(
                        'device_id' => $deviceId,
                        'interfaces' => $interfaceStats
                    );
                } else {
                    throw new Exception('Device not found');
                }
            } else {
                throw new Exception('Invalid device ID');
            }
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
} catch (Exception $e) {
    $response = array(
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => time()
    );
}

// Output JSON response
echo json_encode($response);
?>
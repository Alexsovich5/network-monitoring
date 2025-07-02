<?php
/*
 * Network Monitoring Dashboard - Web Interface Skeleton
 * October 28, 2012 - Basic PHP pages with Bootstrap CSS framework
 * PHP 5.4 Compatible
 */

// Basic error reporting for 2012
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required libraries
require_once 'lib/database.php';
require_once 'lib/snmp_poller.php';

// Simple 2012-style configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'network_monitoring');
define('DB_USER', 'netmon');
define('DB_PASS', 'netmon123');

// Initialize database connection
try {
    $db = new DatabaseManager();
    $dbConnected = true;
} catch (Exception $e) {
    $dbConnected = false;
    $dbError = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Network Monitoring Dashboard - Etech Eritrea PLC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Bootstrap CSS (Early 2012 version) -->
    <style>
        /* Bootstrap-inspired CSS for 2012 */
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 14px; line-height: 20px; color: #333; background-color: #fff; margin: 0; }
        .container { width: 940px; margin: 0 auto; }
        .navbar { overflow: visible; margin-bottom: 20px; background-color: #2c2c2c; background-image: linear-gradient(to bottom, #333, #222); border: 1px solid #252525; border-radius: 4px; }
        .navbar-inner { min-height: 40px; padding-left: 20px; padding-right: 20px; background-color: #2c2c2c; border-radius: 3px; }
        .navbar .brand { float: left; display: block; padding: 10px 20px; margin-left: -20px; font-size: 20px; font-weight: 200; color: #777; text-shadow: 0 1px 0 #333; }
        .navbar .nav { position: relative; left: 0; display: block; float: left; margin: 0 10px 0 0; }
        .navbar .nav > li { float: left; }
        .navbar .nav > li > a { float: none; padding: 10px 15px; color: #777; text-decoration: none; text-shadow: 0 1px 0 #333; }
        .navbar .nav > li > a:hover { background-color: transparent; color: #fff; text-decoration: none; }
        .hero-unit { padding: 60px; margin-bottom: 30px; background-color: #eee; border-radius: 6px; }
        .hero-unit h1 { margin-bottom: 0; font-size: 60px; line-height: 1; letter-spacing: -1px; color: inherit; }
        .hero-unit p { font-size: 18px; font-weight: 200; line-height: 30px; color: inherit; }
        .row { margin-left: -20px; }
        .span4 { float: left; min-height: 1px; margin-left: 20px; width: 300px; }
        .span6 { float: left; min-height: 1px; margin-left: 20px; width: 460px; }
        .span12 { float: left; min-height: 1px; margin-left: 20px; width: 940px; }
        .well { min-height: 20px; padding: 19px; margin-bottom: 20px; background-color: #f5f5f5; border: 1px solid #e3e3e3; border-radius: 4px; }
        .alert { padding: 8px 32px 8px 14px; margin-bottom: 20px; text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5); background-color: #fcf8e3; border: 1px solid #fbeed5; border-radius: 4px; }
        .alert-success { color: #468847; background-color: #dff0d8; border-color: #d6e9c6; }
        .alert-error { color: #b94a48; background-color: #f2dede; border-color: #eed3d7; }
        .btn { display: inline-block; padding: 4px 12px; margin-bottom: 0; font-size: 14px; line-height: 20px; text-align: center; vertical-align: middle; cursor: pointer; color: #333; text-shadow: 0 1px 1px rgba(255, 255, 255, 0.75); background-color: #f5f5f5; border: 1px solid #ccc; border-radius: 4px; }
        .btn:hover { color: #333; text-decoration: none; background-color: #e6e6e6; }
        .table { width: 100%; margin-bottom: 20px; background-color: transparent; border-collapse: collapse; border-spacing: 0; }
        .table th, .table td { padding: 8px; line-height: 20px; text-align: left; vertical-align: top; border-top: 1px solid #ddd; }
        .table th { font-weight: bold; }
        .table thead th { vertical-align: bottom; }
        .clearfix:after { display: table; content: ""; line-height: 0; clear: both; }
        .pull-right { float: right; }
        .status-up { color: #468847; font-weight: bold; }
        .status-down { color: #b94a48; font-weight: bold; }
        .status-unknown { color: #f89406; font-weight: bold; }
    </style>
</head>

<body>
    <!-- Navigation Bar -->
    <div class="navbar">
        <div class="navbar-inner">
            <div class="container">
                <a class="brand" href="index.php">Network Monitor</a>
                <ul class="nav">
                    <li><a href="index.php">Dashboard</a></li>
                    <li><a href="devices.php">Devices</a></li>
                    <li><a href="alerts.php">Alerts</a></li>
                    <li><a href="reports.php">Reports</a></li>
                    <li><a href="test_snmp.php">SNMP Test</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Header Section -->
        <div class="hero-unit">
            <h1>Network Operations Center</h1>
            <p>Enterprise Network Monitoring Dashboard - Etech Eritrea PLC</p>
            <p>Real-time monitoring and alerting for network infrastructure</p>
        </div>

        <!-- System Status -->
        <div class="row">
            <div class="span12">
                <?php if ($dbConnected): ?>
                    <div class="alert alert-success">
                        <strong>System Status:</strong> Database connected successfully
                    </div>
                <?php else: ?>
                    <div class="alert alert-error">
                        <strong>Database Error:</strong> <?php echo htmlspecialchars($dbError); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dashboard Content -->
        <div class="row">
            <!-- Device Summary -->
            <div class="span6">
                <div class="well">
                    <h3>Device Summary</h3>
                    <?php if ($dbConnected): ?>
                        <?php
                        try {
                            $devices = $db->getDeviceStatusSummary();
                            $totalDevices = count($devices);
                            $activeAlerts = 0;
                            foreach ($devices as $device) {
                                $activeAlerts += $device['open_alerts'];
                            }
                        } catch (Exception $e) {
                            $devices = array();
                            $totalDevices = 0;
                            $activeAlerts = 0;
                        }
                        ?>
                        <table class="table">
                            <tr><td><strong>Total Devices:</strong></td><td><?php echo $totalDevices; ?></td></tr>
                            <tr><td><strong>Active Alerts:</strong></td><td class="status-<?php echo $activeAlerts > 0 ? 'down' : 'up'; ?>"><?php echo $activeAlerts; ?></td></tr>
                            <tr><td><strong>Last Update:</strong></td><td><?php echo date('Y-m-d H:i:s'); ?></td></tr>
                        </table>
                    <?php else: ?>
                        <p>Database connection required to display device summary.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- System Information -->
            <div class="span6">
                <div class="well">
                    <h3>System Information</h3>
                    <table class="table">
                        <tr><td><strong>PHP Version:</strong></td><td><?php echo phpversion(); ?></td></tr>
                        <tr><td><strong>Server Time:</strong></td><td><?php echo date('Y-m-d H:i:s T'); ?></td></tr>
                        <tr><td><strong>System Load:</strong></td><td><?php echo function_exists('sys_getloadavg') ? implode(', ', sys_getloadavg()) : 'N/A'; ?></td></tr>
                        <tr><td><strong>Memory Usage:</strong></td><td><?php echo round(memory_get_usage() / 1024 / 1024, 2); ?> MB</td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Devices -->
        <?php if ($dbConnected && !empty($devices)): ?>
        <div class="row">
            <div class="span12">
                <div class="well">
                    <h3>Device Status Overview</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Device Name</th>
                                <th>IP Address</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Interfaces</th>
                                <th>Alerts</th>
                                <th>Last Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $device): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($device['device_name']); ?></td>
                                <td><?php echo htmlspecialchars($device['ip_address']); ?></td>
                                <td><?php echo htmlspecialchars($device['device_type']); ?></td>
                                <td><span class="status-<?php echo $device['status'] == 'active' ? 'up' : 'down'; ?>"><?php echo ucfirst($device['status']); ?></span></td>
                                <td><?php echo $device['interface_count']; ?></td>
                                <td class="<?php echo $device['open_alerts'] > 0 ? 'status-down' : 'status-up'; ?>"><?php echo $device['open_alerts']; ?></td>
                                <td><?php echo $device['last_data_received'] ? date('M j, H:i', strtotime($device['last_data_received'])) : 'Never'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="row">
            <div class="span12">
                <hr>
                <p class="pull-right">
                    <small>Network Monitoring System v1.0 - November 2012</small>
                </p>
                <div class="clearfix"></div>
            </div>
        </div>
    </div>

    <!-- Real-time Dashboard JavaScript (November 15, 2012) -->
    <script type="text/javascript">
        // 2012 JavaScript - jQuery-style AJAX for dashboard updates
        var Dashboard = {
            refreshInterval: 30000, // 30 seconds
            refreshTimer: null,
            
            init: function() {
                console.log('Dashboard initialized - Auto-refresh every 30 seconds');
                this.startAutoRefresh();
                this.bindEvents();
            },
            
            startAutoRefresh: function() {
                var self = this;
                this.refreshTimer = setInterval(function() {
                    self.updateSummary();
                    self.updateDeviceList();
                }, this.refreshInterval);
                
                // Initial load
                this.updateSummary();
                this.updateDeviceList();
            },
            
            stopAutoRefresh: function() {
                if (this.refreshTimer) {
                    clearInterval(this.refreshTimer);
                    this.refreshTimer = null;
                }
            },
            
            bindEvents: function() {
                // Manual refresh button (if exists)
                var refreshBtn = document.getElementById('refresh-btn');
                if (refreshBtn) {
                    var self = this;
                    refreshBtn.onclick = function() {
                        self.updateSummary();
                        self.updateDeviceList();
                        return false;
                    };
                }
            },
            
            updateSummary: function() {
                this.makeRequest('ajax/dashboard_data.php?action=summary', function(data) {
                    if (data.status === 'success') {
                        Dashboard.updateSummaryDisplay(data.data);
                    }
                });
            },
            
            updateDeviceList: function() {
                this.makeRequest('ajax/dashboard_data.php?action=devices', function(data) {
                    if (data.status === 'success') {
                        Dashboard.updateDeviceTable(data.data);
                    }
                });
            },
            
            updateSummaryDisplay: function(data) {
                // Update device summary
                var deviceSummary = document.querySelector('.well h3');
                if (deviceSummary && deviceSummary.textContent === 'Device Summary') {
                    var table = deviceSummary.parentNode.querySelector('table');
                    if (table) {
                        var rows = table.querySelectorAll('tr');
                        if (rows.length >= 3) {
                            // Update device count
                            rows[0].cells[1].textContent = data.devices.total;
                            
                            // Update alerts with color coding
                            var alertCell = rows[1].cells[1];
                            alertCell.textContent = data.devices.alerts;
                            alertCell.className = data.devices.alerts > 0 ? 'status-down' : 'status-up';
                            
                            // Update timestamp
                            rows[2].cells[1].textContent = data.datetime || new Date().toLocaleString();
                        }
                    }
                }
                
                // Update system info
                var systemInfo = document.querySelector('.well h3');
                var systemTables = document.querySelectorAll('.well h3');
                for (var i = 0; i < systemTables.length; i++) {
                    if (systemTables[i].textContent === 'System Information') {
                        var sysTable = systemTables[i].parentNode.querySelector('table');
                        if (sysTable && data.system) {
                            var sysRows = sysTable.querySelectorAll('tr');
                            if (sysRows.length >= 4) {
                                // Update memory usage
                                sysRows[3].cells[1].textContent = data.system.memory_mb + ' MB';
                            }
                        }
                        break;
                    }
                }
            },
            
            updateDeviceTable: function(devices) {
                var deviceTable = document.querySelector('table');
                var tables = document.querySelectorAll('table');
                
                // Find the device status table
                for (var i = 0; i < tables.length; i++) {
                    var thead = tables[i].querySelector('thead');
                    if (thead && thead.textContent.indexOf('Device Name') !== -1) {
                        var tbody = tables[i].querySelector('tbody');
                        if (tbody) {
                            // Clear existing rows
                            tbody.innerHTML = '';
                            
                            // Add updated device rows
                            for (var j = 0; j < devices.length; j++) {
                                var device = devices[j];
                                var row = tbody.insertRow();
                                
                                // Device name
                                row.insertCell(0).textContent = device.name;
                                
                                // IP address
                                row.insertCell(1).textContent = device.ip;
                                
                                // Type
                                row.insertCell(2).textContent = device.type;
                                
                                // Status with color
                                var statusCell = row.insertCell(3);
                                var statusSpan = document.createElement('span');
                                statusSpan.className = device.status === 'active' ? 'status-up' : 'status-down';
                                statusSpan.textContent = device.status.charAt(0).toUpperCase() + device.status.slice(1);
                                statusCell.appendChild(statusSpan);
                                
                                // Interface count
                                row.insertCell(4).textContent = device.interfaces;
                                
                                // Alerts with color
                                var alertCell = row.insertCell(5);
                                alertCell.textContent = device.alerts;
                                alertCell.className = device.alerts > 0 ? 'status-down' : 'status-up';
                                
                                // Last data
                                var lastDataCell = row.insertCell(6);
                                if (device.last_data) {
                                    var date = new Date(device.last_data);
                                    lastDataCell.textContent = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
                                } else {
                                    lastDataCell.textContent = 'Never';
                                }
                            }
                        }
                        break;
                    }
                }
            },
            
            makeRequest: function(url, callback) {
                // 2012-style AJAX using XMLHttpRequest
                var xhr = new XMLHttpRequest();
                xhr.open('GET', url, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                var data = JSON.parse(xhr.responseText);
                                callback(data);
                            } catch (e) {
                                console.error('JSON parse error:', e);
                            }
                        } else {
                            console.error('Request failed:', xhr.status);
                        }
                    }
                };
                
                xhr.send();
            }
        };
        
        // Initialize dashboard when page loads (2012 style)
        if (document.addEventListener) {
            document.addEventListener('DOMContentLoaded', function() {
                Dashboard.init();
            });
        } else if (document.attachEvent) {
            // IE8 support
            document.attachEvent('onreadystatechange', function() {
                if (document.readyState === 'complete') {
                    Dashboard.init();
                }
            });
        }
        
        // Status indicator in top right
        function updateStatusIndicator() {
            var indicator = document.getElementById('status-indicator');
            if (!indicator) {
                indicator = document.createElement('div');
                indicator.id = 'status-indicator';
                indicator.style.cssText = 'position:fixed;top:10px;right:10px;padding:5px 10px;background:#468847;color:white;border-radius:3px;font-size:12px;z-index:1000;';
                indicator.textContent = 'Online';
                document.body.appendChild(indicator);
            }
            
            // Blink indicator during updates
            indicator.style.background = '#f89406';
            indicator.textContent = 'Updating...';
            
            setTimeout(function() {
                indicator.style.background = '#468847';
                indicator.textContent = 'Online';
            }, 1000);
        }
        
        // Override update functions to show status indicator
        var originalUpdateSummary = Dashboard.updateSummary;
        Dashboard.updateSummary = function() {
            updateStatusIndicator();
            originalUpdateSummary.call(this);
        };
    </script>
</body>
</html>
<?php
/*
 * Nagios Status Display Page
 * November 5, 2012 - Integrated Nagios monitoring with network dashboard
 * Shows Nagios host and service status in web interface
 */

require_once 'lib/database.php';
require_once 'lib/nagios_integration.php';

// Initialize components
try {
    $db = new DatabaseManager();
    $nagios = new NagiosIntegration();
    $nagiosConnected = true;
    $nagiosInfo = $nagios->getNagiosInfo();
} catch (Exception $e) {
    $nagiosConnected = false;
    $nagiosError = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Nagios Status - Network Monitor</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Bootstrap CSS (2012 style) -->
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 14px; line-height: 20px; color: #333; background-color: #fff; margin: 0; }
        .container { width: 940px; margin: 0 auto; }
        .navbar { overflow: visible; margin-bottom: 20px; background-color: #2c2c2c; background-image: linear-gradient(to bottom, #333, #222); border: 1px solid #252525; border-radius: 4px; }
        .navbar-inner { min-height: 40px; padding-left: 20px; padding-right: 20px; background-color: #2c2c2c; border-radius: 3px; }
        .navbar .brand { float: left; display: block; padding: 10px 20px; margin-left: -20px; font-size: 20px; font-weight: 200; color: #777; text-shadow: 0 1px 0 #333; }
        .navbar .nav { position: relative; left: 0; display: block; float: left; margin: 0 10px 0 0; }
        .navbar .nav > li { float: left; }
        .navbar .nav > li > a { float: none; padding: 10px 15px; color: #777; text-decoration: none; text-shadow: 0 1px 0 #333; }
        .navbar .nav > li > a:hover { background-color: transparent; color: #fff; text-decoration: none; }
        .navbar .nav > li.active > a { color: #fff; background-color: #333; }
        .row { margin-left: -20px; }
        .span6 { float: left; min-height: 1px; margin-left: 20px; width: 460px; }
        .span12 { float: left; min-height: 1px; margin-left: 20px; width: 940px; }
        .well { min-height: 20px; padding: 19px; margin-bottom: 20px; background-color: #f5f5f5; border: 1px solid #e3e3e3; border-radius: 4px; }
        .alert { padding: 8px 32px 8px 14px; margin-bottom: 20px; text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5); background-color: #fcf8e3; border: 1px solid #fbeed5; border-radius: 4px; }
        .alert-success { color: #468847; background-color: #dff0d8; border-color: #d6e9c6; }
        .alert-error { color: #b94a48; background-color: #f2dede; border-color: #eed3d7; }
        .alert-info { color: #3a87ad; background-color: #d9edf7; border-color: #bce8f1; }
        .table { width: 100%; margin-bottom: 20px; background-color: transparent; border-collapse: collapse; border-spacing: 0; }
        .table th, .table td { padding: 8px; line-height: 20px; text-align: left; vertical-align: top; border-top: 1px solid #ddd; }
        .table th { font-weight: bold; }
        .table thead th { vertical-align: bottom; }
        .table-striped tbody tr:nth-child(odd) td { background-color: #f9f9f9; }
        .clearfix:after { display: table; content: ""; line-height: 0; clear: both; }
        .pull-right { float: right; }
        .status-up { color: #468847; font-weight: bold; }
        .status-down { color: #b94a48; font-weight: bold; }
        .status-warning { color: #f89406; font-weight: bold; }
        .status-unknown { color: #999; font-weight: bold; }
        .badge { display: inline-block; min-width: 10px; padding: 3px 7px; font-size: 11px; font-weight: bold; color: #fff; line-height: 14px; vertical-align: baseline; white-space: nowrap; text-align: center; background-color: #999; border-radius: 10px; }
        .badge-success { background-color: #468847; }
        .badge-warning { background-color: #f89406; }
        .badge-important { background-color: #b94a48; }
        .badge-info { background-color: #3a87ad; }
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
                    <li class="active"><a href="nagios_status.php">Nagios</a></li>
                    <li><a href="reports.php">Reports</a></li>
                    <li><a href="test_snmp.php">SNMP Test</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container">
        <h2>Nagios Integration Status</h2>
        
        <!-- Connection Status -->
        <div class="row">
            <div class="span12">
                <?php if ($nagiosConnected): ?>
                    <div class="alert alert-success">
                        <strong>Nagios Connected:</strong> Successfully reading status from Nagios system
                    </div>
                <?php else: ?>
                    <div class="alert alert-error">
                        <strong>Nagios Error:</strong> <?php echo htmlspecialchars($nagiosError); ?>
                        <br><small>Note: Nagios integration requires /var/nagios/status.dat file</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($nagiosConnected): ?>
        <!-- Nagios Summary -->
        <div class="row">
            <div class="span6">
                <div class="well">
                    <h3>System Information</h3>
                    <table class="table">
                        <tr><td><strong>Status File:</strong></td><td><?php echo htmlspecialchars($nagiosInfo['status_file']); ?></td></tr>
                        <tr><td><strong>Last Update:</strong></td><td><?php echo $nagiosInfo['last_update']; ?></td></tr>
                        <tr><td><strong>File Age:</strong></td><td><?php echo $nagiosInfo['status_file_age']; ?> seconds</td></tr>
                        <tr><td><strong>Command File:</strong></td><td>
                            <?php echo $nagiosInfo['command_file_writable'] ? 
                                '<span class="status-up">Writable</span>' : 
                                '<span class="status-down">Not Writable</span>'; ?>
                        </td></tr>
                    </table>
                </div>
            </div>
            
            <div class="span6">
                <div class="well">
                    <h3>Status Summary</h3>
                    <table class="table">
                        <tr>
                            <td><strong>Total Hosts:</strong></td>
                            <td><?php echo $nagiosInfo['total_hosts']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Host Status:</strong></td>
                            <td>
                                <span class="badge badge-success"><?php echo $nagiosInfo['host_states']['up']; ?> UP</span>
                                <span class="badge badge-important"><?php echo $nagiosInfo['host_states']['down']; ?> DOWN</span>
                                <span class="badge"><?php echo $nagiosInfo['host_states']['unreachable']; ?> UNREACH</span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Total Services:</strong></td>
                            <td><?php echo $nagiosInfo['total_services']; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Service Status:</strong></td>
                            <td>
                                <span class="badge badge-success"><?php echo $nagiosInfo['service_states']['ok']; ?> OK</span>
                                <span class="badge badge-warning"><?php echo $nagiosInfo['service_states']['warning']; ?> WARN</span>
                                <span class="badge badge-important"><?php echo $nagiosInfo['service_states']['critical']; ?> CRIT</span>
                                <span class="badge"><?php echo $nagiosInfo['service_states']['unknown']; ?> UNK</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Host Status Details -->
        <?php
        try {
            $status = $nagios->parseStatusFile();
            $hosts = $status['hosts'];
            $services = $status['services'];
        } catch (Exception $e) {
            $hosts = array();
            $services = array();
        }
        ?>

        <?php if (!empty($hosts)): ?>
        <div class="row">
            <div class="span12">
                <div class="well">
                    <h3>Host Status Details</h3>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Host Name</th>
                                <th>Status</th>
                                <th>Last Check</th>
                                <th>Duration</th>
                                <th>Status Information</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hosts as $host): ?>
                            <?php
                            $state = isset($host['current_state']) ? (int)$host['current_state'] : 3;
                            $stateText = NagiosIntegration::getStateText('host', $state);
                            $stateClass = NagiosIntegration::getStateClass('host', $state);
                            $lastCheck = isset($host['last_check']) ? date('M j H:i', $host['last_check']) : 'Never';
                            $duration = isset($host['last_state_change']) ? $this->formatDuration(time() - $host['last_state_change']) : 'Unknown';
                            ?>
                            <tr>
                                <td><?php echo isset($host['host_name']) ? htmlspecialchars($host['host_name']) : 'Unknown'; ?></td>
                                <td><span class="<?php echo $stateClass; ?>"><?php echo $stateText; ?></span></td>
                                <td><?php echo $lastCheck; ?></td>
                                <td><?php echo $duration; ?></td>
                                <td><?php echo isset($host['plugin_output']) ? htmlspecialchars($host['plugin_output']) : ''; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Service Status - Show only problems or recent changes -->
        <?php
        $problemServices = array();
        foreach ($services as $service) {
            $state = isset($service['current_state']) ? (int)$service['current_state'] : 0;
            if ($state > 0) { // Not OK
                $problemServices[] = $service;
            }
        }
        ?>

        <?php if (!empty($problemServices)): ?>
        <div class="row">
            <div class="span12">
                <div class="well">
                    <h3>Service Problems</h3>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Host</th>
                                <th>Service</th>
                                <th>Status</th>
                                <th>Last Check</th>
                                <th>Duration</th>
                                <th>Status Information</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($problemServices as $service): ?>
                            <?php
                            $state = isset($service['current_state']) ? (int)$service['current_state'] : 3;
                            $stateText = NagiosIntegration::getStateText('service', $state);
                            $stateClass = NagiosIntegration::getStateClass('service', $state);
                            $lastCheck = isset($service['last_check']) ? date('M j H:i', $service['last_check']) : 'Never';
                            $duration = isset($service['last_state_change']) ? $this->formatDuration(time() - $service['last_state_change']) : 'Unknown';
                            ?>
                            <tr>
                                <td><?php echo isset($service['host_name']) ? htmlspecialchars($service['host_name']) : 'Unknown'; ?></td>
                                <td><?php echo isset($service['service_description']) ? htmlspecialchars($service['service_description']) : 'Unknown'; ?></td>
                                <td><span class="<?php echo $stateClass; ?>"><?php echo $stateText; ?></span></td>
                                <td><?php echo $lastCheck; ?></td>
                                <td><?php echo $duration; ?></td>
                                <td><?php echo isset($service['plugin_output']) ? htmlspecialchars($service['plugin_output']) : ''; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php elseif (!empty($services)): ?>
        <div class="row">
            <div class="span12">
                <div class="alert alert-info">
                    <strong>All Services OK:</strong> No service problems detected. Total services monitored: <?php echo count($services); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- Testing Links -->
        <div class="row">
            <div class="span12">
                <div class="well">
                    <h3>Testing & Integration</h3>
                    <p>
                        <a href="nagios_status.php?test_nagios=1" class="btn">Test Nagios Integration</a>
                        <a href="index.php" class="btn">Return to Dashboard</a>
                    </p>
                    <p><small>
                        Integration Notes (November 2012):<br>
                        • Nagios 3.x status.dat file parsing<br>
                        • Command pipe integration for external commands<br>
                        • Host and service status monitoring<br>
                        • Compatible with standard Nagios installations
                    </small></p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="row">
            <div class="span12">
                <hr>
                <p class="pull-right">
                    <small>Nagios Integration - November 5, 2012</small>
                </p>
                <div class="clearfix"></div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// Helper function for duration formatting
function formatDuration($seconds) {
    $units = array(
        'day' => 86400,
        'hour' => 3600,
        'minute' => 60
    );
    
    foreach ($units as $unit => $value) {
        if ($seconds >= $value) {
            $amount = floor($seconds / $value);
            return $amount . ' ' . $unit . ($amount > 1 ? 's' : '');
        }
    }
    
    return $seconds . ' second' . ($seconds != 1 ? 's' : '');
}
?>
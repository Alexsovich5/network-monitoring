<?php
/*
 * Bandwidth Graphs Display Page
 * November 25, 2012 - RRDtool integration for traffic visualization
 * Shows interface bandwidth graphs and utilization charts
 */

require_once 'lib/database.php';
require_once 'lib/rrd_manager.php';
require_once 'lib/snmp_poller.php';

// Initialize components
try {
    $db = new DatabaseManager();
    $rrd = new RRDManager();
    $dbConnected = true;
} catch (Exception $e) {
    $dbConnected = false;
    $dbError = $e->getMessage();
}

// Get parameters
$deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
$period = isset($_GET['period']) ? $_GET['period'] : 'day';
$action = isset($_GET['action']) ? $_GET['action'] : 'view';

// Valid periods
$validPeriods = array('hour', 'day', 'week', 'month');
if (!in_array($period, $validPeriods)) {
    $period = 'day';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bandwidth Graphs - Network Monitor</title>
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
        .span3 { float: left; min-height: 1px; margin-left: 20px; width: 220px; }
        .span9 { float: left; min-height: 1px; margin-left: 20px; width: 700px; }
        .span12 { float: left; min-height: 1px; margin-left: 20px; width: 940px; }
        .well { min-height: 20px; padding: 19px; margin-bottom: 20px; background-color: #f5f5f5; border: 1px solid #e3e3e3; border-radius: 4px; }
        .alert { padding: 8px 32px 8px 14px; margin-bottom: 20px; text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5); background-color: #fcf8e3; border: 1px solid #fbeed5; border-radius: 4px; }
        .alert-success { color: #468847; background-color: #dff0d8; border-color: #d6e9c6; }
        .alert-error { color: #b94a48; background-color: #f2dede; border-color: #eed3d7; }
        .btn { display: inline-block; padding: 4px 12px; margin-bottom: 0; font-size: 14px; line-height: 20px; text-align: center; vertical-align: middle; cursor: pointer; color: #333; text-shadow: 0 1px 1px rgba(255, 255, 255, 0.75); background-color: #f5f5f5; border: 1px solid #ccc; border-radius: 4px; text-decoration: none; }
        .btn:hover { color: #333; text-decoration: none; background-color: #e6e6e6; }
        .btn-primary { color: #fff; text-shadow: 0 -1px 0 rgba(0, 0, 0, 0.25); background-color: #006dcc; border-color: #0044cc; }
        .btn-group { position: relative; display: inline-block; vertical-align: middle; }
        .btn-group .btn { position: relative; border-radius: 0; }
        .btn-group .btn:first-child { border-radius: 4px 0 0 4px; }
        .btn-group .btn:last-child { border-radius: 0 4px 4px 0; }
        .btn-group .btn.active { color: #fff; background-color: #006dcc; border-color: #0044cc; }
        .table { width: 100%; margin-bottom: 20px; background-color: transparent; border-collapse: collapse; border-spacing: 0; }
        .table th, .table td { padding: 8px; line-height: 20px; text-align: left; vertical-align: top; border-top: 1px solid #ddd; }
        .table th { font-weight: bold; }
        .table thead th { vertical-align: bottom; }
        .clearfix:after { display: table; content: ""; line-height: 0; clear: both; }
        .pull-right { float: right; }
        .graph-container { text-align: center; margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
        .graph-container img { max-width: 100%; border: 1px solid #ccc; }
        .graph-error { color: #b94a48; font-style: italic; }
        select { padding: 4px 6px; font-size: 14px; line-height: 20px; color: #555; border: 1px solid #ccc; border-radius: 4px; }
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
                    <li><a href="nagios_status.php">Nagios</a></li>
                    <li class="active"><a href="graphs.php">Graphs</a></li>
                    <li><a href="reports.php">Reports</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container">
        <h2>Bandwidth Graphs & Utilization</h2>
        
        <!-- Database Status -->
        <?php if (!$dbConnected): ?>
        <div class="alert alert-error">
            <strong>Database Error:</strong> <?php echo htmlspecialchars($dbError); ?>
        </div>
        <?php return; endif; ?>

        <div class="row">
            <!-- Device Selection Sidebar -->
            <div class="span3">
                <div class="well">
                    <h3>Device Selection</h3>
                    <?php
                    $devices = $db->getDevices('active');
                    ?>
                    <form method="GET">
                        <label for="device_id"><strong>Select Device:</strong></label>
                        <select name="device_id" id="device_id" onchange="this.form.submit()">
                            <option value="">-- Select Device --</option>
                            <?php foreach ($devices as $device): ?>
                            <option value="<?php echo $device['device_id']; ?>" 
                                    <?php echo $deviceId == $device['device_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($device['device_name']) . ' (' . $device['ip_address'] . ')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="period" value="<?php echo $period; ?>" />
                    </form>
                </div>

                <?php if ($deviceId > 0): ?>
                <div class="well">
                    <h3>Time Period</h3>
                    <div class="btn-group" data-toggle="buttons-radio">
                        <a href="?device_id=<?php echo $deviceId; ?>&period=hour" 
                           class="btn <?php echo $period == 'hour' ? 'btn-primary active' : ''; ?>">Hour</a>
                        <a href="?device_id=<?php echo $deviceId; ?>&period=day" 
                           class="btn <?php echo $period == 'day' ? 'btn-primary active' : ''; ?>">Day</a>
                        <a href="?device_id=<?php echo $deviceId; ?>&period=week" 
                           class="btn <?php echo $period == 'week' ? 'btn-primary active' : ''; ?>">Week</a>
                        <a href="?device_id=<?php echo $deviceId; ?>&period=month" 
                           class="btn <?php echo $period == 'month' ? 'btn-primary active' : ''; ?>">Month</a>
                    </div>
                </div>

                <div class="well">
                    <h3>Actions</h3>
                    <p>
                        <a href="?device_id=<?php echo $deviceId; ?>&period=<?php echo $period; ?>&action=refresh" class="btn">Refresh Graphs</a>
                    </p>
                    <p>
                        <a href="?device_id=<?php echo $deviceId; ?>&action=poll" class="btn">Poll Device</a>
                    </p>
                    <p><small>
                        Graphs update automatically every 5 minutes via SNMP polling.
                    </small></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Graph Display Area -->
            <div class="span9">
                <?php if ($deviceId > 0): ?>
                <?php
                $device = $db->getDevice($deviceId);
                if (!$device) {
                    echo '<div class="alert alert-error">Device not found.</div>';
                    return;
                }
                
                // Handle actions
                if ($action == 'poll') {
                    try {
                        $poller = new SNMPPoller($device['snmp_community']);
                        $interfaces = $poller->getInterfaceList($device['ip_address']);
                        
                        foreach ($interfaces as $ifIndex => $ifName) {
                            $stats = $poller->getInterfaceStats($device['ip_address'], $ifIndex);
                            if ($stats && isset($stats['ifInOctets']) && isset($stats['ifOutOctets'])) {
                                // Update RRD
                                $rrd->updateInterfaceData($deviceId, $ifIndex, 
                                    $stats['ifInOctets'], $stats['ifOutOctets']);
                                
                                // Update database
                                $db->updateInterface($deviceId, $ifIndex, $ifName,
                                    isset($stats['ifType']) ? $stats['ifType'] : null,
                                    isset($stats['ifMtu']) ? $stats['ifMtu'] : null,
                                    isset($stats['ifSpeed']) ? $stats['ifSpeed'] : null,
                                    isset($stats['ifAdminStatus']) && $stats['ifAdminStatus'] == '1' ? 'up' : 'down',
                                    isset($stats['ifOperStatus']) && $stats['ifOperStatus'] == '1' ? 'up' : 'down'
                                );
                            }
                        }
                        
                        echo '<div class="alert alert-success">Device polled successfully. Graphs updated with latest data.</div>';
                    } catch (Exception $e) {
                        echo '<div class="alert alert-error">Polling failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                }
                ?>

                <div class="well">
                    <h3><?php echo htmlspecialchars($device['device_name']); ?> - Interface Graphs</h3>
                    <p><strong>Device:</strong> <?php echo htmlspecialchars($device['ip_address']); ?> | 
                       <strong>Type:</strong> <?php echo htmlspecialchars($device['device_type']); ?> | 
                       <strong>Period:</strong> <?php echo ucfirst($period); ?></p>
                </div>

                <?php
                // Get device interfaces from database
                $sql = "SELECT * FROM device_interfaces WHERE device_id = :device_id ORDER BY interface_index";
                $stmt = $db->getConnection()->prepare($sql);
                $stmt->execute(array(':device_id' => $deviceId));
                $interfaces = $stmt->fetchAll();

                if (empty($interfaces)) {
                    echo '<div class="alert">No interfaces found for this device. <a href="?device_id=' . $deviceId . '&action=poll">Poll device</a> to discover interfaces.</div>';
                } else {
                    foreach ($interfaces as $interface) {
                        $ifIndex = $interface['interface_index'];
                        $ifName = $interface['interface_name'];
                        $ifSpeed = $interface['interface_speed'];
                        
                        echo '<div class="graph-container">';
                        echo '<h4>Interface ' . $ifIndex . ': ' . htmlspecialchars($ifName) . '</h4>';
                        
                        // Generate bandwidth graph
                        try {
                            $graphUrl = $rrd->generateGraph($deviceId, $ifIndex, $period, 700, 200);
                            echo '<p><strong>Traffic Graph:</strong></p>';
                            echo '<img src="' . htmlspecialchars($graphUrl) . '?t=' . time() . '" alt="Traffic Graph" />';
                        } catch (Exception $e) {
                            echo '<p class="graph-error">Traffic graph unavailable: ' . htmlspecialchars($e->getMessage()) . '</p>';
                        }
                        
                        // Generate utilization graph if speed is known
                        if ($ifSpeed && $ifSpeed > 0) {
                            try {
                                $utilUrl = $rrd->generateUtilizationGraph($deviceId, $ifIndex, $ifSpeed, $period, 700, 200);
                                echo '<p><strong>Utilization Graph:</strong></p>';
                                echo '<img src="' . htmlspecialchars($utilUrl) . '?t=' . time() . '" alt="Utilization Graph" />';
                            } catch (Exception $e) {
                                echo '<p class="graph-error">Utilization graph unavailable: ' . htmlspecialchars($e->getMessage()) . '</p>';
                            }
                        }
                        
                        // Interface details
                        echo '<table class="table">';
                        echo '<tr><td><strong>Interface Index:</strong></td><td>' . $ifIndex . '</td></tr>';
                        echo '<tr><td><strong>Interface Name:</strong></td><td>' . htmlspecialchars($ifName) . '</td></tr>';
                        echo '<tr><td><strong>Admin Status:</strong></td><td>' . ucfirst($interface['admin_status']) . '</td></tr>';
                        echo '<tr><td><strong>Operational Status:</strong></td><td>' . ucfirst($interface['oper_status']) . '</td></tr>';
                        if ($ifSpeed) {
                            echo '<tr><td><strong>Speed:</strong></td><td>' . number_format($ifSpeed) . ' bps</td></tr>';
                        }
                        if ($interface['interface_mtu']) {
                            echo '<tr><td><strong>MTU:</strong></td><td>' . $interface['interface_mtu'] . ' bytes</td></tr>';
                        }
                        echo '</table>';
                        
                        echo '</div>';
                    }
                }
                ?>

                <?php else: ?>
                <div class="well">
                    <h3>Select a Device</h3>
                    <p>Please select a device from the sidebar to view bandwidth graphs and interface utilization.</p>
                    
                    <h4>Available Features:</h4>
                    <ul>
                        <li>Real-time SNMP interface polling</li>
                        <li>RRDtool-based bandwidth graphs</li>
                        <li>Traffic and utilization visualization</li>
                        <li>Multiple time periods (hour, day, week, month)</li>
                        <li>Automatic data collection every 5 minutes</li>
                    </ul>
                    
                    <p><small>
                        <strong>Note:</strong> Graphs require RRDtool to be installed and SNMP access to target devices.
                        Historical data is retained for up to 2 years with varying granularity.
                    </small></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="row">
            <div class="span12">
                <hr>
                <p class="pull-right">
                    <small>Bandwidth Monitoring - November 25, 2012</small>
                </p>
                <div class="clearfix"></div>
            </div>
        </div>
    </div>

    <!-- Auto-refresh script for graphs -->
    <script type="text/javascript">
        // Auto-refresh graphs every 5 minutes (2012 style)
        if (window.location.search.indexOf('device_id=') !== -1) {
            setTimeout(function() {
                window.location.reload();
            }, 300000); // 5 minutes
        }
    </script>
</body>
</html>
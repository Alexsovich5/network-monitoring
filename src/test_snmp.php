<?php
/*
 * SNMP Module Test Script
 * October 12, 2012
 * Quick test interface for SNMP polling functionality
 */

require_once 'lib/snmp_poller.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>SNMP Polling Test - Network Monitor</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-form { background: #f0f0f0; padding: 15px; margin: 10px 0; }
        .results { background: #e8f5e8; padding: 15px; margin: 10px 0; font-family: monospace; }
        .error { background: #ffe8e8; padding: 15px; margin: 10px 0; }
        input[type="text"] { width: 200px; padding: 5px; }
        input[type="submit"] { padding: 5px 15px; }
    </style>
</head>
<body>
    <h1>SNMP Polling Module Test</h1>
    <p>Testing SNMP v2c polling functionality - October 12, 2012</p>
    
    <div class="test-form">
        <form method="GET">
            <h3>Device SNMP Test</h3>
            <label>Host/IP: </label>
            <input type="text" name="host" value="<?php echo isset($_GET['host']) ? htmlspecialchars($_GET['host']) : '127.0.0.1'; ?>" />
            
            <label>Community: </label>
            <input type="text" name="community" value="<?php echo isset($_GET['community']) ? htmlspecialchars($_GET['community']) : 'public'; ?>" />
            
            <input type="submit" name="test_device" value="Test Device" />
        </form>
    </div>
    
    <?php
    if (isset($_GET['test_device'])) {
        $host = $_GET['host'];
        $community = $_GET['community'];
        
        echo '<div class="results">';
        echo '<h3>SNMP Test Results</h3>';
        echo "Testing: $host with community: $community<br/>";
        echo "Timestamp: " . date('Y-m-d H:i:s') . "<br/><br/>";
        
        try {
            $poller = new SNMPPoller($community, 3, 2);
            
            if ($poller->testDevice($host)) {
                echo "<strong>SUCCESS:</strong> Device is responding to SNMP<br/><br/>";
                
                echo "<strong>Basic Device Information:</strong><br/>";
                $basic_info = $poller->pollDevice($host);
                foreach ($basic_info as $key => $value) {
                    echo "$key: " . htmlspecialchars($value) . "<br/>";
                }
                
                echo "<br/><strong>Network Interfaces:</strong><br/>";
                $interfaces = $poller->getInterfaceList($host);
                if (!empty($interfaces)) {
                    foreach ($interfaces as $index => $name) {
                        echo "Interface $index: " . htmlspecialchars($name) . "<br/>";
                    }
                    
                    // Test interface statistics for first interface
                    if (count($interfaces) > 0) {
                        $firstInterface = array_keys($interfaces)[0];
                        echo "<br/><strong>Interface $firstInterface Statistics:</strong><br/>";
                        $ifStats = $poller->getInterfaceStats($host, $firstInterface);
                        foreach ($ifStats as $stat => $value) {
                            echo "$stat: " . htmlspecialchars($value) . "<br/>";
                        }
                    }
                } else {
                    echo "No interfaces found or access denied<br/>";
                }
                
            } else {
                echo '<div class="error">';
                echo "<strong>FAILED:</strong> Device is not responding to SNMP<br/>";
                echo "Possible issues:<br/>";
                echo "- SNMP service not running on target device<br/>";
                echo "- Incorrect community string<br/>";
                echo "- Firewall blocking SNMP (UDP port 161)<br/>";
                echo "- Network connectivity issues<br/>";
                echo '</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="error">';
            echo "<strong>ERROR:</strong> " . htmlspecialchars($e->getMessage());
            echo '</div>';
        }
        
        echo '</div>';
    }
    ?>
    
    <div style="margin-top: 30px; padding: 15px; background: #f5f5f5; font-size: 12px;">
        <h4>Notes (October 2012):</h4>
        <ul>
            <li>SNMP v2c is the current standard for network device monitoring</li>
            <li>Default community string is usually "public" for read-only access</li>
            <li>This implementation uses command-line snmpget/snmpwalk tools</li>
            <li>Requires net-snmp package to be installed on the server</li>
            <li>All OIDs follow standard MIB-II specification (RFC 1213)</li>
        </ul>
    </div>
</body>
</html>
<?php
/*
 * SNMP Polling Module
 * October 12, 2012 - SNMP v2c Implementation
 * Compatible with PHP 5.4 and net-snmp tools
 */

class SNMPPoller {
    
    private $community;
    private $timeout;
    private $retries;
    
    public function __construct($community = 'public', $timeout = 3, $retries = 2) {
        $this->community = $community;
        $this->timeout = $timeout;
        $this->retries = $retries;
    }
    
    /**
     * Poll device using SNMP v2c
     * 2012 standard implementation
     */
    public function pollDevice($host, $oids = array()) {
        $results = array();
        
        // Default OIDs for basic device monitoring (2012 standards)
        if (empty($oids)) {
            $oids = array(
                'sysDescr' => '1.3.6.1.2.1.1.1.0',
                'sysUpTime' => '1.3.6.1.2.1.1.3.0',
                'sysName' => '1.3.6.1.2.1.1.5.0',
                'ifNumber' => '1.3.6.1.2.1.2.1.0'
            );
        }
        
        foreach ($oids as $name => $oid) {
            $value = $this->snmpGet($host, $oid);
            $results[$name] = $value;
        }
        
        return $results;
    }
    
    /**
     * Get interface statistics
     * Standard interface monitoring OIDs
     */
    public function getInterfaceStats($host, $ifIndex = 1) {
        $ifOids = array(
            'ifDescr' => "1.3.6.1.2.1.2.2.1.2.$ifIndex",
            'ifType' => "1.3.6.1.2.1.2.2.1.3.$ifIndex",
            'ifMtu' => "1.3.6.1.2.1.2.2.1.4.$ifIndex",
            'ifSpeed' => "1.3.6.1.2.1.2.2.1.5.$ifIndex",
            'ifAdminStatus' => "1.3.6.1.2.1.2.2.1.7.$ifIndex",
            'ifOperStatus' => "1.3.6.1.2.1.2.2.1.8.$ifIndex",
            'ifInOctets' => "1.3.6.1.2.1.2.2.1.10.$ifIndex",
            'ifOutOctets' => "1.3.6.1.2.1.2.2.1.16.$ifIndex"
        );
        
        $stats = array();
        foreach ($ifOids as $name => $oid) {
            $stats[$name] = $this->snmpGet($host, $oid);
        }
        
        return $stats;
    }
    
    /**
     * Core SNMP GET function using system snmpget command
     * 2012 approach using command line tools
     */
    private function snmpGet($host, $oid) {
        $command = sprintf(
            'snmpget -v2c -c %s -t %d -r %d %s %s 2>/dev/null',
            escapeshellarg($this->community),
            $this->timeout,
            $this->retries,
            escapeshellarg($host),
            escapeshellarg($oid)
        );
        
        $output = shell_exec($command);
        
        if ($output === null) {
            return false;
        }
        
        // Parse SNMP output (2012 format)
        if (preg_match('/= (.+)$/', trim($output), $matches)) {
            $value = trim($matches[1]);
            
            // Clean up common SNMP formatting
            $value = preg_replace('/^(STRING|INTEGER|Gauge32|Counter32|Counter64): /', '', $value);
            $value = trim($value, '"');
            
            return $value;
        }
        
        return false;
    }
    
    /**
     * Test device connectivity
     */
    public function testDevice($host) {
        $sysUpTime = $this->snmpGet($host, '1.3.6.1.2.1.1.3.0');
        return ($sysUpTime !== false);
    }
    
    /**
     * Get all interface indices for a device
     */
    public function getInterfaceList($host) {
        $command = sprintf(
            'snmpwalk -v2c -c %s -t %d -r %d %s 1.3.6.1.2.1.2.2.1.2 2>/dev/null',
            escapeshellarg($this->community),
            $this->timeout,
            $this->retries,
            escapeshellarg($host)
        );
        
        $output = shell_exec($command);
        $interfaces = array();
        
        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (preg_match('/\.2\.2\.1\.2\.(\d+) = STRING: (.+)$/', $line, $matches)) {
                    $interfaces[$matches[1]] = trim($matches[2], '"');
                }
            }
        }
        
        return $interfaces;
    }
}

// Example usage for testing (2012 style)
if (isset($_GET['test']) && $_GET['test'] == '1') {
    header('Content-Type: text/plain');
    
    $poller = new SNMPPoller();
    
    // Test with localhost (assuming SNMP is configured)
    $host = '127.0.0.1';
    
    echo "SNMP Polling Test - " . date('Y-m-d H:i:s') . "\n";
    echo "Target: $host\n\n";
    
    if ($poller->testDevice($host)) {
        echo "Device is responding to SNMP\n\n";
        
        $basic_info = $poller->pollDevice($host);
        echo "Basic Device Information:\n";
        foreach ($basic_info as $key => $value) {
            echo "$key: $value\n";
        }
        
        echo "\nInterface List:\n";
        $interfaces = $poller->getInterfaceList($host);
        foreach ($interfaces as $index => $name) {
            echo "Interface $index: $name\n";
        }
    } else {
        echo "Device is not responding to SNMP\n";
        echo "Check SNMP configuration and community string\n";
    }
}
?>
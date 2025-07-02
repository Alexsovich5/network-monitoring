<?php
/*
 * Nagios Integration Module
 * November 5, 2012 - Connected Nagios API for alert management
 * Compatible with Nagios 3.x (2012 standard)
 */

class NagiosIntegration {
    
    private $nagiosCmd;
    private $nagiosUser;
    private $nagiosGroup;
    private $statusFile;
    private $commandFile;
    
    public function __construct($nagiosCmd = '/usr/bin/nagios', $statusFile = '/var/nagios/status.dat', $commandFile = '/var/nagios/rw/nagios.cmd') {
        $this->nagiosCmd = $nagiosCmd;
        $this->statusFile = $statusFile;
        $this->commandFile = $commandFile;
        $this->nagiosUser = 'nagios';
        $this->nagiosGroup = 'nagios';
    }
    
    /**
     * Parse Nagios status.dat file (2012 format)
     * Returns array of host and service status information
     */
    public function parseStatusFile() {
        if (!file_exists($this->statusFile)) {
            throw new Exception("Nagios status file not found: " . $this->statusFile);
        }
        
        $content = file_get_contents($this->statusFile);
        if ($content === false) {
            throw new Exception("Unable to read Nagios status file");
        }
        
        $hosts = array();
        $services = array();
        
        // Parse status.dat format (Nagios 3.x style)
        $blocks = $this->parseStatusBlocks($content);
        
        foreach ($blocks as $block) {
            if ($block['type'] == 'hoststatus') {
                $hosts[] = $this->parseHostStatus($block['data']);
            } elseif ($block['type'] == 'servicestatus') {
                $services[] = $this->parseServiceStatus($block['data']);
            }
        }
        
        return array(
            'hosts' => $hosts,
            'services' => $services,
            'last_update' => filemtime($this->statusFile)
        );
    }
    
    /**
     * Parse status.dat blocks
     */
    private function parseStatusBlocks($content) {
        $blocks = array();
        $lines = explode("\n", $content);
        $currentBlock = null;
        $blockData = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (preg_match('/^(\w+)\s+{$/', $line, $matches)) {
                // Start of new block
                $currentBlock = $matches[1];
                $blockData = '';
            } elseif ($line == '}' && $currentBlock) {
                // End of block
                $blocks[] = array(
                    'type' => $currentBlock,
                    'data' => $blockData
                );
                $currentBlock = null;
                $blockData = '';
            } elseif ($currentBlock && $line) {
                // Block content
                $blockData .= $line . "\n";
            }
        }
        
        return $blocks;
    }
    
    /**
     * Parse host status block
     */
    private function parseHostStatus($data) {
        $host = array();
        $lines = explode("\n", trim($data));
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^(\w+)=(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = $matches[2];
                
                // Convert numeric fields
                if (in_array($key, array('current_state', 'last_hard_state', 'last_check', 'next_check'))) {
                    $value = is_numeric($value) ? (int)$value : $value;
                }
                
                $host[$key] = $value;
            }
        }
        
        return $host;
    }
    
    /**
     * Parse service status block
     */
    private function parseServiceStatus($data) {
        $service = array();
        $lines = explode("\n", trim($data));
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^(\w+)=(.+)$/', $line, $matches)) {
                $key = $matches[1];
                $value = $matches[2];
                
                // Convert numeric fields
                if (in_array($key, array('current_state', 'last_hard_state', 'last_check', 'next_check'))) {
                    $value = is_numeric($value) ? (int)$value : $value;
                }
                
                $service[$key] = $value;
            }
        }
        
        return $service;
    }
    
    /**
     * Get host status by hostname
     */
    public function getHostStatus($hostname) {
        $status = $this->parseStatusFile();
        
        foreach ($status['hosts'] as $host) {
            if (isset($host['host_name']) && $host['host_name'] == $hostname) {
                return $host;
            }
        }
        
        return null;
    }
    
    /**
     * Get service status for host
     */
    public function getServiceStatus($hostname, $serviceName = null) {
        $status = $this->parseStatusFile();
        $services = array();
        
        foreach ($status['services'] as $service) {
            if (isset($service['host_name']) && $service['host_name'] == $hostname) {
                if ($serviceName === null || $service['service_description'] == $serviceName) {
                    $services[] = $service;
                }
            }
        }
        
        return $serviceName ? (count($services) > 0 ? $services[0] : null) : $services;
    }
    
    /**
     * Submit external command to Nagios
     * 2012 method using command pipe
     */
    public function submitCommand($command, $timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $commandLine = "[$timestamp] $command\n";
        
        if (!file_exists($this->commandFile)) {
            throw new Exception("Nagios command file not found: " . $this->commandFile);
        }
        
        if (!is_writable($this->commandFile)) {
            throw new Exception("Nagios command file not writable: " . $this->commandFile);
        }
        
        $result = file_put_contents($this->commandFile, $commandLine, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            throw new Exception("Failed to write to Nagios command file");
        }
        
        return true;
    }
    
    /**
     * Schedule host check
     */
    public function scheduleHostCheck($hostname, $forceCheck = false) {
        $command = $forceCheck ? 
            "SCHEDULE_FORCED_HOST_CHECK;$hostname;" . time() :
            "SCHEDULE_HOST_CHECK;$hostname;" . time();
        
        return $this->submitCommand($command);
    }
    
    /**
     * Schedule service check
     */
    public function scheduleServiceCheck($hostname, $serviceName, $forceCheck = false) {
        $command = $forceCheck ?
            "SCHEDULE_FORCED_SVC_CHECK;$hostname;$serviceName;" . time() :
            "SCHEDULE_SVC_CHECK;$hostname;$serviceName;" . time();
        
        return $this->submitCommand($command);
    }
    
    /**
     * Acknowledge host problem
     */
    public function acknowledgeHostProblem($hostname, $author, $comment, $sticky = 1, $notify = 1, $persistent = 0) {
        $command = "ACKNOWLEDGE_HOST_PROBLEM;$hostname;$sticky;$notify;$persistent;$author;$comment";
        return $this->submitCommand($command);
    }
    
    /**
     * Acknowledge service problem
     */
    public function acknowledgeServiceProblem($hostname, $serviceName, $author, $comment, $sticky = 1, $notify = 1, $persistent = 0) {
        $command = "ACKNOWLEDGE_SVC_PROBLEM;$hostname;$serviceName;$sticky;$notify;$persistent;$author;$comment";
        return $this->submitCommand($command);
    }
    
    /**
     * Enable/disable host notifications
     */
    public function setHostNotifications($hostname, $enable = true) {
        $command = $enable ? 
            "ENABLE_HOST_NOTIFICATIONS;$hostname" :
            "DISABLE_HOST_NOTIFICATIONS;$hostname";
        
        return $this->submitCommand($command);
    }
    
    /**
     * Add host comment
     */
    public function addHostComment($hostname, $author, $comment, $persistent = 1) {
        $command = "ADD_HOST_COMMENT;$hostname;$persistent;$author;$comment";
        return $this->submitCommand($command);
    }
    
    /**
     * Get Nagios system information
     */
    public function getNagiosInfo() {
        $status = $this->parseStatusFile();
        
        $info = array(
            'status_file' => $this->statusFile,
            'status_file_age' => time() - filemtime($this->statusFile),
            'last_update' => date('Y-m-d H:i:s', filemtime($this->statusFile)),
            'total_hosts' => count($status['hosts']),
            'total_services' => count($status['services']),
            'command_file' => $this->commandFile,
            'command_file_writable' => is_writable($this->commandFile)
        );
        
        // Count host states
        $hostStates = array('up' => 0, 'down' => 0, 'unreachable' => 0);
        foreach ($status['hosts'] as $host) {
            $state = isset($host['current_state']) ? (int)$host['current_state'] : 3;
            switch ($state) {
                case 0: $hostStates['up']++; break;
                case 1: $hostStates['down']++; break;
                case 2: $hostStates['unreachable']++; break;
            }
        }
        $info['host_states'] = $hostStates;
        
        // Count service states
        $serviceStates = array('ok' => 0, 'warning' => 0, 'critical' => 0, 'unknown' => 0);
        foreach ($status['services'] as $service) {
            $state = isset($service['current_state']) ? (int)$service['current_state'] : 3;
            switch ($state) {
                case 0: $serviceStates['ok']++; break;
                case 1: $serviceStates['warning']++; break;
                case 2: $serviceStates['critical']++; break;
                case 3: $serviceStates['unknown']++; break;
            }
        }
        $info['service_states'] = $serviceStates;
        
        return $info;
    }
    
    /**
     * Convert Nagios state to readable string
     */
    public static function getStateText($type, $state) {
        if ($type == 'host') {
            switch ((int)$state) {
                case 0: return 'UP';
                case 1: return 'DOWN';
                case 2: return 'UNREACHABLE';
                default: return 'UNKNOWN';
            }
        } else { // service
            switch ((int)$state) {
                case 0: return 'OK';
                case 1: return 'WARNING';
                case 2: return 'CRITICAL';
                case 3: return 'UNKNOWN';
                default: return 'PENDING';
            }
        }
    }
    
    /**
     * Get state CSS class for display
     */
    public static function getStateClass($type, $state) {
        if ($type == 'host') {
            switch ((int)$state) {
                case 0: return 'status-up';
                case 1: case 2: return 'status-down';
                default: return 'status-unknown';
            }
        } else { // service
            switch ((int)$state) {
                case 0: return 'status-up';
                case 1: return 'status-warning';
                case 2: return 'status-down';
                default: return 'status-unknown';
            }
        }
    }
}

// Example usage for testing
if (isset($_GET['test_nagios']) && $_GET['test_nagios'] == '1') {
    header('Content-Type: text/plain');
    
    try {
        $nagios = new NagiosIntegration();
        
        echo "Nagios Integration Test - " . date('Y-m-d H:i:s') . "\n";
        echo "======================================\n\n";
        
        $info = $nagios->getNagiosInfo();
        
        echo "Nagios System Information:\n";
        echo "Status File: " . $info['status_file'] . "\n";
        echo "Last Update: " . $info['last_update'] . "\n";
        echo "File Age: " . $info['status_file_age'] . " seconds\n";
        echo "Command File: " . $info['command_file'] . "\n";
        echo "Command File Writable: " . ($info['command_file_writable'] ? 'Yes' : 'No') . "\n\n";
        
        echo "Host Summary:\n";
        echo "Total Hosts: " . $info['total_hosts'] . "\n";
        echo "UP: " . $info['host_states']['up'] . "\n";
        echo "DOWN: " . $info['host_states']['down'] . "\n";
        echo "UNREACHABLE: " . $info['host_states']['unreachable'] . "\n\n";
        
        echo "Service Summary:\n";
        echo "Total Services: " . $info['total_services'] . "\n";
        echo "OK: " . $info['service_states']['ok'] . "\n";
        echo "WARNING: " . $info['service_states']['warning'] . "\n";
        echo "CRITICAL: " . $info['service_states']['critical'] . "\n";
        echo "UNKNOWN: " . $info['service_states']['unknown'] . "\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        echo "\nNote: This test requires Nagios to be installed and running.\n";
        echo "Status file should be at: /var/nagios/status.dat\n";
        echo "Command file should be at: /var/nagios/rw/nagios.cmd\n";
    }
}
?>
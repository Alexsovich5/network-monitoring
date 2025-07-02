<?php
/*
 * RRDtool Integration for Bandwidth Graphs
 * November 25, 2012 - RRDtool integration for traffic visualization
 * Compatible with RRDtool 1.4.x (2012 standard)
 */

class RRDManager {
    
    private $rrdPath;
    private $rrdtool;
    private $imgPath;
    private $webPath;
    
    public function __construct($rrdPath = '/var/lib/netmon/rrd', $imgPath = '/var/www/html/netmon/images/graphs', $webPath = '/netmon/images/graphs') {
        $this->rrdPath = $rrdPath;
        $this->rrdtool = '/usr/bin/rrdtool';
        $this->imgPath = $imgPath;
        $this->webPath = $webPath;
        
        // Create directories if they don't exist
        if (!is_dir($this->rrdPath)) {
            mkdir($this->rrdPath, 0755, true);
        }
        if (!is_dir($this->imgPath)) {
            mkdir($this->imgPath, 0755, true);
        }
    }
    
    /**
     * Create RRD database for device interface
     * 2012 RRDtool format with standard consolidation functions
     */
    public function createInterfaceRRD($deviceId, $interfaceIndex) {
        $rrdFile = $this->getRRDFilename($deviceId, $interfaceIndex);
        
        if (file_exists($rrdFile)) {
            return true; // Already exists
        }
        
        // RRD creation command (2012 standard)
        $cmd = $this->rrdtool . ' create ' . escapeshellarg($rrdFile) . ' ' .
               '--start ' . (time() - 60) . ' ' .
               '--step 300 ' .  // 5 minute intervals
               'DS:input:COUNTER:600:0:12500000000 ' .     // Input octets (10Gbps max)
               'DS:output:COUNTER:600:0:12500000000 ' .    // Output octets (10Gbps max)
               'RRA:AVERAGE:0.5:1:576 ' .      // 5min avg for 2 days (576 * 5min = 48h)
               'RRA:AVERAGE:0.5:6:672 ' .      // 30min avg for 2 weeks (672 * 30min = 14d)
               'RRA:AVERAGE:0.5:24:732 ' .     // 2h avg for 2 months (732 * 2h = 61d)
               'RRA:AVERAGE:0.5:144:1460 ' .   // 12h avg for 2 years (1460 * 12h = 730d)
               'RRA:MAX:0.5:1:576 ' .          // 5min max for 2 days
               'RRA:MAX:0.5:6:672 ' .          // 30min max for 2 weeks
               'RRA:MAX:0.5:24:732 ' .         // 2h max for 2 months
               'RRA:MAX:0.5:144:1460 ' .       // 12h max for 2 years
               '2>/dev/null';
        
        $output = shell_exec($cmd);
        
        return file_exists($rrdFile);
    }
    
    /**
     * Update RRD with interface data
     */
    public function updateInterfaceData($deviceId, $interfaceIndex, $inputOctets, $outputOctets, $timestamp = null) {
        $rrdFile = $this->getRRDFilename($deviceId, $interfaceIndex);
        
        if (!file_exists($rrdFile)) {
            if (!$this->createInterfaceRRD($deviceId, $interfaceIndex)) {
                throw new Exception("Failed to create RRD file: $rrdFile");
            }
        }
        
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $cmd = $this->rrdtool . ' update ' . escapeshellarg($rrdFile) . ' ' .
               $timestamp . ':' . $inputOctets . ':' . $outputOctets . ' 2>/dev/null';
        
        $output = shell_exec($cmd);
        
        return true;
    }
    
    /**
     * Generate bandwidth graph
     * 2012 RRDtool graph generation with period-appropriate styling
     */
    public function generateGraph($deviceId, $interfaceIndex, $period = 'day', $width = 600, $height = 200) {
        $rrdFile = $this->getRRDFilename($deviceId, $interfaceIndex);
        $imgFile = $this->getImageFilename($deviceId, $interfaceIndex, $period);
        
        if (!file_exists($rrdFile)) {
            throw new Exception("RRD file not found: $rrdFile");
        }
        
        // Calculate time range
        $endTime = time();
        switch ($period) {
            case 'hour':
                $startTime = $endTime - 3600;
                $title = 'Last Hour';
                break;
            case 'day':
                $startTime = $endTime - 86400;
                $title = 'Last 24 Hours';
                break;
            case 'week':
                $startTime = $endTime - 604800;
                $title = 'Last Week';
                break;
            case 'month':
                $startTime = $endTime - 2678400;
                $title = 'Last Month';
                break;
            default:
                $startTime = $endTime - 86400;
                $title = 'Last 24 Hours';
        }
        
        // RRDtool graph command (2012 styling)
        $cmd = $this->rrdtool . ' graph ' . escapeshellarg($imgFile) . ' ' .
               '--start ' . $startTime . ' ' .
               '--end ' . $endTime . ' ' .
               '--width ' . $width . ' ' .
               '--height ' . $height . ' ' .
               '--title "Interface Traffic - ' . $title . '" ' .
               '--vertical-label "Bits per Second" ' .
               '--color BACK#F0F0F0 ' .
               '--color CANVAS#FFFFFF ' .
               '--color GRID#C0C0C0 ' .
               '--color MGRID#808080 ' .
               '--color FONT#000000 ' .
               '--color ARROW#000000 ' .
               '--border 1 ' .
               '--slope-mode ' .
               'DEF:input=' . escapeshellarg($rrdFile) . ':input:AVERAGE ' .
               'DEF:output=' . escapeshellarg($rrdFile) . ':output:AVERAGE ' .
               'CDEF:input_bits=input,8,* ' .
               'CDEF:output_bits=output,8,* ' .
               'CDEF:output_bits_neg=output_bits,-1,* ' .
               'AREA:input_bits#00FF00:"Input Traffic\\l" ' .
               'AREA:output_bits_neg#0000FF:"Output Traffic\\l" ' .
               'LINE1:input_bits#008000 ' .
               'LINE1:output_bits_neg#000080 ' .
               'GPRINT:input_bits:LAST:"Current Input\\: %6.2lf %Sbps" ' .
               'GPRINT:input_bits:AVERAGE:"Average Input\\: %6.2lf %Sbps" ' .
               'GPRINT:input_bits:MAX:"Peak Input\\: %6.2lf %Sbps\\l" ' .
               'GPRINT:output_bits:LAST:"Current Output\\: %6.2lf %Sbps" ' .
               'GPRINT:output_bits:AVERAGE:"Average Output\\: %6.2lf %Sbps" ' .
               'GPRINT:output_bits:MAX:"Peak Output\\: %6.2lf %Sbps\\l" ' .
               '2>/dev/null';
        
        $output = shell_exec($cmd);
        
        if (file_exists($imgFile)) {
            return $this->webPath . '/' . basename($imgFile);
        }
        
        throw new Exception("Failed to generate graph: $imgFile");
    }
    
    /**
     * Generate utilization graph (percentage)
     */
    public function generateUtilizationGraph($deviceId, $interfaceIndex, $interfaceSpeed, $period = 'day', $width = 600, $height = 200) {
        $rrdFile = $this->getRRDFilename($deviceId, $interfaceIndex);
        $imgFile = $this->getImageFilename($deviceId, $interfaceIndex, $period . '_util');
        
        if (!file_exists($rrdFile)) {
            throw new Exception("RRD file not found: $rrdFile");
        }
        
        // Calculate time range
        $endTime = time();
        switch ($period) {
            case 'hour':
                $startTime = $endTime - 3600;
                $title = 'Last Hour Utilization';
                break;
            case 'day':
                $startTime = $endTime - 86400;
                $title = 'Last 24 Hours Utilization';
                break;
            case 'week':
                $startTime = $endTime - 604800;
                $title = 'Last Week Utilization';
                break;
            case 'month':
                $startTime = $endTime - 2678400;
                $title = 'Last Month Utilization';
                break;
            default:
                $startTime = $endTime - 86400;
                $title = 'Last 24 Hours Utilization';
        }
        
        // Convert interface speed to bits per second
        $speedBps = $interfaceSpeed * 8;
        
        $cmd = $this->rrdtool . ' graph ' . escapeshellarg($imgFile) . ' ' .
               '--start ' . $startTime . ' ' .
               '--end ' . $endTime . ' ' .
               '--width ' . $width . ' ' .
               '--height ' . $height . ' ' .
               '--title "Interface Utilization - ' . $title . '" ' .
               '--vertical-label "Percentage %" ' .
               '--upper-limit 100 ' .
               '--lower-limit 0 ' .
               '--rigid ' .
               '--color BACK#F0F0F0 ' .
               '--color CANVAS#FFFFFF ' .
               '--border 1 ' .
               'DEF:input=' . escapeshellarg($rrdFile) . ':input:AVERAGE ' .
               'DEF:output=' . escapeshellarg($rrdFile) . ':output:AVERAGE ' .
               'CDEF:input_bits=input,8,* ' .
               'CDEF:output_bits=output,8,* ' .
               'CDEF:input_util=input_bits,' . $speedBps . ',/,100,* ' .
               'CDEF:output_util=output_bits,' . $speedBps . ',/,100,* ' .
               'AREA:input_util#00FF0080:"Input Utilization" ' .
               'AREA:output_util#0000FF80:"Output Utilization" ' .
               'LINE1:input_util#008000 ' .
               'LINE1:output_util#000080 ' .
               'HRULE:80#FF0000:"Warning (80%)" ' .
               'HRULE:95#FF0000:"Critical (95%)\\l" ' .
               'GPRINT:input_util:LAST:"Current Input\\: %6.2lf%%" ' .
               'GPRINT:input_util:AVERAGE:"Average Input\\: %6.2lf%%" ' .
               'GPRINT:input_util:MAX:"Peak Input\\: %6.2lf%%\\l" ' .
               'GPRINT:output_util:LAST:"Current Output\\: %6.2lf%%" ' .
               'GPRINT:output_util:AVERAGE:"Average Output\\: %6.2lf%%" ' .
               'GPRINT:output_util:MAX:"Peak Output\\: %6.2lf%%\\l" ' .
               '2>/dev/null';
        
        $output = shell_exec($cmd);
        
        if (file_exists($imgFile)) {
            return $this->webPath . '/' . basename($imgFile);
        }
        
        throw new Exception("Failed to generate utilization graph: $imgFile");
    }
    
    /**
     * Get RRD filename
     */
    private function getRRDFilename($deviceId, $interfaceIndex) {
        return $this->rrdPath . "/device_{$deviceId}_if_{$interfaceIndex}.rrd";
    }
    
    /**
     * Get image filename
     */
    private function getImageFilename($deviceId, $interfaceIndex, $period) {
        return $this->imgPath . "/device_{$deviceId}_if_{$interfaceIndex}_{$period}.png";
    }
    
    /**
     * Get graph data for JSON API
     */
    public function getGraphData($deviceId, $interfaceIndex, $period = 'day') {
        $rrdFile = $this->getRRDFilename($deviceId, $interfaceIndex);
        
        if (!file_exists($rrdFile)) {
            return false;
        }
        
        // Calculate time range
        $endTime = time();
        switch ($period) {
            case 'hour':
                $startTime = $endTime - 3600;
                break;
            case 'day':
                $startTime = $endTime - 86400;
                break;
            case 'week':
                $startTime = $endTime - 604800;
                break;
            case 'month':
                $startTime = $endTime - 2678400;
                break;
            default:
                $startTime = $endTime - 86400;
        }
        
        // Fetch data from RRD
        $cmd = $this->rrdtool . ' fetch ' . escapeshellarg($rrdFile) . ' AVERAGE ' .
               '--start ' . $startTime . ' --end ' . $endTime . ' 2>/dev/null';
        
        $output = shell_exec($cmd);
        
        if (!$output) {
            return false;
        }
        
        $lines = explode("\n", trim($output));
        $data = array();
        
        // Skip header line
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            if (preg_match('/^(\d+): (.+) (.+)$/', $line, $matches)) {
                $timestamp = (int)$matches[1];
                $input = $matches[2] !== 'nan' ? (float)$matches[2] : 0;
                $output = $matches[3] !== 'nan' ? (float)$matches[3] : 0;
                
                $data[] = array(
                    'timestamp' => $timestamp,
                    'input_octets' => $input,
                    'output_octets' => $output,
                    'input_bits' => $input * 8,
                    'output_bits' => $output * 8
                );
            }
        }
        
        return $data;
    }
    
    /**
     * Clean old graph images
     */
    public function cleanOldGraphs($maxAge = 3600) {
        $files = glob($this->imgPath . '/*.png');
        $cutoff = time() - $maxAge;
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get RRD info
     */
    public function getRRDInfo($deviceId, $interfaceIndex) {
        $rrdFile = $this->getRRDFilename($deviceId, $interfaceIndex);
        
        if (!file_exists($rrdFile)) {
            return false;
        }
        
        $cmd = $this->rrdtool . ' info ' . escapeshellarg($rrdFile) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        
        $info = array();
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            if (preg_match('/^(.+) = (.+)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2], '"');
                $info[$key] = $value;
            }
        }
        
        return $info;
    }
}

// Test interface for debugging
if (isset($_GET['test_rrd']) && $_GET['test_rrd'] == '1') {
    header('Content-Type: text/plain');
    
    try {
        $rrd = new RRDManager();
        
        echo "RRDtool Integration Test - " . date('Y-m-d H:i:s') . "\n";
        echo "=====================================\n\n";
        
        $testDeviceId = 1;
        $testInterface = 1;
        
        echo "Testing RRD creation for device $testDeviceId interface $testInterface...\n";
        
        if ($rrd->createInterfaceRRD($testDeviceId, $testInterface)) {
            echo "✓ RRD file created successfully\n";
            
            // Test data update
            echo "Testing data update...\n";
            $result = $rrd->updateInterfaceData($testDeviceId, $testInterface, 
                rand(1000000, 10000000), rand(500000, 5000000));
            
            if ($result) {
                echo "✓ Data updated successfully\n";
            } else {
                echo "✗ Data update failed\n";
            }
            
            // Test graph generation
            echo "Testing graph generation...\n";
            try {
                $graphUrl = $rrd->generateGraph($testDeviceId, $testInterface, 'day');
                echo "✓ Graph generated: $graphUrl\n";
            } catch (Exception $e) {
                echo "✗ Graph generation failed: " . $e->getMessage() . "\n";
            }
            
        } else {
            echo "✗ RRD file creation failed\n";
        }
        
        echo "\nNote: This test requires RRDtool to be installed\n";
        echo "Install with: yum install rrdtool (CentOS 6)\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
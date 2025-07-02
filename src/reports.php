<?php
/*
 * Reports Interface
 * December 2, 2012 - Web interface for viewing and generating reports
 * Automated reporting system with cron job integration
 */

require_once 'lib/database.php';
require_once 'lib/report_generator.php';

// Initialize components
try {
    $db = new DatabaseManager();
    $generator = new ReportGenerator();
    $dbConnected = true;
} catch (Exception $e) {
    $dbConnected = false;
    $dbError = $e->getMessage();
}

// Handle report generation
$message = '';
$messageType = '';

if (isset($_POST['generate_report'])) {
    try {
        $reportType = $_POST['report_type'];
        $reportDate = $_POST['report_date'];
        
        switch ($reportType) {
            case 'daily':
                $report = $generator->generateDailyReport($reportDate);
                $message = "Daily report generated successfully!";
                $messageType = 'success';
                break;
                
            case 'weekly':
                $report = $generator->generateWeeklyReport($reportDate);
                $message = "Weekly report generated successfully!";
                $messageType = 'success';
                break;
                
            case 'monthly':
                $month = date('Y-m', strtotime($reportDate));
                $report = $generator->generateMonthlyReport($month);
                $message = "Monthly report generated successfully!";
                $messageType = 'success';
                break;
                
            default:
                throw new Exception("Invalid report type");
        }
        
        if (isset($report['html'])) {
            $message .= " <a href=\"{$report['html']}\">View Report</a>";
        }
        
    } catch (Exception $e) {
        $message = "Report generation failed: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get existing reports
$reportFiles = array();
$reportsPath = '/var/www/html/netmon/reports';
if (is_dir($reportsPath)) {
    $files = glob($reportsPath . '/*.html');
    foreach ($files as $file) {
        $basename = basename($file);
        $reportFiles[] = array(
            'filename' => $basename,
            'path' => '/netmon/reports/' . $basename,
            'size' => filesize($file),
            'modified' => filemtime($file),
            'type' => strpos($basename, 'daily') !== false ? 'Daily' : 
                     (strpos($basename, 'weekly') !== false ? 'Weekly' : 'Monthly')
        );
    }
    
    // Sort by modification time (newest first)
    usort($reportFiles, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Reports - Network Monitor</title>
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
        .span4 { float: left; min-height: 1px; margin-left: 20px; width: 300px; }
        .span8 { float: left; min-height: 1px; margin-left: 20px; width: 620px; }
        .span12 { float: left; min-height: 1px; margin-left: 20px; width: 940px; }
        .well { min-height: 20px; padding: 19px; margin-bottom: 20px; background-color: #f5f5f5; border: 1px solid #e3e3e3; border-radius: 4px; }
        .alert { padding: 8px 32px 8px 14px; margin-bottom: 20px; text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5); background-color: #fcf8e3; border: 1px solid #fbeed5; border-radius: 4px; }
        .alert-success { color: #468847; background-color: #dff0d8; border-color: #d6e9c6; }
        .alert-error { color: #b94a48; background-color: #f2dede; border-color: #eed3d7; }
        .alert-info { color: #3a87ad; background-color: #d9edf7; border-color: #bce8f1; }
        .btn { display: inline-block; padding: 4px 12px; margin-bottom: 0; font-size: 14px; line-height: 20px; text-align: center; vertical-align: middle; cursor: pointer; color: #333; text-shadow: 0 1px 1px rgba(255, 255, 255, 0.75); background-color: #f5f5f5; border: 1px solid #ccc; border-radius: 4px; text-decoration: none; }
        .btn:hover { color: #333; text-decoration: none; background-color: #e6e6e6; }
        .btn-primary { color: #fff; text-shadow: 0 -1px 0 rgba(0, 0, 0, 0.25); background-color: #006dcc; border-color: #0044cc; }
        .table { width: 100%; margin-bottom: 20px; background-color: transparent; border-collapse: collapse; border-spacing: 0; }
        .table th, .table td { padding: 8px; line-height: 20px; text-align: left; vertical-align: top; border-top: 1px solid #ddd; }
        .table th { font-weight: bold; }
        .table thead th { vertical-align: bottom; }
        .table-striped tbody tr:nth-child(odd) td { background-color: #f9f9f9; }
        .clearfix:after { display: table; content: ""; line-height: 0; clear: both; }
        .pull-right { float: right; }
        .form-horizontal .control-group { margin-bottom: 20px; }
        .form-horizontal .control-label { float: left; width: 160px; padding-top: 5px; text-align: right; }
        .form-horizontal .controls { margin-left: 180px; }
        input[type="date"], select { padding: 4px 6px; font-size: 14px; line-height: 20px; color: #555; border: 1px solid #ccc; border-radius: 4px; }
        .badge { display: inline-block; min-width: 10px; padding: 3px 7px; font-size: 11px; font-weight: bold; color: #fff; line-height: 14px; vertical-align: baseline; white-space: nowrap; text-align: center; background-color: #999; border-radius: 10px; }
        .badge-info { background-color: #3a87ad; }
        .badge-success { background-color: #468847; }
        .badge-warning { background-color: #f89406; }
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
                    <li><a href="graphs.php">Graphs</a></li>
                    <li class="active"><a href="reports.php">Reports</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container">
        <h2>Automated Network Reports</h2>
        
        <!-- Message Display -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
        <?php endif; ?>

        <!-- Database Status -->
        <?php if (!$dbConnected): ?>
        <div class="alert alert-error">
            <strong>Database Error:</strong> <?php echo htmlspecialchars($dbError); ?>
        </div>
        <?php return; endif; ?>

        <div class="row">
            <!-- Report Generation -->
            <div class="span4">
                <div class="well">
                    <h3>Generate New Report</h3>
                    <form method="POST" class="form-horizontal">
                        <div class="control-group">
                            <label class="control-label" for="report_type">Report Type:</label>
                            <div class="controls">
                                <select name="report_type" id="report_type" required>
                                    <option value="daily">Daily Report</option>
                                    <option value="weekly">Weekly Report</option>
                                    <option value="monthly">Monthly Report</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="control-group">
                            <label class="control-label" for="report_date">Date:</label>
                            <div class="controls">
                                <input type="date" name="report_date" id="report_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                                <p class="help-block">
                                    <small>Daily: Specific date<br>
                                    Weekly: Monday of the week<br>
                                    Monthly: Any date in the month</small>
                                </p>
                            </div>
                        </div>
                        
                        <div class="control-group">
                            <div class="controls">
                                <button type="submit" name="generate_report" class="btn btn-primary">
                                    Generate Report
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="well">
                    <h3>Scheduled Reports</h3>
                    <p>Automated reports are generated via cron jobs:</p>
                    <ul>
                        <li><strong>Daily:</strong> 06:00 AM</li>
                        <li><strong>Weekly:</strong> Monday 07:00 AM</li>
                        <li><strong>Monthly:</strong> 1st day 08:00 AM</li>
                    </ul>
                    
                    <h4>Cron Configuration:</h4>
                    <pre style="font-size: 11px; background: #f0f0f0; padding: 10px; border-radius: 3px;">
# Daily report at 6 AM
0 6 * * * /usr/bin/php /path/to/report_generator.php daily

# Weekly report on Monday at 7 AM  
0 7 * * 1 /usr/bin/php /path/to/report_generator.php weekly

# Monthly report on 1st at 8 AM
0 8 1 * * /usr/bin/php /path/to/report_generator.php monthly

# Cleanup old reports daily at 2 AM
0 2 * * * /usr/bin/php /path/to/report_generator.php cleanup</pre>
                </div>
            </div>

            <!-- Existing Reports -->
            <div class="span8">
                <div class="well">
                    <h3>Available Reports 
                        <span class="badge badge-info"><?php echo count($reportFiles); ?></span>
                    </h3>
                    
                    <?php if (empty($reportFiles)): ?>
                    <div class="alert alert-info">
                        No reports have been generated yet. Use the form on the left to create your first report.
                    </div>
                    <?php else: ?>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Report Name</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Generated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportFiles as $report): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo htmlspecialchars($report['path']); ?>" target="_blank">
                                        <?php echo htmlspecialchars($report['filename']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="badge badge-<?php 
                                        echo $report['type'] == 'Daily' ? 'info' : 
                                            ($report['type'] == 'Weekly' ? 'success' : 'warning'); 
                                    ?>">
                                        <?php echo $report['type']; ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($report['size'] / 1024, 1); ?> KB</td>
                                <td><?php echo date('M j, Y H:i', $report['modified']); ?></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars($report['path']); ?>" 
                                       target="_blank" class="btn">View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- Report Features -->
                <div class="well">
                    <h3>Report Contents</h3>
                    <div class="row">
                        <div class="span4">
                            <h4>Daily Reports Include:</h4>
                            <ul>
                                <li>Device status summary</li>
                                <li>Alert statistics</li>
                                <li>Availability metrics</li>
                                <li>Traffic overview</li>
                                <li>Critical incidents</li>
                            </ul>
                        </div>
                        
                        <div class="span4">
                            <h4>Weekly/Monthly Add:</h4>
                            <ul>
                                <li>Trend analysis</li>
                                <li>Top interfaces by traffic</li>
                                <li>Performance comparisons</li>
                                <li>Capacity planning data</li>
                                <li>Historical graphs</li>
                            </ul>
                        </div>
                    </div>
                    
                    <h4>Export Formats:</h4>
                    <ul>
                        <li><strong>HTML:</strong> Full interactive reports for web viewing</li>
                        <li><strong>Text:</strong> Email-friendly summaries for daily alerts</li>
                        <li><strong>Future:</strong> PDF exports and email automation</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="row">
            <div class="span12">
                <hr>
                <p class="pull-right">
                    <small>Automated Reporting - December 2, 2012</small>
                </p>
                <div class="clearfix"></div>
            </div>
        </div>
    </div>
</body>
</html>